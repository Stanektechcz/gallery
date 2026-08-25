/**
 * Prohlížeč jedné fotografie: postupné načtení, přiblížení, posun a listování.
 *
 * Vytažené z Media/Show.tsx, kde tvořilo pětinu souboru o třinácti stech řádcích.
 * Se zbytkem detailu ho pojí jedině vlastnosti, které dostane — žádný sdílený stav,
 * takže se dá číst i měnit samostatně.
 */

import { router } from '@inertiajs/react';
import { clsx } from 'clsx';
import { Clock, Maximize2, Minimize2, RotateCcw, ZoomIn, ZoomOut } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export default function ProgressiveImage({ uuid, fullUrl, thumbUrl, alt, width, height, dominantColor, prevUuid, nextUuid }: {
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
                    <button onClick={() => zoom(-0.5)} className="p-1.5 text-[var(--color-text-primary)]/80 hover:text-[var(--color-text-primary)] transition-colors" title="Oddálit (-)">
                        <ZoomOut size={14} />
                    </button>
                    <span className="text-[var(--color-text-primary)]/70 text-xs w-10 text-center">{Math.round(scale * 100)}%</span>
                    <button onClick={() => zoom(0.5)} className="p-1.5 text-[var(--color-text-primary)]/80 hover:text-[var(--color-text-primary)] transition-colors" title="Přiblížit (+)">
                        <ZoomIn size={14} />
                    </button>
                    {scale > 1 && (
                        <button onClick={reset} className="p-1.5 text-[var(--color-text-primary)]/80 hover:text-[var(--color-text-primary)] transition-colors" title="Původní velikost (0)">
                            <RotateCcw size={14} />
                        </button>
                    )}
                    <div className="w-px h-4 bg-white/20 mx-0.5" />
                    <button onClick={() => setFullscreen(v => !v)} className="p-1.5 text-[var(--color-text-primary)]/80 hover:text-[var(--color-text-primary)] transition-colors" title="Celá obrazovka (F)">
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
