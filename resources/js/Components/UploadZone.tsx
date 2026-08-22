import { ACCEPTED_MIME, canPickFolders, isMedia } from '@/lib/mediaTypes';
import { uploadManager } from '@/lib/uploadManager';
import { hasDirectories, readDroppedTree } from '@/lib/dropTree';
import { extractMedia, isZip } from '@/lib/zip';
import { FolderOpen, Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';


interface Props { albumId: number | null; onUploadComplete?: (mediaUuids: string[]) => void; }

export default function UploadZone({ albumId, onUploadComplete }: Props) {
    const [dragging, setDragging] = useState(false);
    const [queued,   setQueued]   = useState(0);
    /** Which archive is being expanded, so a big ZIP does not look like a frozen page. */
    const [busy,     setBusy]     = useState<string | null>(null);
    const [notice,   setNotice]   = useState('');
    const inputRef  = useRef<HTMLInputElement>(null);
    const folderRef = useRef<HTMLInputElement>(null);
    const dragCount = useRef(0);
    // Asked once: the answer cannot change while the page is open.
    const [folders] = useState(canPickFolders);
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
    const process = useCallback(async (raw: FileList | File[] | null) => {
        if (!raw) return;

        const dropped = Array.from(raw);
        const archives = dropped.filter(isZip);
        let accepted = dropped.filter(file => !isZip(file)).filter(isMedia);
        const notes: string[] = [];

        for (const archive of archives) {
            setBusy(archive.name);
            try {
                const { files, skipped } = await extractMedia(archive);
                accepted = accepted.concat(files.filter(isMedia));

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
    /**
     * A drop can be loose files or whole folders, and only one of those arrives in
     * `dataTransfer.files`. The transfer is read out synchronously — it is emptied as
     * soon as this handler yields — and the walk happens after.
     */
    const onDrop = (e: React.DragEvent) => {
        e.preventDefault(); dragCount.current = 0; setDragging(false);

        const transfer = e.dataTransfer;
        const folders = hasDirectories(transfer);

        if (folders) setBusy('složky');

        void readDroppedTree(transfer)
            .then(({ files, truncated }) => {
                if (truncated) {
                    setNotice('Složka je opravdu velká — vzali jsme prvních 25 000 souborů. Zbytek přetáhněte zvlášť.');
                    setTimeout(() => setNotice(''), 10000);
                }

                return process(files);
            })
            .finally(() => { if (folders) setBusy(null); });
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
            <p className="text-[10px] text-[var(--color-text-secondary)] opacity-60">Kontrola duplicit · pokračování po výpadku · archivy i složky se rozbalí samy</p>

            {/* A folder needs its own picker: one input cannot offer both files and
                directories, and the drop target alone leaves anyone who does not drag
                without a way in at all. On iOS, where directories cannot be chosen at
                all, this offers the thing that does work there instead of a dead button. */}
            <button type="button"
                onClick={event => { event.stopPropagation(); (folders ? folderRef : inputRef).current?.click(); }}
                className="mt-1 inline-flex items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 py-1.5 text-xs text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-accent)]/60 hover:text-[var(--color-text-primary)]">
                <FolderOpen size={13}/> {folders ? 'Vybrat celou složku' : 'Vybrat víc fotek najednou'}
            </button>

            {/* What an archive left behind. Stopping the click from reopening the picker,
                because reading a message should not start a second import. */}
            {notice && (
                <p onClick={event => event.stopPropagation()} className="mt-1 rounded-lg bg-amber-500/10 px-2 py-1 text-[11px] text-amber-100">
                    {notice}
                </p>
            )}
            <input ref={inputRef} type="file" multiple accept={[...ACCEPTED_MIME, '.zip', 'application/zip'].join(',')} className="sr-only"
                onChange={e => { process(e.target.files); (e.target as HTMLInputElement).value = ''; }}/>

            {/* No `accept` here on purpose: with webkitdirectory some browsers apply the
                filter to the folder itself and offer nothing at all. The files are sorted
                out by `ok()` a moment later anyway. */}
            <input ref={folderRef} type="file" multiple className="sr-only"
                {...({ webkitdirectory: '', directory: '' } as any)}
                onChange={e => { process(e.target.files); (e.target as HTMLInputElement).value = ''; }}/>
        </div>
    );
}
