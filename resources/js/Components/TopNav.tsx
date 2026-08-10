import { Link } from '@inertiajs/react';
import { ChevronDown, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface NavEntry {
    href: string;
    label: string;
    icon?: any;
}

export interface NavSection {
    id: string;
    label: string;
    icon?: any;
    items: NavEntry[];
}

/** The handful of things people start rather than navigate to. */
const QUICK_ACTIONS: NavEntry[] = [
    { href: '/albums/create', label: 'Nové album' },
    { href: '/calendar', label: 'Naplánovat akci' },
    { href: '/recipes', label: 'Přidat recept' },
    { href: '/denik', label: 'Zápisek do deníku' },
    { href: '/chat', label: 'Napsat zprávu' },
];

/**
 * A bar across the top: search, the menu as dropdowns, and the things people start.
 *
 * It is a second view of the sidebar's data rather than a second menu — the same sections
 * in the same order, honouring the same per-person arrangement. Two menus that could
 * disagree would be worse than one, however convenient the second looked.
 *
 * The search field does not search. It opens the command palette, which already searches
 * everything and is already keyboard-driven; a parallel search box would be a second
 * thing to keep correct. What it adds is discoverability — Ctrl+K is invisible to anyone
 * who was not told about it.
 *
 * Desktop only. On a phone the sidebar drawer and the bottom bar already cover this, and
 * a third navigation surface on a small screen is clutter rather than help.
 */
export default function TopNav({ sections }: { sections: NavSection[] }) {
    const [open, setOpen] = useState<string | null>(null);

    // Closing on navigation, so a dropdown does not survive the page it belonged to.
    useEffect(() => {
        const close = () => setOpen(null);
        document.addEventListener('inertia:navigate', close);

        return () => document.removeEventListener('inertia:navigate', close);
    }, []);

    const openPalette = () => window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true }));

    return (
        <header className="hidden shrink-0 items-center gap-2 border-b border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 py-2 md:flex">
            <button
                type="button"
                onClick={openPalette}
                className="flex min-h-9 flex-1 items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-left text-xs text-[var(--color-text-secondary)] hover:border-[var(--color-accent)]"
            >
                <Search size={15} className="shrink-0" />
                <span className="flex-1">Hledat v galerii, receptech, kalendáři…</span>
                <kbd className="shrink-0 rounded border border-[var(--color-border)] px-1.5 py-0.5 text-[10px]">Ctrl K</kbd>
            </button>

            <nav className="flex items-center gap-0.5" aria-label="Hlavní nabídka">
                {sections.map(section => (
                    <div key={section.id} className="relative">
                        <button
                            type="button"
                            onClick={() => setOpen(current => current === section.id ? null : section.id)}
                            aria-expanded={open === section.id}
                            className={`flex min-h-9 items-center gap-1 rounded-lg px-2.5 text-xs transition-colors ${open === section.id
                                ? 'bg-[var(--color-surface-hover)] text-[var(--color-text-primary)]'
                                : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]'}`}
                        >
                            {section.label}
                            <ChevronDown size={13} className="shrink-0 opacity-60" />
                        </button>

                        {open === section.id && (
                            <>
                                <button type="button" aria-label="Zavřít" onClick={() => setOpen(null)} className="fixed inset-0 z-[745] cursor-default" />
                                <div className="absolute right-0 top-full z-[750] mt-1 max-h-80 w-60 overflow-y-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-1 shadow-2xl">
                                    {section.items.map(item => {
                                        const Icon = item.icon;

                                        return (
                                            <Link
                                                key={item.href}
                                                href={item.href}
                                                onClick={() => setOpen(null)}
                                                className="flex items-center gap-2 rounded-lg px-2 py-2 text-xs text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                                            >
                                                {Icon ? <Icon size={14} className="shrink-0" /> : <span className="w-3.5" />}
                                                <span className="truncate">{item.label}</span>
                                            </Link>
                                        );
                                    })}
                                    {section.items.length === 0 && (
                                        <p className="px-2 py-2 text-[11px] text-[var(--color-text-secondary)]">Tady nic není.</p>
                                    )}
                                </div>
                            </>
                        )}
                    </div>
                ))}

                <div className="relative">
                    <button
                        type="button"
                        onClick={() => setOpen(current => current === 'akce' ? null : 'akce')}
                        aria-expanded={open === 'akce'}
                        aria-label="Rychlé akce"
                        className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-accent)] text-[var(--color-accent-contrast)]"
                    >
                        <Plus size={16} />
                    </button>

                    {open === 'akce' && (
                        <>
                            <button type="button" aria-label="Zavřít" onClick={() => setOpen(null)} className="fixed inset-0 z-[745] cursor-default" />
                            <div className="absolute right-0 top-full z-[750] mt-1 w-52 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-1 shadow-2xl">
                                <p className="px-2 py-1 text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">Rychlé akce</p>
                                {QUICK_ACTIONS.map(action => (
                                    <Link
                                        key={action.href}
                                        href={action.href}
                                        onClick={() => setOpen(null)}
                                        className="block rounded-lg px-2 py-2 text-xs text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                                    >
                                        {action.label}
                                    </Link>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </nav>
        </header>
    );
}
