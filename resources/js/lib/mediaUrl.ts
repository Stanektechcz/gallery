/**
 * Where a media preview lives, with the extension kept off the path.
 *
 * The web server matches image suffixes and serves them from disk; when the file is not
 * there it answers 404 and only then hands the request to the application, which streams
 * the picture under a status it has already committed to. The browser sees an error and
 * draws nothing — a full gallery looking empty.
 *
 * Mirrors MediaVariant::proxyUrl on the server. The two must agree, which is why this is
 * one function rather than a template string repeated in every grid.
 */
export function previewUrl(uuid: string, mediaType: string): string {
    const name = mediaType === 'video' ? 'video_poster' : 'thumbnail';

    return `/files/media/${uuid}/${name}?ext=jpg`;
}
