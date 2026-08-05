import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Activity, CircleCheck, CircleX } from 'lucide-react';

/** Props mirror AdminController::health(). */
type Props = {
    checks: {
        laravel: { app_key: boolean; app_debug: boolean; app_url: boolean };
        database: { connected: boolean };
        storage: { writable: boolean; free_gb: number };
        binaries: { ffmpeg: boolean; exiftool: boolean };
        queue: { pending: number; failed: number };
    };
};

function Flag({ label, ok, hint }: { label: string; ok: boolean; hint?: string }) {
    return (
        <div className="flex items-start gap-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
            <span className={`mt-0.5 shrink-0 ${ok ? 'text-emerald-300' : 'text-red-300'}`}>
                {ok ? <CircleCheck size={17} /> : <CircleX size={17} />}
            </span>
            <div className="min-w-0">
                <p className="text-sm font-medium text-[var(--color-text-primary)]">{label}</p>
                <p className={`text-xs ${ok ? 'text-emerald-300' : 'text-red-300'}`}>{ok ? 'V pořádku' : 'Vyžaduje pozornost'}</p>
                {hint && <p className="mt-1 text-[10px] leading-relaxed text-[var(--color-text-secondary)]">{hint}</p>}
            </div>
        </div>
    );
}

function Metric({ label, value, tone = 'neutral' }: { label: string; value: string; tone?: 'neutral' | 'warn' }) {
    return (
        <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
            <p className={`text-xl font-semibold ${tone === 'warn' ? 'text-amber-300' : 'text-[var(--color-text-primary)]'}`}>{value}</p>
            <p className="text-xs text-[var(--color-text-secondary)]">{label}</p>
        </div>
    );
}

export default function AdminHealth({ checks }: Props) {
    return (
        <AppLayout>
            <Head title="Stav systému" />
            <main className="mx-auto max-w-6xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Správa systému</p>
                <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">
                    <Activity size={24} className="text-[var(--color-accent)]" /> Stav systému
                </h1>
                <p className="mt-2 max-w-3xl text-sm text-[var(--color-text-secondary)]">
                    Kontrola konfigurace, databáze, úložiště a nástrojů pro zpracování médií.
                </p>

                <section className="mt-6">
                    <h2 className="mb-3 font-semibold text-[var(--color-text-primary)]">Konfigurace</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Flag label="Aplikační klíč" ok={checks.laravel.app_key} hint="APP_KEY musí být nastavený, jinak nelze dešifrovat session a cookies." />
                        <Flag label="Debug vypnutý" ok={checks.laravel.app_debug} hint="Na produkci musí být APP_DEBUG=false, jinak se návštěvníkům zobrazí interní chyby." />
                        <Flag label="Adresa aplikace" ok={checks.laravel.app_url} hint="APP_URL se používá pro odkazy v e-mailech a pozvánkách." />
                    </div>
                </section>

                <section className="mt-7">
                    <h2 className="mb-3 font-semibold text-[var(--color-text-primary)]">Databáze a úložiště</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Flag label="Databázové spojení" ok={checks.database.connected} />
                        <Flag label="Zapisovatelné úložiště" ok={checks.storage.writable} hint="storage/app musí být zapisovatelné pro uploady a náhledy." />
                        <Metric
                            label="Volné místo na disku"
                            value={`${checks.storage.free_gb} GB`}
                            tone={checks.storage.free_gb < 5 ? 'warn' : 'neutral'}
                        />
                    </div>
                </section>

                <section className="mt-7">
                    <h2 className="mb-3 font-semibold text-[var(--color-text-primary)]">Nástroje pro média</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Flag label="ffmpeg" ok={checks.binaries.ffmpeg} hint="Bez ffmpeg nelze generovat náhledy videí ani je převádět." />
                        <Flag label="exiftool" ok={checks.binaries.exiftool} hint="Bez exiftool se nenačtou EXIF metadata (datum, GPS, fotoaparát)." />
                    </div>
                </section>

                <section className="mt-7">
                    <h2 className="mb-3 font-semibold text-[var(--color-text-primary)]">Fronta</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Metric label="Čekající úlohy" value={String(checks.queue.pending)} />
                        <Metric label="Selhané úlohy" value={String(checks.queue.failed)} tone={checks.queue.failed > 0 ? 'warn' : 'neutral'} />
                    </div>
                </section>
            </main>
        </AppLayout>
    );
}
