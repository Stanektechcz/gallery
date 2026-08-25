<?php

/**
 * Skryje deprecation hlášky z vendoru — ale jen v produkci.
 *
 * PHP 8.4 začalo hlásit implicitně nullable parametry: `function f($x = null)` místo
 * `function f(?int $x = null)`. Balíček thecodingmachine/safe, který si přitáhne
 * knihovna na web push, je z roku 2018 a má toho plný soubor. Na PHP 8.5 to znamená
 * desítky řádků při každém spuštění.
 *
 * Nastavuje se to tady, protože jinam to nejde. Hlášky vznikají už při deklaraci funkcí,
 * tedy ve chvíli, kdy composer načítá své soubory — dřív, než se Laravel vůbec spustí
 * a než si zaregistruje vlastní obsluhu chyb. Kanál `deprecations` v config/logging.php
 * je tím pádem nezachytí; ten platí až pro to, co se stane za běhu.
 *
 * Proč vůbec: hlášky nic neopravují, protože jde o cizí kód, který nemůžeme změnit.
 * Zato zaplaví výstup — po spuštění doktora se čtyřicet řádků kontrol utopilo v třiceti
 * řádcích hlášek o parametru $count. Diagnostika, kterou není vidět, je k ničemu.
 *
 * Jen v produkci. Ve vývoji a v testech mají hlášky zůstat: tam je chceme vidět kvůli
 * vlastnímu kódu a kvůli tomu, abychom poznali, že je čas balíček vyměnit.
 *
 * Skutečné řešení je ten balíček odstranit — knihovna na web push umí i novější verzi
 * podepisování, která ho nepotřebuje. Než se to ověří na živém odesílání, ať aspoň
 * není vidět.
 */

/**
 * Jaké je prostředí — bez Laravelu, který v tuhle chvíli ještě neběží.
 *
 * APP_ENV na serveru obvykle není systémová proměnná; leží v .env, který ale Laravel
 * načítá až po autoloadu. Nejdřív se proto zkusí prostředí procesu (tak to mají
 * kontejnery a tak se to dá přebít při ladění) a teprve pak se sáhne do .env.
 *
 * Když se nepozná nic, mlčí se. Neznámé prostředí je spíš vývoj než produkce a skrýt
 * hlášku, kterou někdo potřebuje vidět, je horší než ji ukázat navíc.
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

    return 'unknown';
};

if ($prostredi() === 'production') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}
