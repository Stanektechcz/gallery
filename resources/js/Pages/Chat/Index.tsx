import AppLayout from '@/Layouts/AppLayout';
import AudioRecorder from '@/Components/AudioRecorder';
import ChatMessageItem, { type ChatMessage } from '@/Components/ChatMessageItem';
import MessageDetail from '@/Components/MessageDetail';
import PresenceDot from '@/Components/PresenceDot';
import TypingBubble from '@/Components/TypingBubble';
import { recordingFilename } from '@/lib/microphone';
import { lastSeenLabel } from '@/lib/lastSeen';
import { useAutoGrow } from '@/lib/useAutoGrow';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import EmojiPicker from '@/Components/EmojiPicker';
import GifPicker, { type Gif } from '@/Components/GifPicker';
import { ImagePlus, Loader2, MessagesSquare, Mic, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';



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
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [others, setOthers] = useState<Other[]>([]);
    const [draft, setDraft] = useState('');
    const [loading, setLoading] = useState(true);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const [pendingImage, setPendingImage] = useState<File | null>(null);
    const [pendingGif, setPendingGif] = useState<Gif | null>(null);
    const [detailFor, setDetailFor] = useState<string | null>(null);
    const [recording, setRecording] = useState(false);
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
            const fresh: ChatMessage[] = response.data.messages ?? [];
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

    /*
     | Follow the conversation, unless the reader has scrolled up to look at something.
     |
     | The first paint jumps rather than glides: arriving part-way up a conversation and
     | watching it scroll is worse than simply being at the end, which is where anyone
     | opening a chat wants to be.
     */
    const landed = useRef(false);
    useEffect(() => {
        if (!messages.length) return;
        if (!landed.current) {
            landed.current = true;
            bottom.current?.scrollIntoView({ behavior: 'auto' });
            return;
        }
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

    const react = async (message: ChatMessage, emoji: string) => {
        try {
            const response = await axios.post(`/api/v1/chat/${message.uuid}/reakce`, { emoji });
            setMessages(current => current.map(item =>
                item.uuid === response.data.uuid ? { ...item, reactions: response.data.reactions } : item));
        } catch { setError('Reakci se nepodařilo uložit.'); }
    };

    const remove = async (message: ChatMessage) => {
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
                        <ChatMessageItem
                            key={message.id}
                            message={message}
                            read={others.some(other => other.read_up_to >= message.id)}
                            onReact={emoji => void react(message, emoji)}
                            onOpenDetail={() => setDetailFor(message.uuid)}
                        />
                    ))}

                    {typing.length > 0 && <TypingBubble who={typing.map(other => other.name ?? 'Partner').join(', ')} />}

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

                {recording && (
                    <div className="shrink-0">
                        <AudioRecorder
                            busy={sending}
                            withTitle={false}
                            maxSeconds={300}
                            label="Nahrát hlasovku"
                            onRecorded={async (blob, durationMs) => {
                                const form = new FormData();
                                form.append('audio', blob, recordingFilename('hlasovka', blob));
                                form.append('duration_ms', String(durationMs));
                                try {
                                    const response = await axios.post('/api/v1/chat', form);
                                    setMessages(current => [...current, response.data]);
                                    cursor.current = Math.max(cursor.current, response.data.id);
                                    setRecording(false);
                                } catch { setError('Hlasovku se nepodařilo odeslat.'); }
                            }}
                        />
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
                    <button
                        type="button"
                        onClick={() => setRecording(value => !value)}
                        aria-label="Hlasovka"
                        aria-pressed={recording}
                        className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition-colors hover:bg-[var(--color-surface-hover)] ${recording ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'}`}
                    >
                        <Mic size={19} />
                    </button>
                    <GifPicker onPick={gif => { setPendingImage(null); setPendingGif(gif); }} />
                    <EmojiPicker keepOpen onPick={emoji => setDraft(current => current + emoji)} />
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
            {detailFor && (
                <MessageDetail
                    uuid={detailFor}
                    onClose={() => setDetailFor(null)}
                    onDeleted={() => setMessages(current => current.filter(item => item.uuid !== detailFor))}
                />
            )}
        </AppLayout>
    );
}
