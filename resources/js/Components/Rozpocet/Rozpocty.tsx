import { hlaska } from '@/Components/Hlasky';
import Panel from '@/Components/Panel';
import { dny } from '@/lib/cestina';
import { castka as prectiCastku, datum, penize, penizeZbyva, procenta } from '@/lib/penize';
import axios from 'axios';
import { AlertTriangle, CalendarDays, PiggyBank, Plus, Trash2, TrendingUp } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Dialog } from './Ucty';
import type { BezpecneNaDen, Ciselniky } from './typy';

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2.5 text-base text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const POPISEK = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

type LimitKategorie = {
    category_uuid: string; name: string; color: string | null;
    limit: number; spent: number; remaining: number; percent: number; currency: string;
};

type Rozpocet = {
    uuid: string; name: string; kind: 'monthly' | 'trip'; currency: string;
    trip: { uuid: string; name: string } | null;
    starts_on: string; ends_on: string | null;
    limit: number; reserve: number; spent: number; refunded: number;
    remaining: number; percent: number;
    safe_daily: BezpecneNaDen;
    projected_total: number | null;
    projected_verdict: 'ok' | 'tight' | 'over' | 'unknown';
    categories: LimitKategorie[];
    top_categories: Array<{ category_id: number | null; name: string; color: string | null; amount: number; percent: number; currency: string }>;
    alert: number | null;
    alert_thresholds: string;
    is_current: boolean;
};

/**
 * Rozpočty — jednoduché stropy, ne účetní plánování.
 *
 * Čerpání se počítá z knihy, takže rozpočet a skutečnost se nemůžou rozejít. To je
 * celý důvod, proč tenhle tab nemá vlastní položky: dvě evidence téže útraty se dřív
 * nebo později rozejdou a nikdo nepozná, která platí.
 *
 * Měsíční rozpočet se každý první posune sám. Bez toho by po půl roce stálo proti
 * měsíčnímu limitu půl roku útrat a hlásil by šestinásobné překročení.
 */
export default function Rozpocty({ ciselniky, onZmena }: { ciselniky: Ciselniky; onZmena: () => void }) {
    const [rozpocty, setRozpocty] = useState<Rozpocet[]>([]);
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');
    const [formular, setFormular] = useState<'novy' | Rozpocet | null>(null);

    const nacti = useCallback(async () => {
        setNacita(true);

        try {
            const { data } = await axios.get<{ budgets: Rozpocet[] }>('/api/v1/rozpocet/rozpocty');
            setRozpocty(data.budgets);
            setChyba('');
        } catch {
            setChyba('Rozpočty se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, []);

    useEffect(() => { void nacti(); }, [nacti]);

    const smaz = async (r: Rozpocet) => {
        try {
            await axios.delete(`/api/v1/rozpocet/rozpocty/${r.uuid}`);
            hlaska('Rozpočet je smazaný. Zapsané útraty zůstaly.', 'uspech');
            setFormular(null);
            await nacti();
            onZmena();
        } catch {
            hlaska('Rozpočet se nepodařilo smazat.', 'chyba');
        }
    };

    if (nacita) {
        return (
            <div className="space-y-3" aria-busy="true" aria-label="Načítám">
                <div className="h-52 animate-pulse rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface-muted)]"/>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex justify-end">
                <button type="button" onClick={() => setFormular('novy')}
                    className="inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                    <Plus size={16}/> Nový rozpočet
                </button>
            </div>

            {chyba && (
                <div className="rounded-2xl border border-red-500/40 p-3">
                    <p className="text-sm text-[var(--color-text-primary)]">{chyba}</p>
                    <button type="button" onClick={() => void nacti()}
                        className="mt-2 min-h-11 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]">
                        Zkusit znovu
                    </button>
                </div>
            )}

            {! chyba && rozpocty.length === 0 && (
                <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center">
                    <p className="text-sm text-[var(--color-text-primary)]">Zatím žádný rozpočet</p>
                    <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[var(--color-text-secondary)]">
                        Rozpočet je strop, ne plán — čerpání se počítá ze zapsaných útrat, takže se s nimi
                        nemůže rozejít. Měsíční se každý první posune sám.
                    </p>
                    <button type="button" onClick={() => setFormular('novy')}
                        className="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                        <Plus size={16}/> Založit rozpočet
                    </button>
                </div>
            )}

            {rozpocty.map(r => <KartaRozpoctu key={r.uuid} rozpocet={r} onUpravit={() => setFormular(r)}/>)}

            {formular && (
                <FormularRozpoctu rozpocet={formular === 'novy' ? null : formular}
                    ciselniky={ciselniky}
                    onHotovo={() => { setFormular(null); void nacti(); onZmena(); }}
                    onSmazat={formular === 'novy' ? undefined : () => void smaz(formular)}
                    onZavrit={() => setFormular(null)}/>
            )}
        </div>
    );
}

function KartaRozpoctu({ rozpocet: r, onUpravit }: { rozpocet: Rozpocet; onUpravit: () => void }) {
    const prekroceno = r.percent > 100;
    const ton = prekroceno ? 'danger' : r.percent >= 80 ? 'warn' : 'plain';
    const b = r.safe_daily;

    return (
        <Panel tone={ton} icon={prekroceno ? AlertTriangle : PiggyBank}
            title={r.name}
            description={[
                r.kind === 'monthly' ? 'Měsíční' : `Cesta${r.trip ? ` · ${r.trip.name}` : ''}`,
                `${datum(r.starts_on)}${r.ends_on ? ` – ${datum(r.ends_on)}` : ''}`,
            ].join(' · ')}
            actions={
                <button type="button" onClick={onUpravit}
                    className="inline-flex min-h-11 items-center px-2 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    Upravit
                </button>
            }>

            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-2xl font-semibold tabular-nums text-[var(--color-text-primary)]">
                    {penizeZbyva(Math.abs(r.remaining), r.currency)}
                </span>
                <span className="shrink-0 text-[11px] text-[var(--color-text-secondary)]">
                    {prekroceno ? 'nad limit' : 'zbývá'} · z {penize(r.limit, r.currency)}
                </span>
            </div>

            <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                <div className="h-full rounded-full transition-[width]"
                    style={{
                        width: `${Math.min(100, r.percent)}%`,
                        background: prekroceno ? 'var(--fin-vydaj)' : r.percent >= 80 ? 'var(--fin-upozorneni)' : 'var(--fin-prijem)',
                    }}/>
            </div>
            <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                utraceno {penize(r.spent, r.currency)} · {procenta(r.percent)}
                {r.refunded > 0 && ` · vráceno ${penize(r.refunded, r.currency)}`}
                {r.reserve > 0 && ` · rezerva ${penize(r.reserve, r.currency)}`}
            </p>

            <dl className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                <Udaj popisek="Bezpečně na den" ikona={CalendarDays}
                    hodnota={b.per_day !== null ? penize(b.per_day, r.currency) : '—'}
                    popis={popisDenni(b, r.currency)}/>
                <Udaj popisek="Zbývá dní" ikona={CalendarDays}
                    hodnota={b.days_left !== null ? String(b.days_left) : '—'}
                    popis={b.state === 'ended' ? 'období skončilo' : 'včetně dneška'}/>
                <Udaj popisek="Odhad na konec" ikona={TrendingUp}
                    hodnota={r.projected_total !== null ? penize(r.projected_total, r.currency) : '—'}
                    popis={odhadPopis(r)}
                    ton={r.projected_verdict === 'over' ? 'spatne' : 'plain'}/>
            </dl>

            {r.categories.length > 0 && (
                <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                    <p className={POPISEK}>Limity kategorií</p>
                    <ul className="space-y-2">
                        {r.categories.map(k => (
                            <li key={k.category_uuid}>
                                <div className="flex items-baseline justify-between gap-2 text-xs">
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <span className="h-2 w-2 shrink-0 rounded-full"
                                            style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                                        <span className="truncate text-[var(--color-text-primary)]">{k.name}</span>
                                    </span>
                                    <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                                        {penize(k.spent, k.currency)} z {penize(k.limit, k.currency)}
                                    </span>
                                </div>
                                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                                    <div className="h-full rounded-full"
                                        style={{
                                            width: `${Math.min(100, k.percent)}%`,
                                            background: k.percent > 100 ? 'var(--fin-vydaj)' : (k.color ?? 'var(--fin-prijem)'),
                                        }}/>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {r.top_categories.length > 0 && r.categories.length === 0 && (
                <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                    <p className={POPISEK}>Kam peníze šly</p>
                    <ul className="space-y-1">
                        {r.top_categories.slice(0, 5).map(k => (
                            <li key={k.category_id ?? 'bez'} className="flex items-baseline justify-between gap-2 text-xs">
                                <span className="flex min-w-0 items-center gap-1.5">
                                    <span className="h-2 w-2 shrink-0 rounded-full"
                                        style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                                    <span className="truncate text-[var(--color-text-primary)]">{k.name}</span>
                                </span>
                                <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                                    {penize(k.amount, k.currency)} · {procenta(k.percent)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Panel>
    );
}

/** Věta pod denní částkou. U překročení se místo doporučení řekne, o kolik. */
function popisDenni(b: BezpecneNaDen, mena: string): string {
    if (b.state === 'over') return `přesah o ${penize(b.over_by ?? 0, mena)}`;
    if (b.state === 'ended') return 'období skončilo';
    if (b.state === 'not_started') return 'ještě nezačalo';
    if (b.state === 'reserve_only') return 'zbývá už jen rezerva';

    return b.reserve_kept > 0 ? 'rezerva odečtená' : 'do konce období';
}

function odhadPopis(r: Rozpocet): string {
    if (r.projected_verdict === 'unknown') return 'zatím málo dnů na odhad';
    if (r.projected_verdict === 'over') return `při tomhle tempu o ${penize((r.projected_total ?? 0) - r.limit, r.currency)} víc`;
    if (r.projected_verdict === 'tight') return 'vyjde to těsně';

    return 'při současném tempu vyjde';
}

function Udaj({ popisek, hodnota, popis, ikona: Ikona, ton = 'plain' }: {
    popisek: string; hodnota: string; popis: string; ikona: any; ton?: 'plain' | 'spatne';
}) {
    return (
        <div className="rounded-xl border border-[var(--color-border)] p-2.5">
            <p className="flex items-center gap-1 text-[10px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                <Ikona size={11}/> {popisek}
            </p>
            <p className={`mt-0.5 text-sm font-medium tabular-nums ${ton === 'spatne' ? 'text-red-400' : 'text-[var(--color-text-primary)]'}`}>
                {hodnota}
            </p>
            <p className="mt-0.5 text-[10px] leading-tight text-[var(--color-text-secondary)]">{popis}</p>
        </div>
    );
}

function FormularRozpoctu({ rozpocet, ciselniky, onHotovo, onSmazat, onZavrit }: {
    rozpocet: Rozpocet | null;
    ciselniky: Ciselniky;
    onHotovo: () => void; onSmazat?: () => void; onZavrit: () => void;
}) {
    const [form, setForm] = useState({
        name: rozpocet?.name ?? '',
        budget_kind: rozpocet?.kind ?? 'monthly' as 'monthly' | 'trip',
        currency: rozpocet?.currency ?? 'EUR',
        amount: rozpocet ? String(rozpocet.limit) : '',
        reserve_amount: rozpocet?.reserve ? String(rozpocet.reserve) : '',
        trip_uuid: rozpocet?.trip?.uuid ?? '',
    });

    const [limity, setLimity] = useState<Record<string, string>>(() =>
        Object.fromEntries((rozpocet?.categories ?? []).map(k => [k.category_uuid, String(k.limit)])));

    const [uklada, setUklada] = useState(false);
    const [chyba, setChyba] = useState('');
    const [mazani, setMazani] = useState(false);

    const kategorie = ciselniky.categories.filter(k => k.kind === 'expense');
    const soucetLimitu = Object.values(limity).reduce((s, v) => s + (prectiCastku(v) ?? 0), 0);
    const strop = prectiCastku(form.amount) ?? 0;

    const uloz = async () => {
        setUklada(true);
        setChyba('');

        const cesta = ciselniky.trips.find(c => c.uuid === form.trip_uuid);

        const telo = {
            name: form.name,
            budget_kind: form.budget_kind,
            currency: form.currency,
            amount: prectiCastku(form.amount),
            reserve_amount: prectiCastku(form.reserve_amount),
            finance_project_id: null as number | null,
            limits: Object.entries(limity)
                .map(([uuid, v]) => ({ category_uuid: uuid, amount: prectiCastku(v) ?? 0 }))
                .filter(l => l.amount > 0),
        };

        try {
            if (rozpocet) {
                await axios.patch(`/api/v1/rozpocet/rozpocty/${rozpocet.uuid}`, telo);
                hlaska('Rozpočet je upravený.', 'uspech');
            } else {
                await axios.post('/api/v1/rozpocet/rozpocty', telo);
                hlaska('Rozpočet je založený.', 'uspech');
            }

            onHotovo();
        } catch (problem: any) {
            setChyba(problem?.response?.data?.message ?? 'Rozpočet se nepodařilo uložit.');
        } finally {
            setUklada(false);
        }
    };

    return (
        <Dialog nadpis={rozpocet ? 'Úprava rozpočtu' : 'Nový rozpočet'} onZavrit={onZavrit}>
            <div className="space-y-3">
                <div>
                    <label className={POPISEK} htmlFor="rozp-nazev">Název</label>
                    <input id="rozp-nazev" value={form.name} autoFocus
                        onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                        placeholder="Měsíční rozpočet" className={POLE}/>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label className={POPISEK} htmlFor="rozp-mena">Měna</label>
                        <select id="rozp-mena" value={form.currency}
                            onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={POLE}>
                            {['EUR', 'CZK'].map(m => <option key={m} value={m}>{m}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="rozp-castka">Kolik měsíčně</label>
                        <input id="rozp-castka" type="text" inputMode="decimal" value={form.amount}
                            onChange={e => setForm(f => ({ ...f, amount: e.target.value }))}
                            placeholder="800" className={`${POLE} tabular-nums`}/>
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="rozp-rezerva">Rezerva</label>
                        <input id="rozp-rezerva" type="text" inputMode="decimal" value={form.reserve_amount}
                            onChange={e => setForm(f => ({ ...f, reserve_amount: e.target.value }))}
                            placeholder="0" className={`${POLE} tabular-nums`}/>
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            Nerozpočítá se do denní částky — zůstane stranou.
                        </p>
                    </div>
                </div>

                <p className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                    Měsíční rozpočet měří vždycky <strong className="text-[var(--color-text-primary)]">aktuální měsíc</strong>{' '}
                    a každý první se posune sám. Nemusíte ho zakládat znovu.
                </p>

                <div className="border-t border-[var(--color-border)] pt-3">
                    <p className={POPISEK}>Limity kategorií (nepovinné)</p>
                    <ul className="space-y-1.5">
                        {kategorie.map(k => (
                            <li key={k.uuid} className="flex items-center gap-2">
                                <span className="flex min-w-0 flex-1 items-center gap-1.5">
                                    <span className="h-2 w-2 shrink-0 rounded-full"
                                        style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                                    <span className="truncate text-xs text-[var(--color-text-primary)]">{k.name}</span>
                                </span>
                                <input type="text" inputMode="decimal" value={limity[k.uuid] ?? ''}
                                    onChange={e => setLimity(l => ({ ...l, [k.uuid]: e.target.value }))}
                                    aria-label={`Limit kategorie ${k.name}`}
                                    placeholder="—"
                                    className="w-24 shrink-0 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1.5 text-right text-sm tabular-nums text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none"/>
                            </li>
                        ))}
                    </ul>

                    {/* Součet limitů nad stropem není chyba — kategorie se obvykle
                        nevyčerpají všechny. Ale stojí za to o tom vědět. */}
                    {soucetLimitu > 0 && strop > 0 && soucetLimitu > strop && (
                        <p className="mt-2 text-[11px] leading-relaxed text-amber-400">
                            Limity kategorií dávají dohromady {penize(soucetLimitu, form.currency)}, což je
                            víc než celý rozpočet. Není to chyba — všechny se obvykle nevyčerpají — ale
                            hlídat se pak dá jen každá zvlášť, ne součet.
                        </p>
                    )}
                </div>

                {chyba && (
                    <p className="rounded-xl border border-red-500/40 bg-[var(--color-surface-muted)] p-3 text-xs leading-relaxed text-[var(--color-text-primary)]">
                        {chyba}
                    </p>
                )}

                <div className="flex gap-2 border-t border-[var(--color-border)] pt-3">
                    <button type="button" onClick={() => void uloz()} disabled={uklada || ! form.name || ! strop}
                        className="min-h-11 flex-1 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                        {rozpocet ? 'Uložit' : 'Založit rozpočet'}
                    </button>
                    <button type="button" onClick={onZavrit}
                        className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-secondary)]">
                        Zrušit
                    </button>
                </div>

                {onSmazat && (
                    <div className="border-t border-[var(--color-border)] pt-3">
                        {mazani ? (
                            <div>
                                <p className="text-xs leading-relaxed text-[var(--color-text-secondary)]">
                                    Zapsané útraty zůstanou — rozpočet je jen strop nad nimi. Smazat ho
                                    znamená přestat měřit, ne přijít o data.
                                </p>
                                <div className="mt-2 flex gap-2">
                                    <button type="button" onClick={onSmazat}
                                        className="min-h-11 rounded-lg bg-red-500/90 px-3 text-sm font-medium text-white">
                                        Opravdu smazat
                                    </button>
                                    <button type="button" onClick={() => setMazani(false)}
                                        className="min-h-11 rounded-lg px-3 text-sm text-[var(--color-text-secondary)]">
                                        Zpět
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <button type="button" onClick={() => setMazani(true)}
                                className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)] hover:border-red-500/40 hover:text-red-400">
                                <Trash2 size={15}/> Smazat rozpočet
                            </button>
                        )}
                    </div>
                )}
            </div>
        </Dialog>
    );
}
