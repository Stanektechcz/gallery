import { hlaska } from '@/Components/Hlasky';
import { datum, penize } from '@/lib/penize';
import axios from 'axios';
import { CalendarClock, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Dialog } from './Ucty';
import type { Ciselniky } from './typy';

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2.5 text-base text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const POPISEK = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

type Predpis = {
    uuid: string; name: string; type: string;
    amount: number; currency: string;
    wallet: { uuid: string; name: string } | null;
    category: { uuid: string; name: string; color: string | null } | null;
    trip: string | null;
    day_of_month: number;
    starts_on: string; ends_on: string | null;
    split: string | null;
    is_active: boolean;
    paid_count: number;
    remaining_to_trip_end: number;
};

/**
 * Pravidelné platby — nájem se zapíše jednou a chodí sám.
 *
 * Předpis je vzor, ne transakce. Splátky z něj vznikají **jen do dneška**: nájem,
 * který se zaplatí příští měsíc, z účtu ještě neodešel a zapsat ho předem by dalo
 * zůstatek, který nesedí s bankou. Co teprve přijde, je vidět jako závazek.
 */
export default function Pravidelne({ ciselniky, onZmena, onZavrit }: {
    ciselniky: Ciselniky;
    onZmena: () => void;
    onZavrit: () => void;
}) {
    const [predpisy, setPredpisy] = useState<Predpis[]>([]);
    const [nacita, setNacita] = useState(true);
    const [pridava, setPridava] = useState(false);

    const cesta = ciselniky.active_trip;

    useEffect(() => {
        void axios.get<{ recurring: Predpis[] }>('/api/v1/rozpocet/pravidelne')
            .then(({ data }) => { setPredpisy(data.recurring); onZmena(); })
            .catch(() => hlaska('Pravidelné platby se nepodařilo načíst.', 'chyba'))
            .finally(() => setNacita(false));
    }, []);

    const smaz = async (p: Predpis) => {
        try {
            const { data } = await axios.delete(`/api/v1/rozpocet/pravidelne/${p.uuid}`);
            setPredpisy(data.recurring);
            hlaska(data.kept > 0
                ? `Předpis je smazaný. ${data.kept} už zapsaných splátek zůstalo — ty peníze opravdu odešly.`
                : 'Předpis je smazaný.', 'uspech');
            onZmena();
        } catch {
            hlaska('Předpis se nepodařilo smazat.', 'chyba');
        }
    };

    const prepni = async (p: Predpis) => {
        try {
            const { data } = await axios.patch(`/api/v1/rozpocet/pravidelne/${p.uuid}`, { is_active: ! p.is_active });
            setPredpisy(data.recurring);
            onZmena();
        } catch {
            hlaska('Změnu se nepodařilo uložit.', 'chyba');
        }
    };

    const zavazky = predpisy.filter(p => p.is_active).reduce((s, p) => s + p.remaining_to_trip_end, 0);

    return (
        <Dialog nadpis="Pravidelné platby" onZavrit={onZavrit}>
            <p className="mb-3 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                Nájem, telefon, pojištění. Zapíše se jednou a splátky pak přibývají samy —
                ale jen ty, které už měly proběhnout. Co teprve přijde, se hlásí zvlášť.
            </p>

            {nacita && <p className="text-xs text-[var(--color-text-secondary)]">Načítám…</p>}

            {! nacita && predpisy.length === 0 && (
                <p className="mb-3 rounded-xl border border-dashed border-[var(--color-border)] px-3 py-5 text-center text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    Zatím žádná. Vyplatí se u všeho, co chodí každý měsíc — hlavně u nájmu:
                    bez něj rozpočet tvrdí, že zbývá víc, než kolik doopravdy zbývá.
                </p>
            )}

            {! nacita && predpisy.length > 0 && (
                <>
                    <ul className="mb-3 space-y-1.5">
                        {predpisy.map(p => (
                            <li key={p.uuid}
                                className={`rounded-xl border p-3 ${p.is_active ? 'border-[var(--color-border)]' : 'border-dashed border-[var(--color-border)] opacity-60'}`}>
                                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        {p.category?.color && (
                                            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: p.category.color }}/>
                                        )}
                                        <span className="truncate text-sm font-medium text-[var(--color-text-primary)]">{p.name}</span>
                                    </span>
                                    <span className="shrink-0 tabular-nums text-sm text-[var(--color-text-primary)]">
                                        {penize(p.amount, p.currency)}
                                    </span>
                                </div>

                                <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                                    každého {p.day_of_month}.
                                    {p.wallet && ` · ${p.wallet.name}`}
                                    {p.category && ` · ${p.category.name}`}
                                    {' · od '}{datum(p.starts_on)}
                                    {p.ends_on && ` do ${datum(p.ends_on)}`}
                                </p>

                                <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                                    zapsáno {p.paid_count}×
                                    {p.remaining_to_trip_end > 0 && (
                                        <> · do konce cesty ještě{' '}
                                        <strong className="text-[var(--color-text-primary)]">
                                            {penize(p.remaining_to_trip_end, p.currency)}
                                        </strong></>
                                    )}
                                </p>

                                <div className="mt-2 flex gap-2 border-t border-[var(--color-border)] pt-2">
                                    <button type="button" onClick={() => void prepni(p)}
                                        className="min-h-11 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)]">
                                        {p.is_active ? 'Pozastavit' : 'Znovu spustit'}
                                    </button>
                                    <button type="button" onClick={() => void smaz(p)}
                                        aria-label={`Smazat předpis ${p.name}`}
                                        className="flex h-11 w-11 items-center justify-center rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:border-red-500/40 hover:text-red-400">
                                        <Trash2 size={15}/>
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>

                    {zavazky > 0 && cesta && (
                        <p className="mb-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                            Do konce cesty z toho ještě odejde{' '}
                            <strong className="tabular-nums text-[var(--color-text-primary)]">
                                {penize(zavazky, predpisy[0]?.currency ?? 'EUR')}
                            </strong>. Tyhle peníze už mají majitele, i když ještě leží na účtu —
                            rozpočet s nimi počítá jako se závazkem, ne jako s volnou částkou.
                        </p>
                    )}
                </>
            )}

            {! pridava ? (
                <button type="button" onClick={() => setPridava(true)}
                    className="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                    <Plus size={16}/> Nová pravidelná platba
                </button>
            ) : (
                <FormularPredpisu ciselniky={ciselniky}
                    onHotovo={p => { setPredpisy(p); setPridava(false); onZmena(); }}
                    onZrusit={() => setPridava(false)}/>
            )}
        </Dialog>
    );
}

function FormularPredpisu({ ciselniky, onHotovo, onZrusit }: {
    ciselniky: Ciselniky;
    onHotovo: (p: Predpis[]) => void;
    onZrusit: () => void;
}) {
    const cesta = ciselniky.active_trip;

    const [form, setForm] = useState({
        name: '',
        amount: '',
        wallet_uuid: (cesta?.default_wallet_id
            ? ciselniky.wallets.find(u => u.id === cesta.default_wallet_id)?.uuid
            : null) ?? ciselniky.wallets[0]?.uuid ?? '',
        category_uuid: ciselniky.categories.find(k => k.name === 'Ubytování')?.uuid ?? '',
        day_of_month: '1',
        starts_on: cesta?.starts_on ?? new Date().toISOString().slice(0, 10),
        ends_on: cesta?.ends_on ?? '',
        split: 'equal',
    });

    const [uklada, setUklada] = useState(false);
    const [chyba, setChyba] = useState('');

    const ucet = ciselniky.wallets.find(u => u.uuid === form.wallet_uuid);

    const uloz = async () => {
        setUklada(true);
        setChyba('');

        try {
            const { data } = await axios.post('/api/v1/rozpocet/pravidelne', {
                name: form.name,
                amount: Number(form.amount.replace(',', '.')),
                wallet_uuid: form.wallet_uuid,
                category_uuid: form.category_uuid || null,
                trip_uuid: cesta?.uuid ?? null,
                day_of_month: Number(form.day_of_month),
                starts_on: form.starts_on,
                ends_on: form.ends_on || null,
                split: ciselniky.partners.length === 2 ? form.split : null,
            });

            hlaska('Pravidelná platba je nastavená. Splátky, které už měly proběhnout, se dopsaly.', 'uspech');
            onHotovo(data.recurring);
        } catch (problem: any) {
            setChyba(problem?.response?.data?.message ?? 'Předpis se nepodařilo uložit.');
        } finally {
            setUklada(false);
        }
    };

    return (
        <div className="space-y-3 rounded-xl border border-[var(--color-border)] p-3">
            <div>
                <label className={POPISEK} htmlFor="pr-nazev">Co to je</label>
                <input id="pr-nazev" value={form.name} autoFocus
                    onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                    placeholder="Nájem" className={POLE}/>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label className={POPISEK} htmlFor="pr-castka">
                        Částka {ucet && <span className="text-[var(--color-text-primary)]">({ucet.currency})</span>}
                    </label>
                    <input id="pr-castka" type="text" inputMode="decimal" value={form.amount}
                        onChange={e => setForm(f => ({ ...f, amount: e.target.value }))}
                        placeholder="280" className={`${POLE} tabular-nums`}/>
                </div>

                <div>
                    <label className={POPISEK} htmlFor="pr-den">Kolikátého</label>
                    <select id="pr-den" value={form.day_of_month}
                        onChange={e => setForm(f => ({ ...f, day_of_month: e.target.value }))} className={POLE}>
                        {Array.from({ length: 31 }, (_, i) => i + 1).map(d => (
                            <option key={d} value={d}>{d}.</option>
                        ))}
                    </select>
                    {Number(form.day_of_month) > 28 && (
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            V kratším měsíci padne splátka na poslední den, ne do dalšího měsíce.
                        </p>
                    )}
                </div>

                <div>
                    <label className={POPISEK} htmlFor="pr-ucet">Odkud se platí</label>
                    <select id="pr-ucet" value={form.wallet_uuid}
                        onChange={e => setForm(f => ({ ...f, wallet_uuid: e.target.value }))} className={POLE}>
                        {ciselniky.wallets.map(u => (
                            <option key={u.uuid} value={u.uuid}>{u.name} ({u.currency})</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className={POPISEK} htmlFor="pr-kategorie">Kategorie</label>
                    <select id="pr-kategorie" value={form.category_uuid}
                        onChange={e => setForm(f => ({ ...f, category_uuid: e.target.value }))} className={POLE}>
                        <option value="">Bez kategorie</option>
                        {ciselniky.categories.filter(k => k.kind === 'expense').map(k => (
                            <option key={k.uuid} value={k.uuid}>{k.name}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className={POPISEK} htmlFor="pr-od">Od</label>
                    <input id="pr-od" type="date" value={form.starts_on}
                        onChange={e => setForm(f => ({ ...f, starts_on: e.target.value }))} className={POLE}/>
                </div>

                <div>
                    <label className={POPISEK} htmlFor="pr-do">Do</label>
                    <input id="pr-do" type="date" value={form.ends_on}
                        onChange={e => setForm(f => ({ ...f, ends_on: e.target.value }))} className={POLE}/>
                </div>
            </div>

            {ciselniky.partners.length === 2 && (
                <div>
                    <label className={POPISEK}>Čí to je</label>
                    <div className="grid grid-cols-3 gap-1.5">
                        {[
                            { v: 'equal', p: 'Společné' },
                            { v: 'first', p: ciselniky.partners[0].name },
                            { v: 'second', p: ciselniky.partners[1].name },
                        ].map(m => (
                            <button key={m.v} type="button" onClick={() => setForm(f => ({ ...f, split: m.v }))}
                                aria-pressed={form.split === m.v}
                                className={`min-h-11 rounded-xl border px-2 text-sm ${
                                    form.split === m.v
                                        ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                        : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                }`}>
                                {m.p}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {cesta && (
                <p className="text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                    Splátky se přiřadí k cestě <strong className="text-[var(--color-text-primary)]">{cesta.name}</strong>,
                    takže se započítají do jejího rozpočtu.
                </p>
            )}

            {chyba && (
                <p className="rounded-xl border border-red-500/40 bg-[var(--color-surface-muted)] p-3 text-xs text-[var(--color-text-primary)]">
                    {chyba}
                </p>
            )}

            <div className="flex gap-2">
                <button type="button" onClick={() => void uloz()}
                    disabled={uklada || ! form.name || ! form.amount || ! form.wallet_uuid}
                    className="min-h-11 flex-1 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                    <CalendarClock size={15} className="mr-1.5 inline"/> Nastavit
                </button>
                <button type="button" onClick={onZrusit}
                    className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-secondary)]">
                    Zrušit
                </button>
            </div>
        </div>
    );
}
