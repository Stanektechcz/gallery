/**
 * Making a thumbnail in the browser, for the pictures the server cannot open.
 *
 * HEIC is the case that matters. It is what every iPhone photographs in by default, and
 * turning one into a JPEG needs ImageMagick built against libheif — which a lot of
 * servers do not have. When it is missing the upload succeeds, the bytes are safe, and
 * the grid shows a placeholder forever, which reads to the person as a photograph that
 * failed.
 *
 * The device that took the picture can always decode it. So it draws the thumbnail
 * itself and sends that along, and the gallery has something to show whatever the
 * server can do. The server's own thumbnail still wins when it can make one: this only
 * fills a gap, it never overwrites.
 */

/** Matches the server's thumbnail: a 400px square, cropped from the middle. */
const SIZE = 400;

/** Above this the browser is as likely to run out of memory as to produce anything. */
const MAX_SOURCE_BYTES = 80 * 1024 * 1024;

/**
 * A square JPEG thumbnail, or null when this browser cannot read the file.
 *
 * Null is an ordinary answer, not a failure: a RAW file, a video, or a format this
 * browser has never heard of all land here, and the server is better placed to handle
 * every one of them.
 */
export async function makeThumbnail(file: File): Promise<Blob | null> {
    if (typeof createImageBitmap !== 'function') return null;
    if (file.size > MAX_SOURCE_BYTES) return null;
    if (! file.type.startsWith('image/') && ! /\.(heic|heif)$/i.test(file.name)) return null;

    let bitmap: ImageBitmap | null = null;

    try {
        // The browser applies the EXIF rotation itself, so a portrait photograph off a
        // phone does not come out on its side.
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });

        const min = Math.min(bitmap.width, bitmap.height);
        if (! min) return null;

        const canvas = document.createElement('canvas');
        canvas.width = SIZE;
        canvas.height = SIZE;

        const context = canvas.getContext('2d');
        if (! context) return null;

        context.drawImage(
            bitmap,
            Math.round((bitmap.width - min) / 2), Math.round((bitmap.height - min) / 2), min, min,
            0, 0, SIZE, SIZE,
        );

        return await new Promise<Blob | null>(resolve =>
            canvas.toBlob(blob => resolve(blob), 'image/jpeg', 0.82));
    } catch {
        // Every unreadable format arrives here. Saying nothing is right: the upload
        // itself is unaffected and the server will have its own go.
        return null;
    } finally {
        // Released explicitly. A bitmap holds decoded pixels — a few dozen megabytes for
        // a phone photograph — and a folder import would otherwise hold one per file.
        bitmap?.close();
    }
}
