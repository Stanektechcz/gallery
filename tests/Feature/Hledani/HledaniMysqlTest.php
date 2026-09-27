<?php

namespace Tests\Feature\Hledani;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FULLTEXT na MySQL: řazení podle shody a slova bez diakritiky.
 *
 * InnoDB zapisuje do fulltextového indexu až při potvrzení transakce —
 * v obvyklém testu (vše v jedné transakci, která se na konci vrátí) by
 * `MATCH … AGAINST` neviděl nic a hledání by skončilo u záchranného `LIKE`.
 * Tahle třída proto transakci nepoužívá a po sobě uklízí sama. Na SQLite
 * se přeskočí: FULLTEXT tam není.
 */
class HledaniMysqlTest extends TestCase
{
    use RefreshDatabase;
    use VytvariFotky;

    /**
     * Na MySQL bez obalové transakce — viz popis třídy.
     *
     * Na SQLite zůstává výchozí chování: databáze v paměti se mezi testy
     * předává právě přes tenhle seznam, a bez něj by další třídy dostaly
     * prázdnou databázi bez tabulek.
     */
    protected function connectionsToTransact(): array
    {
        $vychozi = config('database.default');

        return config("database.connections.{$vychozi}.driver") === 'mysql' ? [] : [$vychozi];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('FULLTEXT se ověřuje jen na MySQL.');
        }

        $this->zalozDvojici();
        Sanctum::actingAs($this->adri);
    }

    protected function tearDown(): void
    {
        if (isset($this->prostor)) {
            DB::table('media_items')->where('gallery_space_id', $this->prostor->id)->delete();
            DB::table('gallery_space_user')->where('gallery_space_id', $this->prostor->id)->delete();
            DB::table('gallery_spaces')->where('id', $this->prostor->id)->delete();
            DB::table('users')->where('id', $this->adri->id)->delete();
        }

        parent::tearDown();
    }

    public function test_razeni_podle_shody_ne_podle_data(): void
    {
        // Starší fotka sedí líp (slovo třikrát), novější hůř — řadí se podle shody.
        $lepsi = $this->hledatelna(['caption' => 'Chata, chata, chata na Lysé hoře', 'taken_at' => '2019-01-01 10:00:00']);
        $horsi = $this->hledatelna(['caption' => 'Chata u moře', 'taken_at' => '2024-01-01 10:00:00']);
        $this->hledatelna(['caption' => 'Pláž v Chorvatsku', 'taken_at' => '2024-02-01 10:00:00']);
        $this->hledatelna(['caption' => 'Zahrada u babičky', 'taken_at' => '2024-03-01 10:00:00']);

        $vse = $this->getJson('/api/v1/search?q=chata')->assertOk()->assertJsonPath('meta.uroven', 'and');
        $this->assertSame([$lepsi->uuid, $horsi->uuid], array_column($vse->json('data'), 'uuid'));

        // Neznámé slovo: druhý stupeň přes FULLTEXT bez `+`, víc zásahů výš.
        $cast = $this->getJson('/api/hledat?q='.urlencode('chata hoře xyzzy'))->assertOk()->assertJsonPath('uroven', 'or');
        $this->assertSame([$lepsi->uuid, $horsi->uuid], array_column($cast->json('polozky'), 'id'));
    }

    public function test_fulltext_najde_slova_bez_diakritiky_a_bez_zachranneho_like(): void
    {
        $chata = $this->hledatelna(['caption' => 'Chata na Lysé hoře']);
        $this->hledatelna(['caption' => 'Pláž v Chorvatsku']);
        $this->hledatelna(['caption' => 'Zahrada u babičky']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $ids = array_column($this->getJson('/api/hledat?q='.urlencode('LYSE hore'))->assertOk()->json('polozky'), 'id');
        $dotazy = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower((string) $q));
        DB::disableQueryLog();

        $this->assertSame([$chata->uuid], $ids);
        $this->assertTrue($dotazy->contains(fn ($q) => str_contains($q, 'match (')), 'Hledání nešlo přes FULLTEXT.');
        $this->assertFalse($dotazy->contains(fn ($q) => str_contains($q, '`search_text` like')), 'Hledání skončilo u záchranného LIKE.');
    }
}
