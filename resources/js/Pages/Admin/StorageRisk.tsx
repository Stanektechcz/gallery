import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { HardDrive, ShieldCheck, TriangleAlert, Upload } from 'lucide-react';

/** Props mirror StorageRiskController::index(). */
type Props = {
    connection: {
        status?: string | null;
        account_email?: string | null;
        quota_total?: number | null;
        quota_used?: number | null;
        last_ok?: string | null;
        connected_at?: string | null;
        last_error?: string | null;
    } | null;
    originals_count: number;
    originals_size: number;
    pending_uploads: number;
    failed_uploads: number;
    last_integrity: string | null;
    last_backup: string | null;
};

const number = (value: number) => new Intl.NumberFormat('cs-CZ').format(value ?? 0);

function bytes(value?: number | null): string {
    if (!value || value < 1) return '0 B';
    if (value >= 1024 ** 4) return `${(value / 1024 ** 4).toFixed(2)} TB`;
    if (value >= 1024 ** 3) return `${(value / 1024 ** 3).toFixed(1)} GB`;
    if (value >= 1024 ** 2) return `${(value / 1024 ** 2).toFixed(1)} MB`;
    return `${Math.ceil(value / 1024)} kB`;
}

const when = (value?: string | null) =>
    value ? new Date(value).toLocaleString('cs-CZ') : 'nikdy';

function Metric({ label, value, tone = 'neutral' }: { label: string; value: string; tone?: 'neutral' | 'warn' | 'good' }) {
    const colour = tone === 'warn' ? 'text-amber-300' : tone === 'good' ? 'text-emerald-300' : 'text-[var(--color-text-primary)]';
    return (
        <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
            <p className={`text-xl font-semibold ${colour}`}>{value}</p>
            <p className="text-xs text-[var(--color-text-secondary)]">{label}</p>
        </div>
    );
}

export default function AdminStorageRisk({
    connection, originals_count, originals_size, pending_uploads, failed_uploads, last_integrity, last_backup,
}: Props) {
    const quotaUsed = connection?.quota_used ?? 0;
    const quotaTotal = connection?.quota_total ?? 0;
    const quotaPercent = quotaTotal > 0 ? Math.min(100, Math.round((quotaUsed / quotaTotal) * 100)) : null;

    return (
        <AppLayout>
            <Head title="Rizika úložiště" />
            <main className="w-full p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Správa systému</p>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">Rizika úložiště</h1>
                <p className="mt-2 max-w-3xl text-sm text-[var(--color-text-secondary)]">
                    Originály fotek leží na Google Drive. Tahle stránka ukazuje, jestli je spojení zdravé a zda existují
                    aktuální zálohy metadat.
                </p>

                {!connection && (
                    <p className="mt-5 flex items-start gap-2 rounded-xl border border-amber-400/25 bg-amber-500/10 p-4 text-sm text-amber-100">
                        <TriangleAlert size={17} className="mt-0.5 shrink-0" />
                        <span>
                            Google Drive není připojený, originály se tedy nikam nenahrávají.{' '}
                            <Link href="/settings/storage/google" className="underline">Připojit úložiště</Link>
                        </span>
                    </p>
                )}

                {connection && (
                    <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5">
                        <div className="flex items-start gap-3">
                            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-blue-500/10 text-blue-200">
                                <HardDrive size={21} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <h2 className="font-semibold text-[var(--color-text-primary)]">Google Drive</h2>
                                <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                                    {connection.account_email ?? 'neznámý účet'} · stav: {connection.status ?? 'neznámý'}
                                </p>
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                    Připojeno {when(connection.connected_at)} · poslední úspěšný požadavek {when(connection.last_ok)}
                                </p>

                                {quotaPercent !== null && (
                                    <div className="mt-3">
                                        <div className="flex justify-between text-xs text-[var(--color-text-secondary)]">
                                            <span>{bytes(quotaUsed)} z {bytes(quotaTotal)}</span>
                                            <span>{quotaPercent} %</span>
                                        </div>
                                        <div className="mt-1 h-2 overflow-hidden rounded-full bg-[var(--color-surface-muted)]">
                                            <div
                                                className={`h-full rounded-full ${quotaPercent > 90 ? 'bg-red-400' : quotaPercent > 75 ? 'bg-amber-400' : 'bg-emerald-400'}`}
                                                style={{ width: `${quotaPercent}%` }}
                                            />
                                        </div>
                                    </div>
                                )}

                                {connection.last_error && (
                                    <p className="mt-3 rounded-lg border border-red-400/20 bg-red-500/10 p-2 text-xs text-red-100">
                                        Poslední chyba: {connection.last_error}
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                <section className="mt-7">
                    <h2 className="mb-3 font-semibold text-[var(--color-text-primary)]">Originály a uploady</h2>
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Metric label="Originálů na Drive" value={number(originals_count)} />
                        <Metric label="Objem originálů" value={bytes(originals_size)} />
                        <Metric label="Probíhající uploady" value={number(pending_uploads)} />
                        <Metric label="Selhané uploady" value={number(failed_uploads)} tone={failed_uploads > 0 ? 'warn' : 'good'} />
                    </div>
                    {failed_uploads > 0 && (
                        <p className="mt-3 flex items-center gap-2 text-xs text-amber-200">
                            <Upload size={13} /> Selhané uploady znamenají, že originál nemusí být nikde uložený. Zkontrolujte je.
                        </p>
                    )}
                </section>

                <section className="mt-7">
                    <h2 className="mb-3 font-semibold text-[var(--color-text-primary)]">Kontroly a zálohy</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <p className="flex items-center gap-2 text-sm font-medium text-[var(--color-text-primary)]">
                                <ShieldCheck size={15} className="text-[var(--color-accent)]" /> Kontrola integrity Drive
                            </p>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Naposledy: {when(last_integrity)}</p>
                        </div>
                        <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <p className="flex items-center gap-2 text-sm font-medium text-[var(--color-text-primary)]">
                                <ShieldCheck size={15} className="text-[var(--color-accent)]" /> Záloha metadat
                            </p>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Naposledy: {when(last_backup)}</p>
                        </div>
                    </div>
                </section>
            </main>
        </AppLayout>
    );
}
