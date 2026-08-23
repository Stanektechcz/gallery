import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Droplet, Eye, Heart, Lock, Sparkles } from 'lucide-react';
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
    menstruation: 'menstruace', follicular: 'folikulární fáze', fertile: 'plodné dny', luteal: 'luteální fáze',
};

const SYMPTOMS = ['křeče', 'bolest hlavy', 'nadýmání', 'citlivá prsa', 'únava', 'akné', 'nevolnost', 'bolest zad', 'chutě'];
const MOODS = ['v pohodě', 'podrážděná', 'úzkost', 'smutek', 'energie', 'plačtivost'];

const CONFIDENCE: Record<string, string> = {
    low: 'orientační — zatím je z čeho počítat málo',
    medium: 'přibližná',
    high: 'spolehlivá',
};

const den = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long' });

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

    const grid = useMemo(() => monthGrid(month), [month]);

    return (
        <AppLayout>
            <Head title="Cyklus" />

            <div className="p-4 sm:p-6">
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
                    <div className="space-y-5">
                        <Summary data={data}/>

                        <Calendar
                            grid={grid} month={month} byDay={byDay}
                            prediction={data.prediction}
                            onMonth={setMonth}
                            onPick={setEditing}
                        />

                        {editing && (
                            <DayEditor
                                day={editing}
                                existing={byDay.get(editing)}
                                trackSymptoms={data.settings.track_symptoms}
                                onClose={() => setEditing(null)}
                                onSaved={next => { setData(next); setEditing(null); }}
                            />
                        )}

                        <Sharing settings={data.settings} onSaved={setData}/>

                        {partners.length > 0 && <Partners partners={partners}/>}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function Summary({ data }: { data: Overview }) {
    const { prediction, today, settings } = data;

    return (
        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Tile label="Dnes" value={today ? `${today.cycle_day}. den` : '—'}
                hint={today ? PHASE_LABEL[today.phase] ?? today.phase : 'zatím bez záznamů'} accent/>
            <Tile label="Příští menstruace" value={prediction ? den(prediction.next_period_on) : '—'}
                hint={prediction ? (prediction.days_until >= 0 ? `za ${prediction.days_until} dní` : 'měla už začít') : ''}/>
            <Tile label="Plodné dny" value={prediction ? `${den(prediction.fertile_from)} – ${den(prediction.fertile_to)}` : '—'}
                hint={prediction ? `ovulace ${den(prediction.ovulation_on)}` : ''}/>
            <Tile label="Průměrný cyklus" value={`${settings.average_cycle_days} dní`}
                hint={settings.based_on_cycles > 0 ? `z ${settings.based_on_cycles} cyklů` : 'výchozí odhad'}/>

            {prediction && (
                <p className="sm:col-span-2 lg:col-span-4 text-[11px] text-[var(--color-text-secondary)]">
                    Předpověď je {CONFIDENCE[prediction.confidence]}
                    {prediction.spread_days !== null && prediction.spread_days > 4 && ` · délka cyklu kolísá o ${prediction.spread_days} dní`}.
                </p>
            )}
        </section>
    );
}

function Tile({ label, value, hint, accent = false }: { label: string; value: string; hint?: string; accent?: boolean }) {
    return (
        <div className={`rounded-2xl border p-4 ${accent ? 'border-rose-400/30 bg-rose-500/5' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}>
            <p className="text-xs uppercase tracking-wider text-[var(--color-text-secondary)]">{label}</p>
            <p className="mt-1 text-base font-semibold text-[var(--color-text-primary)]">{value}</p>
            {hint && <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">{hint}</p>}
        </div>
    );
}

function Calendar({ grid, month, byDay, prediction, onMonth, onPick }: {
    grid: Array<string | null>; month: Date; byDay: Map<string, Day>;
    prediction: Prediction | null; onMonth: (d: Date) => void; onPick: (day: string) => void;
}) {
    const dnes = new Date().toISOString().slice(0, 10);

    const jePlodny = (day: string) =>
        prediction !== null && day >= prediction.fertile_from && day <= prediction.fertile_to;

    const jeCekana = (day: string) =>
        prediction !== null && day >= prediction.next_period_on && day <= prediction.period_ends_on;

    return (
        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <div className="mb-3 flex items-center justify-between">
                <button type="button" onClick={() => onMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))}
                    className="rounded-lg border border-[var(--color-border)] px-2.5 py-1 text-xs text-[var(--color-text-secondary)]">←</button>
                <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">
                    {month.toLocaleDateString('cs-CZ', { month: 'long', year: 'numeric' })}
                </h2>
                <button type="button" onClick={() => onMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))}
                    className="rounded-lg border border-[var(--color-border)] px-2.5 py-1 text-xs text-[var(--color-text-secondary)]">→</button>
            </div>

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
                            className={`relative aspect-square rounded-lg text-xs transition-colors ${
                                zaznam?.flow && zaznam.flow !== 'none'
                                    ? `${FLOW_COLOR[zaznam.flow]} text-white`
                                    : 'bg-[var(--color-bg-primary)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                            } ${day === dnes ? 'ring-2 ring-[var(--color-accent)]' : ''}`}>
                            {cislo}

                            {/* Předpověď je tečkovaná, zapsaná skutečnost plná — odhad se
                                nemá tvářit jako údaj. */}
                            {! zaznam && jeCekana(day) && (
                                <span className="absolute inset-0 rounded-lg border border-dashed border-rose-400/60"/>
                            )}
                            {! zaznam && jePlodny(day) && (
                                <span className="absolute bottom-0.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-emerald-400"/>
                            )}
                            {zaznam && (zaznam.symptoms.length > 0 || zaznam.note) && (
                                <span className="absolute right-0.5 top-0.5 h-1 w-1 rounded-full bg-white/80"/>
                            )}
                        </button>
                    );
                })}
            </div>

            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-[var(--color-text-secondary)]">
                <span className="flex items-center gap-1"><span className="h-2 w-2 rounded bg-rose-500"/> zapsané krvácení</span>
                <span className="flex items-center gap-1"><span className="h-2 w-2 rounded border border-dashed border-rose-400"/> čekaná menstruace</span>
                <span className="flex items-center gap-1"><span className="h-1.5 w-1.5 rounded-full bg-emerald-400"/> plodné dny</span>
            </div>
        </section>
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
        <section className="rounded-2xl border border-rose-400/25 bg-[var(--color-bg-card)] p-4">
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">{den(day)}</h2>
                <button type="button" onClick={onClose} className="text-xs text-[var(--color-text-secondary)]">Zavřít</button>
            </div>

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

            <div className="mt-4 flex flex-wrap justify-end gap-2">
                {existing && (
                    <button type="button" onClick={() => void remove()} disabled={busy}
                        className="rounded-xl border border-[var(--color-border)] px-3 py-2 text-xs text-red-300 disabled:opacity-50">Smazat záznam</button>
                )}
                <button type="button" onClick={() => void save()} disabled={busy}
                    className="rounded-xl bg-rose-500 px-4 py-2 text-sm font-medium text-white hover:bg-rose-400 disabled:opacity-50">
                    {busy ? 'Ukládám…' : 'Uložit'}
                </button>
            </div>
        </section>
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
        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="flex items-center gap-2 text-sm font-semibold text-[var(--color-text-primary)]">
                <Lock size={15}/> Co vidí partner
            </h2>
            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                Změnu poznáte hned — a kdykoli ji můžete vzít zpátky.
            </p>

            <div className="mt-3 space-y-2">
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
        </section>
    );
}

function Partners({ partners }: { partners: PartnerView[] }) {
    return (
        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="flex items-center gap-2 text-sm font-semibold text-[var(--color-text-primary)]">
                <Heart size={15} className="text-rose-400"/> Sdíleno s vámi
            </h2>

            <div className="mt-3 space-y-3">
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
        </section>
    );
}
