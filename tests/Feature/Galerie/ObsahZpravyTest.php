<?php

namespace Tests\Feature\Galerie;

use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zprávy a hlasovky ve tvaru, ve kterém je kreslí prototyp.
 *
 * Aplikace má chat s vlastní tabulkou — a se **šifrovaným** tělem zprávy.
 * Prototyp z něj neukazoval nic: jedenáct napsaných replik z jednoho srpnového
 * víkendu, které si dvojice nikdy nenapsala.
 */
class ObsahZpravyTest extends TestCase
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

    /** Bez zpráv se nic neposílá — klient si nechá ukázková data. */
    public function test_bez_zprav_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/zpravy')->assertOk()->json('data'));
    }

    /**
     * Tělo zprávy je v databázi šifrované.
     *
     * Přímý dotaz by do chatu poslal base64 místo věty — tohle hlídá, že se čte
     * přes model.
     */
    public function test_telo_zpravy_se_desifruje(): void
    {
        $this->zprava(['body' => 'Vzala jsem termosku, ale čelovky jsou u tebe.']);

        // Kontrolní bod: v tabulce je opravdu šifra, ne text.
        $this->assertStringNotContainsString(
            'termosku',
            (string) DB::table('chat_messages')->value('body'),
        );

        $this->assertSame(
            'Vzala jsem termosku, ale čelovky jsou u tebe.',
            $this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS.0.5'),
        );
    }

    /**
     * `who` je strana, ne iniciála jména.
     *
     * Prototyp porovnává `m.who === 'A'` a myslí tím „moje". Kdyby se posílalo
     * první písmeno jména, četl by si každý z dvojice vlastní zprávy jako cizí.
     */
    public function test_strana_zpravy_je_podle_toho_kdo_se_diva(): void
    {
        $this->zprava(['body' => 'Moje', 'created_by' => $this->adri->id]);
        $this->zprava(['body' => 'Její', 'created_by' => $this->maki->id]);

        $strany = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))
            ->mapWithKeys(fn (array $m) => [$m[5] => $m[1]]);

        $this->assertSame('A', $strany['Moje']);
        $this->assertSame('M', $strany['Její']);

        // A z druhé strany obráceně.
        Sanctum::actingAs($this->maki);

        $strany = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))
            ->mapWithKeys(fn (array $m) => [$m[5] => $m[1]]);

        $this->assertSame('M', $strany['Moje']);
        $this->assertSame('A', $strany['Její']);
    }

    /** Oddělovač dnů říká „Dnes" a „Včera", jinak den a datum. */
    public function test_den_se_pise_slovy(): void
    {
        $this->zprava(['body' => 'Dnešní', 'created_at' => now()->setTime(7, 42)]);
        $this->zprava(['body' => 'Včerejší', 'created_at' => now()->subDay()->setTime(19, 10)]);
        $this->zprava(['body' => 'Stará', 'created_at' => '2026-08-10 07:44:00']);

        $dny = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))
            ->mapWithKeys(fn (array $m) => [$m[5] => $m[2]]);

        $this->assertSame('Dnes', $dny['Dnešní']);
        $this->assertSame('Včera', $dny['Včerejší']);
        $this->assertSame('Pondělí 10. srpna', $dny['Stará']);
        $this->assertSame('7:42', collect($this->getJson('/api/data/zpravy')->json('data.MSGS'))
            ->firstWhere(5, 'Dnešní')[3]);
    }

    /**
     * Druh zprávy určuje, jak se bublina kreslí.
     *
     * Hlasovka má přehrávač, fotka náhled, soubor ikonu podle přípony.
     */
    public function test_druh_zpravy_se_pozna_z_prilohy(): void
    {
        $this->zprava(['body' => 'Text']);
        $this->zprava(['body' => 'Hlasovka o parkování', 'attachment_type' => 'voice', 'attachment_ref' => '0:38']);
        $this->zprava(['body' => 'Tohle bylo po východu', 'attachment_type' => 'photo', 'attachment_ref' => 'Pustevny']);
        $this->zprava(['body' => 'Itinerář Lisabon.pdf', 'media_mime' => 'application/pdf', 'media_size' => 1_887_437]);

        $z = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))
            ->mapWithKeys(fn (array $m) => [$m[5] => [$m[4], $m[6]]]);

        $this->assertSame(['t', ''], $z['Text']);
        $this->assertSame(['v', '0:38'], $z['Hlasovka o parkování']);
        $this->assertSame(['p', 'Pustevny'], $z['Tohle bylo po východu']);
        $this->assertSame(['f', '1,8 MB'], $z['Itinerář Lisabon.pdf']);
    }

    /** Přílohy dostanou svůj seznam s ikonou podle přípony. */
    public function test_prilohy_maji_vlastni_seznam(): void
    {
        $this->zprava(['body' => 'Rozpočet cesty.xlsx', 'media_mime' => 'application/vnd.ms-excel', 'media_size' => 86_016]);
        $this->zprava(['body' => 'Letenky.pdf', 'media_mime' => 'application/pdf', 'media_size' => 327_680]);
        $this->zprava(['body' => 'Jen text']);

        $soubory = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGFILES'))
            ->keyBy(0);

        $this->assertCount(2, $soubory);
        $this->assertSame(['Tabulka · 84 kB', 'ph-file-xls'], [$soubory['Rozpočet cesty.xlsx'][1], $soubory['Rozpočet cesty.xlsx'][2]]);
        $this->assertSame('ph-file-pdf', $soubory['Letenky.pdf'][2]);
    }

    /**
     * Prázdná bublina není zpráva.
     *
     * Po hrách a zrušených přílohách zůstávají řádky bez textu; v chatu by
     * vypadaly jako výpadek.
     */
    public function test_prazdne_zpravy_a_hry_se_neposilaji(): void
    {
        $this->zprava(['body' => 'Skutečná']);
        $this->zprava(['body' => '']);
        $this->zprava(['body' => '', 'attachment_type' => 'game', 'attachment_ref' => (string) Str::uuid()]);

        $z = $this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS');

        $this->assertCount(1, $z);
        $this->assertSame('Skutečná', $z[0][5]);
    }

    /** Smazaná zpráva se nevrací — dvojice ji smazala. */
    public function test_smazana_zprava_se_neposila(): void
    {
        $this->zprava(['body' => 'Zůstane']);
        $this->zprava(['body' => 'Smazaná'])->delete();

        $z = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))->map(fn ($m) => $m[5]);

        $this->assertSame(['Zůstane'], $z->all());
    }

    /** Útržek na úvodní obrazovce bere posledních pár replik, jen textových. */
    public function test_utrzek_na_uvodni_obrazovce(): void
    {
        foreach (range(1, 7) as $i) {
            $this->zprava(['body' => 'Replika '.$i, 'created_at' => now()->subMinutes(10 - $i)]);
        }
        $this->zprava(['body' => 'Hlasovka', 'attachment_type' => 'voice', 'created_at' => now()]);

        $utrzek = $this->getJson('/api/data/zpravy')->assertOk()->json('data.AMSG');

        $this->assertCount(5, $utrzek);
        $this->assertSame('Replika 3', $utrzek[0][1]);
        // Malá písmena, na rozdíl od chatu — prototyp je tak čte. Repliky psala
        // Makinka, dívá se Adrian, takže jsou „její".
        $this->assertSame('m', $utrzek[0][0]);
    }

    /** Zprávy jiného páru se do odpovědi nedostanou. */
    public function test_zpravy_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->zprava(['body' => 'Naše']);
        $this->zprava(['body' => 'Cizí', 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id]);

        $z = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))->map(fn ($m) => $m[5]);

        $this->assertSame(['Naše'], $z->all());
    }

    // ——— pomůcky ———

    private function zprava(array $navic = []): ChatMessage
    {
        $kdy = $navic['created_at'] ?? now();
        unset($navic['created_at']);

        $z = ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->maki->id,
            'body' => 'Zpráva',
        ], $navic));

        $z->forceFill(['created_at' => $kdy])->saveQuietly();

        return $z;
    }

    /**
     * Hlasovka v chatu nese odkaz na nahrávku.
     *
     * Bez něj byla bublina „hlasovka · 0:12" jen popiskem: pod ní nebylo
     * co pustit, ani hned, ani za rok. A délka se brala z toho, co si
     * napsal prohlížeč, ne z nahrávky.
     */
    public function test_hlasovka_nese_odkaz_na_nahravku(): void
    {
        $uuid = (string) Str::uuid();

        DB::table('voice_notes')->insert([
            'uuid' => $uuid,
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Hlasovka z 9:12',
            'path' => 'voice-notes/1/x.webm',
            'mime_type' => 'audio/webm',
            'size_bytes' => 2048,
            'duration_ms' => 74000,
            'recorded_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson('/api/state', ['data' => ['msgList' => [
            ['id' => 'g-n1', 'who' => 'A', 'type' => 'v', 'text' => 'Hlasovka z 9:12', 'audio' => $uuid],
        ]]])->assertOk();

        $radek = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))
            ->first(fn (array $m) => $m[5] === 'Hlasovka z 9:12');

        $this->assertSame('v', $radek[4], 'Zpráva s nahrávkou je hlasovka.');
        $this->assertSame('1:14', $radek[6], 'Délka se čte z nahrávky, ne z prohlížeče.');
        $this->assertSame($uuid, $radek[7]);
    }
}
