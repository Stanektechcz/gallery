import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { AlertTriangle, CheckCircle, RefreshCw, XCircle } from 'lucide-react';

interface Check { label: string; ok: boolean; detail?: string }
interface Props {
    checks: Check[];
    media_stats: { total: number; with_drive: number; missing_local: number; no_thumb: number };
    drive_info: { email: string; status: string; quota_total?: number; quota_used?: number; last_ok?: string } | null;
}

function fmt(b?: number): string {
    if (!b) return '—';
    if (b >= 1e12) return (b/1e12).toFixed(1) + ' TB';
    if (b >= 1e9)  return (b/1e9).toFixed(1)  + ' GB';
    return (b/1e6).toFixed(0) + ' MB';
}

export default function RecoveryIndex({ checks, media_stats, drive_info }: Props) {
    const allOk = checks.every(c => c.ok);

    return (
        <AppLayout>
            <Head title="Recovery Center" />
            <div className="p-4 max-w-2xl mx-auto pb-8">
                <div className="flex items-center gap-3 mb-6">
                    <div className={`w-9 h-9 rounded-lg flex items-center justify-center ${allOk ? 'bg-green-500/20' : 'bg-yellow-500/20'}`}>
                        {allOk ? <CheckCircle size={18} className="text-green-400" /> : <AlertTriangle size={18} className="text-yellow-400" />}
                    </div>
                    <div>
                        <h1 className="text-lg font-semibold text-white">Recovery Center</h1>
                        <p className="text-xs text-[var(--color-text-secondary)]">{allOk ? 'Vše v pořádku' : 'Vyžaduje pozornost'}</p>
                    </div>
                </div>

                {/* System checks */}
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 mb-4">
                    <h2 className="text-sm font-semibold text-white mb-3">Stav systému</h2>
                    <div className="space-y-2">
                        {checks.map(c => (
                            <div key={c.label} className="flex items-center gap-3">
                                {c.ok
                                    ? <CheckCircle size={14} className="text-green-400 shrink-0" />
                                    : <XCircle size={14} className="text-red-400 shrink-0" />}
                                <span className="text-sm text-white flex-1">{c.label}</span>
                                <span className="text-xs text-[var(--color-text-secondary)] truncate max-w-[40%]">{c.detail}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Drive info */}
                {drive_info && (
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 mb-4">
                        <h2 className="text-sm font-semibold text-white mb-3">Google Drive</h2>
                        <div className="space-y-1.5 text-xs">
                            <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Účet</span><span className="text-white">{drive_info.email}</span></div>
                            <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Stav</span>
                                <span className={drive_info.status === 'healthy' ? 'text-green-400' : 'text-yellow-400'}>{drive_info.status}</span>
                            </div>
                            {drive_info.quota_used && drive_info.quota_total && (
                                <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Úložiště</span>
                                    <span className="text-white">{fmt(drive_info.quota_used)} / {fmt(drive_info.quota_total)}</span>
                                </div>
                            )}
                            {drive_info.last_ok && (
                                <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Poslední OK</span>
                                    <span className="text-white">{new Date(drive_info.last_ok).toLocaleString('cs-CZ')}</span>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* Media stats */}
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 mb-4">
                    <h2 className="text-sm font-semibold text-white mb-3">Média</h2>
                    <div className="grid grid-cols-2 gap-3">
                        {[
                            { label: 'Celkem médií', value: media_stats.total, ok: true },
                            { label: 'Na Google Drive', value: media_stats.with_drive, ok: media_stats.with_drive === media_stats.total },
                            { label: 'Bez lokálního souboru', value: media_stats.missing_local, ok: media_stats.missing_local === 0 },
                            { label: 'Bez náhledu', value: media_stats.no_thumb, ok: media_stats.no_thumb === 0 },
                        ].map(s => (
                            <div key={s.label} className={`p-3 rounded-lg ${s.ok ? 'bg-green-500/10 border border-green-500/20' : 'bg-yellow-500/10 border border-yellow-500/20'}`}>
                                <p className="text-xl font-bold text-white">{s.value}</p>
                                <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">{s.label}</p>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Actions */}
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4">
                    <h2 className="text-sm font-semibold text-white mb-3">Opravné akce</h2>
                    <div className="space-y-2 text-xs text-[var(--color-text-secondary)]">
                        <p className="flex items-center gap-2"><RefreshCw size={12}/>Na serveru: <code className="bg-[var(--color-bg-secondary)] px-2 py-0.5 rounded font-mono">php artisan gallery:thumbnails --recover</code></p>
                        <p className="flex items-center gap-2"><RefreshCw size={12}/>EXIF z Drive: <code className="bg-[var(--color-bg-secondary)] px-2 py-0.5 rounded font-mono">php artisan gallery:exif --all</code></p>
                        <p className="flex items-center gap-2"><RefreshCw size={12}/>Obnovit tagy: <code className="bg-[var(--color-bg-secondary)] px-2 py-0.5 rounded font-mono">php artisan gallery:doctor</code></p>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
