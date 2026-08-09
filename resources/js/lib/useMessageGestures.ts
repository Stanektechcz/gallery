import { useRef } from 'react';

/**
 * The gestures a message bubble responds to.
 *
 * Written as pointer events rather than separate mouse and touch handlers, because a
 * long press and a drag are the same gesture on both and duplicating them is how the two
 * drift apart.
 *
 * - double click / double tap  → the quick reaction, a heart
 * - press and hold             → open the reaction picker
 * - swipe right (touch only)   → open the detail sheet
 *
 * A press that moves is a scroll, not a hold, so the timer is cancelled the moment the
 * pointer travels — otherwise scrolling a conversation would fire a reaction picker.
 */
const HOLD_MS = 450;
const MOVE_TOLERANCE = 10;
const SWIPE_DISTANCE = 60;

export function useMessageGestures({ onDoubleTap, onHold, onSwipeRight }: {
    onDoubleTap: () => void;
    onHold: () => void;
    onSwipeRight?: () => void;
}) {
    const timer = useRef<number | null>(null);
    const start = useRef<{ x: number; y: number; touch: boolean } | null>(null);
    const held = useRef(false);
    const lastTap = useRef(0);

    const clear = () => {
        if (timer.current) { window.clearTimeout(timer.current); timer.current = null; }
    };

    return {
        onPointerDown: (event: React.PointerEvent) => {
            start.current = { x: event.clientX, y: event.clientY, touch: event.pointerType !== 'mouse' };
            held.current = false;
            clear();
            timer.current = window.setTimeout(() => { held.current = true; onHold(); }, HOLD_MS);
        },
        onPointerMove: (event: React.PointerEvent) => {
            if (!start.current) return;
            const dx = event.clientX - start.current.x;
            const dy = event.clientY - start.current.y;
            if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) clear();
        },
        onPointerUp: (event: React.PointerEvent) => {
            clear();
            const from = start.current;
            start.current = null;
            if (!from || held.current) return;

            const dx = event.clientX - from.x;
            const dy = event.clientY - from.y;

            // A mostly-horizontal drag to the right opens the detail, on touch only:
            // with a mouse that gesture is text selection.
            if (from.touch && onSwipeRight && dx > SWIPE_DISTANCE && Math.abs(dy) < SWIPE_DISTANCE) {
                onSwipeRight();
                return;
            }

            if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) return;

            const now = Date.now();
            if (now - lastTap.current < 350) { lastTap.current = 0; onDoubleTap(); return; }
            lastTap.current = now;
        },
        onPointerCancel: () => { clear(); start.current = null; },
    };
}
