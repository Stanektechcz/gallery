import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { Grid3X3, Heart, Map, Play, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface MediaCard {
    id: number;
    uuid: string;
    media_type: 'photo' | 'video';
    taken_at: string | null;
    width: number | null;
    height: number | null;
    is_favorite: boolean;
    rating: number | null;
    primary_album?: { id: number; uuid: string; title: string; slug: string } | null;
    variants: Array<{ type: string; url: string; blur_hash?: string; dominant_color?: string; aspect_ratio?: number }>;
}

interface TimelineGroup {
    date: string;
    label: string;
    items: MediaCard[];
}

function MediaCardComponent({ item, onFavoriteToggle, onTrash }: {
    item: MediaCard;
    onFavoriteToggle: (uuid: string, current: boolean) => void;
    onTrash: (uuid: string) => void;
}) {
    const thumb = item.variants?.find(v => v.type === 'thumbnail')
               ?? item.variants?.find(v => v.type === 'original');
    const placeholder = item.variants?.find(v => v.type === 'placeholder');
    const aspect = thumb?.aspect_ratio ?? (item.width && item.height ? item.width / item.height : 1);
    const [hover, setHover] = useState(false);

    return (
        <div
            className="relative group cursor-pointer rounded overflow-hidden bg-[var(--color-bg-card)]"
            style={{ aspectRatio: aspect }}
            onClick={() => router.visit(`/media/${item.uuid}`)}
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
        >
            {placeholder?.dominant_color && (
                <div className="absolute inset-0" style={{ backgroundColor: placeholder.dominant_color }} />
            )}

            {thumb && (
                <img
                    src={thumb.url}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="absolute inset-0 w-full h-full object-cover transition-opacity duration-300"
                />
            )}

            {/* Hover overlay */}
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-colors" />

            {/* Video badge */}
            {item.media_type === 'video' && (
                <div className="absolute top-2 right-2 bg-black/60 rounded-full p-1">
                    <Play size={10} className="text-white fill-white" />
                </div>
            )}

            {/* Favorite indicator (always visible when favorited) */}
            {item.is_favorite && !hover && (
                <div className="absolute top-2 left-2">
                    <Heart size={12} className="text-red-400 fill-red-400" />
                </div>
            )}

            {/* Album path badge */}
            {item.primary_album && (
                <div className="absolute bottom-0 left-0 right-0 px-1.5 py-1 bg-gradient-to-t from-black/70 to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
                    <Link
                        href={`/albums/${item.primary_album.uuid}`}
                        onClick={e => e.stopPropagation()}
                        className="text-[9px] text-white/80 hover:text-white truncate block"
                    >
                        📁 {item.primary_album.title}
                    </Link>
                </div>
            )}

            {/* Hover actions */}
            <div className="absolute bottom-0 left-0 right-0 p-1.5 flex items-center justify-between opacity-0 group-hover:opacity-100 transition-opacity">
                <button
                    onClick={e => { e.stopPropagation(); onFavoriteToggle(item.uuid, item.is_favorite); }}
                    className="w-7 h-7 rounded-full bg-black/60 flex items-center justify-center hover:bg-black/80 transition-colors"
                    title={item.is_favorite ? 'Odebrat z oblíbených' : 'Přidat do oblíbených'}
                >
                    <Heart size={12} className={item.is_favorite ? 'text-red-400 fill-red-400' : 'text-white'} />
                </button>
                <button
                    onClick={e => { e.stopPropagation(); onTrash(item.uuid); }}
                    className="w-7 h-7 rounded-full bg-black/60 flex items-center justify-center hover:bg-red-500/80 transition-colors"
                    title="Přesunout do koše"
                >
                    <Trash2 size={12} className="text-white" />
                </button>
            </div>
        </div>
    );
}

function DateGroup({ group, onFavoriteToggle, onTrash }: {
    group: TimelineGroup;
    onFavoriteToggle: (uuid: string, current: boolean) => void;
    onTrash: (uuid: string) => void;
}) {
    return (
        <section className="mb-6">
            <div className="sticky top-0 z-10 py-3 px-4 bg-[var(--color-bg-primary)]/80 backdrop-blur-sm">
                <h2 className="text-sm font-semibold text-[var(--color-text-secondary)]">{group.label}</h2>
            </div>
            <div
                className="px-4"
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))',
                    gap: '4px',
                }}
            >
                {group.items.map(item => (
                    <MediaCardComponent key={item.id} item={item} onFavoriteToggle={onFavoriteToggle} onTrash={onTrash} />
                ))}
            </div>
        </section>
    );
}

function groupByDate(items: MediaCard[]): TimelineGroup[] {
    const groups: Record<string, MediaCard[]> = {};

    for (const item of items) {
        const date = item.taken_at
            ? new Date(item.taken_at).toLocaleDateString('cs-CZ', { year: 'numeric', month: 'long', day: 'numeric' })
            : 'Bez data';
        const key = item.taken_at ? item.taken_at.substring(0, 10) : '__nodate__';
        if (!groups[key]) groups[key] = [];
        groups[key].push(item);
    }

    return Object.entries(groups).map(([key, items]) => ({
        date: key,
        label: items[0].taken_at
            ? new Date(items[0].taken_at).toLocaleDateString('cs-CZ', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
            : 'Bez data',
        items,
    }));
}

export default function TimelineIndex() {
    const sentinelRef = useRef<HTMLDivElement>(null);
    const queryClient = useQueryClient();
    const [localItems, setLocalItems] = useState<Record<string, Partial<MediaCard>>>({});

    const toggleFavorite = async (uuid: string, current: boolean) => {
        setLocalItems(prev => ({ ...prev, [uuid]: { is_favorite: !current } }));
        try {
            await axios.post(`/api/v1/favorites/${uuid}/toggle`);
        } catch {
            setLocalItems(prev => ({ ...prev, [uuid]: { is_favorite: current } }));
        }
    };

    const trashItem = async (uuid: string) => {
        if (!confirm('P\u0159esunout do ko\u0161e?')) return;
        setLocalItems(prev => ({ ...prev, [uuid]: { ...prev[uuid], _trashed: true } as any }));
        try {
            await axios.delete(`/media/${uuid}`);
            queryClient.invalidateQueries({ queryKey: ['timeline'] });
        } catch (e: any) {
            setLocalItems(prev => { const n = { ...prev }; delete n[uuid]; return n; });
            alert(e?.response?.data?.message ?? 'Chyba p\u0159i p\u0159esunu');
        }
    };

    const {
        data,
        fetchNextPage,
        hasNextPage,
        isFetchingNextPage,
        isLoading,
    } = useInfiniteQuery({
        queryKey: ['timeline'],
        queryFn: async ({ pageParam }) => {
            const params: Record<string, string> = { per_page: '60' };
            if (pageParam) params.cursor = pageParam;
            const res = await axios.get('/api/v1/timeline', { params });
            return res.data;
        },
        initialPageParam: undefined as string | undefined,
        getNextPageParam: (lastPage) => lastPage.meta?.next_cursor ?? undefined,
    });

    // Infinite scroll sentinel
    useEffect(() => {
        const observer = new IntersectionObserver(
            entries => {
                if (entries[0].isIntersecting && hasNextPage && !isFetchingNextPage) {
                    fetchNextPage();
                }
            },
            { rootMargin: '300px' }
        );
        if (sentinelRef.current) observer.observe(sentinelRef.current);
        return () => observer.disconnect();
    }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

    const allItems: MediaCard[] = (data?.pages.flatMap(p => p.data) ?? [])
        .filter(item => !(localItems[item.uuid] as any)?._trashed)
        .map(item => ({ ...item, ...(localItems[item.uuid] ?? {}) }));
    const groups = groupByDate(allItems);

    return (
        <AppLayout>
            <Head title="Timeline" />

            <div className="min-h-full">
                {/* Header */}
                <div className="sticky top-0 z-20 px-4 py-3 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 backdrop-blur-sm flex items-center justify-between">
                    <h1 className="text-sm font-semibold text-white">Fotky</h1>
                    <div className="flex items-center gap-3">
                        <span className="text-xs text-[var(--color-text-secondary)]">{allItems.length} položek</span>
                        <div className="flex items-center gap-1 bg-[var(--color-bg-card)] rounded-lg p-0.5 border border-[var(--color-border)]">
                            <button className="p-1.5 rounded text-[var(--color-accent)] bg-[var(--color-accent)]/10" title="Mřížka">
                                <Grid3X3 size={14} />
                            </button>
                            <Link href="/map" className="p-1.5 rounded text-[var(--color-text-secondary)] hover:text-white transition-colors" title="Mapa">
                                <Map size={14} />
                            </Link>
                        </div>
                    </div>
                </div>

                {/* Skeleton loading */}
                {isLoading && (
                    <div className="grid grid-cols-4 gap-1 p-4 md:grid-cols-6">
                        {Array.from({ length: 24 }).map((_, i) => (
                            <div key={i} className="aspect-square rounded bg-[var(--color-bg-card)] animate-pulse" />
                        ))}
                    </div>
                )}

                {/* Groups */}
                {!isLoading && groups.length === 0 && (
                    <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                        <p className="text-lg mb-2">Zatím žádné fotky</p>
                        <p className="text-sm">Nahrajte první fotografie nebo videa</p>
                    </div>
                )}

                {groups.map(group => (
                    <DateGroup key={group.date} group={group} onFavoriteToggle={toggleFavorite} onTrash={trashItem} />
                ))}

                {/* Sentinel for infinite scroll */}
                <div ref={sentinelRef} className="h-16 flex items-center justify-center">
                    {isFetchingNextPage && (
                        <div className="w-5 h-5 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin" />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
