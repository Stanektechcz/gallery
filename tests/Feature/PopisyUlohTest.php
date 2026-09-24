<?php

namespace Tests\Feature;

use App\Services\Provoz\PlanovaneUlohy;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionClass;
use Tests\TestCase;

/**
 * Každá plánovaná úloha se v administraci jmenuje česky.
 *
 * `PlanovaneUlohy::popis()` má záchranu: když název v seznamu chybí, udělá
 * z klíče `billing-reminders` nadpis „Billing reminders". Tím se nová úloha
 * nikdy nerozbije — jen se na obrazovku pro dvě české osoby propíše anglicky.
 * Přesně to se už jednou stalo třem úlohám a všimlo si toho až oko.
 *
 * Záchrana tu zůstává (spadlá administrace by byla horší), ale zapomenutí
 * teď shodí test místo toho, aby čekalo na čtenáře.
 */
class PopisyUlohTest extends TestCase
{
    public function test_kazda_uloha_ma_cesky_nazev(): void
    {
        $ulohy = $this->nazvyUloh();

        $this->assertNotEmpty($ulohy, 'Plán je prázdný — test by nic nehlídal.');

        $chybi = array_values(array_diff($ulohy, array_keys($this->popisy())));

        $this->assertSame([], $chybi,
            "Tyhle úlohy nemají v `PlanovaneUlohy::POPISY` český název a administrace je napíše anglicky:\n"
            .implode("\n", array_map(fn ($nazev) => '  '.$nazev, $chybi)));
    }

    /** A seznam názvů nesmí zastarat po zrušené úloze. */
    public function test_seznam_nazvu_neobsahuje_zrusene_ulohy(): void
    {
        $ulohy = $this->nazvyUloh();

        $navic = array_values(array_diff(array_keys($this->popisy()), $ulohy));

        $this->assertSame([], $navic,
            "Tyhle názvy už žádná úloha nemá — vyřaďte je:\n"
            .implode("\n", array_map(fn ($nazev) => '  '.$nazev, $navic)));
    }

    /** @return array<string, string> */
    private function popisy(): array
    {
        return (new ReflectionClass(PlanovaneUlohy::class))->getConstant('POPISY');
    }

    /** @return list<string> */
    private function nazvyUloh(): array
    {
        $ulohy = new PlanovaneUlohy;

        return array_map(fn ($uloha) => $ulohy->nazev($uloha), app(Schedule::class)->events());
    }
}
