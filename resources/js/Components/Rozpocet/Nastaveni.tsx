import { hlaska } from '@/Components/Hlasky';
import Panel from '@/Components/Panel';
import axios from 'axios';
import {
    ChevronRight, Eye, EyeOff, Palette, Plus, Star, Tags, Trash2, Users, Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Dialog } from './Ucty';
import type { Ciselniky } from './typy';

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2.5 text-base text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const POPISEK = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

type KategorieSpravy = {
    uuid: string; id: number; name: string; kind: 'expense' | 'income';
    icon: string | null; color: string | null;
    is_favourite: boolean; is_active: boolean; used: number;
};

/**
 * Nastavení — rozdělené do sekcí, ne jedna nekonečná stránka.
 *
 * Na mobilu se otevírá po jedné sekci. Dlouhá stránka s deseti bloky pod sebou
 * znamená, že se to, co člověk hledá, najde scrollováním naslepo.
 */
export default function Nastaveni({ ciselniky, onZmena }: { ciselniky: Ciselniky; onZmena: () => void }) {
    const [sekce, setSekce] = useState<string | null>(null);

    const seznam = [
        { id: 'partneri', nazev: 'Adri a Maki', popis: 'Kdo se dělí o společné výdaje', ikona: Users,
            pocet: `${ciselniky.partners.length}` },
        { id: 'kategorie', nazev: 'Kategorie', popis: 'Názvy, barvy a co se nabízí jako první', ikona: Tags,
            pocet: `${ciselniky.categories.length}` },
        { id: 'ucty', nazev: 'Účty', popis: 'Spravují se v tabu Účty', ikona: Wallet,
            pocet: `${ciselniky.wallets.length}` },
        { id: 'vzhled', nazev: 'Vzhled a zobrazení', popis: 'Motiv se přepíná v nastavení galerie', ikona: Palette },
    ];

    return (
        <div className="space-y-3">
            <ul className="space-y-2">
                {seznam.map(s => (
                    <li key={s.id}>
                        <button type="button" onClick={() => setSekce(s.id)}
                            className="flex min-h-[3.5rem] w-full items-center gap-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] px-4 text-left transition-colors hover:border-[var(--color-accent)]">
                            <s.ikona size={18} className="shrink-0 text-[var(--color-text-secondary)]"/>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium text-[var(--color-text-primary)]">{s.nazev}</span>
                                <span className="block truncate text-[11px] text-[var(--color-text-secondary)]">{s.popis}</span>
                            </span>
                            {s.pocet && (
                                <span className="shrink-0 text-xs tabular-nums text-[var(--color-text-secondary)]">{s.pocet}</span>
                            )}
                            <ChevronRight size={16} className="shrink-0 text-[var(--color-text-secondary)]"/>
                        </button>
                    </li>
                ))}
            </ul>

            {sekce === 'partneri' && <SekcePartneru ciselniky={ciselniky} onZmena={onZmena} onZavrit={() => setSekce(null)}/>}
            {sekce === 'kategorie' && <SekceKategorii onZmena={onZmena} onZavrit={() => setSekce(null)}/>}

            {sekce === 'ucty' && (
                <Dialog nadpis="Účty" onZavrit={() => setSekce(null)}>
                    <p className="text-sm leading-relaxed text-[var(--color-text-secondary)]">
                        Účty se zakládají a upravují přímo v tabu <strong className="text-[var(--color-text-primary)]">Účty</strong> —
                        je tam vidět i zůstatek, takže se nemusí hledat na dvou místech.
                    </p>
                </Dialog>
            )}

            {sekce === 'vzhled' && (
                <Dialog nadpis="Vzhled a zobrazení" onZavrit={() => setSekce(null)}>
                    <p className="text-sm leading-relaxed text-[var(--color-text-secondary)]">
                        Světlý a tmavý motiv, hustota rozhraní i omezení animací se nastavují jednou
                        pro celou galerii — rozpočet je používá taky. Najdete je v nastavení vzhledu.
                    </p>
                </Dialog>
            )}
        </div>
    );
}

/**
 * Partneři.
 *
 * Dva stačí; víc jich modul zvládne, ale rychlá volba „Společné / Adri / Maki"
 * u výdaje se počítá se dvěma. Proto se u třetího a dalších rozdělení zadává ručně.
 */
function SekcePartneru({ ciselniky, onZmena, onZavrit }: {
    ciselniky: Ciselniky; onZmena: () => void; onZavrit: () => void;
}) {
    const [jmeno, setJmeno] = useState('');
    const [uklada, setUklada] = useState(false);

    const pridej = async () => {
        setUklada(true);

        try {
            await axios.post('/api/v1/rozpocet/partneri', { name: jmeno });
            hlaska(`${jmeno} je přidaný.`, 'uspech');
            setJmeno('');
            onZmena();
        } catch {
            hlaska('Partnera se nepodařilo přidat.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    return (
        <Dialog nadpis="Adri a Maki" onZavrit={onZavrit}>
            <p className="mb-3 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                Kdo se dělí o společné výdaje. U výdaje se pak nabídne volba
                <strong className="text-[var(--color-text-primary)]"> Společné / {ciselniky.partners[0]?.name ?? 'první'} / {ciselniky.partners[1]?.name ?? 'druhý'}</strong>,
                a z toho vzniká saldo „kdo komu kolik dluží".
            </p>

            {ciselniky.partners.length > 0 ? (
                <ul className="mb-3 space-y-1.5">
                    {ciselniky.partners.map(p => (
                        <li key={p.id} className="flex items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 py-2.5">
                            <Users size={15} className="shrink-0 text-[var(--color-text-secondary)]"/>
                            <span className="text-sm text-[var(--color-text-primary)]">{p.name}</span>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mb-3 rounded-xl border border-dashed border-[var(--color-border)] px-3 py-4 text-center text-xs text-[var(--color-text-secondary)]">
                    Zatím nikdo. Bez partnerů se výdaje nedělí a saldo se nepočítá.
                </p>
            )}

            {ciselniky.partners.length < 2 && (
                <div>
                    <label className={POPISEK} htmlFor="partner-jmeno">Přidat</label>
                    <div className="flex gap-2">
                        <input id="partner-jmeno" value={jmeno} onChange={e => setJmeno(e.target.value)}
                            placeholder={ciselniky.partners.length === 0 ? 'Adri' : 'Maki'} className={POLE}/>
                        <button type="button" onClick={() => void pridej()} disabled={uklada || ! jmeno.trim()}
                            className="min-h-11 shrink-0 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                            <Plus size={16}/>
                        </button>
                    </div>
                </div>
            )}

            {ciselniky.partners.length >= 2 && (
                <p className="text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                    Dva partneři jsou nastavení, se kterým počítá rychlá volba u výdaje. Přidat
                    dalšího jde přes účty — ale rozdělení se pak zadává ručně.
                </p>
            )}
        </Dialog>
    );
}

/**
 * Kategorie.
 *
 * Použitá kategorie nejde smazat — u starých výdajů by zůstala díra a rozpad by se
 * rozešel se součtem. Jde ale odložit: přestane se nabízet a u historie zůstane.
 * Proto je u každé vidět, kolikrát se použila.
 */
function SekceKategorii({ onZmena, onZavrit }: { onZmena: () => void; onZavrit: () => void }) {
    const [kategorie, setKategorie] = useState<KategorieSpravy[]>([]);
    const [nacita, setNacita] = useState(true);
    const [nova, setNova] = useState('');
    const [chyba, setChyba] = useState('');

    /*
     * Zdejší seznam je jiný než ten pro formulář: obsahuje i odložené kategorie
     * a u každé počet použití. Formulář výdaje naopak schválně nabízí jen aktivní —
     * odložená kategorie se nemá objevit v nabídce, jen v historii.
     */
    useEffect(() => {
        void (async () => {
            try {
                const { data } = await axios.get<{ categories: KategorieSpravy[] }>('/api/v1/rozpocet/kategorie');
                setKategorie(data.categories);
            } catch {
                setChyba('Kategorie se nepodařilo načíst.');
            } finally {
                setNacita(false);
            }
        })();
    }, []);

    const uprav = async (k: KategorieSpravy, zmena: Partial<KategorieSpravy>) => {
        try {
            const { data } = await axios.patch<{ categories: KategorieSpravy[] }>(
                `/api/v1/rozpocet/kategorie/${k.uuid}`, zmena);
            setKategorie(data.categories);
            onZmena();
        } catch {
            hlaska('Změnu se nepodařilo uložit.', 'chyba');
        }
    };

    const smaz = async (k: KategorieSpravy) => {
        try {
            const { data } = await axios.delete<{ categories: KategorieSpravy[] }>(`/api/v1/rozpocet/kategorie/${k.uuid}`);
            setKategorie(data.categories);
            hlaska('Kategorie je smazaná.', 'uspech');
            onZmena();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Kategorii se nepodařilo smazat.', 'chyba');
        }
    };

    const pridej = async () => {
        try {
            const { data } = await axios.post<{ categories: KategorieSpravy[] }>('/api/v1/rozpocet/kategorie', {
                name: nova, kind: 'expense', color: 'var(--graf-7)', is_favourite: false,
            });
            setKategorie(data.categories);
            setNova('');
            onZmena();
        } catch {
            hlaska('Kategorii se nepodařilo přidat.', 'chyba');
        }
    };

    const vydajove = kategorie.filter(k => k.kind === 'expense');
    const prijmove = kategorie.filter(k => k.kind === 'income');

    return (
        <Dialog nadpis="Kategorie" onZavrit={onZavrit}>
            <p className="mb-3 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                Hvězdička znamená, že se kategorie nabídne hned u výdaje — vejde se jich šest.
                Oko ji odloží: přestane se nabízet, ale u dřívějších výdajů zůstane.
            </p>

            {nacita && <p className="text-xs text-[var(--color-text-secondary)]">Načítám…</p>}
            {chyba && <p className="text-xs text-red-400">{chyba}</p>}

            {! nacita && (
                <>
                    <div className="mb-3 flex gap-2">
                        <input value={nova} onChange={e => setNova(e.target.value)}
                            aria-label="Název nové kategorie"
                            placeholder="Nová kategorie" className={POLE}/>
                        <button type="button" onClick={() => void pridej()} disabled={! nova.trim()}
                            className="min-h-11 shrink-0 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                            <Plus size={16}/>
                        </button>
                    </div>

                    <Skupina nazev="Výdaje" kategorie={vydajove} onUprav={uprav} onSmaz={smaz}/>
                    {prijmove.length > 0 && <Skupina nazev="Příjmy" kategorie={prijmove} onUprav={uprav} onSmaz={smaz}/>}
                </>
            )}
        </Dialog>
    );
}

function Skupina({ nazev, kategorie, onUprav, onSmaz }: {
    nazev: string;
    kategorie: KategorieSpravy[];
    onUprav: (k: KategorieSpravy, z: Partial<KategorieSpravy>) => void;
    onSmaz: (k: KategorieSpravy) => void;
}) {
    const oblibenych = kategorie.filter(k => k.is_favourite && k.is_active).length;

    return (
        <div className="mt-3">
            <p className={POPISEK}>{nazev}</p>
            <ul className="space-y-1">
                {kategorie.map(k => (
                    <li key={k.uuid}
                        className={`flex items-center gap-2 rounded-xl border px-2.5 py-1.5 ${
                            k.is_active ? 'border-[var(--color-border)]' : 'border-dashed border-[var(--color-border)] opacity-60'
                        }`}>
                        <span className="h-2.5 w-2.5 shrink-0 rounded-full"
                            style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm text-[var(--color-text-primary)]">{k.name}</span>
                            {k.used > 0 && (
                                <span className="block text-[10px] text-[var(--color-text-secondary)]">
                                    použitá {k.used}×
                                </span>
                            )}
                        </span>

                        <button type="button"
                            onClick={() => onUprav(k, { is_favourite: ! k.is_favourite })}
                            disabled={! k.is_favourite && oblibenych >= 6}
                            aria-label={k.is_favourite ? `Odebrat ${k.name} z rychlé volby` : `Přidat ${k.name} do rychlé volby`}
                            title={! k.is_favourite && oblibenych >= 6 ? 'V rychlé volbě je místo pro šest' : undefined}
                            className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg disabled:opacity-30 ${
                                k.is_favourite ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'
                            }`}>
                            <Star size={15} fill={k.is_favourite ? 'currentColor' : 'none'}/>
                        </button>

                        <button type="button" onClick={() => onUprav(k, { is_active: ! k.is_active })}
                            aria-label={k.is_active ? `Odložit ${k.name}` : `Vrátit ${k.name} do nabídky`}
                            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)]">
                            {k.is_active ? <Eye size={15}/> : <EyeOff size={15}/>}
                        </button>

                        {/* Koš jen u nepoužitých. U použité by stejně vrátil 409 —
                            nabízet akci, která nemůže vyjít, je horší než ji neukázat. */}
                        {k.used === 0 && (
                            <button type="button" onClick={() => onSmaz(k)}
                                aria-label={`Smazat ${k.name}`}
                                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:text-red-400">
                                <Trash2 size={15}/>
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
