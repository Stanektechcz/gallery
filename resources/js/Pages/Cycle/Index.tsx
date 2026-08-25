import Panel, { Stat } from '@/Components/Panel';
import AppLayout from '@/Layouts/AppLayout';
import { dny } from '@/lib/cestina';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Bell, CalendarDays, ChevronDown, Droplet, Eye, Heart, History, LineChart, Lock, Pencil, Repeat, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Menstruační kalendář.
 *
 * Zdravotní údaj, ne další sdílená složka. Partner uvidí jedině to, co si majitelka
 * zapne — a odfiltruje to server, ne tahle obrazovka: schovávat na frontendu údaj, který
 * už přišel po drátě, není soukromí, jen jeho zdání.
 *
 * Předpověď vždycky říká, z čeho vychází. „Podle dvou cyklů" znamená něco jiného než
 * „podle deseti", a kdo si podle toho plánuje dovolenou, ten rozdíl potřebuje vidět.
 */

type Flow = 'none' | 'spotting' | 'light' | 'medium' | 'heavy';

interface Day {
    uuid: string; day: string; flow: Flow;
    symptoms: string[]; moods: string[];
    pain: number | null; temperature: number | null; note: string | null;
    is_cycle_start: boolean;
    is_predicted: boolean;
}

interface Prediction {
    next_period_on: string; days_until: number; period_ends_on: string;
    ovulation_on: string; fertile_from: string; fertile_to: string;
    based_on_cycles: number; spread_days: number | null;
    confidence: 'low' | 'medium' | 'high';
}

interface Overview {
    settings: {
        share_level: 'none' | 'dates' | 'full';
        average_cycle_days: number; average_period_days: number; based_on_cycles: number;
        remind_upcoming: boolean; remind_days_before: number; track_symptoms: boolean;
    };
    days: Day[];
    cycles: Array<{ started_on: string; ended_on: string | null; length: number | null; period_days: number }>;
    prediction: Prediction | null;
    today: { cycle_day: number; phase: string } | null;
    forecast?: Array<{ day: string; phase: string; confidence: string; cycle_day?: number; fertility?: number; is_recorded?: boolean; flow?: string | null }>;
}

interface PartnerView {
    owner: { id: number; name: string };
    share_level: string;
    prediction: Prediction | null;
    today: { cycle_day: number; phase: string } | null;
    days?: Day[];
}

const FLOW_LABEL: Record<Flow, string> = {
    none: 'Nic', spotting: 'Špinění', light: 'Slabá', medium: 'Střední', heavy: 'Silná',
};

const FLOW_COLOR: Record<Flow, string> = {
    none: 'bg-[var(--color-bg-primary)]',
    spotting: 'bg-rose-400/30',
    light: 'bg-rose-400/55',
    medium: 'bg-rose-500/80',
    heavy: 'bg-rose-600',
};

const PHASE_LABEL: Record<string, string> = {
    menstruation: 'menstruace', follicular: 'folikulární fáze', fertile: 'plodné dny',
    // Ovulace tu dřív chyběla, takže se v den ovulace ukazovalo anglické „ovulation“
    // z klíče. Nebylo to vidět dřív, protože „dnes je" tuhle fázi vůbec neznalo.
    ovulation: 'ovulace',
    luteal: 'luteální fáze', pms: 'dny před menstruací',
};

/**
 * Barvy předpovědi — pastelová výplň a tečkovaný okraj.
 *
 * Každá fáze má svou barvu, aby z měsíce šlo číst, co kdy čekat, bez počítání. Dvě
 * pravidla, na kterých to celé stojí:
 *
 * Pastel znamená odhad, sytá barva zapsanou skutečnost. Kdyby předpověď vypadala stejně
 * jako záznam, nikdo by je od sebe nerozeznal — a plánovat dovolenou podle něčeho, co
 * vypadá jako fakt a je to dohad, je horší než nemít barvu žádnou.
 *
 * Tečkovaný okraj to říká podruhé, pro každého, komu barvy splývají.
 */
const PHASE_HINT: Record<string, string> = {
    menstruation: 'bg-rose-500/15 border-dashed border-rose-400/70',
    fertile: 'bg-emerald-500/15 border-dashed border-emerald-400/70',
    ovulation: 'bg-emerald-500/30 border-dashed border-emerald-400',
    pms: 'bg-amber-500/15 border-dashed border-amber-400/70',
    follicular: 'bg-sky-500/10 border-dashed border-sky-400/40',
    luteal: 'bg-violet-500/10 border-dashed border-violet-400/40',
};

/**
 * Zkratka do políčka, aby se den dal přečíst bez legendy.
 *
 * Zkráceno tvrdě: v mřížce sedmi sloupců má buňka na telefonu kolem pětačtyřiceti bodů
 * a delší slovo se zalomí nebo přeteče. Folikulární a luteální fáze zkratku nedostávají
 * — jsou to „nic zvláštního se neděje" dny a popisek u každého z nich by z kalendáře
 * udělal text, ve kterém to důležité zanikne.
 */
const PHASE_SHORT: Record<string, string> = {
    menstruation: 'men',
    fertile: 'plod',
    ovulation: 'ovul',
    pms: 'PMS',
};

/**
 * Tytéž fáze v grafu plodných dní.
 *
 * Odstíny jsou stejné jako v kalendáři — růžová menstruace, modrá folikulární fáze,
 * zelené plodné okno, jantarové PMS, fialová luteální fáze — aby se dva pohledy na týž
 * měsíc daly číst jedním klíčem. Sytost je vyšší: buňka kalendáře je čtverec o straně
 * pětačtyřiceti bodů a bledá výplň v ní vynikne, kdežto proužek v grafu je pár bodů
 * široký a při patnáctiprocentní průhlednosti by na pozadí zmizel úplně.
 */
const PHASE_BAND: Record<string, string> = {
    menstruation: 'bg-rose-500/70',
    follicular: 'bg-sky-400/50',
    fertile: 'bg-emerald-500/60',
    ovulation: 'bg-emerald-400',
    pms: 'bg-amber-500/60',
    luteal: 'bg-violet-400/45',
};

const SYMPTOMS = ['křeče', 'bolest hlavy', 'nadýmání', 'citlivá prsa', 'únava', 'akné', 'nevolnost', 'bolest zad', 'chutě'];
const MOODS = ['v pohodě', 'podrážděná', 'úzkost', 'smutek', 'energie', 'plačtivost'];

const CONFIDENCE: Record<string, string> = {
    low: 'orientační — zatím je z čeho počítat málo',
    medium: 'přibližná',
    high: 'spolehlivá',
};

const den = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long' });

/** Krátký tvar pro osu grafu — „25. srp". Celé „25. srpna" se do třetiny řádku nevejde. */
const denKratce = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' });


/** Dny v mřížce po měsících — kalendář se čte líp než seznam. */
function monthGrid(anchor: Date): Array<string | null> {
    const first = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
    const days = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0).getDate();
    // Pondělí první, jak je u nás zvykem.
    const lead = (first.getDay() + 6) % 7;

    return [
        ...Array(lead).fill(null),
        ...Array.from({ length: days }, (_, i) =>
            `${anchor.getFullYear()}-${String(anchor.getMonth() + 1).padStart(2, '0')}-${String(i + 1).padStart(2, '0')}`),
    ];
}

export default function CycleIndex() {
    const [data, setData] = useState<Overview | null>(null);
    const [partners, setPartners] = useState<PartnerView[]>([]);
    const [month, setMonth] = useState(() => new Date());
    const [editing, setEditing] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    // Počitadlo změn. Statistika se načítá vlastním voláním, protože se dívá dozadu přes
    // celou historii — a bez tohohle by po zápisu dne nebo po doplnění historie zůstala
    // viset na starém výsledku, takže souhrn nahoře hlásil pět cyklů a panel pod ním
    // tvrdil, že není z čeho počítat.
    const [verze, setVerze] = useState(0);

    /** Přijme nový přehled ze serveru a řekne zbytku stránky, že se něco změnilo. */
    const prijmout = useCallback((dalsi: Overview) => {
        setData(dalsi);
        setVerze(v => v + 1);
    }, []);

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/cyklus');
            setData(response.data.mine);
            setPartners(response.data.partners ?? []);
            setError('');
        } catch (problem: any) {
            setError(problem?.response?.status === 404
                ? 'Nejprve vytvořte nebo přijměte pozvánku do společného prostoru.'
                : 'Kalendář se nepodařilo načíst.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const byDay = useMemo(() => {
        const map = new Map<string, Day>();
        data?.days.forEach(d => map.set(d.day, d));

        return map;
    }, [data]);

    /** Předpověď po dnech, aby ji kalendář nemusel hledat v poli u každé buňky. */
    const forecastByDay = useMemo(() => {
        const map = new Map<string, string>();
        data?.forecast?.forEach(f => map.set(f.day, f.phase));

        return map;
    }, [data]);

    const grid = useMemo(() => monthGrid(month), [month]);

    return (
        <AppLayout>
            <Head title="Cyklus" />

            {/* Omezená šířka: kalendář roztažený přes celý širokoúhlý monitor má buňky
                velké jako dlaň a stejně se v něm hůř hledá než v tom menším. */}
            <div className="mx-auto max-w-[1500px] p-4 sm:p-6">
                <header className="mb-5">
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-[var(--color-text-primary)]">
                        <Droplet size={20} className="text-rose-400"/> Cyklus
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm text-[var(--color-text-secondary)]">
                        Záznamy jsou vaše. Partner uvidí jen to, co mu tady sami zpřístupníte — ve výchozím
                        stavu nic.
                    </p>
                </header>

                {loading && <p className="text-sm text-[var(--color-text-secondary)]">Načítám…</p>}
                {error && <p className="text-sm text-red-400">{error}</p>}

                {data && (
                    <div className="space-y-4">
                        <Summary data={data}/>

                        {/* Kalendář a editor dne vedle sebe. Dokud byl editor pod
                            kalendářem, ťuknutí na den odsunulo mřížku nahoru a člověk
                            zapisoval, aniž viděl, do kterého dne. */}
                        {/* items-start: kalendář si drží svou výšku. Bez toho by se natáhl
                            na výšku editoru a pod mřížkou dnů by zůstalo prázdné pole. */}
                        <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                            <Calendar
                                grid={grid} month={month} byDay={byDay}
                                forecast={forecastByDay}
                                onMonth={setMonth}
                                onPick={setEditing}
                                active={editing}
                            />

                            <div className="space-y-4">
                                {editing && (
                                    <DayEditor
                                        key={editing}
                                        day={editing}
                                        existing={byDay.get(editing)}
                                        trackSymptoms={data.settings.track_symptoms}
                                        onClose={() => setEditing(null)}
                                        onSaved={next => { prijmout(next); setEditing(null); }}
                                    />
                                )}

                                {data.forecast && data.forecast.length > 0 && <Fertility forecast={data.forecast}/>}
                            </div>
                        </div>

                        {/* Historie vlevo, nastavení vpravo. Rozbor je dlouhý, sdílení
                            krátké — pod sebou by nastavení skončilo mimo obrazovku. */}
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">
                            <Statistics reloadKey={verze}/>

                            <div className="space-y-4">
                                <Sharing settings={data.settings} onSaved={prijmout}/>
                                <Preferences settings={data.settings} onSaved={prijmout}/>
                                {/* Doplnění historie zmizí, jakmile je z čeho počítat —
                                    nabízet ho někomu, kdo zapisuje rok, nemá smysl. */}
                                {data.settings.based_on_cycles < 2 && (
                                    <Backfill settings={data.settings} onSaved={prijmout}/>
                                )}
                                {partners.length > 0 && <Partners partners={partners}/>}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function Summary({ data }: { data: Overview }) {
    const { prediction, today, settings } = data;

    return (
        <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Stat label="Dnes" icon={CalendarDays} tone="accent"
                value={today ? `${today.cycle_day}. den` : '—'}
                hint={today ? PHASE_LABEL[today.phase] ?? today.phase : 'zatím bez záznamů'}/>
            <Stat label="Příští menstruace" icon={Droplet}
                value={prediction ? den(prediction.next_period_on) : '—'}
                hint={prediction
                    ? <>
                        {prediction.days_until >= 0 ? `za ${dny(prediction.days_until)}` : 'měla už začít'}
                        {' · '}
                        <span title="Spolehlivost roste s počtem zapsaných cyklů.">{CONFIDENCE[prediction.confidence]}</span>
                    </>
                    : 'zapište první den'}/>
            <Stat label="Plodné dny" icon={Sparkles}
                value={prediction ? `${den(prediction.fertile_from)} – ${den(prediction.fertile_to)}` : '—'}
                hint={prediction ? `ovulace ${den(prediction.ovulation_on)}` : ''}/>
            <Stat label="Průměrný cyklus" icon={Repeat} value={`${settings.average_cycle_days} dní`}
                hint={settings.based_on_cycles > 0
                    ? <>
                        z {settings.based_on_cycles} {settings.based_on_cycles === 1 ? 'cyklu' : settings.based_on_cycles <= 4 ? 'cyklů' : 'cyklů'}
                        {prediction?.spread_days !== null && (prediction?.spread_days ?? 0) > 4 && ` · kolísá o ${prediction!.spread_days} dní`}
                    </>
                    : 'výchozí odhad, dokud nejsou data'}/>
        </section>
    );
}

function Calendar({ grid, month, byDay, forecast, onMonth, onPick, active }: {
    grid: Array<string | null>; month: Date; byDay: Map<string, Day>;
    forecast: Map<string, string>;
    onMonth: (d: Date) => void; onPick: (day: string) => void;
    /** Právě otevřený den — v mřížce dostane rámeček, ať je vidět, co se vpravo edituje. */
    active: string | null;
}) {
    const dnes = new Date().toISOString().slice(0, 10);
    // Vysvětlivky jsou zavřené. Buňky nesou popisek přímo v sobě, takže legenda je
    // záchranná síť, ne to, co se čte pokaždé — a otevřená zabírá třetinu panelu.
    const [legenda, setLegenda] = useState(false);

    return (
        <Panel icon={CalendarDays} title={month.toLocaleDateString('cs-CZ', { month: 'long', year: 'numeric' })}
            actions={<>
                <button type="button" title="Předchozí měsíc"
                    onClick={() => onMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))}
                    className="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-[var(--color-border)] px-2.5 py-1 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">←</button>
                <button type="button"
                    onClick={() => onMonth(new Date())}
                    className="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-[var(--color-border)] px-2.5 py-1 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">Dnes</button>
                <button type="button" title="Následující měsíc"
                    onClick={() => onMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))}
                    className="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-[var(--color-border)] px-2.5 py-1 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">→</button>
            </>}>

            <div className="grid grid-cols-7 gap-1 text-center">
                {['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'].map(d => (
                    <span key={d} className="pb-1 text-[10px] text-[var(--color-text-secondary)]">{d}</span>
                ))}

                {grid.map((day, index) => {
                    if (! day) return <span key={`x${index}`}/>;

                    const zaznam = byDay.get(day);
                    const cislo = Number(day.slice(-2));

                    return (
                        <button key={day} type="button" onClick={() => onPick(day)}
                            title={zaznam ? FLOW_LABEL[zaznam.flow] : 'Zapsat'}
                            /* Silnější okraj má každý den, i prázdný: mřížka z pastelových
                               ploch bez hranic splývá v jednu barevnou kaši a přestane se
                               dát počítat, kolikátého co je. */
                            className={`relative aspect-square rounded-lg border-2 text-xs font-medium transition-colors ${
                                zaznam?.flow && zaznam.flow !== 'none'
                                    // Předvyplněný den je bledší a tečkovaný: je to odhad,
                                    // dokud se ho někdo nedotkne.
                                    ? `${FLOW_COLOR[zaznam.flow]} text-white ${zaznam.is_predicted ? 'border-dashed border-white/70 opacity-60' : 'border-rose-300/60'}`
                                    : `text-[var(--color-text-primary)] hover:brightness-125 ${
                                        PHASE_HINT[forecast.get(day) ?? '']
                                            ?? 'bg-[var(--color-bg-primary)] border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                    }`
                            } ${day === dnes ? 'ring-2 ring-offset-2 ring-offset-[var(--color-bg-card)] ring-[var(--color-accent)]' : ''
                            } ${day === active ? 'outline outline-2 outline-offset-1 outline-[var(--color-text-primary)]' : ''}`}>
                            {/* Číslo nahoře, druh dne pod ním. Bez toho popisku se barva
                                musí pořád překládat přes legendu, což je práce navíc
                                pokaždé, když se člověk na kalendář podívá. */}
                            <span className="flex h-full flex-col items-center justify-center leading-none">
                                <span>{cislo}</span>

                                {(() => {
                                    const zkratka = zaznam?.flow && zaznam.flow !== 'none'
                                        ? (zaznam.is_predicted ? 'men?' : 'men')
                                        : PHASE_SHORT[forecast.get(day) ?? ''];

                                    return zkratka
                                        ? <span className="mt-0.5 text-[7px] font-normal opacity-80">{zkratka}</span>
                                        : null;
                                })()}
                            </span>

                            {/* Den ovulace dostane tečku navíc. Barva sama o sobě říká
                                „plodné dny", ale ten jeden den uvnitř okna je jiná
                                informace a stojí za vlastní značku. */}
                            {! zaznam && forecast.get(day) === 'ovulation' && (
                                <span className="absolute bottom-0.5 left-1/2 h-1.5 w-1.5 -translate-x-1/2 rounded-full bg-emerald-300"/>
                            )}
                            {zaznam && (zaznam.symptoms.length > 0 || zaznam.note) && (
                                <span className="absolute right-1 top-1 h-1.5 w-1.5 rounded-full bg-white/90"/>
                            )}
                        </button>
                    );
                })}
            </div>

            {/* Legenda ve dvou řadách, protože jde o dvě různé věci: co je zapsané a co
                se teprve čeká. Smíchat je do jednoho řádku znamená, že rozdíl mezi
                záznamem a odhadem zapadne — a ten je tu ze všeho nejdůležitější. */}
            <div className="mt-4 border-t border-[var(--color-border)] pt-3">
                <button type="button" onClick={() => setLegenda(v => ! v)}
                    className="panel-link flex items-center gap-1.5 text-[11px] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <ChevronDown size={13} className={`transition-transform ${legenda ? 'rotate-180' : ''}`}/>
                    Vysvětlivky barev
                </button>
            </div>

            <div className={`space-y-2 overflow-hidden transition-all ${legenda ? 'mt-3 max-h-96' : 'max-h-0'}`}>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[10px] text-[var(--color-text-secondary)]">
                    <span className="font-medium text-[var(--color-text-primary)]">Zapsáno</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-rose-300/60 bg-rose-600"/> silná</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-rose-300/60 bg-rose-500/80"/> střední</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-rose-300/60 bg-rose-400/55"/> slabá</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-rose-300/60 bg-rose-400/30"/> špinění</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-dashed border-white/70 bg-rose-500/80 opacity-60"/> předvyplněno — ťuknutím potvrdíte</span>
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[10px] text-[var(--color-text-secondary)]">
                    <span className="font-medium text-[var(--color-text-primary)]">Čeká se</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-dashed border-rose-400/70 bg-rose-500/15"/> menstruace</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-dashed border-emerald-400/70 bg-emerald-500/15"/> plodné dny</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-dashed border-emerald-400 bg-emerald-500/30"/> ovulace</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-dashed border-amber-400/70 bg-amber-500/15"/> před menstruací</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-dashed border-sky-400/40 bg-sky-500/10"/> folikulární</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded border-2 border-dashed border-violet-400/40 bg-violet-500/10"/> luteální</span>
                </div>
            </div>
        </Panel>
    );
}

function DayEditor({ day, existing, trackSymptoms, onClose, onSaved }: {
    day: string; existing?: Day; trackSymptoms: boolean;
    onClose: () => void; onSaved: (data: Overview) => void;
}) {
    const [flow, setFlow] = useState<Flow>(existing?.flow ?? 'none');
    const [symptoms, setSymptoms] = useState<string[]>(existing?.symptoms ?? []);
    const [moods, setMoods] = useState<string[]>(existing?.moods ?? []);
    const [pain, setPain] = useState(existing?.pain ?? 0);
    const [note, setNote] = useState(existing?.note ?? '');
    const [start, setStart] = useState(existing?.is_cycle_start ?? false);
    const [busy, setBusy] = useState(false);

    const toggle = (list: string[], set: (v: string[]) => void, value: string) =>
        set(list.includes(value) ? list.filter(v => v !== value) : [...list, value]);

    const save = async () => {
        setBusy(true);
        try {
            const response = await axios.post('/api/v1/cyklus/den', {
                day, flow, symptoms, moods, pain: pain || null, note: note || null, is_cycle_start: start,
            });
            onSaved(response.data);
        } finally { setBusy(false); }
    };

    const remove = async () => {
        setBusy(true);
        try {
            onSaved((await axios.delete(`/api/v1/cyklus/den/${day}`)).data);
        } finally { setBusy(false); }
    };

    return (
        <Panel icon={Pencil} title={den(day)} tone="accent"
            description={existing?.is_predicted ? 'Předvyplněný odhad — uložením ho potvrdíte jako skutečný záznam.' : undefined}
            actions={
                <button type="button" onClick={onClose} title="Zavřít"
                    className="rounded-lg p-1 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <X size={15}/>
                </button>
            }>
            <label className="mb-1.5 block text-xs text-[var(--color-text-secondary)]">Krvácení</label>
            <div className="flex flex-wrap gap-1.5">
                {(Object.keys(FLOW_LABEL) as Flow[]).map(f => (
                    <button key={f} type="button" onClick={() => setFlow(f)}
                        className={`rounded-full px-3 py-1.5 text-xs transition-colors ${
                            flow === f ? 'bg-rose-500 text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)]'
                        }`}>
                        {FLOW_LABEL[f]}
                    </button>
                ))}
            </div>

            {trackSymptoms && (
                <>
                    <label className="mb-1.5 mt-4 block text-xs text-[var(--color-text-secondary)]">Příznaky</label>
                    <div className="flex flex-wrap gap-1.5">
                        {SYMPTOMS.map(s => (
                            <button key={s} type="button" onClick={() => toggle(symptoms, setSymptoms, s)}
                                className={`rounded-full px-2.5 py-1 text-[11px] transition-colors ${
                                    symptoms.includes(s) ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                }`}>{s}</button>
                        ))}
                    </div>

                    <label className="mb-1.5 mt-4 block text-xs text-[var(--color-text-secondary)]">Nálada</label>
                    <div className="flex flex-wrap gap-1.5">
                        {MOODS.map(m => (
                            <button key={m} type="button" onClick={() => toggle(moods, setMoods, m)}
                                className={`rounded-full px-2.5 py-1 text-[11px] transition-colors ${
                                    moods.includes(m) ? 'bg-violet-500 text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                }`}>{m}</button>
                        ))}
                    </div>

                    <label className="mb-1.5 mt-4 block text-xs text-[var(--color-text-secondary)]">Bolest: {pain}/10</label>
                    <input type="range" min={0} max={10} value={pain} onChange={e => setPain(Number(e.target.value))} className="w-full"/>
                </>
            )}

            <label className="mb-1.5 mt-4 block text-xs text-[var(--color-text-secondary)]">Poznámka</label>
            <textarea value={note} onChange={e => setNote(e.target.value)} rows={2} maxLength={1000}
                className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2 text-sm text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none"/>

            {/* Ručně označený začátek má přednost před odhadem — někdy to ví jen ona. */}
            <label className="mt-3 flex items-center gap-2 text-[11px] text-[var(--color-text-secondary)]">
                <input type="checkbox" checked={start} onChange={e => setStart(e.target.checked)}/>
                Tímto dnem začal nový cyklus
            </label>

            <div className="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-[var(--color-border)] pt-3">
                {existing && (
                    <button type="button" onClick={() => void remove()} disabled={busy}
                        className="mr-auto rounded-xl border border-[var(--color-border)] px-3 py-2 text-xs text-red-300 disabled:opacity-50">Smazat záznam</button>
                )}
                <button type="button" onClick={() => void save()} disabled={busy}
                    className="rounded-xl bg-rose-500 px-4 py-2 text-sm font-medium text-white hover:bg-rose-400 disabled:opacity-50">
                    {busy ? 'Ukládám…' : 'Uložit'}
                </button>
            </div>
        </Panel>
    );
}

/**
 * Křivka plodnosti na nejbližší cyklus.
 *
 * Nesymetrická schválně, protože plodnost taková je: spermie přežijí až pět dní, takže
 * styk pět dní před ovulací k otěhotnění vést může, kdežto den po ní už skoro ne. Zvon
 * souměrný kolem ovulace, jak ho kreslí spousta aplikací, popisuje oba konce okna špatně.
 *
 * Není to antikoncepce a nikde se to tak netváří.
 */
function Fertility({ forecast }: {
    forecast: Array<{ day: string; phase: string; cycle_day?: number; fertility?: number; is_recorded?: boolean }>;
}) {
    // Jen do konce nejbližšího cyklu: druhý dopředu stojí na tom, že první vyjde přesně.
    const okno = forecast.slice(0, 32);
    const dnes = new Date().toISOString().slice(0, 10);

    const vrchol = okno.find(d => d.phase === 'ovulation');

    /* Nejbližší plodné okno, ne všechny plodné dny ve výhledu. Do dvaatřiceti dní se
       vejde konec jednoho cyklu i začátek plodných dnů toho dalšího, a prosté vyfiltrování
       obojího dohromady dalo „26. srpna – 25. září, 10 dní" — dvě okna slepená přes měsíc,
       který mezi nimi plodný není. Bere se proto první souvislý úsek. */
    const zacatek = okno.findIndex(d => d.phase === 'fertile' || d.phase === 'ovulation');
    let konec = zacatek;
    while (konec + 1 < okno.length && (okno[konec + 1].phase === 'fertile' || okno[konec + 1].phase === 'ovulation')) konec++;

    const plodne = zacatek === -1 ? [] : okno.slice(zacatek, konec + 1);
    const plodneOd = plodne[0]?.day;
    const plodneDo = plodne[plodne.length - 1]?.day;

    return (
        <Panel icon={Sparkles} title="Plodné dny"
            description="Odhad pravděpodobnosti početí podle dne cyklu. Orientační — na plánování ano, na ochranu ne."
            footnote="Barvy fází odpovídají kalendáři výše. Plná výplň je zapsaný den, bledší odhad.">

            {/* Nejdřív odpověď slovy, teprve pak graf. „Kdy je to nejlepší" je otázka,
                se kterou se sem chodí, a odečítat ji z výšky sloupců je práce navíc. */}
            <div className="mb-3 grid grid-cols-2 gap-2">
                <div className="rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-3 py-2">
                    <p className="text-[10px] uppercase tracking-wide text-emerald-200/80">Plodné období</p>
                    <p className="mt-0.5 text-sm font-semibold text-[var(--color-text-primary)]">
                        {plodneOd ? `${den(plodneOd)} – ${den(plodneDo!)}` : 'mimo okno'}
                    </p>
                    {plodne.length > 0 && (
                        <p className="text-[10px] text-[var(--color-text-secondary)]">{plodne.length} dní</p>
                    )}
                </div>
                <div className="rounded-xl border border-emerald-400/50 bg-emerald-500/20 px-3 py-2">
                    <p className="text-[10px] uppercase tracking-wide text-emerald-100/90">Nejplodnější den</p>
                    <p className="mt-0.5 text-sm font-semibold text-[var(--color-text-primary)]">
                        {vrchol ? den(vrchol.day) : '—'}
                    </p>
                    {vrchol && (
                        <p className="text-[10px] text-[var(--color-text-secondary)]">ovulace · {vrchol.fertility ?? 0} %</p>
                    )}
                </div>
            </div>

            {/* Sloupce nesou pravděpodobnost, pruh pod nimi fázi. Dvě různé informace ve
                dvou vrstvách: den s nulovou plodností má nulový sloupec, ale pořád patří
                do nějaké fáze a ta má být vidět. Dokud se fáze kreslila jen barvou
                sloupce, splynulo celé luteální období do šedé čáry u dna. */}
            <div className="flex h-24 items-end gap-px">
                {okno.map(d => (
                    <div key={d.day} className="relative flex-1"
                        title={`${den(d.day)} · ${PHASE_LABEL[d.phase] ?? d.phase} · ${d.fertility ?? 0} %${d.is_recorded ? ' · zapsáno' : ''}`}>
                        {/* Den ovulace dostane tečku nad sloupcem, stejně jako v kalendáři. */}
                        {d.phase === 'ovulation' && (
                            <span className="absolute -top-2 left-1/2 h-1.5 w-1.5 -translate-x-1/2 rounded-full bg-emerald-300"/>
                        )}
                        <div
                            className={`w-full rounded-t transition-all ${PHASE_BAND[d.phase] ?? 'bg-[var(--color-border)]'} ${d.is_recorded ? '' : 'opacity-75'}`}
                            style={{ height: `${d.fertility ?? 0}%` }}
                        />
                    </div>
                ))}
            </div>

            {/* Pás fází: každý den okna jednou barvou, ve stejném pořadí jako sloupce nad
                ním. Tohle je to, co se dá porovnat s kalendářem den po dni. */}
            <div className="mt-1 flex gap-px overflow-hidden rounded" aria-hidden="true">
                {okno.map(d => (
                    <div key={d.day}
                        className={`h-2 flex-1 ${PHASE_BAND[d.phase] ?? 'bg-[var(--color-border)]'} ${d.is_recorded ? '' : 'opacity-75'} ${
                            d.day === dnes ? 'ring-1 ring-[var(--color-accent)]' : ''
                        }`}/>
                ))}
            </div>

            <div className="mt-1.5 flex justify-between text-[10px] text-[var(--color-text-secondary)]">
                <span>{denKratce(okno[0].day)}</span>
                {vrchol && <span className="text-emerald-300">ovulace {denKratce(vrchol.day)}</span>}
                <span>{denKratce(okno[okno.length - 1].day)}</span>
            </div>
        </Panel>
    );
}

/**
 * Jak se cyklus choval v čase.
 *
 * Načítá se zvlášť, protože se dívá dozadu přes celou historii — u někoho, kdo zapisuje
 * třetí rok, je to podstatně dražší dotaz než dnešní stav, a ten nemá na co čekat.
 */
function Statistics({ reloadKey }: { reloadKey: number }) {
    const [stats, setStats] = useState<{
        cycle_lengths: Array<{ started_on: string; length: number; period_days: number }>;
        shortest: number | null; longest: number | null; average: number; spread: number | null;
        tracked_days: number;
        symptom_patterns: Array<{ symptom: string; phase: string; count: number; in_phase: number }>;
        analysis?: Array<{ code: string; level: string; title: string; detail: string }>;
    } | null>(null);

    // reloadKey, ne prázdné pole. Se zápisem dne nebo doplněnou historií se statistika
    // mění taky — a bez tohohle by viset zůstala na výsledku z prvního načtení stránky.
    useEffect(() => {
        void axios.get('/api/v1/cyklus/statistika').then(r => setStats(r.data)).catch(() => setStats(null));
    }, [reloadKey]);

    // Prázdný panel, ne nic. Kdyby se komponenta odstranila úplně, zůstala by v mřížce
    // díra vedle sdílení — a hlavně by nikde nestálo, proč tu zatím nic není.
    if (! stats || stats.cycle_lengths.length === 0) {
        return (
            <Panel icon={LineChart} title="Jak to chodí">
                <p className="rounded-xl border border-dashed border-[var(--color-border)] px-4 py-8 text-center text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    Zatím není z čeho počítat.<br/>
                    Po druhém zapsaném cyklu se tu objeví délky, rozptyl a co se vám opakuje.
                </p>
            </Panel>
        );
    }

    const nejdelsi = Math.max(...stats.cycle_lengths.map(c => c.length), 1);

    return (
        <Panel icon={LineChart} title="Jak to chodí"
            description={`Z ${stats.tracked_days} zapsaných dnů · nejkratší ${stats.shortest} dní, nejdelší ${stats.longest}${
                stats.spread !== null && stats.spread > 4 ? ' — cyklus je spíš nepravidelný' : ''}`}>

            {/* Rozbor nad čísly, ne pod nimi. Zjištění je to, co si člověk odnese; graf
                je doklad, proč to tak je. */}
            {stats.analysis && stats.analysis.length > 0 && (
                <div className="space-y-2">
                    {stats.analysis.map(zjisteni => (
                        <div key={zjisteni.code}
                            className={`rounded-xl border p-3 ${
                                zjisteni.level === 'warn' ? 'border-amber-400/30 bg-amber-500/5'
                                    : zjisteni.level === 'good' ? 'border-emerald-400/25 bg-emerald-500/5'
                                    : 'border-[var(--color-border)] bg-[var(--color-bg-primary)]'
                            }`}>
                            <p className={`text-xs font-medium ${
                                zjisteni.level === 'warn' ? 'text-amber-200'
                                    : zjisteni.level === 'good' ? 'text-emerald-200'
                                    : 'text-[var(--color-text-primary)]'
                            }`}>{zjisteni.title}</p>
                            <p className="mt-0.5 text-[11px] leading-snug text-[var(--color-text-secondary)]">{zjisteni.detail}</p>
                        </div>
                    ))}

                    {/* Řečeno naplno. Aplikace, která z dvanácti řádků v databázi soudí
                        o zdraví, by si dovolovala víc, než na co má. */}
                    <p className="text-[10px] text-[var(--color-text-secondary)] opacity-70">
                        Nic z toho není diagnóza — je to jen to, co plyne z vašich vlastních záznamů.
                    </p>
                </div>
            )}

            <div className="mt-4 space-y-1.5">
                {stats.cycle_lengths.slice(-12).map(cyklus => (
                    <div key={cyklus.started_on} className="flex items-center gap-2">
                        <span className="w-20 shrink-0 text-[10px] text-[var(--color-text-secondary)]">{den(cyklus.started_on)}</span>
                        <div className="h-4 flex-1 overflow-hidden rounded bg-[var(--color-bg-primary)]">
                            {/* Krvácení tmavší částí téhož pruhu — délka cyklu a délka
                                menstruace patří k sobě a dva grafy vedle sebe je nutí
                                porovnávat očima. */}
                            <div className="flex h-full">
                                <div className="h-full bg-rose-500" style={{ width: `${cyklus.period_days / nejdelsi * 100}%` }}/>
                                <div className="h-full bg-rose-400/25" style={{ width: `${(cyklus.length - cyklus.period_days) / nejdelsi * 100}%` }}/>
                            </div>
                        </div>
                        <span className="w-12 shrink-0 text-right text-[10px] text-[var(--color-text-secondary)]">{cyklus.length} dní</span>
                    </div>
                ))}
            </div>

            {stats.symptom_patterns.length > 0 && (
                <>
                    <h3 className="mt-4 text-xs font-medium text-[var(--color-text-primary)]">Co se opakuje</h3>
                    <p className="mt-0.5 text-[10px] text-[var(--color-text-secondary)]">
                        Není to diagnóza, jen „tohle už znáte" — počítá se jen to, co se objevilo aspoň třikrát.
                    </p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {stats.symptom_patterns.slice(0, 8).map(vzorec => (
                            <span key={vzorec.symptom} className="rounded-full border border-[var(--color-border)] px-2.5 py-1 text-[11px] text-[var(--color-text-secondary)]">
                                {vzorec.symptom} · nejčastěji {PHASE_LABEL[vzorec.phase] ?? vzorec.phase}
                                <span className="ml-1 opacity-60">{vzorec.count}×</span>
                            </span>
                        ))}
                    </div>
                </>
            )}
        </Panel>
    );
}

function Sharing({ settings, onSaved }: { settings: Overview['settings']; onSaved: (d: Overview) => void }) {
    const [busy, setBusy] = useState(false);

    const set = async (level: string) => {
        setBusy(true);
        try {
            onSaved((await axios.patch('/api/v1/cyklus/nastaveni', { share_level: level })).data);
        } finally { setBusy(false); }
    };

    const VOLBY: Array<[string, string, string]> = [
        ['none', 'Nic', 'Partner nevidí vůbec nic.'],
        ['dates', 'Jen termíny', 'Uvidí, kdy čekat menstruaci a plodné dny. Žádné příznaky ani poznámky.'],
        ['full', 'Všechno', 'Uvidí i zapsané dny, příznaky a náladu.'],
    ];

    return (
        <Panel icon={Lock} title="Co vidí partner"
            description="Změnu poznáte hned — a kdykoli ji můžete vzít zpátky.">
            <div className="space-y-2">
                {VOLBY.map(([value, label, popis]) => (
                    <button key={value} type="button" onClick={() => void set(value)} disabled={busy}
                        className={`flex w-full items-start gap-3 rounded-xl border p-3 text-left transition-colors disabled:opacity-60 ${
                            settings.share_level === value
                                ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10'
                                : 'border-[var(--color-border)] hover:border-[var(--color-accent)]/50'
                        }`}>
                        <span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                            settings.share_level === value ? 'border-[var(--color-accent)] bg-[var(--color-accent)]' : 'border-[var(--color-border)]'
                        }`}/>
                        <span>
                            <span className="block text-sm text-[var(--color-text-primary)]">{label}</span>
                            <span className="block text-[11px] text-[var(--color-text-secondary)]">{popis}</span>
                        </span>
                    </button>
                ))}
            </div>
        </Panel>
    );
}

/**
 * Připomínky a co se zapisuje.
 *
 * Všechno tohle server uměl už dřív a příkaz na připomínky to i četl — jenže nastavit
 * se to nedalo odnikud. Feature, kterou nejde zapnout ani vypnout, je pro toho, kdo
 * ji má používat, totéž jako by nebyla.
 */
function Preferences({ settings, onSaved }: { settings: Overview['settings']; onSaved: (d: Overview) => void }) {
    const [busy, setBusy] = useState(false);

    const uloz = async (zmena: Record<string, unknown>) => {
        setBusy(true);
        try {
            onSaved((await axios.patch('/api/v1/cyklus/nastaveni', zmena)).data);
        } finally { setBusy(false); }
    };

    return (
        <Panel icon={Bell} title="Připomínky a zápisky">
            <div className="space-y-3">
                <label className="flex items-start gap-3">
                    <input type="checkbox" checked={settings.remind_upcoming} disabled={busy}
                        onChange={e => void uloz({ remind_upcoming: e.target.checked })} className="mt-0.5"/>
                    <span>
                        <span className="block text-sm text-[var(--color-text-primary)]">Upozornit před menstruací</span>
                        <span className="block text-[11px] text-[var(--color-text-secondary)]">
                            Chodí ráno, jednou. Jen vám — partner dostane upozornění jedině tehdy, když mu sdílení sami zapnete.
                        </span>
                    </span>
                </label>

                {settings.remind_upcoming && (
                    <label className="flex flex-wrap items-center gap-2 pl-7 text-xs text-[var(--color-text-secondary)]">
                        Kolik dní předem
                        <select value={settings.remind_days_before} disabled={busy}
                            onChange={e => void uloz({ remind_days_before: Number(e.target.value) })}
                            className="rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1 text-xs text-[var(--color-text-primary)] focus:outline-none">
                            <option value={0}>v den, kdy má začít</option>
                            {[1, 2, 3, 4, 5, 6, 7].map(d => <option key={d} value={d}>{dny(d)} předem</option>)}
                        </select>
                    </label>
                )}

                <label className="flex items-start gap-3 border-t border-[var(--color-border)] pt-3">
                    <input type="checkbox" checked={settings.track_symptoms} disabled={busy}
                        onChange={e => void uloz({ track_symptoms: e.target.checked })} className="mt-0.5"/>
                    <span>
                        <span className="block text-sm text-[var(--color-text-primary)]">Zapisovat příznaky a náladu</span>
                        <span className="block text-[11px] text-[var(--color-text-secondary)]">
                            Když je vypnuté, u dne zůstane jen krvácení a poznámka. Zapsané příznaky se nemažou.
                        </span>
                    </span>
                </label>

                {/* Ruční průměr má smysl jen dokud není z čeho počítat. Jakmile jsou dva
                    cykly, přebije ho skutečnost a pole by lhalo o tom, co dělá. */}
                {settings.based_on_cycles < 2 && (
                    <div className="flex flex-wrap items-center gap-2 border-t border-[var(--color-border)] pt-3 text-xs text-[var(--color-text-secondary)]">
                        Můj cyklus bývá
                        <input type="number" min={15} max={60} defaultValue={settings.average_cycle_days} disabled={busy}
                            onBlur={e => {
                                const hodnota = Number(e.target.value);
                                if (hodnota >= 15 && hodnota <= 60 && hodnota !== settings.average_cycle_days) {
                                    void uloz({ average_cycle_days: hodnota });
                                }
                            }}
                            className="w-16 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1 text-xs text-[var(--color-text-primary)] focus:outline-none"/>
                        dní dlouhý
                    </div>
                )}
            </div>
        </Panel>
    );
}

/**
 * Doplnění historie zpětně.
 *
 * Předpověď potřebuje aspoň dva cykly. Kdo začne zapisovat dnes, čeká dva měsíce, než
 * mu aplikace řekne cokoli užitečného — a přitom „posledního půl roku to začínalo kolem
 * patnáctého" ví hned.
 *
 * Data se nabídnou, ale musí se potvrdit. Kdyby se poslala jen délka cyklu a server si
 * je dopočítal, vyšel by z pravidelných rozestupů nulový rozptyl a aplikace by hlásila
 * spolehlivou předpověď postavenou na vlastním výpočtu — ne na tom, co se opravdu dělo.
 */
function Backfill({ settings, onSaved }: { settings: Overview['settings']; onSaved: (d: Overview) => void }) {
    const [otevreno, setOtevreno] = useState(false);
    const [delka, setDelka] = useState(settings.average_cycle_days);
    const [krvaceni, setKrvaceni] = useState(settings.average_period_days);
    const [posledni, setPosledni] = useState('');
    const [data, setData] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [hlaska, setHlaska] = useState('');

    /** Odkrokuje data dozadu podle typické délky. Jen návrh — každé jde přepsat. */
    const navrhnout = (pocet: number) => {
        if (! posledni) return;

        const seznam: string[] = [];
        const zaklad = new Date(`${posledni}T12:00:00`);

        for (let i = 0; i < pocet; i++) {
            const d = new Date(zaklad);
            d.setDate(d.getDate() - i * delka);
            seznam.push(d.toISOString().slice(0, 10));
        }

        setData(seznam);
        setHlaska('');
    };

    const ulozit = async () => {
        setBusy(true);
        try {
            const { data: prehled } = await axios.post('/api/v1/cyklus/historie', {
                starts: data,
                period_days: krvaceni,
            });
            onSaved(prehled);
            setHlaska(`Doplněno ${prehled.backfill?.created ?? 0} dní.`);
            setData([]);
            setOtevreno(false);
        } catch (problem: any) {
            setHlaska(problem?.response?.data?.message ?? 'Doplnit se to nepodařilo.');
        } finally { setBusy(false); }
    };

    if (! otevreno) {
        return (
            <Panel icon={History} title="Znáte svoje poslední cykly?"
                description="Předpověď potřebuje aspoň dva cykly. Doplňte, co si pamatujete, a bude užitečná hned — jinak si na ni počkáte dva měsíce.">
                <button type="button" onClick={() => setOtevreno(true)}
                    className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <History size={13}/> Doplnit historii
                </button>
                {hlaska && <p className="mt-2 text-[11px] text-emerald-300">{hlaska}</p>}
            </Panel>
        );
    }

    return (
        <Panel icon={History} title="Doplnit historii"
            actions={
                <button type="button" onClick={() => { setOtevreno(false); setData([]); }} title="Zavřít"
                    className="rounded-lg p-1 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <X size={15}/>
                </button>
            }>
            <div className="space-y-3">
                <label className="block">
                    <span className="mb-1 block text-xs text-[var(--color-text-secondary)]">Kdy začala poslední menstruace</span>
                    <input type="date" value={posledni} max={new Date().toISOString().slice(0, 10)}
                        onChange={e => setPosledni(e.target.value)}
                        className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2 text-sm text-[var(--color-text-primary)] focus:outline-none"/>
                </label>

                <div className="flex flex-wrap items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                    Bývá po
                    <input type="number" min={15} max={60} value={delka} onChange={e => setDelka(Number(e.target.value))}
                        className="w-14 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1 text-xs text-[var(--color-text-primary)] focus:outline-none"/>
                    dnech a trvá
                    <input type="number" min={1} max={14} value={krvaceni} onChange={e => setKrvaceni(Number(e.target.value))}
                        className="w-14 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1 text-xs text-[var(--color-text-primary)] focus:outline-none"/>
                    {dny(krvaceni)}
                </div>

                {data.length === 0 && (
                    <div className="flex flex-wrap gap-2">
                        {[3, 6, 12].map(pocet => (
                            <button key={pocet} type="button" onClick={() => navrhnout(pocet)} disabled={! posledni}
                                className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] disabled:opacity-40">
                                Posledních {pocet}
                            </button>
                        ))}
                    </div>
                )}

                {data.length > 0 && (
                    <>
                        <p className="text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            Projděte data a opravte, co nesedí. Aplikace si je jen odkrokovala podle délky,
                            kterou jste zadali — skutečnost bývá nepravidelnější a právě to je na předpovědi
                            to podstatné.
                        </p>

                        <div className="max-h-56 space-y-1 overflow-y-auto pr-1">
                            {data.map((den, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    <span className="w-6 shrink-0 text-[11px] text-[var(--color-text-secondary)]">{i + 1}.</span>
                                    <input type="date" value={den} max={new Date().toISOString().slice(0, 10)}
                                        onChange={e => setData(s => s.map((d, index) => (index === i ? e.target.value : d)))}
                                        className="min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1 text-xs text-[var(--color-text-primary)] focus:outline-none"/>
                                    <button type="button" onClick={() => setData(s => s.filter((_, index) => index !== i))}
                                        title="Odebrat" className="rounded p-1 text-[var(--color-text-secondary)] hover:text-red-300">
                                        <X size={12}/>
                                    </button>
                                </div>
                            ))}
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <button type="button" onClick={() => void ulozit()} disabled={busy || data.length === 0}
                                className="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-rose-500 px-4 text-sm font-medium text-white hover:bg-rose-400 disabled:opacity-50">
                                {busy ? 'Ukládám…' : `Zapsat ${data.length} ${data.length === 1 ? 'cyklus' : data.length <= 4 ? 'cykly' : 'cyklů'}`}
                            </button>
                            <button type="button" onClick={() => setData([])}
                                className="text-xs text-[var(--color-text-secondary)] underline-offset-2 hover:underline">
                                Začít znovu
                            </button>
                        </div>
                    </>
                )}

                {hlaska && <p className="text-[11px] text-amber-300">{hlaska}</p>}
            </div>
        </Panel>
    );
}

function Partners({ partners }: { partners: PartnerView[] }) {
    return (
        <Panel icon={Heart} title="Sdíleno s vámi">
            <div className="space-y-3">
                {partners.map(partner => (
                    <article key={partner.owner.id} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3">
                        <div className="flex flex-wrap items-baseline gap-2">
                            <p className="text-sm font-medium text-[var(--color-text-primary)]">{partner.owner.name}</p>
                            {partner.today && (
                                <span className="text-xs text-[var(--color-text-secondary)]">
                                    {partner.today.cycle_day}. den · {PHASE_LABEL[partner.today.phase] ?? partner.today.phase}
                                </span>
                            )}
                            <span className="ml-auto flex items-center gap-1 text-[10px] text-[var(--color-text-secondary)]">
                                <Eye size={11}/> {partner.share_level === 'full' ? 'vše' : 'jen termíny'}
                            </span>
                        </div>

                        {partner.prediction && (
                            <p className="mt-1.5 flex flex-wrap items-center gap-x-3 text-xs text-[var(--color-text-secondary)]">
                                <span className="inline-flex items-center gap-1">
                                    <Droplet size={11} className="text-rose-400"/> {den(partner.prediction.next_period_on)}
                                    {partner.prediction.days_until >= 0 && ` (za ${partner.prediction.days_until} dní)`}
                                </span>
                                <span className="inline-flex items-center gap-1">
                                    <Sparkles size={11} className="text-emerald-400"/> plodné {den(partner.prediction.fertile_from)} – {den(partner.prediction.fertile_to)}
                                </span>
                            </p>
                        )}
                    </article>
                ))}
            </div>
        </Panel>
    );
}
