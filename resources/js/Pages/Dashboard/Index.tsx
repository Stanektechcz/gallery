import AppLayout from '@/Layouts/AppLayout';
import PartnerPulsePanel, { CoordinationAction, PartnerPulse } from '@/Components/PartnerPulsePanel';
import PartnerDecisionPanel, { PartnerDecisionSnapshot } from '@/Components/PartnerDecisionPanel';
import ReminderActionPanel, { ActionableReminder } from '@/Components/ReminderActionPanel';
import OnboardingChecklist from '@/Components/OnboardingChecklist';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Album, CalendarDays, Check, ChefHat, Clock, FolderOpen, Heart, Images, Map, MapPin, RefreshCw, Route, Sparkles, Star, TrendingUp, Upload } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface DashboardData {
    generated_at?: string;
    greeting: string;
    user_name: string;
    pending_uploads: number;
    pinned_views: Array<{ id: number; name: string; icon?: string; view_type: string }>;
    upcoming_trip: { id: number; name: string; start_date: string; end_date: string; status: string; finance?:{planned:number;actual:number}; readiness?:{packing_total:number;packing_packed:number;essential_missing:number}; savings_goal?:{target_amount:number;saved_amount:number;monthly_contribution?:number|null;currency:string;percent:number}|null; preparation?:{status:'ready'|'in_progress'|'attention';score:number;next_item?:{title:string;at:string}|null;actions:Array<{key:string;title:string;due_at:string;priority:string}>;summary:{actions_total:number;critical_total:number;risky_connections:number}}; bank_finance?:{available:boolean;connected:boolean;spent_by_currency:Record<string,number>;refunds_by_currency:Record<string,number>;suggested_count:number;confirmed_count:number;balances:Array<{name:string;currency:string;before:{amount:number|null;estimated:boolean};after:{amount:number|null;estimated:boolean};change:number|null}>} } | null;
    finance_hub: { available:boolean; transactions_count?:number; accounts:Array<{uuid:string;name:string;currency:string;current_balance?:number|null;transactions_count:number}> };
    action_inbox: Array<{ key:string; label:string; count:number; href:string; tone:'violet'|'teal'|'sky'|'pink'|'emerald' }>;
    partner_hub: { space_id:number; album_suggestion?:{fingerprint:string;title:string;reason:string;media_count:number;photo_count:number;video_count:number;context?:{type:string;name:string}|null}|null; milestones: Array<{ uuid:string; title:string; icon:string; kind?:string; relationship?:string|null; person_name?:string|null; is_highlighted?:boolean; days_until:number; next_anniversary:string }>; reminders?:ActionableReminder[]; next_event?:{uuid:string;title:string;starts_at:string;place_name?:string|null;trip_id?:number|null;open_tasks_count?:number;planning_items_count?:number}|null; next_actions?:NextAction[]; coordination?:PartnerPulse|null; decisions?:PartnerDecisionSnapshot|null; reflection_prompt?:{id:number;name:string;end_date:string}|null; event_reflection_prompt?:{uuid:string;title:string;starts_at:string}|null; experience_recommendation?:ExperienceRecommendation|null; experience_follow_up?:ExperienceFollowUp|null; date_follow_up?:{uuid:string;title:string;event_uuid:string;starts_at:string;needs_feedback:boolean;needs_memory:boolean}|null; recipe?:{kind:'planned'|'suggestion';uuid:string;title:string;planned_for?:string;servings?:number;times_cooked?:number}|null; memory_evening?:{uuid:string;title:string;status:'planned'|'active';scheduled_for:string;event_uuid?:string|null;media_count:number;selected_count:number}|null; date_idea?:{uuid:string;title:string;summary:string;estimated_cost:number;currency:string;suggested_starts_at?:string|null;destination?:string|null;status:'saved'|'generated'}|null };
}

type NextAction = CoordinationAction;
interface ExperienceRecommendation { id:number; title:string; place_name?:string; kind:'return'|'discover'; reason:string; review_average?:number|null; suggested_starts_at:string; estimated_visit_minutes:number; top_item?:{name:string}|null; next_time_note?:string|null; }
interface ExperienceFollowUp { uuid:string; title:string; starts_at:string; progress_percent:number; next_action:'add_media'|'save_memory'|'review_place'|'reflect'; place?:{id:number;name:string}|null; }
const FOLLOW_UP_LABEL:Record<string,string> = {add_media:'Doplnit fotografie nebo videa',save_memory:'Uložit společnou vzpomínku',review_place:'Ohodnotit navštívený podnik',reflect:'Dopsat, jaké to bylo'};
const ACTION_LABEL: Record<CoordinationAction['type'], string> = { shared_todo: 'úkol', event_task: 'akce', packing_item: 'balení', planning_item: 'podklad', trip_document: 'doklad', gift: 'dárek', settlement: 'finance' };

interface Props {
    data: DashboardData | null;
}

function DashCard({ icon: Icon, label, children, href, color = 'var(--color-accent)' }: {
    icon: any; label: string; children: React.ReactNode; href?: string; color?: string;
}) {
    const inner = (
        <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 hover:border-[var(--color-accent)]/40 transition-colors">
            <div className="flex items-center gap-2 mb-3">
                <div className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ backgroundColor: color + '22' }}>
                    <Icon size={14} style={{ color }} />
                </div>
                <span className="text-xs font-medium text-[var(--color-text-secondary)] uppercase tracking-wider">{label}</span>
            </div>
            {children}
        </div>
    );

    return href ? <Link href={href}>{inner}</Link> : inner;
}

export default function DashboardIndex({ data }: Props) {
    const [milestone, setMilestone] = useState({ title: '', occurred_on: '' });
    const [milestoneSaved, setMilestoneSaved] = useState(false);
    const [nextActions, setNextActions] = useState<NextAction[]>(data?.partner_hub?.next_actions ?? []);
    const [actionBusy, setActionBusy] = useState('');
    const [actionError, setActionError] = useState('');
    const [experienceBusy, setExperienceBusy] = useState(false);
    const [experienceError, setExperienceError] = useState('');
    const [plannedExperience, setPlannedExperience] = useState<{uuid:string;title:string}|null>(null);
    const [dashboardRefreshing, setDashboardRefreshing] = useState(false);
    useEffect(() => { setNextActions(data?.partner_hub?.next_actions ?? []); }, [data?.partner_hub?.next_actions]);
    const refreshDashboard = () => router.reload({ only: ['data'], onStart: () => setDashboardRefreshing(true), onFinish: () => setDashboardRefreshing(false) });
    useEffect(() => {
        const refreshWhenVisible = () => { if (document.visibilityState === 'visible' && navigator.onLine) router.reload({ only: ['data'] }); };
        const timer = window.setInterval(refreshWhenVisible, 120_000);
        window.addEventListener('focus', refreshWhenVisible);
        return () => { window.clearInterval(timer); window.removeEventListener('focus', refreshWhenVisible); };
    }, []);
    if (!data) {
        return (
            <AppLayout>
                <Head title="Domů" />
                <div className="flex items-center justify-center h-full text-[var(--color-text-secondary)]">
                    <p>Galerie není nakonfigurována.</p>
                </div>
            </AppLayout>
        );
    }

    const hour = new Date().getHours();
    const emoji = hour < 12 ? '☀️' : hour < 18 ? '🌤️' : '🌙';
    const addMilestone = async (event: FormEvent) => { event.preventDefault(); if (!milestone.title || !milestone.occurred_on) return; await axios.post('/api/v1/relationship-milestones', { gallery_space_id: data.partner_hub.space_id, ...milestone, visibility: 'shared', remind_annually: true }); setMilestone({title:'',occurred_on:''}); setMilestoneSaved(true); setTimeout(() => setMilestoneSaved(false), 2500); };
    const completeAction = async (action: NextAction) => {
        setActionBusy(action.key); setActionError('');
        try {
            const response = await axios.patch<PartnerPulse>(`/api/v1/coordination/actions/${action.type}/${action.source_key}`, { gallery_space_id: data.partner_hub.space_id, completed: true });
            setNextActions(response.data.actions.slice(0, 5));
        } catch (reason:any) { setActionError(reason.response?.data?.message ?? 'Krok se nepodařilo uložit.'); }
        finally { setActionBusy(''); }
    };
    const planExperience = async (idea: ExperienceRecommendation) => {
        setExperienceBusy(true); setExperienceError('');
        try {
            const response = await axios.post(`/api/v1/places/${idea.id}/plans`, {
                starts_at: idea.suggested_starts_at,
                duration_minutes: idea.estimated_visit_minutes,
                reminder_minutes: 1440,
                from_recommendation: true,
                recommendation_reason: idea.reason,
                recommended_item: idea.top_item?.name ?? null,
                notes: idea.next_time_note ?? null,
            });
            setPlannedExperience({uuid:response.data.event_uuid,title:idea.title});
        } catch (reason:any) { setExperienceError(reason.response?.data?.message ?? 'Společný zážitek se nepodařilo naplánovat.'); }
        finally { setExperienceBusy(false); }
    };

    return (
        <AppLayout>
            <Head title="Domů" />

            <div className="w-full p-4 sm:p-6 lg:p-8 space-y-5 pb-8">
                <OnboardingChecklist />

                {/* Greeting */}
                <div className="flex flex-col gap-3 pt-2 sm:flex-row sm:items-start sm:justify-between">
                    <div><h1 className="text-2xl font-bold text-white">{data.greeting}, {data.user_name} {emoji}</h1><p className="mt-1 text-sm text-[var(--color-text-secondary)]">{new Date().toLocaleDateString('cs-CZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}{data.generated_at ? ` · aktualizováno ${new Date(data.generated_at).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' })}` : ''}</p></div>
                    <button type="button" onClick={refreshDashboard} disabled={dashboardRefreshing} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-sm text-white hover:bg-white/5 disabled:opacity-50"><RefreshCw size={15} className={dashboardRefreshing ? 'animate-spin' : ''}/>{dashboardRefreshing ? 'Aktualizuji…' : 'Aktualizovat'}</button>
                </div>
                <PartnerPulsePanel spaceId={data.partner_hub.space_id} initialPulse={data.partner_hub.coordination} compact />
                <PartnerDecisionPanel spaceId={data.partner_hub.space_id} initialData={data.partner_hub.decisions} compact />
                <ReminderActionPanel initialItems={data.partner_hub.reminders ?? []} compact hideWhenEmpty />

                {data.action_inbox.length > 0 && <section className="rounded-3xl border border-violet-400/25 bg-gradient-to-br from-violet-500/10 to-[var(--color-bg-card)] p-4 sm:p-5"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-medium uppercase tracking-wider text-violet-200">Neztratit ze zřetele</p><h2 className="mt-1 font-semibold text-white">Aktuální věci čekající na zařazení</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Pouze otevřené nebo budoucí položky. Historie sem nepatří.</p></div><Link href="/inbox" className="inline-flex min-h-10 shrink-0 items-center rounded-xl border border-violet-300/30 px-3 text-xs font-medium text-violet-100 hover:bg-violet-400/10">Otevřít inbox</Link></div><div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">{data.action_inbox.map(item => <Link key={item.key} href={item.href} className="flex min-h-16 items-center justify-between gap-3 rounded-2xl border border-white/10 bg-black/10 px-3 py-2.5 hover:border-violet-300/40 hover:bg-violet-400/10"><span className="min-w-0 text-sm text-white">{item.label}</span><span className="flex h-8 min-w-8 shrink-0 items-center justify-center rounded-full bg-violet-400/15 px-2 text-sm font-semibold text-violet-100">{item.count}</span></Link>)}</div></section>}

                {nextActions.length > 0 && <section className="rounded-3xl border border-teal-400/25 bg-gradient-to-br from-teal-500/10 to-[var(--color-bg-card)] p-4 sm:p-5"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-medium uppercase tracking-wider text-teal-200">Dnes stačí toto</p><h2 className="mt-1 font-semibold text-white">Společný krok bez hledání v aplikaci</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Úkoly, kalendář, balení, podklady, doklady, dárky i vyrovnání cest jsou v jednom pořadí.</p></div><Check size={20} className="shrink-0 text-teal-300"/></div><div className="mt-4 space-y-2">{nextActions.map(action => <div key={action.key} className="flex items-center gap-3 rounded-xl border border-teal-400/15 bg-black/10 p-3"><button aria-label={`Označit ${action.title} jako hotové`} disabled={actionBusy === action.key} onClick={() => completeAction(action)} className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-teal-300/50 text-teal-200 hover:bg-teal-400/15 disabled:opacity-40"><Check size={15}/></button><div className="min-w-0 flex-1"><Link href={action.href} className="block truncate text-sm text-white hover:text-teal-200">{action.title}</Link><p className={`mt-0.5 truncate text-xs ${action.is_overdue?'text-amber-200':'text-[var(--color-text-secondary)]'}`}>{action.context}{action.assigned_to ? ` · ${action.assigned_to.name}` : ' · bez přiřazení'}{action.due_at ? ` · ${new Date(action.due_at).toLocaleDateString('cs-CZ', { day:'numeric', month:'short' })}` : ''}</p></div><span className="shrink-0 rounded-full bg-teal-400/10 px-2 py-1 text-[10px] text-teal-100">{ACTION_LABEL[action.type]}</span></div>)}</div>{actionError && <p className="mt-3 text-xs text-red-300">{actionError}</p>}</section>}
                {!data.upcoming_trip && !data.partner_hub?.next_event && nextActions.length === 0 && data.action_inbox.length === 0 && (data.partner_hub?.milestones?.length ?? 0) === 0 && (
                    <section className="rounded-3xl border border-dashed border-[var(--color-border)] bg-[var(--color-bg-card)] p-5 text-center">
                        <CalendarDays className="mx-auto text-[var(--color-accent)]" size={24} />
                        <h2 className="mt-3 font-semibold text-white">Máte volno na nové plány</h2>
                        <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Na dashboardu nejsou žádná stará data. Vyberte si, co chcete společně začít.</p>
                        <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                            <Link href="/calendar" className="inline-flex min-h-10 items-center justify-center rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-white">Naplánovat akci</Link>
                            <Link href="/trips" className="inline-flex min-h-10 items-center justify-center rounded-xl border border-teal-400/35 px-4 text-sm font-medium text-teal-100">Přidat cestu</Link>
                            <Link href="/planning#todos" className="inline-flex min-h-10 items-center justify-center rounded-xl border border-emerald-400/35 px-4 text-sm font-medium text-emerald-100">Vytvořit nákup</Link>
                            <Link href="/?assistant=1" className="inline-flex min-h-10 items-center justify-center rounded-xl border border-violet-400/35 px-4 text-sm font-medium text-violet-100">Napsat pomocníkovi</Link>
                        </div>
                    </section>
                )}
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2"><DashCard icon={TrendingUp} label="Společné finance" href="/finances" color="#10b981">{data.finance_hub?.accounts?.length?<><div className="flex flex-wrap gap-x-5 gap-y-2">{data.finance_hub.accounts.map(account=><div key={account.uuid}><p className="text-base font-semibold text-white">{account.current_balance==null?'—':new Intl.NumberFormat('cs-CZ',{style:'currency',currency:account.currency,maximumFractionDigits:2}).format(account.current_balance)}</p><p className="text-[10px] text-[var(--color-text-secondary)]">{account.name} · {account.transactions_count} transakcí</p></div>)}</div><p className="mt-3 text-xs text-emerald-200">Přehledy, grafy a platby cest →</p></>:<><p className="text-base font-semibold text-white">Nahrát výpis společného účtu</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Importujte export CSV, XLS nebo XLSX a zobrazte zůstatky, grafy i cestovní výdaje.</p><p className="mt-3 text-xs text-emerald-200">Nahrát výpis a otevřít přehled →</p></>}</DashCard></div>

                {(data.upcoming_trip || data.pinned_views?.length > 0 || data.partner_hub?.next_event || data.partner_hub?.reflection_prompt || data.partner_hub?.event_reflection_prompt || data.partner_hub?.experience_recommendation || data.partner_hub?.experience_follow_up || data.partner_hub?.date_follow_up || data.partner_hub?.recipe || data.partner_hub?.memory_evening || data.partner_hub?.date_idea || data.partner_hub?.album_suggestion) && (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        {data.partner_hub?.experience_follow_up && <DashCard icon={Heart} label="Dokončit společný zážitek" href={`/calendar/events/${data.partner_hub.experience_follow_up.uuid}`} color="#ec4899"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="truncate text-base font-semibold text-white">{data.partner_hub.experience_follow_up.title}</p><p className="mt-1 text-xs text-pink-100">{FOLLOW_UP_LABEL[data.partner_hub.experience_follow_up.next_action] ?? 'Dokončit vzpomínku'}</p></div><span className="shrink-0 rounded-full bg-pink-500/10 px-2 py-1 text-xs text-pink-100">{data.partner_hub.experience_follow_up.progress_percent}%</span></div><div className="mt-3 h-1.5 overflow-hidden rounded-full bg-black/20"><div className="h-full rounded-full bg-pink-400" style={{width:`${data.partner_hub.experience_follow_up.progress_percent}%`}}/></div><p className="mt-3 text-xs text-pink-200">Pokračovat ve stejné akci →</p></DashCard>}
                        {data.partner_hub?.date_follow_up && <DashCard icon={Heart} label="Dokončit naše randíčko" href={`/calendar/events/${data.partner_hub.date_follow_up.event_uuid}`} color="#ec4899"><p className="text-base font-semibold text-white">{data.partner_hub.date_follow_up.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Proběhlo {new Date(data.partner_hub.date_follow_up.starts_at).toLocaleDateString('cs-CZ',{day:'numeric',month:'long'})}. Zůstává ve stejné akci, dokud z něj nevznikne úplná vzpomínka.</p><div className="mt-3 flex flex-wrap gap-2">{data.partner_hub.date_follow_up.needs_feedback&&<span className="rounded-full bg-amber-500/10 px-2 py-1 text-[10px] text-amber-100">doplnit můj dojem</span>}{data.partner_hub.date_follow_up.needs_memory&&<span className="rounded-full bg-sky-500/10 px-2 py-1 text-[10px] text-sky-100">vybrat fotky a vzpomínku</span>}</div><p className="mt-3 text-xs text-pink-200">Dokončit životní cyklus zážitku →</p></DashCard>}
                        {data.partner_hub?.recipe && <DashCard icon={ChefHat} label={data.partner_hub.recipe.kind==='planned'?'Naše další vaření':'Tip z naší kuchařky'} href={`/recipes/${data.partner_hub.recipe.uuid}`} color="#f59e0b"><p className="text-base font-semibold text-white">{data.partner_hub.recipe.title}</p>{data.partner_hub.recipe.kind==='planned'&&data.partner_hub.recipe.planned_for?<p className="mt-1 text-xs text-[var(--color-text-secondary)]">{new Date(data.partner_hub.recipe.planned_for).toLocaleString('cs-CZ',{weekday:'long',day:'numeric',month:'long',hour:'2-digit',minute:'2-digit'})} · {data.partner_hub.recipe.servings} porcí</p>:<p className="mt-1 text-xs text-[var(--color-text-secondary)]">{data.partner_hub.recipe.times_cooked?`Už jste společně vařili ${data.partner_hub.recipe.times_cooked}×.`:'Připravte si první společné vaření.'}</p>}<p className="mt-3 text-xs text-amber-200">Otevřít recept a kuchařský režim →</p></DashCard>}
                        {data.partner_hub?.memory_evening && <DashCard icon={Heart} label={data.partner_hub.memory_evening.status==='active'?'Právě probíhá':'Náš večer se vzpomínkami'} href="/memories#memory-evenings" color="#ec4899"><p className="text-base font-semibold text-white">{data.partner_hub.memory_evening.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{new Date(data.partner_hub.memory_evening.scheduled_for).toLocaleString('cs-CZ',{weekday:'long',day:'numeric',month:'long',hour:'2-digit',minute:'2-digit'})} · {data.partner_hub.memory_evening.media_count} momentů</p><p className="mt-2 text-xs text-pink-100">Společně vybráno {data.partner_hub.memory_evening.selected_count}. Po dokončení vznikne propojené album a vzpomínka.</p><p className="mt-3 text-xs text-pink-200">Otevřít společný výběr →</p></DashCard>}
                        {data.partner_hub?.date_idea && <DashCard icon={Sparkles} label={data.partner_hub.date_idea.status==='saved'?'Randíčko, které se vám líbí':'Nový nápad pro vás'} href="/date-ideas" color="#ec4899"><p className="text-base font-semibold text-white">{data.partner_hub.date_idea.title}</p><p className="mt-1 line-clamp-2 text-xs text-[var(--color-text-secondary)]">{data.partner_hub.date_idea.summary}</p><p className="mt-2 text-xs text-pink-100">{data.partner_hub.date_idea.destination?`${data.partner_hub.date_idea.destination} · `:''}odhad {Math.round(data.partner_hub.date_idea.estimated_cost).toLocaleString('cs-CZ')} {data.partner_hub.date_idea.currency}{data.partner_hub.date_idea.suggested_starts_at?` · ${new Date(data.partner_hub.date_idea.suggested_starts_at).toLocaleDateString('cs-CZ',{weekday:'short',day:'numeric',month:'long'})}`:''}</p><p className="mt-3 text-xs text-pink-200">Doladit nebo naplánovat pro oba →</p></DashCard>}
                        {data.partner_hub?.album_suggestion && <DashCard icon={Images} label="Nově nalezený společný zážitek" href="/albums#album-suggestions" color="#8b5cf6"><p className="text-base font-semibold text-white">{data.partner_hub.album_suggestion.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{data.partner_hub.album_suggestion.reason}</p><p className="mt-2 text-xs text-violet-100">{data.partner_hub.album_suggestion.media_count} momentů · {data.partner_hub.album_suggestion.photo_count} fotografií{data.partner_hub.album_suggestion.video_count ? ` · ${data.partner_hub.album_suggestion.video_count} videí` : ''}</p><p className="mt-3 text-xs text-violet-200">Zkontrolovat výběr a vytvořit propojené album →</p></DashCard>}
                        {plannedExperience ? <DashCard icon={Sparkles} label="Další společný zážitek" color="#f97316"><p className="text-base font-semibold text-white">{plannedExperience.title} je naplánováno</p><p className="mt-1 text-xs text-emerald-200">Kalendář, plán návštěvy, oba partneři i připomínka jsou propojené.</p><Link href={`/calendar/events/${plannedExperience.uuid}`} className="mt-3 inline-flex text-xs text-orange-200">Otevřít společný plán →</Link></DashCard> : data.partner_hub?.experience_recommendation && <DashCard icon={Sparkles} label={data.partner_hub.experience_recommendation.kind === 'return' ? 'Kam se společně vrátit' : 'Co spolu objevit'} color="#f97316"><p className="text-base font-semibold text-white">{data.partner_hub.experience_recommendation.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{data.partner_hub.experience_recommendation.place_name || 'Vaše uložené místo'} · {new Date(data.partner_hub.experience_recommendation.suggested_starts_at).toLocaleString('cs-CZ',{weekday:'short',day:'numeric',month:'long',hour:'2-digit',minute:'2-digit'})}</p><p className="mt-2 text-xs text-orange-100">{data.partner_hub.experience_recommendation.reason}</p>{experienceError && <p className="mt-2 text-xs text-red-200">{experienceError}</p>}<div className="mt-3 flex flex-wrap gap-2"><button disabled={experienceBusy} onClick={() => planExperience(data.partner_hub.experience_recommendation!)} className="min-h-9 rounded-lg bg-orange-500 px-3 text-xs font-medium text-white disabled:opacity-50">{experienceBusy ? 'Plánuji…' : 'Naplánovat pro oba'}</button><Link href={`/places/${data.partner_hub.experience_recommendation.id}`} className="inline-flex min-h-9 items-center rounded-lg border border-orange-300/25 px-3 text-xs text-orange-100">Detail místa</Link></div></DashCard>}
                        {data.partner_hub?.next_event && <DashCard icon={CalendarDays} label="Nejbližší společná akce" href={`/calendar/events/${data.partner_hub.next_event.uuid}`} color="#ec4899"><p className="text-base font-semibold text-white">{data.partner_hub.next_event.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{new Date(data.partner_hub.next_event.starts_at).toLocaleDateString('cs-CZ', { day:'numeric', month:'long', hour:'2-digit', minute:'2-digit' })}{data.partner_hub.next_event.place_name ? ` · ${data.partner_hub.next_event.place_name}` : ''}</p>{((data.partner_hub.next_event.open_tasks_count ?? 0) > 0 || (data.partner_hub.next_event.planning_items_count ?? 0) > 0) && <p className="mt-2 text-xs text-pink-200">{data.partner_hub.next_event.open_tasks_count ? `${data.partner_hub.next_event.open_tasks_count} úkolů` : ''}{data.partner_hub.next_event.open_tasks_count && data.partner_hub.next_event.planning_items_count ? ' · ' : ''}{data.partner_hub.next_event.planning_items_count ? `${data.partner_hub.next_event.planning_items_count} podkladů` : ''} k vyřízení</p>}<p className="mt-3 text-xs text-pink-200">Otevřít společný plán →</p></DashCard>}
                        {data.partner_hub?.reflection_prompt && <DashCard icon={Heart} label="Dopovědět společný zážitek" href={`/trips/${data.partner_hub.reflection_prompt.id}/plan`} color="#f97316"><p className="text-base font-semibold text-white">{data.partner_hub.reflection_prompt.name}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Výlet skončil {new Date(`${data.partner_hub.reflection_prompt.end_date}T12:00:00`).toLocaleDateString('cs-CZ', { day:'numeric', month:'long' })}. Uložte si, co bylo nejlepší, a tip pro příště.</p><p className="mt-3 text-xs text-orange-200">Přidat společné ohlédnutí →</p></DashCard>}
                        {data.partner_hub?.event_reflection_prompt && <DashCard icon={Heart} label="Uložit dojem ze společné akce" href={`/calendar/events/${data.partner_hub.event_reflection_prompt.uuid}`} color="#ec4899"><p className="text-base font-semibold text-white">{data.partner_hub.event_reflection_prompt.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Zapište si nejlepší moment a co si příště zopakovat. Naváže to na kalendář i vaše album vzpomínek.</p><p className="mt-3 text-xs text-pink-200">Dopsat společný dojem →</p></DashCard>}
                        {data.upcoming_trip && (
                            <DashCard icon={Route} label="Co následuje" href={`/trips/${data.upcoming_trip.id}/plan`} color="#14b8a6">
                                <p className="text-base font-semibold text-white">{data.upcoming_trip.name}</p>
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{new Date(data.upcoming_trip.start_date).toLocaleDateString('cs-CZ')} – {new Date(data.upcoming_trip.end_date).toLocaleDateString('cs-CZ')}</p>
                                {(data.upcoming_trip.finance?.planned || data.upcoming_trip.finance?.actual) ? <p className="mt-2 text-xs text-[var(--color-text-secondary)]">Rozpočet: plán {data.upcoming_trip.finance?.planned.toLocaleString('cs-CZ')} · skutečnost {data.upcoming_trip.finance?.actual.toLocaleString('cs-CZ')} Kč</p> : <p className="mt-2 text-xs text-[var(--color-text-secondary)]">Rozpočet, balení a cestovní připravenost na jednom místě.</p>}
                                {data.upcoming_trip.bank_finance?.connected&&<div className="mt-2 rounded-xl border border-emerald-300/15 bg-emerald-500/5 p-2 text-xs"><p className="text-emerald-100">Společný účet propojen · {data.upcoming_trip.bank_finance.confirmed_count} plateb</p><p className="mt-1 text-[var(--color-text-secondary)]">Skutečně utraceno: {Object.entries(data.upcoming_trip.bank_finance.spent_by_currency??{}).map(([currency,amount])=>new Intl.NumberFormat('cs-CZ',{style:'currency',currency,maximumFractionDigits:2}).format(amount)).join(' · ')||'zatím nic'}{data.upcoming_trip.bank_finance.suggested_count?` · ${data.upcoming_trip.bank_finance.suggested_count} ke kontrole`:''}</p>{data.upcoming_trip.bank_finance.balances[0]?.before.amount!=null&&<p className="mt-1 text-[var(--color-text-secondary)]">Stav před cestou: {new Intl.NumberFormat('cs-CZ',{style:'currency',currency:data.upcoming_trip.bank_finance.balances[0].currency,maximumFractionDigits:2}).format(data.upcoming_trip.bank_finance.balances[0].before.amount)}{data.upcoming_trip.bank_finance.balances[0].before.estimated?' (odhad)':''}</p>}</div>}
                                {data.upcoming_trip.savings_goal && <div className="mt-2"><div className="flex justify-between text-[10px] text-[var(--color-text-secondary)]"><span>Cestovní fond</span><span>{data.upcoming_trip.savings_goal.percent}% · {data.upcoming_trip.savings_goal.saved_amount.toLocaleString('cs-CZ')} / {data.upcoming_trip.savings_goal.target_amount.toLocaleString('cs-CZ')} {data.upcoming_trip.savings_goal.currency}</span></div><div className="mt-1 h-1.5 overflow-hidden rounded-full bg-black/20"><div className="h-full rounded-full bg-teal-400" style={{width:`${data.upcoming_trip.savings_goal.percent}%`}}/></div></div>}
                                {data.upcoming_trip.readiness && data.upcoming_trip.readiness.packing_total > 0 && <p className={`mt-2 text-xs ${data.upcoming_trip.readiness.essential_missing ? 'text-amber-300' : 'text-[var(--color-text-secondary)]'}`}>Balení: {data.upcoming_trip.readiness.packing_packed}/{data.upcoming_trip.readiness.packing_total}{data.upcoming_trip.readiness.essential_missing ? ` · chybí ${data.upcoming_trip.readiness.essential_missing} důležité` : ''}</p>}
                                {data.upcoming_trip.preparation && <div className={`mt-2 rounded-xl p-2 text-xs ${data.upcoming_trip.preparation.status==='attention'?'bg-amber-500/10 text-amber-100':'bg-black/10 text-[var(--color-text-secondary)]'}`}><div className="flex items-center justify-between"><span>Příprava cesty</span><strong className={data.upcoming_trip.preparation.status==='ready'?'text-emerald-300':data.upcoming_trip.preparation.status==='attention'?'text-amber-300':'text-white'}>{data.upcoming_trip.preparation.score}/100</strong></div>{data.upcoming_trip.preparation.actions[0]&&<p className="mt-1 text-white">Další krok: {data.upcoming_trip.preparation.actions[0].title}</p>}{!data.upcoming_trip.preparation.actions[0]&&data.upcoming_trip.preparation.next_item&&<p className="mt-1">Následuje {data.upcoming_trip.preparation.next_item.title} · {new Date(data.upcoming_trip.preparation.next_item.at).toLocaleString('cs-CZ',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})}</p>}{data.upcoming_trip.preparation.summary.risky_connections>0&&<p className="mt-1 text-amber-200">Prověřit {data.upcoming_trip.preparation.summary.risky_connections} těsných přestupů.</p>}</div>}
                                <p className="mt-3 text-xs text-[var(--color-accent)]">Pokračovat v plánování →</p>
                            </DashCard>
                        )}
                        {data.pinned_views?.length > 0 && (
                            <DashCard icon={Sparkles} label="Připnuté pohledy" color="#8b5cf6">
                                <div className="flex flex-wrap gap-2">{data.pinned_views.map(view => <Link key={view.id} href={`/search?view=${view.id}`} className="rounded-xl border border-[var(--color-border)] px-3 py-2 text-xs text-white hover:border-[var(--color-accent)]">{view.icon ?? '✨'} {view.name}</Link>)}</div>
                            </DashCard>
                        )}
                    </div>
                )}
                {data.partner_hub?.milestones?.length > 0 && (
                    <section className="rounded-3xl border border-pink-500/20 bg-gradient-to-br from-pink-500/10 to-[var(--color-bg-card)] p-4 sm:p-5">
                        <div className="flex items-center justify-between"><div><p className="text-xs font-medium uppercase tracking-wider text-pink-200">Náš společný prostor</p><h2 className="mt-1 font-semibold text-white">Blížící se osobní dny</h2></div><Heart size={20} className="text-pink-300" /></div>
                        <div className="mt-4 space-y-1">{data.partner_hub.milestones.map(item => <Link key={item.uuid} href="/milestones" className={`flex items-center justify-between rounded-xl px-3 py-2 text-sm text-white hover:bg-black/20 ${item.is_highlighted ? 'bg-amber-500/10' : 'bg-black/10'}`}><span className="truncate">{item.icon} {item.title}</span><span className="ml-2 shrink-0 text-xs text-pink-200">{item.days_until === 0 ? 'dnes' : `za ${item.days_until} d.`}</span></Link>)}</div>
                    </section>
                )}

                <form onSubmit={addMilestone} className="flex flex-col gap-2 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:flex-row sm:items-end"><div className="min-w-0 flex-1"><p className="text-xs font-medium text-white">Přidat společný milník</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Výročí se následně automaticky objeví v přehledu i kalendáři.</p></div><input required value={milestone.title} onChange={event => setMilestone({...milestone,title:event.target.value})} placeholder="Např. první společný výlet" className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/><input required type="date" value={milestone.occurred_on} onChange={event => setMilestone({...milestone,occurred_on:event.target.value})} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/><button className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm text-white">{milestoneSaved ? 'Uloženo ✓' : 'Přidat'}</button></form>
                <section className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div><p className="text-xs font-medium uppercase tracking-wider text-[var(--color-accent)]">Právě teď</p><h2 className="mt-1 font-semibold text-white">Aktuální práce s galerií</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Historické vzpomínky najdete ve Vzpomínkách a Timeline. Dashboard ponechává jen živé kroky.</p></div>
                        <Link href="/timeline" className="inline-flex min-h-10 items-center justify-center rounded-xl border border-[var(--color-border)] px-4 text-sm text-white">Otevřít Timeline</Link>
                    </div>
                    {data.pending_uploads > 0 ? <div className="mt-4 rounded-2xl border border-amber-400/20 bg-amber-500/10 p-4"><p className="font-medium text-amber-100">Čeká na nahrání: {data.pending_uploads} souborů</p><p className="mt-1 text-xs text-amber-100/80">Dokončete probíhající nahrávání, aby byly nové položky bezpečně viditelné v galerii.</p></div> : <div className="mt-4 rounded-2xl border border-dashed border-[var(--color-border)] p-4 text-sm text-[var(--color-text-secondary)]">Žádné rozpracované nahrávání. Nové fotografie můžete kdykoli přidat z Timeline.</div>}
                </section>


                {/* Quick nav */}
                <div>
                    <h2 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-3">Rychlý přístup</h2>
                    <div className="grid grid-cols-4 gap-2">
                        {[
                            { href: '/timeline', icon: Clock,    label: 'Fotky' },
                            { href: '/albums',   icon: FolderOpen, label: 'Alba' },
                            { href: '/map',      icon: MapPin,   label: 'Mapa' },
                            { href: '/favorites',icon: Heart,    label: 'Oblíbené' },
                            { href: '/memories', icon: Star,     label: 'Vzpomínky' },
                            { href: '/search',   icon: Album,    label: 'Hledat' },
                        ].map(item => (
                            <Link key={item.href} href={item.href}
                                className="flex flex-col items-center gap-1.5 p-3 rounded-xl bg-[var(--color-bg-card)] border border-[var(--color-border)] hover:border-[var(--color-accent)]/50 transition-colors">
                                <item.icon size={18} className="text-[var(--color-text-secondary)]" />
                                <span className="text-[10px] text-[var(--color-text-secondary)]">{item.label}</span>
                            </Link>
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
