import { hlaska } from '@/Components/Hlasky';
import { novyKlic, zaradit } from '@/lib/frontaZapisu';
import { castka as prectiCastku, dnesniDatum, kurz, penize, TYPY_ZAZNAMU, type TypZaznamu } from '@/lib/penize';
import axios from 'axios';
import { AlertTriangle, ArrowRightLeft, ArrowUpDown, Check, ChevronDown, Minus, Plus, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { Ciselniky, Kategorie, Sablona, Ucet } from './typy';

/**
 * Zápis nového záznamu.
 *
 * Hlavní scénář je jediný a všechno ostatní mu ustupuje: Maki stojí u pokladny,
 * jednou rukou, a chce mít výdaj zapsaný dřív, než dojde ke dveřím. Proto se po
 * otevření rovnou píše částka, kategorie jsou dlaždice na dosah palce a účet, měna,
 * cesta i plátce se předvyplní z toho, co bylo naposledy.
 *
 * Čtyři typy mají čtyři vlastní formuláře, ne jeden univerzální s poli, která u
 * poloviny typů nedávají smysl. Kdo zapisuje výběr hotovosti, nemá vidět obchodníka;
 * kdo zapisuje směnu, potřebuje poplatek a náhled kurzu.
 *
 * Pokročilá pole jsou pod „Další údaje" a jsou zavřená. Nejsou nepovinná proto, že
 * na nich nezáleží, ale proto, že je u devíti z deseti nákupů netřeba — a kdyby byla
 * vidět, prodlouží se každý zápis o rozhodnutí, které nikdo nechtěl dělat.
 */

type Vlastnosti = {
    ciselniky: Ciselniky;
    /** Předvolený typ. Výdaj, pokud se neřekne jinak. */
    vychoziTyp?: TypZaznamu;
    onHotovo: () => void;
    onZavrit: () => void;
};

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2.5 text-base text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const POPISEK = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

/** Čtyři možnosti centrální akce. Pořadí podle četnosti, ne podle abecedy. */
const NABIDKA: Array<{ id: TypZaznamu; popis: string }> = [
    { id: 'expense', popis: 'Nákup, útrata, faktura' },
    { id: 'income', popis: 'Mzda, náhrada, vrácené peníze' },
    { id: 'transfer', popis: 'Mezi vlastními účty ve stejné měně' },
    { id: 'exchange', popis: 'Koruny na eura a zpátky' },
];

export default function PridatZaznam({ ciselniky, vychoziTyp = 'expense', onHotovo, onZavrit }: Vlastnosti) {
    const [typ, setTyp] = useState<TypZaznamu>(vychoziTyp);
    const [ukladam, setUkladam] = useState(false);
    const [chyby, setChyby] = useState<Record<string, string>>({});
    const [varovani, setVarovani] = useState<Array<{ key: string; title: string; body: string }>>([]);
    const [detaily, setDetaily] = useState(false);

    const cesta = ciselniky.active_trip;

    // Předvyplnění. Cesta určuje měnu a účet, jinak se bere poslední použitý —
    // ale všechno jde jedním klepnutím změnit, je to návrh, ne pravidlo.
    const vychoziUcet = useMemo(() => {
        const podleCesty = cesta?.default_wallet_id
            ? ciselniky.wallets.find(u => u.id === cesta.default_wallet_id)
            : null;
        const posledni = ciselniky.last_used.wallet_id
            ? ciselniky.wallets.find(u => u.id === ciselniky.last_used.wallet_id)
            : null;
        const podleMeny = cesta?.currency
            ? ciselniky.wallets.find(u => u.currency === cesta.currency)
            : null;

        return podleCesty ?? posledni ?? podleMeny ?? ciselniky.wallets[0] ?? null;
    }, [ciselniky, cesta]);

    const [form, setForm] = useState(() => ({
        amount_from: '',
        amount_to: '',
        occurred_at: dnesniDatum(),
        wallet_from: vychoziUcet?.uuid ?? '',
        wallet_to: '',
        category: '',
        trip: cesta?.uuid ?? '',
        payer_partner_id: ciselniky.last_used.payer_partner_id ? String(ciselniky.last_used.payer_partner_id) : '',
        beneficiary_partner_id: '',
        fee_amount: '',
        fee_currency: '',
        fee_included: false,
        reference_rate: '',
        provider: '',
        counterparty: '',
        place: '',
        description: '',
        excluded_from_budget: false,
        exclusion_reason: '',
        deleni: 'equal' as 'equal' | 'adri' | 'maki' | 'vlastni',
        podil_prvni: '50',
    }));

    const poleCastky = useRef<HTMLInputElement>(null);

    /*
     * Klíč se vyrábí jednou při otevření formuláře, ne při odeslání.
     *
     * Kdyby vznikal až u odeslání, měl by každý pokus jiný a ochrana proti duplicitě
     * by nefungovala právě tehdy, kdy je potřeba — při opakování po výpadku.
     */
    const klicZapisu = useRef(novyKlic());

    const [sablony, setSablony] = useState<Sablona[]>([]);

    useEffect(() => {
        void axios.get<{ templates: Sablona[] }>('/api/v1/rozpocet/sablony')
            .then(({ data }) => setSablony(data.templates.filter(s => s.type === 'expense').slice(0, 6)))
            .catch(() => setSablony([]));
    }, []);

    /**
     * Použití šablony.
     *
     * Přepíše účet, kategorii, plátce i rozdělení. Částku ne — ta se nepředvyplňuje
     * nikdy, protože je jediná, co se pokaždé liší, a nabídnout u ní číslo znamená,
     * že ho jednou někdo přehlédne a uloží útratu, která se nestala.
     *
     * Zbytek přepisuje celý, ne jen prázdná pole. Šablona je vědomé klepnutí a čeká
     * se od ní, že nastaví přesně to, co má; míchat ji s předchozími volbami by dalo
     * kombinaci, kterou nikdo nezvolil.
     */
    const pouzijSablonu = (s: Sablona) => {
        uprav({
            wallet_from: s.wallet?.uuid ?? form.wallet_from,
            category: s.category?.uuid ?? form.category,
            payer_partner_id: s.payer ? String(s.payer.id) : form.payer_partner_id,
            deleni: s.split === 'first' ? 'adri' : s.split === 'second' ? 'maki' : 'equal',
        });

        poleCastky.current?.focus();

        // Pořadí v nabídce se řídí použitím, takže se to musí započítat.
        void axios.post(`/api/v1/rozpocet/sablony/${s.uuid}/pouzito`).catch(() => {});
    };

    // Kurzor rovnou v částce. Je to jediné pole, které se musí vyplnit pokaždé —
    // všechno ostatní se dá nechat předvyplněné.
    useEffect(() => { poleCastky.current?.focus(); }, []);

    const uprav = (zmena: Partial<typeof form>) => setForm(f => ({ ...f, ...zmena }));

    const zdroj = ciselniky.wallets.find(u => u.uuid === form.wallet_from) ?? null;
    const cil = ciselniky.wallets.find(u => u.uuid === form.wallet_to) ?? null;

    const kategorie = ciselniky.categories.filter(k => k.kind === (typ === 'income' ? 'income' : 'expense'));
    const oblibene = kategorie.filter(k => k.is_favourite).slice(0, 6);
    const [vsechnyKategorie, setVsechnyKategorie] = useState(false);

    /**
     * Změna účtu mění měnu. Nechat starou měnu u nového účtu by znamenalo výdaj
     * v eurech odepsaný z korunového účtu — což je stav, co nemůže být pravda.
     */
    const zmenUcet = (strana: 'wallet_from' | 'wallet_to', uuid: string) => {
        uprav({ [strana]: uuid } as Partial<typeof form>);
        setChyby(c => ({ ...c, [strana]: '' }));
    };

    const nastaveniTypu = TYPY_ZAZNAMU[typ];

    const potrebaZdroj = typ !== 'income';
    const potrebaCil = typ !== 'expense';

    // Náhled kurzu ještě před uložením — sekce 11 to vyžaduje a je to jediné místo,
    // kde jde poznat, že se člověk spletl o řád, dřív než záznam vznikne.
    const nahledKurzu = useMemo(() => {
        if (typ !== 'exchange') return null;

        const z = prectiCastku(form.amount_from);
        const k = prectiCastku(form.amount_to);
        const poplatek = prectiCastku(form.fee_amount) ?? 0;

        if (! z || ! k || z <= 0 || k <= 0 || ! zdroj || ! cil) return null;

        const menaPoplatku = form.fee_currency || zdroj.currency;
        const vydano = z + (! form.fee_included && menaPoplatku === zdroj.currency ? poplatek : 0);
        const prijato = k - (! form.fee_included && menaPoplatku === cil.currency ? poplatek : 0);

        if (prijato <= 0) return null;

        const doEur = cil.currency === 'EUR';
        const kurz = doEur ? vydano / prijato : prijato / vydano;

        return {
            kurz,
            vydano,
            prijato,
            zaTisic: 1000 / kurz,
            doEur,
            extremni: kurz < 15 || kurz > 40,
        };
    }, [typ, form.amount_from, form.amount_to, form.fee_amount, form.fee_currency, form.fee_included, zdroj, cil]);

    const muzeUlozit = (() => {
        const c = prectiCastku(form.amount_from) ?? prectiCastku(form.amount_to);

        if (! c || c <= 0) return false;
        if (potrebaZdroj && ! form.wallet_from) return false;
        if (potrebaCil && ! form.wallet_to) return false;
        if (typ === 'expense' && ! form.category) return false;
        if (typ === 'exchange' && ! prectiCastku(form.amount_to)) return false;

        return true;
    })();

    const uloz = async (potvrzeno = false) => {
        setUkladam(true);
        setChyby({});

        const c = prectiCastku(form.amount_from);
        const cDo = prectiCastku(form.amount_to);

        // Rozdělení mezi partnery. Zaokrouhlovací haléř se dává vždycky prvnímu, aby
        // dvě spuštění nad týmiž daty daly týž výsledek.
        const partneri = ciselniky.partners;
        let split: Array<{ partner_id: number; amount: number; basis: string }> = [];

        if (typ === 'expense' && partneri.length === 2 && c) {
            if (form.deleni === 'equal' || form.deleni === 'vlastni') {
                // Vlastní poměr, nebo půl na půl. Haléř navíc dostává vždycky první —
                // aby dvě uložení téže částky dala týž výsledek a saldo se nekývalo
                // o cent podle toho, kdo zrovna klikl.
                const podil = form.deleni === 'vlastni'
                    ? (prectiCastku(form.podil_prvni) ?? 50) / 100
                    : 0.5;

                const druhy = Math.round(c * (1 - podil) * 100) / 100;

                split = [
                    { partner_id: partneri[0].id, amount: Math.round((c - druhy) * 100) / 100, basis: form.deleni === 'vlastni' ? 'percent' : 'equal' },
                    { partner_id: partneri[1].id, amount: druhy, basis: form.deleni === 'vlastni' ? 'percent' : 'equal' },
                ];
            } else {
                const kdo = form.deleni === 'adri' ? partneri[0] : partneri[1];
                split = [{ partner_id: kdo.id, amount: c, basis: 'fixed' }];
            }
        }

        const telo = {
                type: typ,
                occurred_at: form.occurred_at,
                wallet_from: potrebaZdroj ? form.wallet_from : undefined,
                wallet_to: potrebaCil ? form.wallet_to : undefined,
                amount_from: c ?? undefined,
                amount_to: cDo ?? undefined,
                category: form.category || undefined,
                trip: form.trip || undefined,
                payer_partner_id: form.payer_partner_id ? Number(form.payer_partner_id) : undefined,
                fee_amount: prectiCastku(form.fee_amount) ?? undefined,
                fee_currency: form.fee_currency || undefined,
                fee_included: form.fee_included,
                reference_rate: prectiCastku(form.reference_rate) ?? undefined,
                provider: form.provider || undefined,
                counterparty: form.counterparty || undefined,
                place: form.place || undefined,
                description: form.description || undefined,
                excluded_from_budget: form.excluded_from_budget,
                exclusion_reason: form.exclusion_reason || undefined,
                split: split.length ? split : undefined,
                client_key: klicZapisu.current,
                potvrzeno,
        };

        try {
            const { data } = await axios.post('/api/v1/rozpocet/transakce', telo);

            /*
             * Vrácení posledního zápisu.
             *
             * Nabízí se přímo v hlášce, protože smysl má jen v tom krátkém okamžiku,
             * kdy si člověk uvědomí, že klepl vedle. Kdyby se kvůli tomu muselo někam
             * přejít, je rychlejší záznam prostě smazat ručně a tlačítko by nikdo
             * nepoužil.
             */
            hlaska(
                `${nastaveniTypu.nazev} je zapsaný${typ === 'exchange' ? 'á' : ''}.`,
                'uspech',
                data?.uuid
                    ? {
                        popis: 'Vrátit',
                        provest: () => {
                            void axios
                                .delete(`/api/v1/rozpocet/transakce/${data.uuid}`, { data: { potvrzeno: true } })
                                .then(() => { hlaska('Zápis je vzatý zpátky.', 'info'); onHotovo(); })
                                .catch(() => hlaska('Zápis se nepodařilo vrátit — smažte ho v Transakcích.', 'chyba'));
                        },
                    }
                    : undefined,
            );

            onHotovo();
        } catch (problem: any) {
            const stav = problem?.response?.status;

            /*
             * Bez odpovědi = bez signálu. Zápis se uloží do fronty místo zahození.
             *
             * Rozepsaný výdaj je práce, kterou už někdo udělal, a ztratit ji kvůli
             * chvilce bez signálu v obchodě je to nejhorší, co může modul udělat —
             * odsune se to „na potom" a potom už nikdo neví, kolik to bylo.
             */
            if (! stav) {
                zaradit({
                    client_key: klicZapisu.current,
                    telo,
                    popis: `${nastaveniTypu.nazev} ${form.amount_from || form.amount_to} ${zdroj?.currency ?? cil?.currency ?? ''}`.trim(),
                });

                hlaska('Není signál — zápis počká a odešle se sám, až bude spojení.', 'info');
                onHotovo();

                return;
            }

            if (stav === 409 && problem.response.data?.needs_confirmation) {
                // Neobvyklé, ne špatné. Ptáme se, neodmítáme — rozepsaná data zůstávají.
                setVarovani(problem.response.data.warnings ?? []);
            } else if (stav === 422) {
                const e = problem.response.data?.errors ?? {};
                setChyby(Object.fromEntries(Object.entries(e).map(([k, v]) => [k, (v as string[])[0]])));
            } else {
                hlaska('Záznam se nepodařilo uložit. Zkuste to prosím znovu.', 'chyba');
            }
        } finally {
            setUkladam(false);
        }
    };

    return (
        <div className="flex h-full flex-col">
            {/* Typ napřed. Podle něj se formulář přestaví. */}
            <div className="shrink-0 border-b border-[var(--color-border)] px-4 pb-3 pt-1">
                <div className="grid grid-cols-4 gap-1.5">
                    {NABIDKA.map(m => {
                        const t = TYPY_ZAZNAMU[m.id];
                        const aktivni = m.id === typ;

                        return (
                            <button key={m.id} type="button" onClick={() => { setTyp(m.id); setVarovani([]); setChyby({}); }}
                                aria-pressed={aktivni}
                                className={`min-h-[3.25rem] rounded-xl border px-1 text-center transition-colors ${
                                    aktivni
                                        ? 'border-transparent text-white'
                                        : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                }`}
                                style={aktivni ? { background: t.barva } : undefined}>
                                <span className="block text-[13px] font-medium leading-tight">{t.nazev}</span>
                            </button>
                        );
                    })}
                </div>
                <p className="mt-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                    {NABIDKA.find(m => m.id === typ)?.popis}
                    {(typ === 'transfer' || typ === 'exchange') && (
                        <strong className="text-[var(--color-text-primary)]"> Do výdajů se to nepočítá — peníze se jen přesunou.</strong>
                    )}
                </p>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                {/* Částka je největší a první. */}
                <div>
                    <label className={POPISEK} htmlFor="fin-castka">
                        {typ === 'exchange' ? 'Kolik odešlo' : 'Částka'}
                        {zdroj && potrebaZdroj && <span className="ml-1 text-[var(--color-text-primary)]">({zdroj.currency})</span>}
                        {! potrebaZdroj && cil && <span className="ml-1 text-[var(--color-text-primary)]">({cil.currency})</span>}
                    </label>
                    <input id="fin-castka" ref={poleCastky}
                        type="text" inputMode="decimal" autoComplete="off"
                        value={potrebaZdroj ? form.amount_from : form.amount_to}
                        onChange={e => uprav(potrebaZdroj ? { amount_from: e.target.value } : { amount_to: e.target.value })}
                        placeholder="0,00"
                        className={`${POLE} !text-3xl !font-semibold tabular-nums`}/>
                    {chyby.amount_from && <p className="mt-1 text-xs text-red-400">{chyby.amount_from}</p>}
                </div>

                {/* Šablony. Předvyplní všechno kromě částky — ta se nepředvyplňuje
                    nikdy, protože je to jediný údaj, který se pokaždé liší. */}
                {typ === 'expense' && sablony.length > 0 && (
                    <div className="mt-4">
                        <label className={POPISEK}>Rychlý zápis</label>
                        <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
                            {sablony.map(s => (
                                <button key={s.uuid} type="button" onClick={() => pouzijSablonu(s)}
                                    className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl border border-[var(--color-border)] px-3 text-[13px] text-[var(--color-text-secondary)] hover:border-[var(--color-accent)] hover:text-[var(--color-text-primary)]">
                                    {s.category?.color && (
                                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: s.category.color }}/>
                                    )}
                                    {s.name}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {/* Rychlé kategorie — jen u výdaje a příjmu. */}
                {(typ === 'expense' || typ === 'income') && (
                    <div className="mt-4">
                        <label className={POPISEK}>Kategorie</label>
                        <div className="grid grid-cols-3 gap-1.5 sm:grid-cols-4">
                            {(vsechnyKategorie ? kategorie : oblibene).map(k => (
                                <button key={k.uuid} type="button" onClick={() => uprav({ category: k.uuid })}
                                    aria-pressed={form.category === k.uuid}
                                    className={`min-h-[2.75rem] rounded-xl border px-2 py-1.5 text-left text-[12px] leading-tight transition-colors ${
                                        form.category === k.uuid
                                            ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                            : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                    }`}>
                                    <span className="mr-1 inline-block h-2 w-2 shrink-0 rounded-full align-middle"
                                        style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                                    {k.name}
                                </button>
                            ))}
                            {! vsechnyKategorie && kategorie.length > oblibene.length && (
                                <button type="button" onClick={() => setVsechnyKategorie(true)}
                                    className="min-h-[2.75rem] rounded-xl border border-dashed border-[var(--color-border)] px-2 text-[12px] text-[var(--color-text-secondary)]">
                                    Všechny…
                                </button>
                            )}
                        </div>
                        {chyby.category && <p className="mt-1 text-xs text-red-400">{chyby.category}</p>}
                    </div>
                )}

                {/* Účty. U směny a převodu obě strany. */}
                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                    {potrebaZdroj && (
                        <div>
                            <label className={POPISEK} htmlFor="fin-odkud">
                                {typ === 'expense' ? 'Zaplaceno z' : 'Odkud'}
                            </label>
                            <select id="fin-odkud" value={form.wallet_from}
                                onChange={e => zmenUcet('wallet_from', e.target.value)} className={POLE}>
                                <option value="">Vyberte účet</option>
                                {ciselniky.wallets.map(u => (
                                    <option key={u.uuid} value={u.uuid}>{u.name} · {penize(u.balance, u.currency)}</option>
                                ))}
                            </select>
                            {chyby.wallet_from && <p className="mt-1 text-xs text-red-400">{chyby.wallet_from}</p>}
                        </div>
                    )}

                    {potrebaCil && (
                        <div>
                            <label className={POPISEK} htmlFor="fin-kam">Kam</label>
                            <select id="fin-kam" value={form.wallet_to}
                                onChange={e => zmenUcet('wallet_to', e.target.value)} className={POLE}>
                                <option value="">Vyberte účet</option>
                                {ciselniky.wallets.map(u => (
                                    <option key={u.uuid} value={u.uuid}>{u.name} · {penize(u.balance, u.currency)}</option>
                                ))}
                            </select>
                            {chyby.wallet_to && <p className="mt-1 text-xs text-red-400">{chyby.wallet_to}</p>}
                        </div>
                    )}
                </div>

                {/* Směna: kolik doopravdy přišlo, poplatek a náhled kurzu. */}
                {typ === 'exchange' && (
                    <div className="mt-4 space-y-3">
                        <div>
                            <label className={POPISEK} htmlFor="fin-prislo">
                                Kolik doopravdy přišlo {cil && <span className="text-[var(--color-text-primary)]">({cil.currency})</span>}
                            </label>
                            <input id="fin-prislo" type="text" inputMode="decimal" value={form.amount_to}
                                onChange={e => uprav({ amount_to: e.target.value })} placeholder="0,00"
                                className={`${POLE} tabular-nums`}/>
                            {chyby.amount_to && <p className="mt-1 text-xs text-red-400">{chyby.amount_to}</p>}
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label className={POPISEK} htmlFor="fin-poplatek">Poplatek</label>
                                <input id="fin-poplatek" type="text" inputMode="decimal" value={form.fee_amount}
                                    onChange={e => uprav({ fee_amount: e.target.value })} placeholder="0,00" className={`${POLE} tabular-nums`}/>
                            </div>
                            <div>
                                <label className={POPISEK} htmlFor="fin-poskytovatel">Kde jste směnili</label>
                                <input id="fin-poskytovatel" list="fin-poskytovatele" value={form.provider}
                                    onChange={e => uprav({ provider: e.target.value })} placeholder="Revolut" className={POLE}/>
                                <datalist id="fin-poskytovatele">
                                    {['Revolut', 'Trading 212', 'IBKR', 'Banka', 'Směnárna'].map(p => <option key={p} value={p}/>)}
                                </datalist>
                            </div>
                        </div>

                        {/* Bez tohohle přepínače se efektivní kurz spočítat nedá: stejná
                            trojice čísel znamená dva různé kurzy podle toho, jestli je
                            poplatek uvnitř částky, nebo se platil navíc. */}
                        {(prectiCastku(form.fee_amount) ?? 0) > 0 && (
                            <div className="rounded-xl border border-[var(--color-border)] p-3">
                                <p className={POPISEK}>Ten poplatek…</p>
                                <div className="grid gap-1.5 sm:grid-cols-2">
                                    {[
                                        { v: false, popis: 'se platil navíc', vysvetleni: 'Z účtu odešel nad rámec směněné částky.' },
                                        { v: true, popis: 'je už v částkách', vysvetleni: 'Odepsaná částka ho už obsahuje.' },
                                    ].map(m => (
                                        <button key={String(m.v)} type="button" onClick={() => uprav({ fee_included: m.v })}
                                            aria-pressed={form.fee_included === m.v}
                                            className={`min-h-[2.75rem] rounded-lg border px-3 py-2 text-left text-xs ${
                                                form.fee_included === m.v
                                                    ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                                    : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                            }`}>
                                            <span className="block font-medium">{m.popis}</span>
                                            <span className="block text-[11px] opacity-80">{m.vysvetleni}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {nahledKurzu && (
                            <div className={`rounded-xl border p-3 ${nahledKurzu.extremni ? 'border-amber-500/40' : 'border-[var(--color-border)]'} bg-[var(--color-surface-muted)]`}>
                                <p className="text-sm text-[var(--color-text-primary)]">
                                    Za 1 € jste doopravdy zaplatili{' '}
                                    <strong className="tabular-nums">{kurz(nahledKurzu.kurz)} Kč</strong>
                                </p>
                                <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                    Za každých 1 000 Kč {nahledKurzu.doEur ? 'dostanete' : 'to odpovídá'}{' '}
                                    {kurz(nahledKurzu.zaTisic)} €.
                                    {' '}Z účtu odejde {penize(nahledKurzu.vydano, zdroj!.currency)}, přijde {penize(nahledKurzu.prijato, cil!.currency)}.
                                </p>
                                {nahledKurzu.extremni && (
                                    <p className="mt-1.5 flex items-start gap-1.5 text-[11px] text-amber-400">
                                        <AlertTriangle size={13} className="mt-px shrink-0"/>
                                        Tenhle kurz je hodně mimo obvyklý rozsah. Nesedí desetinná čárka nebo směr směny?
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {/* Kdo to nese — jen u výdaje a jen když jsou dva partneři. */}
                {typ === 'expense' && ciselniky.partners.length === 2 && (
                    <div className="mt-4">
                        <label className={POPISEK}>Čí to byl výdaj</label>
                        <div className="grid grid-cols-4 gap-1.5">
                            {[
                                { id: 'equal' as const, popis: 'Společné' },
                                { id: 'adri' as const, popis: ciselniky.partners[0].name },
                                { id: 'maki' as const, popis: ciselniky.partners[1].name },
                                { id: 'vlastni' as const, popis: 'Jiný poměr' },
                            ].map(m => (
                                <button key={m.id} type="button" onClick={() => uprav({ deleni: m.id })}
                                    aria-pressed={form.deleni === m.id}
                                    className={`min-h-[2.75rem] rounded-xl border px-1 text-[13px] leading-tight ${
                                        form.deleni === m.id
                                            ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                            : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                    }`}>
                                    {m.popis}
                                </button>
                            ))}
                        </div>

                        {/* Vlastní poměr. Zadává se jedno číslo, druhé se dopočítá —
                            dvě pole by dovolila napsat 60 a 60 a formulář by musel
                            odmítat něco, co vůbec nemuselo jít zadat. */}
                        {form.deleni === 'vlastni' && (
                            <div className="mt-2 rounded-xl border border-[var(--color-border)] p-3">
                                <label className={POPISEK} htmlFor="fin-podil">
                                    Podíl pro {ciselniky.partners[0].name}
                                </label>
                                <div className="flex items-center gap-2">
                                    <input id="fin-podil" type="range" min="0" max="100" step="5"
                                        value={form.podil_prvni}
                                        onChange={e => uprav({ podil_prvni: e.target.value })}
                                        className="min-h-11 flex-1 accent-[var(--color-accent)]"/>
                                    <span className="w-12 shrink-0 text-right text-sm tabular-nums text-[var(--color-text-primary)]">
                                        {form.podil_prvni} %
                                    </span>
                                </div>
                                <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                    {ciselniky.partners[0].name} nese {form.podil_prvni} %,{' '}
                                    {ciselniky.partners[1].name} zbylých {100 - Number(form.podil_prvni)} %
                                    {(() => {
                                        const c = prectiCastku(form.amount_from);
                                        if (! c || c <= 0) return null;
                                        const podil = Number(form.podil_prvni) / 100;
                                        const druhy = Math.round(c * (1 - podil) * 100) / 100;

                                        return ` — tedy ${penize(Math.round((c - druhy) * 100) / 100, zdroj?.currency ?? 'EUR')} a ${penize(druhy, zdroj?.currency ?? 'EUR')}`;
                                    })()}.
                                </p>
                            </div>
                        )}

                        {chyby.split && <p className="mt-1 text-xs text-red-400">{chyby.split}</p>}
                    </div>
                )}

                {/* Pokročilá pole. Zavřená — u devíti z deseti nákupů je netřeba. */}
                <button type="button" onClick={() => setDetaily(d => ! d)}
                    aria-expanded={detaily}
                    className="mt-4 flex min-h-[2.75rem] w-full items-center justify-between rounded-xl border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)]">
                    Další údaje
                    <ChevronDown size={16} className={`transition-transform ${detaily ? 'rotate-180' : ''}`}/>
                </button>

                {detaily && (
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className={POPISEK} htmlFor="fin-datum">Datum</label>
                            <input id="fin-datum" type="date" value={form.occurred_at}
                                onChange={e => uprav({ occurred_at: e.target.value })} className={POLE}/>
                        </div>

                        {typ === 'expense' && (
                            <div>
                                <label className={POPISEK} htmlFor="fin-obchodnik">Obchodník</label>
                                <input id="fin-obchodnik" value={form.counterparty}
                                    onChange={e => uprav({ counterparty: e.target.value })} className={POLE}/>
                            </div>
                        )}

                        {ciselniky.partners.length > 0 && typ !== 'transfer' && (
                            <div>
                                <label className={POPISEK} htmlFor="fin-platce">Kdo zaplatil</label>
                                <select id="fin-platce" value={form.payer_partner_id}
                                    onChange={e => uprav({ payer_partner_id: e.target.value })} className={POLE}>
                                    <option value="">Neuvedeno</option>
                                    {ciselniky.partners.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                                </select>
                            </div>
                        )}

                        {ciselniky.trips.length > 0 && (
                            <div>
                                <label className={POPISEK} htmlFor="fin-cesta">Cesta</label>
                                <select id="fin-cesta" value={form.trip}
                                    onChange={e => uprav({ trip: e.target.value })} className={POLE}>
                                    <option value="">Bez cesty</option>
                                    {ciselniky.trips.map(c => <option key={c.uuid} value={c.uuid}>{c.name}</option>)}
                                </select>
                            </div>
                        )}

                        <div>
                            <label className={POPISEK} htmlFor="fin-misto">Město nebo místo</label>
                            <input id="fin-misto" value={form.place}
                                onChange={e => uprav({ place: e.target.value })} className={POLE}/>
                        </div>

                        <div className="sm:col-span-2">
                            <label className={POPISEK} htmlFor="fin-popis">Poznámka</label>
                            <input id="fin-popis" value={form.description}
                                onChange={e => uprav({ description: e.target.value })} className={POLE}/>
                        </div>

                        {typ === 'expense' && (
                            <div className="sm:col-span-2 rounded-xl border border-[var(--color-border)] p-3">
                                <label className="flex items-start gap-2 text-sm text-[var(--color-text-primary)]">
                                    <input type="checkbox" checked={form.excluded_from_budget}
                                        onChange={e => uprav({ excluded_from_budget: e.target.checked })}
                                        className="mt-1 h-4 w-4"/>
                                    <span>
                                        Nepočítat do rozpočtu
                                        <span className="block text-[11px] text-[var(--color-text-secondary)]">
                                            Peníze z účtu odejdou, ale do čerpání se nezapočítají.
                                        </span>
                                    </span>
                                </label>
                                {form.excluded_from_budget && (
                                    <div className="mt-2">
                                        <input value={form.exclusion_reason} onChange={e => uprav({ exclusion_reason: e.target.value })}
                                            placeholder="Důvod — proč se to nemá počítat" className={POLE}/>
                                        {chyby.exclusion_reason && <p className="mt-1 text-xs text-red-400">{chyby.exclusion_reason}</p>}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {/* Varování: neobvyklé, ne špatné. Otázka, ne červené pole. */}
                {varovani.length > 0 && (
                    <div className="mt-4 space-y-2">
                        {varovani.map(v => (
                            <div key={v.key} className="rounded-xl border border-amber-500/40 bg-[var(--color-surface-muted)] p-3">
                                <p className="flex items-center gap-1.5 text-sm font-medium text-[var(--color-text-primary)]">
                                    <AlertTriangle size={15} className="shrink-0 text-amber-400"/> {v.title}
                                </p>
                                <p className="mt-1 text-xs leading-relaxed text-[var(--color-text-secondary)]">{v.body}</p>
                            </div>
                        ))}
                        <p className="text-xs text-[var(--color-text-secondary)]">
                            Když je to takhle správně, uložte znovu — potvrdí se tím, že o tom víte.
                        </p>
                    </div>
                )}

                <div className="h-4"/>
            </div>

            {/* Uložit dole a přilepené, ale nad bezpečnou zónou a nad klávesnicí. */}
            <div className="shrink-0 border-t border-[var(--color-border)] bg-[var(--color-bg-card)] px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom,0px))]">
                <div className="flex gap-2">
                    <button type="button" onClick={onZavrit}
                        className="inline-flex min-h-[2.75rem] items-center justify-center rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-secondary)]">
                        <X size={16}/>
                    </button>
                    <button type="button" onClick={() => void uloz(varovani.length > 0)}
                        disabled={ukladam || ! muzeUlozit}
                        className="inline-flex min-h-[2.75rem] flex-1 items-center justify-center gap-1.5 rounded-xl px-4 text-sm font-medium text-white disabled:opacity-40"
                        style={{ background: nastaveniTypu.barva }}>
                        <Check size={16}/>
                        {varovani.length > 0 ? 'Uložit i tak' : `Zapsat ${nastaveniTypu.akuzativ}`}
                    </button>
                </div>
                {ciselniky.wallets.length === 0 && (
                    <p className="mt-2 text-xs text-amber-400">Nejdřív si v Účtech založte aspoň jeden účet.</p>
                )}
            </div>
        </div>
    );
}
