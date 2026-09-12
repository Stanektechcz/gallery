<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * Úvodní obrazovka „Dnes": `DNES`.
 *
 * Skoro všechno na ní bylo napsané v kódu. „Makinka přidala 34 fotek do
 * Beskydy → Pustevny", vzpomínky z 16. srpna 2019, rytmus vztahu od října
 * 2016, cíl „Cesta na Islandu" s 68 400 Kč, otázka pro dva s hotovou
 * odpovědí druhého, den na jedné ose s obědem u Kastelána a návrhy typu
 * „Rezervace v Lisabonu není zaplacená". Přihlášená dvojice tak první, co
 * po otevření aplikace viděla, byl cizí život.
 *
 * Tvar:
 *
 *     nahrani:  { kdo, pocet, album, albumId, kdy, ulozeno } | null
 *     vyroci:   { den, roky, fotky: [{ year, id, bg, isVideo, dur }] }
 *     archiv:   [[druh, ikona, nadpis, meta, text, pozadí, cesta]]
 *     rytmus:   { od, dni, tydny: ['home'|'trip'] } | null
 *     cil:      { name, saved, target, mesicne, termin, mena } | null
 *     den:      { nadpis, radky: [[čas, ikona, druh, text, cesta, pozadí]] }
 *     navrhy:   [{ id, icon, title, body, cta, route, album }]
 *     aktivita: [[iniciála, text, kdy, náhled]]
 */
class Dnes implements PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    private const DNY = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];

    /** Nahrání v jedné dávce: stejný člověk a nejvýš tři hodiny od posledního souboru. */
    private const DAVKA_HODIN = 3;

    /** @var array<int, bool> */
    private array $nahledy = [];

    public function skupina(): string
    {
        return 'dnes';
    }

    public function uplne(): array
    {
        return ['DNES'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('media_items')) {
            return [];
        }

        $jmena = System::jmenaClenu($prostor);
        $dnes = CarbonImmutable::now();

        return [
            'DNES' => [
                'nahrani' => $this->nahrani($prostor, $jmena, $dnes),
                'vyroci' => $this->vyroci($prostor, $dnes),
                'archiv' => $this->archiv($prostor, $dnes),
                'rytmus' => $this->rytmus($prostor, $dnes),
                'cil' => $this->cil($prostor),
                'den' => $this->den($prostor, $jmena, $dnes),
                'navrhy' => $this->navrhy($prostor, $dnes),
                'aktivita' => $this->aktivita($prostor, $jmena, $dnes),
            ],
        ];
    }

    // ——— poslední nahrávání ———

    /**
     * @param  array<int, string>  $jmena
     * @return array<string, mixed>|null
     */
    private function nahrani(GallerySpace $prostor, array $jmena, CarbonImmutable $dnes): ?array
    {
        $posledni = $this->media($prostor)->whereNotNull('uploaded_at')->orderByDesc('uploaded_at')->first(['uploaded_by', 'uploaded_at']);

        if (! $posledni) {
            return null;
        }

        $konec = CarbonImmutable::parse($posledni->uploaded_at);
        $davka = $this->media($prostor)
            ->where('uploaded_by', $posledni->uploaded_by)
            ->whereBetween('uploaded_at', [$konec->subHours(self::DAVKA_HODIN), $konec])
            ->get(['id', 'status', 'primary_album_id']);

        $albumId = $davka->pluck('primary_album_id')->filter()->countBy()->sortDesc()->keys()->first();
        $album = $albumId && Schema::hasTable('albums')
            ? DB::table('albums')->where('id', $albumId)->whereNull('deleted_at')->first(['uuid', 'title', 'full_display_path'])
            : null;
        $hotovo = $davka->where('status', 'ready')->count();

        return [
            'kdo' => $jmena[(int) $posledni->uploaded_by] ?? 'Někdo',
            'pocet' => $this->pocet($davka->count(), 'soubor', 'soubory', 'souborů'),
            'album' => $album ? ($album->full_display_path ?: $album->title) : '',
            'albumId' => $album->uuid ?? '',
            'kdy' => $this->kdy($konec, $dnes),
            'ulozeno' => $hotovo === $davka->count()
                ? 'všechny originály uložené'
                : $this->pocet($davka->count() - $hotovo, 'soubor se ještě zpracovává', 'soubory se ještě zpracovávají', 'souborů se ještě zpracovává'),
        ];
    }

    // ——— tento den v minulých letech ———

    /** @return array<string, mixed> */
    private function vyroci(GallerySpace $prostor, CarbonImmutable $dnes): array
    {
        $fotky = $this->media($prostor)
            ->whereMonth('taken_at', $dnes->month)
            ->whereDay('taken_at', $dnes->day)
            ->whereYear('taken_at', '<', $dnes->year)
            ->orderByDesc('taken_at')
            ->limit(14)
            ->get(['id', 'uuid', 'taken_at', 'media_type', 'duration_ms']);

        $this->zjistiNahledy($fotky->pluck('id')->all());

        return [
            'den' => $dnes->day.'. '.self::MESICE[$dnes->month],
            'roky' => $fotky->map(fn ($f) => (int) CarbonImmutable::parse($f->taken_at)->year)->unique()->sort()->values()->all(),
            'fotky' => $fotky->map(fn ($f) => [
                'year' => (int) CarbonImmutable::parse($f->taken_at)->year,
                'id' => (string) $f->uuid,
                'bg' => $this->nahled($f),
                'isVideo' => $f->media_type === 'video',
                'dur' => $f->duration_ms ? $this->delka((int) round($f->duration_ms / 1000)) : '',
            ])->values()->all(),
        ];
    }

    /**
     * Dnes v archivu: fotka, výdaj, zápis a událost ze stejného dne v jiném roce.
     *
     * @return list<array<int, string>>
     */
    private function archiv(GallerySpace $prostor, CarbonImmutable $dnes): array
    {
        $nalezy = [];

        $fotka = $this->media($prostor)
            ->whereMonth('taken_at', $dnes->month)->whereDay('taken_at', $dnes->day)->whereYear('taken_at', '<', $dnes->year)
            ->orderByRaw('CASE WHEN caption IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('taken_at')
            ->first(['id', 'uuid', 'taken_at', 'caption', 'location_name']);

        if ($fotka) {
            $this->zjistiNahledy([$fotka->id]);
            $kdy = CarbonImmutable::parse($fotka->taken_at);
            $nalezy[] = ['Fotka', 'ph-image', $fotka->location_name ?: 'Fotka z '.$kdy->year,
                $this->datum($kdy).($fotka->location_name ? ' · '.$fotka->location_name : ''),
                (string) ($fotka->caption ?? ''), $this->nahled($fotka), 'all'];
        }

        if (Schema::hasTable('transactions')) {
            $vydaj = DB::table('transactions')
                ->where('gallery_space_id', $prostor->id)->where('type', 'expense')->whereNull('deleted_at')
                ->whereMonth('occurred_at', $dnes->month)->whereDay('occurred_at', $dnes->day)->whereYear('occurred_at', '<', $dnes->year)
                ->orderByDesc('amount_from')
                ->first(['occurred_at', 'description', 'counterparty', 'amount_from', 'currency_from']);

            if ($vydaj) {
                $kdy = CarbonImmutable::parse($vydaj->occurred_at);
                $nalezy[] = ['Výdaj', 'ph-receipt', (string) ($vydaj->description ?: ($vydaj->counterparty ?: 'Výdaj')),
                    $this->datum($kdy).' · '.$this->castka((float) $vydaj->amount_from, (string) ($vydaj->currency_from ?? 'CZK')),
                    '', 'var(--g-panel)', 'x-transakce'];
            }
        }

        if (Schema::hasTable('journal_entries')) {
            $ja = auth()->id();
            $zapis = DB::table('journal_entries')
                ->where('gallery_space_id', $prostor->id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $ja))
                ->whereMonth('entry_date', $dnes->month)->whereDay('entry_date', $dnes->day)->whereYear('entry_date', '<', $dnes->year)
                ->orderByDesc('entry_date')
                ->first(['entry_date', 'title', 'body']);

            if ($zapis) {
                $nalezy[] = ['Zápis', 'ph-notebook', (string) ($zapis->title ?: 'Zápis v deníku'),
                    $this->datum(CarbonImmutable::parse($zapis->entry_date)),
                    mb_substr(trim(strip_tags((string) $zapis->body)), 0, 130), 'var(--g-panel)', 'x-denik'];
            }
        }

        if (Schema::hasTable('calendar_events')) {
            $udalost = DB::table('calendar_events')
                ->where('gallery_space_id', $prostor->id)->where('is_private', false)
                ->whereMonth('starts_at', $dnes->month)->whereDay('starts_at', $dnes->day)->whereYear('starts_at', '<', $dnes->year)
                ->orderByDesc('starts_at')
                ->first(['starts_at', 'title', 'place_name']);

            if ($udalost) {
                $nalezy[] = ['Událost', 'ph-calendar-dot', (string) $udalost->title,
                    $this->datum(CarbonImmutable::parse($udalost->starts_at)).($udalost->place_name ? ' · '.$udalost->place_name : ''),
                    '', 'var(--g-panel)', 'calendar'];
            }
        }

        return $nalezy;
    }

    // ——— rytmus vztahu ———

    /**
     * Dny od prvního společného milníku a týdny letošního roku: doma, nebo na cestě.
     *
     * Prototyp počítal od 18. října 2016 a týdny si „obarvil" modulem. Rozlišit
     * „každý jinde" a „u rodiny" aplikace neumí, tak se to ani netvrdí.
     *
     * @return array<string, mixed>|null
     */
    private function rytmus(GallerySpace $prostor, CarbonImmutable $dnes): ?array
    {
        $od = Schema::hasTable('relationship_milestones')
            ? DB::table('relationship_milestones')->where('gallery_space_id', $prostor->id)->where('visibility', '!=', 'private')->min('occurred_on')
            : null;

        $cesty = Schema::hasTable('trips')
            ? DB::table('trips')->where('gallery_space_id', $prostor->id)
                ->whereDate('end_date', '>=', $dnes->startOfYear()->toDateString())
                ->whereDate('start_date', '<=', $dnes->toDateString())
                ->get(['start_date', 'end_date'])
            : collect();

        if (! $od && $cesty->isEmpty()) {
            return null;
        }

        $tydny = [];
        $pondeli = $dnes->startOfYear()->startOfWeek(CarbonImmutable::MONDAY);

        while ($pondeli->lte($dnes)) {
            $nedele = $pondeli->addDays(6);
            $tydny[] = $cesty->contains(fn ($c) => CarbonImmutable::parse($c->start_date)->lte($nedele) && CarbonImmutable::parse($c->end_date)->gte($pondeli))
                ? 'trip' : 'home';
            $pondeli = $pondeli->addWeek();
        }

        return [
            'od' => $od ? CarbonImmutable::parse($od)->toDateString() : null,
            'dni' => $od ? (int) CarbonImmutable::parse($od)->startOfDay()->diffInDays($dnes->startOfDay()) : null,
            'tydny' => $tydny,
        ];
    }

    // ——— cíl ———

    /** @return array<string, mixed>|null */
    private function cil(GallerySpace $prostor): ?array
    {
        if (! Schema::hasTable('budget_goals')) {
            return null;
        }

        $cil = DB::table('budget_goals as g')
            ->join('budgets as b', 'b.id', '=', 'g.budget_id')
            ->where('b.gallery_space_id', $prostor->id)
            ->whereNull('b.deleted_at')
            ->where('g.target_amount', '>', 0)
            ->whereColumn('g.saved_amount', '<', 'g.target_amount')
            ->orderByRaw('CASE WHEN g.target_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('g.target_on')
            ->orderBy('g.sort_order')
            ->first(['g.name', 'g.target_amount', 'g.saved_amount', 'g.currency', 'g.target_on']);

        if (! $cil) {
            return null;
        }

        $zbyva = max(0.0, (float) $cil->target_amount - (float) $cil->saved_amount);
        $termin = $cil->target_on ? CarbonImmutable::parse($cil->target_on) : null;
        $mesicu = $termin ? max(1, (int) ceil(CarbonImmutable::today()->diffInMonths($termin, false))) : null;

        return [
            'name' => (string) $cil->name,
            'saved' => $this->castka((float) $cil->saved_amount, (string) $cil->currency),
            'target' => $this->castka((float) $cil->target_amount, (string) $cil->currency),
            'left' => $this->castka($zbyva, (string) $cil->currency),
            'pct' => (int) min(100, round((float) $cil->saved_amount / (float) $cil->target_amount * 100)),
            'mesicne' => $mesicu ? $this->castka($zbyva / $mesicu, (string) $cil->currency) : null,
            'termin' => $termin ? self::MESICE[$termin->month].' '.$termin->year : null,
        ];
    }

    // ——— den na jedné ose ———

    /**
     * Co se dnes stalo, v pořadí podle času. Když je dnešek prázdný, poslední
     * den, kdy se něco dělo — prázdná osa by na úvodní obrazovce nic neřekla.
     *
     * @param  array<int, string>  $jmena
     * @return array<string, mixed>
     */
    private function den(GallerySpace $prostor, array $jmena, CarbonImmutable $dnes): array
    {
        $radky = $this->udalostiDne($prostor, $jmena, $dnes);
        $kdy = $dnes;

        if ($radky === []) {
            $posledni = $this->media($prostor)->whereNotNull('taken_at')->where('taken_at', '<', $dnes->startOfDay())->max('taken_at');

            if ($posledni) {
                $kdy = CarbonImmutable::parse($posledni);
                $radky = $this->udalostiDne($prostor, $jmena, $kdy);
            }
        }

        usort($radky, fn (array $a, array $b) => $a['t'] <=> $b['t']);

        return [
            'nadpis' => ($kdy->isSameDay($dnes) ? 'dnes' : self::DNY[$kdy->dayOfWeek].' '.$kdy->day.'. '.self::MESICE[$kdy->month]),
            'radky' => array_map(fn (array $r) => [$r['t']->format('G:i'), $r[0], $r[1], $r[2], $r[3], $r[4]], array_slice($radky, 0, 12)),
        ];
    }

    /**
     * @param  array<int, string>  $jmena
     * @return list<array<int|string, mixed>>
     */
    private function udalostiDne(GallerySpace $prostor, array $jmena, CarbonImmutable $den): array
    {
        $od = $den->startOfDay();
        $do = $od->addDay();
        $radky = [];

        // Fotky po hodinách — „9 fotek z Petřína", ne devět řádků.
        $fotky = $this->media($prostor)->where('taken_at', '>=', $od)->where('taken_at', '<', $do)
            ->orderBy('taken_at')->get(['id', 'uuid', 'taken_at', 'location_name']);

        foreach ($fotky->groupBy(fn ($f) => CarbonImmutable::parse($f->taken_at)->hour) as $hodina) {
            $prvni = $hodina->first();
            $misto = $hodina->pluck('location_name')->filter()->first();
            $this->zjistiNahledy([$prvni->id]);
            $radky[] = ['t' => CarbonImmutable::parse($prvni->taken_at), 'ph-image', 'Fotky',
                $this->pocet($hodina->count(), 'fotka', 'fotky', 'fotek').($misto ? ' · '.$misto : ''), 'all', $this->nahled($prvni)];
        }

        if (Schema::hasTable('chat_messages')) {
            DB::table('chat_messages')->where('gallery_space_id', $prostor->id)->whereNull('deleted_at')
                ->where('created_at', '>=', $od)->where('created_at', '<', $do)
                ->orderBy('created_at')->limit(4)
                ->get(['created_by', 'body', 'attachment_type', 'created_at'])
                ->each(function ($m) use (&$radky, $jmena) {
                    $text = trim((string) $m->body) !== '' ? '„'.mb_substr(trim((string) $m->body), 0, 60).'"' : 'příloha';
                    $radky[] = ['t' => CarbonImmutable::parse($m->created_at), 'ph-chats-circle', 'Zpráva',
                        ($jmena[(int) $m->created_by] ?? 'Někdo').': '.$text, 'x-zpravy', null];
                });
        }

        if (Schema::hasTable('transactions')) {
            DB::table('transactions')->where('gallery_space_id', $prostor->id)->where('type', 'expense')->whereNull('deleted_at')
                ->whereDate('occurred_at', $od->toDateString())
                ->orderBy('created_at')->limit(4)
                ->get(['description', 'counterparty', 'amount_from', 'currency_from', 'created_at'])
                ->each(function ($t) use (&$radky, $od) {
                    // Datum výdaje nemá čas; řadí se podle toho, kdy byl zapsaný.
                    $zapsano = CarbonImmutable::parse($t->created_at);
                    $radky[] = ['t' => $zapsano->isSameDay($od) ? $zapsano : $od->setTime(12, 0), 'ph-receipt', 'Výdaj',
                        ($t->description ?: ($t->counterparty ?: 'Výdaj')).' · '.$this->castka((float) $t->amount_from, (string) ($t->currency_from ?? 'CZK')), 'x-transakce', null];
                });
        }

        if (Schema::hasTable('shared_todos')) {
            DB::table('shared_todos')->where('gallery_space_id', $prostor->id)->where('status', 'completed')
                ->where('completed_at', '>=', $od)->where('completed_at', '<', $do)
                ->orderBy('completed_at')->limit(4)
                ->get(['title', 'completed_at'])
                ->each(function ($u) use (&$radky) {
                    $radky[] = ['t' => CarbonImmutable::parse($u->completed_at), 'ph-list-checks', 'Úkol', 'Odškrtnuto: '.$u->title, 'x-plan', null];
                });
        }

        if (Schema::hasTable('voice_notes')) {
            DB::table('voice_notes')->where('gallery_space_id', $prostor->id)
                ->where('created_at', '>=', $od)->where('created_at', '<', $do)
                ->orderBy('created_at')->limit(3)
                ->get(['title', 'duration_ms', 'created_at'])
                ->each(function ($v) use (&$radky) {
                    $radky[] = ['t' => CarbonImmutable::parse($v->created_at), 'ph-microphone', 'Hlasovka',
                        $this->delka((int) round(($v->duration_ms ?? 0) / 1000)).($v->title ? ' — '.$v->title : ''), 'x-zpravy', null];
                });
        }

        if (Schema::hasTable('journal_entries')) {
            $ja = auth()->id();
            DB::table('journal_entries')->where('gallery_space_id', $prostor->id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $ja))
                ->whereDate('entry_date', $od->toDateString())
                ->limit(3)
                ->get(['title', 'created_at'])
                ->each(function ($z) use (&$radky, $od) {
                    $zapsano = CarbonImmutable::parse($z->created_at);
                    $radky[] = ['t' => $zapsano->isSameDay($od) ? $zapsano : $od->setTime(21, 0), 'ph-notebook', 'Zápis', (string) ($z->title ?: 'Zápis v deníku'), 'x-denik', null];
                });
        }

        return $radky;
    }

    // ——— návrhy ———

    /**
     * Návrhy z toho, co v datech opravdu je.
     *
     * Zůstaly jen ty, které jde spočítat: den s hromadou fotek bez alba, série
     * podobných fotek a den s fotkami bez zápisu. Zaplacení rezervace a
     * chybějící suroviny prototyp vymyslel.
     *
     * @return list<array<string, mixed>>
     */
    private function navrhy(GallerySpace $prostor, CarbonImmutable $dnes): array
    {
        $navrhy = [];
        $od = $dnes->subDays(30)->startOfDay();

        $bezAlba = $this->media($prostor)->whereNull('primary_album_id')->where('taken_at', '>=', $od)
            ->get(['id', 'taken_at', 'location_name'])
            ->groupBy(fn ($f) => CarbonImmutable::parse($f->taken_at)->toDateString())
            ->sortByDesc(fn (Collection $d) => $d->count())
            ->first();

        if ($bezAlba && $bezAlba->count() >= 10) {
            $den = CarbonImmutable::parse($bezAlba->first()->taken_at);
            $misto = $bezAlba->pluck('location_name')->filter()->countBy()->sortDesc()->keys()->first();
            $navrhy[] = [
                'id' => 's-album-'.$den->toDateString(),
                'icon' => 'ph-folder-plus',
                'title' => $this->pocet($bezAlba->count(), 'fotka', 'fotky', 'fotek').' z '.$den->day.'. '.$den->month.'. nemá album',
                'body' => 'Všechny jsou ze stejného dne'.($misto ? ' a hlavně z místa '.$misto : '').' — uděláme z nich album?',
                'cta' => 'Vytvořit album',
                'route' => 'album-new',
                'album' => $misto ?: $den->day.'. '.self::MESICE[$den->month].' '.$den->year,
            ];
        }

        if (Schema::hasTable('duplicate_groups')) {
            $skupin = DB::table('duplicate_groups')->where('gallery_space_id', $prostor->id)->whereNull('resolved_at')->count();

            if ($skupin > 0) {
                $navrhy[] = [
                    'id' => 's-dupes-'.$skupin,
                    'icon' => 'ph-copy',
                    'title' => $this->pocet($skupin, 'skupina podobných fotek', 'skupiny podobných fotek', 'skupin podobných fotek'),
                    'body' => 'V knihovně jsou téměř shodné snímky. Necháte nejlepší a ostatní přesunete do koše?',
                    'cta' => 'Projít duplicity',
                    'route' => 'x-uklid',
                    'album' => '',
                ];
            }
        }

        if (Schema::hasTable('journal_entries')) {
            $dnySFotkami = $this->media($prostor)->where('taken_at', '>=', $dnes->subDays(7)->startOfDay())->where('taken_at', '<', $dnes->startOfDay())
                ->get(['taken_at'])
                ->countBy(fn ($f) => CarbonImmutable::parse($f->taken_at)->toDateString())
                ->filter(fn (int $n) => $n >= 10)
                ->sortKeysDesc();

            foreach ($dnySFotkami as $datum => $n) {
                $zapsano = DB::table('journal_entries')->where('gallery_space_id', $prostor->id)->whereNull('deleted_at')->whereDate('entry_date', $datum)->exists();

                if (! $zapsano) {
                    $den = CarbonImmutable::parse($datum);
                    $navrhy[] = [
                        'id' => 's-diary-'.$datum,
                        'icon' => 'ph-notebook',
                        'title' => 'Z '.self::DNY[$den->dayOfWeek].' '.$den->day.'. '.$den->month.'. není zápis',
                        'body' => 'Máte '.$this->pocet($n, 'fotku', 'fotky', 'fotek').', ale žádnou větu. Napíšete krátký zápis, než se to zapomene?',
                        'cta' => 'Napsat zápis',
                        'route' => 'x-denik',
                        'album' => '',
                    ];
                    break;
                }
            }
        }

        return $navrhy;
    }

    // ——— aktivita ———

    /**
     * Co kdo v aplikaci udělal, z auditního logu. Po sobě jdoucí nahrání
     * jednoho člověka se slijí do jednoho řádku.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<int, string|null>>
     */
    private function aktivita(GallerySpace $prostor, array $jmena, CarbonImmutable $dnes): array
    {
        if (! Schema::hasTable('audit_logs') || $jmena === []) {
            return [];
        }

        $popisy = [
            'media.upload' => 'nahrání',
            'media.restore' => 'obnovení z koše',
            'media.purge' => 'trvalé smazání',
            'share.create' => 'nový sdílený odkaz',
            'share.update' => 'úprava sdíleného odkazu',
            'share.extend' => 'prodloužení sdíleného odkazu',
            'share.revoke' => 'zrušení sdíleného odkazu',
        ];

        $zaznamy = DB::table('audit_logs')
            ->whereIn('user_id', array_keys($jmena))
            ->whereIn('action', array_keys($popisy))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['user_id', 'action', 'subject_type', 'subject_id', 'payload', 'created_at']);

        $radky = [];
        $predchozi = null;

        foreach ($zaznamy as $z) {
            $klic = $z->user_id.'|'.$z->action;

            // Tentýž člověk, táž akce, do hodiny od předchozí: patří do jednoho řádku.
            if ($predchozi && $predchozi['klic'] === $klic
                && CarbonImmutable::parse($predchozi['od'])->diffInMinutes(CarbonImmutable::parse($z->created_at), true) <= 60) {
                $radky[count($radky) - 1]['n']++;
                $predchozi['od'] = $z->created_at;

                continue;
            }

            if (count($radky) >= 12) {
                break;
            }

            $data = json_decode((string) $z->payload, true) ?: [];
            $radky[] = ['z' => $z, 'n' => 1, 'soubor' => $data['filename'] ?? null];
            $predchozi = ['klic' => $klic, 'od' => $z->created_at];
        }

        $media = collect($radky)->filter(fn ($r) => str_starts_with($r['z']->action, 'media.') && $r['z']->subject_id)
            ->pluck('z.subject_id')->all();
        $snimky = $media ? DB::table('media_items')->whereIn('id', $media)->get(['id', 'uuid'])->keyBy('id') : collect();
        $this->zjistiNahledy(array_keys($snimky->all()));

        return array_map(function (array $r) use ($jmena, $popisy, $snimky, $dnes) {
            $z = $r['z'];
            $kdo = $jmena[(int) $z->user_id] ?? 'Někdo';
            $co = $popisy[$z->action];
            $text = $r['n'] > 1
                ? $kdo.' · '.$co.' ('.$this->pocet($r['n'], 'soubor', 'soubory', 'souborů').')'
                : $kdo.' · '.$co.($r['soubor'] ? ' '.$r['soubor'] : '');
            $snimek = $snimky[$z->subject_id] ?? null;

            return [
                mb_strtoupper(mb_substr($kdo, 0, 1)),
                $text,
                $this->kdy(CarbonImmutable::parse($z->created_at), $dnes),
                $snimek && $z->action !== 'media.purge' ? $this->nahled($snimek) : null,
            ];
        }, $radky);
    }

    // ——— pomůcky ———

    private function media(GallerySpace $prostor)
    {
        return DB::table('media_items')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNull('deleted_at')
            ->where('is_hidden', false);
    }

    /** „dnes ve 21:40", „včera v 8:12", „3. 9. ve 14:05". */
    private function kdy(CarbonImmutable $kdy, CarbonImmutable $dnes): string
    {
        $cas = (in_array($kdy->hour, [2, 3, 4, 12, 13, 14, 20, 21, 22, 23], true) ? 've ' : 'v ').$kdy->format('G:i');

        return match (true) {
            $kdy->isSameDay($dnes) => 'dnes '.$cas,
            $kdy->isSameDay($dnes->subDay()) => 'včera '.$cas,
            default => $kdy->day.'. '.$kdy->month.'.'.($kdy->year !== $dnes->year ? ' '.$kdy->year : '').' '.$cas,
        };
    }

    private function datum(CarbonImmutable $kdy): string
    {
        return $kdy->day.'. '.self::MESICE[$kdy->month].' '.$kdy->year;
    }

    private function delka(int $sekund): string
    {
        return intdiv($sekund, 60).':'.str_pad((string) ($sekund % 60), 2, '0', STR_PAD_LEFT);
    }

    private function castka(float $castka, string $mena): string
    {
        $znak = match (strtoupper($mena)) {
            'CZK' => 'Kč',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => strtoupper($mena),
        };

        return number_format($castka, 0, ',', "\u{00A0}").' '.$znak;
    }

    private function pocet(int $n, string $jedna, string $dve, string $pet): string
    {
        return $n.' '.($n === 1 ? $jedna : ($n >= 2 && $n <= 4 ? $dve : $pet));
    }

    private function nahled(object $f): string
    {
        $n = (int) $f->id;

        if (! ($this->nahledy[$n] ?? false)) {
            // Bez zmenšeniny týž přechod, jaký kreslí knihovna.
            $uhel = 130 + ($n % 5) * 12;
            $odstin = ($n * 47 + 12) % 360;
            $sytost = 16 + ($n % 5) * 5;

            return 'linear-gradient('.$uhel.'deg, hsl('.$odstin.' '.$sytost.'% 58%), hsl('.(($odstin + 34) % 360).' '.($sytost + 6).'% 30%))';
        }

        $adresa = URL::temporarySignedRoute('galerie.media.thumb', CarbonImmutable::tomorrow()->endOfDay(), ['uuid' => $f->uuid]);

        return "url('".$adresa."') center/cover no-repeat #2b2842";
    }

    /** @param  list<int|string>  $id */
    private function zjistiNahledy(array $id): void
    {
        $id = array_values(array_diff(array_map('intval', $id), array_keys($this->nahledy)));

        if (! $id || ! Schema::hasTable('media_variants')) {
            return;
        }

        $maji = DB::table('media_variants')
            ->whereIn('media_item_id', $id)
            ->whereIn('type', ['thumbnail', 'small', 'video_poster', 'original'])
            ->distinct()
            ->pluck('media_item_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        foreach ($id as $jeden) {
            $this->nahledy[$jeden] = in_array($jeden, $maji, true);
        }
    }
}
