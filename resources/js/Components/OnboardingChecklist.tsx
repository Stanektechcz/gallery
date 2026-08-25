import { Link } from '@inertiajs/react';
import axios from 'axios';
import { Check, X } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Step {
    key: string;
    title: string;
    description: string;
    href: string | null;
    done: boolean;
}

/**
 * What a new gallery still needs.
 *
 * The endpoint behind this has existed for a while and nothing ever drew it, so the
 * checklist was computed on every request and shown to nobody. This is the missing half.
 *
 * Renders nothing at all once the steps are done or the person has waved it away — not a
 * collapsed strip, nothing. A finished checklist that lingers says the app is still
 * waiting for something.
 *
 * Completed steps stay visible with a tick rather than disappearing. Watching the list
 * fill up is the point; one that only shows what is missing reads as a list of failures.
 */
export default function OnboardingChecklist() {
    const [steps, setSteps] = useState<Step[]>([]);
    const [remaining, setRemaining] = useState(0);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        void axios.get('/api/v1/onboarding')
            .then(response => {
                setSteps(response.data.steps ?? []);
                setRemaining(response.data.remaining ?? 0);
                setVisible(Boolean(response.data.visible));
            })
            .catch(() => setVisible(false));
    }, []);

    if (!visible || steps.length === 0) return null;

    const hide = () => {
        setVisible(false);
        void axios.post('/api/v1/onboarding/dismiss').catch(() => { /* hiding is not worth an error */ });
    };

    return (
        <section className="mb-5 rounded-2xl border border-[var(--color-accent)]/30 bg-[var(--color-accent)]/5 p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Ať galerie začne žít</h2>
                    <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">
                        {/* Czech counts in three forms; one of them for every number reads as translated. */}
                        Zbývá {remaining} {remaining === 1 ? 'krok' : remaining <= 4 ? 'kroky' : 'kroků'}.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={hide}
                    aria-label="Skrýt nápovědu"
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] sm:h-7 sm:w-7"
                >
                    <X size={15} />
                </button>
            </div>

            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                {steps.map(step => {
                    const body = (
                        <>
                            <span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${step.done
                                ? 'border-emerald-400 bg-emerald-400/20 text-emerald-300'
                                : 'border-[var(--color-border)]'}`}>
                                {step.done && <Check size={10} />}
                            </span>
                            <span className="min-w-0">
                                <span className="block text-xs font-medium text-[var(--color-text-primary)]">{step.title}</span>
                                <span className="block text-[11px] leading-4 text-[var(--color-text-secondary)]">{step.description}</span>
                            </span>
                        </>
                    );

                    const shell = `flex items-start gap-2.5 rounded-xl border p-2.5 transition-colors ${step.done
                        ? 'border-[var(--color-border)] opacity-60'
                        : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`;

                    // A step with nowhere to go is not a link. Naming the gallery happens at
                    // sign-up, so there is no page to send anybody to for it.
                    return step.href
                        ? <Link key={step.key} href={step.href} className={`${shell} hover:border-[var(--color-accent)]`}>{body}</Link>
                        : <div key={step.key} className={shell}>{body}</div>;
                })}
            </div>
        </section>
    );
}
