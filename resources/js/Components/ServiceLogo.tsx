/**
 * A mark for each service, drawn rather than fetched.
 *
 * No remote images: a settings page that reaches out to five other companies to render
 * itself tells each of them when somebody opened it, and breaks when one is unreachable.
 *
 * Where a logo is simple geometry — Dropbox's four diamonds, Drive's three faces — it is
 * drawn as such. Where it is not, the service gets a lettermark in its own colour instead
 * of an approximation, because a wrong logo reads worse than an honest initial.
 */
export default function ServiceLogo({ code, brand, size = 26 }: {
    code: string;
    brand?: string;
    size?: number;
}) {
    const colour = brand ?? 'var(--color-accent)';
    const box = { width: size, height: size };

    if (code === 'dropbox') {
        return (
            <svg viewBox="0 0 24 24" style={box} aria-hidden fill={colour}>
                <path d="M6 2 0 6l6 4 6-4-6-4Zm12 0-6 4 6 4 6-4-6-4ZM0 14l6 4 6-4-6-4-6 4Zm18-4-6 4 6 4 6-4-6-4ZM6 19.5l6 4 6-4-6-3.5-6 3.5Z" />
            </svg>
        );
    }

    if (code === 'google_drive') {
        return (
            <svg viewBox="0 0 24 24" style={box} aria-hidden>
                <path d="M8.4 2h7.2l7.2 12.5h-7.2L8.4 2Z" fill="#ffc107" />
                <path d="M1.2 14.5 8.4 2l3.6 6.25-7.2 12.5-3.6-6.25Z" fill="#1fa463" />
                <path d="M4.8 20.75h14.4l3.6-6.25H8.4l-3.6 6.25Z" fill="#4285f4" />
            </svg>
        );
    }

    if (code === 'onedrive') {
        return (
            <svg viewBox="0 0 24 24" style={box} aria-hidden fill={colour}>
                <path d="M9.6 6.2a5.2 5.2 0 0 1 9.1 2.2 4 4 0 0 1-.6 7.9H6.4a4.6 4.6 0 0 1-.7-9.1 5.2 5.2 0 0 1 3.9-1Z" />
            </svg>
        );
    }

    if (code === 'server') {
        return (
            <svg viewBox="0 0 24 24" style={box} aria-hidden fill="none" stroke={colour} strokeWidth="1.8">
                <rect x="2.5" y="4" width="19" height="6" rx="1.6" />
                <rect x="2.5" y="14" width="19" height="6" rx="1.6" />
                <circle cx="6.5" cy="7" r="1" fill={colour} stroke="none" />
                <circle cx="6.5" cy="17" r="1" fill={colour} stroke="none" />
            </svg>
        );
    }

    if (code === 'discord') {
        return (
            <svg viewBox="0 0 24 24" style={box} aria-hidden fill={colour}>
                <path d="M19.5 5.4A16 16 0 0 0 15.6 4.2l-.3.6a12 12 0 0 0-6.6 0l-.3-.6A16 16 0 0 0 4.5 5.4C2.1 9 1.5 12.5 1.8 15.9a16 16 0 0 0 4.9 2.5l.9-1.4c-.5-.2-1-.5-1.5-.8l.4-.3a11.4 11.4 0 0 0 9.8 0l.4.3c-.5.3-1 .6-1.5.8l.9 1.4a16 16 0 0 0 4.9-2.5c.4-4-.6-7.4-2.5-10.5ZM8.6 13.9c-.9 0-1.7-.9-1.7-2s.8-2 1.7-2 1.7.9 1.7 2-.7 2-1.7 2Zm6.8 0c-1 0-1.7-.9-1.7-2s.8-2 1.7-2 1.7.9 1.7 2-.7 2-1.7 2Z" />
            </svg>
        );
    }

    if (code === 'facebook') {
        return (
            <svg viewBox="0 0 24 24" style={box} aria-hidden fill={colour}>
                <path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.2c-1.3 0-1.7.8-1.7 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12Z" />
            </svg>
        );
    }

    // Lettermark: the service's own colour, its own initial, no invented artwork.
    const initial = { notion: 'N', affine: 'A', mega: 'M', proton_drive: 'P', icloud: 'i' }[code]
        ?? code.charAt(0).toUpperCase();

    return (
        <span
            aria-hidden
            style={{ ...box, background: colour }}
            className="flex items-center justify-center rounded-lg text-sm font-bold text-black/80"
        >
            {initial}
        </span>
    );
}
