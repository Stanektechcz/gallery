import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, MessagesSquare, Send, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Message {
    id: number;
    uuid: string;
    body: string;
    author: { id: number; name: string | null };
    is_mine: boolean;
    edited: boolean;
    sent_at: string | null;
}

interface Other {
    id: number;
    name: string | null;
    online: boolean;
    typing: boolean;
    read_up_to: number;
}

/** Quiet tabs ask less often; the interval climbs while nothing happens. */
const IDLE_STEPS = [2000, 2000, 4000, 8000, 15000];

const time = (value: string | null) =>
    value ? new Date(value).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }) : '';

export default function ChatIndex() {
    const [messages, setMessages] = useState<Message[]>([]);
    const [others, setOthers] = useState<Other[]>([]);
    const [draft, setDraft] = useState('');
    const [loading, setLoading] = useState(true);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');

    const cursor = useRef(0);
    const quiet = useRef(0);
    const bottom = useRef<HTMLDivElement>(null);
    const typingSent = useRef(0);
    const stuckToBottom = useRef(true);

    const poll = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/chat', { params: { after: cursor.current || undefined } });
            const fresh: Message[] = response.data.messages ?? [];
            setOthers(response.data.others ?? []);
            setError('');

            if (fresh.length) {
                quiet.current = 0;
                cursor.current = response.data.cursor ?? cursor.current;
                // Messages arrive as a delta, so append rather than replace — and guard
                // against a duplicate if a send and a poll overlap.
                setMessages(current => {
                    const known = new Set(current.map(item => item.id));
                    return [...current, ...fresh.filter(item => !known.has(item.id))];
                });
            } else {
                quiet.current = Math.min(quiet.current + 1, IDLE_STEPS.length - 1);
            }
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Spojení s chatem se přerušilo.');
        } finally { setLoading(false); }
    }, []);

    // A single self-rescheduling timer, so the pace can change between ticks.
    useEffect(() => {
        let active = true;
        let handle: number | undefined;

        const tick = async () => {
            if (!active) return;
            // Nothing is read while the tab is hidden; there is nobody looking.
            if (document.visibilityState === 'visible') await poll();
            if (!active) return;
            handle = window.setTimeout(tick, IDLE_STEPS[quiet.current]);
        };

        void tick();
        const wake = () => { quiet.current = 0; void poll(); };
        document.addEventListener('visibilitychange', wake);

        return () => {
            active = false;
            if (handle) window.clearTimeout(handle);
            document.removeEventListener('visibilitychange', wake);
        };
    }, [poll]);

    // Follow the conversation, unless the reader has scrolled up to look at something.
    useEffect(() => {
        if (stuckToBottom.current) bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    const onScroll = (event: React.UIEvent<HTMLDivElement>) => {
        const element = event.currentTarget;
        stuckToBottom.current = element.scrollHeight - element.scrollTop - element.clientHeight < 80;
    };

    const announceTyping = () => {
        // At most one announcement every few seconds, not one per keypress.
        const now = Date.now();
        if (now - typingSent.current < 3000) return;
        typingSent.current = now;
        void axios.post('/api/v1/chat/pise').catch(() => { /* Cosmetic; never blocks typing. */ });
    };

    const send = async () => {
        const body = draft.trim();
        if (!body || sending) return;
        setSending(true); setError('');
        try {
            const response = await axios.post('/api/v1/chat', { body });
            setDraft('');
            stuckToBottom.current = true;
            setMessages(current => current.some(item => item.id === response.data.id) ? current : [...current, response.data]);
            cursor.current = Math.max(cursor.current, response.data.id);
            quiet.current = 0;
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Zprávu se nepodařilo odeslat.');
        } finally { setSending(false); }
    };

    const remove = async (message: Message) => {
        if (!window.confirm('Smazat zprávu?')) return;
        try {
            await axios.delete(`/api/v1/chat/${message.uuid}`);
            setMessages(current => current.filter(item => item.id !== message.id));
        } catch { setError('Zprávu se nepodařilo smazat.'); }
    };

    const typing = others.filter(other => other.typing);
    const online = others.filter(other => other.online);

    return (
        <AppLayout title="Chat">
            <Head title="Chat" />
            <div className="mx-auto flex h-full max-w-3xl flex-col p-4 sm:p-6">
                <header className="shrink-0">
                    <h1 className="flex items-center gap-2 text-xl font-bold text-[var(--color-text-primary)]">
                        <MessagesSquare size={22} className="text-[var(--color-accent)]" /> Chat
                    </h1>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]" aria-live="polite">
                        {typing.length
                            ? `${typing.map(other => other.name ?? 'partner').join(', ')} píše…`
                            : online.length
                                ? `${online.map(other => other.name ?? 'partner').join(', ')} je online`
                                : 'Nikdo další teď není v konverzaci'}
                    </p>
                </header>

                {error && <p role="alert" className="mt-3 shrink-0 rounded-xl border border-red-400/25 bg-red-500/10 p-2.5 text-xs text-red-100">{error}</p>}

                <div onScroll={onScroll} className="mt-4 min-h-0 flex-1 space-y-2 overflow-y-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                    {loading && <div className="flex justify-center py-8"><Loader2 className="animate-spin text-[var(--color-accent)]" /></div>}
                    {!loading && messages.length === 0 && (
                        <p className="py-8 text-center text-sm text-[var(--color-text-secondary)]">Zatím tu nic není. Napište první zprávu.</p>
                    )}

                    {messages.map(message => (
                        <div key={message.id} className={`group flex ${message.is_mine ? 'justify-end' : 'justify-start'}`}>
                            <div className={`max-w-[80%] rounded-2xl px-3 py-2 ${message.is_mine
                                ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]'
                                : 'bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'}`}>
                                {!message.is_mine && <p className="text-[10px] opacity-75">{message.author.name}</p>}
                                <p className="whitespace-pre-wrap break-words text-sm">{message.body}</p>
                                <p className="mt-0.5 text-right text-[10px] opacity-65">
                                    {time(message.sent_at)}{message.edited && ' · upraveno'}
                                    {message.is_mine && others.some(other => other.read_up_to >= message.id) && ' · přečteno'}
                                </p>
                            </div>
                            {message.is_mine && (
                                <button
                                    type="button"
                                    onClick={() => void remove(message)}
                                    aria-label="Smazat zprávu"
                                    className="ml-1 self-center p-1 text-[var(--color-text-secondary)] opacity-0 transition-opacity hover:text-red-300 focus:opacity-100 group-hover:opacity-100"
                                >
                                    <Trash2 size={13} />
                                </button>
                            )}
                        </div>
                    ))}
                    <div ref={bottom} />
                </div>

                <div className="mt-3 flex shrink-0 items-end gap-2">
                    <textarea
                        value={draft}
                        onChange={event => { setDraft(event.target.value); announceTyping(); }}
                        onKeyDown={event => {
                            // Enter sends; Shift+Enter is a new line, as everywhere else.
                            if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void send(); }
                        }}
                        rows={1}
                        maxLength={4000}
                        placeholder="Napište zprávu…"
                        className="max-h-32 min-h-11 flex-1 resize-none rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                    />
                    <button
                        type="button"
                        onClick={() => void send()}
                        disabled={sending || !draft.trim()}
                        aria-label="Odeslat"
                        className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--color-accent)] text-[var(--color-accent-contrast)] disabled:opacity-40"
                    >
                        {sending ? <Loader2 size={17} className="animate-spin" /> : <Send size={17} />}
                    </button>
                </div>
            </div>
        </AppLayout>
    );
}
