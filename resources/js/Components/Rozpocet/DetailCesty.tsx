import Panel from '@/Components/Panel';
import { dny, transakce as pocetTransakci } from '@/lib/cestina';
import { datum, kurz, penize, penizeKratce, penizeZbyva, procenta } from '@/lib/penize';
import axios from 'axios';
import { ArrowRightLeft, ArrowUpRight, CalendarDays, Coins, MapPin, TrendingUp, Users, Wallet, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import JakSePocita from './JakSePocita';
import SloupceDnu from './SloupceDnu';

type Predpoved = {
    quality: 'not_started' | 'low' | 'rough' | 'stable';
    days_elapsed?: number; days_total?: number; pace?: number;
    expected_total: number | null; expected_left: number | null;
    runs_out_on: string | null; currency?: string;
};

type Data = {
    trip: {
        uuid: string; name: string; country: string | null; city: string | null;
        starts_on: string | null; ends_on: string | null;
        days_total: number | null; days_left: number | null;
        currency: string; budget: number | null; reserve: number | null;
        spent: number; remaining: number | null; percent: number | null;
        per_day_so_far: number | null;
        safe_daily: { state: string; per_day: number | null; days_left: number | null; over_by: number | null } | null;
        is_active: boolean; state: string; transactions: number;
    };
    daily: Array<{ date: string; amount: number }>;
    categories: Array<{ category_id: number | null; category_uuid: string | null; name: string; color: string | null; amount: number; percent: number; currency: string }>;
    partners: {
        by_currency: Array<{
            currency: string;
            partners: Array<{ partner_id: number; name: string; paid: number; owes: number; balance: number }>;
            settlement: Array<{ from: string; to: string; amount: number; currency: string }>;
        }>;
    };
    wallets: Array<{ name: string; kind: string; amount: number; count: number; currency: string }>;
    exchanges: Array<{ uuid: string; occurred_at: string; provider: string | null;
        from: { amount: number; currency: string }; to: { amount: number; currency: string };
        rate: { effective: number } | null }>;
    largest: Array<{ uuid: string; amount: number; currency: string; occurred_at: string; category: string | null; counterparty: string | null }>;
    prediction: Predpoved | null;
    transactions: number;
};

/**
 * Detail cesty — všechno o jednom pobytu na jedné obrazovce.
 *
 * Do teď bylo k dispozici jen shrnutí po ukončení. Jenže otázka „jak jsme na tom"
 * se klade v půlce pobytu, ne po návratu — tehdy se s odpovědí ještě dá něco dělat.
 */
export default function DetailCesty({ uuid, onZavrit, onTransakce }: {
    uuid: string;
    onZavrit: () => void;
    onTransakce: (filtr: Record<string, string>) => void;
}) {
    const [data, setData] = useState<Data | null>(null);
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');

    const nacti = useCallback(async () => {
        setNacita(true);

        try {
            const odpoved = await axios.get<Data>(`/api/v1/rozpocet/cesty/${uuid}/detail`);
            setData(odpoved.data);
            setChyba('');
        } catch {
            setChyba('Detail cesty se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, [uuid]);

    useEffect(() => { void nacti(); }, [nacti]);

    const c = data?.trip;
    const m = c?.currency ?? 'EUR';

    return (
        <div className="fixed inset-0 z-[950] flex items-end justify-center sm:items-center"
            role="dialog" aria-modal="true" aria-label="Detail cesty">
            <button type="button" aria-label="Zavřít" onClick={onZavrit} className="absolute inset-0 bg-black/50"/>

            <div className="relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] sm:max-w-3xl sm:rounded-2xl">
                <div className="flex shrink-0 items-start justify-between gap-2 border-b border-[var(--color-border)] p-4">
                    <div className="min-w-0">
                        <h2 className="flex items-center gap-1.5 truncate text-base font-semibold text-[var(--color-text-primary)]">
                            {c?.is_active && <MapPin size={16} className="shrink-0 text-[var(--color-accent)]"/>}
                            {c?.name ?? 'Cesta'}
                        </h2>
                        {c && (
                            <p className="mt-0.5 truncate text-xs text-[var(--color-text-secondary)]">
                                {[c.country, c.city].filter(Boolean).join(' · ')}
                                {c.starts_on && ` · ${datum(c.starts_on)}`}
                                {c.ends_on && ` – ${datum(c.ends_on)}`}
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
                            <div className="h-24 animate-pulse rounded-xl bg-[var(--color-surface-muted)]"/>
                            <div className="h-40 animate-pulse rounded-xl bg-[var(--color-surface-muted)]"/>
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

                    {data && c && ! nacita && (
                        <div className="space-y-3">
                            {/* Stav rozpočtu cesty. */}
                            {c.budget !== null ? (
                                <section className="rounded-2xl border border-[var(--color-border)] p-3">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="text-2xl font-semibold tabular-nums text-[var(--color-text-primary)]">
                                            {penizeZbyva(Math.abs(c.remaining ?? 0), m)}
                                        </span>
                                        <span className="shrink-0 text-[11px] text-[var(--color-text-secondary)]">
                                            {(c.percent ?? 0) > 100 ? 'nad limit' : 'zbývá'} · z {penize(c.budget, m)}
                                        </span>
                                    </div>

                                    <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                                        <div className="h-full rounded-full"
                                            style={{
                                                width: `${Math.min(100, c.percent ?? 0)}%`,
                                                background: (c.percent ?? 0) > 100 ? 'var(--fin-vydaj)'
                                                    : (c.percent ?? 0) >= 80 ? 'var(--fin-upozorneni)' : 'var(--fin-prijem)',
                                            }}/>
                                    </div>
                                    <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                        utraceno {penize(c.spent, m)} · {procenta(c.percent ?? 0)}
                                        {c.reserve ? ` · rezerva ${penize(c.reserve, m)}` : ''}
                                    </p>

                                    <dl className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        <Udaj popisek="Bezpečně na den" ikona={CalendarDays}
                                            hodnota={c.safe_daily?.per_day !== null && c.safe_daily?.per_day !== undefined
                                                ? penize(c.safe_daily.per_day, m)
                                                : c.safe_daily?.state === 'over'
                                                    ? `přesah ${penize(c.safe_daily.over_by ?? 0, m)}` : '—'}/>
                                        <Udaj popisek="Zatím průměrně" ikona={TrendingUp}
                                            hodnota={c.per_day_so_far !== null ? `${penize(c.per_day_so_far, m)}/den` : '—'}/>
                                        {/* Počet dní z výpočtu, ne kalendářní rozdíl.
                                            `days_left` na cestě znamená „kolik dní ještě
                                            přijde", ale denní částka dělí i dneškem —
                                            vedle sebe by to byla dvě čísla, ze kterých
                                            si nikdo nespočítá totéž. */}
                                        <Udaj popisek="Zbývá dní" ikona={CalendarDays}
                                            hodnota={c.safe_daily?.days_left !== null && c.safe_daily?.days_left !== undefined
                                                ? String(c.safe_daily.days_left)
                                                : c.days_left !== null ? String(c.days_left) : '—'}/>
                                        <Udaj popisek="Záznamů" ikona={Coins} hodnota={String(c.transactions)}/>
                                    </dl>

                                    {c.safe_daily?.per_day !== null && c.safe_daily?.per_day !== undefined && (
                                        <JakSePocita
                                            nadpis="Kolik se dá utratit denně do konce cesty"
                                            radky={[
                                                { popis: 'Rozpočet cesty', hodnota: penize(c.budget, m) },
                                                { popis: 'Už utraceno', hodnota: `− ${penize(c.spent, m)}` },
                                                ...(c.reserve ? [{ popis: 'Rezerva stranou', hodnota: `− ${penize(c.reserve, m)}` }] : []),
                                                { popis: `Na ${dny(c.safe_daily.days_left ?? 0)}`,
                                                    hodnota: penize(c.safe_daily.per_day, m), vysledek: true },
                                            ]}/>
                                    )}
                                </section>
                            ) : (
                                <p className="rounded-2xl border border-dashed border-[var(--color-border)] px-3 py-4 text-center text-xs leading-relaxed text-[var(--color-text-secondary)]">
                                    Cesta nemá rozpočet, takže se nepočítá, kolik zbývá ani kolik jde utratit
                                    za den. Utraceno zatím {penize(c.spent, m)}.
                                </p>
                            )}

                            {/* Předpověď. Kvalita se hlásí slovem, ne procentem. */}
                            {data.prediction && data.prediction.expected_total !== null && (
                                <Predikce p={data.prediction} mena={m} limit={c.budget}/>
                            )}

                            {data.daily.length > 0 && (
                                <Panel title="Denní útrata" description={`${m} · od začátku cesty`}>
                                    <SloupceDnu dny={data.daily} mena={m}
                                        onDen={den => { onZavrit(); onTransakce({ obdobi: 'vlastni', od: den, do: den, cesta: c.uuid }); }}/>
                                </Panel>
                            )}

                            <div className="grid gap-3 lg:grid-cols-2">
                                <Panel icon={Coins} title="Za co">
                                    {data.categories.length === 0
                                        ? <Prazdno text="Zatím žádné výdaje."/>
                                        : (
                                            <ul className="space-y-2">
                                                {data.categories.slice(0, 7).map(k => (
                                                    <li key={k.category_id ?? 'bez'}>
                                                        <button type="button"
                                                            onClick={() => { onZavrit(); onTransakce({ kategorie: k.category_uuid ?? '', cesta: c.uuid }); }}
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
                                                            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                                                                <div className="h-full rounded-full"
                                                                    style={{ width: `${k.percent}%`, background: k.color ?? 'var(--color-text-secondary)' }}/>
                                                            </div>
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                </Panel>

                                <Panel icon={ArrowUpRight} title="Nejvyšší výdaje">
                                    {data.largest.length === 0
                                        ? <Prazdno text="Zatím žádné výdaje."/>
                                        : (
                                            <ul className="divide-y divide-[var(--color-border)]">
                                                {data.largest.map(v => (
                                                    <li key={v.uuid} className="flex items-baseline justify-between gap-2 py-2">
                                                        <span className="min-w-0">
                                                            <span className="block truncate text-sm text-[var(--color-text-primary)]">
                                                                {v.counterparty ?? v.category ?? 'Výdaj'}
                                                            </span>
                                                            <span className="block text-[11px] text-[var(--color-text-secondary)]">
                                                                {datum(v.occurred_at)}
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

                                <Panel icon={Users} title="Adri a Maki">
                                    {data.partners.by_currency.length === 0
                                        ? <Prazdno text="Zatím není koho rozdělovat."/>
                                        : data.partners.by_currency.map(mena => (
                                            <div key={mena.currency}>
                                                {mena.settlement.length === 0
                                                    ? <p className="text-sm text-[var(--color-text-primary)]">Na téhle cestě máte vyrovnáno.</p>
                                                    : mena.settlement.map((v, i) => (
                                                        <p key={i} className="text-sm text-[var(--color-text-primary)]">
                                                            {v.from} dluží {v.to}{' '}
                                                            <strong className="tabular-nums">{penize(v.amount, v.currency)}</strong>
                                                        </p>
                                                    ))}
                                                <ul className="mt-1.5 space-y-0.5">
                                                    {mena.partners.map(p => (
                                                        <li key={p.partner_id} className="flex items-baseline justify-between gap-2 text-[11px] text-[var(--color-text-secondary)]">
                                                            <span>{p.name}</span>
                                                            <span className="shrink-0 tabular-nums">
                                                                zaplatil {penize(p.paid, mena.currency)} · nese {penize(p.owes, mena.currency)}
                                                            </span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        ))}
                                </Panel>

                                <Panel icon={Wallet} title="Odkud se platilo">
                                    {data.wallets.length === 0
                                        ? <Prazdno text="Zatím žádné výdaje."/>
                                        : (
                                            <ul className="space-y-1.5">
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
                            </div>

                            {data.exchanges.length > 0 && (
                                <Panel icon={ArrowRightLeft} title="Směny na téhle cestě">
                                    <ul className="divide-y divide-[var(--color-border)]">
                                        {data.exchanges.map(s => (
                                            <li key={s.uuid} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
                                                <span className="text-sm text-[var(--color-text-primary)]">
                                                    {penize(s.from.amount, s.from.currency)} → {penize(s.to.amount, s.to.currency)}
                                                </span>
                                                <span className="shrink-0 text-[11px] text-[var(--color-text-secondary)]">
                                                    {datum(s.occurred_at)}{s.provider && ` · ${s.provider}`}
                                                    {s.rate && ` · ${kurz(s.rate.effective)} Kč/€`}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </Panel>
                            )}

                            <button type="button"
                                onClick={() => { onZavrit(); onTransakce({ cesta: c.uuid }); }}
                                className="min-h-11 w-full rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]">
                                Všechny transakce cesty ({pocetTransakci(c.transactions)})
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * Předpověď konce cesty.
 *
 * Kvalita se hlásí slovem, ne procentem. Procento by naznačovalo přesnost, kterou
 * odhad ze čtyř dnů nemá — a lidé by podle něj rozhodovali.
 */
function Predikce({ p, mena, limit }: { p: Predpoved; mena: string; limit: number | null }) {
    const prekroci = limit !== null && (p.expected_total ?? 0) > limit;

    const kvalita = {
        not_started: 'cesta ještě nezačala',
        low: 'zatím málo dnů — spíš tušení než odhad',
        rough: 'orientační odhad z pár dnů',
        stable: 'odhad z dostatku dnů',
    }[p.quality];

    return (
        <section className={`rounded-2xl border p-3 ${prekroci ? 'border-amber-500/40' : 'border-[var(--color-border)]'}`}>
            <p className="text-xs font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                Podle dosavadního tempa
            </p>
            <p className="mt-1 text-sm text-[var(--color-text-primary)]">
                Do konce cesty vyjde celkem{' '}
                <strong className="tabular-nums">{penize(p.expected_total ?? 0, mena)}</strong>
                {limit !== null && (
                    prekroci
                        ? <> — o <strong className="tabular-nums" style={{ color: 'var(--fin-vydaj)' }}>
                            {penize(Math.abs(p.expected_left ?? 0), mena)}</strong> nad rozpočet.</>
                        : <> — zbude {penize(p.expected_left ?? 0, mena)}.</>
                )}
            </p>
            {p.runs_out_on && (
                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                    Při tomhle tempu peníze dojdou{' '}
                    <strong className="text-[var(--color-text-primary)]">{datum(p.runs_out_on)}</strong>.
                </p>
            )}
            <p className="mt-1.5 text-[11px] text-[var(--color-text-secondary)]">
                {kvalita}
                {p.days_elapsed !== undefined && ` · tempo ${penize(p.pace ?? 0, mena)}/den, spočteno z ${p.days_elapsed} dnů pobytu`}
            </p>
        </section>
    );
}

function Udaj({ popisek, hodnota, ikona: Ikona }: { popisek: string; hodnota: string; ikona: any }) {
    return (
        <div className="rounded-xl border border-[var(--color-border)] p-2.5">
            <dt className="flex items-center gap-1 text-[10px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                <Ikona size={11}/> {popisek}
            </dt>
            <dd className="mt-0.5 text-sm font-medium tabular-nums text-[var(--color-text-primary)]">{hodnota}</dd>
        </div>
    );
}

function Prazdno({ text }: { text: string }) {
    return (
        <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-4 text-center text-xs text-[var(--color-text-secondary)]">
            {text}
        </p>
    );
}
