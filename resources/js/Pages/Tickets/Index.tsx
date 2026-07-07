import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeftRight, Clock, RefreshCw, Search, Users, X } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

interface TripResult {
    carrier: string; icon: string;
    departure: string | null; arrival: string | null; duration_min: number | null;
    price: number | null; price_per_pax?: number; currency: string;
    seats: number | null; transfers: number | null;
    source: 'live' | 'link'; note?: string; book_url: string;
}

const MONTHS_CS = ['ledna','února','března','dubna','května','června','července','srpna','září','října','listopadu','prosince'];

function todayISO(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}

function fmtTime(iso: string | null): string {
    if (!iso) return '–';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '–';
    return `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}

function fmtDate(iso: string): string {
    const [y, m, d] = iso.split('-');
    return `${parseInt(d)}. ${MONTHS_CS[parseInt(m)-1]} ${y}`;
}

function fmtDur(min: number | null): string {
    if (!min) return '';
    const h = Math.floor(min / 60), m = min % 60;
    return h > 0 ? `${h}h${m > 0 ? m+'min' : ''}` : `${m}min`;
}

type SortKey = 'price' | 'departure';

export default function TicketsIndex() {
    const [form, setForm] = useState({ from: '', to: '', date: todayISO(), adults: 1 });
    const [results,  setResults]  = useState<TripResult[] | null>(null);
    const [loading,  setLoading]  = useState(false);
    const [error,    setError]    = useState<string | null>(null);
    const [sortBy,   setSortBy]   = useState<SortKey>('price');
    const [searched, setSearched] = useState<{ from: string; to: string; date: string } | null>(null);

    // Autocomplete for from/to fields
    const [acField, setAcField]   = useState<'from' | 'to' | null>(null);
    const [acQuery, setAcQuery]   = useState('');
    const [acResults, setAcResults] = useState<{name:string;country:string}[]>([]);
    const [acLoading, setAcLoading] = useState(false);
    const acTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const handleAcInput = useCallback((field: 'from' | 'to', val: string) => {
        setForm(p => ({ ...p, [field]: val }));
        setAcField(field);
        setAcQuery(val);
        if (acTimer.current) clearTimeout(acTimer.current);
        if (val.length < 2) { setAcResults([]); return; }
        acTimer.current = setTimeout(async () => {
            setAcLoading(true);
            try {
                const r = await axios.get('/api/v1/itinerary/search', { params: { q: val } });
                const cities = (r.data ?? []).filter((x: any) => ['city','country','landmark'].includes(x.category));
                setAcResults(cities.map((x: any) => ({ name: x.name || x.display_name, country: x.country })));
            } catch { setAcResults([]); }
            finally { setAcLoading(false); }
        }, 350);
    }, []);

    const pickAc = (field: 'from' | 'to', name: string) => {
        setForm(p => ({ ...p, [field]: name }));
        setAcField(null); setAcResults([]);
    };

    const swap = () => setForm(p => ({ from: p.to, to: p.from, date: p.date, adults: p.adults }));

    const search = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!form.from.trim() || !form.to.trim()) return;
        setLoading(true); setError(null); setResults(null);
        try {
            const r = await axios.get('/api/v1/tickets/search', {
                params: { from: form.from.trim(), to: form.to.trim(), date: form.date, adults: form.adults },
            });
            setResults(r.data ?? []);
            setSearched({ from: form.from, to: form.to, date: form.date });
        } catch (err: any) {
            setError('Nepodařilo se načíst výsledky. Zkuste to prosím znovu.');
        } finally {
            setLoading(false);
        }
    };

    const sorted = [...(results ?? [])].sort((a, b) => {
        if (sortBy === 'departure') {
            const da = a.departure ? new Date(a.departure).getTime() : 99999999999;
            const db = b.departure ? new Date(b.departure).getTime() : 99999999999;
            return da - db;
        }
        const pa = a.price ?? 99999, pb = b.price ?? 99999;
        return pa - pb;
    });

    const liveCount = sorted.filter(r => r.source === 'live').length;

    return (
        <AppLayout>
            <Head title="Vyhledávání jízdenek" />
            <div className="flex-1 overflow-y-auto min-h-0">
                <div className="max-w-2xl mx-auto px-4 py-6">

                    {/* Header */}
                    <div className="mb-6">
                        <h1 className="text-xl font-bold text-white flex items-center gap-2">
                            🎫 Vyhledávání jízdenek
                        </h1>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-1">
                            Porovnáme RegioJet, FlixBus a České dráhy — jedním klikem na nákup
                        </p>
                    </div>

                    {/* Search form */}
                    <form onSubmit={search} className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 mb-6 space-y-3">

                        {/* From / swap / To */}
                        <div className="flex items-center gap-2">
                            <div className="flex-1 relative">
                                <label className="text-[9px] text-[var(--color-text-secondary)] uppercase tracking-wider block mb-1">Odkud</label>
                                <input
                                    value={form.from}
                                    onChange={e => handleAcInput('from', e.target.value)}
                                    onBlur={() => setTimeout(() => { if (acField === 'from') { setAcField(null); setAcResults([]); } }, 200)}
                                    placeholder="Praha, Brno, Vídeň…"
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-xl px-3 py-2.5 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] transition-colors"
                                />
                                {acField === 'from' && acResults.length > 0 && (
                                    <div className="absolute z-50 top-full mt-1 left-0 right-0 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden max-h-48 overflow-y-auto">
                                        {acResults.map((r, i) => (
                                            <button key={i} type="button" onMouseDown={() => pickAc('from', r.name)}
                                                className="w-full text-left px-3 py-2 hover:bg-[var(--color-bg-secondary)] flex items-center gap-2 border-b border-[var(--color-border)] last:border-0">
                                                <span className="text-sm">📍</span>
                                                <div><p className="text-xs text-white">{r.name}</p><p className="text-[10px] text-[var(--color-text-secondary)]">{r.country}</p></div>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <button type="button" onClick={swap} title="Prohodit"
                                className="mt-5 p-2 rounded-lg text-[var(--color-text-secondary)] hover:text-white hover:bg-[var(--color-bg-secondary)] transition-colors shrink-0">
                                <ArrowLeftRight size={16}/>
                            </button>

                            <div className="flex-1 relative">
                                <label className="text-[9px] text-[var(--color-text-secondary)] uppercase tracking-wider block mb-1">Kam</label>
                                <input
                                    value={form.to}
                                    onChange={e => handleAcInput('to', e.target.value)}
                                    onBlur={() => setTimeout(() => { if (acField === 'to') { setAcField(null); setAcResults([]); } }, 200)}
                                    placeholder="Brno, Vídeň, Berlín…"
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-xl px-3 py-2.5 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] transition-colors"
                                />
                                {acField === 'to' && acResults.length > 0 && (
                                    <div className="absolute z-50 top-full mt-1 left-0 right-0 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden max-h-48 overflow-y-auto">
                                        {acResults.map((r, i) => (
                                            <button key={i} type="button" onMouseDown={() => pickAc('to', r.name)}
                                                className="w-full text-left px-3 py-2 hover:bg-[var(--color-bg-secondary)] flex items-center gap-2 border-b border-[var(--color-border)] last:border-0">
                                                <span className="text-sm">📍</span>
                                                <div><p className="text-xs text-white">{r.name}</p><p className="text-[10px] text-[var(--color-text-secondary)]">{r.country}</p></div>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Date + Passengers + Search button */}
                        <div className="flex items-end gap-2">
                            <div className="flex-1">
                                <label className="text-[9px] text-[var(--color-text-secondary)] uppercase tracking-wider block mb-1">Datum odjezdu</label>
                                <input type="date" value={form.date} min={todayISO()}
                                    onChange={e => setForm(p => ({ ...p, date: e.target.value }))}
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-xl px-3 py-2.5 text-sm text-white outline-none focus:border-[var(--color-accent)] transition-colors"/>
                            </div>

                            <div className="w-32">
                                <label className="text-[9px] text-[var(--color-text-secondary)] uppercase tracking-wider block mb-1">Cestující</label>
                                <div className="relative">
                                    <Users size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                                    <select value={form.adults} onChange={e => setForm(p => ({ ...p, adults: parseInt(e.target.value) }))}
                                        className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-xl pl-8 pr-3 py-2.5 text-sm text-white outline-none focus:border-[var(--color-accent)] transition-colors appearance-none">
                                        {[1,2,3,4,5,6].map(n => <option key={n} value={n}>{n} {n===1?'dospělý':'dospělí'}</option>)}
                                    </select>
                                </div>
                            </div>

                            <button type="submit" disabled={loading || !form.from || !form.to}
                                className="flex items-center gap-2 bg-[var(--color-accent)] text-white px-5 py-2.5 rounded-xl font-medium text-sm hover:opacity-90 disabled:opacity-40 transition-all shrink-0">
                                {loading ? <RefreshCw size={15} className="animate-spin"/> : <Search size={15}/>}
                                {loading ? 'Hledám…' : 'Hledat spoje'}
                            </button>
                        </div>
                    </form>

                    {/* Error */}
                    {error && (
                        <div className="bg-red-500/10 border border-red-500/30 rounded-xl p-3 mb-4 flex items-center gap-2">
                            <span className="text-red-400 text-sm">⚠️ {error}</span>
                            <button onClick={() => setError(null)} className="ml-auto text-red-400 hover:text-white"><X size={14}/></button>
                        </div>
                    )}

                    {/* Results */}
                    {searched && (
                        <div className="mb-3 flex items-center justify-between flex-wrap gap-2">
                            <div>
                                <p className="text-sm font-medium text-white">
                                    {searched.from} → {searched.to}
                                    <span className="text-[var(--color-text-secondary)] font-normal ml-2">{fmtDate(searched.date)}</span>
                                </p>
                                {liveCount > 0 && (
                                    <p className="text-[11px] text-green-400 flex items-center gap-1 mt-0.5">
                                        <span className="w-1.5 h-1.5 rounded-full bg-green-400 inline-block animate-pulse"/>
                                        {liveCount} živých výsledků
                                    </p>
                                )}
                            </div>
                            {/* Sort */}
                            <div className="flex gap-1">
                                {(['price','departure'] as SortKey[]).map(k => (
                                    <button key={k} onClick={() => setSortBy(k)}
                                        className={`px-3 py-1 rounded-lg text-xs transition-colors ${sortBy===k ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-text-secondary)] hover:text-white border border-[var(--color-border)]'}`}>
                                        {k === 'price' ? '⬆ Cena' : '⬆ Odjezd'}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Loading skeleton */}
                    {loading && (
                        <div className="space-y-3">
                            {[1,2,3,4].map(i => (
                                <div key={i} className="h-20 bg-[var(--color-bg-card)] rounded-xl animate-pulse border border-[var(--color-border)]"/>
                            ))}
                        </div>
                    )}

                    {/* Results list */}
                    {!loading && results !== null && (
                        sorted.length === 0 ? (
                            <div className="text-center py-12 text-[var(--color-text-secondary)]">
                                <p className="text-3xl mb-3">🚫</p>
                                <p className="text-sm font-medium text-white">Žádné spoje nenalezeny</p>
                                <p className="text-xs mt-1">Zkuste změnit datum nebo jiný název města</p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {sorted.map((trip, i) => (
                                    <div key={i}
                                        className={`bg-[var(--color-bg-card)] border rounded-xl overflow-hidden transition-all hover:border-[var(--color-accent)]/50 ${trip.source==='live' ? 'border-[var(--color-border)]' : 'border-[var(--color-border)] border-dashed'}`}>
                                        <div className="flex items-center gap-3 p-3.5">

                                            {/* Carrier */}
                                            <div className="text-2xl shrink-0">{trip.icon}</div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-semibold text-white">{trip.carrier}</p>

                                                {trip.source === 'live' && trip.departure ? (
                                                    <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                                                        <span className="text-sm font-mono text-white font-medium">{fmtTime(trip.departure)}</span>
                                                        <span className="text-[var(--color-text-secondary)] text-xs">→</span>
                                                        <span className="text-sm font-mono text-white font-medium">{fmtTime(trip.arrival)}</span>
                                                        {trip.duration_min && (
                                                            <span className="text-xs text-[var(--color-text-secondary)] flex items-center gap-0.5">
                                                                <Clock size={10}/>{fmtDur(trip.duration_min)}
                                                            </span>
                                                        )}
                                                        {trip.transfers !== null && trip.transfers > 0 && (
                                                            <span className="text-[10px] text-yellow-400">{trip.transfers}× přestup</span>
                                                        )}
                                                        {trip.transfers === 0 && (
                                                            <span className="text-[10px] text-green-400">přímý</span>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">{trip.note ?? 'Vyhledat dostupné spoje'}</p>
                                                )}
                                            </div>

                                            {/* Price + book */}
                                            <div className="text-right shrink-0">
                                                {trip.price !== null ? (
                                                    <>
                                                        <p className="text-lg font-bold text-[var(--color-accent)]">
                                                            {trip.price} {trip.currency}
                                                        </p>
                                                        {form.adults > 1 && trip.price_per_pax && (
                                                            <p className="text-[10px] text-[var(--color-text-secondary)]">{trip.price_per_pax} Kč/os</p>
                                                        )}
                                                        {trip.seats !== null && trip.seats <= 10 && (
                                                            <p className="text-[10px] text-orange-400">zbývá {trip.seats} míst</p>
                                                        )}
                                                    </>
                                                ) : (
                                                    <p className="text-xs text-[var(--color-text-secondary)]">zobrazit ceny</p>
                                                )}
                                                <a href={trip.book_url} target="_blank" rel="noopener noreferrer"
                                                    className={`inline-flex items-center gap-1 mt-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${trip.price !== null ? 'bg-[var(--color-accent)] text-white hover:opacity-90' : 'bg-[var(--color-bg-secondary)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                                                    {trip.price !== null ? '🎫 Koupit' : '🔍 Hledat'}
                                                    <span className="text-[10px] opacity-70">↗</span>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )
                    )}

                    {/* Empty state before search */}
                    {!loading && results === null && !error && (
                        <div className="text-center py-16 text-[var(--color-text-secondary)]">
                            <p className="text-5xl mb-4">🎫</p>
                            <p className="text-sm font-medium text-white">Vyhledejte spoje</p>
                            <p className="text-xs mt-2 leading-relaxed max-w-xs mx-auto">
                                Porovnáme RegioJet, FlixBus a České dráhy (IDOS) a zobrazíme ceny i časy odjezdů na jeden pohled.
                            </p>
                        </div>
                    )}

                </div>
            </div>
        </AppLayout>
    );
}
