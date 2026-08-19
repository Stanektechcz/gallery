/**
 * Reading a ZIP in the browser, without a library.
 *
 * Expanding the archive here rather than on the server is the whole point. The files are
 * already on the person's machine, so sending a hundred megabytes up only to send the same
 * pictures back down is wasted twice — and everything the upload queue already does
 * (progress per file, pause, resume, skipping duplicates, retry) applies unchanged, which
 * it would not if the server unpacked a blob.
 *
 * It also removes a class of server risk outright: no archive is ever written to our disk,
 * so there is nothing for a zip bomb or a "../" entry to reach.
 *
 * DecompressionStream is used for deflate, which every current browser has. Nothing is
 * added to the bundle.
 */

const EOCD_SIGNATURE = 0x06054b50;
const CENTRAL_SIGNATURE = 0x02014b50;

/** Entries larger than this are skipped: a single photograph is never this big. */
const MAX_ENTRY_BYTES = 2 * 1024 * 1024 * 1024;

export interface ZipEntry {
    name: string;
    size: number;
    compression: number;
    offset: number;
    /** Bytes as they sit in the archive. Only equal to `size` when nothing was compressed. */
    compressedSize: number;
    /** Milliseconds, from the archive's own record of when the file was written. */
    modified: number;
}

export interface ZipReadResult {
    files: File[];
    /** Named so the person is told what was left behind rather than silently losing it. */
    skipped: Array<{ name: string; reason: string }>;
}

const MEDIA_EXTENSIONS = new Set([
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'bmp', 'tif', 'tiff',
    'mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi', '3gp', 'mpg', 'mpeg',
]);

const MIME_BY_EXTENSION: Record<string, string> = {
    jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif',
    webp: 'image/webp', avif: 'image/avif', heic: 'image/heic', heif: 'image/heif',
    bmp: 'image/bmp', tif: 'image/tiff', tiff: 'image/tiff',
    mp4: 'video/mp4', mov: 'video/quicktime', m4v: 'video/x-m4v', webm: 'video/webm',
    mkv: 'video/x-matroska', avi: 'video/x-msvideo', '3gp': 'video/3gpp',
    mpg: 'video/mpeg', mpeg: 'video/mpeg',
};

export const isZip = (file: File): boolean =>
    file.name.toLowerCase().endsWith('.zip') || file.type === 'application/zip';

/**
 * Every entry in the archive, read from the central directory at the end of the file.
 *
 * The central directory is authoritative; the local headers scattered through the file can
 * disagree with it and are what most naive readers trip over.
 */
export async function listEntries(file: File): Promise<ZipEntry[]> {
    // The end-of-directory record lives in the last 64 kB, after a comment of unknown
    // length, so it is searched for backwards rather than assumed to be at a fixed offset.
    const tailSize = Math.min(file.size, 65_536 + 22);
    const tail = new DataView(await file.slice(file.size - tailSize).arrayBuffer());

    let eocd = -1;
    for (let i = tail.byteLength - 22; i >= 0; i--) {
        if (tail.getUint32(i, true) === EOCD_SIGNATURE) { eocd = i; break; }
    }
    if (eocd < 0) throw new Error('Tohle není platný ZIP archiv.');

    const count = tail.getUint16(eocd + 10, true);
    const directorySize = tail.getUint32(eocd + 12, true);
    const directoryOffset = tail.getUint32(eocd + 16, true);

    const directory = new DataView(
        await file.slice(directoryOffset, directoryOffset + directorySize).arrayBuffer(),
    );

    const entries: ZipEntry[] = [];
    let cursor = 0;

    for (let i = 0; i < count && cursor + 46 <= directory.byteLength; i++) {
        if (directory.getUint32(cursor, true) !== CENTRAL_SIGNATURE) break;

        const compression = directory.getUint16(cursor + 10, true);
        const compressedSize = directory.getUint32(cursor + 20, true);
        const size = directory.getUint32(cursor + 24, true);
        const nameLength = directory.getUint16(cursor + 28, true);
        const extraLength = directory.getUint16(cursor + 30, true);
        const commentLength = directory.getUint16(cursor + 32, true);
        const offset = directory.getUint32(cursor + 42, true);

        const nameBytes = new Uint8Array(directory.buffer, cursor + 46, nameLength);
        const name = new TextDecoder('utf-8').decode(nameBytes);

        const modified = dosTimestamp(
            directory.getUint16(cursor + 12, true),
            directory.getUint16(cursor + 14, true),
        );

        entries.push({ name, size, compressedSize, compression, offset, modified });
        cursor += 46 + nameLength + extraLength + commentLength;
    }

    return entries;
}

/**
 * The MS-DOS date and time a ZIP stores, in milliseconds.
 *
 * The format predates the archive by a decade: two 16-bit words, seconds in steps of two,
 * years counted from 1980, and no timezone at all — so it is read as local time, which is
 * what whoever packed the archive was looking at.
 *
 * A zero date means the packer never wrote one. That falls back to now rather than to
 * 1980, because a photograph filed under the Moscow Olympics is worse than one filed today.
 */
function dosTimestamp(time: number, date: number): number {
    if (date === 0) return Date.now();

    const year = 1980 + ((date >> 9) & 0x7f);
    const month = ((date >> 5) & 0x0f) - 1;
    const day = date & 0x1f;
    const hours = (time >> 11) & 0x1f;
    const minutes = (time >> 5) & 0x3f;
    const seconds = (time & 0x1f) * 2;

    const stamp = new Date(year, month, day, hours, minutes, seconds).getTime();

    return Number.isNaN(stamp) ? Date.now() : stamp;
}

/**
 * Pulls the photographs and videos out of an archive.
 *
 * Anything that is not a picture or a film is skipped and named. Archives from phones and
 * cameras are full of thumbnails, metadata and folder junk, and quietly importing it would
 * fill somebody's gallery with things they did not photograph.
 */
export async function extractMedia(file: File): Promise<ZipReadResult> {
    const entries = await listEntries(file);
    const files: File[] = [];
    const skipped: Array<{ name: string; reason: string }> = [];

    for (const entry of entries) {
        const base = entry.name.split('/').pop() ?? entry.name;

        // Directories, macOS resource forks and dotfiles are noise, not omissions.
        if (entry.name.endsWith('/') || base === '' || base.startsWith('.') || entry.name.startsWith('__MACOSX/')) {
            continue;
        }

        const extension = base.includes('.') ? base.split('.').pop()!.toLowerCase() : '';

        if (! MEDIA_EXTENSIONS.has(extension)) {
            skipped.push({ name: entry.name, reason: 'není fotka ani video' });
            continue;
        }

        if (entry.size > MAX_ENTRY_BYTES) {
            skipped.push({ name: entry.name, reason: 'soubor je příliš velký' });
            continue;
        }

        try {
            const bytes = await readEntry(file, entry);

            files.push(new File([bytes], base, {
                type: MIME_BY_EXTENSION[extension] ?? 'application/octet-stream',
                // Carried across from the archive. Without it every photograph out of a
                // ZIP would be stamped with the moment it was unpacked, and a holiday
                // from three years ago would file itself under today.
                lastModified: entry.modified,
            }));
        } catch (error) {
            skipped.push({ name: entry.name, reason: (error as Error).message });
        }
    }

    return { files, skipped };
}

/**
 * One entry's bytes.
 *
 * The local header's name and extra fields vary in length, so the data offset has to be
 * read from the header itself — using the central directory's offset directly is the
 * commonest way to end up with a file shifted by a few dozen bytes.
 */
async function readEntry(file: File, entry: ZipEntry): Promise<ArrayBuffer> {
    const header = new DataView(await file.slice(entry.offset, entry.offset + 30).arrayBuffer());
    const nameLength = header.getUint16(26, true);
    const extraLength = header.getUint16(28, true);

    const start = entry.offset + 30 + nameLength + extraLength;

    // The compressed length, not the original one. Slicing by the uncompressed size reaches
    // past the end of the entry and into the next file's header; deflate happens to stop at
    // its own end marker and hide that, but it means holding far more of the archive in
    // memory than the entry needs — which is exactly how a large import runs a tab out of it.
    const blob = file.slice(start, start + (entry.compressedSize || entry.size));

    // 0 is stored, 8 is deflate. Everything else is a format we do not read, and saying so
    // beats handing the gallery a file of noise.
    if (entry.compression === 0) return blob.arrayBuffer();
    if (entry.compression !== 8) throw new Error('nepodporovaná komprese v archivu');

    const stream = blob.stream().pipeThrough(new DecompressionStream('deflate-raw'));

    return new Response(stream).arrayBuffer();
}
