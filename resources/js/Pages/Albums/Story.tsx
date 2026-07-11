/**
 * Album Story — block-based story editor and viewer.
 * Blocks: heading, text, quote, photo, video, map, divider
 */

import axios from 'axios';
import { addLocalizedBaseLayer } from '@/lib/localizedMap';
import {
    ChevronDown, ChevronUp, Edit3,
    Plus,
    Trash2, Type
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

// ── Types ─────────────────────────────────────────────────────────────────

export type BlockType = 'heading' | 'text' | 'quote' | 'photo' | 'video' | 'map' | 'divider';

export interface StoryBlock {
    id:          number;
    type:        BlockType;
    sort_order:  number;
    content:     Record<string, any>;
    media?:      Array<{ uuid: string; thumb_url: string; full_url: string; stream_url?: string; poster_url?: string }>;
}

interface AlbumMedia { uuid: string; thumb_url: string; }

// ── Block pickers ─────────────────────────────────────────────────────────

const BLOCK_TYPES: Array<{ type: BlockType; emoji: string; label: string }> = [
    { type: 'heading',  emoji: 'H',  label: 'Nadpis' },
    { type: 'text',     emoji: '📝', label: 'Text' },
    { type: 'quote',    emoji: '💬', label: 'Citát' },
    { type: 'photo',    emoji: '📷', label: 'Fotografie' },
    { type: 'video',    emoji: '🎬', label: 'Video' },
    { type: 'map',      emoji: '📍', label: 'Mapa' },
    { type: 'divider',  emoji: '—',  label: 'Oddělovač' },
];

// ── Read-only block renderers ─────────────────────────────────────────────

function HeadingBlock({ content }: { content: Record<string, any> }) {
    const level = content.level ?? 2;
    const cls = level === 1 ? 'text-3xl font-bold text-white' : level === 2 ? 'text-2xl font-bold text-white' : 'text-xl font-semibold text-white';
    return <div className={`${cls} leading-tight`}>{content.text || 'Nadpis'}</div>;
}

function TextBlock({ content }: { content: Record<string, any> }) {
    return (
        <div className="text-[var(--color-text-secondary)] leading-relaxed text-base whitespace-pre-wrap">
            {content.body || ''}
        </div>
    );
}

function QuoteBlock({ content }: { content: Record<string, any> }) {
    return (
        <blockquote className="border-l-4 border-[var(--color-accent)] pl-5 py-1 my-1">
            <p className="text-lg italic text-white leading-relaxed">„{content.quote || ''}"</p>
            {content.author && (
                <cite className="text-sm text-[var(--color-text-secondary)] mt-1 block not-italic">— {content.author}</cite>
            )}
        </blockquote>
    );
}

function PhotoBlock({ block }: { block: StoryBlock }) {
    const layout  = block.content.layout ?? 'single';
    const caption = block.content.caption;
    const items   = block.media ?? [];
    if (!items.length) return null;

    const gridCls = layout === 'grid2' ? 'grid grid-cols-2 gap-1' : layout === 'grid3' ? 'grid grid-cols-3 gap-1' : '';

    return (
        <div>
            {layout === 'single' ? (
                <a href={`/media/${items[0].uuid}`} target="_blank" rel="noopener noreferrer" className="block">
                    <img src={items[0].full_url} alt="" className="w-full rounded-xl object-cover max-h-[70vh]" loading="lazy"/>
                </a>
            ) : (
                <div className={gridCls}>
                    {items.map(m => (
                        <a key={m.uuid} href={`/media/${m.uuid}`} target="_blank" rel="noopener noreferrer">
                            <img src={m.thumb_url} alt="" className="w-full aspect-square object-cover rounded-lg" loading="lazy"/>
                        </a>
                    ))}
                </div>
            )}
            {caption && <p className="text-sm text-[var(--color-text-secondary)] text-center mt-2 italic">{caption}</p>}
        </div>
    );
}

function VideoBlock({ block }: { block: StoryBlock }) {
    const m = block.media?.[0];
    if (!m?.stream_url) return null;
    return (
        <div>
            <video controls className="w-full rounded-xl" poster={m.poster_url} preload="metadata">
                <source src={m.stream_url} type="video/mp4"/>
            </video>
            {block.content.caption && <p className="text-sm text-[var(--color-text-secondary)] text-center mt-2 italic">{block.content.caption}</p>}
        </div>
    );
}

function MapBlock({ content }: { content: Record<string, any> }) {
    const mapRef  = useRef<HTMLDivElement>(null);
    const [ready, setReady] = useState(!!(window as any).L);

    useEffect(() => {
        if ((window as any).L) { setReady(true); return; }
        const link = document.createElement('link'); link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(link);
        const s = document.createElement('script'); s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        s.onload = () => setReady(true); document.head.appendChild(s);
    }, []);

    useEffect(() => {
        if (!ready || !mapRef.current || !content.latitude || !content.longitude) return;
        const L   = (window as any).L;
        const map = L.map(mapRef.current, {
            center: [content.latitude, content.longitude],
            zoom:   content.zoom ?? 14,
            zoomControl: false, attributionControl: false,
            dragging: false, touchZoom: false, scrollWheelZoom: false,
        });
        addLocalizedBaseLayer(L, map);
        L.marker([content.latitude, content.longitude]).addTo(map);
        return () => { try { map.remove(); } catch {} };
    }, [ready, content.latitude, content.longitude, content.zoom]);

    if (!content.latitude || !content.longitude) {
        return <div className="h-40 rounded-xl bg-[var(--color-bg-secondary)] flex items-center justify-center text-[var(--color-text-secondary)] text-sm">Žádné GPS souřadnice</div>;
    }

    return (
        <div>
            <a href={`https://www.google.com/maps?q=${content.latitude},${content.longitude}`} target="_blank" rel="noopener noreferrer"
                className="block rounded-xl overflow-hidden group relative">
                <div ref={mapRef} className="h-48 w-full pointer-events-none"/>
                <div className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/20">
                    <span className="text-xs text-white bg-black/60 px-2 py-1 rounded">Otevřít Google Maps ↗</span>
                </div>
            </a>
            {content.label && <p className="text-sm text-[var(--color-text-secondary)] text-center mt-2">📍 {content.label}</p>}
        </div>
    );
}

function DividerBlock() {
    return <hr className="border-[var(--color-border)] my-0"/>;
}

// ── Block wrapper (edit mode) ─────────────────────────────────────────────

function EditableBlock({
    block, albumUuid, albumMedia, isFirst, isLast,
    onUpdate, onDelete, onMoveUp, onMoveDown,
}: {
    block: StoryBlock; albumUuid: string; albumMedia: AlbumMedia[];
    isFirst: boolean; isLast: boolean;
    onUpdate: (id: number, content: Record<string,any>) => void;
    onDelete: (id: number) => void;
    onMoveUp: (id: number) => void;
    onMoveDown: (id: number) => void;
}) {
    const [editing, setEditing] = useState(block.content && Object.keys(block.content).length === 0 && block.type !== 'divider');
    const [draft,   setDraft]   = useState<Record<string,any>>(block.content ?? {});

    const save = useCallback(async () => {
        await axios.patch(`/api/v1/albums/${albumUuid}/story/${block.id}`, { content: draft });
        onUpdate(block.id, draft);
        setEditing(false);
    }, [albumUuid, block.id, draft, onUpdate]);

    return (
        <div className="relative group/block">
            {/* Action toolbar */}
            <div className="absolute -right-10 top-0 flex flex-col gap-0.5 opacity-0 group-hover/block:opacity-100 transition-opacity">
                {!isFirst  && <button onClick={() => onMoveUp(block.id)}   className="p-1 rounded bg-[var(--color-bg-card)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white"><ChevronUp size={12}/></button>}
                {!isLast   && <button onClick={() => onMoveDown(block.id)} className="p-1 rounded bg-[var(--color-bg-card)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white"><ChevronDown size={12}/></button>}
                {block.type !== 'divider' && <button onClick={() => setEditing(v=>!v)} className="p-1 rounded bg-[var(--color-bg-card)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-accent)]"><Edit3 size={12}/></button>}
                <button onClick={() => onDelete(block.id)} className="p-1 rounded bg-[var(--color-bg-card)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-red-400"><Trash2 size={12}/></button>
            </div>

            {/* Block content */}
            <div className={editing ? 'ring-1 ring-[var(--color-accent)] rounded-xl p-3 bg-[var(--color-bg-card)]' : ''}>
                {editing
                    ? <BlockEditor type={block.type} draft={draft} setDraft={setDraft} albumMedia={albumMedia} onSave={save} onCancel={() => setEditing(false)}/>
                    : <BlockView block={block}/>
                }
            </div>
        </div>
    );
}

function BlockView({ block }: { block: StoryBlock }) {
    switch (block.type) {
        case 'heading':  return <HeadingBlock content={block.content}/>;
        case 'text':     return <TextBlock    content={block.content}/>;
        case 'quote':    return <QuoteBlock   content={block.content}/>;
        case 'photo':    return <PhotoBlock   block={block}/>;
        case 'video':    return <VideoBlock   block={block}/>;
        case 'map':      return <MapBlock     content={block.content}/>;
        case 'divider':  return <DividerBlock/>;
        default:         return null;
    }
}

// ── Block editors ─────────────────────────────────────────────────────────

function BlockEditor({ type, draft, setDraft, albumMedia, onSave, onCancel }: {
    type: BlockType; draft: Record<string,any>; setDraft: (d: Record<string,any>) => void;
    albumMedia: AlbumMedia[]; onSave: () => void; onCancel: () => void;
}) {
    const inp = (key: string) => ({
        value: draft[key] ?? '',
        onChange: (e: React.ChangeEvent<HTMLInputElement|HTMLTextAreaElement|HTMLSelectElement>) =>
            setDraft({ ...draft, [key]: e.target.value }),
    });

    const fieldCls = 'w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]';

    const actions = (
        <div className="flex gap-2 mt-3">
            <button onClick={onSave}   className="bg-[var(--color-accent)] text-white text-xs px-4 py-1.5 rounded-lg hover:opacity-90">Uložit</button>
            <button onClick={onCancel} className="border border-[var(--color-border)] text-[var(--color-text-secondary)] text-xs px-4 py-1.5 rounded-lg hover:text-white">Zrušit</button>
        </div>
    );

    if (type === 'heading') return (
        <div className="space-y-2">
            <select {...inp('level')} className={fieldCls} value={draft.level ?? 2}
                onChange={e => setDraft({ ...draft, level: parseInt(e.target.value) })}>
                <option value={1}>H1 — Hlavní nadpis</option>
                <option value={2}>H2 — Nadpis sekce</option>
                <option value={3}>H3 — Podnadpis</option>
            </select>
            <input {...inp('text')} placeholder="Text nadpisu…" className={fieldCls}/>
            {actions}
        </div>
    );

    if (type === 'text') return (
        <div className="space-y-2">
            <textarea {...inp('body')} placeholder="Napište příběh…" rows={6} className={fieldCls + ' resize-none'}/>
            {actions}
        </div>
    );

    if (type === 'quote') return (
        <div className="space-y-2">
            <textarea {...inp('quote')} placeholder="Citát…" rows={3} className={fieldCls + ' resize-none'}/>
            <input {...inp('author')} placeholder="Autor (volitelně)" className={fieldCls}/>
            {actions}
        </div>
    );

    if (type === 'photo') {
        const selected: string[] = draft.media_uuids ?? [];
        const toggle = (uuid: string) => {
            const next = selected.includes(uuid) ? selected.filter(u => u !== uuid) : [...selected, uuid];
            setDraft({ ...draft, media_uuids: next });
        };
        return (
            <div className="space-y-2">
                <select value={draft.layout ?? 'single'} onChange={e => setDraft({ ...draft, layout: e.target.value })} className={fieldCls}>
                    <option value="single">Jedna fotografie</option>
                    <option value="grid2">Mřížka 2×</option>
                    <option value="grid3">Mřížka 3×</option>
                </select>
                <div className="grid grid-cols-5 gap-1 max-h-48 overflow-y-auto">
                    {albumMedia.map(m => (
                        <button key={m.uuid} type="button" onClick={() => toggle(m.uuid)}
                            className={`aspect-square rounded overflow-hidden border-2 transition-all ${selected.includes(m.uuid) ? 'border-[var(--color-accent)] opacity-100' : 'border-transparent opacity-60 hover:opacity-100'}`}>
                            <img src={m.thumb_url} alt="" className="w-full h-full object-cover"/>
                        </button>
                    ))}
                    {albumMedia.length === 0 && <p className="col-span-5 text-xs text-[var(--color-text-secondary)] text-center py-4">Album nemá fotografie</p>}
                </div>
                <input {...inp('caption')} placeholder="Popis fotografie (volitelně)" className={fieldCls}/>
                {actions}
            </div>
        );
    }

    if (type === 'video') {
        const selected: string = draft.media_uuid ?? '';
        return (
            <div className="space-y-2">
                <p className="text-xs text-[var(--color-text-secondary)]">Vyberte video z alba:</p>
                <div className="grid grid-cols-5 gap-1 max-h-40 overflow-y-auto">
                    {albumMedia.filter(m => (m as any).media_type === 'video' || (m as any).is_video).map(m => (
                        <button key={m.uuid} type="button" onClick={() => setDraft({ ...draft, media_uuid: m.uuid })}
                            className={`aspect-square rounded overflow-hidden border-2 transition-all ${selected === m.uuid ? 'border-[var(--color-accent)]' : 'border-transparent opacity-60 hover:opacity-100'}`}>
                            <img src={m.thumb_url} alt="" className="w-full h-full object-cover"/>
                        </button>
                    ))}
                    {albumMedia.length === 0 && <p className="col-span-5 text-xs text-[var(--color-text-secondary)] text-center py-4">Žádná videa</p>}
                </div>
                <input {...inp('caption')} placeholder="Popis videa (volitelně)" className={fieldCls}/>
                {actions}
            </div>
        );
    }

    if (type === 'map') return (
        <div className="space-y-2">
            <div className="grid grid-cols-2 gap-2">
                <input {...inp('latitude')}  placeholder="Zeměpisná šířka" type="number" step="any" className={fieldCls}/>
                <input {...inp('longitude')} placeholder="Zeměpisná délka" type="number" step="any" className={fieldCls}/>
            </div>
            <input {...inp('label')} placeholder="Popis místa (např. Národní muzeum)" className={fieldCls}/>
            <input {...inp('zoom')} placeholder="Úroveň zoom (výchozí 14)" type="number" min="1" max="20" className={fieldCls}/>
            {actions}
        </div>
    );

    return null;
}

// ── Add block button ───────────────────────────────────────────────────────

function AddBlockMenu({ onAdd, afterIndex }: { onAdd: (type: BlockType, afterIndex: number) => void; afterIndex: number }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="relative flex justify-center my-1">
            <button onClick={() => setOpen(v => !v)}
                className="flex items-center gap-1.5 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-accent)] border border-dashed border-[var(--color-border)] hover:border-[var(--color-accent)] px-3 py-1 rounded-full transition-all bg-[var(--color-bg-primary)]">
                <Plus size={11}/> Přidat blok
            </button>
            {open && (
                <div className="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden z-50 w-56">
                    <div className="grid grid-cols-2 gap-0">
                        {BLOCK_TYPES.map(bt => (
                            <button key={bt.type}
                                onClick={() => { onAdd(bt.type, afterIndex); setOpen(false); }}
                                className="flex items-center gap-2 px-3 py-2.5 text-xs text-[var(--color-text-secondary)] hover:text-white hover:bg-[var(--color-bg-secondary)] transition-colors border-b border-r border-[var(--color-border)] last:border-r-0">
                                <span className="text-base w-5 text-center">{bt.emoji}</span>
                                {bt.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// ── Main Story component ───────────────────────────────────────────────────

interface AlbumStoryProps {
    albumUuid:  string;
    albumMedia: AlbumMedia[];
    editMode:   boolean;
    coverDate?: string;
    title:      string;
}

export default function AlbumStory({ albumUuid, albumMedia, editMode, coverDate, title }: AlbumStoryProps) {
    const [blocks,  setBlocks]  = useState<StoryBlock[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        axios.get(`/api/v1/albums/${albumUuid}/story`)
            .then(r => setBlocks(r.data ?? []))
            .finally(() => setLoading(false));
    }, [albumUuid]);

    const addBlock = useCallback(async (type: BlockType, afterIndex: number) => {
        const sortOrder = afterIndex + 1;
        // Shift subsequent blocks
        const shifted = blocks.map(b => b.sort_order >= sortOrder ? { ...b, sort_order: b.sort_order + 1 } : b);
        const r = await axios.post(`/api/v1/albums/${albumUuid}/story`, { type, content: {}, sort_order: sortOrder });
        const newBlock = r.data as StoryBlock;
        setBlocks([...shifted, newBlock].sort((a, b) => a.sort_order - b.sort_order));
    }, [albumUuid, blocks]);

    const updateBlock = useCallback((id: number, content: Record<string,any>) => {
        setBlocks(prev => prev.map(b => b.id === id ? { ...b, content } : b));
    }, []);

    const deleteBlock = useCallback(async (id: number) => {
        if (!confirm('Smazat tento blok?')) return;
        await axios.delete(`/api/v1/albums/${albumUuid}/story/${id}`);
        setBlocks(prev => prev.filter(b => b.id !== id));
    }, [albumUuid]);

    const moveBlock = useCallback(async (id: number, dir: 'up' | 'down') => {
        const idx = blocks.findIndex(b => b.id === id);
        if (idx < 0) return;
        const swapIdx = dir === 'up' ? idx - 1 : idx + 1;
        if (swapIdx < 0 || swapIdx >= blocks.length) return;

        const updated = [...blocks];
        [updated[idx], updated[swapIdx]] = [updated[swapIdx], updated[idx]];
        const reordered = updated.map((b, i) => ({ ...b, sort_order: i }));
        setBlocks(reordered);

        await axios.put(`/api/v1/albums/${albumUuid}/story/reorder`, {
            order: reordered.map(b => b.id),
        });
    }, [albumUuid, blocks]);

    if (loading) {
        return (
            <div className="flex items-center justify-center py-16">
                <div className="w-6 h-6 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin"/>
            </div>
        );
    }

    if (!editMode && blocks.length === 0) {
        return (
            <div className="text-center py-16 text-[var(--color-text-secondary)]">
                <Type size={36} className="mx-auto mb-3 opacity-20"/>
                <p className="text-sm">Příběh ještě nebyl napsán</p>
                <p className="text-xs mt-1 opacity-60">Přepněte do režimu úprav a začněte tvořit</p>
            </div>
        );
    }

    return (
        <div className="max-w-2xl mx-auto px-4 py-8 space-y-6">
            {/* Story header */}
            <header className="mb-8">
                <h1 className="text-3xl font-bold text-white mb-1">{title}</h1>
                {coverDate && <p className="text-sm text-[var(--color-text-secondary)]">{new Date(coverDate).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' })}</p>}
            </header>

            {/* Add block at position 0 */}
            {editMode && <AddBlockMenu onAdd={addBlock} afterIndex={-1}/>}

            {blocks.map((block, idx) => (
                <div key={block.id}>
                    {editMode ? (
                        <EditableBlock
                            block={block} albumUuid={albumUuid} albumMedia={albumMedia}
                            isFirst={idx === 0} isLast={idx === blocks.length - 1}
                            onUpdate={updateBlock} onDelete={deleteBlock}
                            onMoveUp={id => moveBlock(id, 'up')} onMoveDown={id => moveBlock(id, 'down')}
                        />
                    ) : (
                        <BlockView block={block}/>
                    )}
                    {editMode && <AddBlockMenu onAdd={addBlock} afterIndex={block.sort_order}/>}
                </div>
            ))}

            {!editMode && blocks.length === 0 && (
                <p className="text-center text-[var(--color-text-secondary)] text-sm py-8">Příběh je prázdný.</p>
            )}
        </div>
    );
}
