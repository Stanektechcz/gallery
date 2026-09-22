<?php

namespace App\Services\Finance;

use App\Models\GallerySpace;
use App\Support\Cas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Zařazení platby podle dřívějších plateb u stejného obchodu.
 *
 * Import výpisu zařadí „ALBERT 0712 Praha" tam, kam dvojice naposledy dala
 * jinou platbu u Alberta. Tatáž pravidla ukazují Finance v „Pravidlech
 * zařazování" — dřív tam u dvojice stálo, že import výpisů galerie nemá.
 */
class ZarazeniPodlePopisu
{
    /** Kolik posledních zařazených plateb se prochází. */
    private const OKNO = 3000;

    /**
     * Klíč obchodu → kategorie z poslední zařazené platby.
     *
     * @return array<string, int>
     */
    public function mapa(GallerySpace $prostor): array
    {
        $mapa = [];

        foreach ($this->zarazene($prostor) as $t) {
            $klic = $this->klic((string) $t->description);

            if ($klic !== '' && ! isset($mapa[$klic])) {
                $mapa[$klic] = (int) $t->category_id;
            }
        }

        return $mapa;
    }

    /**
     * Pravidla k zobrazení: [obchod, kategorie, kolikrát letos].
     *
     * Obchod je poslední popis bez čísel (pobočka, karta); pořadí podle toho,
     * kolikrát se letos platilo. Obchod zaplacený jednou pravidlem není.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public function pravidla(GallerySpace $prostor, int $kolik = 8): array
    {
        $rok = Cas::dnes()->year;
        $pravidla = [];

        foreach ($this->zarazene($prostor) as $t) {
            $klic = $this->klic((string) $t->description);

            if ($klic === '') {
                continue;
            }

            if (! isset($pravidla[$klic])) {
                $pravidla[$klic] = [$this->nazev((string) $t->description), (string) $t->kategorie, 0, 0];
            }

            $pravidla[$klic][3]++;

            if (CarbonImmutable::parse($t->occurred_at)->year === $rok) {
                $pravidla[$klic][2]++;
            }
        }

        return collect($pravidla)
            ->filter(fn (array $p) => $p[3] > 1)
            ->sortByDesc(fn (array $p) => $p[2] * 10000 + $p[3])
            ->take($kolik)
            ->map(fn (array $p) => [$p[0], $p[1], $p[2]])
            ->values()
            ->all();
    }

    /**
     * Popis bez čísel a interpunkce: „ALBERT 0712 Praha" a „Albert 1180 Praha"
     * je týž obchod, číslo pobočky ani karty na zařazení nemá vliv.
     */
    public function klic(string $popis): string
    {
        $t = Str::lower(Str::ascii($popis));
        $t = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z ]+/', ' ', $t)));

        return mb_substr($t, 0, 40);
    }

    /** Čitelný název obchodu: popis bez čísel, s původní diakritikou. */
    private function nazev(string $popis): string
    {
        $t = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[0-9#*\/]+/', ' ', $popis)));

        return mb_substr($t, 0, 40);
    }

    /** @return Collection<int, object> */
    private function zarazene(GallerySpace $prostor): Collection
    {
        return DB::table('transactions as t')
            ->join('finance_categories as k', 'k.id', '=', 't.category_id')
            ->where('t.gallery_space_id', $prostor->id)
            ->whereNull('t.deleted_at')
            ->whereNull('k.deleted_at')
            ->orderByDesc('t.occurred_at')
            ->orderByDesc('t.id')
            ->limit(self::OKNO)
            ->get(['t.description', 't.category_id', 't.occurred_at', 'k.name as kategorie']);
    }
}
