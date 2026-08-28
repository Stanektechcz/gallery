import { kurz, penize, TYPY_ZAZNAMU, type TypZaznamu } from '@/lib/penize';
import { ArrowRightLeft, ArrowDownLeft, ArrowUpRight, Banknote, Repeat, Undo2 } from 'lucide-react';
import type { Pohyb } from './typy';

/**
 * Jeden řádek seznamu pohybů.
 *
 * Směna a převod se ukazují jako **jeden** záznam s oběma stranami. Dva řádky
 * („odešlo z CZK", „přišlo na EUR") by vypadaly jako dvě různé věci a člověk by
 * v seznamu viděl dvojnásobek pohybů, než kolik jich doopravdy udělal.
 *
 * Typ se pozná z ikony, barvy **i** slova. Samotná barva to neunese — rozdíl mezi
 * převodem a výdajem je v tomhle modulu ta nejdůležitější informace a nesmí záviset
 * na tom, jak kdo vidí odstíny.
 */
export default function RadekPohybu({ pohyb, onClick }: { pohyb: Pohyb; onClick?: () => void }) {
    const nastaveni = TYPY_ZAZNAMU[pohyb.type as TypZaznamu] ?? TYPY_ZAZNAMU.expense;
    const Ikona = ikona(pohyb);

    const Obal = onClick ? 'button' : 'div';

    return (
        <li>
            <Obal type={onClick ? 'button' : undefined} onClick={onClick}
                className={`flex w-full items-start gap-2.5 py-2.5 text-left ${onClick ? 'min-h-[3rem]' : ''}`}>
                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                    style={{ background: `color-mix(in srgb, ${nastaveni.barva} 18%, transparent)` }}>
                    <Ikona size={15} style={{ color: nastaveni.barva }}/>
                </span>

                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-baseline gap-x-1.5">
                        <span className="text-sm font-medium text-[var(--color-text-primary)]">
                            {pohyb.category?.name ?? pohyb.counterparty ?? pohyb.type_label}
                        </span>
                        <span className="text-[11px] text-[var(--color-text-secondary)]">{pohyb.type_label}</span>
                        {! pohyb.counts_to_budget && pohyb.type === 'expense' && (
                            <span className="rounded-full border border-[var(--color-border)] px-1.5 text-[10px] text-[var(--color-text-secondary)]">
                                mimo rozpočet
                            </span>
                        )}
                    </span>

                    <span className="mt-0.5 block truncate text-[11px] text-[var(--color-text-secondary)]">
                        {popis(pohyb)}
                    </span>
                </span>

                <span className="shrink-0 text-right">
                    <span className="block text-sm font-medium tabular-nums"
                        style={{ color: pohyb.type === 'income' ? 'var(--fin-prijem)' : 'var(--color-text-primary)' }}>
                        {nastaveni.znamenko}{castka(pohyb)}
                    </span>
                    {pohyb.rate && (
                        <span className="block text-[10px] tabular-nums text-[var(--color-text-secondary)]">
                            {kurz(pohyb.rate.effective)} Kč/€
                        </span>
                    )}
                    {pohyb.fee > 0 && pohyb.fee_currency && ! pohyb.rate && (
                        <span className="block text-[10px] tabular-nums text-[var(--color-text-secondary)]">
                            +{penize(pohyb.fee, pohyb.fee_currency)} poplatek
                        </span>
                    )}
                </span>
            </Obal>
        </li>
    );
}

/** Částka, kterou má smysl na řádku ukázat: u přesunů to, co odešlo. */
function castka(p: Pohyb): string {
    if (p.type === 'income') return p.to ? penize(p.to.amount, p.to.currency) : '';
    if (p.from) return penize(p.from.amount, p.from.currency);

    return p.to ? penize(p.to.amount, p.to.currency) : '';
}

/** Druhý řádek — účty, cesta, místo. U směny obě strany v jednom. */
function popis(p: Pohyb): string {
    const casti: string[] = [];

    if (p.type === 'exchange' && p.from && p.to) {
        casti.push(`${p.from.name} → ${p.to.name}`);
        casti.push(`přišlo ${penize(p.to.amount, p.to.currency)}`);
        if (p.provider) casti.push(p.provider);
    } else if (p.from && p.to) {
        casti.push(`${p.from.name} → ${p.to.name}`);
    } else if (p.from) {
        casti.push(p.from.name);
    } else if (p.to) {
        casti.push(p.to.name);
    }

    if (p.payer) casti.push(`platil ${p.payer}`);
    if (p.place) casti.push(p.place);
    if (p.description) casti.push(p.description);
    if (p.trip) casti.push(p.trip);

    return casti.join(' · ');
}

function ikona(p: Pohyb) {
    if (p.is_settlement) return Repeat;
    if (p.is_refund) return Undo2;

    return {
        income: ArrowDownLeft,
        expense: ArrowUpRight,
        transfer: Repeat,
        exchange: ArrowRightLeft,
        withdrawal: Banknote,
        deposit: Banknote,
    }[p.type] ?? ArrowUpRight;
}
