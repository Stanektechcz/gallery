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

    /*
     * Bdělé okno dne: od sedmi do jedenácti večer.
     *
     * Volný čas na obrazovce znamená **hodiny, na které v kalendáři nic
     * není** — ne hodiny, kdy má člověk sílu. Aplikace neví o dojíždění,
     * vaření ani únavě, takže o nich nic netvrdí; od toho je vedle mapa
     * energie a od toho jde každé číslo přepsat.
     */
    private const OD = 7;

    private const DO = 23;

    private const OKNO = 16.0;

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

        /*
         * Kapacita týdne ve sloupcích.
         *
         * Široké rozvržení pro ni má vlastní obrazovku, úzké kreslí sloupce
         * z `ABARS`. Klíč `cap` v ukázce vůbec není, takže na telefonu byla
         * ta záložka prázdná — přestože čísla dvojice má.
         */
        if ($sloupce = $this->sloupceTydne($kolekce['HOUSE_WEEK'] ?? [], $jmena, $prostor)) {
            $kolekce['ABARS'] = ['cap' => $sloupce];
        }

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
        $dvojice = $this->dvojice($prostor);

        if ($dvojice[0] === null || $dvojice[1] === null) {
            return [];
        }

        $pondeli = CarbonImmutable::now()->startOfWeek();
        $obsazeno = $this->obsazenost($prostor, $pondeli);

        $dny = Schema::hasTable('house_week')
            ? DB::table('house_week')->where('gallery_space_id', $prostor->id)->get()->keyBy('weekday')
            : collect();

        $opravy = $dny->isNotEmpty() && Schema::hasTable('house_week_capacity')
            ? DB::table('house_week_capacity')->whereIn('house_week_id', $dny->pluck('id'))->get()->groupBy('house_week_id')
            : collect();

        // Prázdný kalendář a žádná oprava znamená, že aplikace o týdnu nic
        // neví. Nakreslit sedm dní plných volna by bylo tvrzení, ne údaj.
        if ($obsazeno === [] && $dny->isEmpty()) {
            return [];
        }

        $radky = [];

        foreach (self::DNY as $poradi => $klic) {
            $den = $dny[$klic] ?? null;
            $kapacita = $den ? ($opravy[$den->id] ?? collect())->keyBy('user_id') : collect();
            $obsazenoDen = $obsazeno[$poradi] ?? [];

            $zKalendare = [
                $this->volno($obsazenoDen[$dvojice[0]] ?? 0.0),
                $this->volno($obsazenoDen[$dvojice[1]] ?? 0.0),
            ];

            $radky[] = [
                'key' => $klic,
                'name' => self::DNY_CESKY[$klic],
                'date' => $pondeli->addDays($poradi)->format('j. n.'),
                'a' => isset($kapacita[$dvojice[0]]) ? (float) $kapacita[$dvojice[0]]->free_hours : $zKalendare[0],
                'm' => isset($kapacita[$dvojice[1]]) ? (float) $kapacita[$dvojice[1]]->free_hours : $zKalendare[1],
                'note' => (string) ($den->note ?? ''),
                /*
                 * Co říká kalendář, i když to dvojice přepsala.
                 *
                 * Bez toho by se k původnímu číslu nedalo vrátit: obrazovka by
                 * po přepsání znala jen tu opravu a „podle kalendáře" by nemělo
                 * co dosadit.
                 */
                'autoA' => $zKalendare[0],
                'autoM' => $zKalendare[1],
                'fixA' => isset($kapacita[$dvojice[0]]),
                'fixM' => isset($kapacita[$dvojice[1]]),
            ];
        }

        return $radky;
    }

    /**
     * Kolik hodin z bdělého okna má ten den kdo obsazených.
     *
     * `[pořadí dne v týdnu][id člověka] => hodiny`. Počítá se z kalendáře:
     * událost, u které je člověk účastníkem, zabírá ten čas jemu. Celodenní
     * událost — třeba cesta — zabere okno celé.
     *
     * @return array<int, array<int, float>>
     */
    private function obsazenost(GallerySpace $prostor, CarbonImmutable $pondeli): array
    {
        if (! Schema::hasTable('calendar_events') || ! Schema::hasTable('event_participants')) {
            return [];
        }

        $konec = $pondeli->addDays(7);

        $udalosti = DB::table('calendar_events as u')
            ->join('event_participants as ucast', 'ucast.event_id', '=', 'u.id')
            ->where('u.gallery_space_id', $prostor->id)
            ->where('u.starts_at', '<', $konec)
            ->where(fn ($q) => $q->where('u.ends_at', '>', $pondeli)->orWhereNull('u.ends_at'))
            ->whereNotIn('u.status', ['cancelled', 'declined'])
            ->get(['u.starts_at', 'u.ends_at', 'u.all_day', 'ucast.user_id']);

        $obsazeno = [];

        foreach ($udalosti as $u) {
            $od = CarbonImmutable::parse($u->starts_at);
            $do = $u->ends_at ? CarbonImmutable::parse($u->ends_at) : $od->addHour();

            for ($poradi = 0; $poradi < 7; $poradi++) {
                $den = $pondeli->addDays($poradi);
                $hodin = $u->all_day
                    ? ($od->lt($den->addDay()) && $do->gt($den) ? self::OKNO : 0.0)
                    : $this->prekryv($od, $do, $den->setTime(self::OD, 0), $den->setTime(self::DO, 0));

                if ($hodin > 0) {
                    $obsazeno[$poradi][(int) $u->user_id] = ($obsazeno[$poradi][(int) $u->user_id] ?? 0.0) + $hodin;
                }
            }
        }

        return $obsazeno;
    }

    /** Průnik dvou intervalů v hodinách. */
    private function prekryv(CarbonImmutable $od, CarbonImmutable $do, CarbonImmutable $oknoOd, CarbonImmutable $oknoDo): float
    {
        $zacatek = $od->gt($oknoOd) ? $od : $oknoOd;
        $konec = $do->lt($oknoDo) ? $do : $oknoDo;

        return $konec->gt($zacatek) ? round($zacatek->diffInMinutes($konec) / 60, 2) : 0.0;
    }

    /** Z obsazených hodin volné. Na půlhodiny, jak je obrazovka kreslí. */
    private function volno(float $obsazeno): float
    {
        return round(max(0.0, self::OKNO - $obsazeno) * 2) / 2;
    }

    /**
     * Týden ve sloupcích: `[den, „Adrian 2,5 h · Makinka 1,5 h", %, barva]`.
     *
     * Sto procent má nejvolnější den v týdnu — porovnává se tedy s vlastním
     * týdnem, ne s vymyšleným ideálem. Den, kde ani jeden nemá hodinu volna,
     * je varovný: prototyp na něj u úkolů dává upozornění.
     *
     * @param  list<array<string, mixed>>  $tyden
     * @param  array<int, string>  $jmena
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function sloupceTydne(array $tyden, array $jmena, GallerySpace $prostor): array
    {
        if (! $tyden) {
            return [];
        }

        $dvojice = $this->dvojice($prostor);
        $prvni = $jmena[$dvojice[0]] ?? 'Adrian';
        $druhy = $jmena[$dvojice[1]] ?? 'Makinka';

        $nejvic = max(array_map(fn (array $d) => (float) $d['a'] + (float) $d['m'], $tyden)) ?: 1.0;

        return array_map(function (array $d) use ($prvni, $druhy, $nejvic) {
            $spolu = (float) $d['a'] + (float) $d['m'];
            $tesno = (float) $d['a'] < 1 && (float) $d['m'] < 1;

            return [
                $d['name'].' '.$d['date'],
                $prvni.' '.$this->hodiny((float) $d['a']).' · '.$druhy.' '.$this->hodiny((float) $d['m']),
                (int) round($spolu / $nejvic * 100),
                $tesno ? 1 : ($spolu >= $nejvic ? 0 : 2),
            ];
        }, $tyden);
    }

    private function hodiny(float $hodin): string
    {
        return str_replace('.', ',', (string) round($hodin, 1)).' h';
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
