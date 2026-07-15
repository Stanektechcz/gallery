import AppLayout from '@/Layouts/AppLayout';
import LocationPicker, { LocationValue } from '@/Components/LocationPicker';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Bell, CalendarDays, ChevronLeft, ChevronRight, Film, Image, MapPin, Plus, Route, Save, Sparkles, Upload } from 'lucide-react';
import { ChangeEvent, FormEvent, useEffect, useMemo, useState } from 'react';

interface DayData { day: number; total: number; photos: number; videos: number; thumb?: { uuid: string; thumb?: string } | null; }
interface EventItem { uuid: string; title: string; type: string; starts_at: string; ends_at?: string | null; occurrence_start?: string; all_day: boolean; color?: string | null; place_name?: string | null; open_tasks_count?: number; has_conflict?: boolean; }
interface Space { id: number; name: string; }
interface Trip { id: number; name: string; gallery_space_id: number; }
interface Milestone { uuid:string; title:string; icon:string; occurrence_date:string; kind?:string; person_name?:string|null; relationship?:string|null; is_highlighted?:boolean; }
interface NameDay { id:string; date:string; name:string; official_name:string; title:string; icon:string; is_highlighted:boolean; }
interface Holiday { date:string; title:string; weekday_label:string; is_weekend:boolean; source:string; }
interface HolidayOpportunity { id:string; title:string; start_date:string; end_date:string; duration_days:number; leave_days:string[]; leave_days_count:number; holiday_dates:string[]; holiday_titles:string[]; }

const MONTHS = ['Leden','Únor','Březen','Duben','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
const DAYS = ['Po','Út','St','Čt','Pá','So','Ne'];
const TYPE_LABEL: Record<string, string> = { event: 'Akce', trip: 'Cesta', outing: 'Výlet', birthday: 'Narozeniny', anniversary: 'Výročí', reservation: 'Rezervace', custom: 'Vlastní' };
const localInput = (date = new Date()) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const EVENT_COLORS = ['#7567e8', '#db2777', '#e11d48', '#ea580c', '#ca8a04', '#16a34a', '#0891b2', '#2563eb', '#7c3aed'];
const dayCountLabel = (count:number) => `${count} ${count === 1 ? 'den' : count >= 2 && count <= 4 ? 'dny' : 'dní'}`;
const normalizeEventColor = (value: string) => {
    const hex = value.trim();
    if (/^#[0-9a-f]{6}$/i.test(hex)) return hex;
    const rgb = hex.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i);
    if (!rgb || rgb.slice(1).some(component => Number(component) > 255)) return '';
    return `#${rgb.slice(1).map(component => Number(component).toString(16).padStart(2, '0')).join('')}`;
};

export default function CalendarIndex() {
    const initial = new Date();
    const [year, setYear] = useState(initial.getFullYear());
    const [month, setMonth] = useState(initial.getMonth() + 1);
    const [days, setDays] = useState<DayData[]>([]);
    const [events, setEvents] = useState<EventItem[]>([]);
    const [spaces, setSpaces] = useState<Space[]>([]);
    const [trips, setTrips] = useState<Trip[]>([]);
    const [milestones, setMilestones] = useState<Milestone[]>([]);
    const [holidays, setHolidays] = useState<Holiday[]>([]);
    const [nameDays, setNameDays] = useState<NameDay[]>([]);
    const [holidayOpportunities, setHolidayOpportunities] = useState<HolidayOpportunity[]>([]);
    const [holidayPlanning, setHolidayPlanning] = useState('');
    const [plannedHoliday, setPlannedHoliday] = useState<{uuid:string;trip_id:number;title:string}|null>(null);
    const [plannedIdea, setPlannedIdea] = useState<{event_uuid:string;title:string}|null>(null);
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
    const [icsFileName, setIcsFileName] = useState('');
    const [icsShare, setIcsShare] = useState(true);
    const [icsCreateTrips, setIcsCreateTrips] = useState(false);
    const [icsReminder, setIcsReminder] = useState('60');
    const [importing, setImporting] = useState(false);
    const [importResult, setImportResult] = useState('');
    const [dayNote, setDayNote] = useState('');
    const [dayNoteLoading, setDayNoteLoading] = useState(false);
    const [dayNoteSaving, setDayNoteSaving] = useState(false);
    const [dayNoteMessage, setDayNoteMessage] = useState('');
    const [form, setForm] = useState({ title: '', type: 'event', starts_at: localInput(), ends_at: '', place_name: '', latitude: '' as number|'', longitude: '' as number|'', color: '#7567e8', gallery_space_id: '', trip_id: '', create_trip: false, all_day: false, departure_buffer_minutes: '', reminder: '60' });

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
            setDays(media.data.days ?? []); setEvents(planning.data.events ?? []); setMilestones(planning.data.milestones ?? []); setHolidays(planning.data.holidays ?? []); setNameDays(planning.data.name_days ?? []); setHolidayOpportunities(planning.data.holiday_opportunities ?? []); setSpaces(planning.data.spaces ?? []); setTrips(planning.data.trips ?? []);
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
    const selectedDate = selectedDay ? `${year}-${String(month).padStart(2, '0')}-${String(selectedDay).padStart(2, '0')}` : null;
    const holidayMap = Object.fromEntries(holidays.map(holiday => [Number(holiday.date.slice(8, 10)), holiday]));
    const nameDayMap = nameDays.reduce<Record<number,NameDay[]>>((map,item)=>{(map[Number(item.date.slice(8,10))]??=[]).push(item);return map;},{});
    const milestoneMap = milestones.reduce<Record<number,Milestone[]>>((map,item)=>{(map[Number(item.occurrence_date.slice(8,10))]??=[]).push(item);return map;},{});
    const selectedHoliday = selectedDay ? holidayMap[selectedDay] ?? null : null;
    const selectedNameDays = selectedDay ? nameDayMap[selectedDay] ?? [] : [];
    const selectedMilestones = selectedDay ? milestoneMap[selectedDay] ?? [] : [];
    const selectedHolidayOpportunity = selectedDate ? holidayOpportunities.find(item => selectedDate >= item.start_date && selectedDate <= item.end_date) ?? null : null;

    useEffect(() => {
        if (!selectedDate || !form.gallery_space_id) { setDayNote(''); setDayNoteMessage(''); return; }
        let active = true; setDayNoteLoading(true); setDayNoteMessage('');
        axios.get('/api/v1/calendar/day-note', { params: { gallery_space_id: Number(form.gallery_space_id), date: selectedDate } })
            .then(response => { if (active) setDayNote(response.data?.content ?? ''); })
            .catch(() => { if (active) setDayNoteMessage('Poznámku k tomuto dni se nepodařilo načíst.'); })
            .finally(() => { if (active) setDayNoteLoading(false); });
        return () => { active = false; };
    }, [selectedDate, form.gallery_space_id]);

    const saveDayNote = async () => {
        if (!selectedDate || !form.gallery_space_id) return;
        setDayNoteSaving(true); setDayNoteMessage('');
        try {
            await axios.put('/api/v1/calendar/day-note', { gallery_space_id: Number(form.gallery_space_id), date: selectedDate, content: dayNote });
            setDayNoteMessage(dayNote.trim() ? 'Společná poznámka je uložená a šifrovaná.' : 'Společná poznámka byla odstraněna.');
        } catch (reason:any) { setDayNoteMessage(reason.response?.data?.message ?? 'Poznámku se nepodařilo uložit.'); }
        finally { setDayNoteSaving(false); }
    };

    const create = async (event: FormEvent) => {
        event.preventDefault(); setSaving(true); setError('');
        try {
            await axios.post('/api/v1/calendar/events', {
                ...form, color: normalizeEventColor(form.color) || '#7567e8',
                gallery_space_id: Number(form.gallery_space_id), trip_id: form.trip_id ? Number(form.trip_id) : null,
                ends_at: form.ends_at || null, departure_buffer_minutes: form.departure_buffer_minutes ? Number(form.departure_buffer_minutes) : null,
                participant_ids: sharedMemberIds.length ? sharedMemberIds : undefined,
                reminders: form.reminder ? [{ minutes_before: Number(form.reminder), channel: 'database' }] : [],
            });
            setShowCreate(false); setSharedMemberIds([]); setForm(current => ({ ...current, title: '', place_name: '', latitude: '', longitude: '', trip_id: '', create_trip: false, ends_at: '' })); await load();
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
            const response = await axios.post('/api/v1/calendar/ics-import', {
                gallery_space_id: Number(form.gallery_space_id), ics,
                share_with_space: icsShare, create_trips: icsCreateTrips,
                reminder_minutes: icsReminder === '' ? null : Number(icsReminder),
            });
            const { created, skipped_duplicates: skipped, recurrence_warnings: warnings, trips_created: tripCount, reminders_created: reminderCount } = response.data;
            setImportResult(`Importováno: ${created}. Přeskočené duplicity: ${skipped}.${tripCount ? ` Vytvořené cesty: ${tripCount}.` : ''}${reminderCount ? ` Připomínky: ${reminderCount}.` : ''}${warnings ? ` ${warnings} opakování s limitem výskytů zůstalo jako jednorázová akce.` : ''}`);
            setIcs(''); setIcsFileName(''); await load();
        } catch (reason: any) { setError(reason.response?.data?.message ?? 'Kalendář se nepodařilo importovat.'); }
        finally { setImporting(false); }
    };
    const loadIcsFile = async (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (!file) return;
        if (file.size > 524288) { setError('Soubor ICS může mít maximálně 512 KB.'); event.target.value = ''; return; }
        try { setIcs(await file.text()); setIcsFileName(file.name); setImportResult(''); setError(''); }
        catch { setError('Soubor ICS se nepodařilo přečíst.'); }
    };
    const planHoliday = async (opportunity: HolidayOpportunity) => {
        if (!form.gallery_space_id) { setError('Nejdříve vyberte společný prostor.'); return; }
        setHolidayPlanning(opportunity.id); setError('');
        try {
            const response = await axios.post('/api/v1/calendar/holiday-plan', {
                gallery_space_id: Number(form.gallery_space_id),
                start_date: opportunity.start_date,
                end_date: opportunity.end_date,
            });
            setPlannedHoliday({ uuid:response.data.uuid, trip_id:response.data.trip_id, title:response.data.title });
            await load();
        } catch (reason:any) { setError(reason.response?.data?.message ?? 'Sváteční výlet se nepodařilo naplánovat.'); }
        finally { setHolidayPlanning(''); }
    };

    const loadIdeas = async (theme = ideaTheme, date = ideaDate) => {
        if (!form.gallery_space_id) return;
        setIdeasLoading(true); setError('');
        try { const response = await axios.get('/api/v1/calendar/date-ideas', { params: { gallery_space_id: Number(form.gallery_space_id), theme, date } }); setIdeas(response.data.ideas ?? []); }
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
        try {
            const response = await axios.post(`/api/v1/places/${idea.id}/plans`, {
                starts_at: idea.suggested_starts_at ?? `${ideaDate}T10:00`,
                duration_minutes: idea.estimated_visit_minutes ?? 120,
                reminder_minutes: form.reminder ? Number(form.reminder) : 1440,
                from_recommendation: true,
                recommendation_reason: idea.reason,
                recommended_item: idea.top_item?.name ?? null,
                notes: idea.next_time_note ?? null,
            });
            setPlannedIdea({ event_uuid: response.data.event_uuid, title: idea.title });
            setShowIdeas(false); await load();
        }
        catch (reason:any) { setError(reason.response?.data?.message ?? 'Akci se nepodařilo vytvořit.'); }
        finally { setSaving(false); }
    };

    return <AppLayout><Head title="Kalendář a plánování" />
        <main className="mx-auto max-w-6xl p-4 sm:p-6">
            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div><h1 className="text-xl font-semibold text-white">Kalendář, cesty a společné chvíle</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Akce, rezervace, přípravy i fotky v jednom soukromém přehledu.</p></div>
                <div className="flex flex-wrap gap-2"><button onClick={enableLocalNotifications} className="inline-flex items-center gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm text-[var(--color-text-secondary)] hover:text-white"><Bell size={15}/> Oznámení</button><button onClick={() => { setImportResult(''); setShowImport(true); }} className="inline-flex items-center gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm text-[var(--color-text-secondary)] hover:text-white"><Upload size={15}/><span className="hidden sm:inline">Import ICS</span></button><button onClick={() => setShowCreate(true)} className="inline-flex items-center gap-2 rounded-lg bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"><Plus size={16}/> Nová akce</button></div>
            </div>
            <div className="mb-4 flex flex-wrap justify-end gap-2"><button onClick={() => { setShowSharedSlots(value => !value); if (!showSharedSlots) loadSharedSlots(); }} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-sky-400/30 bg-sky-500/10 px-3 text-sm text-sky-100 hover:bg-sky-500/20"><Bell size={15}/> Společný termín</button><button onClick={() => { setShowIdeas(value => !value); if (!showIdeas) loadIdeas(); }} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-orange-400/30 bg-orange-500/10 px-3 text-sm text-orange-100 hover:bg-orange-500/20"><MapPin size={15}/> Tip z uložených míst</button><Link href="/date-ideas" className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-pink-400/30 bg-pink-500/10 px-3 text-sm text-pink-100 hover:bg-pink-500/20"><Sparkles size={15}/> Generátor randíček</Link></div>
            {error && <p role="alert" className="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}
            {plannedHoliday && <div className="mb-4 flex flex-col gap-3 rounded-xl border border-emerald-400/30 bg-emerald-500/10 p-3 text-sm text-emerald-100 sm:flex-row sm:items-center"><span className="mr-auto">„{plannedHoliday.title}“ je společně v kalendáři i itineráři.</span><Link href={`/calendar/events/${plannedHoliday.uuid}`} className="rounded-lg border border-emerald-300/30 px-3 py-2 text-center">Detail akce</Link><Link href={`/trips/${plannedHoliday.trip_id}/plan`} className="rounded-lg bg-emerald-600 px-3 py-2 text-center text-white">Naplánovat cestu</Link></div>}
            {plannedIdea && <div className="mb-4 flex flex-col gap-3 rounded-xl border border-emerald-400/30 bg-emerald-500/10 p-3 text-sm text-emerald-100 sm:flex-row sm:items-center"><span className="mr-auto">„{plannedIdea.title}“ je v kalendáři, plánu návštěv i připomínkách pro oba.</span><Link href={`/calendar/events/${plannedIdea.event_uuid}`} className="rounded-lg border border-emerald-300/30 px-3 py-2 text-center">Otevřít společný plán</Link></div>}
            {holidayOpportunities.length > 0 && <section className="mb-5 rounded-2xl border border-red-400/20 bg-gradient-to-r from-red-500/10 to-[var(--color-bg-card)] p-4">
                <div className="flex items-start gap-3"><div className="rounded-xl bg-red-500/15 p-2 text-red-200"><CalendarDays size={19}/></div><div><h2 className="font-semibold text-white">Sváteční okna pro společný výlet</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">České dny pracovního klidu jsou přímo v kalendáři. Jedním krokem z nich vznikne společná akce, cesta, itinerář i připomínky.</p></div></div>
                <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">{holidayOpportunities.map(opportunity => <article key={opportunity.id} className="rounded-xl border border-red-300/15 bg-black/10 p-3"><p className="text-sm font-medium text-white">{opportunity.title}</p><p className="mt-1 text-xs text-red-100">{new Date(`${opportunity.start_date}T12:00:00`).toLocaleDateString('cs-CZ',{day:'numeric',month:'numeric'})}–{new Date(`${opportunity.end_date}T12:00:00`).toLocaleDateString('cs-CZ',{day:'numeric',month:'numeric'})} · {dayCountLabel(opportunity.duration_days)}</p><p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">{opportunity.leave_days_count ? `${dayCountLabel(opportunity.leave_days_count)} dovolené: ${opportunity.leave_days.map(date => new Date(`${date}T12:00:00`).toLocaleDateString('cs-CZ',{day:'numeric',month:'numeric'})).join(', ')}` : 'Bez čerpání dovolené'}</p><button type="button" disabled={Boolean(holidayPlanning)} onClick={() => planHoliday(opportunity)} className="mt-3 inline-flex min-h-9 w-full items-center justify-center gap-2 rounded-lg border border-red-300/30 text-xs text-red-100 hover:bg-red-500/10 disabled:opacity-40"><Route size={14}/>{holidayPlanning === opportunity.id ? 'Zakládám cestu…' : 'Naplánovat pro nás'}</button></article>)}</div>
            </section>}
            {showSharedSlots && <section className="mb-5 rounded-2xl border border-sky-500/25 bg-gradient-to-r from-sky-500/10 to-[var(--color-bg-card)] p-4"><div className="flex items-center justify-between gap-3"><div><h2 className="font-semibold text-white">Kdy máme oba čas?</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Průnik dostupnosti a obsazenosti bez odhalování soukromých akcí.</p></div><input type="date" value={ideaDate} onChange={event => setIdeaDate(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"/></div><div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">{slotsLoading ? <p className="text-sm text-[var(--color-text-secondary)]">Hledám společný čas…</p> : sharedSlots.map(slot => <button key={slot.starts_at} onClick={() => useSharedSlot(slot)} className="rounded-xl border border-sky-400/25 bg-black/10 p-3 text-left hover:border-sky-300"><p className="font-medium text-white">{new Date(slot.starts_at).toLocaleDateString('cs-CZ', { weekday:'short', day:'numeric', month:'numeric' })}</p><p className="mt-1 text-xs text-sky-100">{new Date(slot.starts_at).toLocaleTimeString('cs-CZ',{hour:'2-digit',minute:'2-digit'})}–{new Date(slot.ends_at).toLocaleTimeString('cs-CZ',{hour:'2-digit',minute:'2-digit'})}</p></button>)}{!slotsLoading && !sharedSlots.length && <p className="text-sm text-[var(--color-text-secondary)]">V nejbližších dnech jsme nenašli společné okno. Nastavte si níže dostupnost nebo zkuste jiný den.</p>}</div><div className="mt-4 border-t border-sky-400/20 pt-3"><div className="flex items-center justify-between gap-2"><p className="text-xs font-medium text-sky-100">Moje pravidelná dostupnost</p><button onClick={toggleAvailability} className="text-xs text-sky-200 hover:text-white">{showAvailability ? 'Zavřít' : 'Upravit'}</button></div>{showAvailability && <div className="mt-3 space-y-2"><div className="grid gap-2 sm:grid-cols-4"><select value={availabilityWeekday} onChange={event => setAvailabilityWeekday(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white">{['Ne','Po','Út','St','Čt','Pá','So'].map((day,index) => <option key={day} value={index}>{day}</option>)}</select><input type="time" value={availabilityFrom} onChange={event => setAvailabilityFrom(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"/><input type="time" value={availabilityTo} onChange={event => setAvailabilityTo(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"/><button onClick={addAvailability} className="min-h-10 rounded-lg border border-sky-400/30 px-3 text-sm text-sky-100">Nastavit den</button></div><div className="flex flex-wrap gap-2">{availability.length ? availability.map(item => <button key={item.weekday} onClick={() => setAvailability(current => current.filter(rule => rule.weekday !== item.weekday))} className="rounded-lg bg-black/15 px-2 py-1 text-xs text-sky-100" title="Odebrat">{['Ne','Po','Út','St','Čt','Pá','So'][item.weekday]} {item.from}–{item.to} ×</button>) : <p className="text-xs text-[var(--color-text-secondary)]">Bez uloženého pravidla používáme pro návrhy šetrné večerní okno 18:00–21:00.</p>}</div><button disabled={availabilitySaving} onClick={saveAvailability} className="min-h-10 rounded-lg bg-sky-600 px-3 text-sm text-white disabled:opacity-50">{availabilitySaving ? 'Ukládám…' : 'Uložit a přepočítat termíny'}</button></div>}</div></section>}
            {showIdeas && <section className="mb-5 rounded-2xl border border-pink-500/25 bg-gradient-to-r from-pink-500/10 to-[var(--color-bg-card)] p-4"><div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><h2 className="font-semibold text-white">Nápad na společný čas</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Vysvětlitelný výběr z vašich hodnocení, přání, návštěv a cenových preferencí.</p></div><div className="flex flex-wrap gap-2"><input type="date" value={ideaDate} onChange={event => { setIdeaDate(event.target.value); loadIdeas(ideaTheme, event.target.value); }} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"/><select value={ideaTheme} onChange={event => { setIdeaTheme(event.target.value); loadIdeas(event.target.value); }} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-sm text-white"><option value="any">Cokoliv</option><option value="rain">Na déšť</option><option value="photo">Fotogenické</option><option value="budget">Do rozpočtu</option><option value="early">Brzy ráno</option></select></div></div><div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{ideasLoading ? <p className="text-sm text-[var(--color-text-secondary)]">Vyhodnocuji vaše společné zkušenosti…</p> : ideas.map(idea => <article key={idea.id} className="rounded-xl border border-[var(--color-border)] bg-black/10 p-3"><div className="flex items-start justify-between gap-2"><p className="font-medium text-white">{idea.title}</p><span className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] ${idea.kind==='return'?'bg-emerald-500/10 text-emerald-200':'bg-sky-500/10 text-sky-200'}`}>{idea.kind==='return'?'návrat':'objevit'}</span></div><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{idea.place_name || 'Vaše uložené místo'}{idea.price_level ? ` · ${'€'.repeat(idea.price_level)}` : ''}</p><p className="mt-1 text-xs text-pink-200">{idea.reason}</p>{idea.next_time_note && <p className="mt-2 rounded-lg bg-black/10 px-2 py-1.5 text-[10px] text-amber-100">Příště: {idea.next_time_note}</p>}<p className="mt-2 text-[10px] text-[var(--color-text-secondary)]">Návrh: {new Date(idea.suggested_starts_at).toLocaleString('cs-CZ',{weekday:'short',day:'numeric',month:'numeric',hour:'2-digit',minute:'2-digit'})}{idea.review_average ? ` · ★ ${idea.review_average}` : ''}</p><button disabled={saving} onClick={() => createFromIdea(idea)} className="mt-3 min-h-9 w-full rounded-lg border border-pink-400/30 text-xs text-pink-100 hover:bg-pink-500/10 disabled:opacity-40">Naplánovat pro oba</button></article>)}{!ideasLoading && !ideas.length && <p className="text-sm text-[var(--color-text-secondary)]">Pro tento filtr zatím nemáte vhodné nenaplánované místo. Přidejte místo nebo upravte jeho preference.</p>}</div></section>}
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3 sm:p-5">
                    <div className="mb-4 flex items-center justify-between"><button aria-label="Předchozí měsíc" onClick={() => shift(-1)} className="rounded-lg p-2 hover:bg-white/10"><ChevronLeft size={18}/></button><div className="text-center"><h2 className="font-semibold text-white">{MONTHS[month - 1]} {year}</h2><button onClick={() => { setYear(initial.getFullYear()); setMonth(initial.getMonth() + 1); }} className="text-xs text-[var(--color-accent)]">Dnes</button></div><button aria-label="Další měsíc" onClick={() => shift(1)} className="rounded-lg p-2 hover:bg-white/10"><ChevronRight size={18}/></button></div>
                    <div className="mb-1 grid grid-cols-7">{DAYS.map(day => <div key={day} className="py-1 text-center text-xs font-medium text-[var(--color-text-secondary)]">{day}</div>)}</div>
                    <div className={`grid grid-cols-7 gap-1 ${loading ? 'opacity-50' : ''}`}>{cells.map((day, i) => {
                        if (!day) return <div key={i} className="min-h-18 sm:min-h-24" />;
                        const media = dayMap[day], items = eventsByDay[day] ?? [], holiday = holidayMap[day], personalDays = [...(nameDayMap[day]??[]),...(milestoneMap[day]??[])], today = day === initial.getDate() && month === initial.getMonth() + 1 && year === initial.getFullYear();
                        return <button key={i} onClick={() => setSelectedDay(selectedDay === day ? null : day)} className={`relative min-h-18 overflow-hidden rounded-xl border p-1 text-left sm:min-h-24 ${selectedDay === day ? 'border-[var(--color-accent)] ring-1 ring-[var(--color-accent)]' : 'border-[var(--color-border)] hover:border-white/30'} ${today ? 'ring-1 ring-[var(--color-accent)]' : ''}`}>
                            {media?.thumb?.thumb && <img alt="" src={media.thumb.thumb} className="absolute inset-0 h-full w-full object-cover opacity-15"/>}<span className={`relative text-xs font-semibold ${today ? 'text-[var(--color-accent)]' : 'text-white'}`}>{day}</span>
                            <div className="relative mt-1 space-y-0.5">{holiday && <span title={holiday.title} className="block truncate rounded bg-red-500/25 px-1 text-[9px] text-red-100">🇨🇿 {holiday.title}</span>}{personalDays.slice(0,1).map((item:any)=><span key={item.id??item.uuid} title={item.title} className="block truncate rounded bg-amber-500/25 px-1 text-[9px] text-amber-100">{item.icon} {item.name??item.person_name??item.title}</span>)}{items.slice(0, personalDays.length||holiday ? 1 : 2).map(item => <span key={`${item.uuid}-${item.occurrence_start ?? ''}`} className="block truncate rounded px-1 text-[9px] text-white" style={{ backgroundColor: item.color ?? '#7567e8' }}>{item.title}</span>)}{items.length > (personalDays.length||holiday ? 1 : 2) && <span className="text-[9px] text-[var(--color-text-secondary)]">+{items.length - (personalDays.length||holiday ? 1 : 2)} další</span>}</div>
                            {media && <span className="absolute bottom-1 right-1 text-[9px] text-white/80">{media.total} <Image className="inline" size={9}/></span>}
                        </button>;
                    })}</div>
                </section>
                <aside className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                    <h2 className="font-semibold text-white">{selectedDay ? `${selectedDay}. ${MONTHS[month - 1]}` : 'Vyberte den'}</h2>
                    {selectedDay ? <div className="mt-3 space-y-4">
                        {selectedHoliday && <section className="rounded-xl border border-red-400/25 bg-red-500/10 p-3"><p className="text-xs font-medium text-red-100">🇨🇿 Český svátek · {selectedHoliday.weekday_label}</p><p className="mt-1 text-sm font-medium text-white">{selectedHoliday.title}</p>{selectedHolidayOpportunity && <button type="button" disabled={Boolean(holidayPlanning)} onClick={() => planHoliday(selectedHolidayOpportunity)} className="mt-3 inline-flex min-h-9 w-full items-center justify-center gap-2 rounded-lg border border-red-300/30 text-xs text-red-100 disabled:opacity-40"><Route size={14}/>Naplánovat společné volno ({dayCountLabel(selectedHolidayOpportunity.duration_days)})</button>}</section>}
                        {selectedNameDays.map(item=><section key={item.id} className="rounded-xl border border-amber-300/25 bg-amber-500/10 p-3"><p className="text-xs font-medium text-amber-100">🎈 Zvýrazněný svátek</p><p className="mt-1 text-sm font-medium text-white">{item.name} <span className="text-xs font-normal text-[var(--color-text-secondary)]">({item.official_name})</span></p></section>)}
                        {selectedMilestones.map(item=><section key={item.uuid} className="rounded-xl border border-pink-300/20 bg-pink-500/10 p-3"><p className="text-xs font-medium text-pink-100">{item.kind==='birthday'?'Narozeniny blízkého':'Společné výročí'}</p><p className="mt-1 text-sm font-medium text-white">{item.icon} {item.title}</p></section>)}
                        <section className="rounded-xl border border-pink-400/20 bg-pink-500/5 p-3"><div className="flex items-start justify-between gap-2"><div><p className="text-xs font-medium text-pink-100">Společná poznámka dne</p><p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">Soukromý vzkaz, nákupní seznam nebo detail k vašemu společnému plánu.</p></div>{dayNoteLoading && <span className="text-[10px] text-[var(--color-text-secondary)]">Načítám…</span>}</div><textarea value={dayNote} onChange={event => setDayNote(event.target.value)} maxLength={10000} rows={3} placeholder="Co je pro tento den důležité?" className="mt-3 w-full resize-none rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-xs text-white placeholder-[var(--color-text-secondary)]"/><button type="button" disabled={dayNoteSaving || dayNoteLoading} onClick={saveDayNote} className="mt-2 inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-pink-300/35 px-3 text-xs text-pink-100 disabled:opacity-50"><Save size={13}/>{dayNoteSaving ? 'Ukládám…' : 'Uložit pro nás'}</button>{dayNoteMessage && <p className={`mt-2 text-[10px] ${dayNoteMessage.startsWith('Společná') ? 'text-emerald-200' : 'text-red-200'}`}>{dayNoteMessage}</p>}</section>
                        {selectedEvents.length > 0 && <div><p className="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Plán</p>{selectedEvents.map(item => <Link key={item.uuid} href={`/calendar/events/${item.uuid}`} className="mb-2 block rounded-lg border border-[var(--color-border)] p-3 hover:border-[var(--color-accent)]"><p className="text-sm font-medium text-white">{item.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{TYPE_LABEL[item.type] ?? 'Akce'}{item.place_name ? ` · ${item.place_name}` : ''}{item.open_tasks_count ? ` · ${item.open_tasks_count} úkolů` : ''}{item.has_conflict ? ' · kolize v plánu' : ''}</p></Link>)}</div>}
                        {selectedMedia && <div><p className="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Vzpomínky</p><p className="text-sm text-[var(--color-text-secondary)]">{selectedMedia.photos} fotek {selectedMedia.videos ? `a ${selectedMedia.videos} videí` : ''}</p><Link href={`/timeline?date=${range.from.slice(0, 8)}${String(selectedDay).padStart(2, '0')}`} className="mt-2 inline-flex text-sm text-[var(--color-accent)]">Zobrazit média →</Link></div>}
                        {!selectedEvents.length && !selectedMedia && !selectedHoliday && !selectedNameDays.length && !selectedMilestones.length && <p className="text-sm text-[var(--color-text-secondary)]">Tento den je zatím volný. Můžete jej využít pro společný plán.</p>}
                    </div> : <div className="mt-3 space-y-3 text-sm text-[var(--color-text-secondary)]"><p><Sparkles className="mr-2 inline text-[var(--color-accent)]" size={15}/>Fotky se zobrazují spolu s akcemi.</p><Link href="/travel-inbox" className="block text-[var(--color-accent)]">Cestovní inbox →</Link><Link href="/weekly" className="block text-[var(--color-accent)]">Týdenní společný přehled →</Link></div>}
                </aside>
            </div>
            {milestones.length > 0 && <section className="mt-4 rounded-2xl border border-pink-500/20 bg-pink-500/5 p-4"><h2 className="font-semibold text-white">Osobní dny v tomto období</h2><div className="mt-3 flex flex-wrap gap-2">{milestones.map(item => <span key={`${item.uuid}-${item.occurrence_date}`} className={`rounded-lg px-3 py-2 text-sm ${item.is_highlighted?'bg-amber-500/10 text-amber-100':'bg-black/10 text-pink-100'}`}>{item.icon} {item.title} · {new Date(`${item.occurrence_date}T12:00:00`).toLocaleDateString('cs-CZ')}</span>)}</div></section>}
            {showCreate && <div className="fixed inset-0 z-50 flex items-end bg-black/60 p-0 sm:items-center sm:justify-center sm:p-4"><form onSubmit={create} className="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-5 sm:max-w-lg sm:rounded-2xl"><div className="mb-4 flex items-center justify-between"><h2 className="font-semibold text-white">Naplánovat akci</h2><button type="button" onClick={() => setShowCreate(false)} className="text-[var(--color-text-secondary)]">Zavřít</button></div><div className="space-y-3">
                <label className="block text-sm text-[var(--color-text-secondary)]">Název<input required value={form.title} onChange={e => setForm({...form, title:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white" placeholder="Např. Víkend v Brně"/></label>
                <div className="grid grid-cols-2 gap-3"><label className="text-sm text-[var(--color-text-secondary)]">Typ<select value={form.type} onChange={e => setForm({...form,type:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white">{Object.entries(TYPE_LABEL).map(([value,label]) => <option value={value} key={value}>{label}</option>)}</select></label><label className="text-sm text-[var(--color-text-secondary)]">Společný prostor<select required value={form.gallery_space_id} onChange={e => setForm({...form,gallery_space_id:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white">{spaces.map(space => <option key={space.id} value={space.id}>{space.name}</option>)}</select></label></div>
                <label className="block text-sm text-[var(--color-text-secondary)]">Začátek<input required type="datetime-local" value={form.starts_at} onChange={e => setForm({...form,starts_at:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/></label><label className="block text-sm text-[var(--color-text-secondary)]">Konec (volitelné)<input type="datetime-local" value={form.ends_at} onChange={e => setForm({...form,ends_at:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/></label>
                <LocationPicker label="Místo" compact value={{location_name:form.place_name, latitude:form.latitude, longitude:form.longitude}} onChange={(location:LocationValue) => setForm({...form, place_name:location.location_name, latitude:location.latitude, longitude:location.longitude})}/>
                <div><p className="text-sm text-[var(--color-text-secondary)]">Barva v kalendáři</p><div className="mt-2 flex flex-wrap items-center gap-2">{EVENT_COLORS.map(color => <button key={color} type="button" onClick={() => setForm({...form,color})} aria-label={`Použít barvu ${color}`} className={`h-8 w-8 rounded-full border-2 ${form.color.toLowerCase() === color ? 'border-white ring-2 ring-white/30' : 'border-transparent'}`} style={{backgroundColor:color}}/>)}<input type="color" value={normalizeEventColor(form.color) || '#7567e8'} onChange={event => setForm({...form,color:event.target.value})} aria-label="Vlastní barva" className="h-8 w-10 cursor-pointer rounded border border-[var(--color-border)] bg-transparent p-0"/><input value={form.color} onChange={event => setForm({...form,color:event.target.value})} onBlur={event => { const color=normalizeEventColor(event.target.value); if(color) setForm({...form,color}); }} placeholder="#7567e8 nebo rgb(117,103,232)" className="min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-xs text-white"/></div><p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">Vyberte barvu ze sady, vlastního výběru nebo vložte HEX/RGB.</p></div>
                <div className="grid grid-cols-2 gap-3"><label className="text-sm text-[var(--color-text-secondary)]">Napojit na cestu<select value={form.trip_id} onChange={e => setForm({...form,trip_id:e.target.value,create_trip:false})} disabled={form.create_trip} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white disabled:opacity-45"><option value="">Bez existující cesty</option>{trips.filter(trip => !form.gallery_space_id || trip.gallery_space_id === Number(form.gallery_space_id)).map(trip => <option key={trip.id} value={trip.id}>{trip.name}</option>)}</select></label><label className="text-sm text-[var(--color-text-secondary)]">Připomenout<input type="number" min="0" value={form.reminder} onChange={e => setForm({...form,reminder:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/><span className="text-xs">minut předem</span></label></div>
                <label className="flex items-start gap-2 rounded-lg border border-teal-400/25 bg-teal-500/5 p-3 text-sm text-teal-100"><input type="checkbox" checked={form.create_trip} onChange={e => setForm({...form,create_trip:e.target.checked,trip_id:e.target.checked ? '' : form.trip_id,type:e.target.checked ? 'trip' : form.type})}/><span><strong className="block">Rovnou vytvořit plán cesty</strong><span className="text-xs text-teal-100/80">Vznikne společný itinerář, rozpočet, balení a kontrola připravenosti se stejným termínem a místem.</span></span></label>
                <label className="flex items-center gap-2 text-sm text-[var(--color-text-secondary)]"><input type="checkbox" checked={form.all_day} onChange={e => setForm({...form,all_day:e.target.checked})}/> Celý den</label>
            </div><div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setShowCreate(false)} className="rounded-lg px-3 py-2 text-sm text-[var(--color-text-secondary)]">Zrušit</button><button disabled={saving} className="rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{saving ? 'Ukládám…' : 'Vytvořit akci'}</button></div></form></div>}
            {showImport && <div className="fixed inset-0 z-50 flex items-end bg-black/60 p-0 sm:items-center sm:justify-center sm:p-4">
                <form onSubmit={importIcs} className="max-h-[94vh] w-full overflow-y-auto rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-5 sm:max-w-xl sm:rounded-2xl">
                    <div className="flex items-start justify-between gap-4"><div><h2 className="font-semibold text-white">Import kalendáře ICS</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Akce z Google, Apple nebo jiného kalendáře můžete rovnou sdílet, připomenout a propojit s plánováním cest.</p></div><button type="button" onClick={() => setShowImport(false)} className="shrink-0 text-sm text-[var(--color-text-secondary)]">Zavřít</button></div>
                    <label className="mt-4 block text-sm text-[var(--color-text-secondary)]">Společný prostor<select required value={form.gallery_space_id} onChange={e => setForm({...form,gallery_space_id:e.target.value})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] p-2 text-white">{spaces.map(space => <option key={space.id} value={space.id}>{space.name}</option>)}</select></label>
                    <label className="mt-3 flex min-h-20 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-[var(--color-accent)]/50 bg-[var(--color-accent)]/5 p-3 text-center text-sm text-white hover:bg-[var(--color-accent)]/10"><Upload size={18}/><span className="mt-1">{icsFileName || 'Vybrat soubor .ics'}</span><span className="text-[10px] text-[var(--color-text-secondary)]">maximálně 512 KB</span><input type="file" accept=".ics,text/calendar" onChange={loadIcsFile} className="sr-only"/></label>
                    <details className="mt-3"><summary className="cursor-pointer text-xs text-[var(--color-text-secondary)]">Nebo vložit obsah ICS ručně</summary><textarea value={ics} onChange={e => { setIcs(e.target.value); setIcsFileName(''); }} placeholder={'BEGIN:VCALENDAR\nBEGIN:VEVENT\nSUMMARY:…'} rows={7} className="mt-2 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-3 font-mono text-xs text-white placeholder-[var(--color-text-secondary)]"/></details>
                    <div className="mt-4 grid gap-2 sm:grid-cols-2">
                        <label className="flex items-start gap-2 rounded-xl border border-pink-400/20 bg-pink-500/5 p-3 text-sm text-pink-100"><input type="checkbox" checked={icsShare} onChange={event => setIcsShare(event.target.checked)} className="mt-0.5"/><span><strong className="block">Sdílet s partnerem</strong><span className="text-xs text-pink-100/75">Oba událost uvidíte a můžete potvrdit účast.</span></span></label>
                        <label className="flex items-start gap-2 rounded-xl border border-teal-400/20 bg-teal-500/5 p-3 text-sm text-teal-100"><input type="checkbox" checked={icsCreateTrips} onChange={event => setIcsCreateTrips(event.target.checked)} className="mt-0.5"/><span><strong className="block">Vícedenní akce jako cesty</strong><span className="text-xs text-teal-100/75">Pro akce do 90 dní vznikne itinerář, místo a přípravné úkoly.</span></span></label>
                    </div>
                    <label className="mt-3 block text-sm text-[var(--color-text-secondary)]">Připomenout oběma před každou akcí<div className="mt-1 flex items-center gap-2"><input type="number" min="0" max="525600" value={icsReminder} onChange={event => setIcsReminder(event.target.value)} placeholder="Bez připomínky" className="w-32 rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-white"/><span className="text-xs">minut · prázdné pole = bez připomínky</span></div></label>
                    {importResult && <p className="mt-3 rounded-lg bg-green-500/10 p-3 text-sm text-green-200">{importResult}</p>}
                    <div className="mt-4 flex justify-end gap-2"><button type="button" onClick={() => setShowImport(false)} className="rounded-lg px-3 py-2 text-sm text-[var(--color-text-secondary)]">Zrušit</button><button disabled={importing || !ics.trim()} className="rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{importing ? 'Importuji…' : 'Importovat a propojit'}</button></div>
                </form>
            </div>}
        </main>
    </AppLayout>;
}
