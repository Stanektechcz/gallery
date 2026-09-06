<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mlčky platná pravidla — vzorce, které aplikace najde v tom, co se opravdu děje.
 *
 * Obrazovka o sobě říká: „Tohle nejsou pravidla, na kterých jste se dohodli.
 * Jsou to vzorce, které aplikace našla." Dosud to byl seznam sedmi vět
 * napsaných v souboru s ukázkovými daty — tedy pravý opak.
 *
 * Hledá se **čtyřmi hledači**, každý nad jinou tabulkou. Žádný z nich si
 * pravidlo nevymýšlí dopředu: buď v datech vzorec je, nebo se nic nepošle.
 *
 * Dva prahy platí pro všechny: pravidlo se ukáže, jen když mělo aspoň osm
 * příležitostí projevit se a drží aspoň ve třech případech z pěti. Pod tím
 * to není tichá dohoda, to je náhoda.
 */
class TichaPravidla
{
    /** Kolik příležitostí musí vzorec mít, aby se dal nazvat pravidlem. */
    private const NEJMENE = 8;

    /** Pod tímhle podílem to není pravidlo, jen shoda okolností. */
    private const PRAH = 0.6;

    /** Kolik měsíců zpátky se hledá. */
    private const MESICU = 18;

    /**
     * @return list<array{rule: string, holds: int, of: int, since: string, kind: string}>
     */
    public function najdi(GallerySpace $prostor): array
    {
        $od = CarbonImmutable::now()->subMonths(self::MESICU)->startOfDay();

        $nalezy = array_merge(
            $this->delbaPrace($prostor, $od),
            $this->klidNaPenize($prostor, $od),
            $this->velkyNakupSeRekne($prostor, $od),
            $this->denBezPlanu($prostor, $od),
        );

        usort($nalezy, fn (array $a, array $b) => ($b['holds'] / $b['of']) <=> ($a['holds'] / $a['of']));

        return array_slice($nalezy, 0, 8);
    }

    /**
     * Kdo dělá jednu práci, nedělá (nebo naopak vždycky dělá) druhou.
     *
     * Hledá se mezi dvojicemi prací, které se dějí týž den. „Kdo vaří,
     * neuklízí kuchyň" je přesně tenhle tvar — a nikde není zapsané, jen se
     * to tak děje.
     *
     * Testují se obě strany: silné vyhýbání i silné párování. Slabý vztah
     * (kolem poloviny) se nehlásí; to není pravidlo, to je náhoda.
     *
     * @return list<array<string, mixed>>
     */
    private function delbaPrace(GallerySpace $prostor, CarbonImmutable $od): array
    {
        if (! Schema::hasTable('house_chore_log')) {
            return [];
        }

        $zapisy = DB::table('house_chore_log')
            ->where('gallery_space_id', $prostor->id)
            ->where('done_at', '>=', $od)
            ->whereNotNull('user_id')
            ->orderBy('done_at')
            ->limit(4000)
            ->get(['chore_name', 'user_id', 'done_at']);

        if ($zapisy->count() < self::NEJMENE) {
            return [];
        }

        // Den → práce → kdo ji ten den dělal.
        $dny = [];

        foreach ($zapisy as $z) {
            $den = CarbonImmutable::parse($z->done_at)->toDateString();
            $dny[$den][$z->chore_name][] = (int) $z->user_id;
        }

        $prvni = CarbonImmutable::parse($zapisy->first()->done_at)->year;
        $dvojice = [];

        foreach ($dny as $prace) {
            $jmena = array_keys($prace);

            foreach ($jmena as $a) {
                foreach ($jmena as $b) {
                    if ($a === $b) {
                        continue;
                    }

                    $kdoA = $prace[$a];
                    $kdoB = $prace[$b];

                    foreach (array_unique($kdoA) as $clovek) {
                        $klic = $a."\0".$b;
                        $dvojice[$klic]['of'] = ($dvojice[$klic]['of'] ?? 0) + 1;
                        $dvojice[$klic]['spolu'] = ($dvojice[$klic]['spolu'] ?? 0) + (in_array($clovek, $kdoB, true) ? 1 : 0);
                    }
                }
            }
        }

        $nalezy = [];

        foreach ($dvojice as $klic => $pocty) {
            if ($pocty['of'] < self::NEJMENE) {
                continue;
            }

            [$a, $b] = explode("\0", $klic);
            $podil = $pocty['spolu'] / $pocty['of'];

            /*
             * Vyhýbání: kdo dělá A, skoro nikdy nedělá B.
             *
             * Je to vztah symetrický, takže se hlásí jen v jednom směru —
             * jinak by na obrazovce stálo „kdo vaří, neuklízí kuchyň"
             * a hned pod tím „kdo uklízí kuchyň, nevaří". Totéž dvakrát.
             */
            if (1 - $podil >= max(self::PRAH, 0.75)) {
                if (strcmp($a, $b) > 0) {
                    continue;
                }

                $nalezy[] = [
                    'rule' => 'Kdo dělá '.mb_strtolower($a).', nedělá '.mb_strtolower($b),
                    'holds' => $pocty['of'] - $pocty['spolu'],
                    'of' => $pocty['of'],
                    'since' => (string) $prvni,
                    'kind' => 'práce',
                ];

                continue;
            }

            // Párování: jedno bez druhého se nedělá.
            if ($podil >= 0.75) {
                $nalezy[] = [
                    'rule' => 'Kdo dělá '.mb_strtolower($a).', dělá i '.mb_strtolower($b),
                    'holds' => $pocty['spolu'],
                    'of' => $pocty['of'],
                    'since' => (string) $prvni,
                    'kind' => 'práce',
                ];
            }
        }

        usort($nalezy, fn (array $x, array $y) => $y['of'] <=> $x['of']);

        return array_slice($nalezy, 0, 3);
    }

    /**
     * Do kolika hodin se řeší peníze.
     *
     * Hledá se hodina, po které se do financí skoro nesahá. Není to zákaz,
     * který si dvojice dala — je to zvyk, který se dá vyčíst z časů zápisů.
     *
     * @return list<array<string, mixed>>
     */
    private function klidNaPenize(GallerySpace $prostor, CarbonImmutable $od): array
    {
        if (! Schema::hasTable('transactions')) {
            return [];
        }

        $casy = DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->where('created_at', '>=', $od)
            ->limit(3000)
            ->pluck('created_at');

        if ($casy->count() < self::NEJMENE) {
            return [];
        }

        foreach ([21, 22, 23] as $hranice) {
            $drzi = $casy->filter(fn ($c) => CarbonImmutable::parse($c)->hour < $hranice)->count();
            $podil = $drzi / $casy->count();

            if ($podil >= 0.85) {
                return [[
                    'rule' => 'Po '.$hranice.':00 se peníze neřeší',
                    'holds' => $drzi,
                    'of' => $casy->count(),
                    'since' => (string) CarbonImmutable::parse($casy->min())->year,
                    'kind' => 'klid',
                ]];
            }
        }

        return [];
    }

    /**
     * Velký nákup se dopředu řekne.
     *
     * Porovnává se, kolik výdajů nad hranicí mělo předem otevřenou rozvahu.
     * Hranice se nevymýšlí — je to devátý desetil útrat, tedy „velký" podle
     * toho, jak ta dvojice utrácí, ne podle obecné částky.
     *
     * @return list<array<string, mixed>>
     */
    private function velkyNakupSeRekne(GallerySpace $prostor, CarbonImmutable $od): array
    {
        if (! Schema::hasTable('transactions') || ! Schema::hasTable('couple_cooling_purchases')) {
            return [];
        }

        $vydaje = DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->where('type', 'expense')
            ->where('occurred_at', '>=', $od)
            ->whereNotNull('amount_from')
            ->limit(3000)
            ->get(['amount_from', 'occurred_at']);

        if ($vydaje->count() < 20) {
            return [];
        }

        $castky = $vydaje->map(fn ($v) => abs((float) $v->amount_from))->sort()->values();
        $hranice = (float) $castky[(int) floor($castky->count() * 0.9)];

        $velke = $vydaje->filter(fn ($v) => abs((float) $v->amount_from) >= $hranice)->values();

        if ($velke->count() < self::NEJMENE) {
            return [];
        }

        $rozvahy = DB::table('couple_cooling_purchases')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('opened_at')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();

        // Rozvaha musí být **před** nákupem, ne po něm — jinak by to bylo
        // vysvětlování, ne domluva.
        $drzi = $velke->filter(function ($v) use ($rozvahy) {
            $den = CarbonImmutable::parse($v->occurred_at);

            foreach ($rozvahy as $r) {
                $rd = CarbonImmutable::parse($r);
                if ($rd->lte($den) && $rd->gte($den->subDays(14))) {
                    return true;
                }
            }

            return false;
        })->count();

        if ($drzi / $velke->count() < self::PRAH) {
            return [];
        }

        return [[
            'rule' => 'Nákup nad '.number_format($hranice, 0, ',', ' ').' Kč se dopředu řekne',
            'holds' => $drzi,
            'of' => $velke->count(),
            'since' => (string) CarbonImmutable::parse($velke->min('occurred_at'))->year,
            'kind' => 'peníze',
        ]];
    }

    /**
     * Den v týdnu, na který se nic neplánuje.
     *
     * Počítá se po týdnech: kolik z nich mělo ten den prázdný. „V pátek se nic
     * neplánuje" je pravidlo právě tehdy, když je pátek prázdný skoro vždycky.
     *
     * @return list<array<string, mixed>>
     */
    private function denBezPlanu(GallerySpace $prostor, CarbonImmutable $od): array
    {
        if (! Schema::hasTable('calendar_events')) {
            return [];
        }

        $udalosti = DB::table('calendar_events')
            ->where('gallery_space_id', $prostor->id)
            ->where('starts_at', '>=', $od)
            ->where('starts_at', '<', CarbonImmutable::now())
            ->limit(4000)
            ->pluck('starts_at');

        if ($udalosti->count() < self::NEJMENE) {
            return [];
        }

        $obsazene = $udalosti->map(fn ($d) => CarbonImmutable::parse($d))
            ->groupBy(fn (CarbonImmutable $d) => $d->dayOfWeekIso.'|'.$d->format('o-W'));

        $prvni = CarbonImmutable::parse($udalosti->min());
        $tydnu = max(1, (int) ceil($prvni->diffInWeeks(CarbonImmutable::now())));

        $nazvy = [1 => 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];
        $nejlepsi = null;

        foreach ($nazvy as $den => $nazev) {
            $sUdalosti = $obsazene->keys()->filter(fn (string $k) => (int) explode('|', $k)[0] === $den)->count();
            $prazdnych = max(0, $tydnu - $sUdalosti);

            if ($tydnu < self::NEJMENE || $prazdnych / $tydnu < 0.8) {
                continue;
            }

            if ($nejlepsi === null || $prazdnych > $nejlepsi['holds']) {
                $nejlepsi = [
                    'rule' => $this->vDen($nazev).' se nic neplánuje',
                    'holds' => $prazdnych,
                    'of' => $tydnu,
                    'since' => (string) $prvni->year,
                    'kind' => 'klid',
                ];
            }
        }

        return $nejlepsi === null ? [] : [$nejlepsi];
    }

    /** „Ve středu", ne „V středu" — předložka patří ke dni. */
    private function vDen(string $nazev): string
    {
        return match ($nazev) {
            'pondělí' => 'V pondělí',
            'úterý' => 'V úterý',
            'středa' => 'Ve středu',
            'čtvrtek' => 'Ve čtvrtek',
            'pátek' => 'V pátek',
            'sobota' => 'V sobotu',
            default => 'V neděli',
        };
    }
}
