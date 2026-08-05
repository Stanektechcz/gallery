import AppLayout from '@/Layouts/AppLayout';
import AudioRecorder from '@/Components/AudioRecorder';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Check, LoaderCircle, Mic, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

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
        form.append('audio', blob, `hlasovka.${blob.type.includes('ogg') ? 'ogg' : 'webm'}`);
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
            <main className="mx-auto max-w-3xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Zvuk</p>
                <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">
                    <Mic size={24} className="text-[var(--color-accent)]" /> Hlasovky
                </h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Krátké vzkazy, na které není potřeba psát. Nahrávky zůstávají soukromé a přehrají se jen členům vašeho prostoru.
                </p>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}

                <AudioRecorder onRecorded={upload} busy={busy === 'upload'} label="Nahrát hlasovku" />

                <section className="mt-6 space-y-3">
                    {loading && <div className="flex justify-center py-8"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                    {!loading && notes.map(note => (
                        <article key={note.uuid} className={`rounded-2xl border p-4 ${note.heard ? 'border-[var(--color-border)] bg-[var(--color-bg-card)]' : 'border-[var(--color-accent)]/35 bg-[var(--color-accent)]/5'}`}>
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <p className="font-medium text-[var(--color-text-primary)]">
                                    {note.title || 'Bez názvu'}
                                    {!note.heard && <span className="ml-2 rounded-full bg-[var(--color-accent)]/20 px-2 py-0.5 text-[10px] text-[var(--color-accent)]">nové</span>}
                                </p>
                                <p className="text-xs text-[var(--color-text-secondary)]">
                                    {note.author.name} · {when(note.recorded_at)}{note.duration_ms ? ` · ${duration(note.duration_ms)}` : ''}
                                </p>
                            </div>
                            <audio
                                controls
                                preload="none"
                                src={note.stream_url}
                                onPlay={() => markHeard(note)}
                                className="mt-3 w-full"
                            />
                            {note.transcript && <p className="mt-2 text-xs leading-relaxed text-[var(--color-text-secondary)]">{note.transcript}</p>}
                            {note.can_delete && (
                                <button
                                    type="button"
                                    onClick={() => remove(note)}
                                    disabled={busy !== null}
                                    className="mt-3 inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-red-400/30 px-3 text-xs text-red-100 hover:bg-red-500/10 disabled:opacity-40"
                                >
                                    {busy === `del:${note.uuid}` ? <LoaderCircle size={13} className="animate-spin" /> : <Trash2 size={13} />} Smazat
                                </button>
                            )}
                        </article>
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
