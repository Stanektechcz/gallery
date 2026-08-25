import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';

/**
 * Přepínač sekcí jedné stránky.
 *
 * Vzniklo pro rozpočty, kde se všechno kreslilo pod sebe: na telefonu měla stránka
 * 5542 bodů, skoro sedm obrazovek, a k srovnání měsíců se člověk doscrolloval přes
 * čtyři a půl tisíce bodů něčeho jiného. Přitom to nejsou části jednoho souvislého
 * textu — je to několik různých pohledů na tatáž data a v jednu chvíli se dívám
 * vždycky jen na jeden.
 *
 * Sekce se drží v adrese (`?sekce=`), ne ve stavu komponenty. Odkaz na rozvahu pak vede
 * na rozvahu, tlačítko zpět funguje a obnovení stránky nehodí člověka na začátek.
 *
 * Klávesnice: šipky přepínají, protože tak se ovládají záložky všude jinde a kdo je
 * zvyklý, zkusí to i tady. Tabulátorem se prochází jen aktivní záložka a odtud rovnou
 * do obsahu — projít napřed všechny záložky by u pěti sekcí znamenalo pět stisků navíc
 * pokaždé.
 */

export type Sekce = {
    id: string;
    label: string;
    icon?: LucideIcon;
    /** Číslo vedle názvu — kolik položek, kolik varování. Nula se nekreslí. */
    pocet?: number;
    /** Vykreslí tečku, když sekce chce pozornost (přetečená kategorie, čekající žádost). */
    upozorneni?: boolean;
};

export default function SekceNav({ sekce, aktivni, onZmena, className = '' }: {
    sekce: Sekce[];
    aktivni: string;
    onZmena: (id: string) => void;
    className?: string;
}) {
    const pas = useRef<HTMLDivElement>(null);

    // Aktivní záložka se posune do zorného pole. Na telefonu se pás záložek roluje a
    // po návratu na stránku by jinak zůstal na začátku, i když je vybraná ta poslední.
    useEffect(() => {
        const prvek = pas.current?.querySelector<HTMLElement>('[aria-selected="true"]');

        prvek?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [aktivni]);

    const posun = (smer: number) => {
        const kde = sekce.findIndex(s => s.id === aktivni);
        const kam = (kde + smer + sekce.length) % sekce.length;

        onZmena(sekce[kam].id);
    };

    return (
        // -mx-4 px-4: pás se dotýká okrajů, aby bylo poznat, že pokračuje za hranu.
        // Bez toho vypadá poslední oříznutá záložka jako chyba vykreslení.
        <div
            ref={pas}
            role="tablist"
            aria-label="Části rozpočtu"
            onKeyDown={event => {
                if (event.key === 'ArrowRight') { event.preventDefault(); posun(1); }
                if (event.key === 'ArrowLeft') { event.preventDefault(); posun(-1); }
            }}
            className={`-mx-4 flex gap-1 overflow-x-auto px-4 pb-1 scrollbar-hide sm:mx-0 sm:px-0 ${className}`}
        >
            {sekce.map(polozka => {
                const je = polozka.id === aktivni;
                const Ikona = polozka.icon;

                return (
                    <button
                        key={polozka.id}
                        role="tab"
                        type="button"
                        aria-selected={je}
                        tabIndex={je ? 0 : -1}
                        onClick={() => onZmena(polozka.id)}
                        className={`flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3.5 text-sm font-medium transition-colors ${
                            je
                                ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]'
                                : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-muted)] hover:text-[var(--color-text-primary)]'
                        }`}
                    >
                        {Ikona && <Ikona size={16} className="shrink-0"/>}
                        {polozka.label}

                        {/* Číslo v odlišeném poli, ne za pomlčkou — jinak splyne s názvem. */}
                        {polozka.pocet !== undefined && polozka.pocet > 0 && (
                            <span className={`rounded-md px-1.5 py-0.5 text-[11px] tabular-nums ${
                                je ? 'bg-black/15' : 'bg-[var(--color-surface-muted)]'
                            }`}>
                                {polozka.pocet}
                            </span>
                        )}

                        {polozka.upozorneni && (
                            <span
                                aria-hidden="true"
                                className={`h-1.5 w-1.5 shrink-0 rounded-full ${je ? 'bg-black/40' : 'bg-amber-400'}`}
                            />
                        )}
                    </button>
                );
            })}
        </div>
    );
}
