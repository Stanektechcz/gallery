import { MediaCardData, MediaGrid } from '@/Components/MediaGrid';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Archive, RotateCcw, X } from 'lucide-react';
import { useCallback, useState } from 'react';

interface Props {
    media: {
        data: MediaCardData[];
        current_page: number;
        last_page: number;
        total: number;
    };
}

export default function ArchiveIndex({ media }: Props) {
    const [selected, setSelected]  = useState<Set<string>>(new Set());
    const [items, setItems]        = useState<MediaCardData[]>(media.data);
    const [processing, setProc]    = useState(false);

    const toggleSelect = useCallback((uuid: string, sel: boolean) => {
        setSelected(prev => { const n = new Set(prev); sel ? n.add(uuid) : n.delete(uuid); return n; });
    }, []);

    const unarchiveSelected = async () => {
        if (processing || selected.size === 0) return;
        setProc(true);
        try {
            await axios.post('/api/v1/archive/bulk-unarchive', { uuids: Array.from(selected) });
            setItems(prev => prev.filter(i => !selected.has(i.uuid)));
            setSelected(new Set());
        } finally {
            setProc(false);
        }
    };

    const unarchiveOne = async (uuid: string) => {
        await axios.post(`/api/v1/archive/${uuid}/unarchive`);
        setItems(prev => prev.filter(i => i.uuid !== uuid));
    };

    return (
        <AppLayout>
            <Head title="Archiv" />
            <div className="min-h-full">
                {/* Header */}
                <div className="sticky top-0 z-20 px-4 py-3 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 backdrop-blur-sm">
                    <div className="flex items-center justify-between flex-wrap gap-2">
                        <div className="flex items-center gap-2">
                            <Archive size={16} className="text-[var(--color-text-secondary)]" />
                            <h1 className="text-sm font-semibold text-white">Archiv</h1>
                            <span className="text-xs text-[var(--color-text-secondary)]">{items.length} položek</span>
                        </div>
                        <div className="flex items-center gap-2">
                            {selected.size > 0 && (
                                <>
                                    <span className="text-xs text-[var(--color-text-secondary)]">{selected.size} vybráno</span>
                                    <button
                                        onClick={unarchiveSelected}
                                        disabled={processing}
                                        className="text-xs bg-[var(--color-accent)]/20 hover:bg-[var(--color-accent)]/30 disabled:opacity-50 text-[var(--color-accent)] px-3 py-1.5 rounded-lg flex items-center gap-1"
                                    >
                                        <RotateCcw size={12} /> Odarchivovat
                                    </button>
                                    <button onClick={() => setSelected(new Set())} className="text-[var(--color-text-secondary)] hover:text-white"><X size={14} /></button>
                                </>
                            )}
                            {items.length > 0 && selected.size === 0 && (
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

                <div className="p-4">
                    <p className="text-xs text-[var(--color-text-secondary)] mb-4">
                        Archivované položky nejsou v hlavní galerii ani ve vzpomínkách. Lze je kdykoli obnovit.
                    </p>

                    <MediaGrid
                        items={items}
                        selected={selected}
                        onSelect={toggleSelect}
                        getBadge={item => (
                            <div className="absolute bottom-0 left-0 right-0 p-2 bg-gradient-to-t from-black/70 to-transparent flex justify-end items-end opacity-0 group-hover:opacity-100 transition-opacity">
                                <button
                                    onClick={e => { e.preventDefault(); e.stopPropagation(); unarchiveOne(item.uuid); }}
                                    className="bg-[var(--color-accent)]/80 hover:bg-[var(--color-accent)] text-white text-[10px] px-2 py-0.5 rounded flex items-center gap-1"
                                >
                                    <RotateCcw size={8} /> Obnovit
                                </button>
                            </div>
                        )}
                        emptyState={
                            <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                                <Archive size={48} className="mb-3 opacity-20" />
                                <p className="text-lg font-medium text-white mb-1">Archiv je prázdný</p>
                                <p className="text-sm">Archivované fotky a videa se zobrazí zde</p>
                            </div>
                        }
                    />

                    {media.last_page > 1 && (
                        <div className="flex justify-center gap-2 mt-6">
                            {media.current_page > 1 && (
                                <button onClick={() => router.get('/archive', { page: String(media.current_page - 1) })} className="px-4 py-2 text-sm bg-[var(--color-bg-card)] hover:bg-white/10 text-white rounded-lg">← Předchozí</button>
                            )}
                            <span className="px-4 py-2 text-sm text-[var(--color-text-secondary)]">{media.current_page} / {media.last_page}</span>
                            {media.current_page < media.last_page && (
                                <button onClick={() => router.get('/archive', { page: String(media.current_page + 1) })} className="px-4 py-2 text-sm bg-[var(--color-bg-card)] hover:bg-white/10 text-white rounded-lg">Další →</button>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
