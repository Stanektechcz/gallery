<?php

namespace App\Console\Commands;

use App\Services\Provoz\ZalohaDatabaze;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Obnova databáze ze zálohy.
 *
 * Bez `--opravdu` jen ukáže, co v záloze je a jestli se k tomuhle kódu hodí —
 * nic nezmění. S `--opravdu` nejdřív zazálohuje současný stav (pojistka
 * `pred-obnovou-…`, kdyby šlo o špatný soubor) a pak přepíše tabulky, které
 * záloha nese, jejich obsahem. Všechno v jedné transakci: buď celé, nebo nic.
 *
 * Postup po ztrátě databáze: obnovit `.env` (hlavně `APP_KEY` — bez něj nejdou
 * přečíst šifrované sloupce), `php artisan migrate`, pak tenhle příkaz.
 */
class ObnovaZeZalohyCommand extends Command
{
    protected $signature = 'gallery:obnova
        {soubor? : Záloha k obnově (bez něj výpis dostupných)}
        {--opravdu : Opravdu přepsat data obsahem zálohy}';

    protected $description = 'Obnova databáze ze zálohy — bez --opravdu jen ukáže, co by udělala';

    public function handle(ZalohaDatabaze $zaloha): int
    {
        $soubor = $this->argument('soubor');

        if (! is_string($soubor) || $soubor === '') {
            return $this->vypis();
        }

        try {
            $hlavicka = $zaloha->hlavicka($soubor);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tabulky = (array) ($hlavicka['tabulky'] ?? []);
        $this->line(sprintf(
            'Záloha z %s (%s): %d tabulek, %d řádků.',
            $hlavicka['vytvoreno'] ?? '?',
            $hlavicka['ovladac'] ?? '?',
            count($tabulky),
            array_sum($tabulky),
        ));

        if (($nezname = $zaloha->neznameMigrace($hlavicka)) !== []) {
            $this->error('Záloha je z novějšího kódu, než který tu běží (neznámé migrace: '
                .implode(', ', array_slice($nezname, 0, 3)).'). Nasaďte nejdřív tu verzi, ze které je.');

            return self::FAILURE;
        }

        if (! $this->option('opravdu')) {
            $this->warn('Nic se nezměnilo. Obnova vyprázdní '.count($tabulky).' tabulek a naplní je ze zálohy — '
                .'spusťte znovu s --opravdu.');

            return self::SUCCESS;
        }

        try {
            $pojistka = $zaloha->zalohuj('pred-obnovou-');
            $this->line('Pojistka současného stavu: '.$pojistka['cesta']);

            $vysledek = $zaloha->obnov($soubor);
        } catch (Throwable $e) {
            report($e);
            $this->error('Obnova selhala, data zůstala, jak byla: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Obnoveno %d tabulek, %d řádků.', count($vysledek['tabulky']), array_sum($vysledek['tabulky'])));

        foreach ($vysledek['vynechane_sloupce'] as $tabulka => $sloupce) {
            $this->warn("  {$tabulka}: sloupce ".implode(', ', $sloupce).' už ve schématu nejsou — přeskočeny.');
        }

        foreach ($vysledek['chybejici_tabulky'] as $tabulka) {
            $this->warn("  {$tabulka}: tabulka už ve schématu není — přeskočena.");
        }

        return self::SUCCESS;
    }

    private function vypis(): int
    {
        $zalohy = collect(Storage::disk('local')->files(ZalohaDatabaze::SLOZKA))
            ->filter(fn (string $f) => str_ends_with($f, '.ndjson.gz'))
            ->sortDesc()
            ->values();

        if ($zalohy->isEmpty()) {
            $this->warn('Žádná záloha tu není. Vytvoří ji `php artisan gallery:zaloha`.');

            return self::SUCCESS;
        }

        foreach ($zalohy as $soubor) {
            $this->line(sprintf('  %s  (%s kB)', $soubor, number_format(Storage::disk('local')->size($soubor) / 1024, 0, ',', ' ')));
        }

        $this->line('Obnova: php artisan gallery:obnova <soubor> — bez --opravdu jen ukáže, co by udělala.');

        return self::SUCCESS;
    }
}
