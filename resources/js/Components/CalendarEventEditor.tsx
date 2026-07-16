import LocationPicker, { LocationValue } from '@/Components/LocationPicker';
import axios from 'axios';
import { Bell, CalendarClock, Check, MapPin, Plus, Route, Save, Trash2, Users, X } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

interface Participant {
    id: number;
    name: string;
    pivot?: { role?: string; response?: string };
}

interface AvailableParticipant extends Participant {
    space_role?: string;
}

interface EventReminderDraft {
    id?: number;
    minutes_before: number;
    channel: 'database' | 'email';
    user_id: number | null;
}

interface EditableCalendarEvent {
    uuid: string;
    title: string;
    description?: string | null;
    type: string;
    status: string;
    starts_at: string;
    ends_at?: string | null;
    all_day: boolean;
    timezone?: string | null;
    place_name?: string | null;
    latitude?: number | string | null;
    longitude?: number | string | null;
    departure_buffer_minutes?: number | null;
    recurrence_rule?: { frequency?: string; interval?: number; until?: string | null } | null;
    color?: string | null;
    is_private: boolean;
    trip_id?: number | null;
    created_by: number;
    viewer_id?: number | null;
    can_edit: boolean;
    participants: Participant[];
    available_participants: AvailableParticipant[];
    available_trips: Array<{ id: number; name: string; start_date?: string; end_date?: string }>;
    calendar_form_reminders: EventReminderDraft[];
    reminders: Array<{ id: number }>;
}

interface EventForm {
    title: string;
    description: string;
    type: string;
    status: string;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    timezone: string;
    place_name: string;
    latitude: number | '';
    longitude: number | '';
    departure_buffer_minutes: string;
    recurrence_frequency: string;
    recurrence_interval: string;
    recurrence_until: string;
    color: string;
    is_private: boolean;
    trip_id: string;
}

const EVENT_COLORS = ['#7567e8', '#db2777', '#e11d48', '#ea580c', '#ca8a04', '#16a34a', '#0891b2', '#2563eb', '#7c3aed'];
const TYPE_LABELS: Record<string, string> = { event: 'Akce', trip: 'Cesta', outing: 'Výlet', birthday: 'Narozeniny', anniversary: 'Výročí', reservation: 'Rezervace', custom: 'Vlastní' };
const STATUS_LABELS: Record<string, string> = { planned: 'Plánovaná', confirmed: 'Potvrzená', completed: 'Dokončená', cancelled: 'Zrušená' };
const TIMEZONES = ['Europe/Prague', 'Europe/London', 'Europe/Paris', 'Europe/Rome', 'Europe/Athens', 'UTC', 'America/New_York', 'Asia/Tokyo'];
const REMINDER_PRESETS = [15, 60, 180, 1440, 10080];

const localDateTime = (value?: string | null) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

const toIso = (value: string) => value ? new Date(value).toISOString() : null;

const emptyForm: EventForm = {
    title: '', description: '', type: 'event', status: 'planned', starts_at: '', ends_at: '', all_day: false,
    timezone: 'Europe/Prague', place_name: '', latitude: '', longitude: '', departure_buffer_minutes: '',
    recurrence_frequency: '', recurrence_interval: '1', recurrence_until: '', color: '#7567e8', is_private: false, trip_id: '',
};

export default function CalendarEventEditor({ eventUuid, open, onClose, onSaved, onDeleted }: {
    eventUuid: string | null;
    open: boolean;
    onClose: () => void;
    onSaved?: (event: EditableCalendarEvent) => void | Promise<void>;
    onDeleted?: () => void | Promise<void>;
}) {
    const [event, setEvent] = useState<EditableCalendarEvent | null>(null);
    const [form, setForm] = useState<EventForm>(emptyForm);
    const [participantIds, setParticipantIds] = useState<number[]>([]);
    const [reminders, setReminders] = useState<EventReminderDraft[]>([]);
    const [createTrip, setCreateTrip] = useState(false);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState('');

    const hydrate = (data: EditableCalendarEvent) => {
        setEvent(data);
        setForm({
            title: data.title ?? '', description: data.description ?? '', type: data.type ?? 'event', status: data.status ?? 'planned',
            starts_at: localDateTime(data.starts_at), ends_at: localDateTime(data.ends_at), all_day: Boolean(data.all_day),
            timezone: data.timezone ?? 'Europe/Prague', place_name: data.place_name ?? '',
            latitude: data.latitude === null || data.latitude === undefined ? '' : Number(data.latitude),
            longitude: data.longitude === null || data.longitude === undefined ? '' : Number(data.longitude),
            departure_buffer_minutes: data.departure_buffer_minutes === null || data.departure_buffer_minutes === undefined ? '' : String(data.departure_buffer_minutes),
            recurrence_frequency: data.recurrence_rule?.frequency ?? '', recurrence_interval: String(data.recurrence_rule?.interval ?? 1),
            recurrence_until: data.recurrence_rule?.until?.slice(0, 10) ?? '', color: data.color ?? '#7567e8',
            is_private: Boolean(data.is_private), trip_id: data.trip_id ? String(data.trip_id) : '',
        });
        setParticipantIds(data.participants.map(person => person.id));
        setReminders((data.calendar_form_reminders ?? []).map(reminder => ({
            id: reminder.id, minutes_before: reminder.minutes_before, channel: reminder.channel === 'email' ? 'email' : 'database', user_id: reminder.user_id,
        })));
        setCreateTrip(false);
    };

    useEffect(() => {
        if (!open || !eventUuid) return;
        let active = true;
        setLoading(true); setError('');
        axios.get(`/api/v1/calendar/events/${eventUuid}`)
            .then(response => { if (active) hydrate(response.data); })
            .catch(reason => { if (active) setError(reason.response?.data?.message ?? 'Akci se nepodařilo načíst.'); })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [eventUuid, open]);

    useEffect(() => {
        if (!open) return;
        const closeOnEscape = (keyboard: KeyboardEvent) => { if (keyboard.key === 'Escape' && !saving && !deleting) onClose(); };
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', closeOnEscape);
        return () => { document.body.style.overflow = previousOverflow; window.removeEventListener('keydown', closeOnEscape); };
    }, [open, saving, deleting, onClose]);

    const selectedParticipants = useMemo(() => new Set(participantIds), [participantIds]);
    const automatedReminderCount = Math.max(0, (event?.reminders?.length ?? 0) - reminders.length);

    const updateLocation = (location: LocationValue) => setForm(current => ({
        ...current,
        place_name: location.location_name,
        latitude: location.latitude,
        longitude: location.longitude,
    }));

    const toggleParticipant = (id: number) => {
        if (id === event?.created_by) return;
        setParticipantIds(current => current.includes(id) ? current.filter(item => item !== id) : [...current, id]);
        setReminders(current => current.filter(reminder => reminder.user_id === null || reminder.user_id !== id));
    };

    const addReminder = () => {
        const defaultRecipient = event?.viewer_id && selectedParticipants.has(event.viewer_id) ? event.viewer_id : event?.created_by ?? null;
        setReminders(current => current.length >= 12 ? current : [...current, { minutes_before: 60, channel: 'database', user_id: defaultRecipient }]);
    };

    const submit = async (submitEvent: FormEvent) => {
        submitEvent.preventDefault();
        if (!eventUuid || !event?.can_edit) return;
        setSaving(true); setError('');
        try {
            const startValue = form.all_day ? `${form.starts_at.slice(0, 10)}T00:00` : form.starts_at;
            const endValue = form.ends_at
                ? (form.all_day ? `${form.ends_at.slice(0, 10)}T23:59` : form.ends_at)
                : null;
            const startsAt = toIso(startValue);
            const endsAt = endValue ? toIso(endValue) : null;
            if (!startsAt) throw new Error('Vyplňte začátek akce.');
            if (endsAt && new Date(endsAt).getTime() < new Date(startsAt).getTime()) throw new Error('Konec akce nesmí být před začátkem.');

            const response = await axios.patch(`/api/v1/calendar/events/${eventUuid}`, {
                title: form.title.trim(), description: form.description.trim() || null, type: form.type, status: form.status,
                starts_at: startsAt, ends_at: endsAt, all_day: form.all_day, timezone: form.timezone,
                place_name: form.place_name.trim() || null, latitude: form.latitude === '' ? null : Number(form.latitude),
                longitude: form.longitude === '' ? null : Number(form.longitude),
                departure_buffer_minutes: form.departure_buffer_minutes === '' ? null : Number(form.departure_buffer_minutes),
                color: form.color, is_private: form.is_private, trip_id: form.trip_id ? Number(form.trip_id) : null,
                recurrence_rule: form.recurrence_frequency ? {
                    frequency: form.recurrence_frequency, interval: Number(form.recurrence_interval || 1), until: form.recurrence_until || null,
                } : null,
                participant_ids: participantIds,
                reminders: form.status === 'cancelled' ? [] : reminders.map(reminder => ({
                    minutes_before: Number(reminder.minutes_before), channel: reminder.channel, user_id: reminder.user_id,
                })),
            });

            let updated = response.data as EditableCalendarEvent;
            if (createTrip && !updated.trip_id) {
                await axios.post(`/api/v1/calendar/events/${eventUuid}/trip`);
                updated = (await axios.get(`/api/v1/calendar/events/${eventUuid}`)).data;
            }
            hydrate(updated);
            await onSaved?.(updated);
            onClose();
        } catch (reason: any) {
            setError(reason.response?.data?.message ?? reason.message ?? 'Změny se nepodařilo uložit.');
        } finally { setSaving(false); }
    };

    const destroy = async () => {
        if (!eventUuid || !event?.can_edit) return;
        if (!window.confirm(`Opravdu odstranit akci „${event.title}“? Navázaná cesta a album zůstanou zachované.`)) return;
        setDeleting(true); setError('');
        try {
            await axios.delete(`/api/v1/calendar/events/${eventUuid}`);
            await onDeleted?.();
            onClose();
        } catch (reason: any) { setError(reason.response?.data?.message ?? 'Akci se nepodařilo odstranit.'); }
        finally { setDeleting(false); }
    };

    if (!open) return null;

    const inputClass = 'mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-black/10 px-3 py-2 text-sm text-white outline-none focus:border-[var(--color-accent)]';
    const sectionClass = 'rounded-2xl border border-[var(--color-border)] bg-black/10 p-4';

    return (
        <div className="fixed inset-0 z-[850] flex items-end bg-black/70 sm:items-center sm:justify-center sm:p-4" role="dialog" aria-modal="true" aria-label="Správa kalendářní akce">
            <button type="button" aria-label="Zavřít editor" onClick={onClose} className="absolute inset-0 cursor-default"/>
            <form onSubmit={submit} className="safe-area-pb relative z-10 flex max-h-[96dvh] w-full max-w-4xl flex-col overflow-hidden rounded-t-3xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] shadow-2xl sm:max-h-[92dvh] sm:rounded-3xl">
                <header className="flex shrink-0 items-center gap-3 border-b border-[var(--color-border)] px-4 py-3 sm:px-5">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--color-accent)]/15 text-[var(--color-accent)]"><CalendarClock size={19}/></div>
                    <div className="min-w-0 flex-1"><h2 className="truncate font-semibold text-white">Správa kalendářní akce</h2><p className="text-xs text-[var(--color-text-secondary)]">Změny se propíšou do cesty, připomínek a společných přehledů.</p></div>
                    <button type="button" onClick={onClose} aria-label="Zavřít" className="flex h-11 w-11 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white"><X size={20}/></button>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4 sm:p-5">
                    {error && <p role="alert" className="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}
                    {loading ? <p className="py-16 text-center text-sm text-[var(--color-text-secondary)]">Načítám všechny vazby akce…</p> : event && !event.can_edit ? <p className="py-16 text-center text-sm text-amber-200">Tuto akci můžete zobrazit, ale nemáte oprávnění ji měnit.</p> : event ? <div className="grid gap-4 lg:grid-cols-2">
                        <section className={`${sectionClass} lg:col-span-2`}>
                            <h3 className="font-medium text-white">Základní informace</h3>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                <label className="text-xs text-[var(--color-text-secondary)] sm:col-span-2">Název<input required maxLength={160} value={form.title} onChange={change => setForm({...form, title: change.target.value})} className={inputClass}/></label>
                                <label className="text-xs text-[var(--color-text-secondary)]">Typ<select value={form.type} onChange={change => setForm({...form, type:change.target.value})} className={inputClass}>{Object.entries(TYPE_LABELS).map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                                <label className="text-xs text-[var(--color-text-secondary)]">Stav<select value={form.status} onChange={change => setForm({...form, status:change.target.value})} className={inputClass}>{Object.entries(STATUS_LABELS).map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                                <label className="text-xs text-[var(--color-text-secondary)] sm:col-span-2">Popis<textarea maxLength={10000} rows={4} value={form.description} onChange={change => setForm({...form, description:change.target.value})} className={`${inputClass} resize-y`} placeholder="Program, důležité poznámky nebo společná domluva…"/></label>
                            </div>
                        </section>

                        <section className={sectionClass}>
                            <h3 className="flex items-center gap-2 font-medium text-white"><CalendarClock size={16} className="text-[var(--color-accent)]"/>Termín a opakování</h3>
                            <label className="mt-3 flex min-h-11 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-sm text-white"><input type="checkbox" checked={form.all_day} onChange={change => setForm({...form, all_day:change.target.checked})}/>Celodenní akce</label>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                <label className="text-xs text-[var(--color-text-secondary)]">Začátek<input required type={form.all_day ? 'date' : 'datetime-local'} value={form.all_day ? form.starts_at.slice(0,10) : form.starts_at} onChange={change => setForm({...form, starts_at: form.all_day ? `${change.target.value}T00:00` : change.target.value})} className={inputClass}/></label>
                                <label className="text-xs text-[var(--color-text-secondary)]">Konec<input type={form.all_day ? 'date' : 'datetime-local'} value={form.all_day ? form.ends_at.slice(0,10) : form.ends_at} onChange={change => setForm({...form, ends_at: form.all_day && change.target.value ? `${change.target.value}T23:59` : change.target.value})} className={inputClass}/></label>
                                <label className="text-xs text-[var(--color-text-secondary)] sm:col-span-2">Časové pásmo<select value={form.timezone} onChange={change => setForm({...form, timezone:change.target.value})} className={inputClass}>{!TIMEZONES.includes(form.timezone) && <option value={form.timezone}>{form.timezone}</option>}{TIMEZONES.map(zone => <option key={zone} value={zone}>{zone}</option>)}</select></label>
                                <label className="text-xs text-[var(--color-text-secondary)]">Opakování<select value={form.recurrence_frequency} onChange={change => setForm({...form, recurrence_frequency:change.target.value})} className={inputClass}><option value="">Neopakovat</option><option value="daily">Denně</option><option value="weekly">Týdně</option><option value="monthly">Měsíčně</option><option value="yearly">Ročně</option></select></label>
                                {form.recurrence_frequency && <><label className="text-xs text-[var(--color-text-secondary)]">Každých<input type="number" min="1" max="52" value={form.recurrence_interval} onChange={change => setForm({...form, recurrence_interval:change.target.value})} className={inputClass}/></label><label className="text-xs text-[var(--color-text-secondary)] sm:col-span-2">Opakovat do<input type="date" value={form.recurrence_until} onChange={change => setForm({...form, recurrence_until:change.target.value})} className={inputClass}/></label></>}
                            </div>
                            {form.recurrence_frequency && <p className="mt-2 text-[10px] text-amber-100">Úprava mění celou opakovanou sérii. Jednotlivé výjimky zůstanou zachované.</p>}
                        </section>

                        <section className={sectionClass}>
                            <h3 className="flex items-center gap-2 font-medium text-white"><MapPin size={16} className="text-[var(--color-accent)]"/>Místo a odjezd</h3>
                            <div className="mt-3"><LocationPicker compact label="Místo nebo podnik" value={{ location_name:form.place_name, latitude:form.latitude, longitude:form.longitude }} onChange={updateLocation}/></div>
                            <label className="mt-3 block text-xs text-[var(--color-text-secondary)]">Rezerva před odjezdem v minutách<input type="number" min="0" max="1440" value={form.departure_buffer_minutes} onChange={change => setForm({...form, departure_buffer_minutes:change.target.value})} className={inputClass} placeholder="Např. 30"/></label>
                            <div className="mt-3"><p className="text-xs text-[var(--color-text-secondary)]">Barva v kalendáři</p><div className="mt-2 flex flex-wrap items-center gap-2">{EVENT_COLORS.map(color => <button type="button" key={color} onClick={() => setForm({...form,color})} className={`h-8 w-8 rounded-full border-2 ${form.color.toLowerCase() === color ? 'border-white ring-2 ring-white/20' : 'border-transparent'}`} style={{backgroundColor:color}} aria-label={`Nastavit barvu ${color}`}/>)}<input type="color" value={form.color} onChange={change => setForm({...form,color:change.target.value})} className="h-9 w-11 rounded border border-[var(--color-border)] bg-transparent" aria-label="Vlastní barva"/></div></div>
                        </section>

                        <section className={sectionClass}>
                            <h3 className="flex items-center gap-2 font-medium text-white"><Users size={16} className="text-pink-300"/>Účastníci a soukromí</h3>
                            <div className="mt-3 space-y-2">{event.available_participants.map(person => {
                                const checked = selectedParticipants.has(person.id);
                                const creator = person.id === event.created_by;
                                return <label key={person.id} className="flex min-h-11 items-center gap-3 rounded-xl border border-[var(--color-border)] px-3 text-sm text-white"><input type="checkbox" checked={checked} disabled={creator} onChange={() => toggleParticipant(person.id)}/><span className="min-w-0 flex-1 truncate">{person.name}</span><span className="text-[10px] text-[var(--color-text-secondary)]">{creator ? 'autor' : person.space_role === 'editor' ? 'partner · editor' : 'člen'}</span></label>;
                            })}</div>
                            <label className="mt-3 flex items-start gap-3 rounded-xl border border-pink-400/20 bg-pink-500/5 p-3 text-sm text-pink-100"><input className="mt-0.5" type="checkbox" checked={form.is_private} onChange={change => setForm({...form,is_private:change.target.checked})}/><span><strong className="block">Soukromá akce</strong><span className="text-xs text-pink-100/70">Uvidí ji autor a výslovně přidaní účastníci.</span></span></label>
                        </section>

                        <section className={sectionClass}>
                            <div className="flex items-start justify-between gap-3"><div><h3 className="flex items-center gap-2 font-medium text-white"><Bell size={16} className="text-amber-300"/>Připomínky</h3><p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">Připomínky vytvořené jinými částmi systému zůstávají zachované.</p></div><button type="button" onClick={addReminder} disabled={reminders.length >= 12 || form.status === 'cancelled'} className="inline-flex min-h-10 items-center gap-1 rounded-xl border border-amber-300/25 px-3 text-xs text-amber-100 disabled:opacity-40"><Plus size={14}/>Přidat</button></div>
                            {automatedReminderCount > 0 && <p className="mt-3 rounded-lg bg-sky-500/10 px-3 py-2 text-xs text-sky-100">{automatedReminderCount} automatických nebo osobních připomínek spravují navázané cesty, rezervace či recepty.</p>}
                            <div className="mt-3 space-y-2">{reminders.map((reminder,index) => <div key={reminder.id ?? index} className="grid gap-2 rounded-xl border border-[var(--color-border)] p-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                <label className="text-[10px] text-[var(--color-text-secondary)]">Minut předem<input list="calendar-reminder-presets" type="number" min="0" max="525600" value={reminder.minutes_before} onChange={change => setReminders(current => current.map((item,i) => i === index ? {...item, minutes_before:Number(change.target.value)} : item))} className={inputClass}/></label>
                                <label className="text-[10px] text-[var(--color-text-secondary)]">Kanál<select value={reminder.channel} onChange={change => setReminders(current => current.map((item,i) => i === index ? {...item, channel:change.target.value as 'database'|'email'} : item))} className={inputClass}><option value="database">V aplikaci</option><option value="email">E-mail</option></select></label>
                                <label className="text-[10px] text-[var(--color-text-secondary)]">Pro koho<select value={reminder.user_id ?? ''} onChange={change => setReminders(current => current.map((item,i) => i === index ? {...item,user_id:change.target.value ? Number(change.target.value) : null} : item))} className={inputClass}>{event.available_participants.filter(person => selectedParticipants.has(person.id)).map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></label>
                                <button type="button" onClick={() => setReminders(current => current.filter((_,i) => i !== index))} aria-label="Odebrat připomínku" className="mt-4 flex h-11 w-11 items-center justify-center rounded-xl text-red-300 hover:bg-red-500/10"><X size={17}/></button>
                            </div>)}{!reminders.length && <p className="text-sm text-[var(--color-text-secondary)]">Bez ručně nastavené připomínky z kalendáře.</p>}</div>
                            <datalist id="calendar-reminder-presets">{REMINDER_PRESETS.map(value => <option key={value} value={value}/>)}</datalist>
                        </section>

                        <section className={`${sectionClass} lg:col-span-2`}>
                            <h3 className="flex items-center gap-2 font-medium text-white"><Route size={16} className="text-teal-300"/>Propojení s cestováním</h3>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2"><label className="text-xs text-[var(--color-text-secondary)]">Navázaná cesta<select value={form.trip_id} onChange={change => { setForm({...form,trip_id:change.target.value}); setCreateTrip(false); }} className={inputClass}><option value="">Bez navázané cesty</option>{event.available_trips.map(trip => <option key={trip.id} value={trip.id}>{trip.name}{trip.start_date ? ` · ${trip.start_date}` : ''}</option>)}</select></label>{!form.trip_id && <label className="flex items-start gap-3 rounded-xl border border-teal-400/20 bg-teal-500/5 p-3 text-sm text-teal-100 sm:self-end"><input className="mt-0.5" type="checkbox" checked={createTrip} onChange={change => { setCreateTrip(change.target.checked); if (change.target.checked) setForm({...form,type:'trip'}); }}/><span><strong className="block">Vytvořit plán cesty</strong><span className="text-xs text-teal-100/70">Vznikne itinerář, rozpočet, balení a příprava.</span></span></label>}</div>
                        </section>

                        <section className="rounded-2xl border border-red-500/20 bg-red-500/5 p-4 lg:col-span-2">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h3 className="font-medium text-red-100">Odstranění akce</h3><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Úkoly a připomínky této akce se odstraní. Samostatná cesta, album a fotografie zůstanou zachované.</p></div><button type="button" onClick={destroy} disabled={deleting || saving} className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-red-400/30 px-4 text-sm text-red-200 hover:bg-red-500/10 disabled:opacity-40"><Trash2 size={16}/>{deleting ? 'Odstraňuji…' : 'Odstranit akci'}</button></div>
                        </section>
                    </div> : null}
                </div>

                <footer className="flex shrink-0 items-center gap-2 border-t border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-4 py-3 sm:px-5">
                    <p className="mr-auto hidden items-center gap-1 text-xs text-[var(--color-text-secondary)] sm:flex"><Check size={13}/>Server ověří oprávnění i všechny vazby.</p>
                    <button type="button" onClick={onClose} disabled={saving || deleting} className="min-h-11 rounded-xl px-4 text-sm text-[var(--color-text-secondary)] disabled:opacity-40">Zrušit</button>
                    <button disabled={loading || saving || deleting || !event?.can_edit} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-white disabled:opacity-40"><Save size={16}/>{saving ? 'Ukládám…' : 'Uložit změny'}</button>
                </footer>
            </form>
        </div>
    );
}
