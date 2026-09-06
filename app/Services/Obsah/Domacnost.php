<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\HouseChore;
use App\Models\HouseChoreLogEntry;
use App\Models\HouseDue;
use App\Models\HouseInventoryItem;
use App\Models\HousePantryItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domácnost ve tvaru, ve kterém ji kreslí prototyp.
 *
 * Dělba práce, kapacita týdne, lhůty, byt a spíž se do téhle chvíle celé
 * odehrávaly v jednom JSON dokumentu stavu páru. Fungovalo to do chvíle, kdy
 * se někdo zeptal odjinud: připomínka o STK, automatizace po úklidu ani výroční
 * přehled se na dělbu práce neměly kde zeptat.
 *
 * Popisky se počítají tady, ne v databázi: „před 11 dny" a „za 5 dní" jsou
 * pravdivé jen v ten den, kdy se čtou.
 */
class Domacnost implements PoskytovatelObsahu
{
    private const DNY = ['po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];

    private const DNY_CESKY = [
        'po' => 'Pondělí', 'út' => 'Úterý', 'st' => 'Středa', 'čt' => 'Čtvrtek',
        'pá' => 'Pátek', 'so' => 'Sobota', 'ne' => 'Neděle',
    ];

    /** Pádová podoba pro „naposledy v pondělí". */
    private const V_DEN = [
        'po' => 'v pondělí', 'út' => 'v úterý', 'st' => 've středu', 'čt' => 've čtvrtek',
        'pá' => 'v pátek', 'so' => 'v sobotu', 'ne' => 'v neděli',
    ];

    public function skupina(): string
    {
        return 'domacnost';
    }

    /**
     * Nic. Všechny kolekce téhle skupiny jsou seznamy, a ty se vyměňují celé
     * i bez ohlášení; objekt, u kterého by na tom záleželo, tu není.
     */
    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('house_chores')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        $kolekce = array_filter([
            'HOUSE_CHORES' => $this->prace($prostor, $jmena),
            'HOUSE_LOG' => $this->zaznamy($prostor, $jmena),
            'HOUSE_WEEK' => $this->tyden($prostor),
            'HOUSE_DUES' => $this->zavazky($prostor, $jmena),
            'HOUSE_INV' => $this->byt($prostor),
            'PANTRY' => $this->spiz($prostor),
        ], fn ($v) => $v !== null && $v !== []);

        if (! $kolekce) {
            return [];
        }

        /*
         * Telefon má domácnost ve vlastních kolekcích, ne v `GalerieData`.
         *
         * Tvar je až na spíž stejný, takže se posílají tytéž řádky; pole navíc
         * (`delayNote`, `sub`) prototypu nevadí, jen je nekreslí.
         */
        $kolekce['MOBIL'] = array_filter([
            'HOUSE_CHORES' => $kolekce['HOUSE_CHORES'] ?? [],
            'HOUSE_LOG' => $kolekce['HOUSE_LOG'] ?? [],
            'HOUSE_WEEK' => $kolekce['HOUSE_WEEK'] ?? [],
            'HOUSE_DUES' => $kolekce['HOUSE_DUES'] ?? [],
            'HOUSE_INV' => $kolekce['HOUSE_INV'] ?? [],
            // Ve spíži telefonu je poslední pole jedno slovo, ne seznam.
            'MPANTRY' => array_map(
                fn (array $p) => [...array_slice($p, 0, 6), $p[6][0] ?? $p[1]],
                $kolekce['PANTRY'] ?? [],
            ),
        ], fn ($v) => $v !== []);

        return $kolekce;
    }

    /**
     * Dělba práce: `{ id, name, every, who, rotate, mins, last, day, icon, overdue }`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function prace(GallerySpace $prostor, array $jmena): array
    {
        return HouseChore::where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (HouseChore $p) use ($jmena) {
                $opakovani = $p->dniOpakovani();
                $odkdy = $p->last_done_at
                    ? (int) CarbonImmutable::parse($p->last_done_at)->startOfDay()
                        ->diffInDays(CarbonImmutable::now()->startOfDay())
                    : null;

                return array_filter([
                    'id' => $p->uuid,
                    'name' => $p->name,
                    'every' => $p->every,
                    'who' => $jmena[$p->assigned_to] ?? 'spolu',
                    'rotate' => (bool) $p->rotate,
                    'mins' => (int) $p->minutes,
                    'last' => $this->naposledy($odkdy, $p->last_done_at),
                    // Po termínu je to, co se nestihlo v ani jednom celém období navíc.
                    'overdue' => $opakovani !== null && $odkdy !== null && $odkdy > $opakovani,
                    'day' => $p->day,
                    'icon' => $p->icon,
                ], fn ($v) => $v !== null);
            })
            ->values()
            ->all();
    }

    private function naposledy(?int $dni, $kdy): string
    {
        if ($dni === null) {
            return 'zatím nikdy';
        }

        return match (true) {
            $dni === 0 => 'dnes',
            $dni === 1 => 'včera',
            $dni < 7 => self::V_DEN[self::DNY[CarbonImmutable::parse($kdy)->dayOfWeekIso - 1]],
            default => 'před '.$dni.' dny',
        };
    }

    /**
     * Kdo kolik odvedl: `{ id, chore, who, mins, when }`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function zaznamy(GallerySpace $prostor, array $jmena): array
    {
        return HouseChoreLogEntry::where('gallery_space_id', $prostor->id)
            ->where('done_at', '>=', CarbonImmutable::now()->subDays(30))
            ->orderByDesc('done_at')
            ->limit(60)
            ->get()
            ->map(function (HouseChoreLogEntry $z) use ($jmena) {
                $kdy = CarbonImmutable::parse($z->done_at);
                $dni = (int) $kdy->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());

                return [
                    'id' => $z->uuid,
                    'chore' => $z->chore_name,
                    'who' => $jmena[$z->user_id] ?? 'spolu',
                    'mins' => (int) $z->minutes,
                    'when' => match (true) {
                        $dni === 0 => 'dnes '.$kdy->format('G:i'),
                        $dni === 1 => 'včera '.$kdy->format('G:i'),
                        $dni < 7 => mb_strtolower(self::DNY_CESKY[self::DNY[$kdy->dayOfWeekIso - 1]]),
                        default => $kdy->format('j. n.'),
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Kapacita týdne: `{ key, name, date, a, m, note }`.
     *
     * `a` a `m` jsou dvě místa, ne dvě jména — první patří tomu, kdo prostor
     * založil, druhé druhému z páru. Prototyp s dvojicí počítá napevno.
     *
     * @return list<array<string, mixed>>
     */
    private function tyden(GallerySpace $prostor): array
    {
        $dny = DB::table('house_week')
            ->where('gallery_space_id', $prostor->id)
            ->get()
            ->keyBy('weekday');

        if ($dny->isEmpty()) {
            return [];
        }

        $volno = DB::table('house_week_capacity')
            ->whereIn('house_week_id', $dny->pluck('id'))
            ->get()
            ->groupBy('house_week_id');

        $dvojice = $this->dvojice($prostor);
        $pondeli = CarbonImmutable::now()->startOfWeek();

        $radky = [];

        foreach (self::DNY as $poradi => $klic) {
            $den = $dny[$klic] ?? null;

            if (! $den) {
                continue;
            }

            $kapacita = ($volno[$den->id] ?? collect())->keyBy('user_id');

            $radky[] = [
                'key' => $klic,
                'name' => self::DNY_CESKY[$klic],
                'date' => $pondeli->addDays($poradi)->format('j. n.'),
                'a' => (float) ($kapacita[$dvojice[0]]->free_hours ?? 0),
                'm' => (float) ($kapacita[$dvojice[1]]->free_hours ?? 0),
                'note' => (string) ($den->note ?? ''),
            ];
        }

        return $radky;
    }

    /**
     * Lhůty a závazky: `{ id, what, kind, date, days, amount, who, note, delayNote, delayCost, change }`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function zavazky(GallerySpace $prostor, array $jmena): array
    {
        $dnes = CarbonImmutable::now()->startOfDay();

        return HouseDue::where('gallery_space_id', $prostor->id)
            ->whereNull('settled_at')
            ->orderBy('due_on')
            ->get()
            ->map(fn (HouseDue $z) => array_filter([
                'id' => $z->uuid,
                'what' => $z->what,
                'kind' => $z->kind,
                'date' => CarbonImmutable::parse($z->due_on)->format('j. n. Y'),
                // Kolik dní zbývá. Záporné číslo prototyp kreslí jako po termínu.
                'days' => (int) $dnes->diffInDays(CarbonImmutable::parse($z->due_on)->startOfDay(), false),
                'amount' => (int) $z->amount,
                'who' => $jmena[$z->user_id] ?? 'spolu',
                'note' => (string) ($z->note ?? ''),
                'delayNote' => $z->delay_note,
                'delayCost' => $z->delay_cost !== null ? (int) $z->delay_cost : null,
                'change' => $z->change_note,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }

    /**
     * Byt: `{ id, name, sub, room, bought, warrantyTo, warrantyDays, doc, service, … }`.
     *
     * @return list<array<string, mixed>>
     */
    private function byt(GallerySpace $prostor): array
    {
        $dnes = CarbonImmutable::now()->startOfDay();

        return HouseInventoryItem::where('gallery_space_id', $prostor->id)
            ->orderBy('room')
            ->orderBy('name')
            ->get()
            ->map(function (HouseInventoryItem $v) use ($dnes) {
                $zaruka = $v->warranty_to ? CarbonImmutable::parse($v->warranty_to) : null;
                $servis = $v->service_next_on ? CarbonImmutable::parse($v->service_next_on) : null;

                return array_filter([
                    'id' => $v->uuid,
                    'name' => $v->name,
                    'sub' => (string) ($v->subtitle ?? ''),
                    'room' => (string) ($v->room ?? ''),
                    'bought' => $v->bought_on ? CarbonImmutable::parse($v->bought_on)->format('j. n. Y') : '',
                    // Záruka se píše měsícem a rokem — den u ní nikoho nezajímá.
                    'warrantyTo' => $zaruka?->format('n/Y') ?? '',
                    'warrantyDays' => $zaruka ? (int) $dnes->diffInDays($zaruka, false) : null,
                    'doc' => (bool) $v->has_doc,
                    'service' => (bool) $v->needs_service,
                    'serviceNext' => $servis?->format('j. n. Y'),
                    'serviceDays' => $servis ? (int) $dnes->diffInDays($servis, false) : null,
                    'servicePrice' => $v->service_price !== null ? (int) $v->service_price : null,
                    'price' => (int) ($v->price ?? 0),
                    'life' => (int) ($v->life_years ?? 0),
                    'energy' => (int) ($v->energy_per_year ?? 0),
                    'upkeep' => (int) ($v->upkeep_per_year ?? 0),
                ], fn ($v) => $v !== null);
            })
            ->values()
            ->all();
    }

    /**
     * Spíž: `[id, název, kde, kolik, jednotka, dní do zkažení, slova]`.
     *
     * Pole, ne objekt — prototyp ji takhle čte (`p[0]`, `p[1]`, …).
     *
     * @return list<array<int, mixed>>
     */
    private function spiz(GallerySpace $prostor): array
    {
        $dnes = CarbonImmutable::now()->startOfDay();

        return HousePantryItem::where('gallery_space_id', $prostor->id)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (HousePantryItem $p) => [
                $p->uuid,
                $p->name,
                $p->category,
                // Celé číslo, když je celé: „1.0 palice" nikdo neříká.
                (float) $p->quantity == (int) $p->quantity ? (int) $p->quantity : (float) $p->quantity,
                $p->unit,
                $p->expires_on ? (int) $dnes->diffInDays(CarbonImmutable::parse($p->expires_on)->startOfDay(), false) : null,
                $p->keywords ?: [$p->name],
            ])
            ->values()
            ->all();
    }

    /**
     * Dvojice v pořadí, ve kterém ji prototyp kreslí: zakladatel prostoru první.
     *
     * @return array<int, int|null>
     */
    private function dvojice(GallerySpace $prostor): array
    {
        $lide = $prostor->members()->orderByRaw('users.id = ? desc', [$prostor->owner_id])
            ->pluck('users.id')
            ->all();

        return [$lide[0] ?? null, $lide[1] ?? null];
    }
}
