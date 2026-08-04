import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { CircleAlert, LoaderCircle, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Plan {
    code: string; name: string; tagline?: string | null;
    price_monthly: number; currency: string;
    member_limit?: number | null; storage_limit_mb?: number | null;
    features: string[]; is_default: boolean;
}

interface ModuleRow {
    code: string; name: string; tagline?: string | null; description?: string | null;
    price_monthly: number; currency: string; icon: string;
    is_active: boolean; is_included: boolean;
}

interface Usage {
    members: { used: number; limit: number | null; can_add: boolean };
    storage: { used_bytes: number; limit_mb: number | null; percent: number | null };
}

const price = (minor: number, currency: string) =>
    minor === 0 ? 'zdarma' : `${new Intl.NumberFormat('cs-CZ').format(Math.round(minor / 100))} ${currency} / měsíc`;

const gigabytes = (bytes: number) =>
    bytes >= 1024 ** 3 ? `${(bytes / 1024 ** 3).toFixed(1)} GB` : `${Math.max(1, Math.round(bytes / 1024 / 1024))} MB`;

export default function Subscription() {
    const role = usePage().props.auth?.user?.role;
    const isAdmin = role === 'owner' || role === 'admin';

    const [plan, setPlan] = useState<Plan | null>(null);
    const [usage, setUsage] = useState<Usage | null>(null);
    const [modules, setModules] = useState<ModuleRow[]>([]);
    const [available, setAvailable] = useState(true);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/billing/overview');
            setAvailable(response.data.available !== false);
            setPlan(response.data.plan ?? null);
            setUsage(response.data.usage ?? null);
            setModules(response.data.modules ?? []);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Přehled se nepodařilo načíst.');
        } finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const toggle = async (module: ModuleRow) => {
        setBusy(module.code); setError('');
        try {
            const response = await axios.put(`/api/v1/billing/modules/${module.code}`, { enabled: !module.is_active });
            setPlan(response.data.plan ?? null);
            setUsage(response.data.usage ?? null);
            setModules(response.data.modules ?? []);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Modul se nepodařilo přepnout.');
        } finally { setBusy(null); }
    };

    return (
        <AppLayout>
            <Head title="Předplatné" />
            <main className="mx-auto max-w-3xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Nastavení</p>
                <h1 className="mt-1 text-2xl font-bold text-white sm:text-3xl">Předplatné a moduly</h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Přehled tarifu vašeho prostoru a doplňkových modulů.{' '}
                    <Link href="/cenik" className="text-[var(--color-accent)] hover:underline">Zobrazit ceník</Link>
                </p>

                <p className="mt-4 flex items-start gap-2 rounded-xl border border-amber-400/25 bg-amber-500/10 p-3 text-xs text-amber-100">
                    <CircleAlert size={15} className="mt-0.5 shrink-0" />
                    Platby zatím nejsou spuštěné. Moduly aktivuje správce ručně a nic se neúčtuje.
                </p>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}
                {loading && <div className="mt-6 flex justify-center"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                {!loading && !available && (
                    <p className="mt-6 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 text-sm text-[var(--color-text-secondary)]">
                        Pro tarify a moduly dokončete databázové migrace aplikace.
                    </p>
                )}

                {!loading && available && (
                    <>
                        {plan && (
                            <section className="mt-6 rounded-2xl border border-[var(--color-accent)]/35 bg-[var(--color-accent)]/5 p-5">
                                <p className="text-xs uppercase tracking-wide text-[var(--color-accent)]">Aktuální tarif</p>
                                <h2 className="mt-1 text-lg font-semibold text-white">{plan.name}</h2>
                                {plan.tagline && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{plan.tagline}</p>}
                                <p className="mt-3 font-semibold text-white">{price(plan.price_monthly, plan.currency)}</p>

                                {usage && (
                                    <div className="mt-4 space-y-3 border-t border-white/10 pt-3">
                                        <div>
                                            <div className="flex justify-between text-xs">
                                                <span className="text-[var(--color-text-secondary)]">Členové</span>
                                                <span className="text-white">{usage.members.used} z {usage.members.limit ?? '∞'}</span>
                                            </div>
                                            {!usage.members.can_add && (
                                                <p className="mt-1 text-[10px] text-amber-200">Limit tarifu je vyčerpaný, dalšího člena nelze pozvat.</p>
                                            )}
                                        </div>
                                        <div>
                                            <div className="flex justify-between text-xs">
                                                <span className="text-[var(--color-text-secondary)]">Obsazené místo</span>
                                                <span className="text-white">
                                                    {gigabytes(usage.storage.used_bytes)}{usage.storage.limit_mb != null ? ` z ${Math.round(usage.storage.limit_mb / 1000)} GB` : ''}
                                                </span>
                                            </div>
                                            {usage.storage.percent != null && (
                                                <div className="mt-1 h-2 overflow-hidden rounded-full bg-white/10">
                                                    <div
                                                        className={`h-full rounded-full ${usage.storage.percent > 90 ? 'bg-red-400' : usage.storage.percent > 75 ? 'bg-amber-400' : 'bg-emerald-400'}`}
                                                        style={{ width: `${usage.storage.percent}%` }}
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </section>
                        )}

                        <section className="mt-6">
                            <h2 className="flex items-center gap-2 font-semibold text-white"><Sparkles size={17} className="text-violet-300" /> Doplňkové moduly</h2>
                            <div className="mt-3 space-y-3">
                                {modules.map(module => (
                                    <article key={module.code} className={`rounded-2xl border p-4 ${module.is_active ? 'border-emerald-400/30 bg-emerald-500/5' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}>
                                        <div className="flex items-start gap-3">
                                            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-violet-500/10 text-xl">{module.icon}</span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="font-semibold text-white">{module.name}</h3>
                                                    {module.is_included
                                                        ? <span className="rounded-full bg-white/5 px-2 py-0.5 text-[10px] text-[var(--color-text-secondary)]">v tarifu</span>
                                                        : module.is_active
                                                            ? <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] text-emerald-200">aktivní</span>
                                                            : <span className="rounded-full bg-white/5 px-2 py-0.5 text-[10px] text-[var(--color-text-secondary)]">neaktivní</span>}
                                                </div>
                                                {module.description && <p className="mt-2 text-sm leading-relaxed text-[var(--color-text-secondary)]">{module.description}</p>}
                                                <p className="mt-2 text-sm font-medium text-white">{price(module.price_monthly, module.currency)}</p>

                                                {!module.is_included && (
                                                    isAdmin ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => toggle(module)}
                                                            disabled={busy !== null}
                                                            className={`mt-3 inline-flex min-h-10 items-center gap-2 rounded-xl px-4 text-sm font-medium disabled:opacity-40 ${module.is_active ? 'border border-[var(--color-border)] text-white' : 'bg-[var(--color-accent)] text-white'}`}
                                                        >
                                                            {busy === module.code && <LoaderCircle size={14} className="animate-spin" />}
                                                            {module.is_active ? 'Deaktivovat' : 'Aktivovat'}
                                                        </button>
                                                    ) : (
                                                        <p className="mt-3 text-xs text-[var(--color-text-secondary)]">Aktivaci provede správce prostoru.</p>
                                                    )
                                                )}
                                            </div>
                                        </div>
                                    </article>
                                ))}
                                {!modules.length && (
                                    <p className="rounded-2xl border border-dashed border-[var(--color-border)] p-6 text-center text-sm text-[var(--color-text-secondary)]">
                                        Zatím nejsou k dispozici žádné doplňkové moduly.
                                    </p>
                                )}
                            </div>
                        </section>
                    </>
                )}
            </main>
        </AppLayout>
    );
}
