import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Calendar, CalendarClock, Camera, CheckCircle, ExternalLink, Globe, MapPin, Pencil, Plus, RefreshCw, Save, Search, Star, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface WishlistPlace {
    id: number; name: string; country?: string; country_code?: string;
    latitude?: number; longitude?: number; category: string;
    notes?: string; description?: string; website_url?: string;
    priority: 'dream' | 'soon' | 'someday'; visited: boolean; visited_at?: string;
    osm_id?: string; planned_date?: string;
}
interface PlacePhoto { uuid: string; thumbnail_url: string; taken_at?: string; }
interface SearchResult {
    osm_id: string; osm_type: string; display_name: string; name: string;
    country: string; country_code: string; latitude: number; longitude: number;
    category: string; type: string;
}
interface VisitedArea {
    latitude: number; longitude: number; photo_count: number;
    first_visit?: string; last_visit?: string;
}

const PRIORITY_COLORS = { dream: '#ec4899', soon: '#f97316', someday: '#6366f1' };
const PRIORITY_LABELS = { dream: '✨ Sen', soon: '🎯 Brzy', someday: '🌍 Jednou' };
const CATEGORIES = ['city','country','landmark','restaurant','museum','nature','other'];
const CAT_EMOJI: Record<string, string> = { city:'🏙️', country:'🌍', landmark:'🗺️', restaurant:'🍽️', museum:'🏛️', nature:'🌿', other:'📍' };
const CAT_LABEL: Record<string, string> = { city:'Město', country:'Stát/Kraj', landmark:'Památka', restaurant:'Restaurace', museum:'Muzeum', nature:'Příroda', other:'Jiné' };
const EMPTY_FORM = { name:'', country:'', country_code:'', latitude:'', longitude:'', category:'city', priority:'someday', notes:'', description:'', website_url:'', osm_id:'', osm_type:'' };

export default function ItineraryIndex() {
    const mapRef        = useRef<HTMLDivElement>(null);
    const mapObj        = useRef<any>(null);
    const previewMarker = useRef<any>(null);
    const searchTimer   = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [mapLoaded, setMapLoaded] = useState(false);
    const [wishlist,  setWishlist]  = useState<WishlistPlace[]>([]);
    const [visited,   setVisited]   = useState<VisitedArea[]>([]);
    const [filter,    setFilter]    = useState<'all'|'dream'|'soon'|'someday'|'visited'|'planned'>('all');
    const [showForm,  setShowForm]  = useState(false);
    const [checking,  setChecking]  = useState(false);
    const [form,      setForm]      = useState({ ...EMPTY_FORM });
    const [selected,  setSelected]  = useState<WishlistPlace | null>(null);

    // Inline editing state
    const [editField, setEditField] = useState<string | null>(null);
    const [editVal,   setEditVal]   = useState('');
    const [saving,    setSaving]    = useState(false);

    // Photos near selected place
    const [placePhotos,  setPlacePhotos]  = useState<PlacePhoto[]>([]);
    const [photosLoading, setPhotosLoading] = useState(false);

    const [searchQuery,   setSearchQuery]   = useState('');
    const [searchResults, setSearchResults] = useState<SearchResult[]>([]);
    const [searchLoading, setSearchLoading] = useState(false);
    const [showDropdown,  setShowDropdown]  = useState(false);

    useEffect(() => {
        axios.get('/api/v1/itinerary').then(r => {
            setWishlist(r.data.wishlist ?? []);
            setVisited(r.data.visited_areas ?? []);
        });
    }, []);

    useEffect(() => {
        if (!mapRef.current || !mapLoaded) return;
        const L = (window as any).L;
        if (!L) return;
        if (mapObj.current) { mapObj.current.remove(); mapObj.current = null; }

        const map = L.map(mapRef.current, { center: [20, 10], zoom: 2 });
        mapObj.current = map;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 18 }).addTo(map);

        visited.forEach(area => {
            const r = Math.min(22, 5 + Math.log(area.photo_count) * 3);
            L.circleMarker([area.latitude, area.longitude], { radius: r, fillColor: '#22c55e', color: '#16a34a', weight: 1, opacity: 0.9, fillOpacity: 0.5 })
                .addTo(map).bindPopup(`<div style="font-size:12px"><b>📸 ${area.photo_count} fotek</b><br/>${area.first_visit ? new Date(area.first_visit).toLocaleDateString('cs-CZ') : ''}<br/><small style="color:#888">${Number(area.latitude).toFixed(4)}, ${Number(area.longitude).toFixed(4)}</small></div>`);
        });

        wishlist.filter(p => p.latitude && p.longitude).forEach(place => {
            const color = place.visited ? '#22c55e' : (PRIORITY_COLORS[place.priority] ?? '#6366f1');
            const icon = L.divIcon({
                className: '',
                html: `<div style="width:28px;height:28px;border-radius:50%;background:${color};border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;font-size:13px;cursor:pointer">${place.visited ? '✅' : (CAT_EMOJI[place.category]??'📍')}</div>`,
                iconSize: [28,28], iconAnchor: [14,14],
            });
            L.marker([place.latitude!, place.longitude!], { icon }).addTo(map).bindPopup(`
                <div style="font-size:12px;min-width:160px">
                    <b>${place.name}</b>${place.country ? ' · ' + place.country : ''}<br/>
                    <span style="color:${color}">${PRIORITY_LABELS[place.priority] ?? ''}</span>
                    ${place.visited ? `<br/><span style="color:#22c55e">✅ ${place.visited_at ?? 'Navštíveno'}</span>` : ''}
                    ${place.notes ? `<br/><small style="color:#888">${place.notes}</small>` : ''}
                    ${place.description ? `<br/><small style="color:#aaa">${place.description.substring(0, 100)}${place.description.length > 100 ? '…' : ''}</small>` : ''}
                </div>
            `);
        });

        return () => { map.remove(); mapObj.current = null; };
    }, [mapLoaded, wishlist, visited]);

    useEffect(() => {
        if ((window as any).L) { setMapLoaded(true); return; }
        const link = document.createElement('link'); link.rel='stylesheet'; link.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(link);
        const script = document.createElement('script'); script.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; script.onload=()=>setMapLoaded(true); document.head.appendChild(script);
    }, []);

    const handleSearchInput = useCallback((val: string) => {
        setSearchQuery(val);
        setForm(p => ({ ...p, name: val }));
        if (searchTimer.current) clearTimeout(searchTimer.current);
        if (val.length < 2) { setSearchResults([]); setShowDropdown(false); return; }
        searchTimer.current = setTimeout(async () => {
            setSearchLoading(true);
            try {
                const r = await axios.get('/api/v1/itinerary/search', { params: { q: val } });
                setSearchResults(r.data ?? []);
                setShowDropdown(true);
            } catch { /* ignore */ }
            finally { setSearchLoading(false); }
        }, 500);
    }, []);

    const selectResult = (r: SearchResult) => {
        setForm(p => ({
            ...p, name: r.name || r.display_name, country: r.country,
            country_code: r.country_code, latitude: r.latitude.toString(),
            longitude: r.longitude.toString(), category: r.category,
            osm_id: r.osm_id, osm_type: r.osm_type,
        }));
        setSearchQuery(r.name || r.display_name);
        setShowDropdown(false);
        const L = (window as any).L; const map = mapObj.current;
        if (L && map) {
            if (previewMarker.current) { previewMarker.current.remove(); previewMarker.current = null; }
            previewMarker.current = L.marker([r.latitude, r.longitude], {
                icon: L.divIcon({ className: '', html: `<div style="width:32px;height:32px;border-radius:50%;background:#f59e0b;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;font-size:15px">${CAT_EMOJI[r.category]??'📍'}</div>`, iconSize:[32,32], iconAnchor:[16,16] }),
            }).addTo(map).bindPopup(`<b>${r.name}</b><br/><small style="color:#888">${r.display_name.substring(0,80)}</small>`).openPopup();
            map.flyTo([r.latitude, r.longitude], r.category === 'country' ? 5 : 10, { duration: 1 });
        }
    };

    const addPlace = async (e: React.FormEvent) => {
        e.preventDefault();
        if (previewMarker.current) { previewMarker.current.remove(); previewMarker.current = null; }
        const payload = { ...form, latitude: form.latitude ? parseFloat(form.latitude) : undefined, longitude: form.longitude ? parseFloat(form.longitude) : undefined };
        const r = await axios.post('/api/v1/itinerary', payload);
        setWishlist(prev => [r.data, ...prev]);
        setForm({ ...EMPTY_FORM }); setSearchQuery(''); setShowForm(false);
    };

    const toggleVisited = async (place: WishlistPlace) => {
        const r = await axios.patch(`/api/v1/itinerary/${place.id}`, { visited: !place.visited });
        setWishlist(prev => prev.map(p => p.id === place.id ? r.data : p));
        if (selected?.id === place.id) setSelected(r.data);
    };

    // Generic update for any field(s)
    const updatePlace = async (id: number, patch: Partial<WishlistPlace>) => {
        setSaving(true);
        try {
            const r = await axios.patch(`/api/v1/itinerary/${id}`, patch);
            setWishlist(prev => prev.map(p => p.id === id ? r.data : p));
            if (selected?.id === id) setSelected(r.data);
        } finally {
            setSaving(false);
            setEditField(null);
        }
    };

    // Load nearby photos when place is selected
    const loadPlacePhotos = useCallback(async (place: WishlistPlace) => {
        if (!place.latitude || !place.longitude) { setPlacePhotos([]); return; }
        setPhotosLoading(true);
        try {
            const r = await axios.get(`/api/v1/itinerary/${place.id}/photos`);
            setPlacePhotos(r.data ?? []);
        } catch { setPlacePhotos([]); }
        finally { setPhotosLoading(false); }
    }, []);

    const removePlace = async (id: number) => {
        if (!confirm('Odebrat místo z itineráře?')) return;
        await axios.delete(`/api/v1/itinerary/${id}`);
        setWishlist(prev => prev.filter(p => p.id !== id));
        if (selected?.id === id) setSelected(null);
    };

    const autoCheck = async () => {
        setChecking(true);
        try {
            const r = await axios.post('/api/v1/itinerary/check-visited');
            if (r.data.auto_detected > 0) {
                const r2 = await axios.get('/api/v1/itinerary');
                setWishlist(r2.data.wishlist ?? []);
                alert(`Automaticky označeno: ${r.data.auto_detected} míst z fotek!`);
            } else { alert('Žádné nové shody s fotografiemi.'); }
        } finally { setChecking(false); }
    };

    const flyToPlace = (place: WishlistPlace) => {
        const map = mapObj.current;
        if (map && place.latitude && place.longitude) map.flyTo([place.latitude, place.longitude], 10, { duration: 1 });
        const newSelected = selected?.id === place.id ? null : place;
        setSelected(newSelected);
        setEditField(null);
        if (newSelected) loadPlacePhotos(newSelected);
        else setPlacePhotos([]);
    };

    const filtered = wishlist.filter(p => {
        if (filter === 'visited') return p.visited;
        if (filter === 'planned') return !!p.planned_date && !p.visited;
        if (filter === 'all') return true;
        return p.priority === filter && !p.visited;
    });

    const visitedCount = wishlist.filter(p => p.visited).length;
    const dreamCount   = wishlist.filter(p => p.priority === 'dream' && !p.visited).length;

    return (
        <AppLayout>
            <Head title="Světový itinerář" />
            <div className="flex h-full min-h-0">

                {/* ── Left panel ───────────────────────────────────────── */}
                <div className="w-80 shrink-0 flex flex-col border-r border-[var(--color-border)] overflow-hidden">

                    {/* Header + stats */}
                    <div className="p-4 border-b border-[var(--color-border)] shrink-0">
                        <div className="flex items-center gap-2 mb-3">
                            <Globe size={18} className="text-[var(--color-accent)]" />
                            <h1 className="text-sm font-semibold text-white">Světový itinerář</h1>
                        </div>
                        <div className="grid grid-cols-3 gap-2 mb-3">
                            {[{val:visited.length,label:'navštíveno',cls:'text-green-400'},{val:dreamCount,label:'snů',cls:'text-pink-400'},{val:wishlist.length,label:'celkem',cls:'text-[var(--color-accent)]'}].map(({val,label,cls})=>(
                                <div key={label} className="bg-[var(--color-bg-card)] rounded-lg p-2 text-center">
                                    <p className={`text-lg font-bold ${cls}`}>{val}</p>
                                    <p className="text-[9px] text-[var(--color-text-secondary)]">{label}</p>
                                </div>
                            ))}
                        </div>
                        <div className="flex gap-2">
                            <button onClick={() => { setShowForm(v=>!v); setSearchQuery(''); setForm({...EMPTY_FORM}); setShowDropdown(false); }}
                                className="flex-1 flex items-center justify-center gap-1.5 bg-[var(--color-accent)] text-white text-xs py-1.5 rounded-lg hover:opacity-90">
                                <Plus size={12}/> Přidat místo
                            </button>
                            <button onClick={autoCheck} disabled={checking}
                                className="flex items-center gap-1.5 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-xs px-2.5 py-1.5 rounded-lg transition-colors disabled:opacity-40"
                                title="Automaticky označit navštívená místa z fotek">
                                <RefreshCw size={12} className={checking?'animate-spin':''}/> Auto
                            </button>
                        </div>
                    </div>

                    {/* Add form with autocomplete */}
                    {showForm && (
                        <div className="border-b border-[var(--color-border)] shrink-0 overflow-y-auto max-h-[55vh]">
                            <form onSubmit={addPlace} className="p-3 space-y-2">
                                <p className="text-[10px] text-[var(--color-text-secondary)]">Zadejte název nebo použijte vyhledávání</p>

                                {/* Autocomplete search */}
                                <div className="relative">
                                    <Search size={11} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                                    <input
                                        value={searchQuery}
                                        onChange={e => handleSearchInput(e.target.value)}
                                        onFocus={() => searchResults.length > 0 && setShowDropdown(true)}
                                        onBlur={() => setTimeout(() => setShowDropdown(false), 150)}
                                        placeholder="Hledat město, stát, místo světa…"
                                        className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg pl-7 pr-7 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"
                                    />
                                    {searchLoading
                                        ? <RefreshCw size={11} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] animate-spin"/>
                                        : searchQuery && <button type="button" onMouseDown={e=>e.preventDefault()} onClick={()=>{setSearchQuery('');setForm(p=>({...p,name:''}));setSearchResults([]);setShowDropdown(false);}} className="absolute right-2 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] hover:text-white"><X size={11}/></button>
                                    }

                                    {/* Dropdown */}
                                    {showDropdown && searchResults.length > 0 && (
                                        <div className="absolute z-50 top-full mt-1 left-0 right-0 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg shadow-2xl overflow-hidden max-h-52 overflow-y-auto">
                                            {searchResults.map((r, i) => (
                                                <button key={i} type="button" onMouseDown={e => { e.preventDefault(); selectResult(r); }}
                                                    className="w-full text-left px-3 py-2 hover:bg-[var(--color-bg-secondary)] transition-colors border-b border-[var(--color-border)] last:border-0 flex items-start gap-2">
                                                    <span className="text-sm shrink-0 mt-0.5">{CAT_EMOJI[r.category]??'📍'}</span>
                                                    <div className="flex-1 min-w-0">
                                                        <p className="text-xs font-medium text-white truncate">{r.name || r.display_name}</p>
                                                        <p className="text-[10px] text-[var(--color-text-secondary)] truncate">{r.country}{r.type ? ' · ' + (CAT_LABEL[r.type] ?? r.type) : ''}</p>
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                {/* Coords (auto-filled from search, manually editable) */}
                                <div className="grid grid-cols-2 gap-2">
                                    <input type="number" step="any" value={form.latitude} onChange={e=>setForm(p=>({...p,latitude:e.target.value}))}
                                        placeholder="Šířka (lat)" className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                                    <input type="number" step="any" value={form.longitude} onChange={e=>setForm(p=>({...p,longitude:e.target.value}))}
                                        placeholder="Délka (lng)" className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                                </div>

                                <input value={form.country} onChange={e=>setForm(p=>({...p,country:e.target.value}))}
                                    placeholder="Země" className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                                <div className="grid grid-cols-2 gap-2">
                                    <select value={form.category} onChange={e=>setForm(p=>({...p,category:e.target.value}))}
                                        className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]">
                                        {CATEGORIES.map(c => <option key={c} value={c}>{CAT_EMOJI[c]} {CAT_LABEL[c] ?? c}</option>)}
                                    </select>
                                    <select value={form.priority} onChange={e=>setForm(p=>({...p,priority:e.target.value}))}
                                        className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]">
                                        <option value="dream">✨ Sen</option>
                                        <option value="soon">🎯 Brzy</option>
                                        <option value="someday">🌍 Jednou</option>
                                    </select>
                                </div>

                                <textarea value={form.notes} onChange={e=>setForm(p=>({...p,notes:e.target.value}))}
                                    placeholder="Poznámky…" rows={2}
                                    className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none"/>

                                <textarea value={form.description} onChange={e=>setForm(p=>({...p,description:e.target.value}))}
                                    placeholder="Popis místa, proč chceme jet…" rows={2}
                                    className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none"/>

                                <input value={form.website_url} onChange={e=>setForm(p=>({...p,website_url:e.target.value}))}
                                    placeholder="Web (https://…)" type="url"
                                    className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                                <div className="flex gap-2">
                                    <button type="submit" disabled={!form.name && !searchQuery}
                                        className="flex-1 bg-[var(--color-accent)] text-white text-xs py-1.5 rounded-lg hover:opacity-90 disabled:opacity-40">
                                        Přidat do itineráře
                                    </button>
                                    <button type="button" onClick={()=>setShowForm(false)}
                                        className="px-3 text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] rounded-lg hover:text-white">Zrušit</button>
                                </div>
                            </form>
                        </div>
                    )}

                    {/* Filter tabs */}
                    <div className="flex gap-1 px-3 py-2 border-b border-[var(--color-border)] shrink-0 overflow-x-auto">
                        {([['all','Vše'],['dream','✨ Sny'],['soon','🎯 Brzy'],['someday','🌍 Jednou'],['planned','📅 Plán'],['visited','✅ Splněno']] as const).map(([key,label]) => (
                            <button key={key} onClick={()=>setFilter(key as any)}
                                className={`px-2 py-1 rounded-lg text-[10px] whitespace-nowrap transition-colors ${filter===key?'bg-[var(--color-accent)] text-white':'text-[var(--color-text-secondary)] hover:text-white'}`}>
                                {label}
                            </button>
                        ))}
                    </div>

                    {/* Places list */}
                    <div className="flex-1 overflow-y-auto">
                        {filtered.length === 0 ? (
                            <div className="text-center py-8 text-[var(--color-text-secondary)]">
                                <Globe size={28} className="mx-auto mb-2 opacity-30"/>
                                <p className="text-xs">Žádná místa</p>
                            </div>
                        ) : filtered.map(place => (
                            <div key={place.id} onClick={() => flyToPlace(place)}
                                className={`border-b border-[var(--color-border)] cursor-pointer transition-colors ${selected?.id === place.id ? 'bg-[var(--color-bg-card)] border-l-2 border-l-[var(--color-accent)]' : 'hover:bg-[var(--color-bg-card)]'}`}>
                                <div className="px-3 py-2.5">
                                    <div className="flex items-start gap-2">
                                        <span className="text-base mt-0.5 shrink-0">{place.visited ? '✅' : (CAT_EMOJI[place.category]??'📍')}</span>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-xs font-medium text-white truncate">{place.name}</p>
                                            {place.country && <p className="text-[10px] text-[var(--color-text-secondary)]">{place.country}</p>}
                                            <p className="text-[10px]" style={{ color: place.visited ? '#22c55e' : PRIORITY_COLORS[place.priority] }}>
                                                {place.visited ? `✅ ${place.visited_at ?? 'Navštíveno'}` : PRIORITY_LABELS[place.priority]}
                                            </p>
                                        </div>
                                        <div className="flex gap-1 shrink-0" onClick={e => e.stopPropagation()}>
                                            <button onClick={() => toggleVisited(place)} title={place.visited ? 'Zrušit' : 'Označit navštíveno'}
                                                className="p-1 text-[var(--color-text-secondary)] hover:text-green-400 transition-colors">
                                                <CheckCircle size={13} className={place.visited ? 'text-green-400' : ''}/>
                                            </button>
                                            <button onClick={() => removePlace(place.id)} className="p-1 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors">
                                                <Trash2 size={13}/>
                                            </button>
                                        </div>
                                    </div>

                                    {/* Expanded detail panel */}
                                    {selected?.id === place.id && (
                                        <div className="mt-2 pt-2 border-t border-[var(--color-border)] space-y-2" onClick={e=>e.stopPropagation()}>

                                            {/* Priority picker */}
                                            <div className="flex gap-1">
                                                {(['dream','soon','someday'] as const).map(p => (
                                                    <button key={p} onClick={() => updatePlace(place.id, { priority: p })}
                                                        className={`flex-1 text-[9px] py-1 rounded-lg border transition-colors ${place.priority===p ? 'border-transparent text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}
                                                        style={place.priority===p ? { background: PRIORITY_COLORS[p] } : {}}>
                                                        {PRIORITY_LABELS[p]}
                                                    </button>
                                                ))}
                                            </div>

                                            {/* Planned date */}
                                            <div className="flex items-center gap-1.5">
                                                <CalendarClock size={10} className="text-[var(--color-text-secondary)] shrink-0"/>
                                                {editField === `planned_${place.id}` ? (
                                                    <div className="flex gap-1 flex-1">
                                                        <input type="date" value={editVal} onChange={e=>setEditVal(e.target.value)}
                                                            className="flex-1 bg-[var(--color-bg-secondary)] border border-[var(--color-accent)] rounded px-2 py-0.5 text-[10px] text-white outline-none"/>
                                                        <button onClick={() => updatePlace(place.id, { planned_date: editVal || undefined } as any)}
                                                            className="text-[10px] text-green-400 hover:text-green-300 px-1" disabled={saving}>
                                                            <Save size={10}/>
                                                        </button>
                                                        <button onClick={() => setEditField(null)} className="text-[10px] text-[var(--color-text-secondary)]"><X size={10}/></button>
                                                    </div>
                                                ) : (
                                                    <button onClick={() => { setEditField(`planned_${place.id}`); setEditVal(place.planned_date ?? ''); }}
                                                        className="flex items-center gap-1 text-[10px] text-[var(--color-text-secondary)] hover:text-white">
                                                        {place.planned_date
                                                            ? <><Calendar size={9}/> {new Date(place.planned_date).toLocaleDateString('cs-CZ')}</>
                                                            : <><Plus size={9}/> Přidat datum plánu</>
                                                        }
                                                        <Pencil size={8} className="ml-0.5 opacity-50"/>
                                                    </button>
                                                )}
                                            </div>

                                            {/* Notes */}
                                            {editField === `notes_${place.id}` ? (
                                                <div className="space-y-1">
                                                    <textarea value={editVal} onChange={e=>setEditVal(e.target.value)} rows={3} autoFocus
                                                        className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-accent)] rounded-lg px-2 py-1 text-[10px] text-white outline-none resize-none"/>
                                                    <div className="flex gap-1">
                                                        <button onClick={() => updatePlace(place.id, { notes: editVal })} disabled={saving}
                                                            className="flex items-center gap-1 text-[10px] bg-[var(--color-accent)] text-white px-2 py-0.5 rounded hover:opacity-90 disabled:opacity-40">
                                                            <Save size={9}/> Uložit
                                                        </button>
                                                        <button onClick={() => setEditField(null)} className="text-[10px] text-[var(--color-text-secondary)] px-1">Zrušit</button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <button onClick={() => { setEditField(`notes_${place.id}`); setEditVal(place.notes ?? ''); }}
                                                    className="w-full text-left text-[10px] text-[var(--color-text-secondary)] hover:text-white flex items-start gap-1">
                                                    <Pencil size={9} className="mt-0.5 shrink-0"/>
                                                    {place.notes ? <span className="italic">💬 {place.notes}</span> : <span className="opacity-60">Přidat poznámku…</span>}
                                                </button>
                                            )}

                                            {/* Description */}
                                            {place.description && (
                                                <p className="text-[10px] text-[var(--color-text-secondary)] leading-relaxed">{place.description}</p>
                                            )}

                                            {/* Coords + links */}
                                            {place.latitude && place.longitude && (
                                                <p className="text-[10px] text-[var(--color-text-secondary)]">📍 {Number(place.latitude).toFixed(4)}, {Number(place.longitude).toFixed(4)}</p>
                                            )}
                                            <div className="flex gap-2 flex-wrap">
                                                {place.website_url && (
                                                    <a href={place.website_url} target="_blank" rel="noopener noreferrer"
                                                        className="flex items-center gap-1 text-[10px] text-[var(--color-accent)] hover:underline">
                                                        <ExternalLink size={10}/> Web
                                                    </a>
                                                )}
                                                {place.latitude && place.longitude && (
                                                    <a href={`https://www.google.com/maps/search/?api=1&query=${place.latitude},${place.longitude}`} target="_blank" rel="noopener noreferrer"
                                                        className="flex items-center gap-1 text-[10px] text-[var(--color-text-secondary)] hover:text-white">
                                                        <MapPin size={10}/> Google Maps
                                                    </a>
                                                )}
                                            </div>

                                            {/* Nearby photos */}
                                            {place.latitude && place.longitude && (
                                                <div>
                                                    <p className="text-[9px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-1.5 flex items-center gap-1">
                                                        <Camera size={9}/> Fotky z okolí
                                                    </p>
                                                    {photosLoading ? (
                                                        <div className="flex gap-1">
                                                            {[1,2,3].map(i => <div key={i} className="w-12 h-12 rounded bg-[var(--color-bg-secondary)] animate-pulse"/>)}
                                                        </div>
                                                    ) : placePhotos.length > 0 ? (
                                                        <div className="flex flex-wrap gap-1">
                                                            {placePhotos.slice(0, 10).map(ph => (
                                                                <img key={ph.uuid} src={ph.thumbnail_url} alt=""
                                                                    className="w-12 h-12 object-cover rounded-md border border-[var(--color-border)] hover:opacity-80 cursor-pointer transition-opacity"
                                                                    onClick={() => window.open(`/media/${ph.uuid}`, '_blank')}
                                                                    title={ph.taken_at ? new Date(ph.taken_at).toLocaleDateString('cs-CZ') : ''}
                                                                />
                                                            ))}
                                                            {placePhotos.length > 10 && (
                                                                <div className="w-12 h-12 rounded-md bg-[var(--color-bg-secondary)] flex items-center justify-center text-[9px] text-[var(--color-text-secondary)] font-medium">
                                                                    +{placePhotos.length - 10}
                                                                </div>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <p className="text-[9px] text-[var(--color-text-secondary)] italic">Žádné fotky z tohoto místa</p>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                        ))}

                        {/* Planning timeline — shown in 'planned' filter */}
                        {filter === 'planned' && filtered.length > 0 && (
                            <div className="px-3 pt-3 pb-2">
                                <p className="text-[9px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-3 flex items-center gap-1">
                                    <CalendarClock size={9}/> Plánovaný itinerář
                                </p>
                                {[...filtered]
                                    .sort((a, b) => (a.planned_date ?? '9999') < (b.planned_date ?? '9999') ? -1 : 1)
                                    .map((place, idx) => (
                                        <div key={place.id} className="flex gap-2 mb-3 relative">
                                            {/* Timeline line */}
                                            {idx < filtered.length - 1 && (
                                                <div className="absolute left-[15px] top-[26px] w-0.5 h-full bg-[var(--color-border)]"/>
                                            )}
                                            <div className="w-8 h-8 shrink-0 rounded-full border-2 flex items-center justify-center text-sm z-10 bg-[var(--color-bg-secondary)]"
                                                style={{ borderColor: PRIORITY_COLORS[place.priority] }}>
                                                {CAT_EMOJI[place.category] ?? '📍'}
                                            </div>
                                            <div className="flex-1 min-w-0 bg-[var(--color-bg-card)] rounded-lg p-2">
                                                <p className="text-xs font-medium text-white truncate">{place.name}</p>
                                                {place.country && <p className="text-[9px] text-[var(--color-text-secondary)]">{place.country}</p>}
                                                {place.planned_date && (
                                                    <p className="text-[9px] text-[var(--color-accent)] flex items-center gap-1 mt-0.5">
                                                        <Calendar size={8}/> {new Date(place.planned_date).toLocaleDateString('cs-CZ', { day:'numeric', month:'long', year:'numeric' })}
                                                    </p>
                                                )}
                                                <p className="text-[9px] mt-0.5" style={{ color: PRIORITY_COLORS[place.priority] }}>{PRIORITY_LABELS[place.priority]}</p>
                                            </div>
                                        </div>
                                    ))}
                            </div>
                        )}

                        {/* GPS photo clusters in "visited" tab */}
                        {filter === 'visited' && visited.length > 0 && (
                            <div className="px-3 pt-4 pb-2">
                                <p className="text-[10px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Z fotek</p>
                                {visited.slice(0, 15).map((area, i) => (
                                    <div key={i} className="flex items-center gap-2 py-1.5 border-b border-[var(--color-border)] last:border-0">
                                        <span className="text-sm">📸</span>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[10px] font-mono text-[var(--color-text-secondary)] truncate">{Number(area.latitude).toFixed(2)}°, {Number(area.longitude).toFixed(2)}°</p>
                                            <p className="text-[10px] text-green-400">{area.photo_count} fotek</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Progress bar */}
                    {wishlist.length > 0 && (
                        <div className="p-3 border-t border-[var(--color-border)] shrink-0">
                            <div className="flex items-center justify-between text-[10px] text-[var(--color-text-secondary)] mb-1.5">
                                <span className="flex items-center gap-1"><Star size={10}/> Splněno</span>
                                <span>{visitedCount} / {wishlist.length}</span>
                            </div>
                            <div className="h-1.5 bg-[var(--color-bg-secondary)] rounded-full overflow-hidden">
                                <div className="h-full bg-green-500 rounded-full transition-all" style={{ width: `${(visitedCount/wishlist.length)*100}%` }}/>
                            </div>
                        </div>
                    )}
                </div>

                {/* ── Map ────────────────────────────────────────────────── */}
                <div className="flex-1 relative">
                    <div ref={mapRef} className="w-full h-full"/>
                    {!mapLoaded && (
                        <div className="absolute inset-0 flex items-center justify-center bg-[var(--color-bg-primary)]">
                            <div className="flex flex-col items-center gap-2 text-[var(--color-text-secondary)]">
                                <div className="w-6 h-6 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin"/>
                                <span className="text-sm">Načítám mapu světa…</span>
                            </div>
                        </div>
                    )}
                    {mapLoaded && (
                        <div className="absolute bottom-4 right-4 z-[1000] bg-[var(--color-bg-card)]/90 backdrop-blur-sm rounded-lg p-2 border border-[var(--color-border)] text-[10px] space-y-1">
                            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-full bg-green-400 inline-block opacity-60"/>Fotky z cest</div>
                            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-full bg-pink-400 inline-block"/>✨ Sen</div>
                            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-full bg-orange-400 inline-block"/>🎯 Brzy</div>
                            <div className="flex items-center gap-1.5"><span className="w-3 h-3 rounded-full bg-indigo-400 inline-block"/>🌍 Jednou</div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
