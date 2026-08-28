import { hlaska } from '@/Components/Hlasky';
import Panel from '@/Components/Panel';
import { transakce as pocetTransakci } from '@/lib/cestina';
import { kurz, penize, zahlaviDne } from '@/lib/penize';
import axios from 'axios';
import { Filter, Search, SlidersHorizontal, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import RadekPohybu from './RadekPohybu';
import type { Ciselniky, Pohyb, SouhrnMeny } from './typy';

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2 text-sm text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';

/**
 * Úplná historie a hlavní místo dohledání.
 *
 * Seskupuje se po dnech, protože tak si člověk peníze pamatuje — „v pátek jsme byli
 * nakoupit" a ne „třiadvacátá transakce". Záhlaví dne nese součet, takže se dá
 * odpovědět na „kolik jsme utratili v sobotu" bez počítání.
 */
export default function Transakce({ obdobi, filtr, ciselniky, onFiltr, onZmena }: {
    obdobi: string;
    filtr: Record<string, string>;
    ciselniky: Ciselniky;
    onFiltr: (f: Record<string, string>) => void;
    onZmena: () => void;
}) {
    const [radky, setRadky] = useState<Pohyb[]>([]);
    const [souhrn, setSouhrn] = useState<SouhrnMeny[]>([]);
    const [nalezeno, setNalezeno] = useState(0);
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');
    const [filtryOtevrene, setFiltryOtevrene] = useState(false);
    const [hledani, setHledani] = useState(filtr.hledat ?? '');
    const [detail, setDetail] = useState<Pohyb | null>(null);

    const nacti = useCallback(async () => {
        setNacita(true);

        try {
            const { data } = await axios.get('/api/v1/rozpocet/transakce', { params: { obdobi, ...filtr } });

            setRadky(data.transactions);
            setSouhrn(data.summary);
            setNalezeno(data.found);
            setChyba('');
        } catch {
            setChyba('Transakce se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, [obdobi, filtr]);

    useEffect(() => { void nacti(); }, [nacti]);

    // Hledání se posílá až po chvíli klidu. Dotaz po každém písmenu by na telefonu
    // znamenal osm požadavků na slovo a seznam by poskakoval pod prstem.
    useEffect(() => {
        if ((filtr.hledat ?? '') === hledani) return;

        const casovac = setTimeout(() => {
            const novy = { ...filtr };
            if (hledani) novy.hledat = hledani; else delete novy.hledat;
            onFiltr(novy);
        }, 400);

        return () => clearTimeout(casovac);
    }, [hledani]);

    const poDnech = useMemo(() => {
        const skupiny = new Map<string, Pohyb[]>();

        for (const p of radky) {
            if (! skupiny.has(p.occurred_at)) skupiny.set(p.occurred_at, []);
            skupiny.get(p.occurred_at)!.push(p);
        }

        return [...skupiny.entries()];
    }, [radky]);

    const zrusFiltr = (klic: string) => {
        const novy = { ...filtr };
        delete novy[klic];
        if (klic === 'hledat') setHledani('');
        onFiltr(novy);
    };

    const aktivniFiltry = Object.entries(filtr).filter(([, v]) => v !== '');

    const smaz = async (p: Pohyb) => {
        try {
            await axios.delete(`/api/v1/rozpocet/transakce/${p.uuid}`, { data: { potvrzeno: true } });
            hlaska('Záznam je smazaný, zůstatky se přepočítaly.', 'uspech');
            setDetail(null);
            await nacti();
            onZmena();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Záznam se nepodařilo smazat.', 'chyba');
        }
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-0 flex-1">
                    <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]"/>
                    <input value={hledani} onChange={e => setHledani(e.target.value)}
                        placeholder="Hledat v popisu, obchodníkovi nebo místě"
                        aria-label="Hledat v transakcích"
                        className={`${POLE} !min-h-10 pl-9`}/>
                </div>
                <button type="button" onClick={() => setFiltryOtevrene(o => ! o)}
                    aria-expanded={filtryOtevrene}
                    className="inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-xl border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)]">
                    <SlidersHorizontal size={15}/> Filtry
                </button>
            </div>

            {aktivniFiltry.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {aktivniFiltry.map(([k, v]) => (
                        <button key={k} type="button" onClick={() => zrusFiltr(k)}
                            className="inline-flex min-h-11 items-center gap-1 rounded-full border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2.5 text-xs text-[var(--color-text-primary)]">
                            {popisFiltru(k, v, ciselniky)} <X size={12}/>
                        </button>
                    ))}
                    <button type="button" onClick={() => { setHledani(''); onFiltr({}); }}
                        className="inline-flex min-h-11 items-center rounded-full px-2 text-xs text-[var(--color-text-secondary)] underline">
                        Vymazat filtry
                    </button>
                </div>
            )}

            {filtryOtevrene && (
                <Panel title="Filtry">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Vyber popisek="Typ" hodnota={filtr.typ ?? ''}
                            onZmena={v => onFiltr({ ...filtr, typ: v })}
                            moznosti={[
                                { v: 'expense', p: 'Výdaj' }, { v: 'income', p: 'Příjem' },
                                { v: 'transfer', p: 'Převod' }, { v: 'exchange', p: 'Směna' },
                            ]}/>
                        <Vyber popisek="Účet" hodnota={filtr.ucet ?? ''}
                            onZmena={v => onFiltr({ ...filtr, ucet: v })}
                            moznosti={ciselniky.wallets.map(u => ({ v: u.uuid, p: `${u.name} (${u.currency})` }))}/>
                        <Vyber popisek="Kategorie" hodnota={filtr.kategorie ?? ''}
                            onZmena={v => onFiltr({ ...filtr, kategorie: v })}
                            moznosti={ciselniky.categories.map(k => ({ v: k.uuid, p: k.name }))}/>
                        <Vyber popisek="Měna" hodnota={filtr.mena ?? ''}
                            onZmena={v => onFiltr({ ...filtr, mena: v })}
                            moznosti={ciselniky.balances.map(b => ({ v: b.currency, p: b.currency }))}/>
                    </div>
                </Panel>
            )}

            {/* Souhrn období — po měnách, nesečtené dohromady. */}
            {souhrn.length > 0 && (
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {souhrn.map(s => (
                        <div key={s.currency} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                            <p className="text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">{s.currency}</p>
                            <dl className="mt-1 grid grid-cols-3 gap-2 text-xs">
                                <div>
                                    <dt className="text-[var(--color-text-secondary)]">příjem</dt>
                                    <dd className="tabular-nums" style={{ color: 'var(--fin-prijem)' }}>{penize(s.income, s.currency)}</dd>
                                </div>
                                <div>
                                    <dt className="text-[var(--color-text-secondary)]">výdaj</dt>
                                    <dd className="tabular-nums" style={{ color: 'var(--fin-vydaj)' }}>{penize(s.spent, s.currency)}</dd>
                                </div>
                                <div>
                                    <dt className="text-[var(--color-text-secondary)]">rozdíl</dt>
                                    <dd className="tabular-nums text-[var(--color-text-primary)]">{penize(s.net, s.currency)}</dd>
                                </div>
                            </dl>
                        </div>
                    ))}
                </div>
            )}

            <Panel title="Historie"
                description={nacita ? 'Načítám…' : `${pocetTransakci(nalezeno)} v tomhle výběru`}>
                {chyba && (
                    <div className="rounded-xl border border-red-500/40 p-3">
                        <p className="text-sm text-[var(--color-text-primary)]">{chyba}</p>
                        <button type="button" onClick={() => void nacti()}
                            className="mt-2 min-h-9 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]">
                            Zkusit znovu
                        </button>
                    </div>
                )}

                {! nacita && ! chyba && radky.length === 0 && (
                    <div className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-8 text-center">
                        <p className="text-sm text-[var(--color-text-primary)]">
                            {aktivniFiltry.length > 0 ? 'Tomuhle výběru nic neodpovídá' : 'V tomhle období zatím nic není'}
                        </p>
                        <p className="mx-auto mt-1 max-w-sm text-xs leading-relaxed text-[var(--color-text-secondary)]">
                            {aktivniFiltry.length > 0
                                ? 'Zkuste rozšířit období nebo zrušit některý filtr.'
                                : 'První výdaj zapíšete tlačítkem Přidat — stačí částka a kategorie.'}
                        </p>
                        {aktivniFiltry.length > 0 && (
                            <button type="button" onClick={() => { setHledani(''); onFiltr({}); }}
                                className="mt-3 min-h-10 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-primary)]">
                                Vymazat filtry
                            </button>
                        )}
                    </div>
                )}

                {poDnech.map(([den, pohyby]) => {
                    const vydaje = pohyby.filter(p => p.type === 'expense');
                    const prijmy = pohyby.filter(p => p.type === 'income');

                    return (
                        <section key={den} className="border-t border-[var(--color-border)] first:border-t-0">
                            <h3 className="flex items-baseline justify-between gap-2 pt-3 text-xs">
                                <span className="font-medium text-[var(--color-text-primary)]">{zahlaviDne(den)}</span>
                                <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                                    {vydaje.length > 0 && soucetPoMenach(vydaje, 'from')}
                                    {prijmy.length > 0 && ` · +${soucetPoMenach(prijmy, 'to')}`}
                                </span>
                            </h3>
                            <ul className="divide-y divide-[var(--color-border)]">
                                {pohyby.map(p => <RadekPohybu key={p.uuid} pohyb={p} onClick={() => setDetail(p)}/>)}
                            </ul>
                        </section>
                    );
                })}
            </Panel>

            {detail && <Detail pohyb={detail} onZavrit={() => setDetail(null)} onSmazat={() => void smaz(detail)}/>}
        </div>
    );
}

function soucetPoMenach(pohyby: Pohyb[], strana: 'from' | 'to'): string {
    const podle = new Map<string, number>();

    for (const p of pohyby) {
        const s = p[strana];
        if (! s) continue;
        podle.set(s.currency, (podle.get(s.currency) ?? 0) + s.amount);
    }

    return [...podle.entries()].map(([m, c]) => penize(c, m)).join(' + ');
}

function popisFiltru(klic: string, hodnota: string, c: Ciselniky): string {
    if (klic === 'typ') return { expense: 'Výdaj', income: 'Příjem', transfer: 'Převod', exchange: 'Směna' }[hodnota] ?? hodnota;
    if (klic === 'ucet') return c.wallets.find(u => u.uuid === hodnota)?.name ?? 'Účet';
    if (klic === 'kategorie') return c.categories.find(k => k.uuid === hodnota)?.name ?? 'Kategorie';
    if (klic === 'hledat') return `„${hodnota}"`;
    if (klic === 'od') return `od ${hodnota}`;
    if (klic === 'do') return `do ${hodnota}`;

    return `${klic}: ${hodnota}`;
}

function Vyber({ popisek, hodnota, onZmena, moznosti }: {
    popisek: string; hodnota: string;
    onZmena: (v: string) => void;
    moznosti: Array<{ v: string; p: string }>;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-medium text-[var(--color-text-secondary)]">{popisek}</label>
            <select value={hodnota} onChange={e => onZmena(e.target.value)} className={POLE}>
                <option value="">Vše</option>
                {moznosti.map(m => <option key={m.v} value={m.v}>{m.p}</option>)}
            </select>
        </div>
    );
}

/** Detail záznamu — všechno, co o něm víme, i s dopadem na zůstatek. */
function Detail({ pohyb, onZavrit, onSmazat }: { pohyb: Pohyb; onZavrit: () => void; onSmazat: () => void }) {
    const [mazani, setMazani] = useState(false);

    const radky: Array<[string, string]> = [
        ['Typ', pohyb.type_label],
        ['Datum', new Date(`${pohyb.occurred_at}T12:00:00`).toLocaleDateString('cs-CZ')],
        ...(pohyb.from ? [['Odešlo z', `${pohyb.from.name} · ${penize(pohyb.from.amount, pohyb.from.currency)}`] as [string, string]] : []),
        ...(pohyb.to ? [['Přišlo na', `${pohyb.to.name} · ${penize(pohyb.to.amount, pohyb.to.currency)}`] as [string, string]] : []),
        ...(pohyb.category ? [['Kategorie', pohyb.category.name] as [string, string]] : []),
        ...(pohyb.payer ? [['Zaplatil', pohyb.payer] as [string, string]] : []),
        ...(pohyb.trip ? [['Cesta', pohyb.trip] as [string, string]] : []),
        ...(pohyb.counterparty ? [['Obchodník', pohyb.counterparty] as [string, string]] : []),
        ...(pohyb.provider ? [['Poskytovatel', pohyb.provider] as [string, string]] : []),
        ...(pohyb.place ? [['Místo', pohyb.place] as [string, string]] : []),
        ...(pohyb.description ? [['Poznámka', pohyb.description] as [string, string]] : []),
        ...(pohyb.fee > 0 && pohyb.fee_currency
            ? [['Poplatek', `${penize(pohyb.fee, pohyb.fee_currency)} — ${pohyb.fee_included ? 'zahrnutý v částce' : 'placený navíc'}`] as [string, string]]
            : []),
        ['Počítá se do rozpočtu', pohyb.counts_to_budget ? 'ano' : `ne${pohyb.exclusion_reason ? ` — ${pohyb.exclusion_reason}` : ''}`],
    ];

    return (
        <div className="fixed inset-0 z-[950] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-label="Detail záznamu">
            <button type="button" aria-label="Zavřít" onClick={onZavrit} className="absolute inset-0 bg-black/50"/>
            <div className="relative max-h-[88dvh] w-full overflow-y-auto rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 pb-[calc(1rem+env(safe-area-inset-bottom,0px))] sm:max-w-md sm:rounded-2xl">
                <div className="mb-3 flex items-start justify-between gap-2">
                    <h2 className="text-base font-semibold text-[var(--color-text-primary)]">
                        {pohyb.category?.name ?? pohyb.counterparty ?? pohyb.type_label}
                    </h2>
                    <button type="button" onClick={onZavrit} aria-label="Zavřít"
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)]">
                        <X size={18}/>
                    </button>
                </div>

                {pohyb.rate && (
                    <div className="mb-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3">
                        <p className="text-sm text-[var(--color-text-primary)]">
                            Za 1 € doopravdy{' '}
                            <strong className="tabular-nums">
                                {kurz(pohyb.rate.effective)} Kč
                            </strong>
                        </p>
                        <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                            Bez poplatku by to bylo {kurz(pohyb.rate.nominal)} Kč.
                            {pohyb.rate.eur_per_1000_czk && ` Za 1 000 Kč to je ${kurz(pohyb.rate.eur_per_1000_czk)} €.`}
                        </p>
                    </div>
                )}

                <dl className="divide-y divide-[var(--color-border)]">
                    {radky.map(([k, v]) => (
                        <div key={k} className="flex items-baseline justify-between gap-3 py-2">
                            <dt className="shrink-0 text-xs text-[var(--color-text-secondary)]">{k}</dt>
                            <dd className="text-right text-sm text-[var(--color-text-primary)]">{v}</dd>
                        </div>
                    ))}
                </dl>

                <div className="mt-4 border-t border-[var(--color-border)] pt-3">
                    {mazani ? (
                        <div>
                            <p className="text-sm text-[var(--color-text-primary)]">Opravdu smazat?</p>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                {pohyb.from && pohyb.to
                                    ? 'Zruší se obě strany najednou — peníze se vrátí tam, odkud odešly.'
                                    : 'Zůstatek účtu se přepočítá.'}
                            </p>
                            <div className="mt-2 flex gap-2">
                                <button type="button" onClick={onSmazat}
                                    className="min-h-10 flex-1 rounded-lg bg-red-500/90 px-3 text-sm font-medium text-white">
                                    Smazat
                                </button>
                                <button type="button" onClick={() => setMazani(false)}
                                    className="min-h-10 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)]">
                                    Zpět
                                </button>
                            </div>
                        </div>
                    ) : (
                        <button type="button" onClick={() => setMazani(true)}
                            className="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)] hover:border-red-500/40 hover:text-red-400">
                            <Trash2 size={15}/> Smazat záznam
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
