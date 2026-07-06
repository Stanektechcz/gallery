/**
 * AlbumEvent — Event Mode bar, auto-detect banner, and settings editor.
 * Shows for albums with event_mode = true.
 */

import axios from 'axios';
import {
    Calendar, Camera, Check, ChevronDown, ChevronUp,
    Clock, Loader2, MapPin, RefreshCw, Settings2, Video,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface EventData {
    event_mode:       boolean;
    event_start_at?:  string;
    event_end_at?:    string;
    event_place_name?: string;
    event_latitude?:  number;
    event_longitude?: number;
    event_gps_radius: number;
}

interface DetectionResult {
    count:       number;
    photo_count: number;
    video_count: number;
    samples:     Array<{ uuid: string; thumbnail_url: string }>;
}

function fmtDt(dt?: string) {
    if (!dt) return null;
    const d = new Date(dt);
    return d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' })
        + ' ' + d.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
}
function fmtTime(dt?: string) {
    if (!dt) return null;
    return new Date(dt).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
}
function fmtDateOnly(dt?: string) {
    if (!dt) return null;
    return new Date(dt).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' });
}

// ── Edit panel ─────────────────────────────────────────────────────────────

function EventEditPanel({
    albumUuid, initial, onSave, onClose,
}: {
    albumUuid: string; initial: EventData;
    onSave: (data: EventData) => void; onClose: () => void;
}) {
    const [form, setForm] = useState({
        event_mode:       initial.event_mode,
        event_start_at:   initial.event_start_at ? initial.event_start_at.replace(' ', 'T').substring(0, 16) : '',
        event_end_at:     initial.event_end_at   ? initial.event_end_at.replace(' ', 'T').substring(0, 16)   : '',
        event_place_name: initial.event_place_name ?? '',
        event_latitude:   initial.event_latitude  ? String(initial.event_latitude)  : '',
        event_longitude:  initial.event_longitude ? String(initial.event_longitude) : '',
        event_gps_radius: String(initial.event_gps_radius ?? 500),
    });
    const [saving, setSaving] = useState(false);

    const inp = (field: string) => ({
        value: (form as any)[field],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
            setForm(p => ({ ...p, [field]: e.target.value })),
    });

    const cls = 'w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]';

    const save = async () => {
        setSaving(true);
        try {
            const payload: any = {
                event_mode:       form.event_mode,
                event_start_at:   form.event_start_at || null,
                event_end_at:     form.event_end_at   || null,
                event_place_name: form.event_place_name || null,
                event_latitude:   form.event_latitude  ? parseFloat(form.event_latitude)  : null,
                event_longitude:  form.event_longitude ? parseFloat(form.event_longitude) : null,
                event_gps_radius: form.event_gps_radius ? parseInt(form.event_gps_radius) : 500,
            };
            const r = await axios.patch(`/api/v1/albums/${albumUuid}/event`, payload);
            onSave(r.data);
        } finally { setSaving(false); }
    };

    return (
        <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 space-y-3">
            <h3 className="text-sm font-semibold text-white flex items-center gap-2">
                <Calendar size={14} className="text-[var(--color-accent)]"/> Nastavení události
            </h3>

            {/* Enable/disable */}
            <label className="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" checked={form.event_mode}
                    onChange={e => setForm(p => ({ ...p, event_mode: e.target.checked }))}
                    className="w-4 h-4 rounded accent-[var(--color-accent)]"/>
                <span className="text-sm text-white">Událost aktivní</span>
            </label>

            {form.event_mode && (
                <>
                    {/* Time range */}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="text-[10px] text-[var(--color-text-secondary)] block mb-1">Začátek</label>
                            <input type="datetime-local" {...inp('event_start_at')} className={cls}/>
                        </div>
                        <div>
                            <label className="text-[10px] text-[var(--color-text-secondary)] block mb-1">Konec</label>
                            <input type="datetime-local" {...inp('event_end_at')} className={cls}/>
                        </div>
                    </div>

                    {/* Location */}
                    <input {...inp('event_place_name')} placeholder="Název místa (např. Národní muzeum)"
                        className={cls}/>

                    {/* GPS */}
                    <div className="grid grid-cols-3 gap-2">
                        <input {...inp('event_latitude')} placeholder="Šířka" type="number" step="any" className={cls}/>
                        <input {...inp('event_longitude')} placeholder="Délka" type="number" step="any" className={cls}/>
                        <div>
                            <input {...inp('event_gps_radius')} placeholder="Poloměr (m)" type="number" min="50" max="50000" className={cls}/>
                        </div>
                    </div>
                    <p className="text-[10px] text-[var(--color-text-secondary)]">
                        GPS souřadnice a poloměr jsou volitelné. Pokud je nezadáte, sbírají se fotky pouze dle času.
                    </p>
                </>
            )}

            <div className="flex gap-2 pt-1">
                <button onClick={save} disabled={saving}
                    className="flex-1 bg-[var(--color-accent)] text-white text-sm py-2 rounded-lg hover:opacity-90 disabled:opacity-40 flex items-center justify-center gap-1.5">
                    {saving ? <Loader2 size={13} className="animate-spin"/> : <Check size={13}/>}
                    Uložit
                </button>
                <button onClick={onClose}
                    className="px-4 text-sm border border-[var(--color-border)] text-[var(--color-text-secondary)] rounded-lg hover:text-white">
                    Zrušit
                </button>
            </div>
        </div>
    );
}

// ── Main component ─────────────────────────────────────────────────────────

export default function AlbumEvent({ albumUuid }: { albumUuid: string }) {
    const [eventData,   setEventData]   = useState<EventData | null>(null);
    const [detected,    setDetected]    = useState<DetectionResult | null>(null);
    const [loading,     setLoading]     = useState(true);
    const [detecting,   setDetecting]   = useState(false);
    const [collecting,  setCollecting]  = useState(false);
    const [collected,   setCollected]   = useState(false);
    const [showEdit,    setShowEdit]    = useState(false);
    const [showDetails, setShowDetails] = useState(false);

    // Load event data
    useEffect(() => {
        axios.get(`/api/v1/albums/${albumUuid}/event`)
            .then(r => setEventData(r.data))
            .finally(() => setLoading(false));
    }, [albumUuid]);

    // Detect media when event is configured
    const detect = useCallback(async () => {
        if (!eventData?.event_mode || !eventData.event_start_at || !eventData.event_end_at) return;
        setDetecting(true);
        try {
            const r = await axios.get(`/api/v1/albums/${albumUuid}/event-media`);
            setDetected(r.data);
        } finally { setDetecting(false); }
    }, [albumUuid, eventData]);

    useEffect(() => {
        if (eventData?.event_mode && !collected) {
            detect();
        }
    }, [eventData, collected]);

    const collectAll = async () => {
        setCollecting(true);
        try {
            const r = await axios.post(`/api/v1/albums/${albumUuid}/event-collect`);
            setCollected(true);
            setDetected(null);
            // Refresh the page to show new media
            if (r.data.added > 0) {
                setTimeout(() => window.location.reload(), 500);
            }
        } finally { setCollecting(false); }
    };

    const handleSave = (data: EventData) => {
        setEventData(data);
        setShowEdit(false);
        setCollected(false);
        setDetected(null);
    };

    if (loading) return null;

    // If no event mode and no edit open, show just a button to activate
    if (!eventData?.event_mode && !showEdit) {
        return (
            <div className="flex items-center gap-2 mb-4">
                <button onClick={() => setShowEdit(true)}
                    className="flex items-center gap-1.5 text-xs text-[var(--color-text-secondary)] border border-dashed border-[var(--color-border)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] px-3 py-1.5 rounded-lg transition-colors">
                    <Calendar size={12}/> Nastavit jako událost
                </button>
            </div>
        );
    }

    return (
        <div className="mb-5 space-y-3">
            {/* Event info bar */}
            {eventData?.event_mode && !showEdit && (
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-accent)]/30 rounded-xl overflow-hidden">
                    {/* Header */}
                    <div className="flex items-center gap-3 px-4 py-3">
                        <div className="w-9 h-9 rounded-lg bg-[var(--color-accent)]/15 flex items-center justify-center shrink-0">
                            <Calendar size={18} className="text-[var(--color-accent)]"/>
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-[var(--color-accent)] uppercase tracking-wider">Událost</p>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-0.5">
                                {eventData.event_start_at && (
                                    <span className="text-xs text-[var(--color-text-secondary)] flex items-center gap-1">
                                        <Clock size={10}/>
                                        {fmtDateOnly(eventData.event_start_at)}&nbsp;
                                        {fmtTime(eventData.event_start_at)}
                                        {eventData.event_end_at && ` – ${fmtTime(eventData.event_end_at)}`}
                                    </span>
                                )}
                                {eventData.event_place_name && (
                                    <span className="text-xs text-[var(--color-text-secondary)] flex items-center gap-1">
                                        <MapPin size={10}/> {eventData.event_place_name}
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="flex gap-1 shrink-0">
                            <button onClick={() => setShowDetails(v => !v)}
                                className="p-1.5 text-[var(--color-text-secondary)] hover:text-white transition-colors"
                                title="Podrobnosti">
                                {showDetails ? <ChevronUp size={14}/> : <ChevronDown size={14}/>}
                            </button>
                            <button onClick={() => setShowEdit(true)}
                                className="p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-accent)] transition-colors"
                                title="Upravit nastavení">
                                <Settings2 size={14}/>
                            </button>
                            <button onClick={detect} disabled={detecting} title="Znovu detekovat média"
                                className="p-1.5 text-[var(--color-text-secondary)] hover:text-white transition-colors disabled:opacity-40">
                                <RefreshCw size={13} className={detecting ? 'animate-spin' : ''}/>
                            </button>
                        </div>
                    </div>

                    {/* Details (expandable) */}
                    {showDetails && (
                        <div className="px-4 pb-3 border-t border-[var(--color-border)] pt-3 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <p className="text-[var(--color-text-secondary)]">Začátek</p>
                                <p className="text-white">{fmtDt(eventData.event_start_at) ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-[var(--color-text-secondary)]">Konec</p>
                                <p className="text-white">{fmtDt(eventData.event_end_at) ?? '—'}</p>
                            </div>
                            {eventData.event_latitude && (
                                <div className="col-span-2">
                                    <p className="text-[var(--color-text-secondary)]">GPS</p>
                                    <p className="text-white font-mono">{eventData.event_latitude?.toFixed(5)}°, {eventData.event_longitude?.toFixed(5)}° · poloměr {eventData.event_gps_radius} m</p>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* Edit panel */}
            {showEdit && eventData && (
                <EventEditPanel
                    albumUuid={albumUuid} initial={eventData}
                    onSave={handleSave} onClose={() => setShowEdit(false)}
                />
            )}

            {/* Detection banner */}
            {eventData?.event_mode && detected && detected.count > 0 && !collected && (
                <div className="bg-[var(--color-accent)]/10 border border-[var(--color-accent)]/30 rounded-xl p-4">
                    <div className="flex items-start justify-between gap-4 flex-wrap">
                        <div className="flex items-start gap-3">
                            <div className="shrink-0 mt-0.5">
                                <div className="w-8 h-8 rounded-lg bg-[var(--color-accent)]/20 flex items-center justify-center">
                                    <Camera size={16} className="text-[var(--color-accent)]"/>
                                </div>
                            </div>
                            <div>
                                <p className="text-sm font-semibold text-white">
                                    Nalezeno {detected.count} médií z tohoto období
                                </p>
                                <p className="text-xs text-[var(--color-text-secondary)] mt-0.5 flex items-center gap-2">
                                    {detected.photo_count > 0 && <span className="flex items-center gap-1"><Camera size={10}/> {detected.photo_count} fotografií</span>}
                                    {detected.video_count > 0 && <span className="flex items-center gap-1"><Video size={10}/> {detected.video_count} videí</span>}
                                    {eventData.event_start_at && <span>· {fmtDateOnly(eventData.event_start_at)}</span>}
                                </p>
                                {/* Sample thumbnails */}
                                {detected.samples.length > 0 && (
                                    <div className="flex gap-1 mt-2">
                                        {detected.samples.map(s => (
                                            <img key={s.uuid} src={s.thumbnail_url} alt="" className="w-9 h-9 rounded object-cover"/>
                                        ))}
                                        {detected.count > 6 && (
                                            <div className="w-9 h-9 rounded bg-[var(--color-bg-secondary)] flex items-center justify-center text-[10px] text-[var(--color-text-secondary)]">
                                                +{detected.count - 6}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="flex gap-2 shrink-0">
                            <button onClick={collectAll} disabled={collecting}
                                className="flex items-center gap-1.5 bg-[var(--color-accent)] text-white text-sm px-4 py-2 rounded-lg hover:opacity-90 disabled:opacity-40">
                                {collecting ? <Loader2 size={13} className="animate-spin"/> : <Check size={13}/>}
                                Přidat do alba
                            </button>
                            <button onClick={() => setDetected(null)}
                                className="text-xs text-[var(--color-text-secondary)] hover:text-white border border-[var(--color-border)] px-3 py-2 rounded-lg transition-colors">
                                Přeskočit
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Collected confirmation */}
            {collected && (
                <div className="flex items-center gap-2 text-green-400 text-sm px-4 py-2.5 bg-green-500/10 border border-green-500/20 rounded-xl">
                    <Check size={14}/> Média přidána do alba
                </div>
            )}
        </div>
    );
}
