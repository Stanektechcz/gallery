<?php

namespace App\Services\Obsah;

use App\Models\Budget;
use App\Models\FinanceAccess;
use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\LedgerService;
use App\Services\Finance\ZarazeniPodlePopisu;
use App\Support\Cas;
use App\Support\Meny;
use App\Support\SpaceContext;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
class Finance implements MaPrazdneKolekce, PoskytovatelObsahu
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

    /** Kolik řádků „kdo co zaplatil" se posílá za jednu měnu. */
    private const ZAPLACENO_RADKU = 12;

    public function __construct(
        private readonly LedgerService $kniha,
        private readonly ZarazeniPodlePopisu $zarazeni,
        private readonly ExchangeRateService $kurzy,
        private readonly FinanceVMenach $vMenach,
    ) {}

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
        // `INCOMES` celé: ukázkový Adrian s výplatou nesmí zůstat vedle
        // skutečné dvojice, která se jmenuje jinak nebo příjem nezapsala.
        return ['ATX', 'INCOMES'];
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
            'TX' => [],
            'TXCATS' => [],
            'ATX' => [
                'all' => ['rows' => [], 'foot' => '', 'sum' => ''],
                'un' => ['rows' => [], 'foot' => '', 'sum' => ''],
                'rec' => ['rows' => [], 'foot' => '', 'sum' => ''],
                'imp' => ['rows' => [], 'foot' => '', 'sum' => ''],
            ],
            'BUD' => [
                'month' => '', 'today' => 0, 'days' => 0, 'income' => 0, 'plan' => 0, 'surplus' => 0,
                'cats' => [], 'months' => [], 'goals' => [], 'paid' => [],
                'year' => ['income' => 0, 'spent' => 0, 'saved' => 0, 'worst' => '', 'best' => '', 'poznamka' => ''],
                'yearCats' => [],
                // Znak měny rozpočtu a to, co se do něj přepočítalo z jiných měn.
                'mena' => '', 'mimoMenu' => null, 'monthsPoznamka' => '',
                // „Kdo co zaplatil" po měnách a jeho korunový ekvivalent.
                'paidPoMenach' => new \stdClass, 'paidPrepocet' => null,
            ],
            'FIN' => ['accounts' => [], 'upcoming' => [], 'alerts' => [], 'rules' => [], 'imports' => [], 'souhrn' => null],
            'INCOMES' => new \stdClass,
            'SHARED' => [],
            'RULEXP' => [],
            /*
             * `kolekce()` znak měny posílá, `prazdne()` o něm mlčelo — po
             * vyprázdnění financí si prototyp nechal ten poslední a kreslil
             * v něm dál. Prázdno je tu správná hodnota: `kc()` na klientu má
             * `MENA || 'Kč'`, takže spadne na výchozí a ne na cizí měnu.
             */
            'MENA' => '',
            /*
             * Nabízené měny chodí prázdné.
             *
             * Prázdný tvar nesmí nést data (PrazdneKolekceProKlientaTest), a
             * seznam měn je sice nastavení, ne život dvojice, ale kontrola to
             * nerozliší. Výběr na klientu proto padá na CZK, EUR, USD sám.
             */
            'MENY' => [],
            'ABARS' => ['bud' => [], 'year' => [], 'fc' => [], 'res' => []],
            'AL' => ['accounts' => [], 'balancing' => []],
        ];
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

        $hlavni = $this->vMenach->hlavni($prostor);
        $mena = $rozpocet ? $this->vMenach->menaRozpoctu($prostor, $rozpocet) : $hlavni;
        $bud = $rozpocet ? $this->rozpocetVen($prostor, $rozpocet) : null;

        // Co spočítat nejde, se **neposílá** — ne posílá jako `null`. Prototyp by
        // takovou kolekci nechal ukázkovou tak jako tak, jen by o tom mlčel.
        return array_filter([
            'TX' => $this->transakce($prostor, $pohyby),
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
            'FIN' => [
                'accounts' => $ucty = $this->ucty($prostor, $zustatky = $this->zustatkyUctu($prostor, $penezenky)),
                // Nadcházející platby z předpisů (nájem, telefon) na dva měsíce dopředu.
                'upcoming' => $nadchazejici = $this->nadchazejici($prostor),
                // Podle čeho import výpisu zařazuje: obchod → kategorie z poslední platby.
                'rules' => $this->zarazeni->pravidla($prostor),
                /*
                 * Hlavička Účtů sečtená na serveru, v hlavní měně.
                 *
                 * Klient sčítal `accounts[x][2]` sám — koruny s eury, protože
                 * měnu účtu neznal. Tady se sčítá po měnách a přepočte kurzem
                 * ECB; bez kurzu se číslo neukáže vůbec, jen součty po měnách.
                 */
                'souhrn' => $this->souhrnUctu($prostor, $zustatky, $nadchazejici),
            ],
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
            /*
             * Příjem každého zvlášť, `{ jméno: měsíčně }`.
             *
             * Dřív tu chodilo jedno číslo za domácnost. Obrazovky „Dělení podle
             * příjmů" a „Čas versus služba" ale čtou `INCOMES[jméno]` — skalár
             * na objekt navléct nešel, takže zůstala ukázková výplata 52 400
             * a 41 200 Kč a obrazovka podle ní radila, kdo kolik má platit.
             */
            'INCOMES' => (object) $this->prijmyOsob($prostor, $mena),
            'SHARED' => $this->pevneNaklady($rozpocet),
            'RULEXP' => $this->vydajeZaRok($prostor),
            /*
             * Měna, ve které se kreslí částky: hlavní měna dvojice.
             *
             * Dřív to byla měna viditelného rozpočtu — a eurový rozpočet na
             * Německo tak z každé částky v aplikaci udělal eura, i ze zůstatku
             * korunového účtu. Rozpočet v jiné měně nese svůj znak v `BUD.mena`.
             */
            'MENA' => Meny::znak($hlavni),
            // Co nabízejí výběry měny (účet, cesta), hlavní první.
            'MENY' => Meny::NABIZENE,
        ], fn ($v) => $v !== null);
    }

    // ——— transakce ———

    /**
     * Výdaje za poslední rok pro hledání a pravidla: `[den, popis, částka, datum]`.
     *
     * Hledání („kolik jsme dali za restaurace v srpnu") i zkouška pravidla
     * „výdaj nad limit" četly šest řádků z `galerie-data.js` — „Restaurace
     * U Kastelána 1 260 Kč" u každé dvojice. `TX` na to nestačí: nese jen
     * posledních sto dvacet pohybů a měsíc by vyšel poloviční. Den je
     * v genitivu („12. srpna"), protože tak ho hledání porovnává s měsícem;
     * čtvrté pole je datum, aby pravidlo umělo vzít jen poslední měsíc.
     * Páté je kód měny částky — „kolik jsme dali za restaurace" jinak sečte
     * 1 260 Kč s 40 € jako 1 300 čehosi.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: string}>
     */
    private function vydajeZaRok(GallerySpace $prostor): array
    {
        $mesice = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
            'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            // Převod mezi vlastními účty výdaj není — v hledání by zdvojil útratu.
            ->utraty()
            ->where('occurred_at', '>=', Cas::dnes()->subYear()->startOfDay())
            ->orderByDesc('occurred_at')
            ->limit(2000)
            ->get(['occurred_at', 'description', 'counterparty', 'amount_from', 'amount_to', 'currency_from', 'currency_to'])
            ->map(function (Transaction $t) use ($mesice) {
                $kdy = CarbonImmutable::parse($t->occurred_at);

                return [
                    $kdy->day.'. '.$mesice[$kdy->month],
                    (string) ($t->description ?: ($t->counterparty ?: 'Bez popisu')),
                    (int) round(abs((float) ($t->amount_from ?? $t->amount_to ?? 0))),
                    $kdy->toDateString(),
                    $this->menaRadku($t),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Nadcházející platby: `[den, název, částka, druh, kategorie, tento měsíc, uuid předpisu, datum, měna]`.
     *
     * Měna je poslední, aby se nic neposunulo: „Čeká do konce měsíce" sčítalo
     * nájem v korunách s parkováním v eurech, protože měnu předpisu neznalo.
     *
     * Záložka „Nadcházející platby" byla u dvojice prázdná a „Přidat platbu"
     * i „Přeskočit" hlásily „zatím neumíme" — přitom předpisy pravidelných
     * plateb v knize jsou. Termín, který už v knize leží (i smazaný či
     * přeskočený), se nenabízí: generátor ho taky nevytvoří.
     *
     * @return list<array<int, mixed>>
     */
    private function nadchazejici(GallerySpace $prostor): array
    {
        if (! Tabulky::je('finance_recurring')) {
            return [];
        }

        // Měnitelný `Carbon`, protože ho bere `FinanceRecurring::terminy()`.
        $dnes = Carbon::instance(Cas::dnes());
        $do = $dnes->copy()->addDays(60);

        $predpisy = FinanceRecurring::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->with('category:id,name')
            ->get();

        $radky = [];

        foreach ($predpisy as $p) {
            $zapsane = Transaction::withTrashed()->withoutGlobalScope(SpaceContext::SCOPE)
                ->where('recurring_id', $p->id)
                ->whereBetween('occurred_at', [$dnes->toDateString(), $do->toDateString()])
                ->pluck('occurred_at')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->flip();

            foreach ($p->terminy($dnes, $do) as $termin) {
                if ($zapsane->has($termin->toDateString())) {
                    continue;
                }

                $radky[] = [
                    $termin->day.'. '.$termin->month.'.',
                    (string) $p->name,
                    (int) round((float) $p->amount),
                    $p->type === 'income' ? 'příjem' : 'pravidelná platba',
                    $p->category?->name ?? 'Nezařazeno',
                    $termin->isSameMonth($dnes),
                    (string) $p->uuid,
                    $termin->toDateString(),
                    Meny::kod($p->currency) ?? $this->vMenach->hlavni($prostor),
                ];
            }
        }

        usort($radky, fn ($a, $b) => strcmp($a[7], $b[7]));

        return array_slice($radky, 0, 40);
    }

    /** @return Collection<int, Transaction> */
    private function pohyby(GallerySpace $prostor): Collection
    {
        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            // Rozepsaný zápis se v seznamu nedá odlišit — stav se do prototypu
            // neposílá — takže by se tvářil jako hotová útrata a sečetl se do
            // součtu záložky. Do knihy patří, až když je schválený.
            ->zapsane()
            ->with(['category:id,name,icon', 'walletFrom:id,name', 'walletTo:id,name', 'receipt' => fn ($q) => $q->withoutGlobalScope(SpaceContext::SCOPE)->select('id', 'uuid')])
            ->orderByDesc('occurred_at')
            ->limit(self::TRANSAKCI)
            ->get();
    }

    /**
     * `[id, datum, popis, kategorie, částka, účet, ikona, meta, poznámka]`
     *
     * Záporná částka je výdaj — tak to prototyp kreslí a podle znaménka volí barvu.
     * Částka je ve vlastní měně transakce; kolik to dělá v hlavní měně, nese meta.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return list<array<int, mixed>>
     */
    private function transakce(GallerySpace $prostor, Collection $pohyby): array
    {
        return $pohyby->map(function (Transaction $t) use ($prostor) {
            $vydaj = $t->type !== 'income';
            $castka = (float) ($t->amount_from ?? $t->amount_to ?? 0);
            $podepsana = $vydaj ? -abs($castka) : abs($castka);
            $kod = $this->menaRadku($t);
            $vHlavni = $this->vMenach->vHlavni($prostor, $podepsana, $kod);

            return [
                $t->uuid,
                $this->den($t->occurred_at ?? $t->booked_on),
                $t->description ?: ($t->counterparty ?: 'Bez popisu'),
                $t->category?->name ?? 'Nezařazeno',
                (int) round($podepsana),
                $t->walletFrom?->name ?? $t->walletTo?->name ?? '—',
                $this->ikona($t->category?->icon),
                /*
                 * Meta drží prototyp jako volný objekt.
                 *
                 * `rec` — platba z předpisu (detail píše „opakuje se měsíčně"),
                 * `mimo` — vynechaná z rozpočtu, `uuid` — pro zápis zpátky.
                 *
                 * `mena`, `znak` a `hl` chodí vždycky: součty měsíce na klientu
                 * sčítaly pátou pozici přes měny. `hl` je částka v hlavní měně
                 * se stejným znaménkem, a když kurz neznáme, výslovně `null` —
                 * ne nula, ta by součet tiše zkrátila.
                 */
                (object) (array_filter([
                    // Uuid fotky dokladu — „Doklad" v detailu ji otevře (dřív jen jednička a hláška).
                    'receipt' => $t->receipt_media_id ? ($t->receipt?->uuid ?? 1) : null,
                    'rec' => $t->recurring_id ? 'měsíčně' : null,
                    'mimo' => $t->excluded_from_budget ? 1 : null,
                    'mimoProc' => $t->excluded_from_budget ? (string) ($t->exclusion_reason ?? '') : null,
                ], fn ($v) => $v !== null) + [
                    'mena' => $kod,
                    'znak' => Meny::znak($kod),
                    'hl' => $vHlavni === null ? null : (int) round($vHlavni),
                ]),
                // Devátá pozice je poznámka; bez ní místo, kde se platilo.
                (string) ($t->note ?? $t->place ?? ''),
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

        /*
         * Popisek nese celé období, ne měsíc první transakce.
         *
         * Seznam sahá 120 zápisů zpátky, takže běžně přes tři měsíce — a
         * hlavička přesto tvrdila „31 transakcí · září 2026 · −22 460 Kč",
         * i když v září z toho byly necelé dva tisíce. Když se všechno vejde
         * do jednoho měsíce, píše se dál jen ten.
         */
        $mesic = $this->obdobi($pohyby);

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

        return [
            'rows' => $pohyby->map(fn (Transaction $t) => [
                $this->den($t->occurred_at ?? $t->booked_on),
                $t->description ?: ($t->counterparty ?: 'Bez popisu'),
                $this->popisKategorie($t),
                // Vlastní měna transakce, ne měna rozpočtu — koruna z domova
                // se jinak tvářila jako euro z rozpočtu na Německo.
                $this->sCznamenkem($this->podepsana($t), $this->menaRadku($t)),
            ])->values()->all(),
            'foot' => $popisek,
            'sum' => $this->soucetZalozky($pohyby, $mena),
        ];
    }

    /**
     * Součet záložky — po měnách, protože se sčítat nedají.
     *
     * Do součtu jde jen to, co mění hospodářský výsledek (`affectsResult()`):
     * převod ani směna mezi vlastními účty útrata není, i když peníze
     * opustily peněženku. Když je řádků víc měn, posílá se součet za každou
     * zvlášť — sečíst korunu s eurem by bylo číslo bez smyslu.
     */
    private function soucetZalozky(Collection $pohyby, string $mena): string
    {
        $soucty = $pohyby
            ->filter(fn (Transaction $t) => $t->affectsResult())
            ->groupBy(fn (Transaction $t) => $this->menaRadku($t))
            ->map(fn (Collection $s) => $s->sum(fn (Transaction $t) => $this->podepsana($t)));

        if ($soucty->isEmpty()) {
            return $this->sCznamenkem(0.0, $mena);
        }

        if ($soucty->count() === 1) {
            return $this->sCznamenkem($soucty->first(), $soucty->keys()->first());
        }

        return $soucty->map(fn (float $castka, string $mena) => $this->sCznamenkem($castka, $mena))->implode(' · ');
    }

    /**
     * Měna, ve které transakce sama je — ne měna rozpočtu, který na ni kouká.
     *
     * `podepsana()` bere `amount_from`, a když chybí, `amount_to`; měna se
     * řídí týmž pravidlem, jinak by číslo neslo cizí značku.
     *
     * Velkými písmeny, aby „eur" a „EUR" nebyly dvě měny; bez měny je to
     * koruna, jako v `Meny::znak()` — starší zápisy měnu nemají.
     */
    private function menaRadku(Transaction $t): string
    {
        return strtoupper(trim((string) ($t->currency_from ?: $t->currency_to))) ?: Meny::HLAVNI;
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
        $kdy = Tabulky::je('bank_connections')
            ? DB::table('bank_connections')
                ->where('gallery_space_id', $prostor->id)
                ->max('last_synced_at')
            : null;

        if (! $kdy) {
            return '';
        }

        $kdy = Cas::mistni($kdy);

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
                // Šířka pruhu — proto nejvýš sto. Přečerpání o třicet procent
                // dalo `width:130%` a pruh přetekl ze své dráhy; že se limit
                // přešel, říká barva (1) a text vedle.
                min(100, $pomer),
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
        $rok = Cas::dnes()->year;
        $ted = (int) ceil(Cas::dnes()->month / 3);

        // `mesice` jde dvanáct měsíců zpět; pro rok se berou ty z letoška.
        $letos = [];
        foreach ($mesice as $i => $m) {
            $kdy = Cas::dnes()->startOfMonth()->subMonths(11 - $i);
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
        $ja = auth()->id();

        if (! $ja) {
            return null;
        }

        /*
         * Osobní rozpočet druhého z dvojice se nekreslí.
         *
         * Obrazovka brala prostě poslední rozpočet v prostoru. Když si jeden
         * z nich založil vlastní — „Makinčin rozpočet na Německo" — a nesdílel
         * ho, druhý ho stejně viděl i s limity a příjmem. Pravidlo, kdo na co
         * vidí, je v `FinanceAccess::viditelne()`; používá ho i obrazovka
         * rozpočtů, takže obě místa teď ukazují totéž.
         */
        return FinanceAccess::viditelne(
            Budget::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('gallery_space_id', $prostor->id),
            'budget', $ja,
        )
            // Běžící rozpočet má přednost před tím, co skončilo nebo teprve začne.
            // Dnešek dvojice jako datum: s okamžikem v UTC (`now()`) poslední den
            // rozpočtu odpoledne „neběžel" (`'2026-09-30' >= '2026-09-30 12:00'`
            // neplatí) a obrazovka skočila na rozpočet, který teprve začne.
            ->orderByRaw('CASE WHEN starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?) THEN 0 ELSE 1 END', [$dnes = Cas::dnes()->toDateString(), $dnes])
            /*
             * Z běžících rozpočtů ten v hlavní měně.
             *
             * Domácnost v korunách a rozpočet na Německo v eurech běží zároveň;
             * brát ten později založený znamenalo, že Rozpočty ukázaly eura
             * a korunová domácnost zmizela z obrazovky.
             */
            ->orderByRaw('CASE WHEN UPPER(currency) = ? THEN 0 ELSE 1 END', [$this->vMenach->hlavni($prostor)])
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * `{ month, today, days, income, plan, surplus, mena, mimoMenu, paid, paidPoMenach, paidPrepocet,
     * cats: [[název, plán, utraceno, ikona, poznámka, štítek, obvyklé, mimo měnu]] }`
     *
     * Čísla rozpočtu jsou v jeho vlastní měně (znak v `mena`). Rozpočet v hlavní
     * měně v nich má započtenou i přepočtenou útratu v jiných měnách.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<string, mixed>
     */
    private function rozpocetVen(GallerySpace $prostor, Budget $rozpocet): array
    {
        $dnes = Cas::dnes();
        $limity = $this->limity($rozpocet);
        $mena = $this->vMenach->menaRozpoctu($prostor, $rozpocet);

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
        $cerpani = $this->cerpani($prostor, $dnes->startOfMonth(), $dnes->endOfMonth(), $mena);
        $utraceno = $cerpani['castky'];

        /*
         * Obvyklá útrata kategorie: průměr tří celých měsíců před tímhle.
         *
         * „Anomálie" v prototypu brala za obvyklé 82 % limitu — vymyšlené číslo,
         * podle kterého obrazovka hlásila, že kategorie „vybočuje". Tady je to
         * skutečný průměr; kategorie bez historie má nulu a za anomálii se nebere.
         */
        $obvykle = $this->obvykleUtraty($prostor, $dnes, $mena);

        $prijem = $this->mesicniPrijem($rozpocet);
        $plan = $naMesic((float) $limity->sum('amount'));
        $mesice = $this->mesice($prostor, $mena);

        return [
            'month' => $this->mesic($dnes),
            'today' => $dnes->day,
            'days' => $dnes->daysInMonth,
            'income' => (int) round($prijem),
            'plan' => (int) round($plan),
            'surplus' => (int) round($prijem - $plan),
            // Znak měny rozpočtu pro jeho vlastní čísla. `MENA` je hlavní měna
            // celé aplikace; rozpočet na Německo v eurech tu má „€".
            'mena' => Meny::znak($mena),
            'cats' => $limity->map(function (object $limit) use ($utraceno, $obvykle, $mena, $naMesic, $cerpani) {
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
                    // Obvyklá měsíční útrata (průměr tří předchozích měsíců).
                    (int) round($obvykle[$limit->finance_category_id] ?? 0),
                    // Co se do utraceného přepočítalo z jiné měny, a co bez kurzu
                    // započítat nešlo. Null, když se platilo jen v měně rozpočtu.
                    $this->mimoMenuKategorie($cerpani['mimo'][$limit->finance_category_id] ?? null),
                ];
            })->values()->all(),
            'mimoMenu' => $this->mimoMenuMesice($cerpani, $mena),
            ...$this->kdoCoZaplatil($prostor, $dnes),
            'months' => $mesice['radky'],
            'monthsPoznamka' => $mesice['poznamka'],
            'year' => $this->rok($prostor, $rozpocet, $mesice['radky']),
            'goals' => $this->cile($rozpocet),
            'yearCats' => [],
        ];
    }

    /**
     * Utraceno po měsících za posledních dvanáct měsíců.
     *
     * Rozpočet v hlavní měně započítá i útratu v jiné měně, přepočtenou dnešním
     * kurzem ECB; co přepočítat nejde, řekne poznámka. Rozpočet v jiné měně bere
     * jen svou měnu — koruna z domova do rozpočtu na Německo nepatří.
     *
     * @return array{radky: list<array{0: string, 1: int}>, poznamka: string}
     */
    private function mesice(GallerySpace $prostor, string $mena): array
    {
        $od = Cas::dnes()->startOfMonth()->subMonths(11);

        $poMesicich = $this->utratyVMene($prostor, $mena)
            ->where('occurred_at', '>=', $od)
            ->get(['occurred_at', 'amount_from', 'currency_from'])
            ->groupBy(fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at)->format('Y-m'))
            ->map(fn (Collection $skupina) => $this->vMenach->doMeny($prostor, $this->vMenach->poMenach($skupina, $mena), $mena));

        $zkratky = [1 => 'led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'];

        $radky = collect(range(0, 11))
            ->map(function (int $i) use ($od, $poMesicich, $zkratky) {
                $mesic = $od->addMonths($i);

                return [
                    $zkratky[$mesic->month].' '.$mesic->format('y'),
                    (int) round((float) ($poMesicich[$mesic->format('Y-m')]['castka'] ?? 0)),
                ];
            })
            ->all();

        return ['radky' => $radky, 'poznamka' => $this->vMenach->poznamkaPrepoctu($poMesicich->values()->all())];
    }

    /**
     * Útraty, které do rozpočtu v měně `$mena` patří.
     *
     * Rozpočet v hlavní měně bere všechny měny (přepočítají se); rozpočet v jiné
     * měně jen tu svou — kurz do eur aplikace nemá a koruna z domova do rozpočtu
     * na Německo nepatří tak jako tak.
     */
    private function utratyVMene(GallerySpace $prostor, string $mena): Builder
    {
        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->when($mena !== $this->vMenach->hlavni($prostor), fn (Builder $q) => $q->where('currency_from', $mena));
    }

    /** @return array{income: int, spent: int, saved: int, worst: string, best: string, poznamka: string} */
    private function rok(GallerySpace $prostor, Budget $rozpocet, array $mesice = []): array
    {
        $od = Cas::dnes()->startOfYear();
        $mena = $this->vMenach->menaRozpoctu($prostor, $rozpocet);
        $vHlavni = $mena === $this->vMenach->hlavni($prostor);

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->zapsane()
            ->whereIn('type', Transaction::VYSLEDKOVE)
            ->where('occurred_at', '>=', $od)
            ->get(['type', 'amount_from', 'amount_to', 'currency_from', 'currency_to']);

        $kod = fn (?string $m) => strtoupper(trim((string) $m)) ?: $mena;

        /*
         * Příjem i výdaj po měnách.
         *
         * Rozpočet v jiné než hlavní měně bere jen svou měnu — jinak by příjem
         * v korunách a výdaj v eurech vytvořily „ušetřeno", které neodpovídá
         * ani jedné z nich. Rozpočet v hlavní měně přepočte ostatní kurzem ECB
         * a co přepočítat nejde, vynechá a řekne to v poznámce.
         */
        $prijmy = [];
        $vydaje = [];

        foreach ($pohyby as $t) {
            if ($t->type === 'income') {
                $m = $kod($t->currency_to ?: $t->currency_from);
                $prijmy[$m] = ($prijmy[$m] ?? 0.0) + abs((float) ($t->amount_to ?? $t->amount_from));
            } elseif ($t->type === 'expense') {
                $m = $kod($t->currency_from);
                $vydaje[$m] = ($vydaje[$m] ?? 0.0) + abs((float) $t->amount_from);
            }
        }

        if (! $vHlavni) {
            $prijmy = array_intersect_key($prijmy, [$mena => true]);
            $vydaje = array_intersect_key($vydaje, [$mena => true]);
        }

        $prijemVMene = $this->vMenach->doMeny($prostor, $prijmy, $mena);
        $vydajVMene = $this->vMenach->doMeny($prostor, $vydaje, $mena);
        $prijem = $prijemVMene['castka'];
        $vydaj = $vydajVMene['castka'];

        /*
         * Nejdražší a nejlevnější měsíc — z už spočítaných součtů.
         *
         * `prazdne()` obě pole slibuje, ale `rok()` je nevracel, takže v knize
         * roku stálo natrvalo „Nejdražší měsíc —". Měsíční součty přitom leží
         * o dva řádky vedle, takže to nestojí ani jeden dotaz navíc. Měsíce
         * bez jediné útraty se nepočítají: „nejlevnější byl leden (0 Kč)"
         * o ničem nevypovídá.
         */
        $neprazdne = array_values(array_filter($mesice, fn (array $m) => (int) ($m[1] ?? 0) > 0));
        $castky = array_column($neprazdne, 1);

        return [
            'income' => (int) round($prijem),
            'spent' => (int) round($vydaj),
            'saved' => (int) round($prijem - $vydaj),
            'worst' => $castky ? (string) $neprazdne[array_search(max($castky), $castky, true)][0] : '',
            'best' => $castky ? (string) $neprazdne[array_search(min($castky), $castky, true)][0] : '',
            // „přepočteno kurzem ECB k …", „+40 € nezapočteno", nebo nic.
            'poznamka' => $this->vMenach->poznamkaPrepoctu([$prijemVMene, $vydajVMene]),
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
                ? max(1, Cas::dnes()->diffInMonths(CarbonImmutable::parse($cil->target_on), false))
                : null;

            return [
                $cil->name,
                (int) round((float) $cil->target_amount),
                (int) round((float) $cil->saved_amount),
                $mesicu ? (int) round($zbyva / $mesicu) : 0,
                $cil->target_on ? 'do '.CarbonImmutable::parse($cil->target_on)->format('n/Y') : 'průběžně',
                (string) ($cil->note ?? ''),
                null,
                // Pod tímhle se do cíle vkládá („Vložit" u vyhrazené částky).
                (string) $cil->uuid,
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

    /**
     * Průměrná měsíční útrata po kategoriích za tři celé měsíce před `$dnes`.
     *
     * Z téhož čísla vychází „obvyklá útrata" na obrazovce i odhad limitů
     * u nově zakládaného rozpočtu — dva výpočty by se rozešly.
     *
     * @return array<int, float>
     */
    public function obvykleUtraty(GallerySpace $prostor, CarbonImmutable $dnes, string $mena): array
    {
        $pred = $dnes->startOfMonth()->subMonths(3);

        return array_map(fn (float $v) => $v / 3, $this->utracenoPoKategoriich($prostor, $pred, $dnes->startOfMonth()->subSecond(), $mena));
    }

    /**
     * Utraceno po kategoriích v měně `$mena`.
     *
     * @return array<int, float>
     */
    private function utracenoPoKategoriich(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do, string $mena): array
    {
        return $this->cerpani($prostor, $od, $do, $mena)['castky'];
    }

    /**
     * Čerpání rozpočtu po kategoriích a s tím, co se do něj přepočítalo.
     *
     * Rozpočet v jiné než hlavní měně bere jen útratu ve své měně —
     * `FinanceService` (`daily()`/`byCategory()`) filtruje stejně na
     * `currency_from`, jinak by se koruna z domova sečetla s eurem z rozpočtu
     * na Německo, jako by šlo o stejné jednotky.
     *
     * Rozpočet v hlavní měně (koruny) ale útratu v eurech **zahazoval**: večeře
     * ve Vídni z korunové domácnosti zmizela a rozpočet vypadal, že se v něm
     * šetří. Teď se započítá přepočtená kurzem ECB a `mimo` o ní ví, aby šlo
     * napsat „vč. 40 € přepočteno". Bez kurzu se nezapočítá, ale neztratí se:
     * skončí v `nezapocteno` a obrazovka napíše „+40 € nezapočteno".
     *
     * @return array{castky: array<int|string, float>, mimo: array<int|string, array{castka: float, prepocteno: array<string, float>, nezapocteno: array<string, float>, prevod: float, den: ?string}>, prepocteno: array<string, float>, nezapocteno: array<string, float>, prevod: float, den: ?string}
     */
    private function cerpani(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do, string $mena): array
    {
        $radky = $this->utratyVMene($prostor, $mena)
            ->where('excluded_from_budget', false)
            ->whereBetween('occurred_at', [$od, $do])
            ->selectRaw('category_id, currency_from, SUM(ABS(amount_from)) AS castka')
            ->groupBy('category_id', 'currency_from')
            ->toBase()
            ->get();

        $poKategoriich = [];

        foreach ($radky as $r) {
            // Bez kategorie je klíč prázdný řetězec, jako dřív z `pluck()`.
            $kategorie = $r->category_id ?? '';
            $kod = strtoupper(trim((string) $r->currency_from)) ?: $mena;
            $poKategoriich[$kategorie][$kod] = ($poKategoriich[$kategorie][$kod] ?? 0.0) + (float) $r->castka;
        }

        $vysledek = ['castky' => [], 'mimo' => [], 'prepocteno' => [], 'nezapocteno' => [], 'prevod' => 0.0, 'den' => null];

        foreach ($poKategoriich as $kategorie => $poMenach) {
            $prevod = $this->vMenach->doMeny($prostor, $poMenach, $mena);
            $vysledek['castky'][$kategorie] = $prevod['castka'];

            if ($prevod['prepocteno'] === [] && $prevod['nezapocteno'] === []) {
                continue;
            }

            $vysledek['mimo'][$kategorie] = $prevod;
            $vysledek['prevod'] += $prevod['prevod'];
            $vysledek['den'] = $this->vMenach->starsiDen($vysledek['den'], $prevod['den']);

            foreach (['prepocteno', 'nezapocteno'] as $cast) {
                foreach ($prevod[$cast] as $kod => $castka) {
                    $vysledek[$cast][$kod] = ($vysledek[$cast][$kod] ?? 0.0) + $castka;
                }
            }
        }

        return $vysledek;
    }

    /**
     * Index 7 řádku `BUD.cats`: co se do utraceného přepočítalo a co ne.
     *
     * @param  array{prepocteno: array<string, float>, nezapocteno: array<string, float>}|null  $mimo
     * @return array{mimoMenu: object, nezapocteno: object, text: string}|null
     */
    private function mimoMenuKategorie(?array $mimo): ?array
    {
        if ($mimo === null) {
            return null;
        }

        return [
            // Původní částky v cizí měně, které jsou v utraceném započtené.
            'mimoMenu' => (object) $this->vMenach->zaokrouhlene($mimo['prepocteno']),
            // Co bez kurzu započítat nešlo.
            'nezapocteno' => (object) $this->vMenach->zaokrouhlene($mimo['nezapocteno']),
            'text' => implode(' · ', array_filter([
                $mimo['prepocteno'] !== [] ? 'vč. '.$this->vMenach->poMenachText($mimo['prepocteno']).' přepočteno' : '',
                $this->vMenach->nezapoctenoText($mimo['nezapocteno']),
            ])),
        ];
    }

    /**
     * `BUD.mimoMenu`: útrata měsíce v cizí měně napříč kategoriemi, nebo null.
     *
     * @param  array{prepocteno: array<string, float>, nezapocteno: array<string, float>, prevod: float, den: ?string}  $cerpani
     * @return array{poMenach: object, prepocteno: int, nezapocteno: object, popisek: ?string, text: string}|null
     */
    private function mimoMenuMesice(array $cerpani, string $mena): ?array
    {
        if ($cerpani['prepocteno'] === [] && $cerpani['nezapocteno'] === []) {
            return null;
        }

        $popisek = $this->kurzy->popisek(['prepocteno' => $cerpani['prepocteno'] !== [], 'kurzKeDni' => $cerpani['den']]);

        return [
            'poMenach' => (object) $this->vMenach->zaokrouhlene($cerpani['prepocteno']),
            // Kolik ta cizí útrata dělá v měně rozpočtu — už je v `cats[x][2]`.
            'prepocteno' => (int) round($cerpani['prevod']),
            'nezapocteno' => (object) $this->vMenach->zaokrouhlene($cerpani['nezapocteno']),
            'popisek' => $popisek,
            'text' => implode(' · ', array_filter([
                $cerpani['prepocteno'] !== []
                    ? 'vč. '.$this->vMenach->poMenachText($cerpani['prepocteno']).' ('.Meny::castka($cerpani['prevod'], $mena).') '.($popisek ?? 'přepočteno')
                    : '',
                $this->vMenach->nezapoctenoText($cerpani['nezapocteno']),
            ])),
        ];
    }

    /**
     * Kdo tento měsíc co zaplatil: `[jméno, kategorie, částka]`.
     *
     * Vyrovnání mezi dvojicí v prototypu počítalo z napsaných řádků („Adrian ·
     * Nákupy a benzín 14 820 Kč") a oznamovalo, kdo komu dluží. Tady se sčítá
     * kniha: plátce z transakce, a když chybí, kdo ji zapsal.
     *
     * **Po měnách.** Dřív `SUM(ABS(amount_from))` přes všechny měny: 250 Kč
     * a 20 € byly 270 a obrazovka podle toho počítala, kdo komu kolik dluží.
     * Dluhy se mezi měnami nepřevádějí (`LedgerService::settlementPlan()`) —
     * kurz je rozhodnutí člověka, ne systému. Proto:
     *
     * - `paid` — jen hlavní měna, tvar i význam jako dřív; z něj klient
     *   počítá dluh, takže tam cizí měna nesmí,
     * - `paidPoMenach` — `{ "CZK": [[jméno, kategorie, částka]], "EUR": [...] }`,
     * - `paidPrepocet` — kolik kdo zaplatil celkem v hlavní měně, výslovně
     *   jako přepočet s datem kurzu; null, když se platilo jen v hlavní měně.
     *
     * @return array{paid: list<array{0: string, 1: string, 2: int}>, paidPoMenach: object, paidPrepocet: ?array<string, mixed>}
     */
    private function kdoCoZaplatil(GallerySpace $prostor, CarbonImmutable $dnes): array
    {
        $jmena = System::jmenaClenu($prostor);

        /*
         * Od posledního vyrovnání.
         *
         * Vyrovnání říká „k tomuhle dni srovnáno" — platby do toho dne se už
         * mezi dvojicí nepočítají. Bez toho by po zapsaném vyrovnání obrazovka
         * dál tvrdila, že jeden druhému dluží.
         */
        $od = $dnes->startOfMonth();

        if (Tabulky::je('budget_settlements')) {
            // Jen vyrovnání z rozpočtů, které divák vidí: srovnání v partnerově
            // soukromém rozpočtu by jinak posunulo začátek a z „kdo co
            // zaplatil" by zmizely společné platby.
            $posledni = DB::table('budget_settlements as v')
                ->whereIn('v.budget_id', $this->viditelneRozpocty($prostor))
                ->max('v.settled_through');

            if ($posledni && CarbonImmutable::parse($posledni)->addDay()->greaterThan($od)) {
                $od = CarbonImmutable::parse($posledni)->addDay();
            }
        }

        $radky = DB::table('transactions as t')
            ->leftJoin('partners as p', 'p.id', '=', 't.payer_partner_id')
            ->leftJoin('finance_categories as k', 'k.id', '=', 't.category_id')
            ->where('t.gallery_space_id', $prostor->id)
            ->where('t.type', 'expense')
            ->whereIn('t.state', Transaction::ZAPSANE)
            ->whereNull('t.deleted_at')
            ->where('t.excluded_from_budget', false)
            /*
             * Do konce dne, ne k jeho půlnoci.
             *
             * `occurred_at` nese i čas, takže horní mez `'2026-09-30'` uřízla
             * celý poslední den měsíce: třicátého se z „kdo co zaplatil"
             * ztratily všechny ten den zapsané útraty.
             */
            ->whereBetween('t.occurred_at', [$od->startOfDay(), $dnes->endOfMonth()->endOfDay()])
            ->selectRaw('COALESCE(p.user_id, t.created_by) AS kdo, COALESCE(k.name, ?) AS kategorie, t.currency_from AS mena, SUM(ABS(t.amount_from)) AS castka', ['Nezařazeno'])
            ->groupBy('kdo', 'kategorie', 't.currency_from')
            ->orderByDesc('castka')
            ->get()
            ->filter(fn ($r) => isset($jmena[(int) $r->kdo]));

        $hlavni = $this->vMenach->hlavni($prostor);
        $poMenach = [];
        $osoby = [];

        foreach ($radky as $r) {
            $kod = strtoupper(trim((string) $r->mena)) ?: $hlavni;
            $jmeno = $jmena[(int) $r->kdo];
            $poMenach[$kod][] = [$jmeno, (string) $r->kategorie, (int) round((float) $r->castka)];
            $osoby[$jmeno][$kod] = ($osoby[$jmeno][$kod] ?? 0.0) + (float) $r->castka;
        }

        // Nejvýš dvanáct řádků za měnu, jako dřív za všechno — ať eurové
        // drobnosti nevytlačí korunový nájem.
        $poMenach = array_map(fn (array $r) => array_slice($r, 0, self::ZAPLACENO_RADKU), $poMenach);

        return [
            'paid' => $poMenach[$hlavni] ?? [],
            'paidPoMenach' => (object) $poMenach,
            'paidPrepocet' => array_diff(array_keys($poMenach), [$hlavni]) === [] ? null : $this->zaplacenoPrepocet($prostor, $osoby),
        ];
    }

    /**
     * Kolik kdo zaplatil v hlavní měně — jen jako označená informace navíc.
     *
     * Dluh se z toho nepočítá; osoba, u které nějaký kurz chybí, má `null`.
     *
     * @param  array<string, array<string, float>>  $osoby  jméno => měna => částka
     * @return array{mena: string, znak: string, osoby: object, uplne: bool, kurzKeDni: ?string, popisek: ?string}
     */
    private function zaplacenoPrepocet(GallerySpace $prostor, array $osoby): array
    {
        $celkem = [];
        $uplne = true;
        $prepocteno = false;
        $den = null;

        foreach ($osoby as $jmeno => $meny) {
            $vysledek = $this->kurzy->doHlavni($meny, $prostor);
            $celkem[$jmeno] = $vysledek['celkem'] === null ? null : (int) round($vysledek['celkem']);
            $uplne = $uplne && $vysledek['uplne'];
            $prepocteno = $prepocteno || $vysledek['prepocteno'];
            $den = $this->vMenach->starsiDen($den, $vysledek['kurzKeDni']);
        }

        return [
            'mena' => $this->vMenach->hlavni($prostor),
            'znak' => Meny::znak($this->vMenach->hlavni($prostor)),
            'osoby' => (object) $celkem,
            'uplne' => $uplne,
            'kurzKeDni' => $den,
            'popisek' => $this->kurzy->popisek(['prepocteno' => $prepocteno, 'kurzKeDni' => $den]),
        ];
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
     * Zůstatek každé peněženky ve vlastní měně a v hlavní měně (null bez kurzu).
     *
     * @param  Collection<int, Wallet>  $penezenky
     * @return list<array{penezenka: Wallet, castka: float, mena: string, hl: ?float}>
     */
    private function zustatkyUctu(GallerySpace $prostor, Collection $penezenky): array
    {
        if ($penezenky->isEmpty()) {
            return [];
        }

        // `walletBalances` vrací pole, ne modely, a klíčem je uuid.
        $zustatky = $this->kniha->walletBalances($prostor)->keyBy('uuid');

        return $penezenky->map(function (Wallet $p) use ($zustatky, $prostor) {
            $castka = (float) ($zustatky[$p->uuid]['balance'] ?? $p->opening_balance ?? 0);
            $mena = Meny::kod($p->currency) ?? $this->vMenach->hlavni($prostor);

            return ['penezenka' => $p, 'castka' => $castka, 'mena' => $mena, 'hl' => $this->vMenach->vHlavni($prostor, $castka, $mena)];
        })->values()->all();
    }

    /**
     * `[název, druh · IBAN, zůstatek, ikona, napojení, upraveno, příznak, podíl %, uuid, měna, v hlavní měně, zůstatek textem]`
     *
     * Zůstatek na pozici 2 je ve vlastní měně účtu — a obrazovka ho kreslila
     * znakem hlavní měny a sčítala přes měny, protože měnu neznala. Nové
     * pozice jsou až na konci, aby se stávající nic neposunulo: 9 kód měny,
     * 10 zůstatek v hlavní měně (celé číslo, null bez kurzu), 11 zůstatek
     * napsaný ve vlastní měně („100 €").
     *
     * @param  list<array{penezenka: Wallet, castka: float, mena: string, hl: ?float}>  $zustatky
     * @return list<array<int, mixed>>
     */
    private function ucty(GallerySpace $prostor, array $zustatky): array
    {
        if ($zustatky === []) {
            return [];
        }

        /*
         * Podíl z korunových ekvivalentů.
         *
         * Z holých zůstatků vyšlo 100 € na eurovém účtu jako desetina proti
         * 1 000 Kč, přitom je to víc než dvojnásobek. Účet bez kurzu podíl
         * nemá (0 — pruh se nekreslí); odhadnout ho by znamenalo kurz vymyslet.
         */
        $celkem = max(1.0, array_sum(array_map(fn (array $u) => abs((float) $u['hl']), $zustatky)));

        return array_map(function (array $u) use ($celkem) {
            $p = $u['penezenka'];
            $castka = $u['castka'];

            return [
                $p->name,
                trim(($p->kindLabel() ?: 'účet').($p->iban ? ' · '.$p->iban : '')),
                (int) round($castka),
                $this->ikonaUctu($p->kind),
                // Napojení na banku je zvláštní modul; bez něj je účet ruční.
                $p->kind === 'bank' ? 'napojeno' : 'ručně',
                // Česky i na serveru s APP_LOCALE=en — stálo tu „upraveno 0 seconds ago".
                $p->updated_at ? 'upraveno '.(CarbonImmutable::parse($p->updated_at)->diffInSeconds(now(), true) < 60
                    ? 'právě teď'
                    : CarbonImmutable::parse($p->updated_at)->locale('cs')->diffForHumans()) : 'bez pohybu',
                $p->sort_order === 0 ? 'hlavní' : null,
                $u['hl'] === null ? 0 : (int) round(abs($u['hl']) / $celkem * 100),
                // Kam nahrát výpis z banky (ImportVypisuController).
                $p->uuid,
                $u['mena'],
                $u['hl'] === null ? null : (int) round($u['hl']),
                Meny::castka($castka, $u['mena']),
            ];
        }, $zustatky);
    }

    /**
     * `FIN.souhrn` — hlavička Účtů v hlavní měně.
     *
     * `{ celkem, bezne, odlozeno, ceka, mena, znak, kurzKeDni, popisek, uplne,
     * chybi, poMenach: { celkem, bezne, odlozeno, ceka }, texty: { … } }`
     *
     * Každé číslo je buď úplně přepočtené, nebo `null` — smíšené číslo se
     * neposílá nikdy. `poMenach` jsou součty ve vlastních měnách vždycky a
     * `texty` je hotový zápis: „3 500 Kč", a když číslo chybí, „1 000 Kč · 100 €".
     *
     * Odloženo jsou spořicí účty („rezerva"). Klient bral odložené podle
     * příznaků z ukázky („nedotknutelné", ikona letadla), které server nikdy
     * neposílá, takže u dvojice bylo odloženo vždycky nula. Čeká do konce
     * měsíce jsou termíny předpisů z tohoto měsíce, jak je sčítal klient.
     *
     * @param  list<array{penezenka: Wallet, castka: float, mena: string, hl: ?float}>  $zustatky
     * @param  list<array<int, mixed>>  $nadchazejici
     * @return array<string, mixed>
     */
    private function souhrnUctu(GallerySpace $prostor, array $zustatky, array $nadchazejici): array
    {
        $soucty = ['celkem' => [], 'bezne' => [], 'odlozeno' => [], 'ceka' => []];

        foreach ($zustatky as $u) {
            $cast = $u['penezenka']->kind === 'savings' ? 'odlozeno' : 'bezne';

            foreach (['celkem', $cast] as $kam) {
                $soucty[$kam][$u['mena']] = ($soucty[$kam][$u['mena']] ?? 0.0) + $u['castka'];
            }
        }

        foreach ($nadchazejici as $n) {
            if ($n[5]) {
                $soucty['ceka'][$n[8]] = ($soucty['ceka'][$n[8]] ?? 0.0) + (float) $n[2];
            }
        }

        $hlavni = $this->vMenach->hlavni($prostor);
        $souhrn = ['mena' => $hlavni, 'znak' => Meny::znak($hlavni)];
        $uplne = true;
        $prepocteno = false;
        $den = null;
        $chybi = [];
        $poMenach = [];
        $texty = [];

        foreach ($soucty as $pole => $castky) {
            $vysledek = $this->kurzy->doHlavni($castky, $prostor);

            $souhrn[$pole] = $vysledek['celkem'] === null ? null : (int) round($vysledek['celkem']);
            $poMenach[$pole] = (object) $vysledek['poMenach'];
            $texty[$pole] = $vysledek['celkem'] === null
                ? $this->vMenach->poMenachText($vysledek['poMenach'])
                : Meny::castka($vysledek['celkem'], $hlavni);

            $uplne = $uplne && $vysledek['uplne'];
            $prepocteno = $prepocteno || $vysledek['prepocteno'];
            $den = $this->vMenach->starsiDen($den, $vysledek['kurzKeDni']);
            $chybi = array_merge($chybi, $vysledek['chybi']);
        }

        return $souhrn + [
            'kurzKeDni' => $den,
            'popisek' => $this->kurzy->popisek(['prepocteno' => $prepocteno, 'kurzKeDni' => $den]),
            'uplne' => $uplne,
            'chybi' => array_values(array_unique($chybi)),
            'poMenach' => $poMenach,
            'texty' => $texty,
        ];
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
        if (! Tabulky::je('finance_categories')) {
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

    /**
     * Průměrný měsíční příjem každého z dvojice: `[jméno => částka]`.
     *
     * Bere se z příjmů v knize za poslední tři měsíce včetně běžného. Komu
     * příjem patří, říká příjemce transakce, a když chybí, majitel účtu, na
     * který peníze přišly. Průměr je přes měsíce, kdy nějaký příjem přišel —
     * dvojice, která aplikaci používá druhý týden, by jinak měla třetinovou
     * výplatu.
     *
     * Kdo příjem zapsaný nemá, v mapě **není**. Nula by na obrazovce znamenala
     * „nevydělává nic" a dělení podle příjmů by mu přisoudilo nulový podíl.
     *
     * @return array<string, int>
     */
    private function prijmyOsob(GallerySpace $prostor, string $mena): array
    {
        $jmena = System::jmenaClenu($prostor);

        if ($jmena === [] || ! Tabulky::je('partners')) {
            return [];
        }

        $radky = DB::table('transactions as t')
            ->leftJoin('partners as prijemce', 'prijemce.id', '=', 't.beneficiary_partner_id')
            ->leftJoin('wallets as ucet', 'ucet.id', '=', 't.wallet_to_id')
            ->leftJoin('partners as majitel', 'majitel.id', '=', 'ucet.partner_id')
            ->where('t.gallery_space_id', $prostor->id)
            ->where('t.type', 'income')
            ->whereNull('t.deleted_at')
            // Jen zapsané jako v knize: návrh, zamítnutý ani čekající
            // (`pending`) příjem na účet ještě nepřišel.
            ->whereIn('t.state', Transaction::ZAPSANE)
            ->where('t.occurred_at', '>=', Cas::dnes()->startOfMonth()->subMonths(2)->toDateString())
            // Příjem v jiné měně by se k výplatě v korunách přičetl jako koruny.
            ->where(fn ($q) => $q->whereNull('t.currency_to')->orWhere('t.currency_to', $mena))
            ->selectRaw('COALESCE(prijemce.user_id, majitel.user_id) AS kdo, t.occurred_at, t.amount_to')
            ->get();

        $soucty = [];
        $mesice = [];

        foreach ($radky as $r) {
            if ($r->kdo === null || ! isset($jmena[(int) $r->kdo])) {
                continue;
            }

            $kdo = (int) $r->kdo;
            $soucty[$kdo] = ($soucty[$kdo] ?? 0) + (float) $r->amount_to;
            $mesice[$kdo][substr((string) $r->occurred_at, 0, 7)] = true;
        }

        $prijmy = [];

        foreach ($soucty as $kdo => $soucet) {
            if ($soucet > 0) {
                $prijmy[$jmena[$kdo]] = (int) round($soucet / count($mesice[$kdo]));
            }
        }

        return $prijmy;
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
     * Od kdy do kdy seznam sahá — „září 2026" nebo „červenec – září 2026".
     *
     * @param  Collection<int, Transaction>  $pohyby
     */
    private function obdobi(Collection $pohyby): string
    {
        $data = $pohyby->map(fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at ?? $t->booked_on ?? Cas::dnes()));
        $od = $data->min();
        $do = $data->max();

        if ($od === null || $do === null) {
            return '';
        }

        if ($od->format('Y-m') === $do->format('Y-m')) {
            return $this->mesic($do);
        }

        $jmena = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
            'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

        // V rámci jednoho roku stačí rok napsat jednou.
        return $od->year === $do->year
            ? $jmena[$od->month].' – '.$jmena[$do->month].' '.$do->year
            : $this->mesic($od).' – '.$this->mesic($do);
    }

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
        if (! Tabulky::je('budget_settlements') || ! Tabulky::je('budgets')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        // Název i částky partnerova soukromého rozpočtu sem nepatří.
        return DB::table('budget_settlements as v')
            ->join('budgets as r', 'r.id', '=', 'v.budget_id')
            ->whereIn('r.id', $this->viditelneRozpocty($prostor))
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

    /**
     * Rozpočty, které přihlášený vidí — viz `FinanceAccess::viditelneRozpocty()`.
     *
     * @return list<int>
     */
    private function viditelneRozpocty(GallerySpace $prostor): array
    {
        $ja = auth()->id();

        return FinanceAccess::viditelneRozpocty((int) $prostor->id, $ja !== null ? (int) $ja : null);
    }

    /**
     * Částka i s měnou rozpočtu.
     *
     * Makinčin rozpočet na Německo je v eurech; napsat u něj „zbývá 60 Kč" by byla
     * přesně ta tichá nepravda, kvůli které se pak dělají rozhodnutí naslepo.
     * Formát je společný pro celou aplikaci (`Meny::castka()`).
     */
    private function castka(float $castka, string $mena): string
    {
        return Meny::castka($castka, $mena);
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
}
