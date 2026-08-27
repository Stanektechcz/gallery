import CerpaniGraf, { type Cerpani } from '@/Components/Budgets/CerpaniGraf';
import DeleteButton from '@/Components/DeleteButton';
import { hlaska } from '@/Components/Hlasky';
import Panel, { PanelGrid, Stat } from '@/Components/Panel';
import SekceNav, { type Sekce as SekceTyp } from '@/Components/SekceNav';
import { dny, pocet, polozky as pocetPolozek } from '@/lib/cestina';
import { naSirokeObrazovce } from '@/lib/zobrazeni';
import AppLayout from '@/Layouts/AppLayout';
import { uploadManager, waitForUploads } from '@/lib/uploadManager';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle, ArrowRightLeft, BarChart3, CalendarDays, Check, Download, LayoutDashboard, ListPlus,
    Pencil, PieChart, PiggyBank, Plus, Receipt, Repeat, Scale, Search, Settings2, Sparkles, Tags,
    Target, TrendingDown, TrendingUp, Upload, Wallet, X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import StatementImport from './StatementImport';

/**
 * Rozpočty na období.
 *
 * Finance v aplikaci byly navázané na cestu. Půl roku v cizině není cesta: je tam příjem,
 * nájem, druhá měna a partner o dva státy dál, kterého je občas potřeba požádat o peníze.
 *
 * Každá částka si drží svou měnu a všechny součty jsou po měnách zvlášť. To je to, co
 * obrazovka ukazuje jako pravdu, protože jedině to sedí na haléř.
 *
 * Vedle toho se počítá i součet přes měny — kurzem ECB, s datem, výslovně jako odhad.
 * Bez něj se totiž na otázku „kolik jsme dohromady utratili" nedá odpovědět vůbec, a
 * když jeden platí v eurech a druhý v korunách, je to ta nejčastější otázka.
 */

interface BudgetRow {
    uuid: string; name: string; currency: string;
    starts_on: string; ends_on: string | null;
    is_shared: boolean; is_mine: boolean; owner: string | null;
}

interface MoneyRow {
    uuid: string; amount: number; currency: string;
    settled_amount: number | null; settled_currency: string | null; exchange_rate: number | null;
    reason: string | null; status: string; response_note: string | null;
    created_at: string | null; from: string | null; to: string | null; mine: boolean;
}

interface Overview {
    budget: { uuid: string; name: string; currency: string; starts_on: string; ends_on: string | null; monthly_income: number | null; starting_funds: number | null; note: string | null; is_shared: boolean; owner: { id: number; name: string } | null;
        savings_target: number | null; savings_target_on: string | null; period_unit: 'month' | 'week'; period_mode: 'fixed' | 'rolling'; period_label: string };
    period: { days_elapsed: number; days_left: number | null; days_total: number | null; has_started: boolean; has_ended: boolean };
    totals: {
        spent: Record<string, number>; income: Record<string, number>;
        /** Součet přes měny. Null, když kurz chybí nebo je všechno v jedné měně. */
        spent_combined: { total: number; currency: string; date: string; rates: Record<string, number> } | null;
    };
    categories: Array<{
        id: number; name: string; color: string | null;
        planned_monthly: number; planned_to_date: number; spent: number;
        /** Kolik z plánu k dnešku zbývá. Záporné znamená překročeno. */
        left: number;
        /** Nevyčerpané se přenáší do dalšího měsíce — obálka na nepravidelný výdaj. */
        rollover: boolean;
        /** Předvyplní se u nové položky. Null znamená „vybrat pokaždé". */
        default_split?: string | null;
        used_percent: number | null;
    }>;
    months: Array<{ month: string; spent: number; income: number; count: number; other_currency_count?: number }>;
    allowance: { planned_total: number; spent: number; left: number; per_day: number | null; days_left: number | null; currency: string };
    warnings?: Array<{ category: string; spent: number; planned_to_date: number; percent: number; level: 'close' | 'over' }>;
    settlement?: Array<{ currency: string; from: string | null; from_id?: number | null; to: string; to_id: number; amount: number; since: string | null }>;
    settlements?: Array<{ uuid: string; currency: string; amount: number; settled_through: string; from: string | null; to: string | null }>;
    settlement_combined?: { total: number; currency: string; date: string; rates: Record<string, number>; from: string | null; to: string | null } | null;
    runway?: { per_day: number; days_left: number; runs_out_on: string; covers_period: boolean } | null;
    savings?: { target: number; saved: number; missing: number; percent: number; target_on: string | null; days_left: number | null; monthly_needed: number | null; overdue: boolean; reached: boolean } | null;
    comparison?: {
        previous_month: string; current_month: string; currency: string;
        rows: Array<{ name: string; color: string | null; previous: number; current: number; diff: number; diff_percent: number | null }>;
        total_previous: number; total_current: number; total_diff: number;
    } | null;
    /** Co se každý měsíc samo připisuje. Prázdné pole je běžný stav. */
    recurring?: Array<{
        uuid: string; kind: string; amount: number; currency: string;
        note: string | null; category: string | null; day_of_month: number;
        split: 'none' | 'equal' | 'other'; paid_by: string | null;
        last_on: string; occurrences: number;
    }>;
    /** Co teprve přijde. Null, když rozpočet nemá konec — pak není co předpovídat. */
    outlook?: {
        currency: string; horizon_days: number; ends_on: string; days_left: number;
        upcoming: Array<{
            note: string | null; category: string | null; kind: string;
            amount: number; currency: string; due_on: string; days_away: number;
            in_budget_currency: boolean;
        }>;
        recurring_expense: number; recurring_income: number; variable_estimate: number;
        projected_left: number; verdict: 'ok' | 'tight' | 'short';
    } | null;
    /**
     * Fond — jedna suma na celý pobyt. Null u rozpočtu, který stojí na měsíčním příjmu.
     *
     * `free` je zbytek po odečtení pravidelných plateb, které do konce ještě přijdou;
     * `per_day` se počítá z něj, ne ze zůstatku. Null znamená, že žádná denní částka
     * neexistuje, protože už teď chybí na závazky.
     */
    fund?: {
        currency: string;
        starting: number; added: number; spent: number; left: number;
        committed: number;
        commitments: Array<{ note: string | null; category: string | null; amount: number; times: number; total: number; next_on: string }>;
        free: number;
        plan_monthly: number; plan_rest: number | null; plan_spare: number | null;
        affordable_monthly: number | null;
        per_day: number | null; per_month: number | null;
        pace_per_day: number; variable_estimate: number | null; projected_left: number | null;
        starts_on: string; ends_on: string | null;
        days_total: number | null; days_gone: number; days_left: number | null;
        runs_out_in_days: number | null; runs_out_on: string | null;
        verdict: 'ok' | 'tight' | 'short' | 'committed_short' | 'unknown';
        other_currencies: Record<string, number>;
    } | null;
    /** Průběh čerpání den po dni. Null bez konce období nebo bez plánu. */
    burndown?: Cerpani | null;
    /** Kdo kolik zaplatil — všechny výdaje, ne jen dělené. */
    by_payer?: Array<{ id: number; name: string; count: number; currencies: Record<string, number>; main: number }>;
    /** Účastníci i s příjmem. `share` je null, dokud se poměr nedá spočítat. */
    members?: Array<{ user_id: number; name: string; monthly_income: number | null; currency: string; share: number | null }>;
    /** Kdo utrácí za co. Null u jednoho člověka. */
    category_by_payer?: {
        currency: string;
        people: Array<{ id: number; name: string }>;
        rows: Array<{ category_id: number | null; name: string; total: number; by_payer: Record<string, number> }>;
    } | null;
    /** Vývoj salda mezi dvěma lidmi po měsících. */
    balance_trend?: {
        currency: string;
        first: { id: number; name: string };
        second: { id: number; name: string };
        points: Array<{ month: string; change: number; balance: number }>;
    } | null;
    /** Cíle spoření. Několik zvlášť, ne jedno číslo na rozpočet. */
    goals?: Array<{
        uuid: string; name: string; target_amount: number; saved_amount: number;
        currency: string; target_on: string | null; note: string | null;
        percent: number | null; monthly_needed: number | null;
    }>;
    /** Platby, které vypadají jako pravidelné, ale nikdo je tak neoznačil. */
    recurring_candidates?: Array<{
        uuid: string; note: string | null; category: string | null; currency: string;
        average: number; occurrences: number; day_of_month: number; last_on: string;
        entry_uuids: string[];
    }>;
    /** Předpověď výdajů na příští měsíc po kategoriích. */
    prediction?: {
        currency: string; for_month: string; months_measured: number; reliable: boolean;
        total: number; planned_total: number;
        rows: Array<{
            id: number; name: string; color: string | null;
            recurring: number; variable: number; predicted: number;
            trend: 'up' | 'down' | 'flat' | 'unknown'; months: number; planned_monthly: number;
        }>;
    } | null;
    /** Kolik položek rozpočet má. Samotný seznam si načítá vlastní koncový bod. */
    entries_total: number;
}

/** Návrh plánu spočítaný ze skutečných výdajů. Odpovídá /rozpocty/{uuid}/kategorie/navrh. */
interface Navrh {
    months_measured: number;
    currency: string;
    period_label: string;
    suggestions: Array<{ id: number; name: string; current: number; suggested: number; from_entries: number; recurring_part: number }>;
}

/** Jedna položka ze seznamu. Odpovídá tomu, co vrací /rozpocty/{uuid}/polozky. */
interface EntryRow {
    uuid: string; kind: string; amount: number; currency: string; spent_on: string;
    note: string | null; is_recurring: boolean;
    category: string | null; budget_category_id: number | null;
    author: string | null; paid_by: string | null; paid_by_id: number | null;
    split: 'none' | 'equal' | 'other';
    receipt_uuid: string | null;
}

const money = (amount: number, currency: string) =>
    new Intl.NumberFormat('cs-CZ', { style: 'currency', currency, maximumFractionDigits: 2 }).format(amount);

const den = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' });

const datum = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' });

/** „2026-07" → „červenec 2026". Číslo měsíce si nikdo nepřeloží na první pohled. */
const mesic = (klic: string) =>
    new Date(`${klic}-01T12:00:00`).toLocaleDateString('cs-CZ', { month: 'long', year: 'numeric' });

const DELENI: Record<string, string> = { none: 'moje', equal: 'napůl', other: 'za druhého', ratio: 'podle příjmů' };

/** Měny, které nabízíme. Na jednom místě, ať se seznam v zápisu a v úpravě nerozejde. */
const MENY = ['EUR', 'CZK', 'USD', 'GBP', 'PLN', 'CHF'];

const STAV: Record<string, string> = { pending: 'čeká', sent: 'posláno', declined: 'zamítnuto', cancelled: 'zrušeno' };

const FIELD = 'w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2 text-sm text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const LABEL = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

export default function BudgetsIndex() {
    const [budgets, setBudgets] = useState<BudgetRow[]>([]);
    const [requests, setRequests] = useState<MoneyRow[]>([]);
    const [members, setMembers] = useState<Array<{ id: number; name: string }>>([]);
    const [active, setActive] = useState<Overview | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const load = useCallback(async (openUuid?: string) => {
        try {
            const list = await axios.get('/api/v1/rozpocty');
            setBudgets(list.data.budgets ?? []);
            setRequests(list.data.requests ?? []);
            setMembers(list.data.members ?? []);

            const target = openUuid ?? list.data.budgets?.[0]?.uuid;
            if (target) setActive((await axios.get(`/api/v1/rozpocty/${target}`)).data);
            else setActive(null);

            setError('');
        } catch (problem: any) {
            setError(problem?.response?.status === 404
                ? 'Nejprve vytvořte nebo přijměte pozvánku do společného prostoru.'
                : 'Rozpočty se nepodařilo načíst.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { void load(); }, [load]);

    return (
        <AppLayout>
            <Head title="Rozpočty" />

            {/* Šířka je omezená. Na širokém monitoru by se panely roztáhly na metr a
                oko by muselo skákat přes prázdno — čtyři sloupce po pětadvaceti
                centimetrech se nečtou o nic líp než jeden. */}
            <div role="main" className="mx-auto max-w-[1600px] p-4 sm:p-6">
                <header className="mb-5">
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-[var(--color-text-primary)]">
                        <Wallet size={20} className="text-[var(--color-accent)]"/> Rozpočty
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm text-[var(--color-text-secondary)]">
                        Plánování na období — pobyt v cizině, studium, delší cesta. Každá částka si nese svou měnu
                        a nic se nepřepočítává kurzem, který bychom si vymysleli.
                    </p>
                </header>

                {loading && <p className="text-sm text-[var(--color-text-secondary)]">Načítám…</p>}
                {error && <p className="text-sm text-red-400">{error}</p>}

                {! loading && ! error && (
                    <div className="space-y-4">
                        <BudgetPicker budgets={budgets} active={active?.budget.uuid} onPick={uuid => void load(uuid)} onCreated={uuid => void load(uuid)}/>

                        {/* Žádosti o peníze patří do přehledu, ne pod každou sekci —
                            proto jdou dovnitř, ne vedle. */}
                        {active && (
                            <Overview data={active} members={members} requests={requests}
                                onChanged={() => void load(active.budget.uuid)}
                                onDeleted={() => void load()}/>
                        )}

                        {! active && <MoneyRequests requests={requests} members={members} onChanged={() => void load()}/>}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function BudgetPicker({ budgets, active, onPick, onCreated }: {
    budgets: BudgetRow[]; active?: string; onPick: (uuid: string) => void; onCreated: (uuid: string) => void;
}) {
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ name: '', currency: 'EUR', starts_on: '', ends_on: '', monthly_income: '', starting_funds: '', is_shared: true, period_unit: 'month', savings_target: '', savings_target_on: '' });
    const [busy, setBusy] = useState(false);

    const create = async () => {
        if (! form.name.trim() || ! form.starts_on) return;

        setBusy(true);
        try {
            const created = await axios.post('/api/v1/rozpocty', {
                ...form,
                monthly_income: form.monthly_income === '' ? null : Number(form.monthly_income),
                starting_funds: form.starting_funds === '' ? null : Number(form.starting_funds),
                savings_target: form.savings_target === '' ? null : Number(form.savings_target),
                savings_target_on: form.savings_target_on || null,
                ends_on: form.ends_on || null,
            });
            setAdding(false);
            setForm({ name: '', currency: 'EUR', starts_on: '', ends_on: '', monthly_income: '', starting_funds: '', is_shared: true, period_unit: 'month', savings_target: '', savings_target_on: '' });
            onCreated(created.data.budget.uuid);
        } finally { setBusy(false); }
    };

    return (
        <section>
            <div className="flex flex-wrap items-center gap-2">
                {budgets.map(budget => (
                    <button key={budget.uuid} type="button" onClick={() => onPick(budget.uuid)}
                        className={`rounded-xl border px-3 py-2 text-sm transition-colors ${
                            active === budget.uuid
                                ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 text-[var(--color-text-primary)]'
                                : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                        }`}>
                        {budget.name}
                        <span className="ml-2 text-[10px] opacity-70">
                            {budget.currency}{! budget.is_mine && budget.owner ? ` · ${budget.owner}` : ''}
                        </span>
                    </button>
                ))}

                <button type="button" onClick={() => setAdding(v => !v)}
                    className="inline-flex items-center gap-1.5 rounded-xl border border-dashed border-[var(--color-border)] px-3 py-2 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <Plus size={14}/> Nový rozpočet
                </button>
            </div>

            {adding && (
                <div className="mt-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="sm:col-span-2">
                            <label className={LABEL}>Název</label>
                            <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                                placeholder="Německo — půl roku" className={FIELD}/>
                        </div>
                        <div>
                            <label className={LABEL}>Měna</label>
                            <select value={form.currency} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={FIELD}>
                                {['EUR', 'CZK', 'USD', 'GBP', 'PLN', 'CHF'].map(c => <option key={c} value={c}>{c}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className={LABEL}>Od</label>
                            <input type="date" value={form.starts_on} onChange={e => setForm(f => ({ ...f, starts_on: e.target.value }))} className={FIELD}/>
                        </div>
                        <div>
                            <label className={LABEL}>Do (nepovinné)</label>
                            <input type="date" value={form.ends_on} onChange={e => setForm(f => ({ ...f, ends_on: e.target.value }))} className={FIELD}/>
                        </div>
                        <div>
                            <label className={LABEL}>Měsíční příjem</label>
                            <input type="number" inputMode="decimal" value={form.monthly_income}
                                onChange={e => setForm(f => ({ ...f, monthly_income: e.target.value }))} className={FIELD}/>
                        </div>

                        {/* Fond je alternativa k měsíčnímu příjmu, ne doplněk: buď každý
                            měsíc něco přijde, nebo se přijelo s jednou sumou. */}
                        <div>
                            <label className={LABEL}>Suma na celé období</label>
                            <input type="number" inputMode="decimal" value={form.starting_funds}
                                onChange={e => setForm(f => ({ ...f, starting_funds: e.target.value }))}
                                placeholder="např. 8000" className={FIELD}/>
                            <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                Když jedete s pevnou částkou a další příjem nečekáte. Rozpočet pak počítá,
                                jestli vydrží do konce, a kolik z ní zbývá na den.
                            </p>
                        </div>

                        {/* Jednotka plánu. Na čtyřdenní výlet je měsíc špatná míra — plán
                            by se dělil číslem, které s délkou cesty nesouvisí. */}
                        <div>
                            <label className={LABEL}>Plán zadávám</label>
                            <select value={form.period_unit} onChange={e => setForm(f => ({ ...f, period_unit: e.target.value }))} className={FIELD}>
                                <option value="month">za měsíc — delší pobyt</option>
                                <option value="week">za týden — krátká cesta</option>
                            </select>
                        </div>
                        <div>
                            <label className={LABEL}>Chci našetřit (nepovinné)</label>
                            <input type="number" inputMode="decimal" value={form.savings_target}
                                onChange={e => setForm(f => ({ ...f, savings_target: e.target.value }))}
                                placeholder="např. na letenky domů" className={FIELD}/>
                        </div>
                        <div>
                            <label className={LABEL}>Do kdy našetřit</label>
                            <input type="date" value={form.savings_target_on}
                                onChange={e => setForm(f => ({ ...f, savings_target_on: e.target.value }))} className={FIELD}/>
                        </div>
                    </div>

                    <label className="mt-3 flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                        <input type="checkbox" checked={form.is_shared} onChange={e => setForm(f => ({ ...f, is_shared: e.target.checked }))}/>
                        Sdílet s partnerem — jinak rozpočet uvidíte jen vy
                    </label>

                    <div className="mt-3 flex justify-end gap-2">
                        <button type="button" onClick={() => setAdding(false)} className="rounded-xl border border-[var(--color-border)] px-4 py-2 text-sm text-[var(--color-text-secondary)]">Zrušit</button>
                        <button type="button" onClick={() => void create()} disabled={busy || ! form.name.trim() || ! form.starts_on}
                            className="rounded-xl bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                            {busy ? 'Zakládám…' : 'Založit'}
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}

/**
 * Přehled rozpočtu.
 *
 * Panely nejsou pod sebou na celou šířku. Deset karet ve sloupci znamená, že člověk
 * musí scrollovat, aby porovnal plán se skutečností — a porovnávat přes obrazovku
 * nikdo neumí. Vedle sebe patří to, co spolu souvisí: plán vedle toho, kam peníze
 * opravdu tečou, měsíce vedle porovnání dvou z nich.
 *
 * Šířka panelu je podle obsahu, ne podle důležitosti. Zápis položky má šest polí
 * vedle sebe a potřebuje celou šířku; kdo komu dluží je jedna věta a v půlce se ztratí.
 */
/**
 * Nastavení rozpočtu — a jeho smazání.
 *
 * Rozpočet se dosud dal jen založit. Server uměl změnit název, měnu, období, příjem,
 * cíl spoření i sdílení už dřív, ale obrazovka to nikdy nezavolala: špatně zadané datum
 * konce nebo změněný příjem se tedy nedaly opravit jinak než založením nového rozpočtu,
 * a tím se přišlo o všechny zapsané položky. Totéž platilo o mazání — rozpočet založený
 * omylem zůstal v přepínači navždycky.
 *
 * Uloží se jen to, co se opravdu změnilo. Posílat celý formulář by u měny znamenalo
 * přepsat ji stejnou hodnotou i tehdy, když na ni člověk nesáhl, a v historii by to
 * vypadalo jako změna.
 */
/**
 * Příjmy účastníků — podklad pro poměrné dělení.
 *
 * „Napůl" je férové jen tehdy, když oba vydělávají zhruba stejně. Vyplnění je
 * dobrovolné a dokud ho neudělá aspoň dvojice, dělí se dál napůl: hádat, kolik druhý
 * vydělává, je u peněz horší než to nevědět.
 */
function MembersPanel({ budget, members, onChanged }: {
    budget: Overview['budget'];
    members: NonNullable<Overview['members']>;
    onChanged: () => void;
}) {
    const vychozi = Object.fromEntries(members.map(c => [c.user_id, c.monthly_income !== null ? String(c.monthly_income) : '']));
    const [prijmy, setPrijmy] = useState<Record<number, string>>(vychozi);
    const [uklada, setUklada] = useState(false);

    useEffect(() => { setPrijmy(vychozi); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [budget.uuid]);

    const zmeneno = JSON.stringify(prijmy) !== JSON.stringify(vychozi);
    const poměrPlati = members.every(c => c.share !== null) && members.length > 1;

    const uloz = async () => {
        setUklada(true);

        try {
            await axios.put(`/api/v1/rozpocty/${budget.uuid}/ucastnici`, {
                members: members.map(c => ({
                    user_id: c.user_id,
                    monthly_income: prijmy[c.user_id] === '' ? null : Number(prijmy[c.user_id]),
                    currency: budget.currency,
                })),
            });

            hlaska('Příjmy jsou uložené.', 'uspech');
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Příjmy se nepodařilo uložit.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    return (
        <Panel icon={Scale} title="Příjmy a poměrné dělení"
            description="Když je vyplní oba, dá se u položek vybrat dělení podle poměru příjmů — kdo vydělává víc, nese větší část společných výdajů."
            footnote={poměrPlati
                ? `Poměr vychází na ${members.map(c => `${c.name} ${Math.round((c.share ?? 0) * 100)} %`).join(' a ')}.`
                : 'Dokud příjem nevyplní aspoň dva, dělí se „podle poměru" stejně jako napůl. Hádat cizí příjem je horší než ho neznat.'}>

            <div className="space-y-2.5">
                {members.map(clovek => (
                    <div key={clovek.user_id} className="flex items-center gap-2">
                        <label htmlFor={`prijem-${clovek.user_id}`} className="min-w-0 flex-1 truncate text-sm text-[var(--color-text-primary)]">
                            {clovek.name}
                        </label>
                        {clovek.share !== null && (
                            <span className="shrink-0 rounded bg-[var(--color-surface-muted)] px-1.5 py-0.5 text-[11px] tabular-nums text-[var(--color-text-secondary)]">
                                {Math.round(clovek.share * 100)} %
                            </span>
                        )}
                        <input id={`prijem-${clovek.user_id}`} type="number" inputMode="decimal"
                            value={prijmy[clovek.user_id] ?? ''}
                            onChange={e => setPrijmy(s => ({ ...s, [clovek.user_id]: e.target.value }))}
                            placeholder={`měsíčně (${budget.currency})`}
                            className={`${FIELD} w-40 shrink-0`}/>
                    </div>
                ))}
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-[var(--color-border)] pt-3">
                <button type="button" onClick={() => void uloz()} disabled={uklada || ! zmeneno}
                    className="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-3 text-xs font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                    <Check size={14}/> Uložit příjmy
                </button>
                {! zmeneno && <span className="text-[11px] text-[var(--color-text-secondary)]">Zatím není co uložit.</span>}
            </div>
        </Panel>
    );
}

function Settings({ budget, members, onChanged, onDeleted }: {
    budget: Overview['budget'];
    members?: Overview['members'];
    onChanged: () => void;
    onDeleted: () => void;
}) {
    const vychozi = {
        name: budget.name,
        currency: budget.currency,
        starts_on: budget.starts_on,
        ends_on: budget.ends_on ?? '',
        monthly_income: budget.monthly_income !== null ? String(budget.monthly_income) : '',
        starting_funds: budget.starting_funds !== null ? String(budget.starting_funds) : '',
        savings_target: budget.savings_target !== null ? String(budget.savings_target) : '',
        savings_target_on: budget.savings_target_on ?? '',
        period_unit: budget.period_unit,
        period_mode: budget.period_mode ?? 'fixed',
        note: budget.note ?? '',
        is_shared: budget.is_shared,
    };

    const [form, setForm] = useState(vychozi);
    const [uklada, setUklada] = useState(false);

    // Rozpočet se může přepnout pod rukama (jiný v přepínači), a pak musí formulář
    // ukázat ten nový, ne rozpracované hodnoty toho předchozího.
    useEffect(() => { setForm(vychozi); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [budget.uuid]);

    const zmeneno = JSON.stringify(form) !== JSON.stringify(vychozi);

    const uloz = async () => {
        if (! form.name.trim() || ! form.starts_on) return;

        setUklada(true);

        try {
            await axios.patch(`/api/v1/rozpocty/${budget.uuid}`, {
                name: form.name.trim(),
                currency: form.currency,
                starts_on: form.starts_on,
                ends_on: form.ends_on || null,
                monthly_income: form.monthly_income === '' ? null : Number(form.monthly_income),
                starting_funds: form.starting_funds === '' ? null : Number(form.starting_funds),
                savings_target: form.savings_target === '' ? null : Number(form.savings_target),
                savings_target_on: form.savings_target_on || null,
                period_unit: form.period_unit,
                period_mode: form.period_mode,
                note: form.note || null,
                is_shared: form.is_shared,
            });

            hlaska('Nastavení rozpočtu je uložené.', 'uspech');
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Nastavení se nepodařilo uložit.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    return (
        <div className="space-y-4">
            <Panel icon={Wallet} title="Nastavení rozpočtu"
                description="Období, měna a plán. Změna se projeví ve všech přehledech — položky zůstanou, jak jsou."
                footnote="Měna se týká plánu a součtů. Položky si svou měnu nesou samy a přepnutím měny rozpočtu se nepřepočítají.">

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="sm:col-span-2">
                        <label className={LABEL} htmlFor="rozpocet-nazev">Název</label>
                        <input id="rozpocet-nazev" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className={FIELD}/>
                    </div>
                    <div>
                        <label className={LABEL} htmlFor="rozpocet-mena">Měna</label>
                        <select id="rozpocet-mena" value={form.currency} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={FIELD}>
                            {MENY.map(c => <option key={c} value={c}>{c}</option>)}
                        </select>
                    </div>

                    <div>
                        <label className={LABEL} htmlFor="rozpocet-od">Od</label>
                        <input id="rozpocet-od" type="date" value={form.starts_on} onChange={e => setForm(f => ({ ...f, starts_on: e.target.value }))} className={FIELD}/>
                    </div>
                    <div>
                        <label className={LABEL} htmlFor="rozpocet-do">Do</label>
                        <input id="rozpocet-do" type="date" value={form.ends_on} onChange={e => setForm(f => ({ ...f, ends_on: e.target.value }))} className={FIELD}/>
                        <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">Prázdné znamená otevřený konec. Bez něj se nepočítá výhled ani zbytek na den.</p>
                    </div>
                    <div>
                        <label className={LABEL} htmlFor="rozpocet-jednotka">Plán se zadává</label>
                        <select id="rozpocet-jednotka" value={form.period_unit}
                            onChange={e => setForm(f => ({ ...f, period_unit: e.target.value as 'month' | 'week' }))} className={FIELD}>
                            <option value="month">měsíčně</option>
                            <option value="week">týdně</option>
                        </select>
                    </div>

                    {/* Klouzavý režim je pro běžnou domácnost, která nekončí — jen každý
                        měsíc začíná znovu. Bez něj by si člověk musel dvanáctkrát ročně
                        zakládat nový rozpočet. */}
                    <div className="sm:col-span-2 lg:col-span-3">
                        <label className={LABEL} htmlFor="rozpocet-rezim">Jak rozpočet běží</label>
                        <select id="rozpocet-rezim" value={form.period_mode}
                            onChange={e => setForm(f => ({ ...f, period_mode: e.target.value as 'fixed' | 'rolling' }))} className={`${FIELD} lg:w-96`}>
                            <option value="fixed">Pevné období od–do (pobyt, cesta, projekt)</option>
                            <option value="rolling">Klouzavý měsíc — plán se každý první resetuje</option>
                        </select>
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            {form.period_mode === 'rolling'
                                ? 'Přehledy počítají aktuální měsíc. Historie zůstává — měsíc po měsíci, srovnání i předpověď se dívají dál dozadu.'
                                : 'Přehledy počítají celé období od začátku do konce.'}
                        </p>
                    </div>

                    <div>
                        <label className={LABEL} htmlFor="rozpocet-prijem">Příjem {form.period_unit === 'week' ? 'týdně' : 'měsíčně'}</label>
                        <input id="rozpocet-prijem" type="number" inputMode="decimal" value={form.monthly_income}
                            onChange={e => setForm(f => ({ ...f, monthly_income: e.target.value }))} className={FIELD}/>
                    </div>
                    {/* Fond místo příjmu: přijelo se s jednou sumou a další nepřijde. */}
                    <div>
                        <label className={LABEL} htmlFor="rozpocet-fond">Suma na celé období</label>
                        <input id="rozpocet-fond" type="number" inputMode="decimal" value={form.starting_funds}
                            onChange={e => setForm(f => ({ ...f, starting_funds: e.target.value }))}
                            placeholder="např. 8000" className={FIELD}/>
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            {form.starting_funds === ''
                                ? 'Vyplňte, když jedete s pevnou částkou a další příjem nečekáte.'
                                : 'Přehled počítá, jestli suma vydrží do konce, a kolik z ní zbývá na den po odečtení pravidelných plateb.'}
                        </p>
                    </div>
                    <div>
                        <label className={LABEL} htmlFor="rozpocet-cil">Cíl spoření</label>
                        <input id="rozpocet-cil" type="number" inputMode="decimal" value={form.savings_target}
                            onChange={e => setForm(f => ({ ...f, savings_target: e.target.value }))} className={FIELD}/>
                    </div>
                    <div>
                        <label className={LABEL} htmlFor="rozpocet-cil-do">Cíl do</label>
                        <input id="rozpocet-cil-do" type="date" value={form.savings_target_on}
                            onChange={e => setForm(f => ({ ...f, savings_target_on: e.target.value }))} className={FIELD}/>
                    </div>

                    <div className="sm:col-span-2 lg:col-span-3">
                        <label className={LABEL} htmlFor="rozpocet-poznamka">Poznámka</label>
                        <textarea id="rozpocet-poznamka" rows={2} value={form.note}
                            onChange={e => setForm(f => ({ ...f, note: e.target.value }))} className={FIELD}/>
                    </div>
                </div>

                <label className="mt-3 flex cursor-pointer items-start gap-2 text-xs text-[var(--color-text-secondary)]">
                    <input type="checkbox" checked={form.is_shared} onChange={e => setForm(f => ({ ...f, is_shared: e.target.checked }))}
                        className="mt-0.5 h-4 w-4 shrink-0 accent-[var(--color-accent)]"/>
                    <span>
                        <strong className="text-[var(--color-text-primary)]">Sdílet s partnerem</strong>
                        <span className="mt-0.5 block leading-relaxed">Bez toho rozpočet uvidíte jen vy — i společné výdaje a rozvahu.</span>
                    </span>
                </label>

                <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-[var(--color-border)] pt-4">
                    <button type="button" onClick={() => void uloz()} disabled={uklada || ! zmeneno || ! form.name.trim()}
                        className="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                        <Check size={15}/> Uložit změny
                    </button>
                    {zmeneno && (
                        <button type="button" onClick={() => setForm(vychozi)}
                            className="inline-flex min-h-10 items-center rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)]">
                            Vrátit zpět
                        </button>
                    )}
                    {! zmeneno && <span className="text-xs text-[var(--color-text-secondary)]">Zatím není co uložit.</span>}
                </div>
            </Panel>

            {(members?.length ?? 0) > 1 && <MembersPanel budget={budget} members={members!} onChanged={onChanged}/>}

            <Panel tone="danger" icon={AlertTriangle} title="Smazat rozpočet"
                description="Zmizí i všechny jeho položky, kategorie a uzávěrky. Vrátit to nejde.">
                <DeleteButton
                    label={`Smazat rozpočet ${budget.name}`}
                    onDelete={async () => {
                        await axios.delete(`/api/v1/rozpocty/${budget.uuid}`);
                        hlaska(`Rozpočet „${budget.name}" je smazaný.`, 'uspech');
                        onDeleted();
                    }}/>
            </Panel>
        </div>
    );
}

/**
 * Která sekce je otevřená. Drží se v adrese, ne ve stavu.
 *
 * Odkaz na položky pak vede na položky, tlačítko zpět projde sekce po jedné a obnovení
 * stránky nechá člověka tam, kde byl. Ve stavu komponenty by se všechno tohle ztratilo.
 */
function useSekce(vychozi: string): [string, (id: string) => void] {
    const [sekce, setSekce] = useState(() => {
        if (typeof window === 'undefined') return vychozi;

        return new URLSearchParams(window.location.search).get('sekce') ?? vychozi;
    });

    useEffect(() => {
        const zpet = () => setSekce(new URLSearchParams(window.location.search).get('sekce') ?? vychozi);

        window.addEventListener('popstate', zpet);

        return () => window.removeEventListener('popstate', zpet);
    }, [vychozi]);

    const zmen = useCallback((id: string) => {
        setSekce(id);

        const adresa = new URL(window.location.href);

        // Výchozí sekce se do adresy nepíše — `?sekce=prehled` v odkazu nic nepřidává.
        if (id === vychozi) adresa.searchParams.delete('sekce');
        else adresa.searchParams.set('sekce', id);

        window.history.pushState({}, '', adresa);
    }, [vychozi]);

    return [sekce, zmen];
}

function Overview({ data, members, requests, onChanged, onDeleted }: {
    data: Overview;
    members: Array<{ id: number; name: string }>;
    requests: MoneyRow[];
    onChanged: () => void;
    /** Rozpočet zmizel — načíst seznam znovu a otevřít, co zbylo. */
    onDeleted: () => void;
}) {
    const { budget, period, totals, categories, months, allowance } = data;
    const meny = Object.keys({ ...totals.spent, ...totals.income });
    const [sekce, setSekce] = useSekce('prehled');

    // Stavové panely se skládají do pole, protože se každý ukazuje jen někdy. Mřížka
    // o dvou sloupcích s jedním potomkem nechá půlku řádku prázdnou a vypadá jako
    // nedokreslená — tohle podle počtu přepne na jeden sloupec.
    const stav = [
        // Fond úplně první. U rozpočtu z jedné sumy je „vydrží to" hlavní otázka a
        // výhled proti plánu je vedle ní podružný — plán se dá přepsat, hotovost ne.
        data.fund && <FundPanel key="fund" fund={data.fund}/>,
        // Výhled první: „vyjde mi to do konce" je otázka, kvůli které se sem člověk dívá.
        data.outlook && <OutlookPanel key="outlook" outlook={data.outlook}/>,
        data.runway && ! data.runway.covers_period && <RunwayPanel key="runway" runway={data.runway} budget={budget}/>,
        data.warnings && data.warnings.length > 0 && <WarningsPanel key="warnings" warnings={data.warnings} currency={budget.currency}/>,
        ((data.settlement && data.settlement.length > 0) || (data.settlements && data.settlements.length > 0))
            && <SettlementPanel key="settlement" budget={budget} settlement={data.settlement ?? []}
                combined={data.settlement_combined ?? null}
                history={data.settlements ?? []} onChanged={onChanged}/>,
        data.savings && <SavingsPanel key="savings" savings={data.savings} currency={budget.currency}/>,
    ].filter(Boolean);

    const sekce_seznam: SekceTyp[] = [
        { id: 'prehled', label: 'Přehled', icon: LayoutDashboard,
            upozorneni: (data.warnings?.length ?? 0) > 0
                || data.outlook?.verdict === 'short'
                || data.fund?.verdict === 'short'
                || data.fund?.verdict === 'committed_short' },
        { id: 'polozky', label: 'Položky', icon: Receipt, pocet: data.entries_total },
        { id: 'plan', label: 'Plán', icon: Target, pocet: categories.length },
        { id: 'vyvoj', label: 'Vývoj', icon: TrendingUp },
        { id: 'nastaveni', label: 'Nastavení', icon: Settings2 },
    ];

    return (
        <div className="space-y-4">
            <SekceNav sekce={sekce_seznam} aktivni={sekce} onZmena={setSekce}/>

            {/* Dlaždice zůstávají nad sekcemi. Kolik zbývá na den je jediné číslo, které
                v cizině opravdu řídí chování — a mělo by být vidět bez ohledu na to,
                ve které části rozpočtu se člověk zrovna hrabe.

                Na telefonu dva sloupce, ne jeden: čtyři dlaždice pod sebou zabraly
                443 bodů, přes polovinu obrazovky, než začal obsah. */}
            <section className="grid grid-cols-2 gap-2.5 sm:gap-3 xl:grid-cols-4">
                <Stat label="Zbývá na den" icon={CalendarDays} tone="accent"
                    value={allowance.per_day !== null ? money(allowance.per_day, allowance.currency) : '—'}
                    hint={allowance.days_left !== null ? `na ${dny(allowance.days_left)}` : 'bez konce období'}/>
                <Stat label="Zbývá celkem" icon={Wallet} value={money(allowance.left, allowance.currency)}
                    tone={allowance.left < 0 ? 'danger' : 'plain'}
                    hint={`z ${money(allowance.planned_total, allowance.currency)} plánovaných`}/>
                <Stat label="Utraceno" icon={TrendingDown} value={money(allowance.spent, allowance.currency)}
                    hint={meny.length > 1
                        ? <>
                            + {meny.filter(m => m !== budget.currency).map(m => money(totals.spent[m] ?? 0, m)).join(', ')}
                            {/* Součet přes měny se píše jako odhad, protože jím je — kurz
                                je snímek jednoho dne. Bez něj se ale na otázku „kolik
                                dohromady" nedá odpovědět vůbec. */}
                            {totals.spent_combined && (
                                <span className="mt-0.5 block" title={`Kurz ECB z ${datum(totals.spent_combined.date)}`}>
                                    ≈ {money(totals.spent_combined.total, totals.spent_combined.currency)} celkem
                                </span>
                            )}
                        </>
                        : `za ${dny(period.days_elapsed)}`}/>
                <Stat label="Příjem" icon={TrendingUp} value={money(totals.income[budget.currency] ?? 0, budget.currency)}
                    hint={budget.monthly_income
                        ? `plán ${money(budget.monthly_income, budget.currency)} ${budget.period_label}`
                        : 'zatím bez plánu'}/>
            </section>

            {/* Počet sloupců si mřížka odvodí z toho, kolik panelů opravdu přišlo —
                stavové panely se zobrazují podmíněně a prázdná půlka řádku vedle
                osamoceného panelu vypadá, jako by se něco nenačetlo. */}
            {sekce === 'prehled' && (
                <div className="space-y-4">
                    {/* Návrh na označení pravidelné platby jde nad panely: je to jediná
                        věc na téhle obrazovce, která něco chce, a pod čtyřmi panely by
                        ji nikdo nenašel. */}
                    {(data.recurring_candidates?.length ?? 0) > 0 && (
                        <RecurringCandidates budget={budget} rows={data.recurring_candidates!} onChanged={onChanged}/>
                    )}
                    <PanelGrid max={2}>{stav}</PanelGrid>
                    <MoneyRequests requests={requests} members={members} onChanged={onChanged}/>
                </div>
            )}

            {/* Zápis a výpis položek. Celá šířka, protože formulář má šest polí vedle
                sebe — v půlce obrazovky by se zlomil na tři řádky. */}
            {sekce === 'polozky' && (
                <Entries budget={budget} categories={categories} members={members}
                    ucastnici={data.members ?? []} total={data.entries_total} onChanged={onChanged}/>
            )}

            {/* Plán vedle skutečnosti. Dvě odpovědi na jednu otázku — kolik mělo padnout
                a kam to opravdu šlo — a mají smysl jen společně. */}
            {sekce === 'plan' && (
                <div className="space-y-4">
                    <PanelGrid max={2}>
                        <Categories budget={budget} categories={categories} onChanged={onChanged}/>
                        {categories.some(c => c.spent > 0) && <BreakdownPanel categories={categories} currency={budget.currency}/>}
                        {/* Rozdělení mezi lidi patří k rozpadu podle kategorií: obojí je
                            odpověď na „kam to jde", jen jinou osou. */}
                        {(data.by_payer?.length ?? 0) > 1 && <PayerPanel rows={data.by_payer!} currency={budget.currency}/>}
                        {data.category_by_payer && <CrossPanel data={data.category_by_payer}/>}
                    </PanelGrid>

                    {(data.recurring?.length ?? 0) > 0 && (
                        <RecurringPanel budget={budget} rows={data.recurring!} onChanged={onChanged}/>
                    )}

                    <GoalsPanel budget={budget} goals={data.goals ?? []} onChanged={onChanged}/>
                </div>
            )}

            {/* Čas: nejdřív průběh celého období jednou křivkou, pak měsíce po sobě
                a nakonec dva měsíce vedle sebe. Od hrubého k podrobnému. */}
            {sekce === 'vyvoj' && (
                <div className="space-y-4">
                    {data.burndown && <CerpaniPanel data={data.burndown}/>}

                    <PanelGrid max={2}>
                        {data.prediction && <PredictionPanel data={data.prediction}/>}
                        {months.length > 0 && <MonthsPanel months={months} currency={budget.currency}/>}
                        {data.comparison && <Comparison data={data.comparison}/>}
                        {data.balance_trend && <BalanceTrendPanel data={data.balance_trend}/>}
                    </PanelGrid>
                </div>
            )}

            {sekce === 'nastaveni' && (
                <Settings budget={budget} members={data.members} onChanged={onChanged} onDeleted={onDeleted}/>
            )}
        </div>
    );
}

/** Kdy při současném tempu dojdou peníze. Ukazuje se jen tehdy, když to nevyjde. */
/**
 * Jak období dopadne — a co přijde nejdřív.
 *
 * Zbytek přehledu se dívá dozadu. Tenhle panel odpovídá na otázku, kterou si člověk
 * v cizině klade doopravdy: vyjde mi to? „Zbývá na den" tvrdí, že ano, i když třetího
 * přijde nájem, který denní zbytek spolkne celý.
 *
 * Jisté a odhadnuté se drží odděleně a je to napsané: pravidelné platby známe jménem
 * i datem, tempo nepravidelných výdajů je odhad z dosavadního průběhu. Slepit obojí do
 * jednoho čísla by vypadalo přesněji, než to je.
 */
/**
 * Fond — jedna suma na celý pobyt.
 *
 * Nejdřív dvojice pruhů: kolik peněz je pryč proti tomu, kolik času uplynulo. Když
 * peníze utíkají rychleji než dny, je to vidět dřív, než to spočítá jakákoli
 * předpověď — a je to jediné srovnání, které dává smysl i bez znalosti plánu.
 *
 * Denní částka se ukazuje až pod závazky, ne nad nimi. Pořadí je tu obsah: „zbývá
 * 3000" a „na den 30" vedle sebe svádí k tomu spočítat si to zpaměti a nezahrnout
 * nájem. Závazky mezi tím říkají, proč to číslo vyšlo jinak.
 */
function FundPanel({ fund }: { fund: NonNullable<Overview['fund']> }) {
    const f = fund;
    const spatne = f.verdict === 'short' || f.verdict === 'committed_short';
    const ton = spatne ? 'danger' : f.verdict === 'tight' ? 'warn' : 'accent';

    const nadpis = f.verdict === 'committed_short'
        ? 'Nezbývá ani na pravidelné platby'
        : f.verdict === 'short'
            ? 'S touhle sumou to do konce nevyjde'
            : f.verdict === 'tight'
                ? 'Vyjde to těsně'
                : 'Suma na celé období vychází';

    // Podíly pro pruhy. Utracené se poměřuje s tím, co bylo k dispozici celkem —
    // tedy včetně toho, co během pobytu přišlo, jinak by připoslané peníze vypadaly
    // jako utrácení navíc.
    const kDispozici = f.starting + f.added;
    const utracenoPct = kDispozici > 0 ? Math.min(100, Math.round(f.spent / kDispozici * 100)) : 0;
    const casPct = f.days_total && f.days_total > 0
        ? Math.min(100, Math.round(f.days_gone / f.days_total * 100))
        : null;

    // Napřed = utrácí se rychleji, než ubývá čas. O pár procent to kolísá vždycky,
    // takže se hlásí až rozdíl, který znamená něco jiného než zaokrouhlení.
    const napred = casPct !== null && utracenoPct - casPct >= 5;

    return (
        <Panel tone={ton} icon={spatne ? AlertTriangle : PiggyBank} title={nadpis}
            footnote="Pravidelné platby se odečítají dopředu v plné výši — nájem, na který ještě nedošlo, nejsou volné peníze. Tempo nepravidelných výdajů je odhad z dosavadního průběhu.">

            <p className="text-2xl font-semibold tabular-nums text-[var(--color-text-primary)]">
                {money(f.left, f.currency)}
            </p>
            <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">
                zbývá z {money(f.starting, f.currency)}
                {f.added > 0 && <> a {money(f.added, f.currency)} připoslaných</>}
                {f.days_left !== null && <> · do konce {dny(f.days_left)}</>}
            </p>

            {casPct !== null && (
                <div className="mt-3 space-y-1.5">
                    <Pruh label="utraceno" procenta={utracenoPct}
                        barva={napred ? 'var(--graf-2)' : 'var(--graf-1)'}/>
                    <Pruh label="uplynulo" procenta={casPct} barva="var(--color-text-secondary)"/>
                    {napred && (
                        <p className="pt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                            Peníze ubývají rychleji než dny — utraceno {utracenoPct} % při {casPct} % pobytu.
                        </p>
                    )}
                </div>
            )}

            {f.committed > 0 && (
                <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                    <div className="flex items-baseline justify-between gap-2 text-sm">
                        <span className="text-[var(--color-text-secondary)]">Pravidelné platby do konce</span>
                        <span className="shrink-0 tabular-nums text-[var(--color-text-primary)]">
                            − {money(f.committed, f.currency)}
                        </span>
                    </div>
                    <ul className="mt-1.5 space-y-1">
                        {f.commitments.map((z, i) => (
                            <li key={`${z.note}-${i}`} className="flex items-baseline justify-between gap-2 text-[11px] text-[var(--color-text-secondary)]">
                                <span className="truncate">
                                    {z.note ?? z.category ?? 'Pravidelná platba'} · {z.times}× {money(z.amount, f.currency)}
                                </span>
                                <span className="shrink-0 tabular-nums">{money(z.total, f.currency)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                {f.per_day !== null && f.per_month !== null ? (
                    <>
                        <div className="flex items-baseline justify-between gap-2">
                            <span className="text-sm text-[var(--color-text-secondary)]">
                                {f.committed > 0 ? 'Zbývá volných' : 'K dispozici'}
                            </span>
                            <span className="shrink-0 tabular-nums font-medium text-[var(--color-text-primary)]">
                                {money(f.free, f.currency)}
                            </span>
                        </div>
                        <p className="mt-1.5 text-sm text-[var(--color-text-primary)]">
                            <strong className="tabular-nums">{money(f.per_day, f.currency)}</strong>
                            <span className="text-[var(--color-text-secondary)]"> na den</span>
                            <span className="text-[var(--color-text-secondary)]">, tedy </span>
                            <strong className="tabular-nums">{money(f.per_month, f.currency)}</strong>
                            <span className="text-[var(--color-text-secondary)]"> měsíčně</span>
                        </p>
                        <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                            {f.committed > 0
                                ? 'Na všechno kromě pravidelných plateb — ty jsou už odečtené.'
                                : 'Na všechno včetně nájmu — žádná pravidelná platba zatím není zapsaná.'}
                        </p>
                    </>
                ) : (
                    <p className="text-sm text-[var(--color-text-primary)]">
                        {f.free < 0
                            ? <>Na pravidelné platby do konce chybí <strong className="tabular-nums">{money(Math.abs(f.free), f.currency)}</strong>.</>
                            : 'Období skončilo, denní částka se už nepočítá.'}
                    </p>
                )}
            </div>

            {/* Plán proti tomu, co si fond může dovolit. Tohle je jediná část, která
                funguje i den před odjezdem, kdy se ještě nic neutratilo. */}
            {f.plan_monthly > 0 && f.plan_spare !== null && f.affordable_monthly !== null && (
                <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                    <div className="flex items-baseline justify-between gap-2 text-sm">
                        <span className="text-[var(--color-text-secondary)]">Plán počítá měsíčně</span>
                        <span className="shrink-0 tabular-nums text-[var(--color-text-primary)]">{money(f.plan_monthly, f.currency)}</span>
                    </div>
                    <div className="mt-1 flex items-baseline justify-between gap-2 text-sm">
                        <span className="text-[var(--color-text-secondary)]">Fond unese měsíčně</span>
                        <span className={`shrink-0 tabular-nums ${f.affordable_monthly >= f.plan_monthly ? 'text-emerald-400' : 'text-red-400'}`}>
                            {money(f.affordable_monthly, f.currency)}
                        </span>
                    </div>
                    <p className="mt-1.5 text-[11px] text-[var(--color-text-secondary)]">
                        {f.plan_spare >= 0
                            ? <>Podle plánu zbude do konce {money(f.plan_spare, f.currency)}.</>
                            : <>Podle plánu bude do konce chybět {money(Math.abs(f.plan_spare), f.currency)}.</>}
                    </p>
                </div>
            )}

            {f.runs_out_on && (
                <p className="mt-3 rounded-xl border border-red-500/30 bg-[var(--color-surface-muted)] px-3 py-2 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    Při současném tempu peníze dojdou <strong className="text-[var(--color-text-primary)]">{datum(f.runs_out_on)}</strong>
                    {f.ends_on && <>, tedy {dny(Math.max(0, (f.days_left ?? 0) - (f.runs_out_in_days ?? 0)))} před koncem</>}.
                </p>
            )}

            {Object.keys(f.other_currencies).length > 0 && (
                <p className="mt-3 text-[11px] text-[var(--color-text-secondary)]">
                    Mimo fond ještě{' '}
                    {Object.entries(f.other_currencies).map(([m, c]) => money(c, m)).join(', ')}
                    {' '}v jiné měně — do součtu se nepočítá, aby se nemusel hádat kurz.
                </p>
            )}
        </Panel>
    );
}

/** Vodorovný pruh s popiskem. Dvě čísla pod sebou se porovnávají líp než dvě procenta v textu. */
function Pruh({ label, procenta, barva }: { label: string; procenta: number; barva: string }) {
    return (
        <div className="flex items-center gap-2">
            <span className="w-16 shrink-0 text-[11px] text-[var(--color-text-secondary)]">{label}</span>
            <div className="h-2 flex-1 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                <div className="h-full rounded-full" style={{ width: `${procenta}%`, background: barva }}/>
            </div>
            <span className="w-9 shrink-0 text-right text-[11px] tabular-nums text-[var(--color-text-secondary)]">{procenta} %</span>
        </div>
    );
}

function OutlookPanel({ outlook }: { outlook: NonNullable<Overview['outlook']> }) {
    const ton = outlook.verdict === 'short' ? 'danger' : outlook.verdict === 'tight' ? 'warn' : 'accent';

    const nadpis = outlook.verdict === 'short'
        ? 'Do konce období to nevyjde'
        : outlook.verdict === 'tight'
            ? 'Vyjde to těsně'
            : 'Do konce období to vychází';

    return (
        <Panel tone={ton} icon={outlook.verdict === 'ok' ? TrendingUp : AlertTriangle} title={nadpis}
            footnote="Pravidelné platby známe jménem i datem. Tempo nepravidelných výdajů je odhad z dosavadního průběhu, takže skutečnost se od něj bude lišit.">

            <p className="text-2xl font-semibold tabular-nums text-[var(--color-text-primary)]">
                {money(outlook.projected_left, outlook.currency)}
            </p>
            <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">
                zbude z plánu {datum(outlook.ends_on)}
            </p>

            {/* Rozpad, ze kterého je vidět, čím to číslo vzniklo. Bez něj je to věštba. */}
            <dl className="mt-3 space-y-1.5 border-t border-[var(--color-border)] pt-3 text-xs">
                <div className="flex items-baseline justify-between gap-3">
                    <dt className="text-[var(--color-text-secondary)]">Pravidelné platby do konce</dt>
                    <dd className="shrink-0 tabular-nums text-[var(--color-text-primary)]">− {money(outlook.recurring_expense, outlook.currency)}</dd>
                </div>
                <div className="flex items-baseline justify-between gap-3">
                    <dt className="text-[var(--color-text-secondary)]">Odhad ostatních výdajů</dt>
                    <dd className="shrink-0 tabular-nums text-[var(--color-text-primary)]">− {money(outlook.variable_estimate, outlook.currency)}</dd>
                </div>
                {outlook.recurring_income > 0 && (
                    <div className="flex items-baseline justify-between gap-3">
                        {/* Příjem se do zbytku nepřičítá — `zbývá z plánu` není zůstatek na
                            účtu. Ukazuje se proto jako samostatná informace, ne jako součet. */}
                        <dt className="text-[var(--color-text-secondary)]">Ještě přijde na příjmu</dt>
                        <dd className="shrink-0 tabular-nums text-emerald-400">+ {money(outlook.recurring_income, outlook.currency)}</dd>
                    </div>
                )}
            </dl>

            {outlook.upcoming.length > 0 && (
                <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                    <p className="mb-2 text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                        Nejbližších {dny(outlook.horizon_days)}
                    </p>
                    <ul className="space-y-1.5">
                        {outlook.upcoming.slice(0, 6).map((polozka, i) => (
                            <li key={`${polozka.due_on}-${i}`} className="flex items-baseline justify-between gap-3 text-xs">
                                <span className="min-w-0 truncate text-[var(--color-text-primary)]">
                                    {polozka.note ?? polozka.category ?? 'Platba'}
                                    <span className="ml-1.5 text-[var(--color-text-secondary)]">
                                        {polozka.days_away === 0 ? 'dnes' : polozka.days_away === 1 ? 'zítra' : `za ${dny(polozka.days_away)}`}
                                    </span>
                                </span>
                                <span className={`shrink-0 tabular-nums ${polozka.kind === 'income' ? 'text-emerald-400' : 'text-[var(--color-text-primary)]'}`}>
                                    {polozka.kind === 'income' ? '+' : '−'} {money(polozka.amount, polozka.currency)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Panel>
    );
}

function RunwayPanel({ runway, budget }: { runway: NonNullable<Overview['runway']>; budget: Overview['budget'] }) {
    return (
        <Panel tone="danger" icon={AlertTriangle}
            title={runway.days_left === 0 ? 'Rozpočet je vyčerpaný' : `Peníze dojdou ${datum(runway.runs_out_on)}`}>
            <p className="text-xs leading-relaxed text-[var(--color-text-secondary)]">
                Posledních třicet dní utrácíš <strong className="text-[var(--color-text-primary)]">{money(runway.per_day, budget.currency)}</strong> denně.
                {runway.days_left > 0 && ` Při tomhle tempu to je ještě ${dny(runway.days_left)}`}
                {budget.ends_on && `, a období končí ${datum(budget.ends_on)}.`}
            </p>
        </Panel>
    );
}

/** Kategorie, které docházejí. Prázdné je dobrá zpráva a nic se nekreslí. */
function WarningsPanel({ warnings, currency }: { warnings: NonNullable<Overview['warnings']>; currency: string }) {
    return (
        <Panel tone="warn" icon={AlertTriangle} title="Pozor na tyhle kategorie"
            footnote="Měří se proti tomu, co mělo padnout do dneška — ne proti celému období.">
            <div className="space-y-2.5">
                {warnings.map(warning => (
                    <div key={warning.category}>
                        <div className="flex items-baseline justify-between gap-2 text-xs">
                            <span className="text-[var(--color-text-primary)]">{warning.category}</span>
                            <span className={warning.level === 'over' ? 'font-semibold text-red-300' : 'font-semibold text-amber-200'}>
                                {warning.percent} %
                            </span>
                        </div>
                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                            <div className={`h-full ${warning.level === 'over' ? 'bg-red-400' : 'bg-amber-400'}`}
                                style={{ width: `${Math.min(100, warning.percent)}%` }}/>
                        </div>
                        <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                            {money(warning.spent, currency)} z {money(warning.planned_to_date, currency)}
                        </p>
                    </div>
                ))}
            </div>
        </Panel>
    );
}

/**
 * Jedno číslo na konci. Deset drobných převodů nikdo nedělá, jeden ano.
 *
 * Dluh jde uzavřít. Bez toho svítil dál i po zaplacení a příští měsíc se k němu přičetlo
 * nové — po půl roce panel ukazoval součet všeho, co kdy kdo za koho zaplatil, místo
 * toho, co ještě zbývá srovnat.
 */
function SettlementPanel({ budget, settlement, combined, history, onChanged }: {
    budget: Overview['budget'];
    settlement: NonNullable<Overview['settlement']>;
    /** Dluh napříč měnami jedním číslem. Null, když míří různými směry nebo chybí kurz. */
    combined: NonNullable<Overview['settlement_combined']> | null;
    history: NonNullable<Overview['settlements']>;
    onChanged: () => void;
}) {
    const [busy, setBusy] = useState('');

    const vyrovnat = async (mena: string, poslatZadost: boolean) => {
        setBusy(mena);
        try {
            await axios.post(`/api/v1/rozpocty/${budget.uuid}/vyrovnani`, { currency: mena, request_money: poslatZadost });
            onChanged();
        } finally { setBusy(''); }
    };

    return (
        <Panel icon={ArrowRightLeft} title="Kdo komu dluží"
            footnote={'Počítá se z položek označených „napůl" a „za druhého". Každá měna zvlášť, aby čísla seděla přesně; převod na jednu je odhad.'}>
            <div className="space-y-2">
                {settlement.map(radek => (
                    <div key={`${radek.currency}-${radek.to_id}`} className="rounded-xl bg-[var(--color-bg-primary)] px-3 py-2.5">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <span className="text-sm text-[var(--color-text-secondary)]">
                                <strong className="text-[var(--color-text-primary)]">{radek.from ?? 'Druhý'}</strong>
                                {' → '}
                                <strong className="text-[var(--color-text-primary)]">{radek.to}</strong>
                            </span>
                            <span className="text-base font-semibold tabular-nums text-[var(--color-accent)]">
                                {money(radek.amount, radek.currency)}
                            </span>
                        </div>

                        {radek.since && (
                            <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                                Od {datum(radek.since)} — starší je vyrovnané.
                            </p>
                        )}

                        <div className="mt-2 flex flex-wrap gap-2">
                            {/* Poslat žádost a uzavřít je jedno kliknutí. Přepisovat částku
                                do formuláře vedle je práce, kterou aplikace umí sama. */}
                            {radek.from_id && (
                                <button type="button" disabled={busy === radek.currency}
                                    onClick={() => void vyrovnat(radek.currency, true)}
                                    className="inline-flex min-h-8 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-3 text-xs font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                                    <Check size={13}/> Požádat a uzavřít
                                </button>
                            )}
                            <button type="button" disabled={busy === radek.currency}
                                onClick={() => void vyrovnat(radek.currency, false)}
                                className="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] disabled:opacity-50">
                                Už je vyrovnáno
                            </button>
                        </div>
                    </div>
                ))}

                {settlement.length === 0 && (
                    <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-4 text-center text-xs text-[var(--color-text-secondary)]">
                        Všechno je vyrovnané.
                    </p>
                )}

                {/* Jeden převod se posílá jednou částkou. „Dlužíš mi osm set eur a dva
                    a půl tisíce korun" je věta, po které si oba sednou k počítání. */}
                {combined && (
                    <p className="rounded-xl border border-[var(--color-accent)]/30 bg-[var(--color-accent)]/5 px-3 py-2.5 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                        Jedním převodem to je <strong className="text-[var(--color-text-primary)]">≈ {money(combined.total, combined.currency)}</strong>
                        {combined.from && combined.to && <> od {combined.from} pro {combined.to}</>}.
                        <span className="mt-0.5 block opacity-75">Kurz ECB z {datum(combined.date)} — orientační, banka počítá svým.</span>
                    </p>
                )}
            </div>

            {history.length > 0 && (
                <div className="mt-3 border-t border-[var(--color-border)] pt-2.5">
                    <p className="mb-1.5 text-[11px] font-medium text-[var(--color-text-secondary)]">Vyrovnáno dřív</p>
                    <div className="space-y-1">
                        {history.map(zaznam => (
                            <div key={zaznam.uuid} className="group flex items-baseline gap-2 text-[11px] text-[var(--color-text-secondary)]">
                                <span>{datum(zaznam.settled_through)}</span>
                                <span className="min-w-0 flex-1 truncate">
                                    {zaznam.from && zaznam.to ? `${zaznam.from} → ${zaznam.to}` : 'vyrovnáno'}
                                </span>
                                <span className="tabular-nums">{money(zaznam.amount, zaznam.currency)}</span>
                                <DeleteButton
                                    label={`Vzít zpět uzávěrku z ${datum(zaznam.settled_through)}`}
                                    confirmLabel="Vzít zpět"
                                    className="opacity-0 transition-opacity focus:opacity-100 group-hover:opacity-100"
                                    onDelete={async () => { await axios.delete(`/api/v1/rozpocty/${budget.uuid}/vyrovnani/${zaznam.uuid}`); onChanged(); }}>
                                    <X size={11}/>
                                </DeleteButton>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </Panel>
    );
}

/** Spoření na cíl: jeden pruh, kolik chybí a kolik měsíčně odkládat. */
function SavingsPanel({ savings, currency }: { savings: NonNullable<Overview['savings']>; currency: string }) {
    return (
        <Panel icon={PiggyBank} title="Spoření na cíl"
            tone={savings.reached ? 'accent' : savings.overdue ? 'warn' : 'plain'}
            actions={<span className="text-xs tabular-nums text-[var(--color-text-secondary)]">
                {money(savings.saved, currency)} z {money(savings.target, currency)}
            </span>}>
            <div className="h-3 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                <div className={`h-full transition-all ${savings.reached ? 'bg-emerald-400' : savings.overdue ? 'bg-red-400' : 'bg-[var(--color-accent)]'}`}
                    style={{ width: `${savings.percent}%` }}/>
            </div>

            <p className="mt-2.5 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                {savings.reached
                    ? 'Cíl je splněný.'
                    : savings.overdue
                        ? `Termín ${datum(savings.target_on!)} uplynul a chybí ${money(savings.missing, currency)}.`
                        : savings.monthly_needed !== null
                            ? <>Chybí {money(savings.missing, currency)} — to je <strong className="text-[var(--color-text-primary)]">{money(savings.monthly_needed, currency)} měsíčně</strong> do {datum(savings.target_on!)}.</>
                            : `Chybí ${money(savings.missing, currency)}. Termín není nastavený.`}
            </p>
        </Panel>
    );
}

/**
 * Co se každý měsíc samo připisuje.
 *
 * Označit položku za pravidelnou šlo, ale zjistit, co všechno se kvůli tomu děje, ne —
 * a hlavně to nešlo zastavit. Nájem po odstěhování běžel dál a člověk to poznal až
 * podle toho, že mu nesedí zbytek.
 *
 * Zastavení nemaže historii. Zruší se jen příznak u poslední položky, takže se příští
 * měsíc nic nepřipíše, ale co se už zaplatilo, v rozpočtu zůstane.
 */
function RecurringPanel({ budget, rows, onChanged }: {
    budget: Overview['budget'];
    rows: NonNullable<Overview['recurring']>;
    onChanged: () => void;
}) {
    const [busy, setBusy] = useState('');

    const zastavit = async (uuid: string) => {
        setBusy(uuid);
        try {
            await axios.patch(`/api/v1/rozpocty/${budget.uuid}/polozky/${uuid}`, { is_recurring: false });
            onChanged();
        } finally { setBusy(''); }
    };

    const mesicne = rows
        .filter(r => r.kind === 'expense' && r.currency === budget.currency)
        .reduce((sum, r) => sum + r.amount, 0);

    return (
        <Panel icon={Repeat} title="Každý měsíc znovu"
            description="Tyhle položky se samy připíšou i do dalšího měsíce. Zastavení nesmaže, co už bylo zaplaceno."
            actions={mesicne > 0 && (
                <span className="text-xs tabular-nums text-[var(--color-text-secondary)]">
                    {money(mesicne, budget.currency)} měsíčně
                </span>
            )}>
            <div className="space-y-1">
                {rows.map(radek => (
                    <div key={radek.uuid} className="group flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg px-2 py-2 hover:bg-[var(--color-surface-hover)]">
                        <span className="w-10 shrink-0 text-center text-xs text-[var(--color-text-secondary)]" title="Den v měsíci">
                            {radek.day_of_month}.
                        </span>

                        <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-[var(--color-text-primary)]">
                            <span className="min-w-0 max-w-full truncate">{radek.note || radek.category || 'Bez popisu'}</span>
                            {radek.category && radek.note && (
                                <span className="text-[11px] text-[var(--color-text-secondary)]">{radek.category}</span>
                            )}
                            {radek.split !== 'none' && (
                                <span className="rounded bg-[var(--color-accent)]/15 px-1.5 py-0.5 text-[9px] text-[var(--color-accent)]">
                                    {DELENI[radek.split]}{radek.paid_by ? ` · ${radek.paid_by}` : ''}
                                </span>
                            )}
                            <span className="text-[10px] text-[var(--color-text-secondary)]">
                                {radek.occurrences}× · naposledy {den(radek.last_on)}
                            </span>
                        </span>

                        <span className={`shrink-0 text-sm tabular-nums ${radek.kind === 'income' ? 'text-emerald-300' : 'text-[var(--color-text-primary)]'}`}>
                            {radek.kind === 'income' ? '+' : '−'}{money(radek.amount, radek.currency)}
                        </span>

                        <button type="button" disabled={busy === radek.uuid} onClick={() => void zastavit(radek.uuid)}
                            className="min-h-8 shrink-0 rounded-lg border border-[var(--color-border)] px-2.5 text-xs text-[var(--color-text-secondary)] transition-opacity hover:text-red-300 focus:opacity-100 disabled:opacity-50 sm:opacity-0 sm:group-hover:opacity-100">
                            {busy === radek.uuid ? 'Ruším…' : 'Zastavit'}
                        </button>
                    </div>
                ))}
            </div>
        </Panel>
    );
}

/**
 * Kam peníze tečou.
 *
 * Koláč by ukázal totéž, ale porovnat dvě výseče od oka nikdo neumí — délky vedle
 * sebe ano.
 */
/**
 * Cíle spoření uvnitř rozpočtu.
 *
 * Jedno pole na rozpočet nepobere „letenky za čtyři sta" a „notebook za dvanáct set"
 * zároveň — mají různé částky i termíny.
 *
 * Uspořená částka se zadává ručně. Odvozovat ji ze zůstatku by u dvou cílů znamenalo
 * tvrdit o týchž penězích, že patří oběma.
 */
function GoalsPanel({ budget, goals, onChanged }: {
    budget: Overview['budget'];
    goals: NonNullable<Overview['goals']>;
    onChanged: () => void;
}) {
    const [pridava, setPridava] = useState(false);
    const [form, setForm] = useState({ name: '', target_amount: '', saved_amount: '', target_on: '' });
    const [pracuje, setPracuje] = useState(false);

    const pridej = async () => {
        if (! form.name.trim() || ! form.target_amount) return;

        setPracuje(true);

        try {
            await axios.post(`/api/v1/rozpocty/${budget.uuid}/cile`, {
                name: form.name.trim(),
                target_amount: Number(form.target_amount),
                saved_amount: form.saved_amount === '' ? 0 : Number(form.saved_amount),
                target_on: form.target_on || null,
            });

            setForm({ name: '', target_amount: '', saved_amount: '', target_on: '' });
            setPridava(false);
            hlaska('Cíl je založený.', 'uspech');
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Cíl se nepodařilo založit.', 'chyba');
        } finally {
            setPracuje(false);
        }
    };

    const uprav = async (uuid: string, saved: number) => {
        try {
            await axios.patch(`/api/v1/rozpocty/${budget.uuid}/cile/${uuid}`, { saved_amount: saved });
            onChanged();
        } catch {
            hlaska('Změnu se nepodařilo uložit.', 'chyba');
        }
    };

    return (
        <Panel icon={PiggyBank} title="Cíle spoření"
            description="Kolik už je stranou se zadává ručně — ze zůstatku se to odvodit nedá, protože u dvou cílů by to o týchž penězích tvrdilo, že patří oběma."
            actions={! pridava && (
                <button type="button" onClick={() => setPridava(true)}
                    className="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:border-[var(--color-accent)] hover:text-[var(--color-text-primary)]">
                    <Plus size={13}/> Nový cíl
                </button>
            )}>

            {goals.length === 0 && ! pridava && (
                <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs text-[var(--color-text-secondary)]">
                    Zatím žádný cíl. Letenky domů, nový notebook, rezerva na nečekané — každý zvlášť, s vlastním termínem.
                </p>
            )}

            <div className="space-y-3.5">
                {goals.map(cil => {
                    const hotovo = (cil.percent ?? 0) >= 100;

                    return (
                        <div key={cil.uuid}>
                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                <span className="truncate text-[var(--color-text-primary)]">{cil.name}</span>
                                <span className={`shrink-0 tabular-nums ${hotovo ? 'text-emerald-400' : 'text-[var(--color-text-primary)]'}`}>
                                    {money(cil.saved_amount, cil.currency)} <span className="text-[var(--color-text-secondary)]">z {money(cil.target_amount, cil.currency)}</span>
                                </span>
                            </div>

                            <div className="mt-1 flex items-center gap-2">
                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                                    <div className={`h-full ${hotovo ? 'bg-emerald-400' : 'bg-[var(--color-accent)]'}`}
                                        style={{ width: `${Math.min(100, cil.percent ?? 0)}%` }}/>
                                </div>
                                <span className="w-10 shrink-0 text-right text-[11px] tabular-nums text-[var(--color-text-secondary)]">
                                    {cil.percent ?? 0} %
                                </span>
                                <input type="number" inputMode="decimal" defaultValue={cil.saved_amount}
                                    onBlur={e => { const v = Number(e.target.value); if (v !== cil.saved_amount) void uprav(cil.uuid, v); }}
                                    aria-label={`Uspořeno na cíl ${cil.name}`}
                                    className="min-h-9 w-24 shrink-0 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 text-xs tabular-nums text-[var(--color-text-primary)]"/>
                                <DeleteButton label={`Smazat cíl ${cil.name}`}
                                    onDelete={async () => { await axios.delete(`/api/v1/rozpocty/${budget.uuid}/cile/${cil.uuid}`); hlaska(`Cíl „${cil.name}" je smazaný.`, 'uspech'); onChanged(); }}/>
                            </div>

                            <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                                {hotovo
                                    ? 'Cíl je splněný.'
                                    : cil.monthly_needed !== null
                                        ? <>do {datum(cil.target_on!)} zbývá odkládat {money(cil.monthly_needed, cil.currency)} měsíčně</>
                                        : cil.target_on ? `termín ${datum(cil.target_on)}` : 'bez termínu'}
                            </p>
                        </div>
                    );
                })}
            </div>

            {pridava && (
                <div className="mt-3 grid gap-2 border-t border-[var(--color-border)] pt-3 sm:grid-cols-2">
                    <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                        placeholder="Letenky domů" aria-label="Název cíle" className={`${FIELD} sm:col-span-2`}/>
                    <input type="number" inputMode="decimal" value={form.target_amount}
                        onChange={e => setForm(f => ({ ...f, target_amount: e.target.value }))}
                        placeholder={`Cílová částka (${budget.currency})`} aria-label="Cílová částka" className={FIELD}/>
                    <input type="number" inputMode="decimal" value={form.saved_amount}
                        onChange={e => setForm(f => ({ ...f, saved_amount: e.target.value }))}
                        placeholder="Už mám stranou" aria-label="Už uspořeno" className={FIELD}/>
                    <input type="date" value={form.target_on} onChange={e => setForm(f => ({ ...f, target_on: e.target.value }))}
                        aria-label="Termín" className={FIELD}/>
                    <div className="flex gap-2">
                        <button type="button" onClick={() => void pridej()} disabled={pracuje || ! form.name.trim() || ! form.target_amount}
                            className="inline-flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-3 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                            <Check size={14}/> Založit
                        </button>
                        <button type="button" onClick={() => setPridava(false)}
                            className="min-h-10 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)]">Zrušit</button>
                    </div>
                </div>
            )}
        </Panel>
    );
}

/**
 * Platby, které vypadají jako pravidelné, ale nikdo je tak neoznačil.
 *
 * Rozpočet umí pravidelné položky sám dopisovat, hlásit dopředu a počítat do předpovědi —
 * jenže jen u těch, u kterých někdo zaškrtl políčko. Kdo ho zapomene u telefonu, přijde
 * o všechny tři výhody a nikdy se to nedozví, protože nic nechybí.
 */
function RecurringCandidates({ budget, rows, onChanged }: {
    budget: Overview['budget'];
    rows: NonNullable<Overview['recurring_candidates']>;
    onChanged: () => void;
}) {
    const [pracuje, setPracuje] = useState('');

    const oznac = async (radek: NonNullable<Overview['recurring_candidates']>[number]) => {
        setPracuje(radek.uuid);

        try {
            // Označí se celá řada, ne jen poslední kus — opakování je vlastnost té
            // platby, ne jednoho jejího výskytu.
            for (const uuid of radek.entry_uuids) {
                await axios.patch(`/api/v1/rozpocty/${budget.uuid}/polozky/${uuid}`, { is_recurring: true });
            }

            hlaska(`„${radek.note}" je teď pravidelná platba.`, 'uspech');
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Nepodařilo se to označit.', 'chyba');
        } finally {
            setPracuje('');
        }
    };

    return (
        <Panel tone="accent" icon={Repeat} title="Vypadá to na pravidelnou platbu"
            description="Tohle chodí každý měsíc, ale není to označené jako pravidelné — takže se to nedopisuje samo, nehlásí dopředu a nepočítá do předpovědi."
            footnote="Hledá se stejný popis ve třech a víc měsících s kolísáním do čtvrtiny částky. Nájem bývá na korunu stejný, energie ne.">
            <div className="space-y-2.5">
                {rows.map(radek => (
                    <div key={radek.uuid} className="flex flex-wrap items-center gap-2">
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm text-[var(--color-text-primary)]">{radek.note}</span>
                            <span className="block text-[11px] text-[var(--color-text-secondary)]">
                                {pocet(radek.occurrences, 'měsíc', 'měsíce', 'měsíců')} po sobě · průměrně {money(radek.average, radek.currency)} · kolem {radek.day_of_month}. dne
                                {radek.category && ` · ${radek.category}`}
                            </span>
                        </span>
                        <button type="button" onClick={() => void oznac(radek)} disabled={pracuje === radek.uuid}
                            className="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-3 text-xs font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                            <Check size={13}/> {pracuje === radek.uuid ? 'Označuji…' : 'Označit'}
                        </button>
                    </div>
                ))}
            </div>
        </Panel>
    );
}

/**
 * Jak se saldo vyvíjí po měsících.
 *
 * Rozvaha řekne, kdo komu dluží dnes. Neřekne, jestli se to zhoršuje — a dva tisíce,
 * které se každý měsíc srovnávají, jsou něco jiného než dva tisíce, které každý měsíc
 * rostou o pět set, i když dnešní číslo je stejné.
 *
 * Proužky jdou na obě strany od středu, protože saldo má znaménko. Sloupec vpravo
 * znamená, že první z dvojice vydal víc za druhého; vlevo naopak.
 */
function BalanceTrendPanel({ data }: { data: NonNullable<Overview['balance_trend']> }) {
    const rozsah = Math.max(...data.points.map(b => Math.abs(b.balance)), 1);
    const posledni = data.points[data.points.length - 1];
    const predposledni = data.points[data.points.length - 2];

    // Celá věta, ne jedno slovo do šablony: „se rozdíl roste" a „se rozdíl srovnává se"
    // jsou obojí špatně česky a při skládání z útržků to není vidět.
    const smer = predposledni
        ? Math.abs(posledni.balance) > Math.abs(predposledni.balance) ? 'rozdíl vzrostl' : 'se rozdíl srovnal'
        : null;

    return (
        <Panel icon={ArrowRightLeft} title="Jak se rozdíl vyvíjí"
            description={smer
                ? `Za poslední měsíc ${smer}.`
                : 'Zatím jen jeden měsíc — na vývoj je brzy.'}
            footnote={`Vpravo znamená, že víc vydal(a) ${data.first.name}, vlevo ${data.second.name}. Počítá se z položek, ne z uzávěrek — uzávěrka je zaplacení, ne důvod, proč rozdíl vznikl.`}>
            <div className="space-y-2">
                {data.points.map(bod => {
                    const doprava = bod.balance >= 0;
                    const sirka = Math.abs(bod.balance) / rozsah * 50;

                    return (
                        <div key={bod.month} className="flex items-center gap-2 text-[11px]">
                            <span className="w-20 shrink-0 truncate text-[var(--color-text-secondary)]">{mesic(bod.month)}</span>
                            {/* Střed je nula. Bez osy uprostřed by se znaménko dalo poznat
                                jen z čísla a proužek by nenesl vůbec nic. */}
                            <div className="relative h-2.5 flex-1">
                                <span className="absolute inset-y-0 left-1/2 w-px bg-[var(--color-border)]"/>
                                <span className="absolute inset-y-0 rounded-sm"
                                    style={{
                                        [doprava ? 'left' : 'right']: '50%',
                                        width: `${sirka}%`,
                                        backgroundColor: doprava ? 'var(--graf-1)' : 'var(--graf-2)',
                                    }}/>
                            </div>
                            <span className="w-24 shrink-0 text-right tabular-nums text-[var(--color-text-primary)]">
                                {money(Math.abs(bod.balance), data.currency)}
                            </span>
                        </div>
                    );
                })}
            </div>
        </Panel>
    );
}

/** Šipka trendu. Slovo, ne jen znak — samotná šipka nahoru se dá číst obojím směrem. */
const TREND: Record<string, { znak: string; slovo: string; trida: string }> = {
    up: { znak: '↑', slovo: 'roste', trida: 'text-amber-400' },
    down: { znak: '↓', slovo: 'klesá', trida: 'text-emerald-400' },
    flat: { znak: '→', slovo: 'drží se', trida: 'text-[var(--color-text-secondary)]' },
    unknown: { znak: '·', slovo: 'málo dat', trida: 'text-[var(--color-text-secondary)]' },
};

/**
 * Předpověď výdajů na příští měsíc.
 *
 * Výhled odpovídá na „vyjde to do konce období". Tenhle panel na „kde to praskne" —
 * a to je informace, se kterou se dá něco udělat ještě předtím, než praskne.
 *
 * Spolehlivost se přiznává nahoře, ne v poznámce pod čarou. Z jednoho měsíce se
 * předpovídat nedá a tvářit se u peněz, že ano, je horší než mlčet.
 */
function PredictionPanel({ data }: { data: NonNullable<Overview['prediction']> }) {
    const nadPlan = data.total > data.planned_total && data.planned_total > 0;

    return (
        <Panel icon={Sparkles} tone={data.reliable && nadPlan ? 'warn' : 'plain'}
            title={`Předpověď na ${mesic(data.for_month)}`}
            description={data.reliable
                ? 'Pravidelné platby v plné výši, ostatní jako vážený průměr — novější měsíce mají větší váhu.'
                : `Zatím jen ${pocet(data.months_measured, 'dokončený měsíc', 'dokončené měsíce', 'dokončených měsíců')}. Ber to spíš jako dojem než předpověď.`}
            footnote="Trend porovnává poslední měsíc s průměrem předchozích. Pásmo deseti procent je schválně široké — pár procent nahoru a dolů se u domácnosti děje pořád.">

            <div className="mb-3 flex items-baseline justify-between gap-3 border-b border-[var(--color-border)] pb-3">
                <span className="text-xs text-[var(--color-text-secondary)]">Celkem podle historie</span>
                <span className="text-right">
                    <span className={`block text-lg font-semibold tabular-nums ${nadPlan ? 'text-amber-400' : 'text-[var(--color-text-primary)]'}`}>
                        {money(data.total, data.currency)}
                    </span>
                    {data.planned_total > 0 && (
                        <span className="block text-[11px] text-[var(--color-text-secondary)]">
                            plán {money(data.planned_total, data.currency)}
                        </span>
                    )}
                </span>
            </div>

            <div className="space-y-2">
                {data.rows.filter(r => r.predicted > 0).map(radek => {
                    // Kategorie, ve které je jen nájem, není „málo dat" — je to ta
                    // nejjistější položka z celého rozpočtu. Trend u ní nedává smysl,
                    // protože se nemá čemu měnit.
                    const trend = radek.variable === 0 && radek.recurring > 0
                        ? { znak: '=', slovo: 'pravidelné', trida: 'text-[var(--color-text-secondary)]' }
                        : TREND[radek.trend];
                    const presPlan = radek.planned_monthly > 0 && radek.predicted > radek.planned_monthly;

                    return (
                        <div key={radek.id} className="flex items-baseline justify-between gap-2 text-xs">
                            <span className="flex min-w-0 items-baseline gap-1.5">
                                <span className="truncate text-[var(--color-text-primary)]">{radek.name}</span>
                                <span className={`shrink-0 ${trend.trida}`} title={`Trend: ${trend.slovo}`}>
                                    {trend.znak} {trend.slovo}
                                </span>
                            </span>
                            <span className="shrink-0 text-right">
                                <span className={`tabular-nums ${presPlan ? 'text-amber-400' : 'text-[var(--color-text-primary)]'}`}>
                                    {money(radek.predicted, data.currency)}
                                </span>
                                {radek.planned_monthly > 0 && (
                                    <span className="ml-1.5 text-[var(--color-text-secondary)]">z {money(radek.planned_monthly, data.currency)}</span>
                                )}
                            </span>
                        </div>
                    );
                })}
            </div>
        </Panel>
    );
}

/**
 * Kdo utrácí za co — kategorie křížem s lidmi.
 *
 * Tabulka, ne graf: čte se po řádcích („kdo z nás platí nájem") i po sloupcích
 * („za co utrácí Makinka") a obojí naráz umí jen mřížka čísel.
 */
function CrossPanel({ data }: { data: NonNullable<Overview['category_by_payer']> }) {
    return (
        <Panel icon={Scale} title="Kdo utrácí za co"
            footnote="Jen výdaje v měně rozpočtu. Sečíst přes měny by znamenalo hádat kurz — a v tabulce, kde je jedna buňka v eurech a druhá v korunách, se řádky nedají porovnat.">
            {/* Vlastní posuv, aby stránka nepřetekla do šířky. */}
            <div className="-mx-1 overflow-x-auto px-1">
                <table className="w-full min-w-[20rem] border-collapse text-xs">
                    <thead>
                        <tr>
                            <th className="pb-2 text-left font-medium text-[var(--color-text-secondary)]">Kategorie</th>
                            {data.people.map(clovek => (
                                <th key={clovek.id} className="pb-2 pl-3 text-right font-medium text-[var(--color-text-secondary)]">{clovek.name}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {data.rows.map(radek => {
                            const nejvic = Math.max(...data.people.map(c => radek.by_payer[c.id] ?? 0), 1);

                            return (
                                <tr key={radek.category_id ?? 'bez'} className="border-t border-[var(--color-border)]">
                                    <td className="py-1.5 pr-2 text-[var(--color-text-primary)]">{radek.name}</td>
                                    {data.people.map(clovek => {
                                        const castka = radek.by_payer[clovek.id] ?? 0;

                                        return (
                                            <td key={clovek.id} className="py-1.5 pl-3 text-right tabular-nums">
                                                {/* Podbarvení podle podílu v řádku: kdo v téhle kategorii
                                                    utrácí víc, je vidět dřív než se přečtou obě čísla. */}
                                                <span className="rounded px-1.5 py-0.5"
                                                    style={castka > 0 ? { backgroundColor: `color-mix(in srgb, var(--color-accent) ${Math.round(castka / nejvic * 22)}%, transparent)` } : undefined}>
                                                    <span className={castka > 0 ? 'text-[var(--color-text-primary)]' : 'text-[var(--color-text-secondary)]'}>
                                                        {castka > 0 ? money(castka, data.currency) : '—'}
                                                    </span>
                                                </span>
                                            </td>
                                        );
                                    })}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

/**
 * Kdo kolik zaplatil.
 *
 * Vedle „kdo komu dluží" to vypadá jako totéž, ale není. Vyrovnání počítá jen s tím, co
 * se dělí — kdo víc platil věci, které dělené nejsou, v něm není vidět vůbec. A přitom
 * „kolikátý měsíc platím nájem já" je otázka, kvůli které se vedou hovory.
 *
 * Proužky jsou proti sobě, ne pod sebou: poměr dvou čísel se čte líp z délky než
 * z porovnávání dvou částek očima.
 */
function PayerPanel({ rows, currency }: { rows: NonNullable<Overview['by_payer']>; currency: string }) {
    const nejvic = Math.max(...rows.map(r => r.main), 1);
    const celkem = rows.reduce((soucet, r) => soucet + r.main, 0);

    return (
        <Panel icon={Scale} title="Kdo kolik zaplatil"
            footnote="Součet toho, co kdo zaplatil — ne toho, kdo komu dluží. Do rozvahy vstupují jen dělené položky, tady jsou všechny.">
            <div className="space-y-3">
                {rows.map((radek, poradi) => {
                    const ostatniMeny = Object.entries(radek.currencies).filter(([mena]) => mena !== currency);

                    return (
                        <div key={radek.id}>
                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                <span className="truncate text-[var(--color-text-primary)]">{radek.name}</span>
                                <span className="shrink-0 tabular-nums text-[var(--color-text-primary)]">{money(radek.main, currency)}</span>
                            </div>
                            <div className="mt-1 flex items-center gap-2">
                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                                    <div className="h-full" style={{ width: `${radek.main / nejvic * 100}%`, backgroundColor: `var(--graf-${poradi + 1})` }}/>
                                </div>
                                <span className="w-9 shrink-0 text-right text-[11px] tabular-nums text-[var(--color-text-secondary)]">
                                    {celkem > 0 ? `${Math.round(radek.main / celkem * 100)} %` : '—'}
                                </span>
                            </div>
                            <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                                {pocetPolozek(radek.count)}
                                {ostatniMeny.length > 0 && <> · k tomu {ostatniMeny.map(([mena, castka]) => money(castka, mena)).join(', ')}</>}
                            </p>
                        </div>
                    );
                })}
            </div>
        </Panel>
    );
}

/** Nejvíc odstínů, které se dají udržet rozlišitelné. Devátý se s některým plete. */
const GRAF_SLOTU = 8;

function BreakdownPanel({ categories, currency }: { categories: Overview['categories']; currency: string }) {
    const vsechny = categories.filter(c => c.spent > 0).sort((a, b) => b.spent - a.spent);

    /*
     * Barvy se dřív braly z pevného seznamu šesti a cyklily se: sedmá kategorie dostala
     * barvu první a v jednom pruhu tak byly dva stejné díly. Vlastní barva kategorie,
     * kterou si člověk nastavil, se přitom ignorovala úplně.
     *
     * Teď platí pořadí: co má kategorie svoje, to se použije; zbytek bere z ověřené
     * osmice v pevném pořadí. Devátá a další se slučují do „Ostatní" — devátý odstín
     * by se s některým z předchozích pletl a raději jedna položka navíc než dvě, které
     * vypadají stejně.
     */
    const utracene = vsechny.length > GRAF_SLOTU
        ? [
            ...vsechny.slice(0, GRAF_SLOTU - 1),
            {
                id: -1,
                name: 'Ostatní',
                color: null,
                spent: vsechny.slice(GRAF_SLOTU - 1).reduce((soucet, c) => soucet + c.spent, 0),
            },
        ]
        : vsechny;

    const celkem = utracene.reduce((sum, c) => sum + c.spent, 0);
    const nejvic = Math.max(...utracene.map(c => c.spent), 1);
    const barva = (category: { color: string | null }, index: number) =>
        category.color ?? `var(--graf-${(index % GRAF_SLOTU) + 1})`;

    return (
        <Panel icon={PieChart} title="Kam to jde"
            footnote={vsechny.length > GRAF_SLOTU
                ? `Nejmenší kategorie jsou sloučené do „Ostatní" — víc než ${GRAF_SLOTU} barev se v jednom pruhu přestane rozlišovat.`
                : undefined}>
            {/* Jeden pruh složený z podílů — poměr celku je vidět dřív než částky.
                Díly odděluje dvoubodová mezera v barvě plochy, ne obrys: obrys přidává
                inkoust, který nenese data, a sousední odstíny se stejně spolehlivě
                oddělí mezerou. */}
            <div className="mb-4 flex h-3 gap-0.5 overflow-hidden rounded-full">
                {utracene.map((category, index) => (
                    <div key={category.id} style={{ width: `${category.spent / celkem * 100}%`, backgroundColor: barva(category, index) }}
                        title={`${category.name}: ${money(category.spent, currency)}`}/>
                ))}
            </div>

            <div className="space-y-2.5">
                {utracene.map((category, index) => (
                    <div key={category.id} className="flex items-center gap-2.5">
                        <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ backgroundColor: barva(category, index) }}/>
                        <span className="w-20 shrink-0 truncate text-xs text-[var(--color-text-primary)]">{category.name}</span>
                        <div className="h-2 min-w-8 flex-1 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                            <div className="h-full opacity-70" style={{ width: `${category.spent / nejvic * 100}%`, backgroundColor: barva(category, index) }}/>
                        </div>
                        <span className="shrink-0 text-right text-xs tabular-nums text-[var(--color-text-secondary)]">
                            {money(category.spent, currency)}
                        </span>
                        <span className="w-9 shrink-0 text-right text-[10px] tabular-nums text-[var(--color-text-secondary)]">
                            {Math.round(category.spent / celkem * 100)} %
                        </span>
                    </div>
                ))}
            </div>
        </Panel>
    );
}

/** Měsíc po měsíci — příjem i výdaj v jednom měřítku, ať je vidět ztrátový měsíc. */
/**
 * Průběh čerpání jako graf — a jednou větou, co z něj plyne.
 *
 * Samotná křivka je krásná a němá. Nadpis proto říká rovnou závěr, protože na ten se
 * člověk ptá, a graf ho dokládá: kdy se rezerva utrácela rychle, kdy pomalu a jestli
 * odhad míří nad nulu, nebo pod ni.
 */
function CerpaniPanel({ data }: { data: NonNullable<Overview['burndown']> }) {
    const napred = (data.vs_pace ?? 0) >= 0;

    return (
        <Panel icon={BarChart3}
            title={data.vs_pace === null
                ? 'Průběh čerpání'
                : napred
                    ? `Proti tempu máte rezervu ${money(data.vs_pace, data.currency)}`
                    : `Utraceno o ${money(Math.abs(data.vs_pace), data.currency)} víc, než tempo unese`}
            tone={napred ? 'plain' : 'warn'}
            description="Kolik z plánu zbývá den po dni. Ideální tempo je rovnoměrné čerpání, které trefí nulu přesně na konci období."
            footnote="Do grafu vstupují jen výdaje v měně rozpočtu — položky v jiné měně by se musely přepočítat kurzem, který nemáme odkud vzít.">
            <CerpaniGraf data={data}/>
        </Panel>
    );
}

function MonthsPanel({ months, currency }: { months: Overview['months']; currency: string }) {
    const nejvic = Math.max(...months.flatMap(m => [m.spent, m.income]), 1);
    const celkem = months.reduce((soucet, m) => soucet + m.income - m.spent, 0);

    return (
        <Panel icon={BarChart3} title="Měsíc po měsíci"
            footnote="Dva grafy pod sebou nutí porovnávat očima přes mezeru; jedno měřítko ne.">
            <div className="space-y-3.5">
                {months.map(month => {
                    const zbylo = month.income - month.spent;

                    return (
                        <div key={month.month}>
                            <div className="mb-1.5 flex items-baseline justify-between gap-2 text-xs">
                                <span className="text-[var(--color-text-primary)]">
                                    {mesic(month.month)}
                                    {(month.other_currency_count ?? 0) > 0 && (
                                        <span className="ml-1.5 text-[10px] text-[var(--color-text-secondary)]"
                                            title="Položky v jiné měně se do grafu nepočítají — kurz nemáme odkud vzít.">
                                            +{month.other_currency_count} v jiné měně
                                        </span>
                                    )}
                                </span>
                                <span className={`tabular-nums ${zbylo >= 0 ? 'text-emerald-300' : 'text-red-300'}`}>
                                    {zbylo >= 0 ? '+' : '−'}{money(Math.abs(zbylo), currency)}
                                </span>
                            </div>

                            <div className="space-y-1">
                                {month.income > 0 && (
                                    <div className="flex items-center gap-2">
                                        <span className="w-9 shrink-0 text-[9px] text-[var(--color-text-secondary)]">příjem</span>
                                        <div className="h-2.5 min-w-8 flex-1 overflow-hidden rounded bg-[var(--color-bg-primary)]">
                                            <div className="h-full bg-emerald-400/70" style={{ width: `${month.income / nejvic * 100}%` }}/>
                                        </div>
                                        <span className="shrink-0 text-right text-[10px] tabular-nums text-[var(--color-text-secondary)]">{money(month.income, currency)}</span>
                                    </div>
                                )}
                                <div className="flex items-center gap-2">
                                    <span className="w-9 shrink-0 text-[9px] text-[var(--color-text-secondary)]">výdaj</span>
                                    <div className="h-2.5 min-w-8 flex-1 overflow-hidden rounded bg-[var(--color-bg-primary)]">
                                        <div className="h-full bg-[var(--color-accent)]/70" style={{ width: `${month.spent / nejvic * 100}%` }}/>
                                    </div>
                                    <span className="shrink-0 text-right text-[10px] tabular-nums text-[var(--color-text-primary)]">{money(month.spent, currency)}</span>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Součet za celé období. Jednotlivé měsíce odpovídají na „jak dopadl
                srpen"; tohle na „jsme celkově v plusu", což je jiná otázka a z řady
                čísel pod sebou se sečíst nedá. */}
            {months.length > 1 && (
                <div className="mt-4 flex items-baseline justify-between gap-2 border-t border-[var(--color-border)] pt-3">
                    <span className="text-xs text-[var(--color-text-secondary)]">
                        Za {pocet(months.length, 'měsíc', 'měsíce', 'měsíců')} dohromady
                    </span>
                    <span className={`text-sm font-semibold tabular-nums ${celkem >= 0 ? 'text-emerald-300' : 'text-red-300'}`}>
                        {celkem >= 0 ? '+' : '−'}{money(Math.abs(celkem), currency)}
                    </span>
                </div>
            )}
        </Panel>
    );
}

/**
 * Dva měsíce vedle sebe.
 *
 * „Utratili jsme o dva tisíce víc" je konstatování; „o dva tisíce víc za jídlo" je něco,
 * s čím se dá něco udělat. Řazení podle velikosti změny, ne podle abecedy — kvůli tomu
 * se tabulka otevírá.
 */
function Comparison({ data }: { data: NonNullable<Overview['comparison']> }) {
    const nejvic = Math.max(...data.rows.flatMap(r => [r.previous, r.current]), 1);

    return (
        <Panel icon={Scale} title={`${mesic(data.previous_month)} vs ${mesic(data.current_month)}`}
            actions={
                <span className={`text-xs font-semibold tabular-nums ${data.total_diff > 0 ? 'text-red-300' : data.total_diff < 0 ? 'text-emerald-300' : 'text-[var(--color-text-secondary)]'}`}>
                    {data.total_diff > 0 ? '+' : data.total_diff < 0 ? '−' : ''}{money(Math.abs(data.total_diff), data.currency)}
                </span>
            }
            footnote={`Šedý pruh je starší měsíc. Jen položky v ${data.currency} — ostatní měny se nesčítají.`}>
            <div className="space-y-2.5">
                {data.rows.map(radek => (
                    <div key={radek.name}>
                        <div className="mb-1 flex flex-wrap items-baseline justify-between gap-x-2">
                            <span className="text-xs text-[var(--color-text-primary)]">{radek.name}</span>
                            <span className="text-[11px] text-[var(--color-text-secondary)]">
                                {money(radek.previous, data.currency)} → {money(radek.current, data.currency)}
                                {radek.diff !== 0 && (
                                    <span className={radek.diff > 0 ? ' text-red-300' : ' text-emerald-300'}>
                                        {' '}({radek.diff > 0 ? '+' : '−'}{money(Math.abs(radek.diff), data.currency)}
                                        {radek.diff_percent !== null && `, ${radek.diff_percent > 0 ? '+' : ''}${radek.diff_percent} %`})
                                    </span>
                                )}
                            </span>
                        </div>

                        {/* Dva pruhy nad sebou ve stejném měřítku. Čísla vedle sebe se
                            porovnávají hůř než délky. */}
                        <div className="space-y-0.5">
                            <div className="h-2 overflow-hidden rounded bg-[var(--color-bg-primary)]">
                                <div className="h-full bg-[var(--color-text-secondary)]/40" style={{ width: `${radek.previous / nejvic * 100}%` }}/>
                            </div>
                            <div className="h-2 overflow-hidden rounded bg-[var(--color-bg-primary)]">
                                <div className={`h-full ${radek.diff > 0 ? 'bg-red-400/70' : 'bg-emerald-400/70'}`}
                                    style={{ width: `${radek.current / nejvic * 100}%` }}/>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </Panel>
    );
}

/**
 * Jedna kategorie v plánu — a její úprava.
 *
 * Hlavní číslo je zbývající částka, ne procenta. „112 %" neřekne, jestli je to o pět eur,
 * nebo o pět set, a právě podle toho se člověk rozhoduje, jestli si dnes něco koupí.
 * Procenta zůstávají u proužku, kde slouží k porovnání kategorií mezi sebou.
 *
 * Úprava je na místě, ne v okně: měnit plán je běžná věc, kterou člověk dělá průběžně,
 * jak zjišťuje, kolik co doopravdy stojí.
 */
function CategoryRow({ budget, category, onChanged }: {
    budget: Overview['budget'];
    category: Overview['categories'][number];
    onChanged: () => void;
}) {
    const [uprava, setUprava] = useState(false);
    const [nazev, setNazev] = useState(category.name);
    const [plan, setPlan] = useState(String(category.planned_monthly || ''));
    const [prenos, setPrenos] = useState(Boolean(category.rollover));
    const [deleni, setDeleni] = useState(category.default_split ?? '');
    const [uklada, setUklada] = useState(false);

    const preteklo = (category.used_percent ?? 0) > 100;

    const uloz = async () => {
        if (! nazev.trim()) return;

        setUklada(true);

        try {
            await axios.patch(`/api/v1/rozpocty/${budget.uuid}/kategorie/${category.id}`, {
                name: nazev.trim(),
                planned_monthly: Number(plan || 0),
                rollover: prenos,
                default_split: deleni || null,
            });

            setUprava(false);
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Kategorii se nepodařilo uložit.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    if (uprava) {
        return (
            <div className="rounded-xl border border-[var(--color-accent)]/30 bg-[var(--color-surface-muted)] p-3">
                <div className="flex flex-col gap-2 sm:flex-row">
                    <input value={nazev} onChange={e => setNazev(e.target.value)} aria-label="Název kategorie" className={FIELD}/>
                    <input type="number" inputMode="decimal" value={plan} onChange={e => setPlan(e.target.value)}
                        aria-label={`Plán ${budget.period_label}`}
                        placeholder={`${budget.period_unit === 'week' ? 'za týden' : 'za měsíc'} (${budget.currency})`}
                        className={`${FIELD} sm:w-40`}/>
                </div>

                {/* Výchozí dělení se předvyplní u nové položky v téhle kategorii. Nákupy
                    jsou vždycky napůl a oblečení nikdy — vybírat to znovu u každé
                    položky znamená stovky kliknutí za pololetí. */}
                <div className="mt-2.5">
                    <label className={LABEL} htmlFor={`deleni-${category.id}`}>Výchozí dělení u nových položek</label>
                    <select id={`deleni-${category.id}`} value={deleni} onChange={e => setDeleni(e.target.value)} className={`${FIELD} sm:w-56`}>
                        <option value="">Neurčeno — vybrat pokaždé</option>
                        <option value="none">Jen moje</option>
                        <option value="equal">Napůl</option>
                        <option value="other">Za druhého</option>
                        <option value="ratio">Podle příjmů</option>
                    </select>
                </div>

                <label className="mt-2.5 flex cursor-pointer items-start gap-2 text-xs text-[var(--color-text-secondary)]">
                    <input type="checkbox" checked={prenos} onChange={e => setPrenos(e.target.checked)}
                        className="mt-0.5 h-4 w-4 shrink-0 accent-[var(--color-accent)]"/>
                    <span>
                        <strong className="text-[var(--color-text-primary)]">Přenášet nevyčerpané do dalšího měsíce</strong>
                        <span className="mt-0.5 block leading-relaxed">
                            Pro výdaje, které chodí jednou za čas a naráz — jízdenka domů, zubař. Co se
                            v měsíci nevyčerpá, zůstane v kategorii na příště.
                        </span>
                    </span>
                </label>

                <div className="mt-3 flex flex-wrap gap-2">
                    <button type="button" onClick={() => void uloz()} disabled={uklada || ! nazev.trim()}
                        className="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-3 text-xs font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                        <Check size={14}/> Uložit
                    </button>
                    <button type="button" onClick={() => { setUprava(false); setNazev(category.name); setPlan(String(category.planned_monthly || '')); setPrenos(Boolean(category.rollover)); }}
                        className="inline-flex min-h-9 items-center rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)]">
                        Zrušit
                    </button>
                    <DeleteButton
                        label={`Smazat kategorii ${category.name}`}
                        onDelete={async () => { await axios.delete(`/api/v1/rozpocty/${budget.uuid}/kategorie/${category.id}`); onChanged(); }}/>
                </div>
            </div>
        );
    }

    return (
        <div>
            <div className="flex items-baseline justify-between gap-2 text-sm">
                <span className="flex min-w-0 items-center gap-1.5">
                    <span className="truncate text-[var(--color-text-primary)]">{category.name}</span>
                    {category.rollover && (
                        <span title="Nevyčerpané se přenáší do dalšího měsíce"
                            className="shrink-0 rounded bg-[var(--color-surface-muted)] px-1.5 py-0.5 text-[10px] text-[var(--color-text-secondary)]">
                            přenos
                        </span>
                    )}
                </span>
                <span className={`shrink-0 text-sm font-medium tabular-nums ${preteklo ? 'text-red-400' : 'text-[var(--color-text-primary)]'}`}>
                    {category.planned_to_date > 0
                        ? <>{preteklo ? '−' : ''}{money(Math.abs(category.left), budget.currency)}</>
                        : money(category.spent, budget.currency)}
                </span>
            </div>

            <div className="flex items-baseline justify-between gap-2">
                <span className="text-[11px] text-[var(--color-text-secondary)]">
                    {money(category.spent, budget.currency)}
                    {category.planned_to_date > 0 && <> z {money(category.planned_to_date, budget.currency)}</>}
                </span>
                <span className="shrink-0 text-[11px] text-[var(--color-text-secondary)]">
                    {category.planned_to_date > 0 ? (preteklo ? 'přes plán' : 'zbývá') : 'bez plánu'}
                </span>
            </div>

            <div className="mt-1.5 flex items-center gap-2">
                <div className="h-2 flex-1 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                    {/* Nad sto procent je varování, ne chyba — někdo prostě utratil víc. */}
                    <div className={`h-full ${preteklo ? 'bg-red-400' : 'bg-emerald-400/80'}`}
                        style={{ width: `${Math.min(100, category.used_percent ?? 0)}%` }}/>
                </div>
                <span className={`w-11 shrink-0 text-right text-[11px] tabular-nums ${preteklo ? 'text-red-300' : 'text-[var(--color-text-secondary)]'}`}>
                    {category.used_percent !== null ? `${category.used_percent} %` : '—'}
                </span>
                <button type="button" onClick={() => setUprava(true)} aria-label={`Upravit kategorii ${category.name}`}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-muted)] hover:text-[var(--color-text-primary)]">
                    <Pencil size={14}/>
                </button>
            </div>
        </div>
    );
}

function Categories({ budget, categories, onChanged }: { budget: Overview['budget']; categories: Overview['categories']; onChanged: () => void }) {
    const [name, setName] = useState('');
    const [planned, setPlanned] = useState('');
    const [zaklada, setZaklada] = useState(false);
    const [navrh, setNavrh] = useState<Navrh | null>(null);
    const [vybrane, setVybrane] = useState<Set<number>>(new Set());
    const [pracuje, setPracuje] = useState(false);

    /**
     * Vyplnit šest částek je nejtupější práce na celém rozpočtu a zároveň ta, kterou
     * člověk odbude — buď si vymyslí kulaté číslo, nebo pole nechá prázdné a plán
     * pak neřídí nic. Přitom po dvou měsících aplikace ví, kolik za jídlo padne,
     * líp než ten, kdo to má odhadnout.
     */
    const nactiNavrh = async () => {
        setPracuje(true);

        try {
            const { data } = await axios.get<Navrh>(`/api/v1/rozpocty/${budget.uuid}/kategorie/navrh`);

            setNavrh(data);
            // Předvybrané jsou jen ty, kde se návrh od dosavadního plánu opravdu liší.
            setVybrane(new Set(data.suggestions.filter(s => s.suggested !== s.current).map(s => s.id)));
        } catch {
            hlaska('Návrh se nepodařilo spočítat.', 'chyba');
        } finally {
            setPracuje(false);
        }
    };

    const pouzijNavrh = async () => {
        if (! navrh || vybrane.size === 0) return;

        setPracuje(true);

        try {
            for (const polozka of navrh.suggestions.filter(s => vybrane.has(s.id))) {
                await axios.patch(`/api/v1/rozpocty/${budget.uuid}/kategorie/${polozka.id}`, { planned_monthly: polozka.suggested });
            }

            hlaska(`Plán je upravený u ${pocet(vybrane.size, 'kategorie', 'kategorií', 'kategorií')}.`, 'uspech');
            setNavrh(null);
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Plán se nepodařilo uložit.', 'chyba');
        } finally {
            setPracuje(false);
        }
    };

    const zalozZaklad = async () => {
        setZaklada(true);

        try {
            await axios.post(`/api/v1/rozpocty/${budget.uuid}/kategorie/zaklad`);
            hlaska('Kategorie jsou založené. Teď jim doplňte částky.', 'uspech');
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Kategorie se nepodařilo založit.', 'chyba');
        } finally {
            setZaklada(false);
        }
    };

    const add = async () => {
        if (! name.trim()) return;
        await axios.post(`/api/v1/rozpocty/${budget.uuid}/kategorie`, { name: name.trim(), planned_monthly: Number(planned || 0) });
        setName(''); setPlanned('');
        onChanged();
    };

    return (
        <Panel icon={Tags} title="Plán proti skutečnosti"
            description={`Kolik mělo padnout do dneška — přepočteno na to, co z období uplynulo. Plán se zadává ${budget.period_label}.`}
            actions={categories.length > 0 && ! navrh && (
                <button type="button" onClick={() => void nactiNavrh()} disabled={pracuje}
                    className="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:border-[var(--color-accent)] hover:text-[var(--color-text-primary)] disabled:opacity-40">
                    <Sparkles size={13}/> {pracuje ? 'Počítám…' : 'Navrhnout plán'}
                </button>
            )}>
            <div className="space-y-3.5">
                {/* Prázdný stav něco nabízí, ne jen konstatuje. Rozpočet bez kategorií
                    neumí nic — plán je nula a přehled nemá co ukázat — a založit šest
                    kategorií po jedné je zrovna to první, co po člověku aplikace chce.
                    Částky zůstávají prázdné schválně; vymýšlet za někoho, kolik dá za
                    jídlo, je horší než nechat pole nevyplněné. */}
                {categories.length === 0 && (
                    <div className="rounded-xl border border-dashed border-[var(--color-border)] px-4 py-5 text-center">
                        <p className="text-xs leading-relaxed text-[var(--color-text-secondary)]">
                            Zatím žádné kategorie. Bez nich rozpočet jen sčítá — s nimi řekne, na čem se přepálilo.
                        </p>
                        <button type="button" onClick={() => void zalozZaklad()} disabled={zaklada}
                            className="mt-3 inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                            <Plus size={15}/> {zaklada ? 'Zakládám…' : 'Přidat obvyklé kategorie'}
                        </button>
                        <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">
                            Bydlení, jídlo, doprava, zdraví, volný čas a ostatní. Částky si doplníte sami.
                        </p>
                    </div>
                )}
                {categories.map(category => (
                    <CategoryRow key={category.id} budget={budget} category={category} onChanged={onChanged}/>
                ))}
            </div>

            {/* Návrh se ukazuje vedle dosavadního plánu, ne místo něj, a přijímá se po
                kategoriích. Přepsat všech šest čísel najednou by u rozpočtu, který si
                člověk sám nastavil, bylo víc než pomoc. */}
            {navrh && (
                <div className="mt-4 rounded-xl border border-[var(--color-accent)]/30 bg-[var(--color-surface-muted)] p-3">
                    <p className="text-xs font-medium text-[var(--color-text-primary)]">
                        Návrh podle skutečnosti za {pocet(navrh.months_measured, 'měsíc', 'měsíce', 'měsíců')}
                    </p>

                    <ul className="mt-2.5 space-y-1.5">
                        {navrh.suggestions.map(polozka => {
                            const stejne = polozka.suggested === polozka.current;

                            return (
                                <li key={polozka.id}>
                                    <label className={`flex items-center gap-2 text-xs ${stejne ? 'opacity-50' : ''}`}>
                                        <input type="checkbox" disabled={stejne}
                                            checked={vybrane.has(polozka.id)}
                                            onChange={e => setVybrane(soucasne => {
                                                const dalsi = new Set(soucasne);
                                                e.target.checked ? dalsi.add(polozka.id) : dalsi.delete(polozka.id);

                                                return dalsi;
                                            })}
                                            className="h-4 w-4 shrink-0 accent-[var(--color-accent)]"/>
                                        <span className="min-w-0 flex-1 truncate text-[var(--color-text-primary)]">{polozka.name}</span>
                                        <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                                            {money(polozka.current, navrh.currency)} → <strong className="text-[var(--color-text-primary)]">{money(polozka.suggested, navrh.currency)}</strong>
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>

                    <div className="mt-3 flex flex-wrap gap-2">
                        <button type="button" onClick={() => void pouzijNavrh()} disabled={pracuje || vybrane.size === 0}
                            className="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-3 text-xs font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                            <Check size={14}/> Použít vybrané
                        </button>
                        <button type="button" onClick={() => setNavrh(null)}
                            className="inline-flex min-h-9 items-center rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)]">
                            Zavřít
                        </button>
                    </div>

                    <p className="mt-2 text-[10px] leading-relaxed text-[var(--color-text-secondary)]">
                        Pravidelné platby se berou v plné výši, zbytek jako průměr na měsíc. Zaokrouhluje se
                        na desítky — návrh na koruny by předstíral přesnost, kterou odhad z pár měsíců nemá.
                    </p>
                </div>
            )}

            <div className="mt-4 flex flex-col gap-2 border-t border-[var(--color-border)] pt-4 sm:flex-row">
                <input value={name} onChange={e => setName(e.target.value)} placeholder="Nájem, jídlo, doprava…" className={FIELD}/>
                <input type="number" inputMode="decimal" value={planned} onChange={e => setPlanned(e.target.value)}
                    placeholder={`${budget.period_unit === 'week' ? 'za týden' : 'za měsíc'} (${budget.currency})`} className={`${FIELD} sm:w-40`}/>
                <button type="button" onClick={() => void add()} disabled={! name.trim()}
                    className="inline-flex min-h-10 shrink-0 items-center justify-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                    <Plus size={14}/> Přidat
                </button>
            </div>
        </Panel>
    );
}

/**
 * Zápis a výpis položek.
 *
 * Seznam se načítá vlastním voláním, ne z přehledu. Přehled dřív posílal sto nejnovějších
 * položek — po nahrání výpisu o pěti stech řádcích jich čtyři sta nešlo ani vidět, ani
 * smazat. Teď má seznam hledání, filtry a stránkování a s přehledem se potkává jen
 * v tom, že se po každé změně obojí načte znovu.
 */
function Entries({ budget, categories, members, ucastnici, total, onChanged }: {
    budget: Overview['budget']; categories: Overview['categories'];
    /** Protistrany pro výběr plátce u položky — bez přihlášeného. */
    members: Array<{ id: number; name: string }>;
    /** Všichni účastníci rozpočtu včetně přihlášeného — pro filtr „kdo platil". */
    ucastnici: NonNullable<Overview['members']>;
    /** Kolik položek rozpočet má celkem — ze souhrnu, aby šlo napsat „50 z 512". */
    total: number;
    onChanged: () => void;
}) {
    const [form, setForm] = useState({
        kind: 'expense', amount: '', currency: budget.currency, spent_on: new Date().toISOString().slice(0, 10),
        budget_category_id: '', note: '', is_recurring: false, split: 'none', paid_by: '',
    });
    const [busy, setBusy] = useState(false);
    const [importing, setImporting] = useState(false);
    const [receipt, setReceipt] = useState<{ uuid: string; nahled: string } | null>(null);
    const [receiptState, setReceiptState] = useState('');

    const [rows, setRows] = useState<EntryRow[]>([]);
    const [months, setMonths] = useState<string[]>([]);
    const [found, setFound] = useState(0);
    const [hasMore, setHasMore] = useState(false);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<string | null>(null);
    const [filter, setFilter] = useState({ q: '', kind: '', category: '', month: '', payer: '', no_receipt: false });

    // Na monitoru je pro formulář místo vedle seznamu, na telefonu ne — tam by zabral
    // celou první obrazovku. Rozhoduje se jednou při prvním vykreslení; kdo si ho otevře
    // nebo zavře, má přednost.
    const [zapisujeSe, setZapisujeSe] = useState(naSirokeObrazovce);

    /**
     * Načte stránku seznamu.
     *
     * Připojit, nebo nahradit — o tom rozhoduje volající. Připojuje se jen při „načíst
     * další"; změna filtru musí seznam přepsat, jinak by se výsledky dvou různých
     * dotazů slily dohromady.
     */
    const load = useCallback(async (cilovaStranka: number, pripojit: boolean) => {
        setLoading(true);
        try {
            const { data } = await axios.get(`/api/v1/rozpocty/${budget.uuid}/polozky`, {
                params: {
                    page: cilovaStranka,
                    q: filter.q || undefined,
                    kind: filter.kind || undefined,
                    category: filter.category || undefined,
                    month: filter.month || undefined,
                    payer: filter.payer || undefined,
                    no_receipt: filter.no_receipt ? 1 : undefined,
                },
            });

            setRows(stav => (pripojit ? [...stav, ...data.entries] : data.entries));
            setMonths(data.months ?? []);
            setFound(data.total ?? 0);
            setHasMore(Boolean(data.has_more));
            setPage(cilovaStranka);
        } finally {
            setLoading(false);
        }
    }, [budget.uuid, filter]);

    // Psaní do hledání se nechá doznít. Volání na každou stisknutou klávesu by při
    // pěti stech položkách znamenalo desítky dotazů, ze kterých je platný poslední.
    useEffect(() => {
        const casovac = window.setTimeout(() => void load(1, false), filter.q ? 300 : 0);

        return () => window.clearTimeout(casovac);
    }, [load, filter.q]);

    /** Po zápisu, úpravě nebo smazání se musí přepočítat souhrny i seznam. */
    const refresh = () => { onChanged(); void load(1, false); };

    /**
     * Účtenka jde rovnou do galerie jako každá jiná fotka.
     *
     * Vlastní úložiště na účtenky by znamenalo druhý systém na obrázky vedle toho, který
     * už tu je — se zálohou, náhledy a mazáním, které by se musely řešit znovu.
     */
    const attachReceipt = async (file: File) => {
        setReceiptState('Nahrávám účtenku…');
        const nahled = URL.createObjectURL(file);

        try {
            const [uuid] = await waitForUploads(uploadManager.enqueue([file], null));

            if (! uuid) { setReceiptState('Účtenku se nepodařilo nahrát.'); URL.revokeObjectURL(nahled); return; }

            setReceipt({ uuid, nahled });
            setReceiptState('');
        } catch (problem) {
            URL.revokeObjectURL(nahled);
            setReceiptState((problem as Error).message);
        }
    };

    const add = async () => {
        if (! form.amount) return;
        setBusy(true);
        try {
            await axios.post(`/api/v1/rozpocty/${budget.uuid}/polozky`, {
                ...form,
                amount: Number(form.amount),
                budget_category_id: form.budget_category_id ? Number(form.budget_category_id) : null,
                paid_by: form.paid_by ? Number(form.paid_by) : null,
                receipt_uuid: receipt?.uuid ?? null,
            });
            // Dělení a plátce zůstávají: kdo zapisuje společné výdaje, zapisuje jich víc
            // za sebou a přepínat to u každého by bylo otravné. Účtenka ne — ta patří
            // právě k té jedné položce.
            setForm(f => ({ ...f, amount: '', note: '' }));
            if (receipt) URL.revokeObjectURL(receipt.nahled);
            setReceipt(null);
            refresh();
        } finally { setBusy(false); }
    };

    const filtrovano = Boolean(filter.q || filter.kind || filter.category || filter.month || filter.payer || filter.no_receipt);

    return (
        <Panel icon={ListPlus} title="Položky"
            description={total > 0
                ? filtrovano
                    ? `Vyhovuje ${found} z ${total} položek.`
                    : `Celkem ${pocetPolozek(total)}.`
                : undefined}
            actions={<>
                <button type="button" onClick={() => setImporting(v => ! v)}
                    className={`inline-flex min-h-8 items-center gap-1.5 rounded-lg border px-3 text-xs transition-colors ${
                        importing
                            ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 text-[var(--color-text-primary)]'
                            : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                    }`}>
                    <Upload size={13}/> Načíst výpis
                </button>
                {/* Odkaz, ne fetch: stažení souboru přes XHR by znamenalo držet celý
                    obsah v paměti a pak si ho podat blobem sám sobě. */}
                <a href={`/api/v1/rozpocty/${budget.uuid}/export`} download
                    className="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <Download size={13}/> Stáhnout CSV
                </a>
            </>}>

            {importing && (
                <StatementImport budget={budget} categories={categories}
                    onDone={() => { setImporting(false); refresh(); }} onCancel={() => setImporting(false)}/>
            )}

            {/* Zápis je sbalený, dokud ho člověk nechce.

                Rozbalený zabíral i s účtenkou a dělením přes osm set bodů a tlačil první
                položku pod okraj obrazovky — na telefonu se tak seznam otevřel formulářem
                a k tomu, co v rozpočtu je, se člověk musel doscrollovat. Přitom číst se
                chodí častěji než zapisovat. Po zapsání zůstává otevřený, protože kdo zapíše
                jednu položku, zapisuje obvykle rovnou tři. */}
            {! zapisujeSe && (
                <button type="button" onClick={() => setZapisujeSe(true)}
                    className="flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-dashed border-[var(--color-border)] text-sm font-medium text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-accent)] hover:text-[var(--color-text-primary)]">
                    <Plus size={16}/> Zapsat položku
                </button>
            )}

            {/* Zápis má vlastní pozadí, aby bylo poznat, kde končí formulář a začíná
                výpis. Bez toho splyne jedenáct polí a dvacet řádků v jednu plochu. */}
            <div className={`${zapisujeSe ? 'grid' : 'hidden'} gap-2 rounded-xl bg-[var(--color-bg-primary)]/60 p-3 sm:grid-cols-2 lg:grid-cols-6`}>
                <select value={form.kind} onChange={e => setForm(f => ({ ...f, kind: e.target.value }))} className={FIELD}>
                    <option value="expense">Výdaj</option>
                    <option value="income">Příjem</option>
                </select>
                <input type="number" inputMode="decimal" value={form.amount} onChange={e => setForm(f => ({ ...f, amount: e.target.value }))} placeholder="Částka" className={FIELD}/>
                <select value={form.currency} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={FIELD}>
                    {MENY.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
                <select value={form.budget_category_id} onChange={e => setForm(f => ({ ...f, budget_category_id: e.target.value }))} className={FIELD}>
                    <option value="">Bez kategorie</option>
                    {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <input type="date" value={form.spent_on} onChange={e => setForm(f => ({ ...f, spent_on: e.target.value }))} className={FIELD}/>
                <button type="button" onClick={() => void add()} disabled={busy || ! form.amount}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                    <Plus size={14}/> Zapsat
                </button>
                <input value={form.note} onChange={e => setForm(f => ({ ...f, note: e.target.value }))} placeholder="Poznámka" className={`${FIELD} lg:col-span-4`}/>
                <button type="button" onClick={() => setZapisujeSe(false)}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <X size={14}/> Sbalit
                </button>
                <label className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                    <input type="checkbox" checked={form.is_recurring} onChange={e => setForm(f => ({ ...f, is_recurring: e.target.checked }))}/>
                    Pravidelné
                </label>

                {/* Dělení a plátce jen u výdajů. U příjmu nedává „napůl" smysl a prázdné
                    pole, které nic nedělá, mate víc než pole, které tam není. */}
                {form.kind === 'expense' && (
                    <>
                        <select value={form.split} onChange={e => setForm(f => ({ ...f, split: e.target.value }))} className={`${FIELD} lg:col-span-2`}>
                            <option value="none">Jen moje</option>
                            <option value="equal">Napůl</option>
                            <option value="other">Za druhého</option>
                            <option value="ratio">Podle příjmů</option>
                        </select>
                        <select value={form.paid_by} onChange={e => setForm(f => ({ ...f, paid_by: e.target.value }))} className={`${FIELD} lg:col-span-2`}>
                            <option value="">Platil jsem já</option>
                            {members.map(m => <option key={m.id} value={m.id}>Platil{'(a)'} {m.name}</option>)}
                        </select>
                        <p className="self-center text-[11px] text-[var(--color-text-secondary)] lg:col-span-2">
                            {form.split === 'none'
                                ? 'Do vyrovnání se nezapočítá.'
                                : form.split === 'equal'
                                    ? 'Druhý dluží polovinu.'
                                    : 'Druhý dluží celou částku.'}
                        </p>

                        {/* capture="environment" otevře na mobilu rovnou foťák. Účtenku
                            člověk fotí u kasy, ne až doma z galerie. */}
                        <label className="flex cursor-pointer items-center gap-2 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] lg:col-span-3">
                            <input type="file" accept="image/*" capture="environment" className="hidden"
                                onChange={e => { const file = e.target.files?.[0]; e.target.value = ''; if (file) void attachReceipt(file); }}/>
                            {receipt
                                ? <img src={receipt.nahled} alt="" className="h-8 w-8 rounded object-cover"/>
                                : <Receipt size={14}/>}
                            {receipt ? 'Účtenka připojena' : 'Vyfotit účtenku'}
                        </label>

                        {receipt && (
                            <button type="button" onClick={() => { URL.revokeObjectURL(receipt.nahled); setReceipt(null); }}
                                className="self-center text-left text-xs text-[var(--color-text-secondary)] hover:text-red-300 lg:col-span-1">
                                Odebrat
                            </button>
                        )}

                        {receiptState && <p className="self-center text-[11px] text-amber-300 lg:col-span-2">{receiptState}</p>}
                    </>
                )}
            </div>

            {/* Filtry se ukážou, až když je co filtrovat. U deseti položek je hledání
                pole navíc, u pěti set je to jediná cesta, jak něco najít. */}
            {total > 12 && (
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative min-w-40 flex-1">
                        <Search size={13} className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]"/>
                        <input value={filter.q} onChange={e => setFilter(f => ({ ...f, q: e.target.value }))}
                            placeholder="Hledat v poznámkách…" className={`${FIELD} pl-8`}/>
                    </div>
                    <select value={filter.kind} onChange={e => setFilter(f => ({ ...f, kind: e.target.value }))} className={`${FIELD} w-28`}>
                        <option value="">Vše</option>
                        <option value="expense">Výdaje</option>
                        <option value="income">Příjmy</option>
                    </select>
                    <select value={filter.category} onChange={e => setFilter(f => ({ ...f, category: e.target.value }))} className={`${FIELD} w-40`}>
                        <option value="">Všechny kategorie</option>
                        {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                        <option value="none">Bez kategorie</option>
                    </select>
                    {months.length > 1 && (
                        <select value={filter.month} onChange={e => setFilter(f => ({ ...f, month: e.target.value }))} className={`${FIELD} w-40`}>
                            <option value="">Celé období</option>
                            {months.map(m => <option key={m} value={m}>{mesic(m)}</option>)}
                        </select>
                    )}
                    {/* Kdo platil. Seznam uměl hledat text, druh, kategorii i měsíc, ale
                        ne plátce — a „ukaž, co jsem platil já" se pak dalo zjistit jen
                        scrollováním. */}
                    {/* Účastníci z přehledu, ne `members` ze stránky: ten seznam záměrně
                        vynechává přihlášeného (slouží k výběru protistrany), takže by
                        ve filtru chyběl zrovna ten, koho člověk hledá nejčastěji. */}
                    {ucastnici.length > 1 && (
                        <select value={filter.payer} onChange={e => setFilter(f => ({ ...f, payer: e.target.value }))}
                            aria-label="Kdo platil" className={`${FIELD} w-40`}>
                            <option value="">Kdokoliv platil</option>
                            {ucastnici.map(clen => (
                                <option key={clen.user_id} value={clen.user_id}>{clen.name}</option>
                            ))}
                        </select>
                    )}

                    <label className="flex min-h-9 shrink-0 items-center gap-1.5 text-xs text-[var(--color-text-secondary)]">
                        <input type="checkbox" checked={filter.no_receipt}
                            onChange={e => setFilter(f => ({ ...f, no_receipt: e.target.checked }))}
                            className="h-4 w-4 accent-[var(--color-accent)]"/>
                        Bez účtenky
                    </label>

                    {filtrovano && (
                        <button type="button" onClick={() => setFilter({ q: '', kind: '', category: '', month: '', payer: '', no_receipt: false })}
                            className="text-xs text-[var(--color-text-secondary)] underline-offset-2 hover:underline">
                            Zrušit filtry
                        </button>
                    )}
                </div>
            )}

            {/* Vlastní posuvník se stropem. Padesát řádků na stránku je dva tisíce bodů
                výšky — všechno pod položkami by se tím odsunulo mimo dosah. */}
            <div className="mt-3 max-h-[30rem] space-y-1 overflow-y-auto pr-1">
                {rows.map(entry => (
                    editing === entry.uuid
                        ? <EntryEditor key={entry.uuid} budget={budget} categories={categories} members={members} entry={entry}
                            onClose={() => setEditing(null)}
                            onSaved={() => { setEditing(null); refresh(); }}/>
                        : <div key={entry.uuid} className="group flex items-center gap-2 rounded-lg px-2 py-1.5 sm:gap-3 hover:bg-[var(--color-surface-hover)]">
                            <span className="w-11 shrink-0 text-xs text-[var(--color-text-secondary)] sm:w-14">{den(entry.spent_on)}</span>

                            {/* Popis a štítky se na úzké obrazovce zalomí pod sebe. Jeden
                                řádek s ořezem by na telefonu ukázal tři slova a zbytek
                                spolkl — přitom „napůl · Adrian" je ta zajímavá část. */}
                            <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-[var(--color-text-primary)]">
                                <span className="min-w-0 max-w-full truncate">
                                    {entry.note || entry.category || (entry.kind === 'income' ? 'Příjem' : 'Výdaj')}
                                </span>
                                {entry.category && entry.note && <span className="text-[11px] text-[var(--color-text-secondary)]">{entry.category}</span>}
                                {entry.is_recurring && <span className="rounded bg-[var(--color-surface-muted)] px-1.5 py-0.5 text-[9px] text-[var(--color-text-secondary)]">pravidelné</span>}
                                {entry.split && entry.split !== 'none' && (
                                    <span className="rounded bg-[var(--color-accent)]/15 px-1.5 py-0.5 text-[9px] text-[var(--color-accent)]">
                                        {DELENI[entry.split]}{entry.paid_by ? ` · ${entry.paid_by}` : ''}
                                    </span>
                                )}
                                {entry.receipt_uuid && (
                                    <a href={`/media/${entry.receipt_uuid}`} target="_blank" rel="noreferrer" title="Účtenka"
                                        className="inline-flex text-[var(--color-text-secondary)] hover:text-[var(--color-accent)]">
                                        <Receipt size={12}/>
                                    </a>
                                )}
                            </span>

                            <span className={`shrink-0 text-sm tabular-nums ${entry.kind === 'income' ? 'text-emerald-300' : 'text-[var(--color-text-primary)]'}`}>
                                {entry.kind === 'income' ? '+' : '−'}{money(entry.amount, entry.currency)}
                            </span>

                            {/* Na dotyku jsou tlačítka vidět vždycky. Skrývat je za najetí
                                myší znamená, že na telefonu neexistují — a tam se položky
                                zapisují a opravují nejčastěji. */}
                            <button type="button" title="Upravit" onClick={() => setEditing(entry.uuid)}
                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded text-[var(--color-text-secondary)] transition-opacity hover:text-[var(--color-accent)] focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                                <Pencil size={14}/>
                            </button>
                            <DeleteButton
                                label={`Smazat položku ${entry.note || money(entry.amount, entry.currency)} z ${den(entry.spent_on)}`}
                                className="transition-opacity focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                                onDelete={async () => { await axios.delete(`/api/v1/rozpocty/${budget.uuid}/polozky/${entry.uuid}`); refresh(); }}/>
                        </div>
                ))}

                {loading && rows.length === 0 && (
                    <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">Načítám…</p>
                )}

                {! loading && rows.length === 0 && (
                    <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">
                        {filtrovano
                            ? 'Nic, co by odpovídalo filtru.'
                            : 'Zatím nic zapsaného. Zapište první částku, nebo načtěte výpis z banky.'}
                    </p>
                )}
            </div>

            {hasMore && (
                <button type="button" onClick={() => void load(page + 1, true)} disabled={loading}
                    className="mt-3 w-full rounded-lg border border-[var(--color-border)] py-2 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] disabled:opacity-50">
                    {loading ? 'Načítám…' : `Načíst dalších ${Math.min(50, found - rows.length)} z ${found}`}
                </button>
            )}
        </Panel>
    );
}

/**
 * Oprava jedné položky přímo v řádku.
 *
 * Bez tohohle šlo položku jen smazat a napsat znovu — a s ní zmizela účtenka i nastavení
 * dělení, takže překlep v částce stál nové focení a nové naklikání. Otevírá se v místě
 * řádku, ne v dialogu: člověk opravuje to, na co se právě dívá.
 */
function EntryEditor({ budget, categories, members, entry, onClose, onSaved }: {
    budget: Overview['budget']; categories: Overview['categories'];
    members: Array<{ id: number; name: string }>;
    entry: EntryRow; onClose: () => void; onSaved: () => void;
}) {
    const [form, setForm] = useState({
        kind: entry.kind,
        amount: String(entry.amount),
        currency: entry.currency,
        spent_on: entry.spent_on,
        budget_category_id: entry.budget_category_id ? String(entry.budget_category_id) : '',
        note: entry.note ?? '',
        is_recurring: entry.is_recurring,
        // Záměrně jako prostý řetězec: hodnota přichází ze <select>, který užší typ nezná.
        split: String(entry.split ?? 'none'),
        paid_by: entry.paid_by_id ? String(entry.paid_by_id) : '',
    });
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        try {
            await axios.patch(`/api/v1/rozpocty/${budget.uuid}/polozky/${entry.uuid}`, {
                ...form,
                amount: Number(form.amount),
                budget_category_id: form.budget_category_id ? Number(form.budget_category_id) : null,
                paid_by: form.paid_by ? Number(form.paid_by) : null,
            });
            onSaved();
        } finally { setBusy(false); }
    };

    return (
        <div className="rounded-xl border border-[var(--color-accent)]/40 bg-[var(--color-bg-primary)] p-3">
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                <select value={form.kind} onChange={e => setForm(f => ({ ...f, kind: e.target.value }))} className={FIELD}>
                    <option value="expense">Výdaj</option>
                    <option value="income">Příjem</option>
                </select>
                <input type="number" inputMode="decimal" value={form.amount}
                    onChange={e => setForm(f => ({ ...f, amount: e.target.value }))} className={FIELD}/>
                <select value={form.currency} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={FIELD}>
                    {MENY.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
                <select value={form.budget_category_id} onChange={e => setForm(f => ({ ...f, budget_category_id: e.target.value }))} className={FIELD}>
                    <option value="">Bez kategorie</option>
                    {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <input type="date" value={form.spent_on} onChange={e => setForm(f => ({ ...f, spent_on: e.target.value }))} className={FIELD}/>
                <div className="flex gap-2">
                    <button type="button" onClick={() => void save()} disabled={busy || ! form.amount}
                        className="inline-flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-3 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                        <Check size={14}/> Uložit
                    </button>
                    <button type="button" onClick={onClose} title="Zrušit"
                        className="rounded-lg border border-[var(--color-border)] px-2 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                        <X size={14}/>
                    </button>
                </div>

                <input value={form.note} onChange={e => setForm(f => ({ ...f, note: e.target.value }))}
                    placeholder="Poznámka" className={`${FIELD} lg:col-span-4`}/>
                <label className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                    <input type="checkbox" checked={form.is_recurring} onChange={e => setForm(f => ({ ...f, is_recurring: e.target.checked }))}/>
                    Pravidelné
                </label>

                {form.kind === 'expense' && (
                    <>
                        <select value={form.split} onChange={e => setForm(f => ({ ...f, split: e.target.value }))} className={`${FIELD} lg:col-span-2`}>
                            <option value="none">Jen moje</option>
                            <option value="equal">Napůl</option>
                            <option value="other">Za druhého</option>
                            <option value="ratio">Podle příjmů</option>
                        </select>
                        <select value={form.paid_by} onChange={e => setForm(f => ({ ...f, paid_by: e.target.value }))} className={`${FIELD} lg:col-span-2`}>
                            <option value="">Platil jsem já</option>
                            {members.map(m => <option key={m.id} value={m.id}>Platil{'(a)'} {m.name}</option>)}
                        </select>
                    </>
                )}
            </div>

            {entry.receipt_uuid && (
                <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">
                    Účtenka zůstává připojená.{' '}
                    <a href={`/media/${entry.receipt_uuid}`} target="_blank" rel="noreferrer"
                        className="text-[var(--color-accent)] underline-offset-2 hover:underline">Zobrazit</a>
                </p>
            )}
        </div>
    );
}


function MoneyRequests({ requests, members, onChanged }: {
    requests: MoneyRow[]; members: Array<{ id: number; name: string }>; onChanged: () => void;
}) {
    const [form, setForm] = useState({ to_user_id: '', amount: '', currency: 'EUR', reason: '' });
    const [busy, setBusy] = useState(false);
    /** Kurz zná jen ten, kdo směnu provedl — proto se ptáme až tady. */
    const [settling, setSettling] = useState<string | null>(null);
    const [settle, setSettle] = useState({ settled_amount: '', settled_currency: 'CZK', exchange_rate: '' });

    const ask = async () => {
        if (! form.to_user_id || ! form.amount) return;
        setBusy(true);
        try {
            await axios.post('/api/v1/rozpocty/zadost', { ...form, to_user_id: Number(form.to_user_id), amount: Number(form.amount) });
            setForm(f => ({ ...f, amount: '', reason: '' }));
            onChanged();
        } finally { setBusy(false); }
    };

    const respond = async (uuid: string, status: string, extra: Record<string, unknown> = {}) => {
        await axios.post(`/api/v1/rozpocty/zadost/${uuid}`, { status, ...extra });
        setSettling(null);
        setSettle({ settled_amount: '', settled_currency: 'CZK', exchange_rate: '' });
        onChanged();
    };

    return (
        <Panel className="scroll-mt-20" icon={ArrowRightLeft} title="Žádosti o peníze"
            description="Partner dostane upozornění hned. Kolik doopravdy dorazilo a jakým kurzem se zapisuje až při vyřízení.">
            <div id="zadosti" className="sr-only"/>

            {members.length > 0 && (
                <div className="grid gap-2 rounded-xl bg-[var(--color-bg-primary)]/60 p-3 sm:grid-cols-2 lg:grid-cols-5">
                    <select value={form.to_user_id} onChange={e => setForm(f => ({ ...f, to_user_id: e.target.value }))} className={FIELD}>
                        <option value="">Komu…</option>
                        {members.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                    <input type="number" inputMode="decimal" value={form.amount} onChange={e => setForm(f => ({ ...f, amount: e.target.value }))} placeholder="Kolik" className={FIELD}/>
                    <select value={form.currency} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={FIELD}>
                        {['EUR', 'CZK', 'USD', 'GBP', 'PLN', 'CHF'].map(c => <option key={c} value={c}>{c}</option>)}
                    </select>
                    <input value={form.reason} onChange={e => setForm(f => ({ ...f, reason: e.target.value }))} placeholder="Na co (nepovinné)" className={FIELD}/>
                    <button type="button" onClick={() => void ask()} disabled={busy || ! form.to_user_id || ! form.amount}
                        className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                        Požádat
                    </button>
                </div>
            )}

            <div className="mt-4 space-y-2">
                {requests.map(request => (
                    <article key={request.uuid} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3">
                        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span className="text-sm font-medium text-[var(--color-text-primary)]">{money(request.amount, request.currency)}</span>
                            <span className="text-xs text-[var(--color-text-secondary)]">
                                {request.mine ? `→ ${request.to}` : `od ${request.from}`}
                                {request.reason && ` · ${request.reason}`}
                            </span>
                            <span className={`ml-auto rounded-full px-2 py-0.5 text-[10px] ${
                                request.status === 'pending' ? 'bg-amber-400/15 text-amber-200'
                                    : request.status === 'sent' ? 'bg-emerald-400/15 text-emerald-200'
                                    : 'bg-[var(--color-bg-primary)] text-[var(--color-text-secondary)]'
                            }`}>{STAV[request.status] ?? request.status}</span>
                        </div>

                        {request.settled_amount !== null && (
                            <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                Posláno {money(request.settled_amount, request.settled_currency ?? request.currency)}
                                {request.exchange_rate ? ` · kurz ${request.exchange_rate}` : ''}
                            </p>
                        )}

                        {request.status === 'pending' && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {request.mine ? (
                                    <button type="button" onClick={() => void respond(request.uuid, 'cancelled')}
                                        className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 text-xs text-[var(--color-text-secondary)]">Zrušit žádost</button>
                                ) : settling === request.uuid ? (
                                    <div className="grid w-full gap-2 sm:grid-cols-4">
                                        <input type="number" inputMode="decimal" value={settle.settled_amount}
                                            onChange={e => setSettle(s => ({ ...s, settled_amount: e.target.value }))} placeholder="Kolik jsem poslal/a" className={FIELD}/>
                                        <select value={settle.settled_currency} onChange={e => setSettle(s => ({ ...s, settled_currency: e.target.value }))} className={FIELD}>
                                            {['CZK', 'EUR', 'USD', 'GBP', 'PLN', 'CHF'].map(c => <option key={c} value={c}>{c}</option>)}
                                        </select>
                                        <input type="number" inputMode="decimal" step="0.0001" value={settle.exchange_rate}
                                            onChange={e => setSettle(s => ({ ...s, exchange_rate: e.target.value }))} placeholder="Kurz" className={FIELD}/>
                                        <button type="button"
                                            onClick={() => void respond(request.uuid, 'sent', {
                                                settled_amount: settle.settled_amount ? Number(settle.settled_amount) : null,
                                                settled_currency: settle.settled_currency,
                                                exchange_rate: settle.exchange_rate ? Number(settle.exchange_rate) : null,
                                            })}
                                            className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-emerald-500 px-3 text-xs font-medium text-white">
                                            <Check size={13}/> Potvrdit
                                        </button>
                                    </div>
                                ) : (
                                    <>
                                        <button type="button" onClick={() => setSettling(request.uuid)}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-500/15 px-3 py-1.5 text-xs text-emerald-200">
                                            <Check size={13}/> Poslal/a jsem
                                        </button>
                                        <button type="button" onClick={() => void respond(request.uuid, 'declined')}
                                            className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]">
                                            <X size={13}/> Teď nemůžu
                                        </button>
                                    </>
                                )}
                            </div>
                        )}
                    </article>
                ))}
                {requests.length === 0 && <p className="py-3 text-center text-sm text-[var(--color-text-secondary)]">Žádné žádosti.</p>}
            </div>
        </Panel>
    );
}
