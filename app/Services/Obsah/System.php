<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Finance\LedgerService;
use App\Services\Provoz\UlozisteGalerie;
use App\Services\Storage\DriveConnectionResolver;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Co aplikace ví o sobě: jak tvrdá jsou její čísla, kdo které sekce živí
 * a jak je na tom úložiště.
 *
 * Tady je ukázka nejhorší ze všech obrazovek. „Zdraví dat" je jediné místo,
 * kde aplikace přiznává, čemu se dá věřit — a psalo se v něm o zůstatku
 * 38 412 Kč a portfoliu za 961 700 Kč, které dvojice nemá. Obrazovka, která
 * má měřit důvěryhodnost čísel, byla nejméně důvěryhodná z nich.
 *
 * Posílá se jen to, co má oporu: řádek o cyklu, když existují záznamy, řádek
 * o rozpočtu, když existuje rozpočet. Zbytek se **neposílá**, ne dopočítává.
 */
class System implements PoskytovatelObsahu
{
    /** Do kolika dnů bez zápisu se sekce považuje za živou. */
    private const ZIVA_DNI = 90;

    /**
     * Sekce a tabulka, do které se v nich zapisuje.
     *
     * `[id, název, tabulka, sloupec autora, sloupec času]`
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const SEKCE = [
        ['l1', 'Deník', 'journal_entries', 'created_by', 'created_at'],
        ['l2', 'Finance a transakce', 'transactions', 'created_by', 'created_at'],
        ['l3', 'Kuchařka', 'recipes', 'created_by', 'created_at'],
        ['l4', 'Knihovna', 'media_items', 'uploaded_by', 'uploaded_at'],
        ['l5', 'Zprávy a hlasovky', 'chat_messages', 'created_by', 'created_at'],
        ['l6', 'Plánování a úkoly', 'shared_todos', 'created_by', 'created_at'],
        ['l7', 'Cesty a výlety', 'trips', 'created_by', 'created_at'],
        ['l8', 'Milníky a výročí', 'relationship_milestones', 'created_by', 'created_at'],
    ];

    public function __construct(
        private readonly UlozisteGalerie $uloziste,
        private readonly LedgerService $kniha,
        private readonly Formulare $formulare,
        private readonly DriveConnectionResolver $disky,
    ) {}

    public function skupina(): string
    {
        return 'system';
    }

    /**
     * Obojí přichází celé.
     *
     * U zdraví dat je to podstata věci: nechat mezi skutečnými čísly jedno
     * ukázkové znamená, že obrazovka o důvěryhodnosti čísel sama lže. A sekce
     * se nabízejí ke skrytí — nabídnout skrytí sekce, kterou aplikace nemá,
     * je nesmysl.
     */
    public function uplne(): array
    {
        return ['DATA_HEALTH', 'SECLIFE', 'TRASH'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        return array_filter([
            'DATA_HEALTH' => $this->zdraviDat($prostor),
            'SECLIFE' => $this->zivotSekci($prostor),
            'ABARS' => $this->sloupce($prostor),
            'AFORMS' => $this->prepinace($prostor),
            'CONFLICTS' => $this->rozpory($prostor),
            'DISK' => $this->diskAStav($prostor),
            'TRASH' => $this->kos($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Koš: `[{ id, name, from, by, when, left, n }]`.
     *
     * Obrazovka koše měla čtyři vymyšlené řádky a tlačítko „Vyprázdnit koš"
     * hlásilo „Trvale se odstraní 4 položky včetně jednoho albumu" — bez
     * ohledu na to, co v koši je. A pak nesmazalo nic.
     *
     * `left` je, kolik dní zbývá do trvalého odstranění. Počítá se z `purge_after`,
     * a když ho položka nemá, z třiceti dnů od vyhození — to je lhůta, kterou
     * slibuje text nad seznamem.
     *
     * @return list<array<string, mixed>>
     */
    private function kos(GallerySpace $prostor): array
    {
        $polozky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('trashed_at')
            ->orderByDesc('trashed_at')
            ->limit(60)
            ->get(['uuid', 'original_filename', 'trashed_at', 'purge_after', 'uploaded_by', 'media_type']);

        if ($polozky->isEmpty()) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        return $polozky->map(function (MediaItem $m) use ($jmena) {
            $vyhozeno = CarbonImmutable::parse($m->trashed_at);
            $konec = $m->purge_after ? CarbonImmutable::parse($m->purge_after) : $vyhozeno->addDays(30);
            $dnu = max(0, (int) round(now()->diffInDays($konec, false)));

            return [
                'id' => $m->uuid,
                'name' => $m->original_filename,
                // Odkud to bylo se nedopočítává: album po vyhození nemusí
                // existovat a vymyslet cestu by znamenalo tvrdit, kde to leželo.
                'from' => $m->media_type === 'video' ? 'Video' : 'Fotka',
                'by' => $jmena[$m->uploaded_by] ?? '—',
                'when' => $this->kdy($vyhozeno->toDateTimeString()),
                'left' => $this->pocet($dnu, 'den', 'dny', 'dní'),
                'n' => 0,
            ];
        })->values()->all();
    }

    /**
     * Úložiště a synchronizace — celá obrazovka ze skutečnosti.
     *
     * Byla to nejnebezpečnější obrazovka v aplikaci. Stálo v ní „Připojeno —
     * adrian.stanek@gmail.com", „poslední úspěšná synchronizace dnes v 8:12"
     * a „24 316 originálů bezpečně uloženo" — všechno napsané v designovém
     * souboru. Dvojici, která Disk připojený nemá, tvrdila, že jsou její fotky
     * ve dvou kopiích. To není zastaralé číslo, to je nepravda o záloze.
     *
     * @return array<string, mixed>
     */
    private function diskAStav(GallerySpace $prostor): array
    {
        /*
         * Použitelný Disk se hledá stejnou cestou jako všude jinde v aplikaci
         * (`DriveConnectionResolver`): podle **členů prostoru**, ne podle
         * `gallery_space_id`. Účet obvykle patří jednomu z nich a druhý na něj
         * nahrává taky; vazba na prostor v té tabulce je z větší části prázdná.
         *
         * Vedle toho se hledá i účet, který **nefunguje** — rozbité připojení
         * musí obrazovka přiznat, ne ho ukázat jako nepřipojený.
         */
        $disk = $this->disky->forSpace($prostor->id);
        $rozbity = $disk === null ? $this->rozbityDisk($prostor) : null;
        $disk ??= $rozbity;

        $polozky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at');

        $pocet = fn (callable $kde) => (int) $kde((clone $polozky))->count();

        /*
         * Čtyři dlaždice musí **rozdělit celou knihovnu**, ne jen popsat čtyři
         * stavy. Když se počítalo podle `storage_status`, položky se stavem,
         * který do žádné škatulky nepatřil (a v datech takové jsou), se ztratily
         * mezi dlaždicemi: součet neseděl s počtem fotek a nikdo by to nepoznal.
         *
         * Dělí se proto podle toho, co rozhoduje: **má to na Disku své id?**
         * Stav sám o sobě je jen tvrzení. Záznam, který o sobě říká „synced",
         * ale nemá k čemu se vrátit, je přesně ten případ na čtvrté dlaždici.
         */
        $naDisku = ['synced', 'mirrored'];

        $ulozeno = $pocet(fn ($q) => $q->whereNotNull('drive_file_id'));
        $ceka = $pocet(fn ($q) => $q->whereNull('drive_file_id')->where('storage_status', 'uploading'));
        $ztracene = $pocet(fn ($q) => $q->whereNull('drive_file_id')->whereIn('storage_status', $naDisku));
        // Prázdný stav se musí vypsat zvlášť: `NOT IN` v SQL řádek s `NULL`
        // nevrátí, takže by z rozdělení vypadl.
        $jenTady = $pocet(fn ($q) => $q->whereNull('drive_file_id')->where(
            fn ($w) => $w->whereNull('storage_status')
                ->orWhereNotIn('storage_status', array_merge($naDisku, ['uploading'])),
        ));
        $chybne = $pocet(fn ($q) => $q->whereNotNull('processing_error'));

        $bajtu = fn (string $druh) => (int) (clone $polozky)->where('media_type', $druh)->sum('size_bytes');

        $fotky = $bajtu('photo');
        $videa = $bajtu('video');

        $nahledy = Schema::hasTable('media_variants')
            ? (int) DB::table('media_variants')
                ->join('media_items', 'media_items.id', '=', 'media_variants.media_item_id')
                ->where('media_items.gallery_space_id', $prostor->id)
                ->sum('media_variants.size_bytes')
            : 0;

        $celkem = $fotky + $videa + $nahledy;
        $dil = fn (int $b) => $celkem > 0 ? round($b / $celkem * 100, 1).'%' : '0%';

        $pripojeno = $disk !== null && $rozbity === null;
        $posledni = $disk?->last_successful_request_at?->toDateTimeString();

        return [
            'connected' => $pripojeno,
            'account' => $disk->account_email ?? null,

            'intro' => $pripojeno
                ? 'Originály fotek a videí leží na Google účtu '.$disk->account_email.'. Galerie si u sebe drží jen náhledy.'
                : 'Google Disk není připojený. Originály leží jen tady — druhou kopii nemá kdo udělat.',

            'headline' => $pripojeno
                ? 'Připojeno — '.$disk->account_email
                : ($disk === null ? 'Účet není připojený' : 'Připojení nefunguje — '.$disk->account_email),

            // Co se opravdu ví: kdy naposledy Disk odpověděl a kolik originálů
            // má u sebe. Ne „dnes v 8:12".
            'note' => $pripojeno
                ? ($posledni === null
                    ? 'Účet je připojený, ale ještě se nic nepřeneslo.'
                    : 'Poslední úspěšná odpověď Disku '.$this->kdy($posledni).' · '
                        .$this->pocet($ulozeno, 'originál bezpečně uložen', 'originály bezpečně uloženy', 'originálů bezpečně uloženo'))
                : ($disk?->last_error_message ?: 'Bez připojeného účtu se originály nemají kam kopírovat.'),

            'tone' => $pripojeno ? 'ok' : ($disk === null ? 'off' : 'err'),
            'cta' => $disk === null ? 'Připojit Google Disk' : 'Znovu připojit účet',

            'capacity' => [
                ['label' => 'Fotografie '.$this->objem($fotky), 'w' => $dil($fotky), 'color' => 'var(--g-acc)'],
                ['label' => 'Videa '.$this->objem($videa), 'w' => $dil($videa), 'color' => 'var(--g-acc-deep)'],
                ['label' => 'Náhledy a cache '.$this->objem($nahledy), 'w' => $dil($nahledy), 'color' => 'var(--g-warn)'],
            ],

            'states' => [
                ['icon' => 'ph-cloud-check', 'color' => 'var(--g-ok)', 'value' => $this->cislo($ulozeno),
                    'label' => 'Originál bezpečně uložen', 'hint' => 'na Google Disku'],
                ['icon' => 'ph-cloud-arrow-up', 'color' => 'var(--g-acc)', 'value' => $this->cislo($ceka),
                    'label' => 'Čeká na přenos', 'hint' => 'nahraje se, až bude galerie otevřená'],
                ['icon' => 'ph-image', 'color' => 'var(--g-ink2)', 'value' => $this->cislo($jenTady),
                    'label' => 'Jen lokální náhled', 'hint' => $pripojeno ? 'originál se přenese později' : 'druhá kopie chybí'],
                ['icon' => 'ph-warning-circle', 'color' => 'var(--g-mag)', 'value' => $this->cislo($ztracene),
                    'label' => 'Originál nelze najít', 'hint' => 'nejspíš přesunut mimo galerii'],
            ],

            // Varovný pruh se ukáže jen tehdy, když je opravdu co hlásit.
            'failed' => $chybne,
            'failedTitle' => $chybne === 0 ? '' :
                $this->pocet($chybne, 'originál se nepodařilo zpracovat', 'originály se nepodařilo zpracovat', 'originálů se nepodařilo zpracovat'),
        ];
    }

    /**
     * Účet, který je připojený, ale nefunguje.
     *
     * Vypršelý token, odebraný souhlas, smazaná kořenová složka. Pro dvojici
     * je to horší stav než „nepřipojeno": myslí si, že zálohu má.
     */
    private function rozbityDisk(GallerySpace $prostor): ?StorageConnection
    {
        if (! Schema::hasTable('storage_connections')) {
            return null;
        }

        return StorageConnection::query()
            ->where('provider', 'google_drive')
            ->whereNull('revoked_at')
            ->whereIn('owner_user_id', $prostor->members()->pluck('users.id'))
            ->orderByDesc('connected_at')
            ->first();
    }

    /** „dnes v 8:12" nebo datum — bez přesnosti, kterou aplikace nemá. */
    private function kdy(string $cas): string
    {
        $kdy = CarbonImmutable::parse($cas);

        return match (true) {
            $kdy->isToday() => 'dnes v '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera v '.$kdy->format('G:i'),
            default => $kdy->format('j. n. Y').' v '.$kdy->format('G:i'),
        };
    }

    /**
     * Objem lidsky. Desítkové jednotky — tak je počítá i Google Disk.
     *
     * Pod megabajt se ukazují megabajty s desetinou, ne „0,0 GB": nová galerie
     * má pár set kilobajtů náhledů a tři nuly vedle sebe vypadají jako chyba.
     */
    private function objem(int $bajtu): string
    {
        if ($bajtu >= 1_000_000_000) {
            return str_replace('.', ',', (string) round($bajtu / 1_000_000_000, 1)).' GB';
        }

        if ($bajtu >= 1_000_000) {
            return str_replace('.', ',', (string) round($bajtu / 1_000_000, 1)).' MB';
        }

        return max(0, (int) round($bajtu / 1000)).' kB';
    }

    /**
     * Rozpory mezi zařízeními: `[id, co, kde, ikona, moje, kdy, jejich, kdy, sloučeno]`.
     *
     * Dvě verze téhož záznamu, které vznikly, když byl jeden z telefonů
     * offline. Nabídnutá sloučená verze se **nevymýšlí** — je to prázdné
     * pole a rozhodnutí zůstává na dvojici. Domyslet za ně, co si vlastně
     * chtěli poznamenat, by bylo horší než nechat je vybrat.
     *
     * @return list<array<int, mixed>>
     */
    private function rozpory(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('drive_conflicts') || ! Schema::hasTable('storage_connections')) {
            return [];
        }

        return DB::table('drive_conflicts as r')
            ->join('storage_connections as s', 's.id', '=', 'r.storage_connection_id')
            ->where('s.gallery_space_id', $prostor->id)
            ->whereNull('r.resolved_at')
            ->orderByDesc('r.detected_at')
            ->limit(20)
            ->get(['r.id', 'r.entity_type', 'r.entity_id', 'r.conflict_type', 'r.app_state', 'r.drive_state', 'r.detected_at'])
            ->map(function (object $r) {
                $kdy = CarbonImmutable::parse($r->detected_at);

                return [
                    'c'.$r->id,
                    $this->popisRozporu((string) $r->entity_type, (int) $r->entity_id),
                    $this->kdeRozpor((string) $r->entity_type),
                    $this->ikonaRozporu((string) $r->entity_type),
                    $this->stranaRozporu($r->app_state),
                    $this->pred($kdy),
                    $this->stranaRozporu($r->drive_state),
                    $this->pred($kdy),
                    // Sloučenou verzi si dvojice vybere sama.
                    '',
                ];
            })
            ->values()
            ->all();
    }

    private function popisRozporu(string $druh, int $id): string
    {
        $nazev = match ($druh) {
            'media_item' => Schema::hasTable('media_items')
                ? DB::table('media_items')->where('id', $id)->value('original_filename')
                : null,
            'album' => Schema::hasTable('albums')
                ? DB::table('albums')->where('id', $id)->value('title')
                : null,
            default => null,
        };

        return $nazev ? $this->kdeRozpor($druh).' — '.$nazev : $this->kdeRozpor($druh);
    }

    private function kdeRozpor(string $druh): string
    {
        return match ($druh) {
            'media_item' => 'Knihovna',
            'album' => 'Alba',
            'todo' => 'Plánování → Nástěnka',
            default => 'Úložiště',
        };
    }

    private function ikonaRozporu(string $druh): string
    {
        return match ($druh) {
            'media_item' => 'ph-image',
            'album' => 'ph-folders',
            'todo' => 'ph-list-checks',
            default => 'ph-cloud-warning',
        };
    }

    /** Jedna strana rozporu jako věta, ne jako JSON. */
    private function stranaRozporu(mixed $stav): string
    {
        $data = json_decode((string) $stav, true);

        if (! is_array($data) || $data === []) {
            return 'beze změny';
        }

        foreach (['caption', 'title', 'name', 'note'] as $klic) {
            if (! empty($data[$klic])) {
                return (string) $data[$klic];
            }
        }

        return implode(', ', array_map(
            fn ($k, $v) => $k.': '.(is_scalar($v) ? (string) $v : '…'),
            array_keys($data),
            $data,
        ));
    }

    /**
     * Přepínače nastavení: `{ klíč: [[sekce, [[popisek, poznámka, zapnuto]]]] }`.
     *
     * Obrazovka, pro kterou nemá aplikace ani jeden skutečný přepínač, se
     * **neposílá** — prototyp na chybějící klíč sahá přes `AFORMS[key] ||
     * AFORMS.revolut`, takže prázdný by na ni nakreslil cizí formulář.
     *
     * @return array<string, list<array{0: string, 1: list<array{0: string, 1: string, 2: int}>}>>
     */
    private function prepinace(GallerySpace $prostor): array
    {
        $uzivatel = auth()->user();
        $formulare = [];

        foreach ($this->formulare->klice() as $klic) {
            $sekce = $this->formulare->sekce($klic, $prostor, $uzivatel);

            if ($sekce === []) {
                continue;
            }

            $formulare[$klic] = array_map(fn (array $s) => [
                $s['label'],
                array_map(fn (array $r) => [$r['label'], (string) $r['note'], $r['on'] ? 1 : 0], $s['rows']),
            ], $sekce);
        }

        return $formulare;
    }

    // ——— zdraví dat ———

    /**
     * Každý řádek je jedno číslo, které aplikace někde ukazuje, spolu s tím,
     * odkud je a jak moc se na něj dá spolehnout.
     *
     * `conf` se nevymýšlí: tvrdá čísla přicházejí ze zdroje, ruční zápis je
     * tak přesný, jak přesně se zapisoval, a odhad je tím jistější, čím delší
     * řadu záznamů má pod sebou.
     *
     * @return list<array<string, mixed>>
     */
    private function zdraviDat(GallerySpace $prostor): array
    {
        return array_values(array_filter([
            $this->radekZustatek($prostor),
            $this->radekKnihovna($prostor),
            $this->radekOdhadnutaData($prostor),
            $this->radekRozpocet($prostor),
            $this->radekCyklus($prostor),
            $this->radekCesta($prostor),
        ]));
    }

    /** @return array<string, mixed>|null */
    private function radekZustatek(GallerySpace $prostor): ?array
    {
        $penezenky = DB::table('wallets')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['uuid', 'name', 'kind', 'currency', 'opening_balance']);

        if ($penezenky->isEmpty()) {
            return null;
        }

        $zustatky = $this->kniha->walletBalances($prostor)->keyBy('uuid');
        $celkem = $penezenky->sum(fn (object $p) => (float) ($zustatky[$p->uuid]['balance'] ?? $p->opening_balance ?? 0));

        $napojeni = Schema::hasTable('bank_connections')
            ? DB::table('bank_connections')
                ->where('gallery_space_id', $prostor->id)
                ->whereNull('revoked_at')
                ->orderByDesc('last_synced_at')
                ->first(['institution_name', 'last_synced_at'])
            : null;

        $sync = $napojeni?->last_synced_at ? CarbonImmutable::parse($napojeni->last_synced_at) : null;
        $stare = $sync !== null && $sync->lt(now()->subDay());

        return [
            'label' => 'Zůstatek na účtech',
            'value' => $this->castka($celkem, (string) $penezenky->first()->currency),
            'kind' => $sync ? 'hard' : 'manual',
            'where' => $sync
                ? 'Bankovní napojení · '.($napojeni->institution_name ?: 'banka')
                : $this->pocet($penezenky->count(), 'ručně vedený účet', 'ručně vedené účty', 'ručně vedených účtů'),
            'age' => $sync ? $this->pred($sync) : 'podle zapsaných pohybů',
            // Napojený a čerstvý zůstatek je nejtvrdší číslo v aplikaci; ručně
            // vedený je tak přesný, jak přesně se do něj zapisovalo.
            'conf' => $sync ? ($stare ? 88 : 99) : 72,
            'stale' => $stare,
            'note' => $sync
                ? 'Rozdíl proti bance může vzniknout jen u plateb, které ještě nejsou zaúčtované.'
                : 'Účty nejsou napojené na banku — zůstatek platí, pokud se zapsal každý pohyb.',
            'fixLabel' => 'Otevřít účty',
            'route' => 'x-ucty',
            'fixToast' => 'Účty a napojení',
        ];
    }

    /** @return array<string, mixed>|null */
    private function radekKnihovna(GallerySpace $prostor): ?array
    {
        $pocet = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_archived', false)
            ->count();

        if ($pocet === 0) {
            return null;
        }

        return [
            'label' => 'Počet položek v knihovně',
            'value' => $this->cislo($pocet),
            'kind' => 'hard',
            'where' => 'Index knihovny',
            'age' => 'po každém nahrání',
            'conf' => 97,
            'stale' => false,
            'note' => 'Nezahrnuje koš ani karanténu. Fotky bez data se počítají, ale nemají kam patřit.',
            'fixLabel' => 'Úklid knihovny',
            'route' => 'x-uklid',
            'fixToast' => 'Úklid knihovny',
        ];
    }

    /**
     * Fotky, jejichž rok nikdo nezměřil.
     *
     * Obrazovka Datování slibuje, že přijatý odhad „ve Zdraví dat není vidět
     * jako tvrdý údaj" — tohle je to místo, kde se to plní.
     *
     * @return array<string, mixed>|null
     */
    private function radekOdhadnutaData(GallerySpace $prostor): ?array
    {
        if (! Schema::hasColumn('media_items', 'taken_at_estimated')) {
            return null;
        }

        $pocet = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('taken_at_estimated', true)
            ->count();

        if ($pocet === 0) {
            return null;
        }

        return [
            'label' => 'Odhadnutá data fotek',
            'value' => $this->pocet($pocet, 'fotka', 'fotky', 'fotek'),
            'kind' => 'guess',
            'where' => 'Odvozeno z okolních fotek při datování',
            'age' => 'mění se, jen když se datuje',
            // Rok odvozený od sousedů sedí obvykle, den v něm ale nesedí nikdy.
            'conf' => 55,
            'stale' => false,
            'note' => 'Rok bývá správně, den v něm ne — je to první leden. V časové ose proto sedí pořadí, ne přesné datum.',
            'fixLabel' => 'Otevřít datování',
            'route' => 'x-uklid',
            'fixToast' => 'Úklid knihovny → Datování',
        ];
    }

    /** @return array<string, mixed>|null */
    private function radekRozpocet(GallerySpace $prostor): ?array
    {
        $rozpocet = DB::table('budgets')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('starts_on')
            ->first(['id', 'currency']);

        if ($rozpocet === null) {
            return null;
        }

        $plan = (float) DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->sum('amount');

        if ($plan <= 0) {
            return null;
        }

        $dnes = CarbonImmutable::today();

        $utraceno = (float) DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$dnes->startOfMonth(), $dnes->endOfMonth()])
            ->sum('amount_from');

        // Odhad stojí na tom, kolik měsíců má aplikace za sebou. Jeden měsíc
        // dat neumí říct nic o tom, jak měsíc obvykle dopadá.
        $mesicu = $this->mesicuHistorie($prostor);

        return [
            'label' => 'Zbývá v rozpočtu tento měsíc',
            'value' => $this->castka($plan - $utraceno, (string) $rozpocet->currency),
            'kind' => 'guess',
            'where' => 'Dopočítáno z limitů a zapsaných útrat',
            'age' => 'přepočet při každém načtení',
            'conf' => min(84, 45 + $mesicu * 4),
            'stale' => false,
            'note' => $mesicu >= 3
                ? 'Odhad počítá s tím, že zbytek měsíce bude jako předchozí. Velký nákup ho rozhodí.'
                : 'Zatím krátká řada zápisů — čím víc měsíců, tím míň se odhad mýlí.',
            'fixLabel' => 'Otevřít rozpočty',
            'route' => 'x-rozpocty',
            'fixToast' => 'Rozpočty',
        ];
    }

    /** @return array<string, mixed>|null */
    private function radekCyklus(GallerySpace $prostor): ?array
    {
        if (! Schema::hasTable('cycle_days')) {
            return null;
        }

        $zacatky = DB::table('cycle_days')
            ->where('gallery_space_id', $prostor->id)
            ->where('is_cycle_start', true)
            ->where('is_predicted', false)
            ->orderBy('day')
            ->pluck('day');

        // Ze dvou začátků je jedna délka; z jedné délky se průměr nedělá.
        if ($zacatky->count() < 3) {
            return null;
        }

        $delky = [];
        for ($i = 1; $i < $zacatky->count(); $i++) {
            $delky[] = CarbonImmutable::parse($zacatky[$i - 1])->diffInDays(CarbonImmutable::parse($zacatky[$i]));
        }

        $prumer = array_sum($delky) / count($delky);
        $posledni = CarbonImmutable::parse($zacatky->last());

        return [
            'label' => 'Délka cyklu',
            // „28,4 dne", ale u celého čísla „28 dní" — jinak to nikdo nepřečte.
            'value' => round($prumer, 1) == (int) $prumer
                ? $this->pocet((int) $prumer, 'den', 'dny', 'dní')
                : str_replace('.', ',', (string) round($prumer, 1)).' dne',
            'kind' => 'guess',
            'where' => $this->pocet(count($delky), 'zaznamenaný cyklus', 'zaznamenané cykly', 'zaznamenaných cyklů'),
            'age' => 'poslední začátek '.$posledni->format('j. n.'),
            'conf' => min(88, 50 + count($delky) * 5),
            'stale' => $posledni->lt(now()->subDays(60)),
            'note' => 'Předpověď je tím přesnější, čím delší řada záznamů za ní stojí.',
            'fixLabel' => 'Otevřít cyklus',
            'route' => 'x-cyklus',
            'fixToast' => 'Cyklus',
        ];
    }

    /** @return array<string, mixed>|null */
    private function radekCesta(GallerySpace $prostor): ?array
    {
        if (! Schema::hasTable('trip_expenses')) {
            return null;
        }

        $cesta = DB::table('trips')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->orderByDesc('start_date')
            ->first(['id', 'name']);

        if ($cesta === null) {
            return null;
        }

        $utraty = DB::table('trip_expenses')
            ->where('trip_id', $cesta->id)
            ->selectRaw('COUNT(*) AS pocet, SUM(amount) AS soucet, MAX(occurred_at) AS posledni')
            ->first();

        if ((int) $utraty->pocet === 0) {
            return null;
        }

        return [
            'label' => 'Útrata na cestě '.$cesta->name,
            'value' => $this->castka((float) $utraty->soucet, 'CZK'),
            'kind' => 'manual',
            'where' => 'Zapsáno ručně — '.$this->pocet((int) $utraty->pocet, 'položka', 'položky', 'položek'),
            'age' => $utraty->posledni ? 'poslední zápis '.CarbonImmutable::parse($utraty->posledni)->format('j. n.') : 'bez data',
            'conf' => 72,
            'stale' => false,
            'note' => 'Hotovost se na cestě zapisuje z hlavy — pár set stranou je běžné.',
            'fixLabel' => 'Projít útraty',
            'route' => 'x-cesty',
            'fixToast' => 'Cesty a výlety',
        ];
    }

    // ——— kdo sekce živí ———

    /**
     * Kdo do které sekce zapisuje — a která se tři měsíce nežije.
     *
     * `a` je vlastník prostoru, `m` všichni ostatní dohromady. Prototyp kreslí
     * dva pruhy, takže třetí člověk by se do nich nevešel; sečíst je pod jedno
     * jméno je pořád pravda, jen hrubší.
     *
     * @return list<array<string, mixed>>
     */
    private function zivotSekci(GallerySpace $prostor): array
    {
        [$vlastnik, $druhy] = $this->dvojice($prostor);

        if ($vlastnik === null) {
            return [];
        }

        $sekce = [];

        foreach (self::SEKCE as [$id, $nazev, $tabulka, $autor, $cas]) {
            if (! Schema::hasTable($tabulka)) {
                continue;
            }

            $radek = DB::table($tabulka)
                ->where('gallery_space_id', $prostor->id)
                ->selectRaw('SUM(CASE WHEN '.$autor.' = ? THEN 1 ELSE 0 END) AS a', [$vlastnik->id])
                ->selectRaw('SUM(CASE WHEN '.$autor.' IS NOT NULL AND '.$autor.' <> ? THEN 1 ELSE 0 END) AS m', [$vlastnik->id])
                ->selectRaw('MAX('.$cas.') AS posledni')
                ->first();

            $posledni = $radek->posledni ? CarbonImmutable::parse($radek->posledni) : null;

            $sekce[] = [
                'id' => $id,
                'name' => $nazev,
                'a' => (int) $radek->a,
                'm' => (int) $radek->m,
                'aName' => $vlastnik->name,
                'mName' => $druhy?->name ?? 'ostatní',
                'last' => $posledni ? $this->pred($posledni) : null,
                'cold' => $posledni === null || $posledni->lt(now()->subDays(self::ZIVA_DNI)),
            ];
        }

        return $sekce;
    }

    /** @return array{0: ?object, 1: ?object} */
    private function dvojice(GallerySpace $prostor): array
    {
        $lide = DB::table('gallery_space_user as clen')
            ->join('users as u', 'u.id', '=', 'clen.user_id')
            ->where('clen.gallery_space_id', $prostor->id)
            ->orderByRaw('CASE WHEN u.id = ? THEN 0 ELSE 1 END', [$prostor->owner_id])
            ->orderBy('u.id')
            ->get(['u.id', 'u.name']);

        return [$lide->first(), $lide->skip(1)->first()];
    }

    // ——— sloupce administrace ———

    /**
     * Rok v číslech: kolik čeho dvojice letos přibylo — a jak proti loňsku.
     *
     * Počítá se z týchž tabulek jako „kdo sekci živí", jen po letech. Podíl
     * v pruhu je poměr k loňsku, ne k vymyšlenému cíli: „o třetinu víc zápisů
     * než loni" je věta, která něco znamená.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function rokVCislech(GallerySpace $prostor): array
    {
        $letos = CarbonImmutable::now()->startOfYear();
        $loni = $letos->subYear();

        $radky = [];

        foreach (self::SEKCE as [, $nazev, $tabulka, , $cas]) {
            if (! Schema::hasTable($tabulka)) {
                continue;
            }

            $pocet = DB::table($tabulka)
                ->where('gallery_space_id', $prostor->id)
                ->selectRaw('SUM(CASE WHEN '.$cas.' >= ? THEN 1 ELSE 0 END) AS letos', [$letos])
                ->selectRaw('SUM(CASE WHEN '.$cas.' >= ? AND '.$cas.' < ? THEN 1 ELSE 0 END) AS loni', [$loni, $letos])
                ->first();

            // Sekce, do které letos nikdo nic nedal, do ročního přehledu
            // nepatří — „nula" je odpověď pro Zdraví sekcí, ne pro tuhle.
            if ((int) $pocet->letos === 0) {
                continue;
            }

            $radky[] = [$nazev, (int) $pocet->letos, (int) $pocet->loni];
        }

        if (! $radky) {
            return [];
        }

        $nejvic = max(array_map(fn (array $r) => $r[1], $radky)) ?: 1;

        return array_map(function (array $r) use ($nejvic) {
            [$nazev, $letos, $loni] = $r;

            return [
                $nazev,
                $this->cislo($letos).($loni ? ' · loni '.$this->cislo($loni) : ' · loni nic'),
                (int) round($letos / $nejvic * 100),
                match (true) {
                    $loni === 0 => 0,
                    $letos > $loni => 0,
                    $letos < $loni => 1,
                    default => 2,
                },
            ];
        }, $radky);
    }

    /**
     * `health` a `risk` — dvě záložky administrace.
     *
     * Ostatní klíče `ABARS` patří jiným obrazovkám a nechávají se být; kolekce
     * se sesypává z víc poskytovatelů a nikdo z nich ji nedodává celou.
     *
     * @return array<string, list<array{0: string, 1: string, 2: int, 3: int}>>
     */
    private function sloupce(GallerySpace $prostor): array
    {
        $panel = $this->uloziste->panel($prostor);
        $zabrano = (int) ($panel['usedBytes'] ?? 0);
        $limit = $panel['limitBytes'] ?? null;
        $procent = $limit ? (int) round($zabrano / $limit * 100) : 0;

        $jednaKopie = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('storage_status', 'local_only')
            ->selectRaw('COUNT(*) AS pocet, SUM(size_bytes) AS bajtu')
            ->first();

        // Fronta je serverová, ne párová — a záložka „Zdraví systému" se na
        // server taky ptá. Stejná čísla ukazuje i široké rozvržení.
        $chybne = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subWeek())->count()
            : 0;

        $ceka = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;

        return [
            'health' => [
                ['Zaplněnost úložiště', $panel['label'] ?? $this->gb($zabrano), $procent, $procent >= 90 ? 1 : 0],
                ['Chybové úlohy', $this->pocet($chybne, 'chyba', 'chyby', 'chyb').' za 7 dní', min(100, $chybne * 10), $chybne > 0 ? 1 : 2],
                ['Čeká ve frontě', $this->pocet($ceka, 'úloha', 'úlohy', 'úloh'), min(100, $ceka * 5), $ceka > 0 ? 0 : 2],
                ['Druhá kopie', (string) ($panel['sync'] ?? '—'), ($panel['syncTon'] ?? '') === 'ok' ? 100 : 40,
                    ($panel['syncTon'] ?? '') === 'ok' ? 0 : 1],
            ],
            'risk' => array_values(array_filter([
                ['Zaplněnost úložiště', $panel['label'] ?? $this->gb($zabrano), $procent, $procent >= 90 ? 1 : 0],
                (int) $jednaKopie->pocet > 0
                    ? ['Originály jen v jedné kopii', $this->gb((int) $jednaKopie->bajtu).' · riziko',
                        $zabrano > 0 ? (int) round((int) $jednaKopie->bajtu / $zabrano * 100) : 0, 1]
                    : ['Originály jen v jedné kopii', 'žádné — vše je ve dvou', 0, 2],
                $this->radekPosledniKopie($prostor),
                $this->radekPredpoved($prostor, $zabrano, $limit),
            ], fn ($v) => $v !== null)),
        ] + array_filter(['zprCisla' => $this->rokVCislech($prostor)], fn ($v) => $v !== []);
    }

    /** @return array{0: string, 1: string, 2: int, 3: int}|null */
    private function radekPosledniKopie(GallerySpace $prostor): ?array
    {
        if (! Schema::hasTable('storage_operations') || ! Schema::hasTable('storage_connections')) {
            return null;
        }

        $kdy = DB::table('storage_operations as o')
            ->join('storage_connections as s', 's.id', '=', 'o.storage_connection_id')
            ->where('s.gallery_space_id', $prostor->id)
            ->where('o.status', 'completed')
            ->max('o.completed_at');

        if (! $kdy) {
            return ['Poslední kopie do cloudu', 'zatím žádná', 0, 1];
        }

        $kdy = CarbonImmutable::parse($kdy);

        return ['Poslední kopie do cloudu', $this->pred($kdy), 100, $kdy->lt(now()->subWeek()) ? 1 : 0];
    }

    /**
     * Za jak dlouho bude plno — z toho, kolik toho přibylo za poslední čtvrtletí.
     *
     * @return array{0: string, 1: string, 2: int, 3: int}|null
     */
    private function radekPredpoved(GallerySpace $prostor, int $zabrano, ?int $limit): ?array
    {
        if ($limit === null || $limit <= $zabrano) {
            return null;
        }

        $pribylo = (int) MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('uploaded_at', '>=', now()->subDays(90))
            ->sum('size_bytes');

        // Bez přírůstku se nedá předpovědět nic — a nula měsíců by lhala.
        if ($pribylo <= 0) {
            return null;
        }

        $mesicne = $pribylo / 3;
        $mesicu = (int) floor(($limit - $zabrano) / $mesicne);

        return [
            'Předpověď zaplnění',
            $mesicu >= 120 ? 'za víc než deset let' : 'za '.$this->pocet($mesicu, 'měsíc', 'měsíce', 'měsíců'),
            (int) round(min(100, 100 - min(100, $mesicu))),
            $mesicu <= 6 ? 1 : 2,
        ];
    }

    // ——— formát ———

    private function pred(CarbonImmutable $kdy): string
    {
        $minut = $kdy->diffInMinutes(now());

        return match (true) {
            $minut < 60 => 'před '.$this->pocet(max(1, (int) $minut), 'minutou', 'minutami', 'minutami'),
            $kdy->isToday() => 'dnes '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera',
            $kdy->gt(now()->subDays(30)) => 'před '.$this->pocet((int) ceil($kdy->diffInDays(now())), 'dnem', 'dny', 'dny'),
            default => $kdy->format('j. n. Y'),
        };
    }

    private function gb(int $bajtu): string
    {
        $gb = $bajtu / 1_000_000_000;

        return $gb >= 1
            ? str_replace('.', ',', (string) round($gb, 1)).' GB'
            : str_replace('.', ',', (string) round($bajtu / 1_000_000, 1)).' MB';
    }

    private function castka(float $castka, string $mena): string
    {
        $znak = match (strtoupper($mena)) {
            'CZK' => 'Kč',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $mena,
        };

        return number_format($castka, 0, ',', ' ').' '.$znak;
    }

    private function cislo(int $kolik): string
    {
        return number_format($kolik, 0, ',', ' ');
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $this->cislo($kolik).' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }

    private function mesicuHistorie(GallerySpace $prostor): int
    {
        $prvni = DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->min('occurred_at');

        return $prvni ? (int) floor(CarbonImmutable::parse($prvni)->diffInMonths(now())) : 0;
    }
}
