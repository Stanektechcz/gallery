import { recordingFilename } from '@/lib/microphone';
import AppLayout from '@/Layouts/AppLayout';
import AudioRecorder from '@/Components/AudioRecorder';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Crown, LoaderCircle, Lock, Star, Trash2, Trophy } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Rating {
    user: { id: number | null; name: string | null };
    loudness: number; length: number; artistry: number; surprise: number;
    score: number; comment?: string | null;
}

interface Burp {
    uuid: string;
    title?: string | null;
    occasion?: string | null;
    duration_ms?: number | null;
    happened_at?: string | null;
    author: { id: number | null; name: string | null };
    has_audio: boolean;
    stream_url?: string | null;
    average_score?: number | null;
    ratings: Rating[];
    my_rating?: { loudness: number; length: number; artistry: number; surprise: number; comment?: string | null } | null;
    can_rate: boolean;
    can_delete: boolean;
}

interface LeaderboardRow {
    user: { id: number | null; name: string | null };
    burps: number; average_score: number; best_score: number;
}

const CRITERIA: Array<[keyof Draft, string]> = [
    ['loudness', 'Hlasitost'], ['length', 'Délka'], ['artistry', 'Umění'], ['surprise', 'Překvapení'],
];

type Draft = { loudness: number; length: number; artistry: number; surprise: number; comment: string };
const emptyDraft = (): Draft => ({ loudness: 3, length: 3, artistry: 3, surprise: 3, comment: '' });

const when = (value?: string | null) =>
    value ? new Date(value).toLocaleString('cs-CZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

export default function BurpsIndex() {
    const [burps, setBurps] = useState<Burp[]>([]);
    const [leaderboard, setLeaderboard] = useState<LeaderboardRow[]>([]);
    const [champion, setChampion] = useState<{ user: { name: string | null }; title?: string | null; score: number } | null>(null);
    const [loading, setLoading] = useState(true);
    const [locked, setLocked] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [drafts, setDrafts] = useState<Record<string, Draft>>({});
    const [occasion, setOccasion] = useState('');

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/burps');
            setBurps(response.data.burps ?? []);
            setLeaderboard(response.data.leaderboard ?? []);
            setChampion(response.data.champion ?? null);
            setLocked(false);
        } catch (reason: any) {
            // 402 is the entitlement gate, not a failure.
            if (reason?.response?.status === 402) setLocked(true);
            else setError(reason?.response?.data?.message ?? 'Data se nepodařilo načíst.');
        } finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const upload = async (blob: Blob, durationMs: number, title: string) => {
        setBusy('upload'); setError('');
        const form = new FormData();
        form.append('audio', blob, recordingFilename('krkanec', blob));
        form.append('duration_ms', String(Math.max(100, Math.round(durationMs))));
        if (title.trim()) form.append('title', title.trim());
        if (occasion.trim()) form.append('occasion', occasion.trim());
        try {
            await axios.post('/api/v1/burps', form);
            setOccasion('');
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Záznam se nepodařilo uložit.');
        } finally { setBusy(null); }
    };

    const draftFor = (burp: Burp): Draft => drafts[burp.uuid] ?? (burp.my_rating
        ? { ...burp.my_rating, comment: burp.my_rating.comment ?? '' }
        : emptyDraft());

    const setDraft = (burp: Burp, update: Partial<Draft>) =>
        setDrafts(current => ({ ...current, [burp.uuid]: { ...draftFor(burp), ...update } }));

    const rate = async (burp: Burp) => {
        setBusy(`rate:${burp.uuid}`); setError('');
        try {
            await axios.put(`/api/v1/burps/${burp.uuid}/rating`, draftFor(burp));
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Hodnocení se nepodařilo uložit.');
        } finally { setBusy(null); }
    };

    const remove = async (burp: Burp) => {
        if (!window.confirm('Opravdu smazat tenhle záznam i s hodnocením?')) return;
        setBusy(`del:${burp.uuid}`);
        try {
            await axios.delete(`/api/v1/burps/${burp.uuid}`);
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Záznam se nepodařilo smazat.');
        } finally { setBusy(null); }
    };

    if (locked) {
        return (
            <AppLayout>
                <Head title="Hodnocení krkanců" />
                <main className="w-full p-4 sm:p-6">
                    <div className="rounded-2xl border border-violet-400/30 bg-violet-500/5 p-8 text-center">
                        <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-violet-500/15 text-2xl">🎺</span>
                        <h1 className="mt-4 text-xl font-bold text-[var(--color-text-primary)]">Hodnocení krkanců</h1>
                        <p className="mx-auto mt-2 max-w-md text-sm text-[var(--color-text-secondary)]">
                            Tenhle doplňkový modul zatím nemáte aktivní. Zaznamenávejte krkance, nechte je ohodnotit
                            protějškem podle hlasitosti, délky, umění a překvapení a sledujte žebříček i šampiona měsíce.
                        </p>
                        <div className="mt-5 flex flex-wrap justify-center gap-2">
                            <Link href="/cenik" className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                                <Lock size={15} /> Zobrazit ceník
                            </Link>
                            <Link href="/settings/predplatne" className="inline-flex min-h-10 items-center rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]">
                                Správa předplatného
                            </Link>
                        </div>
                    </div>
                </main>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Hodnocení krkanců" />
            <main className="w-full p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-violet-300">Doplňkový modul</p>
                <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">🎺 Hodnocení krkanců</h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Zaznamenejte úlovek a nechte ho ohodnotit. Vlastní krkanec si ohodnotit nemůžete — to by nebylo fér.
                </p>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}

                {champion && (
                    <div className="mt-5 flex items-center gap-3 rounded-2xl border border-amber-400/30 bg-amber-500/10 p-4">
                        <Crown size={22} className="shrink-0 text-amber-300" />
                        <div>
                            <p className="text-xs uppercase tracking-wide text-amber-200">Šampion měsíce</p>
                            <p className="font-semibold text-[var(--color-text-primary)]">{champion.user.name} · {champion.score}/5</p>
                            {champion.title && <p className="text-xs text-[var(--color-text-secondary)]">{champion.title}</p>}
                        </div>
                    </div>
                )}

                <input
                    value={occasion}
                    onChange={event => setOccasion(event.target.value)}
                    maxLength={120}
                    placeholder="Příležitost, nepovinné — třeba „po nedělním obědě“"
                    className="mt-5 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none"
                />
                <AudioRecorder profile="raw" onRecorded={upload} busy={busy === 'upload'} label="Zaznamenat krkanec" maxSeconds={60} />

                {leaderboard.length > 0 && (
                    <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                        <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]"><Trophy size={17} className="text-amber-300" /> Žebříček</h2>
                        <div className="mt-3 space-y-2">
                            {leaderboard.map((row, index) => (
                                <div key={row.user.id ?? index} className="flex items-center justify-between text-sm">
                                    <span className="text-[var(--color-text-primary)]">{index + 1}. {row.user.name}</span>
                                    <span className="text-xs text-[var(--color-text-secondary)]">
                                        průměr {row.average_score}/5 · nejlepší {row.best_score}/5 · {row.burps}×
                                    </span>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                {/* Two columns once there is room. The cards carry a rating form, so the six-across
                    grid the voice notes use would squeeze the inputs — but a single column on a
                    wide monitor leaves half the screen doing nothing. */}
                <section className="mt-6 grid gap-3 xl:grid-cols-2">
                    {loading && <div className="flex justify-center py-8"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                    {!loading && burps.map(burp => {
                        const draft = draftFor(burp);
                        return (
                            <article key={burp.uuid} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-medium text-[var(--color-text-primary)]">{burp.title || 'Bez názvu'}</p>
                                    <p className="text-xs text-[var(--color-text-secondary)]">
                                        {burp.author.name} · {when(burp.happened_at)}{burp.occasion ? ` · ${burp.occasion}` : ''}
                                    </p>
                                </div>
                                {burp.average_score != null && (
                                    <p className="mt-1 inline-flex items-center gap-1 rounded-lg bg-amber-500/10 px-2 py-1 text-xs text-amber-100">
                                        <Star size={12} className="fill-amber-300 text-amber-300" /> {burp.average_score}/5
                                    </p>
                                )}
                                {burp.has_audio && burp.stream_url && <audio controls preload="none" src={burp.stream_url} className="mt-3 w-full" />}

                                {burp.can_rate && (
                                    <div className="mt-3 rounded-xl border border-violet-400/20 bg-violet-500/5 p-3">
                                        <p className="text-xs font-medium text-[var(--color-text-primary)]">{burp.my_rating ? 'Upravit hodnocení' : 'Ohodnotit'}</p>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                            {CRITERIA.map(([key, label]) => (
                                                <label key={key} className="text-[10px] text-[var(--color-text-secondary)]">
                                                    {label}
                                                    <div className="mt-1 flex gap-1">
                                                        {[1, 2, 3, 4, 5].map(value => (
                                                            <button
                                                                key={value}
                                                                type="button"
                                                                aria-label={`${label} ${value} z 5`}
                                                                onClick={() => setDraft(burp, { [key]: value } as Partial<Draft>)}
                                                                className={`min-h-8 flex-1 rounded border text-[10px] ${Number(draft[key]) >= value ? 'border-amber-300/50 bg-amber-400/20 text-amber-100' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}
                                                            >{value}</button>
                                                        ))}
                                                    </div>
                                                </label>
                                            ))}
                                        </div>
                                        <input
                                            value={draft.comment}
                                            onChange={event => setDraft(burp, { comment: event.target.value })}
                                            maxLength={400}
                                            placeholder="Komentář, nepovinné"
                                            className="mt-2 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-xs text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => rate(burp)}
                                            disabled={busy !== null}
                                            className="mt-2 inline-flex min-h-9 items-center gap-2 rounded-lg bg-violet-500 px-3 text-xs font-medium text-white disabled:opacity-40"
                                        >
                                            {busy === `rate:${burp.uuid}` ? <LoaderCircle size={13} className="animate-spin" /> : <Star size={13} />} Uložit hodnocení
                                        </button>
                                    </div>
                                )}

                                {burp.ratings.length > 0 && (
                                    <div className="mt-3 space-y-1">
                                        {burp.ratings.map(rating => (
                                            <p key={rating.user.id ?? rating.score} className="text-xs text-[var(--color-text-secondary)]">
                                                <b className="text-[var(--color-text-primary)]">{rating.user.name}</b> · {rating.score}/5
                                                {rating.comment ? ` · ${rating.comment}` : ''}
                                            </p>
                                        ))}
                                    </div>
                                )}

                                {burp.can_delete && (
                                    <button
                                        type="button"
                                        onClick={() => remove(burp)}
                                        disabled={busy !== null}
                                        className="mt-3 inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-red-400/30 px-3 text-xs text-red-100 hover:bg-red-500/10 disabled:opacity-40"
                                    >
                                        <Trash2 size={13} /> Smazat
                                    </button>
                                )}
                            </article>
                        );
                    })}

                    {!loading && !burps.length && (
                        <div className="rounded-2xl border border-dashed border-[var(--color-border)] bg-[var(--color-bg-card)] p-8 text-center text-sm text-[var(--color-text-secondary)]">
                            Zatím tu nic není. První úlovek zaznamenáte tlačítkem nahoře.
                        </div>
                    )}
                </section>
            </main>
        </AppLayout>
    );
}
