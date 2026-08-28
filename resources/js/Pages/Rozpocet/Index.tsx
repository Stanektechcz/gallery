import { hlaska } from '@/Components/Hlasky';
import Cesty from '@/Components/Rozpocet/Cesty';
import Prehled from '@/Components/Rozpocet/Prehled';
import Rozpocty from '@/Components/Rozpocet/Rozpocty';
import PridatZaznam from '@/Components/Rozpocet/PridatZaznam';
import Nastaveni from '@/Components/Rozpocet/Nastaveni';
import Smeny from '@/Components/Rozpocet/Smeny';
import Statistiky from '@/Components/Rozpocet/Statistiky';
import SpodniNavigace, { TABY } from '@/Components/Rozpocet/SpodniNavigace';
import Transakce from '@/Components/Rozpocet/Transakce';
import Ucty from '@/Components/Rozpocet/Ucty';
import type { Ciselniky, Pohyb, Prehled as PrehledData } from '@/Components/Rozpocet/typy';
import AppLayout from '@/Layouts/AppLayout';
import { dny, zaznamy } from '@/lib/cestina';
import { odeslatFrontu, sledujFrontu, type CekajiciZapis } from '@/lib/frontaZapisu';
import type { TypZaznamu } from '@/lib/penize';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { ChevronDown, CloudOff, MapPin, Plus, RefreshCw, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Modul Rozpočet — společné finance na cestách.
 *
 * Osm tabů v pořadí podle četnosti použití, ne podle abecedy: Přehled a zapisování
 * jsou nahoře, Nastavení poslední. Na mobilu se z toho stane pět položek se
 * zvýrazněným Přidat uprostřed a zbytek je pod „Více" — víc než pět položek ve
 * spodní liště znamená terče, které se netrefí palcem.
 *
 * Období je v URL. Kdo pošle odkaz nebo se vrátí zpátky, uvidí totéž co předtím —
 * a proklik z grafu do Transakcí si nese filtr s sebou, místo aby ho zahodil.
 */
export default function RozpocetIndex() {
    const [ciselniky, setCiselniky] = useState<Ciselniky | null>(null);
    const [prehled, setPrehled] = useState<PrehledData | null>(null);
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');
    const [pridava, setPridava] = useState<TypZaznamu | null>(null);
    const [upravovany, setUpravovany] = useState<Pohyb | null>(null);

    // Stav obrazovky žije v URL, ne v paměti komponenty.
    const [stav, setStav] = useState(() => {
        const p = new URLSearchParams(window.location.search);

        return {
            tab: p.get('tab') ?? 'prehled',
            obdobi: p.get('obdobi') ?? 'mesic',
            filtr: Object.fromEntries(
                ['typ', 'mena', 'ucet', 'kategorie', 'platce', 'misto', 'hledat', 'od', 'do', 'cesta',
                    'od_castky', 'do_castky']
                    .map(k => [k, p.get(k) ?? ''])
                    .filter(([, v]) => v !== ''),
            ) as Record<string, string>,
        };
    });

    // Zápis do URL bez nové položky v historii — jinak by tlačítko zpět procházelo
    // každou změnu filtru zvlášť a z galerie by se vracelo po dvaceti krocích.
    useEffect(() => {
        const p = new URLSearchParams();
        p.set('tab', stav.tab);
        if (stav.obdobi !== 'mesic') p.set('obdobi', stav.obdobi);
        Object.entries(stav.filtr).forEach(([k, v]) => v && p.set(k, v));

        window.history.replaceState(null, '', `?${p.toString()}`);
    }, [stav]);

    const nactiCiselniky = useCallback(async () => {
        const { data } = await axios.get<Ciselniky>('/api/v1/rozpocet/ciselniky');
        setCiselniky(data);
    }, []);

    const nactiPrehled = useCallback(async () => {
        const { data } = await axios.get<PrehledData>('/api/v1/rozpocet/prehled', {
            params: { obdobi: stav.obdobi, ...stav.filtr },
        });
        setPrehled(data);
    }, [stav.obdobi, stav.filtr]);

    const nacti = useCallback(async () => {
        try {
            await Promise.all([nactiCiselniky(), nactiPrehled()]);
            setChyba('');
        } catch (problem: any) {
            setChyba(problem?.response?.status === 404
                ? 'Nejprve vytvořte nebo přijměte pozvánku do společného prostoru.'
                : 'Rozpočet se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, [nactiCiselniky, nactiPrehled]);

    useEffect(() => { void nacti(); }, [nacti]);

    /** Po uložení se přepočítá všechno naráz — ne jeden widget teď a druhý za chvíli. */
    const poZmene = useCallback(async () => {
        setPridava(null);
        setUpravovany(null);
        await nacti();
    }, [nacti]);

    const zavriFormular = useCallback(() => {
        setPridava(null);
        setUpravovany(null);
    }, []);

    /**
     * Otevře existující záznam k opravě.
     *
     * Načítá se přes seznam transakcí, ne z toho, co je zrovna na obrazovce: „poslední
     * záznam" v pruhu Dnes nese jen částku a kategorii, ale formulář potřebuje účty,
     * poplatek i rozdělení.
     */
    const otevriOpravu = useCallback(async (uuid: string) => {
        try {
            const { data } = await axios.get('/api/v1/rozpocet/transakce', { params: { obdobi: 'dnes' } });
            const zaznam = (data.transactions as Pohyb[]).find(t => t.uuid === uuid);

            if (zaznam) {
                setUpravovany(zaznam);
            } else {
                hlaska('Záznam se nepodařilo otevřít — najdete ho v Transakcích.', 'chyba');
            }
        } catch {
            hlaska('Záznam se nepodařilo načíst.', 'chyba');
        }
    }, []);

    const naTab = (tab: string) => setStav(s => ({ ...s, tab }));

    const naTransakce = (filtr: Record<string, string>) =>
        setStav(s => ({
            ...s,
            tab: 'transakce',
            obdobi: filtr.obdobi ?? s.obdobi,
            // Proklik z grafu nese svůj filtr; ostatní se zahodí, aby nevznikla
            // kombinace, kterou nikdo nezadal a která nic nenajde.
            filtr: Object.fromEntries(Object.entries(filtr).filter(([k]) => k !== 'obdobi')) as Record<string, string>,
        }));

    const cesta = ciselniky?.active_trip ?? null;

    /*
     * Zápisy, které čekají na spojení.
     *
     * Zkouší se odeslat, když se prohlížeč vrátí online a při otevření stránky.
     * Opakovat je v nekonečné smyčce by na špatném připojení znamenalo požadavek
     * každou vteřinu a vybitý telefon — od toho je `online`, který přijde právě
     * jednou, když se dá něco dělat.
     */
    const [cekajici, setCekajici] = useState<CekajiciZapis[]>([]);

    useEffect(() => sledujFrontu(setCekajici), []);

    const odeslatCekajici = useCallback(async () => {
        const hotovo = await odeslatFrontu();

        if (hotovo > 0) {
            /*
             * Dvojtečka, ne vedlejší věta.
             *
             * „Odesláno 1 záznam, které čekaly" míchá jednotné číslo s množným
             * přísudkem — a správně to nejde napsat jednou šablonou, protože čeština
             * potřebuje jiný tvar pro jedna, dva až čtyři a pět a víc. Dvojtečka tu
             * shodu přeruší a věta sedí u každého počtu.
             */
            hlaska(`Odesláno z fronty: ${zaznamy(hotovo)}.`, 'uspech');
            await nacti();
        }
    }, [nacti]);

    useEffect(() => {
        void odeslatCekajici();

        window.addEventListener('online', odeslatCekajici);

        return () => window.removeEventListener('online', odeslatCekajici);
    }, [odeslatCekajici]);

    return (
        <AppLayout>
            <Head title="Rozpočet" />

            <div role="main" className="mx-auto max-w-[1600px] px-4 pb-28 pt-4 sm:px-6 lg:pb-8">
                <Hlavicka
                    cesta={cesta}
                    obdobi={stav.obdobi}
                    popisObdobi={prehled?.filter.label ?? ''}
                    onObdobi={o => setStav(s => ({ ...s, obdobi: o }))}
                    onPridat={() => setPridava('expense')}/>

                {/* Taby na širokém. Na mobilu je nahradí spodní lišta. */}
                <nav className="mt-4 hidden gap-1 overflow-x-auto lg:flex" role="tablist" aria-label="Části rozpočtu">
                    {TABY.map(t => (
                        <button key={t.id} type="button" role="tab" aria-selected={stav.tab === t.id}
                            onClick={() => naTab(t.id)}
                            className={`inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-xl px-3 text-sm transition-colors ${
                                stav.tab === t.id
                                    ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]'
                                    : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                            }`}>
                            <t.ikona size={15}/> {t.label}
                        </button>
                    ))}
                </nav>

                {cekajici.length > 0 && (
                    <div className="mt-3 flex flex-wrap items-center gap-2 rounded-2xl border border-amber-500/40 bg-[var(--color-surface-muted)] p-3">
                        <CloudOff size={16} className="shrink-0 text-amber-400"/>
                        <p className="min-w-0 flex-1 text-xs leading-relaxed text-[var(--color-text-primary)]">
                            {/* Zase dvojtečka místo přísudku: „3 záznamy čeká" nesedí a
                                „čekají" zase nesedí u jednoho. */}
                            Čeká na spojení: {zaznamy(cekajici.length)} — {cekajici.map(z => z.popis).join(', ')}.
                            <span className="block text-[var(--color-text-secondary)]">
                                Odešlou se samy, jakmile bude signál. Dvakrát se nezapíšou.
                            </span>
                        </p>
                        <button type="button" onClick={() => void odeslatCekajici()}
                            className="min-h-11 shrink-0 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]">
                            Zkusit teď
                        </button>
                    </div>
                )}

                <div className="mt-4">
                    {nacita && <Kostra/>}

                    {! nacita && chyba && (
                        <div className="rounded-2xl border border-red-500/40 bg-[var(--color-surface-muted)] p-4">
                            <p className="text-sm text-[var(--color-text-primary)]">{chyba}</p>
                            <button type="button" onClick={() => { setNacita(true); void nacti(); }}
                                className="mt-2 inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-primary)]">
                                <RefreshCw size={14}/> Zkusit znovu
                            </button>
                        </div>
                    )}

                    {! nacita && ! chyba && prehled && ciselniky && (
                        <>
                            {stav.tab === 'prehled' && (
                                <Prehled data={prehled} naTab={naTab} naTransakce={naTransakce}
                                    onPridat={() => setPridava('expense')}
                                    onUpravitPosledni={uuid => void otevriOpravu(uuid)}/>
                            )}

                            {stav.tab === 'transakce' && (
                                <Transakce obdobi={stav.obdobi} filtr={stav.filtr}
                                    ciselniky={ciselniky}
                                    onFiltr={f => setStav(s => ({ ...s, filtr: f }))}
                                    onZmena={() => void nacti()}/>
                            )}

                            {stav.tab === 'cesty' && (
                                <Cesty ciselniky={ciselniky} onZmena={() => void nacti()} onTransakce={naTransakce}/>
                            )}

                            {stav.tab === 'ucty' && (
                                <Ucty ciselniky={ciselniky} obdobi={stav.obdobi}
                                    onZmena={() => void nacti()}
                                    onPrevod={() => setPridava('transfer')}/>
                            )}

                            {stav.tab === 'smeny' && (
                                <Smeny obdobi={stav.obdobi} onPridat={() => setPridava('exchange')}/>
                            )}

                            {stav.tab === 'rozpocty' && (
                                <Rozpocty ciselniky={ciselniky} onZmena={() => void nacti()}/>
                            )}

                            {stav.tab === 'statistiky' && (
                                <Statistiky obdobi={stav.obdobi} onTransakce={naTransakce}/>
                            )}

                            {stav.tab === 'nastaveni' && (
                                <Nastaveni ciselniky={ciselniky} onZmena={() => void nacti()}/>
                            )}

                            {! ['prehled', 'transakce', 'cesty', 'ucty', 'smeny', 'rozpocty', 'statistiky', 'nastaveni'].includes(stav.tab) && (
                                <Pripravuje tab={stav.tab} onZpet={() => naTab('prehled')}/>
                            )}
                        </>
                    )}
                </div>
            </div>

            <SpodniNavigace aktivni={stav.tab} onTab={naTab} onPridat={() => setPridava('expense')}/>

            {(pridava || upravovany) && ciselniky && (
                <Sesle onZavrit={zavriFormular}>
                    {/* `key` je tu schválně: přepnutí mezi opravou a novým zápisem musí
                        formulář postavit znovu, jinak by si nechal hodnoty předchozího
                        záznamu a uložil je do jiného. */}
                    <PridatZaznam key={upravovany?.uuid ?? 'novy'}
                        ciselniky={ciselniky}
                        vychoziTyp={pridava ?? 'expense'}
                        upravovany={upravovany}
                        onHotovo={() => void poZmene()}
                        onZmena={() => void nacti()}
                        onZavrit={zavriFormular}/>
                </Sesle>
            )}
        </AppLayout>
    );
}

/** Kolik dní zbývá do začátku cesty. Nula nebo míň znamená, že už běží. */
function zacinaZa(zacatek: string): number {
    const den = new Date(`${zacatek}T12:00:00`);
    const dnes = new Date();
    dnes.setHours(12, 0, 0, 0);

    return Math.round((den.getTime() - dnes.getTime()) / 86400000);
}

/** Hlavička: kde jsme, jaké období a hlavní akce. */
function Hlavicka({ cesta, obdobi, popisObdobi, onObdobi, onPridat }: {
    cesta: Ciselniky['active_trip'];
    obdobi: string; popisObdobi: string;
    onObdobi: (o: string) => void;
    onPridat: () => void;
}) {
    const moznosti = [
        { id: 'dnes', label: 'Dnes' },
        { id: 'tyden', label: 'Tento týden' },
        { id: 'mesic', label: 'Tento měsíc' },
        { id: 'minuly-mesic', label: 'Minulý měsíc' },
        ...(cesta ? [{ id: 'cesta', label: cesta.name }] : []),
    ];

    return (
        <header>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-xl font-semibold text-[var(--color-text-primary)]">Náš rozpočet</h1>
                    <p className="mt-0.5 text-sm text-[var(--color-text-secondary)]">
                        {popisObdobi}
                        {cesta && (
                            <span className="ml-1.5 inline-flex items-center gap-1 rounded-full border border-[var(--color-border)] px-2 py-0.5 text-[11px]">
                                {/* U cesty, která teprve začne, se nepíše „do konce".
                                    Kalendářně je to pravda, ale vedle denní částky
                                    počítané z délky pobytu by to byla dvě různá čísla —
                                    a hlavně před odjezdem nikoho nezajímá konec, ale
                                    začátek. */}
                                <MapPin size={11}/> {cesta.name}
                                {cesta.starts_on && zacinaZa(cesta.starts_on) > 0
                                    ? ` · začíná za ${dny(zacinaZa(cesta.starts_on))}`
                                    : cesta.days_left !== null && ` · ${dny(cesta.days_left)} do konce`}
                            </span>
                        )}
                    </p>
                </div>

                {/* Na mobilu je Přidat ve spodní liště, tady by jen bral místo. */}
                <button type="button" onClick={onPridat}
                    className="hidden min-h-10 shrink-0 items-center gap-1.5 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] lg:inline-flex">
                    <Plus size={16}/> Přidat výdaj
                </button>
            </div>

            <div className="-mx-1 mt-3 flex gap-1.5 overflow-x-auto px-1 pb-1">
                {moznosti.map(m => (
                    <button key={m.id} type="button" onClick={() => onObdobi(m.id)}
                        aria-pressed={obdobi === m.id}
                        className={`min-h-11 shrink-0 rounded-full border px-3.5 text-xs transition-colors ${
                            obdobi === m.id
                                ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                        }`}>
                        {m.label}
                    </button>
                ))}
            </div>
        </header>
    );
}

/**
 * Formulář: na mobilu přes celou výšku, na širokém dialog uprostřed.
 *
 * `dvh` místo `vh`, protože na telefonu se s vyjetou klávesnicí viewport zmenší —
 * s `vh` by tlačítko Uložit skončilo pod klávesnicí a nešlo by na něj dosáhnout.
 */
function Sesle({ children, onZavrit }: { children: React.ReactNode; onZavrit: () => void }) {
    useEffect(() => {
        const zavriEscape = (e: KeyboardEvent) => e.key === 'Escape' && onZavrit();
        window.addEventListener('keydown', zavriEscape);

        return () => window.removeEventListener('keydown', zavriEscape);
    }, [onZavrit]);

    /*
     * Výška podle skutečně viditelné plochy, ne podle `dvh`.
     *
     * Tohle je ta chyba, kvůli které nešlo uložit směnu: `dvh` se při vyjeté
     * klávesnici nezmenší. Formulář si dál myslí, že má celou obrazovku, patička
     * s tlačítkem sedí na jeho spodním okraji — a ten je tři sta bodů pod klávesnicí.
     * Kdo vyplňoval poslední pole, se k „Zapsat směnu" nedostal.
     *
     * `visualViewport` hlásí, co je doopravdy vidět. Dialog se tím zmenší a patička
     * vyjede nad klávesnici. Prohlížeče, které API nemají, spadnou zpátky na `dvh` —
     * tam se nic nezhorší.
     */
    const [vyska, setVyska] = useState<number | null>(null);

    useEffect(() => {
        const vv = window.visualViewport;

        if (! vv) return;

        /*
         * Zasahuje se jen tehdy, když klávesnice opravdu ubrala místo.
         *
         * Bez téhle podmínky by inline výška přebila `sm:h-auto` i na monitoru a dialog
         * by byl vždycky přes celou obrazovku, i kdyby v něm byla tři pole. Sto bodů je
         * práh, pod který se běžné lišty prohlížeče nedostanou, ale klávesnice ano.
         */
        const zmer = () => setVyska(window.innerHeight - vv.height > 100 ? vv.height : null);

        zmer();
        vv.addEventListener('resize', zmer);
        vv.addEventListener('scroll', zmer);

        return () => {
            vv.removeEventListener('resize', zmer);
            vv.removeEventListener('scroll', zmer);
        };
    }, []);

    return (
        <div className="fixed inset-0 z-[950] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-label="Nový záznam">
            <button type="button" aria-label="Zavřít" onClick={onZavrit}
                className="absolute inset-0 bg-black/50"/>
            <div
                className="relative flex h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] sm:h-auto sm:max-h-[88dvh] sm:max-w-lg sm:rounded-2xl"
                style={vyska ? { height: Math.min(vyska * 0.96, vyska - 8), maxHeight: vyska - 8 } : undefined}>
                {children}
            </div>
        </div>
    );
}

function Kostra() {
    return (
        <div className="space-y-3" aria-busy="true" aria-label="Načítám">
            <div className="grid grid-cols-2 gap-2.5 xl:grid-cols-4">
                {[0, 1, 2, 3].map(i => (
                    <div key={i} className="h-[5.5rem] animate-pulse rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface-muted)]"/>
                ))}
            </div>
            <div className="grid gap-3 lg:grid-cols-3">
                <div className="h-56 animate-pulse rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] lg:col-span-2"/>
                <div className="h-56 animate-pulse rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface-muted)]"/>
            </div>
        </div>
    );
}

/**
 * Tab, který ještě není hotový.
 *
 * Radši prázdná obrazovka, která to řekne, než tlačítka, co nic nedělají. Zadání
 * to vyžaduje výslovně: žádná akce nesmí být jen na oko.
 */
function Pripravuje({ tab, onZpet }: { tab: string; onZpet: () => void }) {
    const nazev = TABY.find(t => t.id === tab)?.label ?? tab;

    return (
        <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center">
            <p className="text-sm font-medium text-[var(--color-text-primary)]">{nazev} se teprve staví</p>
            <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[var(--color-text-secondary)]">
                Data pro tuhle část už systém počítá — chybí jí obrazovka. Do té doby
                najdete všechno zapsané v Transakcích.
            </p>
            <button type="button" onClick={onZpet}
                className="mt-3 inline-flex min-h-10 items-center rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-primary)]">
                Zpět na přehled
            </button>
        </div>
    );
}
