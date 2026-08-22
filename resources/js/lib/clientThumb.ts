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

    // A film is read differently — a frame is seeked to rather than decoded whole — and
    // it has no size ceiling, because only the first second of it is ever touched.
    if (file.type.startsWith('video/') || /\.(mp4|mov|m4v|webm|mkv|avi|3gp)$/i.test(file.name)) {
        return frameFromVideo(file);
    }

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

/**
 * A still from the first moment of a film.
 *
 * The server makes these with ffmpeg, which is not always installed — and a video with
 * no poster is a grey rectangle in the grid, indistinguishable from one that failed to
 * upload. The browser that is about to play the file can always draw a frame from it.
 *
 * One second in rather than zero: the very first frame of a phone video is very often
 * black, the shutter having opened a moment before the sensor settled.
 */
function frameFromVideo(file: File): Promise<Blob | null> {
    return new Promise(resolve => {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');

        let settled = false;

        const finish = (blob: Blob | null) => {
            if (settled) return;
            settled = true;

            window.clearTimeout(timer);
            video.removeAttribute('src');
            video.load();
            URL.revokeObjectURL(url);
            resolve(blob);
        };

        // A codec the browser cannot play never fires an event at all — it would sit
        // here forever and hold the next file's thumbnail behind it.
        const timer = window.setTimeout(() => finish(null), 15_000);

        video.onerror = () => finish(null);

        video.onloadeddata = () => {
            // Only seek if there is something to seek to; a one-second clip stays put.
            video.currentTime = Math.min(1, (video.duration || 0) / 2);
        };

        video.onseeked = () => {
            const min = Math.min(video.videoWidth, video.videoHeight);
            if (! min) { finish(null); return; }

            const canvas = document.createElement('canvas');
            canvas.width = SIZE;
            canvas.height = SIZE;

            const context = canvas.getContext('2d');
            if (! context) { finish(null); return; }

            context.drawImage(
                video,
                Math.round((video.videoWidth - min) / 2), Math.round((video.videoHeight - min) / 2), min, min,
                0, 0, SIZE, SIZE,
            );

            canvas.toBlob(blob => finish(blob), 'image/jpeg', 0.82);
        };

        // Muted and inline so a mobile browser will load frames without a tap, which it
        // otherwise refuses to do.
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.src = url;
    });
}
