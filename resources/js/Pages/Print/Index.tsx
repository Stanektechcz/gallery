/**
 * Print/Index.tsx — Photo book / print selection manager.
 * Features: create books, drag & drop ordering, ZIP/filelist/contact sheet export.
 */

import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    Download,
    FileText, FolderOpen, GripVertical,
    Plus, Printer, Trash2, X
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface BookItem {
    id:         number;
    sort_order: number;
    notes?:     string;
    uuid:       string;
    filename:   string;
    taken_at?:  string;
    width?:     number;
    height?:    number;
    size_bytes?: number;
    media_type: string;
    print_quality?: 'excellent'|'good'|'low'|'unsupported';
    recommended_max_cm?: number | null;
    thumb_url?: string;
    full_url:   string;
}

interface PhotoBook {
    id:           number;
    uuid:         string;
    name:         string;
    description?: string;
    purpose:      string;
    item_count:   number;
    target_count?: number;
    cover_thumb?: string;
    created_at:   string;
}

const PURPOSE_LABELS: Record<string, string> = {
    photobook: '📗 Fotokniha',
    print:     '🖨 Tisk',
    web:       '🌐 Web',
    gift:      '🎁 Dárek',
    other:     '📋 Jiné',
};

function formatBytes(b?: number): string {
    if (!b) return '';
    if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)} KB`;
    return `${(b / 1024 ** 2).toFixed(1)} MB`;
}

// ── Drag & Drop reorderable grid ──────────────────────────────────────────

function DragGrid({ items, onReorder, onRemove }: {
    items: BookItem[];
    onReorder: (newOrder: BookItem[]) => void;
    onRemove:  (id: number) => void;
}) {
    const [draggingId, setDraggingId] = useState<number | null>(null);
    const [overId,     setOverId]     = useState<number | null>(null);

    const handleDragStart = (id: number) => setDraggingId(id);
    const handleDragOver  = (e: React.DragEvent, id: number) => { e.preventDefault(); setOverId(id); };
    const handleDrop      = (e: React.DragEvent, targetId: number) => {
        e.preventDefault();
        if (draggingId === null || draggingId === targetId) return;

        const from = items.findIndex(i => i.id === draggingId);
        const to   = items.findIndex(i => i.id === targetId);
        if (from < 0 || to < 0) return;

        const next = [...items];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        onReorder(next.map((it, idx) => ({ ...it, sort_order: idx })));

        setDraggingId(null);
        setOverId(null);
    };
    const handleDragEnd = () => { setDraggingId(null); setOverId(null); };

    return (
        <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7 gap-2">
            {items.map((item, idx) => (
                <div key={item.id}
                    draggable
                    onDragStart={() => handleDragStart(item.id)}
                    onDragOver={e => handleDragOver(e, item.id)}
                    onDrop={e => handleDrop(e, item.id)}
                    onDragEnd={handleDragEnd}
                    className={`relative group rounded-lg overflow-hidden bg-[var(--color-bg-secondary)] border-2 transition-all cursor-grab active:cursor-grabbing ${
                        draggingId === item.id ? 'opacity-40 scale-95' :
                        overId === item.id ? 'border-[var(--color-accent)] scale-105' :
                        'border-transparent hover:border-[var(--color-border)]'
                    }`}>
                    {/* Thumbnail */}
                    <div className="aspect-square">
                        {item.thumb_url
                            ? <img src={item.thumb_url} alt="" className="w-full h-full object-cover"/>
                            : <div className="w-full h-full flex items-center justify-center text-[var(--color-text-secondary)]">
                                <FolderOpen size={20}/>
                              </div>
                        }
                    </div>

                    {/* Order badge */}
                    <div className="absolute top-1 left-1 w-5 h-5 bg-black/70 text-white rounded text-[9px] font-bold flex items-center justify-center">
                        {idx + 1}
                    </div>

                    {item.print_quality && item.print_quality !== 'excellent' && <div className={`absolute bottom-1 left-1 rounded px-1 py-0.5 text-[8px] font-medium ${item.print_quality === 'low' || item.print_quality === 'unsupported' ? 'bg-red-500/90 text-white' : 'bg-amber-400/90 text-black'}`} title={item.print_quality === 'unsupported' ? 'Video nelze vytisknout' : `Doporučený maximální kratší rozměr: ${item.recommended_max_cm ?? '?'} cm`}>{item.print_quality === 'unsupported' ? 'Bez tisku' : item.print_quality === 'low' ? 'Nízké rozlišení' : `max. ${item.recommended_max_cm} cm`}</div>}

                    {/* Grip */}
                    <div className="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <GripVertical size={13} className="text-white drop-shadow"/>
                    </div>

                    {/* Remove button */}
                    <button
                        onMouseDown={e => e.stopPropagation()}
                        onClick={e => { e.stopPropagation(); onRemove(item.id); }}
                        className="absolute bottom-1 right-1 p-0.5 rounded bg-red-500/80 text-white opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-500">
                        <X size={10}/>
                    </button>

                    {/* Filename on hover */}
                    <div className="absolute inset-x-0 bottom-0 translate-y-full group-hover:translate-y-0 transition-transform bg-black/80 px-1 py-0.5">
                        <p className="text-[8px] text-white truncate">{item.filename}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────

export default function PrintIndex() {
    const [books,     setBooks]     = useState<PhotoBook[]>([]);
    const [selected,  setSelected]  = useState<PhotoBook | null>(null);
    const [items,     setItems]     = useState<BookItem[]>([]);
    const [loading,   setLoading]   = useState(true);
    const [loadingItems, setLoadingItems] = useState(false);
    const [showCreate, setShowCreate] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', purpose: 'photobook', target_count: '' });
    const [creating,   setCreating]   = useState(false);
    const [exporting,  setExporting]  = useState<string | null>(null);

    useEffect(() => {
        axios.get('/api/v1/books').then(r => setBooks(r.data ?? [])).finally(() => setLoading(false));
    }, []);

    const selectBook = async (book: PhotoBook) => {
        setSelected(book);
        setLoadingItems(true);
        try {
            const r = await axios.get(`/api/v1/books/${book.uuid}`);
            setItems(r.data.items ?? []);
        } finally { setLoadingItems(false); }
    };

    const createBook = async (e: React.FormEvent) => {
        e.preventDefault(); setCreating(true);
        try {
            const r = await axios.post('/api/v1/books', {
                name:         createForm.name,
                purpose:      createForm.purpose,
                target_count: createForm.target_count ? parseInt(createForm.target_count) : undefined,
            });
            setBooks(prev => [r.data, ...prev]);
            setCreateForm({ name: '', purpose: 'photobook', target_count: '' });
            setShowCreate(false);
            selectBook(r.data);
        } finally { setCreating(false); }
    };

    const deleteBook = async (uuid: string) => {
        if (!confirm('Smazat tento výběr?')) return;
        await axios.delete(`/api/v1/books/${uuid}`);
        setBooks(prev => prev.filter(b => b.uuid !== uuid));
        if (selected?.uuid === uuid) { setSelected(null); setItems([]); }
    };

    const handleReorder = useCallback(async (newItems: BookItem[]) => {
        setItems(newItems);
        if (!selected) return;
        await axios.put(`/api/v1/books/${selected.uuid}/items/reorder`, {
            order: newItems.map(i => i.id),
        }).catch(() => {});
    }, [selected]);

    const removeItem = useCallback(async (itemId: number) => {
        if (!selected) return;
        await axios.delete(`/api/v1/books/${selected.uuid}/items/${itemId}`);
        setItems(prev => prev.filter(i => i.id !== itemId));
        setBooks(prev => prev.map(b => b.uuid === selected.uuid ? { ...b, item_count: b.item_count - 1 } : b));
    }, [selected]);

    const exportZip = async () => {
        if (!selected) return;
        setExporting('zip');
        try { window.open(`/api/v1/books/${selected.uuid}/export/zip`, '_blank'); }
        finally { setTimeout(() => setExporting(null), 2000); }
    };

    const exportFileList = async () => {
        if (!selected) return;
        setExporting('filelist');
        try { window.open(`/api/v1/books/${selected.uuid}/export/filelist`, '_blank'); }
        finally { setTimeout(() => setExporting(null), 2000); }
    };

    const openContactSheet = () => {
        if (!selected) return;
        window.open(`/books/${selected.uuid}/print`, '_blank');
    };

    const percent = selected && selected.target_count
        ? Math.min(100, Math.round((items.length / selected.target_count) * 100))
        : null;

    return (
        <AppLayout>
            <Head title="Výběry k tisku"/>
            <div className="flex h-full min-h-0">

                {/* ── Left: book list ─────────────────────────────── */}
                <div className="w-64 shrink-0 flex flex-col border-r border-[var(--color-border)] overflow-hidden">
                    <div className="p-4 border-b border-[var(--color-border)] shrink-0">
                        <div className="flex items-center justify-between mb-2">
                            <h1 className="text-sm font-semibold text-white flex items-center gap-2">
                                <Printer size={16} className="text-[var(--color-accent)]"/> Výběry
                            </h1>
                            <button onClick={() => setShowCreate(v=>!v)}
                                className="flex items-center gap-1 bg-[var(--color-accent)] text-white text-xs px-2.5 py-1.5 rounded-lg hover:opacity-90">
                                <Plus size={12}/> Nový
                            </button>
                        </div>
                    </div>

                    {/* Create form */}
                    {showCreate && (
                        <form onSubmit={createBook} className="p-3 border-b border-[var(--color-border)] space-y-2 bg-[var(--color-bg-secondary)] shrink-0">
                            <input required value={createForm.name}
                                onChange={e => setCreateForm(p=>({...p,name:e.target.value}))}
                                placeholder="Název výběru *" autoFocus
                                className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                            <select value={createForm.purpose}
                                onChange={e => setCreateForm(p=>({...p,purpose:e.target.value}))}
                                className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]">
                                {Object.entries(PURPOSE_LABELS).map(([k,v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                            <input value={createForm.target_count}
                                onChange={e => setCreateForm(p=>({...p,target_count:e.target.value}))}
                                placeholder="Cílový počet (např. 50)" type="number" min="1" max="500"
                                className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                            <div className="flex gap-2">
                                <button type="submit" disabled={creating}
                                    className="flex-1 bg-[var(--color-accent)] text-white text-xs py-1.5 rounded-lg hover:opacity-90 disabled:opacity-40">
                                    Vytvořit
                                </button>
                                <button type="button" onClick={()=>setShowCreate(false)}
                                    className="px-3 text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] rounded-lg hover:text-white">
                                    ✕
                                </button>
                            </div>
                        </form>
                    )}

                    {/* Books list */}
                    <div className="flex-1 overflow-y-auto">
                        {loading ? (
                            <div className="p-4 space-y-2">
                                {[1,2,3].map(i=><div key={i} className="h-16 bg-[var(--color-bg-card)] rounded-xl animate-pulse"/>)}
                            </div>
                        ) : books.length === 0 ? (
                            <div className="p-6 text-center text-[var(--color-text-secondary)]">
                                <Printer size={28} className="mx-auto mb-2 opacity-30"/>
                                <p className="text-xs">Žádné výběry</p>
                            </div>
                        ) : books.map(book => (
                            <div key={book.uuid}
                                onClick={() => selectBook(book)}
                                className={`flex gap-3 p-3 border-b border-[var(--color-border)] cursor-pointer transition-colors group ${selected?.uuid===book.uuid ? 'bg-[var(--color-bg-card)] border-l-2 border-l-[var(--color-accent)]' : 'hover:bg-[var(--color-bg-card)]'}`}>
                                {/* Cover */}
                                <div className="w-12 h-12 rounded-lg overflow-hidden bg-[var(--color-bg-secondary)] shrink-0">
                                    {book.cover_thumb
                                        ? <img src={book.cover_thumb} alt="" className="w-full h-full object-cover"/>
                                        : <div className="w-full h-full flex items-center justify-center text-xl opacity-30">📗</div>
                                    }
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-semibold text-white truncate">{book.name}</p>
                                    <p className="text-[10px] text-[var(--color-text-secondary)]">{PURPOSE_LABELS[book.purpose]}</p>
                                    <p className="text-[10px] text-[var(--color-accent)] font-medium">
                                        {book.item_count}{book.target_count ? ` / ${book.target_count}` : ''} fotografií
                                    </p>
                                </div>
                                <button onClick={e=>{e.stopPropagation();deleteBook(book.uuid);}}
                                    className="p-1 text-[var(--color-text-secondary)] hover:text-red-400 opacity-0 group-hover:opacity-100 transition-all shrink-0">
                                    <Trash2 size={12}/>
                                </button>
                            </div>
                        ))}
                    </div>
                </div>

                {/* ── Right: book detail ──────────────────────────── */}
                {selected ? (
                    <div className="flex-1 flex flex-col min-h-0 overflow-hidden">
                        {/* Header */}
                        <div className="px-5 py-3 border-b border-[var(--color-border)] shrink-0">
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex-1 min-w-0">
                                    <h2 className="text-base font-bold text-white">{selected.name}</h2>
                                    <div className="flex items-center gap-3 mt-1 text-xs text-[var(--color-text-secondary)]">
                                        <span>{PURPOSE_LABELS[selected.purpose]}</span>
                                        <span className="text-[var(--color-accent)] font-medium">
                                            {items.length}{selected.target_count ? ` / ${selected.target_count}` : ''} fotografií
                                        </span>
                                        {percent !== null && (
                                            <span className={percent >= 100 ? 'text-green-400' : ''}>{percent}%</span>
                                        )}
                                    </div>
                                    {/* Progress bar toward target */}
                                    {selected.target_count && (
                                        <div className="mt-2 h-1.5 bg-[var(--color-bg-secondary)] rounded-full overflow-hidden w-48">
                                            <div className={`h-full rounded-full transition-all ${percent! >= 100 ? 'bg-green-500' : 'bg-[var(--color-accent)]'}`}
                                                style={{ width: `${Math.min(100, percent!)}%`}}/>
                                        </div>
                                    )}
                                </div>

                                {/* Export buttons */}
                                <div className="flex flex-wrap gap-2 shrink-0">
                                    <button onClick={openContactSheet}
                                        className="flex items-center gap-1.5 text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white px-3 py-1.5 rounded-lg transition-colors">
                                        <Printer size={12}/> Contact sheet
                                    </button>
                                    <button onClick={exportFileList}
                                        disabled={exporting === 'filelist'}
                                        className="flex items-center gap-1.5 text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white px-3 py-1.5 rounded-lg transition-colors disabled:opacity-40">
                                        <FileText size={12}/> Seznam souborů
                                    </button>
                                    <button onClick={exportZip}
                                        disabled={exporting === 'zip' || items.length === 0}
                                        className="flex items-center gap-1.5 text-xs bg-[var(--color-accent)] text-white px-3 py-1.5 rounded-lg hover:opacity-90 disabled:opacity-40">
                                        <Download size={12}/> ZIP originálů
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* Instructions */}
                        {items.length === 0 && !loadingItems && (
                            <div className="flex-1 flex items-center justify-center text-[var(--color-text-secondary)]">
                                <div className="text-center max-w-xs">
                                    <Printer size={40} className="mx-auto mb-3 opacity-20"/>
                                    <p className="text-sm font-medium">Výběr je prázdný</p>
                                    <p className="text-xs mt-2 opacity-60 leading-relaxed">
                                        Přidejte fotografie přes hromadné akce v časové ose nebo v albu.<br/>
                                        Vyberte fotky → <strong>Do alba</strong> → nebo použijte BulkActionBar.
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Loading */}
                        {loadingItems && (
                            <div className="flex-1 flex items-center justify-center">
                                <div className="w-6 h-6 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin"/>
                            </div>
                        )}

                        {/* Drag & drop grid */}
                        {!loadingItems && items.length > 0 && (
                            <div className="flex-1 overflow-y-auto p-5">
                                <div className="flex items-center justify-between mb-3">
                                    <p className="text-xs text-[var(--color-text-secondary)]">
                                        Přetažením změníte pořadí · Najeďte myší pro odebrání
                                    </p>
                                </div>
                                <DragGrid items={items} onReorder={handleReorder} onRemove={removeItem}/>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="flex-1 flex items-center justify-center text-[var(--color-text-secondary)]">
                        <div className="text-center max-w-xs">
                            <Printer size={48} className="mx-auto mb-4 opacity-20"/>
                            <p className="text-sm font-medium">Vyberte nebo vytvořte výběr</p>
                            <p className="text-xs mt-1 opacity-60 leading-relaxed">
                                Fotoknihy, výběry k tisku, sady pro darek…<br/>
                                Uspořádejte fotky, pak exportujte jako ZIP nebo contact sheet.
                            </p>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
