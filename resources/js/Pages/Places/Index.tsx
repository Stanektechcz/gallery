import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { localizedCountry } from '@/lib/localizedMap';
import {
    MapPin, Plus, RefreshCw, Search, Trash2, X
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Place {
    id: number; name: string; type: string; country?: string; city?: string;
    latitude?: number; longitude?: number; radius_meters: number;
    description?: string; website_url?: string;
    photo_count: number; visit_count: number;
    first_visit?: string; last_visit?: string;
    album_count: number; cover_thumb?: string;
}
interface SearchResult {
    name: string; display_name: string; country: string; country_code: string;
    latitude: number; longitude: number; category: string; type: string;
}

const TYPES: Record<string, { emoji: string; label: string }> = {
    country:    { emoji: '🌍', label: 'Země' },
    city:       { emoji: '🏙️', label: 'Město' },
    business:   { emoji: '🏢', label: 'Podnik' },
    restaurant: { emoji: '🍽️', label: 'Restaurace' },
    museum:     { emoji: '🏛️', label: 'Muzeum' },
    hotel:      { emoji: '🏨', label: 'Hotel' },
    home:       { emoji: '🏠', label: 'Domov' },
    custom:     { emoji: '📍', label: 'Vlastní' },
};

const EMPTY_FORM = {
    name: '', type: 'city', country: '', city: '', address: '',
    latitude: '', longitude: '', radius_meters: '500',
    description: '', website_url: '', osm_id: '', osm_type: '',
};

export default function PlacesIndex() {
    const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [places,      setPlaces]      = useState<Place[]>([]);
    const [loading,     setLoading]     = useState(true);
    const [filterType,  setFilterType]  = useState<string>('all');
    const [search,      setSearch]      = useState('');
    const [showCreate,  setShowCreate]  = useState(false);
    const [form,        setForm]        = useState({ ...EMPTY_FORM });
    const [creating,    setCreating]    = useState(false);

    // Nominatim autocomplete in create form
    const [nominatimQ,   setNominatimQ]   = useState('');
    const [nominatimRes, setNominatimRes] = useState<SearchResult[]>([]);
    const [nominatimLoad, setNominatimLoad] = useState(false);
    const [showNomDrop,  setShowNomDrop]  = useState(false);

    useEffect(() => {
        axios.get('/api/v1/places').then(r => setPlaces(r.data ?? [])).finally(() => setLoading(false));
    }, []);

    const handleNominatim = useCallback((val: string) => {
        setNominatimQ(val);
        setForm(p => ({ ...p, name: val }));
        if (searchTimer.current) clearTimeout(searchTimer.current);
        if (val.length < 2) { setNominatimRes([]); setShowNomDrop(false); return; }
        searchTimer.current = setTimeout(async () => {
            setNominatimLoad(true);
            try {
                const r = await axios.get('/api/v1/itinerary/search', { params: { q: val } });
                setNominatimRes(r.data ?? []); setShowNomDrop(true);
            } catch { /* ignore */ }
            finally { setNominatimLoad(false); }
        }, 400);
    }, []);

    const selectNominatim = (r: SearchResult) => {
        setNominatimQ(r.name || r.display_name);
        setForm(p => ({
            ...p, name: r.name || r.display_name,
            country: localizedCountry(r.country, r.country_code), latitude: r.latitude.toString(),
            longitude: r.longitude.toString(), osm_id: r.osm_id ?? '',
            osm_type: r.osm_type ?? '',
            type: r.category === 'country' ? 'country' : r.category === 'city' ? 'city' : p.type,
        }));
        setNominatimRes([]); setShowNomDrop(false);
    };

    const createPlace = async (e: React.FormEvent) => {
        e.preventDefault(); setCreating(true);
        try {
            const payload = {
                ...form,
                latitude:      form.latitude      ? parseFloat(form.latitude)      : undefined,
                longitude:     form.longitude     ? parseFloat(form.longitude)     : undefined,
                radius_meters: form.radius_meters ? parseInt(form.radius_meters)   : 500,
            };
            const r = await axios.post('/api/v1/places', payload);
            setPlaces(prev => [r.data, ...prev]);
            setForm({ ...EMPTY_FORM }); setNominatimQ(''); setShowCreate(false);
        } finally { setCreating(false); }
    };

    const deletePlace = async (id: number, e: React.MouseEvent) => {
        e.stopPropagation();
        if (!confirm('Smazat místo? Fotografie zůstanou v galerii.')) return;
        await axios.delete(`/api/v1/places/${id}`);
        setPlaces(prev => prev.filter(p => p.id !== id));
    };

    const filtered = places.filter(p => {
        if (filterType !== 'all' && p.type !== filterType) return false;
        if (search && !p.name.toLowerCase().includes(search.toLowerCase()) &&
            !p.country?.toLowerCase().includes(search.toLowerCase())) return false;
        return true;
    });

    const typeCounts: Record<string, number> = {};
    places.forEach(p => { typeCounts[p.type] = (typeCounts[p.type] ?? 0) + 1; });

    const fmtDate = (d?: string | null) =>
        d ? new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short', year: 'numeric' }) : null;

    return (
        <AppLayout>
            <Head title="Místa" />
            <div className="p-6 max-w-6xl mx-auto">

                {/* Header */}
                <div className="flex items-center justify-between mb-6 gap-4 flex-wrap">
                    <div>
                        <h1 className="text-xl font-bold text-white flex items-center gap-2">
                            <MapPin size={20} className="text-[var(--color-accent)]"/> Místa
                        </h1>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
                            {places.length} míst · {places.reduce((s, p) => s + p.photo_count, 0)} fotografií
                        </p>
                    </div>
                    <div className="flex gap-3 items-center">
                        {/* Search */}
                        <div className="relative">
                            <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                            <input value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Hledat místo…"
                                className="w-52 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg pl-8 pr-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                        </div>
                        <button onClick={() => setShowCreate(v => !v)}
                            className="flex items-center gap-1.5 bg-[var(--color-accent)] text-white text-sm px-3 py-2 rounded-lg hover:opacity-90">
                            <Plus size={14}/> Přidat místo
                        </button>
                    </div>
                </div>

                {/* Create form */}
                {showCreate && (
                    <form onSubmit={createPlace} className="mb-6 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4">
                        <h2 className="text-sm font-semibold text-white mb-3">Nové místo</h2>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            {/* Nominatim search / name */}
                            <div className="relative col-span-full sm:col-span-1">
                                <Search size={11} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                                <input value={nominatimQ} onChange={e => handleNominatim(e.target.value)}
                                    onFocus={() => nominatimRes.length > 0 && setShowNomDrop(true)}
                                    onBlur={() => setTimeout(() => setShowNomDrop(false), 150)}
                                    placeholder="Hledat nebo zadat název *" required
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg pl-7 pr-7 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                                {nominatimLoad && <RefreshCw size={11} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] animate-spin"/>}
                                {nominatimQ && !nominatimLoad && <button type="button" onMouseDown={e => e.preventDefault()} onClick={() => { setNominatimQ(''); setForm(p=>({...p,name:''})); setNominatimRes([]); setShowNomDrop(false); }} className="absolute right-2 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] hover:text-white"><X size={11}/></button>}

                                {showNomDrop && nominatimRes.length > 0 && (
                                    <div className="absolute z-50 top-full mt-1 left-0 right-0 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg shadow-2xl overflow-hidden max-h-48 overflow-y-auto">
                                        {nominatimRes.map((r, i) => (
                                            <button key={i} type="button" onMouseDown={e => { e.preventDefault(); selectNominatim(r); }}
                                                className="w-full text-left px-3 py-2 hover:bg-[var(--color-bg-secondary)] border-b border-[var(--color-border)] last:border-0 flex items-start gap-2">
                                                <span className="text-sm shrink-0">{TYPES[r.category]?.emoji ?? '📍'}</span>
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-xs font-medium text-white truncate">{r.name || r.display_name}</p>
                                                    <p className="text-[10px] text-[var(--color-text-secondary)] truncate">{localizedCountry(r.country, r.country_code)}</p>
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <select value={form.type} onChange={e => setForm(p => ({ ...p, type: e.target.value }))}
                                className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white outline-none focus:border-[var(--color-accent)]">
                                {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v.emoji} {v.label}</option>)}
                            </select>

                            <input value={form.country} onChange={e => setForm(p => ({ ...p, country: e.target.value }))}
                                placeholder="Země" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                            <input value={form.latitude} onChange={e => setForm(p => ({ ...p, latitude: e.target.value }))} type="number" step="any"
                                placeholder="Zeměpisná šířka" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                            <input value={form.longitude} onChange={e => setForm(p => ({ ...p, longitude: e.target.value }))} type="number" step="any"
                                placeholder="Zeměpisná délka" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                            <input value={form.radius_meters} onChange={e => setForm(p => ({ ...p, radius_meters: e.target.value }))} type="number" min="10" max="50000"
                                placeholder="Poloměr (m, výchozí 500)" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                            <textarea value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))}
                                placeholder="Popis (volitelně)…" rows={2}
                                className="col-span-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none"/>

                            <input value={form.website_url} onChange={e => setForm(p => ({ ...p, website_url: e.target.value }))} type="url"
                                placeholder="Web (https://…)" className="col-span-full sm:col-span-1 bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                        </div>
                        <div className="flex gap-2 mt-3">
                            <button type="submit" disabled={creating || !form.name}
                                className="bg-[var(--color-accent)] text-white text-sm px-4 py-2 rounded-lg hover:opacity-90 disabled:opacity-40">
                                {creating ? 'Vytvářím…' : 'Vytvořit místo'}
                            </button>
                            <button type="button" onClick={() => { setShowCreate(false); setNominatimQ(''); setForm({ ...EMPTY_FORM }); }}
                                className="border border-[var(--color-border)] text-[var(--color-text-secondary)] text-sm px-4 py-2 rounded-lg hover:text-white">Zrušit</button>
                        </div>
                    </form>
                )}

                {/* Type filter chips */}
                <div className="flex gap-2 mb-5 overflow-x-auto pb-1">
                    <button onClick={() => setFilterType('all')}
                        className={`text-xs px-3 py-1.5 rounded-full whitespace-nowrap transition-colors ${filterType === 'all' ? 'bg-[var(--color-accent)] text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                        Vše ({places.length})
                    </button>
                    {Object.entries(TYPES).filter(([k]) => typeCounts[k]).map(([k, v]) => (
                        <button key={k} onClick={() => setFilterType(k === filterType ? 'all' : k)}
                            className={`text-xs px-3 py-1.5 rounded-full whitespace-nowrap transition-colors ${filterType === k ? 'bg-[var(--color-accent)] text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                            {v.emoji} {v.label} ({typeCounts[k]})
                        </button>
                    ))}
                </div>

                {/* Places grid */}
                {loading ? (
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        {[...Array(8)].map((_, i) => <div key={i} className="h-40 bg-[var(--color-bg-card)] rounded-xl animate-pulse"/>)}
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="text-center py-16 text-[var(--color-text-secondary)]">
                        <MapPin size={40} className="mx-auto mb-3 opacity-20"/>
                        <p>Žádná místa{search ? ` pro „${search}"` : ''}</p>
                        <p className="text-sm mt-1 opacity-60">Přidejte první místo tlačítkem Přidat místo</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                        {filtered.map(place => (
                            <div key={place.id}
                                onClick={() => router.visit(`/places/${place.id}`)}
                                className="group bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl overflow-hidden hover:border-[var(--color-accent)]/50 cursor-pointer transition-all hover:shadow-lg">

                                {/* Cover */}
                                <div className="aspect-video relative overflow-hidden bg-[var(--color-bg-secondary)]">
                                    {place.cover_thumb
                                        ? <img src={place.cover_thumb} alt="" className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"/>
                                        : <div className="w-full h-full flex items-center justify-center text-4xl opacity-20">{TYPES[place.type]?.emoji ?? '📍'}</div>
                                    }
                                    {/* Type badge */}
                                    <div className="absolute top-2 left-2 bg-black/50 backdrop-blur-sm text-white text-[10px] px-1.5 py-0.5 rounded-full">
                                        {TYPES[place.type]?.emoji} {TYPES[place.type]?.label}
                                    </div>
                                    {/* Delete button */}
                                    <button onClick={e => deletePlace(place.id, e)}
                                        className="absolute top-2 right-2 p-1.5 bg-black/50 backdrop-blur-sm text-white rounded-full opacity-0 group-hover:opacity-100 hover:text-red-400 transition-all">
                                        <Trash2 size={12}/>
                                    </button>
                                </div>

                                {/* Info */}
                                <div className="p-3">
                                    <h3 className="text-sm font-semibold text-white truncate">{place.name}</h3>
                                    {(place.city || place.country) && (
                                        <p className="text-[10px] text-[var(--color-text-secondary)] truncate mt-0.5">{[place.city, place.country].filter(Boolean).join(', ')}</p>
                                    )}

                                    <div className="flex items-center gap-3 mt-2 text-[10px] text-[var(--color-text-secondary)]">
                                        <span>📸 {place.photo_count}</span>
                                        {place.visit_count > 0 && <span>Navštíveno {place.visit_count}×</span>}
                                    </div>

                                    {place.last_visit && (
                                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-1">
                                            Naposledy: {fmtDate(place.last_visit)}
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
