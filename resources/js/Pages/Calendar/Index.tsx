import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Bell, ChevronLeft, ChevronRight, Film, Image, Plus, Sparkles } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

interface DayData { day: number; total: number; photos: number; videos: number; thumb?: { uuid: string; thumb?: string } | null; }
interface EventItem { uuid: string; title: string; type: string; starts_at: string; ends_at?: string | null; occurrence_start?: string; all_day: boolean; color?: string | null; place_name?: string | null; open_tasks_count?: number; has_conflict?: boolean; }
interface Space { id: number; name: string; }
interface Trip { id: number; name: string; gallery_space_id: number; }

const MONTHS = ['Leden','Únor','Březen','Duben','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
const DAYS = ['Po','Út','St','Čt','Pá','So','Ne'];
const TYPE_LABEL: Record<string, string> = { event: 'Akce', trip: 'Cesta', outing: 'Výlet', birthday: 'Narozeniny', anniversary: 'Výročí', reservation: 'Rezervace', custom: 'Vlastní' };
const localInput = (date = new Date()) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

export default function CalendarIndex() {
    const initial = new Date();
    const [year, setYear] = useState(initial.getFullYear());
    const [month, setMonth] = useState(initial.getMonth() + 1);
    const [days, setDays] = useState<DayData[]>([]);
    const [events, setEvents] = useState<EventItem[]>([]);
    const [spaces, setSpaces] = useState<Space[]>([]);
    const [trips, setTrips] = useState<Trip[]>([]);
    const [selectedDay, setSelectedDay] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    const [showCreate, setShowCreate] = useState(false);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({ title: '', type: 'event', starts_at: localInput(), ends_at: '', place_name: '', gallery_space_id: '', trip_id: '', all_day: false, departure_buffer_minutes: '', reminder: '60' });

    const range = useMemo(() => ({
        from: `${year}-${String(month).padStart(2, '0')}-01`,
        to: new Date(year, month, 0).toISOString().slice(0, 10),
    }), [year, month]);

    const load = async () => {
        setLoading(true); setSelectedDay(null);
        try {
            const [media, planning] = await Promise.all([
                axios.get('/api/v1/timeline/calendar', { params: { year, month } }),
                axios.get('/api/v1/calendar/events', { params: range }),
            ]);
            setDays(media.data.days ?? []); setEvents(planning.data.events ?? []); setSpaces(planning.data.spaces ?? []); setTrips(planning.data.trips ?? []);
            if (!form.gallery_space_id && planning.data.spaces?.[0]) setForm(current => ({ ...current, gallery_space_id: String(planning.data.spaces[0].id) }));
        } catch { setError('Kalendář se nepodařilo načíst. Zkuste to prosím znovu.'); }
        finally { setLoading(false); }
    };
    useEffect(() => { load(); }, [year, month]); // eslint-disable-line react-hooks/exhaustive-deps

    const shift = (direction: number) => {
        const next = new Date(year, month - 1 + direction, 1); setYear(next.getFullYear()); setMonth(next.getMonth() + 1);
    };
    const dayMap = Object.fromEntries(days.map(day => [day.day, day]));
    const eventsByDay = events.reduce<Record<number, EventItem[]>>((acc, event) => {
        const date = new Date(event.occurrence_start ?? event.starts_at);
        if (date.getFullYear() === year && date.getMonth() + 1 === month) (acc[date.getDate()] ??= []).push(event);
        return acc;
    }, {});
    const firstDay = new Date(year, month - 1, 1).getDay(); const leading = firstDay === 0 ? 6 : firstDay - 1;
    const cells: Array<number | null> = [...Array(leading).fill(null), ...Array.from({ length: new Date(year, month, 0).getDate() }, (_, i) => i + 1)];
    while (cells.length % 7) cells.push(null);
    const selectedMedia = selectedDay ? dayMap[selectedDay] : null;
    const selectedEvents = selectedDay ? eventsByDay[selectedDay] ?? [] : [];

    const create = async (event: FormEvent) => {
        event.preventDefault(); setSaving(true); setError('');
        try {
            await axios.post('/api/v1/calendar/events', {
                ...form,
                gallery_space_id: Number(form.gallery_space_id), trip_id: form.trip_id ? Number(form.trip_id) : null,
                ends_at: form.ends_at || null, departure_buffer_minutes: form.departure_buffer_minutes ? Number(form.departure_buffer_minutes) : null,
                reminders: form.reminder ? [{ minutes_before: Number(form.reminder), channel: 'database' }] : [],
            });
            setShowCreate(false); setForm(current => ({ ...current, title: '', place_name: '', trip_id: '', ends_at: '' })); await load();
        } catch (reason: any) { setError(reason.response?.data?.message ?? 'Akci se nepodařilo uložit.'); }
        finally { setSaving(false); }
    };

    const enableLocalNotifications = async () => {
        if (!('Notification' in window)) { setError('Tento prohlížeč nepodporuje oznámení.'); return; }
        const result = await Notification.requestPermission();
        if (result !== 'granted') setError('Oznámení nebyla povolena. Připomínky zůstanou v aplikaci a e-mailu.');
    };

    return <AppLayout><Head title="Kalendář a plánování" />
        <main className="mx-auto max-w-6xl p-4 sm:p-6">
            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div><h1 className="text-xl font-semibold text-white">Kalendář, cesty a společné chvíle</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Akce, rezervace, přípravy i fotky v jednom soukromém přehledu.</p></div>
                <div className="flex gap-2"><button onClick={enableLocalNotifications} className="inline-flex items-center gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm text-[var(--color-text-secondary)] hover:text-white"><Bell size={15}/> Oznámení</button><button onClick={() => setShowCreate(true)} className="inline-flex items-center gap-2 rounded-lg bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"><Plus size={16}/> Nová akce</button></div>
            </div>
            {error && <p role="alert" className="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3 sm:p-5">
                    <div className="mb-4 flex items-center justify-between"><button aria-label="Předchozí měsíc" onClick={() => shift(-1)} className="rounded-lg p-2 hover:bg-white/10"><ChevronLeft size={18}/></button><div className="text-center"><h2 className="font-semibold text-white">{MONTHS[month - 1]} {year}</h2><button onClick={() => { setYear(initial.getFullYear()); setMonth(initial.getMonth() + 1); }} className="text-xs text-[var(--color-accent)]">Dnes</button></div><button aria-label="Další měsíc" onClick={() => shift(1)} className="rounded-lg p-2 hover:bg-white/10"><ChevronRight size={18}/></button></div>
                    <div className="mb-1 grid grid-cols-7">{DAYS.map(day => <div key={day} className="py-1 text-center text-xs font-medium text-[var(--color-text-secondary)]">{day}</div>)}</div>
                    <div className={`grid grid-cols-7 gap-1 ${loading ? 'opacity-50' : ''}`}>{cells.map((day, i) => {
                        if (!day) return <div key={i} className="min-h-18 sm:min-h-24" />;
                        const media = dayMap[day], items = eventsByDay[day] ?? [], today = day === initial.getDate() && month === initial.getMonth() + 1 && year === initial.getFullYear();
                        return <button key={i} onClick={() => setSelectedDay(selectedDay === day ? null : day)} className={`relative min-h-18 overflow-hidden rounded-xl border p-1 text-left sm:min-h-24 ${selectedDay === day ? 'border-[var(--color-accent)] ring-1 ring-[var(--color-accent)]' : 'border-[var(--color-border)] hover:border-white/30'} ${today ? 'ring-1 ring-[var(--color-accent)]' : ''}`}>
                            {media?.thumb?.thumb && <img alt="" src={media.thumb.thumb} className="absolute inset-0 h-full w-full object-cover opacity-15"/>}<span className={`relative text-xs font-semibold ${today ? 'text-[var(--color-accent)]' : 'text-white'}`}>{day}</span>
                            <div className="relative mt-1 space-y-0.5">{items.slice(0, 2).map(item => <span key={`${item.uuid}-${item.occurrence_start ?? ''}`} className="block truncate rounded px-1 text-[9px] text-white" style={{ backgroundColor: item.color ?? '#7567e8' }}>{item.title}</span>)}{items.length > 2 && <span className="text-[9px] text-[var(--color-text-secondary)]">+{items.length - 2} další</span>}</div>
                            {media && <span className="absolute bottom-1 right-1 text-[9px] text-white/80">{media.total} <Image className="inline" size={9}/></span>}
                        </button>;
                    })}</div>
                </section>
                <aside className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                    <h2 className="font-semibold text-white">{selectedDay ? `${selectedDay}. ${MONTHS[month - 1]}` : 'Vyberte den'}</h2>
                    {selectedDay ? <div className="mt-3 space-y-4">
                        {selectedEvents.length > 0 && <div><p className="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Plán</p>{selectedEvents.map(item => <Link key={item.uuid} href={`/calendar/events/${item.uuid}`} className="mb-2 block rounded-lg border border-[var(--color-border)] p-3 hover:border-[var(--color-accent)]"><p className="text-sm font-medium text-white">{item.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{TYPE_LABEL[item.type] ?? 'Akce'}{item.place_name ? ` · ${item.place_name}` : ''}{item.open_tasks_count ? ` · ${item.open_tasks_count} úkolů` : ''}{item.has_conflict ? ' · kolize v plánu' : ''}</p></Link>)}</div>}
                        {selectedMedia && <div><p className="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Vzpomínky</p><p className="text-sm text-[var(--color-text-secondary)]">{selectedMedia.photos} fotek {selectedMedia.videos ? `a ${selectedMedia.videos} videí` : ''}</p><Link href={`/timeline?date=${range.from.slice(0, 8)}${String(selectedDay).padStart(2, '0')}`} className="mt-2 inline-flex text-sm text-[var(--color-accent)]">Zobrazit média →</Link></div>}
                        {!selectedEvents.length && !selectedMedia && <p className="text-sm text-[var(--color-text-secondary)]">Tento den je zatím volný. Můžete jej využít pro společný plán.</p>}
                    </div> : <div className="mt-3 space-y-3 text-sm text-[var(--color-text-secondary)]"><p><Sparkles className="mr-2 inline text-[var(--color-accent)]" size={15}/>Fotky se zobrazují spolu s akcemi.</p><Link href="/travel-inbox" className="block text-[var(--color-accent)]">Cestovní inbox →</Link><Link href="/weekly" className="block text-[var(--color-accent)]">Týdenní společný přehled →</Link></div>}
                </aside>
            </div>
            {showCreate && <div className="fixed inset-0 z-50 flex items-end bg-black/60 p-0 sm:items-center sm:justify-center sm:p-4"><form onSubmit={create} className="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-5 sm:max-w-lg sm:rounded-2xl"><div className="mb-4 flex items-center justify-between"><h2 className="font-semibold text-white">Naplánovat akci</h2><button type="button" onClick={() => setShowCreate(false)} className="text-[var(--color-text-secondary)]">Zavřít</button></div><div className="space-y-3">
                <label className="block text-sm text-[var(--color-text-secondary)]">Název<input required value={form.title} onChange={e => setForm({...form, title:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white" placeholder="Např. Víkend v Brně"/></label>
                <div className="grid grid-cols-2 gap-3"><label className="text-sm text-[var(--color-text-secondary)]">Typ<select value={form.type} onChange={e => setForm({...form,type:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white">{Object.entries(TYPE_LABEL).map(([value,label]) => <option value={value} key={value}>{label}</option>)}</select></label><label className="text-sm text-[var(--color-text-secondary)]">Společný prostor<select required value={form.gallery_space_id} onChange={e => setForm({...form,gallery_space_id:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white">{spaces.map(space => <option key={space.id} value={space.id}>{space.name}</option>)}</select></label></div>
                <label className="block text-sm text-[var(--color-text-secondary)]">Začátek<input required type="datetime-local" value={form.starts_at} onChange={e => setForm({...form,starts_at:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/></label><label className="block text-sm text-[var(--color-text-secondary)]">Konec (volitelné)<input type="datetime-local" value={form.ends_at} onChange={e => setForm({...form,ends_at:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/></label>
                <label className="block text-sm text-[var(--color-text-secondary)]">Místo<input value={form.place_name} onChange={e => setForm({...form,place_name:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white" placeholder="Adresa nebo místo setkání"/></label>
                <div className="grid grid-cols-2 gap-3"><label className="text-sm text-[var(--color-text-secondary)]">Cesta<select value={form.trip_id} onChange={e => setForm({...form,trip_id:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white"><option value="">Bez cesty</option>{trips.filter(trip => !form.gallery_space_id || trip.gallery_space_id === Number(form.gallery_space_id)).map(trip => <option key={trip.id} value={trip.id}>{trip.name}</option>)}</select></label><label className="text-sm text-[var(--color-text-secondary)]">Připomenout<input type="number" min="0" value={form.reminder} onChange={e => setForm({...form,reminder:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/><span className="text-xs">minut předem</span></label></div>
                <label className="flex items-center gap-2 text-sm text-[var(--color-text-secondary)]"><input type="checkbox" checked={form.all_day} onChange={e => setForm({...form,all_day:e.target.checked})}/> Celý den</label>
            </div><div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setShowCreate(false)} className="rounded-lg px-3 py-2 text-sm text-[var(--color-text-secondary)]">Zrušit</button><button disabled={saving} className="rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{saving ? 'Ukládám…' : 'Vytvořit akci'}</button></div></form></div>}
        </main>
    </AppLayout>;
}
