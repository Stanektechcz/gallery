import { useCallback, useRef, useState } from 'react';
import { Upload, X, CheckCircle, AlertCircle, Loader2 } from 'lucide-react';
import { startUpload } from '@/lib/uploadService';

interface UploadFile {
    id: string;
    file: File;
    status: 'queued' | 'uploading' | 'done' | 'error';
    percent: number;
    error?: string;
}

interface Props {
    albumId: number;
    onUploadComplete?: () => void;
}

const ACCEPTED_TYPES = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'image/avif', 'image/heic', 'image/heif', 'image/tiff',
    'video/mp4', 'video/quicktime', 'video/webm',
    'video/x-m4v', 'video/x-matroska', 'video/x-msvideo',
];

function isAccepted(file: File): boolean {
    if (ACCEPTED_TYPES.includes(file.type)) return true;
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    return ['jpg','jpeg','png','webp','gif','avif','heic','heif','tiff','tif',
            'mp4','mov','webm','m4v','mkv','avi'].includes(ext);
}

export default function UploadZone({ albumId, onUploadComplete }: Props) {
    const [files, setFiles] = useState<UploadFile[]>([]);
    const [dragging, setDragging] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const dragCounter = useRef(0);

    const processFiles = useCallback((rawFiles: FileList | null) => {
        if (!rawFiles) return;
        const accepted = Array.from(rawFiles).filter(isAccepted);
        if (!accepted.length) return;

        const newItems: UploadFile[] = accepted.map(f => ({
            id: `${f.name}-${f.size}-${Date.now()}-${Math.random()}`,
            file: f,
            status: 'queued',
            percent: 0,
        }));

        setFiles(prev => [...prev, ...newItems]);

        // Start uploads sequentially per file (parallel is fine too)
        newItems.forEach(item => {
            setFiles(prev => prev.map(f => f.id === item.id ? { ...f, status: 'uploading' } : f));

            startUpload(item.file, albumId, (progress) => {
                setFiles(prev => prev.map(f =>
                    f.id === item.id
                        ? { ...f, percent: progress.percent, status: progress.status === 'done' ? 'done' : 'uploading' }
                        : f,
                ));
            })
            .then(() => {
                setFiles(prev => prev.map(f => f.id === item.id ? { ...f, status: 'done', percent: 100 } : f));
                onUploadComplete?.();
            })
            .catch((err: unknown) => {
                const msg = err instanceof Error ? err.message : 'Nahrávání selhalo';
                setFiles(prev => prev.map(f => f.id === item.id ? { ...f, status: 'error', error: msg } : f));
            });
        });
    }, [albumId, onUploadComplete]);

    const onDragEnter = (e: React.DragEvent) => {
        e.preventDefault();
        dragCounter.current++;
        if (dragCounter.current === 1) setDragging(true);
    };
    const onDragLeave = (e: React.DragEvent) => {
        e.preventDefault();
        dragCounter.current--;
        if (dragCounter.current === 0) setDragging(false);
    };
    const onDragOver = (e: React.DragEvent) => { e.preventDefault(); };
    const onDrop = (e: React.DragEvent) => {
        e.preventDefault();
        dragCounter.current = 0;
        setDragging(false);
        processFiles(e.dataTransfer.files);
    };

    const removeFile = (id: string) => {
        setFiles(prev => prev.filter(f => f.id !== id));
    };

    const activeCount  = files.filter(f => f.status === 'uploading').length;
    const doneCount    = files.filter(f => f.status === 'done').length;
    const errorCount   = files.filter(f => f.status === 'error').length;

    return (
        <div className="space-y-3">
            {/* Drop zone */}
            <div
                onDragEnter={onDragEnter}
                onDragLeave={onDragLeave}
                onDragOver={onDragOver}
                onDrop={onDrop}
                onClick={() => inputRef.current?.click()}
                className={[
                    'relative flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed cursor-pointer transition-all select-none',
                    'py-8 px-4',
                    dragging
                        ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 scale-[1.01]'
                        : 'border-[var(--color-border)] hover:border-[var(--color-accent)]/60 hover:bg-white/5',
                ].join(' ')}
            >
                <Upload
                    size={28}
                    className={dragging ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'}
                />
                <p className="text-sm text-white font-medium">
                    {dragging ? 'Pusťte soubory sem' : 'Přetáhněte nebo klikněte pro výběr'}
                </p>
                <p className="text-xs text-[var(--color-text-secondary)]">
                    Fotky &amp; videa · JPG, PNG, HEIC, MP4, MOV…
                </p>

                <input
                    ref={inputRef}
                    type="file"
                    multiple
                    accept={ACCEPTED_TYPES.join(',')}
                    className="sr-only"
                    onChange={e => processFiles(e.target.files)}
                    onClick={e => { (e.target as HTMLInputElement).value = ''; }}
                />
            </div>

            {/* Summary bar */}
            {files.length > 0 && (
                <div className="flex items-center gap-3 text-xs text-[var(--color-text-secondary)]">
                    {activeCount > 0 && (
                        <span className="flex items-center gap-1 text-[var(--color-accent)]">
                            <Loader2 size={12} className="animate-spin" />
                            Nahrávám {activeCount}…
                        </span>
                    )}
                    {doneCount > 0 && (
                        <span className="flex items-center gap-1 text-green-400">
                            <CheckCircle size={12} />
                            {doneCount} hotovo
                        </span>
                    )}
                    {errorCount > 0 && (
                        <span className="flex items-center gap-1 text-red-400">
                            <AlertCircle size={12} />
                            {errorCount} chyb
                        </span>
                    )}
                    <button
                        className="ml-auto text-xs underline hover:text-white"
                        onClick={() => setFiles(prev => prev.filter(f => f.status !== 'done'))}
                    >
                        Skrýt hotové
                    </button>
                </div>
            )}

            {/* File list */}
            {files.length > 0 && (
                <ul className="space-y-1 max-h-56 overflow-y-auto pr-1">
                    {files.map(f => (
                        <li
                            key={f.id}
                            className="flex items-center gap-2 rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] px-3 py-2 text-sm"
                        >
                            {/* Status icon */}
                            <span className="shrink-0">
                                {f.status === 'uploading' && <Loader2 size={14} className="animate-spin text-[var(--color-accent)]" />}
                                {f.status === 'done'      && <CheckCircle size={14} className="text-green-400" />}
                                {f.status === 'error'     && <AlertCircle size={14} className="text-red-400" />}
                                {f.status === 'queued'    && <Upload size={14} className="text-[var(--color-text-secondary)]" />}
                            </span>

                            {/* Name + bar */}
                            <div className="flex-1 min-w-0">
                                <p className="truncate text-white text-xs">{f.file.name}</p>
                                {(f.status === 'uploading' || f.status === 'done') && (
                                    <div className="mt-1 h-0.5 rounded-full bg-white/10 overflow-hidden">
                                        <div
                                            className={`h-full rounded-full transition-all ${f.status === 'done' ? 'bg-green-400' : 'bg-[var(--color-accent)]'}`}
                                            style={{ width: `${f.percent}%` }}
                                        />
                                    </div>
                                )}
                                {f.status === 'error' && (
                                    <p className="text-red-400 text-xs mt-0.5 truncate">{f.error}</p>
                                )}
                            </div>

                            {/* Size */}
                            <span className="shrink-0 text-xs text-[var(--color-text-secondary)]">
                                {(f.file.size / 1024 / 1024).toFixed(1)} MB
                            </span>

                            {/* Remove */}
                            {f.status !== 'uploading' && (
                                <button
                                    onClick={() => removeFile(f.id)}
                                    className="shrink-0 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors"
                                >
                                    <X size={14} />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
