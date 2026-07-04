import { Link, router } from '@inertiajs/react';
import { clsx } from 'clsx';
import { Heart, Play, Star } from 'lucide-react';

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

export function MediaCard({ item, selected, onSelect, onAction, badge, href }: Props) {
    const thumb       = item.variants.find(v => v.type === 'thumbnail');
    const placeholder = item.variants.find(v => v.type === 'placeholder');
    const aspect      = thumb?.aspect_ratio ?? (item.width && item.height ? item.width / item.height : 1);

    const handleClick = (e: React.MouseEvent) => {
        if (onSelect && (e.ctrlKey || e.metaKey || e.shiftKey)) {
            // Ctrl/Cmd/Shift+click = toggle selection
            e.preventDefault();
            onSelect(item.uuid, !selected);
        } else if (onSelect && selected) {
            // If already selected, toggle off
            e.preventDefault();
            onSelect(item.uuid, false);
        } else {
            // Regular click = navigate
            router.visit(href ?? `/media/${item.uuid}`);
        }
    };

    const content = (
        <div
            className={clsx(
                'relative group rounded overflow-hidden bg-[var(--color-bg-card)] cursor-pointer',
                selected && 'ring-2 ring-[var(--color-accent)]'
            )}
            style={{ aspectRatio: aspect }}
            onClick={handleClick}
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
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/25 transition-colors" />

            {/* Selection checkbox */}
            {onSelect && (
                <div className={clsx(
                    'absolute top-2 left-2 w-5 h-5 rounded border-2 flex items-center justify-center transition-all',
                    selected
                        ? 'bg-[var(--color-accent)] border-[var(--color-accent)]'
                        : 'bg-black/40 border-white/60 opacity-0 group-hover:opacity-100'
                )}>
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
}

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
    columns = 'repeat(auto-fill, minmax(160px, 1fr))',
}: GridProps) {
    if (items.length === 0 && emptyState) return <>{emptyState}</>;

    return (
        <div style={{ display: 'grid', gridTemplateColumns: columns, gap: '4px' }}>
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
