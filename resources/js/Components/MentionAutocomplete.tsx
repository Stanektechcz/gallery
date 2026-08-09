import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface MentionItem {
    type: string; id: string; title: string; detail: string | null; icon: string; url: string;
}

/**
 * Reads the "@…" the person is in the middle of typing.
 *
 * Only the run of characters between the last "@" and the caret counts, and only when
 * the "@" starts a word — so an email address in a message never opens a picker.
 */
export function activeMention(text: string, caret: number): { query: string; from: number } | null {
    const before = text.slice(0, caret);
    const at = before.lastIndexOf('@');
    if (at < 0) return null;

    const preceding = at === 0 ? ' ' : before[at - 1];
    if (!/\s/.test(preceding)) return null;

    const query = before.slice(at + 1);
    // A mention is a short run; a newline or a long stretch means they moved on.
    if (query.includes('\n') || query.length > 40) return null;

    return { query, from: at };
}

/**
 * The list under a half-typed mention.
 *
 * A date is answered with that day's plans, a word with matching recipes, events, diary
 * entries and people — which is why the header says which kind of answer this is.
 */
export default function MentionAutocomplete({ query, onPick, onDismiss }: {
    query: string;
    onPick: (item: MentionItem) => void;
    onDismiss: () => void;
}) {
    const [items, setItems] = useState<MentionItem[]>([]);
    const [label, setLabel] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        let active = true;
        setLoading(true);
        const handle = window.setTimeout(() => {
            void axios.get('/api/v1/chat/zminky', { params: { q: query || undefined } })
                .then(response => {
                    if (!active) return;
                    setItems(response.data.items ?? []);
                    setLabel(response.data.label ?? null);
                })
                .catch(() => active && setItems([]))
                .finally(() => active && setLoading(false));
        }, 200);

        return () => { active = false; window.clearTimeout(handle); };
    }, [query]);

    if (!loading && items.length === 0) return null;

    return (
        <>
            <button type="button" aria-label="Zavřít nabídku" onClick={onDismiss} className="fixed inset-0 z-[735] cursor-default" />
            <div className="absolute bottom-full left-0 right-0 z-[740] mb-1 max-h-56 overflow-y-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-1 shadow-2xl">
                {label && (
                    <p className="px-2 py-1 text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">
                        Plán na {label}
                    </p>
                )}
                {loading && items.length === 0 && (
                    <div className="flex justify-center py-3"><Loader2 size={16} className="animate-spin text-[var(--color-accent)]" /></div>
                )}
                {items.map(item => (
                    <button
                        key={`${item.type}-${item.id}`}
                        type="button"
                        onClick={() => onPick(item)}
                        className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                    >
                        <span className="w-5 shrink-0 text-center">{item.icon}</span>
                        <span className="min-w-0 flex-1 truncate text-[var(--color-text-primary)]">{item.title}</span>
                        {item.detail && <span className="shrink-0 text-[10px]">{item.detail}</span>}
                    </button>
                ))}
            </div>
        </>
    );
}

/** The token a picked mention becomes; the server stores exactly this. */
export const mentionToken = (item: MentionItem) =>
    `[[${item.type}:${item.id}|${item.title.replace(/[|\]]/g, '')}]]`;
