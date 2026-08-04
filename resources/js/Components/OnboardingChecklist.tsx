import { Link } from '@inertiajs/react';
import axios from 'axios';
import { Check, Circle, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Step {
    key: string;
    title: string;
    description: string;
    done: boolean;
    href: string | null;
}

/**
 * Shown to a new customer until every step is genuinely done. Steps are derived from
 * real state on the server, so doing the work elsewhere ticks them off too.
 */
export default function OnboardingChecklist() {
    const [visible, setVisible] = useState(false);
    const [steps, setSteps] = useState<Step[]>([]);
    const [remaining, setRemaining] = useState(0);

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/onboarding');
            setVisible(Boolean(response.data.visible));
            setSteps(response.data.steps ?? []);
            setRemaining(response.data.remaining ?? 0);
        } catch { /* The checklist is a nicety; never block the dashboard for it. */ }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const dismiss = async () => {
        setVisible(false);
        try { await axios.post('/api/v1/onboarding/dismiss'); } catch { /* Nothing to recover. */ }
    };

    if (!visible) return null;

    return (
        <section className="mb-5 rounded-2xl border border-[var(--color-accent)]/35 bg-[var(--color-accent)]/5 p-4 sm:p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--color-accent)]/15 text-[var(--color-accent)]">
                        <Sparkles size={19} />
                    </span>
                    <div>
                        <h2 className="font-semibold text-white">Začínáme</h2>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Zbývají {remaining} {remaining === 1 ? 'krok' : remaining < 5 ? 'kroky' : 'kroků'} k rozjeté galerii.
                        </p>
                    </div>
                </div>
                <button type="button" onClick={dismiss} aria-label="Skrýt začátečnický průvodce" className="shrink-0 rounded-lg p-2 text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white">
                    <X size={16} />
                </button>
            </div>

            <ol className="mt-4 space-y-2">
                {steps.map(step => {
                    const body = (
                        <span className="flex items-start gap-3">
                            <span className={`mt-0.5 shrink-0 ${step.done ? 'text-emerald-300' : 'text-[var(--color-text-secondary)]'}`}>
                                {step.done ? <Check size={16} /> : <Circle size={16} />}
                            </span>
                            <span className="min-w-0">
                                <span className={`block text-sm font-medium ${step.done ? 'text-[var(--color-text-secondary)] line-through' : 'text-white'}`}>{step.title}</span>
                                {!step.done && <span className="block text-xs text-[var(--color-text-secondary)]">{step.description}</span>}
                            </span>
                        </span>
                    );

                    return (
                        <li key={step.key}>
                            {step.href && !step.done
                                ? <Link href={step.href} className="block rounded-xl border border-[var(--color-border)] bg-black/10 p-3 transition-colors hover:border-[var(--color-accent)]/40">{body}</Link>
                                : <div className="rounded-xl border border-transparent p-3">{body}</div>}
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
