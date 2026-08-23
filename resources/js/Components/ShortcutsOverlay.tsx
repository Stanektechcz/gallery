import { Keyboard, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Přehled klávesových zkratek.
 *
 * Aplikace jich má slušnou řádku — v prohlížení, v detailu, v promítání — a byly
 * vypsané jedině dole v detailu fotky, tedy na jediném místě, kam se člověk podívá až
 * ve chvíli, kdy je hledat nepotřebuje. Otazník je zvyk, který si lidé nesou odjinud.
 */

const SKUPINY: Array<{ title: string; items: Array<[string, string]> }> = [
    {
        title: 'Kdekoli',
        items: [
            ['?', 'Tento přehled'],
            ['Ctrl K', 'Hledat'],
            ['Esc', 'Zavřít, co je otevřené'],
        ],
    },
    {
        title: 'Prohlížení fotek',
        items: [
            ['← →', 'Předchozí a další'],
            ['F', 'Oblíbená'],
            ['I', 'Informace o snímku'],
            ['D', 'Stáhnout'],
            ['Del', 'Do koše'],
        ],
    },
    {
        title: 'Promítání',
        items: [
            ['Mezerník', 'Spustit a zastavit'],
            ['F', 'Celá obrazovka'],
            ['S', 'Náhodné pořadí'],
            ['← →', 'Ručně dopředu a zpět'],
        ],
    },
    {
        title: 'Výběr v mřížce',
        items: [
            ['Klik', 'Otevřít snímek'],
            ['Ctrl klik', 'Přidat do výběru'],
            ['Shift klik', 'Vybrat rozsah'],
        ],
    },
];

export default function ShortcutsOverlay() {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && open) { setOpen(false); return; }
            if (event.key !== '?') return;

            // Otazník je normální znak: v poli, kde se píše, musí zůstat otazníkem.
            const cil = event.target as HTMLElement | null;
            if (cil && (cil.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(cil.tagName))) return;

            event.preventDefault();
            setOpen(current => ! current);
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    if (! open) return null;

    return (
        <div className="fixed inset-0 z-[700] flex items-center justify-center bg-black/70 p-4" onClick={() => setOpen(false)}>
            <div className="max-h-[80dvh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5"
                onClick={event => event.stopPropagation()}>
                <div className="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 className="flex items-center gap-2 text-sm font-semibold text-[var(--color-text-primary)]">
                            <Keyboard size={16}/> Klávesové zkratky
                        </h2>
                        <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">Otazníkem se přehled zavírá i otevírá.</p>
                    </div>
                    <button type="button" onClick={() => setOpen(false)} aria-label="Zavřít"
                        className="rounded-lg border border-[var(--color-border)] p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                        <X size={14}/>
                    </button>
                </div>

                <div className="grid gap-5 sm:grid-cols-2">
                    {SKUPINY.map(group => (
                        <section key={group.title}>
                            <h3 className="mb-2 text-[10px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">{group.title}</h3>
                            <dl className="space-y-1.5">
                                {group.items.map(([key, meaning]) => (
                                    <div key={key} className="flex items-baseline justify-between gap-3">
                                        <dt className="shrink-0">
                                            <kbd className="rounded border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-1.5 py-0.5 font-mono text-[11px] text-[var(--color-text-primary)]">{key}</kbd>
                                        </dt>
                                        <dd className="min-w-0 flex-1 text-right text-xs text-[var(--color-text-secondary)]">{meaning}</dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))}
                </div>
            </div>
        </div>
    );
}
