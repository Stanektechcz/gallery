<?php

namespace Tests\Unit;

use App\Jobs\SpustPlanovanouUlohu;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Přeskočené a běžící na pozadí se nesmí hlásit jako HOTOVO.
 *
 * `Event::run()` u `withoutOverlapping()` nechá `exitCode` `null` a nic
 * neproběhlo; u `runInBackground()` se proces jen odštěpí a výsledek ještě
 * není znám. Dřív `SpustPlanovanouUlohu` obojí zapsalo se stavem HOTOVO
 * a kódem 0 — administrace pak tvrdila, že ruční spuštění doběhlo, i když
 * se nic nespustilo nebo výsledek ještě nikdo nezná.
 */
class SpustPlanovanouUlohuTest extends TestCase
{
    private ReflectionMethod $preskoceno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preskoceno = new ReflectionMethod(SpustPlanovanouUlohu::class, 'preskoceno');
        $this->preskoceno->setAccessible(true);
    }

    public function test_preskoceno_kvuli_prekryvu_neni_hotovo(): void
    {
        $uloha = $this->udalost();
        $uloha->skippedBecauseOverlapping = true;
        $uloha->exitCode = null;

        $this->assertTrue($this->preskoceno->invoke(null, $uloha));
    }

    public function test_bezici_na_pozadi_bez_znameho_vysledku_neni_hotovo(): void
    {
        $uloha = $this->udalost();
        $uloha->runInBackground = true;
        $uloha->exitCode = null;

        $this->assertTrue($this->preskoceno->invoke(null, $uloha));
    }

    /** Doběhlá úloha na pozadí (výsledek už je znám) se hlásí normálně. */
    public function test_dobehla_uloha_na_pozadi_neni_preskocena(): void
    {
        $uloha = $this->udalost();
        $uloha->runInBackground = true;
        $uloha->exitCode = 0;

        $this->assertFalse($this->preskoceno->invoke(null, $uloha));
    }

    /** Obyčejný úspěšný běh se nehlásí jako přeskočený. */
    public function test_normalni_beh_neni_preskoceny(): void
    {
        $uloha = $this->udalost();
        $uloha->exitCode = 0;

        $this->assertFalse($this->preskoceno->invoke(null, $uloha));
    }

    private function udalost(): Event
    {
        // Skutečný `->run()` se v testu nevolá — mutex tu jen vyplňuje
        // konstruktor, jehož metody `Event` nepoužije.
        $mutex = new class implements EventMutex
        {
            public function create(Event $event): bool
            {
                return true;
            }

            public function exists(Event $event): bool
            {
                return false;
            }

            public function forget(Event $event): void {}
        };

        return new Event($mutex, 'php -v');
    }
}
