import { CSSProperties, useLayoutEffect, useRef, useState } from 'react';

/**
 * Keeps a dropdown panel inside the viewport.
 *
 * The appearance controls sit in the sidebar footer, where a 16rem panel anchored to the
 * button's right edge extended left past the screen and was unreachable. Anchoring left
 * instead would fail the same way for a button near the right edge, so the panel is
 * measured once on open and nudged back inside.
 *
 * useLayoutEffect rather than useEffect: the correction lands before paint, so the panel
 * never appears in the wrong place first.
 */
export function useViewportSafePanel(open: boolean) {
    const ref = useRef<HTMLDivElement>(null);
    const [offset, setOffset] = useState(0);

    useLayoutEffect(() => {
        if (!open) { setOffset(0); return; }

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
        };

        measure();
        window.addEventListener('resize', measure);

        return () => window.removeEventListener('resize', measure);
    }, [open]);

    const style: CSSProperties = { left: offset, right: 'auto', maxWidth: 'calc(100vw - 1.5rem)' };

    return { ref, style };
}
