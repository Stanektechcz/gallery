import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    CheckCircle,
    ExternalLink,
    HardDrive,
    RefreshCw,
    TestTube2,
    Unplug,
    XCircle
} from 'lucide-react';
import { useState } from 'react';

interface Connection {
    status: string;
    account_email: string | null;
    root_folder: string | null;
    quota_total: number | null;
    quota_used: number | null;
    connected_at: string | null;
    last_ok: string | null;
    last_error: string | null;
}

interface Props {
    connection: Connection | null;
    client_configured: boolean;
}

const statusInfo: Record<string, { color: string; label: string; icon: any }> = {
    healthy:          { color: 'text-green-400', label: 'Připojen',        icon: CheckCircle },
    disconnected:     { color: 'text-gray-400',  label: 'Odpojen',         icon: Unplug },
    refresh_required: { color: 'text-yellow-400',label: 'Nutná reautorizace', icon: AlertCircle },
    admin_blocked:    { color: 'text-red-400',   label: 'Zablokován administrátorem', icon: XCircle },
    revoked:          { color: 'text-red-400',   label: 'Revokován',       icon: XCircle },
    error:            { color: 'text-red-400',   label: 'Chyba',           icon: XCircle },
    rate_limited:     { color: 'text-yellow-400',label: 'Rate limit',      icon: AlertCircle },
};

function formatBytes(bytes: number | null): string {
    if (!bytes) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let b = bytes;
    while (b >= 1024 && i < units.length - 1) { b /= 1024; i++; }
    return `${b.toFixed(1)} ${units[i]}`;
}

export default function GoogleStorageSettings({ connection, client_configured }: Props) {
    const [testing, setTesting] = useState(false);
    const [testResults, setTestResults] = useState<Record<string, any> | null>(null);
    const [disconnecting, setDisconnecting] = useState(false);
    const [syncing, setSyncing] = useState(false);

    const status = connection?.status ?? 'disconnected';
    const info   = statusInfo[status] ?? statusInfo.disconnected;
    const StatusIcon = info.icon;

    async function runTest() {
        setTesting(true);
        setTestResults(null);
        try {
            const res = await axios.post('/settings/storage/google/test');
            setTestResults(res.data.tests);
        } catch {
            setTestResults({ error: { pass: false, detail: 'Test request failed' } });
        } finally {
            setTesting(false);
        }
    }

    function connectDrive() {
        window.location.href = '/oauth/google/redirect';
    }

    function forceReconnect() {
        window.location.href = '/oauth/google/redirect?force=1';
    }

    function syncExisting() {
        setSyncing(true);
        router.post('/settings/storage/google/sync-existing', {}, { onFinish: () => setSyncing(false) });
    }

    return (
        <AppLayout>
            <Head title="Google Drive — Nastavení" />

            <div className="max-w-2xl mx-auto px-4 py-8">
                <h1 className="text-xl font-semibold text-white mb-6 flex items-center gap-3">
                    <HardDrive size={22} className="text-[var(--color-accent)]" />
                    Google Drive úložiště
                </h1>

                {!client_configured && (
                    <div className="bg-yellow-500/10 border border-yellow-500/30 rounded-xl p-4 mb-6">
                        <p className="text-yellow-400 text-sm font-medium mb-1">OAuth není nakonfigurován</p>
                        <p className="text-yellow-300/70 text-xs">
                            Nastavte GOOGLE_DRIVE_CLIENT_ID a GOOGLE_DRIVE_CLIENT_SECRET v .env souboru.
                        </p>
                    </div>
                )}

                {/* Status card */}
                <div className="glass rounded-2xl p-6 mb-6">
                    <div className="flex items-center gap-3 mb-4">
                        <StatusIcon size={20} className={info.color} />
                        <div>
                            <p className="text-sm font-medium text-white">{info.label}</p>
                            {connection?.account_email && (
                                <p className="text-xs text-[var(--color-text-secondary)]">{connection.account_email}</p>
                            )}
                        </div>
                    </div>

                    {connection && (
                        <div className="grid grid-cols-2 gap-3 text-xs text-[var(--color-text-secondary)] mb-4">
                            <div>
                                <p className="mb-0.5">Root složka</p>
                                <p className="text-white">{connection.root_folder ?? '—'}</p>
                            </div>
                            <div>
                                <p className="mb-0.5">Kvóta</p>
                                <p className="text-white">
                                    {formatBytes(connection.quota_used)} / {formatBytes(connection.quota_total)}
                                </p>
                            </div>
                            <div>
                                <p className="mb-0.5">Poslední OK</p>
                                <p className="text-white">
                                    {connection.last_ok ? new Date(connection.last_ok).toLocaleString('cs-CZ') : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="mb-0.5">Připojeno</p>
                                <p className="text-white">
                                    {connection.connected_at ? new Date(connection.connected_at).toLocaleString('cs-CZ') : '—'}
                                </p>
                            </div>
                        </div>
                    )}

                    {connection?.last_error && (
                        <div className="bg-red-500/10 border border-red-500/20 rounded-lg p-3 mb-4 text-xs text-red-300">
                            {connection.last_error}
                        </div>
                    )}

                    {/* Actions */}
                    <div className="flex flex-wrap gap-2">
                        {status === 'disconnected' || !connection ? (
                            <button
                                onClick={connectDrive}
                                disabled={!client_configured}
                                className="bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)] disabled:opacity-50 text-white text-sm px-4 py-2 rounded-lg transition-colors flex items-center gap-2"
                            >
                                <ExternalLink size={14} />
                                Připojit Google Drive
                            </button>
                        ) : (
                            <>
                                <button
                                    onClick={runTest}
                                    disabled={testing}
                                    className="bg-white/10 hover:bg-white/15 text-white text-sm px-3 py-2 rounded-lg transition-colors flex items-center gap-2"
                                >
                                    <TestTube2 size={14} className={testing ? 'animate-spin' : ''} />
                                    {testing ? 'Testuji…' : 'Otestovat'}
                                </button>
                                <button
                                    onClick={syncExisting}
                                    disabled={syncing || status !== 'healthy'}
                                    className="bg-[var(--color-accent)]/15 hover:bg-[var(--color-accent)]/25 disabled:opacity-50 text-white text-sm px-3 py-2 rounded-lg transition-colors flex items-center gap-2"
                                >
                                    <RefreshCw size={14} className={syncing ? 'animate-spin' : ''} />
                                    {syncing ? 'Zařazuji…' : 'Synchronizovat média'}
                                </button>
                                <button
                                    onClick={() => router.post('/settings/storage/google/reconnect')}
                                    className="bg-white/10 hover:bg-white/15 text-white text-sm px-3 py-2 rounded-lg transition-colors flex items-center gap-2"
                                >
                                    <RefreshCw size={14} />
                                    Obnovit token
                                </button>
                                <button
                                    onClick={forceReconnect}
                                    className="bg-white/10 hover:bg-white/15 text-white text-sm px-3 py-2 rounded-lg transition-colors"
                                >
                                    Reautorizovat
                                </button>
                                <button
                                    onClick={() => {
                                        if (confirm('Opravdu chcete odpojit Google Drive?')) {
                                            router.post('/settings/storage/google/disconnect');
                                        }
                                    }}
                                    className="bg-red-500/10 hover:bg-red-500/20 text-red-400 text-sm px-3 py-2 rounded-lg transition-colors flex items-center gap-2"
                                >
                                    <Unplug size={14} />
                                    Odpojit
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {/* Test results */}
                {testResults && (
                    <div className="glass rounded-2xl p-5 mb-6">
                        <h3 className="text-sm font-medium text-white mb-3">Výsledky testu</h3>
                        <div className="space-y-2">
                            {Object.entries(testResults).map(([key, result]: [string, any]) => (
                                <div key={key} className="flex items-center gap-2 text-xs">
                                    {result.pass ? (
                                        <CheckCircle size={14} className="text-green-400 shrink-0" />
                                    ) : (
                                        <XCircle size={14} className="text-red-400 shrink-0" />
                                    )}
                                    <span className={result.pass ? 'text-white' : 'text-red-300'}>
                                        {key.replace(/_/g, ' ')}
                                    </span>
                                    {result.detail && (
                                        <span className="text-[var(--color-text-secondary)] ml-auto truncate max-w-32">
                                            {result.detail}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Info */}
                <div className="glass rounded-2xl p-5 text-xs text-[var(--color-text-secondary)] space-y-2">
                    <p className="font-medium text-white mb-2">Jak to funguje</p>
                    <p>Originál se po nahrání bezpečně zálohuje na Google Drive po částech; tlačítko „Synchronizovat média“ doplní i starší soubory.</p>
                    <p>Náhledy, přehrávací kopie videí a lokální cache originálů zůstávají na serveru pro rychlé načítání galerie a plynulé přetáčení.</p>
                    <p>Google Drive je dlouhodobá záloha originálů, lokální kopie není druhá neprovedená synchronizace.</p>
                    <p className="text-yellow-400 mt-3">⚠ Google Drive patří školnímu účtu — počítejte s tím, že přístup může být zrušen po ukončení studia.</p>
                </div>
            </div>
        </AppLayout>
    );
}
