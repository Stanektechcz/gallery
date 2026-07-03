import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Clock, FolderOpen, Image } from 'lucide-react';

interface MediaItem {
    id: number;
    uuid: string;
    media_type: string;
    taken_at: string | null;
    variants: Array<{ type: string; url: string; dominant_color?: string }>;
    is_favorite: boolean;
}

interface Album {
    id: number;
    uuid: string;
    title: string;
    description?: string;
    event_date_start?: string;
    event_date_end?: string;
    media_count: number;
    descendant_count: number;
    sync_status: string;
    color?: string;
    cover?: { variants: Array<{ type: string; url: string }> } | null;
}

interface BreadcrumbItem {
    id: number;
    uuid: string;
    title: string;
    slug: string;
}

interface Props {
    album: Album;
    breadcrumb: BreadcrumbItem[];
    children: Album[];
    media: {
        data: MediaItem[];
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
}

export default function AlbumShow({ album, breadcrumb, children, media }: Props) {
    return (
        <AppLayout>
            <Head title={album.title} />

            <div className="p-4">
                {/* Breadcrumb */}
                <nav className="flex items-center gap-1 text-xs text-[var(--color-text-secondary)] mb-4 flex-wrap">
                    <Link href="/albums" className="hover:text-white transition-colors">Alba</Link>
                    {breadcrumb.slice(0, -1).map(crumb => (
                        <span key={crumb.id} className="flex items-center gap-1">
                            <ChevronRight size={12} />
                            <Link href={`/albums/${crumb.uuid}`} className="hover:text-white transition-colors">
                                {crumb.title}
                            </Link>
                        </span>
                    ))}
                    <span className="flex items-center gap-1">
                        <ChevronRight size={12} />
                        <span className="text-white font-medium">{album.title}</span>
                    </span>
                </nav>

                {/* Album header */}
                <div className="flex items-start justify-between mb-6">
                    <div>
                        <h1 className="text-xl font-semibold text-white mb-1">{album.title}</h1>
                        {album.description && (
                            <p className="text-sm text-[var(--color-text-secondary)]">{album.description}</p>
                        )}
                        <div className="flex items-center gap-4 mt-2 text-xs text-[var(--color-text-secondary)]">
                            {album.media_count > 0 && (
                                <span className="flex items-center gap-1">
                                    <Image size={12} /> {album.media_count} médií
                                </span>
                            )}
                            {album.descendant_count > 0 && (
                                <span className="flex items-center gap-1">
                                    <FolderOpen size={12} /> {album.descendant_count} alb
                                </span>
                            )}
                            {album.event_date_start && (
                                <span className="flex items-center gap-1">
                                    <Clock size={12} />
                                    {new Date(album.event_date_start).toLocaleDateString('cs-CZ')}
                                </span>
                            )}
                            {album.sync_status === 'pending' && (
                                <span className="flex items-center gap-1 text-yellow-400">
                                    <span className="w-1.5 h-1.5 rounded-full bg-yellow-400 animate-pulse" />
                                    Synchronizace čeká
                                </span>
                            )}
                            {album.sync_status === 'synced' && (
                                <span className="text-green-400">✓ Synchronizováno</span>
                            )}
                        </div>
                    </div>
                </div>

                {/* Sub-albums */}
                {children.length > 0 && (
                    <section className="mb-8">
                        <h2 className="text-sm font-medium text-[var(--color-text-secondary)] mb-3 uppercase tracking-wider">
                            Podalba
                        </h2>
                        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                            {children.map(child => {
                                const thumb = child.cover?.variants?.find(v => v.type === 'thumbnail');
                                return (
                                    <Link
                                        key={child.id}
                                        href={`/albums/${child.uuid}`}
                                        className="group relative rounded-xl overflow-hidden bg-[var(--color-bg-card)] border border-[var(--color-border)] hover:border-[var(--color-accent)]/50 transition-all"
                                    >
                                        <div className="aspect-video bg-[var(--color-bg-secondary)] relative overflow-hidden">
                                            {thumb ? (
                                                <img src={thumb.url} alt={child.title} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                                            ) : (
                                                <div className="w-full h-full flex items-center justify-center">
                                                    <FolderOpen size={32} className="text-[var(--color-text-secondary)]" style={{ color: child.color ?? undefined }} />
                                                </div>
                                            )}
                                            {child.sync_status === 'pending' && (
                                                <div className="absolute top-2 right-2 w-2 h-2 rounded-full bg-yellow-400 animate-pulse" />
                                            )}
                                        </div>
                                        <div className="p-2.5">
                                            <p className="text-sm font-medium text-white truncate">{child.title}</p>
                                            <p className="text-xs text-[var(--color-text-secondary)]">
                                                {child.media_count > 0 ? `${child.media_count} médií` : 'Prázdné'}
                                            </p>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    </section>
                )}

                {/* Media grid */}
                {media.data.length > 0 ? (
                    <section>
                        <h2 className="text-sm font-medium text-[var(--color-text-secondary)] mb-3 uppercase tracking-wider">
                            Média
                        </h2>
                        <div
                            style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: '4px' }}
                        >
                            {media.data.map(item => {
                                const thumb = item.variants?.find(v => v.type === 'thumbnail');
                                const placeholder = item.variants?.find(v => v.type === 'placeholder');
                                return (
                                    <Link
                                        key={item.id}
                                        href={`/media/${item.uuid}`}
                                        className="relative group aspect-square rounded overflow-hidden bg-[var(--color-bg-card)]"
                                    >
                                        {placeholder?.dominant_color && (
                                            <div className="absolute inset-0" style={{ backgroundColor: placeholder.dominant_color }} />
                                        )}
                                        {thumb && (
                                            <img src={thumb.url} alt="" loading="lazy" className="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                                        )}
                                    </Link>
                                );
                            })}
                        </div>

                        {media.last_page > 1 && (
                            <p className="text-xs text-center text-[var(--color-text-secondary)] mt-4">
                                Celkem {media.total} médií
                            </p>
                        )}
                    </section>
                ) : (
                    children.length === 0 && (
                        <div className="flex flex-col items-center justify-center h-48 text-[var(--color-text-secondary)]">
                            <FolderOpen size={40} className="mb-3 opacity-30" />
                            <p>Album je prázdné</p>
                            <p className="text-sm mt-1">Nahrajte fotky nebo vytvořte podalba</p>
                        </div>
                    )
                )}
            </div>
        </AppLayout>
    );
}
