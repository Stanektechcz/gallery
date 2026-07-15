import LocationPicker, { LocationValue } from '@/Components/LocationPicker';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { CalendarDays, Clock, CloudRain, Heart, MapPin, RefreshCw, Route, Sparkles, Wallet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Reaction = { user_id:number; user_name?:string; reaction:'love'|'maybe'|'pass'; rating?:number; note?:string };
type Block = { key:string; stage:string; title:string; description:string; icon:string; minutes:number; estimated_cost:number; place_id?:number };
type DateIdea = {
    uuid:string; title:string; summary:string; theme:string; status:string; travel_scope:string; transport_mode:string;
    estimated_cost:number; currency:string; estimated_minutes:number; novelty_percent:number; suggested_starts_at?:string;
    destination?:LocationValue; is_trip_recommended:boolean; my_reaction?:string; reactions:Reaction[]; event_uuid?:string; trip_id?:number;
    plan:{ blocks:Block[]; reasons:string[]; rain_backup?:string; preparation_tasks?:string[]; memory_prompt?:string; weather?:{precipitation_probability?:number;temperature_min?:number;temperature_max?:number;unavailable?:boolean}; budget:{activities:number;transport:number;total:number;limit:number;currency:string;is_estimate:boolean}; route?:{estimated_travel_minutes:number} };
};

const THEMES = [
    ['surprise','✨ Překvap mě'], ['romantic','💞 Romantika'], ['food','🍜 Jídlo'], ['nature','🌿 Příroda'],
    ['culture','🖼️ Kultura'], ['creative','🎨 Tvoření'], ['adventure','🧭 Dobrodružství'], ['relax','🕯️ Odpočinek'], ['low_cost','🪙 Low-cost'],
];

const initialLocation:LocationValue = { location_name:'', latitude:'', longitude:'', location_country:'', location_country_code:'' };
const defaultForm = {
    theme:'surprise', budget_max:'1200', travel_scope:'city', transport_mode:'transit', duration:'evening', time_of_day:'evening',
    preferred_date:'', setting:'any', energy:'medium', food:'any', surprise_level:'2', accessible_only:false, weather_aware:true, new_places_only:false,
};

export default function DateIdeaGenerator({ spaceId }:{ spaceId?:number }) {
    const [form,setForm]=useState(defaultForm); const [location,setLocation]=useState<LocationValue>(initialLocation);
    const [ideas,setIdeas]=useState<DateIdea[]>([]); const [loading,setLoading]=useState(false); const [initialLoading,setInitialLoading]=useState(false);
    const [busy,setBusy]=useState(''); const [error,setError]=useState(''); const [message,setMessage]=useState('');
    const [starts,setStarts]=useState<Record<string,string>>({}); const [tripChoices,setTripChoices]=useState<Record<string,boolean>>({});

    useEffect(()=>{ if(!spaceId)return; setInitialLoading(true); axios.get('/api/v1/date-ideas',{params:{gallery_space_id:spaceId,limit:12}})
        .then(response=>setIdeas(response.data.ideas??[])).catch(()=>{}).finally(()=>setInitialLoading(false)); },[spaceId]);

    const shown=useMemo(()=>ideas.filter(idea=>idea.status!=='dismissed'),[ideas]);
    const replace=(updated:DateIdea)=>setIdeas(current=>current.map(idea=>idea.uuid===updated.uuid?updated:idea));

    const generate=async()=>{
        if(!spaceId)return; setLoading(true); setError(''); setMessage('');
        try {
            const response=await axios.post('/api/v1/date-ideas/generate',{
                gallery_space_id:spaceId,count:4,...form,budget_max:Number(form.budget_max||0),surprise_level:Number(form.surprise_level),
                preferred_date:form.preferred_date||null,destination:form.travel_scope==='home'?initialLocation:location,
            });
            const generated:DateIdea[]=response.data.ideas??[];
            setIdeas(current=>[...generated,...current.filter(old=>!generated.some(fresh=>fresh.uuid===old.uuid))]);
            setStarts(current=>({...current,...Object.fromEntries(generated.map(idea=>[idea.uuid,toLocalInput(idea.suggested_starts_at)]))}));
            setTripChoices(current=>({...current,...Object.fromEntries(generated.map(idea=>[idea.uuid,idea.is_trip_recommended]))}));
            setMessage(response.data.message??(generated.length?`Připraveny ${generated.length} nové neopakované varianty.`:'Zkuste upravit omezení.'));
        } catch(reason:any) { setError(reason?.response?.data?.message??'Randíčka se nepodařilo vygenerovat. Zkuste parametry upravit.'); }
        finally { setLoading(false); }
    };

    const react=async(idea:DateIdea,reaction:'love'|'maybe'|'pass')=>{
        setBusy(`${idea.uuid}:reaction`); setError('');
        try { const response=await axios.patch(`/api/v1/date-ideas/${idea.uuid}/reaction`,{reaction}); replace(response.data); }
        catch(reason:any){setError(reason?.response?.data?.message??'Reakci se nepodařilo uložit.');}
        finally{setBusy('');}
    };

    const plan=async(idea:DateIdea)=>{
        setBusy(`${idea.uuid}:plan`);setError('');setMessage('');
        try{
            const startsAt=starts[idea.uuid]||toLocalInput(idea.suggested_starts_at);
            const response=await axios.post(`/api/v1/date-ideas/${idea.uuid}/plan`,{starts_at:startsAt||undefined,create_trip:tripChoices[idea.uuid]??idea.is_trip_recommended,reminder_minutes:idea.is_trip_recommended?1440:180});
            replace(response.data.idea); setMessage(`„${idea.title}“ je připravené pro oba v kalendáři${response.data.trip_id?' i v plánování cesty':''}.`);
        }catch(reason:any){setError(reason?.response?.data?.message??'Randíčko se nepodařilo naplánovat.');}
        finally{setBusy('');}
    };

    return <section id="date-generator" className="mt-5 overflow-hidden rounded-3xl border border-pink-400/25 bg-gradient-to-br from-pink-500/10 via-[var(--color-bg-card)] to-violet-500/10">
        <div className="p-4 sm:p-5"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p className="text-xs font-medium uppercase tracking-wider text-pink-200">Generátor společných randíček</p><h2 className="mt-1 flex items-center gap-2 text-lg font-semibold text-white"><Sparkles size={19} className="text-pink-300"/>Ne náhodný text, ale plán pro vás dva</h2><p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Kombinuje místo, rozpočet, dopravu, volný kalendář, počasí, uložené podniky i vaše reakce. Stejnou kombinaci znovu nenabídne.</p></div><Link href="/calendar" className="shrink-0 text-sm text-pink-200 hover:text-white">Společný kalendář →</Link></div>

            <div className="mt-5"><p className="mb-2 text-xs font-medium text-[var(--color-text-secondary)]">Na co máte chuť?</p><div className="flex snap-x gap-2 overflow-x-auto pb-2">{THEMES.map(([value,label])=><button key={value} type="button" onClick={()=>setForm({...form,theme:value})} className={`min-h-10 shrink-0 snap-start rounded-full border px-3 text-sm ${form.theme===value?'border-pink-300 bg-pink-500/20 text-white':'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>{label}</button>)}</div></div>

            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label className="text-xs text-[var(--color-text-secondary)]">Dosah randíčka<select value={form.travel_scope} onChange={event=>setForm({...form,travel_scope:event.target.value})} className={inputClass}><option value="home">Doma</option><option value="nearby">Pěšky / blízké okolí</option><option value="city">Po městě a okolí</option><option value="day_trip">Jednodenní výlet</option><option value="weekend">Víkendová cesta</option></select></label>
                <label className="text-xs text-[var(--color-text-secondary)]">Rozpočet pro dva<div className="relative"><input type="number" min="0" step="50" value={form.budget_max} onChange={event=>setForm({...form,budget_max:event.target.value})} className={`${inputClass} pr-14`}/><span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs">Kč</span></div></label>
                <label className="text-xs text-[var(--color-text-secondary)]">Délka<select value={form.duration} onChange={event=>setForm({...form,duration:event.target.value})} className={inputClass}><option value="quick">Rychlé · 60–90 min</option><option value="evening">Večer · 2–3 h</option><option value="half_day">Půl dne</option><option value="full_day">Celý den</option><option value="weekend">Víkend</option></select></label>
                <label className="text-xs text-[var(--color-text-secondary)]">Termín<input type="date" min={new Date().toISOString().slice(0,10)} value={form.preferred_date} onChange={event=>setForm({...form,preferred_date:event.target.value})} className={inputClass}/></label>
            </div>

            {form.travel_scope!=='home'&&<LocationPicker value={location} onChange={setLocation} label="Kam chcete vyrazit? Město, část města, podnik nebo konkrétní místo" compact className="mt-3"/>}

            <details className="mt-4 rounded-2xl border border-[var(--color-border)] bg-black/10 p-3"><summary className="cursor-pointer text-sm font-medium text-white">Doladit atmosféru, dopravu a omezení</summary><div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label className="text-xs text-[var(--color-text-secondary)]">Doprava<select value={form.transport_mode} onChange={event=>setForm({...form,transport_mode:event.target.value})} className={inputClass}><option value="walk">Pěšky</option><option value="bike">Kolo</option><option value="transit">MHD / autobus</option><option value="train">Vlak</option><option value="car">Auto</option></select></label>
                <label className="text-xs text-[var(--color-text-secondary)]">Část dne<select value={form.time_of_day} onChange={event=>setForm({...form,time_of_day:event.target.value})} className={inputClass}><option value="any">Kdykoliv</option><option value="morning">Ráno</option><option value="afternoon">Odpoledne</option><option value="evening">Večer</option></select></label>
                <label className="text-xs text-[var(--color-text-secondary)]">Prostředí<select value={form.setting} onChange={event=>setForm({...form,setting:event.target.value})} className={inputClass}><option value="any">Je nám to jedno</option><option value="indoor">Uvnitř</option><option value="outdoor">Venku</option></select></label>
                <label className="text-xs text-[var(--color-text-secondary)]">Energie<select value={form.energy} onChange={event=>setForm({...form,energy:event.target.value})} className={inputClass}><option value="low">Klid a odpočinek</option><option value="medium">Vyváženě</option><option value="high">Aktivně</option></select></label>
                <label className="text-xs text-[var(--color-text-secondary)]">Jídlo<select value={form.food} onChange={event=>setForm({...form,food:event.target.value})} className={inputClass}><option value="any">Může být součástí</option><option value="none">Bez jídla</option><option value="cafe">Kavárna / dezert</option><option value="dinner">Večeře</option><option value="picnic">Piknik</option></select></label>
                <label className="text-xs text-[var(--color-text-secondary)]">Míra překvapení<select value={form.surprise_level} onChange={event=>setForm({...form,surprise_level:event.target.value})} className={inputClass}><option value="0">Vše ukázat</option><option value="1">Malý twist</option><option value="2">Část zatajit</option><option value="3">Tajný plán</option></select></label>
                <label className="flex min-h-11 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-white"><input type="checkbox" checked={form.weather_aware} onChange={event=>setForm({...form,weather_aware:event.target.checked})}/> Přizpůsobit předpovědi</label>
                <label className="flex min-h-11 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-white"><input type="checkbox" checked={form.accessible_only} onChange={event=>setForm({...form,accessible_only:event.target.checked})}/> Jen bezbariérová místa</label>
                <label className="flex min-h-11 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-white"><input type="checkbox" checked={form.new_places_only} onChange={event=>setForm({...form,new_places_only:event.target.checked})}/> Jen dosud nehodnocená místa</label>
            </div></details>

            <button type="button" onClick={generate} disabled={loading||!spaceId} className="mt-4 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-pink-500 to-violet-500 px-5 text-sm font-semibold text-white shadow-lg shadow-pink-950/20 disabled:opacity-50 sm:w-auto">{loading?<RefreshCw size={17} className="animate-spin"/>:<Sparkles size={17}/>} {loading?'Skládám nové neopakované varianty…':'Vygenerovat 4 unikátní randíčka'}</button>
            {error&&<p role="alert" className="mt-3 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}
            {message&&<p className="mt-3 rounded-xl border border-emerald-400/20 bg-emerald-500/10 p-3 text-sm text-emerald-100">{message}</p>}
        </div>

        <div className="border-t border-pink-400/15 bg-black/10 p-4 sm:p-5"><div className="flex items-center justify-between"><div><h3 className="font-semibold text-white">Návrhy a společná historie</h3><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Srdce ovlivní další generování pro oba; přeskočené varianty se schovají.</p></div>{initialLoading&&<RefreshCw size={16} className="animate-spin text-pink-200"/>}</div>
            <div className="mt-4 grid gap-4 xl:grid-cols-2">{shown.map(idea=><DateIdeaCard key={idea.uuid} idea={idea} busy={busy} start={starts[idea.uuid]??toLocalInput(idea.suggested_starts_at)} createTrip={tripChoices[idea.uuid]??idea.is_trip_recommended} onStart={value=>setStarts({...starts,[idea.uuid]:value})} onTrip={value=>setTripChoices({...tripChoices,[idea.uuid]:value})} onReact={reaction=>react(idea,reaction)} onPlan={()=>plan(idea)}/>)}</div>
            {!initialLoading&&!shown.length&&<div className="mt-4 rounded-2xl border border-dashed border-pink-300/25 p-6 text-center"><Sparkles className="mx-auto text-pink-300"/><p className="mt-2 text-sm text-white">Nastavte náladu a nechte si sestavit první čtyři varianty.</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Můžete začít i bez cíle; generátor použije domácí a obecné aktivity.</p></div>}
        </div>
    </section>;
}

function DateIdeaCard({idea,busy,start,createTrip,onStart,onTrip,onReact,onPlan}:{idea:DateIdea;busy:string;start:string;createTrip:boolean;onStart:(value:string)=>void;onTrip:(value:boolean)=>void;onReact:(reaction:'love'|'maybe'|'pass')=>void;onPlan:()=>void}) {
    const planned=Boolean(idea.event_uuid); const waiting=busy.startsWith(idea.uuid);
    return <article className="flex flex-col rounded-2xl border border-pink-300/20 bg-[var(--color-bg-card)] p-4"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><span className="rounded-full bg-pink-500/10 px-2 py-1 text-[10px] text-pink-100">{themeLabel(idea.theme)}</span><span className="rounded-full bg-violet-500/10 px-2 py-1 text-[10px] text-violet-100">{idea.novelty_percent}% nové</span>{planned&&<span className="rounded-full bg-emerald-500/10 px-2 py-1 text-[10px] text-emerald-200">v kalendáři</span>}</div><h4 className="mt-2 text-base font-semibold text-white">{idea.title}</h4><p className="mt-1 text-xs leading-relaxed text-[var(--color-text-secondary)]">{idea.summary}</p></div><Sparkles size={18} className="shrink-0 text-pink-300"/></div>
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4"><Metric icon={Wallet} value={`${money(idea.estimated_cost)} ${idea.currency}`} label="odhad pro dva"/><Metric icon={Clock} value={durationLabel(idea.estimated_minutes)} label="celkem"/><Metric icon={Route} value={scopeLabel(idea.travel_scope)} label={transportLabel(idea.transport_mode)}/><Metric icon={MapPin} value={idea.destination?.location_name||'flexibilní'} label="lokalita"/></div>
        <div className="mt-3 space-y-2">{idea.plan.blocks.map((block,index)=><div key={block.key} className="grid grid-cols-[28px_minmax(0,1fr)_auto] items-start gap-2 rounded-xl border border-[var(--color-border)] bg-black/10 p-2.5"><span className="text-lg">{block.icon}</span><div><p className="text-sm font-medium text-white">{index+1}. {block.title}</p><p className="mt-0.5 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">{block.description}</p></div><span className="text-[10px] text-[var(--color-text-secondary)]">{block.minutes} min</span></div>)}</div>
        <details className="mt-3 rounded-xl border border-[var(--color-border)] p-3"><summary className="cursor-pointer text-xs font-medium text-pink-100">Proč sedí právě vám · rozpočet · mokrá varianta</summary><div className="mt-2 space-y-2 text-[11px] text-[var(--color-text-secondary)]"><ul className="list-disc space-y-1 pl-4">{idea.plan.reasons.map(reason=><li key={reason}>{reason}</li>)}</ul><p><strong className="text-white">Rozpočet:</strong> aktivity {money(idea.plan.budget.activities)} + doprava {money(idea.plan.budget.transport)} = {money(idea.plan.budget.total)} {idea.currency}. Jde o transparentní odhad, ne živou cenu.</p>{idea.plan.weather&&!idea.plan.weather.unavailable&&<p><CloudRain size={12} className="mr-1 inline"/>Předpověď: {idea.plan.weather.temperature_min}–{idea.plan.weather.temperature_max} °C · déšť {idea.plan.weather.precipitation_probability}% · Open-Meteo.</p>}<p><strong className="text-white">Při dešti:</strong> {idea.plan.rain_backup}</p><p><strong className="text-white">Vzpomínka:</strong> {idea.plan.memory_prompt}</p></div></details>
        <div className="mt-auto pt-4">{idea.reactions.length>0&&<p className="mb-2 text-[10px] text-[var(--color-text-secondary)]">{idea.reactions.map(item=>`${item.user_name??'Partner'}: ${item.reaction==='love'?'chci':item.reaction==='maybe'?'možná':'přeskočit'}`).join(' · ')}</p>}<div className="flex flex-wrap gap-2"><button disabled={waiting||planned} onClick={()=>onReact('love')} className={`min-h-9 rounded-lg border px-3 text-xs ${idea.my_reaction==='love'?'border-pink-300 bg-pink-500/20 text-white':'border-pink-400/30 text-pink-100'}`}><Heart size={13} className="mr-1 inline"/>Chci</button><button disabled={waiting||planned} onClick={()=>onReact('maybe')} className={`min-h-9 rounded-lg border px-3 text-xs ${idea.my_reaction==='maybe'?'border-amber-300 bg-amber-500/15 text-white':'border-amber-400/25 text-amber-100'}`}>🤔 Možná</button><button disabled={waiting||planned} onClick={()=>onReact('pass')} className="min-h-9 rounded-lg border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)]">Přeskočit</button></div>
            {planned?<div className="mt-3 flex flex-col gap-2 sm:flex-row"><Link href={`/calendar/events/${idea.event_uuid}`} className="min-h-10 flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-center text-sm text-white"><CalendarDays size={14} className="mr-1 inline"/>Otevřít společný plán</Link>{idea.trip_id&&<Link href={`/trips/${idea.trip_id}/plan`} className="min-h-10 rounded-lg border border-emerald-400/30 px-3 py-2 text-center text-sm text-emerald-100">Itinerář cesty →</Link>}</div>:<div className="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]"><input type="datetime-local" value={start} onChange={event=>onStart(event.target.value)} className={inputClass}/><button disabled={waiting||!start} onClick={onPlan} className="min-h-11 rounded-lg bg-pink-500 px-4 text-sm font-medium text-white disabled:opacity-50">{busy===`${idea.uuid}:plan`?'Plánuji…':'Naplánovat pro oba'}</button>{idea.is_trip_recommended&&<label className="sm:col-span-2 flex items-start gap-2 rounded-lg border border-teal-400/20 bg-teal-500/5 p-2 text-[11px] text-teal-100"><input type="checkbox" checked={createTrip} onChange={event=>onTrip(event.target.checked)} className="mt-0.5"/><span><strong className="block">Vytvořit i cestu</strong>Itinerář, přípravy, rozpočet a místo budou propojené s touto kalendářovou akcí.</span></label>}</div>}
        </div>
    </article>;
}

function Metric({icon:Icon,value,label}:{icon:any;value:string;label:string}) { return <div className="min-w-0 rounded-lg bg-black/10 p-2"><Icon size={13} className="text-pink-200"/><p className="mt-1 truncate text-xs font-medium text-white" title={value}>{value}</p><p className="truncate text-[9px] text-[var(--color-text-secondary)]">{label}</p></div>; }
function money(value:number){return Math.round(Number(value||0)).toLocaleString('cs-CZ');}
function durationLabel(minutes:number){if(minutes>=1440)return `${Math.round(minutes/1440)} dny`;if(minutes>=60)return `${Math.floor(minutes/60)} h ${minutes%60?`${minutes%60} min`:''}`.trim();return `${minutes} min`;}
function themeLabel(theme:string){return Object.fromEntries(THEMES)[theme]??theme;}
function scopeLabel(scope:string){return ({home:'Doma',nearby:'V okolí',city:'Po městě',day_trip:'Jednodenně',weekend:'Víkend'} as Record<string,string>)[scope]??scope;}
function transportLabel(mode:string){return ({walk:'pěšky',bike:'na kole',transit:'MHD / bus',car:'autem',train:'vlakem'} as Record<string,string>)[mode]??mode;}
function toLocalInput(value?:string){if(!value)return '';const date=new Date(value);if(Number.isNaN(date.getTime()))return '';const local=new Date(date.getTime()-date.getTimezoneOffset()*60000);return local.toISOString().slice(0,16);}
const inputClass='mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white focus:border-pink-400 focus:outline-none';
