import { useViewportSafePanel } from '@/lib/useViewportSafePanel';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

export interface Gif {
    id: string;
    description: string;
    preview: string;
    url: string;
    width: number;
    height: number;
}

/**
 * GIF search, backed by whichever provider the operator configured.
 *
 * The picker hides itself entirely when no key is configured rather than showing a
 * search box that can never return anything. Previews are the small format; the full
 * GIF is only ever fetched once it has actually been sent.
 */
export default function GifPicker({ onPick, compact = false }: { onPick: (gif: Gif) => void; compact?: boolean }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Gif[]>([]);
    const [configured, setConfigured] = useState<boolean | null>(null);
    const [loading, setLoading] = useState(false);
    const panel = useViewportSafePanel(open);

    const search = useCallback(async (term: string) => {
        setLoading(true);
        try {
            const response = await axios.get('/api/v1/chat/gify', { params: { q: term || undefined } });
            setConfigured(response.data.configured !== false);
            setResults(response.data.results ?? []);
        } catch { setConfigured(false); }
        finally { setLoading(false); }
    }, []);

    // Ask once on mount purely to learn whether the button should exist at all.
    useEffect(() => { void search(''); }, [search]);

    useEffect(() => {
        if (!open) return;
        const handle = window.setTimeout(() => void search(query), query ? 350 : 0);
        return () => window.clearTimeout(handle);
    }, [open, query, search]);

    if (configured === false) return null;

    return (
        <div className="relative">
            <button
                type="button"
                aria-label="GIF"
                aria-expanded={open}
                onClick={() => setOpen(value => !value)}
                className={`flex items-center justify-center rounded-lg font-bold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)] ${compact ? 'h-9 w-9 text-[10px]' : 'h-11 w-11 text-xs'}`}
            >
                GIF
            </button>

            {open && (
                <>
                    <button type="button" aria-label="Zavřít GIFy" onClick={() => setOpen(false)} className="fixed inset-0 z-[700] cursor-default" />
                    <div
                        ref={panel.ref}
                        style={panel.style}
                        className="absolute bottom-full z-[710] mb-2 w-80 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 shadow-2xl"
                    >
                        <input
                            value={query}
                            onChange={event => setQuery(event.target.value)}
                            placeholder="Hledat GIF"
                            className="mb-2 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2.5 py-2 text-xs text-[var(--color-text-primary)]"
                        />

                        <div className="max-h-64 overflow-y-auto">
                            {loading && <div className="flex justify-center py-6"><Loader2 className="animate-spin text-[var(--color-accent)]" size={18} /></div>}
                            {!loading && results.length === 0 && (
                                <p className="py-6 text-center text-xs text-[var(--color-text-secondary)]">Nic nenalezeno.</p>
                            )}
                            <div className="grid grid-cols-2 gap-1">
                                {results.map(gif => (
                                    <button
                                        key={gif.id}
                                        type="button"
                                        onClick={() => { onPick(gif); setOpen(false); }}
                                        className="overflow-hidden rounded-lg border border-transparent hover:border-[var(--color-accent)]"
                                    >
                                        <img src={gif.preview} alt={gif.description} loading="lazy" className="h-24 w-full object-cover" />
                                    </button>
                                ))}
                            </div>
                        </div>

                        <p className="pt-1.5 text-center text-[9px] text-[var(--color-text-secondary)]">GIFy poskytuje Tenor nebo Giphy</p>
                    </div>
                </>
            )}
        </div>
    );
}
