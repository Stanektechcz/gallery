import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useEffect, useState } from 'react';

type Milestone = {
    uuid:string; title:string; description?:string|null; occurred_on:string; icon:string;
    visibility:string; remind_annually:boolean; kind?:'milestone'|'birthday'; person_name?:string|null;
    relationship?:string|null; is_highlighted?:boolean; next_anniversary?:string; days_until?:number;
    media?:{uuid:string;thumbnail_url?:string|null;display_title?:string|null;original_filename:string;media_type:string}|null;
};
type NameDay = { id:string; date:string; name:string; official_name:string; title:string; icon:string; is_highlighted:boolean };

const icons = ['❤️','💍','🏡','✈️','🎉','🌱','📸','🥂','🎂','🎁'];
const relationshipLabels:Record<string,string> = {
    partner:'partner/partnerka', parent:'rodič', grandparent:'prarodič', sibling:'sourozenec', child:'dítě',
    friend:'kamarád/kamarádka', relative:'příbuzný', aunt_uncle:'teta/strýc', cousin:'bratranec/sestřenice',
    colleague:'kolega/kolegyně', other:'jiný vztah',
};

function anniversaryInput(occurredOn:string): string {
    const original = new Date(`${occurredOn}T19:00:00`); const value = new Date();
    value.setHours(19, 0, 0, 0); value.setMonth(original.getMonth(), original.getDate());
    if (value.getTime() <= Date.now()) value.setFullYear(value.getFullYear() + 1);
    const part = (number:number) => String(number).padStart(2, '0');
    return `${value.getFullYear()}-${part(value.getMonth() + 1)}-${part(value.getDate())}T${part(value.getHours())}:${part(value.getMinutes())}`;
}

export default function Milestones() {
    const [items,setItems] = useState<Milestone[]>([]); const [upcoming,setUpcoming] = useState<Milestone[]>([]);
    const [spaces,setSpaces] = useState<any[]>([]); const [nameDays,setNameDays] = useState<NameDay[]>([]); const [error,setError] = useState('');
    const [form,setForm] = useState({gallery_space_id:'',kind:'milestone' as 'milestone'|'birthday',title:'',person_name:'',relationship:'parent',occurred_on:'',description:'',icon:'❤️',visibility:'shared',remind_annually:true,is_highlighted:true});
    const [celebration, setCelebration] = useState<{uuid:string;title:string;starts_at:string;reminder_minutes:string;message:string;eventUuid:string}|null>(null);
    const [celebrationBusy, setCelebrationBusy] = useState(false);

    const load = async () => {
        const year = new Date().getFullYear();
        const [list, soon, calendar] = await Promise.all([
            axios.get('/api/v1/relationship-milestones'), axios.get('/api/v1/relationship-milestones/upcoming'),
            axios.get('/api/v1/calendar/events', {params:{from:`${year}-01-01`,to:`${year}-12-31`}}),
        ]);
        setItems(list.data??[]); setUpcoming(soon.data??[]); setSpaces(calendar.data.spaces??[]); setNameDays(calendar.data.name_days??[]);
        setForm(current=>({...current,gallery_space_id:current.gallery_space_id||String(calendar.data.spaces?.[0]?.id??'')}));
    };
    useEffect(()=>{load().catch(()=>setError('Osobní dny se nepodařilo načíst. Dokončete prosím migrace aplikace.'));},[]);

    const add = async (event:FormEvent) => {
        event.preventDefault(); setError('');
        try {
            await axios.post('/api/v1/relationship-milestones',{
                ...form, gallery_space_id:Number(form.gallery_space_id),
                title:form.kind==='birthday' ? (form.title || undefined) : form.title,
                person_name:form.kind==='birthday' ? form.person_name : null,
                relationship:form.kind==='birthday' ? form.relationship : null,
                icon:form.kind==='birthday' && form.icon==='❤️' ? '🎂' : form.icon,
            });
            setForm(current=>({...current,title:'',person_name:'',occurred_on:'',description:'',icon:current.kind==='birthday'?'🎂':'❤️'})); await load();
        } catch (reason:any) { setError(reason.response?.data?.message??'Osobní den se nepodařilo uložit.'); }
    };
    const remove = async (item:Milestone) => { if(confirm(`Smazat „${item.title}“?`)){await axios.delete(`/api/v1/relationship-milestones/${item.uuid}`);await load();} };
    const openCelebration = (item:Milestone) => setCelebration({uuid:item.uuid,title:item.kind==='birthday'?`Oslava narozenin: ${item.person_name}`:`Oslava: ${item.title}`,starts_at:anniversaryInput(item.occurred_on),reminder_minutes:'10080',message:'',eventUuid:''});
    const planCelebration = async () => {
        if (!celebration?.starts_at) return; setCelebrationBusy(true);
        try { const response = await axios.post(`/api/v1/relationship-milestones/${celebration.uuid}/celebration`, {title:celebration.title,starts_at:celebration.starts_at,reminder_minutes:Number(celebration.reminder_minutes || 0)}); setCelebration(current => current ? {...current,message:'Oslava je v kalendáři a připomenutí dostali všichni zapojení.',eventUuid:response.data.uuid} : current); }
        catch (reason:any) { setCelebration(current => current ? {...current,message:reason.response?.data?.message??'Oslavu se nepodařilo naplánovat.'} : current); }
        finally { setCelebrationBusy(false); }
    };

    return <AppLayout><Head title="Osobní dny"/><main className="mx-auto max-w-5xl p-4 sm:p-6">
        <header className="mb-6"><p className="text-sm text-[var(--color-text-secondary)]">Společné zážitky a blízcí</p><h1 className="text-2xl font-bold text-white">Milníky, narozeniny a svátky</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Jeden přehled pro vaše výročí i důležité dny rodiny a přátel. Vše se automaticky propisuje do kalendáře.</p></header>
        {error&&<p className="mb-4 rounded-xl bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}

        <section className="rounded-2xl border border-amber-300/20 bg-gradient-to-br from-amber-500/10 to-[var(--color-bg-card)] p-4">
            <h2 className="font-semibold text-white">🎈 Zvýrazněné svátky</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Tyto osobní svátky jsou zvýrazněné také přímo v kalendáři.</p>
            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">{nameDays.map(day=><div key={day.id} className="rounded-xl border border-amber-200/15 bg-black/10 p-3"><p className="text-sm font-medium text-amber-100">{day.icon} {day.name}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{new Date(`${day.date}T12:00:00`).toLocaleDateString('cs-CZ',{day:'numeric',month:'long'})}</p></div>)}</div>
        </section>

        <section className="mt-4 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4"><h2 className="font-semibold text-white">Co se blíží</h2><div className="mt-3 grid gap-2 sm:grid-cols-2">{upcoming.slice(0,6).map(item=><div key={item.uuid} className={`rounded-xl p-3 ${item.is_highlighted?'border border-amber-300/20 bg-amber-500/10':'bg-black/10'}`}><p className="text-sm text-white">{item.icon} {item.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{item.relationship&&`${relationshipLabels[item.relationship]??item.relationship} · `}za {item.days_until} dní · {new Date(`${item.next_anniversary}T12:00:00`).toLocaleDateString('cs-CZ')}</p></div>)}{!upcoming.length&&<p className="text-sm text-[var(--color-text-secondary)]">Zatím žádný osobní den s připomenutím.</p>}</div></section>

        <form onSubmit={add} className="mt-4 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4"><h2 className="font-semibold text-white">Přidat důležitý den</h2>
            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                <select value={form.kind} onChange={event=>setForm({...form,kind:event.target.value as 'milestone'|'birthday',icon:event.target.value==='birthday'?'🎂':'❤️'})} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white"><option value="milestone">Společný milník / výročí</option><option value="birthday">Narozeniny blízkého</option></select>
                <input required type="date" value={form.occurred_on} onChange={event=>setForm({...form,occurred_on:event.target.value})} aria-label="Datum" className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/>
                {form.kind==='birthday'?<><input required value={form.person_name} onChange={event=>setForm({...form,person_name:event.target.value})} placeholder="Jméno oslavence" className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/><select value={form.relationship} onChange={event=>setForm({...form,relationship:event.target.value})} aria-label="Vztah k oslavenci" className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white">{Object.entries(relationshipLabels).map(([value,label])=><option key={value} value={value}>{label}</option>)}</select><input value={form.title} onChange={event=>setForm({...form,title:event.target.value})} placeholder="Vlastní název (nepovinné)" className="sm:col-span-2 min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/></>:<input required value={form.title} onChange={event=>setForm({...form,title:event.target.value})} placeholder="Např. první společný výlet" className="sm:col-span-2 min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/>}
                <select required value={form.gallery_space_id} onChange={event=>setForm({...form,gallery_space_id:event.target.value})} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white">{spaces.map(space=><option key={space.id} value={space.id}>{space.name}</option>)}</select><select value={form.visibility} onChange={event=>setForm({...form,visibility:event.target.value})} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white"><option value="shared">Společný</option><option value="private">Soukromý</option></select>
                <textarea value={form.description} onChange={event=>setForm({...form,description:event.target.value})} placeholder="Tip na dárek, oblíbená věc nebo poznámka…" rows={2} className="sm:col-span-2 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 py-2 text-sm text-white"/>
            </div>
            <div className="mt-3 flex flex-wrap gap-1">{icons.map(icon=><button type="button" key={icon} onClick={()=>setForm({...form,icon})} className={`rounded-lg p-2 text-lg ${form.icon===icon?'bg-[var(--color-accent)]/30':''}`}>{icon}</button>)}</div>
            <div className="mt-3 flex flex-wrap gap-4"><label className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]"><input type="checkbox" checked={form.remind_annually} onChange={event=>setForm({...form,remind_annually:event.target.checked})}/> Každoroční připomenutí</label>{form.kind==='birthday'&&<label className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]"><input type="checkbox" checked={form.is_highlighted} onChange={event=>setForm({...form,is_highlighted:event.target.checked})}/> Zvýraznit v kalendáři</label>}</div>
            <button className="mt-3 min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm text-white">{form.kind==='birthday'?'Uložit narozeniny':'Uložit milník'}</button>
        </form>

        <section className="mt-4 space-y-2">{items.map(item=><article key={item.uuid} className={`flex flex-wrap gap-3 rounded-2xl border bg-[var(--color-bg-card)] p-4 ${item.is_highlighted?'border-amber-300/25':'border-[var(--color-border)]'}`}><span className="text-2xl">{item.icon}</span>{item.media&&<Link href={`/media/${item.media.uuid}`} aria-label={`Otevřít vzpomínku k ${item.title}`} className="h-14 w-14 shrink-0 overflow-hidden rounded-xl border border-[var(--color-border)] bg-black/10">{item.media.thumbnail_url?<img src={item.media.thumbnail_url} alt="" className="h-full w-full object-cover" loading="lazy"/>:<span className="flex h-full items-center justify-center text-lg">{item.media.media_type==='video'?'🎬':'📷'}</span>}</Link>}<div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="text-white">{item.title}</h2>{item.kind==='birthday'&&<span className="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] text-amber-100">{relationshipLabels[item.relationship??'']??'narozeniny'}</span>}</div><p className="text-xs text-[var(--color-text-secondary)]">{new Date(`${item.occurred_on}T12:00:00`).toLocaleDateString('cs-CZ')} · {item.visibility==='private'?'soukromý':'společný'}</p>{item.description&&<p className="mt-2 text-sm text-[var(--color-text-secondary)]">{item.description}</p>}</div><div className="flex items-start gap-2"><button type="button" onClick={()=>openCelebration(item)} className="text-xs text-pink-200">Naplánovat oslavu</button><button type="button" onClick={()=>remove(item)} className="text-xs text-red-300">Smazat</button></div>
            {celebration?.uuid===item.uuid&&<div className="w-full rounded-xl bg-black/10 p-3"><p className="text-xs text-white">Oslava se vytvoří v kalendáři a připomenutí se rozešle zapojeným partnerům.</p><div className="mt-2 grid gap-2 sm:grid-cols-2"><input value={celebration.title} onChange={event=>setCelebration(current=>current?{...current,title:event.target.value}:current)} maxLength={160} aria-label="Název oslavy" className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/><input type="datetime-local" value={celebration.starts_at} onChange={event=>setCelebration(current=>current?{...current,starts_at:event.target.value}:current)} aria-label="Termín oslavy" className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/></div><div className="mt-2 flex gap-2"><input type="number" min="0" max="525600" value={celebration.reminder_minutes} onChange={event=>setCelebration(current=>current?{...current,reminder_minutes:event.target.value}:current)} aria-label="Minut předem" className="min-h-9 w-28 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/><button type="button" disabled={celebrationBusy} onClick={planCelebration} className="min-h-9 rounded-lg bg-[var(--color-accent)] px-3 text-xs text-white disabled:opacity-40">{celebrationBusy?'Plánuji…':'Přidat do kalendáře'}</button></div>{celebration.message&&<p className={`mt-2 text-xs ${celebration.eventUuid?'text-emerald-300':'text-red-300'}`}>{celebration.message}{celebration.eventUuid&&<Link href={`/calendar/events/${celebration.eventUuid}`} className="ml-1 underline">Otevřít akci</Link>}</p>}</div>}
        </article>)}</section>
    </main></AppLayout>;
}
