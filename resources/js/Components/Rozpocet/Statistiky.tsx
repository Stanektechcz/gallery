import Panel from '@/Components/Panel';
import { datum, kurz, penize, penizeKratce, procenta } from '@/lib/penize';
import axios from 'axios';
import {
    ArrowDownLeft, ArrowRightLeft, ArrowUpRight, BarChart3, CalendarDays,
    ChevronRight, Coins, TrendingDown, TrendingUp, Users, Wallet,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import SloupceDnu from './SloupceDnu';

type Bod = { key: string; income: number; expense: number; net: number };

type Data = {
    filter: { label: string; from: string; to: string | null; days: number | null };
    currency: string;
    currencies: string[];
    summary: { income: number; expense: number; net: number; per_day: number | null };
    previous: { income: number; expense: number; net: number; per_day: number | null; label: string } | null;
    by_currency: Array<{ currency: string; income: number; expense: number; fees: number; spent: number; net: number }>;
    flow: { step: 'den' | 'tyden' | 'mesic'; points: Bod[] };
    categories: Array<{ category_id: number | null; category_uuid: string | null; name: string; color: string | null; amount: number; percent: number; currency: string; count: number }>;
    daily: Array<{ date: string; amount: number }>;
    largest: Array<{ uuid: string; amount: number; currency: string; occurred_at: string; category: string | null; color: string | null; counterparty: string | null; description: string | null }>;
    partners: {
        partners: Array<{ partner_id: number; name: string; paid: number; owes: number; balance: number }>;
        settlement: Array<{ from: string; to: string; amount: number; currency: string }>;
        shared: number; currency: string;
    };
    wallets: Array<{ name: string; kind: string; amount: number; count: number; currency: string }>;
    exchange: {
        acquisition: { held_eur: number; average_rate: number | null; has_unknown: boolean; unknown_eur: number };
        fees: Array<{ currency: string; amount: number }>;
    };
    insights: Array<{
        key: string; category: string; text: string;
        now: number; before: number; currency: string;
        direction: 'up' | 'down'; filter: Record<string, string>;
    }>;
    transactions: number;
};

/**
 * Statistiky — vysvětlit vývoj, ne nakreslit hezké grafy.
 *
 * Všechno počítá týž filtr jako Přehled i Transakce, takže se čísla mezi taby
 * nemůžou rozejít. A všechno je v jedné měně: sečíst koruny s eury do jednoho grafu
 * by dalo tvar, který nic neznamená — proto se měna volí a ostatní se hlásí zvlášť.
 *
 * Z každého grafu se dá prokliknout na transakce, ze kterých vznikl. Číslo, u kterého
 * nejde zjistit, z čeho je, se stejně nedá použít.
 */
export default function Statistiky({ obdobi, onTransakce }: {
    obdobi: string;
    onTransakce: (filtr: Record<string, string>) => void;
}) {
    const [data, setData] = useState<Data | null>(null);
    const [mena, setMena] = useState('');
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');

    const nacti = useCallback(async () => {
        setNacita(true);

        try {
            const odpoved = await axios.get<Data>('/api/v1/rozpocet/statistiky', {
                params: { obdobi, mena: mena || undefined },
            });
            setData(odpoved.data);
            setChyba('');
        } catch {
            setChyba('Statistiky se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, [obdobi, mena]);

    useEffect(() => { void nacti(); }, [nacti]);

    if (nacita) {
        return (
            <div className="space-y-3" aria-busy="true" aria-label="Načítám">
                <div className="grid grid-cols-2 gap-2.5 xl:grid-cols-4">
                    {[0, 1, 2, 3].map(i => (
                        <div key={i} className="h-[5.5rem] animate-pulse rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface-muted)]"/>
                    ))}
                </div>
                <div className="h-64 animate-pulse rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface-muted)]"/>
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

    if (! data || data.transactions === 0) {
        return (
            <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center">
                <p className="text-sm text-[var(--color-text-primary)]">V tomhle období nic není</p>
                <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    Statistiky se počítají ze zapsaných útrat. Zkuste jiné období, nebo začněte
                    zapisovat — po pár dnech už bude co ukazovat.
                </p>
            </div>
        );
    }

    const s = data.summary;
    const m = data.currency;

    return (
        <div className="space-y-3">
            {/* Přepínač měny. Jen když je co přepínat. */}
            {data.currencies.length > 1 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-xs text-[var(--color-text-secondary)]">Měna:</span>
                    {data.currencies.map(c => (
                        <button key={c} type="button" onClick={() => setMena(c)}
                            aria-pressed={c === m}
                            className={`min-h-11 rounded-full border px-3.5 text-xs ${
                                c === m
                                    ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                    : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                            }`}>
                            {c}
                        </button>
                    ))}
                    <span className="text-[11px] text-[var(--color-text-secondary)]">
                        měny se nesčítají — každá se počítá zvlášť
                    </span>
                </div>
            )}

            <div className="grid grid-cols-2 gap-2.5 xl:grid-cols-4">
                <Kpi label="Příjmy" hodnota={penizeKratce(s.income, m)} ikona={ArrowDownLeft}
                    barva="var(--fin-prijem)"
                    zmena={data.previous ? zmena(s.income, data.previous.income) : null}
                    onClick={() => onTransakce({ typ: 'income' })}/>
                <Kpi label="Výdaje" hodnota={penizeKratce(s.expense, m)} ikona={ArrowUpRight}
                    barva="var(--fin-vydaj)"
                    zmena={data.previous ? zmena(s.expense, data.previous.expense) : null}
                    onClick={() => onTransakce({ typ: 'expense' })}/>
                {/* U rozdílu se procenta neukazují. Ze −275 na −226 je „o 18 % víc",
                    což je formálně pravda (−226 je větší číslo) a čte se přesně obráceně,
                    než jak to je: utratili jsme míň. Místo procent stojí předchozí
                    hodnota — z dvojice čísel je směr jasný bez počítání. */}
                <Kpi label="Rozdíl" hodnota={penizeKratce(s.net, m)} ikona={TrendingUp}
                    barva={s.net >= 0 ? 'var(--fin-prijem)' : 'var(--fin-vydaj)'}
                    popisek={data.previous ? `minule ${penizeKratce(data.previous.net, m)}` : 'příjmy minus výdaje'}/>
                <Kpi label="Průměrně za den"
                    hodnota={s.per_day !== null ? penize(s.per_day, m) : '—'}
                    ikona={CalendarDays}
                    popisek={data.filter.days ? `${data.filter.days} dní v období` : undefined}/>
            </div>

            {data.insights.length > 0 && (
                <Panel icon={BarChart3} title="Co se změnilo"
                    footnote="Srovnává se jen to, co má srovnatelný základ — kategorie, která minule neexistovala, nebo drobná částka se neporovnává.">
                    <ul className="space-y-1.5">
                        {data.insights.map(p => (
                            <li key={p.key}>
                                <button type="button" onClick={() => onTransakce(p.filter)}
                                    className="flex min-h-11 w-full items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-left transition-colors hover:border-[var(--color-accent)]">
                                    {p.direction === 'up'
                                        ? <TrendingUp size={15} className="shrink-0" style={{ color: 'var(--fin-vydaj)' }}/>
                                        : <TrendingDown size={15} className="shrink-0" style={{ color: 'var(--fin-prijem)' }}/>}
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm text-[var(--color-text-primary)]">
                                            <strong className="font-medium">{p.category}</strong>: {p.text}
                                        </span>
                                        <span className="block text-[11px] text-[var(--color-text-secondary)]">
                                            {penize(p.now, p.currency)} teď proti {penize(p.before, p.currency)} dřív
                                        </span>
                                    </span>
                                    <ChevronRight size={14} className="shrink-0 text-[var(--color-text-secondary)]"/>
                                </button>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            <div className="grid gap-3 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Panel icon={TrendingUp} title="Příjmy a výdaje v čase"
                        description={`${data.filter.label} · ${m} · po ${{ den: 'dnech', tyden: 'týdnech', mesic: 'měsících' }[data.flow.step]}`}>
                        <GrafToku body={data.flow.points} krok={data.flow.step} mena={m}/>
                    </Panel>
                </div>

                <Panel icon={Coins} title="Podle kategorií">
                    {data.categories.length === 0
                        ? <Prazdno text="Žádné výdaje k rozdělení."/>
                        : (
                            <ul className="space-y-2">
                                {data.categories.slice(0, 8).map(k => (
                                    <li key={k.category_id ?? 'bez'}>
                                        <button type="button"
                                            onClick={() => onTransakce({ kategorie: k.category_uuid ?? '' })}
                                            className="w-full text-left">
                                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                                <span className="flex min-w-0 items-center gap-1.5">
                                                    <span className="h-2.5 w-2.5 shrink-0 rounded-full"
                                                        style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                                                    <span className="truncate text-[var(--color-text-primary)]">{k.name}</span>
                                                </span>
                                                <span className="shrink-0 tabular-nums text-[var(--color-text-primary)]">
                                                    {penizeKratce(k.amount, k.currency)}
                                                </span>
                                            </div>
                                            <div className="mt-1 flex items-center gap-2">
                                                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                                                    <div className="h-full rounded-full"
                                                        style={{ width: `${k.percent}%`, background: k.color ?? 'var(--color-text-secondary)' }}/>
                                                </div>
                                                <span className="w-14 shrink-0 text-right text-[11px] tabular-nums text-[var(--color-text-secondary)]">
                                                    {procenta(k.percent)}
                                                </span>
                                            </div>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                </Panel>
            </div>

            <div className="grid gap-3 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Panel icon={TrendingDown} title="Výdaje po dnech" description={`${data.filter.label} · ${m}`}>
                        {data.daily.length > 0
                            ? <SloupceDnu dny={data.daily} mena={m}
                                onDen={den => onTransakce({ obdobi: 'vlastni', od: den, do: den })}/>
                            : <Prazdno text="Období nemá konec, takže se dny nedají vypsat."/>}
                    </Panel>
                </div>

                <Panel icon={ArrowUpRight} title="Největší výdaje">
                    {data.largest.length === 0
                        ? <Prazdno text="Žádné výdaje."/>
                        : (
                            <ul className="divide-y divide-[var(--color-border)]">
                                {data.largest.map(v => (
                                    <li key={v.uuid} className="flex items-baseline justify-between gap-2 py-2">
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm text-[var(--color-text-primary)]">
                                                {v.counterparty ?? v.category ?? v.description ?? 'Výdaj'}
                                            </span>
                                            <span className="block text-[11px] text-[var(--color-text-secondary)]">
                                                {datum(v.occurred_at)}{v.category && ` · ${v.category}`}
                                            </span>
                                        </span>
                                        <span className="shrink-0 tabular-nums text-sm text-[var(--color-text-primary)]">
                                            {penize(v.amount, v.currency)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                </Panel>
            </div>

            <div className="grid gap-3 lg:grid-cols-3">
                <Panel icon={Users} title="Adri a Maki"
                    footnote="„Zaplatil“ je, co odešlo z účtu. „Nese“ je podíl na výdaji. Rozdíl mezi nimi je dluh.">
                    {data.partners.partners.length === 0
                        ? <Prazdno text="Zatím není koho rozdělovat."/>
                        : (
                            <>
                                <ul className="space-y-2">
                                    {data.partners.partners.map(p => (
                                        <li key={p.partner_id}>
                                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                                <span className="text-[var(--color-text-primary)]">{p.name}</span>
                                                <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                                                    zaplatil {penizeKratce(p.paid, data.partners.currency)}
                                                </span>
                                            </div>
                                            <p className="text-[11px] tabular-nums text-[var(--color-text-secondary)]">
                                                nese {penize(p.owes, data.partners.currency)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                                {data.partners.shared > 0 && (
                                    <p className="mt-2 border-t border-[var(--color-border)] pt-2 text-[11px] text-[var(--color-text-secondary)]">
                                        Společných výdajů za {penize(data.partners.shared, data.partners.currency)}.
                                    </p>
                                )}
                                {data.partners.settlement.map((v, i) => (
                                    <p key={i} className="mt-1 text-sm text-[var(--color-text-primary)]">
                                        {v.from} dluží {v.to} <strong className="tabular-nums">{penize(v.amount, v.currency)}</strong>
                                    </p>
                                ))}
                            </>
                        )}
                </Panel>

                <Panel icon={Wallet} title="Odkud se platilo">
                    {data.wallets.length === 0
                        ? <Prazdno text="Žádné výdaje."/>
                        : (
                            <ul className="space-y-2">
                                {data.wallets.map(u => (
                                    <li key={u.name} className="flex items-baseline justify-between gap-2 text-sm">
                                        <span className="truncate text-[var(--color-text-primary)]">{u.name}</span>
                                        <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                                            {penizeKratce(u.amount, u.currency)} · {u.count}×
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                </Panel>

                <Panel icon={ArrowRightLeft} title="Kurzy a poplatky">
                    {data.exchange.acquisition.average_rate !== null ? (
                        <p className="text-sm text-[var(--color-text-primary)]">
                            Držená eura jsme pořídili průměrně za{' '}
                            <strong className="tabular-nums">{kurz(data.exchange.acquisition.average_rate)} Kč</strong>.
                        </p>
                    ) : (
                        <p className="text-xs text-[var(--color-text-secondary)]">Zatím žádná směna.</p>
                    )}
                    {data.exchange.acquisition.has_unknown && (
                        <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                            U {penize(data.exchange.acquisition.unknown_eur, 'EUR')} se pořizovací cena nezná —
                            do průměru nevstupují.
                        </p>
                    )}
                    {data.exchange.fees.length > 0 && (
                        <p className="mt-2 border-t border-[var(--color-border)] pt-2 text-xs text-[var(--color-text-secondary)]">
                            Na poplatcích padlo{' '}
                            <strong className="text-[var(--color-text-primary)]">
                                {data.exchange.fees.map(f => penize(f.amount, f.currency)).join(' + ')}
                            </strong>{' '}
                            za {data.filter.label.toLowerCase()}.
                        </p>
                    )}
                </Panel>
            </div>

            {data.by_currency.length > 1 && (
                <Panel icon={Coins} title="Po měnách"
                    description="Nesčítá se — kurz se v čase mění a jeden součet by předstíral přesnost, kterou nemá.">
                    <ul className="divide-y divide-[var(--color-border)]">
                        {data.by_currency.map(c => (
                            <li key={c.currency} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
                                <span className="text-sm font-medium text-[var(--color-text-primary)]">{c.currency}</span>
                                <span className="shrink-0 text-xs tabular-nums text-[var(--color-text-secondary)]">
                                    příjem {penize(c.income, c.currency)} · výdaj {penize(c.spent, c.currency)} ·
                                    rozdíl <strong className="text-[var(--color-text-primary)]">{penize(c.net, c.currency)}</strong>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </div>
    );
}

function zmena(ted: number, drive: number): { procenta: number; vic: boolean } | null {
    if (drive === 0 || Math.abs(drive) < 0.01) return null;

    const z = (ted - drive) / Math.abs(drive) * 100;

    return Math.abs(z) < 1 ? null : { procenta: Math.round(z), vic: z > 0 };
}

/**
 * Příjmy a výdaje vedle sebe.
 *
 * Dva sloupce na krok, ne jeden nad druhým: naskládané by naznačovaly součet, který
 * nedává smysl — příjem a výdaj se nesčítají, staví se proti sobě.
 */
function GrafToku({ body, krok, mena }: { body: Bod[]; krok: string; mena: string }) {
    const [vybrany, setVybrany] = useState<number | null>(null);

    if (body.length === 0) {
        return <p className="text-xs text-[var(--color-text-secondary)]">V tomhle období nic není.</p>;
    }

    const maximum = Math.max(...body.map(b => Math.max(b.income, b.expense)), 0);

    if (maximum <= 0) {
        return <p className="text-xs text-[var(--color-text-secondary)]">Žádné pohyby v {mena}.</p>;
    }

    const popis = (k: string) => {
        if (krok === 'mesic') return new Date(`${k}-01T12:00:00`).toLocaleDateString('cs-CZ', { month: 'short', year: '2-digit' });

        return new Date(`${k}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' });
    };

    const detail = vybrany !== null ? body[vybrany] : null;

    return (
        <div>
            <p className="mb-2 text-xs text-[var(--color-text-secondary)]">
                {detail ? (
                    <>
                        <strong className="text-[var(--color-text-primary)]">{popis(detail.key)}</strong>
                        {' · příjem '}<span style={{ color: 'var(--fin-prijem)' }}>{penize(detail.income, mena)}</span>
                        {' · výdaj '}<span style={{ color: 'var(--fin-vydaj)' }}>{penize(detail.expense, mena)}</span>
                    </>
                ) : (
                    <>
                        <span className="inline-block h-2 w-2 rounded-sm align-middle" style={{ background: 'var(--fin-prijem)' }}/> příjem
                        {'  '}
                        <span className="ml-2 inline-block h-2 w-2 rounded-sm align-middle" style={{ background: 'var(--fin-vydaj)' }}/> výdaj
                        {' · maximum '}{penizeKratce(maximum, mena)}
                    </>
                )}
            </p>

            <ul className="flex items-end gap-1" style={{ height: 170 }} role="list"
                aria-label={`Příjmy a výdaje po ${krok === 'den' ? 'dnech' : krok === 'tyden' ? 'týdnech' : 'měsících'} v ${mena}`}>
                {body.map((b, i) => (
                    <li key={b.key} className="flex h-full flex-1 items-end">
                        <button type="button" onClick={() => setVybrany(vybrany === i ? null : i)}
                            aria-label={`${popis(b.key)}: příjem ${penize(b.income, mena)}, výdaj ${penize(b.expense, mena)}`}
                            className="flex h-full w-full items-end justify-center gap-[2px]"
                            style={{ opacity: vybrany === null || vybrany === i ? 1 : 0.4 }}>
                            <span className="w-1/2 rounded-t-[2px]"
                                style={{ height: `${(b.income / maximum) * 100}%`, minHeight: b.income > 0 ? 2 : 0, background: 'var(--fin-prijem)' }}/>
                            <span className="w-1/2 rounded-t-[2px]"
                                style={{ height: `${(b.expense / maximum) * 100}%`, minHeight: b.expense > 0 ? 2 : 0, background: 'var(--fin-vydaj)' }}/>
                        </button>
                    </li>
                ))}
            </ul>

            <div className="mt-1 flex items-baseline justify-between text-[10px] text-[var(--color-text-secondary)]">
                <span>{popis(body[0].key)}</span>
                <span>{popis(body[body.length - 1].key)}</span>
            </div>
        </div>
    );
}

function Kpi({ label, hodnota, popisek, ikona: Ikona, barva, zmena: z, onClick }: {
    label: string; hodnota: string; popisek?: string; ikona: any; barva?: string;
    zmena?: { procenta: number; vic: boolean } | null;
    onClick?: () => void;
}) {
    const Obal = onClick ? 'button' : 'div';

    return (
        <Obal type={onClick ? 'button' : undefined} onClick={onClick}
            className={`rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3 text-left ${
                onClick ? 'transition-colors hover:border-[var(--color-accent)]' : ''
            }`}>
            <p className="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                <Ikona size={12} style={barva ? { color: barva } : undefined}/> {label}
            </p>
            <p className="mt-1 text-xl font-semibold tabular-nums text-[var(--color-text-primary)] sm:text-2xl">{hodnota}</p>
            <p className="mt-1 text-[11px] leading-tight text-[var(--color-text-secondary)]">
                {z ? `o ${Math.abs(z.procenta)} % ${z.vic ? 'víc' : 'míň'} než minule` : (popisek ?? 'proti minule beze změny')}
            </p>
        </Obal>
    );
}

function Prazdno({ text }: { text: string }) {
    return (
        <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs text-[var(--color-text-secondary)]">
            {text}
        </p>
    );
}
