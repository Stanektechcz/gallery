import ChatGame from '@/Components/ChatGame';
import EmojiPicker from '@/Components/EmojiPicker';
import MentionText from '@/Components/MentionText';
import { useMessageGestures } from '@/lib/useMessageGestures';
import axios from 'axios';
import { Check, CheckCheck, MoreHorizontal, Pin } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export interface Reaction { emoji: string; count: number; mine: boolean }

export interface ChatMedia {
    url: string;
    kind: 'image' | 'gif' | 'voice';
    /** Voice only: already kept on the voice-note timeline. */
    pinned?: boolean;
    duration_ms?: number | null;
    width?: number | null;
    height?: number | null;
}

export interface ChatMessage {
    /** Set when the message is a game invitation rather than words. */
    game?: string | null;
    /** What this message answers, if anything. */
    reply_to?: { uuid: string; author_id: number; excerpt: string } | null;
    id: number;
    uuid: string;
    body: string;
    media: ChatMedia | null;
    reactions: Reaction[];
    author: { id: number; name: string | null };
    is_mine: boolean;
    edited: boolean;
    sent_at: string | null;
}

const time = (value: string | null) =>
    value ? new Date(value).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }) : '';

/**
 * One message, with every way of acting on it.
 *
 * Shared by the docked panel and the full page so the two cannot drift: a gesture that
 * works in one works in the other, and there is one place to change how a message looks.
 *
 * The styling is deliberately quiet — no borders, no shadows, one accent for your own
 * words and one muted surface for everyone else's. Timestamps and receipts sit at the
 * lower right in the smallest readable size, so a conversation reads as text rather than
 * as a list of cards.
 */
export default function ChatMessageItem({ message, read, compact = false, meId = 0, onReact, onOpenDetail, onReply }: {
    message: ChatMessage;
    /** Whose side we are on, for the game board's scoreboard. */
    meId?: number;
    /** Somebody else has read it. Only meaningful for your own messages. */
    read: boolean;
    compact?: boolean;
    onReact: (emoji: string) => void;
    onOpenDetail: () => void;
    onReply?: (message: ChatMessage) => void;
}) {
    const [picker, setPicker] = useState(false);
    /** Seeded from the server, so the pin survives a reload rather than resetting. */
    const [pinned, setPinned] = useState<'idle' | 'busy' | 'done'>(
        message.media?.pinned ? 'done' : 'idle',
    );

    const gestures = useMessageGestures({
        // A double tap is the fastest way to say "yes, this" and costs no menu.
        onDoubleTap: () => onReact('❤️'),
        onHold: () => setPicker(true),
        onSwipeRight: onOpenDetail,
    });

    const react = (emoji: string) => { setPicker(false); onReact(emoji); };

    return (
        <div
            id={`zprava-${message.id}`}
            className={`group flex items-end gap-1 scroll-mt-24 transition-colors ${message.is_mine ? 'justify-end' : 'justify-start'}`}
        >
            {message.is_mine && (
                <MessageMenu onOpenDetail={onOpenDetail} onReply={onReply ? () => onReply(message) : undefined} onPick={react} open={picker} setOpen={setPicker} />
            )}

            <div
                {...gestures}
                // Selection has to go, or a long press selects the text under the finger.
                className={`max-w-[80%] select-none rounded-2xl px-3 py-2 ${compact ? 'text-[13px]' : 'text-sm'} ${message.is_mine
                    ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]'
                    : 'bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'}`}
            >
                {!message.is_mine && (
                    <p className="mb-0.5 text-[10px] opacity-70">{message.author.name}</p>
                )}

                {message.media?.kind === 'voice' ? (
                    <span className="my-0.5 flex items-center gap-1.5">
                        <audio controls src={message.media.url} className="h-8 w-48 max-w-full" />
                        {/* Keeping a voice message on the timeline. The copy is the server's
                            job; here it only needs to say whether it worked, and to stop
                            offering itself once it has. */}
                        <button
                            type="button"
                            disabled={pinned !== 'idle'}
                            aria-label="Připnout hlasovku do přehledu"
                            title={pinned === 'done' ? 'Připnuto do hlasovek' : 'Připnout do hlasovek'}
                            onClick={async () => {
                                setPinned('busy');
                                try {
                                    await axios.post(`/api/v1/voice-notes/pripnout/${message.uuid}`);
                                    setPinned('done');
                                } catch { setPinned('idle'); }
                            }}
                            className={`shrink-0 rounded-lg p-1.5 transition-colors ${pinned === 'done'
                                ? 'text-[var(--color-accent)]'
                                : 'opacity-60 hover:opacity-100'}`}
                        >
                            <Pin size={13} className={pinned === 'busy' ? 'animate-pulse' : ''} />
                        </button>
                    </span>
                ) : message.media ? (
                    <img
                        src={message.media.url}
                        alt={message.media.kind === 'gif' ? 'GIF' : 'Obrázek'}
                        loading="lazy"
                        style={message.media.width && message.media.height
                            ? { aspectRatio: `${message.media.width} / ${message.media.height}` }
                            : undefined}
                        className="my-0.5 max-h-72 w-full rounded-xl object-cover"
                    />
                ) : null}

                {message.reply_to && (
                    <p className="mb-1 truncate border-l-2 border-current/40 pl-2 text-[11px] opacity-70">
                        {message.reply_to.excerpt}
                    </p>
                )}
                {message.game && <ChatGame uuid={message.game} meId={meId} />}
                {message.body && <MentionText body={message.body} />}

                <p className="mt-0.5 flex items-center justify-end gap-1 text-[10px] opacity-65">
                    {message.edited && <span>upraveno</span>}
                    {time(message.sent_at)}
                    {/* Receipts are ticks alone: one sent, two read. No words needed. */}
                    {message.is_mine && (read ? <CheckCheck size={13} /> : <Check size={13} />)}
                </p>

                {message.reactions.length > 0 && (
                    <div className="mt-1 flex flex-wrap gap-1">
                        {message.reactions.map(reaction => (
                            <button
                                key={reaction.emoji}
                                type="button"
                                onClick={() => onReact(reaction.emoji)}
                                aria-pressed={reaction.mine}
                                className={`rounded-full bg-[var(--color-bg-card)]/80 px-1.5 py-0.5 text-[11px] ${reaction.mine ? 'ring-1 ring-[var(--color-accent)]' : ''}`}
                            >
                                {reaction.emoji} {reaction.count > 1 ? reaction.count : ''}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {! message.is_mine && (
                <MessageMenu onOpenDetail={onOpenDetail} onReply={onReply ? () => onReply(message) : undefined} onPick={react} open={picker} setOpen={setPicker} />
            )}
        </div>
    );
}

/** The three dots: reactions and the detail sheet, in one place beside the bubble. */
function MessageMenu({ open, setOpen, onPick, onOpenDetail, onReply }: {
    open: boolean;
    setOpen: (value: boolean) => void;
    onPick: (emoji: string) => void;
    onOpenDetail: () => void;
    onReply?: () => void;
}) {
    const [menu, setMenu] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const [spot, setSpot] = useState<{ top: number; left: number } | null>(null);
    const showing = menu || open;

    /*
     | The conversation scrolls, which means it clips: a panel positioned inside a
     | message was cut off by the container the moment it was wider or taller than the
     | bubble. So it is rendered into the body at fixed coordinates measured from the
     | trigger, and nudged back inside the viewport if it would hang off an edge.
     */
    useLayoutEffect(() => {
        if (!showing) { setSpot(null); return; }

        const rect = trigger.current?.getBoundingClientRect();
        if (!rect) return;

        const width = 280;
        const margin = 8;
        const left = Math.min(
            Math.max(margin, rect.left + rect.width / 2 - width / 2),
            window.innerWidth - width - margin,
        );
        // Above the trigger by default; below it when there is no room above.
        const above = rect.top > 120;
        setSpot({ top: above ? rect.top - 52 : rect.bottom + 8, left });
    }, [showing]);

    return (
        <div className="self-center">
            <button
                ref={trigger}
                type="button"
                onClick={() => setMenu(value => !value)}
                aria-label="Možnosti zprávy"
                // Always there on a phone: hover does not exist, so a control that only
                // appears on hover simply never appears.
                className="flex h-7 w-7 items-center justify-center rounded-full text-[var(--color-text-secondary)] opacity-60 transition-opacity hover:bg-[var(--color-surface-hover)] focus:opacity-100 md:opacity-0 md:group-hover:opacity-100"
            >
                <MoreHorizontal size={16} />
            </button>

            {showing && spot && createPortal(
                <>
                    <button
                        type="button"
                        aria-label="Zavřít"
                        onClick={() => { setMenu(false); setOpen(false); }}
                        className="fixed inset-0 z-[725] cursor-default"
                    />
                    <div
                        style={{ top: spot.top, left: spot.left, width: 280 }}
                        className="fixed z-[730] flex items-center gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-1 shadow-2xl"
                    >
                        {['❤️', '😂', '👍', '😮', '😢'].map(emoji => (
                            <button key={emoji} type="button" onClick={() => { setMenu(false); onPick(emoji); }} className="rounded-lg p-1 text-base hover:bg-[var(--color-surface-hover)]">{emoji}</button>
                        ))}
                        {/* Anything outside the quick five, from the full set. */}
                        <EmojiPicker compact label="Další emoji" onPick={emoji => { setMenu(false); onPick(emoji); }} />
                        <span className="mx-0.5 h-5 w-px bg-[var(--color-border)]" />
                        {onReply && (
                            <button
                                type="button"
                                onClick={() => { setMenu(false); setOpen(false); onReply(); }}
                                className="rounded-lg px-2 py-1 text-[11px] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                            >
                                Odpovědět
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => { setMenu(false); setOpen(false); onOpenDetail(); }}
                            className="rounded-lg px-2 py-1 text-[11px] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                        >
                            Detail
                        </button>
                    </div>
                </>,
                document.body,
            )}
        </div>
    );
}
