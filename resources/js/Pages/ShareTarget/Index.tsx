/**
 * ShareTarget/Index.tsx
 * PWA Web Share Target — album picker shown after sharing from phone gallery.
 * Lets the user choose where to save the shared photos/videos.
 */

import AppLayout from '@/Layouts/AppLayout';
import { uploadManager } from '@/lib/uploadManager';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    CheckCircle2, ChevronRight, FolderOpen, Image,
    Loader2, Share2, Video, X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface SharedFile {
    index: number;
    name:  string;
    mime:  string;
    size:  number;
}

interface AlbumNode {
    id:             number;
    uuid:           string;
    title:          string;
    parent_id:      number | null;
    depth:          number;
    media_count:    number;
    children?:      AlbumNode[];
}

interface Props {
    files: SharedFile[];
}

function formatBytes(b: number): string {
    if (b < 1024) return `${b} B`;
    if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)} KB`;
    return `${(b / 1024 ** 2).toFixed(1)} MB`;
}

// ── Recursive album tree node ──────────────────────────────────────────────
function AlbumTreeNode({
    album, selected, expanded, onSelect, onToggle,
}: {
    album:     AlbumNode;
    selected:  AlbumNode | null;
    expanded:  Set<number>;
    onSelect:  (a: AlbumNode) => void;
    onToggle:  (id: number) => void;
}) {
    const hasChildren = album.children && album.children.length > 0;
    const isSelected  = selected?.id === album.id;
    const isExpanded  = expanded.has(album.id);

    return (
        <div>
            <div
                className={`flex items-center gap-1.5 py-2 pr-3 rounded-lg cursor-pointer transition-all group ${isSelected ? 'bg-[var(--color-accent)]/15 text-white' : 'hover:bg-white/5 text-[var(--color-text-secondary)] hover:text-white'}`}
                style={{ paddingLeft: `${(album.depth + 1) * 14}px` }}
                onClick={() => onSelect(album)}>

                {/* Expand/collapse toggle */}
                {hasChildren ? (
                    <button
                        onClick={e => { e.stopPropagation(); onToggle(album.id); }}
                        className="shrink-0 p-0.5 hover:text-white transition-colors">
                        <ChevronRight size={13} className={`transition-transform ${isExpanded ? 'rotate-90' : ''}`}/>
                    </button>
                ) : (
                    <span className="w-[17px] shrink-0"/>
                )}

                <FolderOpen size={14} className={`shrink-0 ${isSelected ? 'text-[var(--color-accent)]' : ''}`}/>

                <span className="flex-1 text-sm font-medium truncate">{album.title}</span>

                {album.media_count > 0 && (
                    <span className="text-[10px] opacity-50 shrink-0">{album.media_count}</span>
                )}

                {isSelected && (
                    <CheckCircle2 size={15} className="text-[var(--color-accent)] shrink-0"/>
                )}
            </div>

            {isExpanded && hasChildren && (
                <div>
                    {album.children!.map(child => (
                        <AlbumTreeNode
                            key={child.id} album={child}
                            selected={selected} expanded={expanded}
                            onSelect={onSelect} onToggle={onToggle}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

// ── Main page ──────────────────────────────────────────────────────────────
export default function ShareTargetIndex({ files }: Props) {
    const [albums,       setAlbums]       = useState<AlbumNode[]>([]);
    const [selected,     setSelected]     = useState<AlbumNode | null>(null);
    const [expanded,     setExpanded]     = useState<Set<number>>(new Set());
    const [saving,       setSaving]       = useState(false);
    const [albumSearch,  setAlbumSearch]  = useState('');

    useEffect(() => {
        axios.get('/api/v1/albums/tree').then(r => {
            const tree: AlbumNode[] = r.data ?? [];
            setAlbums(tree);
            // Auto-expand first level
            const rootIds = tree.filter(a => !a.parent_id).map(a => a.id);
            setExpanded(new Set(rootIds));
        }).catch(() => {});
    }, []);

    const toggleExpand = (id: number) => {
        setExpanded(prev => {
            const n = new Set(prev);
            n.has(id) ? n.delete(id) : n.add(id);
            return n;
        });
    };

    const selectAlbum = (album: AlbumNode) => {
        setSelected(prev => prev?.id === album.id ? null : album);
        // Expand parent path
        if (album.children?.length) {
            setExpanded(prev => new Set([...prev, album.id]));
        }
    };

    // Flat search helper
    const flatAlbums = (nodes: AlbumNode[]): AlbumNode[] =>
        nodes.flatMap(n => [n, ...flatAlbums(n.children ?? [])]);

    const filteredFlat = albumSearch.trim()
        ? flatAlbums(albums).filter(a => a.title.toLowerCase().includes(albumSearch.toLowerCase()))
        : null;

    const handleSave = async () => {
        if (!selected || saving) return;
        setSaving(true);

        try {
            // Fetch each temp file from the server and create File objects
            const fileObjects: File[] = [];
            for (const f of files) {
                const res  = await fetch(`/share-target/file/${f.index}`, { credentials: 'same-origin' });
                if (!res.ok) throw new Error(`Failed to fetch ${f.name}`);
                const blob = await res.blob();
                fileObjects.push(new File([blob], f.name, { type: f.mime }));
            }

            // Enqueue to upload manager
            uploadManager.enqueue(fileObjects, selected.id);

            // Clean up server session
            await axios.delete('/share-target').catch(() => {});

            // Navigate to the selected album
            router.visit(`/albums/${selected.uuid}`);
        } catch (err: any) {
            alert('Chyba při přípravě souborů: ' + (err?.message ?? err));
            setSaving(false);
        }
    };

    const handleCancel = async () => {
        await axios.delete('/share-target').catch(() => {});
        router.visit('/timeline');
    };

    if (files.length === 0) {
        return (
            <AppLayout>
                <Head title="Sdílení" />
                <div className="flex items-center justify-center h-full text-[var(--color-text-secondary)]">
                    <div className="text-center max-w-xs">
                        <Share2 size={40} className="mx-auto mb-3 opacity-20"/>
                        <p className="text-sm font-medium">Žádné sdílené soubory</p>
                        <p className="text-xs mt-1 opacity-60">Sdílejte fotky z galerie telefonu přímo do aplikace.</p>
                        <button onClick={() => router.visit('/timeline')}
                            className="mt-4 text-sm text-[var(--color-accent)] hover:underline">
                            Zpět na galerii
                        </button>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Kam uložit?" />
            <div className="max-w-lg mx-auto px-4 py-6 h-full flex flex-col gap-5">

                {/* Header */}
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-bold text-white flex items-center gap-2">
                            <Share2 size={18} className="text-[var(--color-accent)]"/>
                            Sdílení do galerie
                        </h1>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
                            {files.length} {files.length === 1 ? 'soubor' : files.length < 5 ? 'soubory' : 'souborů'} z telefonu
                        </p>
                    </div>
                    <button onClick={handleCancel} className="p-1.5 text-[var(--color-text-secondary)] hover:text-white">
                        <X size={18}/>
                    </button>
                </div>

                {/* Shared file thumbnails */}
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl overflow-hidden">
                    <div className="px-4 py-2.5 border-b border-[var(--color-border)]">
                        <p className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider">Soubory</p>
                    </div>
                    <div className="divide-y divide-[var(--color-border)] max-h-40 overflow-y-auto">
                        {files.map(f => {
                            const isVideo = f.mime.startsWith('video/');
                            const isImage = f.mime.startsWith('image/');
                            return (
                                <div key={f.index} className="flex items-center gap-3 px-4 py-2.5">
                                    {/* Thumbnail or icon */}
                                    <div className="w-10 h-10 rounded-lg bg-[var(--color-bg-secondary)] flex items-center justify-center shrink-0 overflow-hidden">
                                        {isImage ? (
                                            <img
                                                src={`/share-target/file/${f.index}`}
                                                alt=""
                                                className="w-full h-full object-cover"
                                                onError={e => { (e.target as HTMLImageElement).style.display = 'none'; }}
                                            />
                                        ) : isVideo ? (
                                            <Video size={18} className="text-[var(--color-text-secondary)]"/>
                                        ) : (
                                            <Image size={18} className="text-[var(--color-text-secondary)]"/>
                                        )}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm text-white truncate">{f.name}</p>
                                        <p className="text-[10px] text-[var(--color-text-secondary)]">
                                            {formatBytes(f.size)} · {f.mime.split('/')[1]?.toUpperCase() ?? f.mime}
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Album picker */}
                <div className="flex-1 flex flex-col bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl overflow-hidden min-h-0">
                    <div className="px-4 py-2.5 border-b border-[var(--color-border)] shrink-0">
                        <p className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Kam uložit?</p>
                        {/* Search */}
                        <input
                            value={albumSearch}
                            onChange={e => setAlbumSearch(e.target.value)}
                            placeholder="Hledat album…"
                            className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-1.5 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"
                        />
                    </div>

                    {albums.length === 0 ? (
                        <div className="flex-1 flex items-center justify-center">
                            <Loader2 size={20} className="text-[var(--color-accent)] animate-spin"/>
                        </div>
                    ) : (
                        <div className="flex-1 overflow-y-auto py-1">
                            {filteredFlat ? (
                                /* Search results — flat list */
                                filteredFlat.length === 0 ? (
                                    <p className="px-4 py-6 text-center text-xs text-[var(--color-text-secondary)]">Žádné album nenalezeno</p>
                                ) : filteredFlat.map(album => (
                                    <div key={album.id}
                                        className={`flex items-center gap-2 px-4 py-2 cursor-pointer transition-colors ${selected?.id === album.id ? 'bg-[var(--color-accent)]/15 text-white' : 'hover:bg-white/5 text-[var(--color-text-secondary)] hover:text-white'}`}
                                        onClick={() => setSelected(prev => prev?.id === album.id ? null : album)}>
                                        <FolderOpen size={14}/>
                                        <span className="flex-1 text-sm">{album.title}</span>
                                        {selected?.id === album.id && <CheckCircle2 size={14} className="text-[var(--color-accent)]"/>}
                                    </div>
                                ))
                            ) : (
                                /* Tree view */
                                albums.map(album => (
                                    <AlbumTreeNode
                                        key={album.id} album={album}
                                        selected={selected} expanded={expanded}
                                        onSelect={selectAlbum} onToggle={toggleExpand}
                                    />
                                ))
                            )}
                        </div>
                    )}

                    {/* Selected album breadcrumb */}
                    {selected && (
                        <div className="shrink-0 px-4 py-2.5 border-t border-[var(--color-border)] bg-[var(--color-accent)]/5">
                            <p className="text-[10px] text-[var(--color-text-secondary)]">Vybráno:</p>
                            <p className="text-sm font-semibold text-[var(--color-accent)]">📁 {selected.title}</p>
                        </div>
                    )}
                </div>

                {/* Action buttons */}
                <div className="flex gap-3 shrink-0">
                    <button onClick={handleCancel} disabled={saving}
                        className="flex-1 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-sm py-3 rounded-xl transition-colors disabled:opacity-40">
                        Zrušit
                    </button>
                    <button onClick={handleSave} disabled={!selected || saving}
                        className="flex-[2] bg-[var(--color-accent)] text-white text-sm py-3 rounded-xl hover:opacity-90 disabled:opacity-40 transition-opacity flex items-center justify-center gap-2">
                        {saving ? (
                            <><Loader2 size={16} className="animate-spin"/> Připravuji…</>
                        ) : (
                            <>Uložit {selected ? `do „${selected.title}"` : 'do alba'}</>
                        )}
                    </button>
                </div>
            </div>
        </AppLayout>
    );
}
