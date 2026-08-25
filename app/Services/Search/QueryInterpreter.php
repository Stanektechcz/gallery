<?php

namespace App\Services\Search;

use Illuminate\Support\Carbon;

/**
 * Přečte z dotazu to, co je ve skutečnosti filtr.
 *
 * Nikdo nehledá „media_type=video AND date_from=2025-06-01". Lidi píšou „videa z loňského
 * léta" a čekají, že se to pochopí. Rozpoznané části se z textu vyříznou, takže na
 * fulltext zbyde jen to, co opravdu má hledat ve jménech a popiscích — jinak by se
 * „videa" hledala jako slovo a nenašlo by se nic.
 *
 * Každé rozpoznání se pojmenuje. Když si aplikace přebere dotaz po svém, musí být vidět
 * jak: štítek „Loni v létě" nad výsledky je jediný způsob, jak člověk pozná, že hledá
 * jiné období, než myslel.
 *
 * Vytažené z kontroleru, protože je to čistá funkce nad textem — dá se tak projít
 * desítkami dotazů najednou, což u regulárních výrazů na češtinu není luxus, ale
 * nutnost. Diakritika se řeší dvojím zápisem vzorů; přepisovat ji předem by rozbilo
 * fulltext, který na ní naopak záleží.
 */
class QueryInterpreter
{
    /** @return array{text: string, filters: array<string, mixed>, labels: array<int, string>} */
    public function interpret(string $dotaz, ?Carbon $dnes = null): array
    {
        $dnes ??= Carbon::today();

        $text = mb_strtolower($dotaz);
        $filtry = [];
        $stitky = [];

        foreach ($this->druhy() as $vzor => [$klic, $hodnota, $stitek]) {
            if (preg_match($vzor, $text)) {
                $filtry[$klic] = $hodnota;
                $stitky[] = $stitek;
                $text = preg_replace($vzor, ' ', $text) ?? $text;
            }
        }

        // Období se hledá jen jedno. Dva termíny v jednom dotazu („léto 2024 minulý
        // týden") si odporují a hádat, který myslel, je horší než vzít ten první.
        foreach ([
            fn () => $this->obdobiRocniDoba($text, $dnes),
            fn () => $this->obdobiMesic($text, $dnes),
            fn () => $this->obdobiRelativni($text, $dnes),
            fn () => $this->obdobiPoslednich($text, $dnes),
            fn () => $this->obdobiRok($text),
        ] as $pokus) {
            $nalez = $pokus();

            if ($nalez !== null) {
                $filtry['date_from'] = $nalez['from'];
                $filtry['date_to'] = $nalez['to'];
                $stitky[] = $nalez['label'];
                $text = str_replace($nalez['match'], ' ', $text);

                break;
            }
        }

        return [
            'text' => $this->zbytek($text),
            'filters' => $filtry,
            'labels' => $stitky,
        ];
    }

    /**
     * Co z dotazu zbylo pro fulltext.
     *
     * Po vyříznutí rozpoznaných částí zůstávají osamocené předložky: z „videa z loňského
     * léta" zbude „z". Ve jménech souborů a popiscích se takové slovo nehledá, jen zdržuje
     * — a kdyby zbylo jediné, hledalo by se podle něj a nenašlo nic.
     */
    private function zbytek(string $text): string
    {
        $slova = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $predlozky = ['z', 'ze', 'v', 've', 'na', 'u', 'o', 's', 'se', 'k', 'ke', 'do', 'od', 'za', 'po', 'při', 'pri'];

        return implode(' ', array_filter($slova, fn (string $s) => ! in_array($s, $predlozky, true)));
    }

    /** @return array<string, array{0: string, 1: mixed, 2: string}> */
    private function druhy(): array
    {
        return [
            '/\b(videa?|video)\b/u' => ['media_type', 'video', 'Videa'],
            '/\b(fotky|fotografie|foto)\b/u' => ['media_type', 'photo', 'Fotografie'],
            '/\b(oblíbené|oblibene|favority)\b/u' => ['favorites_only', true, 'Oblíbené'],
            '/\b(s gps|na mapě|na mape)\b/u' => ['has_gps', true, 'S GPS'],
            '/\b(bez gps|bez polohy)\b/u' => ['no_gps', true, 'Bez GPS'],
        ];
    }

    /**
     * Roční doba, případně s rokem nebo s „loni".
     *
     * Zima přechází přes Nový rok, takže „zima 2024" znamená prosinec 2024 až únor 2025.
     * Štítek to říká nahlas — kdo hledá leden 2024, ať vidí, že dostane něco jiného.
     *
     * @return array{from: string, to: string, label: string, match: string}|null
     */
    private function obdobiRocniDoba(string $text, Carbon $dnes): ?array
    {
        // Skloňované tvary, ne jen první pád. „V létě" a „na podzim" jsou to, co člověk
        // napíše; hledat jen „léto" a „podzim" znamená nerozumět většině dotazů.
        $doby = [
            'jar[oaei]|jaře' => ['Jaro', 3, 5],
            'l[ée]t[oaeě]|l[ée]tem' => ['Léto', 6, 8],
            'podzim(?:u|em)?' => ['Podzim', 9, 11],
            'zim[aeyě]|zimou' => ['Zima', 12, 2],
        ];

        foreach ($doby as $vzor => [$nazev, $odMesice, $doMesice]) {
            // Rok může stát před i za: „léto 2024" i „loni v létě". Přídavné jméno se
            // počítá taky — „z loňského léta" je totéž co „loni v létě" a lidi píšou obojí.
            $kdy = '(loni|lo[ňn]sk\w*|letos|letoš\w*|letos\w*|předloni|predloni|předloň\w*|predlon\w*)';

            if (! preg_match('/\b(?:'.$kdy.'\s+(?:v\s+|na\s+)?)?(?:'.$vzor.')(?:\s+(20\d{2}))?\b/u', $text, $shoda)) {
                continue;
            }

            $urceni = mb_strtolower($shoda[1] ?? '');

            $rok = match (true) {
                str_starts_with($urceni, 'loni'), str_starts_with($urceni, 'loň'), str_starts_with($urceni, 'lon') => $dnes->year - 1,
                str_starts_with($urceni, 'předlon'), str_starts_with($urceni, 'predlon'), str_starts_with($urceni, 'předloň') => $dnes->year - 2,
                str_starts_with($urceni, 'letoš'), $urceni === 'letos' => $dnes->year,
                default => isset($shoda[2]) && $shoda[2] !== '' ? (int) $shoda[2] : $dnes->year,
            };

            $od = Carbon::create($rok, $odMesice, 1)->startOfDay();
            $do = $odMesice > $doMesice
                ? Carbon::create($rok + 1, $doMesice, 1)->endOfMonth()
                : Carbon::create($rok, $doMesice, 1)->endOfMonth();

            return [
                'from' => $od->toDateString(),
                'to' => $do->endOfDay()->toDateTimeString(),
                'label' => $odMesice > $doMesice ? "{$nazev} {$rok}/" . ($rok + 1) : "{$nazev} {$rok}",
                'match' => $shoda[0],
            ];
        }

        return null;
    }

    /**
     * Konkrétní měsíc, případně s rokem.
     *
     * Bez roku se bere ten, ve kterém ten měsíc naposledy byl — kdo v srpnu hledá
     * „v prosinci", myslí loňský prosinec, ne ten za čtyři měsíce, protože fotky
     * z budoucnosti nemá.
     *
     * @return array{from: string, to: string, label: string, match: string}|null
     */
    private function obdobiMesic(string $text, Carbon $dnes): ?array
    {
        $mesice = [
            'led(?:en|nu|na)' => [1, 'leden'], 'únor(?:u|a)?|unor(?:u|a)?' => [2, 'únor'],
            'břez(?:en|nu|na)|brez(?:en|nu|na)' => [3, 'březen'], 'dub(?:en|nu|na)' => [4, 'duben'],
            'květ(?:en|nu|na)|kvet(?:en|nu|na)' => [5, 'květen'], 'červn(?:u|a)|červen|cervn(?:u|a)|cerven' => [6, 'červen'],
            'červenc(?:e|i)|červenec|cervenc(?:e|i)|cervenec' => [7, 'červenec'], 'srp(?:en|nu|na)' => [8, 'srpen'],
            'zář(?:í|i)|zar(?:í|i)' => [9, 'září'], 'říjn(?:u|a)|říjen|rijn(?:u|a)|rijen' => [10, 'říjen'],
            'listopad(?:u|em)?' => [11, 'listopad'], 'prosinc(?:e|i)|prosinec' => [12, 'prosinec'],
        ];

        foreach ($mesice as $vzor => [$cislo, $nazev]) {
            if (! preg_match('/\b(?:v\s+)?(?:'.$vzor.')(?:\s+(20\d{2}))?\b/u', $text, $shoda)) {
                continue;
            }

            $rok = isset($shoda[1]) && $shoda[1] !== ''
                ? (int) $shoda[1]
                : ($cislo > $dnes->month ? $dnes->year - 1 : $dnes->year);

            $od = Carbon::create($rok, $cislo, 1)->startOfDay();

            return [
                'from' => $od->toDateString(),
                'to' => $od->copy()->endOfMonth()->endOfDay()->toDateTimeString(),
                // mb varianta: ucfirst na „červenec" nechá „č" malé, protože pracuje
                // po bajtech a první bajt vícebajtového znaku nemá velkou podobu.
                'label' => mb_strtoupper(mb_substr($nazev, 0, 1)).mb_substr($nazev, 1)." {$rok}",
                'match' => $shoda[0],
            ];
        }

        return null;
    }

    /** @return array{from: string, to: string, label: string, match: string}|null */
    private function obdobiRelativni(string $text, Carbon $dnes): ?array
    {
        $varianty = [
            '/\bdnes\b/u' => ['Dnes', fn () => [$dnes->copy(), $dnes->copy()]],
            '/\bvčera|vcera\b/u' => ['Včera', fn () => [$dnes->copy()->subDay(), $dnes->copy()->subDay()]],
            '/\bpředevčírem|predevcirem\b/u' => ['Předevčírem', fn () => [$dnes->copy()->subDays(2), $dnes->copy()->subDays(2)]],
            '/\btento\s+týden|tento\s+tyden\b/u' => ['Tento týden', fn () => [$dnes->copy()->startOfWeek(), $dnes->copy()]],
            '/\bminul[ýy]\s+týden|minul[ýy]\s+tyden\b/u' => ['Minulý týden', fn () => [$dnes->copy()->subWeek()->startOfWeek(), $dnes->copy()->subWeek()->endOfWeek()]],
            '/\btento\s+měsíc|tento\s+mesic\b/u' => ['Tento měsíc', fn () => [$dnes->copy()->startOfMonth(), $dnes->copy()]],
            '/\bminul[ýy]\s+měsíc|minul[ýy]\s+mesic\b/u' => ['Minulý měsíc', fn () => [$dnes->copy()->subMonthNoOverflow()->startOfMonth(), $dnes->copy()->subMonthNoOverflow()->endOfMonth()]],
            '/\bletos\b/u' => ['Letos', fn () => [$dnes->copy()->startOfYear(), $dnes->copy()]],
            '/\bloni\b/u' => ['Loni', fn () => [$dnes->copy()->subYear()->startOfYear(), $dnes->copy()->subYear()->endOfYear()]],
            '/\bpředloni|predloni\b/u' => ['Předloni', fn () => [$dnes->copy()->subYears(2)->startOfYear(), $dnes->copy()->subYears(2)->endOfYear()]],
        ];

        foreach ($varianty as $vzor => [$stitek, $rozsah]) {
            if (! preg_match($vzor, $text, $shoda)) {
                continue;
            }

            [$od, $do] = $rozsah();

            return [
                'from' => $od->toDateString(),
                'to' => $do->endOfDay()->toDateTimeString(),
                'label' => $stitek,
                'match' => $shoda[0],
            ];
        }

        return null;
    }

    /** „posledních 30 dní", „poslední tři měsíce". @return array{from: string, to: string, label: string, match: string}|null */
    private function obdobiPoslednich(string $text, Carbon $dnes): ?array
    {
        if (! preg_match('/\bposledn[íi]ch?\s+(\d{1,3})\s+(dn[íůi]|týdn[ůyi]|tydn[ůyi]|měsíc[ůei]?|mesic[ůei]?|let|rok[ůy]?)\b/u', $text, $shoda)) {
            return null;
        }

        $kolik = max(1, (int) $shoda[1]);
        $jednotka = $shoda[2];

        $od = match (true) {
            str_starts_with($jednotka, 'dn') => $dnes->copy()->subDays($kolik - 1),
            str_starts_with($jednotka, 'týdn'), str_starts_with($jednotka, 'tydn') => $dnes->copy()->subWeeks($kolik),
            str_starts_with($jednotka, 'let'), str_starts_with($jednotka, 'rok') => $dnes->copy()->subYears($kolik),
            default => $dnes->copy()->subMonthsNoOverflow($kolik),
        };

        return [
            'from' => $od->toDateString(),
            'to' => $dnes->copy()->endOfDay()->toDateTimeString(),
            'label' => 'Posledních '.$shoda[1].' '.$jednotka,
            'match' => $shoda[0],
        ];
    }

    /** @return array{from: string, to: string, label: string, match: string}|null */
    private function obdobiRok(string $text): ?array
    {
        if (! preg_match('/\b(20\d{2})\b/', $text, $shoda)) {
            return null;
        }

        return [
            'from' => "{$shoda[1]}-01-01",
            'to' => "{$shoda[1]}-12-31 23:59:59",
            'label' => $shoda[1],
            'match' => $shoda[0],
        ];
    }
}
