import { hlaska } from '@/Components/Hlasky';
import Panel from '@/Components/Panel';
import { dny, zaznamy } from '@/lib/cestina';
import { castka as prectiCastku, datum, kurz, penize, penizeZbyva } from '@/lib/penize';
import axios from 'axios';
import { CheckCircle2, Flag, MapPin, Pencil, Play, Plus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Dialog } from './Ucty';
import type { Ciselniky } from './typy';

const POLE = 'w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2.5 text-base text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';
const POPISEK = 'block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5';

type CestaStav = {
    uuid: string; name: string; country: string | null; city: string | null;
    starts_on: string | null; ends_on: string | null;
    days_total: number | null; days_left: number | null;
    currency: string; budget: number | null; reserve: number | null;
    spent: number; remaining: number | null; percent: number | null;
    per_day_so_far: number | null;
    safe_daily: { state: string; per_day: number | null; days_left: number | null; over_by: number | null } | null;
    is_active: boolean; state: string; note: string | null; transactions: number;
};

/**
 * Cesty — seskupení rozpočtu a útrat za jeden pobyt.
 *
 * Není to plánovač itineráře. Cesta tu existuje proto, aby šlo říct „za Drážďany
 * jsme utratili tolik" bez ručního vybírání transakcí podle data.
 *
 * Aktivní smí být jedna. Předvyplňuje se do nových záznamů, takže dvě aktivní by
 * znamenaly, že se výdaje tiše rozdělí mezi dva pobyty — a nikdo by si toho nevšiml,
 * dokud by na konci nesouhlasily součty obou.
 */
export default function Cesty({ ciselniky, onZmena }: { ciselniky: Ciselniky; onZmena: () => void }) {
    const [cesty, setCesty] = useState<CestaStav[]>([]);
    const [nacita, setNacita] = useState(true);
    const [chyba, setChyba] = useState('');
    const [formular, setFormular] = useState<'nova' | CestaStav | null>(null);
    const [shrnuti, setShrnuti] = useState<{ cesta: CestaStav; data: any } | null>(null);
    const [skupina, setSkupina] = useState<'aktivni' | 'ukoncene'>('aktivni');

    const nacti = useCallback(async () => {
        setNacita(true);

        try {
            const { data } = await axios.get<{ trips: CestaStav[] }>('/api/v1/rozpocet/cesty');
            setCesty(data.trips);
            setChyba('');
        } catch {
            setChyba('Cesty se nepodařilo načíst.');
        } finally {
            setNacita(false);
        }
    }, []);

    useEffect(() => { void nacti(); }, [nacti]);

    const aktivuj = async (c: CestaStav) => {
        try {
            await axios.post(`/api/v1/rozpocet/cesty/${c.uuid}/aktivovat`);
            hlaska(`Jedeme na ${c.name}. Nové záznamy se k té cestě přiřadí samy.`, 'uspech');
            await nacti();
            onZmena();
        } catch {
            hlaska('Cestu se nepodařilo aktivovat.', 'chyba');
        }
    };

    const ukonci = async (c: CestaStav) => {
        try {
            const { data } = await axios.post(`/api/v1/rozpocet/cesty/${c.uuid}/ukoncit`);
            setShrnuti({ cesta: data.trip, data: data.summary });
            await nacti();
            onZmena();
        } catch {
            hlaska('Cestu se nepodařilo ukončit.', 'chyba');
        }
    };

    const otevriShrnuti = async (c: CestaStav) => {
        const { data } = await axios.get(`/api/v1/rozpocet/cesty/${c.uuid}/shrnuti`);
        setShrnuti({ cesta: data.trip, data: data.summary });
    };

    const zobrazene = cesty.filter(c => skupina === 'ukoncene' ? c.state === 'closed' : c.state !== 'closed');

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex gap-1.5">
                    {([['aktivni', 'Probíhající a plánované'], ['ukoncene', 'Ukončené']] as const).map(([id, popis]) => (
                        <button key={id} type="button" onClick={() => setSkupina(id)}
                            aria-pressed={skupina === id}
                            className={`min-h-11 rounded-full border px-3.5 text-xs ${
                                skupina === id
                                    ? 'border-[var(--color-accent)] bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'
                                    : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'
                            }`}>
                            {popis}
                        </button>
                    ))}
                </div>
                <button type="button" onClick={() => setFormular('nova')}
                    className="inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                    <Plus size={16}/> Nová cesta
                </button>
            </div>

            {chyba && (
                <div className="rounded-2xl border border-red-500/40 p-3">
                    <p className="text-sm text-[var(--color-text-primary)]">{chyba}</p>
                    <button type="button" onClick={() => void nacti()}
                        className="mt-2 min-h-11 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]">
                        Zkusit znovu
                    </button>
                </div>
            )}

            {! nacita && ! chyba && zobrazene.length === 0 && (
                <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center">
                    <p className="text-sm text-[var(--color-text-primary)]">
                        {skupina === 'ukoncene' ? 'Žádná ukončená cesta' : 'Zatím žádná cesta'}
                    </p>
                    <p className="mx-auto mt-1 max-w-sm text-xs leading-relaxed text-[var(--color-text-secondary)]">
                        {skupina === 'ukoncene'
                            ? 'Až nějakou ukončíte, najdete tu její shrnutí.'
                            : 'Cesta seskupí útraty za jeden pobyt a dá jim vlastní rozpočet. Nové záznamy se k aktivní cestě přiřadí samy.'}
                    </p>
                    {skupina === 'aktivni' && (
                        <button type="button" onClick={() => setFormular('nova')}
                            className="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                            <Plus size={16}/> Založit cestu
                        </button>
                    )}
                </div>
            )}

            <div className="grid gap-3 lg:grid-cols-2">
                {zobrazene.map(c => (
                    <KartaCesty key={c.uuid} cesta={c}
                        onAktivovat={() => void aktivuj(c)}
                        onUkoncit={() => void ukonci(c)}
                        onUpravit={() => setFormular(c)}
                        onShrnuti={() => void otevriShrnuti(c)}/>
                ))}
            </div>

            {formular && (
                <FormularCesty cesta={formular === 'nova' ? null : formular}
                    ucty={ciselniky.wallets}
                    onHotovo={() => { setFormular(null); void nacti(); onZmena(); }}
                    onZavrit={() => setFormular(null)}/>
            )}

            {shrnuti && (
                <ShrnutiCesty cesta={shrnuti.cesta} data={shrnuti.data} onZavrit={() => setShrnuti(null)}/>
            )}
        </div>
    );
}

function KartaCesty({ cesta, onAktivovat, onUkoncit, onUpravit, onShrnuti }: {
    cesta: CestaStav;
    onAktivovat: () => void; onUkoncit: () => void; onUpravit: () => void; onShrnuti: () => void;
}) {
    const ukoncena = cesta.state === 'closed';
    const prekroceno = cesta.percent !== null && cesta.percent > 100;

    return (
        <Panel tone={cesta.is_active ? 'accent' : 'plain'}
            icon={cesta.is_active ? MapPin : Flag}
            title={cesta.name}
            description={[
                [cesta.country, cesta.city].filter(Boolean).join(' · '),
                cesta.starts_on && `${datum(cesta.starts_on)}${cesta.ends_on ? ` – ${datum(cesta.ends_on)}` : ''}`,
            ].filter(Boolean).join(' · ') || undefined}
            actions={
                <button type="button" onClick={onUpravit} aria-label={`Upravit cestu ${cesta.name}`}
                    className="flex h-11 w-11 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <Pencil size={15}/>
                </button>
            }>

            {cesta.is_active && (
                <p className="mb-2 inline-flex items-center gap-1 rounded-full border border-[var(--color-accent)] px-2 py-0.5 text-[11px] text-[var(--color-text-primary)]">
                    <MapPin size={11}/> Právě jedeme
                </p>
            )}

            {cesta.budget !== null ? (
                <>
                    <div className="flex items-baseline justify-between gap-2">
                        <span className="text-2xl font-semibold tabular-nums text-[var(--color-text-primary)]">
                            {penizeZbyva(cesta.remaining ?? 0, cesta.currency)}
                        </span>
                        <span className="shrink-0 text-[11px] text-[var(--color-text-secondary)]">
                            z {penize(cesta.budget, cesta.currency)}
                        </span>
                    </div>
                    <p className="mt-0.5 text-[11px] text-[var(--color-text-secondary)]">
                        {prekroceno ? 'přesaženo' : 'zbývá'} · utraceno {penize(cesta.spent, cesta.currency)}
                    </p>

                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                        <div className="h-full rounded-full"
                            style={{
                                width: `${Math.min(100, cesta.percent ?? 0)}%`,
                                background: prekroceno ? 'var(--fin-vydaj)' : (cesta.percent ?? 0) >= 80 ? 'var(--fin-upozorneni)' : 'var(--fin-prijem)',
                            }}/>
                    </div>

                    <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt className="text-[var(--color-text-secondary)]">Bezpečně na den</dt>
                            <dd className="tabular-nums text-[var(--color-text-primary)]">
                                {cesta.safe_daily?.per_day !== null && cesta.safe_daily?.per_day !== undefined
                                    ? penize(cesta.safe_daily.per_day, cesta.currency)
                                    : cesta.safe_daily?.state === 'over'
                                        ? `přesah ${penize(cesta.safe_daily.over_by ?? 0, cesta.currency)}`
                                        : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[var(--color-text-secondary)]">Zatím průměrně</dt>
                            <dd className="tabular-nums text-[var(--color-text-primary)]">
                                {cesta.per_day_so_far !== null ? `${penize(cesta.per_day_so_far, cesta.currency)} / den` : '—'}
                            </dd>
                        </div>
                    </dl>
                </>
            ) : (
                <p className="text-xs leading-relaxed text-[var(--color-text-secondary)]">
                    Cesta nemá rozpočet, takže se u ní nepočítá, kolik zbývá ani kolik jde utratit za den.
                    Utraceno zatím {penize(cesta.spent, cesta.currency)}.
                </p>
            )}

            <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">
                {cesta.days_left !== null && ! ukoncena && `${dny(cesta.days_left)} do konce · `}
                {zaznamy(cesta.transactions)}
            </p>

            <div className="mt-3 flex flex-wrap gap-2 border-t border-[var(--color-border)] pt-3">
                {! cesta.is_active && ! ukoncena && (
                    <button type="button" onClick={onAktivovat}
                        className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-primary)]">
                        <Play size={14}/> Jedeme tam
                    </button>
                )}
                {! ukoncena && (
                    <button type="button" onClick={onUkoncit}
                        className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-secondary)]">
                        <CheckCircle2 size={14}/> Ukončit
                    </button>
                )}
                <button type="button" onClick={onShrnuti}
                    className="inline-flex min-h-11 items-center rounded-lg px-3 text-sm text-[var(--color-text-secondary)] underline">
                    Jak to dopadlo
                </button>
            </div>
        </Panel>
    );
}

function FormularCesty({ cesta, ucty, onHotovo, onZavrit }: {
    cesta: CestaStav | null;
    ucty: Ciselniky['wallets'];
    onHotovo: () => void; onZavrit: () => void;
}) {
    const [form, setForm] = useState({
        name: cesta?.name ?? '',
        country: cesta?.country ?? 'Německo',
        city: cesta?.city ?? '',
        starts_on: cesta?.starts_on ?? new Date().toISOString().slice(0, 10),
        ends_on: cesta?.ends_on ?? '',
        base_currency: cesta?.currency ?? 'EUR',
        budget_amount: cesta?.budget !== null && cesta?.budget !== undefined ? String(cesta.budget) : '',
        reserve_amount: cesta?.reserve !== null && cesta?.reserve !== undefined ? String(cesta.reserve) : '',
        default_wallet_id: '',
        activate: ! cesta,
    });
    const [uklada, setUklada] = useState(false);
    const [chyby, setChyby] = useState<Record<string, string>>({});

    // Kolik denně vychází — ještě než se cesta uloží. Nejužitečnější je to právě tady,
    // při rozhodování, jestli je ta částka na tolik dní vůbec dost.
    const nahled = (() => {
        const limit = prectiCastku(form.budget_amount);
        const rezerva = prectiCastku(form.reserve_amount) ?? 0;

        if (! limit || ! form.starts_on || ! form.ends_on) return null;

        const od = new Date(`${form.starts_on}T12:00:00`);
        const doD = new Date(`${form.ends_on}T12:00:00`);
        const dni = Math.round((doD.getTime() - od.getTime()) / 86400000) + 1;

        if (dni <= 0) return null;

        return { dni, naDen: (limit - rezerva) / dni, prekrocenaRezerva: rezerva > limit };
    })();

    const uloz = async () => {
        setUklada(true);
        setChyby({});

        const telo = {
            name: form.name,
            country: form.country || null,
            city: form.city || null,
            starts_on: form.starts_on,
            ends_on: form.ends_on || null,
            base_currency: form.base_currency,
            budget_amount: prectiCastku(form.budget_amount),
            reserve_amount: prectiCastku(form.reserve_amount),
            default_wallet_id: form.default_wallet_id ? Number(form.default_wallet_id) : null,
            activate: form.activate,
        };

        try {
            if (cesta) {
                await axios.patch(`/api/v1/rozpocet/cesty/${cesta.uuid}`, telo);
                hlaska('Cesta je upravená.', 'uspech');
            } else {
                await axios.post('/api/v1/rozpocet/cesty', telo);
                hlaska('Cesta je založená.', 'uspech');
            }

            onHotovo();
        } catch (problem: any) {
            if (problem?.response?.status === 422) {
                const e = problem.response.data?.errors ?? {};
                setChyby(Object.keys(e).length
                    ? Object.fromEntries(Object.entries(e).map(([k, v]) => [k, (v as string[])[0]]))
                    : { obecna: problem.response.data?.message ?? 'Cestu se nepodařilo uložit.' });
            } else {
                setChyby({ obecna: problem?.response?.data?.message ?? 'Cestu se nepodařilo uložit.' });
            }
        } finally {
            setUklada(false);
        }
    };

    return (
        <Dialog nadpis={cesta ? 'Úprava cesty' : 'Nová cesta'} onZavrit={onZavrit}>
            <div className="space-y-3">
                <div>
                    <label className={POPISEK} htmlFor="cesta-nazev">Název</label>
                    <input id="cesta-nazev" value={form.name} autoFocus
                        onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                        placeholder="Drážďany" className={POLE}/>
                    {chyby.name && <p className="mt-1 text-xs text-red-400">{chyby.name}</p>}
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label className={POPISEK} htmlFor="cesta-zeme">Země</label>
                        <input id="cesta-zeme" value={form.country}
                            onChange={e => setForm(f => ({ ...f, country: e.target.value }))} className={POLE}/>
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="cesta-mesto">Město</label>
                        <input id="cesta-mesto" value={form.city}
                            onChange={e => setForm(f => ({ ...f, city: e.target.value }))} className={POLE}/>
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="cesta-od">Od</label>
                        <input id="cesta-od" type="date" value={form.starts_on}
                            onChange={e => setForm(f => ({ ...f, starts_on: e.target.value }))} className={POLE}/>
                        {chyby.starts_on && <p className="mt-1 text-xs text-red-400">{chyby.starts_on}</p>}
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="cesta-do">Do</label>
                        <input id="cesta-do" type="date" value={form.ends_on}
                            onChange={e => setForm(f => ({ ...f, ends_on: e.target.value }))} className={POLE}/>
                        {chyby.ends_on && <p className="mt-1 text-xs text-red-400">{chyby.ends_on}</p>}
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="cesta-mena">Měna rozpočtu</label>
                        <select id="cesta-mena" value={form.base_currency}
                            onChange={e => setForm(f => ({ ...f, base_currency: e.target.value }))} className={POLE}>
                            {['EUR', 'CZK', 'USD', 'PLN'].map(m => <option key={m} value={m}>{m}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="cesta-rozpocet">Rozpočet</label>
                        <input id="cesta-rozpocet" type="text" inputMode="decimal" value={form.budget_amount}
                            onChange={e => setForm(f => ({ ...f, budget_amount: e.target.value }))}
                            placeholder="1200" className={`${POLE} tabular-nums`}/>
                    </div>
                    <div>
                        <label className={POPISEK} htmlFor="cesta-rezerva">Rezerva</label>
                        <input id="cesta-rezerva" type="text" inputMode="decimal" value={form.reserve_amount}
                            onChange={e => setForm(f => ({ ...f, reserve_amount: e.target.value }))}
                            placeholder="100" className={`${POLE} tabular-nums`}/>
                        <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                            Část rozpočtu, která se nerozpočítá do denní částky — zůstane stranou.
                        </p>
                    </div>
                    {ucty.length > 0 && (
                        <div>
                            <label className={POPISEK} htmlFor="cesta-ucet">Odkud se obvykle platí</label>
                            <select id="cesta-ucet" value={form.default_wallet_id}
                                onChange={e => setForm(f => ({ ...f, default_wallet_id: e.target.value }))} className={POLE}>
                                <option value="">Nevybráno</option>
                                {ucty.filter(u => u.id).map(u => (
                                    <option key={u.uuid} value={u.id}>{u.name} ({u.currency})</option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                {nahled && (
                    <p className={`rounded-xl border p-3 text-xs leading-relaxed ${
                        nahled.prekrocenaRezerva ? 'border-amber-500/40' : 'border-[var(--color-border)]'
                    } bg-[var(--color-surface-muted)] text-[var(--color-text-secondary)]`}>
                        {nahled.prekrocenaRezerva ? (
                            <>Rezerva je větší než rozpočet — na útratu by nezbylo nic už první den.</>
                        ) : (
                            <>
                                Na {dny(nahled.dni)} to vychází{' '}
                                <strong className="tabular-nums text-[var(--color-text-primary)]">
                                    {penize(nahled.naDen, form.base_currency)}
                                </strong>{' '}
                                na den.
                            </>
                        )}
                    </p>
                )}

                <label className="flex items-start gap-2 text-sm text-[var(--color-text-primary)]">
                    <input type="checkbox" checked={form.activate}
                        onChange={e => setForm(f => ({ ...f, activate: e.target.checked }))} className="mt-1 h-4 w-4"/>
                    <span>
                        Právě tam jedeme
                        <span className="block text-[11px] text-[var(--color-text-secondary)]">
                            Nové záznamy se k téhle cestě přiřadí samy a formulář předvyplní {form.base_currency}.
                            Aktivní může být jen jedna cesta.
                        </span>
                    </span>
                </label>

                {chyby.obecna && (
                    <p className="rounded-xl border border-red-500/40 bg-[var(--color-surface-muted)] p-3 text-xs leading-relaxed text-[var(--color-text-primary)]">
                        {chyby.obecna}
                    </p>
                )}

                <div className="flex gap-2 border-t border-[var(--color-border)] pt-3">
                    <button type="button" onClick={() => void uloz()} disabled={uklada || ! form.name || ! form.starts_on}
                        className="min-h-11 flex-1 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                        {cesta ? 'Uložit' : 'Založit cestu'}
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

/** Jak cesta dopadla. Zůstává v aplikaci — nevzniká z toho soubor ke stažení. */
function ShrnutiCesty({ cesta, data, onZavrit }: { cesta: CestaStav; data: any; onZavrit: () => void }) {
    const m = data.currency;

    return (
        <Dialog nadpis={`${cesta.name} — jak to dopadlo`} onZavrit={onZavrit}>
            {data.transactions === 0 ? (
                <p className="rounded-xl border border-dashed border-[var(--color-border)] px-3 py-6 text-center text-xs text-[var(--color-text-secondary)]">
                    K téhle cestě zatím žádný záznam nepatří, takže není co shrnout.
                </p>
            ) : (
                <div className="space-y-3">
                    <div className="grid grid-cols-2 gap-2">
                        <Udaj popisek="Plánovali jsme" hodnota={data.budget !== null ? penize(data.budget, m) : '—'}/>
                        <Udaj popisek="Utratili jsme" hodnota={penize(data.spent, m)}/>
                        <Udaj popisek="Rozdíl"
                            hodnota={data.difference !== null
                                ? `${data.difference >= 0 ? 'zbylo ' : 'přes o '}${penize(Math.abs(data.difference), m)}`
                                : '—'}
                            ton={data.difference !== null && data.difference < 0 ? 'spatne' : 'dobre'}/>
                        <Udaj popisek="Průměrně na den" hodnota={penize(data.per_day, m)}/>
                    </div>

                    {data.most_expensive_day && (
                        <p className="text-xs text-[var(--color-text-secondary)]">
                            Nejdražší den byl {datum(data.most_expensive_day.date)} —{' '}
                            <strong className="tabular-nums text-[var(--color-text-primary)]">
                                {penize(data.most_expensive_day.amount, m)}
                            </strong>.
                        </p>
                    )}

                    {data.top_categories.length > 0 && (
                        <div>
                            <p className={POPISEK}>Za co nejvíc</p>
                            <ul className="space-y-1">
                                {data.top_categories.slice(0, 5).map((k: any) => (
                                    <li key={k.name} className="flex items-baseline justify-between gap-2 text-xs">
                                        <span className="flex min-w-0 items-center gap-1.5">
                                            <span className="h-2 w-2 shrink-0 rounded-full"
                                                style={{ background: k.color ?? 'var(--color-text-secondary)' }}/>
                                            <span className="truncate text-[var(--color-text-primary)]">{k.name}</span>
                                        </span>
                                        <span className="shrink-0 tabular-nums text-[var(--color-text-secondary)]">
                                            {penize(k.amount, k.currency)} · {k.percent} %
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {data.average_rate !== null && (
                        <p className="text-xs text-[var(--color-text-secondary)]">
                            Eura jsme na téhle cestě pořizovali průměrně za{' '}
                            <strong className="tabular-nums text-[var(--color-text-primary)]">{kurz(data.average_rate)} Kč</strong>.
                        </p>
                    )}

                    {data.fees.length > 0 && (
                        <p className="text-xs text-[var(--color-text-secondary)]">
                            Na poplatcích padlo{' '}
                            {data.fees.map((f: any) => penize(f.amount, f.currency)).join(' + ')}.
                        </p>
                    )}

                    {data.partner_balance.by_currency.map((mena: any) =>
                        mena.settlement.length > 0 && (
                            <p key={mena.currency} className="text-xs text-[var(--color-text-primary)]">
                                {mena.settlement.map((v: any, i: number) => (
                                    <span key={i}>{v.from} dluží {v.to} {penize(v.amount, v.currency)}. </span>
                                ))}
                            </p>
                        ),
                    )}
                </div>
            )}
        </Dialog>
    );
}

function Udaj({ popisek, hodnota, ton = 'plain' }: { popisek: string; hodnota: string; ton?: 'plain' | 'dobre' | 'spatne' }) {
    return (
        <div className="rounded-xl border border-[var(--color-border)] p-2.5">
            <p className="text-[11px] text-[var(--color-text-secondary)]">{popisek}</p>
            <p className={`mt-0.5 text-sm font-medium tabular-nums ${
                ton === 'spatne' ? 'text-red-400' : ton === 'dobre' ? 'text-emerald-400' : 'text-[var(--color-text-primary)]'
            }`}>
                {hodnota}
            </p>
        </div>
    );
}
