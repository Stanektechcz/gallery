import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { FolderOpen, Image } from 'lucide-react';

interface AlbumNode {
    id: number;
    uuid: string;
    title: string;
    slug: string;
    depth: number;
    color: string | null;
    icon: string | null;
    media_count: number;
    descendant_count: number;
    sync_status: string;
    children?: AlbumNode[];
    cover?: { variants: Array<{ type: string; url: string }> } | null;
}

interface Props {
    albums: AlbumNode[];
}

function AlbumCard({ album }: { album: AlbumNode }) {
    const thumbUrl = album.cover?.variants?.find(v => v.type === 'thumbnail')?.url;

    return (
        <Link
            href={`/albums/${album.uuid}`}
            className="group relative rounded-xl overflow-hidden bg-[var(--color-bg-card)] border border-[var(--color-border)] hover:border-[var(--color-accent)]/50 transition-all hover:shadow-lg hover:shadow-[var(--color-accent)]/10"
        >
            {/* Cover */}
            <div className="aspect-video bg-[var(--color-bg-secondary)] relative overflow-hidden">
                {thumbUrl ? (
                    <img
                        src={thumbUrl}
                        alt={album.title}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center">
                        <FolderOpen
                            size={40}
                            className="text-[var(--color-text-secondary)]"
                            style={{ color: album.color ?? undefined }}
                        />
                    </div>
                )}

                {/* Sync status badge */}
                {album.sync_status === 'pending' && (
                    <div className="absolute top-2 right-2 w-2 h-2 rounded-full bg-yellow-400 animate-pulse" title="Synchronizace čeká" />
                )}
                {album.sync_status === 'failed' && (
                    <div className="absolute top-2 right-2 w-2 h-2 rounded-full bg-red-400" title="Chyba synchronizace" />
                )}
            </div>

            {/* Info */}
            <div className="p-3">
                <h3 className="font-medium text-sm text-white truncate">{album.title}</h3>
                <div className="flex items-center gap-3 mt-1 text-xs text-[var(--color-text-secondary)]">
                    {album.media_count > 0 && (
                        <span className="flex items-center gap-1">
                            <Image size={10} />
                            {album.media_count}
                        </span>
                    )}
                    {album.descendant_count > 0 && (
                        <span className="flex items-center gap-1">
                            <FolderOpen size={10} />
                            {album.descendant_count} alb
                        </span>
                    )}
                </div>
            </div>
        </Link>
    );
}

export default function AlbumsIndex({ albums }: Props) {
    return (
        <AppLayout>
            <Head title="Alba" />

            <div className="p-4">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <h1 className="text-lg font-semibold text-white">Alba</h1>
                    <Link
                        href="/albums/create"
                        className="bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)] text-white text-sm px-3 py-1.5 rounded-lg transition-colors"
                    >
                        + Nové album
                    </Link>
                </div>

                {albums.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                        <FolderOpen size={48} className="mb-3 opacity-30" />
                        <p className="text-lg mb-1">Zatím žádná alba</p>
                        <p className="text-sm">Vytvořte první album pro organizaci fotek</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        {albums.map(album => (
                            <AlbumCard key={album.id} album={album} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
