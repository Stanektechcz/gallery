import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { ArrowRightLeft, Check, Plus, Trash2, Wallet, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Rozpočty na období.
 *
 * Finance v aplikaci byly navázané na cestu. Půl roku v cizině není cesta: je tam příjem,
 * nájem, druhá měna a partner o dva státy dál, kterého je občas potřeba požádat o peníze.
 *
 * Měny se nikde nesčítají. Kurz nemáme odkud brát a odhadnout ho u peněz je horší než ho
 * neznat — kdo si podle vymyšleného kurzu naplánuje nájem, zjistí to až na účtu.
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
    budget: { uuid: string; name: string; currency: string; starts_on: string; ends_on: string | null; monthly_income: number | null; note: string | null; is_shared: boolean; owner: { id: number; name: string } | null };
    period: { days_elapsed: number; days_left: number | null; days_total: number | null; has_started: boolean; has_ended: boolean };
    totals: { spent: Record<string, number>; income: Record<string, number> };
    categories: Array<{ id: number; name: string; color: string | null; planned_monthly: number; planned_to_date: number; spent: number; used_percent: number | null }>;
    months: Array<{ month: string; spent: number; income: number; count: number }>;
    allowance: { planned_total: number; spent: number; left: number; per_day: number | null; days_left: number | null; currency: string };
    warnings?: Array<{ category: string; spent: number; planned_to_date: number; percent: number; level: 'close' | 'over' }>;
    entries: Array<{ uuid: string; kind: string; amount: number; currency: string; spent_on: string; note: string | null; is_recurring: boolean; category: string | null; author: string | null }>;
}

const money = (amount: number, currency: string) =>
    new Intl.NumberFormat('cs-CZ', { style: 'currency', currency, maximumFractionDigits: 2 }).format(amount);

const den = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' });

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

            <div className="p-4 sm:p-6">
                <header className="mb-6">
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
                    <div className="space-y-6">
                        <BudgetPicker budgets={budgets} active={active?.budget.uuid} onPick={uuid => void load(uuid)} onCreated={uuid => void load(uuid)}/>

                        {active && <Overview data={active} onChanged={() => void load(active.budget.uuid)}/>}

                        <MoneyRequests requests={requests} members={members} onChanged={() => void load(active?.budget.uuid)}/>
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
    const [form, setForm] = useState({ name: '', currency: 'EUR', starts_on: '', ends_on: '', monthly_income: '', is_shared: true });
    const [busy, setBusy] = useState(false);

    const create = async () => {
        if (! form.name.trim() || ! form.starts_on) return;

        setBusy(true);
        try {
            const created = await axios.post('/api/v1/rozpocty', {
                ...form,
                monthly_income: form.monthly_income === '' ? null : Number(form.monthly_income),
                ends_on: form.ends_on || null,
            });
            setAdding(false);
            setForm({ name: '', currency: 'EUR', starts_on: '', ends_on: '', monthly_income: '', is_shared: true });
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

function Overview({ data, onChanged }: { data: Overview; onChanged: () => void }) {
    const { budget, period, totals, categories, months, allowance } = data;
    const meny = Object.keys({ ...totals.spent, ...totals.income });

    return (
        <>
            {/* Denní zbytek nahoře: v cizině je to jediné číslo, které opravdu řídí chování. */}
            <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Tile label="Zbývá na den" value={allowance.per_day !== null ? money(allowance.per_day, allowance.currency) : '—'}
                    hint={allowance.days_left !== null ? `na ${allowance.days_left} dní` : 'bez konce období'} accent/>
                <Tile label="Zbývá celkem" value={money(allowance.left, allowance.currency)} hint={`z ${money(allowance.planned_total, allowance.currency)}`}/>
                <Tile label="Utraceno" value={money(allowance.spent, allowance.currency)}
                    hint={meny.length > 1 ? `+ ${meny.filter(m => m !== budget.currency).map(m => money(totals.spent[m] ?? 0, m)).join(', ')}` : `${period.days_elapsed} dní`}/>
                <Tile label="Příjem" value={money(totals.income[budget.currency] ?? 0, budget.currency)}
                    hint={budget.monthly_income ? `plán ${money(budget.monthly_income, budget.currency)} / měsíc` : 'zatím bez plánu'}/>
            </section>

            {/* Co dochází, hned pod čísly. Prázdné je dobrá zpráva a nic se nekreslí —
                varování, které svítí pořád, se přestane číst. */}
            {data.warnings && data.warnings.length > 0 && (
                <section className="rounded-2xl border border-amber-400/25 bg-amber-500/5 p-4">
                    <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Pozor na tyhle kategorie</h2>
                    <div className="mt-2 space-y-1.5">
                        {data.warnings.map(warning => (
                            <p key={warning.category} className="flex flex-wrap items-baseline gap-x-2 text-xs">
                                <span className={warning.level === 'over' ? 'text-red-300' : 'text-amber-200'}>
                                    {warning.category} — {warning.percent} %
                                </span>
                                <span className="text-[var(--color-text-secondary)]">
                                    {money(warning.spent, budget.currency)} z {money(warning.planned_to_date, budget.currency)},
                                    které měly padnout do dneška
                                </span>
                            </p>
                        ))}
                    </div>
                </section>
            )}

            <Categories budget={budget} categories={categories} onChanged={onChanged}/>

            {/* Kam peníze tečou. Koláč by ukázal totéž, ale porovnat dva výseče od oka
                nikdo neumí — délky vedle sebe ano. */}
            {categories.some(c => c.spent > 0) && (
                <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                    <h2 className="mb-3 text-sm font-semibold text-[var(--color-text-primary)]">Kam to jde</h2>

                    {(() => {
                        const utracene = categories.filter(c => c.spent > 0).sort((a, b) => b.spent - a.spent);
                        const celkem = utracene.reduce((sum, c) => sum + c.spent, 0);
                        const nejvic = Math.max(...utracene.map(c => c.spent), 1);
                        const BARVY = ['bg-violet-400', 'bg-sky-400', 'bg-emerald-400', 'bg-amber-400', 'bg-rose-400', 'bg-teal-400'];

                        return (
                            <>
                                {/* Jeden pruh složený z podílů — poměr celku je vidět dřív
                                    než jednotlivé částky. */}
                                <div className="mb-4 flex h-3 overflow-hidden rounded-full">
                                    {utracene.map((category, index) => (
                                        <div key={category.id} className={BARVY[index % BARVY.length]}
                                            style={{ width: `${category.spent / celkem * 100}%` }}
                                            title={`${category.name}: ${money(category.spent, budget.currency)}`}/>
                                    ))}
                                </div>

                                <div className="space-y-2">
                                    {utracene.map((category, index) => (
                                        <div key={category.id} className="flex items-center gap-2.5">
                                            <span className={`h-2.5 w-2.5 shrink-0 rounded-sm ${BARVY[index % BARVY.length]}`}/>
                                            <span className="w-24 shrink-0 truncate text-xs text-[var(--color-text-primary)]">{category.name}</span>
                                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                                                <div className={`h-full ${BARVY[index % BARVY.length]} opacity-70`}
                                                    style={{ width: `${category.spent / nejvic * 100}%` }}/>
                                            </div>
                                            <span className="w-24 shrink-0 text-right text-xs text-[var(--color-text-secondary)]">
                                                {money(category.spent, budget.currency)}
                                            </span>
                                            <span className="w-10 shrink-0 text-right text-[10px] text-[var(--color-text-secondary)]">
                                                {Math.round(category.spent / celkem * 100)} %
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </>
                        );
                    })()}
                </section>
            )}

            {months.length > 0 && (
                <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                    <h2 className="mb-3 text-sm font-semibold text-[var(--color-text-primary)]">Měsíc po měsíci</h2>
                    {/* Příjem i výdaj v jednom měřítku, aby šlo vidět, který měsíc byl
                        ztrátový. Dva grafy pod sebou nutí porovnávat očima přes mezeru. */}
                    <div className="space-y-3">
                        {months.map(month => {
                            const nejvic = Math.max(...months.flatMap(m => [m.spent, m.income]), 1);
                            const zbylo = month.income - month.spent;

                            return (
                                <div key={month.month}>
                                    <div className="mb-1 flex items-baseline justify-between text-xs">
                                        <span className="text-[var(--color-text-secondary)]">{month.month}</span>
                                        <span className={zbylo >= 0 ? 'text-emerald-300' : 'text-red-300'}>
                                            {zbylo >= 0 ? '+' : '−'}{money(Math.abs(zbylo), budget.currency)}
                                        </span>
                                    </div>

                                    <div className="space-y-0.5">
                                        {month.income > 0 && (
                                            <div className="flex items-center gap-2">
                                                <span className="w-10 shrink-0 text-[9px] text-[var(--color-text-secondary)]">příjem</span>
                                                <div className="h-2.5 flex-1 overflow-hidden rounded bg-[var(--color-bg-primary)]">
                                                    <div className="h-full bg-emerald-400/70" style={{ width: `${month.income / nejvic * 100}%` }}/>
                                                </div>
                                                <span className="w-24 shrink-0 text-right text-[10px] text-[var(--color-text-secondary)]">{money(month.income, budget.currency)}</span>
                                            </div>
                                        )}
                                        <div className="flex items-center gap-2">
                                            <span className="w-10 shrink-0 text-[9px] text-[var(--color-text-secondary)]">výdaj</span>
                                            <div className="h-2.5 flex-1 overflow-hidden rounded bg-[var(--color-bg-primary)]">
                                                <div className="h-full bg-[var(--color-accent)]/70" style={{ width: `${month.spent / nejvic * 100}%` }}/>
                                            </div>
                                            <span className="w-24 shrink-0 text-right text-[10px] text-[var(--color-text-primary)]">{money(month.spent, budget.currency)}</span>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>
            )}

            <Entries budget={budget} categories={categories} entries={data.entries} onChanged={onChanged}/>
        </>
    );
}

function Tile({ label, value, hint, accent = false }: { label: string; value: string; hint?: string; accent?: boolean }) {
    return (
        <div className={`rounded-2xl border p-4 ${accent ? 'border-[var(--color-accent)]/30 bg-[var(--color-accent)]/5' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}>
            <p className="text-xs uppercase tracking-wider text-[var(--color-text-secondary)]">{label}</p>
            <p className="mt-1 text-lg font-semibold text-[var(--color-text-primary)]">{value}</p>
            {hint && <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">{hint}</p>}
        </div>
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
        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="mb-3 text-sm font-semibold text-[var(--color-text-primary)]">Kategorie</h2>

            <div className="space-y-3">
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
                            <button type="button" title="Smazat kategorii"
                                onClick={async () => { await axios.delete(`/api/v1/rozpocty/${budget.uuid}/kategorie/${category.id}`); onChanged(); }}
                                className="rounded p-1 text-[var(--color-text-secondary)] hover:text-red-300">
                                <Trash2 size={13}/>
                            </button>
                        </div>
                    </div>
                ))}
            </div>

            <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                <input value={name} onChange={e => setName(e.target.value)} placeholder="Nájem, jídlo, doprava…" className={FIELD}/>
                <input type="number" inputMode="decimal" value={planned} onChange={e => setPlanned(e.target.value)}
                    placeholder={`za měsíc (${budget.currency})`} className={`${FIELD} sm:w-48`}/>
                <button type="button" onClick={() => void add()} disabled={! name.trim()}
                    className="inline-flex min-h-10 shrink-0 items-center justify-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                    <Plus size={14}/> Přidat
                </button>
            </div>
        </section>
    );
}

function Entries({ budget, categories, entries, onChanged }: {
    budget: Overview['budget']; categories: Overview['categories']; entries: Overview['entries']; onChanged: () => void;
}) {
    const [form, setForm] = useState({ kind: 'expense', amount: '', currency: budget.currency, spent_on: new Date().toISOString().slice(0, 10), budget_category_id: '', note: '', is_recurring: false });
    const [busy, setBusy] = useState(false);

    const add = async () => {
        if (! form.amount) return;
        setBusy(true);
        try {
            await axios.post(`/api/v1/rozpocty/${budget.uuid}/polozky`, {
                ...form,
                amount: Number(form.amount),
                budget_category_id: form.budget_category_id ? Number(form.budget_category_id) : null,
            });
            setForm(f => ({ ...f, amount: '', note: '' }));
            onChanged();
        } finally { setBusy(false); }
    };

    return (
        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="mb-3 text-sm font-semibold text-[var(--color-text-primary)]">Položky</h2>

            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                <select value={form.kind} onChange={e => setForm(f => ({ ...f, kind: e.target.value }))} className={FIELD}>
                    <option value="expense">Výdaj</option>
                    <option value="income">Příjem</option>
                </select>
                <input type="number" inputMode="decimal" value={form.amount} onChange={e => setForm(f => ({ ...f, amount: e.target.value }))} placeholder="Částka" className={FIELD}/>
                <select value={form.currency} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={FIELD}>
                    {['EUR', 'CZK', 'USD', 'GBP', 'PLN', 'CHF'].map(c => <option key={c} value={c}>{c}</option>)}
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
            </div>

            <div className="mt-4 space-y-1">
                {entries.map(entry => (
                    <div key={entry.uuid} className="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-[var(--color-surface-hover)]">
                        <span className="w-14 shrink-0 text-xs text-[var(--color-text-secondary)]">{den(entry.spent_on)}</span>
                        <span className="min-w-0 flex-1 truncate text-sm text-[var(--color-text-primary)]">
                            {entry.note || entry.category || (entry.kind === 'income' ? 'Příjem' : 'Výdaj')}
                            {entry.category && entry.note && <span className="ml-2 text-[11px] text-[var(--color-text-secondary)]">{entry.category}</span>}
                            {entry.is_recurring && <span className="ml-2 rounded bg-[var(--color-surface-muted)] px-1.5 py-0.5 text-[9px] text-[var(--color-text-secondary)]">pravidelné</span>}
                        </span>
                        <span className={`shrink-0 text-sm ${entry.kind === 'income' ? 'text-emerald-300' : 'text-[var(--color-text-primary)]'}`}>
                            {entry.kind === 'income' ? '+' : '−'}{money(entry.amount, entry.currency)}
                        </span>
                        <button type="button" title="Smazat"
                            onClick={async () => { await axios.delete(`/api/v1/rozpocty/${budget.uuid}/polozky/${entry.uuid}`); onChanged(); }}
                            className="rounded p-1 text-[var(--color-text-secondary)] hover:text-red-300">
                            <Trash2 size={13}/>
                        </button>
                    </div>
                ))}
                {entries.length === 0 && <p className="py-4 text-center text-sm text-[var(--color-text-secondary)]">Zatím nic zapsaného.</p>}
            </div>
        </section>
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
        <section id="zadosti" className="scroll-mt-20 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="mb-1 flex items-center gap-2 text-sm font-semibold text-[var(--color-text-primary)]">
                <ArrowRightLeft size={15}/> Žádosti o peníze
            </h2>
            <p className="mb-3 text-xs text-[var(--color-text-secondary)]">
                Partner dostane upozornění hned. Kolik doopravdy dorazilo a jakým kurzem se zapisuje až při vyřízení.
            </p>

            {members.length > 0 && (
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
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
        </section>
    );
}
