import { MediaCardData, MediaGrid } from '@/Components/MediaGrid';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Heart, X } from 'lucide-react';
import { useCallback, useState } from 'react';

interface Props {
    media: {
        data: MediaCardData[];
        current_page: number;
        last_page: number;
        total: number;
    };
}

export default function FavoritesIndex({ media }: Props) {
    const [selected, setSelected]  = useState<Set<string>>(new Set());
    const [items, setItems]        = useState<MediaCardData[]>(media.data);
    const [processing, setProc]    = useState(false);

    const toggleSelect = useCallback((uuid: string, sel: boolean) => {
        setSelected(prev => { const n = new Set(prev); sel ? n.add(uuid) : n.delete(uuid); return n; });
    }, []);

    const unfavoriteSelected = async () => {
        if (processing) return;
        setProc(true);
        const uuids = Array.from(selected);
        try {
            await Promise.all(uuids.map(uuid => axios.post(`/api/v1/favorites/${uuid}/toggle`)));
            setItems(prev => prev.filter(i => !uuids.includes(i.uuid)));
            setSelected(new Set());
        } finally {
            setProc(false);
        }
    };

    const toggleFavorite = async (uuid: string) => {
        const res = await axios.post(`/api/v1/favorites/${uuid}/toggle`);
        if (!res.data.is_favorite) {
            setItems(prev => prev.filter(i => i.uuid !== uuid));
        }
    };

    return (
        <AppLayout>
            <Head title="Oblíbené" />
            <div className="min-h-full">
                {/* Header */}
                <div className="sticky top-0 z-20 px-4 py-3 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 backdrop-blur-sm">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Heart size={16} className="text-red-400 fill-red-400" />
                            <h1 className="text-sm font-semibold text-white">Oblíbené</h1>
                            <span className="text-xs text-[var(--color-text-secondary)]">{items.length} položek</span>
                        </div>
                        {selected.size > 0 ? (
                            <div className="flex items-center gap-2">
                                <span className="text-xs text-[var(--color-text-secondary)]">{selected.size} vybráno</span>
                                <button
                                    onClick={unfavoriteSelected}
                                    disabled={processing}
                                    className="text-xs bg-red-500/20 hover:bg-red-500/30 disabled:opacity-50 text-red-400 px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1"
                                >
                                    <Heart size={12} /> Odebrat z oblíbených
                                </button>
                                <button onClick={() => setSelected(new Set())} className="text-[var(--color-text-secondary)] hover:text-white p-1">
                                    <X size={14} />
                                </button>
                            </div>
                        ) : (
                            items.length > 0 && (
                                <button
                                    onClick={() => setSelected(new Set(items.map(i => i.uuid)))}
                                    className="text-xs text-[var(--color-text-secondary)] hover:text-white"
                                >
                                    Vybrat vše
                                </button>
                            )
                        )}
                    </div>
                </div>

                <div className="p-4">
                    <MediaGrid
                        items={items}
                        selected={selected}
                        onSelect={toggleSelect}
                        getHref={i => `/media/${i.uuid}`}
                        emptyState={
                            <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                                <Heart size={48} className="mb-3 opacity-20" />
                                <p className="text-lg font-medium text-white mb-1">Žádné oblíbené</p>
                                <p className="text-sm">Označte fotky nebo videa srdíčkem ❤️ a zobrazí se zde</p>
                            </div>
                        }
                    />

                    {/* Pagination */}
                    {media.last_page > 1 && (
                        <div className="flex justify-center gap-2 mt-6">
                            {media.current_page > 1 && (
                                <button onClick={() => router.get('/favorites', { page: String(media.current_page - 1) })} className="px-4 py-2 text-sm bg-[var(--color-bg-card)] hover:bg-white/10 text-white rounded-lg">← Předchozí</button>
                            )}
                            <span className="px-4 py-2 text-sm text-[var(--color-text-secondary)]">{media.current_page} / {media.last_page}</span>
                            {media.current_page < media.last_page && (
                                <button onClick={() => router.get('/favorites', { page: String(media.current_page + 1) })} className="px-4 py-2 text-sm bg-[var(--color-bg-card)] hover:bg-white/10 text-white rounded-lg">Další →</button>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
