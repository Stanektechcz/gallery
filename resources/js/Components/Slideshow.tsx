/**
 * Slideshow.tsx — Full-featured slideshow component.
 * Features: fullscreen, autoplay, intervals, shuffle, video, captions, GPS map, TV mode.
 * Modes: classic, chronological, travel (GPS transitions), memory (same day past years).
 */

import {
    ChevronLeft, ChevronRight, Map as MapIcon, Maximize2, Minimize2,
    Pause, Play, Settings2, Shuffle, SkipForward, Star, X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

// ── Types ─────────────────────────────────────────────────────────────────

export interface SlideshowItem {
    uuid:          string;
    media_type:    'photo' | 'video';
    full_url?:     string;
    thumb_url?:    string;
    display_title?: string;
    taken_at?:     string;
    latitude?:     number;
    longitude?:    number;
    location?:     string;     // place name if available
    people?:       string[];
    rating?:       number;
    duration_ms?:  number;
    variants?:     Array<{ type: string; url: string }>;
}

type SlideshowMode = 'classic' | 'chronological' | 'travel' | 'memory';

interface SlideshowConfig {
    interval:     number; // seconds
    shuffle:      boolean;
    mode:         SlideshowMode;
    showCaptions: boolean;
    showMap:      boolean;
    tvMode:       boolean;
}

const DEFAULT_CONFIG: SlideshowConfig = {
    interval:     5,
    shuffle:      false,
    mode:         'classic',
    showCaptions: true,
    showMap:      true,
    tvMode:       false,
};

const INTERVALS = [3, 5, 7, 10, 15, 20];

const MODE_LABELS: Record<SlideshowMode, string> = {
    classic:       'Klasická',
    chronological: 'Chronologická',
    travel:        'Cestovní',
    memory:        'Vzpomínka',
};

// ── Helpers ───────────────────────────────────────────────────────────────

function fmtDate(d?: string): string {
    if (!d) return '';
    return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' });
}

function shuffle<T>(arr: T[]): T[] {
    const a = [...arr];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

function gpsDistance(a?: SlideshowItem, b?: SlideshowItem): number {
    if (!a?.latitude || !b?.latitude) return 0;
    return Math.abs(a.latitude - b.latitude) + Math.abs(a.longitude! - b.longitude!);
}

function itemFullUrl(item: SlideshowItem): string {
    if (item.full_url) return item.full_url;
    return `/media/${item.uuid}/full`;
}
function itemThumbUrl(item: SlideshowItem): string {
    if (item.thumb_url) return item.thumb_url;
    const v = item.variants?.find(v => v.type === 'thumbnail') ?? item.variants?.[0];
    return v?.url ?? `/media/${item.uuid}/full`;
}
function itemStreamUrl(item: SlideshowItem): string {
    return `/media/${item.uuid}/stream`;
}

// ── Mini GPS map overlay ───────────────────────────────────────────────────

function GpsTransition({ lat, lng, label, onDone }: {
    lat: number; lng: number; label?: string; onDone: () => void;
}) {
    const mapRef = useRef<HTMLDivElement>(null);
    const [ready, setReady] = useState(!!(window as any).L);

    useEffect(() => {
        if ((window as any).L) { setReady(true); return; }
        const link = document.createElement('link'); link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(link);
        const s = document.createElement('script'); s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        s.onload = () => setReady(true); document.head.appendChild(s);
    }, []);

    useEffect(() => {
        const timer = setTimeout(onDone, 2500);
        return () => clearTimeout(timer);
    }, [onDone]);

    useEffect(() => {
        if (!ready || !mapRef.current) return;
        const L = (window as any).L;
        const map = L.map(mapRef.current, { zoomControl: false, attributionControl: false }).setView([lat, lng], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        L.circleMarker([lat, lng], { radius: 12, fillColor: '#6366f1', color: 'white', weight: 3, fillOpacity: 1 }).addTo(map);
        return () => { try { map.remove(); } catch {} };
    }, [ready, lat, lng]);

    return (
        <div className="fixed inset-0 z-[401] bg-black flex flex-col items-center justify-center animate-fade-in">
            <div className="w-full max-w-lg h-64 rounded-2xl overflow-hidden shadow-2xl">
                {!ready && <div className="w-full h-full bg-gray-800 animate-pulse"/>}
                <div ref={mapRef} className="w-full h-full"/>
            </div>
            {label && <p className="mt-4 text-white text-lg font-medium flex items-center gap-2"><MapIcon size={18}/> {label}</p>}
            <p className="text-white/50 text-xs mt-1">Přejíždíme…</p>
        </div>
    );
}

// ── Settings panel ─────────────────────────────────────────────────────────

function SettingsPanel({ config, onChange }: {
    config: SlideshowConfig; onChange: (c: SlideshowConfig) => void;
}) {
    const set = (patch: Partial<SlideshowConfig>) => onChange({ ...config, ...patch });
    const toggle = (key: keyof SlideshowConfig) => set({ [key]: !config[key] } as any);

    return (
        <div className="absolute bottom-16 right-4 z-[410] w-64 bg-black/90 backdrop-blur-xl rounded-2xl p-4 border border-white/10 text-white shadow-2xl">
            <p className="text-xs font-semibold uppercase tracking-wider text-white/50 mb-3">Nastavení</p>

            {/* Interval */}
            <div className="mb-3">
                <p className="text-xs text-white/60 mb-1.5">Interval</p>
                <div className="flex gap-1 flex-wrap">
                    {INTERVALS.map(s => (
                        <button key={s} onClick={() => set({ interval: s })}
                            className={`px-2 py-1 rounded-lg text-xs transition-colors ${config.interval === s ? 'bg-[var(--color-accent)] text-white' : 'bg-white/10 hover:bg-white/20'}`}>
                            {s}s
                        </button>
                    ))}
                </div>
            </div>

            {/* Mode */}
            <div className="mb-3">
                <p className="text-xs text-white/60 mb-1.5">Režim</p>
                <div className="grid grid-cols-2 gap-1">
                    {(Object.entries(MODE_LABELS) as [SlideshowMode, string][]).map(([key, label]) => (
                        <button key={key} onClick={() => set({ mode: key })}
                            className={`px-2 py-1.5 rounded-lg text-xs transition-colors ${config.mode === key ? 'bg-[var(--color-accent)] text-white' : 'bg-white/10 hover:bg-white/20'}`}>
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Toggles */}
            {[
                { key: 'shuffle',      label: 'Náhodné pořadí' },
                { key: 'showCaptions', label: 'Titulky' },
                { key: 'showMap',      label: 'Mapa při cestování' },
                { key: 'tvMode',       label: 'Televizní režim' },
            ].map(({ key, label }) => (
                <label key={key} className="flex items-center justify-between py-1.5 cursor-pointer">
                    <span className="text-xs text-white/80">{label}</span>
                    <input type="checkbox" checked={(config as any)[key]}
                        onChange={() => toggle(key as keyof SlideshowConfig)}
                        className="w-4 h-4 rounded accent-[var(--color-accent)]"/>
                </label>
            ))}
        </div>
    );
}

// ── Main Slideshow ─────────────────────────────────────────────────────────

interface SlideshowProps {
    items:        SlideshowItem[];
    initialIndex?: number;
    tvMode?:      boolean;
    onClose:      () => void;
}

export default function Slideshow({ items: rawItems, initialIndex = 0, tvMode: initTvMode = false, onClose }: SlideshowProps) {
    const [config,      setConfig]      = useState<SlideshowConfig>({ ...DEFAULT_CONFIG, tvMode: initTvMode });
    const [playing,     setPlaying]     = useState(true);
    const [currentIdx,  setCurrentIdx]  = useState(initialIndex);
    const [showConfig,  setShowConfig]  = useState(false);
    const [showControls, setShowControls] = useState(true);
    const [gpsTransition, setGpsTransition] = useState<{ lat: number; lng: number; label?: string } | null>(null);
    const [videoEnded,  setVideoEnded]  = useState(false);
    const [fullscreen,  setFullscreen]  = useState(true);

    const timerRef    = useRef<ReturnType<typeof setTimeout> | null>(null);
    const videoRef    = useRef<HTMLVideoElement>(null);
    const controlsTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Ordered items based on mode + shuffle
    const orderedItems = (() => {
        let result = [...rawItems];
        if (config.mode === 'chronological' || config.mode === 'travel') {
            result.sort((a, b) => (a.taken_at ?? '').localeCompare(b.taken_at ?? ''));
        } else if (config.mode === 'memory') {
            // Same day, multiple years — sort by year, same date
            const today = new Date();
            result = result.filter(it => {
                if (!it.taken_at) return false;
                const d = new Date(it.taken_at);
                return d.getMonth() === today.getMonth() && d.getDate() === today.getDate();
            }).concat(result.filter(it => {
                if (!it.taken_at) return false;
                const d = new Date(it.taken_at);
                return !(d.getMonth() === today.getMonth() && d.getDate() === today.getDate());
            }));
        }
        if (config.shuffle) result = shuffle(result);
        return result;
    })();

    const item = orderedItems[currentIdx];
    const total = orderedItems.length;

    const goTo = useCallback((idx: number) => {
        const prev = orderedItems[currentIdx];
        const next = orderedItems[idx];
        // Travel mode GPS transition
        if (config.mode === 'travel' && config.showMap && next?.latitude && gpsDistance(prev, next) > 0.05) {
            setGpsTransition({ lat: next.latitude!, lng: next.longitude!, label: next.location });
        } else {
            setCurrentIdx(idx);
            setVideoEnded(false);
        }
    }, [currentIdx, orderedItems, config]);

    const next = useCallback(() => goTo((currentIdx + 1) % total), [goTo, currentIdx, total]);
    const prev = useCallback(() => goTo((currentIdx - 1 + total) % total), [goTo, currentIdx, total]);

    // Autoplay timer
    useEffect(() => {
        if (!playing || item?.media_type === 'video') return;
        timerRef.current = setTimeout(next, config.interval * 1000);
        return () => { if (timerRef.current) clearTimeout(timerRef.current); };
    }, [playing, currentIdx, config.interval, item?.media_type]);

    // Video ended → advance
    useEffect(() => {
        if (videoEnded && playing) { setTimeout(next, 500); }
    }, [videoEnded]);

    // Keyboard shortcuts
    useEffect(() => {
        const h = (e: KeyboardEvent) => {
            if (e.key === 'ArrowRight')  next();
            if (e.key === 'ArrowLeft')   prev();
            if (e.key === 'Escape')      onClose();
            if (e.key === ' ')           setPlaying(v => !v);
            if (e.key === 'f' || e.key === 'F') setFullscreen(v => !v);
            if (e.key === 's' || e.key === 'S') setConfig(c => ({ ...c, shuffle: !c.shuffle }));
        };
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, [next, prev, onClose]);

    // Hide controls after 3s of inactivity
    const resetControlsTimer = useCallback(() => {
        setShowControls(true);
        if (controlsTimer.current) clearTimeout(controlsTimer.current);
        if (!config.tvMode) {
            controlsTimer.current = setTimeout(() => setShowControls(false), 3000);
        }
    }, [config.tvMode]);

    useEffect(() => {
        resetControlsTimer();
        return () => { if (controlsTimer.current) clearTimeout(controlsTimer.current); };
    }, [currentIdx]);

    // GPS transition done
    const onGpsDone = useCallback(() => {
        setGpsTransition(null);
        setCurrentIdx(c => (c + 1) % total);
        setVideoEnded(false);
    }, [total]);

    if (!item) return null;

    const isTv = config.tvMode;
    const displayTitle = item.display_title ?? item.location ?? '';
    const dateStr      = fmtDate(item.taken_at);

    return (
        <div
            className={`fixed inset-0 bg-black flex items-center justify-center select-none ${fullscreen ? 'z-[400]' : 'z-[300]'}`}
            onMouseMove={resetControlsTimer}
            onClick={resetControlsTimer}>

            {/* GPS transition overlay */}
            {gpsTransition && <GpsTransition lat={gpsTransition.lat} lng={gpsTransition.lng} label={gpsTransition.label} onDone={onGpsDone}/>}

            {/* Settings panel */}
            {showConfig && <SettingsPanel config={config} onChange={c => { setConfig(c); setShowConfig(false); }}/>}

            {/* Media */}
            <div className="relative w-full h-full flex items-center justify-center overflow-hidden">
                {item.media_type === 'video' ? (
                    <video
                        ref={videoRef}
                        key={item.uuid}
                        src={itemStreamUrl(item)}
                        poster={itemThumbUrl(item)}
                        autoPlay={playing}
                        className="max-h-full max-w-full object-contain"
                        onEnded={() => setVideoEnded(true)}
                        playsInline
                    />
                ) : (
                    <img
                        key={item.uuid}
                        src={itemFullUrl(item)}
                        alt={displayTitle}
                        className={`max-h-full max-w-full object-contain transition-opacity duration-500 ${isTv ? 'w-full h-full object-cover' : ''}`}
                        loading="eager"
                    />
                )}
            </div>

            {/* Bottom info overlay (always shown in TV mode, on hover in normal) */}
            {config.showCaptions && (displayTitle || dateStr || (item.people?.length ?? 0) > 0) && (
                <div className={`absolute bottom-0 left-0 right-0 transition-opacity duration-300 ${showControls || isTv ? 'opacity-100' : 'opacity-0'}`}>
                    <div className={`px-6 py-4 bg-gradient-to-t from-black/90 via-black/50 to-transparent ${isTv ? 'pb-8' : ''}`}>
                        {displayTitle && (
                            <p className={`text-white font-semibold ${isTv ? 'text-4xl mb-2' : 'text-lg'}`}>{displayTitle}</p>
                        )}
                        {dateStr && (
                            <p className={`text-white/70 ${isTv ? 'text-2xl' : 'text-sm'}`}>{dateStr}</p>
                        )}
                        {item.people && item.people.length > 0 && (
                            <p className={`text-white/50 mt-1 ${isTv ? 'text-xl' : 'text-xs'}`}>{item.people.join(', ')}</p>
                        )}
                        {item.location && !displayTitle && (
                            <p className={`text-white/60 ${isTv ? 'text-xl' : 'text-xs'} mt-0.5`}>📍 {item.location}</p>
                        )}
                    </div>
                </div>
            )}

            {/* Rating stars (bottom-right in TV mode) */}
            {isTv && item.rating && item.rating > 0 && (
                <div className="absolute bottom-8 right-6">
                    <div className="flex gap-1">
                        {[1,2,3,4,5].map(n => (
                            <Star key={n} size={20} className={n <= item.rating! ? 'text-yellow-400 fill-yellow-400' : 'text-white/20'}/>
                        ))}
                    </div>
                </div>
            )}

            {/* Dot progress (TV mode) */}
            {isTv && (
                <div className="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-1.5">
                    {Array.from({ length: Math.min(total, 15) }).map((_, i) => {
                        const idx = Math.floor((i / 15) * total);
                        const active = idx === currentIdx || (i === 14 && currentIdx >= 13);
                        return (
                            <div key={i} className={`rounded-full transition-all ${active ? 'w-2 h-2 bg-white' : 'w-1.5 h-1.5 bg-white/30'}`}/>
                        );
                    })}
                </div>
            )}

            {/* Controls overlay (normal mode) */}
            {!isTv && (
                <div className={`transition-opacity duration-300 ${showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'}`}>
                    {/* Prev / Next arrows */}
                    <button onClick={e => { e.stopPropagation(); prev(); }}
                        className="absolute left-4 top-1/2 -translate-y-1/2 p-3 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors">
                        <ChevronLeft size={24}/>
                    </button>
                    <button onClick={e => { e.stopPropagation(); next(); }}
                        className="absolute right-4 top-1/2 -translate-y-1/2 p-3 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors">
                        <ChevronRight size={24}/>
                    </button>

                    {/* Top bar */}
                    <div className="absolute top-0 left-0 right-0 flex items-center justify-between px-4 py-3 bg-gradient-to-b from-black/70 to-transparent">
                        <div className="flex items-center gap-2 text-white/70 text-sm">
                            <span>{currentIdx + 1} / {total}</span>
                            {config.mode !== 'classic' && (
                                <span className="text-[10px] border border-white/20 px-2 py-0.5 rounded-full">{MODE_LABELS[config.mode]}</span>
                            )}
                        </div>
                        <div className="flex gap-1">
                            <button onClick={e => { e.stopPropagation(); setConfig(c => ({ ...c, shuffle: !c.shuffle })); }}
                                className={`p-2 rounded-full transition-colors ${config.shuffle ? 'text-[var(--color-accent)] bg-[var(--color-accent)]/20' : 'text-white/60 hover:text-white hover:bg-white/10'}`}>
                                <Shuffle size={16}/>
                            </button>
                            <button onClick={e => { e.stopPropagation(); setShowConfig(v => !v); }}
                                className={`p-2 rounded-full transition-colors ${showConfig ? 'text-[var(--color-accent)] bg-[var(--color-accent)]/20' : 'text-white/60 hover:text-white hover:bg-white/10'}`}>
                                <Settings2 size={16}/>
                            </button>
                            <button onClick={e => { e.stopPropagation(); setFullscreen(v => !v); }}
                                className="p-2 rounded-full text-white/60 hover:text-white hover:bg-white/10 transition-colors">
                                {fullscreen ? <Minimize2 size={16}/> : <Maximize2 size={16}/>}
                            </button>
                            <button onClick={e => { e.stopPropagation(); onClose(); }}
                                className="p-2 rounded-full text-white/60 hover:text-white hover:bg-white/10 transition-colors">
                                <X size={16}/>
                            </button>
                        </div>
                    </div>

                    {/* Bottom bar */}
                    <div className="absolute bottom-0 left-0 right-0 px-4 py-3 bg-gradient-to-t from-black/70 to-transparent">
                        {/* Progress bar */}
                        <div className="h-0.5 bg-white/20 rounded-full mb-3 overflow-hidden">
                            <div className="h-full bg-white/70 rounded-full transition-all"
                                style={{ width: `${((currentIdx + 1) / total) * 100}%` }}/>
                        </div>

                        <div className="flex items-center gap-3">
                            <button onClick={e => { e.stopPropagation(); setPlaying(v => !v); }}
                                className="p-2 rounded-full text-white hover:bg-white/10 transition-colors">
                                {playing ? <Pause size={18}/> : <Play size={18}/>}
                            </button>
                            <button onClick={e => { e.stopPropagation(); next(); }}
                                className="p-2 rounded-full text-white/60 hover:text-white hover:bg-white/10 transition-colors">
                                <SkipForward size={15}/>
                            </button>
                            <span className="text-white/50 text-xs">{config.interval}s</span>
                            <div className="flex-1"/>
                            {item.rating && item.rating > 0 && (
                                <div className="flex gap-0.5">
                                    {[1,2,3,4,5].map(n => <Star key={n} size={11} className={n <= item.rating! ? 'text-yellow-400 fill-yellow-400' : 'text-white/20'}/>)}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* TV mode close (top-right, subtle) */}
            {isTv && showControls && (
                <button onClick={onClose}
                    className="absolute top-6 right-6 p-3 rounded-full bg-black/40 text-white/60 hover:text-white hover:bg-black/70 transition-colors">
                    <X size={20}/>
                </button>
            )}
        </div>
    );
}
