import ServiceLogo from '@/Components/ServiceLogo';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ExternalLink, HardDrive, Loader2, Lock, Plug, RefreshCw, Trash2, Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Provider {
    code: string;
    name: string;
    kind: 'storage' | 'service';
    auth: 'token' | 'oauth' | 'invite' | 'builtin' | 'none';
    mode: 'page' | 'redirect' | 'modal' | 'none';
    url?: string;
    brand?: string;
    summary: string;
    help: string;
    scopes: Array<'personal' | 'shared'>;
    ready: boolean;
    available?: boolean;
    caveat?: string;
    steps?: string[];
    signup_url?: string;
    docs_url?: string;
}

interface Quota {
    used_bytes: number;
    limit_bytes: number | null;
    limit_mb: number | null;
    percent: number | null;
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

/** Sizes in the units people actually think in, not in bytes. */
const bytes = (value: number) => {
    if (value >= 1024 ** 3) return `${(value / 1024 ** 3).toFixed(1)} GB`;
    if (value >= 1024 ** 2) return `${Math.round(value / 1024 ** 2)} MB`;

    return `${Math.round(value / 1024)} kB`;
};

/**
 * Whether a storage connection is working.
 *
 * The server's word for it is "healthy". A card comparing against "connected" — a value
 * that only ever existed in one newer provider — showed a perfectly good Google Drive as
 * not connected, which is the worst kind of wrong: everything works and the screen says
 * it does not.
 */
const isConnected = (row?: Storage | null) => row?.status === 'healthy';

/** Names come from the server's catalogue; this is only the fallback for an unknown code. */
const nameOf = (catalogue: Provider[], code: string) =>
    catalogue.find(provider => provider.code === code)?.name ?? code;

/**
 * One service as a card.
 *
 * What a click does is the provider's own business: storage with a library behind it opens
 * a page, a plain sign-in goes straight to the redirect, and anything needing a token or a
 * choice opens a modal. Three behaviours behind one shape, so the card is read once and
 * the difference is in what follows rather than in how it looks.
 *
 * A service we cannot integrate stays on the grid, greyed, saying why. Removing it would
 * only mean somebody asks for it again next month.
 */
function ServiceCard({ provider, connected, onOpen, onExplain }: {
    provider: Provider;
    connected: string | null;
    onOpen: () => void;
    /** Shows the guide without connecting — the only way to read it for a service that
     *  connects by redirect, where clicking the card leaves the page immediately. */
    onExplain: () => void;
}) {
    const dead = provider.available === false;
    const inert = dead || provider.mode === 'none';

    return (
        <div className="relative h-full">
            {/* Kolečko zůstává dvacetibodové, ale sahá o osm bodů dál na všechny strany.
                Průhledný obdélník přes ::after zvětší jen plochu, na kterou se dá klepnout
                — kdyby se zvětšilo samo tlačítko, roztáhl by se viditelný otazník přes
                roh karty. */}
            {(provider.steps?.length || provider.docs_url) && (
                <button
                    type="button"
                    onClick={onExplain}
                    aria-label={`Jak připojit ${provider.name}`}
                    title="Jak to propojit"
                    className="absolute right-2 top-2 z-10 flex h-5 w-5 items-center justify-center rounded-full border border-[var(--color-border)] text-[10px] text-[var(--color-text-secondary)] after:absolute after:-inset-2 after:content-['']hover:border-[var(--color-accent)] hover:text-[var(--color-text-primary)]"
                >
                    ?
                </button>
            )}

        <button
            type="button"
            onClick={inert ? undefined : onOpen}
            disabled={inert}
            title={dead ? provider.help : undefined}
            className={`flex h-full w-full flex-col items-start gap-2 rounded-2xl border p-3 text-left transition-colors ${dead
                ? 'cursor-default border-[var(--color-border)] bg-[var(--color-bg-card)] opacity-45'
                : inert
                    ? 'cursor-default border-[var(--color-border)] bg-[var(--color-bg-card)]'
                    : 'border-[var(--color-border)] bg-[var(--color-bg-card)] hover:border-[var(--color-accent)] hover:bg-[var(--color-surface-hover)]'}`}
        >
            <ServiceLogo code={provider.code} brand={provider.brand} />

            <span className="w-full">
                <span className="block truncate text-sm font-medium text-[var(--color-text-primary)]">{provider.name}</span>
                <span className="mt-0.5 block text-[11px] leading-4 text-[var(--color-text-secondary)]">
                    {dead ? 'Napojit nelze' : provider.summary}
                </span>
            </span>

            <span className="mt-auto pt-1">
                {connected ? (
                    <span className="rounded-md bg-emerald-500/15 px-1.5 py-0.5 text-[10px] text-emerald-200">{connected}</span>
                ) : dead ? null : !provider.ready ? (
                    <span className="rounded-md bg-[var(--color-surface-muted)] px-1.5 py-0.5 text-[10px] text-[var(--color-text-secondary)]">
                        Nenastaveno
                    </span>
                ) : (
                    <span className="text-[10px] text-[var(--color-accent)]">Připojit</span>
                )}
            </span>
        </button>
        </div>
    );
}

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
    /** Keyed by provider code, so a new cloud needs no change here. */
    const [storage, setStorage] = useState<Record<string, Storage>>({});
    const [quota, setQuota] = useState<Quota | null>(null);
    const [backup, setBackup] = useState<{ total: number; backed_up: number; percent: number; failed: number; provider: string } | null>(null);
    /** Which service's form is open. One at a time: two token fields side by side invite pasting into the wrong one. */
    const [adding, setAdding] = useState<Provider | null>(null);
    /** Reading the guide without connecting. Separate from `adding` so opening the help for
     *  a redirect service does not put a token field on screen it has no use for. */
    const [explaining, setExplaining] = useState<Provider | null>(null);

    /**
     * What a card does when clicked, decided by the provider rather than by the card.
     *
     * A page navigates, a redirect leaves for the service, and everything else opens the
     * modal. The redirect is a full page load rather than a client-side visit, because the
     * destination is not ours to route.
     */
    const openProvider = (provider: Provider) => {
        if (provider.available === false) return;

        if (provider.mode === 'page' && provider.url) { router.visit(provider.url); return; }

        if (provider.mode === 'redirect') {
            if (!provider.ready) {
                setError(`${provider.name}: ${provider.help}`);

                return;
            }
            window.location.href = `/settings/propojeni/${provider.code}/start`;

            return;
        }

        setToken('');
        setVisibility(provider.scopes[0] ?? 'personal');
        setAdding(provider);
    };

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/propojeni');
            setConnections(response.data.connections ?? []);
            setCatalogue(response.data.catalogue ?? []);
            setStorage(response.data.storage ?? {});
            setQuota(response.data.quota ?? null);
            setBackup(response.data.backup ?? null);
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
            <main className="w-full p-4 sm:p-6">
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
                        {/* Storage first. It is the one connection the gallery cannot
                            work without, and burying it under the optional ones would say
                            otherwise. Four to a row: the cards carry a name, a mark and a
                            sentence, and a fifth would push the sentence to two lines on
                            every screen narrower than a desk. */}
                        <section className="mt-6">
                            <h2 className="font-semibold text-[var(--color-text-primary)]">Úložiště</h2>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                Fotky leží na našem serveru. Připojením vlastního cloudu je přesunete k sobě.
                            </p>

                            {/* Připojený účet a zkopírovaná knihovna jsou dvě různé věci.
                                Ta druhá je ta, na které záleží, a doteď ji nebylo kde vidět. */}
                            {backup && backup.total > 0 && (
                                <div className={`mt-3 rounded-2xl border p-4 ${
                                    backup.percent === 100 ? 'border-emerald-400/25 bg-emerald-500/5' : 'border-amber-400/25 bg-amber-500/5'
                                }`}>
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <p className="text-sm font-medium text-[var(--color-text-primary)]">
                                            {backup.percent === 100
                                                ? 'Všechny originály jsou v cloudu'
                                                : `V cloudu je ${backup.backed_up} z ${backup.total} originálů`}
                                        </p>
                                        <span className={`text-sm font-semibold ${backup.percent === 100 ? 'text-emerald-300' : 'text-amber-200'}`}>
                                            {backup.percent} %
                                        </span>
                                    </div>

                                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-[var(--color-bg-primary)]">
                                        <div className={`h-full ${backup.percent === 100 ? 'bg-emerald-400' : 'bg-amber-400'}`}
                                            style={{ width: `${backup.percent}%` }}/>
                                    </div>

                                    <p className="mt-2 text-xs text-[var(--color-text-secondary)]">
                                        {backup.percent === 100
                                            ? 'Kopie leží mimo tento server. Kdyby se mu něco stalo, fotky zůstanou.'
                                            : backup.provider === 'local'
                                                ? 'Zatím není připojený žádný cloud — kopie existuje jen na tomto serveru.'
                                                : 'Zbytek se zkopíruje na pozadí. Pokud se to nehne, spusťte na serveru gallery:sync-drive --all.'}
                                        {backup.failed > 0 && ` · ${backup.failed} položek hlásí chybu.`}
                                    </p>
                                </div>
                            )}

                            <div className="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                                {catalogue.filter(provider => provider.kind === 'storage').map(provider => (
                                    <ServiceCard
                                        key={provider.code}
                                        provider={provider}
                                        connected={provider.code === 'server'
                                            ? (quota?.limit_mb ? `${bytes(quota.used_bytes)} / ${bytes(quota.limit_bytes ?? 0)}` : 'Základní')
                                            : isConnected(storage[provider.code])
                                                ? (storage[provider.code].account ?? 'Připojeno')
                                                : null}
                                        onOpen={() => openProvider(provider)}
                                        onExplain={() => setExplaining(provider)}
                                    />
                                ))}
                            </div>

                            {/* How full the server disk is. The limit was already refusing
                                uploads, but nothing said so until a photograph bounced —
                                a quota nobody can see is a surprise rather than a rule. */}
                            {quota && (
                                <div className="mt-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <p className="text-xs text-[var(--color-text-primary)]">
                                            Zabráno {bytes(quota.used_bytes)}
                                            {quota.limit_bytes ? ` z ${bytes(quota.limit_bytes)}` : ' — bez limitu'}
                                        </p>
                                        {quota.percent !== null && (
                                            <p className={`text-[11px] ${quota.percent >= 90 ? 'text-red-300' : 'text-[var(--color-text-secondary)]'}`}>
                                                {quota.percent} %
                                            </p>
                                        )}
                                    </div>

                                    {quota.percent !== null && (
                                        <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                                            <div
                                                className={`h-full rounded-full ${quota.percent >= 90 ? 'bg-red-400' : 'bg-[var(--color-accent)]'}`}
                                                style={{ width: `${Math.max(2, quota.percent)}%` }}
                                            />
                                        </div>
                                    )}

                                    {quota.percent !== null && quota.percent >= 75 && (
                                        <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">
                                            Místo dochází.{' '}
                                            <a href="/settings/predplatne" className="text-[var(--color-accent)] hover:underline">
                                                Rozšířit kapacitu
                                            </a>{' '}
                                            nebo připojte vlastní cloud.
                                        </p>
                                    )}
                                </div>
                            )}

                            {/* Every connected cloud's last failure, not just Drive's. */}
                            {Object.entries(storage).filter(([, row]) => row.last_error).map(([code, row]) => (
                                <p key={code} className="mt-2 rounded-lg bg-red-500/10 p-2 text-[11px] text-red-200">
                                    {nameOf(catalogue, code)} — poslední chyba: {row.last_error}
                                </p>
                            ))}
                        </section>

                        <section className="mt-6">
                            <h2 className="font-semibold text-[var(--color-text-primary)]">Služby</h2>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                Každou lze připojit zvlášť jako osobní a zvlášť jako sdílenou — dva účty téže služby vedle sebe.
                            </p>

                            <div className="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                                {catalogue.filter(provider => provider.kind === 'service').map(provider => (
                                    <ServiceCard
                                        key={provider.code}
                                        provider={provider}
                                        connected={(() => {
                                            const mine = connections.filter(row => row.provider === provider.code);

                                            return mine.length > 0 ? `${mine.length}× připojeno` : null;
                                        })()}
                                        onOpen={() => openProvider(provider)}
                                        onExplain={() => setExplaining(provider)}
                                    />
                                ))}
                            </div>
                        </section>


                        {/* Dvě propojení vedle sebe. Karta „připojit službu" je pár řádků
                            textu a tlačítko — přes celou šířku z ní byl prázdný pruh. */}
                        <div className="mt-6 grid items-start gap-5 xl:grid-cols-2">
                        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
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

                        </section>

                        <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
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
                        </div>

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

                {/* The middle case: more than a sign-in, less than a screen of its own.
                    A modal rather than an expanding card, because the form needs the whole
                    width on a phone and an inline one pushes everything below it away. */}
                {adding && (
                    <div className="fixed inset-0 z-[760] flex items-end justify-center bg-black/60 sm:items-center sm:p-4">
                        <button type="button" aria-label="Zavřít" onClick={() => setAdding(null)} className="absolute inset-0 cursor-default" />

                        <section className="safe-area-pb relative w-full rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-4 sm:max-w-md sm:rounded-2xl">
                            <div className="flex items-start gap-3">
                                <ServiceLogo code={adding.code} brand={adding.brand} size={30} />
                                <div className="min-w-0 flex-1">
                                    <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Připojit {adding.name}</h2>
                                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{adding.help}</p>
                                    {adding.caveat && <p className="mt-1 text-[11px] text-amber-200">{adding.caveat}</p>}

                                    {/* Numbered, because these are done in order and in
                                        somebody else's interface — a paragraph would mean
                                        re-reading it to find where you got to. */}
                                    {adding.steps && adding.steps.length > 0 && (
                                        <ol className="mt-2 space-y-1 text-[11px] leading-4 text-[var(--color-text-secondary)]">
                                            {adding.steps.map((step, index) => (
                                                <li key={index} className="flex gap-1.5">
                                                    <span className="shrink-0 text-[var(--color-accent)]">{index + 1}.</span>
                                                    <span>{step}</span>
                                                </li>
                                            ))}
                                        </ol>
                                    )}

                                    {(adding.signup_url || adding.docs_url) && (
                                        <p className="mt-2 flex flex-wrap gap-3 text-[11px]">
                                            {adding.signup_url && (
                                                <a href={adding.signup_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[var(--color-accent)] hover:underline">
                                                    Otevřít {adding.name} <ExternalLink size={11} />
                                                </a>
                                            )}
                                            {adding.docs_url && (
                                                <a href={adding.docs_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[var(--color-text-secondary)] hover:underline">
                                                    Nápověda <ExternalLink size={11} />
                                                </a>
                                            )}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <input
                                type="password"
                                autoFocus
                                value={token}
                                onChange={event => setToken(event.target.value)}
                                placeholder={adding.auth === 'invite' ? 'Adresa webhooku' : 'Token'}
                                className="mt-4 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                            />

                            {adding.scopes.length > 1 && (
                                <select
                                    value={visibility}
                                    onChange={event => setVisibility(event.target.value as 'personal' | 'shared')}
                                    className="mt-2 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                                >
                                    <option value="personal">Osobní — vidím jen já</option>
                                    <option value="shared">Sdílené — pro celý prostor</option>
                                </select>
                            )}

                            <div className="mt-4 flex gap-2">
                                <button
                                    type="button"
                                    disabled={busy === adding.code || !token}
                                    onClick={async () => {
                                        const provider = adding;
                                        setBusy(provider.code); setError('');
                                        try {
                                            // Notion has its own call: it verifies the token,
                                            // says how many pages it found, and explains the
                                            // commonest surprise — an integration nobody has
                                            // shared a page with can see nothing at all.
                                            if (provider.code === 'notion') {
                                                setAdding(null);
                                                await connectNotion();

                                                return;
                                            }

                                            await axios.post(`/api/v1/propojeni/token/${provider.code}`, { token, visibility });
                                            setToken(''); setAdding(null);
                                            setNotice(`${provider.name} připojeno.`);
                                            await load();
                                        } catch (reason: any) {
                                            setError(reason?.response?.data?.message ?? 'Připojit se nepodařilo.');
                                        } finally { setBusy(null); }
                                    }}
                                    className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--color-accent)] text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50"
                                >
                                    {busy === adding.code && <Loader2 size={14} className="animate-spin" />} Připojit
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setAdding(null)}
                                    className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]"
                                >
                                    Zrušit
                                </button>
                            </div>
                        </section>
                    </div>
                )}
                {/* The guide on its own. Same shape as the connect sheet so the two read as
                    one thing seen twice, but with no field: this is for services that
                    connect by leaving the page, where there is nothing to type here. */}
                {explaining && (
                    <div className="fixed inset-0 z-[760] flex items-end justify-center bg-black/60 sm:items-center sm:p-4">
                        <button type="button" aria-label="Zavřít" onClick={() => setExplaining(null)} className="absolute inset-0 cursor-default" />

                        <section className="safe-area-pb relative w-full rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-4 sm:max-w-md sm:rounded-2xl">
                            <div className="flex items-start gap-3">
                                <ServiceLogo code={explaining.code} brand={explaining.brand} size={30} />
                                <div className="min-w-0 flex-1">
                                    <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Jak propojit {explaining.name}</h2>
                                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{explaining.help}</p>
                                    {explaining.caveat && <p className="mt-1 text-[11px] text-amber-200">{explaining.caveat}</p>}
                                </div>
                            </div>

                            {explaining.steps && explaining.steps.length > 0 && (
                                <ol className="mt-3 space-y-1.5 text-xs leading-5 text-[var(--color-text-secondary)]">
                                    {explaining.steps.map((step, index) => (
                                        <li key={index} className="flex gap-2">
                                            <span className="shrink-0 font-medium text-[var(--color-accent)]">{index + 1}.</span>
                                            <span>{step}</span>
                                        </li>
                                    ))}
                                </ol>
                            )}

                            <div className="mt-4 flex flex-wrap items-center gap-3 text-xs">
                                {explaining.signup_url && (
                                    <a href={explaining.signup_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[var(--color-accent)] hover:underline">
                                        Otevřít {explaining.name} <ExternalLink size={12} />
                                    </a>
                                )}
                                {explaining.docs_url && (
                                    <a href={explaining.docs_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[var(--color-text-secondary)] hover:underline">
                                        Oficiální nápověda <ExternalLink size={12} />
                                    </a>
                                )}
                                <button type="button" onClick={() => setExplaining(null)} className="ml-auto min-h-10 rounded-xl border border-[var(--color-border)] px-4 text-[var(--color-text-primary)]">
                                    Zavřít
                                </button>
                            </div>
                        </section>
                    </div>
                )}
            </main>
        </AppLayout>
    );
}
