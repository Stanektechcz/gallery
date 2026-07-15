import axios from 'axios';
import { CalendarPlus, CheckCircle2, FileSearch, Paperclip, Route, Trash2, Upload } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';

type Day = { id:number; date:string; title?:string };
type ReservationData = {
    type:string; title:string; provider?:string|null; reference?:string|null; starts_at?:string|null; ends_at?:string|null;
    origin?:string|null; destination?:string|null; place_name?:string|null; amount?:number|string|null; currency?:string|null; notes?:string|null; confidence?:number;
};
type ReservationImport = {
    uuid:string; status:'needs_review'|'confirmed'; original_name?:string|null; extraction_method:string; processing_error?:string|null;
    extracted_data:ReservationData; confirmed_data?:ReservationData|null; source_excerpt?:string|null; has_file:boolean; created_at:string;
};

const TYPE_LABELS:Record<string,string> = { ticket:'Jízdenka / letenka', accommodation:'Ubytování', activity:'Aktivita / vstupenka', insurance:'Pojištění', other:'Jiný podklad' };
const toInputDate = (value?:string|null) => value ? value.replace(' ', 'T').slice(0, 16) : '';

export default function TripReservationImportPanel({ tripId, days, currency, onChanged }: { tripId:number; days:Day[]; currency:string; onChanged:()=>Promise<void> }) {
    const [items,setItems] = useState<ReservationImport[]>([]);
    const [open,setOpen] = useState(false);
    const [file,setFile] = useState<File|null>(null);
    const [sourceText,setSourceText] = useState('');
    const [busy,setBusy] = useState('');
    const [message,setMessage] = useState('');
    const [selected,setSelected] = useState<ReservationImport|null>(null);
    const [draft,setDraft] = useState<any>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const load = async () => {
        try { const response=await axios.get(`/api/v1/trips/${tripId}/reservation-imports`); setItems(response.data??[]); }
        catch (error:any) { if(error?.response?.status!==503)setMessage('Cestovní podklady se nepodařilo načíst.'); }
    };
    useEffect(()=>{load();},[tripId]);
    const beginReview = (item:ReservationImport) => {
        const data=item.confirmed_data??item.extracted_data??{};
        const date=(data.starts_at??'').slice(0,10);
        const matchingDay=days.find(day=>day.date===date);
        setSelected(item); setOpen(true); setDraft({...data,type:data.type||'other',title:data.title||item.original_name||'Rezervace',starts_at:toInputDate(data.starts_at),ends_at:toInputDate(data.ends_at),amount:data.amount??'',currency:data.currency||currency,trip_day_id:matchingDay?.id??days[0]?.id??'',sync_itinerary:true,sync_calendar:true,reminder_hours:'24,2'});
    };
    const upload = async (event:FormEvent) => {
        event.preventDefault(); if(!file&&!sourceText.trim())return;
        setBusy('upload');setMessage('');
        try {
            const body=new FormData(); if(file)body.append('file',file); if(sourceText.trim())body.append('source_text',sourceText.trim());
            const response=await axios.post(`/api/v1/trips/${tripId}/reservation-imports`,body);
            const imported=response.data.import as ReservationImport;
            setFile(null);setSourceText('');if(inputRef.current)inputRef.current.value='';
            await load(); beginReview(imported);
            setMessage(response.data.duplicate?'Tento podklad už byl nahrán. Otevřel jsem jeho existující kontrolu.':'Podklad je načtený. Před propojením zkontrolujte rozpoznané údaje.');
        } catch(error:any){setMessage(error?.response?.data?.message??'Podklad se nepodařilo načíst.');}
        finally{setBusy('');}
    };
    const confirmImport = async (event:FormEvent) => {
        event.preventDefault();if(!selected||!draft?.title?.trim())return;setBusy(selected.uuid);setMessage('');
        try {
            await axios.put(`/api/v1/trips/${tripId}/reservation-imports/${selected.uuid}/confirm`,{...draft,trip_day_id:draft.trip_day_id?Number(draft.trip_day_id):null,amount:draft.amount===''?null:Number(draft.amount),starts_at:draft.starts_at||null,ends_at:draft.ends_at||null,reminder_hours:String(draft.reminder_hours).split(',').map((value:string)=>Number(value.trim())).filter((value:number)=>Number.isInteger(value)&&value>0)});
            setSelected(null);setDraft(null);await Promise.all([load(),onChanged()]);setMessage('Rezervace je propojená s doklady, itinerářem, kalendářem, remindery i offline kartou.');
        } catch(error:any){setMessage(error?.response?.data?.message??'Údaje se nepodařilo potvrdit.');}
        finally{setBusy('');}
    };
    const remove = async (item:ReservationImport) => {
        if(!window.confirm('Zahodit tento nepotvrzený podklad?'))return;setBusy(item.uuid);
        try{await axios.delete(`/api/v1/trips/${tripId}/reservation-imports/${item.uuid}`);if(selected?.uuid===item.uuid){setSelected(null);setDraft(null);}await load();}
        catch(error:any){setMessage(error?.response?.data?.message??'Podklad nelze odebrat.');}finally{setBusy('');}
    };
    const pending=items.filter(item=>item.status!=='confirmed'); const confirmed=items.filter(item=>item.status==='confirmed');

    return <section className="mb-5 overflow-hidden rounded-3xl border border-violet-400/25 bg-gradient-to-br from-violet-500/10 via-[var(--color-bg-card)] to-sky-500/5">
        <button type="button" onClick={()=>setOpen(value=>!value)} className="flex min-h-16 w-full items-center gap-3 px-4 py-3 text-left sm:px-5"><span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-violet-400/15 text-violet-200"><FileSearch size={19}/></span><span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-white">Chytré cestovní podklady</span><span className="mt-0.5 block text-[10px] leading-relaxed text-[var(--color-text-secondary)]">Jízdenky, ubytování a rezervace se po kontrole propíší do celé cesty.</span></span><span className="shrink-0 rounded-full bg-violet-400/10 px-2 py-1 text-[10px] text-violet-100">{pending.length?`${pending.length} ke kontrole`:`${confirmed.length} potvrzeno`}</span></button>
        {open&&<div className="border-t border-violet-300/15 p-4 sm:p-5">
            <form onSubmit={upload} className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                <label className="flex min-h-20 cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-violet-300/30 bg-black/10 px-3"><Upload size={17} className="shrink-0 text-violet-200"/><span className="min-w-0"><span className="block truncate text-xs text-white">{file?.name??'PDF, obrázek, e-mail nebo text'}</span><span className="mt-1 block text-[9px] text-[var(--color-text-secondary)]">Max. 20 MB · soubor zůstává neveřejný</span></span><input ref={inputRef} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.eml,.csv,.json" onChange={event=>setFile(event.target.files?.[0]??null)} className="hidden"/></label>
                <textarea value={sourceText} onChange={event=>setSourceText(event.target.value)} placeholder="Nebo vložte text potvrzovacího e-mailu…" className="min-h-20 resize-y rounded-2xl border border-violet-300/20 bg-black/10 px-3 py-2 text-xs text-white placeholder:text-[var(--color-text-secondary)]"/>
                <button disabled={busy==='upload'||(!file&&!sourceText.trim())} className="min-h-11 rounded-2xl bg-violet-500 px-5 text-xs font-medium text-white disabled:opacity-40 lg:min-h-20">{busy==='upload'?'Načítám…':'Načíst a zkontrolovat'}</button>
            </form>
            <p className="mt-2 text-[9px] text-violet-100/60">PDF a fotografie lze zdarma číst lokálními nástroji pdftotext/Tesseract. Žádná data se neposílají externí AI službě a automatika nic nepotvrdí bez vás.</p>

            {(pending.length>0||confirmed.length>0)&&<div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{[...pending,...confirmed].map(item=>{const data=item.confirmed_data??item.extracted_data;return <div key={item.uuid} className={`rounded-2xl border p-3 ${item.status==='confirmed'?'border-emerald-400/20 bg-emerald-500/5':'border-amber-400/25 bg-amber-500/5'}`}><div className="flex items-start gap-2"><span className="min-w-0 flex-1"><span className="block truncate text-xs font-medium text-white">{data.title||item.original_name||'Cestovní podklad'}</span><span className="mt-1 block truncate text-[9px] text-[var(--color-text-secondary)]">{TYPE_LABELS[data.type]??'Rezervace'}{data.reference?` · ${data.reference}`:''}</span></span>{item.status==='confirmed'?<CheckCircle2 size={15} className="shrink-0 text-emerald-300"/>:<span className="shrink-0 rounded-full bg-amber-400/10 px-1.5 py-0.5 text-[8px] text-amber-100">kontrola</span>}</div><div className="mt-3 flex gap-1"><button type="button" onClick={()=>beginReview(item)} className="min-h-8 flex-1 rounded-lg border border-white/10 text-[10px] text-white">{item.status==='confirmed'?'Zkontrolovat údaje':'Otevřít kontrolu'}</button>{item.has_file&&<a href={`/api/v1/trips/${tripId}/reservation-imports/${item.uuid}/download`} className="flex min-h-8 items-center justify-center rounded-lg border border-white/10 px-2 text-[var(--color-text-secondary)]" title="Původní soubor"><Paperclip size={12}/></a>}{item.status!=='confirmed'&&<button type="button" disabled={busy===item.uuid} onClick={()=>remove(item)} className="flex min-h-8 items-center justify-center rounded-lg border border-red-400/15 px-2 text-red-300"><Trash2 size={12}/></button>}</div></div>})}</div>}

            {selected&&draft&&<form onSubmit={confirmImport} className="mt-5 rounded-3xl border border-violet-300/25 bg-black/15 p-4"><div className="flex flex-wrap items-start justify-between gap-2"><div><p className="text-sm font-semibold text-white">Kontrola před propojením</p><p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">Rozpoznané hodnoty jsou návrh. Vše lze před uložením opravit.</p></div>{selected.extracted_data?.confidence!=null&&<span className="rounded-full bg-white/5 px-2 py-1 text-[9px] text-violet-100">jistota {Math.round(Number(selected.extracted_data.confidence)*100)} %</span>}</div>
                {selected.processing_error&&<p className="mt-3 rounded-xl border border-amber-400/20 bg-amber-500/10 p-2 text-[10px] text-amber-100">{selected.processing_error} Údaje můžete doplnit ručně.</p>}
                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Typ<select value={draft.type} onChange={event=>setDraft({...draft,type:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-[var(--color-bg-secondary)] px-2 text-xs text-white">{Object.entries(TYPE_LABELS).map(([key,label])=><option key={key} value={key}>{label}</option>)}</select></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)] sm:col-span-2">Název<input required value={draft.title} onChange={event=>setDraft({...draft,title:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-3 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Poskytovatel<input value={draft.provider??''} onChange={event=>setDraft({...draft,provider:event.target.value})} placeholder="RegioJet, Booking…" className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-3 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Kód rezervace<input value={draft.reference??''} onChange={event=>setDraft({...draft,reference:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-3 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Začátek<input type="datetime-local" value={draft.starts_at??''} onChange={event=>setDraft({...draft,starts_at:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-2 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Konec<input type="datetime-local" value={draft.ends_at??''} onChange={event=>setDraft({...draft,ends_at:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-2 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Den itineráře<select value={draft.trip_day_id??''} onChange={event=>setDraft({...draft,trip_day_id:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-[var(--color-bg-secondary)] px-2 text-xs text-white"><option value="">Bez dne</option>{days.map(day=><option key={day.id} value={day.id}>{new Date(`${day.date}T12:00:00`).toLocaleDateString('cs-CZ',{day:'numeric',month:'short'})}{day.title?` · ${day.title}`:''}</option>)}</select></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Odkud<input value={draft.origin??''} onChange={event=>setDraft({...draft,origin:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-3 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Kam<input value={draft.destination??''} onChange={event=>setDraft({...draft,destination:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-3 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Místo / adresa<input value={draft.place_name??''} onChange={event=>setDraft({...draft,place_name:event.target.value})} className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-3 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Cena<div className="mt-1 flex"><input type="number" min="0" step="0.01" value={draft.amount??''} onChange={event=>setDraft({...draft,amount:event.target.value})} className="min-h-10 min-w-0 flex-1 rounded-l-xl border border-white/10 bg-black/10 px-2 text-xs text-white"/><input maxLength={3} value={draft.currency??currency} onChange={event=>setDraft({...draft,currency:event.target.value.toUpperCase()})} className="min-h-10 w-14 rounded-r-xl border border-l-0 border-white/10 bg-black/10 px-2 text-xs text-white"/></div></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)] sm:col-span-2 lg:col-span-3">Poznámka<textarea value={draft.notes??''} onChange={event=>setDraft({...draft,notes:event.target.value})} className="mt-1 min-h-20 w-full rounded-xl border border-white/10 bg-black/10 px-3 py-2 text-xs text-white"/></label>
                    <label className="text-[9px] text-[var(--color-text-secondary)]">Připomenout předem (hodiny)<input value={draft.reminder_hours} onChange={event=>setDraft({...draft,reminder_hours:event.target.value})} placeholder="24,2" className="mt-1 min-h-10 w-full rounded-xl border border-white/10 bg-black/10 px-3 text-xs text-white"/></label>
                </div>
                <div className="mt-4 grid gap-2 sm:grid-cols-2"><label className="flex items-start gap-2 rounded-xl border border-white/10 p-3 text-[10px] text-violet-100"><input type="checkbox" checked={draft.sync_itinerary} onChange={event=>setDraft({...draft,sync_itinerary:event.target.checked})}/><Route size={14} className="shrink-0"/><span><strong className="block text-white">Přidat do itineráře</strong>Čas, místo a cena se stanou upravitelným blokem dne.</span></label><label className="flex items-start gap-2 rounded-xl border border-white/10 p-3 text-[10px] text-violet-100"><input type="checkbox" checked={draft.sync_calendar} onChange={event=>setDraft({...draft,sync_calendar:event.target.checked})}/><CalendarPlus size={14} className="shrink-0"/><span><strong className="block text-white">Kalendář a upozornění</strong>Oba partneři uvidí termín a dostanou zvolená připomenutí.</span></label></div>
                <div className="mt-4 flex flex-col gap-2 sm:flex-row"><button disabled={busy===selected.uuid} className="min-h-11 flex-1 rounded-xl bg-violet-500 px-4 text-xs font-medium text-white disabled:opacity-40">{busy===selected.uuid?'Propojuji…':selected.status==='confirmed'?'Aktualizovat propojené údaje':'Potvrdit a propojit napříč systémem'}</button><button type="button" onClick={()=>{setSelected(null);setDraft(null);}} className="min-h-11 rounded-xl border border-white/10 px-4 text-xs text-[var(--color-text-secondary)]">Zavřít</button></div>
                {selected.source_excerpt&&<details className="mt-3"><summary className="cursor-pointer text-[9px] text-violet-200/70">Ukázat výřez rozpoznaného textu</summary><p className="mt-2 whitespace-pre-wrap rounded-xl bg-black/20 p-3 text-[9px] leading-relaxed text-[var(--color-text-secondary)]">{selected.source_excerpt}</p></details>}
            </form>}
            {message&&<p className={`mt-3 text-[10px] ${message.includes('nepodařilo')||message.includes('nelze')?'text-red-300':'text-emerald-200'}`}>{message}</p>}
        </div>}
    </section>;
}
