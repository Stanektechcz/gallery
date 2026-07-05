import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Heart, Layers, Link2, Link2Off, Plus, RotateCcw, Star, X, ZoomIn, ZoomOut } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface CompareItem {
    id: number; uuid: string; media_type: string;
    filename: string; display_title?: string; taken_at?: string;
    width?: number; height?: number;
    is_favorite: boolean; is_my_favorite: boolean;
    rating?: number; my_rating?: number;
    camera_make?: string; camera_model?: string;
    aperture?: string; shutter_speed?: string; iso?: number; focal_length?: string;
    full_url: string; thumb_url?: string; large_url?: string;
}

interface ZoomState { scale: number; x: number; y: number }

const INITIAL_ZOOM: ZoomState = { scale: 1, x: 0, y: 0 };

function fmtDate(d?: string) {
    if (!d) return null;
    return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

// ── Single panel ─────────────────────────────────────────────────────────
function ComparePanel({
    item, zoom, onZoomChange, syncEnabled,
    onRemove, onFavorite,
    index,
}: {
    item: CompareItem;
    zoom: ZoomState;
    onZoomChange: (z: ZoomState) => void;
    syncEnabled: boolean;
    onRemove: () => void;
    onFavorite: (uuid: string) => void;
    index: number;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [localZoom, setLocalZoom] = useState<ZoomState>(INITIAL_ZOOM);
    const [imgLoaded, setImgLoaded] = useState(false);
    const [dragging, setDragging] = useState(false);
    const dragStart = useRef<{ x: number; y: number; ox: number; oy: number } | null>(null);
    const pinchRef  = useRef<number | null>(null);
    const scaleRef  = useRef(1);

    const effectiveZoom = syncEnabled ? zoom : localZoom;
    const setEffective  = useCallback((z: ZoomState) => {
        scaleRef.current = z.scale;
        if (syncEnabled) onZoomChange(z);
        else setLocalZoom(z);
    }, [syncEnabled, onZoomChange]);

    const clamp = useCallback((s: number, ox: number, oy: number) => {
        if (s <= 1) return { x: 0, y: 0 };
        const el = containerRef.current;
        if (!el) return { x: ox, y: oy };
        const mx = el.clientWidth  * (s - 1) / 2;
        const my = el.clientHeight * (s - 1) / 2;
        return { x: Math.max(-mx, Math.min(mx, ox)), y: Math.max(-my, Math.min(my, oy)) };
    }, []);

    const zoomBy = useCallback((delta: number) => {
        setEffective({
            scale: Math.max(1, Math.min(8, effectiveZoom.scale + delta)),
            x: effectiveZoom.x, y: effectiveZoom.y,
        });
    }, [effectiveZoom, setEffective]);

    const reset = useCallback(() => { setEffective(INITIAL_ZOOM); }, [setEffective]);

    // Mouse wheel
    const onWheel = (e: React.WheelEvent) => {
        e.preventDefault();
        zoomBy(e.deltaY < 0 ? 0.25 : -0.25);
    };

    // Mouse drag
    const onMouseDown = (e: React.MouseEvent) => {
        if (effectiveZoom.scale <= 1) return;
        setDragging(true);
        dragStart.current = { x: e.clientX, y: e.clientY, ox: effectiveZoom.x, oy: effectiveZoom.y };
    };
    const onMouseMove = (e: React.MouseEvent) => {
        if (!dragging || !dragStart.current) return;
        const c = clamp(effectiveZoom.scale, dragStart.current.ox + e.clientX - dragStart.current.x, dragStart.current.oy + e.clientY - dragStart.current.y);
        setEffective({ ...effectiveZoom, ...c });
    };
    const onMouseUp = () => { setDragging(false); dragStart.current = null; };
    const onDblClick = () => effectiveZoom.scale > 1 ? reset() : zoomBy(2);

    // Non-passive touch for pinch
    useEffect(() => {
        const el = containerRef.current;
        if (!el) return;
        const handler = (e: TouchEvent) => { if (e.touches.length >= 2) e.preventDefault(); };
        el.addEventListener('touchmove', handler, { passive: false });
        return () => el.removeEventListener('touchmove', handler);
    }, []);

    const onTouchStart = (e: React.TouchEvent) => {
        if (e.touches.length === 2) {
            const dx = e.touches[0].clientX - e.touches[1].clientX;
            const dy = e.touches[0].clientY - e.touches[1].clientY;
            pinchRef.current = Math.sqrt(dx*dx + dy*dy);
        } else if (e.touches.length === 1 && scaleRef.current > 1) {
            dragStart.current = { x: e.touches[0].clientX, y: e.touches[0].clientY, ox: effectiveZoom.x, oy: effectiveZoom.y };
        }
    };
    const onTouchMove = (e: React.TouchEvent) => {
        if (e.touches.length === 2 && pinchRef.current !== null) {
            const dx = e.touches[0].clientX - e.touches[1].clientX;
            const dy = e.touches[0].clientY - e.touches[1].clientY;
            const dist = Math.sqrt(dx*dx + dy*dy);
            zoomBy((dist - pinchRef.current) / 100);
            pinchRef.current = dist;
        } else if (e.touches.length === 1 && scaleRef.current > 1 && dragStart.current) {
            const c = clamp(scaleRef.current, dragStart.current.ox + e.touches[0].clientX - dragStart.current.x, dragStart.current.oy + e.touches[0].clientY - dragStart.current.y);
            setEffective({ scale: scaleRef.current, ...c });
        }
    };
    const onTouchEnd = () => { pinchRef.current = null; dragStart.current = null; };

    const imgStyle: React.CSSProperties = {
        transform: `scale(${effectiveZoom.scale}) translate(${effectiveZoom.x / effectiveZoom.scale}px, ${effectiveZoom.y / effectiveZoom.scale}px)`,
        transformOrigin: 'center',
        transition: dragging ? 'none' : 'transform 0.1s ease',
        cursor: effectiveZoom.scale > 1 ? (dragging ? 'grabbing' : 'grab') : 'zoom-in',
        maxHeight: '100%', maxWidth: '100%', objectFit: 'contain',
    };

    const label = ['A','B','C','D'][index];
    const ratingVal = item.my_rating ?? item.rating ?? 0;

    return (
        <div className="flex flex-col min-h-0 border border-[var(--color-border)] rounded-xl overflow-hidden bg-black relative group">
            {/* Photo area */}
            <div
                ref={containerRef}
                className="flex-1 flex items-center justify-center relative overflow-hidden select-none min-h-0"
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
                {/* Label badge */}
                <div className="absolute top-2 left-2 z-10 w-6 h-6 rounded-full bg-[var(--color-accent)] text-white text-xs font-bold flex items-center justify-center shadow">
                    {label}
                </div>

                {/* Remove */}
                <button onClick={onRemove}
                    className="absolute top-2 right-2 z-10 p-1 rounded-full bg-black/60 text-white hover:bg-red-500/80 transition-colors opacity-0 group-hover:opacity-100">
                    <X size={12}/>
                </button>

                {/* Zoom controls */}
                {effectiveZoom.scale > 1 && (
                    <div className="absolute bottom-2 left-1/2 -translate-x-1/2 z-10 flex items-center gap-1 bg-black/70 backdrop-blur rounded-full px-2 py-0.5">
                        <button onClick={() => zoomBy(-0.5)} className="p-1 text-white/70 hover:text-white"><ZoomOut size={11}/></button>
                        <span className="text-white/60 text-[10px] w-8 text-center">{Math.round(effectiveZoom.scale * 100)}%</span>
                        <button onClick={() => zoomBy(0.5)} className="p-1 text-white/70 hover:text-white"><ZoomIn size={11}/></button>
                        <button onClick={reset} className="p-1 text-white/70 hover:text-white"><RotateCcw size={10}/></button>
                    </div>
                )}

                {/* Photo placeholder */}
                {!imgLoaded && item.thumb_url && (
                    <img src={item.thumb_url} alt="" aria-hidden
                        className="absolute inset-0 w-full h-full object-contain blur-sm opacity-50" draggable={false}/>
                )}

                {/* Full photo */}
                <img
                    src={item.large_url ?? item.full_url}
                    alt={item.display_title ?? item.filename}
                    onLoad={() => setImgLoaded(true)}
                    style={imgStyle}
                    draggable={false}
                    className={imgLoaded ? 'opacity-100' : 'opacity-0'}
                />
            </div>

            {/* Meta bar */}
            <div className="shrink-0 px-3 py-2 bg-[var(--color-bg-secondary)] border-t border-[var(--color-border)]">
                <div className="flex items-start justify-between gap-2">
                    <div className="flex-1 min-w-0">
                        <Link href={`/media/${item.uuid}`}
                            className="text-xs font-medium text-white truncate block hover:text-[var(--color-accent)] transition-colors">
                            {item.display_title ?? item.filename}
                        </Link>
                        <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                            {item.taken_at && (
                                <span className="text-[10px] text-[var(--color-text-secondary)]">{fmtDate(item.taken_at)}</span>
                            )}
                            {item.aperture && <span className="text-[10px] text-[var(--color-text-secondary)]">{item.aperture}</span>}
                            {item.shutter_speed && <span className="text-[10px] text-[var(--color-text-secondary)]">{item.shutter_speed}</span>}
                            {item.iso && <span className="text-[10px] text-[var(--color-text-secondary)]">ISO {item.iso}</span>}
                            {item.focal_length && <span className="text-[10px] text-[var(--color-text-secondary)]">{item.focal_length}</span>}
                        </div>
                    </div>
                    <div className="flex items-center gap-1.5 shrink-0">
                        {/* Stars */}
                        <div className="flex gap-0">
                            {[1,2,3,4,5].map(n => (
                                <Star key={n} size={11} className={n <= ratingVal ? 'text-yellow-400 fill-yellow-400' : 'text-[var(--color-border)]'}/>
                            ))}
                        </div>
                        {/* Favorite */}
                        <button onClick={() => onFavorite(item.uuid)} className="p-0.5">
                            <Heart size={13} className={item.is_my_favorite ? 'text-red-400 fill-red-400' : 'text-[var(--color-text-secondary)]'}/>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── Main compare page ────────────────────────────────────────────────────
export default function CompareIndex() {
    const [items,      setItems]      = useState<CompareItem[]>([]);
    const [loading,    setLoading]    = useState(false);
    const [syncEnabled, setSyncEnabled] = useState(true);
    const [sharedZoom, setSharedZoom]  = useState<ZoomState>(INITIAL_ZOOM);
    const [localZooms, setLocalZooms]  = useState<ZoomState[]>([INITIAL_ZOOM, INITIAL_ZOOM, INITIAL_ZOOM, INITIAL_ZOOM]);

    // Read UUIDs from URL
    const urlUuids = new URLSearchParams(window.location.search).get('uuids') ?? '';

    useEffect(() => {
        const uuids = urlUuids.trim();
        if (!uuids) return;
        setLoading(true);
        axios.get('/api/v1/media/compare', { params: { uuids } })
            .then(r => setItems(r.data ?? []))
            .finally(() => setLoading(false));
    }, [urlUuids]);

    const removeItem = (uuid: string) => {
        const next = items.filter(i => i.uuid !== uuid);
        setItems(next);
        const newUuids = next.map(i => i.uuid).join(',');
        router.replace(`/compare?uuids=${newUuids}`, { preserveScroll: true });
    };

    const toggleFavorite = async (uuid: string) => {
        const res = await axios.post(`/api/v1/favorites/${uuid}/toggle`);
        setItems(prev => prev.map(i => i.uuid === uuid
            ? { ...i, is_my_favorite: res.data.is_my_favorite, is_favorite: res.data.is_favorite }
            : i));
    };

    const handlePanelZoom = useCallback((z: ZoomState) => {
        setSharedZoom(z);
    }, []);

    const resetAll = () => {
        setSharedZoom(INITIAL_ZOOM);
        setLocalZooms([INITIAL_ZOOM, INITIAL_ZOOM, INITIAL_ZOOM, INITIAL_ZOOM]);
    };

    // Grid cols based on count
    const count = items.length;
    const gridCols = count <= 1 ? 'grid-cols-1' : count === 2 ? 'grid-cols-2' : count === 3 ? 'grid-cols-3' : 'grid-cols-2';

    return (
        <AppLayout>
            <Head title="Porovnání fotografií" />
            <div className="flex flex-col h-full min-h-0">

                {/* Top bar */}
                <div className="shrink-0 h-11 px-3 border-b border-[var(--color-border)] bg-[var(--color-bg-secondary)] flex items-center gap-3">
                    <Link href="/timeline" className="p-1.5 text-[var(--color-text-secondary)] hover:text-white rounded hover:bg-white/10">
                        <ArrowLeft size={16}/>
                    </Link>

                    <div className="flex items-center gap-2">
                        <Layers size={15} className="text-[var(--color-accent)]"/>
                        <h1 className="text-sm font-semibold text-white">Porovnání</h1>
                        {items.length > 0 && (
                            <span className="text-xs text-[var(--color-text-secondary)]">{items.length} fotografie</span>
                        )}
                    </div>

                    <div className="flex-1"/>

                    {/* Sync zoom toggle */}
                    <button onClick={() => setSyncEnabled(v => !v)}
                        className={`flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg border transition-colors ${syncEnabled ? 'bg-[var(--color-accent)]/20 border-[var(--color-accent)] text-[var(--color-accent)]' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                        {syncEnabled ? <Link2 size={13}/> : <Link2Off size={13}/>}
                        Synchronní zoom
                    </button>

                    {/* Reset zoom */}
                    {(sharedZoom.scale > 1 || localZooms.some(z => z.scale > 1)) && (
                        <button onClick={resetAll}
                            className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white transition-colors">
                            <RotateCcw size={12}/> Reset
                        </button>
                    )}

                    {/* Zoom indicator when synced */}
                    {syncEnabled && sharedZoom.scale > 1 && (
                        <span className="text-xs text-[var(--color-text-secondary)] font-mono">{Math.round(sharedZoom.scale * 100)}%</span>
                    )}
                </div>

                {/* Compare grid */}
                {loading ? (
                    <div className="flex-1 flex items-center justify-center">
                        <div className="text-center text-[var(--color-text-secondary)]">
                            <div className="w-8 h-8 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin mx-auto mb-3"/>
                            <p className="text-sm">Načítám fotografie…</p>
                        </div>
                    </div>
                ) : items.length === 0 ? (
                    <div className="flex-1 flex items-center justify-center text-[var(--color-text-secondary)]">
                        <div className="text-center max-w-xs">
                            <Layers size={48} className="mx-auto mb-4 opacity-20"/>
                            <p className="text-sm font-medium">Žádné fotografie k porovnání</p>
                            <p className="text-xs mt-2 opacity-60">
                                Vyberte 2–4 fotografie v časové ose a klikněte „Porovnat".
                                Nebo přidejte UUID do URL: <code className="text-[var(--color-accent)]">?uuids=uuid1,uuid2</code>
                            </p>
                            <Link href="/timeline"
                                className="mt-4 inline-flex items-center gap-2 bg-[var(--color-accent)] text-white text-sm px-4 py-2 rounded-lg hover:opacity-90">
                                <Plus size={14}/> Vybrat fotografie
                            </Link>
                        </div>
                    </div>
                ) : (
                    <div className={`flex-1 min-h-0 grid ${gridCols} gap-1 p-2`}>
                        {items.map((item, idx) => (
                            <ComparePanel
                                key={item.uuid}
                                item={item}
                                zoom={syncEnabled ? sharedZoom : localZooms[idx]}
                                onZoomChange={syncEnabled ? handlePanelZoom : (z) => {
                                    setLocalZooms(prev => { const n = [...prev]; n[idx] = z; return n; });
                                }}
                                syncEnabled={syncEnabled}
                                onRemove={() => removeItem(item.uuid)}
                                onFavorite={toggleFavorite}
                                index={idx}
                            />
                        ))}
                    </div>
                )}

                {/* Keyboard hints */}
                {items.length > 0 && (
                    <div className="shrink-0 px-4 py-1.5 border-t border-[var(--color-border)] bg-[var(--color-bg-secondary)]/50 flex items-center gap-4">
                        <p className="text-[10px] text-[var(--color-text-secondary)]">
                            Scroll — zoom · Dvojklik — zoom/reset · Drag — posun při přiblížení
                            {syncEnabled ? ' · Zoom synchronní pro všechny panely' : ' · Zoom nezávislý pro každý panel'}
                        </p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
