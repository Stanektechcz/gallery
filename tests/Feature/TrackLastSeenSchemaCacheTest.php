<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `Schema::hasColumn` se neptá databáze při každém požadavku.
 *
 * `TrackLastSeen` volalo `Schema::hasColumn('users', 'last_seen_at')` na
 * úplně každém přihlášeném požadavku — na MySQL nekešovaný dotaz do
 * `information_schema` navíc ke všem ostatním dotazům stránky. Sloupec se
 * po nasazení migrace neztrácí, takže se to stačí zeptat jednou za běh procesu.
 */
class TrackLastSeenSchemaCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_druhy_pozadavek_uz_se_neptá_schematu(): void
    {
        // Statická mezipaměť prostorů se mezi testy stejného procesu sama
        // nezneplatní a SQLite po `RefreshDatabase` recykluje ID.
        SpaceContext::forget();

        $uzivatel = User::factory()->create(['role' => 'owner']);
        $prostor = GallerySpace::create(['name' => 'Galerie', 'owner_id' => $uzivatel->id]);
        $prostor->members()->syncWithoutDetaching([$uzivatel->id => ['role' => 'owner']]);

        Sanctum::actingAs($uzivatel);

        // První požadavek smí sloupec zjišťovat.
        $this->getJson('/api/v1/recovery/duplicates')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/v1/recovery/duplicates')->assertOk();
        $dotazy = DB::getQueryLog();
        DB::disableQueryLog();

        $schemaDotaz = array_filter($dotazy, function (array $q) {
            $sql = mb_strtolower($q['query']);

            return str_contains($sql, 'information_schema')
                || str_contains($sql, 'pragma_table_info')
                || str_contains($sql, 'pragma_table_xinfo')
                || str_contains($sql, 'pragma table_info')
                || str_contains($sql, 'sqlite_master');
        });

        $this->assertSame([], array_values($schemaDotaz), 'TrackLastSeen se pořád ptá schématu na každém požadavku.');
    }
}
