import { hlaska } from '@/Components/Hlasky';
import Panel from '@/Components/Panel';
import { dny } from '@/lib/cestina';
import { castka as prectiCastku, datum, penize, penizeZbyva, procenta } from '@/lib/penize';
import axios from 'axios';
import { AlertTriangle, Calculator, CalendarDays, History, PiggyBank, Plus, RotateCcw, Trash2, TrendingUp } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import GrafCerpani, { type Cerpani } from './GrafCerpani';
import { Dialog } from './Ucty';
import type { BezpecneNaDen, Ciselniky, Pristup } from './typy';

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2.5 text-base text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const POPISEK = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

type LimitKategorie = {
    category_uuid: string; name: string; color: string | null;
    limit: number; spent: number; remaining: number; percent: number; currency: string;
    order: number; priority: number;
    planned: number; original: number; covered: number; missing: number;
    state: 'pokryto' | 'castecne' | 'nepokryto';
    projected: number | null;
    verdict: 'vyjde' | 'tesne' | 'nevyjde' | 'unknown';
    surplus: number;
    shortfall: number;
};

/** Co se dá uvolnit z jedněch peněz do druhých. */
type Uvolneni = {
    currency: string;
    available: number;
    from_free: number;
    moved: number;
    still_short: number;
    frees_up: number;
    covers: string | null;
    givers: Array<{ category_uuid: string; name: string; amount: number; new_planned: number }>;
    receivers: Array<{ category_uuid: string; name: string; amount: number; new_planned: number }>;
};

/** Kolik je na co vyhrazeno a co z toho peníze pokryjí. */
type Rozdeleni = {
    currency: string;
    available: number;
    planned: number;
    free: number;
    missing: number;
    first_uncovered: string | null;
    rows: LimitKategorie[];
    release: Uvolneni;
    balanced: boolean;
};

/** Rada, co s penězi dělat. Vždycky nese číslo — „šetřete" nikoho nikam neposune. */
type Rada = {
    key: string;
    tone: 'dobre' | 'pozor' | 'spatne' | 'tip';
    title: string;
    text: string;
    amount: number;
    currency: string;
};

const TON_BARVA: Record<Rada['tone'], string> = {
    dobre: 'var(--fin-prijem)',
    pozor: 'var(--fin-upozorneni)',
    spatne: 'var(--fin-vydaj)',
    tip: 'var(--color-text-secondary)',
};

/** Kategorie, ve které se utrácí, ale nic na ni vyhrazeno není. */
type MimoPlan = {
    category_uuid: string; name: string; color: string | null;
    spent: number; suggested: number; currency: string;
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
    allocation: Rozdeleni;
    unplanned: MimoPlan[];
    income: number;
    income_adds: boolean;
    auto_balance: boolean;
    available: number;
    top_categories: Array<{ category_id: number | null; name: string; color: string | null; amount: number; percent: number; currency: string }>;
    alert: number | null;
    alert_thresholds: string;
    is_current: boolean;
    owner_user_id: number | null;
    owner_name: string | null;
    access: Pristup[];
    can_edit: boolean;
    burndown: Cerpani;
    advice: Rada[];
    history: ZmenaPlanu[];
};

/** Jedna změna plánu — kdo, kdy a z čeho na co. */
type ZmenaPlanu = {
    action: 'rucne' | 'zruseno' | 'podle-skutecnosti' | 'puvodni-odhad';
    category: string | null;
    from: number | null;
    to: number | null;
    currency: string;
    who: string | null;
    at: string;
};

/**
 * Komu rozpočet patří — jednou větou.
 *
 * Skládá se z hotových kusů, ne z jména vsazeného za předložku: „rozpočet pro Adri"
 * by u jiného jména vyšlo špatně a čeština to nedovoluje odvodit.
 */
function ciJeTo(r: { owner_user_id: number | null; owner_name: string | null; access: Pristup[] }, ja: number): string {
    if (r.owner_user_id === null) return 'Společný';

    const cist = r.access.map(p => p.name).filter(Boolean);
    const majitel = r.owner_user_id === ja ? 'Můj' : `Patří: ${r.owner_name ?? 'někomu jinému'}`;

    return cist.length > 0 ? `${majitel} · vidí i ${cist.join(', ')}` : majitel;
}

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

            {rozpocty.map(r => (
                <KartaRozpoctu key={r.uuid} rozpocet={r} ja={ciselniky.me}
                    onZmena={() => { void nacti(); onZmena(); }}
                    onUpravit={r.can_edit ? () => setFormular(r) : undefined}/>
            ))}

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

function KartaRozpoctu({ rozpocet: r, ja, onUpravit, onZmena }: {
    rozpocet: Rozpocet; ja: number; onUpravit?: () => void; onZmena: () => void;
}) {
    const prekroceno = r.percent > 100;
    const ton = prekroceno ? 'danger' : r.percent >= 80 ? 'warn' : 'plain';
    const b = r.safe_daily;

    return (
        <Panel tone={ton} icon={prekroceno ? AlertTriangle : PiggyBank}
            title={r.name}
            description={[
                r.kind === 'monthly' ? 'Měsíční' : `Cesta${r.trip ? ` · ${r.trip.name}` : ''}`,
                `${datum(r.starts_on)}${r.ends_on ? ` – ${datum(r.ends_on)}` : ''}`,
                ciJeTo(r, ja),
            ].join(' · ')}
            actions={onUpravit ? (
                <button type="button" onClick={onUpravit}
                    className="inline-flex min-h-11 items-center px-2 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    Upravit
                </button>
            ) : (
                // Cizí rozpočet bez práva zápisu: vidět ho smí, měnit ne. Tlačítko, které
                // by skončilo chybou od serveru, je horší než žádné.
                <span className="inline-flex min-h-11 items-center px-2 text-[11px] text-[var(--color-text-secondary)]">
                    jen ke čtení
                </span>
            )}>

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

            <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                <GrafCerpani data={r.burndown}/>
            </div>

            <Rady rady={r.advice}/>

            <CoKdyz rozpocet={r}/>

            {r.categories.length > 0 && <TabulkaRozdeleni rozpocet={r} onZmena={onZmena}/>}

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

/**
 * Co s tím dělat — rady, ne další čísla.
 *
 * Údaj říká, co se stalo; rada říká, co s tím. „Za potraviny 4 800" versus „utrácíte
 * o třetinu rychleji a peníze dojdou 12. ledna" — první se přečte a zapomene, druhé
 * změní, co se koupí k večeři.
 *
 * Nejvýš tři napoprvé. Šest rad najednou je seznam, který nikdo nedočte, a rada,
 * kterou nikdo nedočte, je k ničemu.
 */
function Rady({ rady }: { rady: Rada[] }) {
    const [vse, setVse] = useState(false);

    if (rady.length === 0) return null;

    const videt = vse ? rady : rady.slice(0, 3);

    return (
        <div className="mt-3 border-t border-[var(--color-border)] pt-3">
            <p className={POPISEK}>Co s tím</p>
            <ul className="space-y-2">
                {videt.map(rada => (
                    <li key={rada.key} className="flex gap-2">
                        <span aria-hidden="true" className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                            style={{ background: TON_BARVA[rada.tone] }}/>
                        <span className="min-w-0">
                            <span className="block text-xs font-medium text-[var(--color-text-primary)]">
                                {rada.title}
                            </span>
                            <span className="block text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                {rada.text}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>

            {rady.length > 3 && (
                <button type="button" onClick={() => setVse(! vse)}
                    className="mt-2 min-h-11 text-[11px] text-[var(--color-text-secondary)] underline decoration-dotted underline-offset-4">
                    {vse ? 'Zobrazit méně' : `Zobrazit další (${rady.length - 3})`}
                </button>
            )}
        </div>
    );
}

/**
 * Na co jsou peníze vyhrazené — tabulka, ne seznam pruhů.
 *
 * Čísla ve sloupcích pod sebou jde srovnat pohledem; pruhy vedle sebe ne. A právě
 * srovnání je celý smysl: proti sobě stojí, kolik je na co vyhrazeno a kolik z toho
 * peníze doopravdy pokryjí.
 *
 * Řadí se podle pořadí důležitosti, ne podle čerpání. Pořadí je to, co rozhoduje,
 * když peníze nevyjdou — přeskládat tabulku podle procent by ten vztah schovalo.
 */
function TabulkaRozdeleni({ rozpocet: r, onZmena }: { rozpocet: Rozpocet; onZmena?: () => void }) {
    const a = r.allocation;
    const chybi = a.missing > 0;
    const lzeUpravit = r.can_edit && onZmena !== undefined;

    /** Uloží jednu částku. Jeden požadavek, ne celý formulář. */
    const uloz = async (uuid: string, castka: number) => {
        try {
            await axios.patch(`/api/v1/rozpocet/rozpocty/${r.uuid}/vyhrazeni`, {
                category_uuid: uuid, amount: castka,
            });
            onZmena?.();
        } catch {
            hlaska('Částku se nepodařilo uložit.', 'chyba');
        }
    };

    return (
        <div className="mt-3 border-t border-[var(--color-border)] pt-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p className={POPISEK}>Na co jsou peníze vyhrazené</p>
                <p className="text-[11px] tabular-nums text-[var(--color-text-secondary)]">
                    k rozdělení {penize(a.available, a.currency)}
                    {r.income > 0 && ` · z toho příjem ${penize(r.income, a.currency)}`}
                </p>
            </div>

            {/* Široká tabulka se posouvá uvnitř svého rámečku, ne celou stránkou. */}
            <div className="-mx-1 overflow-x-auto px-1">
                <table className="w-full table-fixed border-collapse text-xs">
                    <caption className="sr-only">
                        Vyhrazené částky podle pořadí důležitosti, jejich pokrytí a čerpání
                    </caption>
                    <thead>
                        <tr className="text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">
                            <th scope="col" className="py-1 pr-2 text-left font-medium">Na co</th>
                            <th scope="col" className="w-[5.5rem] py-1 px-2 text-right font-medium">Vyhrazeno</th>
                            {/* Na úzkém displeji by čtvrtý sloupec vytlačil „Zbývá" mimo
                                obraz. Utracené se tam píše pod název — je to doplňující
                                údaj, kdežto zbývající částka je ta, kvůli které se sem lidi
                                dívají. */}
                            <th scope="col" className="hidden w-[5.5rem] py-1 px-2 text-right font-medium sm:table-cell">
                                Utraceno
                            </th>
                            <th scope="col" className="w-[5.5rem] py-1 pl-2 text-right font-medium">Zbývá</th>
                        </tr>
                    </thead>
                    <tbody>
                        {a.rows.map(k => (
                            <tr key={k.category_uuid} className="border-t border-[var(--color-border)]">
                                <th scope="row" className="py-1.5 pr-2 text-left font-normal">
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <span className="w-4 shrink-0 text-right text-[10px] tabular-nums text-[var(--color-text-secondary)]">
                                            {k.order}.
                                        </span>
                                        <span className="h-2 w-2 shrink-0 rounded-full"
                                            style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                                        <span className="truncate text-[var(--color-text-primary)]">{k.name}</span>
                                    </span>
                                    <span className="block pl-[1.625rem] text-[10px] tabular-nums text-[var(--color-text-secondary)] sm:hidden">
                                        utraceno {penize(k.spent, k.currency)}
                                    </span>
                                </th>
                                <td className="py-1.5 px-2 text-right tabular-nums text-[var(--color-text-primary)]">
                                    {lzeUpravit
                                        ? <CastkaNaMiste hodnota={k.planned} mena={k.currency} popisek={k.name}
                                            onUloz={c => uloz(k.category_uuid, c)}/>
                                        : penize(k.planned, k.currency)}
                                    {/* Původní odhad zůstává vidět. Bez něj se nedá poznat,
                                        jestli se člověk spletl v plánu, nebo se změnil život. */}
                                    {Math.abs(k.original - k.planned) >= 0.01 && (
                                        <span className="block text-[10px] text-[var(--color-text-secondary)]">
                                            původně {penize(k.original, k.currency)}
                                        </span>
                                    )}
                                    {k.state !== 'pokryto' && (
                                        <span className="block text-[10px] text-amber-400">
                                            {k.state === 'nepokryto'
                                                ? 'nepokryto'
                                                : `chybí ${penize(k.missing, k.currency)}`}
                                        </span>
                                    )}
                                </td>
                                <td className="hidden py-1.5 px-2 text-right tabular-nums text-[var(--color-text-secondary)] sm:table-cell">
                                    {penize(k.spent, k.currency)}
                                </td>
                                <td className={`py-1.5 pl-2 text-right tabular-nums ${
                                    k.remaining < 0 ? 'text-[var(--fin-vydaj)]' : 'text-[var(--color-text-primary)]'
                                }`}>
                                    {penize(k.remaining, k.currency)}
                                    {/* Předpověď z dosavadního tempa. Mlčí, dokud není z čeho
                                        počítat — číslo z jednoho nákupu vypadá věrohodně
                                        a je nesmyslné. */}
                                    {k.verdict === 'nevyjde' && (
                                        <span className="block text-[10px] text-[var(--fin-vydaj)]">
                                            nevyjde o {penize(k.shortfall, k.currency)}
                                        </span>
                                    )}
                                    {k.verdict === 'vyjde' && k.surplus > 0 && (
                                        <span className="block text-[10px] text-[var(--color-text-secondary)]">
                                            zbude {penize(k.surplus, k.currency)}
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t-2 border-[var(--color-border)] font-medium">
                            <th scope="row" className="py-1.5 pr-2 text-left text-[var(--color-text-secondary)]">
                                Volné peníze
                            </th>
                            {/* Prázdné buňky kopírují sloupce těla, aby částka zůstala pod
                                „Zbývá" i po skrytí sloupce na úzkém displeji. */}
                            <td/>
                            <td className="hidden sm:table-cell"/>
                            <td className="py-1.5 pl-2 text-right tabular-nums text-[var(--color-text-primary)]">
                                {penize(a.free, a.currency)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {chybi ? (
                <p className="mt-2 text-[11px] leading-relaxed text-amber-400">
                    Vyhrazeno je o {penize(a.missing, a.currency)} víc, než kolik je k rozdělení.
                    Pokrývá se odshora, takže první nepokryté je{' '}
                    <strong className="text-[var(--color-text-primary)]">{a.first_uncovered}</strong>.
                    {r.income_adds && ' Až přijde příjem, dorovná se to samo.'}
                </p>
            ) : (
                <p className="mt-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                    {r.income_adds
                        ? 'Zapsaný příjem se rozdělí sám — odshora podle pořadí, zbytek zůstane volný.'
                        : 'Rozpočet je pevná měsíční částka, zapsané příjmy se k ní nepřičítají.'}
                </p>
            )}

            <Prerozdeleni rozpocet={r} lzeUpravit={lzeUpravit} onZmena={onZmena}/>
            <MimoPlanSeznam rozpocet={r} lzeUpravit={lzeUpravit} onUloz={uloz}/>
            <HistoriePlanu zaznamy={r.history}/>
        </div>
    );
}

/**
 * Co se s plánem dělo.
 *
 * Kniha vede historii peněz, ne historii toho, na čem se lidi dohodli. Když se ve
 * dvou dívají na rozpočet a na jídlo je najednou o dvě stě víc, není jak zjistit,
 * jestli to někdo posunul ručně, nebo se to dorovnalo samo.
 *
 * Zabalené, dokud si o to nikdo neřekne. Většinu času nikoho nezajímá a rozbalené
 * by z karty rozpočtu udělalo výpis událostí.
 */
function HistoriePlanu({ zaznamy }: { zaznamy: ZmenaPlanu[] }) {
    const [otevreno, setOtevreno] = useState(false);

    if (zaznamy.length === 0) return null;

    return (
        <div className="mt-3 border-t border-[var(--color-border)] pt-3">
            <button type="button" onClick={() => setOtevreno(! otevreno)}
                aria-expanded={otevreno}
                className="inline-flex min-h-11 items-center gap-1.5 text-[11px] text-[var(--color-text-secondary)] underline decoration-dotted underline-offset-4">
                <History size={13}/> Co se s plánem dělo ({zaznamy.length})
            </button>

            {otevreno && (
                <ul className="mt-1.5 space-y-1">
                    {zaznamy.map((z, i) => (
                        <li key={`${z.at}-${i}`} className="text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            <span className="tabular-nums">{datum(z.at.slice(0, 10))}</span>
                            {z.who && <> · {z.who}</>}
                            {' · '}
                            <span className="text-[var(--color-text-primary)]">{popisZmeny(z)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Věta o jedné změně.
 *
 * Název kategorie stojí před dvojtečkou, ne uvnitř věty — po předložce by se musel
 * skloňovat a u kategorií, které si pojmenoval uživatel, to nejde odvodit.
 */
function popisZmeny(z: ZmenaPlanu): string {
    const c = (v: number | null) => (v === null ? '—' : penize(v, z.currency));

    switch (z.action) {
        case 'rucne':
            return `${z.category ?? 'plán'}: ${z.from === null ? 'nově' : c(z.from) + ' →'} ${c(z.to)}`;
        case 'zruseno':
            return `${z.category ?? 'položka'}: vyřazeno z plánu (bylo ${c(z.from)})`;
        case 'podle-skutecnosti':
            return `${z.category ?? 'plán'}: přepsáno podle skutečnosti, ${c(z.from)} → ${c(z.to)}`;
        case 'puvodni-odhad':
            return 'plán vrácen na původní odhad';
        default:
            return z.action;
    }
}

/**
 * Částka, kterou jde přepsat na místě.
 *
 * Kvůli padesáti eurům na jídlo otevřít formulář, najít řádek mezi patnácti
 * kategoriemi a přepsat ho nikdo nebude — a plán tím zestárne. Klepnutí na číslo
 * a Enter je ta nejčastější úprava vůbec, takže má stát nejmíň.
 *
 * Escape vrátí původní hodnotu. Bez toho by omylem přepsané číslo šlo zachránit
 * jen tím, že si ho člověk pamatuje.
 */
function CastkaNaMiste({ hodnota, mena, popisek, onUloz }: {
    hodnota: number; mena: string; popisek: string; onUloz: (castka: number) => void;
}) {
    const [upravuje, setUpravuje] = useState(false);
    const [text, setText] = useState(String(hodnota));

    if (! upravuje) {
        return (
            <button type="button"
                onClick={() => { setText(String(hodnota)); setUpravuje(true); }}
                aria-label={`Upravit částku na ${popisek}, teď ${penize(hodnota, mena)}`}
                className="rounded px-1 tabular-nums underline decoration-dotted underline-offset-4 hover:bg-[var(--color-surface-muted)]">
                {penize(hodnota, mena)}
            </button>
        );
    }

    const potvrd = () => {
        const c = prectiCastku(text);
        setUpravuje(false);

        if (c !== null && c !== hodnota) onUloz(c);
    };

    return (
        <input type="text" inputMode="decimal" value={text} autoFocus
            aria-label={`Částka na ${popisek}`}
            onChange={e => setText(e.target.value)}
            onBlur={potvrd}
            onKeyDown={e => {
                if (e.key === 'Enter') potvrd();
                if (e.key === 'Escape') { setText(String(hodnota)); setUpravuje(false); }
            }}
            className="w-20 rounded border border-[var(--color-accent)] bg-[var(--color-bg-primary)] px-1 py-0.5 text-right text-xs tabular-nums text-[var(--color-text-primary)] focus:outline-none"/>
    );
}

/**
 * Uvolnění peněz z jedněch kategorií do druhých.
 *
 * Výpočet běží sám z každého zapsaného výdaje; přepis plánu je jedno klepnutí.
 * Kdyby se plán měnil sám, přestal by to být plán a nikdo by nevěděl, na čem se
 * vlastně dohodli.
 */
function Prerozdeleni({ rozpocet: r, lzeUpravit, onZmena }: {
    rozpocet: Rozpocet; lzeUpravit: boolean; onZmena?: () => void;
}) {
    const [bezi, setBezi] = useState(false);
    const u = r.allocation.release;

    // Vyrovnaný plán se od uloženého liší — pak je co nabídnout k zapsání i k vrácení.
    const upraveno = r.allocation.rows.filter(k => Math.abs(k.original - k.planned) >= 0.01);

    if (u.moved <= 0 && upraveno.length === 0) return null;

    const zavolej = async (kam: string, nedarilo: string) => {
        setBezi(true);

        try {
            const { data } = await axios.post(`/api/v1/rozpocet/rozpocty/${r.uuid}/${kam}`);
            hlaska(data.message, 'uspech');
            onZmena?.();
        } catch {
            hlaska(nedarilo, 'chyba');
        } finally {
            setBezi(false);
        }
    };

    return (
        <div className="mt-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3">
            <p className="text-xs font-medium text-[var(--color-text-primary)]">
                {r.auto_balance
                    ? `Podle skutečnosti je přerovnáno ${penize(u.moved || soucet(upraveno), u.currency)}`
                    : `Podle dosavadního tempa jde přesunout ${penize(u.moved, u.currency)}`}
            </p>
            <ul className="mt-1.5 space-y-0.5 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                {u.from_free > 0 && <li>z volných peněz: {penize(u.from_free, u.currency)}</li>}
                {u.givers.map(d => (
                    <li key={d.category_uuid}>
                        {d.name}: zřejmě nevyčerpá {penize(d.amount, u.currency)}
                    </li>
                ))}
                {u.receivers.map(p => (
                    <li key={p.category_uuid} className="text-[var(--color-text-primary)]">
                        → {p.name}: dorovnat o {penize(p.amount, u.currency)}
                    </li>
                ))}
                {u.frees_up > 0 && (
                    <li className="text-[var(--color-text-primary)]">
                        → uvolní se {penize(u.frees_up, u.currency)}
                        {u.covers && <> a pokryje se {u.covers}</>}
                    </li>
                )}
            </ul>

            {u.still_short > 0 && (
                <p className="mt-1.5 text-[11px] leading-relaxed text-amber-400">
                    I potom by chybělo {penize(u.still_short, u.currency)} — uvolnit se dá jen to,
                    co někde doopravdy zbývá.
                </p>
            )}

            {/* Když se vyrovnává samo, není co zapisovat ani vracet: plán se počítá
                z knihy pokaždé znovu a původní odhad zůstává uložený. Tlačítko, po
                kterém se viditelně nic nestane, je horší než žádné. */}
            {r.auto_balance ? (
                <p className="mt-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                    Přepočítává se samo z každého zapsaného výdaje. Původní odhad zůstává
                    u každé položky, takže je pořád vidět, oč se skutečnost rozešla.
                </p>
            ) : lzeUpravit && (
                <div className="mt-2 flex flex-wrap gap-2">
                    {u.moved > 0 && (
                        <button type="button" onClick={() => void zavolej('prerozdelit', 'Plán se nepodařilo přepsat.')}
                            disabled={bezi}
                            className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)] hover:border-[var(--color-accent)] disabled:opacity-40">
                            <Calculator size={14}/> Přepsat plán podle skutečnosti
                        </button>
                    )}
                    {upraveno.length > 0 && (
                        <button type="button" onClick={() => void zavolej('puvodni-plan', 'Původní plán se nepodařilo vrátit.')}
                            disabled={bezi}
                            className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:border-[var(--color-accent)] disabled:opacity-40">
                            <RotateCcw size={14}/> Vrátit původní odhad
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

/** Kolik peněz se v přerovnání celkem hnulo. */
function soucet(radky: LimitKategorie[]): number {
    return radky.reduce((s, k) => s + Math.max(0, k.planned - k.original), 0);
}

/**
 * Kategorie, ve kterých se utrácí mimo plán.
 *
 * Bez nich by tabulka tvrdila, že plán sedí, zatímco peníze odtékají vedle. Návrh
 * částky vychází z dosavadního tempa, takže vyhradit ji je jedno klepnutí.
 */
function MimoPlanSeznam({ rozpocet: r, lzeUpravit, onUloz }: {
    rozpocet: Rozpocet; lzeUpravit: boolean; onUloz: (uuid: string, castka: number) => void;
}) {
    if (r.unplanned.length === 0) return null;

    return (
        <div className="mt-3 border-t border-[var(--color-border)] pt-3">
            <p className={POPISEK}>Utrácí se i mimo plán</p>
            <ul className="space-y-1.5">
                {r.unplanned.map(k => (
                    <li key={k.category_uuid} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                        <span className="flex min-w-0 flex-1 basis-28 items-center gap-1.5">
                            <span className="h-2 w-2 shrink-0 rounded-full"
                                style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                            <span className="truncate text-[var(--color-text-primary)]">{k.name}</span>
                        </span>
                        <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                            utraceno {penize(k.spent, k.currency)}
                        </span>
                        {lzeUpravit && (
                            <button type="button" onClick={() => onUloz(k.category_uuid, k.suggested)}
                                className="shrink-0 rounded-lg border border-[var(--color-border)] px-2 py-1 text-[11px] text-[var(--color-text-primary)] hover:border-[var(--color-accent)]">
                                Vyhradit {penize(k.suggested, k.currency)}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * „Co když" — simulace, která nic neukládá.
 *
 * Otázka „a co když budeme utrácet dvacet eur denně" má odpověď, kterou si člověk
 * jinak počítá na papíře. Tady ji spočítá rozpočet, ale **nesmí přitom nic změnit**:
 * simulace, která zapíše plánovaný výdaj, by z pomůcky udělala past.
 *
 * Proto žádné ukládání a jednoznačné „Zrušit simulaci", které vrátí skutečná čísla.
 * Dokud je otevřená, je vidět, že jde o hypotézu — jiný rám a jiný nadpis.
 */
function CoKdyz({ rozpocet: r }: { rozpocet: Rozpocet }) {
    const [otevrene, setOtevrene] = useState(false);
    const [denne, setDenne] = useState('');
    const [mimoradny, setMimoradny] = useState('');
    const [rezervaNavic, setRezervaNavic] = useState('');

    const dni = r.safe_daily.days_left ?? 0;

    const vysledek = (() => {
        if (dni <= 0) return null;

        const naDen = prectiCastku(denne);
        const navic = prectiCastku(mimoradny) ?? 0;
        const rezerva = prectiCastku(rezervaNavic) ?? 0;

        if (naDen === null && navic === 0 && rezerva === 0) return null;

        // Nezadaná denní útrata znamená nulu, ne dosavadní tempo. Simulace odpovídá
        // přesně na to, co se do ní napsalo — dosadit za člověka číslo, které nezadal,
        // by dalo výsledek, u kterého by nevěděl, odkud se vzal.
        const predpokladaneVydaje = (naDen ?? 0) * dni + navic;

        const zbudeNaKonci = r.limit - r.spent - predpokladaneVydaje - rezerva;

        return {
            dni,
            vydaje: predpokladaneVydaje,
            zbude: zbudeNaKonci,
            prekroci: zbudeNaKonci < 0,
            // Kolik by se dalo utrácet, aby to vyšlo i s mimořádným výdajem a rezervou.
            doporucene: (r.limit - r.spent - navic - rezerva) / dni,
        };
    })();

    const zrusit = () => { setDenne(''); setMimoradny(''); setRezervaNavic(''); };

    if (dni <= 0) return null;

    return (
        <div className="mt-3 border-t border-[var(--color-border)] pt-3">
            <button type="button" onClick={() => setOtevrene(o => ! o)}
                aria-expanded={otevrene}
                className="inline-flex min-h-11 items-center gap-1.5 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                <Calculator size={14}/> Co když…
            </button>

            {otevrene && (
                <div className="mt-2 rounded-xl border border-dashed border-[var(--color-accent)] p-3">
                    <p className="mb-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                        Zkusmý výpočet. Nic se neuloží a rozpočet se nezmění.
                    </p>

                    <div className="grid gap-2 sm:grid-cols-3">
                        <div>
                            <label className={POPISEK} htmlFor={`cokdyz-den-${r.uuid}`}>Denně utratíme</label>
                            <input id={`cokdyz-den-${r.uuid}`} type="text" inputMode="decimal" value={denne}
                                onChange={e => setDenne(e.target.value)} placeholder="30"
                                className={`${POLE} !py-2 tabular-nums`}/>
                        </div>
                        <div>
                            <label className={POPISEK} htmlFor={`cokdyz-mimo-${r.uuid}`}>Navíc jednorázově</label>
                            <input id={`cokdyz-mimo-${r.uuid}`} type="text" inputMode="decimal" value={mimoradny}
                                onChange={e => setMimoradny(e.target.value)} placeholder="0"
                                className={`${POLE} !py-2 tabular-nums`}/>
                        </div>
                        <div>
                            <label className={POPISEK} htmlFor={`cokdyz-rez-${r.uuid}`}>Rezerva navíc</label>
                            <input id={`cokdyz-rez-${r.uuid}`} type="text" inputMode="decimal" value={rezervaNavic}
                                onChange={e => setRezervaNavic(e.target.value)} placeholder="0"
                                className={`${POLE} !py-2 tabular-nums`}/>
                        </div>
                    </div>

                    {vysledek && (
                        <div className="mt-3 border-t border-[var(--color-border)] pt-2">
                            <p className="text-sm text-[var(--color-text-primary)]">
                                Na konci by {vysledek.prekroci ? 'chybělo' : 'zbylo'}{' '}
                                <strong className="tabular-nums"
                                    style={{ color: vysledek.prekroci ? 'var(--fin-vydaj)' : 'var(--fin-prijem)' }}>
                                    {penize(Math.abs(vysledek.zbude), r.currency)}
                                </strong>.
                            </p>
                            <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                Za {dny(vysledek.dni)} by to dalo {penize(vysledek.vydaje, r.currency)} výdajů.
                                {vysledek.prekroci && vysledek.doporucene > 0 && (
                                    <> Aby to vyšlo, muselo by se utrácet nejvýš{' '}
                                    <strong className="text-[var(--color-text-primary)]">
                                        {penize(vysledek.doporucene, r.currency)}
                                    </strong>{' '}denně.</>
                                )}
                            </p>

                            <button type="button" onClick={zrusit}
                                className="mt-2 inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]">
                                <RotateCcw size={13}/> Zrušit simulaci
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
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
        // Nový rozpočet začíná s rezervou z předvoleb — kdo si ji jednou nastavil,
        // nemusí ji psát u každého dalšího.
        reserve_amount: rozpocet?.reserve
            ? String(rozpocet.reserve)
            : (ciselniky.settings?.default_reserve ? String(ciselniky.settings.default_reserve) : ''),
        trip_uuid: rozpocet?.trip?.uuid ?? '',
        owner_user_id: rozpocet ? (rozpocet.owner_user_id ?? 0) : 0,
        income_adds: rozpocet ? rozpocet.income_adds : false,
        auto_balance: rozpocet ? rozpocet.auto_balance : true,
    });

    // Komu je rozpočet nasdílený. Drží se stranou od `form`, protože se ukládá vlastním
    // požadavkem — nový rozpočet ještě nemá uuid, pod kterým by šlo sdílení zapsat.
    const [sdileni, setSdileni] = useState<Record<number, 'ne' | 'cist' | 'psat'>>(() =>
        Object.fromEntries((rozpocet?.access ?? []).map(p => [p.user_id, p.can_edit ? 'psat' : 'cist'])));

    const [limity, setLimity] = useState<Record<string, string>>(() =>
        Object.fromEntries((rozpocet?.categories ?? []).map(k => [k.category_uuid, String(k.planned)])));

    // Pořadí důležitosti. Drží se odděleně od částek, protože se mění samostatně —
    // přesunout položku výš je jiné rozhodnutí než změnit, kolik na ni jde.
    const [poradi, setPoradi] = useState<Record<string, number>>(() =>
        Object.fromEntries((rozpocet?.categories ?? []).map(k => [k.category_uuid, k.priority])));

    const [uklada, setUklada] = useState(false);
    const [chyba, setChyba] = useState('');
    const [mazani, setMazani] = useState(false);

    const kategorie = ciselniky.categories.filter(k => k.kind === 'expense');
    const soucetLimitu = Object.values(limity).reduce((s, v) => s + (prectiCastku(v) ?? 0), 0);
    const strop = prectiCastku(form.amount) ?? 0;

    const uloz = async () => {
        setUklada(true);
        setChyba('');

        const pristupy = Object.entries(sdileni)
            .filter(([, v]) => v !== 'ne')
            .map(([id, v]) => ({ user_id: Number(id), can_edit: v === 'psat' }));

        const telo = {
            name: form.name,
            budget_kind: form.budget_kind,
            currency: form.currency,
            amount: prectiCastku(form.amount),
            reserve_amount: prectiCastku(form.reserve_amount),
            trip_uuid: form.budget_kind === 'trip' ? form.trip_uuid : '',
            owner_user_id: form.owner_user_id || null,
            access: pristupy,
            income_adds: form.income_adds,
            auto_balance: form.auto_balance,
            limits: Object.entries(limity)
                .map(([uuid, v]) => ({
                    category_uuid: uuid,
                    amount: prectiCastku(v) ?? 0,
                    priority: poradi[uuid] ?? 100,
                }))
                .filter(l => l.amount > 0),
        };

        try {
            if (rozpocet) {
                await axios.patch(`/api/v1/rozpocet/rozpocty/${rozpocet.uuid}`, telo);

                // Vlastník a přístupy mají vlastní koncový bod — úprava rozpočtu je
                // něco jiného než změna toho, kdo do něj smí.
                await axios.post(`/api/v1/rozpocet/rozpocty/${rozpocet.uuid}/sdileni`, {
                    owner_user_id: form.owner_user_id || null, access: pristupy,
                });
            } else {
                // Při zakládání jedním požadavkem: kdo rozpočet rovnou předá druhému,
                // ztratí k němu právo a druhý požadavek by skončil chybou.
                await axios.post('/api/v1/rozpocet/rozpocty', telo);
            }

            hlaska(rozpocet ? 'Rozpočet je upravený.' : 'Rozpočet je založený.', 'uspech');
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

                <div>
                    <span className={POPISEK}>Na co je</span>
                    <div className="grid grid-cols-2 gap-2">
                        {([['monthly', 'Každý měsíc'], ['trip', 'Na jednu cestu']] as const).map(([klic, popis]) => (
                            <button key={klic} type="button"
                                onClick={() => setForm(f => ({
                                    ...f,
                                    budget_kind: klic,
                                    // U nového rozpočtu jde volba s druhem: cesta se jede
                                    // s pevnou sumou, tam je příjem navíc. Rozpočet, který
                                    // už existuje, si svoje nastavení nechá.
                                    income_adds: rozpocet ? f.income_adds : klic === 'trip',
                                }))}
                                aria-pressed={form.budget_kind === klic}
                                className={`min-h-11 rounded-xl border px-3 text-sm ${form.budget_kind === klic
                                    ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 text-[var(--color-text-primary)]'
                                    : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}>
                                {popis}
                            </button>
                        ))}
                    </div>
                </div>

                {form.budget_kind === 'trip' && (
                    <div>
                        <label className={POPISEK} htmlFor="rozp-cesta">Která cesta</label>
                        <select id="rozp-cesta" value={form.trip_uuid} className={POLE}
                            onChange={e => setForm(f => ({ ...f, trip_uuid: e.target.value }))}>
                            <option value="">— vyberte cestu —</option>
                            {ciselniky.trips.map(c => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
                        </select>
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            Období si rozpočet vezme z cesty a počítá jen útraty, které k ní patří.
                        </p>
                        {ciselniky.trips.length === 0 && (
                            <p className="mt-1 text-[11px] leading-relaxed text-amber-400">
                                Zatím není žádná cesta. Nejdřív ji založte v záložce Cesty.
                            </p>
                        )}
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label className={POPISEK} htmlFor="rozp-mena">Měna</label>
                        <select id="rozp-mena" value={form.currency}
                            onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={POLE}>
                            {['EUR', 'CZK'].map(m => <option key={m} value={m}>{m}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="rozp-castka">
                            {form.budget_kind === 'monthly' ? 'Kolik měsíčně' : 'Kolik na celou cestu'}
                        </label>
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

                {form.budget_kind === 'monthly' && (
                    <p className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                        Měsíční rozpočet měří vždycky <strong className="text-[var(--color-text-primary)]">aktuální měsíc</strong>{' '}
                        a každý první se posune sám. Nemusíte ho zakládat znovu.
                    </p>
                )}

                {/* Kdo rozpočet vlastní a kdo do něj vidí.
                    Společný je výchozí — je to dosavadní stav a nejčastější případ. Vlastní
                    dává smysl tam, kde se dvě situace nemají sčítat: cesta do Německa a život
                    v Česku jsou dvě peněženky, ne jeden součet. */}
                <div className="border-t border-[var(--color-border)] pt-3">
                    <label className={POPISEK} htmlFor="rozp-vlastnik">Pro koho je</label>
                    <select id="rozp-vlastnik" value={form.owner_user_id} className={POLE}
                        onChange={e => setForm(f => ({ ...f, owner_user_id: Number(e.target.value) }))}>
                        <option value={0}>Společný — vidí ho oba</option>
                        {ciselniky.members.map(c => (
                            <option key={c.id} value={c.id}>
                                {c.id === ciselniky.me ? `Můj (${c.name})` : c.name}
                            </option>
                        ))}
                    </select>

                    {form.owner_user_id !== 0 && (
                        <div className="mt-2">
                            <p className="text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                Vlastní rozpočet vidí jen majitel. Komu ho ještě ukázat:
                            </p>
                            <ul className="mt-1.5 space-y-1.5">
                                {ciselniky.members.filter(c => c.id !== form.owner_user_id).map(c => (
                                    <li key={c.id} className="flex items-center gap-2">
                                        <span className="min-w-0 flex-1 truncate text-xs text-[var(--color-text-primary)]">{c.name}</span>
                                        <select value={sdileni[c.id] ?? 'ne'}
                                            aria-label={`Přístup k rozpočtu — ${c.name}`}
                                            onChange={e => setSdileni(s => ({ ...s, [c.id]: e.target.value as 'ne' | 'cist' | 'psat' }))}
                                            className="shrink-0 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1.5 text-xs text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none">
                                            <option value="ne">nevidí</option>
                                            <option value="cist">jen se dívá</option>
                                            <option value="psat">může i měnit</option>
                                        </select>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-1.5 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                Skrývá se rozpočet, ne zapsané útraty — ty zůstávají v knize vidět oběma.
                            </p>
                        </div>
                    )}
                </div>

                <div className="border-t border-[var(--color-border)] pt-3">
                    <p className={POPISEK}>Na co vyhradit peníze (nepovinné)</p>
                    <p className="-mt-1 mb-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                        Pořadí rozhoduje, až peníze nevyjdou: pokrývá se odshora, takže nutné
                        věci jsou celé dřív, než dojde na to, co počká.
                    </p>
                    <ul className="space-y-1.5">
                        {kategorie.map(k => (
                            <li key={k.uuid} className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span className="flex min-w-0 flex-1 basis-32 items-center gap-1.5">
                                    <span className="h-2 w-2 shrink-0 rounded-full"
                                        style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                                    <span className="truncate text-xs text-[var(--color-text-primary)]">{k.name}</span>
                                </span>
                                {/* Pořadí se nabízí jen tam, kde je co řadit. U prázdné
                                    částky by to byla volba bez následku. */}
                                {(prectiCastku(limity[k.uuid] ?? '') ?? 0) > 0 && (
                                    <select value={poradi[k.uuid] ?? 50}
                                        aria-label={`Důležitost — ${k.name}`}
                                        onChange={e => setPoradi(p => ({ ...p, [k.uuid]: Number(e.target.value) }))}
                                        className="shrink-0 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1.5 text-xs text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none">
                                        <option value={10}>nutné</option>
                                        <option value={50}>důležité</option>
                                        <option value={90}>když zbyde</option>
                                    </select>
                                )}
                                <input type="text" inputMode="decimal" value={limity[k.uuid] ?? ''}
                                    onChange={e => setLimity(l => ({ ...l, [k.uuid]: e.target.value }))}
                                    aria-label={`Vyhrazeno na ${k.name}`}
                                    placeholder="—"
                                    className="w-24 shrink-0 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-2 py-1.5 text-right text-sm tabular-nums text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none"/>
                            </li>
                        ))}
                    </ul>

                    {/* Vyhradit víc, než kolik je, není chyba — dá se to. Ale musí být
                        vidět, že na poslední položky v pořadí peníze nezbudou. */}
                    {soucetLimitu > 0 && strop > 0 && soucetLimitu > strop && (
                        <p className="mt-2 text-[11px] leading-relaxed text-amber-400">
                            Vyhrazeno je {penize(soucetLimitu, form.currency)}, k dispozici{' '}
                            {penize(strop, form.currency)}. Pokrývá se odshora, takže na to,
                            co je na konci pořadí, peníze nezbudou.
                        </p>
                    )}
                </div>

                {/* Co s příjmem, který v období přibude.
                    U cesty s pevnou sumou je každý příjem opravdu navíc. U měsíčního
                    rozpočtu je výplata sám ten rozpočet a přičíst ji by znamenalo počítat
                    s dvojnásobkem — tichá chyba, která se projeví, až peníze dojdou dřív. */}
                {/* Vyrovnávat plán sám od sebe.
                    Ruční přepočítávání nikdo nedělá a plán tím zestárne. Nic se přitom
                    neztrácí — původní odhad zůstává uložený a je pořád vidět u každé
                    položky, takže se dá kdykoli poznat, oč se skutečnost rozešla. */}
                <label className="flex items-start gap-2 border-t border-[var(--color-border)] pt-3 text-sm text-[var(--color-text-primary)]">
                    <input type="checkbox" checked={form.auto_balance} className="mt-1 h-4 w-4"
                        onChange={e => setForm(f => ({ ...f, auto_balance: e.target.checked }))}/>
                    <span>
                        Vyrovnávat plán podle skutečnosti
                        <span className="block text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            {form.auto_balance
                                ? 'Co jedna kategorie podle tempa nevyčerpá, se ukáže tam, kde chybí. Původní odhad zůstává vidět.'
                                : 'Plán zůstane, jak je zadaný. Přerozdělit se dá ručně tlačítkem u tabulky.'}
                        </span>
                    </span>
                </label>

                <label className="flex items-start gap-2 text-sm text-[var(--color-text-primary)]">
                    <input type="checkbox" checked={form.income_adds} className="mt-1 h-4 w-4"
                        onChange={e => setForm(f => ({ ...f, income_adds: e.target.checked }))}/>
                    <span>
                        Zapsaný příjem přičíst k rozpočtu
                        <span className="block text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            {form.income_adds
                                ? 'Co v období přijde, se rozdělí samo — odshora podle pořadí.'
                                : 'Rozpočet zůstane pevná částka. Vhodné tam, kde je výplata sám ten rozpočet — jinak by se počítala dvakrát.'}
                        </span>
                    </span>
                </label>

                {chyba && (
                    <p className="rounded-xl border border-red-500/40 bg-[var(--color-surface-muted)] p-3 text-xs leading-relaxed text-[var(--color-text-primary)]">
                        {chyba}
                    </p>
                )}

                <div className="flex gap-2 border-t border-[var(--color-border)] pt-3">
                    <button type="button" onClick={() => void uloz()}
                        disabled={uklada || ! form.name || ! strop || (form.budget_kind === 'trip' && ! form.trip_uuid)}
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
