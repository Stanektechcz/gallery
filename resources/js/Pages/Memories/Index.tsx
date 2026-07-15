import AppLayout from '@/Layouts/AppLayout';
import MemoryEveningPanel from '@/Components/MemoryEveningPanel';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Bookmark, CalendarHeart, Clock, EyeOff, Heart, Settings2, Sparkles, TimerReset, X } from 'lucide-react';
import { useEffect, useState } from 'react';

interface MediaCardData {
    id: number;
    uuid: string;
    media_type: string;
    taken_at: string | null;
    width: number | null;
    height: number | null;
    variants: Array<{ type: string; url: string; dominant_color?: string | null; aspect_ratio?: number | null }>;
}
type MemoryType = 'on_this_day' | 'trip_anniversary' | 'favorite_flashback' | 'place_flashback' | 'monthly_highlight';

interface Memory {
    fingerprint: string;
    type: MemoryType;
    title: string;
    subtitle: string;
    reason: string;
    icon: string;
    accent: string;
    count: number;
    items: MediaCardData[];
}

interface Props {
    memories: Memory[];
    today_label: string;
    has_memories: boolean;
}

const TYPE_LABELS: Record<MemoryType, string> = {
    on_this_day: 'Tento den',
    trip_anniversary: 'Výročí cest',
    favorite_flashback: 'Oblíbené znovu',
    place_flashback: 'Místa',
    monthly_highlight: 'Měsíční výběry',
};

function thumbnail(item?: MediaCardData) {
    return item?.variants.find(variant => variant.type === 'thumbnail')?.url;
}

function MemoryCard({ memory, onAction, onShare, onPlan }: { memory: Memory; onAction: (memory: Memory, action: 'saved' | 'dismissed' | 'snoozed') => void; onShare: (memory: Memory) => void; onPlan:(memory:Memory)=>void }) {
    const visible = memory.items.slice(0, 5);

    return (
        <article className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-bg-card)] shadow-xl shadow-black/10">
            <div className="relative grid h-64 grid-cols-4 grid-rows-2 gap-1 bg-[var(--color-bg-secondary)] sm:h-80">
                {visible.map((item, index) => (
                    <Link key={item.uuid} href={`/media/${item.uuid}`}
                        className={`relative overflow-hidden ${index === 0 ? 'col-span-2 row-span-2' : index === 1 || index === 2 ? 'col-span-2' : ''}`}>
                        {thumbnail(item) ? (
                            <img src={thumbnail(item)} alt="" loading="lazy" className="h-full w-full object-cover transition-transform duration-500 hover:scale-105" />
                        ) : (
                            <div className="h-full w-full" style={{ background: memory.accent }} />
                        )}
                        {index === 4 && memory.count > 5 && (
                            <div className="absolute inset-0 flex items-center justify-center bg-black/55 text-xl font-bold text-white">+{memory.count - 5}</div>
                        )}
                    </Link>
                ))}
                <div className="pointer-events-none absolute inset-x-0 bottom-0 h-32 bg-gradient-to-t from-black/85 to-transparent" />
                <div className="pointer-events-none absolute bottom-0 left-0 right-0 p-5 sm:p-6">
                    <div className="mb-2 flex items-center gap-2 text-xs font-medium text-white/80">
                        <span className="text-lg">{memory.icon}</span>
                        <span>{TYPE_LABELS[memory.type]}</span>
                    </div>
                    <h2 className="text-xl font-bold text-white sm:text-2xl">{memory.title}</h2>
                    <p className="mt-1 text-sm text-white/75">{memory.subtitle} · {memory.count} {memory.count === 1 ? 'moment' : 'momentů'}</p>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div className="flex items-start gap-2 text-xs text-[var(--color-text-secondary)]">
                    <Sparkles size={14} className="mt-0.5 shrink-0" style={{ color: memory.accent }} />
                    <div>
                        <p className="font-medium text-white">Proč ji vidíte</p>
                        <p>{memory.reason}</p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button onClick={()=>onPlan(memory)} title="Naplánovat společný večer" className="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl border border-violet-400/30 px-3 text-xs font-medium text-violet-100 hover:bg-violet-500/10 sm:flex-none"><CalendarHeart size={15}/> Večer</button>
                    <button onClick={() => onShare(memory)} title="Uložit pro vás oba" className="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl border border-pink-400/30 px-3 text-xs font-medium text-pink-100 hover:bg-pink-500/10 sm:flex-none"><Heart size={15} /> Pro nás</button>
                    <button onClick={() => onAction(memory, 'saved')} title="Uložit vzpomínku"
                        className="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--color-accent)] px-3 text-xs font-medium text-white sm:flex-none">
                        <Bookmark size={15} /> Uložit
                    </button>
                    <button onClick={() => onAction(memory, 'snoozed')} title="Připomenout později"
                        className="flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white">
                        <TimerReset size={16} />
                    </button>
                    <button onClick={() => onAction(memory, 'dismissed')} title="Tuto vzpomínku nezobrazovat"
                        className="flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:border-red-500/50 hover:text-red-400">
                        <EyeOff size={16} />
                    </button>
                </div>
            </div>
        </article>
    );
}

function PreferencesPanel({ onClose }: { onClose: () => void }) {
    const [frequency, setFrequency] = useState('normal');
    const [enabled, setEnabled] = useState<MemoryType[]>(Object.keys(TYPE_LABELS) as MemoryType[]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        axios.get('/api/v1/memories/preferences').then(response => {
            setFrequency(response.data.frequency ?? 'normal');
            if (response.data.enabled_types) setEnabled(response.data.enabled_types);
        });
    }, []);

    const save = async () => {
        setSaving(true);
        await axios.patch('/api/v1/memories/preferences', { frequency, enabled_types: enabled });
        setSaving(false);
        onClose();
        window.location.reload();
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 backdrop-blur-sm sm:items-center sm:p-4">
            <div className="w-full max-w-md rounded-t-3xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5 shadow-2xl sm:rounded-3xl">
                <div className="mb-5 flex items-center justify-between">
                    <div><h2 className="font-semibold text-white">Nastavení vzpomínek</h2><p className="text-xs text-[var(--color-text-secondary)]">Vy rozhodujete, co se vrací.</p></div>
                    <button onClick={onClose} className="flex h-10 w-10 items-center justify-center rounded-xl hover:bg-white/5"><X size={18} /></button>
                </div>
                <label className="mb-4 block text-xs text-[var(--color-text-secondary)]">Četnost
                    <select value={frequency} onChange={event => setFrequency(event.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white">
                        <option value="more">Častěji</option><option value="normal">Běžně</option><option value="less">Méně často</option><option value="off">Vypnout</option>
                    </select>
                </label>
                <p className="mb-2 text-xs text-[var(--color-text-secondary)]">Typy vzpomínek</p>
                <div className="space-y-1">
                    {(Object.keys(TYPE_LABELS) as MemoryType[]).map(type => (
                        <label key={type} className="flex min-h-11 cursor-pointer items-center justify-between rounded-xl px-3 hover:bg-white/5">
                            <span className="text-sm text-white">{TYPE_LABELS[type]}</span>
                            <input type="checkbox" checked={enabled.includes(type)} onChange={() => setEnabled(previous => previous.includes(type) ? previous.filter(item => item !== type) : [...previous, type])} />
                        </label>
                    ))}
                </div>
                <button onClick={save} disabled={saving || enabled.length === 0} className="mt-5 min-h-11 w-full rounded-xl bg-[var(--color-accent)] text-sm font-medium text-white disabled:opacity-40">{saving ? 'Ukládám…' : 'Uložit nastavení'}</button>
            </div>
        </div>
    );
}

export default function MemoriesIndex({ memories: initialMemories, today_label, has_memories }: Props) {
    const [memories, setMemories] = useState(initialMemories);
    const [showSettings, setShowSettings] = useState(false);
    const [spaceId, setSpaceId] = useState<number | null>(null);
    const [sharedFingerprint, setSharedFingerprint] = useState('');
    const [planningMemory,setPlanningMemory]=useState<Memory|null>(null);

    useEffect(() => { axios.get('/api/v1/calendar/events').then(response => setSpaceId(response.data.spaces?.[0]?.id ?? null)).catch(() => {}); }, []);

    const action = async (memory: Memory, type: 'saved' | 'dismissed' | 'snoozed') => {
        await axios.post('/api/v1/memories/interactions', { fingerprint: memory.fingerprint, memory_type: memory.type, action: type });
        if (type !== 'saved') setMemories(previous => previous.filter(item => item.fingerprint !== memory.fingerprint));
    };
    const share = async (memory: Memory) => {
        if (!spaceId || sharedFingerprint === memory.fingerprint) return;
        await axios.post('/api/v1/shared-memory-moments', { gallery_space_id: spaceId, title: memory.title, happened_on: memory.items[0]?.taken_at?.slice(0, 10) || null, media_item_ids: memory.items.map(item => item.id), is_favorite: true });
        setSharedFingerprint(memory.fingerprint);
    };

    return (
        <AppLayout>
            <Head title="Vzpomínky" />
            <div className="min-h-full">
                <header className="sticky top-0 z-20 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 px-4 py-3 backdrop-blur-xl">
                    <div className="mx-auto flex max-w-5xl items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[var(--color-accent)]/15"><Clock size={19} className="text-[var(--color-accent)]" /></div>
                            <div><h1 className="font-semibold text-white">Pro vás</h1><p className="text-xs text-[var(--color-text-secondary)]">{today_label} · osobní výběry z vašeho archivu</p></div>
                        </div>
                        <button onClick={() => setShowSettings(true)} className="flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white"><Settings2 size={17} /></button>
                    </div>
                </header>

                <main className="mx-auto max-w-5xl space-y-6 p-3 pb-24 sm:p-6">
                    <MemoryEveningPanel spaceId={spaceId} source={planningMemory} onClose={()=>setPlanningMemory(null)}/>
                    {!has_memories || memories.length === 0 ? (
                        <div className="flex min-h-[60vh] flex-col items-center justify-center text-center text-[var(--color-text-secondary)]">
                            <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-3xl bg-[var(--color-accent)]/10"><Sparkles size={34} className="text-[var(--color-accent)]" /></div>
                            <h2 className="text-lg font-semibold text-white">Vzpomínky právě odpočívají</h2>
                            <p className="mt-2 max-w-sm text-sm">Až najdeme výročí, oblíbené momenty, významnou cestu nebo známé místo, objeví se tady.</p>
                        </div>
                    ) : memories.map(memory => <MemoryCard key={memory.fingerprint} memory={memory} onAction={action} onShare={share} onPlan={setPlanningMemory} />)}
                </main>
                {showSettings && <PreferencesPanel onClose={() => setShowSettings(false)} />}
            </div>
        </AppLayout>
    );
}
