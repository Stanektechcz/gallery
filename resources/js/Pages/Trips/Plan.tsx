import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Check, ChevronDown, Clock3, CloudDownload, Compass, GripVertical, MapPin, Plus, Trash2, Wallet, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type ActivityType = 'activity' | 'transport' | 'stay' | 'reservation' | 'note' | 'checklist' | 'expense';
interface Activity { id: number; type: ActivityType; title: string; description?: string; starts_at?: string; ends_at?: string; place_name?: string; status: string; cost?: number; currency: string; }
interface TripDay { id: number; date: string; title?: string; notes?: string; activities: Activity[]; }
interface Trip { id: number; name: string; start_date: string; end_date: string; status: string; budget?: number; currency: string; is_offline_available: boolean; }

const TYPES: Record<ActivityType, { label: string; icon: string; color: string }> = {
    activity: { label: 'Aktivita', icon: '✨', color: '#6c63ff' },
    transport: { label: 'Přesun', icon: '🚆', color: '#3b82f6' },
    stay: { label: 'Ubytování', icon: '🛏️', color: '#14b8a6' },
    reservation: { label: 'Rezervace', icon: '🎟️', color: '#f59e0b' },
    note: { label: 'Poznámka', icon: '📝', color: '#8b5cf6' },
    checklist: { label: 'Úkol', icon: '✅', color: '#22c55e' },
    expense: { label: 'Výdaj', icon: '💳', color: '#ef4444' },
};

export default function TripPlan({ tripId }: { tripId: number }) {
    const [trip, setTrip] = useState<Trip | null>(null);
    const [days, setDays] = useState<TripDay[]>([]);
    const [activeDayId, setActiveDayId] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    const [showAdd, setShowAdd] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({ type: 'activity' as ActivityType, title: '', starts_at: '', ends_at: '', place_name: '', cost: '' });

    const load = async () => {
        const response = await axios.get(`/api/v1/trips/${tripId}/plan`);
        setTrip(response.data.trip); setDays(response.data.days);
        setActiveDayId(previous => previous ?? response.data.days?.[0]?.id ?? null);
        setLoading(false);
    };
    useEffect(() => { load(); }, [tripId]);
    const activeDay = days.find(day => day.id === activeDayId) ?? null;
    const totalCost = useMemo(() => days.flatMap(day => day.activities).reduce((sum, activity) => sum + Number(activity.cost ?? 0), 0), [days]);

    const addActivity = async (event: React.FormEvent) => {
        event.preventDefault(); if (!activeDay || !form.title.trim()) return;
        setSaving(true);
        const payload = { ...form, starts_at: form.starts_at || null, ends_at: form.ends_at || null, place_name: form.place_name || null, cost: form.cost ? Number(form.cost) : null, currency: trip?.currency ?? 'CZK' };
        const response = await axios.post(`/api/v1/trips/${tripId}/plan/days/${activeDay.id}/activities`, payload);
        setDays(previous => previous.map(day => day.id === activeDay.id ? { ...day, activities: [...day.activities, response.data] } : day));
        setForm({ type: 'activity', title: '', starts_at: '', ends_at: '', place_name: '', cost: '' }); setShowAdd(false); setSaving(false);
    };
    const updateActivity = async (activity: Activity, patch: Partial<Activity>) => {
        const response = await axios.patch(`/api/v1/trips/${tripId}/plan/activities/${activity.id}`, patch);
        setDays(previous => previous.map(day => ({ ...day, activities: day.activities.map(item => item.id === activity.id ? { ...item, ...response.data } : item) })));
    };
    const removeActivity = async (activity: Activity) => {
        if (!window.confirm(`Odebrat „${activity.title}“ z itineráře?`)) return;
        await axios.delete(`/api/v1/trips/${tripId}/plan/activities/${activity.id}`);
        setDays(previous => previous.map(day => ({ ...day, activities: day.activities.filter(item => item.id !== activity.id) })));
    };
    const updateStatus = async (status: string) => {
        await axios.patch(`/api/v1/trips/${tripId}`, { status });
        setTrip(previous => previous ? { ...previous, status } : previous);
    };
    const toggleOffline = async () => {
        const next = !trip.is_offline_available;
        await axios.patch(`/api/v1/trips/${tripId}`, { is_offline_available: next });
        await axios.get(`/api/v1/trips/${tripId}/plan`);
        setTrip(previous => previous ? { ...previous, is_offline_available: next } : previous);
    };

    if (loading || !trip) return <AppLayout><div className="flex h-full items-center justify-center"><div className="h-8 w-8 animate-spin rounded-full border-2 border-[var(--color-accent)] border-t-transparent" /></div></AppLayout>;

    return (
        <AppLayout>
            <Head title={`Plán · ${trip.name}`} />
            <div className="min-h-full pb-24">
                <header className="sticky top-0 z-20 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 px-3 py-3 backdrop-blur-xl sm:px-6">
                    <div className="mx-auto flex max-w-6xl items-center gap-3">
                        <Link href="/trips" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white"><ArrowLeft size={18} /></Link>
                        <div className="min-w-0 flex-1"><p className="text-[10px] uppercase tracking-wider text-[var(--color-text-secondary)]">Workspace cesty</p><h1 className="truncate font-semibold text-white">{trip.name}</h1></div>
                        <div className="relative hidden sm:block"><select value={trip.status} onChange={event => updateStatus(event.target.value)} className="min-h-10 appearance-none rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] pl-3 pr-9 text-xs text-white"><option value="draft">Návrh</option><option value="planned">Naplánováno</option><option value="active">Probíhá</option><option value="completed">Dokončeno</option><option value="archived">Archivováno</option></select><ChevronDown size={13} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2" /></div>
                        <button onClick={toggleOffline} title="Dostupnost bez internetu" className={`flex min-h-10 min-w-10 items-center justify-center rounded-xl border ${trip.is_offline_available ? 'border-green-500/40 bg-green-500/10 text-green-400' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}><CloudDownload size={16} /></button>
                        <Link href={`/trips/${trip.id}/now`} className="flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-3 text-xs font-medium text-white"><Compass size={15}/><span className="hidden sm:inline">Právě teď</span></Link>
                        <div className="hidden items-center gap-2 rounded-xl bg-[var(--color-bg-card)] px-3 py-2 sm:flex"><Wallet size={14} className="text-[var(--color-accent)]" /><div><p className="text-[9px] text-[var(--color-text-secondary)]">Plánováno</p><p className="text-xs font-semibold text-white">{totalCost.toLocaleString('cs-CZ')} {trip.currency}</p></div></div>
                    </div>
                </header>

                <main className="mx-auto max-w-6xl p-3 sm:p-6">
                    <div className="mb-5 flex gap-2 overflow-x-auto pb-2 scrollbar-hide">
                        {days.map((day, index) => <button key={day.id} onClick={() => setActiveDayId(day.id)} className={`min-w-[112px] shrink-0 rounded-2xl border p-3 text-left transition ${day.id === activeDayId ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/15' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}><p className="text-[10px] text-[var(--color-text-secondary)]">Den {index + 1}</p><p className="mt-0.5 text-sm font-medium text-white">{new Date(`${day.date}T12:00:00`).toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric' })}</p><p className="mt-1 text-[9px] text-[var(--color-text-secondary)]">{day.activities.length} bloků</p></button>)}
                    </div>

                    {activeDay && <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                        <section>
                            <div className="mb-3 flex items-end justify-between"><div><p className="text-xs text-[var(--color-text-secondary)]">{new Date(`${activeDay.date}T12:00:00`).toLocaleDateString('cs-CZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p><h2 className="text-xl font-bold text-white">{activeDay.title || 'Plán dne'}</h2></div><button onClick={() => setShowAdd(true)} className="flex min-h-11 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-xs font-medium text-white"><Plus size={15} /> Přidat blok</button></div>

                            <div className="space-y-2">
                                {activeDay.activities.length === 0 && !showAdd && <button onClick={() => setShowAdd(true)} className="flex min-h-40 w-full flex-col items-center justify-center rounded-3xl border border-dashed border-[var(--color-border)] text-[var(--color-text-secondary)] hover:border-[var(--color-accent)]"><Plus size={28} /><span className="mt-2 text-sm text-white">Sestavit první část dne</span><span className="mt-1 text-xs">Aktivita, přesun, rezervace, poznámka nebo výdaj</span></button>}
                                {activeDay.activities.map(activity => <ActivityBlock key={activity.id} activity={activity} onUpdate={patch => updateActivity(activity, patch)} onRemove={() => removeActivity(activity)} />)}
                                {showAdd && <ActivityForm form={form} setForm={setForm} onSubmit={addActivity} onClose={() => setShowAdd(false)} saving={saving} />}
                            </div>
                        </section>

                        <aside className="space-y-3">
                            <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4"><p className="text-xs font-semibold text-white">Přehled dne</p><div className="mt-3 space-y-2 text-xs text-[var(--color-text-secondary)]"><div className="flex justify-between"><span>Bloků</span><span className="text-white">{activeDay.activities.length}</span></div><div className="flex justify-between"><span>Dokončeno</span><span className="text-white">{activeDay.activities.filter(item => item.status === 'done').length}</span></div><div className="flex justify-between"><span>Rozpočet</span><span className="text-white">{activeDay.activities.reduce((sum, item) => sum + Number(item.cost ?? 0), 0).toLocaleString('cs-CZ')} {trip.currency}</span></div></div></div>
                            <div className="rounded-2xl border border-[var(--color-border)] bg-gradient-to-br from-[var(--color-accent)]/15 to-transparent p-4"><p className="text-xs font-semibold text-white">Tip</p><p className="mt-1 text-xs leading-relaxed text-[var(--color-text-secondary)]">Bloky drží rezervace, časy, místa i výdaje pohromadě. Na cestě se tento plán promění v kartu „Právě teď“.</p></div>
                        </aside>
                    </div>}
                </main>
            </div>
        </AppLayout>
    );
}

function ActivityBlock({ activity, onUpdate, onRemove }: { activity: Activity; onUpdate: (patch: Partial<Activity>) => void; onRemove: () => void }) {
    const type = TYPES[activity.type] ?? TYPES.activity;
    return <article className={`group flex gap-2 rounded-2xl border p-3 transition ${activity.status === 'done' ? 'border-green-500/20 bg-green-500/5 opacity-70' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}><div className="flex w-5 shrink-0 cursor-grab items-start justify-center pt-2 text-[var(--color-text-secondary)] opacity-40"><GripVertical size={15} /></div><div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-lg" style={{ backgroundColor: `${type.color}22` }}>{type.icon}</div><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-x-2"><p className={`font-medium text-white ${activity.status === 'done' ? 'line-through' : ''}`}>{activity.title}</p><span className="text-[9px] uppercase tracking-wider" style={{ color: type.color }}>{type.label}</span></div>{(activity.starts_at || activity.place_name) && <div className="mt-1 flex flex-wrap gap-3 text-[10px] text-[var(--color-text-secondary)]">{activity.starts_at && <span className="flex items-center gap-1"><Clock3 size={11} />{activity.starts_at.slice(0, 5)}{activity.ends_at ? `–${activity.ends_at.slice(0, 5)}` : ''}</span>}{activity.place_name && <span className="flex items-center gap-1"><MapPin size={11} />{activity.place_name}</span>}</div>}{activity.description && <p className="mt-2 text-xs leading-relaxed text-[var(--color-text-secondary)]">{activity.description}</p>}{activity.cost != null && <p className="mt-2 text-[10px] font-medium text-amber-400">{Number(activity.cost).toLocaleString('cs-CZ')} {activity.currency}</p>}</div><div className="flex shrink-0 items-start gap-1"><button onClick={() => onUpdate({ status: activity.status === 'done' ? 'planned' : 'done' })} title="Dokončit" className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-green-500/10 hover:text-green-400"><Check size={15} /></button><button onClick={onRemove} title="Odebrat" className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-red-500/10 hover:text-red-400"><Trash2 size={14} /></button></div></article>;
}

function ActivityForm({ form, setForm, onSubmit, onClose, saving }: { form: any; setForm: (value: any) => void; onSubmit: (event: React.FormEvent) => void; onClose: () => void; saving: boolean }) {
    return <form onSubmit={onSubmit} className="rounded-2xl border border-[var(--color-accent)]/40 bg-[var(--color-bg-card)] p-4"><div className="mb-3 flex items-center justify-between"><p className="text-sm font-semibold text-white">Nový blok</p><button type="button" onClick={onClose} className="flex h-9 w-9 items-center justify-center rounded-xl"><X size={16} /></button></div><div className="grid gap-3 sm:grid-cols-2"><label className="text-[10px] text-[var(--color-text-secondary)]">Typ<select value={form.type} onChange={e => setForm({ ...form, type: e.target.value })} className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white">{Object.entries(TYPES).map(([key, value]) => <option key={key} value={key}>{value.icon} {value.label}</option>)}</select></label><label className="text-[10px] text-[var(--color-text-secondary)] sm:col-span-2">Název<input autoFocus required value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} placeholder="Co se bude dít?" className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white" /></label><label className="text-[10px] text-[var(--color-text-secondary)]">Od<input type="time" value={form.starts_at} onChange={e => setForm({ ...form, starts_at: e.target.value })} className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label><label className="text-[10px] text-[var(--color-text-secondary)]">Do<input type="time" value={form.ends_at} onChange={e => setForm({ ...form, ends_at: e.target.value })} className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label><label className="text-[10px] text-[var(--color-text-secondary)]">Místo<input value={form.place_name} onChange={e => setForm({ ...form, place_name: e.target.value })} placeholder="Adresa nebo název" className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label><label className="text-[10px] text-[var(--color-text-secondary)]">Cena<input type="number" min="0" value={form.cost} onChange={e => setForm({ ...form, cost: e.target.value })} placeholder="0" className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label></div><button disabled={saving} className="mt-4 min-h-11 w-full rounded-xl bg-[var(--color-accent)] text-sm font-medium text-white disabled:opacity-40">{saving ? 'Přidávám…' : 'Přidat do dne'}</button></form>;
}
