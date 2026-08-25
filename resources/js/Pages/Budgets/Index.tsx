import DeleteButton from '@/Components/DeleteButton';
import Panel, { PanelGrid, Stat } from '@/Components/Panel';
import SekceNav, { type Sekce as SekceTyp } from '@/Components/SekceNav';
import { dny } from '@/lib/cestina';
import AppLayout from '@/Layouts/AppLayout';
import { uploadManager, waitForUploads } from '@/lib/uploadManager';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle, ArrowRightLeft, BarChart3, CalendarDays, Check, Download, LayoutDashboard, ListPlus,
    Pencil, PieChart, PiggyBank, Plus, Receipt, Repeat, Scale, Search, Tags, Target, TrendingDown,
    TrendingUp, Upload, Wallet, X,
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
    budget: { uuid: string; name: string; currency: string; starts_on: string; ends_on: string | null; monthly_income: number | null; note: string | null; is_shared: boolean; owner: { id: number; name: string } | null;
        savings_target: number | null; savings_target_on: string | null; period_unit: 'month' | 'week'; period_label: string };
    period: { days_elapsed: number; days_left: number | null; days_total: number | null; has_started: boolean; has_ended: boolean };
    totals: {
        spent: Record<string, number>; income: Record<string, number>;
        /** Součet přes měny. Null, když kurz chybí nebo je všechno v jedné měně. */
        spent_combined: { total: number; currency: string; date: string; rates: Record<string, number> } | null;
    };
    categories: Array<{ id: number; name: string; color: string | null; planned_monthly: number; planned_to_date: number; spent: number; used_percent: number | null }>;
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
    /** Kolik položek rozpočet má. Samotný seznam si načítá vlastní koncový bod. */
    entries_total: number;
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

const DELENI: Record<string, string> = { none: 'moje', equal: 'napůl', other: 'za druhého' };

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
                                onChanged={() => void load(active.budget.uuid)}/>
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
    const [form, setForm] = useState({ name: '', currency: 'EUR', starts_on: '', ends_on: '', monthly_income: '', is_shared: true, period_unit: 'month', savings_target: '', savings_target_on: '' });
    const [busy, setBusy] = useState(false);

    const create = async () => {
        if (! form.name.trim() || ! form.starts_on) return;

        setBusy(true);
        try {
            const created = await axios.post('/api/v1/rozpocty', {
                ...form,
                monthly_income: form.monthly_income === '' ? null : Number(form.monthly_income),
                savings_target: form.savings_target === '' ? null : Number(form.savings_target),
                savings_target_on: form.savings_target_on || null,
                ends_on: form.ends_on || null,
            });
            setAdding(false);
            setForm({ name: '', currency: 'EUR', starts_on: '', ends_on: '', monthly_income: '', is_shared: true, period_unit: 'month', savings_target: '', savings_target_on: '' });
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

function Overview({ data, members, requests, onChanged }: {
    data: Overview;
    members: Array<{ id: number; name: string }>;
    requests: MoneyRow[];
    onChanged: () => void;
}) {
    const { budget, period, totals, categories, months, allowance } = data;
    const meny = Object.keys({ ...totals.spent, ...totals.income });
    const [sekce, setSekce] = useSekce('prehled');

    // Stavové panely se skládají do pole, protože se každý ukazuje jen někdy. Mřížka
    // o dvou sloupcích s jedním potomkem nechá půlku řádku prázdnou a vypadá jako
    // nedokreslená — tohle podle počtu přepne na jeden sloupec.
    const stav = [
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
        { id: 'prehled', label: 'Přehled', icon: LayoutDashboard, upozorneni: (data.warnings?.length ?? 0) > 0 || data.outlook?.verdict === 'short' },
        { id: 'polozky', label: 'Položky', icon: Receipt, pocet: data.entries_total },
        { id: 'plan', label: 'Plán', icon: Target, pocet: categories.length },
        { id: 'vyvoj', label: 'Vývoj', icon: TrendingUp },
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
                    <PanelGrid max={2}>{stav}</PanelGrid>
                    <MoneyRequests requests={requests} members={members} onChanged={onChanged}/>
                </div>
            )}

            {/* Zápis a výpis položek. Celá šířka, protože formulář má šest polí vedle
                sebe — v půlce obrazovky by se zlomil na tři řádky. */}
            {sekce === 'polozky' && (
                <Entries budget={budget} categories={categories} members={members} total={data.entries_total} onChanged={onChanged}/>
            )}

            {/* Plán vedle skutečnosti. Dvě odpovědi na jednu otázku — kolik mělo padnout
                a kam to opravdu šlo — a mají smysl jen společně. */}
            {sekce === 'plan' && (
                <div className="space-y-4">
                    <PanelGrid max={2}>
                        <Categories budget={budget} categories={categories} onChanged={onChanged}/>
                        {categories.some(c => c.spent > 0) && <BreakdownPanel categories={categories} currency={budget.currency}/>}
                    </PanelGrid>

                    {(data.recurring?.length ?? 0) > 0 && (
                        <RecurringPanel budget={budget} rows={data.recurring!} onChanged={onChanged}/>
                    )}
                </div>
            )}

            {/* Čas: celé období vlevo, poslední dva měsíce podrobně vpravo. */}
            {sekce === 'vyvoj' && (
                <PanelGrid max={2}>
                    {months.length > 0 && <MonthsPanel months={months} currency={budget.currency}/>}
                    {data.comparison && <Comparison data={data.comparison}/>}
                </PanelGrid>
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
function BreakdownPanel({ categories, currency }: { categories: Overview['categories']; currency: string }) {
    const utracene = categories.filter(c => c.spent > 0).sort((a, b) => b.spent - a.spent);
    const celkem = utracene.reduce((sum, c) => sum + c.spent, 0);
    const nejvic = Math.max(...utracene.map(c => c.spent), 1);
    const BARVY = ['bg-violet-400', 'bg-sky-400', 'bg-emerald-400', 'bg-amber-400', 'bg-rose-400', 'bg-teal-400'];

    return (
        <Panel icon={PieChart} title="Kam to jde">
            {/* Jeden pruh složený z podílů — poměr celku je vidět dřív než částky. */}
            <div className="mb-4 flex h-3 overflow-hidden rounded-full">
                {utracene.map((category, index) => (
                    <div key={category.id} className={BARVY[index % BARVY.length]}
                        style={{ width: `${category.spent / celkem * 100}%` }}
                        title={`${category.name}: ${money(category.spent, currency)}`}/>
                ))}
            </div>

            <div className="space-y-2.5">
                {utracene.map((category, index) => (
                    <div key={category.id} className="flex items-center gap-2.5">
                        <span className={`h-2.5 w-2.5 shrink-0 rounded-sm ${BARVY[index % BARVY.length]}`}/>
                        <span className="w-20 shrink-0 truncate text-xs text-[var(--color-text-primary)]">{category.name}</span>
                        <div className="h-2 min-w-8 flex-1 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                            <div className={`h-full ${BARVY[index % BARVY.length]} opacity-70`}
                                style={{ width: `${category.spent / nejvic * 100}%` }}/>
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
function MonthsPanel({ months, currency }: { months: Overview['months']; currency: string }) {
    const nejvic = Math.max(...months.flatMap(m => [m.spent, m.income]), 1);

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

function Categories({ budget, categories, onChanged }: { budget: Overview['budget']; categories: Overview['categories']; onChanged: () => void }) {
    const [name, setName] = useState('');
    const [planned, setPlanned] = useState('');

    const add = async () => {
        if (! name.trim()) return;
        await axios.post(`/api/v1/rozpocty/${budget.uuid}/kategorie`, { name: name.trim(), planned_monthly: Number(planned || 0) });
        setName(''); setPlanned('');
        onChanged();
    };

    return (
        <Panel icon={Tags} title="Plán proti skutečnosti"
            description={`Kolik mělo padnout do dneška — přepočteno na to, co z období uplynulo. Plán se zadává ${budget.period_label}.`}>
            <div className="space-y-3.5">
                {categories.length === 0 && (
                    <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs text-[var(--color-text-secondary)]">
                        Zatím žádné kategorie. Bez nich rozpočet jen sčítá — s nimi řekne, na čem se přepálilo.
                    </p>
                )}
                {categories.map(category => (
                    <div key={category.id}>
                        <div className="flex items-baseline justify-between gap-2 text-sm">
                            <span className="text-[var(--color-text-primary)]">{category.name}</span>
                            <span className="text-xs text-[var(--color-text-secondary)]">
                                {money(category.spent, budget.currency)}
                                {category.planned_to_date > 0 && <> z {money(category.planned_to_date, budget.currency)}</>}
                            </span>
                        </div>
                        <div className="mt-1 flex items-center gap-2">
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                                {/* Nad sto procent je varování, ne chyba — někdo prostě utratil víc. */}
                                <div className={`h-full ${(category.used_percent ?? 0) > 100 ? 'bg-red-400' : 'bg-emerald-400/80'}`}
                                    style={{ width: `${Math.min(100, category.used_percent ?? 0)}%` }}/>
                            </div>
                            <span className={`w-12 shrink-0 text-right text-[11px] ${(category.used_percent ?? 0) > 100 ? 'text-red-300' : 'text-[var(--color-text-secondary)]'}`}>
                                {category.used_percent !== null ? `${category.used_percent} %` : '—'}
                            </span>
                            <DeleteButton
                                label={`Smazat kategorii ${category.name}`}
                                onDelete={async () => { await axios.delete(`/api/v1/rozpocty/${budget.uuid}/kategorie/${category.id}`); onChanged(); }}/>
                        </div>
                    </div>
                ))}
            </div>

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
function Entries({ budget, categories, members, total, onChanged }: {
    budget: Overview['budget']; categories: Overview['categories'];
    members: Array<{ id: number; name: string }>;
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
    const [filter, setFilter] = useState({ q: '', kind: '', category: '', month: '' });

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

    const filtrovano = Boolean(filter.q || filter.kind || filter.category || filter.month);

    return (
        <Panel icon={ListPlus} title="Položky"
            description={total > 0
                ? filtrovano
                    ? `Vyhovuje ${found} z ${total} položek.`
                    : `Celkem ${total} ${total === 1 ? 'položka' : total <= 4 ? 'položky' : 'položek'}.`
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

            {/* Zápis má vlastní pozadí, aby bylo poznat, kde končí formulář a začíná
                výpis. Bez toho splyne jedenáct polí a dvacet řádků v jednu plochu. */}
            <div className="grid gap-2 rounded-xl bg-[var(--color-bg-primary)]/60 p-3 sm:grid-cols-2 lg:grid-cols-6">
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
                <input value={form.note} onChange={e => setForm(f => ({ ...f, note: e.target.value }))} placeholder="Poznámka" className={`${FIELD} lg:col-span-5`}/>
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
                    {filtrovano && (
                        <button type="button" onClick={() => setFilter({ q: '', kind: '', category: '', month: '' })}
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
