import { hlaska } from '@/Components/Hlasky';
import Panel from '@/Components/Panel';
import axios from 'axios';
import {
    CalendarClock, ChevronRight, Eye, EyeOff, Merge, Palette, Plus, Sliders, Star, Tags, Trash2, Users, Wallet, Zap,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import Pravidelne from './Pravidelne';
import { Dialog } from './Ucty';
import { castka as prectiCastku, penize } from '@/lib/penize';
import type { Ciselniky, Predvolby as PredvolbyTyp, Sablona } from './typy';

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2.5 text-base text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const POPISEK = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

/** Skupina účtů, které drží tytéž peníze. */
type SkupinaDuplicit = {
    currency: string;
    kind: string;
    wallets: Array<{ uuid: string; name: string; opening_balance: number }>;
};

/** Co se stane sloučením. Napřed se řekne, teprve pak se to udělá. */
type NahledSlouceni = {
    from: { uuid: string; name: string };
    to: { uuid: string; name: string };
    transactions: number;
    new_opening_balance: number;
    suspicious_double: boolean;
};

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
        { id: 'pravidelne', nazev: 'Pravidelné platby', popis: 'Nájem a spol. — zapíše se jednou a chodí sám', ikona: CalendarClock },
        { id: 'sablony', nazev: 'Rychlý zápis', popis: 'Šablony, které předvyplní všechno kromě částky', ikona: Zap },
        { id: 'ucty', nazev: 'Účty', popis: 'Spravují se v tabu Účty', ikona: Wallet,
            pocet: `${ciselniky.wallets.length}` },
        { id: 'predvolby', nazev: 'Výchozí nastavení', popis: 'Čím se modul otevře a s čím počítá', ikona: Sliders },
        { id: 'duplicity', nazev: 'Zdvojené položky', popis: 'Dva účty na tytéž peníze, dvě kategorie na totéž', ikona: Merge },
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
            {sekce === 'sablony' && <SekceSablon ciselniky={ciselniky} onZavrit={() => setSekce(null)}/>}
            {sekce === 'pravidelne' && <Pravidelne ciselniky={ciselniky} onZmena={onZmena} onZavrit={() => setSekce(null)}/>}

            {sekce === 'ucty' && (
                <Dialog nadpis="Účty" onZavrit={() => setSekce(null)}>
                    <p className="text-sm leading-relaxed text-[var(--color-text-secondary)]">
                        Účty se zakládají a upravují přímo v tabu <strong className="text-[var(--color-text-primary)]">Účty</strong> —
                        je tam vidět i zůstatek, takže se nemusí hledat na dvou místech.
                    </p>
                </Dialog>
            )}

            {sekce === 'predvolby' && <Predvolby onZavrit={() => setSekce(null)}/>}
            {sekce === 'duplicity' && <Duplicity onZmena={onZmena} onZavrit={() => setSekce(null)}/>}

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
 * Zdvojené účty a kategorie.
 *
 * Duplicity vzniknou samy — „EUR karta" a „Eura na kartě" znamenají totéž a peníze
 * jsou pak rozdělené mezi dvě položky, ze kterých ani jedna neukazuje celý obrázek.
 *
 * Nabízí se, nespouští se, a před sloučením se řekne, kolik záznamů se pohne. Slití
 * je nevratné; kdo předem nevidí, co se stane, si jednou omylem slije půl roku dat.
 */
function Duplicity({ onZmena, onZavrit }: { onZmena: () => void; onZavrit: () => void }) {
    const [skupiny, setSkupiny] = useState<SkupinaDuplicit[] | null>(null);
    const [navrh, setNavrh] = useState<{ from: string; to: string; nazvy: [string, string]; mena: string } | null>(null);
    const [nahled, setNahled] = useState<NahledSlouceni | null>(null);
    const [bezi, setBezi] = useState(false);

    const nacti = async () => {
        try {
            const { data } = await axios.get<{ wallets: SkupinaDuplicit[] }>('/api/v1/rozpocet/duplicity');
            setSkupiny(data.wallets);
        } catch {
            hlaska('Zdvojené položky se nepodařilo načíst.', 'chyba');
        }
    };

    useEffect(() => { void nacti(); }, []);

    const pripravSlouceni = async (from: string, to: string, nazvy: [string, string], mena: string) => {
        setNavrh({ from, to, nazvy, mena });

        try {
            const { data } = await axios.post('/api/v1/rozpocet/sloucit', { kind: 'wallet', from, to });
            setNahled(data.preview);
        } catch {
            hlaska('Náhled se nepodařilo připravit.', 'chyba');
            setNavrh(null);
        }
    };

    const sluc = async () => {
        if (! navrh) return;

        setBezi(true);

        try {
            const { data } = await axios.post('/api/v1/rozpocet/sloucit',
                { kind: 'wallet', from: navrh.from, to: navrh.to, potvrzeno: true });
            hlaska(data.message, 'uspech');
            setNavrh(null);
            setNahled(null);
            await nacti();
            onZmena();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Sloučit se nepodařilo.', 'chyba');
        } finally {
            setBezi(false);
        }
    };

    return (
        <Dialog nadpis="Zdvojené položky" onZavrit={onZavrit}>
            {skupiny === null ? (
                <div className="h-24 animate-pulse rounded-xl bg-[var(--color-surface-muted)]" aria-busy="true" aria-label="Načítám"/>
            ) : skupiny.length === 0 ? (
                <p className="text-sm leading-relaxed text-[var(--color-text-secondary)]">
                    Nic zdvojeného. Na každou měnu je jeden účet a jedna hotovost —
                    přesně tak, aby zůstatek ukazoval celou částku.
                </p>
            ) : (
                <div className="space-y-3">
                    {skupiny.map(s => (
                        <div key={`${s.currency}-${s.kind}`} className="rounded-xl border border-[var(--color-border)] p-3">
                            <p className="text-xs font-medium text-[var(--color-text-primary)]">
                                {s.wallets.length}× {s.kind} v {s.currency}
                            </p>
                            <p className="mt-0.5 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                Peníze jsou rozdělené mezi ně a ani jeden neukazuje celý zůstatek.
                            </p>
                            <ul className="mt-2 space-y-1.5">
                                {s.wallets.map((u, i) => (
                                    <li key={u.uuid} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                        <span className="min-w-0 flex-1 basis-28 truncate text-[var(--color-text-primary)]">{u.name}</span>
                                        <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                                            počátek {penize(u.opening_balance, s.currency)}
                                        </span>
                                        {i > 0 && (
                                            <button type="button"
                                                onClick={() => void pripravSlouceni(u.uuid, s.wallets[0].uuid, [u.name, s.wallets[0].name], s.currency)}
                                                className="shrink-0 rounded-lg border border-[var(--color-border)] px-2 py-1 text-[11px] text-[var(--color-text-primary)] hover:border-[var(--color-accent)]">
                                                Slít do „{s.wallets[0].name}"
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}

            {navrh && nahled && (
                <div className="mt-3 rounded-xl border border-amber-500/40 bg-[var(--color-surface-muted)] p-3">
                    <p className="text-xs font-medium text-[var(--color-text-primary)]">
                        Slít „{navrh.nazvy[0]}" do „{navrh.nazvy[1]}"?
                    </p>
                    <ul className="mt-1 space-y-0.5 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                        <li>Přepojí se záznamů: <strong className="text-[var(--color-text-primary)]">{nahled.transactions}</strong></li>
                        <li>
                            Počáteční zůstatek se sečte na{' '}
                            <strong className="tabular-nums text-[var(--color-text-primary)]">
                                {penize(nahled.new_opening_balance, navrh.mena)}
                            </strong>
                        </li>
                        <li>Slití je nevratné. Historie zůstane, jen bude pod jedním účtem.</li>
                    </ul>
                    {nahled.suspicious_double && (
                        <p className="mt-1.5 text-[11px] leading-relaxed text-amber-400">
                            Oba účty mají stejný počáteční zůstatek. Bývá to podpis omylem zdvojeného
                            účtu — tytéž peníze zapsané dvakrát. Součet by vyrobil peníze, které nikdy
                            nebyly. Pokud jde o tenhle případ, prázdný účet radši smažte v tabu Účty.
                        </p>
                    )}
                    <div className="mt-2 flex flex-wrap gap-2">
                        <button type="button" onClick={() => void sluc()} disabled={bezi}
                            className="min-h-11 rounded-lg bg-[var(--color-accent)] px-3 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                            Opravdu slít
                        </button>
                        <button type="button" onClick={() => { setNavrh(null); setNahled(null); }}
                            className="min-h-11 rounded-lg px-3 text-sm text-[var(--color-text-secondary)]">
                            Zpět
                        </button>
                    </div>
                </div>
            )}
        </Dialog>
    );
}

/**
 * Výchozí nastavení modulu.
 *
 * Nabízí se **jen to, co něco doopravdy dělá.** Tabulka předvoleb má sloupců dvacet,
 * napojených je osm — zbylé by byly přepínače bez účinku, a ty jsou horší než žádné:
 * jednou se s nimi pohne, nic se nestane a od té chvíle nikdo nevěří ani ostatním.
 *
 * Ukládá se hned při změně. Formulář s tlačítkem Uložit by u osmi voleb znamenal, že
 * půlka lidí odejde a změny se zahodí.
 */
function Predvolby({ onZavrit }: { onZavrit: () => void }) {
    const [hodnoty, setHodnoty] = useState<PredvolbyTyp | null>(null);
    const [uklada, setUklada] = useState(false);

    useEffect(() => {
        void (async () => {
            try {
                const { data } = await axios.get<{ settings: PredvolbyTyp }>('/api/v1/rozpocet/nastaveni');
                setHodnoty(data.settings);
            } catch {
                hlaska('Nastavení se nepodařilo načíst.', 'chyba');
            }
        })();
    }, []);

    const zmen = async (zmena: Partial<PredvolbyTyp>) => {
        setHodnoty(h => (h ? { ...h, ...zmena } : h));
        setUklada(true);

        try {
            const { data } = await axios.patch<{ settings: PredvolbyTyp }>('/api/v1/rozpocet/nastaveni', zmena);
            setHodnoty(data.settings);
        } catch {
            hlaska('Změnu se nepodařilo uložit.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    return (
        <Dialog nadpis="Výchozí nastavení" onZavrit={onZavrit}>
            {hodnoty === null ? (
                <div className="h-40 animate-pulse rounded-xl bg-[var(--color-surface-muted)]" aria-busy="true" aria-label="Načítám"/>
            ) : (
                <div className="space-y-3" aria-busy={uklada}>
                    <div>
                        <label className={POPISEK} htmlFor="pred-tab">Čím se modul otevře</label>
                        <select id="pred-tab" value={hodnoty.default_tab} className={POLE}
                            onChange={e => void zmen({ default_tab: e.target.value })}>
                            {[['prehled', 'Přehled'], ['transakce', 'Transakce'], ['rozpocty', 'Rozpočty'],
                                ['smeny', 'Směny'], ['cesty', 'Cesty'], ['statistiky', 'Statistiky'],
                                ['ucty', 'Účty']].map(([k, p]) => <option key={k} value={k}>{p}</option>)}
                        </select>
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            Odkaz na konkrétní záložku má přednost — kdo ho dostane, uvidí ji.
                        </p>
                    </div>

                    <div>
                        <label className={POPISEK} htmlFor="pred-obdobi">Jaké období se předvybere</label>
                        <select id="pred-obdobi" value={hodnoty.default_period} className={POLE}
                            onChange={e => void zmen({ default_period: e.target.value })}>
                            {[['dnes', 'Dnes'], ['tyden', 'Tento týden'], ['mesic', 'Tento měsíc'],
                                ['minuly-mesic', 'Minulý měsíc'], ['cesta', 'Probíhající cesta'],
                                ['vse', 'Vše']].map(([k, p]) => <option key={k} value={k}>{p}</option>)}
                        </select>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className={POPISEK} htmlFor="pred-domaci">Domácí měna</label>
                            <select id="pred-domaci" value={hodnoty.home_currency} className={POLE}
                                onChange={e => void zmen({ home_currency: e.target.value })}>
                                {['CZK', 'EUR'].map(m => <option key={m} value={m}>{m}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className={POPISEK} htmlFor="pred-cestovni">Měna na cestách</label>
                            <select id="pred-cestovni" value={hodnoty.travel_currency} className={POLE}
                                onChange={e => void zmen({ travel_currency: e.target.value })}>
                                {['EUR', 'CZK'].map(m => <option key={m} value={m}>{m}</option>)}
                            </select>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className={POPISEK} htmlFor="pred-rezerva">Výchozí rezerva</label>
                            <input id="pred-rezerva" type="text" inputMode="decimal"
                                defaultValue={hodnoty.default_reserve || ''}
                                onBlur={e => void zmen({ default_reserve: prectiCastku(e.target.value) ?? 0 })}
                                placeholder="0" className={`${POLE} tabular-nums`}/>
                            <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                Předvyplní se u nového rozpočtu. Rezerva se nerozpočítá do denní částky.
                            </p>
                        </div>
                        <div>
                            <label className={POPISEK} htmlFor="pred-hranice">Kdy upozornit (%)</label>
                            <input id="pred-hranice" type="text" inputMode="numeric"
                                defaultValue={hodnoty.alert_thresholds}
                                onBlur={e => void zmen({ alert_thresholds: e.target.value })}
                                placeholder="80,90,100" className={`${POLE} tabular-nums`}/>
                            <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                Hranice čerpání oddělené čárkou.
                            </p>
                        </div>
                    </div>

                    <div>
                        <span className={POPISEK}>Hustota seznamů</span>
                        <div className="grid grid-cols-2 gap-2">
                            {([['pohodlne', 'Pohodlná'], ['husté', 'Hustá']] as const).map(([k, p]) => (
                                <button key={k} type="button" onClick={() => void zmen({ list_density: k })}
                                    aria-pressed={hodnoty.list_density === k}
                                    className={`min-h-11 rounded-xl border px-3 text-sm ${hodnoty.list_density === k
                                        ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 text-[var(--color-text-primary)]'
                                        : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}>
                                    {p}
                                </button>
                            ))}
                        </div>
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            Hustá se vejde na obrazovku o třetinu víc řádků, pohodlná se líp trefuje prstem.
                        </p>
                    </div>

                    <label className="flex items-start gap-2 border-t border-[var(--color-border)] pt-3 text-sm text-[var(--color-text-primary)]">
                        <input type="checkbox" checked={hodnoty.show_partner_balance} className="mt-1 h-4 w-4"
                            onChange={e => void zmen({ show_partner_balance: e.target.checked })}/>
                        <span>
                            Ukazovat vyrovnání mezi námi
                            <span className="block text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                Kdo komu kolik dluží. Když jedete ze společné kasy, nemá to co říct.
                            </span>
                        </span>
                    </label>
                </div>
            )}
        </Dialog>
    );
}

/**
 * Šablony rychlého zápisu.
 *
 * „Potraviny, EUR karta, Maki, společné" jedním klepnutím. Částku šablona nenese —
 * je to jediný údaj, který se pokaždé liší, a předvyplnit ho znamená riskovat, že se
 * jednou uloží útrata, která se nestala.
 */
function SekceSablon({ ciselniky, onZavrit }: { ciselniky: Ciselniky; onZavrit: () => void }) {
    const [sablony, setSablony] = useState<Sablona[]>([]);
    const [nacita, setNacita] = useState(true);
    const [form, setForm] = useState({ name: '', category_uuid: '', wallet_uuid: '', split: 'equal' });
    const [uklada, setUklada] = useState(false);

    useEffect(() => {
        void axios.get<{ templates: Sablona[] }>('/api/v1/rozpocet/sablony')
            .then(({ data }) => setSablony(data.templates))
            .catch(() => hlaska('Šablony se nepodařilo načíst.', 'chyba'))
            .finally(() => setNacita(false));
    }, []);

    const kategorie = ciselniky.categories.filter(k => k.kind === 'expense');

    // Název se nabídne podle kategorie — málokdo si vymyslí lepší než „Potraviny".
    const navrhNazvu = kategorie.find(k => k.uuid === form.category_uuid)?.name ?? '';

    const pridej = async () => {
        setUklada(true);

        try {
            const { data } = await axios.post<{ templates: Sablona[] }>('/api/v1/rozpocet/sablony', {
                name: form.name || navrhNazvu,
                type: 'expense',
                category_uuid: form.category_uuid || null,
                wallet_uuid: form.wallet_uuid || null,
                split: form.split,
            });

            setSablony(data.templates);
            setForm({ name: '', category_uuid: '', wallet_uuid: '', split: 'equal' });
            hlaska('Šablona je uložená.', 'uspech');
        } catch {
            hlaska('Šablonu se nepodařilo uložit.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    const smaz = async (s: Sablona) => {
        try {
            const { data } = await axios.delete<{ templates: Sablona[] }>(`/api/v1/rozpocet/sablony/${s.uuid}`);
            setSablony(data.templates);
        } catch {
            hlaska('Šablonu se nepodařilo smazat.', 'chyba');
        }
    };

    return (
        <Dialog nadpis="Rychlý zápis" onZavrit={onZavrit}>
            <p className="mb-3 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                Šablona předvyplní účet, kategorii i rozdělení — zbude zadat částku.
                V nabídce u výdaje se zobrazí šest nejpoužívanějších.
            </p>

            {nacita && <p className="text-xs text-[var(--color-text-secondary)]">Načítám…</p>}

            {! nacita && sablony.length > 0 && (
                <ul className="mb-4 space-y-1.5">
                    {sablony.map(s => (
                        <li key={s.uuid} className="flex items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 py-2">
                            {s.category?.color && (
                                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: s.category.color }}/>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm text-[var(--color-text-primary)]">{s.name}</span>
                                <span className="block truncate text-[11px] text-[var(--color-text-secondary)]">
                                    {[s.category?.name, s.wallet?.name,
                                        s.split === 'equal' ? 'společné' : s.split === 'first' ? ciselniky.partners[0]?.name : ciselniky.partners[1]?.name,
                                    ].filter(Boolean).join(' · ')}
                                    {s.used_count > 0 && ` · použitá ${s.used_count}×`}
                                </span>
                            </span>
                            <button type="button" onClick={() => void smaz(s)}
                                aria-label={`Smazat šablonu ${s.name}`}
                                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:text-red-400">
                                <Trash2 size={15}/>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {! nacita && sablony.length === 0 && (
                <p className="mb-4 rounded-xl border border-dashed border-[var(--color-border)] px-3 py-4 text-center text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    Zatím žádná. Vyplatí se na to, co se opakuje — nákup potravin, MHD,
                    kafe. Ušetří pokaždé tři klepnutí.
                </p>
            )}

            <div className="space-y-3 border-t border-[var(--color-border)] pt-3">
                <p className={POPISEK}>Nová šablona</p>

                <div>
                    <label className={POPISEK} htmlFor="sablona-kategorie">Kategorie</label>
                    <select id="sablona-kategorie" value={form.category_uuid}
                        onChange={e => setForm(f => ({ ...f, category_uuid: e.target.value }))} className={POLE}>
                        <option value="">Vyberte</option>
                        {kategorie.map(k => <option key={k.uuid} value={k.uuid}>{k.name}</option>)}
                    </select>
                </div>

                <div>
                    <label className={POPISEK} htmlFor="sablona-ucet">Účet</label>
                    <select id="sablona-ucet" value={form.wallet_uuid}
                        onChange={e => setForm(f => ({ ...f, wallet_uuid: e.target.value }))} className={POLE}>
                        <option value="">Nechat na výběru</option>
                        {ciselniky.wallets.map(u => <option key={u.uuid} value={u.uuid}>{u.name} ({u.currency})</option>)}
                    </select>
                </div>

                {ciselniky.partners.length === 2 && (
                    <div>
                        <label className={POPISEK}>Čí výdaj</label>
                        <div className="grid grid-cols-3 gap-1.5">
                            {[
                                { v: 'equal', p: 'Společné' },
                                { v: 'first', p: ciselniky.partners[0].name },
                                { v: 'second', p: ciselniky.partners[1].name },
                            ].map(m => (
                                <button key={m.v} type="button" onClick={() => setForm(f => ({ ...f, split: m.v }))}
                                    aria-pressed={form.split === m.v}
                                    className={`min-h-11 rounded-xl border px-2 text-sm ${
                                        form.split === m.v
                                            ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                            : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                    }`}>
                                    {m.p}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                <div>
                    <label className={POPISEK} htmlFor="sablona-nazev">Název</label>
                    <input id="sablona-nazev" value={form.name}
                        onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                        placeholder={navrhNazvu || 'Např. Nákup'} className={POLE}/>
                </div>

                <button type="button" onClick={() => void pridej()}
                    disabled={uklada || (! form.name && ! navrhNazvu)}
                    className="min-h-11 w-full rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                    Uložit šablonu
                </button>
            </div>
        </Dialog>
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
