import { MediaCardData, MediaGrid } from '@/Components/MediaGrid';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, RotateCcw, Trash2, X } from 'lucide-react';
import { useCallback, useState } from 'react';

interface Props {
    media: {
        data: MediaCardData[];
        current_page: number;
        last_page: number;
        total: number;
    };
    retention_days: number;
    can_purge: boolean;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(0)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

function daysUntilPurge(purgeAfter: string | null | undefined): string {
    if (!purgeAfter) return '';
    const days = Math.ceil((new Date(purgeAfter).getTime() - Date.now()) / 86400000);
    if (days < 0) return 'Vyprší brzy';
    if (days === 0) return 'Dnes';
    return `${days}d`;
}

export default function TrashIndex({ media, retention_days, can_purge }: Props) {
    const [selected, setSelected]   = useState<Set<string>>(new Set());
    const [items, setItems]         = useState<MediaCardData[]>(media.data);
    const [processing, setProc]     = useState(false);
    const [showEmptyConfirm, setShowEmptyConfirm] = useState(false);

    const toggleSelect = useCallback((uuid: string, sel: boolean) => {
        setSelected(prev => { const n = new Set(prev); sel ? n.add(uuid) : n.delete(uuid); return n; });
    }, []);

    const restoreSelected = async () => {
        if (processing || selected.size === 0) return;
        setProc(true);
        try {
            await axios.post('/api/v1/trash/bulk-restore', { uuids: Array.from(selected) });
            setItems(prev => prev.filter(i => !selected.has(i.uuid)));
            setSelected(new Set());
        } finally {
            setProc(false);
        }
    };

    const restoreOne = async (uuid: string) => {
        await axios.post(`/api/v1/trash/${uuid}/restore`);
        setItems(prev => prev.filter(i => i.uuid !== uuid));
    };

    const purgeOne = async (uuid: string) => {
        if (!can_purge) return;
        if (!confirm('Trvale smazat tuto položku? Tuto akci nelze vrátit zpět.')) return;
        await axios.delete(`/api/v1/trash/${uuid}/purge`);
        setItems(prev => prev.filter(i => i.uuid !== uuid));
    };

    const emptyTrash = async () => {
        if (!can_purge) return;
        setProc(true);
        try {
            await axios.delete('/api/v1/trash/empty');
            setItems([]);
            setShowEmptyConfirm(false);
        } finally {
            setProc(false);
        }
    };

    return (
        <AppLayout>
            <Head title="Koš" />
            <div className="min-h-full">
                {/* Header */}
                <div className="sticky top-0 z-20 px-4 py-3 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 backdrop-blur-sm">
                    <div className="flex items-center justify-between flex-wrap gap-2">
                        <div className="flex items-center gap-2">
                            <Trash2 size={16} className="text-[var(--color-text-secondary)]" />
                            <h1 className="text-sm font-semibold text-white">Koš</h1>
                            <span className="text-xs text-[var(--color-text-secondary)]">{items.length} položek</span>
                            <span className="text-xs text-[var(--color-text-secondary)] hidden sm:inline">· Smazáno za {retention_days} dní</span>
                        </div>
                        <div className="flex items-center gap-2">
                            {selected.size > 0 && (
                                <>
                                    <span className="text-xs text-[var(--color-text-secondary)]">{selected.size} vybráno</span>
                                    <button
                                        onClick={restoreSelected}
                                        disabled={processing}
                                        className="text-xs bg-green-500/20 hover:bg-green-500/30 disabled:opacity-50 text-green-400 px-3 py-1.5 rounded-lg flex items-center gap-1"
                                    >
                                        <RotateCcw size={12} /> Obnovit
                                    </button>
                                    <button onClick={() => setSelected(new Set())} className="text-[var(--color-text-secondary)] hover:text-white"><X size={14} /></button>
                                </>
                            )}
                            {can_purge && items.length > 0 && selected.size === 0 && (
                                <button
                                    onClick={() => setShowEmptyConfirm(true)}
                                    className="text-xs bg-red-500/10 hover:bg-red-500/20 text-red-400 px-3 py-1.5 rounded-lg flex items-center gap-1"
                                >
                                    <Trash2 size={12} /> Vyprázdnit koš
                                </button>
                            )}
                            {items.length > 0 && (
                                <button
                                    onClick={() => setSelected(new Set(items.map(i => i.uuid)))}
                                    className="text-xs text-[var(--color-text-secondary)] hover:text-white"
                                >
                                    Vybrat vše
                                </button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Warning banner */}
                {items.length > 0 && (
                    <div className="mx-4 mt-4 px-4 py-3 bg-yellow-500/10 border border-yellow-500/20 rounded-xl flex items-start gap-2">
                        <AlertTriangle size={14} className="text-yellow-400 mt-0.5 shrink-0" />
                        <p className="text-xs text-yellow-300/80">
                            Položky v koši budou automaticky trvale smazány po {retention_days} dnech.
                            {can_purge ? ' Jako administrátor je můžete smazat okamžitě.' : ''}
                        </p>
                    </div>
                )}

                <div className="p-4">
                    <MediaGrid
                        items={items}
                        selected={selected}
                        onSelect={toggleSelect}
                        getBadge={item => (
                            <div className="absolute bottom-0 left-0 right-0 p-2 bg-gradient-to-t from-black/70 to-transparent flex justify-between items-end opacity-0 group-hover:opacity-100 transition-opacity">
                                <button
                                    onClick={e => { e.preventDefault(); e.stopPropagation(); restoreOne(item.uuid); }}
                                    className="bg-green-500/80 hover:bg-green-500 text-white text-[10px] px-2 py-0.5 rounded flex items-center gap-1"
                                >
                                    <RotateCcw size={8} /> Obnovit
                                </button>
                                {can_purge && (
                                    <button
                                        onClick={e => { e.preventDefault(); e.stopPropagation(); purgeOne(item.uuid); }}
                                        className="bg-red-500/80 hover:bg-red-500 text-white text-[10px] px-2 py-0.5 rounded"
                                    >
                                        Smazat
                                    </button>
                                )}
                                {item.purge_after && (
                                    <span className="text-white/60 text-[10px] ml-auto">{daysUntilPurge(item.purge_after)}</span>
                                )}
                            </div>
                        )}
                        emptyState={
                            <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                                <Trash2 size={48} className="mb-3 opacity-20" />
                                <p className="text-lg font-medium text-white mb-1">Koš je prázdný</p>
                                <p className="text-sm">Smazané položky se zobrazí zde</p>
                            </div>
                        }
                    />
                </div>

                {/* Empty trash confirmation dialog */}
                {showEmptyConfirm && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
                        <div className="glass rounded-2xl p-6 max-w-sm w-full shadow-2xl">
                            <div className="flex items-center gap-3 mb-4">
                                <div className="w-10 h-10 rounded-xl bg-red-500/20 flex items-center justify-center">
                                    <AlertTriangle size={20} className="text-red-400" />
                                </div>
                                <div>
                                    <h2 className="text-sm font-semibold text-white">Vyprázdnit koš?</h2>
                                    <p className="text-xs text-[var(--color-text-secondary)]">Tato akce je nevratná</p>
                                </div>
                            </div>
                            <p className="text-sm text-[var(--color-text-secondary)] mb-6">
                                Trvale smažete {items.length} {items.length === 1 ? 'položku' : 'položek'}.
                                Originály budou přesunuty do koše Google Drive.
                            </p>
                            <div className="flex gap-3">
                                <button
                                    onClick={() => setShowEmptyConfirm(false)}
                                    className="flex-1 bg-white/10 hover:bg-white/15 text-white text-sm py-2 rounded-lg"
                                >
                                    Zrušit
                                </button>
                                <button
                                    onClick={emptyTrash}
                                    disabled={processing}
                                    className="flex-1 bg-red-500 hover:bg-red-600 disabled:opacity-50 text-white text-sm py-2 rounded-lg"
                                >
                                    {processing ? 'Mažu…' : 'Smazat vše'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
