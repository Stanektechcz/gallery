import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Clock } from 'lucide-react';

interface MediaCardData {
    id: number;
    uuid: string;
    media_type: string;
    taken_at: string | null;
    width: number | null;
    height: number | null;
    is_favorite?: boolean;
    variants: Array<{ type: string; url: string; dominant_color?: string | null; aspect_ratio?: number | null }>;
}

interface Memory {
    year: number;
    label: string;
    date_label: string;
    count: number;
    items: MediaCardData[];
}

interface Props {
    memories: Memory[];
    today_label: string;
    has_memories: boolean;
}

function MemoryCard({ item }: { item: MediaCardData }) {
    const thumb       = item.variants.find(v => v.type === 'thumbnail');
    const placeholder = item.variants.find(v => v.type === 'placeholder');
    const aspect      = thumb?.aspect_ratio ?? 1;

    return (
        <Link
            href={`/media/${item.uuid}`}
            className="relative group rounded-lg overflow-hidden bg-[var(--color-bg-card)] cursor-pointer block"
            style={{ aspectRatio: aspect }}
        >
            {placeholder?.dominant_color && (
                <div className="absolute inset-0" style={{ backgroundColor: placeholder.dominant_color }} />
            )}
            {thumb && (
                <img src={thumb.url} alt="" loading="lazy" className="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
            )}
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors" />
        </Link>
    );
}

export default function MemoriesIndex({ memories, today_label, has_memories }: Props) {
    return (
        <AppLayout>
            <Head title="Vzpomínky" />
            <div className="min-h-full">
                {/* Header */}
                <div className="sticky top-0 z-20 px-4 py-3 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 backdrop-blur-sm">
                    <div className="flex items-center gap-2">
                        <Clock size={16} className="text-[var(--color-accent)]" />
                        <h1 className="text-sm font-semibold text-white">Vzpomínky</h1>
                        <span className="text-xs text-[var(--color-text-secondary)]">· {today_label}</span>
                    </div>
                </div>

                <div className="p-4 max-w-3xl mx-auto">
                    {!has_memories ? (
                        <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                            <Clock size={48} className="mb-3 opacity-20" />
                            <p className="text-lg font-medium text-white mb-1">Žádné vzpomínky</p>
                            <p className="text-sm text-center max-w-xs">
                                Vzpomínky se zobrazí, když budete mít fotky ze stejného dne v minulých letech.
                                Přidejte fotky a za rok se tu objeví vzpomínky!
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-10">
                            {memories.map(memory => (
                                <section key={memory.year}>
                                    {/* Memory header */}
                                    <div className="flex items-center gap-3 mb-4">
                                        <div className="flex items-center gap-2">
                                            <div className="w-8 h-8 rounded-xl bg-[var(--color-accent)]/20 flex items-center justify-center">
                                                <Clock size={14} className="text-[var(--color-accent)]" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-semibold text-white">{memory.label}</p>
                                                <p className="text-xs text-[var(--color-text-secondary)]">{memory.date_label} · {memory.count} {memory.count === 1 ? 'fotka' : memory.count < 5 ? 'fotky' : 'fotek'}</p>
                                            </div>
                                        </div>
                                        <div className="flex-1 h-px bg-[var(--color-border)]" />
                                        <span className="text-xs font-bold text-[var(--color-text-secondary)]">{memory.year}</span>
                                    </div>

                                    {/* Photos grid */}
                                    <div
                                        style={{
                                            display: 'grid',
                                            gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))',
                                            gap: '4px',
                                        }}
                                    >
                                        {memory.items.slice(0, 12).map(item => (
                                            <MemoryCard key={item.uuid} item={item} />
                                        ))}

                                        {/* "See all" card if more than 12 */}
                                        {memory.count > 12 && (
                                            <Link
                                                href={`/timeline?date=${memory.year}-${new Date().getMonth() + 1 < 10 ? '0' : ''}${new Date().getMonth() + 1}-${new Date().getDate() < 10 ? '0' : ''}${new Date().getDate()}&year=${memory.year}`}
                                                className="relative rounded-lg overflow-hidden bg-[var(--color-bg-card)] flex items-center justify-center aspect-square hover:bg-white/5 transition-colors group"
                                            >
                                                <div className="text-center">
                                                    <p className="text-2xl font-bold text-white">+{memory.count - 12}</p>
                                                    <div className="flex items-center gap-1 text-xs text-[var(--color-text-secondary)] mt-1">
                                                        <span>Zobrazit vše</span>
                                                        <ChevronRight size={10} />
                                                    </div>
                                                </div>
                                            </Link>
                                        )}
                                    </div>
                                </section>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
