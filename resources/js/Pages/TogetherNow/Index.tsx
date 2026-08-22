import DualCapture from '@/Components/DualCapture';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Camera, Clock, Hourglass, Lock, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/**
 * "Zároveň" — the day's shared moment.
 *
 * The gallery is otherwise a record of occasions. Nobody photographs an ordinary
 * Tuesday afternoon on purpose, so this asks at a time neither person chose, and the
 * other person's picture stays sealed until you have taken yours.
 *
 * The seal is the server's; this screen only draws what it was given. Nothing arrives
 * here that is meant to be hidden.
 */

interface MomentMedia { uuid: string; url?: string | null; media_type: string }

interface Entry {
    uuid: string;
    user: { id: number | null; name: string | null };
    caption: string | null;
    posted_at: string | null;
    late_minutes: number;
    back: MomentMedia | null;
    front: MomentMedia | null;
}

interface State {
    moment: {
        uuid: string; date: string; notify_at: string; closes_at: string;
        prompt: string | null; is_open: boolean; is_late: boolean; minutes_left: number | null;
    };
    mine: Entry | null;
    waiting_on_you: boolean;
    others: Entry[];
    others_count: number;
    members_count: number;
}

interface PastMoment { uuid: string; date: string; notify_at: string; prompt: string | null; entries: Entry[] }

/** 1 minuta / 2-4 minuty / 5+ minut — the same shape Czech needs everywhere else. */
function minutes(count: number): string {
    if (count === 1) return '1 minuta';
    if (count >= 2 && count <= 4) return `${count} minuty`;

    return `${count} minut`;
}

const dayLabel = (iso: string) =>
    new Date(iso).toLocaleDateString('cs-CZ', { weekday: 'long', day: 'numeric', month: 'long' });

const timeLabel = (iso: string) =>
    new Date(iso).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });

/** One person's answer: what they saw, with their face in the corner of it. */
function MomentCard({ entry, compact = false }: { entry: Entry; compact?: boolean }) {
    return (
        <figure className="overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]">
            <div className="relative aspect-[3/4] bg-black/40">
                {entry.back?.url
                    ? <img src={entry.back.url} alt="" loading="lazy" className="h-full w-full object-cover"/>
                    : <div className="flex h-full items-center justify-center text-xs text-[var(--color-text-secondary)]">Bez fotky okolí</div>}

                {entry.front?.url && (
                    <img src={entry.front.url} alt="" loading="lazy"
                        className={`absolute left-2 top-2 rounded-lg border-2 border-white/80 object-cover shadow-lg ${compact ? 'h-16 w-12' : 'h-24 w-18 sm:h-28 sm:w-20'}`}/>
                )}

                {entry.late_minutes > 0 && (
                    <span className="absolute right-2 top-2 rounded-full bg-black/70 px-2 py-0.5 text-[10px] text-amber-200">
                        o {minutes(entry.late_minutes)} později
                    </span>
                )}
            </div>

            <figcaption className="space-y-0.5 px-3 py-2">
                <p className="truncate text-xs font-medium text-[var(--color-text-primary)]">{entry.user.name ?? 'Někdo'}</p>
                {entry.caption && <p className="line-clamp-2 text-xs text-[var(--color-text-secondary)]">{entry.caption}</p>}
                {entry.posted_at && <p className="text-[10px] text-[var(--color-text-secondary)] opacity-70">{timeLabel(entry.posted_at)}</p>}
            </figcaption>
        </figure>
    );
}

export default function TogetherNowIndex() {
    const [state, setState] = useState<State | null>(null);
    const [past, setPast] = useState<PastMoment[]>([]);
    const [capturing, setCapturing] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const [today, history] = await Promise.all([
                axios.get('/api/v1/daily-moment'),
                axios.get('/api/v1/daily-moment/history'),
            ]);

            setState(today.data);
            setPast(history.data.moments ?? []);
            setError('');
        } catch (problem: any) {
            // A person with no shared space yet gets a 404 from the lookup, which is not
            // a fault and must not read like one — it is the first thing a new account
            // sees here, and "could not load" tells them nothing they can act on.
            setError(problem?.response?.status === 404
                ? 'Nejprve vytvořte nebo přijměte pozvánku do společného prostoru. Zároveň je na dva.'
                : 'Dnešní moment se nepodařilo načíst.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { void load(); }, [load]);

    // The prompt opens at a time nobody chose, so a page left open has to notice that
    // moment arriving on its own rather than waiting to be reloaded.
    useEffect(() => {
        const timer = window.setInterval(() => { void load(); }, 60_000);

        return () => window.clearInterval(timer);
    }, [load]);

    const submit = async (media: { back?: string; front?: string }, caption: string) => {
        const response = await axios.post('/api/v1/daily-moment', {
            back_uuid: media.back ?? null,
            front_uuid: media.front ?? null,
            caption: caption || null,
        });

        setState(response.data);
        setCapturing(false);
        void load();
    };

    return (
        <AppLayout>
            <Head title="Zároveň" />

            <div className="p-4 sm:p-6">
                <header className="mb-6 flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-[var(--color-text-primary)]">
                            <Sparkles size={20} className="text-[var(--color-accent)]"/> Zároveň
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-[var(--color-text-secondary)]">
                            Jednou denně v náhodný čas. Vyfotíte oba, co právě děláte — a co vyfotil ten druhý,
                            uvidíte teprve až pošlete svůj snímek.
                        </p>
                    </div>
                </header>

                {loading && <p className="text-sm text-[var(--color-text-secondary)]">Načítám…</p>}
                {error && <p className="text-sm text-red-400">{error}</p>}

                {state && (
                    <>
                        {/* Today */}
                        <section className="mb-8 rounded-3xl border border-[var(--color-accent)]/25 bg-gradient-to-br from-[var(--color-accent)]/10 to-[var(--color-bg-card)] p-4 sm:p-5">
                            {! state.moment.is_open ? (
                                <div className="flex items-center gap-3">
                                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[var(--color-accent)]/15 text-[var(--color-accent)]">
                                        <Hourglass size={20}/>
                                    </span>
                                    <div>
                                        <p className="text-sm font-medium text-[var(--color-text-primary)]">Dnešní moment ještě nezačal</p>
                                        <p className="text-xs text-[var(--color-text-secondary)]">
                                            Přijde někdy dnes — schválně vám neřekneme kdy. O to jde.
                                        </p>
                                    </div>
                                </div>
                            ) : state.mine ? (
                                <div className="space-y-4">
                                    <div className="flex flex-wrap items-center gap-3">
                                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-400/15 text-emerald-300">
                                            <Camera size={20}/>
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-[var(--color-text-primary)]">Váš dnešní moment je uložený</p>
                                            <p className="text-xs text-[var(--color-text-secondary)]">
                                                {state.others.length > 0
                                                    ? 'A tohle dělal ten druhý ve stejnou chvíli.'
                                                    : state.members_count > 1
                                                        ? 'Teď čekáte vy — jakmile pošle svůj, objeví se tady.'
                                                        : 'Až přizvete někoho dalšího, uvidíte se navzájem.'}
                                            </p>
                                        </div>
                                        <button type="button" onClick={() => setCapturing(true)}
                                            className="ml-auto rounded-xl border border-[var(--color-border)] px-3 py-2 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                                            Přefotit
                                        </button>
                                    </div>

                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                        <MomentCard entry={state.mine}/>
                                        {state.others.map(entry => <MomentCard key={entry.uuid} entry={entry}/>)}
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    <div className="flex flex-wrap items-center gap-3">
                                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[var(--color-accent)]/15 text-[var(--color-accent)]">
                                            <Camera size={20}/>
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-[var(--color-text-primary)]">
                                                {state.moment.is_late ? 'Dnešní moment už uplynul' : 'Teď! Vyfoťte, co právě děláte'}
                                            </p>
                                            <p className="flex flex-wrap items-center gap-x-2 text-xs text-[var(--color-text-secondary)]">
                                                <Clock size={11}/> začalo v {timeLabel(state.moment.notify_at)}
                                                {state.moment.is_late
                                                    ? ' · pošlete i tak, jen to bude označené'
                                                    : state.moment.minutes_left !== null && ` · zbývá ${minutes(state.moment.minutes_left)}`}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Told that somebody is waiting, shown nothing of theirs. */}
                                    {state.waiting_on_you && (
                                        <p className="flex items-center gap-2 rounded-xl bg-[var(--color-bg-card)] px-3 py-2 text-xs text-[var(--color-text-secondary)]">
                                            <Lock size={13}/>
                                            {state.others_count === 1 ? 'Někdo už svůj moment poslal.' : `Momenty už poslali ostatní (${state.others_count}).`}
                                            {' '}Odemkne se, jakmile pošlete svůj.
                                        </p>
                                    )}

                                    <button type="button" onClick={() => setCapturing(true)}
                                        className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] hover:opacity-90 sm:w-auto">
                                        <Camera size={16}/> Vyfotit svůj moment
                                    </button>
                                </div>
                            )}
                        </section>

                        {/* The days already answered. */}
                        {past.length > 0 && (
                            <section>
                                <h2 className="mb-3 text-sm font-semibold text-[var(--color-text-primary)]">Předchozí dny</h2>

                                <div className="space-y-6">
                                    {past.map(moment => (
                                        <article key={moment.uuid}>
                                            <div className="mb-2 flex items-baseline gap-2">
                                                <h3 className="text-xs font-medium text-[var(--color-text-primary)]">{dayLabel(moment.date)}</h3>
                                                <span className="text-[10px] text-[var(--color-text-secondary)]">v {timeLabel(moment.notify_at)}</span>
                                            </div>
                                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                                {moment.entries.map(entry => <MomentCard key={entry.uuid} entry={entry} compact/>)}
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}
                    </>
                )}
            </div>

            {capturing && <DualCapture onCancel={() => setCapturing(false)} onReady={submit}/>}
        </AppLayout>
    );
}
