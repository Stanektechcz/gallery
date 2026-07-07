import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, CheckCircle, Layers, RefreshCw, Sparkles, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

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
    const [cleanup, setCleanup] = useState<{ potential_savings: number; categories: Array<{ key: string; label: string; icon: string; count: number; bytes: number; reason: string; action: string }> } | null>(null);
    const [stackPreview, setStackPreview] = useState<{ count: number; groups: Array<{ key: string; label: string; type: string; confidence: number; items: unknown[] }> } | null>(null);
    const [stacking, setStacking] = useState(false);
    useEffect(() => {
        axios.get('/api/v1/recovery/cleanup').then(response => setCleanup(response.data)).catch(() => undefined);
        axios.get('/api/v1/media-stacks/preview').then(response => setStackPreview(response.data)).catch(() => undefined);
    }, []);
    const applyStacks = async () => {
        if (!stackPreview?.count || !confirm(`Seskupit ${stackPreview.count} rozpoznaných sérií? Fotografie se nesmažou.`)) return;
        setStacking(true);
        try {
            const { data } = await axios.post('/api/v1/media-stacks/apply', { candidate_keys: stackPreview.groups.map(group => group.key) });
            setStackPreview({ count: 0, groups: [] });
            alert(`Vytvořeno stacků: ${data.created}`);
        } finally { setStacking(false); }
    };

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
                {cleanup && cleanup.categories.length > 0 && (
                    <div className="mb-4 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                        <div className="mb-3 flex items-center justify-between"><div className="flex items-center gap-2"><Sparkles size={15} className="text-[var(--color-accent)]"/><h2 className="text-sm font-semibold text-white">Chytrý úklid</h2></div>{cleanup.potential_savings > 0 && <span className="text-[10px] text-green-400">až {fmt(cleanup.potential_savings)} k uvolnění</span>}</div>
                        <p className="mb-3 text-xs text-[var(--color-text-secondary)]">Pouze vysvětlitelné návrhy. Nic se nesmaže bez vaší kontroly.</p>
                        <div className="grid gap-2 sm:grid-cols-2">{cleanup.categories.map(category => (
                            <Link key={category.key} href={category.action} className="rounded-xl border border-[var(--color-border)] p-3 transition hover:border-[var(--color-accent)]">
                                <div className="flex items-center gap-2"><span className="text-xl">{category.icon}</span><div className="min-w-0 flex-1"><p className="text-xs font-medium text-white">{category.label}</p><p className="text-[10px] text-[var(--color-text-secondary)]">{category.count} položek{category.bytes > 0 ? ` · ${fmt(category.bytes)}` : ''}</p></div></div>
                                <p className="mt-2 text-[10px] leading-relaxed text-[var(--color-text-secondary)]">{category.reason}</p>
                            </Link>
                        ))}</div>
                    </div>
                )}

                {stackPreview && (
                    <div className="mb-4 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-start gap-3"><Layers size={18} className="mt-0.5 text-[var(--color-accent)]"/><div><h2 className="text-sm font-semibold text-white">Automatické stacky</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">RAW+JPEG a rychlé série zůstanou pohromadě. Nejlepší snímek bude na obálce.</p></div></div>
                            <button onClick={applyStacks} disabled={!stackPreview.count || stacking} className="min-h-10 shrink-0 rounded-lg bg-[var(--color-accent)] px-4 text-xs font-medium text-white disabled:opacity-40">{stacking ? 'Seskupuji…' : stackPreview.count ? `Seskupit ${stackPreview.count} sérií` : 'Vše seskupeno'}</button>
                        </div>
                    </div>
                )}

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
