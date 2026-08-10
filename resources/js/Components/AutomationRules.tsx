import axios from 'axios';
import { Loader2, Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Condition { field: string; operator: string; value: string }
interface Rule {
    uuid: string; name: string; trigger: string; action: string;
    conditions: Condition[]; action_config: Record<string, string>;
    is_enabled: boolean; run_count: number; last_run_at: string | null;
}
interface Catalogue {
    triggers: Record<string, { label: string; fields: Record<string, string> }>;
    actions: Record<string, { label: string; config: Record<string, string> }>;
    operators: string[];
}

const OPERATOR_LABEL: Record<string, string> = {
    contains: 'obsahuje', equals: 'je přesně', not_contains: 'neobsahuje',
    greater_than: 'je větší než', less_than: 'je menší než',
};

const stamp = (value: string | null) =>
    value ? new Date(value).toLocaleString('cs-CZ', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'zatím neproběhlo';

/**
 * Rules people write for themselves: when this happens, and these things hold, do that.
 *
 * The form is built from the catalogue the server sends rather than from a copy kept here,
 * so it can never offer a trigger the engine does not know — the mismatch would only show
 * up as a rule that silently never runs.
 *
 * Deleting a rule leaves what it made. Tasks an automation created belong to the people
 * who have been living with them, and removing the rule is not a request to erase a
 * fortnight of somebody's list.
 */
export default function AutomationRules() {
    const [rules, setRules] = useState<Rule[]>([]);
    const [catalogue, setCatalogue] = useState<Catalogue | null>(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [draft, setDraft] = useState<Partial<Rule> | null>(null);

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/automation-rules');
            setRules(response.data.rules ?? []);
            setCatalogue({
                triggers: response.data.triggers ?? {},
                actions: response.data.actions ?? {},
                operators: response.data.operators ?? [],
            });
        } catch { setError('Pravidla se nepodařilo načíst.'); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const blank = (): Partial<Rule> => {
        const trigger = Object.keys(catalogue?.triggers ?? {})[0] ?? '';
        const action = Object.keys(catalogue?.actions ?? {})[0] ?? '';

        return { name: '', trigger, action, conditions: [], action_config: {}, is_enabled: true };
    };

    const save = async () => {
        if (!draft?.name?.trim()) { setError('Pravidlo potřebuje název.'); return; }
        setBusy(true); setError('');
        try {
            const body = {
                name: draft.name, trigger: draft.trigger, action: draft.action,
                conditions: draft.conditions ?? [], action_config: draft.action_config ?? {},
                is_enabled: draft.is_enabled ?? true,
            };
            if (draft.uuid) await axios.put(`/api/v1/automation-rules/${draft.uuid}`, body);
            else await axios.post('/api/v1/automation-rules', body);
            setDraft(null);
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Pravidlo se nepodařilo uložit.');
        } finally { setBusy(false); }
    };

    const toggle = async (rule: Rule) => {
        setRules(current => current.map(item => item.uuid === rule.uuid ? { ...item, is_enabled: !item.is_enabled } : item));
        try {
            await axios.put(`/api/v1/automation-rules/${rule.uuid}`, {
                name: rule.name, trigger: rule.trigger, action: rule.action,
                conditions: rule.conditions, action_config: rule.action_config,
                is_enabled: !rule.is_enabled,
            });
        } catch { setError('Změnu se nepodařilo uložit.'); await load(); }
    };

    const remove = async (rule: Rule) => {
        if (!window.confirm(`Smazat pravidlo „${rule.name}“? Co už vytvořilo, zůstane.`)) return;
        try { await axios.delete(`/api/v1/automation-rules/${rule.uuid}`); await load(); }
        catch { setError('Pravidlo se nepodařilo smazat.'); }
    };

    const triggerFields = draft?.trigger ? catalogue?.triggers[draft.trigger]?.fields ?? {} : {};
    const actionConfig = draft?.action ? catalogue?.actions[draft.action]?.config ?? {} : {};

    return (
        <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h2 className="font-semibold text-[var(--color-text-primary)]">Vlastní pravidla</h2>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                        Když se něco stane a platí zadané podmínky, systém provede akci. Pravidlo nikdy nespustí další pravidlo.
                    </p>
                </div>
                {!draft && catalogue && (
                    <button
                        type="button"
                        onClick={() => { setDraft(blank()); setError(''); }}
                        className="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-3 text-sm text-[var(--color-accent-contrast)]"
                    >
                        <Plus size={15} /> Nové
                    </button>
                )}
            </div>

            {error && <p role="alert" className="mt-3 rounded-lg bg-red-500/10 p-2 text-xs text-red-200">{error}</p>}
            {loading && <div className="flex justify-center py-6"><Loader2 size={18} className="animate-spin text-[var(--color-accent)]" /></div>}

            {draft && catalogue && (
                <div className="mt-4 space-y-3 rounded-xl border border-[var(--color-accent)]/40 p-3">
                    <input
                        value={draft.name ?? ''}
                        onChange={event => setDraft({ ...draft, name: event.target.value })}
                        placeholder="Název pravidla"
                        className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-[var(--color-text-primary)]"
                    />

                    <div className="grid gap-2 sm:grid-cols-2">
                        <label className="block">
                            <span className="text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">Když</span>
                            <select
                                value={draft.trigger}
                                onChange={event => setDraft({ ...draft, trigger: event.target.value, conditions: [] })}
                                className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-2 text-sm text-[var(--color-text-primary)]"
                            >
                                {Object.entries(catalogue.triggers).map(([key, value]) => (
                                    <option key={key} value={key}>{value.label}</option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">Udělej</span>
                            <select
                                value={draft.action}
                                onChange={event => setDraft({ ...draft, action: event.target.value, action_config: {} })}
                                className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-2 text-sm text-[var(--color-text-primary)]"
                            >
                                {Object.entries(catalogue.actions).map(([key, value]) => (
                                    <option key={key} value={key}>{value.label}</option>
                                ))}
                            </select>
                        </label>
                    </div>

                    <div>
                        <p className="text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">Podmínky (bez nich platí vždy)</p>
                        {(draft.conditions ?? []).map((condition, index) => (
                            <div key={index} className="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <select
                                    value={condition.field}
                                    onChange={event => {
                                        const next = [...(draft.conditions ?? [])];
                                        next[index] = { ...condition, field: event.target.value };
                                        setDraft({ ...draft, conditions: next });
                                    }}
                                    className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-1.5 text-xs text-[var(--color-text-primary)]"
                                >
                                    {Object.entries(triggerFields).map(([key, label]) => (
                                        <option key={key} value={key}>{label}</option>
                                    ))}
                                </select>
                                <select
                                    value={condition.operator}
                                    onChange={event => {
                                        const next = [...(draft.conditions ?? [])];
                                        next[index] = { ...condition, operator: event.target.value };
                                        setDraft({ ...draft, conditions: next });
                                    }}
                                    className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-1.5 text-xs text-[var(--color-text-primary)]"
                                >
                                    {catalogue.operators.map(operator => (
                                        <option key={operator} value={operator}>{OPERATOR_LABEL[operator] ?? operator}</option>
                                    ))}
                                </select>
                                <input
                                    value={condition.value}
                                    onChange={event => {
                                        const next = [...(draft.conditions ?? [])];
                                        next[index] = { ...condition, value: event.target.value };
                                        setDraft({ ...draft, conditions: next });
                                    }}
                                    className="min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-1.5 text-xs text-[var(--color-text-primary)]"
                                />
                                <button
                                    type="button"
                                    aria-label="Odebrat podmínku"
                                    onClick={() => setDraft({ ...draft, conditions: (draft.conditions ?? []).filter((_, i) => i !== index) })}
                                    className="rounded-lg p-1.5 text-red-300 hover:bg-red-500/10"
                                ><Trash2 size={13} /></button>
                            </div>
                        ))}
                        <button
                            type="button"
                            onClick={() => setDraft({
                                ...draft,
                                conditions: [...(draft.conditions ?? []), {
                                    field: Object.keys(triggerFields)[0] ?? '', operator: 'contains', value: '',
                                }],
                            })}
                            className="mt-2 rounded-lg border border-[var(--color-border)] px-2 py-1.5 text-xs text-[var(--color-text-secondary)]"
                        >
                            + podmínka
                        </button>
                    </div>

                    <div className="grid gap-2 sm:grid-cols-2">
                        {Object.entries(actionConfig).map(([key, label]) => (
                            <label key={key} className="block">
                                <span className="text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">{label}</span>
                                <input
                                    value={draft.action_config?.[key] ?? ''}
                                    onChange={event => setDraft({
                                        ...draft,
                                        action_config: { ...(draft.action_config ?? {}), [key]: event.target.value },
                                    })}
                                    className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-1.5 text-sm text-[var(--color-text-primary)]"
                                />
                            </label>
                        ))}
                    </div>

                    <p className="text-[11px] text-[var(--color-text-secondary)]">
                        V textu můžete použít {Object.keys(triggerFields).map(field => `{${field}}`).join(', ')} — dosadí se hodnota z události.
                    </p>

                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => void save()}
                            className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm text-[var(--color-accent-contrast)] disabled:opacity-50"
                        >
                            {busy && <Loader2 size={14} className="animate-spin" />} Uložit pravidlo
                        </button>
                        <button
                            type="button"
                            onClick={() => { setDraft(null); setError(''); }}
                            className="min-h-10 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]"
                        >
                            Zrušit
                        </button>
                    </div>
                </div>
            )}

            <div className="mt-4 space-y-2">
                {rules.map(rule => (
                    <div key={rule.uuid} className="flex items-center gap-3 rounded-xl border border-[var(--color-border)] p-3">
                        <button
                            type="button"
                            role="switch"
                            aria-checked={rule.is_enabled}
                            aria-label={`Zapnout pravidlo ${rule.name}`}
                            onClick={() => void toggle(rule)}
                            className={`h-5 w-9 shrink-0 rounded-full transition-colors ${rule.is_enabled ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-surface-muted)]'}`}
                        >
                            <span className={`block h-4 w-4 rounded-full bg-white transition-transform ${rule.is_enabled ? 'translate-x-4' : 'translate-x-0.5'}`} />
                        </button>

                        <button
                            type="button"
                            onClick={() => { setDraft({ ...rule }); setError(''); }}
                            className="min-w-0 flex-1 text-left"
                        >
                            <p className="truncate text-sm text-[var(--color-text-primary)]">{rule.name}</p>
                            <p className="truncate text-xs text-[var(--color-text-secondary)]">
                                {catalogue?.triggers[rule.trigger]?.label ?? rule.trigger}
                                {' → '}
                                {catalogue?.actions[rule.action]?.label ?? rule.action}
                                {' · '}
                                {rule.run_count}× · {stamp(rule.last_run_at)}
                            </p>
                        </button>

                        <button
                            type="button"
                            aria-label={`Smazat pravidlo ${rule.name}`}
                            onClick={() => void remove(rule)}
                            className="shrink-0 rounded-lg p-2 text-red-300 hover:bg-red-500/10"
                        ><Trash2 size={15} /></button>
                    </div>
                ))}

                {!loading && rules.length === 0 && !draft && (
                    <p className="rounded-xl border border-dashed border-[var(--color-border)] p-6 text-center text-sm text-[var(--color-text-secondary)]">
                        Zatím žádné vlastní pravidlo.
                    </p>
                )}
            </div>
        </section>
    );
}
