import { transakce as pocetTransakci } from '@/lib/cestina';
import { datum, penize, penizeKratce } from '@/lib/penize';
import axios from 'axios';
import { ArrowDownLeft, ArrowUpRight, ArrowRightLeft, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type Bod = { date: string; balance: number };

type Pohyb = {
    uuid: string; occurred_at: string; type: string;
    direction: 'in' | 'out';
    amount: number; currency: string; fee: number;
    category: string | null; color: string | null;
    counterparty: string | null; description: string | null;
    other_side: string | null; trip: string | null;
};

type Data = {
    wallet: {
        uuid: string; name: string; kind: string; currency: string;
        owner: string | null; opening_balance: number; balance: number;
        is_active: boolean; transactions: number;
    };
    history: Bod[];
    period: { label: string; currency: string; in: number; out: number; net: number; count: number };
    recent: Pohyb[];
};

/**
 * Detail účtu — odkud se ten zůstatek vzal.
 *
 * Zůstatek se nikde nepřepisuje, počítá se z pohybů. Když nesedí se skutečností, je
 * to informace: někde chybí zápis. Tahle obrazovka je místo, kde se to dá dohledat —
 * křivka ukáže, kdy se to rozešlo, a seznam pod ní čím.
 */
export default function DetailUctu({ uuid, obdobi, onZavrit, onPrevod }: {
    uuid: string;
    obdobi: string;
    onZavrit: () => void;
    onPrevod: () => void;
}) {
    const [data, setData] = useState<Data | null>(null);
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');

    const nacti = useCallback(async () => {
        setNacita(true);

        try {
            const odpoved = await axios.get<Data>(`/api/v1/rozpocet/ucty/${uuid}`, { params: { obdobi } });
            setData(odpoved.data);
            setChyba('');
        } catch {
            setChyba('Detail účtu se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, [uuid, obdobi]);

    useEffect(() => { void nacti(); }, [nacti]);

    return (
        <div className="fixed inset-0 z-[950] flex items-end justify-center sm:items-center"
            role="dialog" aria-modal="true" aria-label="Detail účtu">
            <button type="button" aria-label="Zavřít" onClick={onZavrit} className="absolute inset-0 bg-black/50"/>

            <div className="relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] sm:max-w-2xl sm:rounded-2xl">
                <div className="flex shrink-0 items-start justify-between gap-2 border-b border-[var(--color-border)] p-4">
                    <div className="min-w-0">
                        <h2 className="truncate text-base font-semibold text-[var(--color-text-primary)]">
                            {data?.wallet.name ?? 'Účet'}
                        </h2>
                        {data && (
                            <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">
                                {data.wallet.currency}
                                {data.wallet.owner ? ` · ${data.wallet.owner}` : ' · společný'}
                                {' · '}{pocetTransakci(data.wallet.transactions)}
                            </p>
                        )}
                    </div>
                    <button type="button" onClick={onZavrit} aria-label="Zavřít"
                        className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)]">
                        <X size={18}/>
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    {nacita && (
                        <div className="space-y-3" aria-busy="true">
                            <div className="h-20 animate-pulse rounded-xl bg-[var(--color-surface-muted)]"/>
                            <div className="h-32 animate-pulse rounded-xl bg-[var(--color-surface-muted)]"/>
                        </div>
                    )}

                    {chyba && (
                        <div className="rounded-xl border border-red-500/40 p-3">
                            <p className="text-sm text-[var(--color-text-primary)]">{chyba}</p>
                            <button type="button" onClick={() => void nacti()}
                                className="mt-2 min-h-11 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]">
                                Zkusit znovu
                            </button>
                        </div>
                    )}

                    {data && ! nacita && (
                        <div className="space-y-4">
                            <div>
                                <p className={`text-3xl font-semibold tabular-nums ${
                                    data.wallet.balance < 0 ? 'text-red-400' : 'text-[var(--color-text-primary)]'
                                }`}>
                                    {penize(data.wallet.balance, data.wallet.currency)}
                                </p>
                                <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                                    počáteční stav {penize(data.wallet.opening_balance, data.wallet.currency)}
                                    {' · '}zbytek je součet zapsaných pohybů
                                </p>
                                {data.wallet.balance < 0 && (
                                    <p className="mt-2 rounded-xl border border-red-500/40 bg-[var(--color-surface-muted)] p-2.5 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                                        Účet je v mínusu. Buď chybí zápis příjmu nebo směny, nebo šel opravdu
                                        do mínusu — rozdíl srovnáte korekcí v seznamu účtů.
                                    </p>
                                )}
                            </div>

                            <KrivkaZustatku body={data.history} mena={data.wallet.currency}/>

                            <div className="grid grid-cols-3 gap-2">
                                <Udaj popisek="Přišlo" hodnota={penizeKratce(data.period.in, data.period.currency)}
                                    barva="var(--fin-prijem)"/>
                                <Udaj popisek="Odešlo" hodnota={penizeKratce(data.period.out, data.period.currency)}
                                    barva="var(--fin-vydaj)"/>
                                <Udaj popisek="Rozdíl" hodnota={penizeKratce(data.period.net, data.period.currency)}/>
                            </div>
                            <p className="-mt-2 text-[11px] text-[var(--color-text-secondary)]">
                                {data.period.label.toLowerCase()} · {pocetTransakci(data.period.count)}
                            </p>

                            <div>
                                <p className="mb-1.5 text-xs font-medium text-[var(--color-text-secondary)]">
                                    Poslední pohyby
                                </p>
                                {data.recent.length === 0 ? (
                                    <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs text-[var(--color-text-secondary)]">
                                        Na tomhle účtu zatím žádný pohyb není.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-[var(--color-border)]">
                                        {data.recent.map(p => (
                                            <li key={p.uuid} className="flex items-start gap-2.5 py-2">
                                                {p.direction === 'in'
                                                    ? <ArrowDownLeft size={15} className="mt-0.5 shrink-0" style={{ color: 'var(--fin-prijem)' }}/>
                                                    : p.type === 'exchange' || p.type === 'transfer'
                                                        ? <ArrowRightLeft size={15} className="mt-0.5 shrink-0" style={{ color: 'var(--fin-prevod)' }}/>
                                                        : <ArrowUpRight size={15} className="mt-0.5 shrink-0" style={{ color: 'var(--fin-vydaj)' }}/>}

                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm text-[var(--color-text-primary)]">
                                                        {p.category ?? p.counterparty ?? p.other_side ?? 'Pohyb'}
                                                    </span>
                                                    {/* Šipka místo předložky. „z CZK účet" je špatně
                                                        a „z CZK účtu" napsat nejde — název si uživatel
                                                        volí sám a druhý pád se z něj odvodit nedá.
                                                        Šipka řekne směr a skloňovat nepotřebuje. */}
                                                    <span className="block truncate text-[11px] text-[var(--color-text-secondary)]">
                                                        {datum(p.occurred_at)}
                                                        {p.other_side && ` · ${p.direction === 'in' ? '←' : '→'} ${p.other_side}`}
                                                        {p.trip && ` · ${p.trip}`}
                                                    </span>
                                                </span>

                                                <span className="shrink-0 text-right">
                                                    <span className="block text-sm tabular-nums"
                                                        style={{ color: p.direction === 'in' ? 'var(--fin-prijem)' : 'var(--color-text-primary)' }}>
                                                        {p.direction === 'in' ? '+' : '−'}{penize(p.amount, p.currency)}
                                                    </span>
                                                    {p.fee > 0 && (
                                                        <span className="block text-[10px] tabular-nums text-[var(--color-text-secondary)]">
                                                            +{penize(p.fee, p.currency)} poplatek
                                                        </span>
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    )}
                </div>

                <div className="shrink-0 border-t border-[var(--color-border)] p-4 pb-[calc(1rem+env(safe-area-inset-bottom,0px))]">
                    <button type="button" onClick={onPrevod}
                        className="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]">
                        <ArrowRightLeft size={15}/> Převod z tohohle účtu
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * Vývoj zůstatku za devadesát dnů.
 *
 * Osa nezačíná v nule schválně: zůstatek se pohybuje kolem jedné hodnoty a od nuly
 * by celá křivka splynula do rovné čáry u horního okraje. Rozsah je vypsaný pod
 * grafem, aby bylo poznat, že měřítko není od nuly.
 */
function KrivkaZustatku({ body, mena }: { body: Bod[]; mena: string }) {
    const [vybrany, setVybrany] = useState<number | null>(null);

    if (body.length < 2) {
        return <p className="text-xs text-[var(--color-text-secondary)]">Na křivku je zatím málo dnů.</p>;
    }

    const hodnoty = body.map(b => b.balance);
    const min = Math.min(...hodnoty);
    const max = Math.max(...hodnoty);
    const rozsah = Math.max(max - min, 1);
    const dolni = min - rozsah * 0.1;
    const horni = max + rozsah * 0.1;

    const y = (v: number) => 100 - ((v - dolni) / (horni - dolni)) * 100;
    const x = (i: number) => (i / (body.length - 1)) * 100;

    const cara = body.map((b, i) => `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(2)} ${y(b.balance).toFixed(2)}`).join(' ');
    const detail = vybrany !== null ? body[vybrany] : null;

    // Nula se kreslí, jen když je uvnitř rozsahu — u účtu, který šel do mínusu, je
    // to ta nejdůležitější čára v grafu.
    const nulaVRozsahu = dolni < 0 && horni > 0;

    return (
        <div>
            <p className="mb-1 text-xs text-[var(--color-text-secondary)]">
                {detail
                    ? <><strong className="text-[var(--color-text-primary)]">{datum(detail.date)}</strong>{' · '}
                        <strong className="tabular-nums text-[var(--color-text-primary)]">{penize(detail.balance, mena)}</strong></>
                    : <>Za 90 dní mezi {penizeKratce(min, mena)} a {penizeKratce(max, mena)}</>}
            </p>

            <div className="relative" style={{ height: 120 }}>
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" className="h-full w-full" aria-hidden="true">
                    {nulaVRozsahu && (
                        <line x1="0" y1={y(0)} x2="100" y2={y(0)}
                            stroke="var(--fin-vydaj)" strokeWidth="1" strokeDasharray="3 3"
                            vectorEffect="non-scaling-stroke" opacity="0.6"/>
                    )}
                    <path d={cara} fill="none" stroke="var(--color-graf-rada)" strokeWidth="2"
                        vectorEffect="non-scaling-stroke" strokeLinejoin="round"/>
                    {detail && vybrany !== null && (
                        <circle cx={x(vybrany)} cy={y(detail.balance)} r="4"
                            fill="var(--color-accent)" vectorEffect="non-scaling-stroke"/>
                    )}
                </svg>

                {/* Terče na dotyk zvlášť — devadesát bodů po jednom pixelu se prstem
                    netrefí, ale sloupec přes celou výšku ano. */}
                <div className="absolute inset-0 flex">
                    {body.map((b, i) => (
                        <button key={b.date} type="button"
                            onClick={() => setVybrany(vybrany === i ? null : i)}
                            aria-label={`${datum(b.date)}: ${penize(b.balance, mena)}`}
                            className="h-full flex-1"/>
                    ))}
                </div>
            </div>

            <div className="mt-1 flex items-baseline justify-between text-[10px] text-[var(--color-text-secondary)]">
                <span>{datum(body[0].date)}</span>
                <span>dnes</span>
            </div>
            <p className="mt-0.5 text-[10px] text-[var(--color-text-secondary)]">
                Svislá osa je zvětšená na {penizeKratce(dolni, mena)}–{penizeKratce(horni, mena)}, aby byly změny vidět.
            </p>
        </div>
    );
}

function Udaj({ popisek, hodnota, barva }: { popisek: string; hodnota: string; barva?: string }) {
    return (
        <div className="rounded-xl border border-[var(--color-border)] p-2.5">
            <p className="text-[10px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">{popisek}</p>
            <p className="mt-0.5 text-sm font-medium tabular-nums" style={barva ? { color: barva } : undefined}>
                {hodnota}
            </p>
        </div>
    );
}
