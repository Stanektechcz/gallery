import { CSSProperties, useLayoutEffect, useRef, useState } from 'react';

/**
 * Keeps a dropdown panel inside the viewport, on both axes.
 *
 * The controls sit in the sidebar footer, which broke the usual anchoring twice over: a
 * panel pinned to the button's right edge extended off the left of the screen, and one
 * opening downwards from a footer button fell off the bottom.
 *
 * Horizontally the panel is nudged back inside; vertically it flips to the other side of
 * the button, since nudging would cover the button itself. Measured in useLayoutEffect so
 * the correction lands before paint, and again on resize.
 *
 * @param prefer Which side of the button to open on when there is room.
 */
export function useViewportSafePanel(open: boolean, prefer: 'above' | 'below' = 'above') {
    const ref = useRef<HTMLDivElement>(null);
    const [offset, setOffset] = useState(0);
    const [flipped, setFlipped] = useState(false);
    const [maxHeight, setMaxHeight] = useState<number | null>(null);

    useLayoutEffect(() => {
        if (!open) { setOffset(0); setFlipped(false); setMaxHeight(null); return; }

        const measure = () => {
            const element = ref.current;
            if (!element) return;

            const rect = element.getBoundingClientRect();
            const margin = 12;

            let correction = 0;
            if (rect.right > window.innerWidth - margin) correction = window.innerWidth - margin - rect.right;
            if (rect.left + correction < margin) correction = margin - rect.left;
            // The rect already includes the offset applied so far, so add the difference.
            if (correction !== 0) setOffset(current => current + correction);

            const overflowsBelow = rect.bottom > window.innerHeight - margin;
            const overflowsAbove = rect.top < margin;
            if (prefer === 'below' && overflowsBelow && rect.top > window.innerHeight / 2) setFlipped(true);
            if (prefer === 'above' && overflowsAbove && rect.bottom < window.innerHeight / 2) setFlipped(true);

            // A panel taller than the space it has scrolls rather than spilling out.
            if (overflowsBelow || overflowsAbove) {
                setMaxHeight(Math.max(160, window.innerHeight - margin * 2));
            }
        };

        measure();
        window.addEventListener('resize', measure);

        return () => window.removeEventListener('resize', measure);
    }, [open, prefer]);

    // XOR: the preferred side unless the measurement said to flip.
    const opensAbove = (prefer === 'above') !== flipped;

    const style: CSSProperties = {
        left: offset,
        right: 'auto',
        maxWidth: 'calc(100vw - 1.5rem)',
        ...(opensAbove
            ? { bottom: '100%', top: 'auto', marginBottom: '0.5rem', marginTop: 0 }
            : { top: '100%', bottom: 'auto', marginTop: '0.5rem', marginBottom: 0 }),
        ...(maxHeight ? { maxHeight, overflowY: 'auto' as const } : {}),
    };

    return { ref, style };
}
