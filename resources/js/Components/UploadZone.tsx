import { uploadManager } from '@/lib/uploadManager';
import { extractMedia, isZip } from '@/lib/zip';
import { Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const ACCEPTED: string[] = [
    // Standard images
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'image/avif', 'image/heic', 'image/heif', 'image/tiff', 'image/bmp',
    // RAW formats (MIME types vary by browser — we use extension fallback)
    'image/x-canon-cr2', 'image/x-canon-cr3',
    'image/x-nikon-nef', 'image/x-sony-arw', 'image/x-adobe-dng',
    'image/x-olympus-orf', 'image/x-panasonic-rw2', 'image/x-fuji-raf',
    'image/x-pentax-pef', 'image/x-samsung-srw', 'image/x-raw',
    // Video
    'video/mp4', 'video/quicktime', 'video/webm',
    'video/x-m4v', 'video/x-matroska', 'video/x-msvideo',
    'video/avi', 'video/mpeg', 'video/mp2t',
];

const RAW_EXTS = ['cr2','cr3','nef','nrw','arw','dng','orf','rw2','raf','pef','srw','3fr','fff','kdc','dcr','mrw','rwl','x3f'];

function ok(file: File): boolean {
    if (ACCEPTED.includes(file.type)) return true;
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (RAW_EXTS.includes(ext)) return true;
    return ['jpg','jpeg','png','webp','gif','avif','heic','heif','tiff','tif','bmp',
            'mp4','mov','webm','m4v','mkv','avi','mts','m2ts'].includes(ext);
}

interface Props { albumId: number | null; onUploadComplete?: (mediaUuids: string[]) => void; }

export default function UploadZone({ albumId, onUploadComplete }: Props) {
    const [dragging, setDragging] = useState(false);
    const [queued,   setQueued]   = useState(0);
    /** Which archive is being expanded, so a big ZIP does not look like a frozen page. */
    const [busy,     setBusy]     = useState<string | null>(null);
    const [notice,   setNotice]   = useState('');
    const inputRef  = useRef<HTMLInputElement>(null);
    const dragCount = useRef(0);
    const reported = useRef(new Set<string>());

    useEffect(() => {
        if (!onUploadComplete) return;

        const onChange = (event: Event) => {
            const uploads = (event as CustomEvent).detail.uploads as Array<{ id: string; albumId: number | null; status: string; mediaUuid?: string }>;
            const newlyCompleted = uploads.filter(upload =>
                upload.albumId === albumId
                && ['done', 'duplicate'].includes(upload.status)
                && !reported.current.has(upload.id),
            );

            uploads
                .filter(upload => upload.albumId === albumId && ['done', 'duplicate'].includes(upload.status))
                .forEach(upload => reported.current.add(upload.id));

            if (newlyCompleted.length) onUploadComplete(newlyCompleted.map(upload => upload.mediaUuid).filter((uuid): uuid is string => Boolean(uuid)));
        };

        uploadManager.addEventListener('change', onChange);
        return () => uploadManager.removeEventListener('change', onChange);
    }, [albumId, onUploadComplete]);

    /**
     * Takes whatever was dropped, expanding any archives on the way in.
     *
     * A ZIP is unpacked here rather than sent up whole: the pictures are already on this
     * machine, so uploading a hundred megabytes only to receive the same photographs back
     * would pay for them twice — and everything the queue does (per-file progress, pause,
     * resume, skipping duplicates) applies unchanged, which it could not if the server
     * unpacked a blob.
     *
     * Async because reading an archive is, and the drop event must not wait for it.
     */
    const process = useCallback(async (raw: FileList | null) => {
        if (!raw) return;

        const dropped = Array.from(raw);
        const archives = dropped.filter(isZip);
        let accepted = dropped.filter(file => !isZip(file)).filter(ok);
        const notes: string[] = [];

        for (const archive of archives) {
            setBusy(archive.name);
            try {
                const { files, skipped } = await extractMedia(archive);
                accepted = accepted.concat(files.filter(ok));

                // Said out loud rather than swallowed. An archive of a holiday usually
                // holds a stray text file or two, and somebody who is told none of their
                // photographs were dropped stops wondering what went missing.
                if (skipped.length) {
                    notes.push(`${archive.name}: přeskočeno ${skipped.length} souborů, které nejsou fotka ani video.`);
                }
            } catch (error) {
                notes.push(`${archive.name}: ${(error as Error).message}`);
            } finally {
                setBusy(null);
            }
        }

        setNotice(notes.join(' '));
        if (notes.length) setTimeout(() => setNotice(''), 8000);

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
                dragging ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10' : 'border-[var(--color-border)] hover:border-[var(--color-accent)]/60 hover:bg-[var(--color-surface-hover)]',
            ].join(' ')}>
            <Upload size={28} className={dragging ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'}/>
            <p className="text-sm text-[var(--color-text-primary)] font-medium">
                {busy
                    ? `Rozbaluji ${busy}…`
                    : dragging ? 'Pusťte soubory sem' : queued ? `✓ ${queued} souborů přidáno` : 'Přetáhněte nebo klikněte'}
            </p>
            <p className="text-xs text-[var(--color-text-secondary)]">Fotky, videa, RAW i ZIP · JPG PNG HEIC AVIF CR2 NEF ARW DNG MP4 MOV MKV…</p>
            <p className="text-[10px] text-[var(--color-text-secondary)] opacity-60">Kontrola duplicit · pokračování po výpadku · archivy se rozbalí samy</p>

            {/* What an archive left behind. Stopping the click from reopening the picker,
                because reading a message should not start a second import. */}
            {notice && (
                <p onClick={event => event.stopPropagation()} className="mt-1 rounded-lg bg-amber-500/10 px-2 py-1 text-[11px] text-amber-100">
                    {notice}
                </p>
            )}
            <input ref={inputRef} type="file" multiple accept={[...ACCEPTED, '.zip', 'application/zip'].join(',')} className="sr-only"
                onChange={e => { process(e.target.files); (e.target as HTMLInputElement).value = ''; }}/>
        </div>
    );
}
