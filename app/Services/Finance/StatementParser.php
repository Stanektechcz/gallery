<?php

namespace App\Services\Finance;

use Carbon\Carbon;

/**
 * Čtení bankovního výpisu v CSV.
 *
 * Napojení na banku přes API stojí peníze a u německého účtu, který Makinka teprve
 * založí, by stejně nebylo hned. CSV umí vyexportovat každá banka a nestojí nic —
 * jediná cena je, že každá ho dělá jinak, a to se dá vyřešit tady.
 *
 * Nic se neukládá. Parser vrátí řádky, člověk je uvidí a teprve pak potvrdí, které
 * chce. Import, který sám zapíše dvě stě položek, se hůř opravuje, než kdyby to
 * člověk naťukal ručně.
 */
class StatementParser
{
    /** Názvy sloupců, které se v českých a anglických výpisech opakují. */
    private const SLOUPCE = [
        'date' => ['datum', 'datum zauctovani', 'datum zauctování', 'datum provedeni', 'datum splatnosti',
            'date', 'value date', 'started date', 'completed date', 'booking date', 'buchungstag', 'wertstellung'],
        'amount' => ['castka', 'částka', 'objem', 'amount', 'betrag', 'value'],
        'currency' => ['mena', 'měna', 'currency', 'wahrung', 'währung'],
        'note' => ['popis', 'zprava pro prijemce', 'zpráva pro příjemce', 'poznamka', 'poznámka', 'nazev protiuctu',
            'název protiúčtu', 'protistrana', 'description', 'details', 'reference', 'merchant',
            'verwendungszweck', 'beguenstigter', 'typ transakce'],
    ];

    /**
     * @return array{rows: array<int, array<string, mixed>>, skipped: int, columns: array<string, ?int>}
     */
    public function parse(string $obsah): array
    {
        // BOM z Excelu by se jinak přilepil k prvnímu názvu sloupce a ten by se nenašel.
        $obsah = preg_replace('/^\xEF\xBB\xBF/', '', $obsah);

        // Výpisy z českých bank chodí často v CP1250. Rozpozná se tak, že to není UTF-8.
        if (! mb_check_encoding($obsah, 'UTF-8')) {
            $obsah = mb_convert_encoding($obsah, 'UTF-8', 'Windows-1250');
        }

        $oddelovac = $this->oddelovac($obsah);
        $radky = $this->radky($obsah, $oddelovac);

        if ($radky === []) {
            return ['rows' => [], 'skipped' => 0, 'columns' => []];
        }

        // Hlavička nemusí být na prvním řádku — banky nad ni píšou číslo účtu a období.
        [$hlavicka, $offset] = $this->hlavicka($radky);

        if ($hlavicka === null) {
            return ['rows' => [], 'skipped' => count($radky), 'columns' => []];
        }

        $mapa = $this->mapa($hlavicka);

        if ($mapa['date'] === null || $mapa['amount'] === null) {
            return ['rows' => [], 'skipped' => count($radky) - $offset - 1, 'columns' => $mapa];
        }

        $vysledek = [];
        $preskoceno = 0;

        foreach (array_slice($radky, $offset + 1) as $radek) {
            $datum = $this->datum($radek[$mapa['date']] ?? null);
            $castka = $this->castka($radek[$mapa['amount']] ?? null);

            if ($datum === null || $castka === null || abs($castka) < 0.005) {
                $preskoceno++;

                continue;
            }

            $mena = $mapa['currency'] !== null ? strtoupper(trim($radek[$mapa['currency']] ?? '')) : '';

            $vysledek[] = [
                // Záporná částka je výdaj, kladná příjem. Některé banky dávají výdaje
                // kladně do sloupce „debet"; ty se poznají podle toho, že sloupec s
                // částkou nikdy zápornou hodnotu nemá, a to řeší až frontend přepnutím.
                'kind' => $castka < 0 ? 'expense' : 'income',
                'amount' => round(abs($castka), 2),
                'currency' => preg_match('/^[A-Z]{3}$/', $mena) ? $mena : null,
                'spent_on' => $datum->toDateString(),
                'note' => $this->popis($radek, $mapa),
            ];
        }

        return ['rows' => $vysledek, 'skipped' => $preskoceno, 'columns' => $mapa];
    }

    /** Středník vyhrává: čeká se český Excel, ale čárka i tabulátor se poznají. */
    private function oddelovac(string $obsah): string
    {
        $vzorek = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $obsah), 0, 20));

        $pocty = [
            ';' => substr_count($vzorek, ';'),
            ',' => substr_count($vzorek, ','),
            "\t" => substr_count($vzorek, "\t"),
        ];

        arsort($pocty);

        return max($pocty) > 0 ? array_key_first($pocty) : ';';
    }

    /** @return array<int, array<int, string>> */
    private function radky(string $obsah, string $oddelovac): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $obsah);
        rewind($handle);

        $radky = [];

        // Prázdný escape, stejně jako u exportu: banky vydávají RFC CSV, kde zpětné
        // lomítko není řídicí znak, a ve variabilním symbolu se občas objeví.
        while (($radek = fgetcsv($handle, 0, $oddelovac, '"', '')) !== false) {
            if ($radek === [null] || $radek === false) continue;
            $radky[] = array_map(fn ($b) => is_string($b) ? trim($b) : '', $radek);
        }

        fclose($handle);

        return $radky;
    }

    /**
     * Najde řádek s hlavičkou.
     *
     * @param  array<int, array<int, string>>  $radky
     * @return array{0: ?array<int, string>, 1: int}
     */
    private function hlavicka(array $radky): array
    {
        foreach (array_slice($radky, 0, 25) as $i => $radek) {
            $mapa = $this->mapa($radek);

            if ($mapa['date'] !== null && $mapa['amount'] !== null) {
                return [$radek, $i];
            }
        }

        return [null, 0];
    }

    /**
     * @param  array<int, string>  $hlavicka
     * @return array<string, ?int>
     */
    private function mapa(array $hlavicka): array
    {
        $normalizovana = array_map(fn (string $b) => $this->klic($b), $hlavicka);
        $mapa = ['date' => null, 'amount' => null, 'currency' => null, 'note' => null];

        foreach (self::SLOUPCE as $pole => $varianty) {
            foreach ($varianty as $varianta) {
                $index = array_search($this->klic($varianta), $normalizovana, true);

                if ($index !== false) {
                    $mapa[$pole] = (int) $index;

                    break;
                }
            }
        }

        return $mapa;
    }

    /** Bez diakritiky a malými písmeny, aby „Částka" a „castka" byly totéž. */
    private function klic(string $text): string
    {
        $bez = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        return trim(preg_replace('/[^a-z0-9 ]/', '', mb_strtolower($bez)));
    }

    /** Datum ve formátech, které banky používají; pořadí je od nejčastějšího. */
    private function datum(?string $hodnota): ?Carbon
    {
        $hodnota = trim((string) $hodnota);

        if ($hodnota === '') return null;

        // Čas za datem zahodíme — pro rozpočet je jednotkou den.
        $hodnota = preg_replace('/[T ]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $hodnota);

        foreach (['d.m.Y', 'd. m. Y', 'j.n.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Ymd'] as $format) {
            try {
                $datum = Carbon::createFromFormat($format, $hodnota);

                // createFromFormat kousne i nesmysl, když se dá doplnit; kontrola zpět
                // odhalí, že „13/05/2026" v m/d/Y ve skutečnosti nesedí.
                if ($datum && $datum->format($format) === $hodnota) {
                    return $datum->startOfDay();
                }
            } catch (\Throwable) {
                // Formát nesedí, zkusí se další.
            }
        }

        return null;
    }

    /** Česká čárka, mezery mezi tisíci, závorky jako minus. */
    private function castka(?string $hodnota): ?float
    {
        $hodnota = trim((string) $hodnota);

        if ($hodnota === '') return null;

        $zaporne = str_starts_with($hodnota, '(') && str_ends_with($hodnota, ')');
        $hodnota = preg_replace('/[^0-9,.\-]/u', '', $hodnota);

        if ($hodnota === '' || $hodnota === '-') return null;

        // Když jsou v čísle obě oddělovací značky, ta poslední je desetinná.
        $carka = strrpos($hodnota, ',');
        $tecka = strrpos($hodnota, '.');

        if ($carka !== false && $tecka !== false) {
            $hodnota = $carka > $tecka
                ? str_replace(['.', ','], ['', '.'], $hodnota)
                : str_replace(',', '', $hodnota);
        } elseif ($carka !== false) {
            $hodnota = str_replace(',', '.', $hodnota);
        }

        if (! is_numeric($hodnota)) return null;

        return $zaporne ? -abs((float) $hodnota) : (float) $hodnota;
    }

    /**
     * @param  array<int, string>  $radek
     * @param  array<string, ?int>  $mapa
     */
    private function popis(array $radek, array $mapa): ?string
    {
        if ($mapa['note'] !== null && trim($radek[$mapa['note']] ?? '') !== '') {
            return mb_substr(trim($radek[$mapa['note']]), 0, 500);
        }

        // Když sloupec s popisem nepoznáme, vezme se nejdelší text v řádku, který není
        // číslo ani datum — v praxi je to jméno obchodníka.
        $kandidati = [];

        foreach ($radek as $i => $bunka) {
            if (in_array($i, [$mapa['date'], $mapa['amount'], $mapa['currency']], true)) continue;
            if (trim($bunka) === '' || is_numeric(str_replace([' ', ','], ['', '.'], $bunka))) continue;

            $kandidati[] = trim($bunka);
        }

        usort($kandidati, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $kandidati === [] ? null : mb_substr($kandidati[0], 0, 500);
    }
}
