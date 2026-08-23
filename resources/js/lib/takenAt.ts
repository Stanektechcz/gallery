/**
 * Reading the moment a photograph was taken, as the camera wrote it.
 *
 * EXIF records DateTimeOriginal with no timezone at all — it is simply what the clock in
 * the camera said. We store that verbatim, so a photograph taken at 14:51 in Prague has
 * "14:51" in the database, not an instant.
 *
 * Handing that string to `new Date()` treats it as UTC and then renders it in whatever
 * zone the reader happens to be in, which added two hours to every Czech photograph and
 * pushed anything taken after ten at night onto the following day. A picture from a
 * birthday dinner belonged to the day after the birthday.
 *
 * So the components are read out and rebuilt as local time. Nothing is converted: the
 * gallery shows the hour the shutter was pressed, which is the hour the person
 * remembers, whatever country they are sitting in when they look at it.
 */

const STAMP = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/;

/**
 * The stored timestamp as a Date whose local parts are the camera's own.
 *
 * Safe to hand to toLocaleDateString and friends — they will render exactly the numbers
 * that were recorded.
 */
export function takenAtDate(stamp?: string | null): Date | null {
    if (! stamp) return null;

    const parts = STAMP.exec(stamp);

    // Anything that is not our stored shape falls back to ordinary parsing rather than
    // returning nothing: better a converted date than no date.
    if (! parts) {
        const parsed = new Date(stamp);

        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    return new Date(
        Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]),
        Number(parts[4]), Number(parts[5]), Number(parts[6] ?? 0),
    );
}

/** The day a photograph belongs to: "2026-07-01", straight from the stamp. */
export function takenAtDay(stamp?: string | null): string | null {
    if (! stamp) return null;

    const parts = STAMP.exec(stamp);

    return parts ? `${parts[1]}-${parts[2]}-${parts[3]}` : null;
}

/** Formatted the way the rest of the app formats dates, without shifting the day. */
export function takenAtLabel(
    stamp?: string | null,
    options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric' },
    fallback = 'Bez data',
): string {
    const date = takenAtDate(stamp);

    return date ? date.toLocaleDateString('cs-CZ', options) : fallback;
}
