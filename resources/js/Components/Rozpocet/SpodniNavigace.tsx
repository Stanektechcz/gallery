import { Link } from '@inertiajs/react';
import {
    ArrowLeft, ArrowRightLeft, BarChart3, LayoutDashboard, MapPin, MoreHorizontal,
    PiggyBank, Plus, Receipt, Settings2, Wallet, X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Osm tabů modulu v pořadí podle četnosti použití.
 *
 * Přehled a zapisování jsou nahoře, protože se používají denně; Nastavení poslední,
 * protože se otevře dvakrát za pobyt.
 */
/*
 * Cesty tu byly do 29. 8. 2026 a odešly.
 *
 * Půlroční pobyt není výlet. Rozpočet na něj má vlastní období a vlastní peníze, takže
 * cesta k němu nic nepřidávala — jen další obrazovku, kterou bylo potřeba vyplnit,
 * aby šel rozpočet vůbec založit.
 */
export const TABY = [
    { id: 'prehled', label: 'Přehled', ikona: LayoutDashboard },
    { id: 'transakce', label: 'Transakce', ikona: Receipt },
    { id: 'rozpocty', label: 'Rozpočty', ikona: PiggyBank },
    { id: 'smeny', label: 'Směny', ikona: ArrowRightLeft },
    { id: 'statistiky', label: 'Statistiky', ikona: BarChart3 },
    { id: 'ucty', label: 'Účty', ikona: Wallet },
    { id: 'nastaveni', label: 'Nastavení', ikona: Settings2 },
] as const;

/** Co se na mobilu schová pod „Více" — v pořadí ze zadání. */
const POD_VICE = ['rozpocty', 'smeny', 'ucty', 'nastaveni'];

/**
 * Spodní lišta pro telefon.
 *
 * Pět položek, ne osm: víc terčů vedle sebe znamená, že se žádný netrefí palcem.
 * Přidat je uprostřed a vystupuje nad lištu — je to jediná akce, kvůli které se
 * modul otevírá za chůze, a musí být dosažitelná bez dívání.
 *
 * Celá lišta respektuje bezpečnou zónu; na telefonech s gestem by jinak spodní řada
 * ikon skončila pod čárou pro zavření aplikace.
 */
export default function SpodniNavigace({ aktivni, onTab, onPridat }: {
    aktivni: string;
    onTab: (tab: string) => void;
    onPridat: () => void;
}) {
    const [viceOtevrene, setViceOtevrene] = useState(false);

    /*
     * Aplikace má vlastní spodní lištu. Dvě přes sebe znamenají, že jedna tu druhou
     * překryje — a protože je modulová navrchu, nešlo by z rozpočtu na telefonu
     * vůbec odejít. Po dobu modulu se proto ta aplikační schová a návrat do galerie
     * se přesune sem, pod „Více".
     */
    useEffect(() => {
        document.body.classList.add('modul-rozpocet');

        return () => document.body.classList.remove('modul-rozpocet');
    }, []);

    const jeVeVice = POD_VICE.includes(aktivni);

    return (
        <>
            {viceOtevrene && (
                <div className="fixed inset-0 z-[930] lg:hidden" role="dialog" aria-modal="true" aria-label="Další části">
                    <button type="button" aria-label="Zavřít" onClick={() => setViceOtevrene(false)}
                        className="absolute inset-0 bg-black/50"/>
                    <div className="absolute inset-x-0 bottom-0 rounded-t-2xl border-t border-[var(--color-border)] bg-[var(--color-bg-card)] p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom,0px))]">
                        <div className="mb-2 flex items-center justify-between">
                            <p className="text-sm font-medium text-[var(--color-text-primary)]">Další části</p>
                            <button type="button" onClick={() => setViceOtevrene(false)}
                                aria-label="Zavřít"
                                className="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--color-text-secondary)]">
                                <X size={18}/>
                            </button>
                        </div>
                        <ul className="space-y-1">
                            {POD_VICE.map(id => {
                                const t = TABY.find(x => x.id === id)!;

                                return (
                                    <li key={id}>
                                        <button type="button"
                                            onClick={() => { onTab(id); setViceOtevrene(false); }}
                                            aria-current={aktivni === id ? 'page' : undefined}
                                            className={`flex min-h-[3rem] w-full items-center gap-2.5 rounded-xl px-3 text-sm ${
                                                aktivni === id
                                                    ? 'bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                                    : 'text-[var(--color-text-secondary)]'
                                            }`}>
                                            <t.ikona size={18}/> {t.label}
                                        </button>
                                    </li>
                                );
                            })}
                            <li className="border-t border-[var(--color-border)] pt-1">
                                <Link href="/"
                                    className="flex min-h-[3rem] w-full items-center gap-2.5 rounded-xl px-3 text-sm text-[var(--color-text-secondary)]">
                                    <ArrowLeft size={18}/> Zpět do galerie
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            )}

            <nav aria-label="Rozpočet"
                className="fixed inset-x-0 bottom-0 z-[920] border-t border-[var(--color-border)] bg-[var(--color-bg-card)] pb-[env(safe-area-inset-bottom,0px)] lg:hidden">
                <ul className="grid grid-cols-5">
                    <Polozka tab={TABY[0]} aktivni={aktivni === 'prehled'} onClick={() => onTab('prehled')}/>
                    <Polozka tab={TABY[1]} aktivni={aktivni === 'transakce'} onClick={() => onTab('transakce')}/>

                    <li className="flex items-start justify-center">
                        <button type="button" onClick={onPridat} aria-label="Přidat záznam"
                            className="-mt-4 flex h-14 w-14 items-center justify-center rounded-full bg-[var(--color-accent)] text-[var(--color-accent-contrast)] shadow-lg">
                            <Plus size={26}/>
                        </button>
                    </li>

                    <Polozka tab={TABY[5]} aktivni={aktivni === 'statistiky'} onClick={() => onTab('statistiky')}/>

                    <li>
                        <button type="button" onClick={() => setViceOtevrene(true)}
                            aria-current={jeVeVice ? 'page' : undefined}
                            className={`flex min-h-[3.5rem] w-full flex-col items-center justify-center gap-0.5 ${
                                jeVeVice ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'
                            }`}>
                            <MoreHorizontal size={20}/>
                            <span className="text-[10px] leading-none">
                                {jeVeVice ? TABY.find(t => t.id === aktivni)!.label : 'Více'}
                            </span>
                        </button>
                    </li>
                </ul>
            </nav>
        </>
    );
}

function Polozka({ tab, aktivni, onClick }: {
    tab: typeof TABY[number]; aktivni: boolean; onClick: () => void;
}) {
    return (
        <li>
            <button type="button" onClick={onClick} aria-current={aktivni ? 'page' : undefined}
                className={`flex min-h-[3.5rem] w-full flex-col items-center justify-center gap-0.5 ${
                    aktivni ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'
                }`}>
                <tab.ikona size={20}/>
                <span className="text-[10px] leading-none">{tab.label}</span>
            </button>
        </li>
    );
}
