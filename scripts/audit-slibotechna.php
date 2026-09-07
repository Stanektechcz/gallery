<?php

/**
 * Hledá obsluhy, které jenom ohlásí úspěch.
 *
 * V tomhle prototypu se ten vzorec opakuje: tlačítko zavolá `this.toast(…)`
 * s větou v minulém čase („Uloženo", „Odesláno") a jinak neudělá nic — ani
 * nezmění stav, ani nesáhne na server. Z pohledu člověka to vypadá, že se to
 * stalo; po obnovení stránky po tom není stopa.
 *
 * Postup: najdi tělo obsluhy, vyřízni z něj všechna volání `this.toast(…)`
 * i s argumenty (počítáním závorek, ne regulárním výrazem — argumenty bývají
 * vnořené objekty) a podívej se, jestli ve zbytku zůstalo ještě něco, co něco
 * dělá: volání, přiřazení, `++`.
 *
 * Není to důkaz, je to soupis míst k prohlédnutí.
 *
 *     php scripts/audit-slibotechna.php
 */
$soubory = [
    'resources/galerie/galerie-desktop.dc.html',
    'resources/galerie/galerie-mobil.dc.html',
];

/** Vyřízne `$volani(...)` i s argumenty, ať jsou v nich závorky jakkoli vnořené. */
function bezVolani(string $telo, string $volani): string
{
    while (($od = strpos($telo, $volani)) !== false) {
        $i = $od + strlen($volani) - 1;
        $hloubka = 0;
        $vRetezci = false;
        $uvozovka = '';

        for (; $i < strlen($telo); $i++) {
            $z = $telo[$i];

            if ($vRetezci) {
                if ($z === '\\') {
                    $i++;
                } elseif ($z === $uvozovka) {
                    $vRetezci = false;
                }

                continue;
            }

            if ($z === "'" || $z === '"' || $z === '`') {
                $vRetezci = true;
                $uvozovka = $z;

                continue;
            }

            if ($z === '(') {
                $hloubka++;
            } elseif ($z === ')') {
                $hloubka--;
                if ($hloubka === 0) {
                    break;
                }
            }
        }

        $telo = substr($telo, 0, $od).substr($telo, $i + 1);
    }

    return $telo;
}

$nalezy = [];

foreach ($soubory as $soubor) {
    $radky = explode("\n", file_get_contents($soubor));

    foreach ($radky as $i => $radek) {
        if (! preg_match('/^\s*([A-Za-z0-9_]+):\s*(\(\)|\w+|\([^)]*\))\s*=>/', $radek, $m)) {
            continue;
        }

        $telo = '';
        $hloubka = 0;
        $zacalo = false;

        for ($j = $i; $j < min($i + 40, count($radky)); $j++) {
            $telo .= $radky[$j]."\n";
            $hloubka += substr_count($radky[$j], '{') - substr_count($radky[$j], '}');

            if (str_contains($radky[$j], '{')) {
                $zacalo = true;
            }

            if ($zacalo && $hloubka <= 0) {
                break;
            }

            if (! $zacalo && $j > $i) {
                break;
            }
        }

        if (! str_contains($telo, 'this.toast(')) {
            continue;
        }

        $zbytek = bezVolani(substr($telo, strpos($telo, '=>') + 2), 'this.toast(');

        // Řetězce pryč: ať se hláška uvnitř nepočítá jako práce.
        $zbytek = preg_replace('/([\'"])(?:[^\\\\\'"]|\\\\.)*\1/s', 'S', $zbytek) ?? $zbytek;

        // Zbylo ještě volání, přiřazení nebo inkrement?
        if (preg_match('/\w\s*\(|[^=!<>+\-*\/]=[^=]|\+\+|--/', $zbytek)) {
            continue;
        }

        $nalezy[] = [
            basename($soubor, '.dc.html'),
            $i + 1,
            $m[1],
            trim(preg_replace('/\s+/', ' ', mb_substr(ltrim(substr($telo, strpos($telo, '=>') + 2)), 0, 140))),
        ];
    }
}

echo count($nalezy), " obsluh, které jen ohlásí úspěch:\n\n";

foreach ($nalezy as $n) {
    echo str_pad($n[0], 17), str_pad((string) $n[1], 7), str_pad($n[2], 20), $n[3], "\n";
}
