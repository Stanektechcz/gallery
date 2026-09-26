<?php

namespace Tests\Feature\Galerie;

use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dvě různá emoji od stejného člověka jsou dvě reakce, ne jedna.
 *
 * V `utf8mb4_unicode_ci` (provoz) mají skoro všechna emoji stejnou váhu:
 * smích po palci palec smazal, protože ho přepínač v `ChatController::react()`
 * našel jako „stejnou" reakci. SQLite porovnává po bajtech, takže tady
 * funkční test prochází i bez opravy — dokládá záměr a na MySQL v CI opravu
 * `2026_09_29_110000_emoji_reakci_binarne` skutečně ověří. Statické testy níž
 * hlídají samotnou migraci i tam, kde MySQL neběží.
 */
class EmojiReakciBinarneTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACE = 'migrations/2026_09_29_110000_emoji_reakci_binarne.php';

    public function test_dve_ruzna_emoji_od_stejneho_cloveka_vedle_sebe_zustanou(): void
    {
        $adri = User::factory()->create(['name' => 'Adrian']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id, 'is_default' => true]);
        $prostor->members()->syncWithoutDetaching([$adri->id => ['role' => 'owner']]);
        $zprava = ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'created_by' => $adri->id, 'body' => 'Dneska večer?',
        ]);

        Sanctum::actingAs($adri);

        $this->postJson("/api/v1/chat/{$zprava->uuid}/reakce", ['emoji' => '👍'])->assertOk();
        $odpoved = $this->postJson("/api/v1/chat/{$zprava->uuid}/reakce", ['emoji' => '😂'])->assertOk();

        $this->assertEqualsCanonicalizing(['👍', '😂'], collect($odpoved->json('reactions'))->pluck('emoji')->all(),
            'Smích nesmí palec nahradit ani smazat.');
        $this->assertSame(2, DB::table('chat_reactions')->where('chat_message_id', $zprava->id)->count());

        // Druhé klepnutí na smích ruší jen smích.
        $odpoved = $this->postJson("/api/v1/chat/{$zprava->uuid}/reakce", ['emoji' => '😂'])->assertOk();

        $this->assertSame(['👍'], collect($odpoved->json('reactions'))->pluck('emoji')->all());
    }

    /** Každý sloupec ze seznamu dostane `utf8mb4_bin` a zůstane jinak stejný. */
    public function test_migrace_da_sloupcum_s_emoji_binarni_porovnani(): void
    {
        $migrace = require database_path(self::MIGRACE);

        $this->assertSame('utf8mb4_bin', $migrace::POROVNANI);
        $this->assertNotEmpty($migrace::SLOUPCE);

        foreach ($migrace::SLOUPCE as $tabulka => $sloupce) {
            foreach ($sloupce as $sloupec => $definice) {
                $prikaz = $migrace::prikaz($tabulka, $sloupec, $definice, $migrace::POROVNANI);

                $this->assertStringStartsWith("ALTER TABLE `{$tabulka}` MODIFY `{$sloupec}` ", $prikaz);
                $this->assertStringContainsString('CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', $prikaz);

                [$typ, $povinny] = $this->puvodniDefinice($tabulka, $sloupec);
                $this->assertSame($typ, $definice['typ'], "{$tabulka}.{$sloupec}: jiný typ nebo délka než v původní migraci.");
                $this->assertSame($povinny, $definice['povinny'], "{$tabulka}.{$sloupec}: jiná povinnost (NULL) než v původní migraci.");
                $this->assertStringEndsWith($povinny ? ' NOT NULL' : ' NULL', $prikaz);
            }
        }
    }

    /**
     * Nový unikátní index se sloupcem `emoji` bez binárního porovnání se nesmí ztratit.
     *
     * Čte se z migrací: kdo přidá další tabulku s emoji v unikátním indexu,
     * musí ji zapsat i do `SLOUPCE` — jinak na MySQL potká stejnou chybu.
     */
    public function test_kazdy_unikatni_index_s_emoji_je_v_seznamu(): void
    {
        $migrace = require database_path(self::MIGRACE);
        $nalezene = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $soubor) {
            $tabulka = null;

            foreach (file($soubor) ?: [] as $radek) {
                if (preg_match("/Schema::(?:create|table)\\('([a-z0-9_]+)'/", $radek, $m) === 1) {
                    $tabulka = $m[1];
                }

                if ($tabulka !== null && preg_match("/->unique\\(\\[[^\\]]*'emoji'/", $radek) === 1) {
                    $nalezene[] = $tabulka;
                }
            }
        }

        $this->assertContains('chat_reactions', $nalezene, 'Sken migrací nenašel ani známý index — zastaral.');

        foreach (array_unique($nalezene) as $tabulka) {
            $this->assertArrayHasKey($tabulka, $migrace::SLOUPCE,
                "{$tabulka} má emoji v unikátním indexu, ale binární porovnání nedostane.");
        }
    }

    /**
     * Sloupec v databázi testu, postavené všemi migracemi.
     *
     * Migrace se tu znovu nespouští: na MySQL je `ALTER TABLE` DDL a potvrdil
     * by transakci `RefreshDatabase`. Na SQLite je prázdná a sloupec zůstává
     * binární (bez NOCASE).
     */
    public function test_skutecny_sloupec_porovnava_po_bajtech(): void
    {
        $sloupec = collect(Schema::getColumns('chat_reactions'))->firstWhere('name', 'emoji');
        $this->assertNotNull($sloupec);

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertSame('utf8mb4_bin', $sloupec['collation']);
            $this->assertFalse($sloupec['nullable']);
        } else {
            $this->assertContains(strtolower((string) $sloupec['collation']), ['', 'binary']);
        }
    }

    /**
     * Typ a povinnost sloupce tak, jak ho založila původní migrace.
     *
     * @return array{0: string, 1: bool}
     */
    private function puvodniDefinice(string $tabulka, string $sloupec): array
    {
        foreach (glob(database_path('migrations/*.php')) ?: [] as $soubor) {
            if (str_ends_with($soubor, basename(self::MIGRACE))) {
                continue;
            }

            $tabulkaVSouboru = null;

            foreach (file($soubor) ?: [] as $radek) {
                if (preg_match("/Schema::create\\('([a-z0-9_]+)'/", $radek, $m) === 1) {
                    $tabulkaVSouboru = $m[1];
                }

                if ($tabulkaVSouboru === $tabulka
                    && preg_match("/->string\\('".preg_quote($sloupec, '/')."'(?:,\\s*(\\d+))?\\)/", $radek, $m) === 1) {
                    return ['VARCHAR('.($m[1] ?? 255).')', ! str_contains($radek, '->nullable(')];
                }
            }
        }

        $this->fail("Původní definice {$tabulka}.{$sloupec} se v migracích nenašla.");
    }
}
