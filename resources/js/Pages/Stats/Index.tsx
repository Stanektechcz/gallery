import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Archive, Camera, Film, FolderOpen, Heart, Image, MapPin, Star } from 'lucide-react';

const MONTHS_CS = ['Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];

function fmt(n: number): string {
    if (n >= 1e9) return (n/1e9).toFixed(1) + ' GB';
    if (n >= 1e6) return (n/1e6).toFixed(1) + ' MB';
    if (n >= 1e3) return (n/1e3).toFixed(0) + ' KB';
    return n + ' B';
}

function StatCard({ icon: Icon, label, value, sub, color = 'var(--color-accent)' }: { icon: any; label: string; value: string | number; sub?: string; color?: string }) {
    return (
        <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4">
            <div className="flex items-center gap-2 mb-2">
                <div className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ backgroundColor: color + '22' }}>
                    <Icon size={14} style={{ color }} />
                </div>
                <span className="text-xs text-[var(--color-text-secondary)]">{label}</span>
            </div>
            <p className="text-2xl font-bold text-white">{typeof value === 'number' ? value.toLocaleString('cs-CZ') : value}</p>
            {sub && <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">{sub}</p>}
        </div>
    );
}

export default function StatsIndex({ stats }: { stats: any }) {
    if (!stats) return (
        <AppLayout><Head title="Statistiky" />
            <div className="flex items-center justify-center h-full text-[var(--color-text-secondary)]">Galerie není nakonfigurována.</div>
        </AppLayout>
    );

    const maxYear = Math.max(...(stats.per_year?.map((y: any) => y.total) ?? [1]));
    const maxMonth = Math.max(...(stats.per_month?.map((m: any) => m.total) ?? [1]));

    return (
        <AppLayout>
            <Head title="Statistiky" />
            <div className="p-4 max-w-4xl mx-auto space-y-6 pb-8">
                <h1 className="text-xl font-bold text-white">Statistiky galerie</h1>

                {/* Top cards */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <StatCard icon={Image}     label="Fotografie"    value={stats.photos}    color="#6366f1" />
                    <StatCard icon={Film}      label="Videa"          value={stats.videos}    color="#ec4899" />
                    <StatCard icon={FolderOpen} label="Alba"          value={stats.albums}    color="#06b6d4" />
                    <StatCard icon={MapPin}    label="S GPS"          value={stats.with_gps}  color="#22c55e" />
                    <StatCard icon={Heart}     label="Oblíbené"       value={stats.favorites} color="#f97316" />
                    <StatCard icon={Archive}   label="Archivováno"    value={stats.archived}  color="#8b5cf6" />
                    <StatCard icon={Star}      label="Celkem médií"   value={stats.total}     color="#eab308" />
                    <StatCard icon={Image}     label="Celková velikost" value={fmt(stats.total_size)} color="#14b8a6" />
                </div>

                {/* Per year bar chart */}
                {stats.per_year?.length > 0 && (
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4">
                        <h2 className="text-sm font-semibold text-white mb-4">Fotky podle roku</h2>
                        <div className="space-y-2">
                            {stats.per_year.map((y: any) => (
                                <div key={y.year} className="flex items-center gap-3">
                                    <span className="text-xs text-[var(--color-text-secondary)] w-10 shrink-0">{y.year}</span>
                                    <div className="flex-1 h-6 bg-[var(--color-bg-secondary)] rounded overflow-hidden">
                                        <div
                                            className="h-full bg-[var(--color-accent)] rounded transition-all"
                                            style={{ width: `${(y.total / maxYear) * 100}%` }}
                                        />
                                    </div>
                                    <span className="text-xs text-white w-14 text-right shrink-0">{y.total.toLocaleString('cs-CZ')}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Per month (current year) */}
                {stats.per_month?.length > 0 && (
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4">
                        <h2 className="text-sm font-semibold text-white mb-4">Fotky v roce {stats.year} po měsících</h2>
                        <div className="flex items-end gap-1 h-24">
                            {Array.from({ length: 12 }, (_, i) => {
                                const m = stats.per_month?.find((p: any) => p.month === i + 1);
                                const h = m ? (m.total / maxMonth) * 100 : 0;
                                return (
                                    <div key={i} className="flex-1 flex flex-col items-center gap-1">
                                        <div className="w-full relative" style={{ height: 80 }}>
                                            <div
                                                className="absolute bottom-0 left-0 right-0 bg-[var(--color-accent)]/80 rounded-t transition-all"
                                                style={{ height: `${h}%` }}
                                            />
                                        </div>
                                        <span className="text-[8px] text-[var(--color-text-secondary)]">
                                            {MONTHS_CS[i].substring(0,3)}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div className="grid md:grid-cols-2 gap-4">
                    {/* Cameras */}
                    {stats.cameras?.length > 0 && (
                        <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4">
                            <h2 className="text-sm font-semibold text-white mb-3 flex items-center gap-2"><Camera size={14} /> Fotoaparáty</h2>
                            <div className="space-y-2">
                                {stats.cameras.map((c: any) => (
                                    <div key={c.camera_model} className="flex items-center justify-between text-xs">
                                        <span className="text-[var(--color-text-secondary)] truncate max-w-[70%]">{c.camera_model}</span>
                                        <span className="text-white font-medium">{c.count.toLocaleString('cs-CZ')}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Formats */}
                    {stats.formats?.length > 0 && (
                        <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4">
                            <h2 className="text-sm font-semibold text-white mb-3">Formáty souborů</h2>
                            <div className="space-y-2">
                                {stats.formats.map((f: any) => (
                                    <div key={f.ext} className="flex items-center justify-between text-xs">
                                        <span className="text-[var(--color-text-secondary)] font-mono">{f.ext}</span>
                                        <span className="text-white font-medium">{f.count.toLocaleString('cs-CZ')}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
