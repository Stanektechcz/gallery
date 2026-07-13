import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Album, CalendarDays, Clock, FolderOpen, Heart, Map, MapPin, Route, Shuffle, Sparkles, Star, TrendingUp, Upload } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface MediaCard {
    id: number;
    uuid: string;
    media_type: string;
    variants: Array<{ type: string; url: string }>;
}

interface DashboardData {
    greeting: string;
    user_name: string;
    this_time_last_year: {
        count: number;
        date: string;
        items: MediaCard[];
    };
    recent_media: MediaCard[];
    random_memory: MediaCard | null;
    last_album: { uuid: string; title: string; date?: string } | null;
    pending_uploads: number;
    map_stats: { locations: number; countries: number };
    year_stats: { year: number; photos: number; videos: number };
    for_you: Array<{ fingerprint: string; title: string; subtitle: string; icon: string; accent: string; count: number; items: MediaCard[] }>;
    pinned_views: Array<{ id: number; name: string; icon?: string; view_type: string }>;
    upcoming_trip: { id: number; name: string; start_date: string; end_date: string; status: string; finance?:{planned:number;actual:number}; readiness?:{packing_total:number;packing_packed:number;essential_missing:number}; savings_goal?:{target_amount:number;saved_amount:number;monthly_contribution?:number|null;currency:string;percent:number}|null } | null;
    partner_hub: { space_id:number; relationship_started_on?:string|null; milestones: Array<{ uuid:string; title:string; icon:string; days_until:number; next_anniversary:string }>; shared_moments: Array<{ uuid:string; title:string; happened_on?:string|null; is_favorite:boolean }>; next_event?:{uuid:string;title:string;starts_at:string;place_name?:string|null;trip_id?:number|null}|null; reflection_prompt?:{id:number;name:string;end_date:string}|null };
}

interface Props {
    data: DashboardData | null;
}

function MediaStrip({ items, max = 6 }: { items: MediaCard[]; max?: number }) {
    const show = items.slice(0, max);
    return (
        <div className="flex gap-1.5 flex-wrap">
            {show.map(item => {
                const thumb = item.variants?.find(v => v.type === 'thumbnail')?.url
                           ?? item.variants?.find(v => v.type === 'placeholder')?.url;
                return (
                    <Link key={item.id} href={`/media/${item.uuid}`}>
                        <div className="w-16 h-16 rounded-lg overflow-hidden bg-[var(--color-bg-card)] shrink-0">
                            {thumb
                                ? <img src={thumb} alt="" className="w-full h-full object-cover hover:scale-105 transition-transform duration-200" />
                                : <div className="w-full h-full flex items-center justify-center text-[var(--color-text-secondary)]">
                                    <Album size={16} />
                                  </div>
                            }
                        </div>
                    </Link>
                );
            })}
        </div>
    );
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

    return (
        <AppLayout>
            <Head title="Domů" />

            <div className="p-4 max-w-4xl mx-auto space-y-5 pb-8">
                {/* Greeting */}
                <div className="pt-2">
                    <h1 className="text-2xl font-bold text-white">
                        {data.greeting}, {data.user_name} {emoji}
                    </h1>
                    <p className="text-sm text-[var(--color-text-secondary)] mt-1">
                        {new Date().toLocaleDateString('cs-CZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
                    </p>
                </div>

                {/* Quick stats bar */}
                <div className="grid grid-cols-3 gap-3">
                    <Link href="/timeline" className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-3 text-center hover:border-[var(--color-accent)]/50 transition-colors">
                        <p className="text-xl font-bold text-white">{data.year_stats.photos.toLocaleString('cs-CZ')}</p>
                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">fotek {data.year_stats.year}</p>
                    </Link>
                    <Link href="/timeline" className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-3 text-center hover:border-[var(--color-accent)]/50 transition-colors">
                        <p className="text-xl font-bold text-white">{data.year_stats.videos.toLocaleString('cs-CZ')}</p>
                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">videí {data.year_stats.year}</p>
                    </Link>
                    <Link href="/map" className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-3 text-center hover:border-[var(--color-accent)]/50 transition-colors">
                        <p className="text-xl font-bold text-white">{data.map_stats.locations}</p>
                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">míst na mapě</p>
                    </Link>
                </div>

                {/* Personalized "For you" hero */}
                {data.for_you?.[0] && (() => {
                    const memory = data.for_you[0];
                    const cover = memory.items?.[0]?.variants?.find(variant => variant.type === 'thumbnail')?.url;
                    return (
                        <Link href="/memories" className="relative block min-h-56 overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-bg-card)] group">
                            {cover && <img src={cover} alt="" className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" />}
                            <div className="absolute inset-0 bg-gradient-to-r from-black/90 via-black/55 to-transparent" />
                            <div className="relative flex min-h-56 max-w-md flex-col justify-end p-6">
                                <div className="mb-2 flex items-center gap-2 text-xs font-medium text-white/75"><Sparkles size={14} style={{ color: memory.accent }} /> Pro vás</div>
                                <h2 className="text-2xl font-bold text-white">{memory.icon} {memory.title}</h2>
                                <p className="mt-1 text-sm text-white/70">{memory.subtitle} · {memory.count} momentů</p>
                                <span className="mt-4 text-xs font-medium text-white">Otevřít vzpomínku →</span>
                            </div>
                        </Link>
                    );
                })()}

                {(data.upcoming_trip || data.pinned_views?.length > 0 || data.partner_hub?.next_event || data.partner_hub?.reflection_prompt) && (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        {data.partner_hub?.next_event && <DashCard icon={CalendarDays} label="Nejbližší společná akce" href={`/calendar/events/${data.partner_hub.next_event.uuid}`} color="#ec4899"><p className="text-base font-semibold text-white">{data.partner_hub.next_event.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{new Date(data.partner_hub.next_event.starts_at).toLocaleDateString('cs-CZ', { day:'numeric', month:'long', hour:'2-digit', minute:'2-digit' })}{data.partner_hub.next_event.place_name ? ` · ${data.partner_hub.next_event.place_name}` : ''}</p><p className="mt-3 text-xs text-pink-200">Otevřít společný plán →</p></DashCard>}
                        {data.partner_hub?.reflection_prompt && <DashCard icon={Heart} label="Dopovědět společný zážitek" href={`/trips/${data.partner_hub.reflection_prompt.id}/plan`} color="#f97316"><p className="text-base font-semibold text-white">{data.partner_hub.reflection_prompt.name}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Výlet skončil {new Date(`${data.partner_hub.reflection_prompt.end_date}T12:00:00`).toLocaleDateString('cs-CZ', { day:'numeric', month:'long' })}. Uložte si, co bylo nejlepší, a tip pro příště.</p><p className="mt-3 text-xs text-orange-200">Přidat společné ohlédnutí →</p></DashCard>}
                        {data.partner_hub?.relationship_started_on && <DashCard icon={Heart} label="Náš příběh" href="/planning" color="#ec4899"><p className="text-base font-semibold text-white">Spolu od {new Date(`${data.partner_hub.relationship_started_on}T12:00:00`).toLocaleDateString('cs-CZ', { day:'numeric', month:'long', year:'numeric' })}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Výročí, připomínky a nápady na dárky jsou propojené s kalendářem.</p><p className="mt-3 text-xs text-pink-200">Otevřít naše výročí →</p></DashCard>}
                        {data.upcoming_trip && (
                            <DashCard icon={Route} label="Co následuje" href={`/trips/${data.upcoming_trip.id}/plan`} color="#14b8a6">
                                <p className="text-base font-semibold text-white">{data.upcoming_trip.name}</p>
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{new Date(data.upcoming_trip.start_date).toLocaleDateString('cs-CZ')} – {new Date(data.upcoming_trip.end_date).toLocaleDateString('cs-CZ')}</p>
                                {(data.upcoming_trip.finance?.planned || data.upcoming_trip.finance?.actual) ? <p className="mt-2 text-xs text-[var(--color-text-secondary)]">Rozpočet: plán {data.upcoming_trip.finance?.planned.toLocaleString('cs-CZ')} · skutečnost {data.upcoming_trip.finance?.actual.toLocaleString('cs-CZ')} Kč</p> : <p className="mt-2 text-xs text-[var(--color-text-secondary)]">Rozpočet, balení a cestovní připravenost na jednom místě.</p>}
                                {data.upcoming_trip.savings_goal && <div className="mt-2"><div className="flex justify-between text-[10px] text-[var(--color-text-secondary)]"><span>Cestovní fond</span><span>{data.upcoming_trip.savings_goal.percent}% · {data.upcoming_trip.savings_goal.saved_amount.toLocaleString('cs-CZ')} / {data.upcoming_trip.savings_goal.target_amount.toLocaleString('cs-CZ')} {data.upcoming_trip.savings_goal.currency}</span></div><div className="mt-1 h-1.5 overflow-hidden rounded-full bg-black/20"><div className="h-full rounded-full bg-teal-400" style={{width:`${data.upcoming_trip.savings_goal.percent}%`}}/></div></div>}
                                {data.upcoming_trip.readiness && data.upcoming_trip.readiness.packing_total > 0 && <p className={`mt-2 text-xs ${data.upcoming_trip.readiness.essential_missing ? 'text-amber-300' : 'text-[var(--color-text-secondary)]'}`}>Balení: {data.upcoming_trip.readiness.packing_packed}/{data.upcoming_trip.readiness.packing_total}{data.upcoming_trip.readiness.essential_missing ? ` · chybí ${data.upcoming_trip.readiness.essential_missing} důležité` : ''}</p>}
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

                {(data.partner_hub?.milestones?.length > 0 || data.partner_hub?.shared_moments?.length > 0) && <section className="rounded-3xl border border-pink-500/20 bg-gradient-to-br from-pink-500/10 to-[var(--color-bg-card)] p-4 sm:p-5"><div className="flex items-center justify-between"><div><p className="text-xs font-medium uppercase tracking-wider text-pink-200">Náš společný prostor</p><h2 className="mt-1 font-semibold text-white">Co si chceme připomenout</h2></div><Heart size={20} className="text-pink-300"/></div><div className="mt-4 grid gap-3 sm:grid-cols-2">{data.partner_hub.milestones.length > 0 && <div><p className="text-xs text-[var(--color-text-secondary)]">Blížící se výročí</p><div className="mt-2 space-y-1">{data.partner_hub.milestones.map(item => <Link key={item.uuid} href="/milestones" className="flex items-center justify-between rounded-xl bg-black/10 px-3 py-2 text-sm text-white hover:bg-black/20"><span className="truncate">{item.icon} {item.title}</span><span className="ml-2 shrink-0 text-xs text-pink-200">{item.days_until === 0 ? 'dnes' : `za ${item.days_until} d.`}</span></Link>)}</div></div>}{data.partner_hub.shared_moments.length > 0 && <div><p className="text-xs text-[var(--color-text-secondary)]">Vaše vybrané momenty</p><div className="mt-2 space-y-1">{data.partner_hub.shared_moments.map(item => <Link key={item.uuid} href="/shared-memories" className="block truncate rounded-xl bg-black/10 px-3 py-2 text-sm text-white hover:bg-black/20">{item.is_favorite && '♥ '}{item.title}{item.happened_on && <span className="ml-2 text-xs text-[var(--color-text-secondary)]">{new Date(`${item.happened_on}T12:00:00`).toLocaleDateString('cs-CZ')}</span>}</Link>)}</div></div>}</div></section>}

                <form onSubmit={addMilestone} className="flex flex-col gap-2 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:flex-row sm:items-end"><div className="min-w-0 flex-1"><p className="text-xs font-medium text-white">Přidat společný milník</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Výročí se následně automaticky objeví v přehledu i kalendáři.</p></div><input required value={milestone.title} onChange={event => setMilestone({...milestone,title:event.target.value})} placeholder="Např. první společný výlet" className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/><input required type="date" value={milestone.occurred_on} onChange={event => setMilestone({...milestone,occurred_on:event.target.value})} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/><button className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm text-white">{milestoneSaved ? 'Uloženo ✓' : 'Přidat'}</button></form>

                {/* This time last year */}
                {data.this_time_last_year.count > 0 && (
                    <DashCard icon={CalendarDays} label={`Dnes před rokem • ${data.this_time_last_year.date}`} color="#ec4899">
                        <p className="text-xs text-[var(--color-text-secondary)] mb-3">
                            {data.this_time_last_year.count} {data.this_time_last_year.count === 1 ? 'fotografie' : data.this_time_last_year.count < 5 ? 'fotografie' : 'fotografií'}
                        </p>
                        <MediaStrip items={data.this_time_last_year.items} max={8} />
                    </DashCard>
                )}

                {/* 2-column grid for cards */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {/* Recent memories */}
                    <DashCard icon={Heart} label="Naše poslední vzpomínky" href="/timeline" color="#f97316">
                        <MediaStrip items={data.recent_media} max={6} />
                        <p className="text-xs text-[var(--color-text-secondary)] mt-2">Nejnovější fotky →</p>
                    </DashCard>

                    {/* Random memory */}
                    {data.random_memory && (
                        <DashCard icon={Shuffle} label="Náhodná vzpomínka" href={`/media/${data.random_memory.uuid}`} color="#8b5cf6">
                            <MediaStrip items={[data.random_memory]} max={1} />
                            <p className="text-xs text-[var(--color-text-secondary)] mt-2">Otevřít →</p>
                        </DashCard>
                    )}

                    {/* Last album */}
                    {data.last_album && (
                        <DashCard icon={FolderOpen} label="Pokračovat v albu" href={`/albums/${data.last_album.uuid}`} color="#06b6d4">
                            <p className="text-base font-semibold text-white">{data.last_album.title}</p>
                            {data.last_album.date && (
                                <p className="text-xs text-[var(--color-text-secondary)] mt-1">{data.last_album.date}</p>
                            )}
                            <p className="text-xs text-[var(--color-accent)] mt-2">Otevřít album →</p>
                        </DashCard>
                    )}

                    {/* Map */}
                    <DashCard icon={Map} label="Vaše společná mapa" href="/map" color="#22c55e">
                        <p className="text-base font-semibold text-white">
                            {data.map_stats.locations} míst
                        </p>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                            z {data.map_stats.countries} {data.map_stats.countries === 1 ? 'země' : data.map_stats.countries < 5 ? 'zemí' : 'zemí'}
                        </p>
                        <p className="text-xs text-[var(--color-accent)] mt-2">Zobrazit mapu →</p>
                    </DashCard>

                    {/* Pending uploads */}
                    {data.pending_uploads > 0 && (
                        <DashCard icon={Upload} label="Čeká na nahrání" color="#f59e0b">
                            <p className="text-base font-semibold text-white">{data.pending_uploads} souborů</p>
                            <p className="text-xs text-[var(--color-text-secondary)] mt-1">Probíhající nahrávání</p>
                        </DashCard>
                    )}

                    {/* Year stats */}
                    <DashCard icon={TrendingUp} label={`Rok ${data.year_stats.year}`} href="/timeline" color="#6366f1">
                        <div className="flex items-baseline gap-2">
                            <p className="text-base font-semibold text-white">
                                {(data.year_stats.photos + data.year_stats.videos).toLocaleString('cs-CZ')}
                            </p>
                            <p className="text-xs text-[var(--color-text-secondary)]">médií celkem</p>
                        </div>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                            {data.year_stats.photos.toLocaleString('cs-CZ')} fotek
                            · {data.year_stats.videos.toLocaleString('cs-CZ')} videí
                        </p>
                    </DashCard>
                </div>

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
