/**
 * Someone's state as one dot.
 *
 * Three states, because two cannot say the useful thing: green is here in the
 * conversation, amber is in the app but reading something else, red is away entirely.
 * Colour alone never carries meaning — the title and the screen-reader text say it in
 * words, so it still works for anyone who cannot tell the three apart.
 */
export type Presence = { online: boolean; in_chat?: boolean };

const STATE = {
    here: { colour: 'bg-emerald-400', label: 'v konverzaci' },
    app: { colour: 'bg-amber-400', label: 'v aplikaci' },
    away: { colour: 'bg-red-400/70', label: 'offline' },
} as const;

export function presenceState(person: Presence): keyof typeof STATE {
    if (person.in_chat) return 'here';

    return person.online ? 'app' : 'away';
}

export default function PresenceDot({ person, size = 8, note }: { person: Presence; size?: number; note?: string }) {
    const state = STATE[presenceState(person)];

    return (
        <span
            title={note ? `${state.label} · ${note}` : state.label}
            style={{ width: size, height: size }}
            className={`inline-block shrink-0 rounded-full ring-2 ring-[var(--color-bg-secondary)] ${state.colour}`}
        >
            <span className="sr-only">{state.label}</span>
        </span>
    );
}
