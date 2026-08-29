<?php

namespace App\Services\Finance;

/**
 * Rozdělení peněz mezi vyhrazené částky podle pořadí důležitosti.
 *
 * Rozpočet říká, kolik je celkem. Vyhrazené částky říkají, na co to je. Tahle služba
 * odpovídá na třetí otázku, kterou se nikdo neptá, dokud peníze nedojdou: **co se
 * pokryje, když jich není dost** — a co se pokryje navíc, když nečekaně přibudou.
 *
 * Pokrývá se odshora. Nájem dřív než restaurace, jídlo dřív než výlety. Rovnoměrné
 * krácení by znamenalo, že se nezaplatí celý nájem *a* nebude se pořádně jíst; takhle
 * je aspoň vidět, na které položce peníze došly.
 *
 * Příjem se do rozdělení započítá sám. Nic se nepřepočítává na povel a nikde se
 * neukládá výsledek — kdyby se ukládal, rozešel by se s knihou v okamžiku, kdy někdo
 * zapíše výdaj zpětně.
 */
class RozdeleniService
{
    /**
     * @param  array<int, array{category_id: int, category_uuid: string, name: string, color: ?string, planned: float, spent: float, priority: int}>  $polozky
     * @param  int|null  $dni  délka období; null u rozpočtu bez konce
     * @param  int  $uteklo  kolik dní z období už uběhlo včetně dneška
     * @return array<string, mixed>
     */
    public function rozdel(array $polozky, float $kDispozici, string $mena, ?int $dni = null, int $uteklo = 1): array
    {
        // Stejná priorita se řadí podle plánované částky odshora: když dvě položky
        // soupeří o poslední peníze, dostane je ta větší celá, místo aby obě zůstaly
        // napůl pokryté a ani jedna nestačila na to, k čemu byla.
        usort($polozky, fn ($a, $b) => [$a['priority'], -$a['planned']] <=> [$b['priority'], -$b['planned']]);

        $zbyva = $kDispozici;
        $radky = [];
        $prvniNepokryta = null;

        foreach ($polozky as $poradi => $p) {
            $plan = round((float) $p['planned'], 2);
            $pokryto = round(min($plan, max(0, $zbyva)), 2);
            $zbyva = round($zbyva - $pokryto, 2);

            $stav = match (true) {
                $pokryto >= $plan => 'pokryto',
                $pokryto > 0 => 'castecne',
                default => 'nepokryto',
            };

            if ($stav !== 'pokryto' && $prvniNepokryta === null) {
                $prvniNepokryta = $p['name'];
            }

            $utraceno = round((float) $p['spent'], 2);

            $radky[] = [
                'category_id' => $p['category_id'],
                'category_uuid' => $p['category_uuid'],
                'name' => $p['name'],
                'color' => $p['color'],
                'order' => $poradi + 1,
                'priority' => $p['priority'],
                'planned' => $plan,
                'covered' => $pokryto,
                'missing' => round($plan - $pokryto, 2),
                'spent' => $utraceno,
                // Zbývá se počítá proti pokryté částce, ne proti plánu. Slibovat peníze,
                // které v rozpočtu nejsou, je horší než přiznat, že chybí.
                'remaining' => round($pokryto - $utraceno, 2),
                'percent' => $plan > 0 ? min(999, (int) round($utraceno / $plan * 100)) : 0,
                'state' => $stav,
                'count' => (int) ($p['count'] ?? 0),
                'currency' => $mena,
            ];
        }

        $planCelkem = round(array_sum(array_column($radky, 'planned')), 2);
        $chybi = round(array_sum(array_column($radky, 'missing')), 2);

        $radky = $this->predpovez($radky, $dni, $uteklo);

        return [
            'currency' => $mena,
            'available' => round($kDispozici, 2),
            'planned' => $planCelkem,
            // Volné peníze jsou to, co po rozdělení zbylo. Nejsou to „úspory" — jen
            // částka, kterou zatím nikdo na nic neurčil.
            'free' => round(max(0, $zbyva), 2),
            'missing' => $chybi,
            'first_uncovered' => $prvniNepokryta,
            'rows' => $radky,
            'release' => $this->uvolneni($radky, round(max(0, $zbyva), 2), $mena, $chybi, $prvniNepokryta),
        ];
    }

    /**
     * Kam každá položka doputuje, když se tempo nezmění.
     *
     * Počítá se z uběhlé části období, ne z celého — jinak by se první den každá
     * kategorie tvářila, že skončí skoro na nule.
     *
     * Pod tři dny se předpověď nedělá. Z jednoho nákupu za 60 € nejde odvodit měsíc;
     * číslo by vyšlo, vypadalo by věrohodně a bylo by nesmyslné.
     *
     * @param  array<int, array<string, mixed>>  $radky
     * @return array<int, array<string, mixed>>
     */
    private function predpovez(array $radky, ?int $dni, int $uteklo): array
    {
        $lzeVest = $dni !== null && $uteklo >= 3;

        return array_map(function (array $r) use ($lzeVest, $dni, $uteklo) {
            // Nestačí, že uběhlo dost dní — musí být i dost záznamů. Nájem zaplacený
            // jednou patnáctého by se natáhl, jako by se platil každý den, a rozpočet
            // by chtěl přesouvat peníze na položku, která má dávno zaplaceno.
            //
            // Tři záznamy jsou minimum, ze kterého je vidět tempo. Pod tím je to jeden
            // nákup a dva jeho násobky.
            if (! $lzeVest || ($r['count'] ?? 0) < 3) {
                return $r + ['projected' => null, 'verdict' => 'unknown', 'surplus' => 0.0, 'shortfall' => 0.0];
            }

            $odhad = round($r['spent'] / max(1, $uteklo) * $dni, 2);

            // Porovnává se s pokrytou částkou, ne s plánem: uvolnit se dá jen to, co
            // v rozpočtu doopravdy je. Slíbené peníze se přesouvat nedají.
            $kryti = (float) $r['covered'];

            return $r + [
                'projected' => $odhad,
                'verdict' => match (true) {
                    $odhad > $kryti => 'nevyjde',
                    $odhad > $kryti * 0.95 => 'tesne',
                    default => 'vyjde',
                },
                'surplus' => round(max(0, $kryti - $odhad), 2),
                'shortfall' => round(max(0, $odhad - $kryti), 2),
            ];
        }, $radky);
    }

    /**
     * Co se dá uvolnit z jedněch peněz do druhých.
     *
     * Kategorie, která svoje peníze podle všeho nevyčerpá, je drží zbytečně; jiné
     * zatím chybí. Přerozdělit to ručně jde taky, jenže to znamená spočítat si dvě
     * odečítání a trefit se — a nikdo to nedělá, takže plán postupně přestane platit.
     *
     * Bere se odspodu a dává odshora: uvolňuje se z toho, co je nejmíň důležité, a
     * dorovnává to, na čem nejvíc záleží. Opačné pořadí by znamenalo vzít peníze
     * nájmu ve prospěch výletů.
     *
     * Samo se nic nepřepíše. Výpočet je automatický, zásah do plánu jedno klepnutí —
     * plán, který se mění pod rukama, přestane být plán.
     *
     * @param  array<int, array<string, mixed>>  $radky
     * @return array<string, mixed>
     */
    private function uvolneni(array $radky, float $volne, string $mena, float $nepokryto, ?string $prvniNepokryta): array
    {
        $davaji = array_values(array_filter($radky, fn ($r) => ($r['surplus'] ?? 0) > 0));
        $potrebuji = array_values(array_filter($radky, fn ($r) => ($r['shortfall'] ?? 0) > 0));

        // Nejmíň důležité dává první, nejdůležitější bere první.
        usort($davaji, fn ($a, $b) => $b['priority'] <=> $a['priority']);
        usort($potrebuji, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        // Potřeba je dvojí a obojí je skutečná: kategorie, které podle tempa přetečou
        // svůj plán, a kategorie, na které se nedostalo peněz vůbec. Kdyby se počítala
        // jen ta první, seděla by doprava na tisícovce, kterou neutratí, zatímco na
        // volný čas by nebylo nic — a nabídka by mlčela.
        $potreba = round(array_sum(array_column($potrebuji, 'shortfall')) + $nepokryto, 2);
        $kMani = round(array_sum(array_column($davaji, 'surplus')) + $volne, 2);
        $vzit = round(min($kMani, $potreba), 2);

        $presuny = [];
        $zbyva = $vzit;

        foreach ($potrebuji as $p) {
            if ($zbyva <= 0) break;

            $castka = round(min((float) $p['shortfall'], $zbyva), 2);
            $zbyva = round($zbyva - $castka, 2);

            $presuny[] = [
                'category_uuid' => $p['category_uuid'],
                'name' => $p['name'],
                'amount' => $castka,
                'new_planned' => round((float) $p['planned'] + $castka, 2),
            ];
        }

        // Odkud se to vezme. Volné peníze se sáhnou první — ty nikomu neubudou.
        $zbyvaVzit = round($vzit - $volne, 2);
        $odebrani = [];

        foreach ($davaji as $d) {
            if ($zbyvaVzit <= 0) break;

            $castka = round(min((float) $d['surplus'], $zbyvaVzit), 2);
            $zbyvaVzit = round($zbyvaVzit - $castka, 2);

            $odebrani[] = [
                'category_uuid' => $d['category_uuid'],
                'name' => $d['name'],
                'amount' => $castka,
                'new_planned' => round((float) $d['planned'] - $castka, 2),
            ];
        }

        // Co se uvolní, aniž by to někdo dostal jmenovitě. Nezmizí to — přestane být
        // zamluvené, takže se pokrývá dál odshora a dostane se i na to, na co dřív ne.
        $uvolneno = round($vzit - array_sum(array_column($presuny, 'amount')), 2);

        return [
            'currency' => $mena,
            'available' => $kMani,
            'from_free' => round(min($volne, $vzit), 2),
            'givers' => $odebrani,
            'receivers' => $presuny,
            // Kolik peněz se hne. Ne součet obou seznamů — táž koruna je v jednom jako
            // odebraná a ve druhém jako přidaná a spočítat ji dvakrát by nabídku zdvojilo.
            'moved' => $vzit,
            'frees_up' => max(0, $uvolneno),
            'covers' => $uvolneno > 0 ? $prvniNepokryta : null,
            // Kolik by po přerozdělení pořád chybělo. Když se nesejde dost, je poctivější
            // to říct, než přesunout část a tvářit se, že je vyřešeno.
            'still_short' => round(max(0, $potreba - $vzit), 2),
        ];
    }
}
