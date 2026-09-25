<?php

namespace App\Services\Obsah;

use App\Models\CycleDay;
use App\Models\CycleSetting;
use App\Models\GallerySpace;
use App\Support\Cas;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
class Denik implements MaPrazdneKolekce, PoskytovatelObsahu
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

    /**
     * Prázdné kolekce pro modul, který dvojice zatím nepoužila.
     *
     * Bez nich zůstala na obrazovce ukázka z prototypu (viz MaPrazdneKolekce).
     *
     * @return array<string, mixed>
     */
    public function prazdne(): array
    {
        return [
            'ADIARY' => ['diary' => [], 'ms' => [], 'dates' => [], 'cycleLog' => [], 'plan' => []],
            'AL' => ['voice' => []],
        ];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $adiary = array_filter([
            'diary' => $this->zapisy($prostor),
            'ms' => $this->milniky($prostor),
            'cycleLog' => $this->cyklus($prostor),
        ], fn ($v) => $v !== []);

        $hlasovky = $this->hlasovky($prostor);

        return array_filter([
            'ADIARY' => $adiary,
            'AL' => $hlasovky ? ['voice' => $hlasovky] : [],
        ], fn ($v) => $v !== []);
    }

    /**
     * Nahrané hlasovky: `[název, kdy a jak dlouho, štítek]`.
     *
     * Záložka „Hlasovky" v deníku brala řádky z `galerie-data.js` — čtyři
     * nahrávky cizí dvojice včetně délek. Tabulka `voice_notes` přitom
     * v aplikaci je a plní ji nahrávání z prototypu i z chatu.
     *
     * Štítek „přepsáno" dostane jen nahrávka, která přepis opravdu má.
     * Aplikace řeč na text nepřevádí, takže je to informace, ne slib.
     *
     * @return list<array<int, ?string>>
     */
    private function hlasovky(GallerySpace $prostor): array
    {
        if (! Tabulky::je('voice_notes')) {
            return [];
        }

        return DB::table('voice_notes')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['uuid', 'title', 'duration_ms', 'transcript', 'recorded_at', 'created_at'])
            ->map(function (object $h) {
                $kdy = CarbonImmutable::parse($h->recorded_at ?? $h->created_at);
                $prepis = trim((string) ($h->transcript ?? ''));

                return [
                    (string) ($h->title ?: 'Hlasovka'),
                    trim(implode(' · ', array_filter([
                        $kdy->day.'. '.$kdy->month.'.',
                        $h->duration_ms ? $this->delka((int) $h->duration_ms) : null,
                    ]))),
                    $prepis !== '' ? 'přepsáno' : null,
                    null, null, null, null,
                    // Identifikátor nahrávky: přehrání a smazání míří na ni, ne na pořadí.
                    (string) $h->uuid,
                    $prepis !== '' ? mb_substr($prepis, 0, 1000) : null,
                ];
            })
            ->values()
            ->all();
    }

    /** Délka nahrávky jako `m:ss`. */
    private function delka(int $ms): string
    {
        $vteriny = (int) round($ms / 1000);

        return intdiv($vteriny, 60).':'.str_pad((string) ($vteriny % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Zápisy z deníku: `[datum, nadpis, text, doplněk, vlastnosti]`.
     *
     * Vlastnosti nesou identifikátor, soukromí, autora (`A` je ten, kdo se
     * dívá) a náladu — bez nich by se zápis z obrazovky nedal upravit ani
     * smazat a štítek „jen …" by neměl čí jméno napsat.
     *
     * Smazaný zápis (`deleted_at`) se neposílá: `DB::table` o měkkém mazání
     * modelu neví a smazaný zápis by se po obnovení vrátil.
     *
     * @return list<array<int, mixed>>
     */
    private function zapisy(GallerySpace $prostor): array
    {
        if (! Tabulky::je('journal_entries')) {
            return [];
        }

        $ja = auth()->id();

        return DB::table('journal_entries')
            ->where('gallery_space_id', $prostor->id)
            ->when(Tabulky::sloupec('journal_entries', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            // Cizí soukromý zápis není nic, co by měla obrazovka ukazovat.
            ->where(fn ($q) => $q->where('created_by', $ja)->orWhere('visibility', '!=', 'private'))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (object $z) => [
                $this->denCesky(CarbonImmutable::parse($z->entry_date)),
                (string) ($z->title ?: 'Zápis'),
                (string) $z->body,
                $z->mood ? 'nálada '.$z->mood : '',
                [
                    'uuid' => (string) $z->uuid,
                    'priv' => $z->visibility === 'private',
                    'who' => (int) $z->created_by === (int) $ja ? 'A' : 'M',
                    'mine' => (int) $z->created_by === (int) $ja,
                    'mood' => $z->mood ?: null,
                    'iso' => substr((string) $z->entry_date, 0, 10),
                ],
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
        if (! Tabulky::je('relationship_milestones')) {
            return [];
        }

        // Dnešek dvojice: v UTC je po pražské půlnoci ještě včerejšek a výročí
        // by den po něm tvrdilo „letos" místo „před rokem".
        $dnes = Cas::dnes();

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
                    // Datum strojově — obrazovka Milníky z něj počítá nejbližší výročí.
                    $kdy->toDateString(),
                    (bool) ($m->remind_annually ?? true),
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
        if (! Tabulky::je('cycle_days')) {
            return [];
        }

        $ja = auth()->id();

        $urovne = Tabulky::je('cycle_settings')
            ? CycleSetting::where('gallery_space_id', $prostor->id)->pluck('share_level', 'user_id')
            : collect();

        return CycleDay::where('gallery_space_id', $prostor->id)
            ->where(fn ($q) => $q->where('is_cycle_start', true)->orWhereNotNull('note'))
            ->orderByDesc('day')
            ->limit(30)
            ->get()
            ->filter(function (CycleDay $d) use ($ja, $urovne) {
                if ($d->user_id === $ja) {
                    return true;
                }

                $uroven = $urovne[$d->user_id] ?? CycleSetting::SHARE_NONE;

                // „Jen termíny" jsou začátky cyklu. Den jen s poznámkou by se
                // vypsal jako „Záznam" bez textu — a i tak by prozradil, že
                // ten den něco bylo.
                return $uroven === CycleSetting::SHARE_FULL
                    || ($uroven === CycleSetting::SHARE_DATES && $d->is_cycle_start);
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
