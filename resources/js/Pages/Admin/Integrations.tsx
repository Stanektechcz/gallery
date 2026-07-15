import BankConnectionManager from '@/Components/Banking/BankConnectionManager';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    Check,
    CheckCircle2,
    CircleAlert,
    CircleDollarSign,
    Clapperboard,
    ExternalLink,
    KeyRound,
    LoaderCircle,
    MapPinned,
    RefreshCw,
    Route,
    Save,
    ShieldCheck,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type CredentialMeta = {
    label: string;
    type: 'text' | 'email' | 'password';
    placeholder?: string;
    help?: string;
};

type Provider = {
    provider: string;
    name: string;
    category: 'finance' | 'entertainment' | 'travel' | 'places';
    priority: number;
    free: boolean;
    description: string;
    credentials: string[];
    credential_meta: Record<string, CredentialMeta>;
    capabilities: string[];
    setup_steps: string[];
    docs_url: string;
    signup_url: string;
    is_enabled: boolean;
    is_configured: boolean;
    configured_credentials: string[];
    missing_credentials: string[];
    last_tested_at?: string | null;
    last_status?: 'ok' | 'failed' | null;
    last_error?: string | null;
    runtime?: {
        showings_count?: number;
        last_sync_status?: string | null;
        last_sync_at?: string | null;
        last_sync_error?: string | null;
    } | null;
};

type Notice = { tone: 'success' | 'error' | 'info'; text: string } | null;

const categoryMeta = {
    finance: { label: 'Finance', icon: CircleDollarSign },
    entertainment: { label: 'Filmy a kino', icon: Clapperboard },
    travel: { label: 'Cestování', icon: Route },
    places: { label: 'Místa', icon: MapPinned },
};

const formatDate = (value?: string | null) => value
    ? new Date(value).toLocaleString('cs-CZ', { dateStyle: 'medium', timeStyle: 'short' })
    : 'zatím neproběhlo';

export default function Integrations({ providers, gallerySpaceId }: { providers: Provider[]; gallerySpaceId?: number | null }) {
    const [items, setItems] = useState(providers);
    const [values, setValues] = useState<Record<string, Record<string, string>>>({});
    const [notice, setNotice] = useState<Notice>(null);
    const [busy, setBusy] = useState<string | null>(null);

    const featured = useMemo(() => items.filter(item => item.priority < 100), [items]);
    const supporting = useMemo(() => items.filter(item => item.priority >= 100), [items]);
    const readyCount = items.filter(item => item.is_enabled && item.is_configured).length;
    const verifiedCount = items.filter(item => item.last_status === 'ok').length;
    const goCardless = items.find(item => item.provider === 'gocardless_bank_data');

    const change = (provider: string, key: string, value: string) => {
        setValues(current => ({ ...current, [provider]: { ...(current[provider] ?? {}), [key]: value } }));
    };

    const hasPendingValues = (provider: string) => Object.values(values[provider] ?? {}).some(value => value.trim() !== '');

    const mergeItem = (provider: string, data: Partial<Provider>) => {
        setItems(current => current.map(item => item.provider === provider ? { ...item, ...data } : item));
    };

    const save = async (item: Provider, enabled: boolean) => {
        setBusy(`save:${item.provider}`);
        setNotice(null);
        try {
            const response = await axios.put(`/admin/integrations/${item.provider}`, {
                is_enabled: enabled,
                config: values[item.provider] ?? {},
            });
            mergeItem(item.provider, response.data);
            setValues(current => ({ ...current, [item.provider]: {} }));
            setNotice({ tone: 'success', text: `${item.name}: nastavení bylo bezpečně uloženo${response.data.is_enabled ? ' a integrace je aktivní' : ''}.` });
        } catch (error: any) {
            setNotice({ tone: 'error', text: error.response?.data?.message ?? 'Nastavení se nepodařilo uložit.' });
        } finally {
            setBusy(null);
        }
    };

    const test = async (item: Provider) => {
        setBusy(`test:${item.provider}`);
        setNotice(null);
        try {
            const response = await axios.post(`/admin/integrations/${item.provider}/test`);
            mergeItem(item.provider, { last_status: 'ok', last_tested_at: new Date().toISOString(), last_error: null });
            setNotice({ tone: 'success', text: response.data.message ?? `${item.name}: připojení funguje.` });
        } catch (error: any) {
            const message = error.response?.data?.message ?? 'Připojení se nepodařilo ověřit.';
            mergeItem(item.provider, { last_status: 'failed', last_tested_at: new Date().toISOString(), last_error: message });
            setNotice({ tone: 'error', text: message });
        } finally {
            setBusy(null);
        }
    };

    const syncCinema = async () => {
        setBusy('sync:cinema_city');
        setNotice(null);
        try {
            const response = await axios.post('/api/v1/entertainment/cinema/sync', { days: 10 });
            const warnings = (response.data.warnings ?? []) as string[];
            mergeItem('cinema_city', {
                runtime: {
                    showings_count: response.data.count ?? 0,
                    last_sync_status: response.data.status,
                    last_sync_at: new Date().toISOString(),
                    last_sync_error: warnings.length ? warnings.join('\n') : null,
                },
            });
            setNotice({
                tone: warnings.length ? 'info' : 'success',
                text: warnings.length
                    ? `Program byl načten částečně (${response.data.count ?? 0} projekcí). ${warnings[0]}`
                    : `Program Velkého Špalíčku byl obnoven: ${response.data.count ?? 0} projekcí.`,
            });
        } catch (error: any) {
            const base = error.response?.data?.message ?? 'Program kina se nepodařilo obnovit.';
            const reason = error.response?.data?.reason;
            setNotice({ tone: 'error', text: reason ? `${base} Důvod: ${reason}` : base });
        } finally {
            setBusy(null);
        }
    };

    const renderProvider = (item: Provider) => {
        const CategoryIcon = categoryMeta[item.category]?.icon ?? KeyRound;
        const isKeyless = item.credentials.length === 0;
        const pending = hasPendingValues(item.provider);
        const saving = busy === `save:${item.provider}`;
        const testing = busy === `test:${item.provider}`;
        const syncing = busy === 'sync:cinema_city';
        const canTest = isKeyless || (item.is_configured && item.is_enabled);

        return (
            <section id={item.provider} key={item.provider} className="scroll-mt-4 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:p-5">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-white/5 text-[var(--color-accent)]"><CategoryIcon size={18}/></span>
                            <h2 className="font-semibold text-white">{item.name}</h2>
                            {item.free && <span className="rounded-full bg-green-500/15 px-2 py-0.5 text-[10px] text-green-300">bezplatné</span>}
                            <span className={`rounded-full px-2 py-0.5 text-[10px] ${item.is_enabled && item.is_configured ? 'bg-emerald-500/15 text-emerald-200' : item.missing_credentials.length ? 'bg-amber-500/15 text-amber-200' : 'bg-white/5 text-[var(--color-text-secondary)]'}`}>
                                {item.is_enabled && item.is_configured ? 'aktivní' : item.missing_credentials.length ? 'chybí konfigurace' : 'neaktivní'}
                            </span>
                        </div>
                        <p className="mt-3 max-w-3xl text-sm leading-relaxed text-[var(--color-text-secondary)]">{item.description}</p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {item.capabilities.map(capability => <span key={capability} className="rounded-lg border border-[var(--color-border)] bg-black/10 px-2 py-1 text-[11px] text-slate-300">{capability}</span>)}
                        </div>
                    </div>
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        <span className={`inline-flex items-center gap-1 text-xs ${item.last_status === 'ok' ? 'text-green-300' : item.last_status === 'failed' ? 'text-red-300' : 'text-[var(--color-text-secondary)]'}`}>
                            {item.last_status === 'ok' ? <CheckCircle2 size={14}/> : item.last_status === 'failed' ? <CircleAlert size={14}/> : <RefreshCw size={14}/>}
                            {item.last_status === 'ok' ? 'ověřeno' : item.last_status === 'failed' ? 'chyba testu' : 'neověřeno'}
                        </span>
                        <button type="button" onClick={() => test(item)} disabled={busy !== null || !canTest} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-white disabled:cursor-not-allowed disabled:opacity-40">
                            {testing ? <LoaderCircle size={14} className="animate-spin"/> : <RefreshCw size={14}/>} Test připojení
                        </button>
                    </div>
                </div>

                {item.credentials.length > 0 ? (
                    <div className="mt-5 rounded-xl border border-[var(--color-border)] bg-black/10 p-3 sm:p-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            {item.credentials.map(key => {
                                const meta = item.credential_meta[key] ?? { label: key, type: 'text' as const };
                                const stored = item.configured_credentials.includes(key);
                                return (
                                    <label key={key} className="text-xs text-[var(--color-text-secondary)]">
                                        <span className="flex items-center justify-between gap-2">
                                            <span>{meta.label}</span>
                                            {stored && <span className="inline-flex items-center gap-1 text-emerald-300"><Check size={12}/> bezpečně uloženo</span>}
                                        </span>
                                        <input
                                            type={meta.type}
                                            autoComplete="off"
                                            value={values[item.provider]?.[key] ?? ''}
                                            onChange={event => change(item.provider, key, event.target.value)}
                                            placeholder={stored ? 'Nová hodnota pouze při změně' : meta.placeholder ?? 'Vložte hodnotu'}
                                            className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-black/20 px-3 text-sm text-white outline-none focus:border-[var(--color-accent)]"
                                        />
                                        {meta.help && <span className="mt-1 block leading-relaxed">{meta.help}</span>}
                                    </label>
                                );
                            })}
                        </div>
                        <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="inline-flex items-center gap-1 text-xs text-[var(--color-text-secondary)]"><ShieldCheck size={14}/> Hodnoty jsou v databázi šifrované a API je nikdy nevrací.</p>
                            <div className="flex flex-wrap gap-2">
                                {item.is_configured && (
                                    <button type="button" onClick={() => save(item, !item.is_enabled)} disabled={busy !== null || pending} className="min-h-10 rounded-xl border border-[var(--color-border)] px-3 text-xs text-white disabled:opacity-40">
                                        {item.is_enabled ? 'Deaktivovat' : 'Aktivovat'}
                                    </button>
                                )}
                                <button type="button" onClick={() => save(item, item.is_enabled || !item.is_configured)} disabled={busy !== null || !pending} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-xs font-medium text-white disabled:cursor-not-allowed disabled:opacity-40">
                                    {saving ? <LoaderCircle size={14} className="animate-spin"/> : <Save size={14}/>} {item.is_configured ? 'Uložit změny' : 'Uložit a aktivovat'}
                                </button>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="mt-5 flex items-center gap-2 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs text-emerald-100">
                        <CheckCircle2 size={16} className="shrink-0"/> Integrace nevyžaduje klíč a je připravená k použití.
                    </div>
                )}

                {item.provider === 'cinema_city' && (
                    <div className="mt-4 grid gap-3 rounded-xl border border-violet-400/20 bg-violet-500/5 p-3 sm:grid-cols-[1fr_auto] sm:items-center">
                        <div>
                            <p className="text-sm font-medium text-white">Program Velkého Špalíčku</p>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                Budoucích projekcí: {item.runtime?.showings_count ?? 0} · poslední synchronizace: {formatDate(item.runtime?.last_sync_at)}
                                {item.runtime?.last_sync_status ? ` · stav ${item.runtime.last_sync_status}` : ''}
                            </p>
                            {item.runtime?.last_sync_error && <p className="mt-2 whitespace-pre-line text-xs text-amber-200">{item.runtime.last_sync_error}</p>}
                        </div>
                        <button type="button" onClick={syncCinema} disabled={busy !== null} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-violet-500/20 px-4 text-xs font-medium text-violet-100 disabled:opacity-40">
                            {syncing ? <LoaderCircle size={14} className="animate-spin"/> : <RefreshCw size={14}/>} Obnovit 10 dní
                        </button>
                    </div>
                )}

                <div className="mt-4 flex flex-col gap-3 border-t border-[var(--color-border)] pt-4 sm:flex-row sm:items-end sm:justify-between">
                    <details className="text-xs text-[var(--color-text-secondary)]">
                        <summary className="cursor-pointer text-white">Jak integraci zprovoznit</summary>
                        <ol className="mt-2 list-decimal space-y-1 pl-5">{item.setup_steps.map(step => <li key={step}>{step}</li>)}</ol>
                    </details>
                    <div className="flex flex-wrap gap-3 text-xs">
                        <a href={item.docs_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-[var(--color-accent)]">Dokumentace <ExternalLink size={12}/></a>
                        <a href={item.signup_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-[var(--color-accent)]">Získat přístup <ExternalLink size={12}/></a>
                        <span className="text-[var(--color-text-secondary)]">Poslední test: {formatDate(item.last_tested_at)}</span>
                    </div>
                </div>
                {item.last_error && <p className="mt-3 rounded-lg border border-red-500/20 bg-red-500/5 p-2 text-xs text-red-200">{item.last_error}</p>}
            </section>
        );
    };

    return (
        <AppLayout>
            <Head title="Integrace"/>
            <main className="mx-auto max-w-6xl p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Administrace</p>
                        <h1 className="text-2xl font-bold text-white sm:text-3xl">Integrace a API klíče</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-relaxed text-[var(--color-text-secondary)]">Na jednom místě nastavíte Revolut, globální databázi filmů, program kina, mapy, trasy, počasí i kurzy. Klíče aplikace ukládá výhradně šifrovaně.</p>
                    </div>
                    <Link href="/admin" className="text-sm text-[var(--color-accent)]">Přehled administrace</Link>
                </div>

                <div className="mt-5 grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3"><p className="text-xs text-[var(--color-text-secondary)]">Připravené integrace</p><p className="mt-1 text-2xl font-semibold text-white">{readyCount}/{items.length}</p></div>
                    <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3"><p className="text-xs text-[var(--color-text-secondary)]">Úspěšně otestované</p><p className="mt-1 text-2xl font-semibold text-white">{verifiedCount}</p></div>
                    <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3"><p className="text-xs text-[var(--color-text-secondary)]">Uložení klíčů</p><p className="mt-1 inline-flex items-center gap-2 text-sm font-medium text-emerald-200"><ShieldCheck size={18}/> šifrované</p></div>
                </div>

                {notice && <div role="status" className={`mt-4 rounded-xl border p-3 text-sm ${notice.tone === 'success' ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-100' : notice.tone === 'error' ? 'border-red-500/25 bg-red-500/10 text-red-100' : 'border-amber-500/25 bg-amber-500/10 text-amber-100'}`}>{notice.text}</div>}

                <div className="mt-7">
                    <h2 className="text-lg font-semibold text-white">Klíčové integrace</h2>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Revolut, TMDB a program kina jsou zvýrazněné jako první.</p>
                    <div className="mt-3 space-y-4">{featured.map(renderProvider)}</div>
                </div>

                {gallerySpaceId && goCardless?.is_enabled && goCardless.is_configured && (
                    <div className="mt-6" id="revolut-connection"><BankConnectionManager gallerySpaceId={gallerySpaceId}/></div>
                )}

                <div className="mt-8">
                    <h2 className="text-lg font-semibold text-white">Cestování, místa a kurzy</h2>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Podpůrné zdroje využívané automaticky napříč plánováním.</p>
                    <div className="mt-3 space-y-4">{supporting.map(renderProvider)}</div>
                </div>

                <p className="mt-6 flex items-start gap-2 text-xs leading-relaxed text-[var(--color-text-secondary)]"><CheckCircle2 size={15} className="mt-0.5 shrink-0 text-green-300"/> Po uložení klíčů integraci aktivujte a vždy spusťte test. Test už zobrazuje konkrétní bezpečný důvod chyby bez vyzrazení tajných hodnot.</p>
            </main>
        </AppLayout>
    );
}
