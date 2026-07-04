import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ChevronLeft, ChevronRight, Film, Image } from 'lucide-react';
import { useEffect, useState } from 'react';

interface DayData {
    day: number;
    total: number;
    photos: number;
    videos: number;
    thumb?: { uuid: string; thumb?: string } | null;
}

const MONTHS_CS = ['Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
const DAYS_CS   = ['Po','Út','St','Čt','Pá','So','Ne'];

export default function CalendarIndex() {
    const now   = new Date();
    const [year,  setYear]  = useState(now.getFullYear());
    const [month, setMonth] = useState(now.getMonth() + 1);
    const [days,  setDays]  = useState<DayData[]>([]);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState<DayData | null>(null);

    useEffect(() => {
        setLoading(true);
        setSelected(null);
        axios.get('/api/v1/timeline/calendar', { params: { year, month } })
            .then(r => setDays(r.data.days ?? []))
            .finally(() => setLoading(false));
    }, [year, month]);

    const prev = () => {
        if (month === 1) { setYear(y => y - 1); setMonth(12); }
        else setMonth(m => m - 1);
    };
    const next = () => {
        if (month === 12) { setYear(y => y + 1); setMonth(1); }
        else setMonth(m => m + 1);
    };

    // Build calendar grid
    const firstDay  = new Date(year, month - 1, 1).getDay(); // 0=Sun
    const firstMon  = firstDay === 0 ? 6 : firstDay - 1;     // shift to Mon=0
    const daysInMonth = new Date(year, month, 0).getDate();
    const dayMap    = Object.fromEntries(days.map(d => [d.day, d]));

    const cells: Array<number | null> = [
        ...Array(firstMon).fill(null),
        ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
    ];
    // Pad to complete weeks
    while (cells.length % 7 !== 0) cells.push(null);

    return (
        <AppLayout>
            <Head title="Kalendář" />

            <div className="p-4 max-w-2xl mx-auto">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <button onClick={prev} className="p-2 rounded-lg hover:bg-white/10 text-[var(--color-text-secondary)] hover:text-white transition-colors">
                        <ChevronLeft size={18} />
                    </button>
                    <div className="text-center">
                        <h1 className="text-lg font-semibold text-white">{MONTHS_CS[month - 1]} {year}</h1>
                        <button onClick={() => { setYear(now.getFullYear()); setMonth(now.getMonth() + 1); }}
                            className="text-xs text-[var(--color-accent)] hover:underline mt-0.5">dnes</button>
                    </div>
                    <button onClick={next} className="p-2 rounded-lg hover:bg-white/10 text-[var(--color-text-secondary)] hover:text-white transition-colors">
                        <ChevronRight size={18} />
                    </button>
                </div>

                {/* Day headers */}
                <div className="grid grid-cols-7 mb-2">
                    {DAYS_CS.map(d => (
                        <div key={d} className="text-center text-xs font-medium text-[var(--color-text-secondary)] py-1">{d}</div>
                    ))}
                </div>

                {/* Calendar grid */}
                <div className={`grid grid-cols-7 gap-1 ${loading ? 'opacity-50' : ''}`}>
                    {cells.map((day, i) => {
                        if (!day) return <div key={i} />;
                        const d = dayMap[day];
                        const isToday = day === now.getDate() && month === now.getMonth() + 1 && year === now.getFullYear();
                        const isSelected = selected?.day === day;

                        return (
                            <button
                                key={i}
                                onClick={() => setSelected(d ? (isSelected ? null : d) : null)}
                                className={[
                                    'relative rounded-xl overflow-hidden aspect-square flex flex-col items-center justify-start pt-1 transition-all',
                                    d ? 'bg-[var(--color-bg-card)] hover:border-[var(--color-accent)]/60 border' : 'border border-transparent',
                                    isSelected ? 'border-[var(--color-accent)] ring-1 ring-[var(--color-accent)]' : 'border-[var(--color-border)]',
                                    isToday ? 'ring-2 ring-[var(--color-accent)]' : '',
                                ].join(' ')}
                            >
                                {/* Thumbnail background */}
                                {d?.thumb?.thumb && (
                                    <img src={d.thumb.thumb} alt="" className="absolute inset-0 w-full h-full object-cover opacity-30" />
                                )}

                                {/* Day number */}
                                <span className={`relative z-10 text-xs font-semibold leading-none mt-1 ${isToday ? 'text-[var(--color-accent)]' : d ? 'text-white' : 'text-[var(--color-text-secondary)]'}`}>
                                    {day}
                                </span>

                                {/* Count */}
                                {d && (
                                    <span className="relative z-10 text-[9px] text-white/80 mt-auto mb-1 font-medium">
                                        {d.total}
                                    </span>
                                )}

                                {/* Dot indicator */}
                                {d && !d.thumb?.thumb && (
                                    <div className="absolute bottom-1 left-1/2 -translate-x-1/2 w-1 h-1 rounded-full bg-[var(--color-accent)]" />
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* Selected day popup */}
                {selected && (
                    <div className="mt-4 rounded-xl border border-[var(--color-accent)]/40 bg-[var(--color-bg-card)] p-4">
                        <div className="flex items-center justify-between mb-3">
                            <h2 className="text-sm font-semibold text-white">
                                {selected.day}. {MONTHS_CS[month - 1].toLowerCase()} {year}
                            </h2>
                            <button onClick={() => setSelected(null)} className="text-[var(--color-text-secondary)] hover:text-white text-lg leading-none">×</button>
                        </div>
                        <div className="flex items-center gap-4 text-sm text-[var(--color-text-secondary)] mb-3">
                            <span className="flex items-center gap-1.5">
                                <Image size={13} className="text-[var(--color-accent)]" />
                                {selected.photos} fotek
                            </span>
                            {selected.videos > 0 && (
                                <span className="flex items-center gap-1.5">
                                    <Film size={13} className="text-[var(--color-accent)]" />
                                    {selected.videos} videí
                                </span>
                            )}
                        </div>
                        <Link
                            href={`/timeline?date=${year}-${String(month).padStart(2,'0')}-${String(selected.day).padStart(2,'0')}`}
                            className="inline-flex items-center gap-1.5 text-xs bg-[var(--color-accent)] text-white px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity"
                        >
                            Zobrazit fotky →
                        </Link>
                    </div>
                )}

                {/* Month summary */}
                <div className="mt-4 text-center text-xs text-[var(--color-text-secondary)]">
                    {days.reduce((s, d) => s + d.total, 0)} médií v {MONTHS_CS[month - 1].toLowerCase()} {year}
                </div>
            </div>
        </AppLayout>
    );
}
