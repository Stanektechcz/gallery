import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Jeden panel v přehledu.
 *
 * Existuje proto, že rozpočty i cyklus si každý kreslily vlastní kartu s vlastním
 * odsazením a vlastní velikostí nadpisu. Deset skoro stejných karet vedle sebe vypadá
 * jako nedodělek — a hlavně se v nich hůř čte, protože oko nemá čeho se chytit.
 *
 * Tón panelu není dekorace. Klidný panel je stav, zvýrazněný je něco, co si žádá
 * pozornost, varovný něco, co se blíží ke špatnému konci. Když jsou všechny panely
 * stejně důležité, není důležitý žádný.
 */

export type PanelTone = 'plain' | 'accent' | 'warn' | 'danger';

const TONE: Record<PanelTone, string> = {
    plain: 'border-[var(--color-border)] bg-[var(--color-bg-card)]',
    accent: 'border-[var(--color-accent)]/30 bg-[var(--color-accent)]/5',
    warn: 'border-amber-400/30 bg-amber-500/5',
    danger: 'border-red-400/30 bg-red-500/5',
};

const ICON_TONE: Record<PanelTone, string> = {
    plain: 'text-[var(--color-text-secondary)]',
    accent: 'text-[var(--color-accent)]',
    warn: 'text-amber-300',
    danger: 'text-red-300',
};

export default function Panel({
    title, description, icon: Icon, tone = 'plain', actions, footnote, className = '', bodyClassName = '', children,
}: {
    title?: string;
    description?: string;
    icon?: LucideIcon;
    tone?: PanelTone;
    /** Tlačítka vpravo v hlavičce — export, přepnutí, zavření. */
    actions?: ReactNode;
    /** Vysvětlivka dole malým písmem. Patří sem to, co je potřeba jen jednou. */
    footnote?: ReactNode;
    className?: string;
    bodyClassName?: string;
    children: ReactNode;
}) {
    return (
        // Bez h-full. Panel v buňce mřížky se na výšku řádku natáhne sám (grid items
        // mají stretch), kdežto h-full uvnitř sloupce, kde jsou panely nad sebou, natáhne
        // na plnou výšku každý z nich — a druhý pak vyleze pod mřížku.
        <section className={`flex w-full flex-col rounded-2xl border p-4 ${TONE[tone]} ${className}`}>
            {(title || actions) && (
                <header className="mb-3 flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        {title && (
                            // min-w-0 na obojím a zalomení na textu: bez toho dlouhý nadpis
                            // („červenec 2026 vs srpen 2026") přeteče panel na telefonu,
                            // protože položka flexu se sama pod svůj obsah nesmrskne.
                            <h2 className="flex min-w-0 items-start gap-2 text-sm font-semibold text-[var(--color-text-primary)]">
                                {Icon && <Icon size={15} className={`mt-0.5 shrink-0 ${ICON_TONE[tone]}`}/>}
                                <span className="min-w-0 break-words">{title}</span>
                            </h2>
                        )}
                        {description && (
                            <p className="mt-1 text-xs leading-relaxed text-[var(--color-text-secondary)]">{description}</p>
                        )}
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
                </header>
            )}

            <div className={`min-w-0 flex-1 ${bodyClassName}`}>{children}</div>

            {footnote && (
                <p className="mt-3 border-t border-[var(--color-border)] pt-2.5 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                    {footnote}
                </p>
            )}
        </section>
    );
}

/**
 * Jedno číslo, na které se člověk dívá jako první.
 *
 * Vlastní komponenta, ne panel s jedním odstavcem: dlaždice má jinou hierarchii — číslo
 * je hlavní a popisek vedlejší, kdežto v panelu je to naopak.
 */
export function Stat({
    label, value, hint, tone = 'plain', icon: Icon,
}: {
    label: string;
    value: string;
    hint?: ReactNode;
    tone?: PanelTone;
    icon?: LucideIcon;
}) {
    return (
        <div className={`rounded-2xl border p-4 ${TONE[tone]}`}>
            <p className="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                {Icon && <Icon size={12} className={ICON_TONE[tone]}/>}
                {label}
            </p>
            {/* tabular-nums: aby čísla pod sebou neposkakovala, když se změní o jednu číslici. */}
            <p className="mt-1.5 text-xl font-semibold tabular-nums text-[var(--color-text-primary)]">{value}</p>
            {hint && <p className="mt-0.5 text-[11px] leading-snug text-[var(--color-text-secondary)]">{hint}</p>}
        </div>
    );
}
