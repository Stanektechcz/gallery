/**
 * What counts as a photograph or a film, in one place.
 *
 * This used to be three lists that disagreed. The drop zone knew eighteen RAW formats;
 * the ZIP reader knew none of them, so an archive straight off a camera had every file
 * in it skipped as "not a photo or a video" while the same files dropped loose uploaded
 * fine. In the other direction the ZIP reader extracted .3gp and .mpg that the drop zone
 * then discarded without a word.
 *
 * Keeping the knowledge here means adding a format is one edit, and the two paths into
 * the gallery cannot drift apart again.
 */

/** Ordinary photographs, the ones every phone and browser makes. */
export const IMAGE_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'bmp', 'tif', 'tiff',
] as const;

/**
 * Camera raw files.
 *
 * Every maker has their own, browsers report no useful MIME type for any of them, and
 * they are exactly what somebody with a real camera has a folder of — so they are
 * recognised by extension and nothing else.
 */
export const RAW_EXTENSIONS = [
    'cr2', 'cr3', 'nef', 'nrw', 'arw', 'dng', 'orf', 'rw2', 'raf',
    'pef', 'srw', '3fr', 'fff', 'kdc', 'dcr', 'mrw', 'rwl', 'x3f',
] as const;

/** Films, including the AVCHD that camcorders and older phones still produce. */
export const VIDEO_EXTENSIONS = [
    'mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi', 'mts', 'm2ts', '3gp', 'mpg', 'mpeg',
] as const;

export const MEDIA_EXTENSIONS: ReadonlySet<string> = new Set<string>([
    ...IMAGE_EXTENSIONS, ...RAW_EXTENSIONS, ...VIDEO_EXTENSIONS,
]);

/**
 * MIME types for the file picker's `accept`.
 *
 * Only a hint: browsers disagree about what they report for HEIC and every RAW format,
 * which is why the extension check below is what actually decides.
 */
export const ACCEPTED_MIME = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'image/avif', 'image/heic', 'image/heif', 'image/tiff', 'image/bmp',
    'image/x-canon-cr2', 'image/x-canon-cr3',
    'image/x-nikon-nef', 'image/x-sony-arw', 'image/x-adobe-dng',
    'image/x-olympus-orf', 'image/x-panasonic-rw2', 'image/x-fuji-raf',
    'image/x-pentax-pef', 'image/x-samsung-srw', 'image/x-raw',
    'video/mp4', 'video/quicktime', 'video/webm',
    'video/x-m4v', 'video/x-matroska', 'video/x-msvideo',
    'video/avi', 'video/mpeg', 'video/mp2t', 'video/3gpp',
];

/** Names the file is saved under when the browser gave no type of its own. */
export const MIME_BY_EXTENSION: Record<string, string> = {
    jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif',
    webp: 'image/webp', avif: 'image/avif', heic: 'image/heic', heif: 'image/heif',
    bmp: 'image/bmp', tif: 'image/tiff', tiff: 'image/tiff',
    cr2: 'image/x-canon-cr2', cr3: 'image/x-canon-cr3', nef: 'image/x-nikon-nef',
    nrw: 'image/x-nikon-nef', arw: 'image/x-sony-arw', dng: 'image/x-adobe-dng',
    orf: 'image/x-olympus-orf', rw2: 'image/x-panasonic-rw2', raf: 'image/x-fuji-raf',
    pef: 'image/x-pentax-pef', srw: 'image/x-samsung-srw',
    mp4: 'video/mp4', mov: 'video/quicktime', m4v: 'video/x-m4v', webm: 'video/webm',
    mkv: 'video/x-matroska', avi: 'video/x-msvideo', '3gp': 'video/3gpp',
    mpg: 'video/mpeg', mpeg: 'video/mpeg', mts: 'video/mp2t', m2ts: 'video/mp2t',
};

export const extensionOf = (name: string): string =>
    name.includes('.') ? name.split('.').pop()!.toLowerCase() : '';

/**
 * Whether this browser can really hand over a whole folder.
 *
 * iOS reports the attribute and then ignores it: the picker opens on the photo library
 * and no directory can be chosen at all. Trusting the feature test alone leaves every
 * iPhone with a button that does nothing, which is worse than not offering one — so the
 * platform is checked as well, and iOS is offered multi-select instead, which is the
 * thing that actually works there.
 */
export function canPickFolders(): boolean {
    if (typeof document === 'undefined') return false;

    const supported = 'webkitdirectory' in document.createElement('input');
    const iOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
        // iPadOS reports itself as a Mac; the touch points give it away.
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    return supported && ! iOS;
}

/**
 * Whether the gallery takes this file.
 *
 * The extension has the final say. A browser that reports an empty type for a HEIC off
 * an iPhone — which they do — must not be the reason somebody's photograph is refused.
 */
export function isMedia(file: File): boolean {
    if (ACCEPTED_MIME.includes(file.type)) return true;

    return MEDIA_EXTENSIONS.has(extensionOf(file.name));
}
