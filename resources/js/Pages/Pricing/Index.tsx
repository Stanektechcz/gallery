import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Check, Images, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Plan {
    code: string; name: string; tagline?: string | null; description?: string | null;
    price_monthly: number; currency: string;
    member_limit?: number | null; storage_limit_mb?: number | null;
    features: string[]; is_default: boolean;
}

interface Module {
    code: string; name: string; tagline?: string | null; description?: string | null;
    price_monthly: number; currency: string; icon: string;
}

/** Prices are stored in minor units so nothing rounds badly on the way here. */
export const price = (minor: number, currency: string) =>
    minor === 0
        ? 'zdarma'
        : `${new Intl.NumberFormat('cs-CZ').format(Math.round(minor / 100))} ${currency}`;

const storage = (mb?: number | null) => mb == null ? 'neomezeně' : mb >= 1000 ? `${Math.round(mb / 1000)} GB` : `${mb} MB`;

export default function PricingIndex() {
    const [plans, setPlans] = useState<Plan[]>([]);
    const [modules, setModules] = useState<Module[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        axios.get('/api/v1/public/billing/catalogue')
            .then(response => { setPlans(response.data.plans ?? []); setModules(response.data.modules ?? []); })
            .finally(() => setLoading(false));
    }, []);

    return (
        <>
            <Head title="Ceník" />
            <div className="min-h-screen bg-[var(--color-bg-primary)] px-4 py-10">
                <div className="mx-auto max-w-5xl">
                    <header className="text-center">
                        <div className="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-[var(--color-accent)] shadow-lg shadow-[var(--color-accent)]/30">
                            <Images size={28} className="text-[var(--color-accent-contrast)]" />
                        </div>
                        <h1 className="text-3xl font-bold text-[var(--color-text-primary)]">Ceník</h1>
                        <p className="mx-auto mt-2 max-w-2xl text-sm text-[var(--color-text-secondary)]">
                            Vyberte tarif podle toho, kolik vás je a kolik toho chcete uchovat. Doplňkové moduly
                            si můžete přidat kdykoliv a stejně tak je zase vypnout.
                        </p>
                    </header>

                    {loading && <div className="mt-10 flex justify-center"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                    {!loading && (
                        <>
                            <section className="mt-10 grid gap-4 md:grid-cols-3">
                                {plans.map(plan => (
                                    <article key={plan.code} className={`rounded-2xl border p-5 ${plan.is_default ? 'border-[var(--color-accent)]/45 bg-[var(--color-accent)]/5' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}>
                                        <h2 className="text-lg font-semibold text-[var(--color-text-primary)]">{plan.name}</h2>
                                        {plan.tagline && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{plan.tagline}</p>}
                                        <p className="mt-4 text-2xl font-bold text-[var(--color-text-primary)]">
                                            {price(plan.price_monthly, plan.currency)}
                                            {plan.price_monthly > 0 && <span className="text-sm font-normal text-[var(--color-text-secondary)]"> / měsíc</span>}
                                        </p>
                                        <ul className="mt-4 space-y-2">
                                            {plan.features.map(feature => (
                                                <li key={feature} className="flex items-start gap-2 text-sm text-[var(--color-text-secondary)]">
                                                    <Check size={15} className="mt-0.5 shrink-0 text-emerald-300" />{feature}
                                                </li>
                                            ))}
                                        </ul>
                                        <dl className="mt-4 border-t border-[var(--color-border)] pt-3 text-xs text-[var(--color-text-secondary)]">
                                            <div className="flex justify-between"><dt>Členů</dt><dd className="text-[var(--color-text-primary)]">{plan.member_limit ?? 'neomezeně'}</dd></div>
                                            <div className="mt-1 flex justify-between"><dt>Prostor</dt><dd className="text-[var(--color-text-primary)]">{storage(plan.storage_limit_mb)}</dd></div>
                                        </dl>
                                    </article>
                                ))}
                            </section>

                            {modules.length > 0 && (
                                <section className="mt-12">
                                    <h2 className="text-xl font-semibold text-[var(--color-text-primary)]">Doplňkové moduly</h2>
                                    <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                                        Přidávají se k jakémukoliv tarifu a účtují se zvlášť.
                                    </p>
                                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                        {modules.map(module => (
                                            <article key={module.code} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5">
                                                <div className="flex items-start gap-3">
                                                    <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-violet-500/10 text-xl">{module.icon}</span>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold text-[var(--color-text-primary)]">{module.name}</h3>
                                                        {module.tagline && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{module.tagline}</p>}
                                                        {module.description && <p className="mt-2 text-sm leading-relaxed text-[var(--color-text-secondary)]">{module.description}</p>}
                                                        <p className="mt-3 font-semibold text-[var(--color-text-primary)]">
                                                            {price(module.price_monthly, module.currency)}
                                                            {module.price_monthly > 0 && <span className="text-xs font-normal text-[var(--color-text-secondary)]"> / měsíc</span>}
                                                        </p>
                                                    </div>
                                                </div>
                                            </article>
                                        ))}
                                    </div>
                                </section>
                            )}
                        </>
                    )}

                    {!loading && plans.length > 0 && (
                        <div className="mt-10 text-center">
                            <Link href="/registrace" className="inline-flex min-h-11 items-center rounded-xl bg-[var(--color-accent)] px-6 text-sm font-medium text-[var(--color-accent-contrast)] transition-colors hover:bg-[var(--color-accent-hover)]">
                                Založit galerii
                            </Link>
                        </div>
                    )}

                    <p className="mt-8 text-center text-xs text-[var(--color-text-secondary)]">
                        Ceny jsou uvedeny včetně DPH. Platby zatím nejsou spuštěné — tarify a moduly aktivuje správce.
                        {' '}<Link href="/login" className="text-[var(--color-accent)] hover:underline">Přihlásit se</Link>
                    </p>
                </div>
            </div>
        </>
    );
}
