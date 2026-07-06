/**
 * TV/Index.tsx — TV Mode: fullscreen slideshow optimized for TV display.
 * Large text, minimal UI, auto-advancing, designed for:
 * - Family gatherings, visits, celebrations
 * - Display on a TV connected to a laptop/phone
 */

import Slideshow, { type SlideshowItem } from '@/Components/Slideshow';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, Monitor, Tv } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function TvIndex() {
    const [items,   setItems]   = useState<SlideshowItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [started, setStarted] = useState(false);
    const [source,  setSource]  = useState<'all' | 'favorites' | 'rated'>('all');
    const [limit,   setLimit]   = useState(100);

    useEffect(() => {
        const params: Record<string, any> = { per_page: limit };
        if (source === 'favorites') params.is_favorite = 1;
        if (source === 'rated') params.min_rating = 4;

        axios.get('/api/v1/timeline', { params }).then(r => {
            const data = r.data?.data ?? [];
            const mapped: SlideshowItem[] = data.map((m: any) => {
                const thumb = m.variants?.find((v: any) => v.type === 'thumbnail')
                    ?? m.variants?.find((v: any) => v.type === 'small')
                    ?? m.variants?.[0];
                return {
                    uuid:          m.uuid,
                    media_type:    m.media_type,
                    thumb_url:     thumb?.url,
                    display_title: m.display_title,
                    taken_at:      m.taken_at,
                    latitude:      m.latitude,
                    longitude:     m.longitude,
                    rating:        m.rating,
                    people:        m.people?.map((p: any) => p.name) ?? [],
                };
            });
            setItems(mapped);
        }).finally(() => setLoading(false));
    }, [source, limit]);

    if (started && items.length > 0) {
        return (
            <>
                <Head title="TV Režim"/>
                <Slideshow
                    items={items}
                    tvMode
                    onClose={() => setStarted(false)}
                />
            </>
        );
    }

    return (
        <>
            <Head title="TV Režim"/>
            <div className="min-h-screen bg-black flex items-center justify-center p-8">
                <div className="max-w-sm w-full text-center">
                    <div className="w-20 h-20 rounded-2xl bg-[var(--color-accent)]/20 flex items-center justify-center mx-auto mb-6">
                        <Tv size={40} className="text-[var(--color-accent)]"/>
                    </div>

                    <h1 className="text-3xl font-bold text-white mb-2">TV Režim</h1>
                    <p className="text-[var(--color-text-secondary)] mb-8">
                        Spustí prezentaci fotografií na celou obrazovku — ideální pro televizi, rodinné oslavy nebo návštěvy.
                    </p>

                    {/* Source selection */}
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 mb-4 text-left space-y-3">
                        <p className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider">Zdroj fotografií</p>
                        {[
                            { key: 'all',       label: 'Všechny fotky', desc: 'Celá galerie' },
                            { key: 'favorites', label: '❤️ Oblíbené',    desc: 'Pouze oblíbené' },
                            { key: 'rated',     label: '★★★★ Hodnocené', desc: '4+ hvězdičky' },
                        ].map(opt => (
                            <label key={opt.key} className="flex items-center gap-3 cursor-pointer group">
                                <input type="radio" name="source" value={opt.key} checked={source === opt.key as any}
                                    onChange={() => setSource(opt.key as any)}
                                    className="accent-[var(--color-accent)] w-4 h-4"/>
                                <div>
                                    <p className="text-sm text-white">{opt.label}</p>
                                    <p className="text-[10px] text-[var(--color-text-secondary)]">{opt.desc}</p>
                                </div>
                            </label>
                        ))}

                        {/* Limit */}
                        <div className="pt-2 border-t border-[var(--color-border)]">
                            <p className="text-xs text-[var(--color-text-secondary)] mb-1.5">Počet fotografií: {limit}</p>
                            <input type="range" min={20} max={500} step={10} value={limit}
                                onChange={e => setLimit(parseInt(e.target.value))}
                                className="w-full accent-[var(--color-accent)]"/>
                        </div>
                    </div>

                    {/* Info */}
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-3 mb-6 text-left text-xs text-[var(--color-text-secondary)]">
                        <p className="text-white font-medium mb-1 flex items-center gap-1.5"><Monitor size={12}/> Ovládání</p>
                        <p>← → šipky — navigace · Mezerník — přehrávání</p>
                        <p>F — celá obrazovka · Esc — zavřít TV režim</p>
                    </div>

                    <button onClick={() => setStarted(true)} disabled={loading || items.length === 0}
                        className="w-full bg-[var(--color-accent)] text-white text-lg py-4 rounded-2xl hover:opacity-90 disabled:opacity-40 font-semibold flex items-center justify-center gap-3 transition-opacity">
                        {loading
                            ? <><Loader2 size={20} className="animate-spin"/> Načítám…</>
                            : <><Tv size={20}/> Spustit TV režim ({items.length} fotek)</>
                        }
                    </button>

                    <p className="text-[10px] text-[var(--color-text-secondary)] mt-4">
                        Tip: Zvětšete okno prohlížeče na celou obrazovku (F11) nebo zrcadlete obrazovku na TV přes HDMI.
                    </p>
                </div>
            </div>
        </>
    );
}
