import { useEffect, type RefObject } from 'react';

/**
 * Grows a textarea with its content, up to a limit, then lets it scroll.
 *
 * A fixed one-line box hides what you are writing; an unbounded one eats the
 * conversation above it. Height is measured rather than counted, because a wrapped long
 * word occupies a line that no newline count would find.
 */
export function useAutoGrow(ref: RefObject<HTMLTextAreaElement | null>, value: string, maxRows = 5): void {
    useEffect(() => {
        const element = ref.current;
        if (!element) return;

        // Reset first: without it the box can only ever grow, never shrink back.
        element.style.height = 'auto';

        const styles = window.getComputedStyle(element);
        const lineHeight = parseFloat(styles.lineHeight) || 20;
        const padding = parseFloat(styles.paddingTop) + parseFloat(styles.paddingBottom);
        const border = parseFloat(styles.borderTopWidth) + parseFloat(styles.borderBottomWidth);
        const max = lineHeight * maxRows + padding + border;

        element.style.height = `${Math.min(element.scrollHeight, max)}px`;
        element.style.overflowY = element.scrollHeight > max ? 'auto' : 'hidden';
    }, [ref, value, maxRows]);
}
