import axios from 'axios';
import { Check, FileText, Loader2, X } from 'lucide-react';
import { useState } from 'react';

/**
 * Načtení bankovního výpisu do rozpočtu.
 *
 * Dva kroky, a to schválně. Import, který jedním kliknutím zapíše dvě stě položek, se
 * opravuje hůř, než kdyby je člověk naťukal ručně — proto se nejdřív ukáže, co v souboru
 * je, a teprve pak se to uloží.
 *
 * Duplicity se předem odškrtnou samy. Výpisy se stahují po měsících a překryv je
 * pravidlo, ne výjimka.
 */

interface Radek {
    kind: 'expense' | 'income';
    amount: number;
    currency: string;
    spent_on: string;
    note: string | null;
    duplicate: boolean;
    /** Co uhádl klasifikátor obchodníků. Návrh, ne rozhodnutí — dá se přepsat. */
    suggested_category_id: number | null;
    suggested_from: 'rule' | 'default' | null;
    budget_category_id?: number | null;
}

const FIELD = 'w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 py-2 text-sm text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';

const money = (amount: number, currency: string) =>
    new Intl.NumberFormat('cs-CZ', { style: 'currency', currency, maximumFractionDigits: 2 }).format(amount);

const den = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' });

export default function StatementImport({ budget, categories, onDone, onCancel }: {
    budget: { uuid: string; currency: string };
    categories: Array<{ id: number; name: string }>;
    onDone: () => void;
    onCancel: () => void;
}) {
    const [rows, setRows] = useState<Radek[] | null>(null);
    const [chosen, setChosen] = useState<Set<number>>(new Set());
    const [busy, setBusy] = useState(false);
    const [note, setNote] = useState('');
    const [error, setError] = useState('');

    const read = async (file: File) => {
        setBusy(true);
        setError('');

        try {
            const form = new FormData();
            form.append('file', file);

            const { data } = await axios.post(`/api/v1/rozpocty/${budget.uuid}/vypis/nahled`, form);
            // Návrh kategorie se rovnou stane volbou. Zůstat jen jako našeptávaná
            // hodnota by znamenalo, že se stejně musí u každého řádku potvrdit.
            const nactene = ((data.rows ?? []) as Radek[]).map(radek => ({
                ...radek,
                budget_category_id: radek.suggested_category_id,
            }));

            setRows(nactene);
            // Předvybrané je všechno kromě toho, co už v rozpočtu je.
            setChosen(new Set(nactene.map((radek, i) => (radek.duplicate ? -1 : i)).filter(i => i >= 0)));

            setNote(nactene.length === 0
                ? 'V souboru se nenašla žádná platná řádka. Zkontrolujte, že jde o CSV výpis se sloupci datum a částka.'
                : `Načteno ${nactene.length} položek${data.skipped ? `, ${data.skipped} přeskočeno` : ''}${data.duplicates ? `, ${data.duplicates} už v rozpočtu je` : ''}${data.categorised ? `, ${data.categorised} zařazeno podle obchodníka` : ''}.`);
        } catch (problem: any) {
            setError(problem?.response?.data?.message ?? 'Soubor se nepodařilo přečíst.');
        } finally {
            setBusy(false);
        }
    };

    const save = async () => {
        if (! rows || chosen.size === 0) return;
        setBusy(true);

        try {
            await axios.post(`/api/v1/rozpocty/${budget.uuid}/vypis`, {
                rows: [...chosen].sort((a, b) => a - b).map(i => ({
                    kind: rows[i].kind,
                    amount: rows[i].amount,
                    currency: rows[i].currency,
                    spent_on: rows[i].spent_on,
                    note: rows[i].note,
                    budget_category_id: rows[i].budget_category_id ?? null,
                })),
            });
            onDone();
        } catch (problem: any) {
            setError(problem?.response?.data?.message ?? 'Uložení se nezdařilo.');
            setBusy(false);
        }
    };

    const prepnout = (i: number) => setChosen(stav => {
        const dalsi = new Set(stav);
        dalsi.has(i) ? dalsi.delete(i) : dalsi.add(i);

        return dalsi;
    });

    const nastavitKategorii = (i: number, hodnota: string) =>
        setRows(stav => stav!.map((radek, index) =>
            index === i ? { ...radek, budget_category_id: hodnota ? Number(hodnota) : null } : radek));

    /** Doplní kategorii jen tam, kde ještě žádná není — návrhy klasifikátoru zůstanou. */
    const doplnitZbytek = (hodnota: string) =>
        setRows(stav => stav!.map(radek =>
            radek.budget_category_id ? radek : { ...radek, budget_category_id: hodnota ? Number(hodnota) : null }));

    const bezKategorie = rows?.filter(r => ! r.budget_category_id).length ?? 0;

    return (
        <div className="mb-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-primary)] p-4">
            <div className="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-[var(--color-text-primary)]">
                        <FileText size={15}/> Načíst výpis z banky
                    </h3>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                        CSV z internetového bankovnictví. Nic se neuloží, dokud to nepotvrdíte.
                    </p>
                </div>
                <button type="button" onClick={onCancel} className="rounded p-1 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                    <X size={16}/>
                </button>
            </div>

            {! rows && (
                <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-[var(--color-border)] px-4 py-6 text-sm text-[var(--color-text-secondary)] hover:border-[var(--color-accent)] hover:text-[var(--color-text-primary)]">
                    <input type="file" accept=".csv,text/csv,text/plain" className="hidden" disabled={busy}
                        onChange={e => { const file = e.target.files?.[0]; e.target.value = ''; if (file) void read(file); }}/>
                    {busy ? <><Loader2 size={15} className="animate-spin"/> Čtu soubor…</> : 'Vyberte soubor CSV'}
                </label>
            )}

            {note && <p className="mb-2 text-xs text-[var(--color-text-secondary)]">{note}</p>}
            {error && <p className="mb-2 text-xs text-red-400">{error}</p>}

            {rows && rows.length > 0 && (
                <>
                    <div className="mb-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                        <button type="button" onClick={() => setChosen(new Set(rows.map((_, i) => i)))}
                            className="text-xs text-[var(--color-text-secondary)] underline-offset-2 hover:underline">
                            Vybrat vše
                        </button>
                        <button type="button" onClick={() => setChosen(new Set())}
                            className="text-xs text-[var(--color-text-secondary)] underline-offset-2 hover:underline">
                            Nevybrat nic
                        </button>

                        {/* Hromadné doplnění nepřepisuje, co klasifikátor uhádl — jinak by
                            jedno kliknutí zahodilo práci, kterou aplikace právě odvedla. */}
                        {bezKategorie > 0 && categories.length > 0 && (
                            <label className="ml-auto flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                                Zbylým {bezKategorie} dát
                                <select defaultValue="" onChange={e => { doplnitZbytek(e.target.value); e.target.value = ''; }}
                                    className={`${FIELD} w-40 py-1.5`}>
                                    <option value="">vyberte…</option>
                                    {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </label>
                        )}
                    </div>

                    {/* Vlastní posuvník, ne celá stránka — výpis má klidně tři sta řádků
                        a formulář pod ním by se odsunul mimo dosah. */}
                    <div className="max-h-80 overflow-y-auto rounded-lg border border-[var(--color-border)]">
                        {rows.map((radek, i) => (
                            <div key={i}
                                className={`flex items-center gap-2.5 border-b border-[var(--color-border)] px-2.5 py-1.5 last:border-b-0 ${chosen.has(i) ? '' : 'opacity-45'}`}>
                                {/* Zaškrtávátko a popis jsou v <label>, výběr kategorie ne —
                                    kliknutí do selectu uvnitř labelu by řádek odškrtlo. */}
                                <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5">
                                    <input type="checkbox" checked={chosen.has(i)} onChange={() => prepnout(i)} className="shrink-0"/>
                                    <span className="w-12 shrink-0 text-[11px] text-[var(--color-text-secondary)]">{den(radek.spent_on)}</span>
                                    <span className="min-w-0 flex-1 truncate text-xs text-[var(--color-text-primary)]">
                                        {radek.note || (radek.kind === 'income' ? 'Příjem' : 'Výdaj')}
                                        {radek.duplicate && (
                                            <span className="ml-2 rounded bg-amber-500/15 px-1.5 py-0.5 text-[9px] text-amber-300">už zapsáno</span>
                                        )}
                                    </span>
                                </label>

                                {categories.length > 0 && (
                                    <select value={radek.budget_category_id ?? ''} onChange={e => nastavitKategorii(i, e.target.value)}
                                        title={radek.suggested_from === 'rule' ? 'Podle vašeho pravidla' : radek.suggested_from === 'default' ? 'Odhad podle obchodníka' : undefined}
                                        className={`w-28 shrink-0 rounded border bg-[var(--color-bg-primary)] px-1.5 py-1 text-[11px] text-[var(--color-text-primary)] focus:outline-none ${
                                            radek.budget_category_id && radek.budget_category_id === radek.suggested_category_id
                                                ? 'border-emerald-400/40'
                                                : 'border-[var(--color-border)]'
                                        }`}>
                                        <option value="">— bez —</option>
                                        {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                    </select>
                                )}

                                <span className={`shrink-0 text-xs tabular-nums ${radek.kind === 'income' ? 'text-emerald-300' : 'text-[var(--color-text-primary)]'}`}>
                                    {radek.kind === 'income' ? '+' : '−'}{money(radek.amount, radek.currency || budget.currency)}
                                </span>
                            </div>
                        ))}
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" onClick={() => void save()} disabled={busy || chosen.size === 0}
                            className="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                            {busy ? <Loader2 size={14} className="animate-spin"/> : <Check size={14}/>}
                            Zapsat {chosen.size} {chosen.size === 1 ? 'položku' : chosen.size >= 2 && chosen.size <= 4 ? 'položky' : 'položek'}
                        </button>
                        <button type="button" onClick={() => { setRows(null); setNote(''); setError(''); }}
                            className="text-xs text-[var(--color-text-secondary)] underline-offset-2 hover:underline">
                            Jiný soubor
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
