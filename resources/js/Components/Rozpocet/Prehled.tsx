import Panel from '@/Components/Panel';
import { dny, nakupy } from '@/lib/cestina';
import { datum, kurz, penize, penizeKratce, penizeZbyva, procenta, zahlaviDne } from '@/lib/penize';
import {
    AlertTriangle, ArrowRightLeft, Banknote, CalendarDays, ChevronRight, Coins,
    Pencil, PiggyBank, Plus, TrendingDown, TrendingUp, Wallet,
} from 'lucide-react';
import { useMemo, type ReactNode } from 'react';
import JakSePocita from './JakSePocita';
import RadekPohybu from './RadekPohybu';
import SloupceDnu from './SloupceDnu';
import type { Prehled as PrehledData } from './typy';

/**
 * Přehled — čtyři otázky, na které má odpovědět dřív, než se člověk stihne zamyslet:
 * kolik máme, kolik jsme utratili, kolik zbývá a vydrží to do konce.
 *
 * Pořadí widgetů je na mobilu i na desktopu totéž, jen se jinak skládá. Čtyři KPI
 * jsou nahoře v mřížce, ne v posuvném carouselu — co je za okrajem, to se nečte.
 */
export default function Prehled({ data, naTab, naTransakce, onPridat, onUpravitPosledni }: {
    data: PrehledData;
    naTab: (tab: string) => void;
    naTransakce: (filtr: Record<string, string>) => void;
    onPridat: () => void;
    onUpravitPosledni: (uuid: string) => void;
}) {
    const mena = data.main_currency;
    const soucet = data.summary.find(s => s.currency === mena);
    const minule = data.previous.find(s => s.currency === mena);

    // Trend proti stejně dlouhému předchozímu období. Bez „stejně dlouhému" by
    // rozjetý měsíc proti celému minulému hlásil pokles, který se nestal.
    const trend = useMemo(() => {
        if (! soucet || ! minule || minule.spent <= 0) return null;

        const zmena = (soucet.spent - minule.spent) / minule.spent * 100;

        return { procenta: Math.round(zmena), vic: zmena > 0 };
    }, [soucet, minule]);

    const rozpocet = data.budget;
    const bezpecne = rozpocet?.safe_daily;

    const dnes = data.today;

    return (
        <div className="space-y-3">
            {/*
             * Dnešek úplně nahoře.
             *
             * „Kolik ještě dnes můžu" je otázka, kterou si člověk na cestě klade
             * několikrát denně, a odpověď na ni nemá být schovaná mezi grafy. Pruh
             * počítá vždycky dnešek, i když je vybrané jiné období — přepočítat ho
             * podle filtru by dalo číslo, které v tu chvíli nikoho nezajímá.
             */}
            <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3"
                aria-label="Dnešní stav">
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <div className="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
                        <button type="button" onClick={() => naTransakce({ obdobi: 'dnes' })}
                            className="text-left">
                            <span className="block text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                                Dnes utraceno
                            </span>
                            <span className="block text-xl font-semibold tabular-nums text-[var(--color-text-primary)]">
                                {penize(dnes.spent, dnes.currency)}
                            </span>
                        </button>

                        {dnes.daily_limit !== null && (
                            <span className="text-xs text-[var(--color-text-secondary)]">
                                {dnes.over_today !== null ? (
                                    <>
                                        o <strong style={{ color: 'var(--fin-vydaj)' }}>{penize(dnes.over_today, dnes.currency)}</strong>{' '}
                                        nad dnešní částkou {penize(dnes.daily_limit, dnes.currency)}
                                    </>
                                ) : (
                                    <>
                                        dnes ještě{' '}
                                        <strong className="text-[var(--color-text-primary)]">
                                            {penize(dnes.left_today ?? 0, dnes.currency)}
                                        </strong>{' '}
                                        z {penize(dnes.daily_limit, dnes.currency)}
                                    </>
                                )}
                            </span>
                        )}

                        {/* Nula v eurech vedle „1 nákup" vypadá jako chyba. Není: dnešní
                            nákup byl v korunách a tenhle panel počítá měnu rozpočtu.
                            Bez téhle věty si člověk myslí, že se zápis ztratil. */}
                        {dnes.count > 0 && (
                            <span className="text-[11px] text-[var(--color-text-secondary)]">
                                {nakupy(dnes.count)}
                                {dnes.spent === 0 && dnes.last && dnes.last.currency !== dnes.currency
                                    && `, ale v ${dnes.last.currency} — do částky v ${dnes.currency} se nepočítá`}
                            </span>
                        )}
                    </div>

                    <div className="flex shrink-0 items-center gap-2">
                        {/* Oprava posledního zápisu bez procházení historie — nejčastější
                            oprava je ta hned po uložení, kdy si člověk všimne překlepu. */}
                        {dnes.last && (
                            <button type="button" onClick={() => onUpravitPosledni(dnes.last!.uuid)}
                                className="inline-flex min-h-11 items-center gap-1.5 rounded-xl border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                                <Pencil size={13}/>
                                <span className="hidden sm:inline">{dnes.last.category ?? 'Poslední'} </span>
                                {penize(dnes.last.amount, dnes.last.currency)}
                            </button>
                        )}
                        <button type="button" onClick={onPridat}
                            className="inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                            <Plus size={16}/> Přidat
                        </button>
                    </div>
                </div>
            </section>

            {data.alerts.length > 0 && (
                <div className="space-y-2">
                    {data.alerts.slice(0, 3).map(u => (
                        <div key={u.key}
                            className={`rounded-2xl border p-3 ${u.tone === 'danger' ? 'border-red-500/40' : 'border-amber-500/40'} bg-[var(--color-surface-muted)]`}>
                            <p className="flex items-start gap-2 text-sm font-medium text-[var(--color-text-primary)]">
                                <AlertTriangle size={15} className={`mt-0.5 shrink-0 ${u.tone === 'danger' ? 'text-red-400' : 'text-amber-400'}`}/>
                                {u.title}
                            </p>
                            <p className="mt-1 pl-[1.4rem] text-xs leading-relaxed text-[var(--color-text-secondary)]">{u.body}</p>
                            {u.action && (
                                <button type="button"
                                    onClick={() => u.action!.filter ? naTransakce(u.action!.filter) : naTab(u.action!.tab)}
                                    className="mt-1.5 ml-[1.4rem] inline-flex min-h-9 items-center gap-1 rounded-lg border border-[var(--color-border)] px-2.5 text-xs text-[var(--color-text-primary)]">
                                    {u.action.label} <ChevronRight size={13}/>
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {/* Čtyři KPI. Na mobilu dva sloupce, na širokém čtyři. */}
            <div className="grid grid-cols-2 gap-2.5 xl:grid-cols-4">
                <Kpi
                    label="Utraceno"
                    hodnota={soucet ? penizeKratce(soucet.spent, mena) : penize(0, mena)}
                    ikona={TrendingDown}
                    popisek={trend
                        ? `${trend.vic ? 'o ' : 'o '}${Math.abs(trend.procenta)} % ${trend.vic ? 'víc' : 'míň'} než minule`
                        : data.filter.label.toLowerCase()}
                    onClick={() => naTransakce({ typ: 'expense' })}/>

                {rozpocet ? (
                    <Kpi
                        label="Zbývá z rozpočtu"
                        hodnota={penizeZbyva(rozpocet.remaining, rozpocet.currency)}
                        ikona={PiggyBank}
                        ton={rozpocet.state === 'over' ? 'danger' : rozpocet.state === 'near' ? 'warn' : 'plain'}
                        popisek={`vyčerpáno ${procenta(rozpocet.percent)}`}
                        pruh={Math.min(100, rozpocet.percent)}
                        onClick={() => naTab('rozpocty')}/>
                ) : (
                    <Kpi label="Rozpočet" hodnota="—" ikona={PiggyBank}
                        popisek="zatím žádný" onClick={() => naTab('rozpocty')}/>
                )}

                <Kpi
                    label="Bezpečně na den"
                    hodnota={bezpecne?.per_day !== null && bezpecne?.per_day !== undefined
                        ? penize(bezpecne.per_day, rozpocet!.currency)
                        : '—'}
                    ikona={CalendarDays}
                    ton={bezpecne?.state === 'over' ? 'danger' : 'plain'}
                    popisek={popisekDenni(bezpecne, rozpocet?.currency)}
                    vysvetleni={rozpocet && bezpecne?.per_day !== null && bezpecne?.per_day !== undefined ? (
                        <JakSePocita
                            nadpis="Kolik se dá utratit denně, aby rozpočet vydržel"
                            radky={[
                                { popis: 'Rozpočet', hodnota: penize(rozpocet.limit, rozpocet.currency) },
                                { popis: 'Už utraceno', hodnota: `− ${penize(rozpocet.spent, rozpocet.currency)}` },
                                ...(rozpocet.reserve > 0
                                    ? [{ popis: 'Rezerva stranou', hodnota: `− ${penize(rozpocet.reserve, rozpocet.currency)}` }]
                                    : []),
                                { popis: `Zbývá k rozdělení na ${dny(bezpecne.days_left ?? 0)}`,
                                    hodnota: penize(rozpocet.limit - rozpocet.spent - rozpocet.reserve, rozpocet.currency) },
                                { popis: 'Na jeden den', hodnota: penize(bezpecne.per_day, rozpocet.currency), vysledek: true },
                            ]}
                            poznamka="Dnešek se počítá jako celý den — poslední den období tedy zbývá jeden, ne nula."/>
                    ) : undefined}/>

                <Kpi
                    label="Máme k dispozici"
                    hodnota={data.balances[0] ? penizeKratce(data.balances[0].total, data.balances[0].currency) : '—'}
                    ikona={Wallet}
                    popisek={data.balances.slice(1).map(b => penizeKratce(b.total, b.currency)).join(' · ') || 'na všech účtech'}
                    onClick={() => naTab('ucty')}/>
            </div>

            {/* Výdaje po dnech a kategorie. Na širokém vedle sebe 8/4. */}
            <div className="grid gap-3 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Panel icon={TrendingDown} title="Výdaje po dnech"
                        description={`${data.filter.label} · ${mena}`}>
                        {data.daily.length > 0
                            ? <SloupceDnu dny={data.daily} mena={mena}
                                onDen={den => naTransakce({ obdobi: 'vlastni', od: den, do: den })}/>
                            : <Prazdno text="V tomhle období zatím nic není."/>}
                    </Panel>
                </div>

                <Panel icon={Coins} title="Kategorie"
                    description={data.categories.length > 0 ? 'Kam peníze šly' : undefined}>
                    {data.categories.length === 0
                        ? <Prazdno text="Zatím žádné výdaje k rozdělení."/>
                        : (
                            <ul className="space-y-2">
                                {data.categories.slice(0, 7).map(k => (
                                    <li key={k.category_id ?? 'bez'}>
                                        <button type="button"
                                            onClick={() => naTransakce({ kategorie: k.category_uuid ?? '' })}
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
                                                <span className="w-10 shrink-0 text-right text-[11px] tabular-nums text-[var(--color-text-secondary)]">
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

            {/* Účty, směny a partneři. */}
            <div className="grid gap-3 lg:grid-cols-3">
                <Panel icon={Wallet} title="Účty" description="Kde peníze jsou"
                    actions={<button type="button" onClick={() => naTab('ucty')}
                        className="inline-flex min-h-11 items-center px-2 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">Vše</button>}>
                    {data.wallets.length === 0
                        ? <Prazdno text="Zatím žádný účet. Založte si ho v Účtech."/>
                        : (
                            <ul className="space-y-2">
                                {data.wallets.slice(0, 6).map(u => (
                                    <li key={u.uuid} className="flex items-baseline justify-between gap-2 text-sm">
                                        <span className="flex min-w-0 items-center gap-1.5">
                                            {u.kind === 'cash' ? <Banknote size={13} className="shrink-0 text-[var(--color-text-secondary)]"/>
                                                : <Wallet size={13} className="shrink-0 text-[var(--color-text-secondary)]"/>}
                                            <span className="truncate text-[var(--color-text-primary)]">{u.name}</span>
                                        </span>
                                        <span className={`shrink-0 tabular-nums ${u.is_negative ? 'text-red-400' : 'text-[var(--color-text-primary)]'}`}>
                                            {penize(u.balance, u.currency)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                </Panel>

                <Panel icon={ArrowRightLeft} title="Směny"
                    description={data.exchange.acquisition.average_rate !== null
                        ? undefined
                        : 'Zatím žádná směna'}>
                    {data.exchange.acquisition.average_rate !== null ? (
                        <>
                            <p className="text-sm text-[var(--color-text-primary)]">
                                Průměrně jsme aktuálně držené 1 € pořídili za{' '}
                                <strong className="tabular-nums">
                                    {kurz(data.exchange.acquisition.average_rate)} Kč
                                </strong>
                            </p>
                            <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                Držíme {penize(data.exchange.acquisition.held_eur, 'EUR')}
                                {data.exchange.acquisition.has_unknown && (
                                    <>, z toho {penize(data.exchange.acquisition.unknown_eur, 'EUR')} s neznámou
                                    pořizovací cenou — ta do průměru nevstupuje</>
                                )}.
                            </p>
                            {data.exchange.last?.rate && (
                                <p className="mt-2 border-t border-[var(--color-border)] pt-2 text-[11px] text-[var(--color-text-secondary)]">
                                    Naposledy {datum(data.exchange.last.occurred_at)}
                                    {data.exchange.last.provider && ` · ${data.exchange.last.provider}`}:{' '}
                                    <strong className="tabular-nums text-[var(--color-text-primary)]">
                                        {kurz(data.exchange.last.rate.effective)} Kč/€
                                    </strong>
                                </p>
                            )}

                            <JakSePocita
                                nadpis="Jak vzniká průměrný pořizovací kurz"
                                radky={[
                                    { popis: 'Eura pořízená směnou', hodnota: penize(data.exchange.acquisition.known_eur, 'EUR') },
                                    { popis: 'Co stála celkem', hodnota: penize(data.exchange.acquisition.cost_czk, 'CZK') },
                                    { popis: 'Průměr na jedno euro',
                                        hodnota: `${kurz(data.exchange.acquisition.average_rate ?? 0)} Kč`, vysledek: true },
                                    ...(data.exchange.acquisition.has_unknown
                                        ? [{ popis: 'Eura s neznámou cenou (mimo průměr)',
                                            hodnota: penize(data.exchange.acquisition.unknown_eur, 'EUR') }]
                                        : []),
                                ]}
                                poznamka="Útrata v eurech ubere ze zásoby i z pořizovací hodnoty poměrně, takže průměr nemění — z peněženky nejde utratit „to euro z března“. Poplatek je v ceně započítaný podle toho, jestli se platil navíc, nebo byl v částce."/>
                        </>
                    ) : (
                        <Prazdno text="Až směníte koruny na eura, uvidíte tu skutečný kurz včetně poplatků."/>
                    )}
                    <button type="button" onClick={() => naTab('smeny')}
                        className="mt-2 inline-flex min-h-11 items-center gap-1 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                        Historie směn <ChevronRight size={13}/>
                    </button>
                </Panel>

                <PanelPartneru data={data} naTransakce={naTransakce}/>
            </div>

            <Panel icon={CalendarDays} title="Poslední aktivita"
                actions={<button type="button" onClick={() => naTab('transakce')}
                    className="inline-flex min-h-11 items-center px-2 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">Všechny</button>}>
                {data.recent.length === 0
                    ? <Prazdno text="V tomhle období zatím nic není. Zapište první výdaj tlačítkem Přidat."/>
                    : (
                        <ul className="divide-y divide-[var(--color-border)]">
                            {data.recent.map(p => <RadekPohybu key={p.uuid} pohyb={p}/>)}
                        </ul>
                    )}
            </Panel>
        </div>
    );
}

/** Věta pod „bezpečně na den". U překročení se místo doporučení řekne, o kolik. */
function popisekDenni(b: PrehledData['budget'] extends null ? never : any, mena?: string): string {
    if (! b) return 'bez rozpočtu';
    if (b.state === 'over') return `přesah o ${penize(b.over_by ?? 0, mena ?? 'CZK')}`;
    if (b.state === 'ended') return 'období skončilo';
    if (b.state === 'not_started') return 'ještě nezačalo';
    if (b.state === 'open_ended') return 'bez konce období';

    return `${dny(b.days_left ?? 0)} do konce${b.reserve_kept > 0 ? ', rezerva odečtená' : ''}`;
}

function PanelPartneru({ data, naTransakce }: { data: PrehledData; naTransakce: (f: Record<string, string>) => void }) {
    const radky = data.partner_balance.by_currency.filter(m => m.settlement.length > 0);

    return (
        <Panel icon={TrendingUp} title="Adri a Maki">
            {data.partner_balance.by_currency.length === 0 ? (
                <Prazdno text="Až přibudou společné výdaje, uvidíte tu, kdo komu kolik dluží."/>
            ) : radky.length === 0 ? (
                <p className="text-sm text-[var(--color-text-primary)]">Máme vyrovnáno.</p>
            ) : (
                <ul className="space-y-2">
                    {radky.map(m => m.settlement.map((v, i) => (
                        <li key={`${m.currency}-${i}`} className="text-sm text-[var(--color-text-primary)]">
                            {v.from} dluží {v.to}{' '}
                            <strong className="tabular-nums">{penize(v.amount, v.currency)}</strong>
                        </li>
                    )))}
                </ul>
            )}

            {/*
             * Rozpis, ze kterého to saldo vzniklo.
             *
             * Částka „dlužíš mi třicet eur" bez možnosti kontroly je přesně to, kvůli
             * čemu se lidé o peníze hádají. Rozdíl mezi „zaplatil" a „nese" je celý
             * výpočet a je vidět na jednom řádku.
             */}
            {data.partner_balance.by_currency.map(m => (
                <div key={m.currency}>
                    <JakSePocita
                        nadpis={`Jak vzniklo saldo v ${m.currency}`}
                        radky={[
                            ...m.partners.flatMap(p => [
                                { popis: `${p.name} — zaplatil ze svého`, hodnota: penize(p.paid, m.currency) },
                                { popis: `${p.name} — jeho podíl na výdajích`, hodnota: `− ${penize(p.owes, m.currency)}` },
                                { popis: `${p.name} — rozdíl`, hodnota: penize(p.balance, m.currency), vysledek: true },
                            ]),
                        ]}
                        poznamka={
                            <>
                                Kdo zaplatil víc, než kolik nese, má u druhého rozdíl. Výdaj ze
                                společného účtu se do „zaplatil" nikomu nepočítá — ty peníze byly
                                obou už předtím.
                                <button type="button" onClick={() => naTransakce({ typ: 'expense' })}
                                    className="mt-1 block min-h-11 text-[11px] text-[var(--color-text-secondary)] underline">
                                    Zobrazit výdaje ve výpočtu
                                </button>
                            </>
                        }/>
                </div>
            ))}
        </Panel>
    );
}

/**
 * Karta s jedním číslem.
 *
 * Obal je vždycky `div`, i když je karta prokliknutelná. Kdyby byl `button`, nešlo by
 * do něj vložit rozbalovací „Jak se to počítá" — tlačítko v tlačítku je neplatné HTML
 * a prohlížeče to řeší každý jinak, včetně toho, že vnitřní přestane fungovat.
 * Klikací je proto jen horní část s číslem.
 */
function Kpi({ label, hodnota, popisek, ikona: Ikona, ton = 'plain', pruh, onClick, vysvetleni }: {
    label: string; hodnota: string; popisek: string;
    ikona: any; ton?: 'plain' | 'warn' | 'danger'; pruh?: number;
    onClick?: () => void;
    vysvetleni?: ReactNode;
}) {
    const barvy = {
        plain: 'border-[var(--color-border)] bg-[var(--color-bg-card)]',
        warn: 'border-amber-500/40 bg-[var(--color-bg-card)]',
        danger: 'border-red-500/40 bg-[var(--color-bg-card)]',
    }[ton];

    const Vnitrek = (
        <>
            <p className="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                <Ikona size={12}/> {label}
            </p>
            <p className="mt-1 text-xl font-semibold tabular-nums text-[var(--color-text-primary)] sm:text-2xl">{hodnota}</p>
            {pruh !== undefined && (
                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                    <div className="h-full rounded-full"
                        style={{ width: `${pruh}%`, background: ton === 'danger' ? 'var(--fin-vydaj)' : ton === 'warn' ? 'var(--fin-upozorneni)' : 'var(--fin-prijem)' }}/>
                </div>
            )}
            <p className="mt-1 text-[11px] leading-tight text-[var(--color-text-secondary)]">{popisek}</p>
        </>
    );

    return (
        <div className={`rounded-2xl border p-3 text-left ${barvy} ${onClick ? 'transition-colors hover:border-[var(--color-accent)]' : ''}`}>
            {onClick
                ? <button type="button" onClick={onClick} className="w-full text-left">{Vnitrek}</button>
                : Vnitrek}
            {vysvetleni}
        </div>
    );
}

function Prazdno({ text }: { text: string }) {
    return (
        <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs leading-relaxed text-[var(--color-text-secondary)]">
            {text}
        </p>
    );
}
