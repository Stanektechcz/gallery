import axios from 'axios';
import { CircleDollarSign, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

type Budget = { uuid: string; title: string; occasion?: string | null; scope: 'shared' | 'personal'; year: number; planned_amount: number; currency: string; gift_plan_amount: number; spent_amount: number; remaining_amount: number; over_limit: boolean };
const money = (value: number, currency: string) => new Intl.NumberFormat('cs-CZ', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value || 0);

export default function GiftBudgetPanel({ spaceId }: { spaceId?: number }) {
    const currentYear = new Date().getFullYear();
    const [year, setYear] = useState(currentYear);
    const [budgets, setBudgets] = useState<Budget[]>([]);
    const [form, setForm] = useState({ title: 'Dárky', occasion: '', planned_amount: '', currency: 'CZK', scope: 'shared' as 'shared' | 'personal' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const load = async () => {
        if (!spaceId) return;
        setError('');
        try { const response = await axios.get('/api/v1/calendar/gift-budgets', { params: { gallery_space_id: spaceId, year } }); setBudgets(response.data?.budgets ?? []); }
        catch (reason: any) { setError(reason?.response?.data?.message ?? 'Rozpočty dárků se nepodařilo načíst.'); }
    };
    useEffect(() => { void load(); }, [spaceId, year]);

    const totals = useMemo(() => budgets.reduce<Record<string, { limit: number; spent: number }>>((all, item) => { const key = `${item.scope}-${item.currency}`; all[key] ??= { limit: 0, spent: 0 }; all[key].limit += item.planned_amount; all[key].spent += item.spent_amount; return all; }, {}), [budgets]);
    const create = async (event: FormEvent) => {
        event.preventDefault(); if (!spaceId || !form.title.trim() || !form.planned_amount) return;
        setBusy(true); setError('');
        try { await axios.post('/api/v1/calendar/gift-budgets', { gallery_space_id: spaceId, year, ...form, planned_amount: Number(form.planned_amount), occasion: form.occasion || null }); setForm(current => ({ ...current, title: 'Dárky', occasion: '', planned_amount: '' })); await load(); }
        catch (reason: any) { setError(reason?.response?.data?.message ?? 'Rozpočet se nepodařilo uložit.'); }
        finally { setBusy(false); }
    };
    const remove = async (budget: Budget) => {
        if (!spaceId || !confirm(`Odstranit rozpočet „${budget.title}“?`)) return;
        setBusy(true); setError('');
        try { await axios.delete(`/api/v1/calendar/gift-budgets/${budget.uuid}`, { data: { gallery_space_id: spaceId } }); await load(); }
        catch (reason: any) { setError(reason?.response?.data?.message ?? 'Rozpočet se nepodařilo odstranit.'); }
        finally { setBusy(false); }
    };

    return <section className="rounded-2xl border border-emerald-400/20 bg-[var(--color-bg-card)] p-4 sm:p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]"><CircleDollarSign size={18} className="text-emerald-300"/>Roční rozpočet dárků</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Plán se porovnává s hodnotou dárků a skutečné čerpání vzniká až po označení „Koupeno“. Soukromé překvapení je jen v osobním rozpočtu autora.</p></div><label className="text-xs text-[var(--color-text-secondary)]">Rok<input type="number" min="2000" max="2100" value={year} onChange={event => setYear(Math.max(2000, Math.min(2100, Number(event.target.value) || currentYear)))} className="ml-2 min-h-9 w-20 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 text-sm text-[var(--color-text-primary)]"/></label></div>
        {error && <p className="mt-3 rounded-lg bg-red-500/10 p-2 text-xs text-red-200">{error}</p>}
        {!!Object.keys(totals).length && <div className="mt-4 grid gap-2 sm:grid-cols-2">{Object.entries(totals).map(([key, total]) => <div key={key} className="rounded-xl bg-[var(--color-surface-muted)] p-3 text-sm"><p className="text-xs text-[var(--color-text-secondary)]">{key.startsWith('shared') ? 'Společný rozpočet' : 'Můj soukromý rozpočet'} · {key.split('-').at(-1)}</p><p className="mt-1 font-medium text-[var(--color-text-primary)]">Utraceno {money(total.spent, key.split('-').at(-1) ?? 'CZK')} z {money(total.limit, key.split('-').at(-1) ?? 'CZK')}</p></div>)}</div>}
        <form onSubmit={create} className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-5"><input value={form.title} onChange={event => setForm({ ...form, title: event.target.value })} placeholder="Název rozpočtu" required className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm text-[var(--color-text-primary)]"/><input value={form.occasion} onChange={event => setForm({ ...form, occasion: event.target.value })} placeholder="Příležitost (volitelné)" className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm text-[var(--color-text-primary)]"/><input type="number" min="0" step="1" value={form.planned_amount} onChange={event => setForm({ ...form, planned_amount: event.target.value })} placeholder="Limit" required className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm text-[var(--color-text-primary)]"/><select value={form.scope} onChange={event => setForm({ ...form, scope: event.target.value as 'shared' | 'personal' })} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-sm text-[var(--color-text-primary)]"><option value="shared">Společný</option><option value="personal">Můj soukromý</option></select><button disabled={busy} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-3 text-sm text-white disabled:opacity-50"><Plus size={15}/>Přidat limit</button></form>
        <div className="mt-4 grid gap-2 lg:grid-cols-2">{budgets.map(budget => { const ratio = budget.planned_amount > 0 ? Math.min(100, Math.round((budget.spent_amount / budget.planned_amount) * 100)) : 0; return <article key={budget.uuid} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3"><div className="flex gap-3"><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h3 className="truncate text-sm font-medium text-[var(--color-text-primary)]">{budget.title}</h3><span className="rounded bg-[var(--color-surface-muted)] px-2 py-0.5 text-[10px] text-[var(--color-text-secondary)]">{budget.scope === 'shared' ? 'společný' : 'soukromý'}</span>{budget.occasion && <span className="text-[10px] text-[var(--color-text-secondary)]">{budget.occasion}</span>}</div><p className={`mt-1 text-xs ${budget.over_limit ? 'text-red-200' : 'text-[var(--color-text-secondary)]'}`}>{money(budget.spent_amount, budget.currency)} skutečně · {money(budget.gift_plan_amount, budget.currency)} v nápadech · zbývá {money(budget.remaining_amount, budget.currency)}</p><div className="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-muted)]"><div className={budget.over_limit ? 'h-full bg-red-400' : 'h-full bg-emerald-400'} style={{ width: `${ratio}%` }}/></div></div><button type="button" disabled={busy} onClick={() => void remove(budget)} aria-label={`Odstranit rozpočet ${budget.title}`} className="rounded-lg p-2 text-[var(--color-text-secondary)] hover:bg-red-500/10 hover:text-red-200 disabled:opacity-50"><Trash2 size={15}/></button></div></article>; })}{!budgets.length && <p className="rounded-xl border border-dashed border-[var(--color-border)] p-5 text-center text-sm text-[var(--color-text-secondary)] lg:col-span-2">Pro tento rok zatím není nastavený žádný limit.</p>}</div>
    </section>;
}