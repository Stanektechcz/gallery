import axios from 'axios';
import { Loader2, Trash2, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Fallback { preset: string | null; initial: string; colour: string }

/** The built-in faces, drawn as text rather than fetched — they cost nothing to render. */
const GLYPH: Record<string, string> = {
    kocka: '🐱', pes: '🐶', liska: '🦊', sova: '🦉', medved: '🐻', panda: '🐼',
    srdce: '❤️', hvezda: '⭐', mesic: '🌙', kytka: '🌸', kava: '☕', duha: '🌈',
};

const COLOURS = ['#7c5cff', '#e0568a', '#2fa8a0', '#e08c3a', '#4a80e8', '#9d5ce6', '#2f9e5e', '#d4546a'];

/** One face, however it is defined. Used here and anywhere else a person is shown. */
export function Avatar({ url, fallback, size = 40 }: { url: string | null; fallback: Fallback; size?: number }) {
    if (url) {
        return <img src={url} alt="" style={{ width: size, height: size }} className="shrink-0 rounded-full object-cover" />;
    }

    return (
        <span
            style={{ width: size, height: size, background: fallback.colour, fontSize: size * 0.45 }}
            className="flex shrink-0 items-center justify-center rounded-full font-bold text-white"
        >
            {fallback.preset ? GLYPH[fallback.preset] ?? fallback.initial : fallback.initial}
        </span>
    );
}

/**
 * Choosing a face: upload one, pick a drawn one, or keep the initial on a colour.
 *
 * The three are mutually exclusive by construction on the server, so the editor never has
 * to decide which one wins.
 */
export default function AvatarEditor({ url, fallback, onChange }: {
    url: string | null;
    fallback: Fallback;
    onChange: (next: { avatar_url: string | null; avatar_fallback: Fallback }) => void;
}) {
    const [presets, setPresets] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const file = useRef<HTMLInputElement>(null);

    useEffect(() => {
        void axios.get('/api/v1/avatar/moznosti')
            .then(response => setPresets(response.data.presets ?? []))
            .catch(() => { /* Presets are a convenience; upload still works without them. */ });
    }, []);

    const save = async (build: (form: FormData) => void) => {
        setBusy(true); setError('');
        try {
            const form = new FormData();
            build(form);
            const response = await axios.post('/api/v1/avatar', form);
            onChange(response.data);
            if (file.current) file.current.value = '';
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Avatar se nepodařilo uložit.');
        } finally { setBusy(false); }
    };

    return (
        <div>
            <div className="flex items-center gap-4">
                <Avatar url={url} fallback={fallback} size={64} />
                <div className="flex flex-wrap gap-2">
                    <input
                        ref={file}
                        type="file"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        className="hidden"
                        onChange={event => {
                            const chosen = event.target.files?.[0];
                            if (chosen) void save(form => form.append('image', chosen));
                        }}
                    />
                    <button type="button" disabled={busy} onClick={() => file.current?.click()} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)] disabled:opacity-50">
                        {busy ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />} Nahrát obrázek
                    </button>
                    {(url || fallback.preset) && (
                        <button type="button" disabled={busy} onClick={() => void save(form => form.append('clear', '1'))} className="inline-flex min-h-10 items-center gap-2 rounded-xl px-3 text-xs text-red-300 hover:bg-red-500/10 disabled:opacity-50">
                            <Trash2 size={14} /> Zpět na iniciálu
                        </button>
                    )}
                </div>
            </div>

            {error && <p role="alert" className="mt-2 rounded-lg bg-red-500/10 p-2 text-xs text-red-100">{error}</p>}

            {presets.length > 0 && (
                <>
                    <p className="mt-4 text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">Nebo si vyberte</p>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {presets.map(preset => (
                            <button
                                key={preset}
                                type="button"
                                disabled={busy}
                                onClick={() => void save(form => form.append('preset', preset))}
                                aria-pressed={fallback.preset === preset}
                                className={`flex h-10 w-10 items-center justify-center rounded-full text-lg disabled:opacity-50 ${fallback.preset === preset ? 'ring-2 ring-[var(--color-accent)]' : 'bg-[var(--color-surface-muted)]'}`}
                            >
                                {GLYPH[preset] ?? '🙂'}
                            </button>
                        ))}
                    </div>

                    <p className="mt-4 text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">Barva pozadí</p>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {COLOURS.map(colour => (
                            <button
                                key={colour}
                                type="button"
                                disabled={busy}
                                onClick={() => void save(form => form.append('colour', colour))}
                                aria-label={`Barva ${colour}`}
                                style={{ background: colour }}
                                className={`h-8 w-8 rounded-full disabled:opacity-50 ${fallback.colour === colour ? 'ring-2 ring-[var(--color-text-primary)]' : ''}`}
                            />
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
