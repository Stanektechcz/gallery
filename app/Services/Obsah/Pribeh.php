<?php

namespace App\Services\Obsah;

use App\Http\Controllers\Api\Galerie\TiskController;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Models\Transaction;
use App\Services\Auth\PristupDoGalerie;
use App\Support\Cas;
use App\Support\Meny;
use App\Support\SpaceContext;
use App\Support\Tabulky;
use App\Support\Trezor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
class Pribeh implements MaPrazdneKolekce, PoskytovatelObsahu
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
            'STORY' => [],
            'STORYMS' => [],
            'STORY_ROKY' => new \stdClass,
            // Kroky objednávky tisku se posílají jen s objednávkou; bez ní
            // nemá obrazovka co sledovat. `prazdne()` o nich dosud mlčelo.
            'POSTEPS' => [],
            'PORDERS' => [],
            'EM_ITEMS' => [],
            'EM_LOG' => [],
            'PAPER_ROWS' => [],
            'GV_C' => [],
            'RECON' => new \stdClass,
            'ABARS' => ['tier' => []],
            'AL' => ['films' => [], 'series' => [], 'watchlist' => [], 'story' => [], 'print' => [], 'orders' => []],
        ];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        return array_filter([
            'STORY' => $this->kapitoly($prostor),
            'STORYMS' => $this->milniky($prostor),
            'STORY_ROKY' => $this->rokyPribehu($prostor),
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
            /*
             * Vedle filmů ještě tři seznamy, které kreslila ukázka: kapitoly
             * příběhu, rozpracované tisky a odeslané objednávky. Všechny tři
             * mají tabulku, ze které se čte jinde na téže obrazovce — jen
             * `xRows` na ni nikdy nebylo napojené.
             */
            'AL' => $this->filmy($prostor)
                + array_filter([
                    'story' => $this->kapitolyDoSeznamu($prostor),
                    'print' => $this->tiskDoSeznamu($objednavky, false),
                    // Odeslané objednávky chodí i prázdné: ukázka na jejich
                    // místě tvrdí „doručeno 8. 1. 2026 · 1 190 Kč", tedy že
                    // dvojice zaplatila. Prázdný seznam je proti tomu poctivý.
                    'orders' => $this->tiskDoSeznamu($objednavky, true),
                ], fn (array $v, string $k) => $k === 'orders' || $v !== [], ARRAY_FILTER_USE_BOTH),
            'RECON' => $this->rekonstrukce($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Kapitoly příběhu jako seznam `[název, popis, štítek]`.
     *
     * Obrazovka je kreslila z ukázky — „Jak jsme se potkali · kapitola 1 ·
     * 12 fotek · 2016" u dvojice, která žádnou kapitolu nenapsala. Počet fotek
     * je týž, jaký ukazuje osa příběhu: z roku kapitoly, ne vymyšlený.
     *
     * @return list<array<int, string>>
     */
    private function kapitolyDoSeznamu(GallerySpace $prostor): array
    {
        $kapitoly = $this->kapitoly($prostor);

        if ($kapitoly === []) {
            return [];
        }

        $radky = [];

        foreach ($kapitoly as $i => $k) {
            [, $nazev, $rok, $stav, $fotek] = $k;

            $radky[] = [
                (string) $nazev,
                implode(' · ', array_filter([
                    'kapitola '.($i + 1),
                    $fotek > 0 ? $this->pocet((int) $fotek, 'fotka', 'fotky', 'fotek') : null,
                    $rok !== '' ? $rok : null,
                ])),
                $stav === 'hotovo' ? 'hotovo' : 'píše se',
            ];
        }

        return $radky;
    }

    /**
     * Tisk jako dva seznamy: rozpracované a odeslané.
     *
     * Dělí je `step`: nula znamená koncept, který si dvojice skládá a nikam
     * neodešla. Ukázka měla oba plné — „Fotokniha Chorvatsko 2026 · 42 stran"
     * a „doručeno 8. 1. 2026 · 1 190 Kč" — u dvojice, která si nic neobjednala.
     *
     * @param  list<array<int, mixed>>  $objednavky
     * @return list<array<int, string>>
     */
    private function tiskDoSeznamu(array $objednavky, bool $odeslane): array
    {
        $kroky = TiskController::KROKY;
        $radky = [];

        foreach ($objednavky as $o) {
            [, $nazev, $druh, $cena, $krok, $termin, $sledovani] = $o;
            $mena = (string) ($o[9] ?? 'CZK');

            if (((int) $krok > 0) !== $odeslane) {
                continue;
            }

            $radky[] = [
                (string) $nazev,
                implode(' · ', array_filter([
                    $this->druhTisku((string) $druh),
                    $odeslane ? ($kroky[(int) $krok] ?? null) : null,
                    $odeslane ? $termin : null,
                    (int) $cena > 0 ? $this->castka((int) $cena, $mena) : null,
                    $odeslane && $sledovani !== '' ? 'zásilka '.$sledovani : null,
                ])),
                $odeslane
                    ? ((int) $krok >= count($kroky) - 1 ? 'hotovo' : 'probíhá')
                    : 'koncept',
            ];
        }

        return $radky;
    }

    private function druhTisku(string $druh): string
    {
        return match ($druh) {
            'book', 'kniha' => 'fotokniha',
            'calendar', 'kalendar' => 'kalendář',
            'frame', 'ramecek' => 'rámeček',
            'cards', 'prani' => 'přání',
            'print', 'tisk' => 'tisk',
            default => $druh !== '' ? $druh : 'tisk',
        };
    }

    /**
     * Filmy, seriály a watchlist: `AL` po klíčích, jak je čte `rowsOf`.
     *
     * Řádek je `[název, popis, štítek, pásmo, {a, m}, dílů hotovo, dílů celkem]`.
     * První tři pole má i ukázka; zbytek prototyp bez nich nekreslí, takže
     * `galerie-data.js` se kvůli tomu nemění.
     *
     * Bez těch polí by obrazovka po každém načtení zapomněla, kdo co dal za
     * hvězdičky a kde se přestalo dívat — hodnocení totiž žije ve stavu
     * prohlížeče a stav se serverové klíče nedrží.
     *
     * @return array<string, list<array<int, mixed>>>
     */
    private function filmy(GallerySpace $prostor): array
    {
        if (! Tabulky::je('watch_titles')) {
            return [];
        }

        $tituly = DB::table('watch_titles')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($tituly->isEmpty()) {
            return [];
        }

        $hodnoceni = Tabulky::je('watch_title_ratings')
            ? DB::table('watch_title_ratings')->whereIn('watch_title_id', $tituly->pluck('id'))->get()->groupBy('watch_title_id')
            : collect();

        [$prvni, $druhy] = $this->dvojiceId($prostor);

        $seznamy = ['films' => [], 'series' => [], 'watchlist' => []];

        foreach ($tituly as $t) {
            $moje = ($hodnoceni[$t->id] ?? collect())->keyBy('user_id');
            $znamky = [
                'a' => (int) ($moje[$prvni]->rating ?? 0),
                'm' => (int) ($moje[$druhy]->rating ?? 0),
            ];

            $klic = $t->status === 'chceme' ? 'watchlist' : ($t->kind === 'seriál' ? 'series' : 'films');

            $seznamy[$klic][] = [
                $t->title,
                $this->popisTitulu($t, $znamky),
                (string) ($t->status ?? 'chceme'),
                (string) ($t->tier ?? '') ?: null,
                array_filter($znamky) ? $znamky : null,
                $t->episodes_done === null ? null : (int) $t->episodes_done,
                $t->episodes_total === null ? null : (int) $t->episodes_total,
                /*
                 * Identifikátor řádku podle titulu, ne podle pořadí.
                 *
                 * `films-3` bylo pořadí: přidal-li ten druhý titul nebo jeden
                 * smazal, hodnocení ze starší obrazovky dopadlo na jiný film
                 * a starší seznam titul zdvojil.
                 */
                $klic.'-'.$t->uuid,
            ];
        }

        return array_filter($seznamy);
    }

    /**
     * Dvojice jako čísla, ve stejném pořadí jako `DVOJICE`.
     *
     * Přihlášený první — obrazovka svoje hvězdičky kreslí vlevo a je to
     * `a`. Kdyby se pořadí lišilo, viděl by každý svoje hodnocení pod jménem
     * toho druhého.
     *
     * @return list<int|null>
     */
    private function dvojiceId(GallerySpace $prostor): array
    {
        // Jen dvojice: host, který přišel dřív než partner, měl na obrazovce
        // partnerovy hvězdičky. `FilmyVeStavu` zapisuje jen `a` (divák), takže
        // pořadí druhého místa zápis neovlivní.
        $lide = app(PristupDoGalerie::class)->dvojiceOdDivaka($prostor, auth()->user())
            ->map(fn ($clen) => (int) $clen->id)
            ->all();

        return [$lide[0] ?? null, $lide[1] ?? null];
    }

    /**
     * Popisek řádku: kde se přestalo dívat, nebo jak to dopadlo.
     *
     * Prototyp z něj čte postup i známku — `S1E7`, `9/10`, „na watchlistu".
     * Píše se proto tak, jak je zvyklý ho číst, ne jak by se to řeklo.
     *
     * @param  array{a: int, m: int}  $znamky
     */
    private function popisTitulu(object $t, array $znamky): string
    {
        if ($t->status === 'chceme') {
            return 'na watchlistu';
        }

        $casti = [];

        if ($t->episodes_done !== null && $t->episodes_total) {
            // Sérii aplikace nikde neeviduje — `watch_titles` má jen díly.
            // „S1E7" tvrdilo o každém seriálu, že jde o první řadu.
            $casti[] = (int) $t->episodes_done >= (int) $t->episodes_total
                ? 'dokoukáno · '.$this->pocet((int) $t->episodes_total, 'díl', 'díly', 'dílů')
                : $this->pocet((int) $t->episodes_done, 'díl', 'díly', 'dílů').' z '.(int) $t->episodes_total.' · sledujeme';
        } elseif ($t->status === 'probíhá') {
            $casti[] = 'sledujeme';
        } else {
            $casti[] = 'viděli jsme';
        }

        // Společná známka z desítky — průměr hvězdiček obou, ne jednoho.
        $dane = array_values(array_filter($znamky));

        if ($dane !== []) {
            $casti[] = round(array_sum($dane) / count($dane) * 2, 1).'/10';
        } elseif ($t->rating !== null) {
            $casti[] = ((int) $t->rating).'/10';
        }

        return implode(' · ', $casti);
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
        // Denní součty z databáze, ne celá knihovna v paměti. Při shodě
        // vyhrává novější den — „od nejnovějšího".
        $dny = collect($this->fotekPoDnech($prostor))
            ->sortKeysDesc()
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
            // Rekonstrukce dne vypisuje jména souborů a místa — u trezoru je
            // i jméno souboru obsah.
            ->where('is_hidden', false)
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

        if (Tabulky::je('transactions')) {
            // Jen skutečné platby: smazaný řádek ani směna či převod mezi
            // vlastními peněženkami nejsou krok dne („Směna na eura, 8 000 Kč").
            $platby = DB::table('transactions')
                ->where('gallery_space_id', $prostor->id)
                ->whereNull('deleted_at')
                ->whereIn('type', Transaction::VYSLEDKOVE)
                ->whereIn('state', Transaction::ZAPSANE)
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

        if (Tabulky::je('journal_entries')) {
            // Stejné pravidlo jako Dnes: soukromý zápis druhého ani smazaný
            // zápis do rekonstrukce nepatří — jeho název je obsah deníku.
            $zapisy = $this->viditelneZapisy($prostor)
                ->whereDate('entry_date', $den->toDateString())
                ->get(['title', 'created_at']);

            foreach ($zapisy as $z) {
                $kroky[] = [
                    'cas' => Cas::mistni($z->created_at)->format('G:i'),
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

            /*
             * Mezera mezi snímky se měří odspoda nahoru.
             *
             * Carbon 3 vrací rozdíl **se znaménkem**: pozdější okamžik proti
             * dřívějšímu dá zápor. Fotky sem chodí seřazené podle času, takže
             * `$kdy->diffInMinutes($aktualni['do'])` bylo vždycky ≤ 0 a shluk
             * nikdy nerozdělil čas — jen změna místa. Celý den se tím slil do
             * jednoho „momentu" a rekonstrukce dne pak neměla dost kroků, aby
             * vůbec vznikla.
             */
            $novy = $aktualni === null
                || $misto !== $aktualni['misto']
                || $aktualni['do']->diffInMinutes($kdy) > 30;

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
            // Jednotné číslo bylo ve všech třech tvarech v druhém pádu:
            // „1 shluku fotek, 1 platby, 1 zápisu".
            isset($podle['fotky']) ? $this->pocet($podle['fotky'], 'shluk fotek', 'shluky fotek', 'shluků fotek') : null,
            isset($podle['transakce']) ? $this->pocet($podle['transakce'], 'platba', 'platby', 'plateb') : null,
            isset($podle['denik']) ? $this->pocet($podle['denik'], 'zápis', 'zápisy', 'zápisů') : null,
        ]));
    }

    /** Příběh vypráví, kolik se dalo — bez znaménka; zápis částky je společný (`Meny`). */
    private function castka(float $castka, string $mena): string
    {
        return Meny::castka(abs($castka), $mena);
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
        if (! Tabulky::je('couple_story_chapters')) {
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
     * Milníky: `[id, rok, datum, název, poznámka, ikona, kapitola, den]`.
     *
     * Poslední pole je den jako datum. Obrazovka kreslí `4. dubna 2026`,
     * ale zpátky posílá to, co dostala — a z textu se den v tabulce dá
     * přečíst jen hádáním. `galerie-data.js` ho nemá; prototyp na něj
     * nesahá, takže ukázce nevadí, že chybí.
     *
     * @return list<array<int, mixed>>
     */
    private function milniky(GallerySpace $prostor): array
    {
        if (! Tabulky::je('couple_story_milestones')) {
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
                    $kdy->format('Y-m-d'),
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
        if (! Tabulky::je('print_orders')) {
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
                // Měna je desátá, tedy za vším, co prototyp čte podle indexu —
                // ten se nemění. Potřebuje ji seznam „Tisk a fotoknihy": cena
                // bez měny je u objednávky z ciziny jen číslo.
                (string) ($o->currency ?: 'CZK'),
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
        if (! Tabulky::je('emergency_access_items')) {
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
        if (! Tabulky::je('emergency_access_log')) {
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
        if (! Tabulky::je('paper_backup_rows')) {
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
        if (! Tabulky::je('guest_comments')) {
            return [];
        }

        $trezor = Trezor::odemcen();

        return DB::table('guest_comments as k')
            ->leftJoin('shared_links as o', 'o.id', '=', 'k.shared_link_id')
            ->leftJoin('media_items as m', 'm.id', '=', 'k.media_item_id')
            ->where('k.gallery_space_id', $prostor->id)
            ->orderByDesc('k.created_at')
            ->limit(60)
            ->get([
                'k.uuid', 'k.guest_name', 'k.body', 'k.kind', 'k.duration', 'k.audio_path',
                'k.is_hidden', 'k.is_pinned', 'k.created_at',
                'o.name as odkaz', 'm.original_filename as fotka', 'm.is_hidden as fotka_skryta',
                'm.trashed_at as fotka_v_kosi',
            ])
            ->map(fn (object $k) => array_filter([
                'id' => $k->uuid,
                'who' => $k->guest_name,
                'text' => $k->body,
                'when' => $this->kdy(CarbonImmutable::parse($k->created_at)),
                'share' => (string) ($k->odkaz ?? ''),
                'hidden' => (bool) $k->is_hidden,
                'kind' => $k->kind === 'voice' ? 'voice' : null,
                // Fotka z trezoru se zamčeným trezorem se tu nejmenuje — jinak
                // by vzkazy hostů prozradily název souboru, který koš i knihovna
                // za zamčeným trezorem schovávají.
                'photo' => ($k->fotka_v_kosi === null && (! $k->fotka_skryta || $trezor)) ? $k->fotka : null,
                'len' => $k->duration,
                // Bez adresy je hlasovka řádek, který tvrdí, že babička něco
                // řekla, a nejde si to poslechnout.
                // Podepsaná — veřejná adresa /storage by hlasovku vydala komukoli.
                'audio' => $k->audio_path ? MediaVariant::proxyUrl($k->audio_path) : null,
                'pinned' => (bool) $k->is_pinned,
            ], fn ($v) => $v !== null))
            // Šedesát nejnovějších, ale v pořadí, jak přicházely — obrazovka
            // bere poslední prvek jako nejnovější vzkaz („Zobrazit jako host").
            ->reverse()
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
        if (! Tabulky::je('watch_titles')) {
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
        // Z denních součtů, ne z celé knihovny: dřív se při každém načtení
        // příběhu natáhly do paměti všechny fotky, jen aby se spočítaly.
        $roky = [];

        foreach ($this->fotekPoDnech($prostor) as $den => $pocet) {
            $rok = substr($den, 0, 4);
            $roky[$rok] = ($roky[$rok] ?? 0) + $pocet;
        }

        return $roky;
    }

    /**
     * Kolik fotek je z kterého dne: `{ 'Y-m-d': počet }`, sečtené v databázi.
     *
     * Viditelné fotky s časem pořízení — bez koše a bez trezoru. `DATE()` platí
     * v MySQL i SQLite a bere uložený čas stejně jako dřívější
     * `CarbonImmutable::parse($m->taken_at)`.
     *
     * @return array<string, int>
     */
    private function fotekPoDnech(GallerySpace $prostor): array
    {
        return $this->viditelneFotky($prostor)
            ->selectRaw('DATE(taken_at) as den, COUNT(*) as pocet')
            ->groupBy('den')
            ->orderBy('den')
            ->pluck('pocet', 'den')
            ->mapWithKeys(fn ($pocet, $den) => [substr((string) $den, 0, 10) => (int) $pocet])
            ->all();
    }

    /** Fotky, ze kterých příběh počítá — základ dotazu bez sloupců. */
    private function viditelneFotky(GallerySpace $prostor): Builder
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->whereNotNull('taken_at')
            ->toBase();
    }

    /**
     * Souhrn každého roku pro návrh kapitoly: `{ rok: { fotek, mista, den, zapisu, cest } }`.
     *
     * Tlačítko „Složit návrh" psalo pořád tutéž větu o 412 fotkách z Ostravy,
     * Prahy a Beskyd. Prohlížeč to sám spočítat nemůže — knihovna mu posílá
     * jen posledních pár set fotek, takže by rok vyšel poloviční. Počty jsou
     * proto odsud, přes všechny fotky.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rokyPribehu(GallerySpace $prostor): array
    {
        /*
         * Počty sčítá databáze, ne PHP.
         *
         * Dřív se při každém načtení příběhu natáhla celá knihovna (čas
         * a místo každé fotky) jen proto, aby se spočítala. Teď chodí jeden
         * řádek za den a jeden za dvojici rok–místo.
         */
        $dny = collect($this->fotekPoDnech($prostor));

        if ($dny->isEmpty()) {
            return [];
        }

        $mista = $this->viditelneFotky($prostor)
            ->whereNotNull('location_name')
            ->whereRaw("TRIM(location_name) <> ''")
            ->selectRaw('SUBSTR(DATE(taken_at), 1, 4) as rok, location_name, COUNT(*) as pocet')
            ->groupBy('rok', 'location_name')
            ->get()
            ->groupBy(fn (object $r) => (string) (int) $r->rok);

        $zapisy = $this->zapisyPoRocich($prostor);
        $cesty = Tabulky::je('trips')
            ? DB::table('trips')->where('gallery_space_id', $prostor->id)->get(['start_date', 'end_date'])
            : collect();

        return $dny
            ->groupBy(fn (int $pocet, string $den) => (string) (int) substr($den, 0, 4), true)
            ->sortKeys()
            ->map(function (Collection $dnyRoku, string $rok) use ($mista, $zapisy, $cesty) {
                // Nejplodnější den; při shodě ten dřívější, ať je výsledek pevný.
                $den = $dnyRoku->sortKeys()->sortDesc()->keys()->first();
                $kdy = CarbonImmutable::parse($den);

                return [
                    'fotek' => (int) $dnyRoku->sum(),
                    'mista' => ($mista[$rok] ?? collect())
                        ->sortBy([['pocet', 'desc'], ['location_name', 'asc']])
                        ->pluck('location_name')
                        ->take(3)
                        ->values()
                        ->all(),
                    'den' => $kdy->day.'. '.self::MESICE[$kdy->month],
                    'zapisu' => (int) ($zapisy[$rok] ?? 0),
                    // Cesta přes Silvestra patří do obou let.
                    'cest' => $cesty->filter(fn (object $c) => (int) substr((string) $c->start_date, 0, 4) <= (int) $rok
                        && (int) substr((string) $c->end_date, 0, 4) >= (int) $rok)->count(),
                ];
            })
            ->all();
    }

    /** @return array<string, int> */
    private function zapisyPoRocich(GallerySpace $prostor): array
    {
        if (! Tabulky::je('journal_entries')) {
            return [];
        }

        // Jen zápisy, které divák smí číst — soukromý zápis druhého ani smazaný
        // se do „kolik jsme toho roku napsali" nepočítá. Sečte to databáze:
        // `SUBSTR(DATE(…))` platí v MySQL i SQLite (na rozdíl od `strftime`).
        return $this->viditelneZapisy($prostor)
            ->whereNotNull('entry_date')
            ->selectRaw('SUBSTR(DATE(entry_date), 1, 4) as rok, COUNT(*) as pocet')
            ->groupBy('rok')
            ->pluck('pocet', 'rok')
            ->map(fn ($pocet) => (int) $pocet)
            ->mapWithKeys(fn (int $pocet, $rok) => [(string) (int) $rok => $pocet])
            ->all();
    }

    /**
     * Zápisy deníku, které přihlášený smí vidět — stejné pravidlo jako Dnes.
     *
     * Sdílené všech, soukromé jen vlastní; smazané nikdy. Bez přihlášeného
     * (konzole) jen sdílené.
     */
    private function viditelneZapisy(GallerySpace $prostor): Builder
    {
        $ja = auth()->id();

        return DB::table('journal_entries')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('visibility', '!=', 'private')
                ->when($ja !== null, fn ($v) => $v->orWhere('created_by', $ja)));
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
        // Okamžik (kapsle založena) v pásmu dvojice — viz App\Support\Cas.
        $kdy = Cas::mistni($kdy);

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
