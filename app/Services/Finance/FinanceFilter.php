<?php

namespace App\Services\Finance;

use App\Models\Budget;
use App\Models\FinanceAccess;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Support\Cas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Jeden filtr pro všechny taby.
 *
 * Zadání to říká výslovně: „stejné období, cesta, účet nebo partner musí mít stejný
 * význam ve všech tabech". Kdyby si každá obrazovka počítala „tento měsíc" po svém,
 * dřív nebo později by Přehled ukázal jinou sumu než Statistiky — obě správně podle
 * svého výpočtu, a přitom by si odporovaly. Rozdíl by přitom nebyl vidět nikde;
 * jenom by čísla neseděla a nešlo by zjistit proč.
 *
 * Rozsah se proto počítá tady a všechno ostatní ho jen dostane hotový.
 */
class FinanceFilter
{
    /** Nejdelší vlastní období — deset let, jako u přehledu financí prostoru. */
    private const NEJDELSI_OBDOBI_DNI = 3660;

    public function __construct(
        public readonly Carbon $od,
        public readonly ?Carbon $do,
        public readonly string $obdobi,
        public readonly string $popis,
        public readonly ?FinanceProject $cesta = null,
        public readonly array $volby = [],
        // Filtr podle cesty, kterou uživatel nevidí — výběr je prázdný.
        public readonly bool $prazdny = false,
    ) {}

    /**
     * Dnešek dvojice — jediný „dnes" celého modulu.
     *
     * `occurred_at` je datum, jak ho člověk zapsal podle pražských hodin. Server běží
     * v UTC, takže `Carbon::today()` mezi pražskou půlnocí a druhou ráno ukazoval ještě
     * včerejšek: útrata zapsaná prvního října po půlnoci nebyla v „dnes" ani v „tomto
     * měsíci" a přehled tvrdil, že se v říjnu ještě nic neutratilo.
     */
    public static function dnes(): Carbon
    {
        return Carbon::instance(Cas::dnes());
    }

    /**
     * Poskládá filtr z parametrů dotazu.
     *
     * `obdobi` může být předvolba nebo `vlastni` s daty. Cesta má přednost před
     * kalendářem: kdo se dívá na pobyt, chce vidět celý pobyt, ne jeho průnik
     * s tímhle měsícem.
     */
    public static function zDotazu(array $data, GallerySpace $space, ?Carbon $dnes = null, ?int $uzivatel = null): self
    {
        self::zkontroluj($data);

        $dnes ??= self::dnes();
        $uzivatel ??= auth()->id();
        $obdobi = $data['obdobi'] ?? 'mesic';

        $cesta = null;

        /*
         * Cesta, kterou uživatel nevidí (cizí soukromá nebo neexistující), dá prázdný
         * výběr — ne „všechno bez filtru". Dřív se filtr podle ní tiše zahodil a
         * seznam „za tuhle cestu" ukázal všechny zápisy prostoru.
         */
        $prazdny = false;

        if (! empty($data['cesta'])) {
            $cesta = self::viditelneCesty($space, $uzivatel)->where('uuid', $data['cesta'])->first();
            $prazdny = $cesta === null;
        } elseif ($obdobi === 'cesta') {
            $cesta = self::viditelneCesty($space, $uzivatel)
                ->where('kind', 'trip')->where('is_active', true)->first();
        }

        if ($cesta && in_array($obdobi, ['cesta', 'konkretni-cesta'], true)) {
            return new self(
                od: $cesta->starts_on->copy(),
                do: $cesta->ends_on?->copy(),
                obdobi: 'cesta',
                popis: $cesta->name,
                cesta: $cesta,
                volby: $data,
            );
        }

        if ($obdobi === 'vlastni') {
            self::zkontrolujRozsah($data, $dnes);
        }

        [$od, $do, $popis] = match ($obdobi) {
            'dnes' => [$dnes->copy(), $dnes->copy(), 'Dnes'],
            'tyden' => [$dnes->copy()->startOfWeek(), $dnes->copy()->endOfWeek(), 'Tento týden'],
            'minuly-mesic' => [
                $dnes->copy()->subMonthNoOverflow()->startOfMonth(),
                $dnes->copy()->subMonthNoOverflow()->endOfMonth(),
                'Minulý měsíc',
            ],
            'vlastni' => [
                Carbon::parse($data['od'] ?? $dnes->copy()->startOfMonth()),
                Carbon::parse($data['do'] ?? $dnes),
                'Vlastní období',
            ],
            /*
             * Celé období běžícího rozpočtu.
             *
             * Půlroční pobyt se do „tohoto měsíce" nevejde. Kdo jede s jednou sumou na
             * šest měsíců, potřebuje vidět celou dobu — jinak by proti půlročnímu
             * rozpočtu stály útraty za pár dnů a zbývalo by pořád skoro všechno.
             */
            'obdobi-rozpoctu' => (function () use ($space, $dnes) {
                $dotaz = fn () => Budget::where('gallery_space_id', $space->id)
                    ->where('scope', 'ledger')->whereNotNull('ends_on');

                // Přednost má běžící rozpočet. Když žádný neběží, vezme se nejbližší
                // budoucí — týden před odjezdem je „celé období" to jediné, co dává
                // smysl, a spadnout přitom na aktuální měsíc znamená ukázat prázdno.
                $b = $dotaz()->whereDate('starts_on', '<=', $dnes)->whereDate('ends_on', '>=', $dnes)
                    ->orderByDesc('starts_on')->first()
                    ?? $dotaz()->whereDate('starts_on', '>', $dnes)->orderBy('starts_on')->first();

                return $b === null
                    ? [$dnes->copy()->startOfMonth(), $dnes->copy()->endOfMonth(), 'Tento měsíc']
                    : [$b->starts_on->copy(), $b->ends_on->copy(), 'Celé období rozpočtu'];
            })(),
            default => [$dnes->copy()->startOfMonth(), $dnes->copy()->endOfMonth(), 'Tento měsíc'],
        };

        return new self($od, $do, $obdobi === 'vlastni' ? 'vlastni' : $obdobi, $popis, $cesta, $data, $prazdny);
    }

    /**
     * Parametry filtru, jak přišly z adresy.
     *
     * Jen řetězce a rozumná data. Pole místo řetězce (`typ[]=…`) shodilo štítky
     * i dotaz a `od=abc` spadlo na nerozluštitelném datu — obojí jako chyba 500.
     */
    private static function zkontroluj(array $data): void
    {
        $retezec = 'nullable|string|max:200';

        Validator::make($data, [
            'obdobi' => 'nullable|string|max:40',
            'od' => 'nullable|date',
            'do' => 'nullable|date',
            'cesta' => $retezec,
            'typ' => $retezec,
            'mena' => $retezec,
            'ucet' => $retezec,
            'kategorie' => $retezec,
            'platce' => $retezec,
            'prijemce' => $retezec,
            'misto' => $retezec,
            'hledat' => $retezec,
            'od_castky' => 'nullable|numeric',
            'do_castky' => 'nullable|numeric',
        ])->validate();
    }

    /**
     * Vlastní období: od před do a nejvýš deset let.
     *
     * Denní přehled má řádek za každý den rozsahu. `od=0001-01-01&do=9999-12-31`
     * by jich vyrobil přes tři miliony a požadavek by padl na paměti. Deset let je
     * stejná mez jako u přehledu financí prostoru.
     */
    private static function zkontrolujRozsah(array $data, Carbon $dnes): void
    {
        $od = Carbon::parse($data['od'] ?? $dnes->copy()->startOfMonth());
        $do = Carbon::parse($data['do'] ?? $dnes);

        if ($do->lessThan($od)) {
            throw ValidationException::withMessages(['do' => 'Konec období musí být až po jeho začátku.']);
        }

        if ($od->diffInDays($do) > self::NEJDELSI_OBDOBI_DNI) {
            throw ValidationException::withMessages(['od' => 'Jedno období může mít nejvýše deset let.']);
        }
    }

    /** Cesty prostoru, které uživatel smí vidět. Bez uživatele (konzole) všechny. */
    private static function viditelneCesty(GallerySpace $space, ?int $uzivatel): Builder
    {
        $dotaz = FinanceProject::where('gallery_space_id', $space->id);

        return $uzivatel === null ? $dotaz : FinanceAccess::viditelne($dotaz, 'trip', $uzivatel);
    }

    /**
     * Srovnatelné předchozí období — pro „o kolik víc než minule".
     *
     * Stejně dlouhé a hned předtím. Porovnávat rozjetý měsíc s celým minulým by
     * třetího v měsíci hlásilo devadesátiprocentní pokles, který se nestal.
     */
    public function predchozi(): ?self
    {
        if ($this->do === null) {
            return null;
        }

        $dni = (int) $this->od->diffInDays($this->do) + 1;

        return new self(
            od: $this->od->copy()->subDays($dni),
            do: $this->od->copy()->subDay(),
            obdobi: $this->obdobi,
            popis: 'Předchozí období',
            cesta: null,
            volby: $this->volby,
            prazdny: $this->prazdny,
        );
    }

    /** Kolik dní období má. Null u otevřeného konce. */
    public function dni(): ?int
    {
        return $this->do ? (int) $this->od->diffInDays($this->do) + 1 : null;
    }

    /**
     * Kolik dní z období už uběhlo, včetně dneška.
     *
     * Průměr na den se musí dělit tímhle, ne délkou období. Prvního v měsíci uběhl
     * jeden den — dělit třiceti by znamenalo ukázat třicetinu toho, co člověk ten den
     * doopravdy utratil, a průměr by se do pravdy dostal až poslední den v měsíci.
     *
     * U období, které skončilo, je to jeho celá délka. U budoucího aspoň jeden den,
     * aby se nedělilo nulou.
     */
    public function dniUteklo(?Carbon $dnes = null): int
    {
        $dnes ??= self::dnes();
        $konec = $this->do === null ? $dnes : $dnes->copy()->min($this->do);

        return max(1, (int) $this->od->diffInDays($konec, false) + 1);
    }

    /**
     * Dotaz na pohyby v tomhle rozsahu i s rozšířenými filtry.
     *
     * Načítá i vazby, které všechny výpočty potřebují. Bez nich by se u tisíce
     * transakcí spustila tisícovka dotazů na peněženku a stránka by se nenačetla.
     */
    public function dotaz(GallerySpace $space): Builder
    {
        return Transaction::where('gallery_space_id', $space->id)
            ->with(['walletFrom:id,name,currency,partner_id,kind', 'walletTo:id,name,currency,partner_id,kind',
                'category:id,uuid,name,color,icon', 'shares', 'refundOf:id,category_id',
                'payer:id,name', 'project:id,name,uuid'])
            ->when($this->prazdny, fn ($q) => $q->whereRaw('1 = 0'))
            ->whereDate('occurred_at', '>=', $this->od)
            ->when($this->do, fn ($q) => $q->whereDate('occurred_at', '<=', $this->do))
            ->when($this->cesta, fn ($q) => $q->where('finance_project_id', $this->cesta->id))
            ->when($this->volby['typ'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($this->volby['mena'] ?? null, fn ($q, $m) => $q->where(
                fn ($v) => $v->where('currency_from', $m)->orWhere('currency_to', $m)
            ))
            ->when($this->volby['ucet'] ?? null, fn ($q, $u) => $q->where(fn ($v) => $v
                ->whereHas('walletFrom', fn ($x) => $x->where('uuid', $u))
                ->orWhereHas('walletTo', fn ($x) => $x->where('uuid', $u))))
            ->when($this->volby['kategorie'] ?? null, fn ($q, $k) => $q
                ->whereHas('category', fn ($x) => $x->where('uuid', $k)))
            ->when($this->volby['platce'] ?? null, fn ($q, $p) => $q->where('payer_partner_id', $p))
            ->when($this->volby['prijemce'] ?? null, fn ($q, $p) => $q->where('beneficiary_partner_id', $p))
            ->when($this->volby['misto'] ?? null, fn ($q, $m) => $q->where('place', 'like', "%{$m}%"))
            ->when($this->volby['od_castky'] ?? null, fn ($q, $c) => $q->where('amount_from', '>=', $c))
            ->when($this->volby['do_castky'] ?? null, fn ($q, $c) => $q->where('amount_from', '<=', $c))
            ->when($this->volby['hledat'] ?? null, fn ($q, $h) => $q->where(fn ($v) => $v
                ->where('description', 'like', "%{$h}%")
                ->orWhere('counterparty', 'like', "%{$h}%")
                ->orWhere('place', 'like', "%{$h}%")));
    }

    /** Popis filtru pro obrazovku — z čeho se skládají odnímatelné štítky. */
    public function stitky(): array
    {
        $s = [['klic' => 'obdobi', 'popis' => $this->popis]];

        foreach ([
            'typ' => 'Typ', 'mena' => 'Měna', 'ucet' => 'Účet', 'kategorie' => 'Kategorie',
            'platce' => 'Platil', 'prijemce' => 'Náleží', 'misto' => 'Místo', 'hledat' => 'Hledání',
        ] as $klic => $nazev) {
            if (! empty($this->volby[$klic])) {
                $s[] = ['klic' => $klic, 'popis' => $nazev.': '.$this->volby[$klic]];
            }
        }

        return $s;
    }
}
