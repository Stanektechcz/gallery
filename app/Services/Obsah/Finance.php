<?php

namespace App\Services\Obsah;

use App\Models\Budget;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Finance\LedgerService;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance ve tvaru, ve kterém je kreslí prototyp.
 *
 * Obrazovky Přehled, Transakce, Rozpočty a Účty čtou `TX`, `BUD`, `FIN`, `INCOMES`
 * a `SHARED`. Dosud pocházely z ukázkových dat, takže dvojice viděla cizí nákupy
 * místo svých — a **Makinčin rozpočet na Německo, který v aplikaci je, se v nich
 * neobjevil vůbec**.
 *
 * Tvary jsou dané prototypem a nemění se: `TX` je pole na devíti pozicích,
 * `BUD.cats` na šesti. Přizpůsobuje se obsah, ne obrazovka.
 */
class Finance implements PoskytovatelObsahu
{
    /** Kolik transakcí se posílá. Obrazovka jich ukáže dvacítky, ne tisíce. */
    private const TRANSAKCI = 120;

    /**
     * Do téhle priority je položka „nedotknutelná".
     *
     * Priorita se v aplikaci řadí vzestupně — nižší číslo dostane peníze první.
     * Nájem má 10, volný čas 90.
     */
    private const PEVNE = 10;

    public function __construct(private readonly LedgerService $kniha) {}

    public function skupina(): string
    {
        return 'finance';
    }

    /**
     * `FIN` má vedle účtů ještě splátky, upozornění, pravidla a importy, které
     * se počítají jinde; `BUD` zase části, které aplikace nevede. Smazat je
     * znamená prázdné obrazovky, ne pravdu.
     *
     * `ATX` je opačný případ: čtyři záložky téže knihy. Nechat mezi nimi jednu
     * ukázkovou znamená, že „Opakované" ukazují platby, které dvojice nemá.
     */
    public function uplne(): array
    {
        return ['ATX'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $rozpocet = $this->rozpocet($prostor);
        $penezenky = $this->penezenky($prostor);
        $pohyby = $this->pohyby($prostor);

        /*
         * Buď finance, nebo ukázka — nic mezi tím.
         *
         * Kdyby se posílala jen neprázdná kolekce, vypadalo by to takhle: rozpočet
         * skutečný, ale pod ním dvacet vymyšlených nákupů, protože kniha je zatím
         * prázdná. Jakmile má dvojice finance založené, posílá se všechno —
         * i prázdný seznam transakcí, protože „zatím nic" je pravda, kdežto cizí
         * nákupy jsou lež.
         */
        if ($rozpocet === null && $penezenky->isEmpty() && $pohyby->isEmpty()) {
            return [];
        }

        $mena = $rozpocet?->currency ?: 'CZK';
        $bud = $rozpocet ? $this->rozpocetVen($prostor, $rozpocet) : null;

        // Co spočítat nejde, se **neposílá** — ne posílá jako `null`. Prototyp by
        // takovou kolekci nechal ukázkovou tak jako tak, jen by o tom mlčel.
        return array_filter([
            'TX' => $this->transakce($pohyby),
            /*
             * Tytéž pohyby ve čtyřech záložkách obrazovky Transakce.
             *
             * Prázdné `ATX` se **neposílá**: je to úplná kolekce, takže by
             * u klienta smazala i ukázkové záložky — a obrazovka, která čte
             * `ATX[key] || ATX.all` bez pojistky, by spadla na `undefined.rows`.
             */
            'ATX' => $this->zalozkyTransakci($prostor, $pohyby, $mena) ?: null,
            // A tentýž rozpočet ve sloupcích. Druhý výpočet by dřív nebo později
            // ukázal na dvou záložkách dvě různá čísla o téže kategorii.
            'ABARS' => ($bud ? $this->sloupceRozpoctu($bud, $mena) : []) ?: null,
            'BUD' => $bud,
            /*
             * Názvy kategorií pro výběry u transakcí.
             *
             * Prototyp je měl napsané v souboru s ukázkovými daty, takže dvojici,
             * která si kategorie přejmenovala nebo přidala, nabízel k zařazení
             * cizí jména — a zapsané zařazení pak mířilo na kategorii, kterou
             * v účetnictví nemá.
             */
            'TXCATS' => $this->nazvyKategorii($prostor),
            'FIN' => ['accounts' => $ucty = $this->ucty($prostor, $penezenky)],
            /*
             * Tytéž účty jako seznam.
             *
             * Záložka „Účty a napojení" je kreslí ještě jednou a brala je
             * z `galerie-data.js`: vedle skutečné peněženky stál „Revolut ·
             * Adrian" a „ČSOB · Makinka" někoho cizího, u toho „sync dnes
             * 8:14" jako by se opravdu synchronizovalo.
             */
            'AL' => array_filter([
                'accounts' => array_map(
                    fn (array $u) => [$u[0], trim($u[1].' · '.$u[5]), $u[4]],
                    $ucty,
                ),
                /*
                 * Vyrovnání mezi rozpočty.
                 *
                 * Záložka „Vyrovnávání" měla tři napsané řádky („Restaurace
                 * do Potravin · vyrovnáno 500 Kč") a jedno „pravidlo", které
                 * nikde neexistovalo. Vyrovnání se přitom zapisují do
                 * `budget_settlements` a jde o peníze mezi dvěma lidmi —
                 * tam je vymyšlený řádek obzvlášť špatný nápad.
                 */
                'balancing' => $this->vyrovnani($prostor),
                // `balancing` chodí i prázdný: prázdný stav pro něj prototyp
                // nemá, ale ukázka na jeho místě tvrdí, že se mezi rozpočty
                // přesouvaly peníze. Prázdný seznam je proti tomu poctivý.
            ], fn (array $v, string $k) => $k === 'balancing' || $v !== [], ARRAY_FILTER_USE_BOTH),
            // Totéž číslo jako v hlavičce rozpočtu — dvě různá by si na dvou
            // obrazovkách protiřečila.
            'INCOMES' => $rozpocet ? (int) round($this->mesicniPrijem($rozpocet)) : 0,
            'SHARED' => $this->pevneNaklady($rozpocet),
            /*
             * Měna, ve které se kreslí částky.
             *
             * Prototyp měl v jediném formátovači natvrdo „Kč". Makinčin rozpočet
             * na Německo je v eurech, takže by obrazovka u každé částky psala
             * měnu, ve které rozpočet není — a rozhodovalo by se podle toho.
             */
            'MENA' => $this->znakMeny($rozpocet?->currency ?: 'CZK'),
        ], fn ($v) => $v !== null);
    }

    // ——— transakce ———

    /** @return Collection<int, Transaction> */
    private function pohyby(GallerySpace $prostor): Collection
    {
        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->with(['category:id,name,icon', 'walletFrom:id,name', 'walletTo:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(self::TRANSAKCI)
            ->get();
    }

    /**
     * `[id, datum, popis, kategorie, částka, účet, ikona, meta, poznámka]`
     *
     * Záporná částka je výdaj — tak to prototyp kreslí a podle znaménka volí barvu.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return list<array<int, mixed>>
     */
    private function transakce(Collection $pohyby): array
    {
        return $pohyby->map(function (Transaction $t) {
            $vydaj = $t->type !== 'income';
            $castka = (float) ($t->amount_from ?? $t->amount_to ?? 0);

            return [
                $t->uuid,
                $this->den($t->occurred_at ?? $t->booked_on),
                $t->description ?: ($t->counterparty ?: 'Bez popisu'),
                $t->category?->name ?? 'Nezařazeno',
                (int) round($vydaj ? -abs($castka) : abs($castka)),
                $t->walletFrom?->name ?? $t->walletTo?->name ?? '—',
                $this->ikona($t->category?->icon),
                // Meta drží prototyp jako volný objekt; účtenka je jediné, co čte.
                (object) array_filter(['receipt' => $t->receipt_media_id ? 1 : null]),
                // Devátá pozice je volná poznámka. Kniha pro ni sloupec nemá,
                // takže se posílá místo, kde se platilo — nebo nic.
                (string) ($t->place ?? ''),
            ];
        })->values()->all();
    }

    /**
     * Záložky obrazovky Transakce: `{ all, un, rec, imp }`.
     *
     * Každá je `{ rows: [[den, popis, kategorie, částka]], foot, sum }`. Jsou to
     * tytéž pohyby jako v `TX`, jen pohledy na ně — proto se počítají z už
     * načtené knihy a ne čtyřmi dalšími dotazy.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<string, array<string, mixed>>
     */
    private function zalozkyTransakci(GallerySpace $prostor, Collection $pohyby, string $mena): array
    {
        if ($pohyby->isEmpty()) {
            return [];
        }

        $nezarazene = $pohyby->filter(fn (Transaction $t) => $t->category_id === null);
        $opakovane = $pohyby->filter(fn (Transaction $t) => $t->recurring_id !== null);
        $importovane = $pohyby->filter(fn (Transaction $t) => (string) $t->provider !== '');

        $mesic = $this->mesic(CarbonImmutable::parse($pohyby->first()->occurred_at ?? now()));

        /*
         * Všechny čtyři záložky se posílají i prázdné.
         *
         * Kdyby se prázdná vynechala, prototyp by na ni sáhl přes `ATX[key] ||
         * ATX.all` a v „Opakovaných" by se objevily úplně všechny transakce.
         * A nechat tam ukázku znamená poslat dvojici hledat platbu, kterou nemá.
         */
        return [
            'all' => $this->zalozka($pohyby, $mena,
                $this->pocet($pohyby->count(), 'transakce', 'transakce', 'transakcí').' · '.$mesic,
                'Zatím žádné transakce'),

            'un' => $this->zalozka($nezarazene, $mena,
                $this->pocet($nezarazene->count(), 'nezařazená transakce', 'nezařazené transakce', 'nezařazených transakcí').' — zařaďte je',
                'Všechno je zařazené'),

            'rec' => $this->zalozka($opakovane, $mena,
                $this->pocet($opakovane->count(), 'opakovaná platba', 'opakované platby', 'opakovaných plateb'),
                'Žádná opakovaná platba'),

            'imp' => $this->zalozka($importovane, $mena,
                $this->pocet($importovane->count(), 'importovaná', 'importované', 'importovaných').$this->kdySync($prostor),
                'Zatím nic naimportováno'),
        ];
    }

    /**
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<string, mixed>
     */
    private function zalozka(Collection $pohyby, string $mena, string $popisek, string $prazdno): array
    {
        if ($pohyby->isEmpty()) {
            return ['rows' => [], 'foot' => $prazdno, 'sum' => ''];
        }

        $soucet = $pohyby->sum(fn (Transaction $t) => $this->podepsana($t));

        return [
            'rows' => $pohyby->map(fn (Transaction $t) => [
                $this->den($t->occurred_at ?? $t->booked_on),
                $t->description ?: ($t->counterparty ?: 'Bez popisu'),
                $this->popisKategorie($t),
                $this->sCznamenkem($this->podepsana($t), $mena),
            ])->values()->all(),
            'foot' => $popisek,
            'sum' => $this->sCznamenkem($soucet, $mena),
        ];
    }

    /**
     * Kategorie tak, jak ji čeká obrazovka: u opakovaných a importovaných
     * s tím, odkud pocházejí.
     */
    private function popisKategorie(Transaction $t): string
    {
        $nazev = $t->category?->name ?? 'Nezařazeno';

        if ((string) $t->provider !== '') {
            return $t->provider.' · import';
        }

        return $t->recurring_id !== null ? $nazev.' · měsíčně' : $nazev;
    }

    private function podepsana(Transaction $t): float
    {
        $castka = (float) ($t->amount_from ?? $t->amount_to ?? 0);

        return $t->type === 'income' ? abs($castka) : -abs($castka);
    }

    /** Částka se znaménkem — prototyp podle prvního znaku volí barvu i řazení. */
    private function sCznamenkem(float $castka, string $mena): string
    {
        $znak = $castka < 0 ? '−' : '+';

        return $znak.$this->castka(abs($castka), $mena);
    }

    /** „ · sync dnes 8:14" — nebo nic, když se ještě nesynchronizovalo. */
    private function kdySync(GallerySpace $prostor): string
    {
        $kdy = Schema::hasTable('bank_connections')
            ? DB::table('bank_connections')
                ->where('gallery_space_id', $prostor->id)
                ->max('last_synced_at')
            : null;

        if (! $kdy) {
            return '';
        }

        $kdy = CarbonImmutable::parse($kdy);

        return ' · sync '.($kdy->isToday() ? 'dnes' : $kdy->format('j. n.')).' '.$kdy->format('G:i');
    }

    /**
     * Sloupce rozpočtu: `{ bud, year, res, fc }`, řádek `[popisek, údaj, %, barva]`.
     *
     * Barva 1 je varovná, 2 tlumená, 0 základní — tak to prototyp kreslí.
     *
     * Ostatní klíče (`tier`, `health`, `cycle`, …) patří jiným obrazovkám a
     * zůstávají, jak jsou: server tady odpovídá za peníze, ne za tierlisty.
     *
     * @param  array<string, mixed>  $bud
     * @return array<string, list<array{0: string, 1: string, 2: int, 3: int}>>
     */
    private function sloupceRozpoctu(array $bud, string $mena): array
    {
        $kategorie = $bud['cats'] ?? [];

        if (! $kategorie) {
            return [];
        }

        return array_filter([
            'bud' => $this->sloupceKategorii($kategorie, $mena),
            'year' => $this->sloupceCtvrtleti($bud['months'] ?? [], $mena),
            'res' => $this->sloupceVyhrazenych($kategorie, $bud, $mena),
            'fc' => $this->sloupcePredpovedi($kategorie, $bud, $mena),
        ], fn ($v) => $v !== []);
    }

    /**
     * @param  list<array<int, mixed>>  $kategorie
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function sloupceKategorii(array $kategorie, string $mena): array
    {
        return array_map(function (array $k) use ($mena) {
            $plan = (int) $k[1];
            $utraceno = (int) $k[2];
            $pomer = $plan > 0 ? (int) round($utraceno / $plan * 100) : 0;

            return [
                (string) $k[0],
                $plan > 0
                    ? number_format($utraceno, 0, ',', ' ').' '.$this->zNeboZe($plan).' '.$this->castka($plan, $mena)
                    : $this->castka($utraceno, $mena).' · bez limitu',
                $pomer,
                // Varovně jen to, co je za hranou nebo těsně před ní.
                $pomer >= 95 ? 1 : ($pomer <= 35 ? 2 : 0),
            ];
        }, $kategorie);
    }

    /**
     * Rok po čtvrtletích. Poslední, ve kterém se ještě utrácí, je plán.
     *
     * @param  list<array{0: string, 1: int}>  $mesice
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function sloupceCtvrtleti(array $mesice, string $mena): array
    {
        if (count($mesice) < 12) {
            return [];
        }

        $ctvrtleti = ['Leden – březen', 'Duben – červen', 'Červenec – září', 'Říjen – prosinec'];
        $rok = CarbonImmutable::today()->year;
        $ted = (int) ceil(CarbonImmutable::today()->month / 3);

        // `mesice` jde dvanáct měsíců zpět; pro rok se berou ty z letoška.
        $letos = [];
        foreach ($mesice as $i => $m) {
            $kdy = CarbonImmutable::today()->startOfMonth()->subMonths(11 - $i);
            if ($kdy->year === $rok) {
                $letos[(int) ceil($kdy->month / 3)] = ($letos[(int) ceil($kdy->month / 3)] ?? 0) + (int) $m[1];
            }
        }

        if (! $letos) {
            return [];
        }

        $nejvic = max(array_map('abs', $letos)) ?: 1;
        $radky = [];

        foreach ($ctvrtleti as $i => $nazev) {
            $q = $i + 1;

            if (! isset($letos[$q])) {
                continue;
            }

            $radky[] = [
                $nazev,
                $this->castka($letos[$q], $mena),
                (int) round(abs($letos[$q]) / $nejvic * 100),
                $q === $ted ? 1 : ($q > $ted ? 2 : 0),
            ];
        }

        return $radky;
    }

    /**
     * Vyhrazené částky: nedotknutelné, ostatní plán a co z příjmu zbývá volné.
     *
     * @param  list<array<int, mixed>>  $kategorie
     * @param  array<string, mixed>  $bud
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function sloupceVyhrazenych(array $kategorie, array $bud, string $mena): array
    {
        $prijem = (int) ($bud['income'] ?? 0);

        if ($prijem <= 0) {
            return [];
        }

        $pevne = array_filter($kategorie, fn (array $k) => ($k[5] ?? null) === 'nedotknutelné');
        $volitelne = array_filter($kategorie, fn (array $k) => ($k[5] ?? null) !== 'nedotknutelné');

        $soucet = fn (array $list) => array_sum(array_map(fn (array $k) => (int) $k[1], $list));
        $radky = [];

        foreach ($pevne as $k) {
            $radky[] = [
                'Nedotknutelné · '.mb_strtolower((string) $k[0]),
                $this->castka((int) $k[1], $mena),
                (int) round(min(100, (int) $k[1] / $prijem * 100)),
                2,
            ];
        }

        if ($volitelne) {
            $radky[] = [
                'Vyhrazeno · zbytek plánu',
                $this->castka($soucet($volitelne), $mena),
                (int) round(min(100, $soucet($volitelne) / $prijem * 100)),
                0,
            ];
        }

        $volne = $prijem - $soucet($kategorie);

        $radky[] = [
            'Volné',
            $this->castka($volne, $mena),
            (int) round(max(0, min(100, $volne / $prijem * 100))),
            $volne < 0 ? 1 : 0,
        ];

        return $radky;
    }

    /**
     * Předpověď čerpání: kolik zbývá do konce měsíce a kdo utrácí rychleji, než
     * měsíc ubíhá.
     *
     * @param  list<array<int, mixed>>  $kategorie
     * @param  array<string, mixed>  $bud
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function sloupcePredpovedi(array $kategorie, array $bud, string $mena): array
    {
        $den = (int) ($bud['today'] ?? 0);
        $dni = (int) ($bud['days'] ?? 0);

        if ($den <= 0 || $dni <= 0) {
            return [];
        }

        $tempo = $den / $dni;
        $plan = array_sum(array_map(fn (array $k) => (int) $k[1], $kategorie));
        $utraceno = array_sum(array_map(fn (array $k) => (int) $k[2], $kategorie));
        $zbyva = $plan - $utraceno;
        $zbyvaDni = $dni - $den;

        $rychleji = [];
        $vPlanu = [];
        $sRezervou = [];

        foreach ($kategorie as $k) {
            if ((int) $k[1] <= 0) {
                continue;
            }

            $pomer = (int) $k[2] / (int) $k[1];

            // Tempo je, kolik měsíce uběhlo. Kdo je nad ním, utrácí rychleji;
            // kdo je pod polovinou, má rezervu.
            if ($pomer > $tempo + 0.1) {
                $rychleji[] = (string) $k[0];
            } elseif ($pomer < $tempo / 2) {
                $sRezervou[] = (string) $k[0];
            } else {
                $vPlanu[] = (string) $k[0];
            }
        }

        return array_values(array_filter([
            [
                'Do konce měsíce zbývá',
                $this->castka($zbyva, $mena).' na '.$this->pocet($zbyvaDni, 'den', 'dny', 'dní'),
                (int) round(max(0, min(100, $plan > 0 ? $utraceno / $plan * 100 : 0))),
                $zbyva < 0 ? 1 : 0,
            ],
            $rychleji ? ['Čerpáno rychleji než plán', implode(', ', array_slice($rychleji, 0, 3)), 99, 1] : null,
            $vPlanu ? ['V plánu', implode(', ', array_slice($vPlanu, 0, 3)), 60, 0] : null,
            $sRezervou ? ['S rezervou', implode(', ', array_slice($sRezervou, 0, 3)), 32, 2] : null,
        ], fn ($v) => $v !== null));
    }

    // ——— rozpočet ———

    private function rozpocet(GallerySpace $prostor): ?Budget
    {
        return Budget::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            // Běžící rozpočet má přednost před tím, co skončilo nebo teprve začne.
            ->orderByRaw('CASE WHEN starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?) THEN 0 ELSE 1 END', [now(), now()])
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * `{ month, today, days, income, plan, surplus, cats: [[název, plán, utraceno, ikona, poznámka, štítek]] }`
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<string, mixed>
     */
    private function rozpocetVen(GallerySpace $prostor, Budget $rozpocet): array
    {
        $dnes = CarbonImmutable::today();
        $limity = $this->limity($rozpocet);
        $mena = $rozpocet->currency ?: 'CZK';

        /*
         * Limity jsou za celé období, obrazovka je měsíční.
         *
         * Makinčin rozpočet má u ubytování 1 680 € — šest měsíců po 280. Ukázat to
         * jako měsíční limit by znamenalo tvrdit, že na nájem má šestkrát víc,
         * než má. Dělí se proto počtem měsíců, které rozpočet pokrývá.
         */
        $mesicu = max(1, $rozpocet->monthsCovered());
        $naMesic = fn (float $castka) => $castka / $mesicu;

        // Utraceno se počítá z knihy, ne z vlastních položek rozpočtu — jinak by
        // obrazovka ukazovala plán, který nikdo neporovnal se skutečností.
        $utraceno = $this->utracenoPoKategoriich($prostor, $dnes->startOfMonth(), $dnes->endOfMonth());

        $prijem = $this->mesicniPrijem($rozpocet);
        $plan = $naMesic((float) $limity->sum('amount'));

        return [
            'month' => $this->mesic($dnes),
            'today' => $dnes->day,
            'days' => $dnes->daysInMonth,
            'income' => (int) round($prijem),
            'plan' => (int) round($plan),
            'surplus' => (int) round($prijem - $plan),
            'cats' => $limity->map(function (object $limit) use ($utraceno, $mena, $naMesic) {
                $skutecnost = (float) ($utraceno[$limit->finance_category_id] ?? 0);
                $limitMesicne = $naMesic((float) $limit->amount);

                return [
                    $limit->nazev ?? 'Nezařazeno',
                    (int) round($limitMesicne),
                    (int) round($skutecnost),
                    $this->ikona($limit->ikona),
                    $this->poznamkaKategorie($limitMesicne, $skutecnost, $mena),
                    // Priorita se v aplikaci řadí **vzestupně**: co má nižší číslo,
                    // dostane peníze první a shazuje se poslední. Nedotknutelné je
                    // tedy to s nejnižší, ne s nejvyšší — obráceně by obrazovka
                    // označila za jisté zrovna to, co odpadne první.
                    (int) ($limit->priority ?? 100) <= self::PEVNE ? 'nedotknutelné' : null,
                ];
            })->values()->all(),
            'months' => $this->mesice($prostor, $mena),
            'year' => $this->rok($prostor, $rozpocet),
            'goals' => $this->cile($rozpocet),
            'yearCats' => [],
        ];
    }

    /**
     * Utraceno po měsících za posledních dvanáct měsíců.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function mesice(GallerySpace $prostor, string $mena): array
    {
        $od = CarbonImmutable::today()->startOfMonth()->subMonths(11);

        $soucty = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('occurred_at', '>=', $od)
            ->get(['occurred_at', 'amount_from'])
            ->groupBy(fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at)->format('Y-m'))
            ->map(fn (Collection $skupina) => (float) $skupina->sum(fn (Transaction $t) => abs((float) $t->amount_from)));

        $zkratky = [1 => 'led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'];

        return collect(range(0, 11))
            ->map(function (int $i) use ($od, $soucty, $zkratky) {
                $mesic = $od->addMonths($i);

                return [
                    $zkratky[$mesic->month].' '.$mesic->format('y'),
                    (int) round((float) ($soucty[$mesic->format('Y-m')] ?? 0)),
                ];
            })
            ->all();
    }

    /** @return array{income: int, spent: int, saved: int} */
    private function rok(GallerySpace $prostor, Budget $rozpocet): array
    {
        $od = CarbonImmutable::today()->startOfYear();

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('occurred_at', '>=', $od)
            ->get(['type', 'amount_from', 'amount_to']);

        $prijem = (float) $pohyby->where('type', 'income')->sum(fn (Transaction $t) => abs((float) ($t->amount_to ?? $t->amount_from)));
        $vydaj = (float) $pohyby->where('type', '!=', 'income')->sum(fn (Transaction $t) => abs((float) $t->amount_from));

        return [
            'income' => (int) round($prijem),
            'spent' => (int) round($vydaj),
            'saved' => (int) round($prijem - $vydaj),
        ];
    }

    /**
     * Cíle spoření: `[název, cíl, ušetřeno, měsíčně, termín, poznámka, odkaz]`
     *
     * @return list<array<int, mixed>>
     */
    private function cile(Budget $rozpocet): array
    {
        return $rozpocet->goals()->get()->map(function ($cil) {
            $zbyva = max(0.0, (float) $cil->target_amount - (float) $cil->saved_amount);
            $mesicu = $cil->target_on
                ? max(1, CarbonImmutable::today()->diffInMonths(CarbonImmutable::parse($cil->target_on), false))
                : null;

            return [
                $cil->name,
                (int) round((float) $cil->target_amount),
                (int) round((float) $cil->saved_amount),
                $mesicu ? (int) round($zbyva / $mesicu) : 0,
                $cil->target_on ? 'do '.CarbonImmutable::parse($cil->target_on)->format('n/Y') : 'průběžně',
                (string) ($cil->note ?? ''),
                null,
            ];
        })->values()->all();
    }

    /**
     * Kolik na měsíc.
     *
     * Rozpočet nemusí mít měsíční příjem: Makinčin rozpočet na Německo ho nemá,
     * má jednorázově složené prostředky na půl roku. Prototyp v hlavičce čeká
     * měsíční číslo, takže se prostředky rozpočítají na měsíce, které rozpočet
     * pokrývá. Nula by tam znamenala „nemáme z čeho žít", což není totéž jako
     * „příjem nechodí měsíčně".
     */
    private function mesicniPrijem(Budget $rozpocet): float
    {
        if ($rozpocet->monthly_income !== null) {
            return (float) $rozpocet->monthly_income;
        }

        return (float) ($rozpocet->starting_funds ?? 0) / max(1, $rozpocet->monthsCovered());
    }

    /**
     * Limity kategorií rozpočtu.
     *
     * `budget_category_limits` nemá vlastní model — v aplikaci se do ní sahá
     * dotazovačem, a tenhle poskytovatel to dělá stejně, aby nevznikla druhá
     * cesta k témuž řádku.
     *
     * @return Collection<int, object>
     */
    private function limity(Budget $rozpocet): Collection
    {
        return collect(DB::table('budget_category_limits as l')
            ->leftJoin('finance_categories as k', 'k.id', '=', 'l.finance_category_id')
            ->where('l.budget_id', $rozpocet->id)
            // Vzestupně, jako je aplikace financuje: nejdřív to, co se neshazuje.
            ->orderBy('l.priority')
            ->orderByDesc('l.amount')
            ->get(['l.id', 'l.finance_category_id', 'l.amount', 'l.priority', 'k.name as nazev', 'k.icon as ikona']));
    }

    /** @return array<int, float> */
    private function utracenoPoKategoriich(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do): array
    {
        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('excluded_from_budget', false)
            ->whereBetween('occurred_at', [$od, $do])
            ->selectRaw('category_id, SUM(ABS(amount_from)) AS castka')
            ->groupBy('category_id')
            ->pluck('castka', 'category_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function poznamkaKategorie(float $plan, float $utraceno, string $mena): string
    {
        if ($plan <= 0) {
            return 'bez limitu';
        }

        $zbyva = $plan - $utraceno;

        return $zbyva >= 0
            ? 'zbývá '.$this->castka($zbyva, $mena)
            : 'přečerpáno o '.$this->castka(abs($zbyva), $mena);
    }

    /**
     * Ikony aplikace jsou z Lucide (`bus`), prototyp kreslí Phosphor (`ph-bus`).
     *
     * Většina jmen se kryje, takže stačí předpona; co se nekryje, má převod níž.
     * Bez toho by u každé kategorie zůstalo prázdné místo po ikoně.
     */
    private function ikona(?string $jmeno): string
    {
        if (! $jmeno) {
            return 'ph-tag';
        }

        $prevod = [
            'shopping-cart' => 'basket', 'utensils' => 'fork-knife', 'fuel' => 'gas-pump',
            'circle-parking' => 'car', 'spray-can' => 'spray-bottle', 'ticket' => 'ticket',
            'home' => 'house-line', 'heart-pulse' => 'heartbeat', 'shirt' => 't-shirt',
            'graduation-cap' => 'student', 'plane' => 'airplane-tilt', 'train-front' => 'train',
            'piggy-bank' => 'piggy-bank', 'wallet' => 'wallet', 'gift' => 'gift',
        ];

        return 'ph-'.($prevod[$jmeno] ?? $jmeno);
    }

    // ——— účty ———

    /** @return Collection<int, Wallet> */
    private function penezenky(GallerySpace $prostor): Collection
    {
        return Wallet::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  Collection<int, Wallet>  $penezenky
     * @return list<array<int, mixed>>
     */
    private function ucty(GallerySpace $prostor, Collection $penezenky): array
    {
        if ($penezenky->isEmpty()) {
            return [];
        }

        // `walletBalances` vrací pole, ne modely, a klíčem je uuid.
        $zustatky = $this->kniha->walletBalances($prostor)->keyBy('uuid');
        $zustatek = fn (Wallet $p) => (float) ($zustatky[$p->uuid]['balance'] ?? $p->opening_balance ?? 0);
        $celkem = max(1.0, (float) $penezenky->sum(fn (Wallet $p) => abs($zustatek($p))));

        return $penezenky->map(function (Wallet $p) use ($zustatek, $celkem) {
            $castka = $zustatek($p);

            return [
                $p->name,
                trim(($p->kindLabel() ?: 'účet').($p->iban ? ' · '.$p->iban : '')),
                (int) round($castka),
                $this->ikonaUctu($p->kind),
                // Napojení na banku je zvláštní modul; bez něj je účet ruční.
                $p->kind === 'bank' ? 'napojeno' : 'ručně',
                $p->updated_at ? 'upraveno '.$p->updated_at->diffForHumans() : 'bez pohybu',
                $p->sort_order === 0 ? 'hlavní' : null,
                (int) round(abs($castka) / $celkem * 100),
            ];
        })->values()->all();
    }

    private function ikonaUctu(?string $druh): string
    {
        return match ($druh) {
            'bank' => 'ph-bank',
            'cash' => 'ph-money',
            'savings' => 'ph-piggy-bank',
            default => 'ph-credit-card',
        };
    }

    // ——— pevné náklady ———

    /** @return list<array<string, mixed>> */
    /**
     * Názvy kategorií, ze kterých si dvojice u transakce vybírá.
     *
     * Jen výdajové a jen aktivní: v seznamu k zařazení nákupu nemá co dělat
     * příjem ani kategorie, kterou si dvojice schovala.
     *
     * @return list<string>
     */
    private function nazvyKategorii(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('finance_categories')) {
            return [];
        }

        return DB::table('finance_categories')
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->whereNot('kind', 'income')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(60)
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    private function pevneNaklady(?Budget $rozpocet): array
    {
        if ($rozpocet === null) {
            return [];
        }

        $mesicu = max(1, $rozpocet->monthsCovered());

        // „Nedotknutelné" kategorie jsou to, co dvojice platí každý měsíc bez
        // rozmýšlení — přesně to, co prototyp v `SHARED` ukazuje.
        return $this->limity($rozpocet)
            ->filter(fn (object $limit) => (int) ($limit->priority ?? 100) <= self::PEVNE)
            ->map(fn (object $limit) => [
                'id' => 'p'.$limit->id,
                'name' => $limit->nazev ?? 'Nezařazeno',
                'amount' => (int) round((float) $limit->amount / $mesicu),
            ])
            ->values()
            ->all();
    }

    // ——— formát ———

    private function den(?\DateTimeInterface $kdy): string
    {
        return $kdy ? CarbonImmutable::parse($kdy)->format('j. n.') : '—';
    }

    private function mesic(CarbonImmutable $kdy): string
    {
        $jmena = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
            'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

        return $jmena[$kdy->month].' '.$kdy->year;
    }

    /**
     * Částka i s měnou rozpočtu.
     *
     * Makinčin rozpočet na Německo je v eurech; napsat u něj „zbývá 60 Kč" by byla
     * přesně ta tichá nepravda, kvůli které se pak dělají rozhodnutí naslepo.
     */
    /**
     * Vyrovnání mezi rozpočty: `[co, kolik a kdy, štítek]`.
     *
     * Ukázka tvrdila „automaticky" — jako by aplikace peníze mezi rozpočty
     * přesouvala sama. Nepřesouvá: každé vyrovnání někdo zapsal a v tabulce
     * je u něj podepsaný. Štítek proto říká, kdo to byl.
     *
     * @return list<array<int, string>>
     */
    private function vyrovnani(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('budget_settlements') || ! Schema::hasTable('budgets')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        return DB::table('budget_settlements as v')
            ->join('budgets as r', 'r.id', '=', 'v.budget_id')
            ->where('r.gallery_space_id', $prostor->id)
            ->orderByDesc('v.settled_through')
            ->limit(30)
            ->get(['v.amount', 'v.currency', 'v.settled_through', 'v.note', 'v.from_user_id', 'v.to_user_id', 'v.created_by', 'r.name as rozpocet'])
            ->map(function (object $v) use ($jmena) {
                $od = $jmena[$v->from_user_id] ?? null;
                $komu = $jmena[$v->to_user_id] ?? null;

                return [
                    $v->note ?: ($od && $komu ? $od.' → '.$komu : (string) $v->rozpocet),
                    trim(implode(' · ', array_filter([
                        'vyrovnáno '.$this->castka((float) $v->amount, (string) $v->currency),
                        CarbonImmutable::parse($v->settled_through)->format('j. n.'),
                        $v->note && $od && $komu ? $od.' → '.$komu : null,
                    ]))),
                    // Kdo to zapsal. „Automaticky" by byla lež: vyrovnání
                    // vzniká jen tím, že ho někdo z dvojice provede.
                    ($jmena[$v->created_by] ?? null) ?: 'zapsáno ručně',
                ];
            })
            ->values()
            ->all();
    }

    private function castka(float $castka, string $mena): string
    {
        return number_format($castka, 0, ',', ' ').' '.$this->znakMeny($mena);
    }

    /**
     * „ze 6 000" proti „z 5 000".
     *
     * Předložka se řídí tím, jak se číslo čte — „ze dvou tisíc", „ze šesti",
     * „ze sedmi". Napsat všude „z" je drobnost, kterou pozná každý, kdo česky
     * mluví.
     */
    private function zNeboZe(int $castka): string
    {
        $prvni = (int) substr((string) abs($castka), 0, 1);

        return in_array($prvni, [2, 6, 7], true) ? 'ze' : 'z';
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $kolik.' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }

    private function znakMeny(string $mena): string
    {
        return match (strtoupper($mena)) {
            'CZK' => 'Kč',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $mena,
        };
    }
}
