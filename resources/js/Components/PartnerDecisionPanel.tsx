import { Link } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle2, ExternalLink, HeartHandshake, LoaderCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

export type PartnerDecisionType = 'date_idea' | 'entertainment_title' | 'viewing_date' | 'poll';
export interface PartnerDecisionItem {
    key:string; type:PartnerDecisionType; source_key:string; title:string; description?:string|null; context:string;
    due_at?:string|null; cover_url?:string|null; href:string; accent:'pink'|'violet'|'teal';
    options:Array<{value:string;label:string;tone:'positive'|'neutral'|'negative'|'choice'}>;
}
export interface PartnerDecisionSnapshot {
    space:{id:number;name:string}; items:PartnerDecisionItem[];
    summary:{total:number;date_ideas:number;watchlist:number;polls:number};
    available_sources:Record<string,boolean>; partially_available:boolean;
}

const tone:Record<string,string>={
    positive:'border-emerald-400/35 bg-emerald-500/10 text-emerald-100 hover:bg-emerald-500/20',
    neutral:'border-amber-300/25 bg-amber-500/5 text-amber-100 hover:bg-amber-500/15',
    negative:'border-white/10 text-[var(--color-text-secondary)] hover:border-red-300/25 hover:text-red-100',
    choice:'border-teal-300/25 bg-teal-500/5 text-teal-100 hover:bg-teal-500/15',
};

export default function PartnerDecisionPanel({spaceId,initialData=null,compact=false}:{spaceId?:number|null;initialData?:PartnerDecisionSnapshot|null;compact?:boolean}){
    const [data,setData]=useState<PartnerDecisionSnapshot|null>(initialData);
    const [loading,setLoading]=useState(!initialData);const [busy,setBusy]=useState('');const [error,setError]=useState('');const [notice,setNotice]=useState('');
    const load=useCallback(async()=>{if(!spaceId&&!initialData)return;setLoading(true);setError('');try{const response=await axios.get<PartnerDecisionSnapshot>('/api/v1/coordination/decisions',{params:{gallery_space_id:spaceId||initialData?.space.id,limit:compact?5:20}});setData(response.data);}catch(reason:any){setError(reason.response?.data?.message??'Společná rozhodnutí se nepodařilo načíst.');}finally{setLoading(false);}},[spaceId,initialData?.space.id,compact]);
    useEffect(()=>{if(!initialData)void load();},[initialData,load]);
    const respond=async(item:PartnerDecisionItem,responseValue:string)=>{setBusy(item.key);setError('');setNotice('');try{const response=await axios.put<PartnerDecisionSnapshot>(`/api/v1/coordination/decisions/${item.type}/${item.source_key}`,{gallery_space_id:data?.space.id??spaceId,response:responseValue});setData(response.data);setNotice('Vaše volba je uložená v původní funkci a partner ji uvidí ve svém přehledu.');}catch(reason:any){setError(reason.response?.data?.message??'Volbu se nepodařilo uložit.');}finally{setBusy('');}};
    const due=(value?:string|null)=>value?new Date(value).toLocaleString('cs-CZ',{weekday:'short',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}):null;

    if(loading&&!data)return <section className="flex min-h-28 items-center justify-center rounded-3xl border border-violet-400/20 bg-[var(--color-bg-card)]"><LoaderCircle size={21} className="animate-spin text-violet-300"/></section>;
    if(compact&&data?.items.length===0)return null;
    if(!data)return error?<p className="rounded-2xl border border-red-400/20 bg-red-500/10 p-4 text-sm text-red-100">{error}</p>:null;
    const items=data.items.slice(0,compact?3:20);

    return <section id="partner-decisions" className="scroll-mt-24 rounded-3xl border border-violet-400/25 bg-gradient-to-br from-violet-500/10 via-[var(--color-bg-card)] to-pink-500/5 p-4 sm:p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-violet-200"><HeartHandshake size={15}/>Rozhodnout spolu</p><h2 className="mt-1 font-semibold text-white">Nápady, filmy, termíny a ankety na jednom místě</h2><p className="mt-1 text-xs leading-relaxed text-[var(--color-text-secondary)]">Volba se ukládá přímo k randíčku, watchlistu nebo hlasování. Po shodě pokračuje stejný záznam do kalendáře a životního cyklu zážitku.</p></div><div className="flex shrink-0 flex-wrap gap-1.5 text-[10px]"><Pill value={data.summary.date_ideas} label="randíčka"/><Pill value={data.summary.watchlist} label="watchlist"/><Pill value={data.summary.polls} label="ankety"/></div></div>
        {notice&&<p className="mt-3 rounded-xl bg-emerald-500/10 p-3 text-xs text-emerald-100">{notice}</p>}{error&&<p className="mt-3 rounded-xl bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}
        <div className={`mt-4 grid gap-3 ${compact?'lg:grid-cols-3':'xl:grid-cols-2'}`}>{items.map(item=><article key={item.key} className="flex min-w-0 gap-3 rounded-2xl border border-white/10 bg-black/10 p-3 sm:p-4">{item.cover_url&&<img src={item.cover_url} alt="" loading="lazy" decoding="async" className="h-24 w-16 shrink-0 rounded-xl object-cover"/>}<div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-2"><div className="min-w-0"><h3 className="truncate text-sm font-medium text-white">{item.title}</h3><p className="mt-0.5 text-[10px] text-violet-100">{item.context}{due(item.due_at)?` · ${due(item.due_at)}`:''}</p></div><Link href={item.href} aria-label={`Otevřít ${item.title}`} className="shrink-0 text-[var(--color-text-secondary)] hover:text-white"><ExternalLink size={14}/></Link></div>{!compact&&item.description&&<p className="mt-2 line-clamp-2 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">{item.description}</p>}<div className="mt-3 flex flex-wrap gap-1.5">{item.options.map(option=><button key={option.value} disabled={busy===item.key} onClick={()=>respond(item,option.value)} className={`min-h-9 rounded-lg border px-2.5 text-[10px] font-medium disabled:opacity-40 ${tone[option.tone]}`}>{busy===item.key?'Ukládám…':option.label}</button>)}</div></div></article>)}</div>
        {items.length===0&&<div className="mt-4 flex items-center gap-3 rounded-2xl bg-emerald-500/10 p-4 text-sm text-emerald-100"><CheckCircle2 size={20}/><div><strong>Máte rozhodnuto.</strong><p className="mt-0.5 text-xs text-emerald-100/75">Nové návrhy se zde objeví automaticky, ať vzniknou v plánování, randíčkách nebo watchlistu.</p></div></div>}
        {data.partially_available&&!compact&&<p className="mt-3 text-[10px] text-amber-100">Některý zdroj rozhodnutí čeká na databázovou migraci; ostatní zdroje fungují dál samostatně.</p>}
        {!compact&&<div className="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-[11px]"><Link href="/date-ideas" className="text-pink-200">Generovat randíčko →</Link><Link href="/watchlist" className="text-violet-200">Přidat film nebo seriál →</Link><Link href="/planning" className="text-teal-200">Vytvořit anketu →</Link></div>}
    </section>;
}
function Pill({value,label}:{value:number;label:string}){return <span className="rounded-full bg-violet-500/10 px-2.5 py-1 text-violet-100">{value} {label}</span>}
