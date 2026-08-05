import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Check, LoaderCircle, Moon, Palette, RotateCcw, Sun } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Mode = 'dark' | 'light';
type Palette = Record<string, string>;

/** Mirrors App\Support\ThemePalette so the editor and the server agree on the tokens. */
const TOKENS: Array<{ key: string; label: string; hint: string }> = [
    { key: 'bg-primary',     label: 'Pozadí aplikace',       hint: 'plocha za vším ostatním' },
    { key: 'bg-secondary',   label: 'Pozadí panelů',         hint: 'postranní menu a lišty' },
    { key: 'bg-card',        label: 'Pozadí karet',          hint: 'obsahové bloky' },
    { key: 'border',         label: 'Linky a okraje',        hint: 'oddělovače a rámečky' },
    { key: 'text-primary',   label: 'Hlavní text',           hint: 'nadpisy a důležité údaje' },
    { key: 'text-secondary', label: 'Vedlejší text',         hint: 'popisky a doplňky' },
    { key: 'accent',         label: 'Zvýrazňující barva',    hint: 'tlačítka a aktivní prvky' },
    { key: 'accent-hover',   label: 'Zvýraznění při najetí', hint: 'tlačítka pod kurzorem' },
];

const DEFAULTS: Record<Mode, Palette> = {
    dark: {
        'bg-primary': '#0f0f1a', 'bg-secondary': '#1a1a2e', 'bg-card': '#16213e',
        border: '#2d2d4e', 'text-primary': '#f0f0f5', 'text-secondary': '#9ca3af',
        accent: '#6c63ff', 'accent-hover': '#7c74ff',
    },
    light: {
        'bg-primary': '#f6f5fb', 'bg-secondary': '#ffffff', 'bg-card': '#ffffff',
        border: '#e2dfec', 'text-primary': '#16151f', 'text-secondary': '#5b5770',
        accent: '#5b51e8', 'accent-hover': '#4a3fd6',
    },
};

/** Relative luminance, per WCAG. */
function luminance(hex: string): number {
    const value = hex.replace('#', '');
    const channels = [0, 2, 4].map(i => {
        const c = parseInt(value.slice(i, i + 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrast(a: string, b: string): number {
    const [x, y] = [luminance(a), luminance(b)].sort((m, n) => n - m);
    return (x + 0.05) / (y + 0.05);
}

const isHex = (value: string) => /^#[0-9a-fA-F]{6}$/.test(value);

export default function Appearance() {
    const stored = (usePage().props.auth?.user as { theme_palette?: Record<Mode, Palette> } | undefined)?.theme_palette;

    const [mode, setMode] = useState<Mode>('dark');
    const [palettes, setPalettes] = useState<Record<Mode, Palette>>({
        dark: { ...DEFAULTS.dark, ...(stored?.dark ?? {}) },
        light: { ...DEFAULTS.light, ...(stored?.light ?? {}) },
    });
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');

    const palette = palettes[mode];

    // Live preview: the editor writes the tokens straight onto the document while the
    // chosen mode is the one being displayed, so changes are seen on the real interface.
    useEffect(() => {
        const active = document.documentElement.dataset.theme
            ?? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
        if (active !== mode) return;

        for (const [token, value] of Object.entries(palette)) {
            if (isHex(value)) document.documentElement.style.setProperty(`--color-${token}`, value);
        }
    }, [palette, mode]);

    const set = (token: string, value: string) =>
        setPalettes(current => ({ ...current, [mode]: { ...current[mode], [token]: value } }));

    const resetMode = () => setPalettes(current => ({ ...current, [mode]: { ...DEFAULTS[mode] } }));

    const save = async () => {
        setBusy(true); setError(''); setSaved(false);
        try {
            await axios.patch('/api/v1/user-preferences', { theme_palette: palettes });
            setSaved(true);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Paletu se nepodařilo uložit.');
        } finally { setBusy(false); }
    };

    // Readability is checked rather than assumed: a palette can otherwise be saved in a
    // state where the text cannot be read on its own background.
    const checks = useMemo(() => ([
        { label: 'Hlavní text na pozadí', ratio: contrast(palette['text-primary'], palette['bg-primary']), need: 4.5 },
        { label: 'Hlavní text na kartě', ratio: contrast(palette['text-primary'], palette['bg-card']), need: 4.5 },
        { label: 'Vedlejší text na kartě', ratio: contrast(palette['text-secondary'], palette['bg-card']), need: 4.5 },
        { label: 'Bílá na zvýraznění', ratio: contrast('#ffffff', palette.accent), need: 4.5 },
    ]), [palette]);

    const failing = checks.filter(check => check.ratio < check.need);

    return (
        <AppLayout>
            <Head title="Vzhled a barvy" />
            <main className="mx-auto max-w-4xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Nastavení</p>
                <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">
                    <Palette size={22} className="text-[var(--color-accent)]" /> Vzhled a barvy
                </h1>
                <p className="mt-2 max-w-2xl text-sm text-[var(--color-text-secondary)]">
                    Nastavte si vlastní barvy zvlášť pro tmavý a světlý režim. Změny vidíte hned; uloží se až tlačítkem.
                </p>

                <div className="mt-5 inline-flex rounded-xl border border-[var(--color-border)] p-1">
                    {(['dark', 'light'] as Mode[]).map(value => {
                        const Icon = value === 'dark' ? Moon : Sun;
                        return (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setMode(value)}
                                aria-pressed={mode === value}
                                className={`inline-flex min-h-9 items-center gap-2 rounded-lg px-4 text-sm ${mode === value ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-text-secondary)]'}`}
                            >
                                <Icon size={14} /> {value === 'dark' ? 'Tmavý' : 'Světlý'}
                            </button>
                        );
                    })}
                </div>

                <p className="mt-2 text-xs text-[var(--color-text-secondary)]">
                    Náhled na živo funguje jen u režimu, který máte právě zapnutý. Druhý si přepněte dole v postranním menu.
                </p>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}

                <section className="mt-6 grid gap-3 sm:grid-cols-2">
                    {TOKENS.map(token => (
                        <label key={token.key} className="flex items-center gap-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                            <input
                                type="color"
                                value={palette[token.key]}
                                onChange={event => set(token.key, event.target.value)}
                                aria-label={token.label}
                                className="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-[var(--color-border)] bg-transparent"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium text-[var(--color-text-primary)]">{token.label}</span>
                                <span className="block text-[10px] text-[var(--color-text-secondary)]">{token.hint}</span>
                            </span>
                            <input
                                value={palette[token.key]}
                                onChange={event => set(token.key, event.target.value)}
                                aria-label={`${token.label} — hex`}
                                spellCheck={false}
                                className={`w-24 shrink-0 rounded-lg border bg-[var(--color-surface-muted)] px-2 py-1.5 text-center font-mono text-xs text-[var(--color-text-primary)] ${isHex(palette[token.key]) ? 'border-[var(--color-border)]' : 'border-red-400'}`}
                            />
                        </label>
                    ))}
                </section>

                <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                    <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Čitelnost</h2>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                        Poměr kontrastu podle WCAG. Pod 4,5 se text čte špatně.
                    </p>
                    <div className="mt-3 space-y-1.5">
                        {checks.map(check => (
                            <div key={check.label} className="flex items-center justify-between text-xs">
                                <span className="text-[var(--color-text-secondary)]">{check.label}</span>
                                <span className={check.ratio >= check.need ? 'text-emerald-300' : 'text-amber-300'}>
                                    {check.ratio.toFixed(1)}:1 {check.ratio >= check.need ? '· v pořádku' : '· málo'}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>

                <div className="mt-6 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={save}
                        disabled={busy}
                        className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-5 text-sm font-medium text-white disabled:opacity-40"
                    >
                        {busy ? <LoaderCircle size={15} className="animate-spin" /> : <Check size={15} />} Uložit paletu
                    </button>
                    <button
                        type="button"
                        onClick={resetMode}
                        className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]"
                    >
                        <RotateCcw size={14} /> Výchozí pro {mode === 'dark' ? 'tmavý' : 'světlý'}
                    </button>
                    {saved && <span className="text-xs text-emerald-300">Uloženo. Po načtení stránky platí všude.</span>}
                    {failing.length > 0 && (
                        <span className="text-xs text-amber-300">
                            {failing.length} {failing.length === 1 ? 'kombinace se čte' : 'kombinace se čtou'} špatně — uložit to jde, ale zvažte úpravu.
                        </span>
                    )}
                </div>
            </main>
        </AppLayout>
    );
}
