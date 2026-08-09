import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { BookHeart, Loader2, Lock, Pencil, Plus, Trash2, Users, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Entry {
    uuid: string;
    title: string | null;
    body: string;
    mood: string | null;
    entry_date: string | null;
    shared: boolean;
    shared_at: string | null;
    author: { id: number; name: string | null };
    is_mine: boolean;
    can_edit: boolean;
}

type Scope = 'all' | 'mine' | 'shared';

const scopes: Array<{ value: Scope; label: string }> = [
    { value: 'all', label: 'Vše' },
    { value: 'mine', label: 'Můj deník' },
    { value: 'shared', label: 'Sdílené' },
];

const czechDate = (value: string | null) =>
    value ? new Date(value).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' }) : '';

const blank = { uuid: '', title: '', body: '', mood: '', entry_date: new Date().toISOString().slice(0, 10) };

export default function JournalIndex() {
    const [entries, setEntries] = useState<Entry[]>([]);
    const [moods, setMoods] = useState<string[]>([]);
    const [scope, setScope] = useState<Scope>('all');
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [editorOpen, setEditorOpen] = useState(false);
    const [draft, setDraft] = useState(blank);

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/journal', {
                params: { scope: scope === 'all' ? undefined : scope, q: query.trim() || undefined },
            });
            setEntries(response.data.entries ?? []);
            setMoods(response.data.moods ?? []);
            setError('');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Deník se nepodařilo načíst.');
        } finally { setLoading(false); }
    }, [scope, query]);

    // Typing in the search box should not fire a request per keystroke.
    useEffect(() => {
        const handle = window.setTimeout(() => void load(), query ? 300 : 0);
        return () => window.clearTimeout(handle);
    }, [load, query]);

    const save = async () => {
        if (!draft.body.trim()) { setError('Zápisek bez textu uložit nelze.'); return; }
        setBusy('save'); setError('');
        const payload = {
            title: draft.title.trim() || null,
            body: draft.body,
            mood: draft.mood || null,
            entry_date: draft.entry_date,
        };
        try {
            if (draft.uuid) await axios.patch(`/api/v1/journal/${draft.uuid}`, payload);
            else await axios.post('/api/v1/journal', payload);
            setEditorOpen(false); setDraft(blank);
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Zápisek se nepodařilo uložit.');
        } finally { setBusy(null); }
    };

    /** Sharing is per entry, so the toggle lives on the entry and nowhere else. */
    const toggleShare = async (entry: Entry) => {
        setBusy(entry.uuid); setError('');
        try {
            await axios.put(`/api/v1/journal/${entry.uuid}/sdileni`, { shared: !entry.shared });
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Sdílení se nepodařilo změnit.');
        } finally { setBusy(null); }
    };

    const remove = async (entry: Entry) => {
        if (!window.confirm('Opravdu smazat tento zápisek?')) return;
        setBusy(entry.uuid);
        try { await axios.delete(`/api/v1/journal/${entry.uuid}`); await load(); }
        catch (reason: any) { setError(reason?.response?.data?.message ?? 'Smazat se nepodařilo.'); }
        finally { setBusy(null); }
    };

    const edit = (entry: Entry) => {
        setDraft({
            uuid: entry.uuid,
            title: entry.title ?? '',
            body: entry.body,
            mood: entry.mood ?? '',
            entry_date: entry.entry_date ?? blank.entry_date,
        });
        setEditorOpen(true);
    };

    return (
        <AppLayout title="Deník">
            <Head title="Deník" />
            <main className="mx-auto max-w-3xl p-4 sm:p-6">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Jen pro vás</p>
                        <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">
                            <BookHeart size={26} className="text-[var(--color-accent)]" /> Deník
                        </h1>
                        <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                            Každý zápisek je nejdřív jen váš. Sdílíte ho, až když sami chcete.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => { setDraft(blank); setEditorOpen(true); }}
                        className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]"
                    >
                        <Plus size={16} /> Nový zápisek
                    </button>
                </header>

                <div className="mt-5 flex flex-wrap items-center gap-2">
                    {scopes.map(option => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() => setScope(option.value)}
                            className={`min-h-9 rounded-lg px-3 text-xs ${scope === option.value
                                ? 'bg-[var(--color-accent)]/15 text-[var(--color-accent-contrast)]'
                                : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'}`}
                        >
                            {option.label}
                        </button>
                    ))}
                    <input
                        value={query}
                        onChange={event => setQuery(event.target.value)}
                        placeholder="Hledat v zápiscích"
                        className="ml-auto min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-xs text-[var(--color-text-primary)] sm:w-56"
                    />
                </div>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}
                {loading && <div className="mt-8 flex justify-center"><Loader2 className="animate-spin text-[var(--color-accent)]" /></div>}

                {!loading && entries.length === 0 && (
                    <p className="mt-8 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5 text-sm text-[var(--color-text-secondary)]">
                        Zatím tu nic není. První zápisek uvidíte jen vy.
                    </p>
                )}

                <div className="mt-5 space-y-3">
                    {entries.map(entry => (
                        <article key={entry.uuid} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-xs text-[var(--color-text-secondary)]">
                                        {czechDate(entry.entry_date)}
                                        {!entry.is_mine && ` · ${entry.author.name ?? 'partner'}`}
                                        {entry.mood && ` · ${entry.mood}`}
                                    </p>
                                    {entry.title && <h2 className="mt-1 font-semibold text-[var(--color-text-primary)]">{entry.title}</h2>}
                                </div>
                                <span className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-1 text-[10px] ${entry.shared
                                    ? 'bg-emerald-500/15 text-emerald-200'
                                    : 'bg-[var(--color-surface-muted)] text-[var(--color-text-secondary)]'}`}>
                                    {entry.shared ? <Users size={11} /> : <Lock size={11} />}
                                    {entry.shared ? 'Sdílené' : 'Soukromé'}
                                </span>
                            </div>

                            <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-[var(--color-text-secondary)]">{entry.body}</p>

                            {entry.can_edit && (
                                <div className="mt-3 flex flex-wrap gap-2 border-t border-[var(--color-border)] pt-3">
                                    <button
                                        type="button"
                                        disabled={busy === entry.uuid}
                                        onClick={() => void toggleShare(entry)}
                                        className={`inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-xs disabled:opacity-50 ${entry.shared
                                            ? 'border border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                            : 'bg-emerald-600 text-white'}`}
                                    >
                                        {entry.shared ? <><Lock size={13} /> Zrušit sdílení</> : <><Users size={13} /> Sdílet s partnerem</>}
                                    </button>
                                    <button type="button" onClick={() => edit(entry)} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]">
                                        <Pencil size={13} /> Upravit
                                    </button>
                                    <button type="button" disabled={busy === entry.uuid} onClick={() => void remove(entry)} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-xs text-red-300 hover:bg-red-500/10 disabled:opacity-50">
                                        <Trash2 size={13} /> Smazat
                                    </button>
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            </main>

            {editorOpen && (
                <div className="fixed inset-0 z-[700] flex items-end bg-black/60 sm:items-center sm:justify-center sm:p-4">
                    <div className="safe-area-pb max-h-[92vh] w-full overflow-y-auto rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-5 sm:max-w-lg sm:rounded-2xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="font-semibold text-[var(--color-text-primary)]">{draft.uuid ? 'Upravit zápisek' : 'Nový zápisek'}</h2>
                            <button type="button" onClick={() => setEditorOpen(false)} aria-label="Zavřít" className="text-[var(--color-text-secondary)]"><X size={18} /></button>
                        </div>

                        <div className="space-y-3">
                            <input
                                value={draft.title}
                                onChange={event => setDraft({ ...draft, title: event.target.value })}
                                maxLength={180}
                                placeholder="Nadpis, nepovinné"
                                className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                            />
                            <textarea
                                value={draft.body}
                                onChange={event => setDraft({ ...draft, body: event.target.value })}
                                rows={10}
                                maxLength={50000}
                                placeholder="Jaký byl dnešek?"
                                className="w-full resize-none rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                            />
                            <div className="grid gap-2 sm:grid-cols-2">
                                <input
                                    type="date"
                                    value={draft.entry_date}
                                    max={blank.entry_date}
                                    onChange={event => setDraft({ ...draft, entry_date: event.target.value })}
                                    className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm text-[var(--color-text-primary)]"
                                />
                                <select
                                    value={draft.mood}
                                    onChange={event => setDraft({ ...draft, mood: event.target.value })}
                                    className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm text-[var(--color-text-primary)]"
                                >
                                    <option value="">Nálada, nepovinné</option>
                                    {moods.map(mood => <option key={mood} value={mood}>{mood}</option>)}
                                </select>
                            </div>

                            <p className="rounded-lg bg-[var(--color-surface-muted)] p-2.5 text-[10px] text-[var(--color-text-secondary)]">
                                Zápisek se uloží jako soukromý. Sdílet ho můžete kdykoliv později.
                            </p>

                            <div className="flex gap-2">
                                <button type="button" disabled={busy === 'save'} onClick={() => void save()} className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                                    {busy === 'save' && <Loader2 size={15} className="animate-spin" />} Uložit
                                </button>
                                <button type="button" onClick={() => setEditorOpen(false)} className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]">Zrušit</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
