import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ExternalLink, HardDrive, Loader2, Lock, Plug, RefreshCw, Trash2, Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Provider {
    code: string;
    name: string;
    auth: 'token' | 'oauth' | 'invite';
    summary: string;
    help: string;
    scopes: Array<'personal' | 'shared'>;
    ready: boolean;
    caveat?: string;
    managed_by?: string;
}

interface Storage {
    account: string | null;
    status: string;
    last_ok: string | null;
    last_error: string | null;
    last_error_at: string | null;
}

interface Connection {
    uuid: string;
    provider: string;
    visibility: 'personal' | 'shared';
    label: string | null;
    account_name: string | null;
    account_avatar: string | null;
    status: string;
    last_error: string | null;
    owner: { id: number; name: string | null };
    is_mine: boolean;
    can_manage: boolean;
    has_webhook: boolean;
    documents: number;
}

interface Doc {
    uuid: string; title: string | null; kind: string;
    icon: string | null; url: string | null; updated_at: string | null;
}

/** Names come from the server's catalogue; this is only the fallback for an unknown code. */
const nameOf = (catalogue: Provider[], code: string) =>
    catalogue.find(provider => provider.code === code)?.name ?? code;

export default function Connections() {
    const flash = (usePage().props as any).flash ?? {};
    const [connections, setConnections] = useState<Connection[]>([]);
    const [documents, setDocuments] = useState<Doc[]>([]);
    const [discordReady, setDiscordReady] = useState(true);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const [token, setToken] = useState('');
    const [visibility, setVisibility] = useState<'personal' | 'shared'>('personal');

    const [catalogue, setCatalogue] = useState<Provider[]>([]);
    const [storage, setStorage] = useState<Storage | null>(null);
    /** Which service's form is open. One at a time: two token fields side by side invite pasting into the wrong one. */
    const [adding, setAdding] = useState<Provider | null>(null);

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/propojeni');
            setConnections(response.data.connections ?? []);
            setCatalogue(response.data.catalogue ?? []);
            setStorage(response.data.storage ?? null);
            setDocuments(response.data.documents ?? []);
            setDiscordReady(response.data.discord_ready !== false);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Propojení se nepodařilo načíst.');
        } finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const connectNotion = async () => {
        if (!token.trim()) { setError('Vložte token integrace.'); return; }
        setBusy('notion'); setError('');
        try {
            const response = await axios.post('/api/v1/propojeni/notion', { token: token.trim(), visibility });
            setToken('');
            setNotice(response.data.synced > 0
                ? `Notion je propojený, načteno ${response.data.synced} stránek.`
                : 'Notion je propojený. Zatím s integrací nesdílíte žádnou stránku — nasdílejte ji v Notionu a dejte Synchronizovat.');
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Notion se nepodařilo propojit.');
        } finally { setBusy(null); }
    };

    const sync = async (row: Connection) => {
        setBusy(row.uuid); setError('');
        try {
            const response = await axios.post(`/api/v1/propojeni/${row.uuid}/synchronizace`);
            setNotice(response.data.error ?? `Načteno ${response.data.synced} stránek.`);
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Synchronizace selhala.');
        } finally { setBusy(null); }
    };

    const setShared = async (row: Connection, shared: boolean) => {
        setBusy(row.uuid);
        try {
            await axios.put(`/api/v1/propojeni/${row.uuid}/viditelnost`, { visibility: shared ? 'shared' : 'personal' });
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Viditelnost se nepodařilo změnit.');
        } finally { setBusy(null); }
    };

    const disconnect = async (row: Connection) => {
        if (!window.confirm(`Odpojit ${nameOf(catalogue, row.provider)}? Načtené stránky se odstraní z přehledu.`)) return;
        setBusy(row.uuid);
        try { await axios.delete(`/api/v1/propojeni/${row.uuid}`); await load(); }
        catch (reason: any) { setError(reason?.response?.data?.message ?? 'Odpojit se nepodařilo.'); }
        finally { setBusy(null); }
    };

    const saveWebhook = async (row: Connection, url: string) => {
        setBusy(row.uuid); setError('');
        try {
            await axios.put(`/api/v1/propojeni/${row.uuid}/webhook`, { webhook_url: url || null });
            setNotice(url ? 'Webhook uložen, zkušební zpráva odeslána.' : 'Webhook odebrán.');
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Webhook se nepodařilo uložit.');
        } finally { setBusy(null); }
    };

    const notion = connections.filter(row => row.provider === 'notion');
    const discord = connections.filter(row => row.provider === 'discord');

    return (
        <AppLayout title="Úložiště a služby">
            <Head title="Úložiště a služby" />
            <main className="mx-auto max-w-3xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Nastavení</p>
                <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">
                    <Plug size={24} className="text-[var(--color-accent)]" /> Úložiště a služby
                </h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Kam galerie ukládá soubory a s čím je propojená. Osobní propojení vidíte jen vy;
                    sdílené může používat celý prostor, ale odpojit ho smí jen ten, kdo ho vytvořil.
                </p>

                {(flash.error || error) && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{flash.error || error}</p>}
                {(flash.success || notice) && <p className="mt-4 rounded-xl border border-emerald-400/25 bg-emerald-500/10 p-3 text-xs text-emerald-100">{flash.success || notice}</p>}
                {loading && <div className="mt-8 flex justify-center"><Loader2 className="animate-spin text-[var(--color-accent)]" /></div>}

                {!loading && (
                    <>
                        {/* Storage first. It is the one connection the gallery cannot work
                            without, and burying it under the optional ones would say the
                            opposite. It keeps its own screen for the heavy operations —
                            re-syncing and rebuilding the folder tree are not things to put
                            a click away from a token field. */}
                        <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]">
                                        <HardDrive size={17} className="text-[var(--color-accent)]" /> Úložiště galerie
                                    </h2>
                                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                        {storage
                                            ? <>Google Drive · {storage.account ?? 'účet neznámý'}</>
                                            : 'Zatím není připojené žádné úložiště. Fotky se ukládají jen lokálně.'}
                                    </p>
                                    {storage?.last_error && (
                                        <p className="mt-2 rounded-lg bg-red-500/10 p-2 text-[11px] text-red-200">
                                            Poslední chyba: {storage.last_error}
                                        </p>
                                    )}
                                </div>

                                <span className={`shrink-0 rounded-lg px-2 py-1 text-[10px] ${storage?.status === 'connected'
                                    ? 'bg-emerald-500/15 text-emerald-200'
                                    : 'bg-[var(--color-surface-muted)] text-[var(--color-text-secondary)]'}`}>
                                    {storage?.status === 'connected' ? 'Připojeno' : storage ? storage.status : 'Nepřipojeno'}
                                </span>
                            </div>

                            <Link
                                href="/settings/storage/google"
                                className="mt-3 inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)] hover:border-[var(--color-accent)]"
                            >
                                <HardDrive size={14} /> {storage ? 'Spravovat úložiště' : 'Připojit Google Drive'}
                            </Link>
                        </section>

                        {/* What else can be joined, and what it would take. A service whose
                            OAuth application nobody has registered says so here rather than
                            handing somebody a button that leads to an error page. */}
                        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <h2 className="font-semibold text-[var(--color-text-primary)]">Dostupné služby</h2>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                Každou lze připojit zvlášť jako osobní a zvlášť jako sdílenou — dva účty téže služby vedle sebe.
                            </p>

                            <div className="mt-3 space-y-2">
                                {catalogue.filter(provider => provider.managed_by !== 'storage').map(provider => {
                                    const mine = connections.filter(row => row.provider === provider.code);

                                    return (
                                        <div key={provider.code} className="rounded-xl border border-[var(--color-border)] p-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium text-[var(--color-text-primary)]">
                                                        {provider.name}
                                                        {mine.length > 0 && (
                                                            <span className="ml-2 text-[10px] text-[var(--color-accent)]">
                                                                {mine.length}× připojeno
                                                            </span>
                                                        )}
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-[var(--color-text-secondary)]">{provider.summary}</p>
                                                    {provider.caveat && (
                                                        <p className="mt-1 text-[11px] text-amber-200">{provider.caveat}</p>
                                                    )}
                                                </div>

                                                {provider.auth === 'token' && provider.code !== 'notion' ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => { setAdding(adding?.code === provider.code ? null : provider); setToken(''); setVisibility(provider.scopes[0]); }}
                                                        className="shrink-0 rounded-lg border border-[var(--color-border)] px-2.5 py-1.5 text-xs text-[var(--color-text-primary)] hover:border-[var(--color-accent)]"
                                                    >
                                                        Připojit
                                                    </button>
                                                ) : (
                                                    <span className="shrink-0 rounded-lg bg-[var(--color-surface-muted)] px-2 py-1 text-[10px] text-[var(--color-text-secondary)]">
                                                        {provider.code === 'notion' ? 'níže' : provider.ready ? 'přes přesměrování' : 'nenastaveno'}
                                                    </span>
                                                )}
                                            </div>

                                            {adding?.code === provider.code && (
                                                <div className="mt-3 space-y-2 border-t border-[var(--color-border)] pt-3">
                                                    <p className="text-[11px] text-[var(--color-text-secondary)]">{provider.help}</p>
                                                    <input
                                                        type="password"
                                                        value={token}
                                                        onChange={event => setToken(event.target.value)}
                                                        placeholder="Token"
                                                        className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-[var(--color-text-primary)]"
                                                    />
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <select
                                                            value={visibility}
                                                            onChange={event => setVisibility(event.target.value as 'personal' | 'shared')}
                                                            className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-2 text-xs text-[var(--color-text-primary)]"
                                                        >
                                                            {provider.scopes.includes('personal') && <option value="personal">Osobní — jen pro mě</option>}
                                                            {provider.scopes.includes('shared') && <option value="shared">Sdílené — pro celý prostor</option>}
                                                        </select>
                                                        <button
                                                            type="button"
                                                            disabled={busy === provider.code || !token}
                                                            onClick={async () => {
                                                                setBusy(provider.code); setError('');
                                                                try {
                                                                    await axios.post(`/api/v1/propojeni/token/${provider.code}`, { token, visibility });
                                                                    setToken(''); setAdding(null);
                                                                    setNotice(`${provider.name} připojeno.`);
                                                                    await load();
                                                                } catch (reason: any) {
                                                                    setError(reason?.response?.data?.message ?? 'Připojit se nepodařilo.');
                                                                } finally { setBusy(null); }
                                                            }}
                                                            className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-3 text-xs text-[var(--color-accent-contrast)] disabled:opacity-50"
                                                        >
                                                            {busy === provider.code && <Loader2 size={13} className="animate-spin" />} Uložit
                                                        </button>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </section>

                        <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <h2 className="font-semibold text-[var(--color-text-primary)]">Notion</h2>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                V Notionu si vytvořte vlastní integraci (Settings → Connections → Develop or manage integrations),
                                zkopírujte její interní token a <strong>nasdílejte jí stránky</strong>, které sem chcete promítat.
                                Bez nasdílení integrace nevidí nic — tak je Notion navržený.
                            </p>

                            {notion.map(row => (
                                <article key={row.uuid} className="mt-3 rounded-xl border border-[var(--color-border)] p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-[var(--color-text-primary)]">{row.label ?? row.account_name}</p>
                                            <p className="text-[11px] text-[var(--color-text-secondary)]">
                                                {row.documents} stránek · {row.is_mine ? 'vaše' : `${row.owner.name ?? 'partner'}`}
                                            </p>
                                        </div>
                                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-[10px] ${row.visibility === 'shared' ? 'bg-emerald-500/15 text-emerald-200' : 'bg-[var(--color-surface-muted)] text-[var(--color-text-secondary)]'}`}>
                                            {row.visibility === 'shared' ? <Users size={11} /> : <Lock size={11} />}
                                            {row.visibility === 'shared' ? 'Sdílené' : 'Osobní'}
                                        </span>
                                    </div>

                                    {row.last_error && <p className="mt-2 rounded-lg bg-red-500/10 p-2 text-[11px] text-red-100">{row.last_error}</p>}

                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <button type="button" disabled={busy === row.uuid} onClick={() => void sync(row)} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)] disabled:opacity-50">
                                            <RefreshCw size={13} className={busy === row.uuid ? 'animate-spin' : ''} /> Synchronizovat
                                        </button>
                                        {row.can_manage && (
                                            <>
                                                <button type="button" disabled={busy === row.uuid} onClick={() => void setShared(row, row.visibility !== 'shared')} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)] disabled:opacity-50">
                                                    {row.visibility === 'shared' ? 'Nechat jen pro sebe' : 'Sdílet s prostorem'}
                                                </button>
                                                <button type="button" disabled={busy === row.uuid} onClick={() => void disconnect(row)} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-xs text-red-300 hover:bg-red-500/10 disabled:opacity-50">
                                                    <Trash2 size={13} /> Odpojit
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </article>
                            ))}

                            <div className="mt-3 space-y-2 border-t border-[var(--color-border)] pt-3">
                                <input
                                    value={token}
                                    onChange={event => setToken(event.target.value)}
                                    type="password"
                                    autoComplete="off"
                                    placeholder="Interní token integrace (ntn_…)"
                                    className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                                />
                                <div className="flex flex-wrap gap-2">
                                    <select value={visibility} onChange={event => setVisibility(event.target.value as 'personal' | 'shared')} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm text-[var(--color-text-primary)]">
                                        <option value="personal">Osobní — vidím jen já</option>
                                        <option value="shared">Sdílené — vidí celý prostor</option>
                                    </select>
                                    <button type="button" disabled={busy === 'notion'} onClick={() => void connectNotion()} className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                                        {busy === 'notion' && <Loader2 size={14} className="animate-spin" />} Připojit Notion
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <h2 className="font-semibold text-[var(--color-text-primary)]">Discord</h2>

                            {!discordReady && (
                                <p className="mt-2 rounded-lg border border-amber-400/25 bg-amber-500/10 p-2.5 text-[11px] text-amber-100">
                                    Discord zatím není nastavený. Správce musí v administraci doplnit Client ID a Client Secret aplikace.
                                </p>
                            )}

                            {discord.map(row => (
                                <article key={row.uuid} className="mt-3 rounded-xl border border-[var(--color-border)] p-3">
                                    <div className="flex items-center gap-3">
                                        {row.account_avatar && <img src={row.account_avatar} alt="" className="h-9 w-9 rounded-full" />}
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-[var(--color-text-primary)]">{row.account_name}</p>
                                            <p className="text-[11px] text-[var(--color-text-secondary)]">{row.is_mine ? 'váš účet' : row.owner.name}</p>
                                        </div>
                                    </div>

                                    {row.last_error && <p className="mt-2 rounded-lg bg-red-500/10 p-2 text-[11px] text-red-100">{row.last_error}</p>}

                                    {row.can_manage && (
                                        <div className="mt-3 space-y-2 border-t border-[var(--color-border)] pt-3">
                                            <label className="block text-[11px] text-[var(--color-text-secondary)]">
                                                Adresa webhooku kanálu — sem budou chodit upozornění
                                            </label>
                                            <div className="flex flex-wrap gap-2">
                                                <input
                                                    defaultValue=""
                                                    placeholder={row.has_webhook ? 'Webhook je nastavený — vložením nového ho přepíšete' : 'https://discord.com/api/webhooks/…'}
                                                    onBlur={event => { if (event.target.value.trim()) void saveWebhook(row, event.target.value.trim()); }}
                                                    className="min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-xs text-[var(--color-text-primary)]"
                                                />
                                                {row.has_webhook && (
                                                    <button type="button" disabled={busy === row.uuid} onClick={() => void saveWebhook(row, '')} className="min-h-10 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)] disabled:opacity-50">Odebrat</button>
                                                )}
                                            </div>
                                            <div className="flex flex-wrap gap-2 pt-1">
                                                <button type="button" disabled={busy === row.uuid} onClick={() => void setShared(row, row.visibility !== 'shared')} className="min-h-9 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)] disabled:opacity-50">
                                                    {row.visibility === 'shared' ? 'Nechat jen pro sebe' : 'Sdílet s prostorem'}
                                                </button>
                                                <button type="button" disabled={busy === row.uuid} onClick={() => void disconnect(row)} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-xs text-red-300 hover:bg-red-500/10 disabled:opacity-50">
                                                    <Trash2 size={13} /> Odpojit
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </article>
                            ))}

                            {discordReady && discord.length === 0 && (
                                <a href="/discord/pripojit" className="mt-3 inline-flex min-h-10 items-center gap-2 rounded-lg bg-[#5865F2] px-4 text-sm font-medium text-white">
                                    Připojit Discord
                                </a>
                            )}

                            <p className="mt-3 text-[11px] text-[var(--color-text-secondary)]">
                                Živý stav (online, ve hře, poslouchá Spotify) Discord přes HTTP nevydává — chodí jen po Gateway
                                trvale běžícímu botovi. Co je dostupné: účet, servery a propojené služby profilu.
                            </p>
                        </section>

                        {documents.length > 0 && (
                            <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                                <h2 className="font-semibold text-[var(--color-text-primary)]">Načtené stránky</h2>
                                <div className="mt-3 space-y-1">
                                    {documents.map(doc => (
                                        <a
                                            key={doc.uuid}
                                            href={doc.url ?? '#'}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="flex items-center gap-2 rounded-lg px-2 py-2 text-sm text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                                        >
                                            <span className="w-5 shrink-0 text-center">{doc.icon ?? (doc.kind === 'database' ? '🗂️' : '📄')}</span>
                                            <span className="min-w-0 flex-1 truncate">{doc.title}</span>
                                            <ExternalLink size={13} className="shrink-0 opacity-60" />
                                        </a>
                                    ))}
                                </div>
                            </section>
                        )}
                    </>
                )}
            </main>
        </AppLayout>
    );
}
