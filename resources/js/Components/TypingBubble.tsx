/**
 * "Someone is writing" drawn where their message will appear.
 *
 * A bubble rather than a line in the header, because that is where the eye already is
 * and where the message itself is about to land.
 */
export default function TypingBubble({ who }: { who: string }) {
    return (
        <div className="flex justify-start" aria-live="polite">
            <div className="flex items-center gap-1.5 rounded-2xl bg-[var(--color-surface-muted)] px-3 py-2.5">
                <span className="sr-only">{who} píše</span>
                {[0, 1, 2].map(index => (
                    <span
                        key={index}
                        // Staggered, so the three read as a wave rather than a blink.
                        style={{ animationDelay: `${index * 160}ms` }}
                        className="h-1.5 w-1.5 animate-bounce rounded-full bg-[var(--color-text-secondary)]"
                    />
                ))}
            </div>
        </div>
    );
}
