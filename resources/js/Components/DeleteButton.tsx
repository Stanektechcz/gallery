import { Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * Mazací tlačítko, které se napřed zeptá.
 *
 * Na myši je omyl levný: kurzor míří přesně a ruka se nechvěje. Na telefonu je palec
 * široký a stránka se pod ním posouvá, takže klepnutí často přistane o kus vedle.
 * Proto první klepnutí nic nesmaže — jen odkryje „Smazat" a „Zpět".
 *
 * Odkryté potvrzení se samo zavře po pěti vteřinách. Bez toho by nechtěné první
 * klepnutí nechalo na stránce viset živé mazací tlačítko, na které palec při dalším
 * scrollování snadno přistane podruhé.
 *
 * `window.confirm` by byl kratší, ale na telefonu vyskočí přes celou obrazovku a
 * vypadá jako hlášení prohlížeče, ne jako součást aplikace. Na místech, kde už je,
 * zůstává — ptá se, a to je to podstatné.
 */
export default function DeleteButton({
    onDelete,
    label,
    confirmLabel = 'Smazat',
    className = '',
    disabled = false,
    children,
}: {
    /** Co se má stát po potvrzení. Chybu si řeší volající — tlačítko se jen odemkne. */
    onDelete: () => void | Promise<void>;
    /** Čte se nahlas odečítačem a je i v title. Např. „Smazat výdaj Večeře". */
    label: string;
    /** Text potvrzovacího tlačítka, když se hodí něco přesnějšího než „Smazat". */
    confirmLabel?: string;
    className?: string;
    disabled?: boolean;
    /** Vlastní obsah místo ikony koše. */
    children?: React.ReactNode;
}) {
    const [armed, setArmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const timer = useRef<number | null>(null);

    const disarm = () => {
        if (timer.current) window.clearTimeout(timer.current);
        timer.current = null;
        setArmed(false);
    };

    const arm = () => {
        setArmed(true);
        if (timer.current) window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setArmed(false), 5000);
    };

    useEffect(() => () => { if (timer.current) window.clearTimeout(timer.current); }, []);

    const run = async () => {
        disarm();
        setBusy(true);
        try {
            await onDelete();
        } finally {
            setBusy(false);
        }
    };

    if (armed) {
        return (
            <span className="inline-flex shrink-0 items-center gap-1">
                <button
                    type="button"
                    onClick={run}
                    disabled={busy}
                    className="min-h-9 rounded-lg border border-red-400/40 bg-red-500/10 px-2.5 text-[11px] font-medium text-red-200 disabled:opacity-50"
                >
                    {confirmLabel}
                </button>
                <button
                    type="button"
                    onClick={disarm}
                    className="min-h-9 rounded-lg px-2 text-[11px] text-[var(--color-text-secondary)]"
                >
                    Zpět
                </button>
            </span>
        );
    }

    return (
        <button
            type="button"
            onClick={arm}
            disabled={disabled || busy}
            aria-label={label}
            title={label}
            className={`inline-flex min-h-9 min-w-9 shrink-0 items-center justify-center gap-1.5 rounded-lg text-[var(--color-text-secondary)] transition-colors hover:bg-red-500/10 hover:text-red-200 disabled:opacity-40 ${className}`}
        >
            {children ?? <Trash2 size={14} />}
        </button>
    );
}
