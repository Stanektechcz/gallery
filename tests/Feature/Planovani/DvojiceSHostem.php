<?php

namespace Tests\Feature\Planovani;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Dvojice (vlastník + partner) a host s rolí `viewer` v jednom prostoru.
 *
 * Host vidí jen sdílené odkazy — do plánů, vzpomínek ani automatických alb
 * dvojice nepatří.
 */
trait DvojiceSHostem
{
    /** @return array{0: User, 1: User, 2: User, 3: GallerySpace} vlastník, partner, host, prostor */
    protected function dvojiceSHostem(): array
    {
        $vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $host = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'My dva', 'slug' => 'my-dva-'.Str::random(6), 'owner_id' => $vlastnik->id, 'is_default' => true]);
        $prostor->members()->attach($vlastnik->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $prostor->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $prostor->members()->attach($host->id, ['role' => 'viewer', 'can_delete' => false, 'can_share' => false, 'joined_at' => now()]);

        return [$vlastnik, $partner, $host, $prostor];
    }

    /**
     * Účet s vlastním prostorem (první, podle něj ho pouští brána), který je
     * navíc hostem v cizí galerii.
     *
     * @return array{0: User, 1: GallerySpace, 2: User, 3: GallerySpace} účet, jeho prostor, cizí vlastník, cizí prostor
     */
    protected function hostCiziGalerie(): array
    {
        $ucet = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $vlastni = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Můj prostor', 'slug' => 'muj-'.Str::random(6), 'owner_id' => $ucet->id, 'is_default' => true]);
        $vlastni->members()->attach($ucet->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        $cizi = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ciziProstor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Cizí galerie', 'slug' => 'cizi-'.Str::random(6), 'owner_id' => $cizi->id, 'is_default' => true]);
        $ciziProstor->members()->attach($cizi->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $ciziProstor->members()->attach($ucet->id, ['role' => 'viewer', 'can_delete' => false, 'can_share' => false, 'joined_at' => now()]);

        return [$ucet, $vlastni, $cizi, $ciziProstor];
    }

    /**
     * Požadavek na API s odemčeným trezorem.
     *
     * API má sezení (a tedy i odemčený trezor) jen pro požadavky z vlastního
     * rozhraní — Sanctum je pozná podle `Referer` ze `sanctum.stateful`.
     */
    protected function sOdemcenymTrezorem(User $kdo): static
    {
        $domena = (string) (config('sanctum.stateful')[0] ?? 'localhost');

        return $this->actingAs($kdo)->withSession($this->odemcenyTrezor($kdo))->withHeader('Referer', 'http://'.$domena.'/');
    }

    protected function fotka(GallerySpace $prostor, User $vlastnik, string $nazev, array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'owner_user_id' => $vlastnik->id, 'uploaded_by' => $vlastnik->id,
            'original_filename' => $nazev, 'safe_filename' => $nazev, 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 4096, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => now()->subYear(), 'uploaded_at' => now(),
        ], $navic));
    }
}
