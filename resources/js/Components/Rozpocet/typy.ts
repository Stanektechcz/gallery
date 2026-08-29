/** Tvary, které posílá `/api/v1/rozpocet`. */

export type Ucet = {
    uuid: string;
    id?: number;
    name: string;
    kind: string;
    kind_label: string;
    currency: string;
    owner: string | null;
    opening_balance: number;
    balance: number;
    is_negative: boolean;
};

export type Kategorie = {
    uuid: string;
    id: number;
    name: string;
    kind: 'expense' | 'income';
    icon: string | null;
    color: string | null;
    is_favourite: boolean;
    default_wallet_id: number | null;
    default_split: string | null;
};

export type Partner = { id: number; uuid: string; name: string; kind: string };

/** Šablona rychlého zápisu. Částku nenese nikdy — ta se pokaždé liší. */
export type Sablona = {
    uuid: string;
    name: string;
    type: 'expense' | 'income';
    category: { uuid: string; name: string; color: string | null } | null;
    wallet: { uuid: string; name: string; currency: string } | null;
    payer: { id: number; name: string } | null;
    split: 'equal' | 'first' | 'second' | null;
    used_count: number;
};

export type Cesta = {
    uuid: string;
    name: string;
    country: string | null;
    city: string | null;
    starts_on: string | null;
    ends_on: string | null;
    days_left: number | null;
    budget: number | null;
    reserve: number | null;
    currency: string;
    default_wallet_id: number | null;
    is_active: boolean;
    state: string;
    owner_user_id?: number | null;
    owner_name?: string | null;
    access?: Pristup[];
};

/**
 * Předvolby modulu — jak se má rozpočet chovat, než mu kdo cokoli řekne.
 *
 * Jen to, co něco doopravdy dělá. Přepínač bez účinku je horší než chybějící:
 * jednou se přepne, nic se nestane a od té chvíle nikdo nevěří ani ostatním.
 */
export type Predvolby = {
    home_currency: string;
    travel_currency: string;
    default_period: string;
    default_tab: string;
    list_density: 'pohodlne' | 'husté';
    default_reserve: number;
    alert_thresholds: string;
    show_partner_balance: boolean;
};

/** Komu je cizí rozpočet nebo cesta nasdílená. */
export type Pristup = { user_id: number; name: string | null; can_edit: boolean };

export type Clen = { id: number; name: string };

export type Ciselniky = {
    categories: Kategorie[];
    wallets: Ucet[];
    balances: Array<{ currency: string; total: number; cash: number; wallets: number }>;
    partners: Partner[];
    trips: Cesta[];
    active_trip: Cesta | null;
    last_used: { wallet_id: number | null; payer_partner_id: number | null; category_id: number | null };
    members: Clen[];
    me: number;
    settings: Predvolby;
};

export type Kurz = {
    direction: 'czk_to_eur' | 'eur_to_czk';
    spent: number; spent_currency: string;
    received: number; received_currency: string;
    effective: number; nominal: number;
    fee: number; fee_currency: string | null; fee_included: boolean;
    eur_per_1000_czk: number | null;
    reference: number | null; reference_gap: number | null;
};

export type Pohyb = {
    uuid: string;
    type: string;
    type_label: string;
    counts_to_budget: boolean;
    occurred_at: string;
    from: { uuid: string | null; name: string; amount: number; currency: string } | null;
    to: { uuid: string | null; name: string; amount: number; currency: string } | null;
    category: { name: string; color: string | null; icon: string | null } | null;
    category_uuid: string | null;
    trip_uuid: string | null;
    payer_partner_id: number | null;
    payer: string | null;
    trip: string | null;
    counterparty: string | null;
    provider: string | null;
    place: string | null;
    description: string | null;
    fee: number;
    fee_currency: string | null;
    fee_included: boolean;
    rate: Kurz | null;
    is_settlement: boolean;
    is_refund: boolean;
    excluded: boolean;
    exclusion_reason: string | null;
    has_split: boolean;
    state: string;
    updated_at: string | null;
};

export type SouhrnMeny = {
    currency: string;
    income: number;
    expense: number;
    fees: number;
    spent: number;
    net: number;
};

export type BezpecneNaDen = {
    state: 'ok' | 'over' | 'ended' | 'not_started' | 'open_ended' | 'reserve_only';
    per_day: number | null;
    days_left: number | null;
    remaining: number;
    over_by: number | null;
    pace_so_far?: number;
    reserve_kept: number;
};

export type StavRozpoctu = {
    name: string | null;
    kind: string;
    currency: string;
    limit: number;
    spent: number;
    refunded: number;
    remaining: number;
    reserve: number;
    percent: number;
    safe_daily: BezpecneNaDen;
    state: 'ok' | 'near' | 'over';
};

export type Prehled = {
    filter: {
        period: string; label: string; from: string; to: string | null;
        days: number | null; trip: Cesta | null;
        chips: Array<{ klic: string; popis: string }>;
    };
    summary: SouhrnMeny[];
    previous: SouhrnMeny[];
    main_currency: string;
    budget: StavRozpoctu | null;
    balances: Array<{ currency: string; total: number; cash: number; wallets: number }>;
    wallets: Ucet[];
    daily: Array<{ date: string; amount: number }>;
    categories: Array<{
        category_id: number | null; category_uuid: string | null; name: string; color: string | null; icon: string | null;
        amount: number; gross: number; refunded: number; count: number; currency: string; percent: number;
    }>;
    partner_balance: {
        by_currency: Array<{
            currency: string;
            partners: Array<{ partner_id: number; name: string; paid: number; owes: number; balance: number }>;
            settlement: Array<{ from: string; from_id: number; to: string; to_id: number; amount: number; currency: string }>;
        }>;
    };
    exchange: {
        acquisition: {
            held_eur: number; known_eur: number; unknown_eur: number;
            cost_czk: number; average_rate: number | null; has_unknown: boolean;
        };
        last: { occurred_at: string; provider: string | null; rate: Kurz | null } | null;
        period_volume: Array<{ currency: string; amount: number }>;
        period_fees: Array<{ currency: string; amount: number }>;
        count: number;
    };
    /**
     * Dnešek. Počítá se vždycky za dnešek, i když je vybrané jiné období —
     * „kolik ještě dnes můžu" je otázka, která na filtru nezávisí.
     */
    today: {
        currency: string;
        spent: number;
        count: number;
        daily_limit: number | null;
        left_today: number | null;
        over_today: number | null;
        last: { uuid: string; amount: number; currency: string; category: string | null; wallet: string | null } | null;
    };
    recent: Pohyb[];
    alerts: Array<{
        key: string; tone: 'warn' | 'danger';
        title: string; body: string;
        action: { label: string; tab: string; filter?: Record<string, string> } | null;
    }>;
    active_trip: Cesta | null;
};
