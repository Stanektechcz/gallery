<?php

use App\Models\SystemSetting;
use App\Services\Provoz\PlanovaneUlohy;
use Illuminate\Support\Facades\Schedule;

/*
 * Pásmo dvojice pro úlohy s pevnou hodinou.
 *
 * `config('app.timezone')` je UTC, takže „v devět ráno" znamenalo jedenáct
 * pražského času. U úklidu nad ránem to nevadí, u výročí a denního přehledu
 * ano — ty se posílají lidem a hodina je na nich to podstatné. Úlohy bez pevné
 * hodiny (každou minutu, hodinově) pásmo nepotřebují.
 *
 * Hlídá `tests/Feature/CasovaPasmaPrikazuTest.php`.
 */
$pasmo = config('app.display_timezone', 'Europe/Prague');

/*
 * Jak dlouho drží zámek proti souběhu.
 *
 * Holé `withoutOverlapping()` ho drží **1440 minut**, tedy celý den.
 * `releaseOnTerminationSignals` pokryje SIGTERM a SIGINT, ale ne SIGKILL,
 * zabití kvůli paměti ani výpadek proudu — a po jednom takovém konci se
 * minutová úloha den neprovede. Nikde to nevypadá jako porucha: připomínky
 * prostě nechodí a nikdo neví proč.
 *
 * Deset minut je s rezervou víc, než kterákoli z těchhle úloh potřebuje, a
 * zároveň tak krátce, že se výpadek srovná sám. Hlídá
 * `tests/Feature/ZamkyPlanovaceTest.php`.
 */
$zamekMinut = 10;

Schedule::command('gallery:deliver-reminders --no-interaction')
    ->everyMinute()
    ->withoutOverlapping($zamekMinut)
    ->name('calendar-reminders');

// Every minute, because the moment's time is drawn per space per day — there is no hour
// to hang this on, which is exactly what stops anyone from being ready for it.
Schedule::command('gallery:daily-moment --no-interaction')
    ->everyMinute()
    ->withoutOverlapping($zamekMinut)
    ->name('daily-moment');

// Připomínka blížící se menstruace. Příkaz si sám hlídá denní dobu i to, aby za jedno
// dopoledne neposlal dvě zprávy — plánovač ho proto může volat klidně každou hodinu.
Schedule::command('gallery:cycle-reminders --no-interaction')
    ->hourly()
    ->withoutOverlapping($zamekMinut)
    ->name('cycle-reminders');

// Večerní souhrn pro ty, kdo si ho zapnuli. Příkaz si hlídá čas i to, aby za jeden
// večer neposlal dva.
Schedule::command('gallery:notification-digest --no-interaction')
    ->hourly()
    ->withoutOverlapping($zamekMinut)
    ->name('notification-digest');

// Automatické štítky z data a místa. V noci, protože prochází celý archiv.
Schedule::command('gallery:auto-tag --apply --no-interaction')
    ->dailyAt('03:20')
    ->timezone($pasmo)
    ->withoutOverlapping($zamekMinut)
    ->name('auto-tag');

/*
 * Tři úlohy vyřazeného rozpočtového systému tady byly do 29. 8. 2026 a odešly s ním.
 *
 * `money-request-reminders` připomínal žádosti o peníze, které se od odstranění
 * `BudgetController` nedají založit ani zodpovědět — žádnou obrazovku nikdy neměly.
 * `budget-alerts` a `recurring-entries` pracovaly nad rozpočty, které po smazání
 * starých obrazovek a API nejde otevřít. Upozornění na něco, co se nedá otevřít, je
 * horší než ticho: člověk ho jde hledat a nenajde.
 *
 * Data v `budget_entries` a `money_requests` zůstala. Odešel jen kód, který na ně
 * sahal, takže se dají kdykoli přečíst z databáze.
 *
 * Modul Rozpočet si upozornění počítá sám a ukazuje je v přehledu. Push notifikace
 * pro něj zatím nejsou — je to samostatná práce, ne něco, co by tímhle zaniklo.
 */
// Old plans should never remain in current views simply because nobody opened the calendar.
Schedule::command('gallery:close-elapsed-events --no-interaction')
    ->dailyAt('00:05')
    ->timezone($pasmo)
    ->withoutOverlapping($zamekMinut)
    ->name('close-elapsed-calendar-events');
Schedule::command('gallery:planning-followups --no-interaction')
    ->hourly()
    ->withoutOverlapping($zamekMinut)
    ->name('planning-followups');

Schedule::command('gallery:relationship-milestones --no-interaction')
    ->dailyAt('09:00')
    ->timezone($pasmo)
    ->withoutOverlapping($zamekMinut)
    ->name('relationship-milestones');

// Vzpomínky na dnešek a zítřek + jedno oznámení o té nejsilnější. Příkaz existoval,
// ale v plánovači nebyl: záložka Vzpomínky se sama nikdy neobnovila a připomínka
// „v 8:30", kterou slibuje nastavení, nepřišla. V pásmu dvojice, ne v UTC serveru.
Schedule::command('gallery:memories --no-interaction')
    ->dailyAt('08:30')
    ->timezone(config('app.display_timezone', 'Europe/Prague'))
    ->withoutOverlapping($zamekMinut)
    ->name('memories');

Schedule::command('gallery:sync-cinema --days=10 --no-interaction')
    ->dailyAt('06:15')
    ->timezone($pasmo)
    ->withoutOverlapping($zamekMinut)
    ->name('cinema-city-program');

// PSD2 providers commonly limit unattended account access to four reads per day.
Schedule::command('gallery:sync-banking --no-interaction')
    ->everySixHours()
    ->withoutOverlapping($zamekMinut)
    ->name('read-only-bank-sync');

// Scheduler tasks
Schedule::command('gallery:doctor --no-interaction')
    ->everyFiveMinutes()
    ->runInBackground()
    ->name('storage-health');

/*
 * `queue:retry all` tu stávalo každých deset minut a bylo horší než nic.
 *
 * `RetryCommand` vynuluje počet pokusů a smaže řádek z `failed_jobs`. Úloha,
 * která padá trvale, se tím točila donekonečna — `--tries=3` ji nikdy
 * neukončilo, protože pokusy se každých deset minut vrátily na nulu. A hlavně:
 * `gallery:doctor` hlásí poruchu od deseti záznamů ve `failed_jobs` výš, jenže
 * ta tabulka byla do deseti minut zase prázdná. Jediné místo, kde by se dvojice
 * dozvěděla, že něco spadlo, tak mlčelo.
 *
 * Opakovat se má to, o čem někdo rozhodl — `queue:retry <id>` ručně, podle
 * toho, co ve `failed_jobs` opravdu leží. Hlídá `tests/Feature/FrontaTest.php`.
 */

/*
 * Fronta se vyprázdní, i když démona nikdo nespustil.
 *
 * Úlohy leží v databázi a bere si je trvale běžící `queue:work` pod dohledem
 * supervisoru. Když ho nikdo nenastaví — nebo spadne po restartu serveru —
 * fronta jen tiše roste: doktor v ní našel 2 371 úloh, z nichž nejstarší
 * čekala třináct dní. Stály s nimi náhledy, zrcadlení originálů na Disk
 * i upozornění, a nikde to nevypadalo jako porucha; prostě se nic nedělo.
 *
 * Tohle démona nenahrazuje, jen jistí. `--stop-when-empty` skončí, jakmile
 * není co dělat, `--max-time` běh ukončí i tehdy, když práce přibývá rychleji,
 * a `withoutOverlapping` hlídá, aby vedle sebe neběželo víc kopií. Zámek drží
 * deset minut: kdyby proces spadl, nesmí frontu zablokovat napořád.
 *
 * `--queue=` tu chybělo, a tím padal celý smysl téhle pojistky: bez něj bere
 * `queue:work` jen `default`, zatímco náhledy, převody a zrcadlení na Disk
 * chodí na `media`, `drive` a `high`. Ten incident s 2 371 úlohami byl přesně
 * tenhle nedobraný zbytek. Pořadí je pořadí přednosti.
 */
Schedule::command('queue:work --queue=high,default,media,drive --stop-when-empty --max-time=280 --tries=3 --no-interaction')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('queue-drain');

Schedule::command('gallery:rebuild-albums')
    ->hourly()
    ->name('quick-reconciliation');

Schedule::command('gallery:status')
    ->dailyAt('02:00')
    ->timezone($pasmo)
    ->name('daily-status');

// Kopie originálů do cloudu, které tam ještě nejsou.
//
// Zrcadlení se dosud spouštělo jedině při nahrání. Co jednou selhalo — výpadek sítě,
// nedoběhlá fronta — nebo co je starší než připojení cloudu, tam zůstalo navždy a
// nikdo se to nedozvěděl; doktor pak hlásil „0 z 203 originálů zkopírováno" jako trvalý
// stav. Příkaz je idempotentní a omezený, takže se dá volat opakovaně a sám se dorovná.
//
// V noci, protože kopíruje originály: u pěti set fotek jsou to gigabajty přes síť.
Schedule::command('gallery:mirror-backlog --no-interaction')
    ->dailyAt('02:40')
    ->timezone($pasmo)
    ->withoutOverlapping($zamekMinut)
    ->name('mirror-backlog');

Schedule::command('gallery:clean-temp')
    ->daily()
    ->name('temp-cleanup');

Schedule::command('gallery:scan-duplicates')
    ->weekly()
    ->name('weekly-duplicate-scan');

// Koš po třiceti dnech.
//
// `purge_after` se dosud jen zapisovalo a nikdo podle něj neuklízel — smazaná
// fotka zůstávala na disku i v součtu úložiště navždy, takže se platilo za místo,
// které podle obrazovky ubylo. V noci, protože maže z disku.
Schedule::command('gallery:purge-trash --no-interaction')
    ->dailyAt('04:20')
    ->timezone($pasmo)
    ->withoutOverlapping($zamekMinut)
    ->name('trash-purge');

// Zrušení účtu po čtrnáctidenní lhůtě. Nastavení to slibovalo a nikdo to
// neprovedl — žádost se jen zapsala. V noci, protože maže soubory.
Schedule::command('gallery:zrus-ucty --no-interaction')
    ->dailyAt('04:40')
    ->timezone($pasmo)
    ->withoutOverlapping($zamekMinut)
    ->name('account-deletion');

// ——— Prototyp Galerie ———

// Domluvy, kterým vypršela platnost. Musí běžet na serveru: klient si odpočet
// sice počítá sám, ale zapsat vypršení může jen tehdy, když si někdo aplikaci
// otevře. Schválně bez upozornění — domluva zmizí a nikdo ji neporušil.
Schedule::command('galerie:expire --no-interaction')
    ->dailyAt('03:10')
    ->timezone($pasmo)
    ->name('galerie-expire');

// Jediné upozornění, které prototyp posílá: rozhodnutí čeká na revizi.
// Podvečer, ne ráno — revize je věc na doma, ne do práce.
Schedule::command('galerie:notify --no-interaction')
    ->dailyAt('18:00')
    ->timezone($pasmo)
    ->name('galerie-notify');

/*
 * Změny z Disku na rozpory.
 *
 * Webhook je jen ukládal se stavem `pending` a nikdo je nezpracoval: fotka
 * smazaná na Disku zůstala v aplikaci jako platná a obrazovka „Rozpory mezi
 * zařízeními" kreslila ukázku. Každých pět minut — rozpor, o kterém se dvojice
 * dozví za den, už většinou stihla někde přepsat.
 */
Schedule::command('gallery:process-drive-changes --no-interaction')
    ->everyFiveMinutes()
    ->withoutOverlapping($zamekMinut)
    ->name('drive-changes');

// Scheduler heartbeat (for doctor check)
Schedule::call(function () {
    SystemSetting::set('scheduler_last_heartbeat', now()->toIso8601String());
})->everyMinute()->name('scheduler-heartbeat');

/*
 * Pozastavení z administrace platí pro každou úlohu výš.
 *
 * Jedním průchodem, ne `->skip()` u každé definice: přidaná úloha by se na
 * takový řádek zaručeně zapomněla a v administraci by šla pozastavit tlačítkem,
 * které nic nedělá. Tep plánovače se nepozastavuje — bez něj by doktor hlásil,
 * že plánovač neběží, i když jen stojí jedna úloha.
 */
foreach (app(Illuminate\Console\Scheduling\Schedule::class)->events() as $uloha) {
    $nazev = app(PlanovaneUlohy::class)->nazev($uloha);

    if ($nazev === 'scheduler-heartbeat') {
        continue;
    }

    $uloha->skip(fn () => app(PlanovaneUlohy::class)->pozastavena($nazev));
}
