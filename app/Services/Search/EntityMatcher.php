<?php

namespace App\Services\Search;

use App\Models\GallerySpace;
use App\Models\Person;
use App\Models\Place;
use Illuminate\Support\Collection;

/**
 * Kdo a kde — z toho, co v dotazu zbylo po rozpoznání času a druhu média.
 *
 * QueryInterpreter vyřízne „loni", „videa" a podobné; co zůstane, se dosud hledalo
 * doslova v `search_text`. Jenže čeština skloňuje: „Praha" najde fotku z Prahy,
 * „z Prahy" ne, protože v textu je uloženo „Praha" a hledá se „prahy". Totéž „u Makinky"
 * proti „Makinka" a „s Adrianem" proti „Adrian". Přitom právě takhle se mluví — nikdo
 * nenapíše „fotky Praha".
 *
 * Porovnává se proto společný začátek slova, ne celé slovo. Čeština mění konce, ne kořeny:
 * Praha, Prahy, Praze, Prahou mají společné „prah". Aby se to nezvrhlo v hádání, platí
 * tři podmínky najednou — společný začátek aspoň čtyři znaky a od něj nejvýš tři znaky
 * na každou stranu. „Prahy" tak sedne na „Praha", ale „prahovým" na ni už ne.
 *
 * Když se slovo na někoho nebo někam trefí, přestává se hledat v textu a stává se z něj
 * filtr podle vazby. To je přesnější než hledání v textu — a hlavně se to dá pojmenovat
 * ve štítku nad výsledky, stejně jako se pojmenuje rozpoznané období.
 *
 * Mez, kterou porovnávání začátků má: slova lišící se jen poslední hláskou projdou.
 * „mistr" se trefí na „Místo" a žádné nastavení hranic to nespraví. Právě proto se
 * nález pojmenuje ve štítku — kdo hledal mistra a nad výsledky uvidí „Kavárna Místo",
 * ví, co se stalo, a může to vzít zpátky. Tiché hledání v textu tuhle možnost nedávalo.
 */
class EntityMatcher
{
    /** Kratší slova se nezkoumají: „na", „do", „to" by se trefila na cokoliv. */
    private const NEJKRATSI_SLOVO = 4;

    /**
     * Kolik znaků musí mít společný začátek a kolik nejvýš smí zbýt za ním.
     *
     * Zkoušel jsem začátek zkrátit na tři, aby prošlo „v Praze" — a hned se „adresa"
     * začala trefovat na Adriana, protože obojí začíná na „adr" a zbývají tři znaky.
     * Tichý filtr podle běžného slova je horší než nenalezené místo, takže hranice
     * zůstává na čtyřech a změna kořene se řeší jinde, v `mekkeVarianty`.
     */
    private const NEJKRATSI_ZACATEK = 4;
    private const NEJDELSI_KONCOVKA = 3;

    /**
     * @return array{place_ids: list<int>, person_ids: list<int>, labels: list<string>, text: string}
     */
    public function match(GallerySpace $space, string $text): array
    {
        $vysledek = ['place_ids' => [], 'person_ids' => [], 'labels' => [], 'text' => $text];

        $slova = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($slova === []) {
            return $vysledek;
        }

        $mista = $this->mista($space);
        $lide = $this->lide($space);

        $zbytek = [];

        foreach ($slova as $slovo) {
            if (mb_strlen($slovo) < self::NEJKRATSI_SLOVO) {
                $zbytek[] = $slovo;

                continue;
            }

            $misto = $this->najdi($mista, $slovo);

            if ($misto !== null) {
                $vysledek['place_ids'][] = $misto['id'];
                $vysledek['labels'][] = $misto['label'];

                continue;
            }

            $clovek = $this->najdi($lide, $slovo);

            if ($clovek !== null) {
                $vysledek['person_ids'][] = $clovek['id'];
                $vysledek['labels'][] = $clovek['label'];

                continue;
            }

            $zbytek[] = $slovo;
        }

        $vysledek['place_ids'] = array_values(array_unique($vysledek['place_ids']));
        $vysledek['person_ids'] = array_values(array_unique($vysledek['person_ids']));
        $vysledek['labels'] = array_values(array_unique($vysledek['labels']));
        $vysledek['text'] = implode(' ', $zbytek);

        return $vysledek;
    }

    /**
     * Místa i s městem a zemí — „z Prahy" má najít fotky z kavárny v Praze, ne jen místo,
     * které se tak jmenuje.
     *
     * @return Collection<int, array{id: int, label: string, klice: list<string>}>
     */
    private function mista(GallerySpace $space): Collection
    {
        return Place::where('gallery_space_id', $space->id)
            ->get(['id', 'name', 'city', 'country'])
            ->map(fn (Place $m) => [
                'id' => (int) $m->id,
                'label' => $m->name,
                'klice' => $this->klice([$m->name, $m->city, $m->country]),
            ]);
    }

    /**
     * @return Collection<int, array{id: int, label: string, klice: list<string>}>
     */
    private function lide(GallerySpace $space): Collection
    {
        return Person::where('gallery_space_id', $space->id)
            ->get(['id', 'name', 'nickname'])
            ->map(fn (Person $o) => [
                'id' => (int) $o->id,
                'label' => $o->name,
                'klice' => $this->klice([$o->name, $o->nickname]),
            ]);
    }

    /**
     * Jednotlivá slova názvu, každé zvlášť.
     *
     * „Chata na Lysé hoře" se má dát najít slovem „chata" i „hoře". Předložky a spojky
     * vypadnou samy — jsou kratší než hranice, pod kterou se slova nezkoumají.
     *
     * @param  list<?string>  $zdroje
     * @return list<string>
     */
    private function klice(array $zdroje): array
    {
        $klice = [];

        foreach (array_filter($zdroje) as $zdroj) {
            foreach (preg_split('/\s+/u', (string) $zdroj, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $slovo) {
                $n = $this->normalizuj($slovo);

                if (mb_strlen($n) >= self::NEJKRATSI_SLOVO) {
                    $klice[] = $n;

                    foreach ($this->mekkeVarianty($n) as $varianta) {
                        $klice[] = $varianta;
                    }
                }
            }
        }

        return array_values(array_unique($klice));
    }

    /**
     * Tvary, kde se mění i poslední souhláska kořene.
     *
     * Čeština v šestém pádě střídá h/g → z, k → c a ch → š: Praha → v Praze, matka →
     * matce, socha → na soše. Porovnávání společných začátků na tohle nestačí, protože
     * „Praze" má s „Prahou" společné jen tři znaky — a povolit tři znaky plošně znamená
     * trefovat se na běžná slova. Vyrábí se proto rovnou ta jedna varianta navíc.
     *
     * @return list<string>
     */
    private function mekkeVarianty(string $klic): array
    {
        $strida = ['h' => 'z', 'g' => 'z', 'k' => 'c', 'ch' => 's'];
        $varianty = [];

        // Kořen bez koncové samohlásky: „praha" → „prah", „matka" → „matk".
        $koren = preg_replace('/[aeiouy]$/u', '', $klic) ?? $klic;

        if ($koren === $klic || mb_strlen($koren) < 3) {
            return [];
        }

        foreach ($strida as $z => $na) {
            if (str_ends_with($koren, $z)) {
                $varianty[] = mb_substr($koren, 0, mb_strlen($koren) - mb_strlen($z)).$na.'e';
                break;
            }
        }

        return $varianty;
    }

    /**
     * @param  Collection<int, array{id: int, label: string, klice: list<string>}>  $kde
     * @return array{id: int, label: string}|null
     */
    private function najdi(Collection $kde, string $slovo): ?array
    {
        $hledane = $this->normalizuj($slovo);

        foreach ($kde as $polozka) {
            foreach ($polozka['klice'] as $klic) {
                if ($this->stejnyZaklad($hledane, $klic)) {
                    return ['id' => $polozka['id'], 'label' => $polozka['label']];
                }
            }
        }

        return null;
    }

    /** Shodují se ta dvě slova v kořeni? */
    private function stejnyZaklad(string $a, string $b): bool
    {
        $spolecne = 0;
        $max = min(mb_strlen($a), mb_strlen($b));

        while ($spolecne < $max && mb_substr($a, $spolecne, 1) === mb_substr($b, $spolecne, 1)) {
            $spolecne++;
        }

        return $spolecne >= self::NEJKRATSI_ZACATEK
            && mb_strlen($a) - $spolecne <= self::NEJDELSI_KONCOVKA
            && mb_strlen($b) - $spolecne <= self::NEJDELSI_KONCOVKA;
    }

    /**
     * Malá písmena bez diakritiky.
     *
     * Bez toho by „Ostravice" nesedlo na „ostravici" jen kvůli velkému O, a „hoře"
     * na „hore" kvůli háčku — a psát v hledání diakritiku nikdo nechce.
     */
    private function normalizuj(string $slovo): string
    {
        $bez = \Illuminate\Support\Str::ascii($slovo);

        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $bez) ?? '');
    }
}
