import { useViewportSafePanel } from '@/lib/useViewportSafePanel';
import { Smile } from 'lucide-react';
import { useMemo, useState } from 'react';

/**
 * A curated emoji picker.
 *
 * Deliberately a hand-picked list rather than the full Unicode set behind a dependency:
 * a complete picker means shipping a megabyte of metadata to render a grid nobody
 * scrolls past the second row of. These are the ones couples actually send, grouped so
 * the one you want is a glance away, with Czech keywords so search works in the language
 * the rest of the app speaks.
 */
const GROUPS: Array<{ label: string; emoji: Array<[string, string]> }> = [
    {
        label: 'Nálada',
        emoji: [
            ['😀', 'usmev radost'], ['😂', 'smich slzy'], ['🥰', 'zamilovany laska'], ['😍', 'srdicka oci laska'],
            ['😘', 'pusa polibek'], ['🤗', 'objeti'], ['😉', 'mrknuti'], ['🙂', 'usmev'],
            ['😅', 'nervozni smich'], ['🤣', 'valim se smichy'], ['😊', 'stastny'], ['😴', 'spanek unava'],
            ['😭', 'plac smutek'], ['😢', 'slza smutek'], ['😡', 'vztek zlost'], ['😳', 'prekvapeni stud'],
            ['🤔', 'premysleni'], ['🙄', 'oci v sloup'], ['😜', 'jazyk sranda'], ['🥺', 'prosim oci'],
        ],
    },
    {
        label: 'Láska',
        emoji: [
            ['❤️', 'srdce laska'], ['🧡', 'srdce oranzove'], ['💛', 'srdce zlute'], ['💚', 'srdce zelene'],
            ['💙', 'srdce modre'], ['💜', 'srdce fialove'], ['🖤', 'srdce cerne'], ['🤍', 'srdce bile'],
            ['💕', 'dve srdce'], ['💖', 'trpytive srdce'], ['💗', 'rostouci srdce'], ['💘', 'sip srdce'],
            ['💝', 'darek srdce'], ['💞', 'srdce kolem'], ['😻', 'kocka laska'], ['💋', 'pusa otisk'],
        ],
    },
    {
        label: 'Gesta',
        emoji: [
            ['👍', 'palec nahoru souhlas'], ['👎', 'palec dolu nesouhlas'], ['👏', 'potlesk tleskani'], ['🙌', 'ruce nahoru'],
            ['🙏', 'prosim diky modlitba'], ['🤝', 'podani ruky dohoda'], ['✌️', 'mir vitezstvi'], ['🤞', 'drzim palce'],
            ['👋', 'ahoj mavani'], ['💪', 'sila biceps'], ['🫶', 'srdce z rukou'], ['👌', 'ok v poradku'],
        ],
    },
    {
        label: 'Jídlo',
        emoji: [
            ['☕', 'kava'], ['🍺', 'pivo'], ['🍷', 'vino'], ['🥂', 'pripitek oslava'],
            ['🍕', 'pizza'], ['🍔', 'burger'], ['🍰', 'dort'], ['🍫', 'cokolada'],
            ['🍓', 'jahoda'], ['🥑', 'avokado'], ['🍜', 'polevka nudle'], ['🥐', 'croissant snidane'],
        ],
    },
    {
        label: 'Život',
        emoji: [
            ['🔥', 'ohen super'], ['✨', 'jiskry'], ['🎉', 'oslava party'], ['🎁', 'darek'],
            ['🏠', 'domov dum'], ['🚗', 'auto cesta'], ['✈️', 'letadlo dovolena'], ['🏖️', 'plaz dovolena'],
            ['🌙', 'noc mesic'], ['☀️', 'slunce'], ['🌧️', 'dest'], ['❄️', 'snih zima'],
            ['🐶', 'pes'], ['🐱', 'kocka'], ['🌸', 'kvetina'], ['💤', 'spanek'],
        ],
    },
];

const ALL = GROUPS.flatMap(group => group.emoji);

export default function EmojiPicker({ onPick, label = 'Emoji', compact = false }: { onPick: (emoji: string) => void; label?: string; compact?: boolean }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const panel = useViewportSafePanel(open);

    const results = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) return null;
        return ALL.filter(([, keywords]) => keywords.includes(needle)).map(([emoji]) => emoji);
    }, [query]);

    const choose = (emoji: string) => {
        onPick(emoji);
        setOpen(false);
        setQuery('');
    };

    return (
        <div className="relative">
            <button
                type="button"
                aria-label={label}
                aria-expanded={open}
                onClick={() => setOpen(value => !value)}
                className={`flex items-center justify-center rounded-lg text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)] ${compact ? 'h-9 w-9' : 'h-11 w-11'}`}
            >
                <Smile size={compact ? 16 : 18} />
            </button>

            {open && (
                <>
                    <button type="button" aria-label="Zavřít emoji" onClick={() => setOpen(false)} className="fixed inset-0 z-[700] cursor-default" />
                    <div
                        ref={panel.ref}
                        style={panel.style}
                        className="absolute bottom-full z-[710] mb-2 w-72 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 shadow-2xl"
                    >
                        <input
                            value={query}
                            onChange={event => setQuery(event.target.value)}
                            placeholder="Hledat (srdce, smich, kava…)"
                            className="mb-2 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2.5 py-2 text-xs text-[var(--color-text-primary)]"
                        />

                        <div className="max-h-56 overflow-y-auto">
                            {results ? (
                                results.length ? (
                                    <div className="grid grid-cols-8 gap-0.5">
                                        {results.map(emoji => (
                                            <button key={emoji} type="button" onClick={() => choose(emoji)} className="rounded-lg p-1.5 text-lg hover:bg-[var(--color-surface-hover)]">{emoji}</button>
                                        ))}
                                    </div>
                                ) : <p className="px-1 py-3 text-center text-xs text-[var(--color-text-secondary)]">Nic nenalezeno.</p>
                            ) : GROUPS.map(group => (
                                <div key={group.label} className="mb-1">
                                    <p className="px-1 pb-0.5 text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">{group.label}</p>
                                    <div className="grid grid-cols-8 gap-0.5">
                                        {group.emoji.map(([emoji]) => (
                                            <button key={emoji} type="button" onClick={() => choose(emoji)} className="rounded-lg p-1.5 text-lg hover:bg-[var(--color-surface-hover)]">{emoji}</button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
