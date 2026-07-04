import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useInfiniteQuery } from '@tanstack/react-query';
import axios from 'axios';
import { Heart, Play } from 'lucide-react';
import { useEffect, useRef } from 'react';

interface MediaCard {
    id: number;
    uuid: string;
    media_type: 'photo' | 'video';
    taken_at: string | null;
    width: number | null;
    height: number | null;
    is_favorite: boolean;
    rating: number | null;
    variants: Array<{ type: string; url: string; blur_hash?: string; dominant_color?: string; aspect_ratio?: number }>;
}

interface TimelineGroup {
    date: string;
    label: string;
    items: MediaCard[];
}

function MediaCardComponent({ item }: { item: MediaCard }) {
    const thumb = item.variants?.find(v => v.type === 'thumbnail')
               ?? item.variants?.find(v => v.type === 'original');
    const placeholder = item.variants?.find(v => v.type === 'placeholder');
    const aspect = thumb?.aspect_ratio ?? (item.width && item.height ? item.width / item.height : 1);

    return (
        <div
            className="relative group cursor-pointer rounded overflow-hidden bg-[var(--color-bg-card)]"
            style={{ aspectRatio: aspect }}
            onClick={() => router.visit(`/media/${item.uuid}`)}
        >
            {/* Placeholder color */}
            {placeholder?.dominant_color && (
                <div
                    className="absolute inset-0"
                    style={{ backgroundColor: placeholder.dominant_color }}
                />
            )}

            {/* Thumbnail */}
            {thumb && (
                <img
                    src={thumb.url}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="absolute inset-0 w-full h-full object-cover transition-opacity duration-300"
                />
            )}

            {/* Overlay */}
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors" />

            {/* Video badge */}
            {item.media_type === 'video' && (
                <div className="absolute top-2 right-2 bg-black/60 rounded-full p-1">
                    <Play size={10} className="text-white fill-white" />
                </div>
            )}

            {/* Favorite */}
            {item.is_favorite && (
                <div className="absolute top-2 left-2">
                    <Heart size={12} className="text-red-400 fill-red-400" />
                </div>
            )}
        </div>
    );
}

function DateGroup({ group }: { group: TimelineGroup }) {
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
                    <MediaCardComponent key={item.id} item={item} />
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

    const allItems: MediaCard[] = data?.pages.flatMap(p => p.data) ?? [];
    const groups = groupByDate(allItems);

    return (
        <AppLayout>
            <Head title="Timeline" />

            <div className="min-h-full">
                {/* Header */}
                <div className="sticky top-0 z-20 px-4 py-3 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 backdrop-blur-sm flex items-center justify-between">
                    <h1 className="text-sm font-semibold text-white">Fotky</h1>
                    <div className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                        <span>{allItems.length} položek</span>
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
                    <DateGroup key={group.date} group={group} />
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
