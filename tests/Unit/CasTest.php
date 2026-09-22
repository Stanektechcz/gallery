<?php

namespace Tests\Unit;

use App\Support\Cas;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Okamžiky se ukazují v pásmu dvojice, časy podle hodin se neposouvají.
 *
 * Obsah pro obrazovky formátoval UTC: zpráva odeslaná v 11:09 ukazovala 9:09.
 */
class CasTest extends TestCase
{
    public function test_okamzik_se_prevede_do_prahy(): void
    {
        config(['app.display_timezone' => 'Europe/Prague']);

        // Léto (UTC+2) i zima (UTC+1).
        $this->assertSame('11:09', Cas::mistni('2026-09-22 09:09:00')->format('G:i'));
        $this->assertSame('10:09', Cas::mistni('2026-01-22 09:09:00')->format('G:i'));
        $this->assertSame('11:09', Cas::mistni(CarbonImmutable::parse('2026-09-22 09:09:00', 'UTC'))->format('G:i'));
        $this->assertSame('2026-09-23', Cas::mistni('2026-09-22 22:30:00')->toDateString(), 'Po půlnoci v Praze je už další den.');
        $this->assertNull(Cas::mistni(null));
    }

    public function test_cas_podle_hodin_se_neposouva(): void
    {
        config(['app.display_timezone' => 'Europe/Prague']);

        $zacatek = Cas::zHodin('2026-09-22 18:00:00');
        $this->assertSame('18:00', $zacatek->format('G:i'));
        $this->assertSame('Europe/Prague', $zacatek->timezoneName);

        // Okamžik 16:30 UTC (18:30 v Praze) je po akci, která začala v 18:00.
        $this->assertTrue(Cas::mistni('2026-09-22 16:30:00')->gt($zacatek));
    }
}
