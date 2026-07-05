/**
 * UploadPanel — Floating upload manager UI.
 * Shows global progress, stats, and per-file rows with actions.
 */

import { uploadManager, type ManagedUpload } from '@/lib/uploadManager';
import {
    AlertTriangle, CheckCircle2, ChevronDown, ChevronUp,
    Loader2, Pause, Play, RefreshCw, Trash2, X, ZoomIn,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

function formatBytes(b: number): string {
    if (b < 1024) return `${b} B`;
    if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)} KB`;
    return `${(b / 1024 ** 2).toFixed(1)} MB`;
}

const STATUS_LABEL: Record<string, string> = {
    waiting:    'Čeká',
    hashing:    'Kontrola…',
    duplicate:  'Duplicitní',
    uploading:  'Nahrává',
    paused:     'Pozastaveno',
    offline:    'Offline',
    processing: 'Zpracování',
    done:       'Hotovo',
    error:      'Chyba',
    cancelled:  'Zrušeno',
};

const STATUS_COLOR: Record<string, string> = {
    waiting:    'text-[var(--color-text-secondary)]',
    hashing:    'text-blue-400',
    duplicate:  'text-yellow-400',
    uploading:  'text-[var(--color-accent)]',
    paused:     'text-orange-400',
    offline:    'text-orange-400',
    processing: 'text-blue-400',
    done:       'text-green-400',
    error:      'text-red-400',
    cancelled:  'text-[var(--color-text-secondary)]',
};

function UploadRow({ item }: { item: ManagedUpload }) {
    const isActive    = ['uploading', 'hashing', 'processing'].includes(item.status);
    const canPause    = ['uploading', 'waiting'].includes(item.status);
    const canResume   = ['paused', 'offline'].includes(item.status);
    const canRetry    = ['error', 'cancelled'].includes(item.status);
    const canCancel   = !['done', 'duplicate', 'cancelled', 'error'].includes(item.status);
    const isDone      = ['done', 'duplicate'].includes(item.status);

    const progressColor = item.status === 'error' ? 'bg-red-500'
        : item.status === 'paused' ? 'bg-orange-400'
        : isDone ? 'bg-green-500'
        : 'bg-[var(--color-accent)]';

    return (
        <div className="flex items-start gap-2.5 px-3 py-2 border-b border-[var(--color-border)] last:border-0 hover:bg-white/3 transition-colors">
            {/* Thumbnail */}
            <div className="w-9 h-9 shrink-0 rounded-md overflow-hidden bg-[var(--color-bg-secondary)] flex items-center justify-center">
                {item.thumb
                    ? <img src={item.thumb} alt="" className="w-full h-full object-cover"/>
                    : <span className="text-[10px] text-[var(--color-text-secondary)] uppercase">{item.filename.split('.').pop()}</span>
                }
            </div>

            {/* Info + progress */}
            <div className="flex-1 min-w-0">
                <div className="flex items-center justify-between gap-1 mb-0.5">
                    <p className="text-[11px] font-medium text-white truncate">{item.filename}</p>
                    <span className={`text-[10px] shrink-0 ${STATUS_COLOR[item.status] ?? 'text-[var(--color-text-secondary)]'}`}>
                        {isActive && <Loader2 size={9} className="inline animate-spin mr-0.5"/>}
                        {STATUS_LABEL[item.status] ?? item.status}
                    </span>
                </div>

                {/* Progress bar */}
                {!isDone && item.status !== 'cancelled' && (
                    <div className="h-1 bg-[var(--color-bg-secondary)] rounded-full overflow-hidden mb-1">
                        <div className={`h-full rounded-full transition-all duration-300 ${progressColor}`}
                            style={{ width: `${item.percent}%` }}/>
                    </div>
                )}

                <div className="flex items-center gap-1.5">
                    <span className="text-[9px] text-[var(--color-text-secondary)]">{formatBytes(item.size)}</span>
                    {item.percent > 0 && !isDone && item.status !== 'error' && (
                        <span className="text-[9px] text-[var(--color-text-secondary)]">· {item.percent}%</span>
                    )}
                    {item.error && (
                        <span className="text-[9px] text-red-400 truncate max-w-28" title={item.error}>{item.error}</span>
                    )}
                    {item.status === 'duplicate' && (
                        <span className="text-[9px] text-yellow-400">· Soubor již existuje</span>
                    )}
                </div>
            </div>

            {/* Actions */}
            <div className="flex items-center gap-0.5 shrink-0">
                {canPause  && <button onClick={() => uploadManager.pause(item.id)}  title="Pozastavit" className="p-1 text-[var(--color-text-secondary)] hover:text-orange-400 transition-colors"><Pause size={11}/></button>}
                {canResume && <button onClick={() => uploadManager.resume(item.id)} title="Pokračovat"  className="p-1 text-[var(--color-text-secondary)] hover:text-green-400 transition-colors"><Play  size={11}/></button>}
                {canRetry  && <button onClick={() => uploadManager.retry(item.id)}  title="Zkusit znovu" className="p-1 text-[var(--color-text-secondary)] hover:text-blue-400 transition-colors"><RefreshCw size={11}/></button>}
                {canCancel
                    ? <button onClick={() => uploadManager.cancel(item.id)} title="Zrušit"   className="p-1 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors"><X size={11}/></button>
                    : <button onClick={() => uploadManager.remove(item.id)} title="Odebrat"  className="p-1 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors"><Trash2 size={11}/></button>
                }
                {item.mediaUuid && <a href={`/media/${item.mediaUuid}`} target="_blank" rel="noopener noreferrer" title="Otevřít" className="p-1 text-[var(--color-text-secondary)] hover:text-[var(--color-accent)] transition-colors"><ZoomIn size={11}/></a>}
            </div>
        </div>
    );
}

export default function UploadPanel() {
    const [uploads,     setUploads]     = useState<ManagedUpload[]>([]);
    const [minimized,   setMinimized]   = useState(false);
    const [visible,     setVisible]     = useState(false);
    const globalDropRef = useRef<boolean>(false);

    useEffect(() => {
        const handler = (e: Event) => {
            const items = (e as CustomEvent).detail.uploads as ManagedUpload[];
            setUploads([...items]);
            if (items.length > 0) setVisible(true);
        };
        uploadManager.addEventListener('change', handler);
        return () => uploadManager.removeEventListener('change', handler);
    }, []);

    // Global drag-drop handler on the window
    useEffect(() => {
        const onDragOver = (e: DragEvent) => {
            if (e.dataTransfer?.types.includes('Files')) e.preventDefault();
        };
        const onDrop = (e: DragEvent) => {
            e.preventDefault();
            const files = e.dataTransfer?.files;
            if (files && files.length > 0) {
                uploadManager.enqueue(Array.from(files), null);
            }
        };
        window.addEventListener('dragover', onDragOver);
        window.addEventListener('drop', onDrop);
        return () => {
            window.removeEventListener('dragover', onDragOver);
            window.removeEventListener('drop', onDrop);
        };
    }, []);

    if (!visible || uploads.length === 0) return null;

    const stats = uploadManager.getStats();
    const activeUploads = uploads.filter(u => ['uploading', 'hashing', 'processing', 'waiting', 'paused'].includes(u.status));
    const showList = !minimized;

    const progressBarColor = stats.error > 0
        ? 'bg-gradient-to-r from-[var(--color-accent)] to-red-500'
        : 'bg-[var(--color-accent)]';

    return (
        <div className="fixed bottom-0 right-4 z-[600] w-80 shadow-2xl rounded-t-xl overflow-hidden border border-[var(--color-border)] border-b-0 bg-[var(--color-bg-card)]">

            {/* Header */}
            <div className="flex items-center gap-2 px-3 py-2.5 bg-[var(--color-bg-secondary)] border-b border-[var(--color-border)]">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                        <p className="text-xs font-semibold text-white">
                            Nahrávání {stats.total} {stats.total === 1 ? 'souboru' : 'souborů'}
                        </p>
                        {stats.uploading > 0 && <Loader2 size={11} className="text-[var(--color-accent)] animate-spin"/>}
                    </div>
                    {/* Overall progress bar */}
                    <div className="h-1.5 bg-[var(--color-border)] rounded-full overflow-hidden">
                        <div className={`h-full rounded-full transition-all duration-500 ${progressBarColor}`}
                            style={{ width: `${stats.percent}%` }}/>
                    </div>
                </div>
                <span className="text-xs font-bold text-white shrink-0">{stats.percent}%</span>
                <button onClick={() => setMinimized(v => !v)} className="p-1 text-[var(--color-text-secondary)] hover:text-white transition-colors">
                    {minimized ? <ChevronUp size={14}/> : <ChevronDown size={14}/>}
                </button>
                <button onClick={() => setVisible(false)} className="p-1 text-[var(--color-text-secondary)] hover:text-white transition-colors" title="Skrýt panel">
                    <X size={14}/>
                </button>
            </div>

            {/* Stats row */}
            <div className="flex items-center gap-0 px-3 py-1.5 bg-[var(--color-bg-secondary)]/50 border-b border-[var(--color-border)] text-[10px] flex-wrap">
                {stats.done > 0      && <span className="text-green-400 mr-2">✓ {stats.done} hotovo</span>}
                {stats.uploading > 0 && <span className="text-[var(--color-accent)] mr-2">↑ {stats.uploading} nahrává</span>}
                {stats.waiting > 0   && <span className="text-[var(--color-text-secondary)] mr-2">◦ {stats.waiting} čeká</span>}
                {stats.paused > 0    && <span className="text-orange-400 mr-2">⏸ {stats.paused} pozast.</span>}
                {stats.error > 0     && <span className="text-red-400 mr-2">✗ {stats.error} chyba</span>}
                <div className="flex-1"/>
                {/* Pause all / Resume all */}
                {!stats.allPaused && stats.uploading + stats.waiting > 0 && (
                    <button onClick={() => uploadManager.pauseAll()} className="flex items-center gap-0.5 text-orange-400 hover:text-orange-300 transition-colors">
                        <Pause size={9}/> Pauza
                    </button>
                )}
                {(stats.allPaused || stats.paused > 0) && (
                    <button onClick={() => uploadManager.resumeAll()} className="flex items-center gap-0.5 text-green-400 hover:text-green-300 transition-colors ml-2">
                        <Play size={9}/> Pokračovat
                    </button>
                )}
                {stats.done + stats.cancelled > 0 && (
                    <button onClick={() => uploadManager.clearDone()} className="text-[var(--color-text-secondary)] hover:text-white transition-colors ml-2">
                        Vyčistit
                    </button>
                )}
            </div>

            {/* File list */}
            {showList && (
                <div className="max-h-64 overflow-y-auto">
                    {uploads
                        .slice()
                        .sort((a, b) => {
                            const order: Record<string, number> = { uploading: 0, hashing: 1, processing: 2, waiting: 3, paused: 4, error: 5, offline: 6, done: 7, duplicate: 8, cancelled: 9 };
                            return (order[a.status] ?? 10) - (order[b.status] ?? 10);
                        })
                        .map(item => <UploadRow key={item.id} item={item}/>)
                    }
                </div>
            )}
        </div>
    );
}
