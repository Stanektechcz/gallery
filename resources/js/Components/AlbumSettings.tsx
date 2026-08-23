import axios from 'axios';
import { router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';

/**
 * An album's own settings.
 *
 * Everything here could already be chosen when the album was created and then never
 * again — a typo in the title survived forever, and a holiday album stayed "Soukromé"
 * because that was the default on the day it was made. The endpoint accepted all of it
 * the whole time; only the screen was missing.
 *
 * Sent as a patch of what actually changed rather than the whole form. The album row
 * carries fields this screen does not show, and posting a full object would quietly
 * blank them.
 */

const COLOURS = ['#6366f1', '#ec4899', '#f97316', '#22c55e', '#06b6d4', '#a855f7', '#eab308'];

export interface AlbumSettingsValues {
    title: string;
    description?: string | null;
    event_date_start?: string | null;
    event_date_end?: string | null;
    visibility?: 'private' | 'shared' | 'public' | null;
    sort_mode?: string | null;
    sort_direction?: string | null;
    color?: string | null;
}

interface Props {
    albumUuid: string;
    album: AlbumSettingsValues;
    onClose: () => void;
}

/** Dates arrive as full timestamps; the date input wants only the day. */
const asDay = (value?: string | null): string => (value ? value.substring(0, 10) : '');

const FIELD = 'w-full rounded-lg bg-[var(--color-bg-primary)] border border-[var(--color-border)] text-[var(--color-text-primary)] placeholder-[var(--color-text-secondary)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors';
const LABEL = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

export default function AlbumSettings({ albumUuid, album, onClose }: Props) {
    const [form, setForm] = useState({
        title: album.title ?? '',
        description: album.description ?? '',
        event_date_start: asDay(album.event_date_start),
        event_date_end: asDay(album.event_date_end),
        visibility: album.visibility ?? 'private',
        sort_mode: album.sort_mode ?? 'date_taken',
        sort_direction: album.sort_direction ?? 'desc',
        color: album.color ?? '',
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
        setForm(current => ({ ...current, [key]: value }));

    const save = async () => {
        if (! form.title.trim()) {
            setError('Album potřebuje název.');

            return;
        }

        // An end date before the start is refused by the server anyway; saying so here
        // saves a round trip and points at the field that is wrong.
        if (form.event_date_start && form.event_date_end && form.event_date_end < form.event_date_start) {
            setError('Konec období nemůže být dřív než jeho začátek.');

            return;
        }

        setSaving(true);
        setError('');

        try {
            await axios.patch(`/albums/${albumUuid}`, {
                title: form.title.trim(),
                description: form.description.trim() || null,
                event_date_start: form.event_date_start || null,
                event_date_end: form.event_date_end || null,
                visibility: form.visibility,
                sort_mode: form.sort_mode,
                sort_direction: form.sort_direction,
                color: form.color || null,
            });

            // Renaming an album renames its folder on the connected drive, which happens
            // on a queue. The row we reload may still say "pending"; that is the sync
            // catching up, not the change failing.
            router.reload({ only: ['album', 'media'] });
            onClose();
        } catch (problem: any) {
            const errors = problem?.response?.data?.errors;
            setError(errors ? Object.values(errors).flat().join(' ') : 'Nastavení se nepodařilo uložit.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <section className="mb-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:p-5">
            <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Nastavení alba</h2>
                    <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">Název, popis, období, viditelnost i výchozí řazení.</p>
                </div>
                <button type="button" onClick={onClose} title="Zavřít"
                    className="shrink-0 rounded-lg border border-[var(--color-border)] p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <X size={14}/>
                </button>
            </div>

            <div className="space-y-4">
                <div>
                    <label className={LABEL}>Název</label>
                    <input value={form.title} onChange={e => set('title', e.target.value)} maxLength={255} className={FIELD}/>
                </div>

                <div>
                    <label className={LABEL}>Popis</label>
                    <textarea value={form.description} onChange={e => set('description', e.target.value)}
                        rows={3} maxLength={5000} className={`${FIELD} resize-none`}
                        placeholder="O čem album je — kde jste byli, s kým, co si z toho pamatujete."/>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label className={LABEL}>Období od</label>
                        <input type="date" value={form.event_date_start} onChange={e => set('event_date_start', e.target.value)} className={FIELD}/>
                    </div>
                    <div>
                        <label className={LABEL}>Období do</label>
                        <input type="date" value={form.event_date_end} onChange={e => set('event_date_end', e.target.value)} className={FIELD}/>
                    </div>
                    <div>
                        <label className={LABEL}>Viditelnost</label>
                        <select value={form.visibility} onChange={e => set('visibility', e.target.value as typeof form.visibility)} className={FIELD}>
                            <option value="private">Soukromé</option>
                            <option value="shared">Sdílené</option>
                            <option value="public">Veřejné</option>
                        </select>
                    </div>
                    <div>
                        <label className={LABEL}>Výchozí řazení</label>
                        {/* Widths given by the grid, not by the fields.
                            Both selects carried FIELD's own w-full, and appending w-auto
                            to one of them did not undo it — Tailwind decides by stylesheet
                            order, not by the order of names in the string. The direction
                            select won the whole row and the mode select was squeezed down
                            to its arrow, unreadable and unpickable. */}
                        <div className="grid grid-cols-[1fr_auto] gap-2">
                            <select value={form.sort_mode} onChange={e => set('sort_mode', e.target.value)}
                                className={`${FIELD} min-w-0`}>
                                <option value="date_taken">Datum pořízení</option>
                                <option value="date_uploaded">Datum nahrání</option>
                                <option value="title">Název</option>
                                <option value="manual">Ručně</option>
                            </select>
                            {/* Ordering a manually arranged album makes no sense — the point of
                                arranging it by hand is that the order is already decided. */}
                            <select value={form.sort_direction} onChange={e => set('sort_direction', e.target.value)}
                                disabled={form.sort_mode === 'manual'}
                                className="rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2 text-sm text-[var(--color-text-primary)] transition-colors focus:border-[var(--color-accent)] focus:outline-none disabled:opacity-40">
                                <option value="desc">Sestupně</option>
                                <option value="asc">Vzestupně</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div>
                    <label className={LABEL}>Barva</label>
                    <div className="flex flex-wrap items-center gap-2">
                        {COLOURS.map(colour => (
                            <button key={colour} type="button" onClick={() => set('color', colour)}
                                title={colour}
                                className={`h-7 w-7 rounded-full border-2 transition-transform ${form.color === colour ? 'scale-110 border-[var(--color-text-primary)]' : 'border-transparent hover:scale-105'}`}
                                style={{ backgroundColor: colour }}>
                                {form.color === colour && <Check size={13} className="mx-auto text-white"/>}
                            </button>
                        ))}
                        <button type="button" onClick={() => set('color', '')}
                            className={`rounded-lg border px-2.5 py-1 text-xs transition-colors ${form.color === '' ? 'border-[var(--color-accent)] text-[var(--color-text-primary)]' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'}`}>
                            Bez barvy
                        </button>
                    </div>
                </div>

                {error && <p className="text-xs text-red-400">{error}</p>}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" onClick={onClose}
                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                        Zrušit
                    </button>
                    <button type="button" onClick={save} disabled={saving}
                        className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] hover:opacity-90 disabled:opacity-50">
                        <Check size={15}/>{saving ? 'Ukládám…' : 'Uložit nastavení'}
                    </button>
                </div>
            </div>
        </section>
    );
}
