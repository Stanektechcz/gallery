import { SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';

export type ActiveFilter = {
    /** Co je zapnuté, jak to má stát v odznaku — „🍽️ Restaurace", „Navštíveno". */
    label: string;
    /** Vrátí tenhle jeden filtr do výchozího stavu. */
    clear: () => void;
};

/**
 * Skládací pruh filtrů.
 *
 * Stránka míst měla tři řady odznaků nad sebou — typ, stav a rychlé výběry. Na displeji
 * širokém 375 bodů to znamenalo, že první místo začínalo 473 bodů dolů: hlavička a filtry
 * spolykaly osmapadesát procent obrazovky dřív, než se ukázal jakýkoli obsah.
 *
 * Na telefonu je proto vidět jeden řádek. Skupiny se rozbalí až na požádání a na širokých
 * displejích jsou rozbalené pořád — tam prostor je a schovávat je by byla práce navíc pro nic.
 * Rozhoduje o tom CSS, ne měření šířky v JavaScriptu: `hidden sm:flex` funguje hned při
 * prvním vykreslení, kdežto zjišťování šířky by na okamžik ukázalo špatný stav.
 *
 * Zapnuté filtry jsou v tom jednom řádku vždycky vidět jako odznaky s křížkem. Bez toho by
 * se dalo něco odfiltrovat, pruh sbalit a pak marně hledat, kam se poděla polovina míst.
 */
export default function FilterBar({
    active,
    summary,
    onClearAll,
    leading,
    className = 'mb-5',
    children,
}: {
    /** Zapnuté filtry. Prázdné pole = nic se nefiltruje. */
    active: ActiveFilter[];
    /** Co je vidět po filtrování — „6 míst", „3 z 24 fotek". */
    summary: string;
    /** Vrátí všechno do výchozího stavu. Bez něj se tlačítko „Zrušit vše" nezobrazí. */
    onClearAll?: () => void;
    /**
     * Co má být vidět i po sbalení — typicky hledání. Stojí v tomtéž řádku jako
     * tlačítko „Filtry", takže na širokém displeji nezabírá vlastní řádek navíc.
     */
    leading?: React.ReactNode;
    className?: string;
    /** Skupiny odznaků. Každá skupina ať je jeden prvek s vlastním aria-label. */
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className={className}>
            <div className="flex flex-wrap items-center gap-2">
                {leading}
                <button
                    type="button"
                    onClick={() => setOpen(value => !value)}
                    aria-expanded={open}
                    className={`inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-xs transition-colors sm:hidden ${
                        active.length
                            ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/15 text-[var(--color-text-primary)]'
                            : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                    }`}
                >
                    <SlidersHorizontal size={13} />
                    Filtry
                    {active.length > 0 && (
                        <span className="rounded-full bg-[var(--color-accent)] px-1.5 text-[10px] font-semibold text-[var(--color-accent-contrast)]">
                            {active.length}
                        </span>
                    )}
                </button>

                <span className="text-xs text-[var(--color-text-secondary)]">{summary}</span>

                {/* Zapnuté filtry zůstávají vidět i po sbalení — jinak by se ztratily. */}
                {active.map(filter => (
                    <button
                        key={filter.label}
                        type="button"
                        onClick={filter.clear}
                        aria-label={`Zrušit filtr ${filter.label}`}
                        className="inline-flex min-h-8 items-center gap-1 rounded-full border border-[var(--color-accent)]/40 bg-[var(--color-accent)]/10 px-2.5 text-xs text-[var(--color-text-primary)] sm:hidden"
                    >
                        {filter.label}
                        <X size={11} />
                    </button>
                ))}

                {onClearAll && active.length > 1 && (
                    <button
                        type="button"
                        onClick={onClearAll}
                        className="min-h-8 px-2 text-xs text-[var(--color-text-secondary)] underline sm:hidden"
                    >
                        Zrušit vše
                    </button>
                )}
            </div>

            <div className={`${open ? 'mt-2 flex' : 'hidden'} flex-col gap-1.5 sm:mt-2 sm:flex`}>{children}</div>
        </div>
    );
}
