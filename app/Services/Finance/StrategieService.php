<?php

namespace App\Services\Finance;

/**
 * Rady, co s penězi dělat — ne jen čísla, co se s nimi stalo.
 *
 * Rozdíl je zásadní. „Za potraviny jste utratili 4 800" je údaj; „utrácíte o třetinu
 * rychleji, než na kolik jsou peníze, a při tomhle tempu dojdou 12. ledna" je rada,
 * podle které se dá zařídit.
 *
 * Tři pravidla, kterými se to tu řídí:
 *
 *  1. **Nic se nevymýšlí.** Když na radu nejsou data, rada nevznikne. Půl rady je
 *     horší než žádná — člověk podle ní jedná stejně, jen se mýlí.
 *  2. **Každá rada nese číslo.** „Šetřete na jídle" nikoho nikam neposune. „Když
 *     ubereš euro denně, vydrží peníze o 23 dní déle" ano.
 *  3. **Řadí se podle toho, kolik to vynese**, ne podle toho, jak zle to zní.
 *     Nahoře je to, co má největší vliv na to, jestli peníze vydrží.
 */
class StrategieService
{
    /** Pod tuhle částku se rada nevyplatí — z drobných se nedá ušetřit pobyt. */
    private const DROBNE = 20.0;

    /**
     * @param  array<string, mixed>  $rozpocet  výstup rozpočtu i s rozdělením
     * @return array<int, array<string, mixed>>
     */
    public function proRozpocet(array $rozpocet): array
    {
        $mena = $rozpocet['currency'];
        $bezpecne = $rozpocet['safe_daily'];
        $rady = [];

        foreach ([
            $this->tempo($rozpocet, $bezpecne, $mena),
            $this->dokdyVydrzi($rozpocet, $bezpecne, $mena),
            $this->pevneNaklady($rozpocet, $mena),
            $this->rezerva($rozpocet, $mena),
            $this->nepokryte($rozpocet, $mena),
        ] as $rada) {
            if ($rada !== null) {
                $rady[] = $rada;
            }
        }

        foreach ($this->kdeUbrat($rozpocet, $mena) as $rada) {
            $rady[] = $rada;
        }

        usort($rady, fn ($a, $b) => [$b['weight'], $b['impact'] ?? 0] <=> [$a['weight'], $a['impact'] ?? 0]);

        return array_slice($rady, 0, 6);
    }

    /**
     * Jak rychle se utrácí proti tomu, kolik je.
     *
     * Nejdůležitější číslo celého pobytu, a přitom je vidět až tehdy, když se obě
     * čísla postaví vedle sebe. Samotné „utratili jste 4 800" nikomu neřekne, jestli
     * je to moc.
     *
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>|null
     */
    private function tempo(array $r, array $b, string $mena): ?array
    {
        $dni = $b['days_left'] ?? null;

        if (($b['per_day'] ?? null) === null || $dni === null || $r['spent'] <= 0) {
            return null;
        }

        $uteklo = $this->uteklo($r);

        if ($uteklo < 3) {
            return null;
        }

        $skutecne = round($r['spent'] / $uteklo, 2);
        $doporucene = (float) $b['per_day'];
        $rozdil = round($skutecne - $doporucene, 2);

        if (abs($rozdil) < 0.5) {
            return [
                'key' => 'tempo-sedi',
                'tone' => 'dobre',
                'weight' => 60,
                'title' => 'Tempo sedí',
                'text' => 'Utrácíte zhruba tolik, kolik je na den k dispozici. Když to takhle půjde dál, peníze vydrží do konce.',
                'amount' => $skutecne,
                'currency' => $mena,
            ];
        }

        $rychleji = $rozdil > 0;

        return [
            'key' => 'tempo',
            'tone' => $rychleji ? 'pozor' : 'dobre',
            'weight' => $rychleji ? 100 : 55,
            'impact' => abs($rozdil) * $dni,
            'title' => $rychleji ? 'Utrácíte rychleji, než je k dispozici' : 'Utrácíte míň, než je k dispozici',
            'text' => $rychleji
                ? 'Denně jde o '.$this->cislo(abs($rozdil)).' '.$mena.' víc, než kolik na den zbývá. Za zbytek období je to '
                    .$this->cislo(abs($rozdil) * $dni).' '.$mena.', které nikde nejsou.'
                : 'Denně zbývá '.$this->cislo(abs($rozdil)).' '.$mena.'. Za zbytek období z toho bude '
                    .$this->cislo(abs($rozdil) * $dni).' '.$mena.' navíc.',
            'amount' => $skutecne,
            'currency' => $mena,
        ];
    }

    /**
     * Kdy peníze dojdou, když se nic nezmění.
     *
     * Datum je konkrétnější než procento. „Vyčerpáno na 78 %" si nikdo nepřevede na
     * „do konce ledna to nedáme", i když je to totéž.
     *
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>|null
     */
    private function dokdyVydrzi(array $r, array $b, string $mena): ?array
    {
        $uteklo = $this->uteklo($r);

        if ($uteklo < 3 || $r['spent'] <= 0 || $r['ends_on'] === null) {
            return null;
        }

        $naDen = $r['spent'] / $uteklo;
        $zbyva = (float) $r['remaining'] - (float) $r['reserve'];

        if ($naDen <= 0 || $zbyva <= 0) {
            return null;
        }

        $vydrzi = (int) floor($zbyva / $naDen);
        $doKonce = (int) ($b['days_left'] ?? 0);

        if ($vydrzi >= $doKonce) {
            return null;
        }

        $chybi = $doKonce - $vydrzi;

        return [
            'key' => 'dojdou',
            'tone' => 'spatne',
            'weight' => 110,
            'impact' => $chybi * $naDen,
            'title' => 'Při tomhle tempu peníze nedojdou do konce',
            'text' => 'Vydrží ještě zhruba '.$this->dni($vydrzi).', do konce období jich zbývá '.$this->dni($doKonce)
                .'. Chybí '.$this->cislo($chybi * $naDen).' '.$mena.', nebo se musí ubrat '
                .$this->cislo($zbyva / max(1, $doKonce)).' '.$mena.' na den.',
            'amount' => round($zbyva / max(1, $doKonce), 2),
            'currency' => $mena,
        ];
    }

    /**
     * Kolik z rozpočtu spolkne to, co se nedá ovlivnit.
     *
     * Nájem se nedá „ušetřit" chytrým nakupováním. Když sežere většinu peněz, je to
     * ta nejdůležitější věc, kterou má člověk vědět — a jediná páka je najít levnější
     * bydlení, ne kupovat levnější rohlíky.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>|null
     */
    private function pevneNaklady(array $r, string $mena): ?array
    {
        // Pevná platba je ta, která má předpis, nebo se za celé období objevila jednou
        // dvakrát. Kategorie bez jediného záznamu se sem počítat nesmí: prázdné
        // potraviny nejsou nájem, jen se o nich zatím nic neví — a rada by pak tvrdila,
        // že se osmdesát procent rozpočtu nedá ovlivnit, což je nesmysl.
        $pevne = collect($r['allocation']['rows'])
            ->filter(fn (array $k) => ($k['recurring'] ?? false)
                || ($k['priority'] <= 10 && $k['count'] >= 1 && $k['count'] <= 2))
            ->sum('planned');

        $celkem = (float) $r['allocation']['available'];

        if ($pevne <= 0 || $celkem <= 0) {
            return null;
        }

        $podil = (int) round($pevne / $celkem * 100);

        if ($podil < 35) {
            return null;
        }

        $zbytek = $celkem - $pevne;
        $dni = max(1, (int) ($r['safe_daily']['days_left'] ?? 1));

        return [
            'key' => 'pevne-naklady',
            'tone' => $podil >= 60 ? 'spatne' : 'pozor',
            'weight' => 90,
            'impact' => $pevne,
            'title' => 'Pevné platby berou '.$podil.' % peněz',
            'text' => 'Na všechno ostatní zbývá '.$this->cislo($zbytek).' '.$mena.', tedy '
                .$this->cislo($zbytek / $dni).' '.$mena.' na den. Tuhle část rozpočtu nezmění chytré nakupování, '
                .'jedině levnější bydlení nebo delší pobyt za stejný nájem.',
            'amount' => round($zbytek / $dni, 2),
            'currency' => $mena,
        ];
    }

    /**
     * Kde ubrat, aby to bylo znát.
     *
     * Deset procent z největší proměnlivé položky vynese víc než celá nejmenší
     * položka. Bez tohohle srovnání lidi šetří tam, kde je to nejvíc vidět, ne tam,
     * kde je to nejvíc znát.
     *
     * @param  array<string, mixed>  $r
     * @return array<int, array<string, mixed>>
     */
    private function kdeUbrat(array $r, string $mena): array
    {
        $dni = max(1, (int) ($r['safe_daily']['days_left'] ?? 1));

        return collect($r['allocation']['rows'])
            // Jednorázové platby se neškrtají po deseti procentech. Nájem se buď
            // platí celý, nebo se člověk stěhuje.
            ->filter(fn (array $k) => $k['count'] >= 3 && $k['planned'] >= self::DROBNE)
            ->sortByDesc('planned')
            ->take(1)
            ->map(function (array $k) use ($mena, $dni) {
                $desetina = round($k['planned'] * 0.1, 2);

                return [
                    'key' => 'ubrat-'.$k['category_uuid'],
                    'tone' => 'tip',
                    'weight' => 70,
                    'impact' => $desetina,
                    'title' => 'Největší páka je '.$k['name'],
                    'text' => 'Ubrat desetinu tady znamená '.$this->cislo($desetina).' '.$mena
                        .', tedy '.$this->cislo($desetina / $dni).' '.$mena.' na den navíc jinde. '
                        .'U menších položek by stejné procento nebylo znát.',
                    'amount' => $desetina,
                    'currency' => $mena,
                ];
            })->values()->all();
    }

    /**
     * Rezerva na to, co se nedá naplánovat.
     *
     * Cesta zpátky, lékař, rozbitý telefon. Rozpočet bez rezervy vypadá bohatší, než
     * je — a první nepředvídaná věc z něj udělá dluh.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>|null
     */
    private function rezerva(array $r, string $mena): ?array
    {
        if ((float) $r['reserve'] > 0 || (float) $r['limit'] <= 0) {
            return null;
        }

        $doporuceno = round((float) $r['limit'] * 0.05, 2);

        return [
            'key' => 'bez-rezervy',
            'tone' => 'pozor',
            'weight' => 80,
            'impact' => $doporuceno,
            'title' => 'Rozpočet nemá rezervu',
            'text' => 'Cesta zpátky, lékař nebo rozbitý telefon se do plánu nevejdou. '
                .'Odložit stranou '.$this->cislo($doporuceno).' '.$mena
                .' znamená o tolik míň na útratu, zato to první nepříjemnost nepoloží.',
            'amount' => $doporuceno,
            'currency' => $mena,
        ];
    }

    /**
     * Plán slibuje víc, než kolik je peněz.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>|null
     */
    private function nepokryte(array $r, string $mena): ?array
    {
        $chybi = (float) $r['allocation']['missing'];

        if ($chybi <= 0) {
            return null;
        }

        return [
            'key' => 'nepokryte',
            'tone' => 'pozor',
            'weight' => 85,
            'impact' => $chybi,
            'title' => 'Plán slibuje víc, než kolik je',
            'text' => 'Vyhrazeno je o '.$this->cislo($chybi).' '.$mena.' víc, než kolik je k rozdělení. '
                .'Pokrývá se odshora, takže na konec pořadí se nedostane — první nepokryté je '
                .($r['allocation']['first_uncovered'] ?? 'poslední v pořadí').'.',
            'amount' => $chybi,
            'currency' => $mena,
        ];
    }

    /** Kolik dní z období už uběhlo. */
    private function uteklo(array $r): int
    {
        $od = \Illuminate\Support\Carbon::parse($r['starts_on']);
        $do = $r['ends_on'] ? \Illuminate\Support\Carbon::parse($r['ends_on']) : null;
        $dnes = \Illuminate\Support\Carbon::today();

        return max(0, (int) $od->diffInDays($dnes->min($do ?? $dnes), false) + 1);
    }

    private function cislo(float $c): string
    {
        return number_format($c, 2, ',', ' ');
    }

    /** Dny se skloňují: 1 den, 2 dny, 5 dní. */
    private function dni(int $n): string
    {
        return $n.match (true) {
            $n === 1 => ' den',
            $n >= 2 && $n <= 4 => ' dny',
            default => ' dní',
        };
    }
}
