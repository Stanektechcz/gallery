<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Services\Obsah\FinanceRozbory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sezónní fondy, které přišly jako změna stavu.
 *
 * Obrazovka umí tři věci: přidat 250 měsíčně, ubrat 250 a obálku utratit.
 * Všechny tři končily v prohlížeči — `seasonVals()` čte `state.season ||
 * SEASON`, takže po prvním kliknutí přestal platit `budget_goals` a začala
 * platit kopie. Dvojice pak v Rozpočtech viděla jiný stav fondu než v jeho
 * vlastní obrazovce.
 */
class RozboryVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['season'];

    public function __construct(private readonly FinanceRozbory $obsah) {}

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('season', $patch);
    }

    /** @return array<string, mixed> */
    public function bezRozboru(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /**
     * Provede zápis a vrátí fondy spočítané znovu z databáze.
     *
     * Vrací se, aby obrazovka po kliknutí neblikla zpátky na hodnoty z načtení
     * stránky — ale **neukládá se**: jediná pravda je `budget_goals`.
     *
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor): array
    {
        if (! Schema::hasTable('budget_goals')) {
            return [];
        }

        $fondy = DB::table('budget_goals as c')
            ->join('budgets as r', 'r.id', '=', 'c.budget_id')
            ->where('r.gallery_space_id', $prostor->id)
            ->get(['c.id', 'c.uuid', 'c.target_amount', 'c.saved_amount', 'c.target_on'])
            ->keyBy('uuid');

        $dnes = CarbonImmutable::now();

        foreach ((array) ($patch['season'] ?? []) as $f) {
            $f = (array) $f;
            $fond = $fondy[(string) ($f['id'] ?? '')] ?? null;

            if ($fond === null) {
                continue;
            }

            $zmena = [];

            // „Obálka utracena, spoří se znovu od nuly."
            if (array_key_exists('saved', $f) && (int) $f['saved'] !== (int) $fond->saved_amount) {
                $zmena['saved_amount'] = max(0, (int) $f['saved']);
            }

            $termin = $this->novyTermin($f, $fond, $zmena, $dnes);

            if ($termin !== null) {
                $zmena['target_on'] = $termin;
            }

            if ($zmena !== []) {
                DB::table('budget_goals')->where('id', $fond->id)->update($zmena + ['updated_at' => now()]);
            }
        }

        $fondy = $this->obsah->kolekce($prostor)['SEASON'] ?? [];

        return $fondy ? ['season' => $fondy] : [];
    }

    /**
     * Měsíční částku nemá kam uložit — je to podíl, ne sloupec.
     *
     * „Odkládat o 250 víc" ale něco znamená: fond bude plný dřív. Termín se
     * proto posune tak, aby při nové částce vyšel — a obrazovka pak spočítá
     * zpátky přesně to číslo, o které dvojice požádala.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $zmena
     */
    private function novyTermin(array $f, object $fond, array $zmena, CarbonImmutable $dnes): ?string
    {
        $per = (int) ($f['per'] ?? 0);

        if ($per <= 0) {
            return null;
        }

        $usporeno = (int) ($zmena['saved_amount'] ?? $fond->saved_amount);
        $chybi = max(0, (int) $fond->target_amount - $usporeno);

        if ($chybi === 0) {
            return null;
        }

        // Přesně tolik měsíců, ne do konce toho posledního: obrazovka počítá
        // částku zpátky ze zbývajících měsíců a konec měsíce by přidal další.
        $mesicu = max(1, (int) ceil($chybi / $per));
        $novy = $dnes->addMonths($mesicu)->toDateString();
        $stary = $fond->target_on ? CarbonImmutable::parse($fond->target_on) : null;

        // Bez změny se nesahá na nic: přepočet by jinak termín posouval
        // o pár dní při každém načtení obrazovky.
        if ($stary !== null && max(1, (int) ceil($dnes->diffInMonths($stary))) === $mesicu) {
            return null;
        }

        return $novy;
    }
}
