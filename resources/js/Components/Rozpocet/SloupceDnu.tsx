import { penize, penizeKratce } from '@/lib/penize';
import { useState } from 'react';

/**
 * Výdaje po dnech.
 *
 * Sloupce jsou HTML, ne SVG. Text uvnitř `viewBox` se totiž škáluje spolu s grafem
 * a na telefonu z desetibodového popisku vyjde šestibodový — to už se nedá přečíst.
 * Tady zůstává písmo písmem a mění se jen šířky sloupců.
 *
 * Dnešek je zvýrazněný a průměr je vodorovná čára: samotná výška sloupce neřekne,
 * jestli je to hodně, dokud není proti čemu.
 */
export default function SloupceDnu({ dny, mena, onDen }: {
    dny: Array<{ date: string; amount: number }>;
    mena: string;
    onDen?: (den: string) => void;
}) {
    const [vybrany, setVybrany] = useState<string | null>(null);

    // Delší období se zhustí na posledních 45 dní — víc sloupců než pixelů znamená,
    // že jsou pod jeden pixel široké a graf je jen šedý pruh.
    const zobrazene = dny.length > 45 ? dny.slice(-45) : dny;

    const maximum = Math.max(...zobrazene.map(d => d.amount), 0);
    const sVydajem = zobrazene.filter(d => d.amount > 0);
    const prumer = sVydajem.length > 0 ? sVydajem.reduce((s, d) => s + d.amount, 0) / sVydajem.length : 0;
    const dnes = new Date().toISOString().slice(0, 10);

    if (maximum <= 0) {
        return (
            <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs text-[var(--color-text-secondary)]">
                V tomhle období zatím žádný výdaj není.
            </p>
        );
    }

    const detail = vybrany ? zobrazene.find(d => d.date === vybrany) : null;

    return (
        <div>
            <div className="flex items-baseline justify-between gap-2">
                <p className="text-xs text-[var(--color-text-secondary)]">
                    {detail
                        ? <>
                            <strong className="text-[var(--color-text-primary)]">{new Date(`${detail.date}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long' })}</strong>
                            {' · '}{penize(detail.amount, mena)}
                        </>
                        : <>Průměrně {penizeKratce(prumer, mena)} za den, kdy se utrácelo</>}
                </p>
                <p className="shrink-0 text-[11px] tabular-nums text-[var(--color-text-secondary)]">
                    max {penizeKratce(maximum, mena)}
                </p>
            </div>

            <div className="relative mt-2" style={{ height: 140 }}>
                {/* Čára průměru. Bez ní je výška sloupce číslo bez měřítka. */}
                {prumer > 0 && (
                    <div className="pointer-events-none absolute inset-x-0 border-t border-dashed border-[var(--color-text-secondary)]/40"
                        style={{ bottom: `${(prumer / maximum) * 100}%` }} aria-hidden="true"/>
                )}

                <ul className="flex h-full items-end gap-[2px]"
                    role="list" aria-label={`Výdaje po dnech v ${mena}`}>
                    {zobrazene.map(d => {
                        const vyska = maximum > 0 ? (d.amount / maximum) * 100 : 0;
                        const jeDnes = d.date === dnes;
                        const jeVybrany = d.date === vybrany;

                        return (
                            <li key={d.date} className="flex h-full flex-1 items-end">
                                <button type="button"
                                    onClick={() => { setVybrany(jeVybrany ? null : d.date); if (jeVybrany && onDen) onDen(d.date); }}
                                    aria-label={`${new Date(`${d.date}T12:00:00`).toLocaleDateString('cs-CZ')}: ${penize(d.amount, mena)}`}
                                    className="group flex h-full w-full items-end justify-center">
                                    <span className="w-full rounded-t-[3px] transition-opacity"
                                        style={{
                                            height: `${Math.max(vyska, d.amount > 0 ? 3 : 0)}%`,
                                            minHeight: d.amount > 0 ? 3 : 0,
                                            background: jeDnes ? 'var(--color-accent)' : 'var(--fin-vydaj)',
                                            opacity: jeVybrany || ! vybrany ? 1 : 0.4,
                                        }}/>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className="mt-1 flex items-baseline justify-between text-[10px] text-[var(--color-text-secondary)]">
                <span>{new Date(`${zobrazene[0].date}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' })}</span>
                <span>{new Date(`${zobrazene[zobrazene.length - 1].date}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' })}</span>
            </div>

            {detail && onDen && (
                <button type="button" onClick={() => onDen(detail.date)}
                    className="mt-2 inline-flex min-h-9 items-center rounded-lg border border-[var(--color-border)] px-2.5 text-xs text-[var(--color-text-primary)]">
                    Zobrazit transakce toho dne
                </button>
            )}
        </div>
    );
}
