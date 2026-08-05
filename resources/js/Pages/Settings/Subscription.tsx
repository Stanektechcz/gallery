import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { CircleAlert, CreditCard, LoaderCircle, Lock, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

interface Plan {
    code: string; group_type: string; name: string; tagline?: string | null;
    price_monthly: number; price_yearly: number; currency: string;
    member_limit?: number | null; storage_limit_mb?: number | null;
    features: string[]; feature_codes: string[]; is_default: boolean;
}

interface ModuleRow {
    code: string; name: string; tagline?: string | null; description?: string | null;
    price_monthly: number; currency: string; icon: string; is_active: boolean; features: string[];
}

interface FeatureRow {
    code: string; name: string; tagline?: string | null; description?: string | null;
    category: string; icon: string; route?: string | null;
    is_core: boolean; can_switch_off: boolean; entitled: boolean; enabled: boolean;
}

interface Usage {
    members: { used: number; limit: number | null; can_add: boolean };
    storage: { used_bytes: number; limit_mb: number | null; percent: number | null };
}

type Period = 'monthly' | 'yearly';

const money = (minor: number, currency: string) =>
    minor === 0 ? 'zdarma' : `${new Intl.NumberFormat('cs-CZ').format(Math.round(minor / 100))} ${currency}`;

const gigabytes = (bytes: number) =>
    bytes >= 1024 ** 3 ? `${(bytes / 1024 ** 3).toFixed(1)} GB` : `${Math.max(1, Math.round(bytes / 1024 / 1024))} MB`;

export default function Subscription() {
    const role = usePage().props.auth?.user?.role;
    const isAdmin = role === 'owner' || role === 'admin';

    const [plan, setPlan] = useState<Plan | null>(null);
    const [usage, setUsage] = useState<Usage | null>(null);
    const [modules, setModules] = useState<ModuleRow[]>([]);
    const [features, setFeatures] = useState<FeatureRow[]>([]);
    const [catalogue, setCatalogue] = useState<Plan[]>([]);
    const [gateway, setGateway] = useState<{ configured: boolean; test_mode: boolean } | null>(null);
    const [period, setPeriod] = useState<Period>('monthly');
    const [available, setAvailable] = useState(true);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const load = useCallback(async () => {
        try {
            const [overview, cat, gw] = await Promise.all([
                axios.get('/api/v1/billing/overview'),
                axios.get('/api/v1/public/billing/catalogue'),
                axios.get('/api/v1/billing/gateway').catch(() => ({ data: null })),
            ]);
            setAvailable(overview.data.available !== false);
            setPlan(overview.data.plan ?? null);
            setUsage(overview.data.usage ?? null);
            setModules(overview.data.modules ?? []);
            setFeatures(overview.data.features ?? []);
            setCatalogue(cat.data.plans ?? []);
            setGateway(gw.data);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Přehled se nepodařilo načíst.');
        } finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const applyOverview = (data: any) => {
        setPlan(data.plan ?? null);
        setUsage(data.usage ?? null);
        setModules(data.modules ?? []);
        setFeatures(data.features ?? []);
    };

    const toggleFeature = async (feature: FeatureRow) => {
        setBusy(`f:${feature.code}`); setError('');
        try {
            const response = await axios.put(`/api/v1/billing/features/${feature.code}`, { enabled: !feature.enabled });
            applyOverview(response.data);
            // The menu is driven by a shared Inertia prop, which an axios call does not
            // refresh — without this the item stays in the navigation until a full reload
            // and switching a feature off looks like it did nothing.
            router.reload();
            setNotice(feature.enabled ? `${feature.name} je skrytá z menu.` : `${feature.name} je zpět v menu.`);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Funkci se nepodařilo přepnout.');
        } finally { setBusy(null); }
    };

    /** Sends the buyer to Comgate. Nothing is granted until the gateway calls us back. */
    const buy = async (type: 'plan' | 'module', code: string) => {
        setBusy(`buy:${code}`); setError('');
        try {
            const response = await axios.post('/api/v1/billing/checkout', { type, code, period });
            if (response.data.redirect) {
                window.location.href = response.data.redirect;
                return;
            }
            setError('Platební bránu se nepodařilo otevřít.');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Platbu se nepodařilo založit.');
        } finally { setBusy(null); }
    };

    const byCategory = useMemo(() => {
        const groups: Record<string, FeatureRow[]> = {};
        for (const feature of features) (groups[feature.category] ??= []).push(feature);
        return Object.entries(groups);
    }, [features]);

    const upgrades = catalogue.filter(candidate => candidate.code !== plan?.code);

    return (
        <AppLayout>
            <Head title="Předplatné" />
            <main className="mx-auto max-w-4xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Nastavení</p>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">Předplatné a funkce</h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Vyberte si, co chcete používat. <Link href="/cenik" className="text-[var(--color-accent)] hover:underline">Ceník</Link>
                </p>

                {gateway && !gateway.configured && (
                    <p className="mt-4 flex items-start gap-2 rounded-xl border border-amber-400/25 bg-amber-500/10 p-3 text-xs text-amber-100">
                        <CircleAlert size={15} className="mt-0.5 shrink-0" />
                        Platební brána zatím není nastavená, takže placené tarify nelze koupit. Doplňte přihlašovací údaje Comgate.
                    </p>
                )}
                {gateway?.configured && gateway.test_mode && (
                    <p className="mt-4 rounded-xl border border-sky-400/25 bg-sky-500/10 p-3 text-xs text-sky-100">
                        Brána běží v testovacím režimu — žádné skutečné peníze se nepřevádějí.
                    </p>
                )}

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}
                {notice && <p className="mt-4 rounded-xl border border-emerald-400/25 bg-emerald-500/10 p-3 text-xs text-emerald-100">{notice}</p>}
                {loading && <div className="mt-6 flex justify-center"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                {!loading && !available && (
                    <p className="mt-6 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 text-sm text-[var(--color-text-secondary)]">
                        Pro tarify a funkce dokončete databázové migrace aplikace.
                    </p>
                )}

                {!loading && available && (
                    <>
                        {plan && (
                            <section className="mt-6 rounded-2xl border border-[var(--color-accent)]/35 bg-[var(--color-accent)]/5 p-5">
                                <p className="text-xs uppercase tracking-wide text-[var(--color-accent)]">Aktuální tarif</p>
                                <h2 className="mt-1 text-lg font-semibold text-[var(--color-text-primary)]">{plan.name}</h2>
                                {plan.tagline && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{plan.tagline}</p>}
                                <p className="mt-3 font-semibold text-[var(--color-text-primary)]">
                                    {money(plan.price_monthly, plan.currency)}
                                    {plan.price_monthly > 0 && <span className="text-sm font-normal text-[var(--color-text-secondary)]"> / měsíc</span>}
                                </p>

                                {usage && (
                                    <div className="mt-4 space-y-3 border-t border-[var(--color-border)] pt-3">
                                        <div className="flex justify-between text-xs">
                                            <span className="text-[var(--color-text-secondary)]">Členové</span>
                                            <span className="text-[var(--color-text-primary)]">{usage.members.used} z {usage.members.limit ?? '∞'}</span>
                                        </div>
                                        <div>
                                            <div className="flex justify-between text-xs">
                                                <span className="text-[var(--color-text-secondary)]">Obsazené místo</span>
                                                <span className="text-[var(--color-text-primary)]">
                                                    {gigabytes(usage.storage.used_bytes)}{usage.storage.limit_mb != null ? ` z ${Math.round(usage.storage.limit_mb / 1000)} GB` : ''}
                                                </span>
                                            </div>
                                            {usage.storage.percent != null && (
                                                <div className="mt-1 h-2 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                                                    <div className={`h-full rounded-full ${usage.storage.percent > 90 ? 'bg-red-400' : usage.storage.percent > 75 ? 'bg-amber-400' : 'bg-emerald-400'}`} style={{ width: `${usage.storage.percent}%` }} />
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </section>
                        )}

                        {/* Feature chooser */}
                        <section className="mt-8">
                            <div className="flex items-center gap-2">
                                <Sparkles size={17} className="text-violet-300" />
                                <h2 className="font-semibold text-[var(--color-text-primary)]">Vaše funkce</h2>
                            </div>
                            <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                                Co vypnete, zmizí z menu. Kdykoliv se to dá vrátit — o data nepřijdete.
                            </p>

                            <div className="mt-4 space-y-6">
                                {byCategory.map(([category, rows]) => (
                                    <div key={category}>
                                        <h3 className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">{category}</h3>
                                        <div className="mt-2 space-y-2">
                                            {rows.map(feature => (
                                                <div
                                                    key={feature.code}
                                                    className={`flex items-start gap-3 rounded-xl border p-3 ${feature.entitled ? 'border-[var(--color-border)] bg-[var(--color-bg-card)]' : 'border-[var(--color-border)] bg-[var(--color-surface-muted)] opacity-70'}`}
                                                >
                                                    <span className="mt-0.5 text-lg">{feature.icon}</span>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium text-[var(--color-text-primary)]">{feature.name}</span>
                                                            {feature.is_core && <span className="rounded-full bg-[var(--color-surface-muted)] px-2 py-0.5 text-[10px] text-[var(--color-text-secondary)]">základ</span>}
                                                            {!feature.entitled && <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] text-amber-200"><Lock size={9} /> ve vyšším tarifu</span>}
                                                        </div>
                                                        {feature.tagline && <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">{feature.tagline}</p>}
                                                    </div>

                                                    {feature.entitled && feature.can_switch_off ? (
                                                        <button
                                                            type="button"
                                                            role="switch"
                                                            aria-checked={feature.enabled}
                                                            aria-label={`${feature.name}: ${feature.enabled ? 'zapnuto' : 'vypnuto'}`}
                                                            onClick={() => toggleFeature(feature)}
                                                            disabled={busy !== null}
                                                            className={`relative mt-1 h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 ${feature.enabled ? 'bg-emerald-500' : 'bg-white/15'}`}
                                                        >
                                                            <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition-all ${feature.enabled ? 'left-[22px]' : 'left-0.5'}`} />
                                                        </button>
                                                    ) : (
                                                        <span className="mt-1 text-[10px] text-[var(--color-text-secondary)]">{feature.entitled ? 'vždy zapnuto' : ''}</span>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>

                        {/* Upgrade */}
                        {isAdmin && upgrades.length > 0 && (
                            <section className="mt-10">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <h2 className="font-semibold text-[var(--color-text-primary)]">Změnit tarif</h2>
                                    <div className="inline-flex rounded-xl border border-[var(--color-border)] p-1">
                                        {(['monthly', 'yearly'] as Period[]).map(value => (
                                            <button key={value} type="button" onClick={() => setPeriod(value)} aria-pressed={period === value}
                                                className={`min-h-8 rounded-lg px-3 text-xs ${period === value ? 'bg-[var(--color-accent)] text-[var(--color-text-primary)]' : 'text-[var(--color-text-secondary)]'}`}>
                                                {value === 'monthly' ? 'Měsíčně' : 'Ročně'}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                    {upgrades.map(candidate => {
                                        const price = period === 'yearly'
                                            ? (candidate.price_yearly > 0 ? candidate.price_yearly : candidate.price_monthly * 10)
                                            : candidate.price_monthly;
                                        return (
                                            <article key={candidate.code} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                                                <h3 className="font-semibold text-[var(--color-text-primary)]">{candidate.name}</h3>
                                                {candidate.tagline && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{candidate.tagline}</p>}
                                                <p className="mt-3 font-semibold text-[var(--color-text-primary)]">
                                                    {money(price, candidate.currency)}
                                                    {price > 0 && <span className="text-xs font-normal text-[var(--color-text-secondary)]"> / {period === 'yearly' ? 'rok' : 'měsíc'}</span>}
                                                </p>
                                                <p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">
                                                    {candidate.feature_codes.length} funkcí · {candidate.member_limit ?? '∞'} členů
                                                </p>
                                                <button
                                                    type="button"
                                                    onClick={() => buy('plan', candidate.code)}
                                                    disabled={busy !== null || price === 0 || !gateway?.configured}
                                                    className="mt-3 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-text-primary)] disabled:opacity-40"
                                                >
                                                    {busy === `buy:${candidate.code}` ? <LoaderCircle size={14} className="animate-spin" /> : <CreditCard size={14} />}
                                                    {price === 0 ? 'Zdarma' : 'Přejít na tarif'}
                                                </button>
                                            </article>
                                        );
                                    })}
                                </div>
                            </section>
                        )}

                        {/* Add-ons */}
                        {modules.length > 0 && (
                            <section className="mt-10">
                                <h2 className="font-semibold text-[var(--color-text-primary)]">Doplňkové moduly</h2>
                                <div className="mt-3 space-y-3">
                                    {modules.map(module => (
                                        <article key={module.code} className={`rounded-2xl border p-4 ${module.is_active ? 'border-emerald-400/30 bg-emerald-500/5' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}>
                                            <div className="flex items-start gap-3">
                                                <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-violet-500/10 text-xl">{module.icon}</span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h3 className="font-semibold text-[var(--color-text-primary)]">{module.name}</h3>
                                                        {module.is_active && <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] text-emerald-200">aktivní</span>}
                                                    </div>
                                                    {module.description && <p className="mt-1.5 text-sm leading-relaxed text-[var(--color-text-secondary)]">{module.description}</p>}
                                                    <p className="mt-2 text-sm font-medium text-[var(--color-text-primary)]">{money(module.price_monthly, module.currency)} <span className="text-xs font-normal text-[var(--color-text-secondary)]">/ měsíc</span></p>

                                                    {isAdmin && !module.is_active && (
                                                        <button
                                                            type="button"
                                                            onClick={() => buy('module', module.code)}
                                                            disabled={busy !== null || !gateway?.configured}
                                                            className="mt-3 inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-text-primary)] disabled:opacity-40"
                                                        >
                                                            {busy === `buy:${module.code}` ? <LoaderCircle size={14} className="animate-spin" /> : <CreditCard size={14} />} Koupit modul
                                                        </button>
                                                    )}
                                                    {!isAdmin && <p className="mt-2 text-xs text-[var(--color-text-secondary)]">Nákup provede správce prostoru.</p>}
                                                </div>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}
                    </>
                )}
            </main>
        </AppLayout>
    );
}
