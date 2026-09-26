<?php

namespace App\Services\Provoz;

use App\Models\FinanceAccess;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\FinanceRozbory;
use App\Support\Cas;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
    public function zpracuj(array $patch, GallerySpace $prostor, User $kdo): array
    {
        if (! Tabulky::je('budget_goals')) {
            return [];
        }

        /*
         * `lockForUpdate` drží řádky, dokud `zpracuj()` neskončí (běží uvnitř
         * `DB::transaction` ve `StateController`) — vklad z Rozpočtů, který by
         * jinak spadl přesně mezi tohle čtení a zápis níž, počká.
         */
        $fondy = DB::table('budget_goals as c')
            ->join('budgets as r', 'r.id', '=', 'c.budget_id')
            ->where('r.gallery_space_id', $prostor->id)
            ->lockForUpdate()
            ->get(['c.id', 'c.uuid', 'c.budget_id', 'r.owner_user_id', 'c.target_amount', 'c.saved_amount', 'c.target_on'])
            ->keyBy('uuid');

        // Pražský dnešek: termín „za dva měsíce" se počítá od dne dvojice,
        // ne od UTC, které je po 22:00 ještě včera.
        $dnes = Cas::dnes();

        /*
         * Jen fondy, které prohlížeč opravdu změnil.
         *
         * Posílá se celý seznam, jenže ten je opis z doby načtení: vklad, který
         * mezitím udělal ten druhý v Rozpočtech, v něm chybí. Každé „+250"
         * u jiné obálky pak vrátilo tuhle na starou částku. Bez seznamu změn
         * (starší klient) se bere všechno, ale uspořenou částku hlídá níž
         * pravidlo „jen nula".
         */
        $zmenene = OdebraneVStavu::zmenene($patch, 'season');

        foreach ((array) ($patch['season'] ?? []) as $f) {
            $f = (array) $f;
            $fond = $fondy[(string) ($f['id'] ?? '')] ?? null;

            if ($fond === null || ! OdebraneVStavu::zmeneno($zmenene, $fond->uuid)) {
                continue;
            }

            /*
             * Soukromý rozpočet toho druhého se odsud měnit nedá.
             *
             * Vklad v Rozpočtech to hlídá (`FinanceAkceController::vklad`),
             * stav to obcházel: kdokoli z prostoru vynuloval obálku v cizím
             * rozpočtu, do kterého se smí jen dívat.
             */
            if (! FinanceAccess::smiUpravit('budget', (int) $fond->budget_id, $fond->owner_user_id !== null ? (int) $fond->owner_user_id : null, (int) $kdo->id)) {
                continue;
            }

            $zmena = [];

            /*
             * „Obálka utracena, spoří se znovu od nuly." — nic jiného.
             *
             * Obrazovka uspořenou částku jinak než na nulu nemění; vklady a
             * výběry chodí přes Rozpočty. Jiné číslo je tedy opis z doby
             * načtení a zapsat ho by smazalo vklad, který mezitím přišel.
             *
             * Vynulování je navíc podmíněné tím, co se opravdu přečetlo
             * (`vynulujFond`) — `lockForUpdate` výš řeší souběh v rámci jedné
             * transakce, tahle podmínka je pojistka navíc a jde otestovat
             * i tam, kde SQLite žádný zámek nedrží.
             */
            if (array_key_exists('saved', $f) && is_numeric($f['saved']) && (float) $f['saved'] == 0 && (float) $fond->saved_amount != 0) {
                if ($this->vynulujFond((int) $fond->id, (string) $fond->saved_amount)) {
                    $zmena['saved_amount'] = 0;
                }
            }

            $termin = $this->novyTermin($f, $fond, $zmena, $dnes);

            if ($termin !== null) {
                DB::table('budget_goals')->where('id', $fond->id)->update(['target_on' => $termin, 'updated_at' => now()]);
            }
        }

        $fondy = $this->obsah->kolekce($prostor)['SEASON'] ?? [];

        return $fondy ? ['season' => $fondy] : [];
    }

    /**
     * Vynuluje uspořenou částku, jen když v databázi je pořád to, co jsme přečetli.
     *
     * `WHERE saved_amount = $ocekavano` je podmínka platná v okamžiku zápisu, ne
     * ta přečtená o chvíli dřív — vklad, který mezitím přišel z Rozpočtů
     * (`FinanceAkceController::vklad`), tak zápis nulou nepřepíše. Vrací, jestli
     * se opravdu zapsalo.
     */
    private function vynulujFond(int $id, string $ocekavano): bool
    {
        return DB::table('budget_goals')
            ->where('id', $id)
            ->where('saved_amount', $ocekavano)
            ->update(['saved_amount' => 0, 'updated_at' => now()]) > 0;
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
