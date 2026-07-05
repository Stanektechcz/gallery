import { uploadManager } from '@/lib/uploadManager';
import { Upload } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

const ACCEPTED: string[] = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'image/avif', 'image/heic', 'image/heif', 'image/tiff',
    'video/mp4', 'video/quicktime', 'video/webm',
    'video/x-m4v', 'video/x-matroska', 'video/x-msvideo',
];

function ok(file: File): boolean {
    if (ACCEPTED.includes(file.type)) return true;
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    return ['jpg','jpeg','png','webp','gif','avif','heic','heif','tiff','tif',
            'mp4','mov','webm','m4v','mkv','avi'].includes(ext);
}

interface Props { albumId: number | null; onUploadComplete?: () => void; }

export default function UploadZone({ albumId, onUploadComplete }: Props) {
    const [dragging, setDragging] = useState(false);
    const [queued,   setQueued]   = useState(0);
    const inputRef  = useRef<HTMLInputElement>(null);
    const dragCount = useRef(0);

    const process = useCallback((raw: FileList | null) => {
        if (!raw) return;
        const accepted = Array.from(raw).filter(ok);
        if (!accepted.length) return;
        uploadManager.enqueue(accepted, albumId);
        setQueued(n => n + accepted.length);
        setTimeout(() => setQueued(0), 3000);
    }, [albumId]);

    const onDE = (e: React.DragEvent) => { e.preventDefault(); dragCount.current++; if (dragCount.current === 1) setDragging(true); };
    const onDL = (e: React.DragEvent) => { e.preventDefault(); dragCount.current--; if (dragCount.current === 0) setDragging(false); };
    const onDO = (e: React.DragEvent) => { e.preventDefault(); };
    const onDrop = (e: React.DragEvent) => {
        e.preventDefault(); dragCount.current = 0; setDragging(false);
        process(e.dataTransfer.files);
    };

    return (
        <div onDragEnter={onDE} onDragLeave={onDL} onDragOver={onDO} onDrop={onDrop}
            onClick={() => inputRef.current?.click()}
            className={['flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed cursor-pointer transition-all select-none py-8 px-4',
                dragging ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10' : 'border-[var(--color-border)] hover:border-[var(--color-accent)]/60 hover:bg-white/5',
            ].join(' ')}>
            <Upload size={28} className={dragging ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'}/>
            <p className="text-sm text-white font-medium">
                {dragging ? 'PusĹĄte soubory sem' : queued ? `âś“ ${queued} souborĹŻ pĹ™idĂˇno` : 'PĹ™etĂˇhnÄ›te nebo kliknÄ›te'}
            </p>
            <p className="text-xs text-[var(--color-text-secondary)]">Fotky &amp; videa Â· JPG PNG HEIC MP4 MOV</p>
            <p className="text-[10px] text-[var(--color-text-secondary)] opacity-60">Kontrola duplicit Â· pokraÄŤovĂˇnĂ­ po vĂ˝padku</p>
            <input ref={inputRef} type="file" multiple accept={ACCEPTED.join(',')} className="sr-only"
                onChange={e => { process(e.target.files); (e.target as HTMLInputElement).value = ''; }}/>
        </div>
    );
}

