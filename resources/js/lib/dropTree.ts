/**
 * Reading whole folders that were dropped on the page.
 *
 * `DataTransfer.files` only ever contains loose files. Drop a folder and it holds
 * nothing at all — which is why dragging a year of photographs onto the page used to
 * look like it simply did not work. The folder is reachable only through the entries
 * API, walked one level at a time.
 *
 * Two things here are easy to get wrong and both are silent when you do:
 *
 * The entries must be taken out of the event synchronously. `dataTransfer.items` is
 * emptied the moment the drop handler yields, so the handles are collected first and
 * the walking happens afterwards.
 *
 * A directory reader hands back at most a hundred entries per call and signals the end
 * with an empty batch, never with a count. Reading once looks correct on a test folder
 * of ten photographs and quietly loses everything past the hundredth in a real one.
 */

/** Ceiling on a single drop. Past this the queue, not the page, should be doing the work. */
const MAX_FILES = 25_000;

/** How deep a dropped tree is followed. Deeper than this is a mistake, not an archive. */
const MAX_DEPTH = 12;

interface FileSystemEntryLike {
    isFile: boolean;
    isDirectory: boolean;
    name: string;
    fullPath: string;
    file?: (onSuccess: (file: File) => void, onError: (error: unknown) => void) => void;
    createReader?: () => {
        readEntries: (onSuccess: (entries: FileSystemEntryLike[]) => void, onError: (error: unknown) => void) => void;
    };
}

export interface DroppedTree {
    files: File[];
    /** True when the drop hit the ceiling, so the caller can say so rather than lose files quietly. */
    truncated: boolean;
}

/**
 * Whether a drop carries any folder at all.
 *
 * Worth knowing before the walk starts, because reading a tree takes long enough that
 * the page should say what it is doing.
 */
export function hasDirectories(transfer: DataTransfer): boolean {
    return Array.from(transfer.items ?? []).some(item => {
        const entry = (item as any).webkitGetAsEntry?.() as FileSystemEntryLike | null;

        return Boolean(entry?.isDirectory);
    });
}

/**
 * Everything inside a drop, folders walked to the bottom.
 *
 * Falls back to the plain file list when the browser has no entries API, which costs
 * nothing and keeps loose files working everywhere.
 */
export async function readDroppedTree(transfer: DataTransfer): Promise<DroppedTree> {
    // Collected before anything is awaited: the transfer is dead after this tick.
    const roots: FileSystemEntryLike[] = [];

    for (const item of Array.from(transfer.items ?? [])) {
        const entry = (item as any).webkitGetAsEntry?.() as FileSystemEntryLike | null;
        if (entry) roots.push(entry);
    }

    if (! roots.length) {
        return { files: Array.from(transfer.files ?? []), truncated: false };
    }

    const files: File[] = [];
    let truncated = false;

    const walk = async (entry: FileSystemEntryLike, depth: number): Promise<void> => {
        if (files.length >= MAX_FILES) { truncated = true; return; }

        if (entry.isFile) {
            const file = await readFile(entry);
            // The folder path is kept on the file so an importer can tell two files
            // called IMG_0001.JPG from different folders apart later on.
            if (file) files.push(file);

            return;
        }

        if (! entry.isDirectory || depth >= MAX_DEPTH) return;

        for (const child of await readDirectory(entry)) {
            await walk(child, depth + 1);
        }
    };

    for (const root of roots) {
        await walk(root, 0);
    }

    return { files, truncated };
}

/** One entry's File, or null when the browser refuses it (a permission or a vanished file). */
function readFile(entry: FileSystemEntryLike): Promise<File | null> {
    return new Promise(resolve => {
        if (! entry.file) { resolve(null); return; }

        entry.file(file => resolve(file), () => resolve(null));
    });
}

/**
 * Every child of a directory.
 *
 * Called in a loop because a reader returns them in batches of about a hundred and
 * reports the end only by returning nothing. A single call reads a small folder
 * correctly and truncates a large one without complaining.
 */
function readDirectory(entry: FileSystemEntryLike): Promise<FileSystemEntryLike[]> {
    return new Promise(resolve => {
        const reader = entry.createReader?.();
        if (! reader) { resolve([]); return; }

        const found: FileSystemEntryLike[] = [];

        const readBatch = () => {
            reader.readEntries(batch => {
                if (! batch.length) { resolve(found); return; }

                found.push(...batch);
                readBatch();
            }, () => resolve(found));
        };

        readBatch();
    });
}
