import { recordingFilename } from '@/lib/microphone';
import AppLayout from '@/Layouts/AppLayout';
import AudioRecorder from '@/Components/AudioRecorder';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Check, LoaderCircle, Mic, Pin, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

interface Note {
    uuid: string;
    title?: string | null;
    author: { id: number | null; name: string | null };
    duration_ms?: number | null;
    size_bytes: number;
    transcript?: string | null;
    recorded_at?: string | null;
    stream_url: string;
    heard: boolean;
    from_chat?: boolean;
    can_delete: boolean;
}

const duration = (ms?: number | null) => {
    if (!ms) return '';
    const total = Math.round(ms / 1000);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
};

const when = (value?: string | null) =>
    value ? new Date(value).toLocaleString('cs-CZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

export default function VoiceNotesIndex() {
    const [notes, setNotes] = useState<Note[]>([]);

    /**
     * The notes gathered under the day they were recorded.
     *
     * Insertion order carries the grouping, so the API's ordering decides the timeline and
     * this does not sort it a second time — two places deciding the order is how a list
     * ends up disagreeing with itself.
     */
    const byDay = useMemo(() => {
        const groups = new Map<string, Note[]>();

        for (const note of notes) {
            const day = note.recorded_at
                ? new Date(note.recorded_at).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' })
                : 'Bez data';

            (groups.get(day) ?? groups.set(day, []).get(day)!).push(note);
        }

        return [...groups.entries()];
    }, [notes]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/voice-notes');
            setNotes(response.data.notes ?? []);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Hlasovky se nepodařilo načíst.');
        } finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const upload = async (blob: Blob, durationMs: number, title: string) => {
        setBusy('upload'); setError('');
        const form = new FormData();
        // The extension has to match the recorded type or the mimetypes rule rejects it.
        form.append('audio', blob, recordingFilename('hlasovka', blob));
        form.append('duration_ms', String(Math.max(200, Math.round(durationMs))));
        if (title.trim()) form.append('title', title.trim());
        try {
            await axios.post('/api/v1/voice-notes', form);
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Hlasovku se nepodařilo uložit.');
        } finally { setBusy(null); }
    };

    const markHeard = async (note: Note) => {
        if (note.heard) return;
        try {
            await axios.post(`/api/v1/voice-notes/${note.uuid}/listened`);
            setNotes(current => current.map(item => item.uuid === note.uuid ? { ...item, heard: true } : item));
        } catch { /* Marking as heard is a convenience, never blocking. */ }
    };

    const remove = async (note: Note) => {
        if (!window.confirm('Opravdu smazat tuhle hlasovku?')) return;
        setBusy(`del:${note.uuid}`);
        try {
            await axios.delete(`/api/v1/voice-notes/${note.uuid}`);
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Hlasovku se nepodařilo smazat.');
        } finally { setBusy(null); }
    };

    return (
        <AppLayout>
            <Head title="Hlasovky" />
            <main className="w-full p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Zvuk</p>
                <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">
                    <Mic size={24} className="text-[var(--color-accent)]" /> Hlasovky
                </h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Krátké vzkazy, na které není potřeba psát. Nahrávky zůstávají soukromé a přehrají se jen členům vašeho prostoru.
                </p>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}

                <AudioRecorder onRecorded={upload} busy={busy === 'upload'} label="Nahrát hlasovku" />

                {/* A timeline grouped by the day things were said, and within a day a grid
                    rather than a stack. A voice note carries a name, a length and a play
                    button — a full-width row for that is mostly empty, and twenty of them
                    is a lot of scrolling for very little. */}
                <section className="mt-6">
                    {loading && <div className="flex justify-center py-8"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                    {!loading && byDay.map(([day, dayNotes]) => (
                        <div key={day} className="mt-6 first:mt-0">
                            <div className="mb-2 flex items-center gap-3">
                                <h2 className="shrink-0 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{day}</h2>
                                <span className="h-px flex-1 bg-[var(--color-border)]" />
                                <span className="shrink-0 text-[10px] text-[var(--color-text-secondary)]">{dayNotes.length}</span>
                            </div>

                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
                                {dayNotes.map(note => (
                                    <article
                                        key={note.uuid}
                                        className={`flex flex-col rounded-xl border p-2.5 ${note.heard
                                            ? 'border-[var(--color-border)] bg-[var(--color-bg-card)]'
                                            : 'border-[var(--color-accent)]/35 bg-[var(--color-accent)]/5'}`}
                                    >
                                        <div className="flex items-start gap-1.5">
                                            <p className="min-w-0 flex-1 truncate text-xs font-medium text-[var(--color-text-primary)]" title={note.title || 'Bez názvu'}>
                                                {note.title || 'Bez názvu'}
                                            </p>
                                            {!note.heard && <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-accent)]" title="Nepřehráno" />}
                                        </div>

                                        <p className="flex items-center gap-1 truncate text-[10px] text-[var(--color-text-secondary)]">
                                            {/* Marked rather than labelled: a word would take
                                                the width the name needs, and the icon already
                                                matches the button that put it here. */}
                                            {note.from_chat && <Pin size={9} className="shrink-0 text-[var(--color-accent)]" aria-label="Připnuto z konverzace" />}
                                            <span className="truncate">
                                                {note.author.name}{note.duration_ms ? ` · ${duration(note.duration_ms)}` : ''}
                                            </span>
                                        </p>

                                        {/* The native player, kept small. Rolling our own would
                                            mean rebuilding seeking, buffering and keyboard
                                            control for no gain the person can hear. */}
                                        <audio
                                            controls
                                            preload="none"
                                            src={note.stream_url}
                                            onPlay={() => markHeard(note)}
                                            className="mt-2 h-8 w-full"
                                        />

                                        {note.can_delete && (
                                            <button
                                                type="button"
                                                onClick={() => remove(note)}
                                                disabled={busy !== null}
                                                aria-label={`Smazat ${note.title || 'hlasovku'}`}
                                                className="mt-1.5 self-end rounded-lg p-1 text-red-300 hover:bg-red-500/10 disabled:opacity-40"
                                            >
                                                {busy === `del:${note.uuid}` ? <LoaderCircle size={12} className="animate-spin" /> : <Trash2 size={12} />}
                                            </button>
                                        )}
                                    </article>
                                ))}
                            </div>
                        </div>
                    ))}

                    {!loading && !notes.length && (
                        <div className="rounded-2xl border border-dashed border-[var(--color-border)] bg-[var(--color-bg-card)] p-8 text-center">
                            <Check className="mx-auto text-[var(--color-accent)]" size={22} />
                            <p className="mt-3 font-medium text-[var(--color-text-primary)]">Zatím tu není žádná hlasovka</p>
                            <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Nahrajte první vzkaz tlačítkem nahoře.</p>
                        </div>
                    )}
                </section>
            </main>
        </AppLayout>
    );
}
