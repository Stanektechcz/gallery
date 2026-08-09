import AppLayout from '@/Layouts/AppLayout';
import PresenceDot from '@/Components/PresenceDot';
import { lastSeenLabel } from '@/lib/lastSeen';
import { useAutoGrow } from '@/lib/useAutoGrow';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import EmojiPicker from '@/Components/EmojiPicker';
import GifPicker, { type Gif } from '@/Components/GifPicker';
import { ImagePlus, Loader2, MessagesSquare, Send, SmilePlus, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Reaction { emoji: string; count: number; mine: boolean }

interface Media { url: string; kind: 'image' | 'gif'; width: number | null; height: number | null }

interface Message {
    id: number;
    uuid: string;
    body: string;
    media: Media | null;
    reactions: Reaction[];
    author: { id: number; name: string | null };
    is_mine: boolean;
    edited: boolean;
    sent_at: string | null;
}

interface Other {
    id: number;
    name: string | null;
    online: boolean;
    in_chat: boolean;
    typing: boolean;
    last_seen_at: string | null;
    read_up_to: number;
}

/** Quiet tabs ask less often; the interval climbs while nothing happens. */
const IDLE_STEPS = [2000, 2000, 4000, 8000, 15000];

/** The handful worth one tap; everything else lives in the picker. */
const QUICK_REACTIONS = ['❤️', '😂', '👍', '😮', '😢', '🔥'];

const time = (value: string | null) =>
    value ? new Date(value).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }) : '';

export default function ChatIndex() {
    const [messages, setMessages] = useState<Message[]>([]);
    const [others, setOthers] = useState<Other[]>([]);
    const [draft, setDraft] = useState('');
    const [loading, setLoading] = useState(true);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const [pendingImage, setPendingImage] = useState<File | null>(null);
    const [pendingGif, setPendingGif] = useState<Gif | null>(null);
    const [pickerFor, setPickerFor] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const cursor = useRef(0);
    const quiet = useRef(0);
    const bottom = useRef<HTMLDivElement>(null);
    const typingSent = useRef(0);
    const stuckToBottom = useRef(true);
    const composer = useRef<HTMLTextAreaElement>(null);

    useAutoGrow(composer, draft);

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
        // A picture or a GIF is a message on its own; only all three being empty is not.
        if ((!body && !pendingImage && !pendingGif) || sending) return;
        setSending(true); setError('');
        try {
            const form = new FormData();
            if (body) form.append('body', body);
            if (pendingImage) form.append('image', pendingImage);
            if (pendingGif) {
                form.append('gif_url', pendingGif.url);
                form.append('gif_width', String(pendingGif.width));
                form.append('gif_height', String(pendingGif.height));
            }
            const response = await axios.post('/api/v1/chat', form);
            setDraft('');
            setPendingImage(null);
            setPendingGif(null);
            if (fileInput.current) fileInput.current.value = '';
            stuckToBottom.current = true;
            setMessages(current => current.some(item => item.id === response.data.id) ? current : [...current, response.data]);
            cursor.current = Math.max(cursor.current, response.data.id);
            quiet.current = 0;
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Zprávu se nepodařilo odeslat.');
        } finally { setSending(false); }
    };

    const react = async (message: Message, emoji: string) => {
        setPickerFor(null);
        try {
            const response = await axios.post(`/api/v1/chat/${message.uuid}/reakce`, { emoji });
            setMessages(current => current.map(item =>
                item.uuid === response.data.uuid ? { ...item, reactions: response.data.reactions } : item));
        } catch { setError('Reakci se nepodařilo uložit.'); }
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
                    <p className="mt-1 flex items-center gap-2 text-xs text-[var(--color-text-secondary)]" aria-live="polite">
                        {others.length === 1 && <PresenceDot person={others[0]} />}
                        {typing.length
                            ? `${typing.map(other => other.name ?? 'partner').join(', ')} píše…`
                            : others.length === 1
                                // One partner: say exactly when they were last around.
                                ? `${others[0].name ?? 'Partner'} — ${lastSeenLabel(others[0].last_seen_at, others[0].online)}`
                                : others.length
                                    ? `${online.length} z ${others.length} online`
                                    : 'Zatím jste tu sami'}
                    </p>
                </header>

                {others.length > 1 && (
                    <div className="mt-2 flex shrink-0 flex-wrap gap-1.5">
                        {others.map(other => (
                            <span key={other.id} className="inline-flex items-center gap-2 rounded-full bg-[var(--color-surface-muted)] px-2.5 py-1 text-[11px] text-[var(--color-text-secondary)]">
                                <PresenceDot person={other} />
                                <span className="text-[var(--color-text-primary)]">{other.name}</span>
                                · {lastSeenLabel(other.last_seen_at, other.online)}
                            </span>
                        ))}
                    </div>
                )}

                {error && <p role="alert" className="mt-3 shrink-0 rounded-xl border border-red-400/25 bg-red-500/10 p-2.5 text-xs text-red-100">{error}</p>}

                <div onScroll={onScroll} className="mt-4 min-h-0 flex-1 space-y-2.5 overflow-y-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                    {loading && <div className="flex justify-center py-8"><Loader2 className="animate-spin text-[var(--color-accent)]" /></div>}
                    {!loading && messages.length === 0 && (
                        <p className="py-8 text-center text-sm text-[var(--color-text-secondary)]">Zatím tu nic není. Napište první zprávu.</p>
                    )}

                    {messages.map(message => (
                        <div key={message.id} className={`group flex ${message.is_mine ? 'justify-end' : 'justify-start'}`}>
                            <div className={`max-w-[80%] rounded-2xl px-3.5 py-2.5 ${message.is_mine
                                ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]'
                                : 'bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'}`}>
                                {!message.is_mine && <p className="text-[10px] opacity-75">{message.author.name}</p>}
                                {message.media && (
                                    <img
                                        src={message.media.url}
                                        alt={message.media.kind === 'gif' ? 'GIF' : 'Obrázek ve zprávě'}
                                        loading="lazy"
                                        // The ratio is known for GIFs, so the bubble does not
                                        // jump once the picture arrives.
                                        style={message.media.width && message.media.height
                                            ? { aspectRatio: `${message.media.width} / ${message.media.height}` }
                                            : undefined}
                                        className="mb-1 max-h-72 w-full rounded-xl object-cover"
                                    />
                                )}
                                {message.body && <p className="whitespace-pre-wrap break-words text-sm">{message.body}</p>}
                                <p className="mt-0.5 text-right text-[10px] opacity-65">
                                    {time(message.sent_at)}{message.edited && ' · upraveno'}
                                    {message.is_mine && others.some(other => other.read_up_to >= message.id) && ' · přečteno'}
                                </p>

                                {message.reactions.length > 0 && (
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {message.reactions.map(reaction => (
                                            <button
                                                key={reaction.emoji}
                                                type="button"
                                                onClick={() => void react(message, reaction.emoji)}
                                                aria-pressed={reaction.mine}
                                                className={`rounded-full px-1.5 py-0.5 text-[11px] ${reaction.mine
                                                    ? 'bg-[var(--color-bg-card)] ring-1 ring-[var(--color-accent)]'
                                                    : 'bg-[var(--color-bg-card)]/70'}`}
                                            >
                                                {reaction.emoji} {reaction.count}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div className="relative self-center">
                                <button
                                    type="button"
                                    onClick={() => setPickerFor(pickerFor === message.uuid ? null : message.uuid)}
                                    aria-label="Přidat reakci"
                                    className="p-1 text-[var(--color-text-secondary)] opacity-0 transition-opacity hover:text-[var(--color-text-primary)] focus:opacity-100 group-hover:opacity-100"
                                >
                                    <SmilePlus size={14} />
                                </button>
                                {pickerFor === message.uuid && (
                                    <>
                                        <button type="button" aria-label="Zavřít reakce" onClick={() => setPickerFor(null)} className="fixed inset-0 z-[700] cursor-default" />
                                        <div className="absolute bottom-full right-0 z-[710] mb-1 flex gap-0.5 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-1 shadow-2xl">
                                            {QUICK_REACTIONS.map(emoji => (
                                                <button key={emoji} type="button" onClick={() => void react(message, emoji)} className="rounded-lg p-1 text-base hover:bg-[var(--color-surface-hover)]">{emoji}</button>
                                            ))}
                                        </div>
                                    </>
                                )}
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

                {(pendingImage || pendingGif) && (
                    <div className="mt-3 flex shrink-0 items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2">
                        <img
                            src={pendingGif ? pendingGif.preview : URL.createObjectURL(pendingImage!)}
                            alt="Náhled přílohy"
                            className="h-12 w-12 rounded-lg object-cover"
                        />
                        <p className="min-w-0 flex-1 truncate text-xs text-[var(--color-text-secondary)]">
                            {pendingGif ? pendingGif.description : pendingImage?.name}
                        </p>
                        <button
                            type="button"
                            onClick={() => {
                                setPendingImage(null);
                                setPendingGif(null);
                                if (fileInput.current) fileInput.current.value = '';
                            }}
                            aria-label="Odebrat přílohu"
                            className="p-1 text-[var(--color-text-secondary)] hover:text-red-300"
                        >
                            <X size={16} />
                        </button>
                    </div>
                )}

                <div className="mt-3 flex shrink-0 items-end gap-1">
                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        className="hidden"
                        onChange={event => {
                            const file = event.target.files?.[0] ?? null;
                            // One attachment at a time: a picture replaces a chosen GIF.
                            if (file) setPendingGif(null);
                            setPendingImage(file);
                        }}
                    />
                    <button
                        type="button"
                        onClick={() => fileInput.current?.click()}
                        aria-label="Přiložit obrázek"
                        className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                    >
                        <ImagePlus size={19} />
                    </button>
                    <GifPicker onPick={gif => { setPendingImage(null); setPendingGif(gif); }} />
                    <EmojiPicker onPick={emoji => setDraft(current => current + emoji)} />
                    <textarea
                        value={draft}
                        onChange={event => { setDraft(event.target.value); announceTyping(); }}
                        onKeyDown={event => {
                            // Enter sends; Shift+Enter is a new line, as everywhere else.
                            if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void send(); }
                        }}
                        ref={composer}
                        rows={1}
                        maxLength={4000}
                        placeholder="Napište zprávu…"
                        className="min-h-11 flex-1 resize-none rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm leading-6 text-[var(--color-text-primary)] placeholder-[var(--color-text-secondary)] focus:border-[var(--color-accent)] focus:outline-none"
                    />
                    <button
                        type="button"
                        onClick={() => void send()}
                        disabled={sending || (!draft.trim() && !pendingImage && !pendingGif)}
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
