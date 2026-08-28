import { hlaska } from '@/Components/Hlasky';
import Panel from '@/Components/Panel';
import { castka as prectiCastku, penize } from '@/lib/penize';
import axios from 'axios';
import { Banknote, CreditCard, Landmark, Pencil, Plus, Scale, Trash2, Wallet, X } from 'lucide-react';
import { useState } from 'react';
import type { Ciselniky, Ucet } from './typy';

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2.5 text-base text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const POPISEK = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

const DRUHY = [
    { id: 'bank', nazev: 'Bankovní účet', ikona: Landmark },
    { id: 'card', nazev: 'Karta nebo služba', ikona: CreditCard },
    { id: 'cash', nazev: 'Hotovost', ikona: Banknote },
    { id: 'other', nazev: 'Jiný účet', ikona: Wallet },
] as const;

/**
 * Účty — místa, kde peníze doopravdy jsou.
 *
 * Seskupené po měnách, protože sečíst koruny s eury do jednoho čísla by dalo údaj,
 * se kterým se nedá nic dělat.
 *
 * Zůstatek se nikde nepřepisuje ručně. Vzniká z pohybů, takže když nesedí, je to
 * informace — někde chybí zápis. Přepsat ho by tu informaci zahodilo a zůstatek by
 * přestal být odvoditelný. Proto je místo přepsání korekce: zapíše se rozdíl
 * i s důvodem a je vidět, kdy a proč se to stalo.
 */
export default function Ucty({ ciselniky, onZmena }: { ciselniky: Ciselniky; onZmena: () => void }) {
    const [formular, setFormular] = useState<'novy' | Ucet | null>(null);
    const [korekce, setKorekce] = useState<Ucet | null>(null);

    const poMenach = ciselniky.balances;

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
                {poMenach.map(m => (
                    <div key={m.currency} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                        <p className="text-[11px] font-medium uppercase tracking-wider text-[var(--color-text-secondary)]">
                            Celkem {m.currency}
                        </p>
                        <p className="mt-1 text-xl font-semibold tabular-nums text-[var(--color-text-primary)]">
                            {penize(m.total, m.currency)}
                        </p>
                        <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                            z toho hotovost {penize(m.cash, m.currency)}
                        </p>
                    </div>
                ))}
                {poMenach.length === 0 && (
                    <p className="col-span-full rounded-2xl border border-dashed border-[var(--color-border)] px-3 py-6 text-center text-xs text-[var(--color-text-secondary)]">
                        Zatím žádný účet. Bez něj nejde zapsat výdaj — peníze musí odněkud odejít.
                    </p>
                )}
            </div>

            <Panel icon={Wallet} title="Účty a peněženky"
                description="Zůstatek se počítá ze zapsaných pohybů, nepřepisuje se ručně."
                actions={
                    <button type="button" onClick={() => setFormular('novy')}
                        className="inline-flex min-h-11 items-center gap-1 rounded-lg bg-[var(--color-accent)] px-3 text-sm font-medium text-[var(--color-accent-contrast)]">
                        <Plus size={15}/> Nový účet
                    </button>
                }>
                {ciselniky.wallets.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-6 text-center text-xs leading-relaxed text-[var(--color-text-secondary)]">
                        Začněte tím, odkud budete platit — třeba eurová karta a hotovost.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {ciselniky.wallets.map(u => {
                            const druh = DRUHY.find(d => d.id === u.kind) ?? DRUHY[3];

                            return (
                                <li key={u.uuid}
                                    className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3">
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <druh.ikona size={17} className="shrink-0 text-[var(--color-text-secondary)]"/>
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-[var(--color-text-primary)]">{u.name}</p>
                                            <p className="truncate text-[11px] text-[var(--color-text-secondary)]">
                                                {druh.nazev} · {u.currency}
                                                {u.owner ? ` · ${u.owner}` : ' · společný'}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <span className={`tabular-nums text-sm font-medium ${u.is_negative ? 'text-red-400' : 'text-[var(--color-text-primary)]'}`}>
                                            {penize(u.balance, u.currency)}
                                        </span>
                                        <button type="button" onClick={() => setKorekce(u)}
                                            aria-label={`Srovnat zůstatek účtu ${u.name}`}
                                            className="flex h-11 w-11 items-center justify-center rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                                            <Scale size={15}/>
                                        </button>
                                        <button type="button" onClick={() => setFormular(u)}
                                            aria-label={`Upravit účet ${u.name}`}
                                            className="flex h-11 w-11 items-center justify-center rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                                            <Pencil size={15}/>
                                        </button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Panel>

            {formular && (
                <FormularUctu ucet={formular === 'novy' ? null : formular}
                    partneri={ciselniky.partners}
                    onHotovo={() => { setFormular(null); onZmena(); }}
                    onZavrit={() => setFormular(null)}/>
            )}

            {korekce && (
                <FormularKorekce ucet={korekce}
                    onHotovo={() => { setKorekce(null); onZmena(); }}
                    onZavrit={() => setKorekce(null)}/>
            )}
        </div>
    );
}

function FormularUctu({ ucet, partneri, onHotovo, onZavrit }: {
    ucet: Ucet | null;
    partneri: Ciselniky['partners'];
    onHotovo: () => void;
    onZavrit: () => void;
}) {
    const [form, setForm] = useState({
        name: ucet?.name ?? '',
        kind: ucet?.kind ?? 'card',
        currency: ucet?.currency ?? 'EUR',
        opening_balance: ucet ? String(ucet.opening_balance) : '',
        partner_id: '',
        is_active: true,
    });
    const [uklada, setUklada] = useState(false);
    const [chyba, setChyba] = useState('');
    const [mazani, setMazani] = useState(false);

    const uloz = async () => {
        setUklada(true);
        setChyba('');

        try {
            const telo = {
                name: form.name,
                kind: form.kind,
                partner_id: form.partner_id ? Number(form.partner_id) : null,
                opening_balance: prectiCastku(form.opening_balance) ?? 0,
            };

            if (ucet) {
                await axios.patch(`/api/v1/rozpocet/ucty/${ucet.uuid}`, telo);
                hlaska('Účet je upravený.', 'uspech');
            } else {
                await axios.post('/api/v1/rozpocet/ucty', { ...telo, currency: form.currency });
                hlaska('Účet je založený.', 'uspech');
            }

            onHotovo();
        } catch (problem: any) {
            setChyba(problem?.response?.data?.message ?? 'Účet se nepodařilo uložit.');
        } finally {
            setUklada(false);
        }
    };

    const smaz = async () => {
        try {
            await axios.delete(`/api/v1/rozpocet/ucty/${ucet!.uuid}`);
            hlaska('Účet je smazaný.', 'uspech');
            onHotovo();
        } catch (problem: any) {
            setChyba(problem?.response?.data?.message ?? 'Účet se nepodařilo smazat.');
            setMazani(false);
        }
    };

    const odloz = async () => {
        try {
            await axios.patch(`/api/v1/rozpocet/ucty/${ucet!.uuid}`, { is_active: false });
            hlaska('Účet je odložený. Historie zůstala.', 'uspech');
            onHotovo();
        } catch {
            setChyba('Účet se nepodařilo odložit.');
        }
    };

    return (
        <Dialog nadpis={ucet ? 'Úprava účtu' : 'Nový účet'} onZavrit={onZavrit}>
            <div className="space-y-3">
                <div>
                    <label className={POPISEK} htmlFor="ucet-nazev">Název</label>
                    <input id="ucet-nazev" value={form.name} autoFocus
                        onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                        placeholder="EUR karta" className={POLE}/>
                </div>

                <div>
                    <label className={POPISEK}>Druh</label>
                    <div className="grid grid-cols-2 gap-1.5">
                        {DRUHY.map(d => (
                            <button key={d.id} type="button" onClick={() => setForm(f => ({ ...f, kind: d.id }))}
                                aria-pressed={form.kind === d.id}
                                className={`inline-flex min-h-11 items-center gap-1.5 rounded-xl border px-3 text-sm ${
                                    form.kind === d.id
                                        ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                        : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                                }`}>
                                <d.ikona size={15}/> {d.nazev}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Měna jen u nového účtu. U existujícího by přepnutí nepřepočítalo
                    zapsané pohyby — jen by k týmž číslům přidalo jinou značku. */}
                {! ucet ? (
                    <div>
                        <label className={POPISEK} htmlFor="ucet-mena">Měna</label>
                        <select id="ucet-mena" value={form.currency}
                            onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} className={POLE}>
                            {['EUR', 'CZK', 'USD', 'PLN', 'GBP'].map(m => <option key={m} value={m}>{m}</option>)}
                        </select>
                    </div>
                ) : (
                    <p className="rounded-xl border border-[var(--color-border)] px-3 py-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                        Měna <strong className="text-[var(--color-text-primary)]">{ucet.currency}</strong> se
                        u založeného účtu nemění — zapsané pohyby by se nepřepočítaly, jen by u nich stála
                        jiná značka. Kdyby byla špatně, založte nový účet.
                    </p>
                )}

                {partneri.length > 0 && (
                    <div>
                        <label className={POPISEK} htmlFor="ucet-vlastnik">Čí je</label>
                        <select id="ucet-vlastnik" value={form.partner_id}
                            onChange={e => setForm(f => ({ ...f, partner_id: e.target.value }))} className={POLE}>
                            <option value="">Společný</option>
                            {partneri.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            Ze společného účtu nevzniká osobní dluh — peníze na něm byly už předtím obou.
                        </p>
                    </div>
                )}

                <div>
                    <label className={POPISEK} htmlFor="ucet-pocatek">Počáteční zůstatek</label>
                    <input id="ucet-pocatek" type="text" inputMode="decimal" value={form.opening_balance}
                        onChange={e => setForm(f => ({ ...f, opening_balance: e.target.value }))}
                        placeholder="0,00" className={`${POLE} tabular-nums`}/>
                    <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                        Kolik na účtu je teď. Není to příjem — do výdajů ani příjmů se nepočítá.
                    </p>
                </div>

                {chyba && (
                    <p className="rounded-xl border border-red-500/40 bg-[var(--color-surface-muted)] p-3 text-xs leading-relaxed text-[var(--color-text-primary)]">
                        {chyba}
                    </p>
                )}

                <div className="flex gap-2 border-t border-[var(--color-border)] pt-3">
                    <button type="button" onClick={() => void uloz()} disabled={uklada || ! form.name}
                        className="min-h-11 flex-1 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                        {ucet ? 'Uložit' : 'Založit účet'}
                    </button>
                    <button type="button" onClick={onZavrit}
                        className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-secondary)]">
                        Zrušit
                    </button>
                </div>

                {ucet && (
                    <div className="border-t border-[var(--color-border)] pt-3">
                        {mazani ? (
                            <div className="flex flex-wrap gap-2">
                                <button type="button" onClick={() => void smaz()}
                                    className="min-h-11 rounded-lg bg-red-500/90 px-3 text-sm font-medium text-white">
                                    Opravdu smazat
                                </button>
                                <button type="button" onClick={() => void odloz()}
                                    className="min-h-11 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-primary)]">
                                    Radši odložit
                                </button>
                                <button type="button" onClick={() => setMazani(false)}
                                    className="min-h-11 rounded-lg px-3 text-sm text-[var(--color-text-secondary)]">
                                    Zpět
                                </button>
                            </div>
                        ) : (
                            <button type="button" onClick={() => setMazani(true)}
                                className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)] hover:border-red-500/40 hover:text-red-400">
                                <Trash2 size={15}/> Smazat nebo odložit
                            </button>
                        )}
                    </div>
                )}
            </div>
        </Dialog>
    );
}

/**
 * Srovnání zůstatku se skutečností.
 *
 * Zapíše se rozdíl, ne nová hodnota. Za půl roku pak jde zjistit, že se tehdy něco
 * nezapsalo — místo aby zůstatek prostě někdy někde skočil a nikdo nevěděl proč.
 */
function FormularKorekce({ ucet, onHotovo, onZavrit }: { ucet: Ucet; onHotovo: () => void; onZavrit: () => void }) {
    const [skutecny, setSkutecny] = useState('');
    const [duvod, setDuvod] = useState('');
    const [uklada, setUklada] = useState(false);
    const [chyba, setChyba] = useState('');

    const hodnota = prectiCastku(skutecny);
    const rozdil = hodnota !== null ? Math.round((hodnota - ucet.balance) * 100) / 100 : null;

    const uloz = async () => {
        setUklada(true);

        try {
            await axios.post(`/api/v1/rozpocet/ucty/${ucet.uuid}/korekce`, {
                actual_balance: hodnota, reason: duvod,
            });
            hlaska('Rozdíl je zapsaný jako korekce.', 'uspech');
            onHotovo();
        } catch (problem: any) {
            setChyba(problem?.response?.data?.message ?? 'Korekci se nepodařilo uložit.');
        } finally {
            setUklada(false);
        }
    };

    return (
        <Dialog nadpis={`Srovnat zůstatek — ${ucet.name}`} onZavrit={onZavrit}>
            <p className="text-sm text-[var(--color-text-secondary)]">
                Podle zapsaných pohybů je na účtu{' '}
                <strong className="tabular-nums text-[var(--color-text-primary)]">{penize(ucet.balance, ucet.currency)}</strong>.
                Kolik je tam doopravdy?
            </p>

            <div className="mt-3 space-y-3">
                <div>
                    <label className={POPISEK} htmlFor="korekce-castka">Skutečný zůstatek ({ucet.currency})</label>
                    <input id="korekce-castka" type="text" inputMode="decimal" autoFocus value={skutecny}
                        onChange={e => setSkutecny(e.target.value)} placeholder="0,00"
                        className={`${POLE} !text-2xl !font-semibold tabular-nums`}/>
                </div>

                {rozdil !== null && rozdil !== 0 && (
                    <p className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3 text-xs leading-relaxed text-[var(--color-text-secondary)]">
                        Rozdíl je{' '}
                        <strong className="tabular-nums text-[var(--color-text-primary)]">
                            {rozdil > 0 ? '+' : '−'}{penize(Math.abs(rozdil), ucet.currency)}
                        </strong>.
                        {' '}Zapíše se jako {rozdil < 0 ? 'chybějící' : 'přebývající'} částka s vaším důvodem, aby
                        bylo za půl roku vidět, co se stalo. Do rozpočtu se nepočítá — nikdo za ni nic nekoupil.
                    </p>
                )}

                {rozdil === 0 && (
                    <p className="text-xs text-[var(--color-text-secondary)]">Zůstatek sedí, korekce není potřeba.</p>
                )}

                <div>
                    <label className={POPISEK} htmlFor="korekce-duvod">Čím to je</label>
                    <input id="korekce-duvod" value={duvod} onChange={e => setDuvod(e.target.value)}
                        placeholder="Zapomenutý nákup, spropitné, kurzový rozdíl…" className={POLE}/>
                </div>

                {chyba && <p className="text-xs text-red-400">{chyba}</p>}

                <div className="flex gap-2 border-t border-[var(--color-border)] pt-3">
                    <button type="button" onClick={() => void uloz()}
                        disabled={uklada || rozdil === null || rozdil === 0 || ! duvod}
                        className="min-h-11 flex-1 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                        Zapsat korekci
                    </button>
                    <button type="button" onClick={onZavrit}
                        className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-secondary)]">
                        Zrušit
                    </button>
                </div>
            </div>
        </Dialog>
    );
}

export function Dialog({ nadpis, children, onZavrit }: {
    nadpis: string; children: React.ReactNode; onZavrit: () => void;
}) {
    return (
        <div className="fixed inset-0 z-[950] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-label={nadpis}>
            <button type="button" aria-label="Zavřít" onClick={onZavrit} className="absolute inset-0 bg-black/50"/>
            <div className="relative max-h-[92dvh] w-full overflow-y-auto rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 pb-[calc(1rem+env(safe-area-inset-bottom,0px))] sm:max-w-md sm:rounded-2xl">
                <div className="mb-3 flex items-start justify-between gap-2">
                    <h2 className="text-base font-semibold text-[var(--color-text-primary)]">{nadpis}</h2>
                    <button type="button" onClick={onZavrit} aria-label="Zavřít"
                        className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)]">
                        <X size={18}/>
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}
