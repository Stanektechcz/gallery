import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Bell, ChevronLeft, ChevronRight, Film, Image, Plus, Sparkles, Upload } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

interface DayData { day: number; total: number; photos: number; videos: number; thumb?: { uuid: string; thumb?: string } | null; }
interface EventItem { uuid: string; title: string; type: string; starts_at: string; ends_at?: string | null; occurrence_start?: string; all_day: boolean; color?: string | null; place_name?: string | null; open_tasks_count?: number; has_conflict?: boolean; }
interface Space { id: number; name: string; }
interface Trip { id: number; name: string; gallery_space_id: number; }
interface Milestone { uuid:string; title:string; icon:string; occurrence_date:string; }

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
    const [milestones, setMilestones] = useState<Milestone[]>([]);
    const [selectedDay, setSelectedDay] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    const [showCreate, setShowCreate] = useState(false);
    const [showImport, setShowImport] = useState(false);
    const [showIdeas, setShowIdeas] = useState(false);
    const [showSharedSlots, setShowSharedSlots] = useState(false);
    const [showAvailability, setShowAvailability] = useState(false);
    const [ideaTheme, setIdeaTheme] = useState('any');
    const [ideas, setIdeas] = useState<any[]>([]);
    const [ideasLoading, setIdeasLoading] = useState(false);
    const [slotsLoading, setSlotsLoading] = useState(false);
    const [sharedSlots, setSharedSlots] = useState<{starts_at:string;ends_at:string;duration_minutes:number}[]>([]);
    const [sharedMemberIds, setSharedMemberIds] = useState<number[]>([]);
    const [availability, setAvailability] = useState<{weekday:number;from:string;to:string}[]>([]);
    const [quietHours, setQuietHours] = useState<{from:string;to:string}|null>(null);
    const [availabilityWeekday, setAvailabilityWeekday] = useState('1');
    const [availabilityFrom, setAvailabilityFrom] = useState('18:00');
    const [availabilityTo, setAvailabilityTo] = useState('21:00');
    const [availabilitySaving, setAvailabilitySaving] = useState(false);
    const [ideaDate, setIdeaDate] = useState(localInput().slice(0, 10));
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [ics, setIcs] = useState('');
    const [importing, setImporting] = useState(false);
    const [importResult, setImportResult] = useState('');
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
            setDays(media.data.days ?? []); setEvents(planning.data.events ?? []); setMilestones(planning.data.milestones ?? []); setSpaces(planning.data.spaces ?? []); setTrips(planning.data.trips ?? []);
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
                participant_ids: sharedMemberIds.length ? sharedMemberIds : undefined,
                reminders: form.reminder ? [{ minutes_before: Number(form.reminder), channel: 'database' }] : [],
            });
            setShowCreate(false); setSharedMemberIds([]); setForm(current => ({ ...current, title: '', place_name: '', trip_id: '', ends_at: '' })); await load();
        } catch (reason: any) { setError(reason.response?.data?.message ?? 'Akci se nepodařilo uložit.'); }
        finally { setSaving(false); }
    };

    const enableLocalNotifications = async () => {
        if (!('Notification' in window)) { setError('Tento prohlížeč nepodporuje oznámení.'); return; }
        const result = await Notification.requestPermission();
        if (result !== 'granted') setError('Oznámení nebyla povolena. Připomínky zůstanou v aplikaci a e-mailu.');
    };

    const importIcs = async (event: FormEvent) => {
        event.preventDefault(); if (!ics.trim() || !form.gallery_space_id) return;
        setImporting(true); setError(''); setImportResult('');
        try {
            const response = await axios.post('/api/v1/calendar/ics-import', { gallery_space_id: Number(form.gallery_space_id), ics });
            const { created, skipped_duplicates: skipped, recurrence_warnings: warnings } = response.data;
            setImportResult(`Importováno: ${created}. Přeskočené duplicity: ${skipped}.${warnings ? ` ${warnings} opakování s limitem výskytů zůstalo jako jednorázová akce.` : ''}`);
            setIcs(''); await load();
        } catch (reason: any) { setError(reason.response?.data?.message ?? 'Kalendář se nepodařilo importovat.'); }
        finally { setImporting(false); }
    };

    const loadIdeas = async (theme = ideaTheme) => {
        if (!form.gallery_space_id) return;
        setIdeasLoading(true); setError('');
        try { const response = await axios.get('/api/v1/calendar/date-ideas', { params: { gallery_space_id: Number(form.gallery_space_id), theme, date: ideaDate } }); setIdeas(response.data.ideas ?? []); }
        catch (reason:any) { setError(reason.response?.data?.message ?? 'Nápady se nepodařilo načíst.'); }
        finally { setIdeasLoading(false); }
    };
    const loadSharedSlots = async () => {
        if (!form.gallery_space_id) return;
        setSlotsLoading(true); setError('');
        try { const response = await axios.get('/api/v1/calendar/shared-slots', { params: { gallery_space_id: Number(form.gallery_space_id), from: ideaDate, duration_minutes: 120 } }); setSharedSlots(response.data.slots ?? []); setSharedMemberIds(response.data.member_ids ?? []); }
        catch (reason:any) { setError(reason.response?.data?.message ?? 'Společné termíny se nepodařilo najít.'); }
        finally { setSlotsLoading(false); }
    };
    const useSharedSlot = (slot:{starts_at:string;ends_at:string}) => {
        setForm(current => ({ ...current, title: current.title || 'Společný čas', type: 'event', starts_at: localInput(new Date(slot.starts_at)), ends_at: localInput(new Date(slot.ends_at)) }));
        setShowSharedSlots(false); setShowCreate(true);
    };
    const toggleAvailability = async () => {
        const next = !showAvailability; setShowAvailability(next); if (!next) return;
        try { const response = await axios.get('/api/v1/calendar/availability'); setAvailability(response.data.availability ?? []); setQuietHours(response.data.quiet_hours ?? null); }
        catch { setError('Dostupnost se nepodařilo načíst.'); }
    };
    const addAvailability = () => {
        if (availabilityFrom >= availabilityTo) { setError('Konec dostupnosti musí být později než začátek.'); return; }
        const weekday = Number(availabilityWeekday);
        setAvailability(current => [...current.filter(item => item.weekday !== weekday), { weekday, from:availabilityFrom, to:availabilityTo }].sort((a,b) => a.weekday - b.weekday));
    };
    const saveAvailability = async () => {
        setAvailabilitySaving(true); setError('');
        try { await axios.put('/api/v1/calendar/availability', { availability, quiet_hours:quietHours }); await loadSharedSlots(); }
        catch (reason:any) { setError(reason.response?.data?.message ?? 'Dostupnost se nepodařilo uložit.'); }
        finally { setAvailabilitySaving(false); }
    };
    const createFromIdea = async (idea:any) => {
        if (!form.gallery_space_id) return;
        setSaving(true); setError('');
        try { await axios.post('/api/v1/calendar/events', { gallery_space_id: Number(form.gallery_space_id), title: idea.title, type: 'outing', starts_at: `${ideaDate}T10:00`, place_name: idea.place_name || idea.title, reminders: form.reminder ? [{ minutes_before: Number(form.reminder), channel: 'database' }] : [] }); setShowIdeas(false); await load(); }
        catch (reason:any) { setError(reason.response?.data?.message ?? 'Akci se nepodařilo vytvořit.'); }
        finally { setSaving(false); }
    };

    return <AppLayout><Head title="Kalendář a plánování" />
        <main className="mx-auto max-w-6xl p-4 sm:p-6">
            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div><h1 className="text-xl font-semibold text-white">Kalendář, cesty a společné chvíle</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Akce, rezervace, přípravy i fotky v jednom soukromém přehledu.</p></div>
                <div className="flex flex-wrap gap-2"><button onClick={enableLocalNotifications} className="inline-flex items-center gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm text-[var(--color-text-secondary)] hover:text-white"><Bell size={15}/> Oznámení</button><button onClick={() => { setImportResult(''); setShowImport(true); }} className="inline-flex items-center gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm text-[var(--color-text-secondary)] hover:text-white"><Upload size={15}/><span className="hidden sm:inline">Import ICS</span></button><button onClick={() => setShowCreate(true)} className="inline-flex items-center gap-2 rounded-lg bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"><Plus size={16}/> Nová akce</button></div>
            </div>
            <div className="mb-4 flex flex-wrap justify-end gap-2"><button onClick={() => { setShowSharedSlots(value => !value); if (!showSharedSlots) loadSharedSlots(); }} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-sky-400/30 bg-sky-500/10 px-3 text-sm text-sky-100 hover:bg-sky-500/20"><Bell size={15}/> Společný termín</button><button onClick={() => { setShowIdeas(value => !value); if (!showIdeas) loadIdeas(); }} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-pink-400/30 bg-pink-500/10 px-3 text-sm text-pink-100 hover:bg-pink-500/20"><Sparkles size={15}/> Nápad pro nás</button></div>
            {error && <p role="alert" className="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}
            {showSharedSlots && <section className="mb-5 rounded-2xl border border-sky-500/25 bg-gradient-to-r from-sky-500/10 to-[var(--color-bg-card)] p-4"><div className="flex items-center justify-between gap-3"><div><h2 className="font-semibold text-white">Kdy máme oba čas?</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Průnik dostupnosti a obsazenosti bez odhalování soukromých akcí.</p></div><input type="date" value={ideaDate} onChange={event => setIdeaDate(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"/></div><div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">{slotsLoading ? <p className="text-sm text-[var(--color-text-secondary)]">Hledám společný čas…</p> : sharedSlots.map(slot => <button key={slot.starts_at} onClick={() => useSharedSlot(slot)} className="rounded-xl border border-sky-400/25 bg-black/10 p-3 text-left hover:border-sky-300"><p className="font-medium text-white">{new Date(slot.starts_at).toLocaleDateString('cs-CZ', { weekday:'short', day:'numeric', month:'numeric' })}</p><p className="mt-1 text-xs text-sky-100">{new Date(slot.starts_at).toLocaleTimeString('cs-CZ',{hour:'2-digit',minute:'2-digit'})}–{new Date(slot.ends_at).toLocaleTimeString('cs-CZ',{hour:'2-digit',minute:'2-digit'})}</p></button>)}{!slotsLoading && !sharedSlots.length && <p className="text-sm text-[var(--color-text-secondary)]">V nejbližších dnech jsme nenašli společné okno. Nastavte si níže dostupnost nebo zkuste jiný den.</p>}</div><div className="mt-4 border-t border-sky-400/20 pt-3"><div className="flex items-center justify-between gap-2"><p className="text-xs font-medium text-sky-100">Moje pravidelná dostupnost</p><button onClick={toggleAvailability} className="text-xs text-sky-200 hover:text-white">{showAvailability ? 'Zavřít' : 'Upravit'}</button></div>{showAvailability && <div className="mt-3 space-y-2"><div className="grid gap-2 sm:grid-cols-4"><select value={availabilityWeekday} onChange={event => setAvailabilityWeekday(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white">{['Ne','Po','Út','St','Čt','Pá','So'].map((day,index) => <option key={day} value={index}>{day}</option>)}</select><input type="time" value={availabilityFrom} onChange={event => setAvailabilityFrom(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"/><input type="time" value={availabilityTo} onChange={event => setAvailabilityTo(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"/><button onClick={addAvailability} className="min-h-10 rounded-lg border border-sky-400/30 px-3 text-sm text-sky-100">Nastavit den</button></div><div className="flex flex-wrap gap-2">{availability.length ? availability.map(item => <button key={item.weekday} onClick={() => setAvailability(current => current.filter(rule => rule.weekday !== item.weekday))} className="rounded-lg bg-black/15 px-2 py-1 text-xs text-sky-100" title="Odebrat">{['Ne','Po','Út','St','Čt','Pá','So'][item.weekday]} {item.from}–{item.to} ×</button>) : <p className="text-xs text-[var(--color-text-secondary)]">Bez uloženého pravidla používáme pro návrhy šetrné večerní okno 18:00–21:00.</p>}</div><button disabled={availabilitySaving} onClick={saveAvailability} className="min-h-10 rounded-lg bg-sky-600 px-3 text-sm text-white disabled:opacity-50">{availabilitySaving ? 'Ukládám…' : 'Uložit a přepočítat termíny'}</button></div>}</div></section>}
            {showIdeas && <section className="mb-5 rounded-2xl border border-pink-500/25 bg-gradient-to-r from-pink-500/10 to-[var(--color-bg-card)] p-4"><div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><h2 className="font-semibold text-white">Nápad na společný čas</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Vychází z vašich uložených míst a osobních preferencí.</p></div><div className="flex flex-wrap gap-2"><input type="date" value={ideaDate} onChange={event => setIdeaDate(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"/><select value={ideaTheme} onChange={event => { setIdeaTheme(event.target.value); loadIdeas(event.target.value); }} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"><option value="any">Cokoliv</option><option value="rain">Na déšť</option><option value="photo">Fotogenické</option><option value="budget">Do rozpočtu</option><option value="early">Brzy ráno</option></select></div></div><div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{ideasLoading ? <p className="text-sm text-[var(--color-text-secondary)]">Hledám ve vašich místech…</p> : ideas.map(idea => <article key={idea.id} className="rounded-xl border border-[var(--color-border)] bg-black/10 p-3"><p className="font-medium text-white">{idea.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{idea.place_name || idea.reason}</p>{idea.place_name && <p className="mt-1 text-xs text-pink-200">{idea.reason}</p>}<button disabled={saving} onClick={() => createFromIdea(idea)} className="mt-3 min-h-9 w-full rounded-lg border border-pink-400/30 text-xs text-pink-100 hover:bg-pink-500/10 disabled:opacity-40">Přidat do kalendáře</button></article>)}{!ideasLoading && !ideas.length && <p className="text-sm text-[var(--color-text-secondary)]">Pro tento filtr zatím nemáte uložené místo. Upravte preference u místa a návrh se zde objeví.</p>}</div></section>}
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
            {milestones.length > 0 && <section className="mt-4 rounded-2xl border border-pink-500/20 bg-pink-500/5 p-4"><h2 className="font-semibold text-white">Výročí v tomto období</h2><div className="mt-3 flex flex-wrap gap-2">{milestones.map(item => <span key={`${item.uuid}-${item.occurrence_date}`} className="rounded-lg bg-black/10 px-3 py-2 text-sm text-pink-100">{item.icon} {item.title} · {new Date(`${item.occurrence_date}T12:00:00`).toLocaleDateString('cs-CZ')}</span>)}</div></section>}
            {showCreate && <div className="fixed inset-0 z-50 flex items-end bg-black/60 p-0 sm:items-center sm:justify-center sm:p-4"><form onSubmit={create} className="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-5 sm:max-w-lg sm:rounded-2xl"><div className="mb-4 flex items-center justify-between"><h2 className="font-semibold text-white">Naplánovat akci</h2><button type="button" onClick={() => setShowCreate(false)} className="text-[var(--color-text-secondary)]">Zavřít</button></div><div className="space-y-3">
                <label className="block text-sm text-[var(--color-text-secondary)]">Název<input required value={form.title} onChange={e => setForm({...form, title:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white" placeholder="Např. Víkend v Brně"/></label>
                <div className="grid grid-cols-2 gap-3"><label className="text-sm text-[var(--color-text-secondary)]">Typ<select value={form.type} onChange={e => setForm({...form,type:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white">{Object.entries(TYPE_LABEL).map(([value,label]) => <option value={value} key={value}>{label}</option>)}</select></label><label className="text-sm text-[var(--color-text-secondary)]">Společný prostor<select required value={form.gallery_space_id} onChange={e => setForm({...form,gallery_space_id:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white">{spaces.map(space => <option key={space.id} value={space.id}>{space.name}</option>)}</select></label></div>
                <label className="block text-sm text-[var(--color-text-secondary)]">Začátek<input required type="datetime-local" value={form.starts_at} onChange={e => setForm({...form,starts_at:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/></label><label className="block text-sm text-[var(--color-text-secondary)]">Konec (volitelné)<input type="datetime-local" value={form.ends_at} onChange={e => setForm({...form,ends_at:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/></label>
                <label className="block text-sm text-[var(--color-text-secondary)]">Místo<input value={form.place_name} onChange={e => setForm({...form,place_name:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white" placeholder="Adresa nebo místo setkání"/></label>
                <div className="grid grid-cols-2 gap-3"><label className="text-sm text-[var(--color-text-secondary)]">Cesta<select value={form.trip_id} onChange={e => setForm({...form,trip_id:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white"><option value="">Bez cesty</option>{trips.filter(trip => !form.gallery_space_id || trip.gallery_space_id === Number(form.gallery_space_id)).map(trip => <option key={trip.id} value={trip.id}>{trip.name}</option>)}</select></label><label className="text-sm text-[var(--color-text-secondary)]">Připomenout<input type="number" min="0" value={form.reminder} onChange={e => setForm({...form,reminder:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/><span className="text-xs">minut předem</span></label></div>
                <label className="flex items-center gap-2 text-sm text-[var(--color-text-secondary)]"><input type="checkbox" checked={form.all_day} onChange={e => setForm({...form,all_day:e.target.checked})}/> Celý den</label>
            </div><div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setShowCreate(false)} className="rounded-lg px-3 py-2 text-sm text-[var(--color-text-secondary)]">Zrušit</button><button disabled={saving} className="rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{saving ? 'Ukládám…' : 'Vytvořit akci'}</button></div></form></div>}
            {showImport && <div className="fixed inset-0 z-50 flex items-end bg-black/60 p-0 sm:items-center sm:justify-center sm:p-4"><form onSubmit={importIcs} className="w-full rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-5 sm:max-w-lg sm:rounded-2xl"><div className="flex items-center justify-between"><div><h2 className="font-semibold text-white">Import kalendáře ICS</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Vložte obsah souboru .ics. Data zůstávají jen ve vašem společném prostoru.</p></div><button type="button" onClick={() => setShowImport(false)} className="text-sm text-[var(--color-text-secondary)]">Zavřít</button></div><label className="mt-4 block text-sm text-[var(--color-text-secondary)]">Společný prostor<select required value={form.gallery_space_id} onChange={e => setForm({...form,gallery_space_id:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white">{spaces.map(space => <option key={space.id} value={space.id}>{space.name}</option>)}</select></label><textarea required value={ics} onChange={e => setIcs(e.target.value)} placeholder={'BEGIN:VCALENDAR\nBEGIN:VEVENT\nSUMMARY:…'} rows={10} className="mt-3 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-3 font-mono text-xs text-white placeholder-[var(--color-text-secondary)]"/>{importResult && <p className="mt-3 rounded-lg bg-green-500/10 p-3 text-sm text-green-200">{importResult}</p>}<div className="mt-4 flex justify-end gap-2"><button type="button" onClick={() => setShowImport(false)} className="rounded-lg px-3 py-2 text-sm text-[var(--color-text-secondary)]">Zrušit</button><button disabled={importing || !ics.trim()} className="rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{importing ? 'Importuji…' : 'Importovat'}</button></div></form></div>}
        </main>
    </AppLayout>;
}
