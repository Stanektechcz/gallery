/**
 * Print/ContactSheet.tsx — Printable contact sheet for a photo book.
 * Opened in a new tab, user presses Ctrl+P to print.
 * Uses @media print CSS (auto-triggered on load if ?autoprint=1).
 */

import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

interface Item {
    id: number; sort_order: number; uuid: string; filename: string;
    taken_at?: string; width?: number; height?: number; size_bytes?: number;
    thumb_url?: string;
}
interface Book {
    name: string; purpose: string; item_count: number; target_count?: number;
}

function formatBytes(b?: number): string {
    if (!b) return '';
    if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)} KB`;
    return `${(b / 1024 ** 2).toFixed(1)} MB`;
}
function fmtDate(d?: string) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function ContactSheet() {
    const [book,    setBook]    = useState<Book | null>(null);
    const [items,   setItems]   = useState<Item[]>([]);
    const [loading, setLoading] = useState(true);

    // Extract UUID from URL
    const uuid = window.location.pathname.split('/')[2] ?? '';

    useEffect(() => {
        if (!uuid) return;
        axios.get(`/api/v1/books/${uuid}/export/contact`)
            .then(r => { setBook(r.data.book); setItems(r.data.items ?? []); })
            .finally(() => setLoading(false));
    }, [uuid]);

    // Auto-print when loaded
    useEffect(() => {
        if (!loading && items.length > 0) {
            const params = new URLSearchParams(window.location.search);
            if (params.get('autoprint') === '1') {
                setTimeout(() => window.print(), 500);
            }
        }
    }, [loading, items.length]);

    if (loading) {
        return (
            <div className="flex items-center justify-center min-h-screen bg-white">
                <p className="text-gray-500 text-sm">Načítám…</p>
            </div>
        );
    }

    const today = new Date().toLocaleDateString('cs-CZ');

    return (
        <>
            <Head title={`Contact Sheet — ${book?.name ?? ''}`}/>

            <style>{`
                @media print {
                    .no-print { display: none !important; }
                    body { margin: 0; background: white; }
                    .page-break { page-break-before: always; }
                }
                * { box-sizing: border-box; }
                body { font-family: 'Arial', sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
                @media print {
                    body { background: white; }
                }
            `}</style>

            {/* Print controls (hidden when printing) */}
            <div className="no-print fixed top-0 left-0 right-0 z-50 bg-gray-800 text-white px-6 py-3 flex items-center justify-between shadow-lg">
                <div>
                    <p className="text-sm font-semibold">{book?.name} — Contact Sheet</p>
                    <p className="text-xs text-gray-400">{items.length} fotografií · Ctrl+P pro tisk</p>
                </div>
                <div className="flex gap-3">
                    <button onClick={() => window.print()}
                        className="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded-lg transition-colors">
                        🖨 Tisknout
                    </button>
                    <button onClick={() => window.close()}
                        className="bg-gray-600 hover:bg-gray-700 text-white text-sm px-4 py-2 rounded-lg transition-colors">
                        Zavřít
                    </button>
                </div>
            </div>

            {/* Contact sheet content */}
            <div style={{ padding: '80px 20px 20px', maxWidth: '297mm', margin: '0 auto', background: 'white' }}>

                {/* Header */}
                <div style={{ borderBottom: '2px solid #333', paddingBottom: '10px', marginBottom: '16px' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' }}>
                        <div>
                            <h1 style={{ margin: 0, fontSize: '20px', fontWeight: 700 }}>{book?.name}</h1>
                            <p style={{ margin: '3px 0 0', fontSize: '12px', color: '#666' }}>
                                {items.length} fotografií{book?.target_count ? ` z ${book.target_count}` : ''} · Exportováno {today}
                            </p>
                        </div>
                        <p style={{ fontSize: '11px', color: '#999' }}>gallery.stanektech.cz</p>
                    </div>
                </div>

                {/* Grid */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(6, 1fr)',
                    gap: '6px',
                }}>
                    {items.map((item, idx) => (
                        <div key={item.id} style={{ border: '1px solid #ddd', borderRadius: '4px', overflow: 'hidden', background: '#fafafa' }}>
                            {/* Thumbnail */}
                            <div style={{ width: '100%', paddingTop: '100%', position: 'relative', background: '#eee' }}>
                                {item.thumb_url && (
                                    <img src={item.thumb_url} alt=""
                                        style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }}/>
                                )}
                                {/* Order badge */}
                                <div style={{
                                    position: 'absolute', top: '3px', left: '3px',
                                    background: 'rgba(0,0,0,0.7)', color: 'white',
                                    fontSize: '9px', fontWeight: 700, padding: '1px 4px', borderRadius: '2px',
                                }}>
                                    {idx + 1}
                                </div>
                            </div>
                            {/* Metadata */}
                            <div style={{ padding: '3px 4px' }}>
                                <p style={{ margin: 0, fontSize: '8px', color: '#333', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                    {item.filename}
                                </p>
                                <p style={{ margin: '1px 0 0', fontSize: '7px', color: '#888' }}>
                                    {fmtDate(item.taken_at)}
                                    {item.width && item.height ? ` · ${item.width}×${item.height}` : ''}
                                </p>
                                {item.size_bytes && (
                                    <p style={{ margin: '1px 0 0', fontSize: '7px', color: '#aaa' }}>{formatBytes(item.size_bytes)}</p>
                                )}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Footer */}
                <div style={{ borderTop: '1px solid #ddd', marginTop: '20px', paddingTop: '8px', display: 'flex', justifyContent: 'space-between' }}>
                    <p style={{ fontSize: '9px', color: '#aaa', margin: 0 }}>{book?.name}</p>
                    <p style={{ fontSize: '9px', color: '#aaa', margin: 0 }}>{today}</p>
                    <p style={{ fontSize: '9px', color: '#aaa', margin: 0 }}>{items.length} fotografií</p>
                </div>
            </div>
        </>
    );
}
