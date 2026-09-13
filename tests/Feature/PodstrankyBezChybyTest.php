<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Routy;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Každá podstránka bez parametru se otevře bez chyby serveru.
 *
 * Vedle galerie na `/` pořád běží starší rozhraní (`/prehled`, `/rozpocty`,
 * `/denik`, nastavení, administrace…). Testy hlídaly jeho API, ne stránky —
 * chyba 500 na stránce, kterou nikdo neotevřel, by se ukázala až u dvojice.
 */
class PodstrankyBezChybyTest extends TestCase
{
    use RefreshDatabase;

    /** Cesty, které vedou ven (OAuth, platební brána) nebo stahují balík aplikace. */
    private const VEN = [
        'oauth/google/redirect', 'oauth/google/callback', 'oauth/dropbox/callback', 'oauth/onedrive/callback',
        'discord/pripojit', 'discord/zpet', 'banking/callback', 'platby/comgate/navrat', 'app/android/download',
    ];

    public function test_prihlaseny_vlastnik_otevre_kazdou_podstranku(): void
    {
        $vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true, 'name' => 'Adrian Test']);
        $partner = User::factory()->create(['role' => 'partner', 'is_active' => true, 'name' => 'Jana Test']);
        $prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Společně', 'slug' => 'spolecne', 'owner_id' => $vlastnik->id, 'is_default' => true]);
        $prostor->members()->attach($vlastnik->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $prostor->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        $this->actingAs($vlastnik);

        $chyby = [];

        foreach ($this->stranky() as $uri) {
            $odpoved = $this->get('/'.ltrim($uri, '/'));

            if ($odpoved->getStatusCode() >= 500) {
                $chyby[] = $uri.' → '.$odpoved->getStatusCode().' '.mb_substr(strip_tags((string) $odpoved->exception?->getMessage()), 0, 200);
            }
        }

        $this->assertSame([], $chyby, "Podstránky s chybou serveru:\n".implode("\n", $chyby));
    }

    public function test_neprihlaseny_nedostane_chybu_serveru(): void
    {
        $chyby = [];

        foreach ($this->stranky() as $uri) {
            $odpoved = $this->get('/'.ltrim($uri, '/'));

            if ($odpoved->getStatusCode() >= 500) {
                $chyby[] = $uri.' → '.$odpoved->getStatusCode();
            }
        }

        $this->assertSame([], $chyby, "Podstránky s chybou serveru bez přihlášení:\n".implode("\n", $chyby));
    }

    /** @return list<string> */
    private function stranky(): array
    {
        return collect(Routy::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true))
            ->map(fn (Route $r) => $r->uri())
            ->filter(fn (string $uri) => ! str_starts_with($uri, 'api') && ! str_contains($uri, '{')
                && ! preg_match('#^(_|sanctum|livewire|telescope|horizon|storage|up$)#', $uri)
                && ! in_array($uri, self::VEN, true))
            ->unique()
            ->values()
            ->all();
    }
}
