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
 * Jen v produkci. Ve vývoji a v testech mají hlášky zůstat: tam je chceme vidět kvůli
 * vlastnímu kódu a kvůli tomu, abychom poznali, že je čas ten balíček vyměnit.
 *
 * Vyhodit ho zatím nejde. Cesta ven vede přes web-token řady 3, jenže ta vyžaduje
 * brick/math nejvýš 0.12, kdežto Laravel 13 chce aspoň 0.14 — rozsahy se nepřekrývají.
 * Řada 4 zatím existuje jen jako vývojová větev.
 */
$prostredi = static function (): string {
    foreach ([$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV') ?: null] as $hodnota) {
        if (is_string($hodnota) && $hodnota !== '') {
            return $hodnota;
        }
    }

    $env = dirname(__DIR__) . '/.env';

    if (! is_readable($env)) {
        return 'unknown';
    }

    // Jen ten jeden řádek. Načítat celý .env vlastním parserem znamená druhé místo,
    // které se od Laravelu časem rozejde v tom, co považuje za hodnotu.
    foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $radek) {
        if (str_starts_with(ltrim($radek), 'APP_ENV=')) {
            return trim(explode('=', $radek, 2)[1], " \t\"'");
        }
    }

    // Když se nepozná nic, mlčí se. Neznámé prostředí je spíš vývoj než produkce
    // a skrýt hlášku, kterou někdo potřebuje vidět, je horší než ji ukázat navíc.
    return 'unknown';
};

if ($prostredi() === 'production') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_COMPILE_WARNING);
}
