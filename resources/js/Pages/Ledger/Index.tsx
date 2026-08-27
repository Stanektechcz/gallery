import { hlaska } from '@/Components/Hlasky';
import Panel, { PanelGrid, Stat } from '@/Components/Panel';
import SekceNav, { type Sekce as SekceTyp } from '@/Components/SekceNav';
import AppLayout from '@/Layouts/AppLayout';
import { pocet, transakce } from '@/lib/cestina';
import { naSirokeObrazovce } from '@/lib/zobrazeni';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle, ArrowRightLeft, Banknote, Building2, Check, LayoutDashboard, Pencil,
    Plus, Receipt, Scale, Trash2, TrendingUp, Users, Wallet as WalletIcon, X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Účetní kniha pro víc subjektů, měn a peněženek.
 *
 * Vedle rozpočtů, ne místo nich. Rozpočty řeší, kolik se smí utratit; kniha řeší, kde
 * peníze doopravdy jsou a kdo komu kolik dluží.
 *
 * Celá obrazovka stojí na jednom rozlišení, které se v běžných aplikacích plete:
 * **převod, směna a výběr nejsou výdaj.** Formulář to proto neschovává do jednoho
 * univerzálního pole — typ se volí první a podle něj se ukáže jen to, co k němu patří.
 * Kdo zapisuje výběr hotovosti, nemá vidět pole na obchodníka.
 */

type Wallet = {
    uuid: string; name: string; kind: string; kind_label: string;
    currency: string; partner: string | null; opening_balance: number; balance: number;
};

type Partner = { uuid: string; id: number; kind: string; kind_label: string; name: string; registration_no: string | null; is_active: boolean };

type Prehled = {
    wallets: Wallet[];
    result: { currencies: Array<{ currency: string; income: number; expense: number; fees: number; net: number }> };
    partners: Array<{ partner_id: number; name: string; currencies: Array<{ currency: string; paid: number; should_bear: number; balance: number }> }>;
    settlement_plan: Array<{ currency: string; from: string; to: string; amount: number }>;
    upcoming: { pending_by_currency: Record<string, number>; items: Array<{ uuid: string; type_label: string; occurred_at: string; amount: number; currency: string; description: string | null; state: string }> };
    flagged: {
        no_receipt: Array<{ uuid: string; amount: number; currency: string; occurred_at: string; description: string | null }>;
        exchange_without_reference: Array<{ uuid: string; occurred_at: string; summary: string }>;
        negative_wallets: Wallet[];
    };
    trend: Record<string, Array<{ month: string; income: number; expense: number; net: number }>>;
    not_available: Record<string, string>;
};

/** Řádek výpisu. Nese uuid peněženek, aby šel otevřít zpátky do formuláře. */
type Pohyb = {
    uuid: string; type: string; type_label: string; affects_result: boolean;
    occurred_at: string;
    from: { uuid: string; name: string; amount: number; currency: string } | null;
    to: { uuid: string; name: string; amount: number; currency: string } | null;
    fee: number; fee_currency: string | null;
    rate: number | null; reference_rate: number | null;
    payer: string | null; payer_partner_id: number | null;
    project: string | null; counterparty: string | null; description: string | null; state: string;
};

const castka = (c: number, mena: string) =>
    new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: mena, maximumFractionDigits: 2 }).format(c);

const mesic = (klic: string) =>
    new Date(`${klic}-01T12:00:00`).toLocaleDateString('cs-CZ', { month: 'long', year: 'numeric' });

const FIELD = 'w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2 text-sm text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const LABEL = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

/**
 * Typy transakcí i s tím, co k nim patří.
 *
 * `zdroj` a `cil` říkají, které strany se u typu vyplňují — formulář podle toho ukáže
 * jen to, co dává smysl. `presun` znamená, že to nemění výsledek, jen přesouvá peníze,
 * a obrazovka to má u zápisu rovnou napsat, aby to nikoho nepřekvapilo v přehledu.
 *
 * Každý typ nese i své tvary. Čeština má rody a pády: „směna" je ženská a „výdaj"
 * mužský, takže z jedné šablony „{nazev} je zapsaný" vyjde u poloviny typů nesmysl —
 * a v kódu to vypadá správně, protože tam je jen jedna věta. Proto se píšou celé:
 * `akuzativ` do tlačítka („Zapsat směnu"), `potvrzeni` do hlášky. Šest řádků navíc
 * proti šesti chybám, které se najdou až na obrazovce.
 */
const TYPY = [
    { id: 'expense', nazev: 'Výdaj', akuzativ: 'výdaj', potvrzeni: 'Výdaj je zapsaný.', zdroj: true, cil: false, presun: false, popis: 'Peníze odešly ven — nákup, faktura, útrata.' },
    { id: 'income', nazev: 'Příjem', akuzativ: 'příjem', potvrzeni: 'Příjem je zapsaný.', zdroj: false, cil: true, presun: false, popis: 'Peníze přišly zvenčí — výplata, platba od zákazníka, refundace.' },
    { id: 'transfer', nazev: 'Převod', akuzativ: 'převod', potvrzeni: 'Převod je zapsaný.', zdroj: true, cil: true, presun: true, popis: 'Přesun mezi vlastními peněženkami v téže měně. Nic se neutratilo.' },
    { id: 'exchange', nazev: 'Směna měny', akuzativ: 'směnu', potvrzeni: 'Směna je zapsaná.', zdroj: true, cil: true, presun: true, popis: 'Skutečným nákladem je jen poplatek. Zbytek peněz nezmizel, jen změnil měnu.' },
    { id: 'withdrawal', nazev: 'Výběr hotovosti', akuzativ: 'výběr', potvrzeni: 'Výběr hotovosti je zapsaný.', zdroj: true, cil: true, presun: true, popis: 'Z účtu do kapsy. Výdaj vznikne teprve utracením té hotovosti.' },
    { id: 'deposit', nazev: 'Vklad hotovosti', akuzativ: 'vklad', potvrzeni: 'Vklad hotovosti je zapsaný.', zdroj: true, cil: true, presun: true, popis: 'Z kapsy na účet.' },
] as const;

export default function LedgerIndex() {
    const [prehled, setPrehled] = useState<Prehled | null>(null);
    const [partneri, setPartneri] = useState<Partner[]>([]);
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');
    const [sekce, setSekce] = useState('prehled');
    const [upravovana, setUpravovana] = useState<Pohyb | null>(null);
    // Po uložení opravy musí výpis načíst znovu, i když se na něj vrací z jiné sekce.
    const [verze, setVerze] = useState(0);

    const nacti = useCallback(async () => {
        try {
            const [d, p] = await Promise.all([
                axios.get<Prehled>('/api/v1/kniha/prehled'),
                axios.get<{ partners: Partner[] }>('/api/v1/kniha/partneri'),
            ]);

            setPrehled(d.data);
            setPartneri(p.data.partners);
            setChyba('');
        } catch (problem: any) {
            setChyba(problem?.response?.status === 404
                ? 'Nejprve vytvořte nebo přijměte pozvánku do společného prostoru.'
                : 'Knihu se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, []);

    useEffect(() => { void nacti(); }, [nacti]);

    const sekce_seznam: SekceTyp[] = [
        { id: 'prehled', label: 'Přehled', icon: LayoutDashboard, upozorneni: (prehled?.flagged.negative_wallets.length ?? 0) > 0 },
        { id: 'zapis', label: upravovana ? 'Oprava' : 'Zápis', icon: upravovana ? Pencil : Plus },
        { id: 'pohyby', label: 'Pohyby', icon: Receipt },
        { id: 'penezenky', label: 'Peněženky', icon: WalletIcon, pocet: prehled?.wallets.length },
        { id: 'partneri', label: 'Partneři', icon: Users, pocet: partneri.length },
    ];

    return (
        <AppLayout>
            <Head title="Účetní kniha" />

            <div role="main" className="mx-auto max-w-[1600px] p-4 sm:p-6">
                <header className="mb-5">
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-[var(--color-text-primary)]">
                        <Scale size={20} className="text-[var(--color-accent)]"/> Účetní kniha
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">
                        Partneři, peněženky a všechno, co s penězi hne. Převod, směna ani výběr hotovosti
                        nejsou výdaj — jen přesouvají peníze, a kniha to rozlišuje.
                    </p>
                </header>

                {nacita && <p className="text-sm text-[var(--color-text-secondary)]">Načítám…</p>}
                {chyba && <p className="text-sm text-red-400">{chyba}</p>}

                {! nacita && ! chyba && prehled && (
                    <div className="space-y-4">
                        <SekceNav sekce={sekce_seznam} aktivni={sekce} onZmena={setSekce}/>

                        {sekce === 'prehled' && <Dashboard data={prehled}/>}

                        {/* `key` je tu schválně: přepnutí mezi opravou a novým zápisem musí
                            formulář postavit znovu, jinak by si nechal hodnoty předchozí
                            transakce a uložil je do jiné. */}
                        {sekce === 'zapis' && (
                            <Zapis key={upravovana?.uuid ?? 'novy'}
                                wallets={prehled.wallets} partners={partneri}
                                upravovana={upravovana}
                                onSaved={() => { setUpravovana(null); setVerze(v => v + 1); void nacti(); }}
                                onZrusit={() => setUpravovana(null)}/>
                        )}

                        {sekce === 'pohyby' && (
                            <Pohyby key={verze} wallets={prehled.wallets}
                                onUpravit={p => { setUpravovana(p); setSekce('zapis'); }}
                                onZmena={() => void nacti()}/>
                        )}
                        {sekce === 'penezenky' && <Penezenky wallets={prehled.wallets} partners={partneri} onChanged={() => void nacti()}/>}
                        {sekce === 'partneri' && <Partneri partners={partneri} onChanged={() => void nacti()}/>}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

/** Přehled podle druhého oddílu zadání. */
function Dashboard({ data }: { data: Prehled }) {
    const rizikovych = data.flagged.no_receipt.length + data.flagged.exchange_without_reference.length + data.flagged.negative_wallets.length;

    return (
        <div className="space-y-4">
            {/* Zůstatky napřed: „kde peníze jsou" je otázka, kvůli které se sem chodí. */}
            <section className="grid grid-cols-2 gap-2.5 sm:gap-3 xl:grid-cols-4">
                {data.wallets.slice(0, 8).map(p => (
                    <Stat key={p.uuid} label={p.name} icon={p.kind === 'cash' ? Banknote : WalletIcon}
                        tone={p.balance < 0 ? 'danger' : 'plain'}
                        value={castka(p.balance, p.currency)}
                        hint={p.partner ? `${p.kind_label} · ${p.partner}` : p.kind_label}/>
                ))}
                {data.wallets.length === 0 && (
                    <p className="col-span-full rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs text-[var(--color-text-secondary)]">
                        Zatím žádná peněženka. Založte bankovní účet nebo hotovost v sekci Peněženky.
                    </p>
                )}
            </section>

            <PanelGrid max={2}>
                <Panel icon={TrendingUp} title="Příjmy a výdaje"
                    description="Jen to, co opravdu mění výsledek. Převody, směny a výběry se sem nepočítají."
                    footnote="Poplatky jsou jediná část přesunů, která je skutečným nákladem — banka si je nechala a zpátky je nedá.">
                    {data.result.currencies.length === 0
                        ? <p className="text-xs text-[var(--color-text-secondary)]">Zatím žádné pohyby.</p>
                        : (
                            <div className="space-y-3">
                                {data.result.currencies.map(m => (
                                    <div key={m.currency}>
                                        <div className="flex items-baseline justify-between gap-2 text-sm">
                                            <span className="font-medium text-[var(--color-text-primary)]">{m.currency}</span>
                                            <span className={`tabular-nums ${m.net >= 0 ? 'text-emerald-400' : 'text-red-400'}`}>
                                                {m.net >= 0 ? '+' : '−'}{castka(Math.abs(m.net), m.currency)}
                                            </span>
                                        </div>
                                        <dl className="mt-1 grid grid-cols-3 gap-2 text-[11px]">
                                            <div><dt className="text-[var(--color-text-secondary)]">příjem</dt><dd className="tabular-nums text-[var(--color-text-primary)]">{castka(m.income, m.currency)}</dd></div>
                                            <div><dt className="text-[var(--color-text-secondary)]">výdaj</dt><dd className="tabular-nums text-[var(--color-text-primary)]">{castka(m.expense, m.currency)}</dd></div>
                                            <div><dt className="text-[var(--color-text-secondary)]">poplatky</dt><dd className="tabular-nums text-[var(--color-text-primary)]">{castka(m.fees, m.currency)}</dd></div>
                                        </dl>
                                    </div>
                                ))}
                            </div>
                        )}
                </Panel>

                <Panel icon={Scale} title="Kdo komu dluží"
                    description="Zaplatil proti tomu, co měl nést. Kladné saldo znamená, že ostatní dluží jemu."
                    footnote="Vyrovnání navrhuje nejmenší počet převodů. Každá měna zvlášť — převod korun nesmaže dluh v eurech, dokud někdo neurčí kurz.">
                    {data.partners.length === 0
                        ? <p className="text-xs text-[var(--color-text-secondary)]">Zatím není co dělit.</p>
                        : (
                            <>
                                <div className="space-y-2">
                                    {data.partners.map(p => (
                                        <div key={p.partner_id}>
                                            <p className="text-sm text-[var(--color-text-primary)]">{p.name}</p>
                                            {p.currencies.map(m => (
                                                <p key={m.currency} className="text-[11px] text-[var(--color-text-secondary)]">
                                                    {m.currency}: zaplatil {castka(m.paid, m.currency)}, měl nést {castka(m.should_bear, m.currency)}
                                                    <span className={`ml-1.5 tabular-nums ${m.balance >= 0 ? 'text-emerald-400' : 'text-red-400'}`}>
                                                        ({m.balance >= 0 ? '+' : '−'}{castka(Math.abs(m.balance), m.currency)})
                                                    </span>
                                                </p>
                                            ))}
                                        </div>
                                    ))}
                                </div>

                                {data.settlement_plan.length > 0 && (
                                    <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                                        <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">Návrh vyrovnání</p>
                                        {data.settlement_plan.map((n, i) => (
                                            <p key={i} className="text-xs text-[var(--color-text-primary)]">
                                                {n.from} → {n.to} <span className="tabular-nums">{castka(n.amount, n.currency)}</span>
                                            </p>
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                </Panel>

                {rizikovych > 0 && (
                    <Panel tone="warn" icon={AlertTriangle} title="Vyžaduje pozornost">
                        <div className="space-y-2.5 text-xs">
                            {data.flagged.negative_wallets.length > 0 && (
                                <p className="text-[var(--color-text-primary)]">
                                    <strong>Peněženka v mínusu:</strong>{' '}
                                    {data.flagged.negative_wallets.map(p => `${p.name} (${castka(p.balance, p.currency)})`).join(', ')}
                                    <span className="mt-0.5 block text-[var(--color-text-secondary)]">Buď se něco zapsalo špatně, nebo se opravdu čerpalo do minusu — obojí je potřeba vidět.</span>
                                </p>
                            )}
                            {data.flagged.no_receipt.length > 0 && (
                                <p className="text-[var(--color-text-primary)]">
                                    <strong>{pocet(data.flagged.no_receipt.length, 'výdaj bez dokladu', 'výdaje bez dokladu', 'výdajů bez dokladu')}</strong>
                                    <span className="mt-0.5 block text-[var(--color-text-secondary)]">Nejvyšší: {castka(data.flagged.no_receipt[0].amount, data.flagged.no_receipt[0].currency)}. Po půl roce se to nedoloží.</span>
                                </p>
                            )}
                            {data.flagged.exchange_without_reference.length > 0 && (
                                <p className="text-[var(--color-text-primary)]">
                                    <strong>{pocet(data.flagged.exchange_without_reference.length, 'směna bez referenčního kurzu', 'směny bez referenčního kurzu', 'směn bez referenčního kurzu')}</strong>
                                    <span className="mt-0.5 block text-[var(--color-text-secondary)]">Bez něj se zpětně nedá zkontrolovat, jestli byl kurz férový.</span>
                                </p>
                            )}
                        </div>
                    </Panel>
                )}

                {Object.keys(data.trend).length > 0 && (
                    <Panel icon={TrendingUp} title="Vývoj po měsících"
                        footnote="Jen příjmy a výdaje. U přesunů by graf ukazoval hory a doly podle toho, kdy kdo směnil peníze — o hospodaření to neříká nic.">
                        <div className="space-y-3">
                            {Object.entries(data.trend).map(([mena, mesice]) => {
                                const nejvic = Math.max(...mesice.flatMap(m => [m.income, m.expense]), 1);

                                return (
                                    <div key={mena}>
                                        <p className="mb-1.5 text-[11px] font-medium text-[var(--color-text-secondary)]">{mena}</p>
                                        {mesice.map(m => (
                                            <div key={m.month} className="mb-1.5">
                                                <div className="flex items-baseline justify-between text-[11px]">
                                                    <span className="text-[var(--color-text-primary)]">{mesic(m.month)}</span>
                                                    <span className={`tabular-nums ${m.net >= 0 ? 'text-emerald-400' : 'text-red-400'}`}>
                                                        {m.net >= 0 ? '+' : '−'}{castka(Math.abs(m.net), mena)}
                                                    </span>
                                                </div>
                                                <div className="mt-0.5 flex gap-1">
                                                    <div className="h-1.5 rounded-sm bg-emerald-400/70" style={{ width: `${m.income / nejvic * 50}%` }}/>
                                                    <div className="h-1.5 rounded-sm bg-[var(--color-accent)]/70" style={{ width: `${m.expense / nejvic * 50}%` }}/>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                );
                            })}
                        </div>
                    </Panel>
                )}
            </PanelGrid>

            {/* Co zadání chce, ale zatím nemá odkud vzít. Napsané, ne spočítané jako nula. */}
            <Panel icon={AlertTriangle} title="Zatím nedostupné"
                description="Vzorec ze zadání počítá i s rezervacemi a závazky. Ty vznikají z objednávek a ten modul zatím není hotový — tak se to sem píše, místo aby se to tiše sečetlo jako nula.">
                <ul className="space-y-1 text-xs text-[var(--color-text-secondary)]">
                    {Object.entries(data.not_available).map(([klic, popis]) => (
                        <li key={klic}>· {popis}</li>
                    ))}
                </ul>
            </Panel>
        </div>
    );
}

/**
 * Zápis transakce.
 *
 * Typ se volí první a formulář se podle něj přestaví. Jednotné pole „částka" se šesti
 * významy by znamenalo, že se výběr hotovosti zapíše jako výdaj — což je přesně chyba,
 * kvůli které celá kniha rozlišuje typy.
 */
const PRAZDNY_FORMULAR = {
    occurred_at: new Date().toISOString().slice(0, 10),
    wallet_from: '', wallet_to: '', amount_from: '', amount_to: '',
    fee_amount: '', reference_rate: '', rate_source: '',
    payer_partner_id: '', counterparty: '', description: '',
};

/** Rozloží zapsaný pohyb zpátky do formuláře. */
const doFormulare = (p: Pohyb) => ({
    occurred_at: p.occurred_at,
    wallet_from: p.from?.uuid ?? '',
    wallet_to: p.to?.uuid ?? '',
    amount_from: p.from ? String(p.from.amount) : '',
    amount_to: p.to ? String(p.to.amount) : '',
    fee_amount: p.fee ? String(p.fee) : '',
    reference_rate: p.reference_rate !== null ? String(p.reference_rate) : '',
    rate_source: '',
    payer_partner_id: p.payer_partner_id !== null ? String(p.payer_partner_id) : '',
    counterparty: p.counterparty ?? '',
    description: p.description ?? '',
});

/**
 * Zápis i oprava.
 *
 * Jeden formulář pro obojí, protože po opravě musí platit stejná pravidla jako po
 * zápisu — dva formuláře by znamenaly dvě sady kontrol, které se časem rozejdou.
 * Při opravě se posílá celá transakce, ne jen změněná pole; server ji pak kontroluje
 * úplně stejně, jako by se zapisovala poprvé.
 */
function Zapis({ wallets, partners, upravovana, onSaved, onZrusit }: {
    wallets: Wallet[]; partners: Partner[]; upravovana: Pohyb | null;
    onSaved: () => void; onZrusit: () => void;
}) {
    const [typ, setTyp] = useState<typeof TYPY[number]['id']>(
        (upravovana?.type as typeof TYPY[number]['id']) ?? 'expense');
    const [form, setForm] = useState(upravovana ? doFormulare(upravovana) : PRAZDNY_FORMULAR);
    const [uklada, setUklada] = useState(false);

    const nastaveni = TYPY.find(t => t.id === typ)!;
    const zdroj = wallets.find(p => p.uuid === form.wallet_from);
    const cil = wallets.find(p => p.uuid === form.wallet_to);

    const uloz = async () => {
        setUklada(true);

        try {
            const telo = {
                type: typ,
                occurred_at: form.occurred_at,
                wallet_from: nastaveni.zdroj ? form.wallet_from : undefined,
                wallet_to: nastaveni.cil ? form.wallet_to : undefined,
                amount_from: form.amount_from === '' ? undefined : Number(form.amount_from),
                amount_to: form.amount_to === '' ? undefined : Number(form.amount_to),
                fee_amount: form.fee_amount === '' ? undefined : Number(form.fee_amount),
                reference_rate: form.reference_rate === '' ? undefined : Number(form.reference_rate),
                rate_source: form.rate_source || undefined,
                payer_partner_id: form.payer_partner_id === '' ? undefined : Number(form.payer_partner_id),
                counterparty: form.counterparty || undefined,
                description: form.description || undefined,
            };

            if (upravovana) {
                await axios.patch(`/api/v1/kniha/transakce/${upravovana.uuid}`, telo);
                hlaska('Změna je uložená.', 'uspech');
            } else {
                await axios.post('/api/v1/kniha/transakce', telo);
                hlaska(nastaveni.potvrzeni, 'uspech');
                setForm(f => ({ ...f, amount_from: '', amount_to: '', fee_amount: '', counterparty: '', description: '' }));
            }

            onSaved();
        } catch (problem: any) {
            // Hlášky o nesmyslných kombinacích posílá server a stojí za to je ukázat
            // doslova — říkají přesně, co je špatně, a nabízejí správný typ.
            hlaska(problem?.response?.data?.message
                ?? (upravovana ? 'Změnu se nepodařilo uložit.' : 'Transakci se nepodařilo zapsat.'), 'chyba');
        } finally {
            setUklada(false);
        }
    };

    const hotovo = form.occurred_at
        && (! nastaveni.zdroj || form.wallet_from)
        && (! nastaveni.cil || form.wallet_to)
        && (form.amount_from !== '' || form.amount_to !== '');

    return (
        <Panel icon={upravovana ? Pencil : Plus}
            title={upravovana ? 'Oprava zápisu' : 'Zápis do knihy'}
            description={nastaveni.popis}
            tone={nastaveni.presun ? 'accent' : 'plain'}>

            {upravovana && (
                <p className="mb-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    Opravujete zápis z {new Date(upravovana.occurred_at).toLocaleDateString('cs-CZ')}. Po uložení
                    se přepočítají zůstatky peněženek i vyrovnání mezi partnery.
                </p>
            )}

            {/* Typ napřed. Podle něj se formulář přestaví — u výběru hotovosti nemá být
                vidět pole na obchodníka, protože z kapsy do kapsy se nenakupuje. */}
            <div className="-mx-1 mb-4 flex gap-1.5 overflow-x-auto px-1 pb-1">
                {TYPY.map(t => (
                    <button key={t.id} type="button" onClick={() => setTyp(t.id)}
                        className={`min-h-10 shrink-0 rounded-xl px-3 text-sm transition-colors ${
                            t.id === typ
                                ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]'
                                : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                        }`}>
                        {t.nazev}
                    </button>
                ))}
            </div>

            {nastaveni.presun && (
                <p className="mb-3 rounded-xl border border-[var(--color-accent)]/30 bg-[var(--color-surface-muted)] px-3 py-2 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    <strong className="text-[var(--color-text-primary)]">Do výdajů se to nepočítá.</strong>{' '}
                    Peníze se jen přesunou — {typ === 'exchange' ? 'skutečným nákladem je jen poplatek.' : 'výdaj vznikne teprve jejich utracením.'}
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label className={LABEL} htmlFor="kniha-datum">Datum</label>
                    <input id="kniha-datum" type="date" value={form.occurred_at}
                        onChange={e => setForm(f => ({ ...f, occurred_at: e.target.value }))} className={FIELD}/>
                </div>

                {nastaveni.zdroj && (
                    <>
                        <div>
                            <label className={LABEL} htmlFor="kniha-odkud">Odkud</label>
                            <select id="kniha-odkud" value={form.wallet_from}
                                onChange={e => setForm(f => ({ ...f, wallet_from: e.target.value }))} className={FIELD}>
                                <option value="">Vyberte peněženku</option>
                                {wallets.map(p => <option key={p.uuid} value={p.uuid}>{p.name} ({p.currency})</option>)}
                            </select>
                        </div>
                        <div>
                            <label className={LABEL} htmlFor="kniha-castka-z">
                                Odepsaná částka {zdroj && <span className="text-[var(--color-text-primary)]">({zdroj.currency})</span>}
                            </label>
                            <input id="kniha-castka-z" type="number" inputMode="decimal" value={form.amount_from}
                                onChange={e => setForm(f => ({ ...f, amount_from: e.target.value }))} className={FIELD}/>
                        </div>
                    </>
                )}

                {nastaveni.cil && (
                    <>
                        <div>
                            <label className={LABEL} htmlFor="kniha-kam">Kam</label>
                            <select id="kniha-kam" value={form.wallet_to}
                                onChange={e => setForm(f => ({ ...f, wallet_to: e.target.value }))} className={FIELD}>
                                <option value="">Vyberte peněženku</option>
                                {wallets.map(p => <option key={p.uuid} value={p.uuid}>{p.name} ({p.currency})</option>)}
                            </select>
                        </div>
                        <div>
                            <label className={LABEL} htmlFor="kniha-castka-do">
                                Připsaná částka {cil && <span className="text-[var(--color-text-primary)]">({cil.currency})</span>}
                            </label>
                            <input id="kniha-castka-do" type="number" inputMode="decimal" value={form.amount_to}
                                onChange={e => setForm(f => ({ ...f, amount_to: e.target.value }))} className={FIELD}/>
                        </div>
                    </>
                )}

                {/* Poplatek a referenční kurz jen u směny — jinde nemají co dělat. */}
                {typ === 'exchange' && (
                    <>
                        <div>
                            <label className={LABEL} htmlFor="kniha-poplatek">Poplatek {zdroj && `(${zdroj.currency})`}</label>
                            <input id="kniha-poplatek" type="number" inputMode="decimal" value={form.fee_amount}
                                onChange={e => setForm(f => ({ ...f, fee_amount: e.target.value }))} className={FIELD}/>
                        </div>
                        <div>
                            <label className={LABEL} htmlFor="kniha-kurz">Referenční kurz</label>
                            <input id="kniha-kurz" type="number" inputMode="decimal" step="0.0001" value={form.reference_rate}
                                onChange={e => setForm(f => ({ ...f, reference_rate: e.target.value }))} placeholder="např. 24,05" className={FIELD}/>
                            <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                Kurz ČNB nebo ECB k tomu dni. Skutečný kurz se dopočítá z částek sám.
                            </p>
                        </div>
                    </>
                )}

                {typ === 'expense' && (
                    <>
                        <div>
                            <label className={LABEL} htmlFor="kniha-platce">Kdo zaplatil</label>
                            <select id="kniha-platce" value={form.payer_partner_id}
                                onChange={e => setForm(f => ({ ...f, payer_partner_id: e.target.value }))} className={FIELD}>
                                <option value="">Neuvedeno</option>
                                {partners.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className={LABEL} htmlFor="kniha-obchodnik">Obchodník nebo dodavatel</label>
                            <input id="kniha-obchodnik" value={form.counterparty}
                                onChange={e => setForm(f => ({ ...f, counterparty: e.target.value }))} className={FIELD}/>
                        </div>
                    </>
                )}

                <div className="sm:col-span-2 lg:col-span-3">
                    <label className={LABEL} htmlFor="kniha-popis">Popis</label>
                    <input id="kniha-popis" value={form.description}
                        onChange={e => setForm(f => ({ ...f, description: e.target.value }))} className={FIELD}/>
                </div>
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-[var(--color-border)] pt-4">
                <button type="button" onClick={() => void uloz()} disabled={uklada || ! hotovo}
                    className="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                    <Check size={15}/> {upravovana ? 'Uložit změnu' : `Zapsat ${nastaveni.akuzativ}`}
                </button>
                {upravovana && (
                    <button type="button" onClick={onZrusit}
                        className="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                        <X size={15}/> Zrušit opravu
                    </button>
                )}
                {wallets.length === 0 && (
                    <span className="text-xs text-red-400">Nejdřív založte aspoň jednu peněženku.</span>
                )}
            </div>
        </Panel>
    );
}

/**
 * Výpis pohybů.
 *
 * Do teď šlo zapsat a nešlo opravit — překlep v částce zůstal v knize navždycky a
 * zůstatek podle něj nesouhlasil. Řádek proto nabízí opravu i smazání.
 *
 * Filtry drží typ a rozsah, ne text: hledání ve stovkách řádků potřebuje jiný index
 * a bez něj by se to na telefonu jen zaseklo. Kdo hledá konkrétní nákup, zúží typ a
 * období — to zvládne i malá kniha.
 */
function Pohyby({ wallets, onUpravit, onZmena }: {
    wallets: Wallet[]; onUpravit: (p: Pohyb) => void; onZmena: () => void;
}) {
    const [radky, setRadky] = useState<Pohyb[]>([]);
    const [nalezeno, setNalezeno] = useState(0);
    const [nacita, setNacita] = useState(true);
    const [filtr, setFiltr] = useState({ type: '', wallet: '', from: '', to: '' });
    const [mazany, setMazany] = useState<string | null>(null);

    const nacti = useCallback(async () => {
        setNacita(true);

        try {
            const { data } = await axios.get<{ found: number; transactions: Pohyb[] }>(
                '/api/v1/kniha/transakce',
                { params: {
                    type: filtr.type || undefined,
                    wallet: filtr.wallet || undefined,
                    from: filtr.from || undefined,
                    to: filtr.to || undefined,
                } },
            );

            setRadky(data.transactions);
            setNalezeno(data.found);
        } catch {
            hlaska('Výpis se nepodařilo načíst.', 'chyba');
        } finally {
            setNacita(false);
        }
    }, [filtr]);

    useEffect(() => { void nacti(); }, [nacti]);

    const smaz = async (p: Pohyb) => {
        setMazany(p.uuid);

        try {
            await axios.delete(`/api/v1/kniha/transakce/${p.uuid}`);
            hlaska('Zápis je smazaný, zůstatky se přepočítaly.', 'uspech');
            await nacti();
            onZmena();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Zápis se nepodařilo smazat.', 'chyba');
        } finally {
            setMazany(null);
        }
    };

    return (
        <Panel icon={Receipt} title="Pohyby"
            description={nacita ? 'Načítám…' : `${transakce(nalezeno)} v knize.`}>

            <div className="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label className={LABEL} htmlFor="pohyby-typ">Typ</label>
                    <select id="pohyby-typ" value={filtr.type}
                        onChange={e => setFiltr(f => ({ ...f, type: e.target.value }))} className={FIELD}>
                        <option value="">Všechny</option>
                        {TYPY.map(t => <option key={t.id} value={t.id}>{t.nazev}</option>)}
                    </select>
                </div>
                <div>
                    <label className={LABEL} htmlFor="pohyby-penezenka">Peněženka</label>
                    <select id="pohyby-penezenka" value={filtr.wallet}
                        onChange={e => setFiltr(f => ({ ...f, wallet: e.target.value }))} className={FIELD}>
                        <option value="">Všechny</option>
                        {wallets.map(p => <option key={p.uuid} value={p.uuid}>{p.name}</option>)}
                    </select>
                </div>
                <div>
                    <label className={LABEL} htmlFor="pohyby-od">Od</label>
                    <input id="pohyby-od" type="date" value={filtr.from}
                        onChange={e => setFiltr(f => ({ ...f, from: e.target.value }))} className={FIELD}/>
                </div>
                <div>
                    <label className={LABEL} htmlFor="pohyby-do">Do</label>
                    <input id="pohyby-do" type="date" value={filtr.to}
                        onChange={e => setFiltr(f => ({ ...f, to: e.target.value }))} className={FIELD}/>
                </div>
            </div>

            {! nacita && radky.length === 0 && (
                <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-6 text-center text-sm text-[var(--color-text-secondary)]">
                    {nalezeno === 0 && ! filtr.type && ! filtr.wallet && ! filtr.from && ! filtr.to
                        ? 'V knize zatím nic není. Začněte zápisem.'
                        : 'Tomuhle výběru nic neodpovídá. Zkuste rozšířit období nebo zrušit filtr typu.'}
                </p>
            )}

            <ul className="space-y-2">
                {radky.map(p => (
                    <li key={p.uuid}
                        className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3">
                        <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium text-[var(--color-text-primary)]">{p.type_label}</span>
                                    {/* Přesuny se odlišují, protože v přehledu výdajů nejsou — a kdo
                                        je hledá mezi výdaji, má hned vidět proč tam nejsou. */}
                                    {! p.affects_result && (
                                        <span className="rounded-full border border-[var(--color-border)] px-2 py-0.5 text-[11px] text-[var(--color-text-secondary)]">
                                            přesun, ne výdaj
                                        </span>
                                    )}
                                    <span className="text-xs text-[var(--color-text-secondary)]">
                                        {new Date(p.occurred_at).toLocaleDateString('cs-CZ')}
                                    </span>
                                </div>

                                <p className="mt-1 truncate text-xs text-[var(--color-text-secondary)]">
                                    {p.from && <>{p.from.name} −{castka(p.from.amount, p.from.currency)}</>}
                                    {p.from && p.to && ' → '}
                                    {p.to && <>{p.to.name} +{castka(p.to.amount, p.to.currency)}</>}
                                    {p.fee > 0 && p.fee_currency && <> · poplatek {castka(p.fee, p.fee_currency)}</>}
                                </p>

                                {(p.counterparty || p.description || p.payer) && (
                                    <p className="mt-0.5 truncate text-xs text-[var(--color-text-secondary)]">
                                        {[p.counterparty, p.description, p.payer && `platil ${p.payer}`]
                                            .filter(Boolean).join(' · ')}
                                    </p>
                                )}
                            </div>

                            <div className="flex shrink-0 gap-1">
                                <button type="button" onClick={() => onUpravit(p)}
                                    aria-label={`Opravit zápis z ${new Date(p.occurred_at).toLocaleDateString('cs-CZ')}`}
                                    className="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                                    <Pencil size={15}/>
                                </button>
                                <button type="button" onClick={() => void smaz(p)} disabled={mazany === p.uuid}
                                    aria-label={`Smazat zápis z ${new Date(p.occurred_at).toLocaleDateString('cs-CZ')}`}
                                    className="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:border-red-500/40 hover:text-red-400 disabled:opacity-40">
                                    <Trash2 size={15}/>
                                </button>
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/** Peněženky i s jejich zůstatky. */
function Penezenky({ wallets, partners, onChanged }: { wallets: Wallet[]; partners: Partner[]; onChanged: () => void }) {
    const [pridava, setPridava] = useState(false);
    const [form, setForm] = useState({ name: '', kind: 'bank', currency: 'CZK', partner_id: '', opening_balance: '' });
    const [uklada, setUklada] = useState(false);

    const pridej = async () => {
        if (! form.name.trim()) return;

        setUklada(true);

        try {
            await axios.post('/api/v1/kniha/penezenky', {
                name: form.name.trim(),
                kind: form.kind,
                currency: form.currency,
                partner_id: form.partner_id === '' ? null : Number(form.partner_id),
                opening_balance: form.opening_balance === '' ? 0 : Number(form.opening_balance),
            });

            setForm({ name: '', kind: 'bank', currency: 'CZK', partner_id: '', opening_balance: '' });
            setPridava(false);
            hlaska('Peněženka je založená.', 'uspech');
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Peněženku se nepodařilo založit.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    return (
        <Panel icon={WalletIcon} title="Peněženky"
            description="Bankovní účet, hotovost, karta. Jedna měna na peněženku — účet, na kterém leží koruny i eura, jsou ve skutečnosti dva účty."
            actions={! pridava && (
                <button type="button" onClick={() => setPridava(true)}
                    className="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] hover:border-[var(--color-accent)] hover:text-[var(--color-text-primary)]">
                    <Plus size={13}/> Nová peněženka
                </button>
            )}>

            <div className="space-y-2">
                {wallets.map(p => (
                    <div key={p.uuid} className="flex items-baseline justify-between gap-2 border-b border-[var(--color-border)] pb-2 last:border-0">
                        <span className="min-w-0">
                            <span className="block truncate text-sm text-[var(--color-text-primary)]">{p.name}</span>
                            <span className="block text-[11px] text-[var(--color-text-secondary)]">
                                {p.kind_label}{p.partner && ` · ${p.partner}`} · počáteční stav {castka(p.opening_balance, p.currency)}
                            </span>
                        </span>
                        <span className={`shrink-0 text-sm font-medium tabular-nums ${p.balance < 0 ? 'text-red-400' : 'text-[var(--color-text-primary)]'}`}>
                            {castka(p.balance, p.currency)}
                        </span>
                    </div>
                ))}
                {wallets.length === 0 && ! pridava && (
                    <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs text-[var(--color-text-secondary)]">
                        Zatím žádná peněženka. Bez ní se nedá zapsat nic — peníze musí odněkud odejít nebo někam přijít.
                    </p>
                )}
            </div>

            {pridava && (
                <div className="mt-3 grid gap-2 border-t border-[var(--color-border)] pt-3 sm:grid-cols-2 lg:grid-cols-3">
                    <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                        placeholder="Běžný účet" aria-label="Název peněženky" className={`${FIELD} sm:col-span-2`}/>
                    <select value={form.kind} onChange={e => setForm(f => ({ ...f, kind: e.target.value }))} aria-label="Druh" className={FIELD}>
                        <option value="bank">bankovní účet</option>
                        <option value="cash">hotovost</option>
                        <option value="card">karta</option>
                        <option value="other">jiné</option>
                    </select>
                    <select value={form.currency} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} aria-label="Měna" className={FIELD}>
                        {['CZK', 'EUR', 'USD', 'GBP', 'PLN', 'CHF'].map(m => <option key={m} value={m}>{m}</option>)}
                    </select>
                    <select value={form.partner_id} onChange={e => setForm(f => ({ ...f, partner_id: e.target.value }))} aria-label="Patří partnerovi" className={FIELD}>
                        <option value="">společná</option>
                        {partners.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                    <input type="number" inputMode="decimal" value={form.opening_balance}
                        onChange={e => setForm(f => ({ ...f, opening_balance: e.target.value }))}
                        placeholder="Počáteční stav" aria-label="Počáteční stav" className={FIELD}/>
                    <div className="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <button type="button" onClick={() => void pridej()} disabled={uklada || ! form.name.trim()}
                            className="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                            <Check size={14}/> Založit
                        </button>
                        <button type="button" onClick={() => setPridava(false)}
                            className="inline-flex min-h-10 items-center rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)]">
                            <X size={14}/> Zrušit
                        </button>
                    </div>
                </div>
            )}
        </Panel>
    );
}

/** Partneři — osoby, firmy i organizace. */
function Partneri({ partners, onChanged }: { partners: Partner[]; onChanged: () => void }) {
    const [pridava, setPridava] = useState(naSirokeObrazovce);
    const [form, setForm] = useState({ name: '', kind: 'person', registration_no: '' });
    const [uklada, setUklada] = useState(false);

    const pridej = async () => {
        if (! form.name.trim()) return;

        setUklada(true);

        try {
            await axios.post('/api/v1/kniha/partneri', {
                name: form.name.trim(),
                kind: form.kind,
                registration_no: form.registration_no || null,
            });

            setForm({ name: '', kind: 'person', registration_no: '' });
            hlaska('Partner je založený.', 'uspech');
            onChanged();
        } catch (problem: any) {
            hlaska(problem?.response?.data?.message ?? 'Partnera se nepodařilo založit.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    return (
        <Panel icon={Users} title="Partneři"
            description="Protistrany i vlastníci peněz. Může to být člověk, firma nebo organizace — dodavatel vystupuje v knize stejně jako kdokoliv jiný.">
            <div className="space-y-2">
                {partners.map(p => (
                    <div key={p.uuid} className="flex items-center gap-2 border-b border-[var(--color-border)] pb-2 last:border-0">
                        {p.kind === 'person' ? <Users size={15} className="shrink-0 text-[var(--color-text-secondary)]"/> : <Building2 size={15} className="shrink-0 text-[var(--color-text-secondary)]"/>}
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm text-[var(--color-text-primary)]">{p.name}</span>
                            <span className="block text-[11px] text-[var(--color-text-secondary)]">
                                {p.kind_label}{p.registration_no && ` · IČO ${p.registration_no}`}
                            </span>
                        </span>
                    </div>
                ))}
                {partners.length === 0 && ! pridava && (
                    <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs text-[var(--color-text-secondary)]">
                        Zatím žádný partner.
                    </p>
                )}
            </div>

            <div className="mt-3 grid gap-2 border-t border-[var(--color-border)] pt-3 sm:grid-cols-[1fr_10rem_10rem_auto]">
                <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                    placeholder="Jméno nebo název" aria-label="Jméno partnera" className={FIELD}/>
                <select value={form.kind} onChange={e => setForm(f => ({ ...f, kind: e.target.value }))} aria-label="Druh partnera" className={FIELD}>
                    <option value="person">osoba</option>
                    <option value="company">firma</option>
                    <option value="organization">organizace</option>
                </select>
                <input value={form.registration_no} onChange={e => setForm(f => ({ ...f, registration_no: e.target.value }))}
                    placeholder="IČO" aria-label="IČO" className={FIELD} disabled={form.kind === 'person'}/>
                <button type="button" onClick={() => void pridej()} disabled={uklada || ! form.name.trim()}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                    <Plus size={14}/> Přidat
                </button>
            </div>
        </Panel>
    );
}
