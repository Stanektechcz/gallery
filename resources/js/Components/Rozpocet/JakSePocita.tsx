import { HelpCircle } from 'lucide-react';
import { useState, type ReactNode } from 'react';

/**
 * „Jak se to počítá?“
 *
 * U čísel jako bezpečně na den, efektivní kurz nebo partnerské saldo nestačí hodnotu
 * ukázat — je odvozená z několika věcí naráz a bez rozpisu se nedá zkontrolovat.
 * A číslo o penězích, které nejde zkontrolovat, se buď slepě věří, nebo se přestane
 * používat; obojí je špatně.
 *
 * Vysvětlení používá **konkrétní čísla uživatele**, ne obecnou definici. „Zbývající
 * rozpočet dělený počtem dnů" nikomu nepomůže; „(700 − 18,50 − 50) ÷ 12 dní = 52,63"
 * jde ověřit na kalkulačce.
 *
 * Zavřené, dokud se na to někdo nezeptá. Rozpis u každého čísla by z přehledu udělal
 * učebnici.
 */
export default function JakSePocita({ nadpis, radky, poznamka }: {
    nadpis: string;
    /** Kroky výpočtu. `hodnota` se zobrazí vpravo, zarovnaná na číslice. */
    radky: Array<{ popis: string; hodnota: string; vysledek?: boolean }>;
    poznamka?: ReactNode;
}) {
    const [otevrene, setOtevrene] = useState(false);

    return (
        <div className="mt-2">
            <button type="button" onClick={() => setOtevrene(o => ! o)}
                aria-expanded={otevrene}
                className="inline-flex min-h-11 items-center gap-1 text-[11px] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                <HelpCircle size={13}/> Jak se to počítá?
            </button>

            {otevrene && (
                <div className="mt-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3">
                    <p className="mb-2 text-xs font-medium text-[var(--color-text-primary)]">{nadpis}</p>

                    <dl className="space-y-1">
                        {radky.map((r, i) => (
                            <div key={i}
                                className={`flex items-baseline justify-between gap-3 text-[11px] ${
                                    r.vysledek ? 'border-t border-[var(--color-border)] pt-1 font-medium' : ''
                                }`}>
                                <dt className={r.vysledek ? 'text-[var(--color-text-primary)]' : 'text-[var(--color-text-secondary)]'}>
                                    {r.popis}
                                </dt>
                                <dd className="shrink-0 tabular-nums text-[var(--color-text-primary)]">{r.hodnota}</dd>
                            </div>
                        ))}
                    </dl>

                    {poznamka && (
                        <p className="mt-2 border-t border-[var(--color-border)] pt-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            {poznamka}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
