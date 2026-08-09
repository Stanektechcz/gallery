import { lastSeenLabel } from '@/lib/lastSeen';
import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import AudioRecorder from '@/Components/AudioRecorder';
import ChatMessageItem, { type ChatMessage } from '@/Components/ChatMessageItem';
import MessageDetail from '@/Components/MessageDetail';
import PresenceDot from '@/Components/PresenceDot';
import TypingBubble from '@/Components/TypingBubble';
import { useAutoGrow } from '@/lib/useAutoGrow';
import EmojiPicker from '@/Components/EmojiPicker';
import GifPicker, { type Gif } from '@/Components/GifPicker';
import { recordingFilename } from '@/lib/microphone';
import { ChevronDown, ImagePlus, Loader2, Maximize2, MessagesSquare, Mic, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';


interface Conversation {
    uuid: string; kind: 'direct' | 'group'; title: string;
    icon: string | null; unread: number;
}

interface Other {
    id: number; name: string | null;
    online: boolean; in_chat: boolean; typing: boolean;
    last_seen_at: string | null; read_up_to: number;
}

/** Closed docks ask rarely; an open one keeps up with the conversation. */
const CLOSED_INTERVAL = 30000;
const OPEN_STEPS = [2000, 2000, 4000, 8000];

const time = (value: string | null) =>
    value ? new Date(value).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }) : '';

/**
 * The conversation as a panel, sitting above the assistant button.
 *
 * It shares the same endpoints as the full page, so the two stay in step without either
 * owning the other. While closed it still polls, slowly, for one reason only: to know
 * whether to show the unread badge — a messenger that only tells you about messages once
 * you open it is not telling you anything.
 */
export default function ChatDock() {
    const page = usePage().props as {
        auth?: { user?: { id?: number } };
        features?: string[] | null;
        chatBootstrap?: { conversation: Conversation; messages: ChatMessage[]; cursor: number } | null;
    };
    const features = page.features ?? null;
    const available = !features || features.includes('chat');
    const boot = page.chatBootstrap ?? null;

    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState<ChatMessage[]>(boot?.messages ?? []);
    const [others, setOthers] = useState<Other[]>([]);
    const [unread, setUnread] = useState(0);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [loading, setLoading] = useState(!boot);
    const [conversations, setConversations] = useState<Conversation[]>([]);
    const [current, setCurrent] = useState<string | null>(boot?.conversation?.uuid ?? null);
    const [switcher, setSwitcher] = useState(false);
    const [pendingImage, setPendingImage] = useState<File | null>(null);
    const [pendingGif, setPendingGif] = useState<Gif | null>(null);
    const [recording, setRecording] = useState(false);
    const [detailFor, setDetailFor] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const composer = useRef<HTMLTextAreaElement>(null);
    const currentRef = useRef<string | null>(null);
    currentRef.current = current;

    const cursor = useRef(boot?.cursor ?? 0);
    const quiet = useRef(0);
    const bottom = useRef<HTMLDivElement>(null);
    const openRef = useRef(open);
    openRef.current = open;

    useAutoGrow(composer, draft);

    const poll = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/chat', {
                params: {
                    conversation: currentRef.current ?? undefined,
                    after: cursor.current || undefined,
                    // Closed, the panel is not reading; the server must not mark it read.
                    peek: openRef.current ? undefined : 1,
                },
            });
            const fresh: ChatMessage[] = response.data.messages ?? [];
            setOthers(response.data.others ?? []);
            if (!currentRef.current && response.data.conversation?.uuid) {
                setCurrent(response.data.conversation.uuid);
            }

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

    // Opening lands on the newest message; later arrivals glide.
    const opened = useRef(false);
    useEffect(() => {
        if (!open) { opened.current = false; return; }
        setUnread(0);
        bottom.current?.scrollIntoView({ behavior: opened.current ? 'smooth' : 'auto' });
        opened.current = true;
    }, [open, messages]);

    useEffect(() => {
        if (!open) return;
        void axios.get('/api/v1/konverzace')
            .then(response => setConversations(response.data.conversations ?? []))
            .catch(() => { /* The dock still works on whichever conversation is open. */ });
    }, [open]);

    /** Switching resets the cursor: the new conversation has its own message ids. */
    const choose = (uuid: string) => {
        setSwitcher(false);
        if (uuid === current) return;
        setCurrent(uuid);
        currentRef.current = uuid;
        cursor.current = 0;
        quiet.current = 0;
        setMessages([]);
        setLoading(true);
        void poll();
    };

    /** One path for every kind of message, so nothing is special-cased at the call site. */
    const post = async (build: (form: FormData) => void) => {
        setSending(true);
        try {
            const form = new FormData();
            if (currentRef.current) form.append('conversation', currentRef.current);
            build(form);
            const response = await axios.post('/api/v1/chat', form);
            setMessages(current => [...current, response.data].slice(-80));
            cursor.current = Math.max(cursor.current, response.data.id);
            quiet.current = 0;
            return true;
        } catch { return false; }
        finally { setSending(false); }
    };

    const sendVoice = async (blob: Blob, durationMs: number) => {
        const ok = await post(form => {
            form.append('audio', blob, recordingFilename('hlasovka', blob));
            form.append('duration_ms', String(durationMs));
        });
        if (ok) setRecording(false);
    };

    const typingSent = useRef(0);

    /** At most one announcement every few seconds, not one per keypress. */
    const announceTyping = () => {
        const now = Date.now();
        if (now - typingSent.current < 3000) return;
        typingSent.current = now;
        void axios.post('/api/v1/chat/pise', currentRef.current ? { conversation: currentRef.current } : {})
            .catch(() => { /* Cosmetic; it must never block typing. */ });
    };

    const react = async (message: ChatMessage, emoji: string) => {
        try {
            const response = await axios.post(`/api/v1/chat/${message.uuid}/reakce`, { emoji });
            setMessages(current => current.map(item =>
                item.uuid === response.data.uuid ? { ...item, reactions: response.data.reactions } : item));
        } catch { /* A failed reaction is not worth interrupting the conversation for. */ }
    };

    const send = async () => {
        const body = draft.trim();
        if ((!body && !pendingImage && !pendingGif) || sending) return;
        const ok = await post(form => {
            if (body) form.append('body', body);
            if (pendingImage) form.append('image', pendingImage);
            if (pendingGif) {
                form.append('gif_url', pendingGif.url);
                form.append('gif_width', String(pendingGif.width));
                form.append('gif_height', String(pendingGif.height));
            }
        });
        if (!ok) return;
        setDraft('');
        setPendingImage(null);
        setPendingGif(null);
        if (fileInput.current) fileInput.current.value = '';
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
            {detailFor && (
                <MessageDetail
                    uuid={detailFor}
                    onClose={() => setDetailFor(null)}
                    onDeleted={() => setMessages(current => current.filter(item => item.uuid !== detailFor))}
                />
            )}

            {open && (
                <section
                    aria-label="Chat"
                    // Above the assistant button, which sits at bottom-[4.5rem] on mobile.
                    className="fixed bottom-[15rem] right-4 z-[720] flex max-h-[60vh] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] shadow-2xl md:bottom-[11.5rem] md:right-5"
                >
                    <header className="flex shrink-0 items-center gap-2 border-b border-[var(--color-border)] px-3 py-2.5">
                        <div className="relative min-w-0 flex-1">
                            <button
                                type="button"
                                onClick={() => setSwitcher(value => !value)}
                                aria-expanded={switcher}
                                className="flex min-w-0 items-center gap-1 text-left"
                            >
                                <span className="truncate text-sm font-semibold text-[var(--color-text-primary)]">
                                    {conversations.find(item => item.uuid === current)?.title
                                        ?? (others.length === 1 ? others[0].name ?? 'Chat' : 'Chat')}
                                </span>
                                <ChevronDown size={16} className="shrink-0 text-[var(--color-text-secondary)]" />
                            </button>

                            {switcher && (
                                <>
                                    <button type="button" aria-label="Zavřít výběr" onClick={() => setSwitcher(false)} className="fixed inset-0 z-[725] cursor-default" />
                                    <div className="absolute left-0 top-full z-[730] mt-1 max-h-56 w-56 overflow-y-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-1 shadow-2xl">
                                        {conversations.length === 0 && <p className="px-2 py-2 text-[11px] text-[var(--color-text-secondary)]">Zatím žádná konverzace.</p>}
                                        {conversations.map(item => (
                                            <button
                                                key={item.uuid}
                                                type="button"
                                                onClick={() => choose(item.uuid)}
                                                className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs ${item.uuid === current ? 'bg-[var(--color-accent)]/15 text-[var(--color-accent-contrast)]' : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'}`}
                                            >
                                                <span className="w-4 shrink-0 text-center">{item.icon ?? (item.kind === 'group' ? '👥' : '💬')}</span>
                                                <span className="min-w-0 flex-1 truncate">{item.title}</span>
                                                {item.unread > 0 && <span className="shrink-0 rounded-full bg-[var(--color-accent)] px-1.5 text-[10px] text-[var(--color-accent-contrast)]">{item.unread}</span>}
                                            </button>
                                        ))}
                                        <Link href="/chat" className="mt-1 block border-t border-[var(--color-border)] px-2 pt-2 text-[11px] text-[var(--color-accent)]">Spravovat konverzace →</Link>
                                    </div>
                                </>
                            )}
                            <p className="flex items-center gap-1.5 truncate text-[11px] text-[var(--color-text-secondary)]">
                                {others.length === 1 && <PresenceDot person={others[0]} size={7} />}
                                {status}
                            </p>
                        </div>
                        <Link href="/chat" aria-label="Otevřít celý chat" className="p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                            <Maximize2 size={16} />
                        </Link>
                        <button type="button" onClick={() => setOpen(false)} aria-label="Zavřít chat" className="p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                            <X size={16} />
                        </button>
                    </header>

                    {others.length > 1 && (
                        <div className="flex shrink-0 flex-wrap gap-1 border-b border-[var(--color-border)] px-3 py-1.5">
                            {others.map(other => (
                                <span key={other.id} className="inline-flex items-center gap-1.5 rounded-full bg-[var(--color-surface-muted)] px-2 py-1 text-[10px] text-[var(--color-text-secondary)]">
                                    <PresenceDot person={other} size={7} note={lastSeenLabel(other.last_seen_at, other.online)} />
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
                            <ChatMessageItem
                                key={message.id}
                                compact
                                message={message}
                                read={others.some(other => other.read_up_to >= message.id)}
                                onReact={emoji => void react(message, emoji)}
                                onOpenDetail={() => setDetailFor(message.uuid)}
                            />
                        ))}

                        {others.some(other => other.typing) && (
                            <TypingBubble who={others.filter(other => other.typing).map(other => other.name ?? 'Partner').join(', ')} />
                        )}

                        <div ref={bottom} />
                    </div>

                    {(pendingImage || pendingGif) && (
                        <div className="flex shrink-0 items-center gap-2 border-t border-[var(--color-border)] px-2 py-1.5">
                            <img src={pendingGif ? pendingGif.preview : URL.createObjectURL(pendingImage!)} alt="" className="h-8 w-8 rounded object-cover" />
                            <p className="min-w-0 flex-1 truncate text-[10px] text-[var(--color-text-secondary)]">{pendingGif ? pendingGif.description : pendingImage?.name}</p>
                            <button type="button" onClick={() => { setPendingImage(null); setPendingGif(null); if (fileInput.current) fileInput.current.value = ''; }} aria-label="Odebrat přílohu" className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:text-red-300"><X size={16}/></button>
                        </div>
                    )}

                    {recording && (
                        <div className="relative shrink-0 border-t border-[var(--color-border)] px-2">
                            <button
                                type="button"
                                onClick={() => setRecording(false)}
                                aria-label="Zrušit nahrávání"
                                className="absolute right-2 top-2 z-10 flex h-7 w-7 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                            >
                                <X size={15} />
                            </button>
                            <AudioRecorder
                                busy={sending}
                                withTitle={false}
                                maxSeconds={180}
                                label="Nahrát hlasovku"
                                onRecorded={(blob, durationMs) => sendVoice(blob, durationMs)}
                            />
                        </div>
                    )}

                    <div className="flex shrink-0 items-end gap-0.5 border-t border-[var(--color-border)] p-2">
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            className="hidden"
                            onChange={event => {
                                const file = event.target.files?.[0] ?? null;
                                if (file) setPendingGif(null);
                                setPendingImage(file);
                            }}
                        />
                        <button type="button" onClick={() => fileInput.current?.click()} aria-label="Přiložit obrázek" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"><ImagePlus size={16}/></button>
                        <button
                            type="button"
                            onPointerDown={event => { if (event.pointerType !== 'mouse') setRecording(true); }}
                            onClick={event => { if ((event as any).nativeEvent?.pointerType === 'mouse' || !('ontouchstart' in window)) setRecording(value => !value); }}
                            aria-label="Hlasovka" aria-pressed={recording} className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg hover:bg-[var(--color-surface-hover)] ${recording ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'}`}><Mic size={16}/></button>
                        <GifPicker compact onPick={gif => { setPendingImage(null); setPendingGif(gif); }} />
                        <EmojiPicker compact keepOpen onPick={emoji => setDraft(current => current + emoji)} />
                        <textarea
                            value={draft}
                            onChange={event => { setDraft(event.target.value); announceTyping(); }}
                            onKeyDown={event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void send(); } }}
                            ref={composer}
                            rows={1}
                            maxLength={4000}
                            placeholder="Napsat…"
                            className="min-h-9 flex-1 resize-none rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2.5 py-2 text-[13px] leading-5 text-[var(--color-text-primary)] placeholder-[var(--color-text-secondary)] focus:border-[var(--color-accent)] focus:outline-none"
                        />
                        <button type="button" onClick={() => void send()} disabled={sending || (!draft.trim() && !pendingImage && !pendingGif)} aria-label="Odeslat" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--color-accent)] text-[var(--color-accent-contrast)] disabled:opacity-40">
                            {sending ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
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
                className="fixed bottom-[9rem] right-4 z-[715] flex h-12 w-12 items-center justify-center rounded-full border border-[var(--color-border)] bg-[var(--color-bg-secondary)] text-[var(--color-text-primary)] shadow-lg md:bottom-[5.75rem] md:right-5"
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
