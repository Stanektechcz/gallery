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

    /**
     * Dnešní datum dvojice je porovnatelné s daty z databáze.
     *
     * V 23:30 UTC je v Praze už další den; datum se ale musí dát srovnat
     * s půlnocí `start_date` bez posunu o dvě hodiny (jinak by v den odjezdu
     * cesta ještě „plánovala").
     */
    public function test_dnesni_datum_dvojice(): void
    {
        config(['app.display_timezone' => 'Europe/Prague']);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 23:30:00', 'UTC'));

        $dnes = Cas::dnes();
        $odjezd = CarbonImmutable::parse('2026-10-09');

        $this->assertSame('2026-10-09', $dnes->toDateString());
        $this->assertTrue($dnes->equalTo($odjezd), 'V den odjezdu je dnešek roven začátku cesty.');
        $this->assertSame(3, (int) $odjezd->diffInDays(CarbonImmutable::parse('2026-10-12')));
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

    /**
     * Čas z požadavku se do DATETIME zapíše v tvaru, který vezme i MySQL.
     *
     * ISO s pásmem MySQL ve striktním režimu odmítne (SQLite ho spolkne),
     * proto se to nedá chytit zápisem do testovací databáze — hlídá se tvar.
     */
    public function test_cas_pro_databazi_bez_pasma_a_v_hodinach_cesty(): void
    {
        config(['app.display_timezone' => 'Europe/Prague']);

        // Okamžik s pásmem → hodiny cesty (v říjnu ještě letní čas, UTC+2).
        $this->assertSame('2026-10-26 01:00:00', Cas::hodinyProDb('2026-10-26T00:00:00.000000Z'));
        $this->assertSame('2026-10-01 02:00:00', Cas::hodinyProDb('2026-10-01T00:00:00.000Z'));
        $this->assertSame('2026-10-01 09:00:00', Cas::hodinyProDb('2026-10-01T02:00:00+02:00', 'Asia/Tokyo'));
        // Půlnoc v Praze poslaná přes toISOString() zůstane ve správném dni.
        $this->assertSame('2026-10-27 00:00:00', Cas::hodinyProDb('2026-10-26T23:00:00.000Z'));

        // Bez pásma jsou to hodiny, jak je člověk napsal — neposouvají se.
        $this->assertSame('2026-10-26 18:30:00', Cas::hodinyProDb('2026-10-26T18:30'));
        $this->assertSame('2026-10-26 00:00:00', Cas::hodinyProDb('2026-10-26'));
        $this->assertSame('2026-10-26 18:30:00', Cas::hodinyProDb('2026-10-26 18:30:00', 'America/New_York'));

        // Neplatné pásmo cesty nespadne, vezme se pásmo dvojice.
        $this->assertSame('2026-10-01 02:00:00', Cas::hodinyProDb('2026-10-01T00:00:00Z', 'Není/Pásmo'));
    }
}
