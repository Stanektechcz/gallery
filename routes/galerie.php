<?php

use App\Http\Controllers\Api\CalendarPlanningController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\Galerie\AdminController;
use App\Http\Controllers\Api\Galerie\AlbaController;
use App\Http\Controllers\Api\Galerie\AlbumArchivController;
use App\Http\Controllers\Api\Galerie\CestyAkceController;
use App\Http\Controllers\Api\Galerie\DataController;
use App\Http\Controllers\Api\Galerie\DenikController;
use App\Http\Controllers\Api\Galerie\DomaciPraceController;
use App\Http\Controllers\Api\Galerie\FinanceAkceController;
use App\Http\Controllers\Api\Galerie\ImportVypisuController;
use App\Http\Controllers\Api\Galerie\KategorieUkoluController;
use App\Http\Controllers\Api\Galerie\KosController;
use App\Http\Controllers\Api\Galerie\LideController;
use App\Http\Controllers\Api\Galerie\MechanismController;
use App\Http\Controllers\Api\Galerie\MediaController;
use App\Http\Controllers\Api\Galerie\MilnikyController;
use App\Http\Controllers\Api\Galerie\MistaController;
use App\Http\Controllers\Api\Galerie\PravidloController;
use App\Http\Controllers\Api\Galerie\PripomenutiController;
use App\Http\Controllers\Api\Galerie\RozporController;
use App\Http\Controllers\Api\Galerie\RucniPlatbaController;
use App\Http\Controllers\Api\Galerie\RychlyZapisController;
use App\Http\Controllers\Api\Galerie\SdileniController;
use App\Http\Controllers\Api\Galerie\StateController;
use App\Http\Controllers\Api\Galerie\StitkyController;
use App\Http\Controllers\Api\Galerie\StorageController;
use App\Http\Controllers\Api\Galerie\TiskController;
use App\Http\Controllers\Api\Galerie\TokenController;
use App\Http\Controllers\Api\Galerie\TrezorController;
use App\Http\Controllers\Api\Galerie\UkolyController;
use App\Http\Controllers\Api\Galerie\UlozisteController;
use App\Http\Controllers\Api\Galerie\VzkazyHostuController;
use App\Http\Controllers\Api\Galerie\WebauthnController;
use App\Http\Controllers\Api\Galerie\ZamekController;
use App\Http\Controllers\Api\Galerie\ZaznamController;
use Illuminate\Support\Facades\Route;

/*
 * Routy prototypu Galerie.
 *
 * Registrují se BEZ prefixu — `api` si nesou samy. Přidat je pod `api` by
 * znamenalo `/api/api/state` a klient by nenašel nic; prototyp má adresu
 * napevno v `galerie-api.js` a ten se podle zadání nemění.
 *
 * Stav patří páru, ne uživateli: oba partneři čtou a píší tentýž záznam.
 */
/*
 * Přihlášení je vstupní cesta, proto tvrdší limit než na zbytek.
 *
 * Třetí parametr je **předpona počítadla** a je tu nutná. Bez ní si
 * `ThrottleRequests` klíčuje pokusy jen podle uživatele a adresy, takže každá
 * cesta se stejným limitem sdílí jedno počítadlo — a přihlášení si ho dělilo
 * s otiskem prstu o kus níž. Každé ťuknutí na otisk i každý pokus o heslo tak
 * ubíraly z téhož limitu; po vyčerpání dostalo přihlášení 429 a obrazovka
 * na to řekla „Server neodpověděl". Člověk to zkoušel dál — čímž si limit
 * držel vyčerpaný.
 */
Route::middleware(['throttle:20,1,prihlaseni'])->post('sanctum/token', [TokenController::class, 'store'])
    ->name('galerie.token.store');

/*
 * Otisk před přihlášením.
 *
 * Tyhle dvě cesty musí být přístupné bez tokenu — jde o zamčenou aplikaci,
 * ve které ještě nikdo přihlášený není. Challenge se drží v sezení a cache,
 * takže odpověď nejde přehrát; limit je stejně tvrdý jako u hesla.
 */
Route::middleware(['throttle:20,1,otisk'])->prefix('api/webauthn')->group(function () {
    Route::post('login/options', [WebauthnController::class, 'loginOptions'])->name('galerie.webauthn.login.options');
    Route::post('login', [WebauthnController::class, 'login'])->name('galerie.webauthn.login');
});

/*
 * `dvojice`: host a účet s odebraným přístupem sem nesmí a klíč „jen čtení"
 * nic nezapíše — viz `JenDvojice`. Kontroluje se u každého požadavku, protože
 * token nebo sezení mohly vzniknout dřív, než přístup skončil.
 */
Route::middleware(['auth:sanctum', 'dvojice', 'throttle:120,1'])->prefix('api')->group(function () {
    Route::get('state', [StateController::class, 'show'])->name('galerie.state.show');
    Route::patch('state', [StateController::class, 'update'])->name('galerie.state.update');
    Route::delete('state', [StateController::class, 'destroy'])->name('galerie.state.destroy');

    Route::post('logout', [TokenController::class, 'destroy'])->name('galerie.logout');

    Route::get('mechanisms', [MechanismController::class, 'index'])->name('galerie.mechanisms');

    // Čísla pro postranní panel — vidí je každý, na rozdíl od administrace.
    Route::get('storage', StorageController::class)->name('galerie.storage');

    /*
     * Obsah obrazovek ze skutečné databáze, po skupinách.
     *
     * Prototyp kreslí z `GalerieData`; tohle jsou tytéž kolekce, jen se skutečnými
     * řádky. Neznámá skupina je 404, ne prázdno — ať se překlep pozná hned.
     */
    // Víc skupin jednou odpovědí — načtení stránky místo dvaceti požadavků tři.
    Route::get('data', [DataController::class, 'davka'])->name('galerie.data.davka');

    Route::get('data/{skupina}', DataController::class)
        ->whereAlpha('skupina')
        ->name('galerie.data');

    /*
     * „Spustit teď" u automatizace.
     *
     * Tlačítko dosud jen napsalo do historie, co by se bylo stalo. Tohle
     * pravidlo doopravdy provede a odpověď nese jeho novou historii.
     */
    Route::post('pravidla/{pravidlo}/spustit', PravidloController::class)
        ->whereUuid('pravidlo')
        ->name('galerie.pravidlo.spustit');

    /*
     * Zápis toho, co aplikace vědět nemůže.
     *
     * Kdo umí přepnout bojler, čeho se kdo u rozhodnutí bojí, jak dopadl
     * podobný případ a na jakém čísle rozhodnutí stálo. Vlastní cesta, ne
     * změna stavu: prototyp tyhle kolekce čte jako konstanty z `GalerieData`,
     * takže odpověď musí nést celou skupinu znovu.
     */
    Route::post('zaznamy/{druh}', ZaznamController::class)
        ->whereIn('druh', ZaznamController::DRUHY)
        ->name('galerie.zaznam');

    /*
     * „Zkusit znovu" u originálů, které se nepřenesly na Disk.
     *
     * Tlačítko na obrazovce úložiště dosud nemělo obsluhu vůbec. U zálohy je
     * to nejhorší možné chování: kliknutí, nic se nestane, a dvojice si myslí,
     * že přenos běží.
     */
    Route::post('uloziste/prenest', UlozisteController::class)->name('galerie.uloziste.prenest');

    /*
     * Album jako jeden archiv.
     *
     * Tlačítko „Stáhnout" v panelu alba nemělo obsluhu. Stahovat po jednom
     * nejde — u alba s dvěma sty fotkami by prohlížeč po pár souborech zbytek
     * zablokoval.
     */
    Route::get('alba/{album}/archiv', AlbumArchivController::class)
        ->whereUuid('album')
        ->name('galerie.album.archiv');

    /*
     * Založit album a zařadit do něj fotky.
     *
     * „Album vytvořeno" dosud zapsalo jen do stavu v prohlížeči — album nebylo
     * v databázi ani na Disku a fotky v něm ležely jen na jedné obrazovce.
     */
    Route::post('alba', [AlbaController::class, 'store'])->name('galerie.alba.store');
    Route::post('alba/zaradit', [AlbaController::class, 'zarad'])->name('galerie.alba.zaradit');
    // Správa alba z panelu — dřív jen stav prohlížeče (viz AlbaController).
    Route::patch('alba/{album}', [AlbaController::class, 'update'])->whereUuid('album')->name('galerie.alba.update');
    Route::post('alba/{album}/presunout', [AlbaController::class, 'presun'])->whereUuid('album')->name('galerie.alba.presun');
    Route::post('alba/{album}/titulni', [AlbaController::class, 'titulni'])->whereUuid('album')->name('galerie.alba.titulni');
    Route::delete('alba/{album}', [AlbaController::class, 'destroy'])->whereUuid('album')->name('galerie.alba.destroy');
    Route::post('alba/{album}/obnovit', [AlbaController::class, 'obnov'])->whereUuid('album')->name('galerie.alba.obnovit');
    Route::post('alba/{album}/sloucit', [AlbaController::class, 'sluc'])->whereUuid('album')->name('galerie.alba.sloucit');
    // Archiv alb — dřív jen schované v jednom prohlížeči, bez archivu, kam by šlo.
    Route::post('alba/{album}/archivovat', [AlbaController::class, 'archivuj'])->whereUuid('album')->name('galerie.alba.archivovat');

    /*
     * Objednávka tisku.
     *
     * „Kniha odeslána do tisku" hlásilo tlačítko a nikde nevznikl záznam.
     * Aplikace s tiskárnou nemluví — zapíše objednávku a stav posouvá ten,
     * komu přijde potvrzení.
     */
    Route::post('tisk/objednavka', [TiskController::class, 'store'])->name('galerie.tisk.store');
    Route::post('tisk/stav', [TiskController::class, 'step'])->name('galerie.tisk.step');

    /*
     * Sdílení odkazem.
     *
     * Obrazovka slibovala odkaz, který někomu pošlete, a celý ho držela ve
     * stavu prohlížeče: po odhlášení zmizel a otevřít ho nešlo nikdy — token
     * se nikde nezaložil.
     */
    Route::post('sdileni', [SdileniController::class, 'store'])->name('galerie.sdileni.store');
    Route::patch('sdileni/{odkaz}', [SdileniController::class, 'update'])
        ->whereNumber('odkaz')->name('galerie.sdileni.update');
    // „Prodloužit o 30 dní" ve statistice odkazu jen ohlásilo, že se to stalo.
    Route::post('sdileni/{odkaz}/prodlouzit', [SdileniController::class, 'prodluz'])
        ->whereNumber('odkaz')->name('galerie.sdileni.prodlouzit');
    Route::delete('sdileni/{odkaz}', [SdileniController::class, 'destroy'])
        ->whereNumber('odkaz')->name('galerie.sdileni.destroy');

    /*
     * Finance z obrazovek galerie — dřív „zatím neumíme" (viz FinanceAkceController).
     */
    Route::prefix('finance')->name('galerie.finance.')->group(function () {
        Route::post('transakce/{uuid}/poznamka', [FinanceAkceController::class, 'poznamka'])->whereUuid('uuid')->name('poznamka');
        Route::post('transakce/{uuid}/rozpocet', [FinanceAkceController::class, 'rozpocet'])->whereUuid('uuid')->name('rozpocet');
        Route::post('transakce/{uuid}/opakovat', [FinanceAkceController::class, 'opakovat'])->whereUuid('uuid')->name('opakovat');
        Route::post('transakce/{uuid}/rozdelit', [FinanceAkceController::class, 'rozdelit'])->whereUuid('uuid')->name('rozdelit');
        Route::post('platby', [FinanceAkceController::class, 'pridatPlatbu'])->name('platby.store');
        Route::post('platby/{uuid}/preskocit', [FinanceAkceController::class, 'preskocit'])->whereUuid('uuid')->name('platby.preskocit');
        Route::post('rozpocet/zalozit', [FinanceAkceController::class, 'zalozRozpocet'])->name('rozpocet.zalozit');
        Route::post('rozpocet/limity', [FinanceAkceController::class, 'limity'])->name('limity');
        Route::post('rozpocet/obalka', [FinanceAkceController::class, 'obalka'])->name('obalka');
        Route::post('rozpocet/presun', [FinanceAkceController::class, 'presun'])->name('presun');
        Route::post('rozpocet/puvodni', [FinanceAkceController::class, 'puvodniPlan'])->name('puvodni');
        Route::post('cile', [FinanceAkceController::class, 'pridatCil'])->name('cile.store');
        Route::post('cile/{uuid}/vklad', [FinanceAkceController::class, 'vklad'])->whereUuid('uuid')->name('cile.vklad');
        Route::post('vyrovnani', [FinanceAkceController::class, 'vyrovnat'])->name('vyrovnani');
        Route::post('ucty', [FinanceAkceController::class, 'pridatUcet'])->name('ucty.store');
        Route::post('prevod', [FinanceAkceController::class, 'prevod'])->name('prevod');
        // Výpis z banky do Transakcí — dřív „stahování z banky zatím neumíme".
        Route::post('import', ImportVypisuController::class)
            ->middleware('throttle:10,1,import-vypisu')->name('import');
    });

    // Cesty: výdaj cesty a bod programu dne (dřív jen stav obrazovky / „zatím neumíme").
    Route::post('cesty/{cesta}/vydaj', [CestyAkceController::class, 'vydaj'])->whereNumber('cesta')->name('galerie.cesty.vydaj');
    Route::post('cesty/{cesta}/program', [CestyAkceController::class, 'program'])->whereNumber('cesta')->name('galerie.cesty.program');
    Route::post('cesty/program/{aktivita}/posunout', [CestyAkceController::class, 'posunout'])->whereNumber('aktivita')->name('galerie.cesty.posunout');
    Route::post('cesty/program/{aktivita}/hotovo', [CestyAkceController::class, 'splneno'])->whereNumber('aktivita')->name('galerie.cesty.splneno');
    // Travel inbox — dřív šel jen vyřizovat, přidat do něj nešlo nic.
    Route::post('cesty/inbox', [CestyAkceController::class, 'doInboxu'])->name('galerie.cesty.inbox');
    // Jízdenky: dřív „Nová jízdenka — doplňte trasu", kterou nešlo doplnit.
    Route::post('cesty/jizdenky', [CestyAkceController::class, 'jizdenka'])->name('galerie.cesty.jizdenka');
    Route::delete('cesty/jizdenky/{uuid}', [CestyAkceController::class, 'smazatJizdenku'])->whereUuid('uuid')->name('galerie.cesty.jizdenka.smazat');

    // Místa: nový cíl, „byli jsme" a společná poznámka (dřív „zatím neumíme").
    Route::post('mista', [MistaController::class, 'store'])->name('galerie.mista.store');
    Route::post('mista/stav', [MistaController::class, 'stav'])->name('galerie.mista.stav');
    Route::post('mista/poznamka', [MistaController::class, 'poznamka'])->name('galerie.mista.poznamka');

    // Rychlá ruční platba — dřív řádek jen na obrazovce s datem „30. 8.".
    Route::post('platby/rucne', RucniPlatbaController::class)->name('galerie.platby.rucne');
    // Připomínka druhému do telefonu — dřív hláška „odesláno" bez odeslání.
    Route::post('pripomenout', PripomenutiController::class)
        ->middleware('throttle:12,1,pripominky')->name('galerie.pripomenout');

    // Rychlý zápis do deníku a úkol z telefonu — dřív jen v paměti telefonu.
    Route::post('rychle/denik', [RychlyZapisController::class, 'denik'])->name('galerie.rychle.denik');
    Route::post('rychle/ukol', [RychlyZapisController::class, 'ukol'])->name('galerie.rychle.ukol');
    // „Na příští týden" v Týdenním přehledu — dřív jen otevřelo plán.
    Route::post('ukoly/{uuid}/pristi-tyden', [UkolyController::class, 'naPristiTyden'])->whereUuid('uuid')->name('galerie.ukoly.pristi-tyden');

    // Kategorie úkolů (vlastní nástěnky) — dřív jen ve stavu prohlížeče.
    Route::post('ukoly/kategorie', [KategorieUkoluController::class, 'store'])->name('galerie.ukoly.kategorie.store');
    Route::patch('ukoly/kategorie/{uuid}', [KategorieUkoluController::class, 'update'])->whereUuid('uuid')->name('galerie.ukoly.kategorie.update');
    Route::delete('ukoly/kategorie/{uuid}', [KategorieUkoluController::class, 'destroy'])->whereUuid('uuid')->name('galerie.ukoly.kategorie.destroy');

    // Milník vztahu — „Přidat milník" dřív zakládalo nápad na dárek.
    Route::post('milniky', [MilnikyController::class, 'store'])->name('galerie.milniky.store');

    // Deník z počítače — dialog „Nový zápis" dřív zapisoval jen do stavu obrazovky.
    Route::post('denik', [DenikController::class, 'store'])->name('galerie.denik.store');

    // Domácí práce — dřív šla dělba jen prohlížet, nová práce neměla kudy vzniknout.
    Route::post('domacnost/prace', [DomaciPraceController::class, 'store'])->name('galerie.domacnost.prace.store');
    Route::delete('domacnost/prace/{uuid}', [DomaciPraceController::class, 'destroy'])->whereUuid('uuid')->name('galerie.domacnost.prace.destroy');
    Route::patch('denik/{zapis}', [DenikController::class, 'update'])->whereUuid('zapis')->name('galerie.denik.update');
    Route::delete('denik/{zapis}', [DenikController::class, 'destroy'])->whereUuid('zapis')->name('galerie.denik.destroy');

    // Lidé: jméno, skrytí a sloučení dřív žily jen ve stavu prohlížeče (viz LideController).
    Route::patch('osoby/{osoba}', [LideController::class, 'update'])->whereNumber('osoba')->name('galerie.osoby.update');
    Route::post('osoby/{osoba}/sloucit', [LideController::class, 'sluc'])->whereNumber('osoba')->name('galerie.osoby.sloucit');
    // Štítky psané dvakrát — „Sloučit" dřív jen přepnulo štítek řádku.
    Route::post('stitky/sloucit', [StitkyController::class, 'sluc'])->name('galerie.stitky.sloucit');

    // Moderace vzkazů hostů — „Vzkaz skryt, host ho už nevidí" dřív platilo jen v prohlížeči.
    Route::patch('vzkazy-hostu/{vzkaz}', [VzkazyHostuController::class, 'update'])
        ->whereUuid('vzkaz')->name('galerie.vzkazy-hostu.update');
    Route::post('vzkazy-hostu/{vzkaz}/prilepit', [VzkazyHostuController::class, 'prilep'])
        ->whereUuid('vzkaz')->name('galerie.vzkazy-hostu.prilepit');
    Route::delete('vzkazy-hostu/{vzkaz}', [VzkazyHostuController::class, 'destroy'])
        ->whereUuid('vzkaz')->name('galerie.vzkazy-hostu.destroy');

    /*
     * Vyřešení rozporu mezi aplikací a Diskem.
     *
     * „Vyřešeno" přepsalo jediné pole ve stavu prohlížeče. Rozpor zůstal
     * otevřený, takže se při dalším načtení vrátil — a druhý z dvojice ho
     * viděl celou dobu.
     */
    Route::post('rozpory/vyresit', RozporController::class)->name('galerie.rozpor.vyresit');

    /*
     * Trezor.
     *
     * Odemčení porovnával prohlížeč s konstantou z `galerie-data.js` — tedy
     * s heslem, které si mohl přečíst kdokoli, kdo si otevřel adresu skriptu.
     * Zámek je přitom v aplikaci skutečný; tyhle tři cesty na něj obrazovku
     * konečně napojují.
     *
     * Odemykání má vlastní, tvrdší limit než zbytek skupiny — je to hádání
     * hesla, ne běžné klepání po aplikaci.
     */
    Route::get('trezor', [TrezorController::class, 'stav'])->name('galerie.trezor.stav');
    /*
     * Třetí parametr `throttle` je předpona počítadla, a je tu schválně.
     *
     * Bez ní si `ThrottleRequests` klíčuje pokusy jen podle uživatele
     * a adresy, takže **všechny cesty ve skupině sdílejí jedno počítadlo**:
     * limit 120/min na běžné klepání by po pár minutách prohlížení vyčerpal
     * i limit 10/min na hádání hesla a člověk by dostal 429 na trezor jen
     * proto, že si prohlédl fotky.
     */
    Route::post('trezor/odemknout', [TrezorController::class, 'odemkni'])
        ->middleware('throttle:10,1,trezor-odemknout')->name('galerie.trezor.odemknout');
    Route::post('trezor/zamknout', [TrezorController::class, 'zamkni'])->name('galerie.trezor.zamknout');

    /*
     * Zámek aplikace.
     *
     * Šestimístný kód se porovnával v prohlížeči s konstantou `LOCKPIN`
     * z veřejného `galerie-data.js` — kódy obou partnerů si mohl přečíst
     * kdokoli a obrazovka je sama vypisovala v nápovědě. Kód teď patří
     * jednomu člověku a ověřuje ho server.
     *
     * Ověřování i obnova mají vlastní, tvrdší limit: je to hádání šesti
     * číslic, tedy milion možností, a bez limitu je to otázka minut.
     */
    Route::get('zamek', [ZamekController::class, 'stav'])->name('galerie.zamek.stav');
    Route::post('zamek', [ZamekController::class, 'nastav'])
        ->middleware('throttle:10,1,zamek-nastavit')->name('galerie.zamek.nastav');
    Route::post('zamek/overit', [ZamekController::class, 'over'])
        ->middleware('throttle:20,1,zamek-overit')->name('galerie.zamek.overit');
    Route::post('zamek/obnovit', [ZamekController::class, 'obnov'])
        ->middleware('throttle:5,1,zamek-obnovit')->name('galerie.zamek.obnovit');
    // „Odhlásit ostatní" v nastavení jen ukázalo hlášku. Je to přitom jediné,
    // co má člověk po ruce, když zjistí, že se někdo přihlásil odjinud.
    Route::post('zamek/odhlasit-ostatni', [ZamekController::class, 'odhlasOstatni'])
        ->name('galerie.zamek.odhlasit');

    /*
     * Koš.
     *
     * Obrazovka měla čtyři vymyšlené řádky a tlačítka, která jen přepsala stav
     * v prohlížeči — přitom potvrzovací dialog sliboval smazání originálů
     * z Google Disku.
     */
    Route::prefix('kos')->name('galerie.kos.')->group(function () {
        Route::post('vratit', [KosController::class, 'restore'])->name('restore');
        Route::post('odstranit', [KosController::class, 'purge'])->name('purge');
        Route::post('vyprazdnit', [KosController::class, 'empty'])->name('empty');
    });

    /*
     * Odběr upozornění.
     *
     * Míří rovnou na kontroler, který tuhle tabulku obsluhuje pro zbytek
     * aplikace. Druhá implementace téhož by znamenala dvě místa, kde se dá
     * zapomenout smazat odběr odhlášeného zařízení — a tělo požadavku je
     * shodné, prototyp posílá `PushSubscription` z prohlížeče tak, jak je.
     */
    Route::post('push/subscribe', [CalendarPlanningController::class, 'storePushSubscription'])
        ->name('galerie.push.subscribe');
    Route::delete('push/subscribe', [CalendarPlanningController::class, 'destroyPushSubscription'])
        ->name('galerie.push.unsubscribe');

    /*
     * Administrace prostoru.
     *
     * Každá akce vrací celý přehled znovu — obrazovka se překresluje z databáze,
     * ne z toho, co si klient myslí, že se stalo.
     */
    Route::prefix('admin')->name('galerie.admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');

        Route::post('users', [AdminController::class, 'invite'])->name('users.invite');
        Route::post('users/{id}/resend', [AdminController::class, 'resend'])->name('users.resend');
        Route::patch('users/{id}/role', [AdminController::class, 'role'])->name('users.role');
        Route::post('users/{id}/transfer', [AdminController::class, 'transfer'])->name('users.transfer');
        Route::post('users/{id}/access', [AdminController::class, 'access'])->name('users.access');

        Route::post('keys', [AdminController::class, 'storeKey'])->name('keys.store');
        Route::post('keys/{id}/regenerate', [AdminController::class, 'regenerateKey'])->name('keys.regenerate');
        Route::delete('keys/{id}', [AdminController::class, 'destroyKey'])->name('keys.destroy');

        Route::post('jobs/{uloha}/run', [AdminController::class, 'runJob'])->name('jobs.run');
        Route::post('jobs/{uloha}/pause', [AdminController::class, 'pauseJob'])->name('jobs.pause');

        Route::post('health/check', [AdminController::class, 'healthCheck'])->name('health.check');
        Route::post('plan', [AdminController::class, 'plan'])->name('plan');
        Route::post('risks/{riziko}/fix', [AdminController::class, 'fixRisk'])->name('risks.fix');
    });

    // Klíč se registruje až přihlášenému člověku — jinak by si otisk k účtu
    // připojil kdokoli, kdo zná e-mail.
    Route::post('webauthn/register/options', [WebauthnController::class, 'registerOptions'])
        ->name('galerie.webauthn.register.options');
    Route::post('webauthn/register', [WebauthnController::class, 'register'])
        ->name('galerie.webauthn.register');
});

/*
 * Média.
 *
 * Mimo skupinu výš kvůli limitu: nahrávání velkého videa po osmimegabajtových
 * částech je klidně sto požadavků za sebou a do 120 za minutu se nevejde.
 * Adresy i hlavičky jsou dané prototypem (`galerie-api.js`) a nemění se.
 *
 * Předpona `media` u limitu je nutná: bez ní sdílí počítadlo se skupinou výš
 * (klíčuje se jen podle uživatele). Nahrání videa po 130 částech pak vyčerpalo
 * i limit 120 pro zbytek aplikace a stav, data i zprávy vracely 429.
 */
Route::middleware(['auth:sanctum', 'dvojice', 'throttle:600,1,media'])->prefix('api')->group(function () {
    Route::post('media', [MediaController::class, 'store'])->name('galerie.media.store');
    Route::post('media/chunk', [MediaController::class, 'chunk'])->name('galerie.media.chunk');
    Route::get('media/{uuid}/raw', [MediaController::class, 'raw'])->name('galerie.media.raw');
    Route::delete('media/{uuid}', [MediaController::class, 'destroy'])->name('galerie.media.destroy');
    Route::post('media/do-kose', [MediaController::class, 'destroyMany'])->name('galerie.media.destroy-many');
    Route::post('media/{uuid}/uprava', [MediaController::class, 'uprava'])->name('galerie.media.uprava');
    Route::post('media/archiv', [MediaController::class, 'archiv'])->name('galerie.media.archiv');
});

/*
 * Náhled do mřížky.
 *
 * Originály by z jednoho otevření knihovny udělaly stovky megabajtů, takže
 * dlaždice bere zmenšeninu. Stojí mimo `auth:sanctum` a místo tokenu se ověřuje
 * podpisem: obrázek v CSS si prohlížeč stahuje sám a hlavičku `Authorization`
 * k němu nepřidá. Podpis platí pro jediný soubor a den; token v adrese by
 * zůstal v historii prohlížeče i v přístupovém logu.
 */
Route::middleware(['signed', 'throttle:600,1'])
    ->get('api/media/{uuid}/thumb', [MediaController::class, 'thumb'])
    ->name('galerie.media.thumb');

/*
 * Obrázek poslaný do chatu.
 *
 * Ze stejného důvodu jako náhled výš: bublina si ho kreslí přes `background`
 * a prohlížeč k němu hlavičku `Authorization` nepřidá. Bez toho ukazovala
 * barvu spočítanou z pořadí repliky — s poslanou fotkou bez souvislosti.
 */
Route::middleware(['signed', 'throttle:600,1'])
    ->get('api/chat/{uuid}/nahled', [ChatController::class, 'signedMedia'])
    ->name('galerie.chat.nahled');

/*
 * Přehrání videa.
 *
 * Ze stejného důvodu jako náhled: `<video src>` si prohlížeč stahuje sám
 * a hlavičku `Authorization` k němu nepřidá. Prohlížeč fotky měl místo videa
 * obrázek s namalovaným pruhem přehrávání — tlačítka pod ním neměla obsluhu,
 * protože nebylo co ovládat.
 */
Route::middleware(['signed', 'throttle:600,1'])
    ->get('api/media/{uuid}/video', [MediaController::class, 'video'])
    ->name('galerie.media.video');
