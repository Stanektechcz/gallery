import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { CircleDollarSign, Database, HardDrive, KeyRound, ShieldCheck, Users } from 'lucide-react';

type Props = {
    stats: {
        users: number;
        media_total: number;
        photos: number;
        videos: number;
        ready: number;
        failed: number;
        trashed: number;
        albums: number;
    };
    connection?: {
        account_email?: string | null;
        connection_status?: string | null;
        quota_total?: number | null;
        quota_used?: number | null;
    } | null;
    queue: { pending: number; failed: number };
};

const number = (value: number) => new Intl.NumberFormat('cs-CZ').format(value ?? 0);

export default function Dashboard({ stats, connection, queue }: Props) {
    return (
        <AppLayout>
            <Head title="Administrace"/>
            <main className="mx-auto max-w-6xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Správa systému</p>
                <h1 className="mt-1 text-2xl font-bold text-white sm:text-3xl">Administrace</h1>
                <p className="mt-2 max-w-3xl text-sm text-[var(--color-text-secondary)]">Centrální nastavení datových zdrojů, úložiště a provozního stavu aplikace.</p>

                <section className="mt-6 grid gap-4 md:grid-cols-2">
                    <Link href="/admin/integrations" className="group rounded-2xl border border-[var(--color-accent)]/30 bg-[var(--color-accent)]/5 p-5 transition hover:bg-[var(--color-accent)]/10">
                        <div className="flex items-start gap-3">
                            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--color-accent)]/15 text-[var(--color-accent)]"><KeyRound size={21}/></span>
                            <div><h2 className="font-semibold text-white">Integrace a API klíče</h2><p className="mt-1 text-sm leading-relaxed text-[var(--color-text-secondary)]">GoCardless/Revolut, TMDB, Cinema City, mapy, trasy, počasí a měnové kurzy.</p><span className="mt-3 inline-block text-xs font-medium text-[var(--color-accent)]">Otevřít konfiguraci →</span></div>
                        </div>
                    </Link>
                    <Link href="/settings/storage/google" className="group rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5 transition hover:bg-white/5">
                        <div className="flex items-start gap-3">
                            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-blue-500/10 text-blue-200"><HardDrive size={21}/></span>
                            <div><h2 className="font-semibold text-white">Google Drive</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">{connection?.account_email ?? 'Úložiště zatím není připojeno'} · {connection?.connection_status ?? 'bez připojení'}</p><span className="mt-3 inline-block text-xs font-medium text-[var(--color-accent)]">Spravovat úložiště →</span></div>
                        </div>
                    </Link>
                    <Link href="/finances#connection" className="group rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5 transition hover:bg-white/5">
                        <div className="flex items-start gap-3">
                            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-500/10 text-emerald-200"><CircleDollarSign size={21}/></span>
                            <div><h2 className="font-semibold text-white">Finance a Revolut</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Připojené účty, importy, synchronizace a přehled transakcí.</p><span className="mt-3 inline-block text-xs font-medium text-[var(--color-accent)]">Otevřít finance →</span></div>
                        </div>
                    </Link>
                    <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5">
                        <div className="flex items-start gap-3"><span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-violet-500/10 text-violet-200"><ShieldCheck size={21}/></span><div><h2 className="font-semibold text-white">Fronta zpracování</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Čeká: {number(queue.pending)} · selhalo: {number(queue.failed)}</p><p className="mt-3 text-xs text-[var(--color-text-secondary)]">Fotografie, videa, metadata, náhledy a synchronizace integrací.</p></div></div>
                    </div>
                </section>

                <section className="mt-7">
                    <h2 className="text-lg font-semibold text-white">Stav obsahu</h2>
                    <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        {[
                            ['Uživatelé', stats.users, Users],
                            ['Média', stats.media_total, Database],
                            ['Fotografie', stats.photos, Database],
                            ['Videa', stats.videos, Database],
                            ['Alba', stats.albums, HardDrive],
                            ['Chyby', stats.failed, ShieldCheck],
                        ].map(([label, value, Icon]: any) => (
                            <div key={label} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                                <Icon size={15} className="text-[var(--color-accent)]"/>
                                <p className="mt-3 text-xl font-semibold text-white">{number(value)}</p>
                                <p className="text-xs text-[var(--color-text-secondary)]">{label}</p>
                            </div>
                        ))}
                    </div>
                </section>
            </main>
        </AppLayout>
    );
}
