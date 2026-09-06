<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        return ['STORY', 'STORYMS', 'PORDERS', 'EM_ITEMS', 'EM_LOG', 'PAPER_ROWS', 'GV_C'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        return array_filter([
            'STORY' => $this->kapitoly($prostor),
            'STORYMS' => $this->milniky($prostor),
            'PORDERS' => $this->objednavky($prostor),
            'EM_ITEMS' => $this->nouzovePolozky($prostor),
            'EM_LOG' => $this->nouzovyProtokol($prostor),
            'PAPER_ROWS' => $this->papir($prostor),
            'GV_C' => $this->komentareHostu($prostor),
            'ABARS' => ($t = $this->tierlisty($prostor)) ? ['tier' => $t] : null,
        ], fn ($v) => $v !== null && $v !== []);
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
                'k.uuid', 'k.guest_name', 'k.body', 'k.kind', 'k.duration',
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
