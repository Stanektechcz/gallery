<?php

namespace Tests\Feature\Stav41;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smazaný nápad na dárek ani uložené randíčko starší opis nevzkřísí.
 *
 * `SeznamyVeStavu` zakládá, co podle názvu v tabulce není. Řádek, který
 * obrazovka dostala ze serveru (`datesSaved-0`, `gifts-2` — pořadí v `AL`),
 * ale v tabulce nebyl proto, že ho ten druhý mezitím smazal — a karta
 * otevřená od rána ho při další úpravě seznamu založila znovu.
 */
class SeznamyVeStavuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_radek_ze_serveru_se_nezalozi_znovu(): void
    {
        $this->stav(['xRows' => [
            'datesSaved' => [['t' => 'Piknik u přehrady', 'm' => 'uloženo 30. 7.', 'g' => 'uloženo', 'id' => 'datesSaved-0']],
            'gifts' => [['t' => 'Nápad: Hodinky', 'm' => 'bez poznámky', 'g' => 'nápad', 'id' => 'gifts-1']],
        ]])->assertOk();

        $this->assertSame(0, DB::table('couple_date_ideas')->count());
        $this->assertSame(0, DB::table('gift_ideas')->count());
    }

    /** Nový řádek z obrazovky (`…-n3`) i z telefonu (bez id) se založí. */
    public function test_novy_radek_se_zalozi(): void
    {
        $this->stav(['xRows' => [
            'datesSaved' => [['t' => 'Keramika pro dva', 'm' => 'přidáno dnes', 'g' => 'nové', 'id' => 'datesSaved-n3']],
            'gifts' => [['t' => 'Nápad: Kniha', 'm' => 'z rychlého vstupu', 'g' => 'nápad']],
        ]])->assertOk();

        $this->assertSame(['Keramika pro dva'], DB::table('couple_date_ideas')->pluck('title')->all());
        $this->assertSame(['Kniha'], DB::table('gift_ideas')->pluck('title')->all());
    }

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }
}
