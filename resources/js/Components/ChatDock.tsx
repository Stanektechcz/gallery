import { lastSeenLabel } from '@/lib/lastSeen';
import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, Maximize2, MessagesSquare, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Message {
    id: number; uuid: string; body: string;
    media: { url: string; kind: string } | null;
    author: { id: number; name: string | null };
    is_mine: boolean; sent_at: string | null;
}

interface Other {
    id: number; name: string | null;
    online: boolean; in_chat: boolean; typing: boolean;
    last_seen_at: string | null; read_up_to: number;
}

/** Closed docks ask rarely; an open one keeps up with the conversation. */
const CLOSED_INTERVAL = 30000;
const OPEN_STEPS = [2000, 2000, 4000, 8000];

/**
 * The conversation as a panel, sitting above the assistant button.
 *
 * It shares the same endpoints as the full page, so the two stay in step without either
 * owning the other. While closed it still polls, slowly, for one reason only: to know
 * whether to show the unread badge — a messenger that only tells you about messages once
 * you open it is not telling you anything.
 */
export default function ChatDock() {
    const page = usePage().props as { auth?: { user?: { id?: number } }; features?: string[] | null };
    const features = page.features ?? null;
    const available = !features || features.includes('chat');

    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState<Message[]>([]);
    const [others, setOthers] = useState<Other[]>([]);
    const [unread, setUnread] = useState(0);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [loading, setLoading] = useState(true);

    const cursor = useRef(0);
    const quiet = useRef(0);
    const bottom = useRef<HTMLDivElement>(null);
    const openRef = useRef(open);
    openRef.current = open;

    const poll = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/chat', {
                params: {
                    after: cursor.current || undefined,
                    // Closed, the panel is not reading; the server must not mark it read.
                    peek: openRef.current ? undefined : 1,
                },
            });
            const fresh: Message[] = response.data.messages ?? [];
            setOthers(response.data.others ?? []);

            if (fresh.length) {
                quiet.current = 0;
                cursor.current = response.data.cursor ?? cursor.current;
                setMessages(current => {
                    const known = new Set(current.map(item => item.id));
                    return [...current, ...fresh.filter(item => !known.has(item.id))].slice(-80);
                });
                if (!openRef.current) {
                    setUnread(count => count + fresh.filter(item => !item.is_mine).length);
                }
            } else {
                quiet.current = Math.min(quiet.current + 1, OPEN_STEPS.length - 1);
            }
        } catch { /* A dock that cannot reach the server just stays quiet. */ }
        finally { setLoading(false); }
    }, []);

    useEffect(() => {
        if (!available) return;
        let active = true;
        let handle: number | undefined;

        const tick = async () => {
            if (!active) return;
            if (document.visibilityState === 'visible') await poll();
            if (!active) return;
            handle = window.setTimeout(tick, openRef.current ? OPEN_STEPS[quiet.current] : CLOSED_INTERVAL);
        };

        void tick();
        return () => { active = false; if (handle) window.clearTimeout(handle); };
    }, [available, poll, open]);

    useEffect(() => {
        if (open) { setUnread(0); bottom.current?.scrollIntoView(); }
    }, [open, messages]);

    const send = async () => {
        const body = draft.trim();
        if (!body || sending) return;
        setSending(true);
        try {
            const form = new FormData();
            form.append('body', body);
            const response = await axios.post('/api/v1/chat', form);
            setDraft('');
            setMessages(current => [...current, response.data].slice(-80));
            cursor.current = Math.max(cursor.current, response.data.id);
            quiet.current = 0;
        } catch { /* Surfaced on the full page; the dock keeps the text so nothing is lost. */ }
        finally { setSending(false); }
    };

    if (!available) return null;

    const typing = others.filter(other => other.typing);
    const status = typing.length
        ? `${typing.map(other => other.name ?? 'partner').join(', ')} píše…`
        : others.length === 1
            ? lastSeenLabel(others[0].last_seen_at, others[0].online)
            : `${others.filter(other => other.online).length} z ${others.length} online`;

    return (
        <>
            {open && (
                <section
                    aria-label="Chat"
                    // Above the assistant button, which sits at bottom-[4.5rem] on mobile.
                    className="fixed bottom-[8.5rem] right-4 z-[720] flex max-h-[60vh] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] shadow-2xl md:bottom-[9rem] md:right-5"
                >
                    <header className="flex shrink-0 items-center gap-2 border-b border-[var(--color-border)] px-3 py-2.5">
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold text-[var(--color-text-primary)]">
                                {others.length === 1 ? others[0].name ?? 'Chat' : 'Chat'}
                            </p>
                            <p className="flex items-center gap-1.5 truncate text-[11px] text-[var(--color-text-secondary)]">
                                {others.some(other => other.online) && (
                                    <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-400" aria-hidden />
                                )}
                                {status}
                            </p>
                        </div>
                        <Link href="/chat" aria-label="Otevřít celý chat" className="p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                            <Maximize2 size={15} />
                        </Link>
                        <button type="button" onClick={() => setOpen(false)} aria-label="Zavřít chat" className="p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                            <X size={16} />
                        </button>
                    </header>

                    {others.length > 1 && (
                        <div className="flex shrink-0 flex-wrap gap-1 border-b border-[var(--color-border)] px-3 py-1.5">
                            {others.map(other => (
                                <span key={other.id} title={lastSeenLabel(other.last_seen_at, other.online)} className="inline-flex items-center gap-1 rounded-full bg-[var(--color-surface-muted)] px-1.5 py-0.5 text-[10px] text-[var(--color-text-secondary)]">
                                    <span className={`h-1.5 w-1.5 rounded-full ${other.in_chat ? 'bg-emerald-400' : other.online ? 'bg-amber-400' : 'bg-[var(--color-text-secondary)]/40'}`} aria-hidden />
                                    {other.name}
                                </span>
                            ))}
                        </div>
                    )}

                    <div className="min-h-0 flex-1 space-y-1.5 overflow-y-auto p-3">
                        {loading && <div className="flex justify-center py-4"><Loader2 size={16} className="animate-spin text-[var(--color-accent)]" /></div>}
                        {!loading && messages.length === 0 && (
                            <p className="py-4 text-center text-xs text-[var(--color-text-secondary)]">Zatím tu nic není.</p>
                        )}
                        {messages.map(message => (
                            <div key={message.id} className={`flex ${message.is_mine ? 'justify-end' : 'justify-start'}`}>
                                <div className={`max-w-[85%] rounded-xl px-2.5 py-1.5 ${message.is_mine
                                    ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]'
                                    : 'bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'}`}>
                                    {message.media && <img src={message.media.url} alt="" loading="lazy" className="mb-1 max-h-32 w-full rounded-lg object-cover" />}
                                    {message.body && <p className="whitespace-pre-wrap break-words text-xs">{message.body}</p>}
                                </div>
                            </div>
                        ))}
                        <div ref={bottom} />
                    </div>

                    <div className="flex shrink-0 items-end gap-1.5 border-t border-[var(--color-border)] p-2">
                        <textarea
                            value={draft}
                            onChange={event => setDraft(event.target.value)}
                            onKeyDown={event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void send(); } }}
                            rows={1}
                            maxLength={4000}
                            placeholder="Napsat…"
                            className="max-h-24 min-h-9 flex-1 resize-none rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2.5 py-2 text-xs text-[var(--color-text-primary)]"
                        />
                        <button type="button" onClick={() => void send()} disabled={sending || !draft.trim()} aria-label="Odeslat" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--color-accent)] text-[var(--color-accent-contrast)] disabled:opacity-40">
                            {sending ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
                        </button>
                    </div>
                </section>
            )}

            <button
                type="button"
                onClick={() => setOpen(value => !value)}
                aria-label={unread ? `Chat, ${unread} nepřečtených` : 'Chat'}
                aria-expanded={open}
                // Directly above the assistant, sharing its right edge.
                className="fixed bottom-[8rem] right-4 z-[715] flex h-12 w-12 items-center justify-center rounded-full border border-[var(--color-border)] bg-[var(--color-bg-secondary)] text-[var(--color-text-primary)] shadow-lg md:bottom-[4.5rem] md:right-5"
            >
                <MessagesSquare size={20} />
                {unread > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-[var(--color-accent)] px-1 text-[10px] font-bold text-[var(--color-accent-contrast)]">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>
        </>
    );
}
