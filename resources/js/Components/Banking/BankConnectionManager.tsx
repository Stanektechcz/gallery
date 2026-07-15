import axios from 'axios';
import { Building2, ExternalLink, FileSpreadsheet, LockKeyhole, Plus, RefreshCw, Trash2, Unplug, Upload, WalletCards } from 'lucide-react';
import { ChangeEvent, useCallback, useEffect, useState } from 'react';

type Connection = {
    uuid:string; provider:string; institution_name:string; status:string; sync_enabled:boolean;
    consent_expires_at?:string|null; last_success_at?:string|null; last_error?:string|null;
};
type Account = {
    uuid:string; name:string; institution:string; currency:string; iban_masked?:string|null; is_joint:boolean;
    current_balance?:number|null; available_balance?:number|null; balance_updated_at?:string|null;
    history_available_from?:string|null; transactions_count:number;
};
type Import = { uuid:string; filename:string; status:string; rows_imported:number; rows_duplicate:number; rows_failed:number; period_from?:string|null; period_to?:string|null; created_at?:string };
type Rule = { uuid:string; field:'merchant'|'counterparty'|'description'|'type'; operator:'contains'|'equals'|'starts_with'; pattern:string; category:string; trip_action:'suggest'|'include'|'exclude'; priority:number };
type Overview = { available:boolean; connections:Connection[]; accounts:Account[]; imports:Import[]; rules:Rule[]; transactions_count?:number };
type Institution = { id:string; name:string; logo?:string; transaction_total_days?:number };

export default function BankConnectionManager({ gallerySpaceId, returnTripId, compact = false, onChanged }: {
    gallerySpaceId:number; returnTripId?:number; compact?:boolean; onChanged?:()=>void;
}) {
    const [data,setData]=useState<Overview>({available:true,connections:[],accounts:[],imports:[],rules:[]});
    const [institutions,setInstitutions]=useState<Institution[]>([]);
    const [institutionId,setInstitutionId]=useState('');
    const [busy,setBusy]=useState(''); const [message,setMessage]=useState(''); const [error,setError]=useState('');
    const [rule,setRule]=useState({field:'merchant',operator:'contains',pattern:'',category:'food',trip_action:'suggest'});

    const load=useCallback(async()=>{
        try { const response=await axios.get<Overview>('/api/v1/banking',{params:{gallery_space_id:gallerySpaceId}}); setData(response.data); }
        catch(reason:any){ setError(reason.response?.data?.message??'Bankovní přehled se nepodařilo načíst. Zkontrolujte migrace.'); }
    },[gallerySpaceId]);
    useEffect(()=>{load();},[load]);

    const findInstitutions=async()=>{
        setBusy('institutions');setError('');setMessage('');
        try {
            const response=await axios.get<Institution[]>('/api/v1/banking/institutions',{params:{gallery_space_id:gallerySpaceId,country:'CZ'}});
            setInstitutions(response.data); setInstitutionId(response.data[0]?.id??'');
            if(!response.data.length) setError('Revolut nebyl v nabídce pro české připojení nalezen. Zkontrolujte GoCardless klíče a jejich produkční přístup.');
        } catch(reason:any){setError(reason.response?.data?.message??'Seznam bank se nepodařilo načíst. Nejprve uložte a otestujte GoCardless klíče v administraci.');}
        finally{setBusy('');}
    };
    const connect=async()=>{
        if(!institutionId)return;setBusy('connect');setError('');setMessage('');
        try{
            const response=await axios.post('/api/v1/banking/connections',{gallery_space_id:gallerySpaceId,institution_id:institutionId,country:'CZ',return_trip_id:returnTripId??null});
            window.location.assign(response.data.authorization_url);
        }catch(reason:any){setError(reason.response?.data?.message??'Bezpečné připojení Revolutu se nepodařilo zahájit.');setBusy('');}
    };
    const sync=async(connection:Connection)=>{
        setBusy(`sync-${connection.uuid}`);setError('');setMessage('');
        try{await axios.post(`/api/v1/banking/connections/${connection.uuid}/sync`);await load();onChanged?.();setMessage('Zůstatky, transakce a cestovní výdaje jsou aktuální.');}
        catch(reason:any){setError(reason.response?.data?.message??'Synchronizace se nepodařila.');}
        finally{setBusy('');}
    };
    const disconnect=async(connection:Connection)=>{
        if(!window.confirm('Odpojit automatickou synchronizaci? Již získaná historie, grafy a vazby na cesty zůstanou zachované.'))return;
        setBusy(`disconnect-${connection.uuid}`);setError('');
        try{await axios.delete(`/api/v1/banking/connections/${connection.uuid}`);await load();onChanged?.();setMessage('Automatická synchronizace byla odpojena. Historie zůstala uložená.');}
        catch(reason:any){setError(reason.response?.data?.message??'Připojení se nepodařilo odpojit.');}
        finally{setBusy('');}
    };
    const upload=async(event:ChangeEvent<HTMLInputElement>)=>{
        const file=event.target.files?.[0]; if(!file)return; setBusy('import');setError('');setMessage('');
        try{
            const form=new FormData();form.append('gallery_space_id',String(gallerySpaceId));form.append('statement',file);
            const response=await axios.post('/api/v1/banking/imports',form);
            await load();onChanged?.();
            setMessage(response.data.duplicate_file?'Tento výpis už byl uložen; žádná data se nezdvojila.':`Importováno ${response.data.import.rows_imported} transakcí, ${response.data.import.rows_duplicate} duplicit přeskočeno.`);
        }catch(reason:any){setError(reason.response?.data?.message??'Výpis se nepodařilo importovat.');}
        finally{setBusy('');event.target.value='';}
    };
    const addRule=async()=>{if(!rule.pattern.trim())return;setBusy('rule');setError('');try{await axios.post('/api/v1/banking/rules',{gallery_space_id:gallerySpaceId,...rule,priority:100});setRule({...rule,pattern:''});await load();setMessage('Pravidlo se použije při dalších synchronizacích a importech.');}catch(reason:any){setError(reason.response?.data?.message??'Pravidlo se nepodařilo uložit.');}finally{setBusy('');}};
    const deleteRule=async(item:Rule)=>{setBusy(`rule-${item.uuid}`);setError('');try{await axios.delete(`/api/v1/banking/rules/${item.uuid}`);await load();}catch(reason:any){setError(reason.response?.data?.message??'Pravidlo se nepodařilo odstranit.');}finally{setBusy('');}};
    const active=data.connections.filter(item=>item.sync_enabled&&item.status!=='revoked');
    const money=(value:number|null|undefined,currency:string)=>value===null||value===undefined?'—':new Intl.NumberFormat('cs-CZ',{style:'currency',currency,maximumFractionDigits:2}).format(value);

    return <section className={`rounded-3xl border border-emerald-400/20 bg-gradient-to-br from-emerald-500/10 via-[var(--color-bg-card)] to-[var(--color-bg-card)] ${compact?'p-3 sm:p-4':'p-4 sm:p-5'}`}>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-emerald-200"><WalletCards size={15}/> Společný účet a cesty</p><h2 className="mt-1 text-lg font-semibold text-white">Revolut pouze pro čtení</h2><p className="mt-1 max-w-2xl text-xs leading-relaxed text-[var(--color-text-secondary)]">Aplikace ukládá historii zůstatků a transakcí, automaticky ji propojuje s cestami a rozpočty. Nezískává přihlašovací údaje ani oprávnění odesílat platby.</p></div><span className="inline-flex w-fit items-center gap-1 rounded-full border border-emerald-300/20 bg-emerald-500/10 px-2.5 py-1 text-[10px] text-emerald-100"><LockKeyhole size={11}/> read-only PSD2</span></div>
        {error&&<p className="mt-3 rounded-xl border border-red-400/20 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}{message&&<p className="mt-3 rounded-xl border border-emerald-400/20 bg-emerald-500/10 p-3 text-xs text-emerald-100">{message}</p>}
        {!data.available&&<p className="mt-3 rounded-xl bg-amber-500/10 p-3 text-xs text-amber-100">Bankovní tabulky zatím nejsou dostupné. Spusťte nové databázové migrace.</p>}
        {data.accounts.length>0&&<div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">{data.accounts.map(account=><article key={account.uuid} className="rounded-2xl border border-white/10 bg-black/10 p-3"><div className="flex items-start justify-between gap-2"><div className="min-w-0"><p className="truncate text-sm font-medium text-white">{account.name}</p><p className="truncate text-[10px] text-[var(--color-text-secondary)]">{account.institution}{account.iban_masked?` · ${account.iban_masked}`:''}{account.is_joint?' · společný':''}</p></div><Building2 size={15} className="shrink-0 text-emerald-300"/></div><p className="mt-3 text-xl font-semibold text-white">{money(account.current_balance,account.currency)}</p><div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-[var(--color-text-secondary)]"><span>{account.transactions_count} transakcí</span>{account.history_available_from&&<span>historie od {new Date(`${account.history_available_from}T12:00:00`).toLocaleDateString('cs-CZ')}</span>}{account.balance_updated_at&&<span>stav {new Date(account.balance_updated_at).toLocaleString('cs-CZ')}</span>}</div></article>)}</div>}
        {active.length>0&&<div className="mt-4 space-y-2">{active.map(connection=><div key={connection.uuid} className="flex flex-col gap-2 rounded-xl border border-white/10 bg-black/10 p-3 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-xs font-medium text-white">{connection.institution_name}</p><p className="mt-0.5 text-[10px] text-[var(--color-text-secondary)]">{connection.last_success_at?`Poslední synchronizace ${new Date(connection.last_success_at).toLocaleString('cs-CZ')}`:'Čeká na první synchronizaci'}{connection.consent_expires_at?` · souhlas do ${new Date(connection.consent_expires_at).toLocaleDateString('cs-CZ')}`:''}</p>{connection.last_error&&<p className="mt-1 text-[10px] text-red-200">{connection.last_error}</p>}</div><div className="flex gap-2"><button onClick={()=>sync(connection)} disabled={!!busy} className="inline-flex min-h-9 flex-1 items-center justify-center gap-1 rounded-lg bg-emerald-500 px-3 text-xs font-medium text-white disabled:opacity-50 sm:flex-none"><RefreshCw size={13} className={busy===`sync-${connection.uuid}`?'animate-spin':''}/>Synchronizovat</button><button onClick={()=>disconnect(connection)} disabled={!!busy} title="Odpojit a zachovat historii" className="inline-flex min-h-9 items-center justify-center rounded-lg border border-white/10 px-3 text-[var(--color-text-secondary)] hover:text-white disabled:opacity-50"><Unplug size={14}/></button></div></div>)}</div>}
        {active.length===0&&<div className="mt-4 rounded-2xl border border-dashed border-emerald-300/25 p-3"><div className="flex flex-col gap-2 sm:flex-row sm:items-end"><div className="flex-1"><p className="text-xs font-medium text-white">Automatická synchronizace</p><p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">Připojení proběhne přímo na stránce Revolutu a aplikace obdrží pouze transakce, zůstatky a údaje o účtu.</p>{institutions.length>0&&<select value={institutionId} onChange={event=>setInstitutionId(event.target.value)} className="mt-2 min-h-10 w-full rounded-lg border border-white/10 bg-[var(--color-bg-primary)] px-3 text-xs text-white">{institutions.map(item=><option key={item.id} value={item.id}>{item.name}{item.transaction_total_days?` · historie až ${item.transaction_total_days} dní`:''}</option>)}</select>}</div>{institutions.length===0?<button onClick={findInstitutions} disabled={!!busy} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-emerald-500 px-4 text-xs font-medium text-white disabled:opacity-50"><Building2 size={14}/>{busy==='institutions'?'Načítám…':'Najít Revolut'}</button>:<button onClick={connect} disabled={!!busy||!institutionId} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-emerald-500 px-4 text-xs font-medium text-white disabled:opacity-50">Přejít k bezpečnému připojení <ExternalLink size={13}/></button>}</div></div>}
        <div className="mt-4 flex flex-col gap-2 rounded-2xl border border-white/10 bg-black/10 p-3 sm:flex-row sm:items-center sm:justify-between"><div className="flex items-start gap-2"><FileSpreadsheet size={16} className="mt-0.5 shrink-0 text-emerald-300"/><div><p className="text-xs font-medium text-white">Import Revolut výpisu</p><p className="mt-0.5 text-[10px] text-[var(--color-text-secondary)]">CSV/XLSX je plnohodnotná záloha i alternativa k API. Překryvy a opakované soubory se bezpečně deduplikují.</p></div></div><label className="inline-flex min-h-10 cursor-pointer items-center justify-center gap-2 rounded-xl border border-emerald-300/25 px-4 text-xs text-emerald-100 hover:bg-emerald-500/10"><Upload size={14}/>{busy==='import'?'Importuji…':'Nahrát výpis'}<input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" disabled={!!busy} onChange={upload} className="hidden"/></label></div>
        {!compact&&data.imports.length>0&&<div className="mt-4"><p className="text-xs font-medium text-white">Historie importů</p><div className="mt-2 overflow-x-auto"><table className="w-full min-w-[560px] text-left text-[11px]"><thead className="text-[var(--color-text-secondary)]"><tr><th className="pb-2 font-normal">Soubor</th><th className="pb-2 font-normal">Období</th><th className="pb-2 text-right font-normal">Nové</th><th className="pb-2 text-right font-normal">Duplicity</th><th className="pb-2 text-right font-normal">Chyby</th></tr></thead><tbody>{data.imports.map(item=><tr key={item.uuid} className="border-t border-white/5 text-white"><td className="max-w-[220px] truncate py-2">{item.filename}</td><td className="py-2 text-[var(--color-text-secondary)]">{item.period_from??'—'} – {item.period_to??'—'}</td><td className="py-2 text-right">{item.rows_imported}</td><td className="py-2 text-right">{item.rows_duplicate}</td><td className={`py-2 text-right ${item.rows_failed?'text-red-200':''}`}>{item.rows_failed}</td></tr>)}</tbody></table></div></div>}
        {!compact&&<div className="mt-4 rounded-2xl border border-white/10 bg-black/10 p-3"><p className="text-xs font-medium text-white">Vlastní automatická kategorizace</p><p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">Například vše od „Infinit“ zařadit mezi aktivity. Ručně potvrzené vazby se při synchronizaci nemění.</p><div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-[120px_120px_minmax(140px,1fr)_135px_130px_auto]"><select value={rule.field} onChange={event=>setRule({...rule,field:event.target.value})} className="min-h-10 rounded-lg border border-white/10 bg-[var(--color-bg-primary)] px-2 text-xs text-white"><option value="merchant">Obchodník</option><option value="counterparty">Protistrana</option><option value="description">Popis</option><option value="type">Typ platby</option></select><select value={rule.operator} onChange={event=>setRule({...rule,operator:event.target.value})} className="min-h-10 rounded-lg border border-white/10 bg-[var(--color-bg-primary)] px-2 text-xs text-white"><option value="contains">obsahuje</option><option value="equals">je přesně</option><option value="starts_with">začíná na</option></select><input value={rule.pattern} onChange={event=>setRule({...rule,pattern:event.target.value})} onKeyDown={event=>{if(event.key==='Enter'){event.preventDefault();addRule();}}} placeholder="např. Infinit" className="min-h-10 rounded-lg border border-white/10 bg-[var(--color-bg-primary)] px-3 text-xs text-white"/><select value={rule.category} onChange={event=>setRule({...rule,category:event.target.value})} className="min-h-10 rounded-lg border border-white/10 bg-[var(--color-bg-primary)] px-2 text-xs text-white"><option value="transport">Doprava</option><option value="accommodation">Ubytování</option><option value="food">Jídlo a pití</option><option value="activities">Aktivity</option><option value="insurance">Pojištění</option><option value="other">Ostatní</option></select><select value={rule.trip_action} onChange={event=>setRule({...rule,trip_action:event.target.value})} title="Vazba na cestu" className="min-h-10 rounded-lg border border-white/10 bg-[var(--color-bg-primary)] px-2 text-xs text-white"><option value="suggest">Navrhnout</option><option value="include">Vždy zahrnout</option><option value="exclude">Nezahrnovat</option></select><button onClick={addRule} disabled={busy==='rule'||!rule.pattern.trim()} className="inline-flex min-h-10 items-center justify-center gap-1 rounded-lg bg-emerald-500 px-3 text-xs text-white disabled:opacity-50"><Plus size={13}/>Přidat</button></div>{data.rules.length>0&&<div className="mt-3 flex flex-wrap gap-2">{data.rules.map(item=><span key={item.uuid} className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-[var(--color-bg-card)] px-3 py-1.5 text-[10px] text-white"><span className="text-[var(--color-text-secondary)]">{item.field} {item.operator}</span> „{item.pattern}“ → {item.category} · {item.trip_action==='include'?'zahrnout':item.trip_action==='exclude'?'vyloučit':'navrhnout'}<button onClick={()=>deleteRule(item)} disabled={!!busy} className="text-[var(--color-text-secondary)] hover:text-red-200"><Trash2 size={11}/></button></span>)}</div>}</div>}
    </section>;
}
