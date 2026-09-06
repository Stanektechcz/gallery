<?php

namespace App\Services\Obsah;

use App\Models\CycleDay;
use App\Models\CycleSetting;
use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zápisy z deníku, milníky a záznamy cyklu — pro obrazovku „co o nás víme".
 *
 * `ADIARY` je pět seznamů pod sebou; posílají se jen ty, které se dají naplnit
 * pravdou. Klíč, který server nepošle, zůstává ukázkový — objekt se přepisuje
 * po klíčích, ne celý.
 *
 * Deník je **soukromý zápis**, dokud ho někdo nesdílí (`visibility`), a záznamy
 * cyklu se řídí úrovní sdílení stejně jako v kalendáři cyklu. Obrazovka, která
 * ukáže všechno všem, je horší než obrazovka, která neukáže nic.
 */
class Denik implements PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    public function skupina(): string
    {
        return 'denik';
    }

    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $adiary = array_filter([
            'diary' => $this->zapisy($prostor),
            'ms' => $this->milniky($prostor),
            'cycleLog' => $this->cyklus($prostor),
        ], fn ($v) => $v !== []);

        return $adiary ? ['ADIARY' => $adiary] : [];
    }

    /**
     * Zápisy z deníku: `[datum, nadpis, text, doplněk]`.
     *
     * @return list<array<int, string>>
     */
    private function zapisy(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('journal_entries')) {
            return [];
        }

        $ja = auth()->id();

        return DB::table('journal_entries')
            ->where('gallery_space_id', $prostor->id)
            // Cizí soukromý zápis není nic, co by měla obrazovka ukazovat.
            ->where(fn ($q) => $q->where('created_by', $ja)->orWhere('visibility', '!=', 'private'))
            ->orderByDesc('entry_date')
            ->limit(40)
            ->get()
            ->map(fn (object $z) => [
                $this->denCesky(CarbonImmutable::parse($z->entry_date)),
                (string) ($z->title ?: 'Zápis'),
                (string) $z->body,
                trim(implode(' · ', array_filter([
                    $z->mood ? 'nálada '.$z->mood : null,
                    $z->visibility === 'private' ? 'jen moje' : null,
                ]))),
            ])
            ->values()
            ->all();
    }

    /**
     * Milníky: `[datum, název, text, jak dávno]`.
     *
     * @return list<array<int, string>>
     */
    private function milniky(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('relationship_milestones')) {
            return [];
        }

        $dnes = CarbonImmutable::now();

        $ja = auth()->id();

        return DB::table('relationship_milestones')
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('occurred_on')
            // Soukromý milník patří tomu, kdo si ho zapsal.
            ->where(fn ($q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $ja))
            ->orderByDesc('occurred_on')
            ->limit(30)
            ->get()
            ->map(function (object $m) use ($dnes) {
                $kdy = CarbonImmutable::parse($m->occurred_on);
                $let = (int) $kdy->diffInYears($dnes);

                return [
                    $this->denCesky($kdy),
                    $m->title,
                    (string) ($m->description ?? ''),
                    $let >= 1
                        ? 'před '.$this->pocet($let, 'rokem', 'lety', 'lety')
                        : 'letos',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Záznamy cyklu: `[datum, co, poznámka, odkud]`.
     *
     * Drží se **stejná úroveň sdílení** jako v kalendáři cyklu: bez svolení se
     * cizí záznam neposílá vůbec, u „jen termínů" jde ven začátek cyklu bez
     * příznaků.
     *
     * @return list<array<int, string>>
     */
    private function cyklus(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('cycle_days')) {
            return [];
        }

        $ja = auth()->id();

        $urovne = Schema::hasTable('cycle_settings')
            ? CycleSetting::where('gallery_space_id', $prostor->id)->pluck('share_level', 'user_id')
            : collect();

        return CycleDay::where('gallery_space_id', $prostor->id)
            ->where(fn ($q) => $q->where('is_cycle_start', true)->orWhereNotNull('note'))
            ->orderByDesc('day')
            ->limit(30)
            ->get()
            ->filter(function (CycleDay $d) use ($ja, $urovne) {
                return $d->user_id === $ja
                    || ($urovne[$d->user_id] ?? CycleSetting::SHARE_NONE) !== CycleSetting::SHARE_NONE;
            })
            ->map(function (CycleDay $d) use ($ja, $urovne) {
                $cele = $d->user_id === $ja
                    || ($urovne[$d->user_id] ?? CycleSetting::SHARE_NONE) === CycleSetting::SHARE_FULL;

                return [
                    $this->denCesky(CarbonImmutable::parse($d->day)),
                    $d->is_cycle_start ? 'Začátek cyklu' : 'Záznam',
                    $cele ? (string) ($d->note ?? '') : '',
                    'záznam',
                ];
            })
            ->values()
            ->all();
    }

    // ——— formát ———

    private function denCesky(CarbonImmutable $den): string
    {
        return $den->day.'. '.self::MESICE[$den->month].' '.$den->year;
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $kolik.' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }
}
