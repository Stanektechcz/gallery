import { Link, router } from '@inertiajs/react';
import { clsx } from 'clsx';
import { Heart, Play, Star } from 'lucide-react';
import { memo } from 'react';

export interface MediaCardData {
    id: number;
    uuid: string;
    media_type: string;
    taken_at: string | null;
    trashed_at?: string | null;
    purge_after?: string | null;
    width: number | null;
    height: number | null;
    is_favorite?: boolean;
    rating?: number | null;
    display_title?: string;
    variants: Array<{
        type: string;
        url: string;
        dominant_color?: string | null;
        aspect_ratio?: number | null;
    }>;
}

interface Props {
    item: MediaCardData;
    selected?: boolean;
    onSelect?: (uuid: string, selected: boolean) => void;
    onAction?: (action: string, uuid: string) => void;
    badge?: React.ReactNode;
    href?: string;
}

export const MediaCard = memo(function MediaCard({ item, selected, onSelect, onAction, badge, href }: Props) {
    const thumb       = item.variants.find(v => v.type === 'thumbnail');
    const placeholder = item.variants.find(v => v.type === 'placeholder');
    const previewUrl  = thumb?.url ?? `/files/media/${item.uuid}/${item.media_type === 'video' ? 'video_poster.jpg' : 'thumbnail.jpg'}`;
    const aspect      = thumb?.aspect_ratio ?? (item.width && item.height ? item.width / item.height : 1);

    const handleClick = (e: React.MouseEvent) => {
        // Always navigate on normal click — selection is only via the checkbox
        router.visit(href ?? `/media/${item.uuid}`);
    };

    const handleCheckboxClick = (e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();
        if (onSelect) onSelect(item.uuid, !selected);
    };

    const content = (
        <div
            className={clsx(
                'relative group rounded overflow-hidden bg-[var(--color-bg-card)] cursor-pointer',
                selected && 'ring-2 ring-[var(--color-accent)]'
            )}
            // Let the browser skip paint/layout work for cards outside the
            // viewport. This is especially important for large shared albums.
            style={{
                aspectRatio: aspect,
                contentVisibility: 'auto',
                containIntrinsicSize: '160px 160px',
            }}
            onClick={onSelect || !href ? handleClick : undefined}
        >
            {placeholder?.dominant_color && (
                <div className="absolute inset-0" style={{ backgroundColor: placeholder.dominant_color }} />
            )}
            {previewUrl && (
                <img
                    src={previewUrl}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    fetchPriority="low"
                    draggable={false}
                    className="absolute inset-0 w-full h-full object-cover transition-opacity duration-300"
                />
            )}

            {/* Hover overlay */}
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/25 transition-colors" />

            {/* Selection checkbox */}
            {onSelect && (
                <div
                    className={clsx(
                        'absolute top-2 left-2 w-5 h-5 rounded border-2 flex items-center justify-center transition-all cursor-pointer z-10',
                        selected
                            ? 'bg-[var(--color-accent)] border-[var(--color-accent)]'
                            : 'bg-black/45 border-white/70 opacity-75 md:opacity-0 md:group-hover:opacity-100'
                    )}
                    onClick={handleCheckboxClick}
                >
                    {selected && <span className="text-white text-xs font-bold">✓</span>}
                </div>
            )}

            {/* Media type badge */}
            {item.media_type === 'video' && (
                <div className="absolute top-2 right-2 bg-black/60 rounded-full p-1">
                    <Play size={10} className="text-white fill-white" />
                </div>
            )}

            {/* Favorite indicator */}
            {item.is_favorite && (
                <div className="absolute bottom-2 left-2">
                    <Heart size={12} className="text-red-400 fill-red-400 drop-shadow" />
                </div>
            )}

            {/* Rating */}
            {item.rating && item.rating > 0 && (
                <div className="absolute bottom-2 right-2 bg-black/50 rounded px-1 flex items-center gap-0.5">
                    <Star size={10} className="text-yellow-400 fill-yellow-400" />
                    <span className="text-white text-[10px]">{item.rating}</span>
                </div>
            )}

            {/* Custom badge */}
            {badge}
        </div>
    );

    if (!onSelect && href) {
        return <Link href={href}>{content}</Link>;
    }
    return content;
});

interface GridProps {
    items: MediaCardData[];
    selected?: Set<string>;
    onSelect?: (uuid: string, selected: boolean) => void;
    onAction?: (action: string, uuid: string) => void;
    getHref?: (item: MediaCardData) => string;
    getBadge?: (item: MediaCardData) => React.ReactNode;
    emptyState?: React.ReactNode;
    columns?: string;
}

export function MediaGrid({
    items,
    selected,
    onSelect,
    onAction,
    getHref,
    getBadge,
    emptyState,
    columns = 'repeat(auto-fill, minmax(min(128px, 100%), 1fr))',
}: GridProps) {
    if (items.length === 0 && emptyState) return <>{emptyState}</>;

    return (
        <div className="min-w-0" style={{ display: 'grid', gridTemplateColumns: columns, gap: '4px' }}>
            {items.map(item => (
                <MediaCard
                    key={item.uuid}
                    item={item}
                    selected={selected?.has(item.uuid)}
                    onSelect={onSelect}
                    onAction={onAction}
                    href={getHref ? getHref(item) : `/media/${item.uuid}`}
                    badge={getBadge ? getBadge(item) : undefined}
                />
            ))}
        </div>
    );
}
