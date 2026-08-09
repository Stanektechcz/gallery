import { Link } from '@inertiajs/react';

/** Matches a stored mention: [[type:id|Label]] */
const TOKEN = /\[\[(\w+):([^|\]]+)\|([^\]]*)\]\]/g;

const ICON: Record<string, string> = {
    event: '📅', recipe: '🍳', journal: '📔', person: '👤',
};

const HREF: Record<string, (id: string) => string> = {
    event: id => `/calendar/${id}`,
    recipe: id => `/recipes/${id}`,
    journal: () => '/denik',
    person: () => '/people',
};

/**
 * Renders a message body, turning stored mentions into links.
 *
 * The label travels with the token so a message still reads correctly when the thing it
 * points at is gone, while the link keeps working while it exists — the alternative,
 * resolving every mention on every render, would mean a query per message.
 */
export default function MentionText({ body }: { body: string }) {
    const parts: Array<string | { type: string; id: string; label: string }> = [];
    let cursor = 0;

    for (const match of body.matchAll(TOKEN)) {
        const at = match.index ?? 0;
        if (at > cursor) parts.push(body.slice(cursor, at));
        parts.push({ type: match[1], id: match[2], label: match[3] });
        cursor = at + match[0].length;
    }
    if (cursor < body.length) parts.push(body.slice(cursor));

    return (
        <p className="whitespace-pre-wrap break-words leading-relaxed">
            {parts.map((part, index) => {
                if (typeof part === 'string') return <span key={index}>{part}</span>;

                const href = HREF[part.type]?.(part.id);
                const label = `${ICON[part.type] ?? '🔗'} ${part.label}`;

                // Without a known destination it still reads as a mention, just not a link.
                if (!href) return <span key={index} className="font-medium underline decoration-dotted">{label}</span>;

                return (
                    <Link
                        key={index}
                        href={href}
                        onClick={event => event.stopPropagation()}
                        className="font-medium underline decoration-dotted underline-offset-2 hover:decoration-solid"
                    >
                        {label}
                    </Link>
                );
            })}
        </p>
    );
}
