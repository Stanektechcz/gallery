import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ArrowRight, Check, Images, LoaderCircle, Lock, ShieldCheck, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Plan {
    code: string; group_type: string; name: string; tagline?: string | null; description?: string | null;
    price_monthly: number; price_yearly: number; currency: string;
    member_limit?: number | null; storage_limit_mb?: number | null;
    features: string[]; feature_codes: string[]; is_default: boolean; highlight: boolean;
}

interface Module {
    code: string; name: string; tagline?: string | null; description?: string | null;
    price_monthly: number; currency: string; icon: string; features: string[];
}

interface FeatureRow {
    code: string; name: string; tagline?: string | null; category: string; icon: string; is_core: boolean;
}

type Period = 'monthly' | 'yearly';

const money = (minor: number, currency: string) =>
    minor === 0 ? 'zdarma' : `${new Intl.NumberFormat('cs-CZ').format(Math.round(minor / 100))} ${currency}`;

const storage = (mb?: number | null) =>
    mb == null ? 'neomezeně' : mb >= 1000 ? `${Math.round(mb / 1000)} GB` : `${mb} MB`;

const GROUP_LABEL: Record<string, string> = { couple: 'Pro dva', family: 'Pro rodinu', group: 'Pro skupinu' };

export default function Landing() {
    const [plans, setPlans] = useState<Plan[]>([]);
    const [modules, setModules] = useState<Module[]>([]);
    const [features, setFeatures] = useState<FeatureRow[]>([]);
    const [period, setPeriod] = useState<Period>('monthly');
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        axios.get('/api/v1/public/billing/catalogue')
            .then(r => { setPlans(r.data.plans ?? []); setModules(r.data.modules ?? []); setFeatures(r.data.features ?? []); })
            .finally(() => setLoading(false));
    }, []);

    // Features grouped the way the catalogue orders them, so the page mirrors the product.
    const byCategory = useMemo(() => {
        const groups: Record<string, FeatureRow[]> = {};
        for (const feature of features) (groups[feature.category] ??= []).push(feature);
        return Object.entries(groups);
    }, [features]);

    const priceOf = (plan: Plan) =>
        period === 'yearly'
            ? (plan.price_yearly > 0 ? plan.price_yearly : plan.price_monthly * 10)
            : plan.price_monthly;

    return (
        <>
            <Head title="Vaše společné vzpomínky na jednom místě" />

            <div className="min-h-screen bg-[var(--color-bg-primary)]">
                {/* Header */}
                <header className="mx-auto flex max-w-6xl items-center justify-between px-4 py-5">
                    <span className="flex items-center gap-2.5">
                        <span className="grid h-9 w-9 place-items-center rounded-xl bg-[var(--color-accent)]">
                            <Images size={19} className="text-white" />
                        </span>
                        <span className="font-semibold text-white">Stanektech Gallery</span>
                    </span>
                    <nav className="flex items-center gap-2">
                        <Link href="/login" className="rounded-lg px-3 py-2 text-sm text-[var(--color-text-secondary)] hover:text-white">Přihlásit se</Link>
                        <Link href="/registrace" className="rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white hover:bg-[var(--color-accent-hover)]">Vyzkoušet zdarma</Link>
                    </nav>
                </header>

                {/* Hero */}
                <section className="mx-auto max-w-6xl px-4 pb-8 pt-10 sm:pt-16">
                    <div className="max-w-3xl">
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-[var(--color-accent)]/30 bg-[var(--color-accent)]/10 px-3 py-1 text-xs text-[var(--color-accent)]">
                            <Sparkles size={13} /> Především pro dvojice
                        </span>
                        <h1 className="mt-5 text-4xl font-bold leading-[1.1] text-white sm:text-5xl">
                            Váš společný život<br />na jednom místě
                        </h1>
                        <p className="mt-5 max-w-2xl text-lg leading-relaxed text-[var(--color-text-secondary)]">
                            Galerie, kalendář, cesty, kuchařka a vzpomínky pro dva. Bez rozházených alb
                            po telefonech a bez hledání, kdo co kdy zaplatil. Rodiny a větší skupiny
                            si vyberou vlastní tarif.
                        </p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link href="/registrace" className="inline-flex min-h-12 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-6 font-medium text-white hover:bg-[var(--color-accent-hover)]">
                                Založit galerii zdarma <ArrowRight size={17} />
                            </Link>
                            <a href="#cenik" className="inline-flex min-h-12 items-center rounded-xl border border-[var(--color-border)] px-6 text-white hover:bg-white/5">
                                Prohlédnout tarify
                            </a>
                        </div>
                        <p className="mt-4 flex items-center gap-1.5 text-xs text-[var(--color-text-secondary)]">
                            <ShieldCheck size={14} /> Tarif Duo je trvale zdarma. Platební údaje nepotřebujete.
                        </p>
                    </div>
                </section>

                {/* What you get */}
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <h2 className="text-2xl font-bold text-white sm:text-3xl">Co všechno v tom je</h2>
                    <p className="mt-2 max-w-2xl text-sm text-[var(--color-text-secondary)]">
                        Funkce si zapínáte sami. Co nepoužíváte, zmizí z rozhraní — a kdykoliv se dá vrátit.
                    </p>

                    {loading && <div className="mt-10 flex justify-center"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                    <div className="mt-8 space-y-10">
                        {byCategory.map(([category, rows]) => (
                            <div key={category}>
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-[var(--color-accent)]">{category}</h3>
                                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {rows.map(feature => (
                                        <article key={feature.code} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                                            <span className="text-xl">{feature.icon}</span>
                                            <h4 className="mt-2 font-semibold text-white">{feature.name}</h4>
                                            {feature.tagline && <p className="mt-1 text-sm leading-relaxed text-[var(--color-text-secondary)]">{feature.tagline}</p>}
                                        </article>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Pricing */}
                <section id="cenik" className="mx-auto max-w-6xl scroll-mt-6 px-4 py-14">
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-2xl font-bold text-white sm:text-3xl">Tarify</h2>
                            <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                                Začněte zdarma. Přejít výš i zpět můžete kdykoliv.
                            </p>
                        </div>
                        <div className="inline-flex self-start rounded-xl border border-[var(--color-border)] p-1 sm:self-auto">
                            {(['monthly', 'yearly'] as Period[]).map(value => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setPeriod(value)}
                                    aria-pressed={period === value}
                                    className={`min-h-9 rounded-lg px-4 text-sm ${period === value ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-text-secondary)]'}`}
                                >
                                    {value === 'monthly' ? 'Měsíčně' : 'Ročně · 2 měsíce zdarma'}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="mt-8 grid gap-4 lg:grid-cols-4">
                        {plans.map(plan => (
                            <article
                                key={plan.code}
                                className={`flex flex-col rounded-2xl border p-5 ${plan.highlight ? 'border-[var(--color-accent)]/50 bg-[var(--color-accent)]/5' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}
                            >
                                <div className="flex items-center gap-2">
                                    <h3 className="text-lg font-semibold text-white">{plan.name}</h3>
                                    {plan.highlight && <span className="rounded-full bg-[var(--color-accent)] px-2 py-0.5 text-[10px] font-medium text-white">Nejoblíbenější</span>}
                                </div>
                                <span className="mt-1 text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">{GROUP_LABEL[plan.group_type] ?? plan.group_type}</span>
                                {plan.tagline && <p className="mt-2 text-xs text-[var(--color-text-secondary)]">{plan.tagline}</p>}

                                <p className="mt-4 text-3xl font-bold text-white">
                                    {money(priceOf(plan), plan.currency)}
                                    {priceOf(plan) > 0 && <span className="text-sm font-normal text-[var(--color-text-secondary)]"> / {period === 'yearly' ? 'rok' : 'měsíc'}</span>}
                                </p>

                                <ul className="mt-5 flex-1 space-y-2">
                                    {plan.features.map(item => (
                                        <li key={item} className="flex items-start gap-2 text-sm text-[var(--color-text-secondary)]">
                                            <Check size={15} className="mt-0.5 shrink-0 text-emerald-300" />{item}
                                        </li>
                                    ))}
                                </ul>

                                <dl className="mt-5 border-t border-[var(--color-border)] pt-3 text-xs text-[var(--color-text-secondary)]">
                                    <div className="flex justify-between"><dt>Členů</dt><dd className="text-white">{plan.member_limit ?? 'neomezeně'}</dd></div>
                                    <div className="mt-1 flex justify-between"><dt>Prostor</dt><dd className="text-white">{storage(plan.storage_limit_mb)}</dd></div>
                                    <div className="mt-1 flex justify-between"><dt>Funkcí</dt><dd className="text-white">{plan.feature_codes.length}</dd></div>
                                </dl>

                                <Link
                                    href={`/registrace?tarif=${plan.code}&obdobi=${period}`}
                                    className={`mt-5 inline-flex min-h-11 items-center justify-center rounded-xl px-4 text-sm font-medium ${plan.highlight ? 'bg-[var(--color-accent)] text-white hover:bg-[var(--color-accent-hover)]' : 'border border-[var(--color-border)] text-white hover:bg-white/5'}`}
                                >
                                    {plan.price_monthly === 0 ? 'Začít zdarma' : 'Vybrat tarif'}
                                </Link>
                            </article>
                        ))}
                    </div>

                    {modules.length > 0 && (
                        <div className="mt-12">
                            <h3 className="text-lg font-semibold text-white">Doplňkové moduly</h3>
                            <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Přidávají se k jakémukoliv tarifu a účtují se zvlášť.</p>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                {modules.map(module => (
                                    <article key={module.code} className="flex items-start gap-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5">
                                        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-violet-500/10 text-xl">{module.icon}</span>
                                        <div className="min-w-0 flex-1">
                                            <h4 className="font-semibold text-white">{module.name}</h4>
                                            {module.description && <p className="mt-1.5 text-sm leading-relaxed text-[var(--color-text-secondary)]">{module.description}</p>}
                                            <p className="mt-3 font-semibold text-white">{money(module.price_monthly, module.currency)} <span className="text-xs font-normal text-[var(--color-text-secondary)]">/ měsíc</span></p>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        </div>
                    )}
                </section>

                {/* Trust */}
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <div className="grid gap-4 sm:grid-cols-3">
                        {[
                            [Lock, 'Vaše data zůstávají vaše', 'Prostory jsou od sebe oddělené na úrovni databáze. K vašim fotkám se nikdo jiný nedostane.'],
                            [ShieldCheck, 'Platby přes Comgate', 'Karty i bankovní převody. Platební údaje se k nám nikdy nedostanou.'],
                            [Sparkles, 'Funkce podle vás', 'Zapnete si jen to, co používáte. Zbytek nezavazí v rozhraní.'],
                        ].map(([Icon, title, text]: any) => (
                            <div key={title} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5">
                                <Icon size={20} className="text-[var(--color-accent)]" />
                                <h3 className="mt-3 font-semibold text-white">{title}</h3>
                                <p className="mt-1.5 text-sm leading-relaxed text-[var(--color-text-secondary)]">{text}</p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Final call */}
                <section className="mx-auto max-w-6xl px-4 pb-20">
                    <div className="rounded-3xl border border-[var(--color-accent)]/30 bg-[var(--color-accent)]/5 p-8 text-center sm:p-12">
                        <h2 className="text-2xl font-bold text-white sm:text-3xl">Začněte dnes, zdarma</h2>
                        <p className="mx-auto mt-3 max-w-xl text-sm text-[var(--color-text-secondary)]">
                            Tarif Duo je trvale zdarma pro dva a 25 GB. Vyšší tarif si můžete pořídit
                            kdykoliv později přímo v nastavení.
                        </p>
                        <Link href="/registrace" className="mt-6 inline-flex min-h-12 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-7 font-medium text-white hover:bg-[var(--color-accent-hover)]">
                            Založit galerii <ArrowRight size={17} />
                        </Link>
                    </div>
                </section>

                <footer className="border-t border-[var(--color-border)]">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-xs text-[var(--color-text-secondary)]">
                        <span>© {new Date().getFullYear()} Stanektech Gallery</span>
                        <span className="flex gap-4">
                            <Link href="/cenik" className="hover:text-white">Ceník</Link>
                            <Link href="/login" className="hover:text-white">Přihlášení</Link>
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}
