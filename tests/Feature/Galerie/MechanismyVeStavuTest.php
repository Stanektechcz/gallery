<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->assertSame($this->dnes()->toDateString(), DB::table('couple_family_contacts')->value('last_contact_on'));
        $this->assertSame($this->maki->id, (int) DB::table('couple_family_contacts')->value('last_contact_by'));
    }

    /**
     * Vynulovaná místní kopie (`fam: null`) na serveru nic nesmaže.
     *
     * Klient kopii vynuluje, aby znovu četl data ze serveru; `(array) null`
     * je prázdný seznam a převodník by ho mohl vzít jako „všechno odebráno".
     */
    public function test_vynulovana_kopie_rodiny_nic_nesmaze(): void
    {
        DB::table('couple_family_contacts')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => 'Máma Adriana',
            'side_user_id' => $this->adri->id, 'every_days' => 7, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->stav(['fam' => null])->assertOk();

        $this->assertSame(1, DB::table('couple_family_contacts')->count());
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

    /**
     * Přidání „dvou pravd" nesmí smazat to, co mezitím zapsal ten druhý.
     *
     * `MechanismyVeStavu::KLICE` tabulku `couple_truths` neznala, takže se
     * `OdebraneVStavu` ptal na klíč `couple_truths`, zatímco prohlížeč posílá
     * `truths`. Obě pojistky proti staré kopii tím vypadly naráz a ze smazání
     * zbylo `whereNotIn` — tedy „smaž všechno, co v mém seznamu není".
     */
    public function test_stara_kopie_neprepise_partnerovu_pravdu(): void
    {
        $uuid = $this->pravdaOdPartnera();

        // Karta drží starý opis a v `__zmenene` říká, že sama nic nezměnila.
        $this->stav([
            'truths' => [
                ['id' => $uuid, 'title' => 'Od Makinky', 'when' => 'dnes', 'a' => 'Starý opis.', 'm' => 'Starý opis.'],
            ],
            '__zmenene' => ['truths' => []],
            '__odebrane' => ['truths' => []],
        ])->assertOk();

        $this->assertSame('Moje verze.', DB::table('couple_truths')->where('uuid', $uuid)->value('first_version'),
            'Řádek, který karta nezměnila, se nesmí přepsat jejím starým opisem.');
    }

    /** A odebrání se naopak provést musí. */
    public function test_odebrana_pravda_se_smaze(): void
    {
        $uuid = $this->pravdaOdPartnera();

        $this->stav(['truths' => [], '__odebrane' => ['truths' => [$uuid]]])->assertOk();

        $this->assertSame(0, DB::table('couple_truths')->count());
    }

    private function pravdaOdPartnera(): string
    {
        $uuid = (string) Str::uuid();

        DB::table('couple_truths')->insert([
            'uuid' => $uuid,
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Od Makinky',
            'first_version' => 'Moje verze.',
            'second_version' => 'Tvoje verze.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    /**
     * Prázdný seznam bez `__odebrane` nesmí smazat nic.
     *
     * Mazání stojí na dvojici `when(zustavaji !== [])` + `when(odebrane !== null)`.
     * Když přijde prázdný seznam a prohlížeč neřekne, co odebral, vypadnou obě
     * podmínky a ze `delete()` zbude „smaž všechno v tomhle prostoru".
     * Starší klient `__odebrane` neposílá vůbec.
     */
    #[DataProvider('seznamyPrevodniku')]
    public function test_prazdny_seznam_bez_odebranych_nemaze(string $klic, string $tabulka): void
    {
        DB::table($tabulka)->insert($this->radek($tabulka));

        $this->stav([$klic => []])->assertOk();

        $this->assertSame(1, DB::table($tabulka)->count(),
            'Prázdný seznam bez `__odebrane` se nedá odlišit od „nic jsem neodebral".');
    }

    /** @return array<string, array{string, string}> */
    public static function seznamyPrevodniku(): array
    {
        return [
            'dvě pravdy' => ['truths', 'couple_truths'],
            'laskavosti' => ['favList', 'couple_favours'],
            'odpuštěné' => ['forgList', 'couple_forgiven'],
            'anti-rozpočet' => ['antiList', 'couple_anti_budget'],
            'rodina' => ['fam', 'couple_family_contacts'],
            'pravidla' => ['rules', 'automation_rules'],
            'kapitoly' => ['storyList', 'couple_story_chapters'],
            'milníky' => ['msList', 'couple_story_milestones'],
            'nouzový přístup' => ['emItems', 'emergency_access_items'],
            'papírová záloha' => ['paper', 'paper_backup_rows'],
        ];
    }

    /** @return array<string, mixed> */
    private function radek(string $tabulka): array
    {
        $zaklad = [
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return $zaklad + match ($tabulka) {
            'couple_truths' => ['title' => 'Od partnera', 'first_version' => 'A', 'second_version' => 'B'],
            'couple_favours' => ['from_user_id' => $this->maki->id, 'what' => 'Od partnera', 'happened_on' => now()->toDateString(), 'weight' => 1],
            'couple_forgiven' => ['what' => 'Od partnera', 'happened_on' => now()->toDateString()],
            'couple_anti_budget' => ['name' => 'Od partnera', 'kind' => 'nekoupeno', 'saved' => 100, 'decided_on' => now()->toDateString()],
            'couple_family_contacts' => ['name' => 'Babička', 'every_days' => 30],
            'automation_rules' => ['name' => 'Od partnera', 'trigger' => 'upload', 'action' => 'tag', 'is_enabled' => true],
            'couple_story_chapters' => ['title' => 'Od partnera', 'year' => '2026'],
            'couple_story_milestones' => ['title' => 'Od partnera', 'happened_on' => now()->toDateString()],
            'emergency_access_items' => ['label' => 'Od partnera', 'note' => 'Kde to leží'],
            'paper_backup_rows' => ['label' => 'Od partnera', 'value' => 'Zapsáno na papíře'],
            default => [],
        };
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }
}
