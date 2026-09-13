<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Notifications\WebPushService;
use App\Services\Provoz\PauzaDvojice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Připomínka druhému jde opravdu do jeho telefonu — a jen jemu.
 */
class PripomenutiTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian Staněk']);
        $this->maki = User::factory()->create(['name' => 'Makinka Kubíčková']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    public function test_pripominka_jde_druhemu_clenovi(): void
    {
        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->once()
            ->withArgs(fn (User $komu, array $zprava) => $komu->is($this->maki)
                && $zprava['title'] === 'Připomínka od Adrian'
                && $zprava['body'] === 'Vezmeš cestou chleba?')
            ->andReturn(2);
        $this->app->instance(WebPushService::class, $push);

        $this->postJson('/api/pripomenout', ['text' => 'Vezmeš cestou chleba?'])
            ->assertOk()
            ->assertJsonPath('doruceno', 2)
            ->assertJsonPath('zprava', 'Připomínka odešla do telefonu — Makinka');
    }

    /** Bez zapnutých upozornění se neřekne „odesláno". */
    public function test_bez_odberu_rekne_pravdu(): void
    {
        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->once()->andReturn(0);
        $this->app->instance(WebPushService::class, $push);

        $this->postJson('/api/pripomenout', ['text' => 'Ahoj'])
            ->assertOk()
            ->assertJsonPath('doruceno', 0)
            ->assertJsonPath('zprava', 'Makinka nemá zapnutá upozornění — připomínka do telefonu nedošla');
    }

    /** Poděkování za práci v domácnosti jde stejnou cestou pod svým jménem. */
    public function test_podekovani_se_tak_jmenuje(): void
    {
        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->once()
            ->withArgs(fn (User $komu, array $zprava) => $komu->is($this->maki) && $zprava['title'] === 'Poděkování od Adrian')
            ->andReturn(1);
        $this->app->instance(WebPushService::class, $push);

        $this->postJson('/api/pripomenout', ['text' => 'Díky za vysávání', 'druh' => 'podekovani', 'obrazovka' => 'x-domacnost'])
            ->assertOk()
            ->assertJsonPath('zprava', 'Poděkování odešlo do telefonu — Makinka');

        $this->postJson('/api/pripomenout', ['text' => 'x', 'druh' => 'cokoli'])->assertStatus(422);
    }

    /** Během pauzy dvojice se nic neposílá a hláška to řekne. Po jejím konci zase ano. */
    public function test_pauza_dvojice_ztisi_pripominky(): void
    {
        $prostor = GallerySpace::whereHas('members', fn ($q) => $q->whereKey($this->adri->id))->firstOrFail();
        CoupleState::forCouple($prostor->id)->update(['data' => ['pauseOn' => true, 'pauseUntil' => now()->addHours(5)->getTimestampMs()]]);

        $push = Mockery::mock(WebPushService::class);
        $push->shouldNotReceive('sendToUser');
        $this->app->instance(WebPushService::class, $push);

        $this->postJson('/api/pripomenout', ['text' => 'Ahoj'])
            ->assertOk()
            ->assertJsonPath('doruceno', 0)
            ->assertJsonPath('zprava', 'Běží pauza — do telefonu se do jejího konce nic neposílá');

        CoupleState::where('couple_id', $prostor->id)->first()->update(['data' => ['pauseOn' => true, 'pauseUntil' => now()->subMinute()->getTimestampMs()]]);
        $this->assertFalse(PauzaDvojice::bezi($this->maki));
    }

    public function test_prazdna_pripominka_neprojde(): void
    {
        $this->postJson('/api/pripomenout', ['text' => ''])->assertStatus(422);
    }
}
