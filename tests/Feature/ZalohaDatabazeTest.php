<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Databáze se zálohuje — a ze zálohy jde obnovit.
 *
 * `BACKUP_AND_RESTORE.md` popisoval noční `BackupMetadataJob` a příkazy
 * `gallery:backup-metadata` a `gallery:restore-from-backup`. Žádný z nich
 * neexistoval. Deník, finance, alba, lidé i poznámky byly jen v databázi
 * a aplikace je nezálohovala nikam.
 *
 * Záloha nese data, ne schéma: schéma postaví `migrate` z kódu a obnova do
 * něj data vloží. Tak funguje stejně na MySQL (provoz) i na SQLite (testy)
 * a dá se tady ověřit celým kruhem — neověřená záloha je stejná past jako
 * žádná.
 *
 * Bez obalové transakce: SQLite uvnitř transakce vypnutí cizích klíčů
 * ignoruje, a obnova je potřebuje vypnout. Proto ani `RefreshDatabase`
 * (transakce), ani `DatabaseMigrations` (po testu vrací migrace a jedna
 * starší se na SQLite vrátit nedá) — každý test si databázi postaví sám.
 */
class ZalohaDatabazeTest extends TestCase
{
    private const SLEDOVANE = ['users', 'gallery_spaces', 'gallery_space_user', 'albums', 'tags', 'couple_states'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        Storage::fake('local');
    }

    public function test_zaloha_a_obnova_vrati_data_beze_zmeny(): void
    {
        $this->naplnGalerii();
        $pred = $this->snimek();

        $this->artisan('gallery:zaloha')->assertExitCode(0);
        $soubor = $this->posledniZaloha();

        // Katastrofa: data jsou pryč.
        DB::table('tags')->delete();
        DB::table('couple_states')->delete();
        DB::table('albums')->delete();

        $this->artisan('gallery:obnova', ['soubor' => $soubor, '--opravdu' => true])->assertExitCode(0);

        $this->assertSame($pred, $this->snimek(), 'Obnova nevrátila data tak, jak byla.');
    }

    public function test_obnova_bez_opravdu_nic_nezmeni(): void
    {
        $this->naplnGalerii();
        $this->artisan('gallery:zaloha')->assertExitCode(0);
        DB::table('tags')->delete();

        $this->artisan('gallery:obnova', ['soubor' => $this->posledniZaloha()])
            ->expectsOutputToContain('--opravdu')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('tags')->count(), 'Obnova bez --opravdu sáhla na data.');
    }

    public function test_zaloha_vynecha_docasne_tabulky(): void
    {
        $this->naplnGalerii();
        DB::table('sessions')->insert([
            'id' => Str::random(40), 'user_id' => null, 'ip_address' => '127.0.0.1',
            'user_agent' => 'x', 'payload' => 'tajne-sezeni', 'last_activity' => time(),
        ]);

        $this->artisan('gallery:zaloha')->assertExitCode(0);

        $obsah = gzdecode(Storage::disk('local')->get($this->posledniZaloha()));
        $this->assertStringNotContainsString('tajne-sezeni', $obsah, 'Záloha nese přihlášená sezení.');
        $this->assertStringContainsString('"t":"tags"', $obsah);
    }

    public function test_zaloha_drzi_jen_poslednich_n(): void
    {
        config(['gallery.backup_keep' => 2]);
        $this->naplnGalerii();

        foreach (['2026-09-20 03:30', '2026-09-21 03:30', '2026-09-22 03:30'] as $kdy) {
            $this->travelTo(now()->parse($kdy));
            $this->artisan('gallery:zaloha')->assertExitCode(0);
        }

        $zalohy = Storage::disk('local')->files('zalohy');
        sort($zalohy);
        $this->assertCount(2, $zalohy, 'Starší zálohy se neuklízí.');
        $this->assertStringContainsString('2026-09-22', end($zalohy));
    }

    /** Zálohu z novějšího kódu obnova odmítne — nesla by tabulky, které tu nejsou. */
    public function test_obnova_odmitne_zalohu_z_novejsiho_kodu(): void
    {
        $soubor = $this->rucniZaloha(['2099_01_01_000000_z_budoucnosti'], ['tags' => []]);

        $this->artisan('gallery:obnova', ['soubor' => $soubor, '--opravdu' => true])
            ->expectsOutputToContain('novějšího kódu')
            ->assertExitCode(1);
    }

    /** Sloupec, který mezitím ze schématu zmizel, se přeskočí a řekne se to. */
    public function test_obnova_snese_sloupec_ktery_uz_neni(): void
    {
        $this->naplnGalerii();
        $prostor = (int) DB::table('gallery_spaces')->value('id');
        $soubor = $this->rucniZaloha(null, ['tags' => [[
            'id' => 900, 'gallery_space_id' => $prostor, 'name' => 'Staré', 'slug' => 'stare',
            'depth' => 0, 'materialized_path' => '', 'zrusenej_sloupec' => 'x',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]]]);

        $this->artisan('gallery:obnova', ['soubor' => $soubor, '--opravdu' => true])
            ->expectsOutputToContain('zrusenej_sloupec')
            ->assertExitCode(0);

        $this->assertSame('Staré', DB::table('tags')->where('id', 900)->value('name'));
    }

    // ——— pomocné ———

    private function naplnGalerii(): void
    {
        $adri = User::factory()->create(['name' => 'Adrian']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);
        $prostor->members()->syncWithoutDetaching([$adri->id => ['role' => 'owner']]);

        DB::table('albums')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'title' => 'Léto u moře',
            'slug' => 'leto-u-more', 'visibility' => 'shared', 'created_by' => $adri->id,
            'created_at' => '2026-07-01 10:00:00', 'updated_at' => '2026-07-01 10:00:00',
        ]);
        foreach (['Rodina', 'Výlety — hory & moře', "Uvozovky \" a ' apostrof"] as $i => $nazev) {
            DB::table('tags')->insert([
                'gallery_space_id' => $prostor->id, 'name' => $nazev, 'slug' => 'stitek-'.$i,
                'created_at' => '2026-07-01 10:00:00', 'updated_at' => '2026-07-01 10:00:00',
            ]);
        }
        DB::table('couple_states')->insert([
            'couple_id' => $prostor->id,
            'data' => json_encode(['klTasks' => [['id' => 1, 't' => 'Zavolat babičce']], 'emoji' => '❤']),
            'created_at' => '2026-07-01 10:00:00', 'updated_at' => '2026-07-01 10:00:00',
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snimek(): array
    {
        $snimek = [];

        foreach (self::SLEDOVANE as $tabulka) {
            $snimek[$tabulka] = DB::table($tabulka)->get()
                ->map(fn ($r) => (array) $r)
                ->sortBy(fn ($r) => json_encode($r))
                ->values()
                ->all();
        }

        return $snimek;
    }

    private function posledniZaloha(): string
    {
        $zalohy = Storage::disk('local')->files('zalohy');
        sort($zalohy);
        $this->assertNotEmpty($zalohy, 'Záloha nevznikla.');

        return end($zalohy);
    }

    /**
     * Záloha složená ručně — hlavička a řádky.
     *
     * @param  list<string>|null  $migrace  `null` = migrace této databáze
     * @param  array<string, list<array<string, mixed>>>  $radky
     */
    private function rucniZaloha(?array $migrace, array $radky): string
    {
        $migrace ??= DB::table('migrations')->pluck('migration')->all();
        $obsah = json_encode(['zaloha' => 1, 'migrace' => $migrace, 'tabulky' => array_map('count', $radky)])."\n";

        foreach ($radky as $tabulka => $seznam) {
            foreach ($seznam as $radek) {
                $obsah .= json_encode(['t' => $tabulka, 'r' => $radek])."\n";
            }
        }

        Storage::disk('local')->put('zalohy/rucni.ndjson.gz', gzencode($obsah));

        return 'zalohy/rucni.ndjson.gz';
    }
}
