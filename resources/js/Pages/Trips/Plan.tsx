import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ArrowDown, ArrowLeft, ArrowUp, Check, ChevronDown, Clock3, CloudDownload, Compass, MapPin, Pencil, Plus, Save, Trash2, Wallet, X } from 'lucide-react';
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
    const updateActivity = async (activity: Activity, patch: Record<string, unknown>) => {
        const response = await axios.patch(`/api/v1/trips/${tripId}/plan/activities/${activity.id}`, patch);
        setDays(previous => previous.map(day => ({ ...day, activities: day.activities.map(item => item.id === activity.id ? { ...item, ...response.data } : item) })));
    };
    const removeActivity = async (activity: Activity) => {
        if (!window.confirm(`Odebrat „${activity.title}“ z itineráře?`)) return;
        await axios.delete(`/api/v1/trips/${tripId}/plan/activities/${activity.id}`);
        setDays(previous => previous.map(day => ({ ...day, activities: day.activities.filter(item => item.id !== activity.id) })));
    };
    const moveActivity = async (index: number, direction: -1 | 1) => {
        if (!activeDay) return;
        const target = index + direction;
        if (target < 0 || target >= activeDay.activities.length) return;
        const previous = activeDay.activities;
        const reordered = [...previous];
        [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
        setDays(items => items.map(day => day.id === activeDay.id ? { ...day, activities: reordered } : day));
        try {
            await axios.put(`/api/v1/trips/${tripId}/plan/days/${activeDay.id}/activities/reorder`, { order: reordered.map(item => item.id) });
        } catch (error: any) {
            setDays(items => items.map(day => day.id === activeDay.id ? { ...day, activities: previous } : day));
            alert(error?.response?.data?.message ?? 'Pořadí programu se nepodařilo uložit.');
        }
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
                                {activeDay.activities.map((activity, index) => <ActivityBlock key={activity.id} activity={activity} onUpdate={patch => updateActivity(activity, patch)} onRemove={() => removeActivity(activity)} onMoveUp={() => moveActivity(index, -1)} onMoveDown={() => moveActivity(index, 1)} canMoveUp={index > 0} canMoveDown={index < activeDay.activities.length - 1} />)}
                                {showAdd && <ActivityForm form={form} setForm={setForm} onSubmit={addActivity} onClose={() => setShowAdd(false)} saving={saving} />}
                            </div>
                        </section>

                        <aside className="space-y-3">
                            <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4"><p className="text-xs font-semibold text-white">Přehled dne</p><div className="mt-3 space-y-2 text-xs text-[var(--color-text-secondary)]"><div className="flex justify-between"><span>Bloků</span><span className="text-white">{activeDay.activities.length}</span></div><div className="flex justify-between"><span>Dokončeno</span><span className="text-white">{activeDay.activities.filter(item => item.status === 'done').length}</span></div><div className="flex justify-between"><span>Rozpočet</span><span className="text-white">{activeDay.activities.reduce((sum, item) => sum + Number(item.cost ?? 0), 0).toLocaleString('cs-CZ')} {trip.currency}</span></div></div></div>
                            <TripPlanningPanel tripId={trip.id} currency={trip.currency} />
                            <TripReadiness tripId={trip.id} />
                            <TripPackingPanel tripId={trip.id} />
                            <EmergencyCard tripId={trip.id} />
                            <div className="rounded-2xl border border-[var(--color-border)] bg-gradient-to-br from-[var(--color-accent)]/15 to-transparent p-4"><p className="text-xs font-semibold text-white">Tip</p><p className="mt-1 text-xs leading-relaxed text-[var(--color-text-secondary)]">Bloky drží rezervace, časy, místa i výdaje pohromadě. Na cestě se tento plán promění v kartu „Právě teď“.</p></div>
                        </aside>
                    </div>}
                </main>
            </div>
        </AppLayout>
    );
}

function TripPlanningPanel({ tripId, currency }: { tripId: number; currency: string }) {
    const [data, setData] = useState<{ expenses:any[]; totals:{planned:number;actual:number;budget:number;currency:string}; route_variants:any[] } | null>(null);
    const [expense, setExpense] = useState({ title: '', amount: '', state: 'actual' });
    const [variant, setVariant] = useState({ title: '', strategy: 'custom', estimated_minutes: '', estimated_cost: '' });
    const load = () => axios.get(`/api/v1/trips/${tripId}/planning`).then(r => setData(r.data)).catch(() => {});
    useEffect(() => { load(); }, [tripId]);
    const addExpense = async (e: React.FormEvent) => { e.preventDefault(); if (!expense.title || !expense.amount) return; await axios.post(`/api/v1/trips/${tripId}/expenses`, { ...expense, amount:Number(expense.amount), currency }); setExpense({title:'',amount:'',state:'actual'}); load(); };
    const addVariant = async (e: React.FormEvent) => { e.preventDefault(); if (!variant.title) return; await axios.post(`/api/v1/trips/${tripId}/route-variants`, { ...variant, estimated_minutes: variant.estimated_minutes ? Number(variant.estimated_minutes) : null, estimated_cost: variant.estimated_cost ? Number(variant.estimated_cost) : null, currency }); setVariant({title:'',strategy:'custom',estimated_minutes:'',estimated_cost:''}); load(); };
    const select = async (id:number) => { await axios.post(`/api/v1/trips/${tripId}/route-variants/${id}/select`); load(); };
    return <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4"><p className="text-xs font-semibold text-white">Rozpočet a varianty</p><div className="mt-3 grid grid-cols-2 gap-2 text-[10px] text-[var(--color-text-secondary)]"><span>Plán: <b className="text-white">{Number(data?.totals.planned ?? 0).toLocaleString('cs-CZ')} {currency}</b></span><span>Skutečnost: <b className="text-white">{Number(data?.totals.actual ?? 0).toLocaleString('cs-CZ')} {currency}</b></span></div><form onSubmit={addExpense} className="mt-3 space-y-2"><input value={expense.title} onChange={e=>setExpense({...expense,title:e.target.value})} placeholder="Výdaj" className="min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><div className="flex gap-2"><input type="number" min="0" value={expense.amount} onChange={e=>setExpense({...expense,amount:e.target.value})} placeholder="Kč" className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><select value={expense.state} onChange={e=>setExpense({...expense,state:e.target.value})} className="rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-1 text-xs text-white"><option value="actual">Skutečný</option><option value="planned">Plánovaný</option></select><button className="rounded-lg border border-[var(--color-border)] px-2 text-xs text-white">+</button></div></form><form onSubmit={addVariant} className="mt-4 border-t border-[var(--color-border)] pt-3"><input value={variant.title} onChange={e=>setVariant({...variant,title:e.target.value})} placeholder="Název varianty trasy" className="min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><div className="mt-2 flex gap-2"><input type="number" min="0" value={variant.estimated_minutes} onChange={e=>setVariant({...variant,estimated_minutes:e.target.value})} placeholder="min" className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><button className="rounded-lg border border-[var(--color-border)] px-2 text-xs text-white">Přidat</button></div></form><div className="mt-3 space-y-1">{data?.route_variants.map(item => <button key={item.id} onClick={()=>select(item.id)} className={`block w-full rounded-lg border p-2 text-left text-xs ${item.is_selected?'border-[var(--color-accent)] text-white':'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}>{item.is_selected?'✓ ':''}{item.title}{item.estimated_minutes?` · ${item.estimated_minutes} min`:''}</button>)}</div></div>;
}

function EmergencyCard({ tripId }: { tripId: number }) {
    const [card,setCard]=useState<any>(null); const [editing,setEditing]=useState(false); const [form,setForm]=useState({accommodation_name:'',accommodation_address:'',accommodation_phone:'',insurance_provider:'',insurance_number:'',notes:''});
    const load=()=>axios.get(`/api/v1/trips/${tripId}/emergency-card`).then(r=>{setCard(r.data);if(r.data)setForm({accommodation_name:r.data.accommodation_name??'',accommodation_address:r.data.accommodation_address??'',accommodation_phone:r.data.accommodation_phone??'',insurance_provider:r.data.insurance_provider??'',insurance_number:r.data.insurance_number??'',notes:r.data.notes??''});}).catch(()=>{});
    useEffect(()=>{load();},[tripId]);
    const save=async(e:React.FormEvent)=>{e.preventDefault();await axios.put(`/api/v1/trips/${tripId}/emergency-card`,form);setEditing(false);load();};
    return <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4"><div className="flex items-center justify-between"><p className="text-xs font-semibold text-white">Nouzová karta</p><button onClick={()=>setEditing(!editing)} className="text-xs text-amber-300">{editing?'Zavřít':'Upravit'}</button></div>{editing?<form onSubmit={save} className="mt-3 space-y-2"><input value={form.accommodation_name} onChange={e=>setForm({...form,accommodation_name:e.target.value})} placeholder="Ubytování" className="min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><textarea value={form.accommodation_address} onChange={e=>setForm({...form,accommodation_address:e.target.value})} placeholder="Adresa" className="w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-2 text-xs text-white"/><input value={form.accommodation_phone} onChange={e=>setForm({...form,accommodation_phone:e.target.value})} placeholder="Telefon ubytování" className="min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><input value={form.insurance_provider} onChange={e=>setForm({...form,insurance_provider:e.target.value})} placeholder="Pojišťovna" className="min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><input value={form.insurance_number} onChange={e=>setForm({...form,insurance_number:e.target.value})} placeholder="Číslo pojištění" className="min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><button className="rounded-lg border border-amber-500/40 px-2 py-1 text-xs text-amber-200">Uložit kartu</button></form>:<div className="mt-2 space-y-1 text-xs text-[var(--color-text-secondary)]">{card?<><p className="text-white">{card.accommodation_name||'Ubytování není vyplněno'}</p>{card.accommodation_address&&<p>{card.accommodation_address}</p>}{card.accommodation_phone&&<a className="text-amber-300" href={`tel:${card.accommodation_phone}`}>{card.accommodation_phone}</a>}{card.insurance_provider&&<p>Pojištění: {card.insurance_provider}</p>}</>:<p>Doplňte ubytování, pojištění a nouzové kontakty pro offline použití.</p>}</div>}</div>;
}

function TripReadiness({ tripId }: { tripId: number }) {
    const [data,setData]=useState<any>(null); const [limit,setLimit]=useState({category:'food',amount:''}); const [document,setDocument]=useState({type:'insurance',title:''});
    const load=()=>axios.get(`/api/v1/trips/${tripId}/readiness`).then(r=>setData(r.data)).catch(()=>{});
    useEffect(()=>{load();},[tripId]);
    const addLimit=async(e:React.FormEvent)=>{e.preventDefault();if(!limit.amount)return;await axios.put(`/api/v1/trips/${tripId}/budget-limits`,{category:limit.category,amount:Number(limit.amount)});setLimit({...limit,amount:''});load();};
    const addDocument=async(e:React.FormEvent)=>{e.preventDefault();if(!document.title)return;await axios.post(`/api/v1/trips/${tripId}/documents`,document);setDocument({...document,title:''});load();};
    return <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4"><p className="text-xs font-semibold text-white">Kontrola připravenosti</p><div className="mt-2 space-y-1 text-xs text-[var(--color-text-secondary)]">{data?.budget?.map((item:any)=><p key={item.category} className={item.status==='over'?'text-red-300':item.status==='warning'?'text-amber-300':''}>{item.category}: {item.actual.toLocaleString('cs-CZ')} / {item.limit.toLocaleString('cs-CZ')} {item.currency}</p>)}{data?.time_conflicts?.map((item:any,index:number)=><p key={index} className="text-red-300">Kolize: {item.first} × {item.second}</p>)}{data?.expired_documents?.map((item:any)=><p key={item.id} className="text-red-300">Propadlý doklad: {item.title}</p>)}</div><form onSubmit={addLimit} className="mt-3 flex gap-1"><select value={limit.category} onChange={e=>setLimit({...limit,category:e.target.value})} className="min-w-0 rounded border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-1 text-[10px] text-white"><option value="food">Jídlo</option><option value="transport">Doprava</option><option value="accommodation">Ubytování</option><option value="activities">Aktivity</option></select><input type="number" min="0" value={limit.amount} onChange={e=>setLimit({...limit,amount:e.target.value})} placeholder="Limit" className="min-w-0 flex-1 rounded border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><button className="text-xs text-[var(--color-accent)]">Uložit</button></form><form onSubmit={addDocument} className="mt-2 flex gap-1"><select value={document.type} onChange={e=>setDocument({...document,type:e.target.value})} className="rounded border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-1 text-[10px] text-white"><option value="insurance">Pojištění</option><option value="id_card">Doklad</option><option value="ticket">Jízdenka</option></select><input value={document.title} onChange={e=>setDocument({...document,title:e.target.value})} placeholder="Přidat doklad" className="min-w-0 flex-1 rounded border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><button className="text-xs text-[var(--color-accent)]">+</button></form></div>;
}

function TripPackingPanel({ tripId }: { tripId: number }) {
    const [items, setItems] = useState<any[]>([]);
    const [title, setTitle] = useState('');
    const [category, setCategory] = useState('other');
    const [busy, setBusy] = useState(false);
    const load = () => axios.get(`/api/v1/trips/${tripId}/packing-items`).then(response => setItems(response.data ?? [])).catch(() => {});
    useEffect(() => { load(); }, [tripId]);
    const add = async (event: React.FormEvent) => { event.preventDefault(); if (!title.trim()) return; setBusy(true); try { await axios.post(`/api/v1/trips/${tripId}/packing-items`, { title: title.trim(), category }); setTitle(''); load(); } finally { setBusy(false); } };
    const apply = async (template: string) => { setBusy(true); try { await axios.post(`/api/v1/trips/${tripId}/packing-items/apply-template`, { template }); load(); } finally { setBusy(false); } };
    const toggle = async (item: any) => { await axios.patch(`/api/v1/trips/${tripId}/packing-items/${item.id}`, { is_packed: !item.is_packed }); load(); };
    const remove = async (item: any) => { if (confirm(`Odebrat „${item.title}“?`)) { await axios.delete(`/api/v1/trips/${tripId}/packing-items/${item.id}`); load(); } };
    const packed = items.filter(item => item.is_packed).length;
    return <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4"><div className="flex items-center justify-between"><p className="text-xs font-semibold text-white">Balicí seznam</p><span className="text-[10px] text-[var(--color-text-secondary)]">{packed}/{items.length}</span></div><div className="mt-2 flex flex-wrap gap-1">{[['weekend','Víkend'],['flight','Letadlo'],['car','Auto'],['first_aid','Lékárnička']].map(([template,label]) => <button disabled={busy} key={template} onClick={() => apply(template)} className="min-h-8 rounded-lg border border-[var(--color-border)] px-2 text-[10px] text-[var(--color-text-secondary)] hover:text-white disabled:opacity-40">+ {label}</button>)}</div><form onSubmit={add} className="mt-3 flex gap-1"><input value={title} onChange={event => setTitle(event.target.value)} placeholder="Přidat věc" className="min-h-9 min-w-0 flex-1 rounded border border-[var(--color-border)] bg-black/10 px-2 text-xs text-white"/><select value={category} onChange={event => setCategory(event.target.value)} className="max-w-24 rounded border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-1 text-[10px] text-white"><option value="other">Ostatní</option><option value="documents">Doklady</option><option value="health">Zdraví</option><option value="electronics">Elektronika</option><option value="car">Auto</option></select><button disabled={busy} className="px-2 text-xs text-[var(--color-accent)]">+</button></form><div className="mt-3 max-h-64 space-y-1 overflow-auto">{items.map(item => <div key={item.id} className="flex items-center gap-2 rounded-lg px-1 py-1"><button onClick={() => toggle(item)} className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border ${item.is_packed ? 'border-green-400 bg-green-500/20 text-green-300' : 'border-[var(--color-text-secondary)]'}`}>{item.is_packed && <Check size={12}/>}</button><span className={`min-w-0 flex-1 truncate text-xs ${item.is_packed ? 'text-[var(--color-text-secondary)] line-through' : 'text-white'}`}>{item.title}{item.is_essential && <span className="ml-1 text-amber-300" title="Nezbytné">★</span>}</span><button onClick={() => remove(item)} className="p-1 text-[var(--color-text-secondary)] hover:text-red-300" aria-label="Odebrat položku"><Trash2 size={12}/></button></div>)}{!items.length && <p className="py-2 text-xs text-[var(--color-text-secondary)]">Zvolte šablonu nebo přidejte vlastní položku.</p>}</div></section>;
}

function ActivityBlock({ activity, onUpdate, onRemove, onMoveUp, onMoveDown, canMoveUp, canMoveDown }: {
    activity: Activity; onUpdate: (patch: Record<string, unknown>) => Promise<void> | void; onRemove: () => void;
    onMoveUp: () => void; onMoveDown: () => void; canMoveUp: boolean; canMoveDown: boolean;
}) {
    const type = TYPES[activity.type] ?? TYPES.activity;
    const [editing, setEditing] = useState(false);
    const [savingEdit, setSavingEdit] = useState(false);
    const [edit, setEdit] = useState({ title: activity.title, starts_at: activity.starts_at?.slice(0, 5) ?? '', ends_at: activity.ends_at?.slice(0, 5) ?? '', place_name: activity.place_name ?? '', cost: activity.cost?.toString() ?? '' });
    const saveEdit = async (event: React.FormEvent) => {
        event.preventDefault(); if (!edit.title.trim()) return;
        setSavingEdit(true);
        await onUpdate({ title: edit.title.trim(), starts_at: edit.starts_at || null, ends_at: edit.ends_at || null, place_name: edit.place_name || null, cost: edit.cost ? Number(edit.cost) : null });
        setSavingEdit(false); setEditing(false);
    };

    if (editing) return <form onSubmit={saveEdit} className="rounded-2xl border border-[var(--color-accent)]/40 bg-[var(--color-bg-card)] p-4"><div className="grid gap-2 sm:grid-cols-2"><input required value={edit.title} onChange={e => setEdit({...edit,title:e.target.value})} className="min-h-11 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white sm:col-span-2" placeholder="Název"/><input type="time" value={edit.starts_at} onChange={e => setEdit({...edit,starts_at:e.target.value})} className="min-h-11 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white"/><input type="time" value={edit.ends_at} onChange={e => setEdit({...edit,ends_at:e.target.value})} className="min-h-11 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white"/><input value={edit.place_name} onChange={e => setEdit({...edit,place_name:e.target.value})} className="min-h-11 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" placeholder="Místo"/><input type="number" min="0" value={edit.cost} onChange={e => setEdit({...edit,cost:e.target.value})} className="min-h-11 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" placeholder="Cena"/></div><div className="mt-3 flex gap-2"><button disabled={savingEdit} className="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--color-accent)] text-xs text-white"><Save size={14}/>{savingEdit?'Ukládám…':'Uložit'}</button><button type="button" onClick={() => setEditing(false)} className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-xs">Zrušit</button></div></form>;

    return <article className={`group flex flex-col gap-2 rounded-2xl border p-3 transition sm:flex-row ${activity.status === 'done' ? 'border-green-500/20 bg-green-500/5 opacity-70' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}><div className="flex min-w-0 flex-1 gap-3"><div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-lg" style={{ backgroundColor: `${type.color}22` }}>{type.icon}</div><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-x-2"><p className={`font-medium text-white ${activity.status === 'done' ? 'line-through' : ''}`}>{activity.title}</p><span className="text-[9px] uppercase tracking-wider" style={{ color: type.color }}>{type.label}</span></div>{(activity.starts_at || activity.place_name) && <div className="mt-1 flex flex-wrap gap-3 text-[10px] text-[var(--color-text-secondary)]">{activity.starts_at && <span className="flex items-center gap-1"><Clock3 size={11}/>{activity.starts_at.slice(0,5)}{activity.ends_at?`–${activity.ends_at.slice(0,5)}`:''}</span>}{activity.place_name&&<span className="flex items-center gap-1"><MapPin size={11}/>{activity.place_name}</span>}</div>}{activity.description&&<p className="mt-2 text-xs text-[var(--color-text-secondary)]">{activity.description}</p>}{activity.cost!=null&&<p className="mt-2 text-[10px] font-medium text-amber-400">{Number(activity.cost).toLocaleString('cs-CZ')} {activity.currency}</p>}</div></div><div className="flex shrink-0 justify-end gap-1"><button disabled={!canMoveUp} onClick={onMoveUp} title="Posunout nahoru" className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5 disabled:opacity-20"><ArrowUp size={14}/></button><button disabled={!canMoveDown} onClick={onMoveDown} title="Posunout dolů" className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5 disabled:opacity-20"><ArrowDown size={14}/></button><button onClick={() => setEditing(true)} title="Upravit" className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white"><Pencil size={14}/></button><button onClick={() => onUpdate({status:activity.status==='done'?'planned':'done'})} title="Dokončit" className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-green-500/10 hover:text-green-400"><Check size={15}/></button><button onClick={onRemove} title="Odebrat" className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-red-500/10 hover:text-red-400"><Trash2 size={14}/></button></div></article>;
}

function ActivityForm({ form, setForm, onSubmit, onClose, saving }: { form: any; setForm: (value: any) => void; onSubmit: (event: React.FormEvent) => void; onClose: () => void; saving: boolean }) {
    return <form onSubmit={onSubmit} className="rounded-2xl border border-[var(--color-accent)]/40 bg-[var(--color-bg-card)] p-4"><div className="mb-3 flex items-center justify-between"><p className="text-sm font-semibold text-white">Nový blok</p><button type="button" onClick={onClose} className="flex h-9 w-9 items-center justify-center rounded-xl"><X size={16} /></button></div><div className="grid gap-3 sm:grid-cols-2"><label className="text-[10px] text-[var(--color-text-secondary)]">Typ<select value={form.type} onChange={e => setForm({ ...form, type: e.target.value })} className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white">{Object.entries(TYPES).map(([key, value]) => <option key={key} value={key}>{value.icon} {value.label}</option>)}</select></label><label className="text-[10px] text-[var(--color-text-secondary)] sm:col-span-2">Název<input autoFocus required value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} placeholder="Co se bude dít?" className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white" /></label><label className="text-[10px] text-[var(--color-text-secondary)]">Od<input type="time" value={form.starts_at} onChange={e => setForm({ ...form, starts_at: e.target.value })} className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label><label className="text-[10px] text-[var(--color-text-secondary)]">Do<input type="time" value={form.ends_at} onChange={e => setForm({ ...form, ends_at: e.target.value })} className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label><label className="text-[10px] text-[var(--color-text-secondary)]">Místo<input value={form.place_name} onChange={e => setForm({ ...form, place_name: e.target.value })} placeholder="Adresa nebo název" className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label><label className="text-[10px] text-[var(--color-text-secondary)]">Cena<input type="number" min="0" value={form.cost} onChange={e => setForm({ ...form, cost: e.target.value })} placeholder="0" className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label></div><button disabled={saving} className="mt-4 min-h-11 w-full rounded-xl bg-[var(--color-accent)] text-sm font-medium text-white disabled:opacity-40">{saving ? 'Přidávám…' : 'Přidat do dne'}</button></form>;
}
