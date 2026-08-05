import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';

/** Props mirror AdminController::audit() — a Laravel paginator of AuditLog with its user. */
interface AuditEntry {
    id: number;
    action: string;
    subject_type?: string | null;
    subject_id?: number | null;
    payload?: Record<string, unknown> | null;
    ip_address?: string | null;
    created_at: string;
    user?: { id: number; name: string } | null;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

type Props = { logs: Paginator<AuditEntry> };

export default function AdminAudit({ logs }: Props) {
    return (
        <AppLayout>
            <Head title="Audit log" />
            <main className="mx-auto max-w-6xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Správa systému</p>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">Audit log</h1>
                <p className="mt-2 max-w-3xl text-sm text-[var(--color-text-secondary)]">
                    Záznam bezpečnostně významných akcí. Celkem {logs.total} záznamů.
                </p>

                <div className="mt-6 overflow-x-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]">
                    <table className="w-full min-w-[720px] text-left text-sm">
                        <thead className="border-b border-[var(--color-border)] text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">
                            <tr>
                                <th className="px-4 py-3 font-medium">Akce</th>
                                <th className="px-4 py-3 font-medium">Uživatel</th>
                                <th className="px-4 py-3 font-medium">Předmět</th>
                                <th className="px-4 py-3 font-medium">IP</th>
                                <th className="px-4 py-3 font-medium">Kdy</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map(entry => (
                                <tr key={entry.id} className="border-b border-[var(--color-border)] last:border-0">
                                    <td className="px-4 py-3">
                                        <span className="inline-flex items-center gap-1.5 font-mono text-xs text-[var(--color-text-primary)]">
                                            <ScrollText size={12} className="text-[var(--color-accent)]" />{entry.action}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">{entry.user?.name ?? 'systém'}</td>
                                    <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">
                                        {entry.subject_type ? `${entry.subject_type}#${entry.subject_id ?? '?'}` : '—'}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-[10px] text-[var(--color-text-secondary)]">{entry.ip_address ?? '—'}</td>
                                    <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">
                                        {new Date(entry.created_at).toLocaleString('cs-CZ')}
                                    </td>
                                </tr>
                            ))}
                            {!logs.data.length && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-8 text-center text-sm text-[var(--color-text-secondary)]">
                                        Zatím tu není žádný záznam.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {logs.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between">
                        {logs.prev_page_url
                            ? <Link href={logs.prev_page_url} className="rounded-lg border border-[var(--color-border)] px-4 py-2 text-xs text-[var(--color-text-primary)] hover:bg-[var(--color-surface-hover)]">← Novější</Link>
                            : <span />}
                        <span className="text-xs text-[var(--color-text-secondary)]">Strana {logs.current_page} z {logs.last_page}</span>
                        {logs.next_page_url
                            ? <Link href={logs.next_page_url} className="rounded-lg border border-[var(--color-border)] px-4 py-2 text-xs text-[var(--color-text-primary)] hover:bg-[var(--color-surface-hover)]">Starší →</Link>
                            : <span />}
                    </div>
                )}
            </main>
        </AppLayout>
    );
}
