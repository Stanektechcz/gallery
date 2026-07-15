import { Link } from '@inertiajs/react';
import axios from 'axios';
import { AlarmClock, Check, HeartHandshake, LoaderCircle, Users } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

export interface CoordinationAction {
    key: string;
    type: 'shared_todo' | 'event_task' | 'packing_item' | 'planning_item' | 'trip_document' | 'gift' | 'settlement';
    source_key: string;
    title: string;
    context: string;
    due_at?: string | null;
    priority: string;
    is_overdue: boolean;
    assigned_to?: { id: number; name: string } | null;
    assignment_locked?: boolean;
    href: string;
}

interface CheckIn {
    uuid: string;
    user_id: number;
    user_name: string;
    mood?: string | null;
    energy?: number | null;
    capacity: 'unavailable' | 'light' | 'normal' | 'high';
    focus?: string | null;
    note?: string | null;
    is_shared: boolean;
}

export interface PartnerPulse {
    space: { id: number; name: string };
    actions: CoordinationAction[];
    members: Array<{ id: number; name: string; avatar_path?: string | null; open_actions: number; overdue_actions: number; check_in?: CheckIn | null }>;
    my_check_in?: CheckIn | null;
    summary: { total: number; mine: number; unassigned: number; overdue: number; due_this_week: number; snoozed_for_me: number };
    recommendation?: { code: string; title: string; message: string; suggested_user_id?: number } | null;
    check_in_available: boolean;
}

const SOURCE_LABEL: Record<CoordinationAction['type'], string> = {
    shared_todo: 'společný úkol', event_task: 'kalendář', packing_item: 'balení', planning_item: 'podklad', trip_document: 'doklad', gift: 'dárek', settlement: 'vyrovnání cesty',
};
const MOODS: Record<string, string> = { joyful: '😊 Radost', calm: '😌 Klid', tired: '😴 Únava', stressed: '😵 Napětí', excited: '🤩 Těšení', low: '🌧️ Nic moc' };
const CAPACITY: Record<CheckIn['capacity'], string> = { unavailable: 'Dnes ne', light: 'Jen lehce', normal: 'Normálně', high: 'Mám prostor' };

export default function PartnerPulsePanel({ spaceId, initialPulse, compact = false }: { spaceId?: number | null; initialPulse?: PartnerPulse | null; compact?: boolean }) {
    const [pulse, setPulse] = useState<PartnerPulse | null>(initialPulse ?? null);
    const [loading, setLoading] = useState(!initialPulse);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const [checkIn, setCheckIn] = useState({ mood: '', energy: '3', capacity: 'normal' as CheckIn['capacity'], focus: '', is_shared: true });

    const load = async () => {
        setLoading(true); setError('');
        try {
            const response = await axios.get<PartnerPulse>('/api/v1/coordination/pulse', { params: { gallery_space_id: spaceId || undefined, limit: compact ? 6 : 20 } });
            setPulse(response.data);
        } catch (reason: any) { setError(reason.response?.data?.message ?? 'Partnerský přehled se nepodařilo načíst.'); }
        finally { setLoading(false); }
    };

    useEffect(() => { if (!initialPulse) void load(); }, [spaceId]);
    useEffect(() => {
        if (!pulse?.my_check_in) return;
        setCheckIn({ mood: pulse.my_check_in.mood ?? '', energy: String(pulse.my_check_in.energy ?? 3), capacity: pulse.my_check_in.capacity, focus: pulse.my_check_in.focus ?? '', is_shared: pulse.my_check_in.is_shared });
    }, [pulse?.my_check_in?.uuid]);

    const mutate = async (action: CoordinationAction, payload: Record<string, unknown>) => {
        setBusy(action.key); setError('');
        try {
            const response = await axios.patch<PartnerPulse>(`/api/v1/coordination/actions/${action.type}/${action.source_key}`, { gallery_space_id: pulse?.space.id ?? spaceId, ...payload });
            setPulse(response.data);
        } catch (reason: any) { setError(reason.response?.data?.message ?? 'Společný krok se nepodařilo uložit.'); }
        finally { setBusy(''); }
    };
    const saveCheckIn = async (event: FormEvent) => {
        event.preventDefault(); setBusy('check-in'); setError('');
        try {
            const response = await axios.put<PartnerPulse>('/api/v1/coordination/check-in', { gallery_space_id: pulse?.space.id ?? spaceId, ...checkIn, mood: checkIn.mood || null, energy: Number(checkIn.energy), focus: checkIn.focus || null });
            setPulse(response.data);
        } catch (reason: any) { setError(reason.response?.data?.message ?? 'Dnešní check-in se nepodařilo uložit.'); }
        finally { setBusy(''); }
    };
    const tomorrow = useMemo(() => { const date = new Date(); date.setDate(date.getDate() + 1); date.setHours(8, 0, 0, 0); return date.toISOString(); }, []);

    if (loading && !pulse) return <section className="flex min-h-28 items-center justify-center rounded-3xl border border-teal-400/20 bg-[var(--color-bg-card)]"><LoaderCircle className="animate-spin text-teal-300" size={22}/></section>;
    if (!pulse) return error ? <p className="rounded-2xl border border-red-400/20 bg-red-500/10 p-4 text-sm text-red-200">{error}</p> : null;

    return (
        <section className="rounded-3xl border border-teal-400/25 bg-gradient-to-br from-teal-500/10 via-[var(--color-bg-card)] to-pink-500/5 p-4 sm:p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div><p className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-teal-200"><HeartHandshake size={15}/> Partnerský puls</p><h2 className="mt-1 font-semibold text-white">Dnešní kapacita a společná zodpovědnost</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Jeden živý přehled nad úkoly, kalendářem, cestami, doklady, dárky, financemi a balením.</p></div>
                <div className="flex flex-wrap gap-1.5 text-[11px]"><Pill value={pulse.summary.mine} label="moje"/><Pill value={pulse.summary.unassigned} label="bez přiřazení" warn={pulse.summary.unassigned > 0}/><Pill value={pulse.summary.overdue} label="po termínu" warn={pulse.summary.overdue > 0}/></div>
            </div>

            <div className="mt-4 grid gap-2 sm:grid-cols-2">
                {pulse.members.map(member => <div key={member.id} className="rounded-xl border border-white/5 bg-black/10 p-3"><div className="flex items-center justify-between gap-2"><span className="truncate text-sm font-medium text-white">{member.name}</span><span className={`text-xs ${member.overdue_actions ? 'text-amber-200' : 'text-teal-100'}`}>{member.open_actions} kroků</span></div>{member.check_in ? <p className="mt-1 truncate text-xs text-[var(--color-text-secondary)]">{member.check_in.mood ? MOODS[member.check_in.mood] : 'Dnešní stav'} · energie {member.check_in.energy ?? '–'}/5 · {CAPACITY[member.check_in.capacity]}{member.check_in.focus ? ` · ${member.check_in.focus}` : ''}</p> : <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Dnešní stav zatím nesdílel/a.</p>}</div>)}
            </div>

            {pulse.recommendation && <div className={`mt-3 rounded-xl px-3 py-2 text-xs ${pulse.recommendation.code === 'balanced' ? 'bg-emerald-500/10 text-emerald-100' : 'bg-amber-500/10 text-amber-100'}`}><strong>{pulse.recommendation.title}.</strong> {pulse.recommendation.message}</div>}

            {pulse.check_in_available && <form onSubmit={saveCheckIn} className="mt-4 grid gap-2 rounded-2xl border border-white/5 bg-black/10 p-3 sm:grid-cols-2 lg:grid-cols-5">
                <select aria-label="Dnešní nálada" value={checkIn.mood} onChange={event => setCheckIn(current => ({...current, mood:event.target.value}))} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] px-2 text-xs text-white"><option value="">Jak se mám?</option>{Object.entries(MOODS).map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select>
                <label className="flex min-h-10 items-center gap-2 rounded-lg border border-[var(--color-border)] px-2 text-xs text-[var(--color-text-secondary)]">Energie <input aria-label="Energie" type="range" min="1" max="5" value={checkIn.energy} onChange={event => setCheckIn(current => ({...current, energy:event.target.value}))} className="min-w-0 flex-1"/><span className="text-white">{checkIn.energy}/5</span></label>
                <select aria-label="Dnešní kapacita" value={checkIn.capacity} onChange={event => setCheckIn(current => ({...current, capacity:event.target.value as CheckIn['capacity']}))} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] px-2 text-xs text-white">{Object.entries(CAPACITY).map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select>
                <input value={checkIn.focus} onChange={event => setCheckIn(current => ({...current, focus:event.target.value}))} placeholder="Na co se dnes soustředím" className="min-h-10 rounded-lg border border-[var(--color-border)] bg-transparent px-3 text-xs text-white placeholder:text-[var(--color-text-secondary)]"/>
                <button disabled={busy === 'check-in'} className="min-h-10 rounded-lg bg-teal-500 px-3 text-xs font-medium text-white disabled:opacity-50">{busy === 'check-in' ? 'Ukládám…' : pulse.my_check_in ? 'Aktualizovat stav' : 'Sdílet dnešní stav'}</button>
                <label className="flex items-center gap-2 text-[11px] text-[var(--color-text-secondary)] sm:col-span-2 lg:col-span-5"><input type="checkbox" checked={checkIn.is_shared} onChange={event => setCheckIn(current => ({...current,is_shared:event.target.checked}))}/> Sdílet s partnerem; jinak zůstane check-in jen mně.</label>
            </form>}

            {!compact && <div className="mt-4 space-y-2">
                {pulse.actions.length === 0 && <p className="rounded-xl bg-emerald-500/10 p-3 text-sm text-emerald-100">Všechny společné kroky jsou hotové. Teď zbývá užít si čas spolu.</p>}
                {pulse.actions.map(action => <div key={action.key} className="grid gap-2 rounded-xl border border-white/5 bg-black/10 p-3 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto] sm:items-center">
                    <button aria-label={`Dokončit ${action.title}`} disabled={busy === action.key} onClick={() => mutate(action,{completed:true})} className="flex h-9 w-9 items-center justify-center rounded-full border border-teal-300/40 text-teal-200 hover:bg-teal-400/15 disabled:opacity-40"><Check size={16}/></button>
                    <div className="min-w-0"><Link href={action.href} className="block truncate text-sm font-medium text-white hover:text-teal-200">{action.title}</Link><p className={`mt-0.5 truncate text-xs ${action.is_overdue ? 'text-amber-200' : 'text-[var(--color-text-secondary)]'}`}>{SOURCE_LABEL[action.type]} · {action.context}{action.due_at ? ` · ${new Date(action.due_at).toLocaleDateString('cs-CZ',{day:'numeric',month:'short'})}` : ''}</p></div>
                    {action.assignment_locked ? <span className="inline-flex min-h-9 max-w-40 items-center rounded-lg border border-[var(--color-border)] px-2 text-xs text-teal-100">Platí {action.assigned_to?.name}</span> : <select aria-label={`Přiřadit ${action.title}`} value={action.assigned_to?.id ?? ''} onChange={event => mutate(action,{assigned_to:event.target.value ? Number(event.target.value) : null})} className="min-h-9 max-w-40 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] px-2 text-xs text-white"><option value="">Domluvit kdo</option>{pulse.members.map(member => <option key={member.id} value={member.id}>{member.name}</option>)}</select>}
                    <button disabled={busy === action.key} onClick={() => mutate(action,{snoozed_until:tomorrow})} className="inline-flex min-h-9 items-center justify-center gap-1 rounded-lg border border-[var(--color-border)] px-2 text-xs text-[var(--color-text-secondary)] hover:text-white"><AlarmClock size={13}/> zítra</button>
                </div>)}
            </div>}
            {error && <p className="mt-3 text-xs text-red-300">{error}</p>}
            {!compact && <p className="mt-3 flex items-center gap-1.5 text-[11px] text-[var(--color-text-secondary)]"><Users size={13}/> Přiřazení mění původní položku. Odložení ji skryje pouze vám a nesmaže ji partnerovi.</p>}
        </section>
    );
}

function Pill({ value, label, warn = false }: { value: number; label: string; warn?: boolean }) {
    return <span className={`rounded-full px-2.5 py-1 ${warn ? 'bg-amber-500/15 text-amber-100' : 'bg-teal-500/10 text-teal-100'}`}>{value} {label}</span>;
}
