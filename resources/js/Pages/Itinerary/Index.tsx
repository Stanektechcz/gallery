import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle, Globe, MapPin, Plus, RefreshCw, Star, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface WishlistPlace {
    id: number; name: string; country?: string; country_code?: string;
    latitude?: number; longitude?: number; category: string;
    notes?: string; priority: 'dream' | 'soon' | 'someday';
    visited: boolean; visited_at?: string;
}
interface VisitedArea {
    latitude: number; longitude: number; photo_count: number;
    first_visit?: string; last_visit?: string;
}

const PRIORITY_COLORS = { dream: '#ec4899', soon: '#f97316', someday: '#6366f1' };
const PRIORITY_LABELS = { dream: '✨ Sen', soon: '🎯 Brzy', someday: '🌍 Jednou' };
const CATEGORIES = ['city','country','landmark','restaurant','museum','nature','other'];
const CAT_EMOJI: Record<string, string> = { city:'🏙️', country:'🌍', landmark:'🗺️', restaurant:'🍽️', museum:'🏛️', nature:'🌿', other:'📍' };

export default function ItineraryIndex() {
    const mapRef = useRef<HTMLDivElement>(null);
    const mapObj = useRef<any>(null);
    const [mapLoaded, setMapLoaded] = useState(false);
    const [wishlist, setWishlist] = useState<WishlistPlace[]>([]);
    const [visited,  setVisited]  = useState<VisitedArea[]>([]);
    const [stats,    setStats]    = useState<any>({});
    const [filter,   setFilter]   = useState<'all'|'dream'|'soon'|'someday'|'visited'>('all');
    const [showForm, setShowForm] = useState(false);
    const [checking, setChecking] = useState(false);
    const [form, setForm] = useState({ name:'', country:'', latitude:'', longitude:'', category:'city', priority:'someday', notes:'' });

    // Load data
    useEffect(() => {
        axios.get('/api/v1/itinerary').then(r => {
            setWishlist(r.data.wishlist ?? []);
            setVisited(r.data.visited_areas ?? []);
            setStats(r.data.stats ?? {});
        });
    }, []);

    // Build map
    useEffect(() => {
        if (!mapRef.current || !mapLoaded) return;
        const L = (window as any).L;
        if (!L) return;

        if (mapObj.current) { mapObj.current.remove(); mapObj.current = null; }

        const map = L.map(mapRef.current, { center: [20, 10], zoom: 2, zoomControl: true });
        mapObj.current = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors', maxZoom: 18,
        }).addTo(map);

        // Visited areas (from photos) — green circles
        visited.forEach(area => {
            const r = Math.min(20, 4 + Math.log(area.photo_count) * 3);
            L.circleMarker([area.latitude, area.longitude], {
                radius: r, fillColor: '#22c55e', color: '#16a34a',
                weight: 1, opacity: 0.9, fillOpacity: 0.6,
            }).addTo(map).bindPopup(`
                <div style="font-size:12px;min-width:120px">
                    <b>📸 ${area.photo_count} fotek</b><br/>
                    ${area.first_visit ? new Date(area.first_visit).toLocaleDateString('cs-CZ') : ''}<br/>
                    <small style="color:#888">${area.latitude.toFixed(4)}, ${area.longitude.toFixed(4)}</small>
                </div>
            `);
        });

        // Wishlist — colored markers
        wishlist.filter(p => p.latitude && p.longitude).forEach(place => {
            const color = place.visited ? '#22c55e' : PRIORITY_COLORS[place.priority] ?? '#6366f1';
            const icon = L.divIcon({
                className: '',
                html: `<div style="width:28px;height:28px;border-radius:50%;background:${color};border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;font-size:13px;cursor:pointer">
                    ${place.visited ? '✅' : (CAT_EMOJI[place.category] ?? '📍')}
                </div>`,
                iconSize: [28, 28], iconAnchor: [14, 14],
            });
            L.marker([place.latitude!, place.longitude!], { icon }).addTo(map).bindPopup(`
                <div style="font-size:12px;min-width:160px">
                    <b>${place.name}</b>${place.country ? ` · ${place.country}` : ''}<br/>
                    <span style="color:${color}">${PRIORITY_LABELS[place.priority]}</span>
                    ${place.visited ? `<br/><span style="color:#22c55e">✅ Navštíveno ${place.visited_at ?? ''}</span>` : ''}
                    ${place.notes ? `<br/><small style="color:#888">${place.notes}</small>` : ''}
                </div>
            `);
        });

        return () => { map.remove(); mapObj.current = null; };
    }, [mapLoaded, wishlist, visited]);

    // Load Leaflet
    useEffect(() => {
        if ((window as any).L) { setMapLoaded(true); return; }
        const link = document.createElement('link'); link.rel='stylesheet'; link.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(link);
        const script = document.createElement('script'); script.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; script.onload=()=>setMapLoaded(true); document.head.appendChild(script);
    }, []);

    const addPlace = async (e: React.FormEvent) => {
        e.preventDefault();
        const payload = {
            ...form,
            latitude:  form.latitude  ? parseFloat(form.latitude)  : undefined,
            longitude: form.longitude ? parseFloat(form.longitude) : undefined,
        };
        const r = await axios.post('/api/v1/itinerary', payload);
        setWishlist(prev => [r.data, ...prev]);
        setForm({ name:'', country:'', latitude:'', longitude:'', category:'city', priority:'someday', notes:'' });
        setShowForm(false);
    };

    const toggleVisited = async (place: WishlistPlace) => {
        const r = await axios.patch(`/api/v1/itinerary/${place.id}`, { visited: !place.visited });
        setWishlist(prev => prev.map(p => p.id === place.id ? r.data : p));
    };

    const removePlace = async (id: number) => {
        if (!confirm('Odebrat místo?')) return;
        await axios.delete(`/api/v1/itinerary/${id}`);
        setWishlist(prev => prev.filter(p => p.id !== id));
    };

    const autoCheck = async () => {
        setChecking(true);
        const r = await axios.post('/api/v1/itinerary/check-visited');
        if (r.data.auto_detected > 0) {
            const r2 = await axios.get('/api/v1/itinerary');
            setWishlist(r2.data.wishlist ?? []);
            alert(`Automaticky označeno jako navštíveno: ${r.data.auto_detected} míst!`);
        } else {
            alert('Žádné nové shody s fotografiemi nalezeny.');
        }
        setChecking(false);
    };

    const filtered = wishlist.filter(p => {
        if (filter === 'visited') return p.visited;
        if (filter === 'all') return true;
        return p.priority === filter && !p.visited;
    });

    const visitedCount = wishlist.filter(p => p.visited).length;
    const dreamCount   = wishlist.filter(p => p.priority === 'dream' && !p.visited).length;

    return (
        <AppLayout>
            <Head title="Světový itinerář" />

            <div className="flex h-full min-h-0">
                {/* Left panel */}
                <div className="w-80 shrink-0 flex flex-col border-r border-[var(--color-border)] overflow-hidden">
                    {/* Header */}
                    <div className="p-4 border-b border-[var(--color-border)] shrink-0">
                        <div className="flex items-center gap-2 mb-3">
                            <Globe size={18} className="text-[var(--color-accent)]" />
                            <h1 className="text-sm font-semibold text-white">Světový itinerář</h1>
                        </div>

                        {/* Stats */}
                        <div className="grid grid-cols-3 gap-2 mb-3">
                            <div className="bg-[var(--color-bg-card)] rounded-lg p-2 text-center">
                                <p className="text-lg font-bold text-white">{visited.length}</p>
                                <p className="text-[9px] text-[var(--color-text-secondary)]">navštíveno</p>
                            </div>
                            <div className="bg-[var(--color-bg-card)] rounded-lg p-2 text-center">
                                <p className="text-lg font-bold text-pink-400">{dreamCount}</p>
                                <p className="text-[9px] text-[var(--color-text-secondary)]">snů</p>
                            </div>
                            <div className="bg-[var(--color-bg-card)] rounded-lg p-2 text-center">
                                <p className="text-lg font-bold text-[var(--color-accent)]">{wishlist.length}</p>
                                <p className="text-[9px] text-[var(--color-text-secondary)]">celkem</p>
                            </div>
                        </div>

                        {/* Actions */}
                        <div className="flex gap-2">
                            <button onClick={() => setShowForm(v=>!v)}
                                className="flex-1 flex items-center justify-center gap-1.5 bg-[var(--color-accent)] text-white text-xs py-1.5 rounded-lg hover:opacity-90">
                                <Plus size={12}/> Přidat
                            </button>
                            <button onClick={autoCheck} disabled={checking}
                                className="flex items-center gap-1.5 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-xs px-2.5 py-1.5 rounded-lg transition-colors disabled:opacity-40"
                                title="Automaticky označit navštívená místa podle fotek">
                                <RefreshCw size={12} className={checking ? 'animate-spin' : ''}/> Auto
                            </button>
                        </div>
                    </div>

                    {/* Add form */}
                    {showForm && (
                        <form onSubmit={addPlace} className="p-3 border-b border-[var(--color-border)] shrink-0 bg-[var(--color-bg-secondary)] space-y-2">
                            <input required value={form.name} onChange={e=>setForm(p=>({...p,name:e.target.value}))}
                                placeholder="Název místa *" className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                            <input value={form.country} onChange={e=>setForm(p=>({...p,country:e.target.value}))}
                                placeholder="Země (např. Itálie)" className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                            <div className="grid grid-cols-2 gap-2">
                                <input type="number" step="any" value={form.latitude} onChange={e=>setForm(p=>({...p,latitude:e.target.value}))}
                                    placeholder="Šířka (lat)" className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                                <input type="number" step="any" value={form.longitude} onChange={e=>setForm(p=>({...p,longitude:e.target.value}))}
                                    placeholder="Délka (lng)" className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <select value={form.category} onChange={e=>setForm(p=>({...p,category:e.target.value}))}
                                    className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]">
                                    {CATEGORIES.map(c => <option key={c} value={c}>{CAT_EMOJI[c]} {c}</option>)}
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
                            <div className="flex gap-2">
                                <button type="submit" className="flex-1 bg-[var(--color-accent)] text-white text-xs py-1.5 rounded-lg hover:opacity-90">Uložit</button>
                                <button type="button" onClick={()=>setShowForm(false)} className="px-3 text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] rounded-lg">✕</button>
                            </div>
                        </form>
                    )}

                    {/* Filter tabs */}
                    <div className="flex gap-1 px-3 py-2 border-b border-[var(--color-border)] shrink-0 overflow-x-auto">
                        {[['all','Vše'],['dream','✨ Sny'],['soon','🎯 Brzy'],['someday','🌍 Jednou'],['visited','✅ Splněno']].map(([key,label]) => (
                            <button key={key} onClick={()=>setFilter(key as any)}
                                className={`px-2 py-1 rounded-lg text-[10px] whitespace-nowrap transition-colors ${filter===key?'bg-[var(--color-accent)] text-white':'text-[var(--color-text-secondary)] hover:text-white'}`}>
                                {label}
                            </button>
                        ))}
                    </div>

                    {/* List */}
                    <div className="flex-1 overflow-y-auto p-2">
                        {filtered.length === 0 ? (
                            <div className="text-center py-8 text-[var(--color-text-secondary)]">
                                <MapPin size={28} className="mx-auto mb-2 opacity-30"/>
                                <p className="text-xs">Prázdný seznam</p>
                                <p className="text-[10px] mt-1">Přidejte svá vysněná místa</p>
                            </div>
                        ) : filtered.map(place => (
                            <div key={place.id}
                                className={`flex items-start gap-2 p-2.5 rounded-xl mb-1 border transition-all cursor-default ${place.visited ? 'bg-green-500/5 border-green-500/20' : 'bg-[var(--color-bg-card)] border-[var(--color-border)] hover:border-[var(--color-accent)]/30'}`}>
                                <div className="w-7 h-7 rounded-full flex items-center justify-center text-base shrink-0"
                                    style={{ backgroundColor: (place.visited ? '#22c55e' : PRIORITY_COLORS[place.priority]) + '22' }}>
                                    {place.visited ? '✅' : CAT_EMOJI[place.category]}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className={`text-xs font-medium truncate ${place.visited ? 'line-through text-[var(--color-text-secondary)]' : 'text-white'}`}>{place.name}</p>
                                    {place.country && <p className="text-[10px] text-[var(--color-text-secondary)]">{place.country}</p>}
                                    {place.visited && place.visited_at && (
                                        <p className="text-[10px] text-green-400 mt-0.5">
                                            {new Date(place.visited_at).toLocaleDateString('cs-CZ')}
                                        </p>
                                    )}
                                    {!place.visited && (
                                        <span className="inline-block text-[9px] px-1.5 py-0.5 rounded-full mt-0.5"
                                            style={{ backgroundColor: PRIORITY_COLORS[place.priority] + '22', color: PRIORITY_COLORS[place.priority] }}>
                                            {PRIORITY_LABELS[place.priority]}
                                        </span>
                                    )}
                                    {place.notes && <p className="text-[10px] text-[var(--color-text-secondary)] mt-1 truncate">{place.notes}</p>}
                                </div>
                                <div className="flex flex-col gap-1 shrink-0">
                                    <button onClick={()=>toggleVisited(place)} title={place.visited ? 'Zrušit splnění' : 'Označit jako navštíveno'}
                                        className={`w-5 h-5 rounded-full flex items-center justify-center transition-colors ${place.visited ? 'bg-green-500/20 text-green-400 hover:bg-red-500/20 hover:text-red-400' : 'text-[var(--color-text-secondary)] hover:text-green-400'}`}>
                                        <CheckCircle size={12}/>
                                    </button>
                                    <button onClick={()=>removePlace(place.id)} className="w-5 h-5 rounded-full flex items-center justify-center text-[var(--color-text-secondary)] hover:text-red-400 transition-colors">
                                        <Trash2 size={11}/>
                                    </button>
                                </div>
                            </div>
                        ))}

                        {/* Photo-based visited areas */}
                        {filter === 'visited' && visited.length > 0 && (
                            <div className="mt-4">
                                <p className="text-[10px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 px-1">Z fotografií</p>
                                {visited.slice(0, 20).map((area, i) => (
                                    <div key={i} className="flex items-center gap-2 px-2.5 py-2 rounded-xl mb-1 bg-green-500/5 border border-green-500/20">
                                        <div className="w-6 h-6 rounded-full bg-green-500/20 flex items-center justify-center text-xs">📸</div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[10px] font-mono text-[var(--color-text-secondary)] truncate">
                                                {area.latitude.toFixed(2)}°, {area.longitude.toFixed(2)}°
                                            </p>
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
                                <div className="h-full bg-green-500 rounded-full transition-all"
                                    style={{ width: `${wishlist.length > 0 ? (visitedCount/wishlist.length)*100 : 0}%` }}/>
                            </div>
                        </div>
                    )}
                </div>

                {/* Map */}
                <div className="flex-1 relative">
                    <div ref={mapRef} className="w-full h-full" />
                    {!mapLoaded && (
                        <div className="absolute inset-0 flex items-center justify-center bg-[var(--color-bg-primary)]">
                            <div className="flex flex-col items-center gap-2 text-[var(--color-text-secondary)]">
                                <div className="w-6 h-6 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin"/>
                                <span className="text-sm">Načítám mapu světa…</span>
                            </div>
                        </div>
                    )}
                    {/* Legend */}
                    {mapLoaded && (
                        <div className="absolute bottom-4 right-4 bg-[var(--color-bg-secondary)]/90 backdrop-blur rounded-xl p-3 text-xs space-y-1.5">
                            <div className="flex items-center gap-2"><div className="w-3 h-3 rounded-full bg-green-500 opacity-70"/><span className="text-[var(--color-text-secondary)]">Navštíveno (fotky)</span></div>
                            <div className="flex items-center gap-2"><div className="w-3 h-3 rounded-full bg-pink-400"/><span className="text-[var(--color-text-secondary)]">Sen</span></div>
                            <div className="flex items-center gap-2"><div className="w-3 h-3 rounded-full bg-orange-400"/><span className="text-[var(--color-text-secondary)]">Brzy</span></div>
                            <div className="flex items-center gap-2"><div className="w-3 h-3 rounded-full bg-indigo-400"/><span className="text-[var(--color-text-secondary)]">Jednou</span></div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
