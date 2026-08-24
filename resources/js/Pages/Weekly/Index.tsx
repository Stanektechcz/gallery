import Panel, { PanelGrid } from '@/Components/Panel';
import AppLayout from '@/Layouts/AppLayout';
import { takenAtDate } from '@/lib/takenAt';
import PartnerPulsePanel from '@/Components/PartnerPulsePanel';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { CalendarDays, Heart, Hourglass, Images, Sparkles } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

const dny = (pocet: number) => `${pocet} ${pocet === 1 ? 'den' : pocet >= 2 && pocet <= 4 ? 'dny' : 'dní'}`;

interface Overview { period:string[]; events:any[]; open_tasks:any[]; travel_inbox:any[]; rediscover:any[]; on_this_day?:any[]; }
interface Milestone { uuid:string; title:string; icon:string; days_until:number; next_anniversary:string; }
interface SharedMoment { uuid:string; title:string; happened_on?:string|null; is_favorite:boolean; }

export default function Weekly() {
    const [data, setData] = useState<Overview | null>(null); const [spaceId, setSpaceId] = useState<number | null>(null); const [milestones, setMilestones] = useState<Milestone[]>([]); const [moments, setMoments] = useState<SharedMoment[]>([]); const [capsule, setCapsule] = useState({ title:'', deliver_at:'' }); const [saved, setSaved] = useState(false); const [memoryEveningAt, setMemoryEveningAt] = useState(''); const [memoryEveningMessage, setMemoryEveningMessage] = useState(''); const [savingMemoryEvening, setSavingMemoryEvening] = useState(false);
    useEffect(() => { Promise.allSettled([axios.get('/api/v1/calendar/weekly-overview'), axios.get('/api/v1/calendar/events'), axios.get('/api/v1/relationship-milestones/upcoming'), axios.get('/api/v1/shared-memory-moments')]).then(([overview, calendar, upcoming, shared]) => { setData(overview.status === 'fulfilled' ? overview.value.data : {period:[],events:[],open_tasks:[],travel_inbox:[],rediscover:[],on_this_day:[]}); if (calendar.status === 'fulfilled') setSpaceId(calendar.value.data.spaces?.[0]?.id ?? null); if (upcoming.status === 'fulfilled') setMilestones((upcoming.value.data ?? []).slice(0, 3)); if (shared.status === 'fulfilled') setMoments((shared.value.data ?? []).slice(0, 3)); }); }, []);
    const seal = async (event:FormEvent) => { event.preventDefault(); if (!spaceId) return; await axios.post('/api/v1/calendar/time-capsules', { gallery_space_id:spaceId, ...capsule }); setCapsule({title:'',deliver_at:''}); setSaved(true); setTimeout(() => setSaved(false), 2500); };
    const scheduleMemoryEvening = async () => { if (!spaceId || !memoryEveningAt || !moments.length) return; setSavingMemoryEvening(true); setMemoryEveningMessage(''); try { const response = await axios.post('/api/v1/calendar/memory-evening', { gallery_space_id:spaceId, scheduled_at:memoryEveningAt, moment_uuids:moments.map(moment => moment.uuid) }); setMemoryEveningMessage('Večer se vzpomínkami je v kalendáři a oba máte připomínku.'); setData(current => current ? {...current, events:[...current.events, response.data]} : current); } catch (error:any) { setMemoryEveningMessage(error?.response?.data?.message ?? 'Společný večer se nepodařilo naplánovat.'); } finally { setSavingMemoryEvening(false); } };
    const events = data?.events ?? [];
    const onThisDay = data?.on_this_day ?? [];

    return (
        <AppLayout>
            <Head title="Týdenní přehled"/>

            {/* Omezená šířka a mřížka místo sloupce karet. Panely tady jsou krátké —
                pár řádků seznamu — a přes celou obrazovku po sobě znamenaly metr
                scrollování kvůli obsahu, který se vejde na jednu vedle druhé. */}
            <main className="mx-auto w-full max-w-[1500px] p-4 sm:p-6">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold text-[var(--color-text-primary)]">Náš týden</h1>
                        <p className="mt-1 max-w-2xl text-sm text-[var(--color-text-secondary)]">
                            Jen aktuální a nadcházející plány. Starší akce se po termínu automaticky uzavírají.
                        </p>
                    </div>
                    <Link href="/calendar" className="shrink-0 text-sm text-[var(--color-accent)]">Kalendář →</Link>
                </header>

                {spaceId && <div className="mt-5"><PartnerPulsePanel spaceId={spaceId}/></div>}

                <div className="mt-4 space-y-4">
                    {/* Akce týdne vlevo, výročí a momenty vpravo — plán a to, co se k němu
                        váže, patří vedle sebe. */}
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <Panel icon={CalendarDays} title="Akce tohoto týdne">
                            {events.length > 0 ? (
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {events.map((item:any) => (
                                        <p key={item.id ?? item.uuid}
                                            className="rounded-lg bg-[var(--color-surface-muted)] p-2 text-sm text-[var(--color-text-secondary)]">
                                            {item.title}
                                        </p>
                                    ))}
                                </div>
                            ) : (
                                <p className="rounded-xl border border-dashed border-[var(--color-border)] p-4 text-center text-sm text-[var(--color-text-secondary)]">
                                    Na tento týden zatím nic nemáte.{' '}
                                    <Link href="/calendar" className="text-[var(--color-accent)] underline">Vytvořit akci</Link>
                                </p>
                            )}
                        </Panel>

                        <Panel icon={Heart} tone="accent" title="Pro nás dva"
                            description="Výročí a společné momenty se přirozeně potkávají s vaším týdenním plánem.">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p className="text-xs text-[var(--color-text-secondary)]">Blížící se výročí</p>
                                    <div className="mt-2 space-y-1">
                                        {milestones.length > 0 ? milestones.map(item => (
                                            <p key={item.uuid} className="flex items-baseline justify-between gap-2 rounded-lg bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-[var(--color-text-primary)]">
                                                <span className="min-w-0 truncate">{item.icon} {item.title}</span>
                                                <span className="shrink-0 text-xs text-[var(--color-text-secondary)]">
                                                    {item.days_until === 0 ? 'dnes' : `za ${dny(item.days_until)}`}
                                                </span>
                                            </p>
                                        )) : <p className="text-sm text-[var(--color-text-secondary)]">Zatím žádné.</p>}
                                    </div>
                                </div>
                                <div>
                                    <p className="text-xs text-[var(--color-text-secondary)]">Naposledy uložené vzpomínky</p>
                                    <div className="mt-2 space-y-1">
                                        {moments.length > 0 ? moments.map(item => (
                                            <p key={item.uuid} className="truncate rounded-lg bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-[var(--color-text-primary)]">
                                                {item.is_favorite && '♥ '}{item.title}
                                            </p>
                                        )) : <p className="text-sm text-[var(--color-text-secondary)]">Vyberte si první společný moment ve Vzpomínkách.</p>}
                                    </div>
                                </div>
                            </div>

                            {moments.length > 0 && (
                                <div className="mt-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3">
                                    <p className="text-sm font-medium text-[var(--color-text-primary)]">Večer se vzpomínkami</p>
                                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                        Zobrazené momenty přidáme do jedné sdílené akce a připomeneme oběma.
                                    </p>
                                    <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                                        <input required type="datetime-local" value={memoryEveningAt}
                                            onChange={event => setMemoryEveningAt(event.target.value)}
                                            className="min-h-10 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 text-sm text-[var(--color-text-primary)]"/>
                                        <button disabled={!memoryEveningAt || savingMemoryEvening} onClick={scheduleMemoryEvening}
                                            className="min-h-10 shrink-0 rounded-lg bg-pink-600 px-4 text-sm text-white disabled:opacity-50">
                                            {savingMemoryEvening ? 'Plánuji…' : 'Naplánovat večer'}
                                        </button>
                                    </div>
                                    {memoryEveningMessage && (
                                        <p className={`mt-2 text-xs ${memoryEveningMessage.startsWith('Večer') ? 'text-emerald-300' : 'text-red-300'}`}>
                                            {memoryEveningMessage}
                                        </p>
                                    )}
                                </div>
                            )}
                        </Panel>
                    </div>

                    {/* Objevovací panely vedle sebe. Každý je jen hrst odkazů, takže přes
                        celou šířku by z nich byly skoro prázdné pruhy. */}
                    <PanelGrid>
                        {onThisDay.length > 0 && (
                            <Panel icon={Images} title="Dnes před lety"
                                description="Fotky pořízené ve stejný den v minulých letech.">
                                <div className="flex flex-wrap gap-2">
                                    {onThisDay.map((item:any) => (
                                        <Link key={item.uuid} href={`/media/${item.uuid}`}
                                            className="rounded-lg border border-violet-400/25 bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-violet-100 hover:border-violet-300">
                                            📷 {item.display_title || item.original_filename || 'Vzpomínka'}
                                            <span className="text-violet-200/70"> · {takenAtDate(item.taken_at)!.getFullYear()}</span>
                                        </Link>
                                    ))}
                                </div>
                            </Panel>
                        )}

                        <Panel icon={Sparkles} title="Co jsme ještě neviděli"
                            description="Starší, neoblíbené fotografie k opětovnému objevení.">
                            {(data?.rediscover ?? []).length > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    {(data?.rediscover ?? []).map((item:any) => (
                                        <Link key={item.uuid} href={`/media/${item.uuid}`}
                                            className="rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm text-[var(--color-text-primary)] hover:border-[var(--color-accent)]/50">
                                            {item.display_title || 'Vzpomínka'}
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-[var(--color-text-secondary)]">Zatím není co objevovat.</p>
                            )}
                        </Panel>

                        <Panel icon={Hourglass} title="Časová kapsle"
                            description="Zapečetěte krátký vzkaz; doručí se jako soukromé upozornění ve zvolený čas.">
                            <form onSubmit={seal} className="space-y-2">
                                <input required value={capsule.title} onChange={event => setCapsule({...capsule, title:event.target.value})}
                                    placeholder="Název vzpomínky"
                                    className="min-h-10 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 text-sm text-[var(--color-text-primary)]"/>
                                <input required type="datetime-local" value={capsule.deliver_at}
                                    onChange={event => setCapsule({...capsule, deliver_at:event.target.value})}
                                    className="min-h-10 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] px-3 text-sm text-[var(--color-text-primary)]"/>
                                <button className="min-h-10 w-full rounded-lg bg-[var(--color-accent)] px-3 text-sm font-medium text-[var(--color-accent-contrast)]">
                                    {saved ? 'Uloženo ✓' : 'Zapečetit'}
                                </button>
                            </form>
                        </Panel>
                    </PanelGrid>
                </div>
            </main>
        </AppLayout>
    );
}
