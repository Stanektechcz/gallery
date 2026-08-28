import Panel from '@/Components/Panel';
import { datum, kurz, penize, penizeKratce } from '@/lib/penize';
import axios from 'axios';
import { ArrowRightLeft, Award, Coins, Plus, TrendingUp, Wallet } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { Kurz } from './typy';

type Smena = {
    uuid: string; occurred_at: string; provider: string | null;
    from: { name: string | null; amount: number; currency: string };
    to: { name: string | null; amount: number; currency: string };
    trip: string | null;
    rate: Kurz | null;
};

type Poskytovatel = {
    name: string; count: number; volume: number; volume_currency: string;
    received: number; average_rate: number | null; fees: number;
    eur_per_1000_czk: number | null;
    comparable: boolean; is_best: boolean; is_worst: boolean;
};

type Data = {
    acquisition: {
        held_eur: number; known_eur: number; unknown_eur: number;
        cost_czk: number; average_rate: number | null; has_unknown: boolean;
    };
    period: { label: string; from: string; to: string | null };
    period_volume: Array<{ currency: string; amount: number; count: number }>;
    period_fees: Array<{ currency: string; amount: number }>;
    providers: Poskytovatel[];
    exchanges: Smena[];
    count: number;
};

/**
 * Směny — kolik nás eura doopravdy stála.
 *
 * Celý tab stojí na rozdílu mezi nabízeným a skutečným kurzem. Poskytovatel, který
 * slibuje 24,00 a vezme si pětistovku, vyjde hůř než ten s 24,20 bez poplatku — a
 * z reklamního čísla to poznat nejde. Proto se všude počítá jen s tím, kolik eur
 * doopravdy přišlo.
 */
export default function Smeny({ obdobi, onPridat }: { obdobi: string; onPridat: () => void }) {
    const [data, setData] = useState<Data | null>(null);
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');

    const nacti = useCallback(async () => {
        setNacita(true);

        try {
            const odpoved = await axios.get<Data>('/api/v1/rozpocet/smeny', { params: { obdobi } });
            setData(odpoved.data);
            setChyba('');
        } catch {
            setChyba('Směny se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, [obdobi]);

    useEffect(() => { void nacti(); }, [nacti]);

    if (nacita) {
        return (
            <div className="grid gap-3 lg:grid-cols-2" aria-busy="true" aria-label="Načítám">
                {[0, 1, 2, 3].map(i => (
                    <div key={i} className="h-32 animate-pulse rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface-muted)]"/>
                ))}
            </div>
        );
    }

    if (chyba) {
        return (
            <div className="rounded-2xl border border-red-500/40 p-4">
                <p className="text-sm text-[var(--color-text-primary)]">{chyba}</p>
                <button type="button" onClick={() => void nacti()}
                    className="mt-2 min-h-11 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-primary)]">
                    Zkusit znovu
                </button>
            </div>
        );
    }

    if (! data || data.count === 0) {
        return (
            <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center">
                <p className="text-sm text-[var(--color-text-primary)]">Zatím žádná směna</p>
                <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    Až tu nějaká bude, uvidíte skutečný kurz včetně poplatků — a po pár směnách i to,
                    který způsob vám doopravdy vychází nejlíp. Ne podle nabízeného kurzu, ale podle
                    toho, kolik eur nakonec přišlo.
                </p>
                <button type="button" onClick={onPridat}
                    className="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                    <Plus size={16}/> Zapsat směnu
                </button>
            </div>
        );
    }

    const p = data.acquisition;

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-2.5 xl:grid-cols-4">
                <Kpi label="Držíme" hodnota={penizeKratce(p.held_eur, 'EUR')} ikona={Wallet}
                    popisek={p.has_unknown ? `z toho ${penize(p.unknown_eur, 'EUR')} s neznámou cenou` : 'na eurových účtech'}/>
                <Kpi label="Pořízeno průměrně za"
                    hodnota={p.average_rate !== null ? `${kurz(p.average_rate)} Kč` : '—'}
                    ikona={TrendingUp}
                    popisek={p.average_rate !== null ? 'za 1 € · vážený průměr' : 'zatím není z čeho počítat'}/>
                <Kpi label={`Směněno · ${data.period.label.toLowerCase()}`}
                    hodnota={data.period_volume[0] ? penizeKratce(data.period_volume[0].amount, data.period_volume[0].currency) : '—'}
                    ikona={ArrowRightLeft}
                    popisek={data.period_volume[0] ? `${data.period_volume[0].count}×` : 'žádná směna'}/>
                <Kpi label="Poplatky"
                    hodnota={data.period_fees[0] ? penizeKratce(data.period_fees[0].amount, data.period_fees[0].currency) : penize(0, 'CZK')}
                    ikona={Coins}
                    popisek={data.period.label.toLowerCase()}/>
            </div>

            <div className="grid gap-3 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Panel icon={TrendingUp} title="Vývoj skutečného kurzu"
                        description="Každý bod je jedna směna — kolik korun stálo euro po poplatcích."
                        footnote="Vodorovná čára je vážený průměr toho, co teď držíme.">
                        <GrafKurzu smeny={data.exchanges} prumer={p.average_rate}/>
                    </Panel>
                </div>

                <Panel icon={Award} title="Kde se vyplatí"
                    description={data.providers.length > 1 ? 'Seřazeno podle skutečně získaných eur, ne podle nabízeného kurzu.' : undefined}
                    footnote={data.providers.some(x => ! x.comparable)
                        ? 'Kdo má zatím jedinou směnu, není označený jako nejlepší — z jednoho případu závěr neplyne, mohl padnout na dobrý den.'
                        : undefined}>
                    {data.providers.length === 0 ? (
                        <p className="text-xs text-[var(--color-text-secondary)]">Zatím není co porovnávat.</p>
                    ) : (
                        <ul className="space-y-2">
                            {data.providers.map(x => (
                                <li key={x.name} className="rounded-xl border border-[var(--color-border)] p-2.5">
                                    <div className="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                                        <span className="flex items-center gap-1.5 text-sm text-[var(--color-text-primary)]">
                                            {x.name}
                                            {x.is_best && (
                                                <span className="rounded-full px-1.5 text-[10px] text-white" style={{ background: 'var(--fin-prijem)' }}>
                                                    nejlepší
                                                </span>
                                            )}
                                            {/* Štítek „nejdražší" tu schválně není. Seznam je řazený od
                                                nejlepšího kurzu, takže nejdražší je vidět dole i bez
                                                označení — a označit ho nejde poctivě: poskytovatel
                                                s jedinou směnou se neporovnává, takže by štítek visel
                                                u někoho, nad kým je vidět horší číslo. */}
                                            {! x.comparable && (
                                                <span className="rounded-full border border-[var(--color-border)] px-1.5 text-[10px] text-[var(--color-text-secondary)]">
                                                    zatím 1×
                                                </span>
                                            )}
                                        </span>
                                        <span className="shrink-0 tabular-nums text-sm text-[var(--color-text-primary)]">
                                            {x.average_rate !== null ? `${kurz(x.average_rate)} Kč/€` : '—'}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                                        {x.count}× · celkem {penizeKratce(x.volume, x.volume_currency)}
                                        {x.fees > 0 && ` · poplatky ${penize(x.fees, x.volume_currency)}`}
                                        {x.eur_per_1000_czk && ` · ${kurz(x.eur_per_1000_czk)} € za 1 000 Kč`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            <Panel icon={ArrowRightLeft} title="Historie směn"
                description={`${data.count}× celkem`}
                actions={
                    <button type="button" onClick={onPridat}
                        className="inline-flex min-h-11 items-center gap-1 rounded-lg bg-[var(--color-accent)] px-3 text-sm font-medium text-[var(--color-accent-contrast)]">
                        <Plus size={15}/> Směna
                    </button>
                }>
                <ul className="divide-y divide-[var(--color-border)]">
                    {data.exchanges.map(s => (
                        <li key={s.uuid} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 py-2.5">
                            <div className="min-w-0">
                                <p className="text-sm text-[var(--color-text-primary)]">
                                    {penize(s.from.amount, s.from.currency)} → {penize(s.to.amount, s.to.currency)}
                                </p>
                                <p className="truncate text-[11px] text-[var(--color-text-secondary)]">
                                    {datum(s.occurred_at)}
                                    {s.provider && ` · ${s.provider}`}
                                    {s.rate && s.rate.fee > 0 && s.rate.fee_currency &&
                                        ` · poplatek ${penize(s.rate.fee, s.rate.fee_currency)}`}
                                    {s.trip && ` · ${s.trip}`}
                                </p>
                            </div>
                            {s.rate && (
                                <div className="shrink-0 text-right">
                                    <p className="text-sm font-medium tabular-nums text-[var(--color-text-primary)]">
                                        {kurz(s.rate.effective)} Kč/€
                                    </p>
                                    {s.rate.fee > 0 && (
                                        <p className="text-[10px] tabular-nums text-[var(--color-text-secondary)]">
                                            bez poplatku {kurz(s.rate.nominal)}
                                        </p>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </Panel>
        </div>
    );
}

/**
 * Vývoj skutečného kurzu v čase.
 *
 * Text je mimo SVG. Popisky uvnitř `viewBox` se škálují spolu s grafem a na telefonu
 * z desetibodového písma vyjde šestibodové.
 *
 * Osa nezačíná v nule schválně: kurzy se pohybují mezi 23 a 26 a od nuly by všechny
 * body splynuly do jedné čáry u horního okraje. U kurzu je zajímavý rozdíl, ne poměr
 * k nule — proto se ukazuje jen rozsah, ve kterém se opravdu pohybují, a krajní
 * hodnoty jsou vypsané, aby bylo poznat, jak je měřítko zvětšené.
 */
function GrafKurzu({ smeny, prumer }: { smeny: Smena[]; prumer: number | null }) {
    const body = [...smeny].reverse().filter(s => s.rate).map(s => ({
        datum: s.occurred_at,
        kurz: s.rate!.effective,
        poskytovatel: s.provider,
    }));

    const [vybrany, setVybrany] = useState<number | null>(null);

    if (body.length === 0) {
        return <p className="text-xs text-[var(--color-text-secondary)]">Zatím žádná směna s vypočteným kurzem.</p>;
    }

    if (body.length === 1) {
        // Jeden bod není vývoj. Předstírat čáru by naznačovalo trend, který neexistuje.
        return (
            <p className="text-sm text-[var(--color-text-primary)]">
                Zatím jedna směna — za 1 € <strong className="tabular-nums">{kurz(body[0].kurz)} Kč</strong>.
                <span className="mt-1 block text-[11px] text-[var(--color-text-secondary)]">
                    Vývoj se ukáže, až budou aspoň dvě. Z jednoho bodu trend nevyplývá.
                </span>
            </p>
        );
    }

    const hodnoty = body.map(b => b.kurz);
    const min = Math.min(...hodnoty);
    const max = Math.max(...hodnoty);
    const rozsah = Math.max(max - min, 0.5);
    const dolni = min - rozsah * 0.15;
    const horni = max + rozsah * 0.15;

    const y = (k: number) => 100 - ((k - dolni) / (horni - dolni)) * 100;
    const x = (i: number) => body.length > 1 ? (i / (body.length - 1)) * 100 : 50;

    const cara = body.map((b, i) => `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(2)} ${y(b.kurz).toFixed(2)}`).join(' ');
    const detail = vybrany !== null ? body[vybrany] : null;

    return (
        <div>
            <p className="mb-2 text-xs text-[var(--color-text-secondary)]">
                {detail ? (
                    <>
                        <strong className="text-[var(--color-text-primary)]">{datum(detail.datum)}</strong>
                        {' · '}<strong className="tabular-nums text-[var(--color-text-primary)]">{kurz(detail.kurz)} Kč/€</strong>
                        {detail.poskytovatel && ` · ${detail.poskytovatel}`}
                    </>
                ) : (
                    <>Nejlevněji {kurz(min)} Kč, nejdráž {kurz(max)} Kč za euro</>
                )}
            </p>

            <div className="relative" style={{ height: 150 }}>
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" className="h-full w-full" aria-hidden="true">
                    {prumer !== null && prumer >= dolni && prumer <= horni && (
                        <line x1="0" y1={y(prumer)} x2="100" y2={y(prumer)}
                            stroke="var(--color-text-secondary)" strokeWidth="1" strokeDasharray="3 3"
                            vectorEffect="non-scaling-stroke" opacity="0.5"/>
                    )}
                    <path d={cara} fill="none" stroke="var(--fin-smena)" strokeWidth="2"
                        vectorEffect="non-scaling-stroke" strokeLinejoin="round"/>
                    {body.map((b, i) => (
                        <circle key={i} cx={x(i)} cy={y(b.kurz)} r="4"
                            fill={vybrany === i ? 'var(--color-accent)' : 'var(--fin-smena)'}
                            vectorEffect="non-scaling-stroke"/>
                    ))}
                </svg>

                {/* Terče na dotyk jsou zvlášť a širší než body — čtyřpixelový kruh se
                    prstem netrefí. */}
                <div className="absolute inset-0 flex">
                    {body.map((b, i) => (
                        <button key={i} type="button"
                            onClick={() => setVybrany(vybrany === i ? null : i)}
                            aria-label={`${datum(b.datum)}: ${kurz(b.kurz)} Kč za euro`}
                            className="h-full flex-1"/>
                    ))}
                </div>
            </div>

            <div className="mt-1 flex items-baseline justify-between text-[10px] tabular-nums text-[var(--color-text-secondary)]">
                <span>{datum(body[0].datum)}</span>
                <span>{datum(body[body.length - 1].datum)}</span>
            </div>
            <p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">
                Svislá osa je zvětšená na rozsah {kurz(dolni)}–{kurz(horni)} Kč, aby byly rozdíly vidět.
            </p>
        </div>
    );
}

function Kpi({ label, hodnota, popisek, ikona: Ikona }: {
    label: string; hodnota: string; popisek: string; ikona: any;
}) {
    return (
        <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
            <p className="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                <Ikona size={12}/> {label}
            </p>
            <p className="mt-1 text-xl font-semibold tabular-nums text-[var(--color-text-primary)]">{hodnota}</p>
            <p className="mt-1 text-[11px] leading-tight text-[var(--color-text-secondary)]">{popisek}</p>
        </div>
    );
}
