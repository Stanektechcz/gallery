<?php

namespace App\Services\Finance;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Services\Banking\BankTransactionClassifier;

/**
 * Uhádne kategorii rozpočtu z popisu položky.
 *
 * Klasifikátor obchodníků v systému už je — zná Lidl, Kaufland, RegioJet i Booking a
 * navíc umí vlastní pravidla, která si prostor nastavil. Bez tohohle mostu ale mluví
 * jinou řečí než rozpočty: vrací pevný slovník („food", „transport"), kdežto kategorie
 * si každý pojmenuje po svém — někdo „Jídlo", jiný „Nákupy" nebo „Potraviny".
 *
 * Most je proto slovníkový, ne strojový. Seznam synonym se dá přečíst a doplnit, což je
 * u hádání to jediné, co se počítá: když to jednou trefí špatně, musí být vidět proč.
 *
 * Nic se nezapisuje natvrdo. Výsledek je návrh, který člověk v náhledu vidí a může ho
 * přepsat — import, který si sám zařadí dvě stě položek a nezeptá se, se opravuje hůř,
 * než kdyby nezařadil nic.
 */
class CategoryMatcher
{
    /**
     * Jak se česky (a anglicky) jmenují kategorie, které klasifikátor umí rozeznat.
     *
     * Klíč je slovník klasifikátoru, hodnoty jsou tvary, které lidé používají v názvech
     * vlastních kategorií. Porovnává se bez diakritiky a malými písmeny.
     *
     * @var array<string, array<int, string>>
     */
    private const SYNONYMA = [
        'food' => ['jidlo', 'potraviny', 'nakupy', 'nakup', 'strava', 'jideln', 'restaurac', 'food', 'groceries'],
        'transport' => ['doprava', 'cestovani', 'jizdne', 'benzin', 'palivo', 'auto', 'mhd', 'transport', 'travel'],
        'accommodation' => ['ubytovani', 'najem', 'bydleni', 'hotel', 'byt', 'rent', 'accommodation'],
        'activities' => ['zabava', 'volny cas', 'kultura', 'aktivity', 'vylety', 'zazitky', 'fun', 'activities'],
        'insurance' => ['pojisteni', 'pojistka', 'insurance'],
    ];

    public function __construct(private readonly BankTransactionClassifier $classifier) {}

    /**
     * Ke každému řádku doplní id kategorie, pokud se nějaká trefí.
     *
     * @param  array<int, array<string, mixed>>  $radky
     * @return array<int, array<string, mixed>>
     */
    public function suggest(Budget $budget, array $radky): array
    {
        $budget->loadMissing('categories');

        $podleSlovniku = $this->mapaKategorii($budget->categories);

        // Prostor je potřeba kvůli vlastním pravidlům; ta mají přednost před výchozím
        // seznamem obchodníků a je to jediné místo, kde se dá hádání opravit natrvalo.
        $space = $budget->gallerySpace;

        foreach ($radky as $i => $radek) {
            $popis = trim((string) ($radek['note'] ?? ''));

            if ($popis === '' || $space === null) {
                $radky[$i]['suggested_category_id'] = null;
                $radky[$i]['suggested_from'] = null;

                continue;
            }

            $vysledek = $this->classifier->classify($space, [
                'merchant_name' => $popis,
                'description' => $popis,
                'amount' => $radek['kind'] === 'income' ? $radek['amount'] : -$radek['amount'],
            ]);

            $radky[$i]['suggested_category_id'] = $podleSlovniku[$vysledek['category']] ?? null;
            // Odkud návrh přišel — vlastní pravidlo, nebo výchozí seznam. V náhledu se to
            // ukáže, aby bylo poznat, čemu se dá věřit víc.
            $radky[$i]['suggested_from'] = $vysledek['rule'] !== null ? 'rule' : 'default';
        }

        return $radky;
    }

    /**
     * Přiřadí slovníku klasifikátoru skutečné kategorie rozpočtu.
     *
     * @param  \Illuminate\Support\Collection<int, BudgetCategory>  $kategorie
     * @return array<string, int>
     */
    private function mapaKategorii($kategorie): array
    {
        $mapa = [];

        foreach (self::SYNONYMA as $slovnikovy => $tvary) {
            foreach ($kategorie as $kategorieRozpoctu) {
                $nazev = $this->klic($kategorieRozpoctu->name);

                foreach ($tvary as $tvar) {
                    // str_contains oběma směry: „Jídlo a pití" chytí „jidlo" a naopak
                    // krátký název „Jídlo" chytí delší tvar ze seznamu.
                    if (str_contains($nazev, $tvar) || str_contains($tvar, $nazev)) {
                        // První nalezená vyhrává — kategorie jsou seřazené tak, jak si je
                        // člověk založil, a to je rozumnější pořadí než abeceda.
                        $mapa[$slovnikovy] ??= $kategorieRozpoctu->id;

                        continue 3;
                    }
                }
            }
        }

        return $mapa;
    }

    /** Bez diakritiky a malými písmeny, aby „Jídlo" a „jidlo" byly totéž. */
    private function klic(string $text): string
    {
        $bez = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        return trim(preg_replace('/[^a-z0-9 ]/', '', mb_strtolower($bez)));
    }
}
