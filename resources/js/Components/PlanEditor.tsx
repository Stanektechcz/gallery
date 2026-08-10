import axios from 'axios';
import { Loader2, Pencil } from 'lucide-react';
import { useState } from 'react';

export interface EditablePlan {
    code: string;
    name: string;
    tagline?: string | null;
    price_monthly: number;
    price_yearly: number;
    currency: string;
    member_limit: number | null;
    storage_limit_mb?: number | null;
    is_public?: boolean;
    is_default?: boolean;
    subscribers?: number;
}

/** Whole units on screen, hundredths on the wire — money is stored in hundredths everywhere. */
const toMajor = (minor: number) => String(Math.round(minor / 100));
const toMinor = (major: string) => Math.round(Number(major.replace(',', '.')) * 100) || 0;

/**
 * The catalogue itself: names, prices, limits, whether a plan is on offer.
 *
 * This lived in a seeder, so every price change was a deployment. It is deliberately
 * separate from the feature matrix below it — that decides what a plan *is*, this decides
 * what it costs and who can see it, and mixing the two makes a wide table nobody can read.
 *
 * A price change does not touch anybody already subscribed; their window was paid at the
 * old price. The subscriber count is shown for that reason: it is the number of people a
 * mistake here would reach.
 */
export default function PlanEditor({ plans, onSaved }: {
    plans: EditablePlan[];
    onSaved: (payload: any) => void;
}) {
    const [editing, setEditing] = useState<string | null>(null);
    const [draft, setDraft] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const open = (plan: EditablePlan) => {
        setEditing(plan.code);
        setError('');
        setDraft({
            name: plan.name,
            tagline: plan.tagline ?? '',
            price_monthly: toMajor(plan.price_monthly),
            price_yearly: toMajor(plan.price_yearly),
            member_limit: plan.member_limit === null ? '' : String(plan.member_limit),
            storage_limit_mb: plan.storage_limit_mb == null ? '' : String(plan.storage_limit_mb),
            is_public: plan.is_public === false ? 'ne' : 'ano',
        });
    };

    const save = async (code: string) => {
        setBusy(true); setError('');
        try {
            const response = await axios.put(`/api/v1/admin/billing/plans/${encodeURIComponent(code)}`, {
                name: draft.name,
                tagline: draft.tagline || null,
                price_monthly: toMinor(draft.price_monthly),
                price_yearly: toMinor(draft.price_yearly),
                member_limit: draft.member_limit === '' ? null : Number(draft.member_limit),
                storage_limit_mb: draft.storage_limit_mb === '' ? null : Number(draft.storage_limit_mb),
                is_public: draft.is_public === 'ano',
            });
            onSaved(response.data);
            setEditing(null);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Tarif se nepodařilo uložit.');
        } finally { setBusy(false); }
    };

    const field = (key: string, label: string, extra: Record<string, unknown> = {}) => (
        <label className="block">
            <span className="text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</span>
            <input
                value={draft[key] ?? ''}
                onChange={event => setDraft(current => ({ ...current, [key]: event.target.value }))}
                className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-1.5 text-sm text-[var(--color-text-primary)]"
                {...extra}
            />
        </label>
    );

    return (
        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="font-semibold text-[var(--color-text-primary)]">Ceník tarifů</h2>
            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                Změna ceny se nedotkne nikoho, kdo už tarif má — jejich období je zaplacené za starou cenu.
                Nová se uplatní až při dalším nákupu.
            </p>

            {error && <p role="alert" className="mt-2 rounded-lg bg-red-500/10 p-2 text-xs text-red-200">{error}</p>}

            <div className="mt-3 space-y-2">
                {plans.map(plan => (
                    <div key={plan.code} className="rounded-xl border border-[var(--color-border)] p-3">
                        <div className="flex items-center gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-[var(--color-text-primary)]">
                                    {plan.name}
                                    {plan.is_default && <span className="ml-2 text-[10px] text-[var(--color-accent)]">výchozí</span>}
                                    {plan.is_public === false && <span className="ml-2 text-[10px] text-[var(--color-text-secondary)]">skrytý</span>}
                                </p>
                                <p className="text-xs text-[var(--color-text-secondary)]">
                                    {plan.price_monthly === 0 ? 'zdarma' : `${toMajor(plan.price_monthly)} ${plan.currency} měsíčně`}
                                    {plan.price_yearly > 0 && ` · ${toMajor(plan.price_yearly)} ${plan.currency} ročně`}
                                    {plan.member_limit !== null && ` · ${plan.member_limit} členů`}
                                    {plan.subscribers !== undefined && ` · ${plan.subscribers} předplatných`}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => (editing === plan.code ? setEditing(null) : open(plan))}
                                aria-label={`Upravit tarif ${plan.name}`}
                                className="shrink-0 rounded-lg border border-[var(--color-border)] p-2 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]"
                            >
                                <Pencil size={14} />
                            </button>
                        </div>

                        {editing === plan.code && (
                            <div className="mt-3 grid gap-2 border-t border-[var(--color-border)] pt-3 sm:grid-cols-2">
                                {field('name', 'Název')}
                                {field('tagline', 'Podtitul')}
                                {field('price_monthly', `Měsíčně (${plan.currency})`, { inputMode: 'numeric' })}
                                {field('price_yearly', `Ročně (${plan.currency})`, { inputMode: 'numeric' })}
                                {field('member_limit', 'Limit členů (prázdné = bez limitu)', { inputMode: 'numeric' })}
                                {field('storage_limit_mb', 'Úložiště v MB', { inputMode: 'numeric' })}

                                <label className="block">
                                    <span className="text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">V nabídce</span>
                                    <select
                                        value={draft.is_public ?? 'ano'}
                                        onChange={event => setDraft(current => ({ ...current, is_public: event.target.value }))}
                                        className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-1.5 text-sm text-[var(--color-text-primary)]"
                                    >
                                        <option value="ano">Ano</option>
                                        <option value="ne">Ne, skrytý</option>
                                    </select>
                                </label>

                                <div className="flex items-end gap-2 sm:col-span-2">
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={() => void save(plan.code)}
                                        className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm text-[var(--color-accent-contrast)] disabled:opacity-50"
                                    >
                                        {busy && <Loader2 size={14} className="animate-spin" />} Uložit
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setEditing(null)}
                                        className="min-h-10 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]"
                                    >
                                        Zrušit
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}
