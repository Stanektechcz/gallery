import { dny, minuty } from '@/lib/cestina';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { Camera, Flame, Lock } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * The day's shared moment, on the dashboard.
 *
 * Zároveň only works if it is noticed while the window is open, and a sidebar entry is
 * not noticed — the prompt arrives at a minute nobody chose, which is precisely when
 * nobody is looking at the menu.
 *
 * Draws nothing at all unless there is something to say: before the prompt opens, and
 * on a day already answered with nobody left to wait for, this stays out of the way.
 */

interface State {
    moment: { is_open: boolean; is_late: boolean; minutes_left: number | null };
    mine: unknown | null;
    waiting_on_you: boolean;
    others: unknown[];
    others_count: number;
    streak: number;
}

const minutes = minuty;

/** Pátá kopie téhož pravidla; teď ukazuje na to jedno v cestina.ts. */
const days = dny;

export default function TogetherNowCard() {
    const [state, setState] = useState<State | null>(null);

    useEffect(() => {
        let live = true;

        const load = async () => {
            try {
                const response = await axios.get('/api/v1/daily-moment');
                if (live) setState(response.data);
            } catch {
                // Silence is right here. A person with no shared space, or a plan without
                // the feature, should see the dashboard they expected — not an error
                // about something they never asked for.
                if (live) setState(null);
            }
        };

        void load();
        const timer = window.setInterval(load, 120_000);

        return () => { live = false; window.clearInterval(timer); };
    }, []);

    if (! state?.moment.is_open) return null;

    const posted = state.mine !== null;
    const revealed = posted && state.others.length > 0;

    // Answered, and nobody left to wait for: nothing worth a card.
    if (posted && ! revealed && state.others_count === 0) return null;

    return (
        <section className="rounded-3xl border border-amber-400/25 bg-gradient-to-br from-amber-500/10 to-[var(--color-bg-card)] p-4 sm:p-5">
            <div className="flex flex-wrap items-center gap-3">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-amber-400/15 text-amber-200">
                    <Camera size={20}/>
                </span>

                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-[var(--color-text-primary)]">
                        {posted
                            ? 'Váš moment je uložený — podívejte se na ten druhý'
                            : state.moment.is_late
                                ? 'Dnešní Zároveň už uplynulo'
                                : 'Zároveň! Vyfoťte, co právě děláte'}
                    </p>

                    <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-[var(--color-text-secondary)]">
                        {! posted && state.waiting_on_you && (
                            <span className="inline-flex items-center gap-1"><Lock size={11}/> někdo už poslal svůj</span>
                        )}
                        {! posted && ! state.moment.is_late && state.moment.minutes_left !== null && (
                            <span>zbývá {minutes(state.moment.minutes_left)}</span>
                        )}
                        {! posted && state.moment.is_late && <span>pošlete i tak, jen to bude označené</span>}
                        {state.streak > 1 && (
                            <span className="inline-flex items-center gap-1 text-amber-200">
                                <Flame size={11}/> {days(state.streak)} v řadě
                            </span>
                        )}
                    </p>
                </div>

                <Link href="/zaroven"
                    className="inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-xl bg-amber-400/90 px-4 text-sm font-medium text-black hover:bg-amber-300">
                    {posted ? 'Otevřít' : 'Vyfotit'}
                </Link>
            </div>
        </section>
    );
}
