import axios from 'axios';
import { Loader2, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Detail {
    uuid: string;
    sent_at: string | null;
    edited_at: string | null;
    author: { id: number; name: string | null };
    can_delete: boolean;
    kind: string;
    edits: number;
    size_bytes: number | null;
    readers: Array<{ id: number; name: string | null; read: boolean; read_at: string | null }>;
}

const KIND: Record<string, string> = {
    text: 'Text', image: 'Obrázek', gif: 'GIF', voice: 'Hlasovka',
};

const stamp = (value: string | null) =>
    value
        ? new Date(value).toLocaleString('cs-CZ', {
            day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit',
        })
        : '—';

const size = (bytes: number | null) =>
    bytes ? (bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.round(bytes / 1024)} kB`) : null;

/**
 * Everything about one message: when it went, who has seen it, and the way to remove it.
 *
 * A sheet rather than a popover, because on a phone this is reached by swiping the bubble
 * and a popover would open under the thumb that just opened it.
 */
export default function MessageDetail({ uuid, onClose, onDeleted }: {
    uuid: string;
    onClose: () => void;
    onDeleted: () => void;
}) {
    const [detail, setDetail] = useState<Detail | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        void axios.get(`/api/v1/chat/${uuid}/detail`)
            .then(response => setDetail(response.data))
            .catch(reason => setError(reason?.response?.data?.message ?? 'Detail se nepodařilo načíst.'));
    }, [uuid]);

    const remove = async () => {
        if (!window.confirm('Smazat zprávu? Zůstane v historii konverzace, ale nikomu se nezobrazí.')) return;
        setBusy(true);
        try { await axios.delete(`/api/v1/chat/${uuid}`); onDeleted(); onClose(); }
        catch { setError('Zprávu se nepodařilo smazat.'); }
        finally { setBusy(false); }
    };

    return (
        <div className="fixed inset-0 z-[740] flex items-end bg-black/60 sm:items-center sm:justify-center sm:p-4">
            <button type="button" aria-label="Zavřít detail" onClick={onClose} className="absolute inset-0 cursor-default" />

            <section className="safe-area-pb relative w-full rounded-t-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-4 sm:max-w-sm sm:rounded-2xl">
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Detail zprávy</h2>
                    <button type="button" onClick={onClose} aria-label="Zavřít" className="flex h-8 w-8 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]"><X size={16} /></button>
                </div>

                {error && <p role="alert" className="mb-3 rounded-lg bg-red-500/10 p-2 text-xs text-red-100">{error}</p>}
                {!detail && !error && <div className="flex justify-center py-6"><Loader2 size={18} className="animate-spin text-[var(--color-accent)]" /></div>}

                {detail && (
                    <>
                        <dl className="space-y-1.5 text-xs">
                            <Row label="Odesláno" value={stamp(detail.sent_at)} />
                            <Row label="Autor" value={detail.author.name ?? '—'} />
                            <Row label="Typ" value={KIND[detail.kind] ?? detail.kind} />
                            {size(detail.size_bytes) && <Row label="Velikost" value={size(detail.size_bytes)!} />}
                            {detail.edited_at && <Row label="Upraveno" value={`${stamp(detail.edited_at)} · ${detail.edits}×`} />}
                        </dl>

                        <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                            <p className="mb-1.5 text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">Přečteno</p>
                            {detail.readers.length === 0 && <p className="text-xs text-[var(--color-text-secondary)]">V konverzaci nikdo další není.</p>}
                            {detail.readers.map(reader => (
                                <div key={reader.id} className="flex items-center justify-between py-0.5 text-xs">
                                    <span className="text-[var(--color-text-primary)]">{reader.name}</span>
                                    <span className="text-[var(--color-text-secondary)]">
                                        {reader.read ? stamp(reader.read_at) : 'zatím nepřečteno'}
                                    </span>
                                </div>
                            ))}
                        </div>

                        {detail.can_delete && (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => void remove()}
                                className="mt-3 flex min-h-10 w-full items-center justify-center gap-2 rounded-xl border border-red-400/30 text-xs text-red-300 hover:bg-red-500/10 disabled:opacity-50"
                            >
                                {busy ? <Loader2 size={14} className="animate-spin" /> : <Trash2 size={14} />} Smazat zprávu
                            </button>
                        )}
                    </>
                )}
            </section>
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-[var(--color-text-secondary)]">{label}</dt>
            <dd className="text-right text-[var(--color-text-primary)]">{value}</dd>
        </div>
    );
}
