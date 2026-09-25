<?php

namespace Tests\Feature\Nahravani;

use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\GuestUpload;
use App\Models\MediaItem;
use App\Models\SharedLink;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Schválení nahrávky hosta: jednou, v kvótě a s příponou, kterou MySQL unese.
 *
 * Stav se kontroloval před transakcí bez zámku — dvě souběžná schválení
 * založila dvě média. Kvóta se neověřovala vůbec a přípona šla do
 * varchar(20) tak, jak ji host napsal.
 */
class SchvaleniNahravkyHostaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase', 'owner_id' => $this->user->id, 'is_default' => true,
        ]);
        $this->space->members()->attach($this->user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
    }

    public function test_soubezne_schvaleni_zalozi_nejvys_jedno_medium(): void
    {
        $nahravka = $this->nahravka();

        // Druhé schválení doběhne mezi načtením a zápisem.
        GuestUpload::retrieved(function (GuestUpload $upload) {
            DB::table('guest_uploads')->where('id', $upload->id)->update(['status' => 'approved']);
        });

        $this->actingAs($this->user)->postJson("/api/v1/guest-uploads/{$nahravka->uuid}/approve")->assertStatus(422);
        $this->assertSame(0, MediaItem::count());
    }

    public function test_schvaleni_nad_kvotu_se_odmitne(): void
    {
        $plan = BillingPlan::create(['code' => 'maly', 'name' => 'Malý', 'price_monthly' => 0, 'storage_limit_mb' => 1, 'member_limit' => 2]);
        app(EntitlementService::class)->assignPlan($this->space, $plan);
        $nahravka = $this->nahravka(['size_bytes' => 2 * 1024 * 1024]);

        $this->actingAs($this->user)->postJson("/api/v1/guest-uploads/{$nahravka->uuid}/approve")->assertStatus(402);

        $this->assertSame(0, MediaItem::count());
        $this->assertSame('pending', $nahravka->fresh()->status);
        Storage::disk('local')->assertExists($nahravka->storage_path);
    }

    public function test_pripona_z_nazvu_hosta_se_ocisti(): void
    {
        $nahravka = $this->nahravka(['original_filename' => 'Snímek 2024.05 dovolená u moře', 'mime_type' => 'image/jpeg']);

        $mediaId = $this->actingAs($this->user)->postJson("/api/v1/guest-uploads/{$nahravka->uuid}/approve")
            ->assertCreated()
            ->json('media.id');
        $media = MediaItem::findOrFail($mediaId);

        $this->assertSame('jpg', $media->extension);
        Storage::disk('public')->assertExists("media/{$media->uuid}/original.jpg");
        $this->assertSame('approved', $nahravka->fresh()->status);
    }

    private function nahravka(array $atributy = []): GuestUpload
    {
        $odkaz = SharedLink::create([
            'created_by' => $this->user->id, 'gallery_space_id' => $this->space->id,
            'target_type' => 'selection', 'allow_guest_upload' => true, 'is_active' => true,
        ]);
        $uuid = (string) Str::uuid();
        $cesta = "guest_uploads/{$uuid}/soubor";
        Storage::disk('local')->put($cesta, 'obsah');

        return GuestUpload::create($atributy + [
            'uuid' => $uuid, 'shared_link_id' => $odkaz->id, 'original_filename' => 'vylet.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 5, 'storage_path' => $cesta,
        ]);
    }
}
