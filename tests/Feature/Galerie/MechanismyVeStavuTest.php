<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mechanismy pro dva se zapisují do tabulek, ne do jednoho JSON dokumentu.
 *
 * Nebylo to ztracené, ale nešlo se na to zeptat: kolik laskavostí je
 * nevyrovnaných, se z blobu nedozví ani upozornění, ani týdenní přehled.
 */
class MechanismyVeStavuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Nová laskavost vznikne v tabulce, ne ve stavu. */
    public function test_nova_laskavost_vznikne_v_tabulce(): void
    {
        $odpoved = $this->stav(['favList' => [
            ['id' => 'f-nova', 'from' => 'Makinka', 'what' => 'Vzala pojištění na sebe', 'date' => '2026-08-28', 'w' => 3, 'settled' => false],
        ]])->assertOk();

        $radek = DB::table('couple_favours')->first();

        $this->assertSame('Vzala pojištění na sebe', $radek->what);
        $this->assertSame($this->maki->id, (int) $radek->from_user_id);
        $this->assertSame(3, (int) $radek->weight);

        $this->assertArrayNotHasKey('favList', (array) $this->getJson('/api/state')->assertOk()->json('data'));
        $this->assertSame('Makinka', $odpoved->json('data.favList.0.from'));
        $this->assertContains('favList', $odpoved->json('docasne'));
    }

    /**
     * Vyrovnat laskavost je přepnutí příznaku, ne smazání.
     *
     * Účet je o tom, co se stalo, ne o tom, co ještě visí.
     */
    public function test_vyrovnana_laskavost_zustava(): void
    {
        $this->stav(['favList' => [
            ['id' => 'f1', 'from' => 'Makinka', 'what' => 'Pojištění', 'date' => '2026-08-28', 'w' => 3, 'settled' => false],
        ]])->assertOk();

        $uuid = DB::table('couple_favours')->value('uuid');

        $this->stav(['favList' => [
            ['id' => $uuid, 'from' => 'Makinka', 'what' => 'Pojištění', 'date' => '2026-08-28', 'w' => 3, 'settled' => true],
        ]])->assertOk();

        $this->assertSame(1, DB::table('couple_favours')->count());
        $this->assertTrue((bool) DB::table('couple_favours')->value('is_settled'));
    }

    /** Anti-rozpočet: měsíc je slovo, ne datum. */
    public function test_anti_rozpocet_prelozi_mesic(): void
    {
        $this->stav(['antiList' => [
            ['name' => 'Fitness, kam jsme šli třikrát', 'month' => 'leden', 'type' => 'předplatné', 'saved' => 890, 'back' => false],
        ]])->assertOk();

        $radek = DB::table('couple_anti_budget')->first();

        $this->assertSame('predplatne', $radek->kind);
        $this->assertSame(890, (int) $radek->saved);
        $this->assertSame('01', substr((string) $radek->decided_on, 5, 2));
    }

    /**
     * „Ozvali jsme se" posouvá datum, obyčejné načtení ne.
     *
     * Jinak by rotace kontaktu s rodinou skákala při každém překreslení.
     */
    public function test_datum_kontaktu_posune_jen_zmena(): void
    {
        DB::table('couple_family_contacts')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Máma Adriana',
            'side_user_id' => $this->adri->id,
            'every_days' => 7,
            'last_contact_on' => now()->subDays(4)->toDateString(),
            'last_contact_by' => $this->adri->id,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uuid = DB::table('couple_family_contacts')->value('uuid');
        $puvodni = DB::table('couple_family_contacts')->value('last_contact_on');

        // Beze změny toho, kdo byl poslední — datum se nehne.
        $this->stav(['fam' => [
            ['id' => $uuid, 'name' => 'Máma Adriana', 'side' => 'Adrian', 'lastWho' => 'Adrian', 'every' => 7, 'note' => ''],
        ]])->assertOk();

        $this->assertSame($puvodni, DB::table('couple_family_contacts')->value('last_contact_on'));

        // Ozvala se Makinka — to je nový kontakt.
        $this->stav(['fam' => [
            ['id' => $uuid, 'name' => 'Máma Adriana', 'side' => 'Adrian', 'lastWho' => 'Makinka', 'every' => 7, 'note' => ''],
        ]])->assertOk();

        $this->assertSame(now()->toDateString(), DB::table('couple_family_contacts')->value('last_contact_on'));
        $this->assertSame($this->maki->id, (int) DB::table('couple_family_contacts')->value('last_contact_by'));
    }

    /** Dvě pravdy se uloží obě. */
    public function test_dve_pravdy_se_ulozi_obe(): void
    {
        $this->stav(['truths' => [
            ['id' => 't-nova', 'title' => 'Kdo přišel pozdě', 'when' => 'červenec 2026', 'a' => 'Byl jsem tam.', 'm' => 'Nebyl.'],
        ]])->assertOk();

        $radek = DB::table('couple_truths')->first();

        $this->assertSame('Byl jsem tam.', $radek->first_version);
        $this->assertSame('Nebyl.', $radek->second_version);
    }

    /** Záznam bez popisu se neuloží. */
    public function test_prazdny_zaznam_se_neulozi(): void
    {
        $this->stav(['favList' => [['id' => 'x', 'from' => 'Adrian', 'what' => '  ', 'date' => '2026-08-01']]])->assertOk();

        $this->assertSame(0, DB::table('couple_favours')->count());
    }

    /** Mechanismy druhého páru se odsud změnit nedají. */
    public function test_cizi_zaznam_zustane(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        DB::table('couple_favours')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $ciziProstor->id,
            'from_user_id' => $cizi->id,
            'what' => 'Cizí laskavost',
            'happened_on' => now()->toDateString(),
            'weight' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->stav(['favList' => []])->assertOk();

        $this->assertSame(1, DB::table('couple_favours')->count());
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }
}
