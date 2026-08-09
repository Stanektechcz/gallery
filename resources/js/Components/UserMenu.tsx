import { useViewportSafePanel } from '@/lib/useViewportSafePanel';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    CircleDollarSign, HardDrive, LogOut, Monitor, Moon,
    Palette, Plug, Settings, ShieldCheck, Sun,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Theme = 'dark' | 'light' | 'system';
type Density = 'comfortable' | 'standard' | 'compact';

interface User {
    name?: string;
    email?: string;
    role?: string;
    theme?: unknown;
    interface_density?: unknown;
}

const themes: Array<{ value: Theme; label: string; icon: typeof Sun }> = [
    { value: 'dark',   label: 'Tmavý',  icon: Moon },
    { value: 'light',  label: 'Světlý', icon: Sun },
    { value: 'system', label: 'Systém', icon: Monitor },
];

const densities: Array<{ value: Density; label: string }> = [
    { value: 'comfortable', label: 'Volné' },
    { value: 'standard',    label: 'Střední' },
    { value: 'compact',     label: 'Husté' },
];

/** Everything that belongs to the person rather than to the gallery. */
export const userMenuLinks = [
    { href: '/settings/security',       label: 'Profil a zabezpečení', icon: Settings },
    { href: '/settings/vzhled',         label: 'Vzhled a barvy',       icon: Palette },
    { href: '/settings/predplatne',     label: 'Předplatné a moduly',  icon: CircleDollarSign },
    { href: '/settings/storage/google', label: 'Úložiště a Drive',     icon: HardDrive },
    { href: '/settings/propojeni',      label: 'Propojení služeb',     icon: Plug },
    { href: '/privacy',                 label: 'Soukromí a dědictví',  icon: ShieldCheck },
];

const isTheme = (value: unknown): value is Theme =>
    value === 'dark' || value === 'light' || value === 'system';

const isDensity = (value: unknown): value is Density =>
    value === 'comfortable' || value === 'standard' || value === 'compact';

/** Keeps the document attribute, the browser chrome colour and the stored choice in step. */
function applyTheme(theme: Theme): void {
    const resolved = theme === 'system'
        ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
        : theme;

    if (theme === 'system') delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = resolved;

    // Without this the phone's status bar keeps the other theme's colour.
    document.querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', resolved === 'light' ? '#f6f5fb' : '#1a1a2e');
}

/** Density rides on the root font size; see the rules in app.css. */
function applyDensity(density: Density): void {
    if (density === 'comfortable') delete document.documentElement.dataset.density;
    else document.documentElement.dataset.density = density;
}

/**
 * The signed-in person's own menu: appearance, their settings pages and the way out.
 *
 * The component stays mounted whenever the avatar is on screen and only the panel is
 * conditional, so the theme effects below keep running while the menu is closed. The
 * first paint is handled by the inline script in app.blade.php, which sets the attribute
 * before React boots and so avoids a flash of the wrong theme.
 */
export default function UserMenu({ user, compact = false }: { user?: User; compact?: boolean }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const panel = useViewportSafePanel(open);

    const [theme, setTheme] = useState<Theme>(() => {
        const stored = typeof localStorage !== 'undefined' ? localStorage.getItem('maki-theme') : null;
        return isTheme(user?.theme) ? user.theme : (isTheme(stored) ? stored : 'system');
    });
    const [density, setDensity] = useState<Density>(() =>
        isDensity(user?.interface_density) ? user.interface_density : 'comfortable');

    useEffect(() => {
        applyTheme(theme);
        try { localStorage.setItem('maki-theme', theme); } catch { /* Storage is optional. */ }
    }, [theme]);

    useEffect(() => {
        applyDensity(density);
        try { localStorage.setItem('maki-density', density); } catch { /* Storage is optional. */ }
    }, [density]);

    // Following the system means reacting when the system changes.
    useEffect(() => {
        if (theme !== 'system') return;
        const query = window.matchMedia('(prefers-color-scheme: light)');
        const react = () => applyTheme('system');
        query.addEventListener('change', react);
        return () => query.removeEventListener('change', react);
    }, [theme]);

    /** Optimistic, because the choice should feel instant; reverted if the server refuses. */
    const save = async <T,>(field: string, value: T, previous: T, apply: (value: T) => void) => {
        if (value === previous) return;
        apply(value);
        setBusy(true);
        try { await axios.patch('/api/v1/user-preferences', { [field]: value }); }
        catch { apply(previous); }
        finally { setBusy(false); }
    };

    const initial = user?.name?.[0]?.toUpperCase() ?? '?';

    return (
        <div className="relative">
            <button
                type="button"
                aria-label="Nabídka uživatele"
                aria-expanded={open}
                aria-haspopup="menu"
                onClick={() => setOpen(value => !value)}
                className={`flex min-w-0 items-center gap-2 rounded-lg py-2 text-left hover:bg-[var(--color-surface-hover)] ${compact ? 'px-1' : 'w-full px-2'}`}
            >
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--color-accent)]/30 text-xs font-bold text-[var(--color-accent)]">
                    {initial}
                </span>
                {!compact && (
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-xs font-medium text-[var(--color-text-primary)]">{user?.name}</span>
                        <span className="block truncate text-xs text-[var(--color-text-secondary)]">{user?.role}</span>
                    </span>
                )}
            </button>

            {open && (
                <>
                    <button
                        type="button"
                        aria-label="Zavřít nabídku uživatele"
                        onClick={() => setOpen(false)}
                        className="fixed inset-0 z-40 cursor-default"
                    />
                    <div
                        ref={panel.ref}
                        style={panel.style}
                        role="menu"
                        className="absolute bottom-full z-50 mb-2 w-64 overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] shadow-2xl"
                    >
                        <div className="border-b border-[var(--color-border)] px-3 py-2.5">
                            <p className="truncate text-sm font-medium text-[var(--color-text-primary)]">{user?.name}</p>
                            <p className="truncate text-xs text-[var(--color-text-secondary)]">{user?.email}</p>
                        </div>

                        <div className="border-b border-[var(--color-border)] p-2">
                            <p className="px-1 pb-1.5 text-[10px] font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Vzhled</p>
                            <div className="flex gap-1">
                                {themes.map(option => {
                                    const Icon = option.icon;
                                    const chosen = theme === option.value;
                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            disabled={busy}
                                            aria-pressed={chosen}
                                            onClick={() => void save('theme', option.value, theme, setTheme)}
                                            className={`flex flex-1 flex-col items-center gap-1 rounded-lg px-1 py-2 text-[10px] disabled:opacity-50 ${chosen ? 'bg-[var(--color-accent)]/15 text-[var(--color-accent-contrast)]' : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'}`}
                                        >
                                            <Icon size={14} />{option.label}
                                        </button>
                                    );
                                })}
                            </div>

                            <p className="px-1 pb-1.5 pt-2 text-[10px] font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Hustota rozhraní</p>
                            <div className="flex gap-1">
                                {densities.map(option => {
                                    const chosen = density === option.value;
                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            disabled={busy}
                                            aria-pressed={chosen}
                                            onClick={() => void save('interface_density', option.value, density, setDensity)}
                                            className={`flex-1 rounded-lg px-1 py-1.5 text-[10px] disabled:opacity-50 ${chosen ? 'bg-[var(--color-accent)]/15 text-[var(--color-accent-contrast)]' : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'}`}
                                        >
                                            {option.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="p-1">
                            {userMenuLinks.map(item => {
                                const Icon = item.icon;
                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        role="menuitem"
                                        onClick={() => setOpen(false)}
                                        className="flex items-center gap-2.5 rounded-lg px-2 py-2 text-xs text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                                    >
                                        <Icon size={14} className="shrink-0" />{item.label}
                                    </Link>
                                );
                            })}
                        </div>

                        <div className="border-t border-[var(--color-border)] p-1">
                            <button
                                type="button"
                                role="menuitem"
                                onClick={() => router.post('/logout')}
                                className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-xs text-red-300 hover:bg-red-500/10"
                            >
                                <LogOut size={14} className="shrink-0" />Odhlásit se
                            </button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
