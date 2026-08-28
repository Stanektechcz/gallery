<?php

namespace App\Services\Finance;

use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
    public function __construct(
        public readonly Carbon $od,
        public readonly ?Carbon $do,
        public readonly string $obdobi,
        public readonly string $popis,
        public readonly ?FinanceProject $cesta = null,
        public readonly array $volby = [],
    ) {}

    /**
     * Poskládá filtr z parametrů dotazu.
     *
     * `obdobi` může být předvolba nebo `vlastni` s daty. Cesta má přednost před
     * kalendářem: kdo se dívá na pobyt, chce vidět celý pobyt, ne jeho průnik
     * s tímhle měsícem.
     */
    public static function zDotazu(array $data, GallerySpace $space, ?Carbon $dnes = null): self
    {
        $dnes ??= Carbon::today();
        $obdobi = $data['obdobi'] ?? 'mesic';

        $cesta = null;

        if (! empty($data['cesta'])) {
            $cesta = FinanceProject::where('gallery_space_id', $space->id)
                ->where('uuid', $data['cesta'])->first();
        } elseif ($obdobi === 'cesta') {
            $cesta = FinanceProject::where('gallery_space_id', $space->id)
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
            default => [$dnes->copy()->startOfMonth(), $dnes->copy()->endOfMonth(), 'Tento měsíc'],
        };

        return new self($od, $do, $obdobi === 'vlastni' ? 'vlastni' : $obdobi, $popis, $cesta, $data);
    }

    /**
     * Srovnatelné předchozí období — pro „o kolik víc než minule".
     *
     * Stejně dlouhé a hned předtím. Porovnávat rozjetý měsíc s celým minulým by
     * třetího v měsíci hlásilo devadesátiprocentní pokles, který se nestal.
     */
    public function predchozi(): ?self
    {
        if ($this->do === null) return null;

        $dni = (int) $this->od->diffInDays($this->do) + 1;

        return new self(
            od: $this->od->copy()->subDays($dni),
            do: $this->od->copy()->subDay(),
            obdobi: $this->obdobi,
            popis: 'Předchozí období',
            cesta: null,
            volby: $this->volby,
        );
    }

    /** Kolik dní období má. Null u otevřeného konce. */
    public function dni(): ?int
    {
        return $this->do ? (int) $this->od->diffInDays($this->do) + 1 : null;
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
