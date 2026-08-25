<?php

/**
 * Co se musí stát dřív, než composer načte autoloader.
 *
 * Obojí, co je tady, patří právě sem a nikam jinam: jakmile se načte vendor, je pozdě.
 * Laravel v tu chvíli ještě neběží, takže se nedá použít config ani jeho obsluha chyb.
 */

/**
 * Nesprávné PHP má skončit větou, ne osmi řádky výpisu zásobníku.
 *
 * Na serveru jsou dvě PHP: systémové 8.1 a to, na kterém aplikace běží. Kdo napíše
 * `php artisan` místo plné cesty, dostane od composeru fatal error o platform checku
 * a k němu hromadu hlášek o nenačtených rozšířeních — z čehož vůbec neplyne, že stačí
 * použít jiný binárku. Stalo se to dvakrát a podruhé i po upozornění, což je dost na to,
 * aby si to aplikace ohlídala sama.
 *
 * Jen v příkazové řádce. Web běží přes FPM, které se nastavuje jednou a omylem se
 * nepřepne — a kontrola verze při každém požadavku by byla práce navíc pro nic.
 */
if (PHP_SAPI === 'cli' && PHP_VERSION_ID < 80401) {
    $skript = basename($_SERVER['argv'][0] ?? 'artisan');

    fwrite(STDERR, "\n  Tohle PHP je staré: " . PHP_VERSION . ", potřeba je aspoň 8.4.1.\n\n");
    fwrite(STDERR, "  Spustili jste `php {$skript}`, což vzalo systémové PHP. Aplikace běží\n");
    fwrite(STDERR, "  na jiné instalaci — použijte tu samou, jakou používá deploy.sh:\n\n");
    fwrite(STDERR, "      grep PHP_BIN deploy.sh      # ukáže cestu\n");
    fwrite(STDERR, "      /cesta/k/php {$skript} …\n\n");

    exit(1);
}

/**
 * Hlášky z vendoru, které se nedají opravit a jen zaplavují výstup.
 *
 * PHP 8.4 začalo hlásit implicitně nullable parametry. Balíček thecodingmachine/safe,
 * který si přitáhne knihovna na web push, je z roku 2018 a má toho plný soubor —
 * dvě stě řádků při každém spuštění. Po doktorovi se v nich utopilo čtyřicet řádků
 * kontrol, tedy přesně to, kvůli čemu se spouštěl.
 *
 * E_COMPILE_WARNING je tam kvůli dvěma hláškám, které po vypnutí deprecations zbyly:
 * „resource is not a supported builtin type" a „integer will be interpreted as a class
 * name". Vznikají při překladu týchž souborů. Ověřeno, že jde právě o tuhle úroveň —
 * vyloučení E_WARNING je nechá být, vyloučení E_COMPILE_WARNING je odstraní. Je to
 * proto užší zásah než vypnout varování obecně; běžná runtime varování zůstávají.
 *
 * Platí ve všech prostředích a nic tím nezmizí. Dvě věci to drží pohromadě:
 *
 * Zaprvé, balíček se autoloaduje přes „files", takže se všech dvě stě jeho souborů
 * přeloží najednou při `require vendor/autoload.php` — hned za tímhle řádkem.
 * Zadruhé, Laravel při startu volá `error_reporting(-1)` (HandleExceptions, řádek 47),
 * takže se hlášení zase v plné šíři zapne dřív, než se dostane ke slovu jakýkoli náš
 * kód. Maska pokrývá právě to jedno okno mezi tím.
 *
 * Původně se tady omezovala jen produkce, aby si vývoj hlášky nechal. Jenže vidět
 * nebylo co — vlastního kódu se to okno netýká — a cena byla vysoká: lokální server
 * vypsal dvě stě řádků do těla odpovědi ještě před hlavičkami, takže každý požadavek
 * skončil na „Cannot modify header information". Vývojový server byl tím pádem
 * nepoužitelný a přišlo se na to až ve chvíli, kdy se doopravdy spustil.
 *
 * Vyhodit balíček zatím nejde. Cesta ven vede přes web-token řady 3, jenže ta vyžaduje
 * brick/math nejvýš 0.12, kdežto Laravel 13 chce aspoň 0.14 — rozsahy se nepřekrývají.
 * Řada 4 zatím existuje jen jako vývojová větev.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_COMPILE_WARNING);
