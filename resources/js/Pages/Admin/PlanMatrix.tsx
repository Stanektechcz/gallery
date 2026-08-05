import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Check, LoaderCircle, SlidersHorizontal } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

interface FeatureRow { code: string; name: string; category: string; icon: string; is_core: boolean; tagline?: string | null }
interface PlanRow { code: string; name: string; group_type: string; price_monthly: number; price_yearly: number; currency: string; member_limit: number | null; feature_codes: string[] }
interface ModuleRow { code: string; name: string; icon: string; price_monthly: number; currency: string; feature_codes: string[] }

const money = (minor: number, currency: string) =>
    minor === 0 ? 'zdarma' : `${new Intl.NumberFormat('cs-CZ').format(Math.round(minor / 100))} ${currency}`;

/**
 * What each plan contains. Changing a cell takes effect for every customer on that plan
 * immediately — the entitlement engine reads this table, nothing is compiled in.
 */
export default function PlanMatrix() {
    const [features, setFeatures] = useState<FeatureRow[]>([]);
    const [plans, setPlans] = useState<PlanRow[]>([]);
    const [modules, setModules] = useState<ModuleRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState('');

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/admin/billing/matrix');
            setFeatures(response.data.features ?? []);
            setPlans(response.data.plans ?? []);
            setModules(response.data.modules ?? []);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Matici se nepodařilo načíst.');
        } finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const toggle = async (target: 'plan' | 'module', code: string, featureCode: string, currently: string[]) => {
        const next = currently.includes(featureCode)
            ? currently.filter(item => item !== featureCode)
            : [...currently, featureCode];

        setBusy(`${target}:${code}:${featureCode}`); setError(''); setSaved('');
        try {
            const response = await axios.put('/api/v1/admin/billing/matrix', { target, code, feature_codes: next });
            setFeatures(response.data.features ?? []);
            setPlans(response.data.plans ?? []);
            setModules(response.data.modules ?? []);
            setSaved('Uloženo. Změna platí pro všechny zákazníky na tomto tarifu okamžitě.');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Změnu se nepodařilo uložit.');
        } finally { setBusy(null); }
    };

    const grouped = useMemo(() => {
        const groups: Record<string, FeatureRow[]> = {};
        for (const feature of features) (groups[feature.category] ??= []).push(feature);
        return Object.entries(groups);
    }, [features]);

    return (
        <AppLayout>
            <Head title="Nabídka tarifů" />
            <main className="mx-auto max-w-6xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Správa systému</p>
                <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-white sm:text-3xl">
                    <SlidersHorizontal size={22} className="text-[var(--color-accent)]" /> Co obsahují tarify
                </h1>
                <p className="mt-2 max-w-3xl text-sm text-[var(--color-text-secondary)]">
                    Zaškrtnutím určíte, které funkce tarif odemyká. Zákazník si pak z odemčených funkcí
                    vybírá, co chce mít zapnuté — vypnout smí, odemknout ne.
                </p>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}
                {saved && <p className="mt-4 rounded-xl border border-emerald-400/25 bg-emerald-500/10 p-3 text-xs text-emerald-100">{saved}</p>}
                {loading && <div className="mt-8 flex justify-center"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                {!loading && (
                    <>
                        <div className="mt-6 overflow-x-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]">
                            <table className="w-full min-w-[46rem] text-sm">
                                <thead>
                                    <tr className="border-b border-[var(--color-border)]">
                                        <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Funkce</th>
                                        {plans.map(plan => (
                                            <th key={plan.code} className="px-3 py-3 text-center">
                                                <span className="block font-semibold text-white">{plan.name}</span>
                                                <span className="block text-[10px] font-normal text-[var(--color-text-secondary)]">
                                                    {money(plan.price_monthly, plan.currency)} · {plan.member_limit ?? '∞'} členů
                                                </span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {grouped.map(([category, rows]) => (
                                        <>
                                            <tr key={`h-${category}`} className="bg-black/15">
                                                <td colSpan={plans.length + 1} className="px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-[var(--color-accent)]">
                                                    {category}
                                                </td>
                                            </tr>
                                            {rows.map(feature => (
                                                <tr key={feature.code} className="border-b border-[var(--color-border)] last:border-0">
                                                    <td className="px-4 py-2.5">
                                                        <span className="flex items-center gap-2">
                                                            <span>{feature.icon}</span>
                                                            <span className="text-white">{feature.name}</span>
                                                            {feature.is_core && <span className="rounded-full bg-white/5 px-2 py-0.5 text-[10px] text-[var(--color-text-secondary)]">vždy</span>}
                                                        </span>
                                                    </td>
                                                    {plans.map(plan => {
                                                        const on = feature.is_core || plan.feature_codes.includes(feature.code);
                                                        const key = `plan:${plan.code}:${feature.code}`;
                                                        return (
                                                            <td key={plan.code} className="px-3 py-2.5 text-center">
                                                                <button
                                                                    type="button"
                                                                    role="switch"
                                                                    aria-checked={on}
                                                                    aria-label={`${feature.name} v tarifu ${plan.name}`}
                                                                    disabled={feature.is_core || busy !== null}
                                                                    onClick={() => toggle('plan', plan.code, feature.code, plan.feature_codes)}
                                                                    className={`grid h-7 w-7 place-items-center rounded-lg border transition-colors disabled:cursor-not-allowed disabled:opacity-45 ${on ? 'border-emerald-400/40 bg-emerald-500/20 text-emerald-200' : 'border-[var(--color-border)] text-transparent hover:border-[var(--color-accent)]/50'}`}
                                                                >
                                                                    {busy === key ? <LoaderCircle size={13} className="animate-spin text-white" /> : <Check size={14} />}
                                                                </button>
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <section className="mt-8">
                            <h2 className="font-semibold text-white">Doplňkové moduly</h2>
                            <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Modul odemyká funkce nad rámec tarifu, za samostatný poplatek.</p>
                            <div className="mt-3 space-y-3">
                                {modules.map(module => (
                                    <article key={module.code} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-lg">{module.icon}</span>
                                            <h3 className="font-semibold text-white">{module.name}</h3>
                                            <span className="text-xs text-[var(--color-text-secondary)]">{money(module.price_monthly, module.currency)} / měsíc</span>
                                        </div>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {features.filter(f => !f.is_core).map(feature => {
                                                const on = module.feature_codes.includes(feature.code);
                                                return (
                                                    <button
                                                        key={feature.code}
                                                        type="button"
                                                        aria-pressed={on}
                                                        disabled={busy !== null}
                                                        onClick={() => toggle('module', module.code, feature.code, module.feature_codes)}
                                                        className={`min-h-8 rounded-lg border px-2.5 text-[11px] disabled:opacity-40 ${on ? 'border-violet-300/50 bg-violet-500/20 text-violet-100' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}
                                                    >
                                                        {on ? '✓ ' : ''}{feature.icon} {feature.name}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </article>
                                ))}
                            </div>
                        </section>
                    </>
                )}
            </main>
        </AppLayout>
    );
}
