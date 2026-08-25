import { CheckCircle2, Info, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Krátká zpráva o tom, co se právě povedlo nebo nepovedlo.
 *
 * Vzniklo místo třinácti `alert()`. Nativní dialog je na telefonu systémové okno přes
 * celou obrazovku, které se musí odklepnout — u hlášky typu „Propojeno 5 nových fotek"
 * to znamená, že aplikace kvůli dobré zprávě zastaví člověku práci. Navíc vypadá jako
 * cizí těleso: nemá motiv aplikace ani její písmo.
 *
 * Zpráva se proto ukáže vedle obsahu, sama zmizí a dá se odklepnout, když překáží.
 * Chyby zůstávají déle než dobré zprávy, protože se musí stihnout přečíst a bývají delší.
 *
 * Odesílá se voláním `hlaska(...)` odkudkoliv, i mimo komponentu — proto seznam
 * posluchačů v modulu a ne kontext. Stejný postup jako u sdíleného stahování: volající
 * nemusí nic protahovat přes stromy komponent.
 */

export type DruhHlasky = 'uspech' | 'chyba' | 'info';

type Zprava = { id: number; text: string; druh: DruhHlasky };

/** Jak dlouho zpráva zůstane. Chyba déle — je delší a je potřeba si ji přečíst. */
const TRVANI: Record<DruhHlasky, number> = { uspech: 4500, info: 5500, chyba: 9000 };

/** Víc než tři zprávy naráz by na telefonu zakryly obsah, kterého se týkají. */
const NEJVIC = 3;

let posluchaci: Array<(z: Zprava) => void> = [];
let dalsiId = 0;

export function hlaska(text: string, druh: DruhHlasky = 'info'): void {
    const zprava = { id: ++dalsiId, text, druh };

    posluchaci.forEach((posluchac) => posluchac(zprava));
}

const TON: Record<DruhHlasky, { ram: string; ikona: string }> = {
    uspech: { ram: 'border-emerald-400/40', ikona: 'text-emerald-400' },
    chyba: { ram: 'border-red-400/40', ikona: 'text-red-400' },
    info: { ram: 'border-[var(--color-accent)]/40', ikona: 'text-[var(--color-accent)]' },
};

const IKONA: Record<DruhHlasky, typeof Info> = {
    uspech: CheckCircle2,
    chyba: TriangleAlert,
    info: Info,
};

export default function Hlasky() {
    const [zpravy, setZpravy] = useState<Zprava[]>([]);

    useEffect(() => {
        const prijmi = (zprava: Zprava) => {
            setZpravy((soucasne) => [...soucasne, zprava].slice(-NEJVIC));

            window.setTimeout(
                () => setZpravy((soucasne) => soucasne.filter((z) => z.id !== zprava.id)),
                TRVANI[zprava.druh],
            );
        };

        posluchaci.push(prijmi);

        return () => { posluchaci = posluchaci.filter((p) => p !== prijmi); };
    }, []);

    if (zpravy.length === 0) return null;

    return (
        // Na telefonu dole nad spodním ovládáním, na monitoru vpravo nahoře, kde spodní
        // ovládání není a zpráva nepřekáží palci. Čtečkám se hlásí zdvořile, aby
        // nepřerušila to, co se právě předčítá — u chyby se to níž přepíná na `alert`.
        <div
            aria-live="polite"
            className="pointer-events-none fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom,0px)+0.75rem)] z-[940] flex flex-col items-center gap-2 px-3 sm:inset-x-auto sm:bottom-auto sm:right-4 sm:top-4 sm:items-end sm:px-0"
        >
            {zpravy.map((zprava) => {
                const Ikona = IKONA[zprava.druh];

                return (
                    <div
                        key={zprava.id}
                        role={zprava.druh === 'chyba' ? 'alert' : 'status'}
                        className={`pointer-events-auto flex w-full max-w-md items-start gap-2.5 rounded-2xl border px-3 py-2.5 shadow-lg backdrop-blur-sm ${TON[zprava.druh].ram} bg-[var(--color-bg-card)] sm:w-auto sm:min-w-72`}
                    >
                        <Ikona size={17} className={`mt-0.5 shrink-0 ${TON[zprava.druh].ikona}`}/>
                        {/* min-w-0 a break-words: bez toho dlouhá zpráva bez mezer roztáhne kartu přes okraj. */}
                        <p className="min-w-0 flex-1 break-words text-xs leading-relaxed text-[var(--color-text-primary)]">
                            {zprava.text}
                        </p>
                        <button
                            type="button"
                            onClick={() => setZpravy((soucasne) => soucasne.filter((z) => z.id !== zprava.id))}
                            aria-label="Zavřít zprávu"
                            className="-my-1 -mr-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]"
                        >
                            <X size={15}/>
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
