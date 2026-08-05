import { useViewportSafePanel } from '@/lib/useViewportSafePanel';
import axios from 'axios';
import { Check, Monitor, Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

type Theme = 'dark' | 'light' | 'system';

const options: Array<{ value: Theme; label: string; note: string; icon: typeof Sun }> = [
    { value: 'dark',   label: 'Tmavý',   note: 'původní vzhled galerie', icon: Moon },
    { value: 'light',  label: 'Světlý',  note: 'na denní světlo',        icon: Sun },
    { value: 'system', label: 'Podle systému', note: 'sleduje nastavení zařízení', icon: Monitor },
];

const valid = (value: unknown): value is Theme => value === 'dark' || value === 'light' || value === 'system';

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

export default function ThemeControl({ initial }: { initial?: unknown }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const panel = useViewportSafePanel(open);
    const [theme, setTheme] = useState<Theme>(() => {
        const stored = typeof localStorage !== 'undefined' ? localStorage.getItem('maki-theme') : null;
        return valid(initial) ? initial : (valid(stored) ? stored : 'system');
    });

    useEffect(() => {
        applyTheme(theme);
        try { localStorage.setItem('maki-theme', theme); } catch { /* Storage is optional. */ }
    }, [theme]);

    // Following the system means reacting when the system changes.
    useEffect(() => {
        if (theme !== 'system') return;
        const query = window.matchMedia('(prefers-color-scheme: light)');
        const react = () => applyTheme('system');
        query.addEventListener('change', react);
        return () => query.removeEventListener('change', react);
    }, [theme]);

    const choose = async (value: Theme) => {
        if (value === theme) { setOpen(false); return; }
        const previous = theme;
        setTheme(value); setBusy(true);
        try { await axios.patch('/api/v1/user-preferences', { theme: value }); }
        catch { setTheme(previous); }
        finally { setBusy(false); setOpen(false); }
    };

    const Active = options.find(option => option.value === theme)?.icon ?? Monitor;

    return (
        <div className="relative">
            <button
                type="button"
                aria-label="Vzhled aplikace"
                aria-expanded={open}
                onClick={() => setOpen(value => !value)}
                className="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
            >
                <Active size={16} />
            </button>

            {open && (
                <>
                    <button type="button" aria-label="Zavřít nabídku vzhledu" onClick={() => setOpen(false)} className="fixed inset-0 z-40 cursor-default" />
                    <div ref={panel.ref} style={panel.style} className="absolute bottom-full z-50 mb-2 w-60 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 shadow-2xl">
                        <p className="px-2 py-1 text-xs font-medium text-[var(--color-text-primary)]">Vzhled</p>
                        {options.map(option => {
                            const Icon = option.icon;
                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    disabled={busy}
                                    onClick={() => void choose(option.value)}
                                    className={`mt-1 flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left disabled:opacity-50 ${theme === option.value ? 'bg-[var(--color-accent)]/15 text-[var(--color-text-primary)]' : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]'}`}
                                >
                                    <span className="flex h-5 w-5 items-center justify-center">
                                        {theme === option.value ? <Check size={14} /> : <Icon size={14} />}
                                    </span>
                                    <span>
                                        <span className="block text-xs">{option.label}</span>
                                        <span className="block text-[10px] opacity-75">{option.note}</span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </>
            )}
        </div>
    );
}
