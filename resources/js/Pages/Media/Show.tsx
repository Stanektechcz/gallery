import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { addLocalizedBaseLayer } from '@/lib/localizedMap';
import { clsx } from 'clsx';
import {
    Archive,
    Camera,
    ChevronLeft, ChevronRight,
    Clock,
    Download,
    ExternalLink,
    FolderOpen,
    Heart,
    Info, MapPin,
    LockKeyhole,
    Maximize2,
    MessageSquare,
    Minimize2,
    MoreHorizontal,
    RotateCcw,
    Star,
    Tag,
    Trash2,
    Users,
    X,
    ZoomIn,
    ZoomOut
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Variant {
    id: number;
    type: string;
    url: string;
    width?: number;
    height?: number;
    format?: string;
    dominant_color?: string;
    blur_hash?: string;
}

interface Tag { id: number; name: string; slug: string; color?: string }
interface Person { id: number; name: string }
interface Place { id: number; name: string; city?: string; country?: string; latitude?: number; longitude?: number }
interface Album { id: number; uuid: string; title: string }
interface ExperienceLinks { events:Array<{uuid:string;title:string;starts_at?:string|null;trip_id?:number|null}>; trips:Array<{id:number;name:string;start_date?:string|null;end_date?:string|null}>; memories:Array<{uuid:string;title:string;happened_on?:string|null}>; }

interface MediaItem {
    id: number;
    uuid: string;
    gallery_space_id: number;
    media_type: 'photo' | 'video';
    original_filename: string;
    display_title?: string;
    description?: string;
    caption?: string;
    notes?: string;
    extension: string;
    mime_type: string;
    size_bytes: number;
    width?: number;
    height?: number;
    duration_ms?: number;
    bitrate?: number;
    frame_rate?: number;
    video_codec?: string;
    audio_codec?: string;
    taken_at?: string;
    taken_at_timezone?: string;
    latitude?: number;
    longitude?: number;
    altitude?: number;
    camera_make?: string;
    camera_model?: string;
    lens_model?: string;
    iso?: number;
    aperture?: string;
    shutter_speed?: string;
    focal_length?: string;
    rating?: number;
    is_favorite: boolean;
    is_my_favorite?: boolean;
    is_shared_favorite?: boolean;
    my_rating?: number;
    is_archived: boolean;
    is_hidden: boolean;
    trashed_at?: string;
    status: string;
    storage_status?: string;
    processing_error?: string;
    drive_file_id?: string;
    // Extended format
    is_panorama?: boolean;
    is_360?: boolean;
    panorama_projection?: string;
    is_raw?: boolean;
    raw_format?: string;
    live_photo_role?: 'main' | 'video';
    live_photo_pair_id?: number;
    variants: Variant[];
    tags: Tag[];
    people: Person[];
    places: Place[];
    albums?: Album[];
    experience_links?: ExperienceLinks;
}

interface Props {
    media: MediaItem;
    breadcrumb: Array<{ id: number; uuid: string; title: string }>;
    prev: { id: number; uuid: string } | null;
    next: { id: number; uuid: string } | null;
}

function formatBytes(b: number): string {
    if (b < 1024) return `${b} B`;
    if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)} KB`;
    if (b < 1024 ** 3) return `${(b / 1024 ** 2).toFixed(1)} MB`;
    return `${(b / 1024 ** 3).toFixed(2)} GB`;
}

function formatDate(d?: string): string {
    if (!d) return '—';
    return new Date(d).toLocaleString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatDuration(milliseconds?: number): string {
    if (!milliseconds) return '—';
    const seconds = Math.floor(milliseconds / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    return hours > 0
        ? `${hours}:${String(minutes % 60).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`
        : `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}

const REACTIONS = [
    { emoji: '❤️', key: 'love',   label: 'Miluju' },
    { emoji: '😂', key: 'funny',  label: 'Vtipné' },
    { emoji: '🥹', key: 'memory', label: 'Vzpomínka' },
    { emoji: '🔥', key: 'top',    label: 'Top' },
];

interface ReactionDetail { user_id: number; name: string; initial: string; reaction: string; is_me: boolean; }

function ReactionPanel({ uuid }: { uuid: string }) {
    const [reactions, setReactions] = useState<Record<string, number>>({});
    const [mine,      setMine]      = useState<string | null>(null);
    const [details,   setDetails]   = useState<ReactionDetail[]>([]);

    useEffect(() => {
        axios.get(`/api/v1/media/${uuid}/reactions`).then(r => {
            setReactions(r.data.counts ?? {});
            setMine(r.data.mine ?? null);
            setDetails(r.data.details ?? []);
        }).catch(() => {});
    }, [uuid]);

    const react = async (key: string) => {
        const prev = mine;
        const prevCounts = { ...reactions };
        const newMine = mine === key ? null : key;
        const newCounts = { ...reactions };
        if (prev) newCounts[prev] = Math.max(0, (newCounts[prev]||1) - 1);
        if (newMine) newCounts[newMine] = (newCounts[newMine]||0) + 1;
        setMine(newMine); setReactions(newCounts);
        try {
            const r = await axios.post(`/api/v1/media/${uuid}/react`, { reaction: newMine });
            setReactions(r.data.counts ?? newCounts);
            setMine(r.data.mine ?? newMine);
            if (r.data.details) setDetails(r.data.details);
        } catch {
            setMine(prev); setReactions(prevCounts);
        }
    };

    return (
        <section>
            <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Reakce</h3>
            <div className="flex gap-2 flex-wrap">
                {REACTIONS.map(r => {
                    const who = details.filter(d => d.reaction === r.key);
                    return (
                        <button key={r.key} onClick={() => react(r.key)} title={r.label}
                            className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-full border text-xs transition-all ${mine === r.key ? 'bg-[var(--color-accent)]/20 border-[var(--color-accent)] text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:border-[var(--color-accent)]/50 hover:text-white'}`}>
                            <span className="text-sm">{r.emoji}</span>
                            {who.length > 0 && (
                                <span className="flex items-center gap-0.5">
                                    {who.map(d => (
                                        <span key={d.user_id}
                                            className={`inline-flex w-4 h-4 rounded-full items-center justify-center text-[9px] font-bold ${d.is_me ? 'bg-[var(--color-accent)] text-white' : 'bg-white/20 text-white'}`}
                                            title={d.name}>
                                            {d.initial}
                                        </span>
                                    ))}
                                </span>
                            )}
                            {who.length === 0 && <span className="text-[10px]">{r.label}</span>}
                        </button>
                    );
                })}
            </div>
        </section>
    );
}

function CurationPanel({ uuid }: { uuid: string }) {
    const [boards, setBoards] = useState<Array<{ uuid: string; title: string }>>([]);
    const [selected, setSelected] = useState('');
    const [message, setMessage] = useState('');

    useEffect(() => {
        axios.get('/api/v1/curation-boards').then(response => {
            setBoards(response.data ?? []);
            setSelected(response.data?.[0]?.uuid ?? '');
        }).catch(() => {});
    }, []);

    const add = async () => {
        if (!selected) { setMessage('Nejdřív vytvořte společný výběr.'); return; }
        try {
            await axios.post(`/api/v1/curation-boards/${selected}/items`, { media_uuids: [uuid] });
            setMessage('Přidáno do společného výběru.');
        } catch (error: any) {
            setMessage(error?.response?.data?.message ?? 'Přidání se nepodařilo.');
        }
    };

    return <section>
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">Společný výběr</h3>
        {boards.length ? <div className="flex gap-2"><select value={selected} onChange={event => setSelected(event.target.value)} className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"><option value="">Vyberte kolekci</option>{boards.map(board => <option key={board.uuid} value={board.uuid}>{board.title}</option>)}</select><button onClick={add} className="min-h-9 rounded-lg bg-[var(--color-accent)] px-3 text-xs text-white">Přidat</button></div> : <Link href="/curation" className="text-xs text-[var(--color-accent)] hover:underline">Vytvořit první společný výběr</Link>}
        {message && <p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">{message}</p>}
    </section>;
}

function AddToEventPanel({ uuid, mediaId }: { uuid: string; mediaId: number }) {
    const [events, setEvents] = useState<Array<{uuid:string;title:string;starts_at:string;place_name?:string|null;already_linked:boolean}>>([]);
    const [loaded, setLoaded] = useState(false); const [loading, setLoading] = useState(false); const [message, setMessage] = useState('');
    const load = async () => { setLoading(true); setMessage(''); try { const response = await axios.get(`/api/v1/media/${uuid}/event-suggestions`); setEvents(response.data.events ?? []); setLoaded(true); } catch { setMessage('Vhodné akce se nepodařilo načíst.'); } finally { setLoading(false); } };
    const attach = async (event:{uuid:string;title:string}) => { setLoading(true); setMessage(''); try { await axios.post(`/api/v1/calendar/events/${event.uuid}/media-suggestions`, {media_ids:[mediaId]}); setMessage(`Připojeno k akci „${event.title}“.`); setEvents(current => current.map(item => item.uuid === event.uuid ? {...item,already_linked:true} : item)); router.reload({only:['media']}); } catch (error:any) { setMessage(error?.response?.data?.message ?? 'Médium se nepodařilo připojit k akci.'); } finally { setLoading(false); } };
    return <section><h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">Přidat ke společné akci</h3>{!loaded ? <button onClick={load} disabled={loading} className="min-h-9 rounded-lg border border-pink-400/30 px-3 text-xs text-pink-100 disabled:opacity-40">{loading ? 'Hledám…' : 'Najít akci podle data'}</button> : <div className="space-y-1.5">{events.map(event => <div key={event.uuid} className="flex items-center justify-between gap-2 rounded-lg border border-[var(--color-border)] p-2"><div className="min-w-0"><p className="truncate text-xs text-white">{event.title}</p><p className="text-[10px] text-[var(--color-text-secondary)]">{new Date(event.starts_at).toLocaleDateString('cs-CZ')}{event.place_name ? ` · ${event.place_name}` : ''}</p></div><button disabled={loading || event.already_linked} onClick={() => attach(event)} className={`shrink-0 rounded px-2 py-1 text-[10px] ${event.already_linked ? 'bg-emerald-500/10 text-emerald-200' : 'border border-pink-400/30 text-pink-100'}`}>{event.already_linked ? 'Připojeno ✓' : 'Přidat'}</button></div>)}{!events.length && <p className="text-xs text-[var(--color-text-secondary)]">V okolí data média není žádná akce, kterou můžete upravit.</p>}</div>}{message && <p className="mt-2 text-[10px] text-[var(--color-text-secondary)]">{message}</p>}</section>;
}

function MilestonePanel({ mediaId, gallerySpaceId, takenAt }: { mediaId: number; gallerySpaceId: number; takenAt?: string }) {
    const [milestones, setMilestones] = useState<Array<{ uuid: string; title: string; occurred_on: string; media_item_id?: number | null }>>([]);
    const [selected, setSelected] = useState('');
    const [title, setTitle] = useState('');
    const [occurredOn, setOccurredOn] = useState(takenAt?.slice(0, 10) ?? new Date().toISOString().slice(0, 10));
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState('');

    const load = async () => {
        try {
            const response = await axios.get('/api/v1/relationship-milestones');
            const items = response.data ?? [];
            setMilestones(items);
            setSelected(current => current || items.find((item: any) => !item.media_item_id)?.uuid || '');
        } catch { setMessage('Milníky se nepodařilo načíst.'); }
    };

    useEffect(() => { load(); }, []);

    const attach = async () => {
        if (!selected) { setMessage('Nejdřív vytvořte milník nebo vyberte existující.'); return; }
        setLoading(true); setMessage('');
        try {
            const response = await axios.patch(`/api/v1/relationship-milestones/${selected}`, { media_item_id: mediaId });
            setMessage(`Vzpomínka je připojena k milníku „${response.data.title}“.`);
            await load();
        } catch (error: any) { setMessage(error?.response?.data?.message ?? 'Vzpomínku se nepodařilo připojit.'); }
        finally { setLoading(false); }
    };

    const create = async () => {
        if (!title.trim()) { setMessage('Doplňte název společného milníku.'); return; }
        setLoading(true); setMessage('');
        try {
            const response = await axios.post('/api/v1/relationship-milestones', {
                gallery_space_id: gallerySpaceId, title: title.trim(), occurred_on: occurredOn,
                media_item_id: mediaId, visibility: 'shared', remind_annually: true,
            });
            setMessage(`Vznikl společný milník „${response.data.title}“.`);
            setTitle(''); await load();
        } catch (error: any) { setMessage(error?.response?.data?.message ?? 'Milník se nepodařilo uložit.'); }
        finally { setLoading(false); }
    };

    const linked = milestones.filter(item => item.media_item_id === mediaId);
    const available = milestones.filter(item => item.media_item_id !== mediaId);

    return <section>
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">Naše milníky</h3>
        {linked.length > 0 && <div className="mb-2 space-y-1">{linked.map(item => <p key={item.uuid} className="rounded-lg bg-pink-500/10 px-2 py-1 text-xs text-pink-100">{item.title} · {new Date(item.occurred_on).toLocaleDateString('cs-CZ')}</p>)}</div>}
        {available.length > 0 && <div className="flex gap-2"><select value={selected} onChange={event => setSelected(event.target.value)} className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"><option value="">Vybrat existující milník</option>{available.map(item => <option key={item.uuid} value={item.uuid}>{item.title}</option>)}</select><button onClick={attach} disabled={loading || !selected} className="min-h-9 rounded-lg border border-pink-400/30 px-3 text-xs text-pink-100 disabled:opacity-40">Připojit</button></div>}
        <div className="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]"><input value={title} onChange={event => setTitle(event.target.value)} placeholder="Nový společný milník" className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/><input type="date" value={occurredOn} onChange={event => setOccurredOn(event.target.value)} className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/></div>
        <button onClick={create} disabled={loading || !title.trim()} className="mt-2 min-h-9 rounded-lg bg-[var(--color-accent)] px-3 text-xs text-white disabled:opacity-40">Vytvořit z této vzpomínky</button>
        {message && <p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">{message}</p>}
    </section>;
}

function RevisitFromMediaPanel({ uuid, title, places }: { uuid: string; title: string; places: Place[] }) {
    const [loaded, setLoaded] = useState(false);
    const [candidates, setCandidates] = useState<Array<{uuid:string;display_title?:string|null;original_filename:string;taken_at?:string|null}>>([]);
    const [prompt, setPrompt] = useState(''); const [message, setMessage] = useState('');
    const [form, setForm] = useState({ title: `Znovu spolu: ${title}`, place_name: places[0]?.name ?? '', starts_at: '', reminder_minutes: '10080' });
    const [saving, setSaving] = useState(false); const [eventUuid, setEventUuid] = useState('');

    const load = async () => {
        setMessage('');
        try {
            const response = await axios.get(`/api/v1/media/${uuid}/revisit-suggestions`);
            setCandidates(response.data.candidates ?? []); setPrompt(response.data.prompt ?? response.data.message ?? ''); setLoaded(true);
        } catch (error: any) { setMessage(error?.response?.data?.message ?? 'Návrh návratu se nepodařilo načíst.'); }
    };
    const schedule = async () => {
        if (!form.starts_at) { setMessage('Vyberte termín společného návratu.'); return; }
        setSaving(true); setMessage('');
        try {
            const response = await axios.post(`/api/v1/media/${uuid}/revisit-suggestions`, { ...form, reminder_minutes: Number(form.reminder_minutes || 0) });
            setEventUuid(response.data.uuid); setMessage('Společný návrat je v kalendáři pro oba a původní vzpomínka je k němu připojená.');
        } catch (error: any) { setMessage(error?.response?.data?.message ?? 'Společný návrat se nepodařilo naplánovat.'); }
        finally { setSaving(false); }
    };

    return <section>
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">Vrátit se na toto místo</h3>
        {!loaded ? <button onClick={load} className="min-h-9 rounded-lg border border-teal-400/30 px-3 text-xs text-teal-100">Navrhnout společný návrat</button> : <div className="space-y-2"><p className="text-xs text-[var(--color-text-secondary)]">{prompt || 'Vytvořte nový společný zážitek z této vzpomínky.'}</p>{candidates.length > 0 && <p className="text-[10px] text-[var(--color-text-secondary)]">Ve stejném okolí už máte {candidates.length} dalších vzpomínek. <Link href={`/media/${candidates[0].uuid}`} className="text-[var(--color-accent)] hover:underline">Otevřít poslední →</Link></p>}<input value={form.title} onChange={event => setForm(current => ({...current,title:event.target.value}))} maxLength={160} aria-label="Název společné akce" className="min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/><div className="grid gap-2 sm:grid-cols-2"><input value={form.place_name} onChange={event => setForm(current => ({...current,place_name:event.target.value}))} maxLength={255} placeholder="Místo" className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/><input type="datetime-local" value={form.starts_at} onChange={event => setForm(current => ({...current,starts_at:event.target.value}))} aria-label="Termín návratu" className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/></div><div className="flex gap-2"><input type="number" min="0" max="525600" value={form.reminder_minutes} onChange={event => setForm(current => ({...current,reminder_minutes:event.target.value}))} aria-label="Minut předem" title="Minut předem" className="min-h-9 w-28 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"/><button disabled={saving} onClick={schedule} className="min-h-9 rounded-lg border border-teal-400/30 px-3 text-xs text-teal-100 disabled:opacity-40">{saving ? 'Plánuji…' : 'Přidat do kalendáře'}</button></div></div>}
        {message && <p className={`mt-2 text-[10px] ${eventUuid ? 'text-emerald-300' : 'text-[var(--color-text-secondary)]'}`}>{message}{eventUuid && <Link href={`/calendar/events/${eventUuid}`} className="ml-1 underline">Otevřít akci</Link>}</p>}
    </section>;
}

// ── GPS mini-map (lazy Leaflet) ─────────────────────────────────────────
function GpsMap({ lat, lng }: { lat: number; lng: number }) {
    const mapRef = useRef<HTMLDivElement>(null);
    const [ready, setReady] = useState(!!(window as any).L);

    useEffect(() => {
        if ((window as any).L) { setReady(true); return; }
        const link = document.createElement('link'); link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(link);
        const s = document.createElement('script');
        s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        s.onload = () => setReady(true); document.head.appendChild(s);
    }, []);

    useEffect(() => {
        if (!ready || !mapRef.current) return;
        const L = (window as any).L;
        const map = L.map(mapRef.current, {
            zoomControl: false, attributionControl: false,
            dragging: false, touchZoom: false, doubleClickZoom: false, scrollWheelZoom: false,
        }).setView([lat, lng], 14);
        addLocalizedBaseLayer(L, map);
        L.circleMarker([lat, lng], { radius: 9, fillColor: '#6366f1', color: 'white', weight: 2.5, fillOpacity: 1 }).addTo(map);
        return () => { try { map.remove(); } catch { /* ignore */ } };
    }, [ready, lat, lng]);

    return (
        <a href={`https://maps.google.com/maps?q=${lat},${lng}`} target="_blank" rel="noopener noreferrer"
            className="block w-full h-28 rounded-lg overflow-hidden group relative">
            {!ready && <div className="w-full h-full bg-[var(--color-bg-secondary)] animate-pulse rounded-lg"/>}
            <div ref={mapRef} className="w-full h-full"/>
            <div className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/30">
                <span className="text-[10px] text-white bg-black/50 px-2 py-0.5 rounded">Otevřít mapy ↗</span>
            </div>
        </a>
    );
}

function ProgressiveImage({ uuid, fullUrl, thumbUrl, alt, width, height, dominantColor, prevUuid, nextUuid }: {
    uuid: string; fullUrl: string; thumbUrl?: string;
    alt: string; width?: number; height?: number; dominantColor?: string;
    prevUuid?: string; nextUuid?: string;
}) {
    const [loaded, setLoaded]     = useState(false);
    const [error, setError]       = useState(false);
    const [scale, setScale]       = useState(1);
    const [offset, setOffset]     = useState({ x: 0, y: 0 });
    const [dragging, setDragging] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);
    const dragStart     = useRef<{ x: number; y: number; ox: number; oy: number } | null>(null);
    const pinchDistRef  = useRef<number | null>(null);
    const swipeStartRef = useRef<{ x: number; y: number } | null>(null);
    const containerRef  = useRef<HTMLDivElement>(null);
    // Keep scale in a ref for non-React touch handlers
    const scaleRef = useRef(1);

    // Inertia reuses this viewer while navigating between media. Without a
    // reset, one failed original kept the fallback thumbnail (and disabled
    // zoom controls) for every following photo.
    useEffect(() => {
        setLoaded(false);
        setError(false);
        setScale(1);
        scaleRef.current = 1;
        setOffset({ x: 0, y: 0 });
        setDragging(false);
        setFullscreen(false);
    }, [uuid]);

    const clampOffset = useCallback((s: number, ox: number, oy: number) => {
        if (s <= 1) return { x: 0, y: 0 };
        const el = containerRef.current;
        if (!el) return { x: ox, y: oy };
        const maxX = el.clientWidth  * (s - 1) / 2;
        const maxY = el.clientHeight * (s - 1) / 2;
        return { x: Math.max(-maxX, Math.min(maxX, ox)), y: Math.max(-maxY, Math.min(maxY, oy)) };
    }, []);

    const zoom = useCallback((delta: number) => {
        setScale(prev => {
            const next = Math.max(1, Math.min(8, prev + delta));
            scaleRef.current = next;
            setOffset(o => clampOffset(next, o.x, o.y));
            return next;
        });
    }, [clampOffset]);

    const reset = () => { setScale(1); scaleRef.current = 1; setOffset({ x: 0, y: 0 }); };

    // Mouse wheel zoom
    const onWheel = (e: React.WheelEvent) => { e.preventDefault(); zoom(e.deltaY < 0 ? 0.3 : -0.3); };

    // Double-click zoom
    const onDblClick = (e: React.MouseEvent) => { if (scale > 1) { reset(); } else { zoom(2); } };

    // Mouse drag to pan
    const onMouseDown = (e: React.MouseEvent) => {
        if (scale <= 1) return;
        setDragging(true);
        dragStart.current = { x: e.clientX, y: e.clientY, ox: offset.x, oy: offset.y };
    };
    const onMouseMove = (e: React.MouseEvent) => {
        if (!dragging || !dragStart.current) return;
        const dx = e.clientX - dragStart.current.x;
        const dy = e.clientY - dragStart.current.y;
        setOffset(clampOffset(scale, dragStart.current.ox + dx, dragStart.current.oy + dy));
    };
    const onMouseUp = () => { setDragging(false); dragStart.current = null; };

    // Touch: non-passive touchmove to allow preventDefault for pinch
    useEffect(() => {
        const el = containerRef.current;
        if (!el) return;
        const handler = (e: TouchEvent) => {
            if (e.touches.length >= 2) {
                e.preventDefault(); // Block page scroll during pinch
            } else if (e.touches.length === 1 && scaleRef.current > 1) {
                e.preventDefault(); // Block scroll when panning a zoomed image
            }
        };
        el.addEventListener('touchmove', handler, { passive: false });
        return () => el.removeEventListener('touchmove', handler);
    }, []);

    const onTouchStart = (e: React.TouchEvent) => {
        if (e.touches.length === 2) {
            // Pinch start
            const dx = e.touches[0].clientX - e.touches[1].clientX;
            const dy = e.touches[0].clientY - e.touches[1].clientY;
            pinchDistRef.current = Math.sqrt(dx * dx + dy * dy);
            swipeStartRef.current = null;
        } else if (e.touches.length === 1) {
            pinchDistRef.current = null;
            swipeStartRef.current = { x: e.touches[0].clientX, y: e.touches[0].clientY };
            if (scaleRef.current > 1) {
                // Pan start
                dragStart.current = { x: e.touches[0].clientX, y: e.touches[0].clientY, ox: offset.x, oy: offset.y };
            }
        }
    };

    const onTouchMove = (e: React.TouchEvent) => {
        if (e.touches.length === 2 && pinchDistRef.current !== null) {
            // Pinch zoom
            const dx = e.touches[0].clientX - e.touches[1].clientX;
            const dy = e.touches[0].clientY - e.touches[1].clientY;
            const dist = Math.sqrt(dx * dx + dy * dy);
            const delta = (dist - pinchDistRef.current) / 80;
            zoom(delta);
            pinchDistRef.current = dist;
        } else if (e.touches.length === 1 && scaleRef.current > 1 && dragStart.current) {
            // Pan when zoomed
            const dx = e.touches[0].clientX - dragStart.current.x;
            const dy = e.touches[0].clientY - dragStart.current.y;
            setOffset(clampOffset(scaleRef.current, dragStart.current.ox + dx, dragStart.current.oy + dy));
        }
    };

    const onTouchEnd = (e: React.TouchEvent) => {
        pinchDistRef.current = null;
        dragStart.current = null;
        // Swipe navigation (only when not zoomed)
        if (scaleRef.current <= 1 && swipeStartRef.current && e.changedTouches.length > 0) {
            const dx = e.changedTouches[0].clientX - swipeStartRef.current.x;
            const dy = e.changedTouches[0].clientY - swipeStartRef.current.y;
            if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                if (dx < 0 && nextUuid) router.visit(`/media/${nextUuid}`);
                if (dx > 0 && prevUuid) router.visit(`/media/${prevUuid}`);
            }
        }
        swipeStartRef.current = null;
    };

    // Keyboard shortcuts
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if (e.key === '+' || e.key === '=') zoom(0.5);
            if (e.key === '-') zoom(-0.5);
            if (e.key === '0') reset();
            if (e.key === 'f' || e.key === 'F') setFullscreen(v => !v);
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [zoom]);

    const imgStyle: React.CSSProperties = {
        transform: `scale(${scale}) translate(${offset.x / scale}px, ${offset.y / scale}px)`,
        transformOrigin: 'center',
        transition: dragging ? 'none' : 'transform 0.15s ease',
        cursor: scale > 1 ? (dragging ? 'grabbing' : 'grab') : 'zoom-in',
        maxHeight: 'calc(100dvh - 120px)',
        maxWidth: '100%',
        objectFit: 'contain',
    };

    return (
        <div
            ref={containerRef}
            className={clsx(
                'relative flex items-center justify-center select-none overflow-hidden',
                fullscreen ? 'fixed inset-0 z-[900] bg-black' : 'w-full h-full'
            )}
            onWheel={onWheel}
            onMouseDown={onMouseDown}
            onMouseMove={onMouseMove}
            onMouseUp={onMouseUp}
            onMouseLeave={onMouseUp}
            onDoubleClick={onDblClick}
            onTouchStart={onTouchStart}
            onTouchMove={onTouchMove}
            onTouchEnd={onTouchEnd}
        >
            {/* Blurred placeholder */}
            {dominantColor && !loaded && (
                <div className="absolute inset-0" style={{ backgroundColor: dominantColor }} />
            )}
            {thumbUrl && !loaded && (
                <img src={thumbUrl} alt="" aria-hidden className="absolute inset-0 w-full h-full object-contain blur-sm opacity-60" />
            )}

            {/* Full resolution */}
            {!error ? (
                <img
                    key={uuid}
                    src={fullUrl}
                    alt={alt}
                    onLoad={() => setLoaded(true)}
                    onError={() => setError(true)}
                    style={{ ...imgStyle, opacity: loaded ? 1 : 0 }}
                    draggable={false}
                />
            ) : thumbUrl ? (
                <img src={thumbUrl} alt={alt} style={imgStyle} draggable={false} />
            ) : (
                <div className="flex flex-col items-center gap-2 text-[var(--color-text-secondary)]">
                    <Clock size={24} />
                    <p className="text-sm">Fotografie není dostupná</p>
                </div>
            )}

            {/* Loading spinner */}
            {!loaded && !error && (
                <div className="absolute bottom-4 right-4 z-20 w-5 h-5 rounded-full border-2 border-white/40 border-t-white animate-spin" />
            )}

            {/* Zoom controls */}
            {loaded && (
                <div className="absolute bottom-4 left-1/2 -translate-x-1/2 z-20 flex items-center gap-1 bg-black/60 backdrop-blur rounded-full px-2 py-1">
                    <button onClick={() => zoom(-0.5)} className="p-1.5 text-white/80 hover:text-white transition-colors" title="Oddálit (-)">
                        <ZoomOut size={14} />
                    </button>
                    <span className="text-white/70 text-xs w-10 text-center">{Math.round(scale * 100)}%</span>
                    <button onClick={() => zoom(0.5)} className="p-1.5 text-white/80 hover:text-white transition-colors" title="Přiblížit (+)">
                        <ZoomIn size={14} />
                    </button>
                    {scale > 1 && (
                        <button onClick={reset} className="p-1.5 text-white/80 hover:text-white transition-colors" title="Původní velikost (0)">
                            <RotateCcw size={14} />
                        </button>
                    )}
                    <div className="w-px h-4 bg-white/20 mx-0.5" />
                    <button onClick={() => setFullscreen(v => !v)} className="p-1.5 text-white/80 hover:text-white transition-colors" title="Celá obrazovka (F)">
                        {fullscreen ? <Minimize2 size={14} /> : <Maximize2 size={14} />}
                    </button>
                </div>
            )}

            {/* Fullscreen close */}
            {fullscreen && (
                <button
                    onClick={() => setFullscreen(false)}
                    className="absolute top-4 right-4 z-30 p-2 rounded-full bg-black/60 text-white hover:bg-black/80 transition-colors"
                >
                    <Minimize2 size={18} />
                </button>
            )}
        </div>
    );
}

export default function MediaShow({ media, breadcrumb, prev, next }: Props) {
    const [item, setItem]        = useState(media);
    const [infoOpen, setInfo]    = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    const [isMine,     setIsMine]    = useState(media.is_my_favorite ?? media.is_favorite);
    const [isShared,   setIsShared]  = useState(media.is_shared_favorite ?? false);
    const [rating, setRating]    = useState(media.my_rating ?? media.rating ?? 0);
    const [hovRating, setHovR]   = useState(0);
    const [saving, setSaving]    = useState(false);
    const [comments, setComments] = useState<any[]>([]);
    const [commentText, setCommentText] = useState('');
    const [commentPrivate, setCommentPrivate] = useState(false);
    const [commentsLoaded, setCommentsLoaded] = useState(false);
    const [memberRatings, setMemberRatings] = useState<{user_id:number;name:string;initial:string;rating:number;is_me:boolean}[]>([]);

    // Load comments + per-user ratings when info panel opens
    useEffect(() => {
        if (infoOpen && !commentsLoaded) {
            axios.get(`/api/v1/media/${item.uuid}/comments`)
                .then(r => { setComments(r.data ?? []); setCommentsLoaded(true); })
                .catch(() => {});
        }
        if (infoOpen && memberRatings.length === 0) {
            axios.get(`/api/v1/media/${item.uuid}/ratings`)
                .then(r => setMemberRatings(r.data ?? []))
                .catch(() => {});
        }
    }, [infoOpen, item.uuid]);

    const largeVar    = item.variants.find(v => v.type === 'large')
                    ?? item.variants.find(v => v.type === 'medium')
                    ?? item.variants.find(v => v.type === 'small')
                    ?? item.variants.find(v => v.type === 'thumbnail')
                    ?? item.variants.find(v => v.type === 'original');

    const originalVar = item.variants.find(v => v.type === 'original');
    const placeholder = item.variants.find(v => v.type === 'placeholder');
    const compatVideo = item.variants.find(v => v.type === 'video_compat');
    const poster      = item.variants.find(v => v.type === 'video_poster') ?? item.variants.find(v => v.type === 'thumbnail');

    // Full-res URL: always stream via /media/{uuid}/full (local first, Drive fallback)
    const fullUrl  = `/media/${item.uuid}/full`;
    // Thumb for progressive loading placeholder
    const thumbUrl = item.variants.find(v => v.type === 'thumbnail')?.url
                  ?? item.variants.find(v => v.type === 'original')?.url;

    const toggleFavorite = async () => {
        setSaving(true);
        try {
            const res = await axios.post(`/api/v1/favorites/${item.uuid}/toggle`);
            setIsMine(res.data.is_my_favorite);
            setIsShared(res.data.is_shared_favorite ?? false);
            setItem(prev => ({ ...prev, is_favorite: res.data.is_favorite, is_my_favorite: res.data.is_my_favorite, is_shared_favorite: res.data.is_shared_favorite }));
        } finally {
            setSaving(false);
        }
    };

    const setRatingValue = async (val: number) => {
        const newVal = val === rating ? 0 : val;
        setSaving(true);
        try {
            await axios.patch(`/api/v1/media/${item.uuid}`, { rating: newVal });
            setRating(newVal);
            setItem(prev => ({ ...prev, rating: newVal, my_rating: newVal }));
            // Refresh member ratings
            axios.get(`/api/v1/media/${item.uuid}/ratings`).then(r => setMemberRatings(r.data ?? []));
        } finally {
            setSaving(false);
        }
    };

    const archiveItem = async () => {
        const res = await axios.post(`/media/${item.uuid}/archive`);
        if (res.data.is_archived) {
            router.visit('/archive');
        }
    };

    const toggleVault = async () => {
        const response = await axios.post(`/vault/media/${item.uuid}/toggle`);
        setItem(previous => ({ ...previous, is_hidden: response.data.is_hidden }));
        if (response.data.is_hidden) router.visit('/vault');
    };

    const trashItem = async () => {
        if (!confirm('Přesunout do koše?')) return;
        try {
            await axios.delete(`/media/${item.uuid}`);
            router.visit('/timeline');
        } catch (e: any) {
            const msg = e?.response?.data?.message ?? e?.message ?? 'Chyba p\u0159i p\u0159esunu do ko\u0161e';
            alert(msg);
        }
    };

    const downloadItem = () => { window.open(`/media/${item.uuid}/download?original=1`, '_blank'); };
    const downloadLocal = () => { window.open(`/media/${item.uuid}/download`, '_blank'); };

    // Keyboard shortcuts (bod 62)
    useEffect(() => {
        const handler = async (e: KeyboardEvent) => {
            // Skip if focus is on input/textarea
            const tag = (e.target as HTMLElement)?.tagName?.toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

            switch (e.key) {
                case 'f': case 'F': toggleFavorite(); break;
                case 'i': case 'I': setInfo(v => !v); break;
                case 'd': case 'D': downloadItem(); break;
                case 'Delete': trashItem(); break;
                case 'ArrowLeft':  if (prev) router.visit(`/media/${prev.uuid}`); break;
                case 'ArrowRight': if (next) router.visit(`/media/${next.uuid}`); break;
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [item.uuid, item.is_favorite, prev, next]);

    return (
        <AppLayout>
            <Head title={item.display_title ?? item.original_filename} />

            <div className="flex h-full min-h-0 flex-col">
                {/* ── Top bar ─────────────────────────────────────────────── */}
                <div className="flex h-11 shrink-0 items-center gap-0.5 overflow-visible border-b border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-1 sm:gap-1 sm:px-2">

                    {/* Back to timeline */}
                    <Link href="/timeline" className="p-2 text-[var(--color-text-secondary)] hover:text-white rounded-lg hover:bg-white/10 shrink-0" title="Zpět (Esc)">
                        <ChevronLeft size={16}/>
                    </Link>

                    {/* ← Prev photo */}
                    {prev ? <Link href={`/media/${prev.uuid}`} className="p-1.5 rounded text-white hover:bg-white/10 transition-colors shrink-0" title="Předchozí (←)"><ChevronLeft size={13}/></Link>
                        : <button disabled className="p-1.5 rounded text-[var(--color-border)] shrink-0" title="Žádná předchozí fotografie"><ChevronLeft size={13}/></button>}

                    {/* Title (centered) */}
                    <p className="flex-1 text-center text-sm text-white/80 font-medium truncate px-1 min-w-0">
                        {item.display_title ?? item.original_filename}
                    </p>

                    {/* Next photo → */}
                    {next ? <Link href={`/media/${next.uuid}`} className="p-1.5 rounded text-white hover:bg-white/10 transition-colors shrink-0" title="Další (→)"><ChevronRight size={13}/></Link>
                        : <button disabled className="p-1.5 rounded text-[var(--color-border)] shrink-0" title="Žádná další fotografie"><ChevronRight size={13}/></button>}

                    <div className="mx-0.5 hidden h-4 w-px bg-[var(--color-border)] sm:block"/>

                    {/* ❤️ Favorite */}
                    <button onClick={toggleFavorite} disabled={saving}
                        title={isShared ? 'Společné oblíbené ❤️❤️' : isMine ? 'Odebrat z oblíbených (F)' : 'Přidat do oblíbených (F)'}
                        className={clsx('flex items-center gap-0.5 p-2 rounded-lg hover:bg-white/10 transition-colors shrink-0', isMine ? 'text-red-400' : 'text-[var(--color-text-secondary)]')}>
                        <Heart size={15} className={isMine ? 'fill-red-400' : ''}/>
                        {isShared && <Heart size={11} className="fill-red-400 text-red-400 -ml-1.5"/>}
                    </button>

                    {/* ★ Rating */}
                    <div className="hidden shrink-0 items-center gap-0 sm:flex">
                        {[1,2,3,4,5].map(n => (
                            <button key={n} onClick={() => setRatingValue(n)}
                                onMouseEnter={() => setHovR(n)} onMouseLeave={() => setHovR(0)}
                                className="p-1 hover:scale-110 transition-transform">
                                <Star size={13} className={clsx('transition-colors',
                                    (hovRating || rating) >= n ? 'text-yellow-400 fill-yellow-400' : 'text-[var(--color-text-secondary)]')}/>
                            </button>
                        ))}
                    </div>

                    {/* 💬 Comments (opens info panel) */}
                    <button onClick={() => setInfo(v => { if (!v) return true; return v; })}
                        className={clsx('relative p-2 rounded-lg hover:bg-white/10 transition-colors shrink-0', infoOpen ? 'text-white' : 'text-[var(--color-text-secondary)]')}
                        title="Komentáře">
                        <MessageSquare size={15}/>
                        {comments.length > 0 && (
                            <span className="absolute top-0.5 right-0.5 text-[9px] bg-[var(--color-accent)] text-white rounded-full w-3.5 h-3.5 flex items-center justify-center font-bold leading-none">
                                {comments.length}
                            </span>
                        )}
                    </button>

                    {/* ℹ Info panel toggle */}
                    <button onClick={() => setInfo(!infoOpen)}
                        className={clsx('p-2 rounded-lg hover:bg-white/10 transition-colors shrink-0', infoOpen ? 'text-white bg-white/10' : 'text-[var(--color-text-secondary)]')}
                        title="Info panel (I)">
                        <Info size={15}/>
                    </button>

                    {/* ⋯ More */}
                    <div className="relative shrink-0">
                        <button onClick={() => setMoreOpen(v => !v)}
                            className="p-2 rounded-lg hover:bg-white/10 text-[var(--color-text-secondary)] hover:text-white transition-colors"
                            title="Další akce">
                            <MoreHorizontal size={15}/>
                        </button>
                        {moreOpen && (
                            <div className="absolute right-0 top-full mt-1 w-48 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden z-50"
                                onMouseLeave={() => setMoreOpen(false)}>
                                <button onClick={() => { downloadItem(); setMoreOpen(false); }}
                                    className="w-full text-left px-4 py-2.5 text-xs text-[var(--color-text-secondary)] hover:text-white hover:bg-white/5 flex items-center gap-2">
                                    <Download size={12}/> Stáhnout z Drive
                                </button>
                                <button onClick={() => { downloadLocal(); setMoreOpen(false); }}
                                    className="w-full text-left px-4 py-2.5 text-xs text-[var(--color-text-secondary)] hover:text-white hover:bg-white/5 flex items-center gap-2">
                                    <ExternalLink size={12}/> Stáhnout lokálně
                                </button>
                                <div className="border-t border-[var(--color-border)]"/>
                                <button onClick={() => { archiveItem(); setMoreOpen(false); }}
                                    className="w-full text-left px-4 py-2.5 text-xs text-[var(--color-text-secondary)] hover:text-white hover:bg-white/5 flex items-center gap-2">
                                    <Archive size={12}/> Archivovat
                                </button>
                                <button onClick={() => { toggleVault(); setMoreOpen(false); }}
                                    className="w-full text-left px-4 py-2.5 text-xs text-[var(--color-text-secondary)] hover:text-white hover:bg-white/5 flex items-center gap-2">
                                    <LockKeyhole size={12}/> {item.is_hidden ? 'Odebrat z trezoru' : 'Přesunout do trezoru'}
                                </button>
                                <button onClick={() => { trashItem(); setMoreOpen(false); }}
                                    className="w-full text-left px-4 py-2.5 text-xs text-red-400 hover:bg-red-500/10 flex items-center gap-2">
                                    <Trash2 size={12}/> Přesunout do koše
                                </button>
                                <div className="border-t border-[var(--color-border)]"/>
                                <div className="px-4 py-2 text-[10px] text-[var(--color-text-secondary)] space-y-0.5">
                                    <p>F — oblíbené · I — info</p>
                                    <p>D — stáhnout · Del — koš</p>
                                    <p>← → — navigace · +/- zoom</p>
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {/* Main content */}
                <div className="relative flex min-h-0 flex-1 overflow-hidden">
                    {/* Media viewer */}
                    <div className="flex-1 flex items-center justify-center bg-black relative overflow-hidden">
                        {/* Prev / Next overlays (shown on hover, useful for mouse) */}
                        {prev && (
                            <Link href={`/media/${prev.uuid}`} className="absolute left-3 z-10 p-2 rounded-full bg-black/40 hover:bg-black/60 text-white transition-colors opacity-0 hover:opacity-100 group-hover:opacity-100 focus:opacity-100">
                                <ChevronLeft size={20} />
                            </Link>
                        )}
                        {next && (
                            <Link href={`/media/${next.uuid}`} className="absolute right-3 z-10 p-2 rounded-full bg-black/40 hover:bg-black/60 text-white transition-colors opacity-0 hover:opacity-100 group-hover:opacity-100 focus:opacity-100">
                                <ChevronRight size={20} />
                            </Link>
                        )}

                        {item.media_type === 'video' ? (
                            <video
                                key={item.uuid}
                                controls
                                className="max-h-full max-w-full"
                                poster={poster?.url}
                                preload="metadata"
                            >
                                {compatVideo && <source src={compatVideo.url} type="video/mp4" />}
                                {originalVar && <source src={originalVar.url} type={item.mime_type} />}
                                <source src={`/media/${item.uuid}/stream`} type={item.mime_type} />
                            </video>
                        ) : item.media_type === 'photo' ? (
                            item.is_panorama ? (
                                /* Panorama / 360° viewer */
                                <div className="w-full h-full relative overflow-hidden">
                                    {/* Badge */}
                                    <div className="absolute top-3 left-3 z-10 px-2 py-0.5 rounded-full text-[11px] font-bold bg-black/60 text-white backdrop-blur-sm">
                                        {item.is_360 ? '🌐 360°' : '🔭 Panorama'}
                                    </div>
                                    {item.is_360 ? (
                                        /* 360° equirectangular: horizontally draggable */
                                        <div className="w-full h-full overflow-x-scroll overflow-y-hidden cursor-grab active:cursor-grabbing"
                                            style={{ scrollSnapType: 'none' }}>
                                            <img
                                                src={fullUrl}
                                                alt={item.display_title ?? item.original_filename}
                                                style={{ height: '100%', width: 'auto', minWidth: '200%', objectFit: 'cover' }}
                                                draggable={false}
                                            />
                                        </div>
                                    ) : (
                                        /* Wide panorama: scroll-x */
                                        <div className="w-full h-full overflow-x-auto overflow-y-hidden">
                                            <img
                                                src={fullUrl}
                                                alt={item.display_title ?? item.original_filename}
                                                style={{ height: '100%', width: 'auto', maxWidth: 'none' }}
                                                draggable={false}
                                            />
                                        </div>
                                    )}
                                    <p className="absolute bottom-3 left-1/2 -translate-x-1/2 text-white/50 text-[10px] bg-black/40 px-2 py-0.5 rounded-full">
                                        ← přetažením nebo scrollem →
                                    </p>
                                </div>
                            ) : (
                                <ProgressiveImage
                                    uuid={item.uuid}
                                    fullUrl={fullUrl}
                                    thumbUrl={thumbUrl}
                                    alt={item.display_title ?? item.original_filename}
                                    width={item.width}
                                    height={item.height}
                                    dominantColor={placeholder?.dominant_color}
                                    prevUuid={prev?.uuid}
                                    nextUuid={next?.uuid}
                                />
                            )
                        ) : (
                            <div className="flex flex-col items-center gap-3 text-[var(--color-text-secondary)]">
                                <div className="w-16 h-16 rounded-xl bg-[var(--color-bg-card)] flex items-center justify-center">
                                    <Clock size={24} />
                                </div>
                                <p className="text-sm">Náhled se připravuje…</p>
                                <p className="text-xs">{item.original_filename}</p>
                            </div>
                        )}
                    </div>

                    {/* Info panel */}
                    {infoOpen && (
                        <div className="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom,0px))] z-[620] max-h-[72dvh] w-full space-y-4 overflow-y-auto overscroll-contain rounded-t-2xl border-t border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-4 shadow-2xl md:static md:z-auto md:max-h-none md:w-72 md:shrink-0 md:rounded-none md:border-l md:border-t-0 md:shadow-none">
                            <div className="sticky top-0 z-10 -mx-4 -mt-4 mb-2 flex items-center justify-between border-b border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-4 py-2 md:hidden"><span className="text-sm font-semibold text-white">Informace a vzpomínky</span><button type="button" onClick={()=>setInfo(false)} className="flex h-9 w-9 items-center justify-center rounded-lg text-white" aria-label="Zavřít informace"><X size={18}/></button></div>
                            {/* File */}
                            <section>
                                <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Soubor</h3>
                                <div className="space-y-1.5 text-xs">
                                    <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Název</span><span className="text-white truncate ml-2 max-w-36">{item.original_filename}</span></div>
                                    <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Velikost</span><span className="text-white">{formatBytes(item.size_bytes)}</span></div>
                                    {item.width && item.height && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Rozlišení</span><span className="text-white">{item.width} × {item.height}</span></div>}
                                    {item.duration_ms && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Délka</span><span className="text-white">{formatDuration(item.duration_ms)}</span></div>}
                                    {item.media_type === 'video' && item.video_codec && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Video</span><span className="text-white uppercase">{item.video_codec}{item.frame_rate ? ` · ${Math.round(item.frame_rate)} fps` : ''}</span></div>}
                                    {item.media_type === 'video' && item.audio_codec && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Zvuk</span><span className="text-white uppercase">{item.audio_codec}</span></div>}
                                    {item.media_type === 'video' && item.bitrate && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Datový tok</span><span className="text-white">{Math.round(item.bitrate / 1000)} kb/s</span></div>}
                                    {/* Format badges */}
                                    <div className="flex flex-wrap gap-1 pt-0.5">
                                        {item.is_raw && <span className="bg-orange-500/20 text-orange-300 text-[9px] px-1.5 py-0.5 rounded-full font-medium uppercase">{item.raw_format ?? 'RAW'}</span>}
                                        {item.is_360 && <span className="bg-indigo-500/20 text-indigo-300 text-[9px] px-1.5 py-0.5 rounded-full font-medium">🌐 360°</span>}
                                        {item.is_panorama && !item.is_360 && <span className="bg-cyan-500/20 text-cyan-300 text-[9px] px-1.5 py-0.5 rounded-full font-medium">🔭 Panorama</span>}
                                        {item.live_photo_role === 'main' && <span className="bg-green-500/20 text-green-300 text-[9px] px-1.5 py-0.5 rounded-full font-medium">▶ Live Photo</span>}
                                        {item.live_photo_role === 'video' && <span className="bg-yellow-500/20 text-yellow-300 text-[9px] px-1.5 py-0.5 rounded-full font-medium">🎬 Live Video</span>}
                                    </div>
                                </div>
                            </section>

                            {/* Date */}
                            {item.taken_at && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <Clock size={10} /> Datum
                                    </h3>
                                    <p className="text-xs text-white">{formatDate(item.taken_at)}</p>
                                </section>
                            )}

                            {/* Camera */}
                            {(item.camera_make || item.camera_model) && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <Camera size={10} /> Fotoaparát
                                    </h3>
                                    <div className="space-y-1 text-xs">
                                        {item.camera_make && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Výrobce</span><span className="text-white">{item.camera_make}</span></div>}
                                        {item.camera_model && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Model</span><span className="text-white">{item.camera_model}</span></div>}
                                        {item.lens_model && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Objektiv</span><span className="text-white truncate ml-2 max-w-32">{item.lens_model}</span></div>}
                                        {item.aperture && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Clona</span><span className="text-white">{item.aperture}</span></div>}
                                        {item.shutter_speed && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Čas závěrky</span><span className="text-white">{item.shutter_speed}</span></div>}
                                        {item.iso && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">ISO</span><span className="text-white">{item.iso}</span></div>}
                                        {item.focal_length && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Ohnisko</span><span className="text-white">{item.focal_length}</span></div>}
                                    </div>
                                </section>
                            )}

                            {/* GPS */}
                            {item.latitude && item.longitude && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <MapPin size={10} /> GPS poloha
                                    </h3>
                                    {/* Mini interactive map */}
                                    <GpsMap lat={item.latitude} lng={item.longitude} />
                                    <div className="mt-2 space-y-1 text-xs">
                                        <div className="flex justify-between">
                                            <span className="text-[var(--color-text-secondary)]">Šířka</span>
                                            <span className="text-white font-mono">{item.latitude.toFixed(6)}°</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-[var(--color-text-secondary)]">Délka</span>
                                            <span className="text-white font-mono">{item.longitude.toFixed(6)}°</span>
                                        </div>
                                        {item.altitude != null && (
                                            <div className="flex justify-between">
                                                <span className="text-[var(--color-text-secondary)]">Nadm. výška</span>
                                                <span className="text-white">{Math.round(item.altitude)} m</span>
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex gap-2 mt-2">
                                        <a href={`https://maps.google.com/maps?q=${item.latitude},${item.longitude}`}
                                            target="_blank" rel="noopener noreferrer"
                                            className="text-[10px] text-[var(--color-accent)] hover:underline">
                                            Google Maps ↗
                                        </a>
                                        <a href={`https://www.mapy.cz/?q=${item.latitude},${item.longitude}`}
                                            target="_blank" rel="noopener noreferrer"
                                            className="text-[10px] text-[var(--color-accent)] hover:underline">
                                            Mapy.cz ↗
                                        </a>
                                    </div>
                                </section>
                            )}

                            {/* Google Drive */}
                            <section>
                                <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Google Drive</h3>
                                <div className="space-y-1 text-xs">
                                    {item.drive_file_id ? (
                                        <>
                                            <div className="flex justify-between">
                                                <span className="text-[var(--color-text-secondary)]">Stav</span>
                                                <span className="text-green-400">✓ Synchronizováno</span>
                                            </div>
                                            <a href={`https://drive.google.com/file/d/${item.drive_file_id}/view`}
                                                target="_blank" rel="noopener noreferrer"
                                                className="text-[10px] text-[var(--color-accent)] hover:underline block mt-1">
                                                Otevřít na Drive ↗
                                            </a>
                                        </>
                                    ) : item.storage_status === 'local_only' || item.storage_status === 'ready' ? (
                                        <>
                                            <div className="flex justify-between">
                                                <span className="text-[var(--color-text-secondary)]">Stav</span>
                                                <span className="text-green-400">✓ Bezpečně uloženo lokálně</span>
                                            </div>
                                            <p className="text-[10px] leading-relaxed text-[var(--color-text-secondary)]">Google Drive není připojený; originál zůstává k dispozici v galerii.</p>
                                        </>
                                    ) : (
                                        <span className="text-[var(--color-text-secondary)]">Čeká na synchronizaci</span>
                                    )}
                                </div>
                            </section>

                            {/* Tags */}
                            {item.tags.length > 0 && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <Tag size={10} /> Tagy
                                    </h3>
                                    <div className="flex flex-wrap gap-1">
                                        {item.tags.map(tag => (
                                            <span key={tag.id} className="text-[10px] bg-white/10 text-white px-2 py-0.5 rounded-full">
                                                {tag.name}
                                            </span>
                                        ))}
                                    </div>
                                </section>
                            )}

                            {/* People */}
                            {item.people.length > 0 && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <Users size={10} /> Osoby
                                    </h3>
                                    <div className="flex flex-wrap gap-1">
                                        {item.people.map(person => (
                                            <Link key={person.id} href={`/people?highlight=${person.id}`}
                                                className="text-[10px] bg-[var(--color-accent)]/20 text-[var(--color-accent)] px-2 py-0.5 rounded-full hover:bg-[var(--color-accent)]/40 transition-colors">
                                                {person.name}
                                            </Link>
                                        ))}
                                    </div>
                                </section>
                            )}

                            {/* Description */}
                            {item.description && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Popis</h3>
                                    <p className="text-xs text-white leading-relaxed">{item.description}</p>
                                </section>
                            )}

                            {/* Albums — all memberships */}
                            {(item.albums && item.albums.length > 0 || breadcrumb.length > 0) && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <FolderOpen size={10}/> Alba
                                    </h3>
                                    <div className="flex flex-wrap gap-1">
                                        {/* All albums from eager-loaded relation */}
                                        {item.albums && item.albums.length > 0 ? (
                                            item.albums.map(album => (
                                                <Link key={album.uuid} href={`/albums/${album.uuid}`}
                                                    className="text-[10px] bg-[var(--color-bg-secondary)] border border-[var(--color-border)] text-[var(--color-accent)] px-2 py-0.5 rounded-full hover:border-[var(--color-accent)] transition-colors">
                                                    📁 {album.title}
                                                </Link>
                                            ))
                                        ) : breadcrumb.length > 0 ? (
                                            <Link href={`/albums/${breadcrumb[breadcrumb.length - 1]?.uuid}`}
                                                className="text-[10px] bg-[var(--color-bg-secondary)] border border-[var(--color-border)] text-[var(--color-accent)] px-2 py-0.5 rounded-full hover:border-[var(--color-accent)] transition-colors">
                                                📁 {breadcrumb.map(b => b.title).join(' / ')}
                                            </Link>
                                        ) : null}
                                    </div>
                                </section>
                            )}

                            {item.experience_links && (item.experience_links.events.length > 0 || item.experience_links.trips.length > 0 || item.experience_links.memories.length > 0) && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1"><Heart size={10}/> Souvislosti</h3>
                                    <p className="mb-2 text-[10px] text-[var(--color-text-secondary)]">Toto médium je propojené s vašimi společnými zážitky.</p>
                                    <div className="flex flex-wrap gap-1">
                                        {item.experience_links.events.map(event => <Link key={`event-${event.uuid}`} href={`/calendar/events/${event.uuid}`} className="text-[10px] bg-pink-500/10 border border-pink-400/25 text-pink-100 px-2 py-1 rounded-full hover:bg-pink-500/20">📅 {event.title}</Link>)}
                                        {item.experience_links.trips.map(trip => <Link key={`trip-${trip.id}`} href={`/trips/${trip.id}/plan`} className="text-[10px] bg-teal-500/10 border border-teal-400/25 text-teal-100 px-2 py-1 rounded-full hover:bg-teal-500/20">🧭 {trip.name}</Link>)}
                                        {item.experience_links.memories.map(memory => <Link key={`memory-${memory.uuid}`} href="/shared-memories" className="text-[10px] bg-orange-500/10 border border-orange-400/25 text-orange-100 px-2 py-1 rounded-full hover:bg-orange-500/20">♥ {memory.title}</Link>)}
                                    </div>
                                </section>
                            )}

                            <AddToEventPanel uuid={item.uuid} mediaId={item.id} />

                            <MilestonePanel mediaId={item.id} gallerySpaceId={item.gallery_space_id} takenAt={item.taken_at} />

                            <RevisitFromMediaPanel uuid={item.uuid} title={item.display_title || item.original_filename} places={item.places ?? []} />

                            {/* Places — linked places */}
                            {item.places && item.places.length > 0 && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <MapPin size={10}/> Místa
                                    </h3>
                                    <div className="flex flex-wrap gap-1">
                                        {item.places.map(place => (
                                            <Link key={place.id} href={`/places/${place.id}`}
                                                className="text-[10px] bg-[var(--color-bg-secondary)] border border-[var(--color-border)] text-white px-2 py-0.5 rounded-full hover:border-[var(--color-accent)] transition-colors">
                                                📍 {place.name}{place.city ? ` · ${place.city}` : ''}
                                            </Link>
                                        ))}
                                    </div>
                                </section>
                            )}

                            <CurationPanel uuid={item.uuid} />

                            {/* Reakce (bod 19) */}
                            <ReactionPanel uuid={item.uuid} />

                            {/* Per-user star ratings (bod 18) */}
                            {memberRatings.length > 0 && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Hodnocení</h3>
                                    <div className="space-y-2">
                                        {memberRatings.map(m => (
                                            <div key={m.user_id} className="flex items-center gap-2">
                                                <span className={`text-[10px] w-16 truncate ${m.is_me ? 'text-white font-medium' : 'text-[var(--color-text-secondary)]'}`}>{m.name}</span>
                                                <div className="flex gap-0.5">
                                                    {[1,2,3,4,5].map(n => (
                                                        <span key={n}>
                                                            <Star size={11} className={n <= (m.is_me ? rating : m.rating) ? 'text-yellow-400 fill-yellow-400' : 'text-[var(--color-border)]'}/>
                                                        </span>
                                                    ))}
                                                </div>
                                                {m.rating === 0 && m.is_me && <span className="text-[9px] text-[var(--color-text-secondary)]">bez hodnocení</span>}
                                                {m.rating === 0 && !m.is_me && <span className="text-[9px] text-[var(--color-text-secondary)]">—</span>}
                                            </div>
                                        ))}
                                    </div>
                                </section>
                            )}

                            {/* Komentáře (bod 20) */}
                            <section>
                                <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Komentáře</h3>
                                <div className="space-y-2 mb-3 max-h-40 overflow-y-auto">
                                    {comments.map(c => (
                                        <div key={c.id} className={`p-2 rounded-lg text-xs ${c.is_private ? 'bg-yellow-500/10 border border-yellow-500/20' : 'bg-[var(--color-bg-secondary)]'}`}>
                                            <div className="flex items-center justify-between mb-1">
                                                <span className="font-medium text-white">{c.user_name}</span>
                                                {c.is_private && <span className="text-[9px] text-yellow-400">soukromé</span>}
                                            </div>
                                            <p className="text-[var(--color-text-secondary)] leading-relaxed">{c.body}</p>
                                        </div>
                                    ))}
                                    {comments.length === 0 && <p className="text-xs text-[var(--color-text-secondary)]">Žádné komentáře</p>}
                                </div>
                                <form onSubmit={async e => {
                                    e.preventDefault();
                                    if (!commentText.trim()) return;
                                    const r = await axios.post(`/api/v1/media/${item.uuid}/comments`, { body: commentText, is_private: commentPrivate });
                                    setComments(prev => [...prev, r.data]);
                                    setCommentText('');
                                }} className="space-y-2">
                                    <textarea value={commentText} onChange={e => setCommentText(e.target.value)}
                                        placeholder="Přidat komentář…" rows={2}
                                        className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none" />
                                    <div className="flex items-center justify-between">
                                        <label className="flex items-center gap-1.5 text-xs text-[var(--color-text-secondary)] cursor-pointer">
                                            <input type="checkbox" checked={commentPrivate} onChange={e => setCommentPrivate(e.target.checked)} className="w-3 h-3" />
                                            Soukromá poznámka
                                        </label>
                                        <button type="submit" disabled={!commentText.trim()}
                                            className="text-xs bg-[var(--color-accent)] text-white px-2.5 py-1 rounded-lg disabled:opacity-40 hover:opacity-90">
                                            Přidat
                                        </button>
                                    </div>
                                </form>
                            </section>

                            {/* Keyboard shortcuts hint */}
                            <section className="pt-2 border-t border-[var(--color-border)]">
                                <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Zkratky</h3>
                                <div className="space-y-1 text-[10px] text-[var(--color-text-secondary)]">
                                    {[['F','Oblíbené'],['I','Info panel'],['D','Stáhnout'],['Del','Koš'],['← →','Navigace']].map(([k,v]) => (
                                        <div key={k} className="flex justify-between">
                                            <kbd className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded px-1.5 py-0.5 font-mono">{k}</kbd>
                                            <span>{v}</span>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
