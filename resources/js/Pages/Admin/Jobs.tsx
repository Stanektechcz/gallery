import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Clock, TriangleAlert } from 'lucide-react';

/** Props mirror AdminController::jobs() — raw rows from the jobs / failed_jobs tables. */
interface PendingJob {
    id: number;
    queue: string;
    payload: string;
    attempts: number;
    reserved_at?: number | null;
    available_at: number;
    created_at: number;
}

interface FailedJob {
    id: number;
    uuid: string;
    connection: string;
    queue: string;
    payload: string;
    exception: string;
    failed_at: string;
}

type Props = { pending: PendingJob[]; failed: FailedJob[] };

/** Queue payloads are JSON blobs; the display name is the only useful part here. */
function jobName(payload: string): string {
    try {
        const parsed = JSON.parse(payload);
        return parsed?.displayName ?? parsed?.job ?? 'Neznámá úloha';
    } catch {
        return 'Neznámá úloha';
    }
}

const fromUnix = (value?: number | null) =>
    value ? new Date(value * 1000).toLocaleString('cs-CZ') : '—';

const firstLine = (text: string) => (text ?? '').split('\n')[0].slice(0, 200);

export default function AdminJobs({ pending, failed }: Props) {
    return (
        <AppLayout>
            <Head title="Fronta úloh" />
            <main className="mx-auto max-w-6xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Správa systému</p>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">Fronta úloh</h1>
                <p className="mt-2 max-w-3xl text-sm text-[var(--color-text-secondary)]">
                    Zpracování fotek, videí, náhledů a synchronizací. Zobrazeno je posledních 50 záznamů z každé fronty.
                </p>

                <section className="mt-6">
                    <div className="mb-3 flex items-center gap-2">
                        <Clock size={17} className="text-[var(--color-accent)]" />
                        <h2 className="font-semibold text-[var(--color-text-primary)]">Čekající ({pending.length})</h2>
                    </div>
                    <div className="overflow-x-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]">
                        <table className="w-full min-w-[560px] text-left text-sm">
                            <thead className="border-b border-[var(--color-border)] text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Úloha</th>
                                    <th className="px-4 py-3 font-medium">Fronta</th>
                                    <th className="px-4 py-3 font-medium">Pokusů</th>
                                    <th className="px-4 py-3 font-medium">Zařazeno</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pending.map(job => (
                                    <tr key={job.id} className="border-b border-[var(--color-border)] last:border-0">
                                        <td className="px-4 py-3 text-[var(--color-text-primary)]">{jobName(job.payload)}</td>
                                        <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">{job.queue}</td>
                                        <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">{job.attempts}</td>
                                        <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">{fromUnix(job.created_at)}</td>
                                    </tr>
                                ))}
                                {!pending.length && (
                                    <tr>
                                        <td colSpan={4} className="px-4 py-8 text-center text-sm text-emerald-300">
                                            Fronta je prázdná, vše je zpracované.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="mt-7">
                    <div className="mb-3 flex items-center gap-2">
                        <TriangleAlert size={17} className="text-amber-300" />
                        <h2 className="font-semibold text-[var(--color-text-primary)]">Selhané ({failed.length})</h2>
                    </div>
                    <div className="space-y-2">
                        {failed.map(job => (
                            <div key={job.id} className="rounded-xl border border-red-400/20 bg-red-500/5 p-3">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-medium text-[var(--color-text-primary)]">{jobName(job.payload)}</p>
                                    <p className="text-xs text-[var(--color-text-secondary)]">
                                        {job.queue} · {new Date(job.failed_at).toLocaleString('cs-CZ')}
                                    </p>
                                </div>
                                <p className="mt-2 break-words font-mono text-[10px] leading-relaxed text-red-200">
                                    {firstLine(job.exception)}
                                </p>
                            </div>
                        ))}
                        {!failed.length && (
                            <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-8 text-center text-sm text-emerald-300">
                                Žádná úloha neselhala.
                            </div>
                        )}
                    </div>
                </section>
            </main>
        </AppLayout>
    );
}
