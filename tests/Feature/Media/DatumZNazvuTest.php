<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `gallery:reconcile-dates` se musí dostat dál než k prvním N řádkům.
 *
 * `limit` bez řazení a bez místa, kde pokračovat: fotky bez data v názvu
 * zůstanou bez data navždy, takže každý další běh vzal znovu tytéž první
 * řádky a na zbytek archivu se nedostal nikdy.
 */
class DatumZNazvuTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    public function test_druhy_beh_pokracuje_za_poslednim_zpracovanym(): void
    {
        $this->zalozProstor();
        $this->media(['original_filename' => 'dovolena.jpg']);
        $druha = $this->media(['original_filename' => 'plaz.jpg']);
        $sDatem = $this->media(['original_filename' => '20260701_145133.jpg']);

        $this->artisan('gallery:reconcile-dates', ['--apply' => true, '--limit' => 2])
            ->expectsOutputToContain("--after-id={$druha->id}")
            ->assertExitCode(0);
        $this->assertNull(DB::table('media_items')->where('id', $sDatem->id)->value('taken_at'));

        $this->artisan('gallery:reconcile-dates', ['--apply' => true, '--limit' => 2, '--after-id' => $druha->id])
            ->assertExitCode(0);

        $this->assertSame('2026-07-01 14:51:33', DB::table('media_items')->where('id', $sDatem->id)->value('taken_at'));
    }
}
