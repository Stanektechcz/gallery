<?php

namespace App\Services\Obsah;

use App\Http\Controllers\Api\Galerie\TiskController;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Náš příběh, tisk, nouzový přístup, tierlisty, papírová záloha a hosté.
 *
 * Šest obrazovek, které dosud neměly kam psát. Každá z nich teď má tabulku
 * a svého pisatele — bez toho druhého by ta první byla horší než nic.
 *
 * Nikde se nedopočítává to, co může říct jen člověk: proč kapitola končí zrovna
 * takhle, kde leží obálka se záložním klíčem, co si host myslí o fotce.
 * Aplikace k tomu doplní jen to, co skutečně ví — kolik je ke kapitole fotek,
 * kdy se naposledy kopírovalo do cloudu.
 */
class Pribeh implements PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    public function skupina(): string
    {
        return 'pribeh';
    }

    /**
     * Všechno celé.
     *
     * Ukázková kapitola vedle skutečných by byla vyprávění o něčem, co se
     * nestalo; ukázková objednávka by dvojici řekla, že jí něco jede poštou.
     */
    public function uplne(): array
    {
        return ['STORY', 'STORYMS', 'PORDERS', 'EM_ITEMS', 'EM_LOG', 'PAPER_ROWS', 'GV_C', 'RECON'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        return array_filter([
            'STORY' => $this->kapitoly($prostor),
            'STORYMS' => $this->milniky($prostor),
            'PORDERS' => $objednavky = $this->objednavky($prostor),
            /*
             * Kroky zásilky. Katalog, ale patří k `step` v databázi — dvě
             * místa s jinými názvy by znamenala pruh, který ukazuje jinam,
             * než co server zapsal.
             *
             * Bez objednávek se neposílá: samotné popisky nikam nepatří
             * a skupina, která nemá co říct, má mlčet celá.
             */
            'POSTEPS' => $objednavky ? TiskController::KROKY : [],
            'EM_ITEMS' => $this->nouzovePolozky($prostor),
            'EM_LOG' => $this->nouzovyProtokol($prostor),
            'PAPER_ROWS' => $this->papir($prostor),
            'GV_C' => $this->komentareHostu($prostor),
            'ABARS' => ($t = $this->tierlisty($prostor)) ? ['tier' => $t] : null,
            'RECON' => $this->rekonstrukce($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Rekonstrukce dne: `{ klíč: { label, title, sources, steps, gap } }`.
     *
     * Den poskládaný z toho, co po něm zbylo — z fotek, plateb, zápisů
     * a zpráv. **Nic se nevypráví.** Ukázka měla věty jako „Vzhůru dřív než
     * ostatní"; tady stojí, co data říkají: kolik fotek, odkud, za kolik.
     *
     * Díra v datech se hlásí, ne zaplňuje. Dvě hodiny, o kterých aplikace nic
     * neví, jsou informace — domyslet je znamená napsat dvojici do vzpomínek
     * něco, co si nepamatuje.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rekonstrukce(GallerySpace $prostor): array
    {
        $dny = $this->dnySDaty($prostor);

        if ($dny === []) {
            return [];
        }

        $vysledek = [];

        foreach ($dny as $den) {
            $kroky = $this->krokyDne($prostor, $den);

            // Den o jednom kroku není rekonstrukce, je to jedna fotka.
            if (count($kroky) < 2) {
                continue;
            }

            $vysledek[$den->format('Y-m-d')] = [
                'label' => $den->format('j. n. Y'),
                'title' => $this->denCesky($den),
                'sources' => $this->zdroje($kroky),
                'steps' => array_map(
                    fn (array $k) => [$k['cas'], $k['text'], $k['zdroj'], $k['ikona']],
                    $kroky,
                ),
                'gap' => $this->dira($kroky),
            ];
        }

        return $vysledek;
    }

    /**
     * Dny, po kterých zbylo nejvíc — nejvýš tři, od nejnovějšího.
     *
     * @return list<CarbonImmutable>
     */
    private function dnySDaty(GallerySpace $prostor): array
    {
        $dny = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNotNull('taken_at')
            ->get(['taken_at'])
            ->countBy(fn (MediaItem $m) => CarbonImmutable::parse($m->taken_at)->format('Y-m-d'))
            ->sortDesc()
            ->take(3)
            ->keys();

        return $dny->map(fn (string $d) => CarbonImmutable::parse($d))->all();
    }

    /**
     * Kroky jednoho dne, seřazené v čase.
     *
     * @return list<array<string, string>>
     */
    private function krokyDne(GallerySpace $prostor, CarbonImmutable $den): array
    {
        $od = $den->startOfDay();
        $do = $den->endOfDay();
        $kroky = [];

        // Fotky se slučují do shluků: dvacet snímků z jednoho místa za dvacet
        // minut je jeden okamžik, ne dvacet řádků.
        $fotky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereBetween('taken_at', [$od, $do])
            ->orderBy('taken_at')
            ->get(['taken_at', 'location_name', 'original_filename']);

        foreach ($this->shluky($fotky) as $shluk) {
            $kroky[] = [
                'cas' => $shluk['od']->format('G:i'),
                'text' => $this->vetaShluku($shluk),
                'zdroj' => $shluk['pocet'] === 1
                    ? 'fotka '.$shluk['nazev'].' · čas z EXIF'
                    : 'fotky · '.$this->pocet($shluk['pocet'], 'snímek', 'snímky', 'snímků').' za sebou',
                'ikona' => $shluk['pocet'] > 5 ? 'ph-images' : 'ph-camera',
            ];
        }

        if (Schema::hasTable('transactions')) {
            $platby = DB::table('transactions')
                ->where('gallery_space_id', $prostor->id)
                ->whereBetween('occurred_at', [$od, $do])
                ->orderBy('occurred_at')
                ->get(['occurred_at', 'description', 'counterparty', 'amount_from', 'currency_from', 'place']);

            foreach ($platby as $p) {
                $kdy = CarbonImmutable::parse($p->occurred_at);
                $co = $p->description ?: ($p->counterparty ?: 'Platba');

                $kroky[] = [
                    // Transakce mívá jen datum; bez času se řadí na konec dne.
                    'cas' => $kdy->format('G:i') === '0:00' ? '—' : $kdy->format('G:i'),
                    'text' => $co.', '.$this->castka((float) $p->amount_from, (string) $p->currency_from)
                        .($p->place ? ' · '.$p->place : '').'.',
                    'zdroj' => 'transakce'.($p->counterparty ? ' · '.$p->counterparty : ''),
                    'ikona' => 'ph-receipt',
                ];
            }
        }

        if (Schema::hasTable('journal_entries')) {
            $zapisy = DB::table('journal_entries')
                ->where('gallery_space_id', $prostor->id)
                ->whereDate('entry_date', $den->toDateString())
                ->get(['title', 'created_at']);

            foreach ($zapisy as $z) {
                $kroky[] = [
                    'cas' => CarbonImmutable::parse($z->created_at)->format('G:i'),
                    'text' => 'Zápis v deníku: „'.$z->title.'".',
                    'zdroj' => 'deník',
                    'ikona' => 'ph-notebook',
                ];
            }
        }

        usort($kroky, fn (array $a, array $b) => strcmp(
            str_pad($a['cas'] === '—' ? '99:99' : $a['cas'], 5, '0', STR_PAD_LEFT),
            str_pad($b['cas'] === '—' ? '99:99' : $b['cas'], 5, '0', STR_PAD_LEFT),
        ));

        return $kroky;
    }

    /**
     * Fotky do shluků: nový shluk začíná po půl hodině bez snímku nebo na
     * jiném místě.
     *
     * @param  Collection<int, MediaItem>  $fotky
     * @return list<array<string, mixed>>
     */
    private function shluky($fotky): array
    {
        $shluky = [];
        $aktualni = null;

        foreach ($fotky as $m) {
            $kdy = CarbonImmutable::parse($m->taken_at);
            $misto = (string) ($m->location_name ?? '');

            $novy = $aktualni === null
                || $misto !== $aktualni['misto']
                || $kdy->diffInMinutes($aktualni['do']) > 30;

            if ($novy) {
                if ($aktualni !== null) {
                    $shluky[] = $aktualni;
                }

                $aktualni = [
                    'od' => $kdy, 'do' => $kdy, 'misto' => $misto,
                    'pocet' => 0, 'nazev' => $m->original_filename,
                ];
            }

            $aktualni['do'] = $kdy;
            $aktualni['pocet']++;
        }

        if ($aktualni !== null) {
            $shluky[] = $aktualni;
        }

        return $shluky;
    }

    /** @param  array<string, mixed>  $shluk */
    private function vetaShluku(array $shluk): string
    {
        $minut = (int) $shluk['od']->diffInMinutes($shluk['do']);
        $kde = $shluk['misto'] !== '' ? ' — '.$shluk['misto'] : '';

        if ($shluk['pocet'] === 1) {
            return 'Jedna fotka'.$kde.'.';
        }

        return $this->pocet($shluk['pocet'], 'fotka', 'fotky', 'fotek')
            .($minut > 0 ? ' za '.$this->pocet($minut, 'minutu', 'minuty', 'minut') : ' během chvíle')
            .$kde.'.';
    }

    /**
     * Nejdelší díra mezi kroky.
     *
     * Hlásí se, nezaplňuje: dvě hodiny, o kterých aplikace nic neví, jsou
     * informace.
     *
     * @param  list<array<string, string>>  $kroky
     */
    private function dira(array $kroky): string
    {
        $casy = array_values(array_filter(array_column($kroky, 'cas'), fn (string $c) => $c !== '—'));

        if (count($casy) < 2) {
            return '';
        }

        $nejvic = 0;
        $od = $do = '';

        for ($i = 1; $i < count($casy); $i++) {
            $a = CarbonImmutable::createFromFormat('G:i', $casy[$i - 1]);
            $b = CarbonImmutable::createFromFormat('G:i', $casy[$i]);
            $minut = (int) $a->diffInMinutes($b);

            if ($minut > $nejvic) {
                $nejvic = $minut;
                $od = $casy[$i - 1];
                $do = $casy[$i];
            }
        }

        if ($nejvic < 120) {
            return 'Den drží pohromadě — mezi zápisy není delší prázdno než dvě hodiny.';
        }

        return 'Mezi '.$od.' a '.$do.' nejsou žádná data — '
            .$this->pocet((int) round($nejvic / 60), 'hodina', 'hodiny', 'hodin')
            .' prázdno. Jestli si vzpomenete, doplňte je; jinak den zůstane s dírou, což je taky odpověď.';
    }

    /** @param  list<array<string, string>>  $kroky */
    private function zdroje(array $kroky): string
    {
        $podle = array_count_values(array_map(
            fn (array $k) => str_contains($k['zdroj'], 'transakce') ? 'transakce'
                : (str_contains($k['zdroj'], 'deník') ? 'denik' : 'fotky'),
            $kroky,
        ));

        return implode(', ', array_filter([
            isset($podle['fotky']) ? $this->pocet($podle['fotky'], 'shluku fotek', 'shluků fotek', 'shluků fotek') : null,
            isset($podle['transakce']) ? $this->pocet($podle['transakce'], 'platby', 'plateb', 'plateb') : null,
            isset($podle['denik']) ? $this->pocet($podle['denik'], 'zápisu', 'zápisů', 'zápisů') : null,
        ]));
    }

    private function castka(float $castka, string $mena): string
    {
        $znak = match (strtoupper($mena)) {
            'CZK' => 'Kč', 'EUR' => '€', 'USD' => '$', default => $mena,
        };

        return number_format(abs($castka), 0, ',', ' ').' '.$znak;
    }

    /** „Pátek 24. července 2026" — nadpis rekonstruovaného dne. */
    private function denCesky(CarbonImmutable $den): string
    {
        $dny = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];

        return $dny[$den->dayOfWeek].' '.$den->day.'. '.self::MESICE[$den->month].' '.$den->year;
    }

    /**
     * Kapitoly: `[id, název, rok, stav, fotek, zápisů, text]`.
     *
     * Fotky a zápisy se počítají z roku kapitoly — je to jediné, co k ní
     * aplikace umí přiřadit sama. Text píše dvojice.
     *
     * @return list<array<int, mixed>>
     */
    private function kapitoly(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_story_chapters')) {
            return [];
        }

        $kapitoly = DB::table('couple_story_chapters')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($kapitoly->isEmpty()) {
            return [];
        }

        $fotky = $this->poRocich($prostor);
        $zapisy = $this->zapisyPoRocich($prostor);

        return $kapitoly
            ->map(fn (object $k) => [
                $k->uuid,
                $k->title,
                (string) ($k->year ?? ''),
                $this->stavKapitoly((string) $k->status),
                (int) ($fotky[(string) $k->year] ?? 0),
                (int) ($zapisy[(string) $k->year] ?? 0),
                (string) ($k->body ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Milníky: `[id, rok, datum, název, poznámka, ikona, kapitola]`.
     *
     * @return list<array<int, mixed>>
     */
    private function milniky(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_story_milestones')) {
            return [];
        }

        return DB::table('couple_story_milestones as m')
            ->leftJoin('couple_story_chapters as k', 'k.id', '=', 'm.chapter_id')
            ->where('m.gallery_space_id', $prostor->id)
            ->orderBy('m.happened_on')
            ->get(['m.uuid', 'm.happened_on', 'm.title', 'm.note', 'm.icon', 'k.uuid as kapitola'])
            ->map(function (object $m) {
                $kdy = CarbonImmutable::parse($m->happened_on);

                return [
                    $m->uuid,
                    (string) $kdy->year,
                    $kdy->day.'. '.self::MESICE[$kdy->month].' '.$kdy->year,
                    $m->title,
                    (string) ($m->note ?? ''),
                    (string) ($m->icon ?? 'ph-sparkle'),
                    (string) ($m->kapitola ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Objednávky tisku: `[id, název, druh, cena, krok, termín, sledování, ?, poznámka]`.
     *
     * Termín se pozná od odhadu — „odhad 4. 9." a „4. 9." jsou dvě různá
     * tvrzení a dvojice se podle nich rozhoduje, jestli má čekat.
     *
     * @return list<array<int, mixed>>
     */
    private function objednavky(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('print_orders')) {
            return [];
        }

        return DB::table('print_orders')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn (object $o) => [
                $o->uuid,
                $o->title,
                (string) $o->kind,
                (int) $o->price,
                (int) $o->step,
                $o->due_on
                    ? (($o->due_estimated ? 'odhad ' : '').CarbonImmutable::parse($o->due_on)->format('j. n. Y'))
                    : 'termín neznámý',
                (string) ($o->tracking ?? ''),
                (int) $o->id,
                (string) ($o->note ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Nouzový přístup: `[{ id, label, note, on }]`.
     *
     * @return list<array<string, mixed>>
     */
    private function nouzovePolozky(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('emergency_access_items')) {
            return [];
        }

        return DB::table('emergency_access_items')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $p) => [
                'id' => $p->uuid,
                'label' => $p->label,
                'note' => (string) ($p->note ?? ''),
                'on' => (bool) $p->is_shared,
            ])
            ->values()
            ->all();
    }

    /**
     * Protokol nouzového přístupu: `[{ text, when }]`.
     *
     * @return list<array<string, string>>
     */
    private function nouzovyProtokol(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('emergency_access_log')) {
            return [];
        }

        return DB::table('emergency_access_log')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('happened_at')
            ->limit(40)
            ->get()
            ->map(fn (object $z) => [
                'text' => $z->text,
                'when' => CarbonImmutable::parse($z->happened_at)->format('j. n. Y'),
            ])
            ->values()
            ->all();
    }

    /**
     * Papírová záloha: `[{ id, label, value, on, changed }]`.
     *
     * @return list<array<string, mixed>>
     */
    private function papir(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('paper_backup_rows')) {
            return [];
        }

        return DB::table('paper_backup_rows')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $r) => [
                'id' => $r->uuid,
                'label' => $r->label,
                'value' => (string) ($r->value ?? ''),
                'on' => (bool) $r->is_done,
                'changed' => (bool) $r->changed,
            ])
            ->values()
            ->all();
    }

    /**
     * Komentáře hostů: `[{ id, who, text, when, share, hidden, … }]`.
     *
     * Host nemá uživatele — má jméno, které si napsal, a odkaz, kterým přišel.
     *
     * @return list<array<string, mixed>>
     */
    private function komentareHostu(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('guest_comments')) {
            return [];
        }

        return DB::table('guest_comments as k')
            ->leftJoin('shared_links as o', 'o.id', '=', 'k.shared_link_id')
            ->leftJoin('media_items as m', 'm.id', '=', 'k.media_item_id')
            ->where('k.gallery_space_id', $prostor->id)
            ->orderByDesc('k.created_at')
            ->limit(60)
            ->get([
                'k.uuid', 'k.guest_name', 'k.body', 'k.kind', 'k.duration', 'k.audio_path',
                'k.is_hidden', 'k.is_pinned', 'k.created_at',
                'o.name as odkaz', 'm.original_filename as fotka',
            ])
            ->map(fn (object $k) => array_filter([
                'id' => $k->uuid,
                'who' => $k->guest_name,
                'text' => $k->body,
                'when' => $this->kdy(CarbonImmutable::parse($k->created_at)),
                'share' => (string) ($k->odkaz ?? ''),
                'hidden' => (bool) $k->is_hidden,
                'kind' => $k->kind === 'voice' ? 'voice' : null,
                'photo' => $k->fotka,
                'len' => $k->duration,
                // Bez adresy je hlasovka řádek, který tvrdí, že babička něco
                // řekla, a nejde si to poslechnout.
                'audio' => $k->audio_path ? Storage::disk('public')->url($k->audio_path) : null,
                'pinned' => (bool) $k->is_pinned,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }

    /**
     * Tierlisty do sloupců: `[pásmo, tituly, %, barva]`.
     *
     * Sto procent má nejobsazenější pásmo — porovnává se s vlastním
     * seznamem, ne s cizím žebříčkem.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function tierlisty(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('watch_titles')) {
            return [];
        }

        $pasma = DB::table('watch_titles')
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('tier')
            ->orderBy('tier')
            ->get(['tier', 'title'])
            ->groupBy('tier');

        if ($pasma->isEmpty()) {
            return [];
        }

        $nejvic = max($pasma->map->count()->all()) ?: 1;

        $popis = [
            'S' => 'nezapomenutelné', 'A' => 'velmi dobré', 'B' => 'dobré',
            'C' => 'projde', 'D' => 'nic moc', 'F' => 'ztráta času',
        ];

        return $pasma
            ->map(function ($tituly, string $pasmo) use ($nejvic, $popis) {
                $jmena = $tituly->pluck('title');

                return [
                    $pasmo.' · '.($popis[$pasmo] ?? 'zařazené'),
                    $jmena->count() <= 3
                        ? $jmena->implode(', ')
                        : $jmena->take(2)->implode(', ').' a '.$this->pocet($jmena->count() - 2, 'další', 'další', 'dalších'),
                    (int) round($jmena->count() / $nejvic * 100),
                    0,
                ];
            })
            ->values()
            ->all();
    }

    // ——— co k tomu aplikace ví ———

    /** @return array<string, int> */
    private function poRocich(GallerySpace $prostor): array
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNotNull('taken_at')
            ->get(['taken_at'])
            ->countBy(fn (MediaItem $m) => (string) CarbonImmutable::parse($m->taken_at)->year)
            ->all();
    }

    /** @return array<string, int> */
    private function zapisyPoRocich(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('journal_entries')) {
            return [];
        }

        return DB::table('journal_entries')
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('entry_date')
            ->get(['entry_date'])
            ->countBy(fn (object $z) => (string) CarbonImmutable::parse($z->entry_date)->year)
            ->all();
    }

    private function stavKapitoly(string $stav): string
    {
        return match ($stav) {
            'done' => 'hotovo',
            'published' => 'zveřejněno',
            default => 'píše se',
        };
    }

    private function kdy(CarbonImmutable $kdy): string
    {
        return match (true) {
            $kdy->isToday() => 'dnes '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera '.$kdy->format('G:i'),
            $kdy->gt(CarbonImmutable::now()->subWeek()) => 'před '.$this->pocet((int) ceil($kdy->diffInDays(CarbonImmutable::now())), 'dnem', 'dny', 'dny'),
            default => $kdy->format('j. n. Y'),
        };
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
