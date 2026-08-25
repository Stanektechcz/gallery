/**
 * Postranní panely v detailu fotografie.
 *
 * Reakce, zařazení do výběru, přidání k akci, milník vztahu, návrat na místo a mapa.
 * Vytažené z Media/Show.tsx, kde spolu s prohlížečem tvořily polovinu souboru
 * o třinácti stech řádcích.
 *
 * Každý si drží vlastní stav a data si načítá sám podle uuid, které dostane — proto
 * je šlo vytáhnout beze změny chování. To je taky důvod, proč jich detail unese tolik:
 * co se nezobrazí, se nenačítá.
 */

import { addLocalizedBaseLayer } from '@/lib/localizedMap';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

interface Place { id: number; name: string; city?: string; country?: string; latitude?: number; longitude?: number }

const REACTIONS = [
    { emoji: '❤️', key: 'love',   label: 'Miluju' },
    { emoji: '😂', key: 'funny',  label: 'Vtipné' },
    { emoji: '🥹', key: 'memory', label: 'Vzpomínka' },
    { emoji: '🔥', key: 'top',    label: 'Top' },
];

interface ReactionDetail { user_id: number; name: string; initial: string; reaction: string; is_me: boolean; }

export function ReactionPanel({ uuid }: { uuid: string }) {
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
                            className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-full border text-xs transition-all ${mine === r.key ? 'bg-[var(--color-accent)]/20 border-[var(--color-accent)] text-[var(--color-accent-contrast)]' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:border-[var(--color-accent)]/50 hover:text-[var(--color-text-primary)]'}`}>
                            <span className="text-sm">{r.emoji}</span>
                            {who.length > 0 && (
                                <span className="flex items-center gap-0.5">
                                    {who.map(d => (
                                        <span key={d.user_id}
                                            className={`inline-flex w-4 h-4 rounded-full items-center justify-center text-[9px] font-bold ${d.is_me ? 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]' : 'bg-white/20 text-[var(--color-text-primary)]'}`}
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

export function CurationPanel({ uuid }: { uuid: string }) {
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
        {boards.length ? <div className="flex gap-2"><select value={selected} onChange={event => setSelected(event.target.value)} className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"><option value="">Vyberte kolekci</option>{boards.map(board => <option key={board.uuid} value={board.uuid}>{board.title}</option>)}</select><button onClick={add} className="min-h-9 rounded-lg bg-[var(--color-accent)] px-3 text-xs text-[var(--color-accent-contrast)]">Přidat</button></div> : <Link href="/curation" className="text-xs text-[var(--color-accent)] hover:underline">Vytvořit první společný výběr</Link>}
        {message && <p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">{message}</p>}
    </section>;
}

export function AddToEventPanel({ uuid, mediaId }: { uuid: string; mediaId: number }) {
    const [events, setEvents] = useState<Array<{uuid:string;title:string;starts_at:string;place_name?:string|null;already_linked:boolean}>>([]);
    const [loaded, setLoaded] = useState(false); const [loading, setLoading] = useState(false); const [message, setMessage] = useState('');
    const load = async () => { setLoading(true); setMessage(''); try { const response = await axios.get(`/api/v1/media/${uuid}/event-suggestions`); setEvents(response.data.events ?? []); setLoaded(true); } catch { setMessage('Vhodné akce se nepodařilo načíst.'); } finally { setLoading(false); } };
    const attach = async (event:{uuid:string;title:string}) => { setLoading(true); setMessage(''); try { await axios.post(`/api/v1/calendar/events/${event.uuid}/media-suggestions`, {media_ids:[mediaId]}); setMessage(`Připojeno k akci „${event.title}“.`); setEvents(current => current.map(item => item.uuid === event.uuid ? {...item,already_linked:true} : item)); router.reload({only:['media']}); } catch (error:any) { setMessage(error?.response?.data?.message ?? 'Médium se nepodařilo připojit k akci.'); } finally { setLoading(false); } };
    return <section><h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">Přidat ke společné akci</h3>{!loaded ? <button onClick={load} disabled={loading} className="min-h-9 rounded-lg border border-pink-400/30 px-3 text-xs text-pink-100 disabled:opacity-40">{loading ? 'Hledám…' : 'Najít akci podle data'}</button> : <div className="space-y-1.5">{events.map(event => <div key={event.uuid} className="flex items-center justify-between gap-2 rounded-lg border border-[var(--color-border)] p-2"><div className="min-w-0"><p className="truncate text-xs text-[var(--color-text-primary)]">{event.title}</p><p className="text-[10px] text-[var(--color-text-secondary)]">{new Date(event.starts_at).toLocaleDateString('cs-CZ')}{event.place_name ? ` · ${event.place_name}` : ''}</p></div><button disabled={loading || event.already_linked} onClick={() => attach(event)} className={`shrink-0 rounded px-2 py-1 text-[10px] ${event.already_linked ? 'bg-emerald-500/10 text-emerald-200' : 'border border-pink-400/30 text-pink-100'}`}>{event.already_linked ? 'Připojeno ✓' : 'Přidat'}</button></div>)}{!events.length && <p className="text-xs text-[var(--color-text-secondary)]">V okolí data média není žádná akce, kterou můžete upravit.</p>}</div>}{message && <p className="mt-2 text-[10px] text-[var(--color-text-secondary)]">{message}</p>}</section>;
}

export function MilestonePanel({ mediaId, gallerySpaceId, takenAt }: { mediaId: number; gallerySpaceId: number; takenAt?: string }) {
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
        {available.length > 0 && <div className="flex gap-2"><select value={selected} onChange={event => setSelected(event.target.value)} className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"><option value="">Vybrat existující milník</option>{available.map(item => <option key={item.uuid} value={item.uuid}>{item.title}</option>)}</select><button onClick={attach} disabled={loading || !selected} className="min-h-9 rounded-lg border border-pink-400/30 px-3 text-xs text-pink-100 disabled:opacity-40">Připojit</button></div>}
        <div className="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]"><input value={title} onChange={event => setTitle(event.target.value)} placeholder="Nový společný milník" className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"/><input type="date" value={occurredOn} onChange={event => setOccurredOn(event.target.value)} className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"/></div>
        <button onClick={create} disabled={loading || !title.trim()} className="mt-2 min-h-9 rounded-lg bg-[var(--color-accent)] px-3 text-xs text-[var(--color-accent-contrast)] disabled:opacity-40">Vytvořit z této vzpomínky</button>
        {message && <p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">{message}</p>}
    </section>;
}

export function RevisitFromMediaPanel({ uuid, title, places }: { uuid: string; title: string; places: Place[] }) {
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
        {!loaded ? <button onClick={load} className="min-h-9 rounded-lg border border-teal-400/30 px-3 text-xs text-teal-100">Navrhnout společný návrat</button> : <div className="space-y-2"><p className="text-xs text-[var(--color-text-secondary)]">{prompt || 'Vytvořte nový společný zážitek z této vzpomínky.'}</p>{candidates.length > 0 && <p className="text-[10px] text-[var(--color-text-secondary)]">Ve stejném okolí už máte {candidates.length} dalších vzpomínek. <Link href={`/media/${candidates[0].uuid}`} className="panel-link text-[var(--color-accent)] hover:underline">Otevřít poslední →</Link></p>}<input value={form.title} onChange={event => setForm(current => ({...current,title:event.target.value}))} maxLength={160} aria-label="Název společné akce" className="min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"/><div className="grid gap-2 sm:grid-cols-2"><input value={form.place_name} onChange={event => setForm(current => ({...current,place_name:event.target.value}))} maxLength={255} placeholder="Místo" className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"/><input type="datetime-local" value={form.starts_at} onChange={event => setForm(current => ({...current,starts_at:event.target.value}))} aria-label="Termín návratu" className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"/></div><div className="flex gap-2"><input type="number" min="0" max="525600" value={form.reminder_minutes} onChange={event => setForm(current => ({...current,reminder_minutes:event.target.value}))} aria-label="Minut předem" title="Minut předem" className="min-h-9 w-28 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"/><button disabled={saving} onClick={schedule} className="min-h-9 rounded-lg border border-teal-400/30 px-3 text-xs text-teal-100 disabled:opacity-40">{saving ? 'Plánuji…' : 'Přidat do kalendáře'}</button></div></div>}
        {message && <p className={`mt-2 text-[10px] ${eventUuid ? 'text-emerald-300' : 'text-[var(--color-text-secondary)]'}`}>{message}{eventUuid && <Link href={`/calendar/events/${eventUuid}`} className="ml-1 underline">Otevřít akci</Link>}</p>}
    </section>;
}

// ── GPS mini-map (lazy Leaflet) ─────────────────────────────────────────
export function GpsMap({ lat, lng }: { lat: number; lng: number }) {
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
            <div className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-[var(--color-surface-muted)]">
                <span className="text-[10px] text-white bg-black/50 px-2 py-0.5 rounded">Otevřít mapy ↗</span>
            </div>
        </a>
    );
}
