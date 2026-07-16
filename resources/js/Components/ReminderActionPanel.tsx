import { Link } from '@inertiajs/react';
import axios from 'axios';
import { AlarmClock, Bell, Check, Clock3, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface ReminderHistoryItem {
    status: string;
    channel: string;
    detail?: string | null;
    created_at?: string | null;
}

export interface ActionableReminder {
    id: number;
    event_id: number;
    user_id?: number | null;
    recipient_name?: string | null;
    channel: string;
    status: string;
    remind_at: string;
    original_remind_at?: string | null;
    snoozed_until?: string | null;
    snooze_count: number;
    delivered_at?: string | null;
    acknowledged_at?: string | null;
    dismissed_at?: string | null;
    last_error?: string | null;
    can_act: boolean;
    event?: { uuid:string; title:string; starts_at:string; place_name?:string|null } | null;
    history?: ReminderHistoryItem[];
}

const statusLabel:Record<string,string> = {
    pending:'Naplánováno', delivered:'Čeká na potvrzení', failed:'Doručení selhalo',
    acknowledged:'Vyřízeno', dismissed:'Skryto', processing:'Doručuji',
};
const historyLabel:Record<string,string> = {
    scheduled:'naplánováno', delivered:'doručeno', failed:'chyba doručení', snoozed:'odloženo', acknowledged:'vyřízeno', dismissed:'skryto',
};
const dateTime = (value?:string|null) => value ? new Intl.DateTimeFormat('cs-CZ', {day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'}).format(new Date(value)) : '—';

export default function ReminderActionPanel({
    initialItems,
    eventUuid,
    compact = false,
    hideWhenEmpty = false,
}: {
    initialItems: ActionableReminder[];
    eventUuid?: string;
    compact?: boolean;
    hideWhenEmpty?: boolean;
}) {
    const [items, setItems] = useState(initialItems);
    const [minutes, setMinutes] = useState('1440');
    const [busy, setBusy] = useState('');
    const [message, setMessage] = useState('');
    useEffect(() => setItems(initialItems), [initialItems]);

    const update = (next:ActionableReminder) => setItems(current => {
        if (['acknowledged','dismissed'].includes(next.status) && !eventUuid) return current.filter(item => item.id !== next.id);
        const exists = current.some(item => item.id === next.id);
        return exists ? current.map(item => item.id === next.id ? next : item) : [next, ...current];
    });
    const act = async (item:ActionableReminder, action:'snooze'|'acknowledge'|'dismiss', payload:Record<string,number> = {}) => {
        setBusy(`${item.id}:${action}`); setMessage('');
        try {
            const response = await axios.post(`/api/v1/reminders/${item.id}/${action}`, payload);
            update(response.data);
            setMessage(action === 'snooze' ? 'Připomínka je odložená a znovu se ozve ve zvolený čas.' : action === 'acknowledge' ? 'Připomínka je označená jako vyřízená.' : 'Připomínka je skrytá.');
        } catch (reason:any) { setMessage(reason.response?.data?.message ?? 'Připomínku se nepodařilo změnit.'); }
        finally { setBusy(''); }
    };
    const add = async () => {
        if (!eventUuid) return;
        setBusy('add'); setMessage('');
        try {
            const response = await axios.post(`/api/v1/reminders/events/${eventUuid}`, {minutes_before:Number(minutes),channel:'database'});
            update(response.data); setMessage('Osobní připomínka je uložená přímo u této společné akce.');
        } catch (reason:any) { setMessage(reason.response?.data?.message ?? 'Připomínku se nepodařilo přidat.'); }
        finally { setBusy(''); }
    };

    if (hideWhenEmpty && !items.length) return null;

    return <section className={`rounded-2xl border border-amber-400/25 bg-gradient-to-br from-amber-500/10 to-[var(--color-bg-card)] ${compact?'p-4':'p-4 sm:p-5'}`}>
        <div className="flex items-start justify-between gap-3"><div><p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-amber-200"><AlarmClock size={15}/> Chytré připomínky</p><h2 className="mt-1 font-semibold text-white">Nic důležitého nezapadne</h2><p className="mt-1 text-xs leading-relaxed text-[var(--color-text-secondary)]">Odložení je osobní. Termín akce, partnerovy připomínky ani propojená cesta se nezmění.</p></div><Bell size={20} className="shrink-0 text-amber-300"/></div>
        {eventUuid && <div className="mt-4 flex flex-col gap-2 rounded-xl border border-amber-300/15 bg-black/10 p-3 sm:flex-row sm:items-center"><select aria-label="Kdy připomenout" value={minutes} onChange={event=>setMinutes(event.target.value)} className="min-h-10 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white"><option value="15">15 minut předem</option><option value="60">1 hodinu předem</option><option value="180">3 hodiny předem</option><option value="1440">1 den předem</option><option value="10080">1 týden předem</option><option value="43200">30 dní předem</option></select><button type="button" disabled={busy==='add'} onClick={add} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-amber-500/20 px-3 text-xs font-medium text-amber-100 disabled:opacity-40"><Plus size={14}/>{busy==='add'?'Ukládám…':'Přidat pro mě'}</button></div>}
        <div className="mt-4 space-y-2">{items.map(item => {
            const actionable = item.can_act && !['acknowledged','dismissed'].includes(item.status);
            const eventStarts = item.event?.starts_at ? new Date(item.event.starts_at).getTime() : 0;
            const canTomorrow = !eventStarts || eventStarts > Date.now() + 24*60*60*1000;
            return <article key={item.id} className="rounded-xl border border-amber-300/15 bg-black/10 p-3"><div className="flex items-start gap-3"><span className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${item.status==='failed'?'bg-red-500/15 text-red-200':item.status==='delivered'?'bg-amber-500/20 text-amber-100':'bg-white/5 text-[var(--color-text-secondary)]'}`}><Clock3 size={15}/></span><div className="min-w-0 flex-1">{item.event ? <Link href={`/calendar/events/${item.event.uuid}`} className="block truncate text-sm font-medium text-white hover:text-amber-100">{item.event.title}</Link> : <p className="text-sm font-medium text-white">Připomínka akce</p>}<p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">{statusLabel[item.status]??item.status} · {dateTime(item.remind_at)}{item.recipient_name?` · ${item.recipient_name}`:''}{item.snooze_count?` · odloženo ${item.snooze_count}×`:''}</p>{item.event?.place_name&&<p className="mt-1 truncate text-[10px] text-[var(--color-text-secondary)]">📍 {item.event.place_name}</p>}{item.last_error&&<p className="mt-1 text-[10px] text-red-200">{item.last_error}</p>}</div></div>{actionable&&<div className="mt-3 flex flex-wrap gap-1.5 border-t border-amber-300/10 pt-3"><button type="button" disabled={!!busy} onClick={()=>act(item,'snooze',{minutes:60})} className="min-h-9 rounded-lg border border-[var(--color-border)] px-2.5 text-[10px] text-[var(--color-text-secondary)] hover:text-white disabled:opacity-40">Za 1 hodinu</button>{canTomorrow&&<button type="button" disabled={!!busy} onClick={()=>act(item,'snooze',{minutes:1440})} className="min-h-9 rounded-lg border border-[var(--color-border)] px-2.5 text-[10px] text-[var(--color-text-secondary)] hover:text-white disabled:opacity-40">Zítra</button>}<button type="button" disabled={!!busy} onClick={()=>act(item,'acknowledge')} className="inline-flex min-h-9 items-center gap-1 rounded-lg bg-emerald-500/15 px-2.5 text-[10px] text-emerald-100 disabled:opacity-40"><Check size={12}/> Vyřízeno</button><button type="button" disabled={!!busy} onClick={()=>act(item,'dismiss')} className="ml-auto inline-flex min-h-9 items-center gap-1 rounded-lg px-2 text-[10px] text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white disabled:opacity-40"><X size={12}/> Skrýt</button></div>}{!compact&&item.history&&item.history.length>0&&<details className="mt-2 border-t border-amber-300/10 pt-2"><summary className="cursor-pointer text-[10px] text-[var(--color-text-secondary)]">Historie připomínky ({item.history.length})</summary><div className="mt-2 space-y-1">{item.history.slice(0,5).map((entry,index)=><p key={`${entry.status}-${entry.created_at}-${index}`} className="text-[10px] text-[var(--color-text-secondary)]"><span className="text-white/80">{historyLabel[entry.status]??entry.status}</span> · {dateTime(entry.created_at)}{entry.detail?` · ${entry.detail}`:''}</p>)}</div></details>}</article>;
        })}{!items.length&&<p className="rounded-xl border border-dashed border-amber-300/15 p-4 text-center text-xs text-[var(--color-text-secondary)]">Žádná aktivní připomínka. U této akce si můžete přidat vlastní termín.</p>}</div>
        {message&&<p className={`mt-3 text-xs ${message.includes('nepodařilo')||message.includes('nelze')?'text-red-200':'text-emerald-200'}`}>{message}</p>}
    </section>;
}
