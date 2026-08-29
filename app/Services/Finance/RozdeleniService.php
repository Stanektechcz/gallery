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
     * @return array<string, mixed>
     */
    public function rozdel(array $polozky, float $kDispozici, string $mena): array
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
                'currency' => $mena,
            ];
        }

        $planCelkem = round(array_sum(array_column($radky, 'planned')), 2);
        $chybi = round(array_sum(array_column($radky, 'missing')), 2);

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
        ];
    }
}
