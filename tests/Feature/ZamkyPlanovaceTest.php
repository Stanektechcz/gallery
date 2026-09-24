<?php

namespace Tests\Feature;

use App\Listeners\ZaznamenejBehUlohy;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Zámek proti souběhu nesmí držet den a selhání se musí dozvědět.
 *
 * Dvě tiché věci, které se poznají teprve tím, že se týden nic nestane.
 */
class ZamkyPlanovaceTest extends TestCase
{
    /**
     * Holé `withoutOverlapping()` drží zámek 24 hodin.
     *
     * Výchozí `$expiresAt` je 1440 minut. `releaseOnTerminationSignals` pokryje
     * SIGTERM a SIGINT, ale ne SIGKILL, zabití kvůli paměti ani výpadek proudu —
     * a po jednom takovém konci se minutová úloha neprovede celý den. Nikde to
     * nevypadá jako porucha: připomínky prostě nechodí.
     */
    public function test_zamek_proti_soubehu_ma_rozumnou_platnost(): void
    {
        $kod = (string) file_get_contents(base_path('routes/console.php'));

        preg_match_all("/Schedule::command\((.*?)->name\('([a-z-]+)'\)/s", $kod, $shody, PREG_SET_ORDER);

        $bezPlatnosti = [];

        foreach ($shody as $shoda) {
            if (preg_match('/->withoutOverlapping\(\s*\)/', $shoda[1])) {
                $bezPlatnosti[] = '  '.$shoda[2];
            }
        }

        $this->assertSame([], $bezPlatnosti,
            "Zámek bez uvedené platnosti drží 24 hodin:\n".implode("\n", $bezPlatnosti));
    }

    /**
     * Úloha na pozadí musí mít kam ohlásit, že spadla.
     *
     * `runInBackground()` vidličkou odštěpí proces a `ScheduleRunCommand` hned
     * nato pošle `ScheduledTaskFinished` s nulovou dobou běhu a bez návratového
     * kódu — skutečný konec ohlásí až `ScheduledBackgroundTaskFinished`, na
     * který nikdo neposlouchal. `gallery:doctor` tedy mohl vracet FAILURE
     * donekonečna a v administraci se o tom nic neobjevilo.
     */
    public function test_konec_ulohy_na_pozadi_ma_posluchace(): void
    {
        $this->assertTrue(Event::hasListeners(ScheduledBackgroundTaskFinished::class),
            'Bez posluchače se návratový kód úlohy na pozadí nikde neobjeví.');
    }

    /** A ten posluchač ten návratový kód opravdu umí přijmout. */
    public function test_posluchac_umi_konec_na_pozadi(): void
    {
        $this->assertTrue(method_exists(ZaznamenejBehUlohy::class, 'skoncilNaPozadi'));
    }
}
