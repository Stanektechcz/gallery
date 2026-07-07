import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Calendar, Camera, GripVertical, MapPin, Plus, RefreshCw, Route, Search, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type TransportMode = 'car'|'train'|'bus'|'plane'|'walk'|'bike'|'boat';
const TRANSPORT: Record<TransportMode, { label: string; icon: string; speed: number }> = {
    car:   { label: 'Auto',    icon: '🚗', speed: 80  },
    train: { label: 'Vlak',    icon: '🚂', speed: 120 },
    bus:   { label: 'Autobus', icon: '🚌', speed: 70  },
    plane: { label: 'Letadlo', icon: '✈️', speed: 800 },
    walk:  { label: 'Pěšky',  icon: '🚶', speed: 5   },
    bike:  { label: 'Kolo',    icon: '🚲', speed: 20  },
    boat:  { label: 'Loď',     icon: '⛴️', speed: 30  },
};

function haversine(lat1: number, lon1: number, lat2: number, lon2: number): number {
    const R = 6371, dLat = (lat2 - lat1) * Math.PI / 180, dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}
function fmtDist(km: number): string {
    return km < 1 ? `${Math.round(km*1000)}m` : km < 10 ? `${km.toFixed(1)}km` : `${Math.round(km)}km`;
}
function fmtTime(hours: number): string {
    const h = Math.floor(hours), m = Math.round((hours-h)*60);
    if (h === 0) return `${m}min`;
    if (m === 0) return `${h}h`;
    return `${h}h${m.toString().padStart(2,'0')}`;
}

interface Waypoint {
    id: number; place_name: string; latitude?: number; longitude?: number;
    sort_order: number; notes?: string; arrived_at?: string; departed_at?: string;
    transport_mode?: TransportMode; duration_override?: number;
}
interface OsrmRoute { distance_km: number; duration_min: number; source?: string; }
interface Trip {
    id: number; name: string; description?: string; notes?: string;
    start_date: string; end_date: string; duration_days: number;
    media_count: number; cover_thumb?: string; waypoints: Waypoint[];
}
interface TripMedia {
    id: number; uuid: string; thumbnail_url: string;
    taken_at: string; latitude?: number; longitude?: number;
}
interface Suggestion { count: number; samples: TripMedia[]; all_ids: number[]; }
interface SearchResult {
    name: string; display_name: string; country: string;
    latitude: number; longitude: number; category: string;
}

const MONTHS_CS = ['ledna','února','března','dubna','května','června','července','srpna','září','října','listopadu','prosince'];

// ── Price estimation (calibrated to CZ/SK market 2025) ─────────────────────
interface PriceEstimate {
    carrier: string; icon: string; minPrice: number; maxPrice?: number;
    currency: string; note?: string; bookUrl?: string;
}
function estimatePrices(km: number, from: string, to: string, isoDate: string): PriceEstimate[] {
    if (!km || km < 8) return [];
    const f = encodeURIComponent(from), t = encodeURIComponent(to);
    const r: PriceEstimate[] = [];

    // ČD (eJízdenka with early purchase ~35% off, base ~1.2 Kč/km)
    const cdBase = Math.max(30, Math.round(1.2 * km / 10) * 10);
    r.push({ carrier: 'České dráhy', icon: '🚂', currency: 'Kč',
        minPrice: Math.round(cdBase * 0.65 / 5) * 5,
        maxPrice: cdBase,
        note: 'eJízdenka',
        bookUrl: `https://idos.idnes.cz/vlak/spojeni/?f=${f}&t=${t}&date=${isoDate}&time=0600`,
    });

    // RegioJet Bus (~0.58 Kč/km, min 49 Kč)
    const rjBus = Math.max(49, Math.round(0.58 * km / 5) * 5);
    r.push({ carrier: 'RegioJet Bus', icon: '🟡', currency: 'Kč',
        minPrice: rjBus,
        maxPrice: Math.round(rjBus * 1.4 / 5) * 5,
        bookUrl: `https://www.regiojet.cz/vlaky-a-autobusy/jizdenky-online/?f=${from}&t=${to}&date=${isoDate}`,
    });

    // RegioJet vlak (~0.9 Kč/km, min 79 Kč) — only if plausible route
    if (km > 25) {
        const rjTrain = Math.max(79, Math.round(0.9 * km / 5) * 5);
        r.push({ carrier: 'RegioJet vlak', icon: '🟡', currency: 'Kč',
            minPrice: rjTrain,
            maxPrice: Math.round(rjTrain * 1.3 / 5) * 5,
            bookUrl: `https://www.regiojet.cz/vlaky-a-autobusy/jizdenky-online/?f=${from}&t=${to}&date=${isoDate}`,
        });
    }

    // FlixBus (~0.5 Kč/km, min 99 Kč — often flash sales)
    if (km > 70) {
        const flix = Math.max(99, Math.round(0.5 * km / 5) * 5);
        r.push({ carrier: 'FlixBus', icon: '🟢', currency: 'Kč',
            minPrice: Math.round(flix * 0.55 / 5) * 5,
            maxPrice: Math.round(flix * 1.8 / 5) * 5,
            note: 'flash výprodeje',
            bookUrl: `https://shop.flixbus.cz/search?departureCity=${f}&arrivalCity=${t}&rideDate=${isoDate}&adult=1`,
        });
    }

    // Car per person (2 people sharing, 7L/100km × 42 Kč/L)
    const carPP = Math.max(30, Math.round(1.47 * km / 10) * 10);
    r.push({ carrier: 'Auto /os. (2 os.)', icon: '🚗', currency: 'Kč',
        minPrice: carPP,
        note: 'palivo',
        bookUrl: `https://www.google.com/maps/dir/${encodeURIComponent(from)}/${encodeURIComponent(to)}`,
    });

    // Plane — only for long routes
    if (km > 350) {
        r.push({ carrier: 'Letadlo', icon: '✈️', currency: 'EUR',
            minPrice: Math.round(Math.max(30, km * 0.06) / 5) * 5,
            maxPrice: Math.round(Math.max(80, km * 0.18) / 5) * 5,
            note: 'low-cost',
            bookUrl: `https://www.skyscanner.cz/letiste/${f}/${t}/${isoDate.replace(/-/g,'')}/1adults/`,
        });
    }

    return r.sort((a, b) => a.minPrice - b.minPrice).slice(0, 4);
}

function fmtRange(start: string, end: string): string {
    const s = new Date(start), e = new Date(end);
    if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear()) {
        return `${s.getDate()}. – ${e.getDate()}. ${MONTHS_CS[e.getMonth()]} ${e.getFullYear()}`;
    }
    return `${s.getDate()}. ${MONTHS_CS[s.getMonth()]} – ${e.getDate()}. ${MONTHS_CS[e.getMonth()]} ${e.getFullYear()}`;
}

function buildTransportLinks(from: Waypoint, to: Waypoint, tripDate: string) {
    const f = encodeURIComponent(from.place_name);
    const t = encodeURIComponent(to.place_name);
    const iso = tripDate.substring(0, 10);
    const [y, mo, d] = iso.split('-');
    const czDate = `${d}.${mo}.${y}`;
    const fLat = from.latitude, fLng = from.longitude;
    const tLat = to.latitude,   tLng = to.longitude;
    return [
        { label: 'ČD / IDOS',   icon: '🚂', color: '#e31e24', url: `https://idos.idnes.cz/vlak/spojeni/?f=${f}&t=${t}&date=${iso}&time=0600` },
        { label: 'RegioJet',    icon: '🟡', color: '#f5c400', url: `https://www.regiojet.cz/vlaky-a-autobusy/jizdenky-online/?f=${from.place_name}&t=${to.place_name}&date=${iso}` },
        { label: 'FlixBus',     icon: '🟢', color: '#73d700', url: `https://shop.flixbus.cz/search?departureCity=${f}&arrivalCity=${t}&rideDate=${iso}&adult=1` },
        { label: 'Google Maps', icon: '🗺️', color: '#4285f4',
            url: fLat && fLng && tLat && tLng
                ? `https://www.google.com/maps/dir/${fLat},${fLng}/${tLat},${tLng}/`
                : `https://www.google.com/maps/dir/?api=1&origin=${f}&destination=${t}&travelmode=driving` },
        { label: 'Waze',        icon: '🔵', color: '#33ccff',
            url: tLat && tLng ? `https://waze.com/ul?navigate=yes&to=${tLat},${tLng}&from=${fLat},${fLng}` : null },
        { label: 'Mapy.cz',    icon: '📍', color: '#e25400',
            url: fLat && fLng && tLat && tLng
                ? `https://mapy.cz/zakladni?planovani-trasy&planovani[0][x]=${fLng}&planovani[0][y]=${fLat}&planovani[1][x]=${tLng}&planovani[1][y]=${tLat}`
                : `https://mapy.cz/` },
        { label: 'Skyscanner',  icon: '✈️', color: '#0770e3', url: `https://www.skyscanner.cz/letiste/${f}/${t}/${iso.replace(/-/g, '')}/1adults/` },
        { label: 'IDOS autobus',icon: '🚌', color: '#e07000', url: `https://idos.idnes.cz/autobus/spojeni/?f=${f}&t=${t}&date=${iso}&time=0600` },
    ].filter(l => l.url !== null) as {label:string;icon:string;color:string;url:string}[];
}

export default function TripsIndex() {
    const mapRef       = useRef<HTMLDivElement>(null);
    const mapObj       = useRef<any>(null);
    const searchTimer  = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [trips,       setTrips]       = useState<Trip[]>([]);
    const [loading,     setLoading]     = useState(true);
    const [selectedId,  setSelectedId]  = useState<number | null>(null);
    const [tripMedia,   setTripMedia]   = useState<TripMedia[]>([]);
    const [suggestion,  setSuggestion]  = useState<Suggestion | null>(null);
    const [loadingMedia, setLoadingMedia] = useState(false);
    const [addingMedia,  setAddingMedia]  = useState(false);
    const [mapLoaded,   setMapLoaded]   = useState(false);

    // Create trip form
    const [showCreate, setShowCreate] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', start_date: '', end_date: '', description: '' });
    const [creating,   setCreating]   = useState(false);

    // Waypoint form + autocomplete
    const [showWpForm, setShowWpForm] = useState(false);
    const [wpSearch,   setWpSearch]   = useState('');
    const [wpResults,  setWpResults]  = useState<SearchResult[]>([]);
    const [wpLoading,  setWpLoading]  = useState(false);
    const [wpDropdown, setWpDropdown] = useState(false);
    const [wpForm,     setWpForm]     = useState({ place_name: '', latitude: '', longitude: '' });

    const [editNotes, setEditNotes] = useState(false);
    const [notesVal,  setNotesVal]  = useState('');
    const [savingNotes, setSavingNotes] = useState(false);
    const notesSaveTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    // Drag-and-drop reorder state
    const dragIdx     = useRef<number | null>(null);
    const [dragOver,  setDragOver]  = useState<number | null>(null);

    // Transport mode + OSRM routing
    const [editLegIdx,  setEditLegIdx]  = useState<number | null>(null);
    const [osrmRoutes,  setOsrmRoutes]  = useState<Record<string, OsrmRoute>>({});
    const [fetchingLegs, setFetchingLegs] = useState<Record<string, boolean>>({});
    const [wpError,     setWpError]     = useState<string | null>(null);

    const selected = trips.find(t => t.id === selectedId) ?? null;

    // Load trip list
    useEffect(() => {
        axios.get('/api/v1/trips').then(r => setTrips(r.data ?? [])).finally(() => setLoading(false));
    }, []);

    // Load media + suggest when trip changes
    useEffect(() => {
        if (!selectedId) { setTripMedia([]); setSuggestion(null); return; }
        setEditNotes(false);
        setLoadingMedia(true); setTripMedia([]); setSuggestion(null);
        Promise.all([
            axios.get(`/api/v1/trips/${selectedId}/media`),
            axios.get(`/api/v1/trips/${selectedId}/suggest-media`),
        ]).then(([mR, sR]) => {
            setTripMedia(mR.data ?? []);
            if (sR.data?.count > 0) setSuggestion(sR.data);
        }).finally(() => setLoadingMedia(false));
    }, [selectedId]);

    // Rebuild Leaflet map when selection/media changes
    useEffect(() => {
        if (!mapRef.current || !mapLoaded || !selected) return;
        const L = (window as any).L;
        if (!L) return;

        if (mapObj.current) { mapObj.current.remove(); mapObj.current = null; }
        const map = L.map(mapRef.current, { zoomControl: true });
        mapObj.current = map;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 18 }).addTo(map);

        const bounds: [number, number][] = [];

        // Waypoints with coords
        const wpsGps = selected.waypoints.filter(w => w.latitude && w.longitude);
        if (wpsGps.length >= 2) {
            L.polyline(wpsGps.map(w => [w.latitude!, w.longitude!]), {
                color: '#6366f1', weight: 3, opacity: 0.85, dashArray: '8 5',
            }).addTo(map);
        }
        wpsGps.forEach((wp, idx) => {
            const icon = L.divIcon({
                className: '',
                html: `<div style="width:26px;height:26px;border-radius:50%;background:#6366f1;border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:white">${idx + 1}</div>`,
                iconSize: [26, 26], iconAnchor: [13, 13],
            });
            L.marker([wp.latitude!, wp.longitude!], { icon })
                .addTo(map).bindPopup(`<b>${wp.place_name}</b>${wp.arrived_at ? `<br/><small>${wp.arrived_at}</small>` : ''}`);
            bounds.push([wp.latitude!, wp.longitude!]);
        });

        // Photo GPS dots
        tripMedia.filter(m => m.latitude && m.longitude).forEach(m => {
            L.circleMarker([m.latitude!, m.longitude!], {
                radius: 4, fillColor: '#22c55e', color: '#16a34a', weight: 1, fillOpacity: 0.7,
            }).addTo(map);
            bounds.push([m.latitude!, m.longitude!]);
        });

        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [32, 32] });
        } else {
            map.setView([48.5, 15], 5);
        }

        return () => { map.remove(); mapObj.current = null; };
    }, [mapLoaded, selected, tripMedia]);

    // Load Leaflet
    useEffect(() => {
        if ((window as any).L) { setMapLoaded(true); return; }
        const link = document.createElement('link'); link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(link);
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => setMapLoaded(true); document.head.appendChild(script);
    }, []);

    // Waypoint autocomplete (reuses itinerary Nominatim proxy)
    const handleWpSearch = useCallback((val: string) => {
        setWpSearch(val);
        setWpForm(p => ({ ...p, place_name: val }));
        if (searchTimer.current) clearTimeout(searchTimer.current);
        if (val.length < 2) { setWpResults([]); setWpDropdown(false); return; }
        searchTimer.current = setTimeout(async () => {
            setWpLoading(true);
            try {
                const r = await axios.get('/api/v1/itinerary/search', { params: { q: val } });
                setWpResults(r.data ?? []); setWpDropdown(true);
            } catch { /* ignore */ }
            finally { setWpLoading(false); }
        }, 400);
    }, []);

    const selectWpResult = (r: SearchResult) => {
        setWpSearch(r.name || r.display_name);
        setWpForm({ place_name: r.name || r.display_name, latitude: r.latitude.toString(), longitude: r.longitude.toString() });
        setWpResults([]); setWpDropdown(false);
    };

    // Actions
    const createTrip = async (e: React.FormEvent) => {
        e.preventDefault(); setCreating(true);
        try {
            const r = await axios.post('/api/v1/trips', createForm);
            setTrips(prev => [r.data, ...prev]);
            setSelectedId(r.data.id);
            setCreateForm({ name: '', start_date: '', end_date: '', description: '' }); setShowCreate(false);
        } finally { setCreating(false); }
    };

    const deleteTrip = async (id: number) => {
        if (!confirm('Smazat cestu? Fotografie zůstanou v galerii.')) return;
        await axios.delete(`/api/v1/trips/${id}`);
        setTrips(prev => prev.filter(t => t.id !== id));
        if (selectedId === id) { setSelectedId(null); }
    };

    const addWaypoint = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedId || !wpForm.place_name) return;
        setWpError(null);
        try {
            const r = await axios.post(`/api/v1/trips/${selectedId}/waypoints`, {
                place_name: wpForm.place_name,
                latitude:   wpForm.latitude ? parseFloat(wpForm.latitude) : undefined,
                longitude:  wpForm.longitude ? parseFloat(wpForm.longitude) : undefined,
            });
            setTrips(prev => prev.map(t => t.id === selectedId ? { ...t, waypoints: [...t.waypoints, r.data] } : t));
            setWpForm({ place_name: '', latitude: '', longitude: '' }); setWpSearch(''); setShowWpForm(false);
        } catch (err: any) {
            const msg = err?.response?.data?.message ?? err?.response?.data?.error ?? 'Chyba ukládání';
            if (msg.includes('migrate') || err?.response?.status === 503) {
                setWpError('⚠️ Server potřebuje spustit: php artisan migrate');
            } else {
                setWpError(`Chyba: ${msg}`);
            }
        }
    };

    const removeWaypoint = async (wpId: number) => {
        if (!selectedId) return;
        await axios.delete(`/api/v1/trips/${selectedId}/waypoints/${wpId}`);
        setTrips(prev => prev.map(t => t.id === selectedId
            ? { ...t, waypoints: t.waypoints.filter(w => w.id !== wpId) } : t));
    };

    const addAllMedia = async () => {
        if (!selectedId || !suggestion) return;
        setAddingMedia(true);
        try {
            await axios.post(`/api/v1/trips/${selectedId}/media`, { media_ids: suggestion.all_ids });
            const r = await axios.get(`/api/v1/trips/${selectedId}/media`);
            setTripMedia(r.data ?? []);
            setTrips(prev => prev.map(t => t.id === selectedId ? { ...t, media_count: r.data.length } : t));
            setSuggestion(null);
        } finally { setAddingMedia(false); }
    };

    const saveNotes = async (val: string) => {
        if (!selectedId) return;
        setSavingNotes(true);
        try {
            await axios.patch(`/api/v1/trips/${selectedId}`, { notes: val });
            setTrips(prev => prev.map(t => t.id === selectedId ? { ...t, notes: val } : t));
        } finally { setSavingNotes(false); }
    };

    // Auto-save notes with debounce (500ms after last keystroke) + immediate on blur
    const handleNotesChange = (val: string) => {
        setNotesVal(val);
        if (notesSaveTimer.current) clearTimeout(notesSaveTimer.current);
        notesSaveTimer.current = setTimeout(() => saveNotes(val), 800);
    };
    const handleNotesBlur = (val: string) => {
        if (notesSaveTimer.current) { clearTimeout(notesSaveTimer.current); notesSaveTimer.current = null; }
        saveNotes(val);
    };

    const updateWaypointMode = async (wpId: number, mode: TransportMode | null, durOverride?: number) => {
        if (!selectedId) return;
        const payload: Record<string, any> = { transport_mode: mode };
        if (durOverride !== undefined) payload.duration_override = durOverride;
        const r = await axios.patch(`/api/v1/trips/${selectedId}/waypoints/${wpId}`, payload);
        setTrips(prev => prev.map(t => t.id === selectedId
            ? { ...t, waypoints: t.waypoints.map(w => w.id === wpId ? { ...w, ...r.data } : w) }
            : t));
        // If mode changed to routable, fetch OSRM
        if (mode && ['car','walk','bike'].includes(mode)) {
            const wps = trips.find(t => t.id === selectedId)?.waypoints ?? [];
            const idx = wps.findIndex(w => w.id === wpId);
            if (idx > 0) {
                const prev = wps[idx - 1];
                if (prev.latitude && prev.longitude && wps[idx].latitude) {
                    const osrmMode = mode === 'car' ? 'driving' : mode === 'walk' ? 'walking' : 'cycling';
                    fetchLegRoute(prev, wps[idx], osrmMode);
                }
            }
        }
    };

    // Fetch OSRM road distance for a single leg
    const fetchLegRoute = useCallback(async (from: Waypoint, to: Waypoint, osrmMode: 'driving'|'walking'|'cycling') => {
        if (!from.latitude || !from.longitude || !to.latitude || !to.longitude) return;
        const key = `${from.id}_${to.id}_${osrmMode}`;
        setFetchingLegs(prev => ({ ...prev, [key]: true }));
        try {
            const r = await axios.get('/api/v1/trips/route-distance', {
                params: { from_lat: from.latitude, from_lng: from.longitude, to_lat: to.latitude, to_lng: to.longitude, mode: osrmMode },
            });
            setOsrmRoutes(prev => ({ ...prev, [key]: r.data }));
        } catch { /* OSRM unavailable — fall back to Haversine */ }
        finally { setFetchingLegs(prev => ({ ...prev, [key]: false })); }
    }, []);

    // Auto-fetch OSRM routes when selection changes
    useEffect(() => {
        if (!selected) return;
        selected.waypoints.forEach((wp, i) => {
            if (i === 0) return;
            const prev = selected.waypoints[i - 1];
            const mode = wp.transport_mode;
            if (!mode || !['car','walk','bike'].includes(mode)) return;
            const osrmMode = mode === 'car' ? 'driving' : mode === 'walk' ? 'walking' : 'cycling';
            const key = `${prev.id}_${wp.id}_${osrmMode}`;
            if (!osrmRoutes[key]) fetchLegRoute(prev, wp, osrmMode);
        });
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selected?.id, selected?.waypoints.map(w => `${w.id}:${w.transport_mode}`).join(',')]);

    // Drag-and-drop handlers
    const handleDragStart = (idx: number) => { dragIdx.current = idx; };
    const handleDragOver = (e: React.DragEvent, idx: number) => {
        e.preventDefault(); setDragOver(idx);
    };
    const handleDrop = async (e: React.DragEvent, toIdx: number) => {
        e.preventDefault(); setDragOver(null);
        if (!selectedId || dragIdx.current === null || dragIdx.current === toIdx) return;
        const from = dragIdx.current; dragIdx.current = null;
        const wps = [...(selected?.waypoints ?? [])];
        const [moved] = wps.splice(from, 1);
        wps.splice(toIdx, 0, moved);
        // Optimistic update
        setTrips(prev => prev.map(t => t.id === selectedId ? { ...t, waypoints: wps } : t));
        await axios.put(`/api/v1/trips/${selectedId}/waypoints/reorder`, { order: wps.map(w => w.id) });
    };

    // Compute per-leg stats (OSRM when available, Haversine fallback)
    const routeLegs = (selected?.waypoints ?? []).reduce<Array<{
        km: number|null; mode: TransportMode|null; time: number|null; osrm: OsrmRoute|null; fetching: boolean;
    }>>((acc, wp, i) => {
        if (i === 0) return acc;
        const prev = selected!.waypoints[i - 1];
        const hasPrev = prev.latitude && prev.longitude;
        const hasCur  = wp.latitude  && wp.longitude;
        const km      = (hasPrev && hasCur) ? haversine(prev.latitude!, prev.longitude!, wp.latitude!, wp.longitude!) : null;
        const mode    = wp.transport_mode ?? null;
        const osrmMode = mode === 'car' ? 'driving' : mode === 'walk' ? 'walking' : mode === 'bike' ? 'cycling' : null;
        const osrmKey  = osrmMode ? `${prev.id}_${wp.id}_${osrmMode}` : null;
        const osrm     = osrmKey ? (osrmRoutes[osrmKey] ?? null) : null;
        const fetching = osrmKey ? (fetchingLegs[osrmKey] ?? false) : false;
        const speed    = mode ? TRANSPORT[mode].speed : null;
        const time     = wp.duration_override
            ? wp.duration_override / 60
            : osrm ? osrm.duration_min / 60
            : (km && speed) ? km / speed : null;
        acc.push({ km, mode, time, osrm, fetching });
        return acc;
    }, []);
    const totalKm   = routeLegs.every(l => l.km   !== null) ? routeLegs.reduce((s, l) => s + l.km!,   0) : null;
    const totalTime = routeLegs.every(l => l.time  !== null) ? routeLegs.reduce((s, l) => s + l.time!, 0) : null;

    return (
        <AppLayout>
            <Head title="Cesty" />
            <div className="flex h-full min-h-0">

                {/* ── Left: trip list ──────────────────────────────────── */}
                <div className="w-72 shrink-0 flex flex-col border-r border-[var(--color-border)] overflow-hidden">

                    {/* Header */}
                    <div className="p-4 border-b border-[var(--color-border)] shrink-0">
                        <div className="flex items-center justify-between mb-2">
                            <h1 className="text-sm font-semibold text-white flex items-center gap-2">
                                <Route size={16} className="text-[var(--color-accent)]"/> Cesty
                            </h1>
                            <button onClick={() => setShowCreate(v => !v)}
                                className="flex items-center gap-1 bg-[var(--color-accent)] text-white text-xs px-2.5 py-1.5 rounded-lg hover:opacity-90">
                                <Plus size={12}/> Nová
                            </button>
                        </div>
                        <p className="text-[10px] text-[var(--color-text-secondary)]">
                            {trips.length} {trips.length === 1 ? 'cesta' : trips.length < 5 ? 'cesty' : 'cest'}
                        </p>
                    </div>

                    {/* Create form */}
                    {showCreate && (
                        <form onSubmit={createTrip} className="p-3 border-b border-[var(--color-border)] space-y-2 bg-[var(--color-bg-secondary)] shrink-0">
                            <input required value={createForm.name} onChange={e => setCreateForm(p => ({ ...p, name: e.target.value }))}
                                placeholder="Název cesty *" autoFocus
                                className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <label className="text-[9px] text-[var(--color-text-secondary)] block mb-0.5">Od</label>
                                    <input required type="date" value={createForm.start_date} onChange={e => setCreateForm(p => ({ ...p, start_date: e.target.value }))}
                                        className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]"/>
                                </div>
                                <div>
                                    <label className="text-[9px] text-[var(--color-text-secondary)] block mb-0.5">Do</label>
                                    <input required type="date" value={createForm.end_date} onChange={e => setCreateForm(p => ({ ...p, end_date: e.target.value }))}
                                        className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-xs text-white outline-none focus:border-[var(--color-accent)]"/>
                                </div>
                            </div>
                            <textarea value={createForm.description} onChange={e => setCreateForm(p => ({ ...p, description: e.target.value }))}
                                placeholder="Popis (volitelně)…" rows={2}
                                className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none"/>
                            <div className="flex gap-2">
                                <button type="submit" disabled={creating}
                                    className="flex-1 bg-[var(--color-accent)] text-white text-xs py-1.5 rounded-lg hover:opacity-90 disabled:opacity-40">
                                    {creating ? 'Vytvářím…' : 'Vytvořit cestu'}
                                </button>
                                <button type="button" onClick={() => setShowCreate(false)}
                                    className="px-3 text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] rounded-lg hover:text-white">Zrušit</button>
                            </div>
                        </form>
                    )}

                    {/* Trip list */}
                    <div className="flex-1 overflow-y-auto">
                        {loading ? (
                            <div className="space-y-3 p-3">
                                {[1,2,3].map(i => <div key={i} className="h-20 bg-[var(--color-bg-card)] rounded-xl animate-pulse"/>)}
                            </div>
                        ) : trips.length === 0 ? (
                            <div className="p-6 text-center text-[var(--color-text-secondary)]">
                                <Route size={28} className="mx-auto mb-2 opacity-30"/>
                                <p className="text-xs">Žádné cesty zatím</p>
                                <p className="text-[10px] mt-1">Vytvořte svou první cestu tlačítkem Nová</p>
                            </div>
                        ) : trips.map(trip => (
                            <div key={trip.id}
                                onClick={() => setSelectedId(trip.id === selectedId ? null : trip.id)}
                                className={`border-b border-[var(--color-border)] cursor-pointer transition-colors group ${selectedId === trip.id ? 'bg-[var(--color-bg-card)] border-l-2 border-l-[var(--color-accent)]' : 'hover:bg-[var(--color-bg-card)]'}`}>
                                <div className="flex gap-3 p-3">
                                    {/* Cover thumbnail */}
                                    <div className="w-14 h-14 shrink-0 rounded-lg overflow-hidden bg-[var(--color-bg-secondary)]">
                                        {trip.cover_thumb
                                            ? <img src={trip.cover_thumb} alt="" className="w-full h-full object-cover"/>
                                            : <div className="w-full h-full flex items-center justify-center text-2xl opacity-30">🗺️</div>
                                        }
                                    </div>

                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-start justify-between gap-1">
                                            <p className="text-xs font-semibold text-white truncate">{trip.name}</p>
                                            <div className="flex items-center gap-1 shrink-0" onClick={e => e.stopPropagation()}>
                                                <span className="text-[10px] text-[var(--color-text-secondary)]">{trip.duration_days}d</span>
                                                <button onClick={() => deleteTrip(trip.id)}
                                                    className="p-0.5 text-[var(--color-text-secondary)] hover:text-red-400 opacity-0 group-hover:opacity-100 transition-all">
                                                    <Trash2 size={11}/>
                                                </button>
                                            </div>
                                        </div>

                                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5 flex items-center gap-1">
                                            <Calendar size={9}/> {fmtRange(trip.start_date, trip.end_date)}
                                        </p>

                                        {/* Waypoints summary — Brno → Vídeň → Benátky */}
                                        {trip.waypoints.length > 0 && (
                                            <p className="text-[10px] text-[var(--color-accent)] mt-1 truncate">
                                                {trip.waypoints.map(w => w.place_name).join(' → ')}
                                            </p>
                                        )}

                                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">📸 {trip.media_count} fotek</p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* ── Right: trip detail ──────────────────────────────── */}
                {selected ? (
                    <div className="flex-1 flex flex-col min-h-0 overflow-hidden">

                        {/* Trip header */}
                        <div className="px-5 py-3 border-b border-[var(--color-border)] shrink-0">
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex-1 min-w-0">
                                    <h2 className="text-base font-bold text-white truncate">{selected.name}</h2>
                                    <p className="text-xs text-[var(--color-text-secondary)] mt-0.5 flex items-center gap-3 flex-wrap">
                                        <span className="flex items-center gap-1"><Calendar size={11}/>{fmtRange(selected.start_date, selected.end_date)}</span>
                                        <span>{selected.duration_days} {selected.duration_days === 1 ? 'den' : selected.duration_days < 5 ? 'dny' : 'dní'}</span>
                                        <span>📸 {selected.media_count} fotek</span>
                                    </p>
                                    {selected.waypoints.length > 0 && (
                                        <p className="text-xs text-[var(--color-accent)] mt-1 flex items-center gap-1 flex-wrap">
                                            <MapPin size={10}/>
                                            {selected.waypoints.map((w, i) => (
                                                <span key={w.id} className="flex items-center gap-1">
                                                    {i > 0 && <span className="text-[var(--color-text-secondary)]">↓</span>}
                                                    {w.place_name}
                                                </span>
                                            ))}
                                        </p>
                                    )}
                                </div>
                                <button onClick={() => setSelectedId(null)} className="text-[var(--color-text-secondary)] hover:text-white p-1 shrink-0">
                                    <X size={16}/>
                                </button>
                            </div>
                        </div>

                        {/* ── Suggestion banner ── */}
                        {suggestion && (
                            <div className="mx-4 mt-3 shrink-0 bg-[var(--color-accent)]/10 border border-[var(--color-accent)]/30 rounded-xl p-3">
                                <div className="flex items-center justify-between gap-3 flex-wrap">
                                    <div className="flex items-center gap-3">
                                        <Camera size={18} className="text-[var(--color-accent)] shrink-0"/>
                                        <div>
                                            <p className="text-xs font-medium text-white">
                                                Nalezli jsme {suggestion.count} fotografií pořízených {fmtRange(selected.start_date, selected.end_date)}.
                                                Přidat je do cesty?
                                            </p>
                                            {suggestion.samples.length > 0 && (
                                                <div className="flex gap-1 mt-1.5">
                                                    {suggestion.samples.slice(0, 6).map(s => (
                                                        <img key={s.id} src={s.thumbnail_url} alt="" className="w-8 h-8 object-cover rounded"/>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex gap-2 shrink-0">
                                        <button onClick={addAllMedia} disabled={addingMedia}
                                            className="text-xs bg-[var(--color-accent)] text-white px-3 py-1.5 rounded-lg hover:opacity-90 disabled:opacity-40 flex items-center gap-1.5">
                                            {addingMedia && <RefreshCw size={11} className="animate-spin"/>}
                                            Přidat vše
                                        </button>
                                        <button onClick={() => setSuggestion(null)}
                                            className="text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white px-3 py-1.5 rounded-lg">
                                            Přeskočit
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* ── Content: map + waypoints | media ── */}
                        <div className="flex-1 min-h-0 flex flex-col overflow-hidden">

                            {/* Upper half: map left, waypoints right */}
                            <div className="flex border-b border-[var(--color-border)] shrink-0" style={{ height: '42%', minHeight: 200 }}>

                                {/* Leaflet map */}
                                <div className="flex-1 relative">
                                    <div ref={mapRef} className="absolute inset-0"/>
                                    {!mapLoaded && (
                                        <div className="absolute inset-0 flex items-center justify-center bg-[var(--color-bg-secondary)]">
                                            <div className="w-5 h-5 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin"/>
                                        </div>
                                    )}
                                    {selected.waypoints.filter(w => !w.latitude).length > 0 && mapLoaded && (
                                        <div className="absolute bottom-2 left-2 z-[1000] bg-[var(--color-bg-card)]/90 backdrop-blur-sm rounded px-2 py-1 text-[10px] text-[var(--color-text-secondary)]">
                                            Některá místa bez GPS — hledáme souřadnice
                                        </div>
                                    )}
                                </div>

                                {/* Waypoints panel */}
                                <div className="w-64 shrink-0 border-l border-[var(--color-border)] flex flex-col overflow-hidden">
                                    <div className="px-3 py-2 border-b border-[var(--color-border)] flex items-center justify-between shrink-0">
                                        <p className="text-[10px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider">Trasa</p>
                                        <button onClick={() => setShowWpForm(v => !v)} title="Přidat místo"
                                            className="text-[var(--color-text-secondary)] hover:text-white transition-colors">
                                            <Plus size={13}/>
                                        </button>
                                    </div>

                                    {/* Add waypoint form */}
                                    {showWpForm && (
                                        <form onSubmit={addWaypoint} className="p-2 border-b border-[var(--color-border)] space-y-1.5 shrink-0 bg-[var(--color-bg-secondary)]">
                                            <div className="relative">
                                                <Search size={10} className="absolute left-2 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                                                <input
                                                    value={wpSearch}
                                                    onChange={e => handleWpSearch(e.target.value)}
                                                    onFocus={() => wpResults.length > 0 && setWpDropdown(true)}
                                                    onBlur={() => setTimeout(() => setWpDropdown(false), 150)}
                                                    placeholder="Hledat místo…" autoFocus
                                                    className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded pl-6 pr-1 py-1.5 text-[10px] text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"
                                                />
                                                {wpLoading && <RefreshCw size={10} className="absolute right-2 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] animate-spin"/>}
                                                {wpDropdown && wpResults.length > 0 && (
                                                    <div className="absolute z-50 top-full mt-1 left-0 right-0 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded shadow-xl max-h-36 overflow-y-auto">
                                                        {wpResults.map((r, i) => (
                                                            <button key={i} type="button" onMouseDown={e => { e.preventDefault(); selectWpResult(r); }}
                                                                className="w-full text-left px-2 py-1.5 hover:bg-[var(--color-bg-secondary)] border-b border-[var(--color-border)] last:border-0">
                                                                <p className="text-[10px] font-medium text-white truncate">{r.name || r.display_name}</p>
                                                                <p className="text-[9px] text-[var(--color-text-secondary)] truncate">{r.country}</p>
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                            <div className="flex gap-1">
                                                <button type="submit" disabled={!wpForm.place_name}
                                                    className="flex-1 bg-[var(--color-accent)] text-white text-[10px] py-1 rounded hover:opacity-90 disabled:opacity-40">
                                                    Přidat
                                                </button>
                                                <button type="button" onClick={() => { setShowWpForm(false); setWpSearch(''); setWpForm({ place_name:'', latitude:'', longitude:'' }); }}
                                                    className="px-2 text-[10px] border border-[var(--color-border)] text-[var(--color-text-secondary)] rounded hover:text-white">✕</button>
                                            </div>
                                        </form>
                                    )}

                                    {/* Error banner */}
                                    {wpError && (
                                        <div className="mx-2 mt-1 bg-red-500/10 border border-red-500/30 rounded-lg p-2 text-[10px] text-red-400 flex items-start gap-1.5 shrink-0">
                                            <span className="shrink-0">⚠️</span>
                                            <span>{wpError}</span>
                                            <button onClick={() => setWpError(null)} className="ml-auto shrink-0 hover:text-white"><X size={10}/></button>
                                        </div>
                                    )}

                                    {/* Waypoints list with drag-and-drop */}
                                    <div className="flex-1 overflow-y-auto">
                                        {selected.waypoints.length === 0 ? (
                                            <p className="p-4 text-[10px] text-[var(--color-text-secondary)] text-center">
                                                Přidejte navštívená místa trasy
                                            </p>
                                        ) : (
                                            <div className="py-1">
                                                {selected.waypoints.map((wp, idx) => (
                                                    <div key={wp.id}>
                                                        {/* Waypoint row */}
                                                        <div
                                                            draggable
                                                            onDragStart={() => handleDragStart(idx)}
                                                            onDragOver={e => handleDragOver(e, idx)}
                                                            onDrop={e => handleDrop(e, idx)}
                                                            onDragEnd={() => setDragOver(null)}
                                                            className={`flex items-center gap-1.5 px-2 py-2 group/wp transition-colors ${dragOver === idx ? 'bg-[var(--color-accent)]/10 border-t-2 border-[var(--color-accent)]' : 'hover:bg-[var(--color-bg-secondary)]'}`}
                                                        >
                                                            <GripVertical size={12} className="text-[var(--color-text-secondary)] opacity-40 group-hover/wp:opacity-80 cursor-grab shrink-0"/>
                                                            <div className="w-5 h-5 shrink-0 rounded-full bg-[var(--color-accent)] text-white text-[9px] font-bold flex items-center justify-center">
                                                                {idx + 1}
                                                            </div>
                                                            <div className="flex-1 min-w-0">
                                                                <p className="text-[10px] font-medium text-white truncate">{wp.place_name}</p>
                                                                {wp.latitude && (
                                                                    <p className="text-[9px] text-[var(--color-text-secondary)]">
                                                                        {Number(wp.latitude).toFixed(2)}°, {Number(wp.longitude).toFixed(2)}°
                                                                    </p>
                                                                )}
                                                            </div>
                                                            <button onClick={() => removeWaypoint(wp.id)}
                                                                className="p-0.5 text-[var(--color-text-secondary)] hover:text-red-400 opacity-0 group-hover/wp:opacity-100 transition-all shrink-0">
                                                                <X size={10}/>
                                                            </button>
                                                        </div>

                                                        {/* ── Transport leg ── */}
                                                        {idx < selected.waypoints.length - 1 && (() => {
                                                            const leg = routeLegs[idx];
                                                            const nextWp = selected.waypoints[idx + 1];
                                                            const isOpen = editLegIdx === idx;

                                                            // Compact display text
                                                            const distText = leg?.osrm
                                                                ? `${leg.osrm.distance_km}km`
                                                                : (leg?.km ? `~${fmtDist(leg.km)}` : null);
                                                            const timeText = leg?.osrm
                                                                ? fmtTime(leg.osrm.duration_min / 60)
                                                                : (leg?.time ? fmtTime(leg.time) : null);

                                                            return (
                                                                <div className="mx-2 mb-0.5">
                                                                    {/* Compact leg row */}
                                                                    <button onClick={() => setEditLegIdx(isOpen ? null : idx)}
                                                                        className={`flex items-center gap-1.5 w-full text-left px-2 py-1 rounded-lg transition-colors ${isOpen ? 'bg-[var(--color-bg-card)] border border-[var(--color-border)]' : 'hover:bg-[var(--color-bg-secondary)]'} group/leg`}>
                                                                        <div className="w-px h-5 bg-[var(--color-border)] mx-1.5 shrink-0"/>
                                                                        {leg?.fetching ? (
                                                                            <RefreshCw size={10} className="text-[var(--color-text-secondary)] animate-spin shrink-0"/>
                                                                        ) : leg?.mode ? (
                                                                            <span className="text-sm shrink-0">{TRANSPORT[leg.mode].icon}</span>
                                                                        ) : (
                                                                            <span className="text-[9px] text-[var(--color-text-secondary)] opacity-50 group-hover/leg:opacity-100 shrink-0">+ doprava</span>
                                                                        )}
                                                                        <span className="text-[9px] text-[var(--color-text-secondary)] flex-1 truncate">
                                                                            {leg?.mode && TRANSPORT[leg.mode].label}
                                                                            {distText && ` · ${distText}`}
                                                                            {timeText && ` · ${timeText}`}
                                                                            {leg?.osrm && <span className="ml-1 opacity-40">(cesta)</span>}
                                                                        </span>
                                                                        <span className="text-[9px] text-[var(--color-text-secondary)] shrink-0 opacity-50 group-hover/leg:opacity-100">↗</span>
                                                                    </button>

                                                                    {/* Expanded planning panel */}
                                                                    {isOpen && (
                                                                        <div className="bg-[var(--color-bg-card)] border border-t-0 border-[var(--color-border)] rounded-b-lg p-2.5 space-y-2.5">
                                                                            {/* Route info */}
                                                                            <div>
                                                                                <p className="text-[9px] text-[var(--color-text-secondary)] font-semibold uppercase tracking-wider mb-1">
                                                                                    {wp.place_name} → {nextWp.place_name}
                                                                                </p>
                                                                                <div className="flex gap-2 text-[9px]">
                                                                                    {leg?.km && <span className="text-[var(--color-text-secondary)]">✈ vzduch: {fmtDist(leg.km)}</span>}
                                                                                    {leg?.osrm && <span className="text-white font-medium">🛣 silnice: {leg.osrm.distance_km}km · {fmtTime(leg.osrm.duration_min/60)}</span>}
                                                                                    {leg?.fetching && <span className="text-[var(--color-text-secondary)] animate-pulse">počítám…</span>}
                                                                                </div>
                                                                            </div>

                                                                            {/* Mode selector */}
                                                                            <div>
                                                                                <p className="text-[9px] text-[var(--color-text-secondary)] mb-1">Způsob dopravy:</p>
                                                                                <div className="flex flex-wrap gap-1">
                                                                                    {(Object.keys(TRANSPORT) as TransportMode[]).map(m => (
                                                                                        <button key={m} type="button"
                                                                                            onClick={() => {
                                                                                                updateWaypointMode(nextWp.id, nextWp.transport_mode === m ? null : m);
                                                                                                if (['car','walk','bike'].includes(m) && wp.latitude && wp.longitude && nextWp.latitude) {
                                                                                                    const om = m === 'car' ? 'driving' : m === 'walk' ? 'walking' : 'cycling';
                                                                                                    fetchLegRoute(wp, nextWp, om);
                                                                                                }
                                                                                            }}
                                                                                            className={`flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] border transition-colors ${nextWp.transport_mode === m ? 'bg-[var(--color-accent)] border-transparent text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                                                                                            <span>{TRANSPORT[m].icon}</span> {TRANSPORT[m].label}
                                                                                        </button>
                                                                                    ))}
                                                                                </div>
                                                                            </div>

                                                                            {/* Price estimates */}
                                                                            {(() => {
                                                                                const roadKm = leg?.osrm?.distance_km ?? leg?.km ?? null;
                                                                                if (!roadKm) return null;
                                                                                const prices = estimatePrices(roadKm, wp.place_name, nextWp.place_name, selected.start_date.substring(0,10));
                                                                                if (!prices.length) return null;
                                                                                return (
                                                                                    <div>
                                                                                        <p className="text-[9px] text-[var(--color-text-secondary)] mb-1 flex items-center gap-1">
                                                                                            💰 Cenové odhady
                                                                                            <span className="opacity-50 font-normal">(přibližné)</span>
                                                                                        </p>
                                                                                        <div className="space-y-1">
                                                                                            {prices.map((p, pi) => (
                                                                                                <a key={pi} href={p.bookUrl ?? '#'} target="_blank" rel="noopener noreferrer"
                                                                                                    className="flex items-center gap-2 px-2 py-1.5 rounded-lg bg-[var(--color-bg-secondary)] hover:bg-white/5 transition-colors group/price">
                                                                                                    <span className="text-base shrink-0">{p.icon}</span>
                                                                                                    <div className="flex-1 min-w-0">
                                                                                                        <p className="text-[10px] font-medium text-white truncate">{p.carrier}</p>
                                                                                                        {p.note && <p className="text-[9px] text-[var(--color-text-secondary)]">{p.note}</p>}
                                                                                                    </div>
                                                                                                    <div className="text-right shrink-0">
                                                                                                        <p className="text-[10px] font-semibold text-[var(--color-accent)]">
                                                                                                            od {p.minPrice} {p.currency}
                                                                                                        </p>
                                                                                                        {p.maxPrice && (
                                                                                                            <p className="text-[9px] text-[var(--color-text-secondary)]">
                                                                                                                do {p.maxPrice} {p.currency}
                                                                                                        </p>
                                                                                                        )}
                                                                                                    </div>
                                                                                                    <span className="text-[9px] text-[var(--color-text-secondary)] opacity-0 group-hover/price:opacity-100 shrink-0">↗</span>
                                                                                                </a>
                                                                                            ))}
                                                                                        </div>
                                                                                        <p className="text-[8px] text-[var(--color-text-secondary)] opacity-40 mt-1">
                                                                                            * odhad dle vzdálenosti {leg?.osrm ? '(silniční)' : '(vzdušná čára)'}, skutečné ceny se liší
                                                                                        </p>
                                                                                    </div>
                                                                                );
                                                                            })()}

                                                                            {/* Transport booking links */}
                                                                            <div>
                                                                                <p className="text-[9px] text-[var(--color-text-secondary)] mb-1">Přímé vyhledávání:</p>
                                                                                <div className="flex flex-wrap gap-1">
                                                                                    {buildTransportLinks(wp, nextWp, selected.start_date).map(link => (
                                                                                        <a key={link.label} href={link.url} target="_blank" rel="noopener noreferrer"
                                                                                            className="flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] bg-[var(--color-bg-secondary)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white hover:border-white/20 transition-colors">
                                                                                            <span>{link.icon}</span> {link.label}
                                                                                        </a>
                                                                                    ))}
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            );
                                                        })()}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {/* Route stats summary */}
                                    {selected.waypoints.length >= 2 && (
                                        <div className="p-3 border-t border-[var(--color-border)] shrink-0 bg-[var(--color-bg-secondary)] space-y-1">
                                            <p className="text-[9px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider">Statistiky trasy</p>
                                            <div className="flex items-center justify-between text-[10px]">
                                                <span className="text-[var(--color-text-secondary)] flex items-center gap-1"><MapPin size={9}/> {selected.waypoints.length} zastávek</span>
                                                {totalKm !== null && (
                                                    <span className="text-white font-medium">📏 {fmtDist(totalKm)}</span>
                                                )}
                                            </div>
                                            {totalTime !== null && (
                                                <div className="flex items-center justify-between text-[10px]">
                                                    <span className="text-[var(--color-text-secondary)]">⏱ Odhad cesty</span>
                                                    <span className="text-[var(--color-accent)] font-medium">{fmtTime(totalTime)}</span>
                                                </div>
                                            )}
                                            {routeLegs.some(l => l.km !== null) && (
                                                <div className="mt-1 space-y-0.5">
                                                    {routeLegs.map((leg, i) => leg.km !== null && (
                                                        <div key={i} className="flex items-center gap-1 text-[9px] text-[var(--color-text-secondary)]">
                                                            <span className="text-[10px]">{leg.mode ? TRANSPORT[leg.mode].icon : '→'}</span>
                                                            <span className="truncate">{selected.waypoints[i].place_name}</span>
                                                            <span className="text-[var(--color-border)]">→</span>
                                                            <span className="truncate">{selected.waypoints[i+1].place_name}</span>
                                                            <span className="ml-auto shrink-0 font-medium text-white">{fmtDist(leg.km!)}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Lower half: notes + photo grid */}
                            <div className="flex-1 overflow-y-auto min-h-0">
                                {/* Notes strip — always editable, auto-saves on change + blur */}
                                <div className="px-4 py-2 border-b border-[var(--color-border)] relative">
                                    <textarea
                                        value={editNotes ? notesVal : (selected.notes ?? '')}
                                        onFocus={() => { if (!editNotes) { setNotesVal(selected.notes ?? ''); setEditNotes(true); } }}
                                        onChange={e => handleNotesChange(e.target.value)}
                                        onBlur={e => { handleNotesBlur(e.target.value); setEditNotes(false); }}
                                        rows={2} placeholder="+ Přidat poznámky k cestě…"
                                        className="w-full bg-transparent text-xs text-[var(--color-text-secondary)] placeholder-[var(--color-text-secondary)]/40 outline-none resize-none hover:text-white focus:text-white transition-colors"
                                    />
                                    {savingNotes && (
                                        <span className="absolute right-4 top-2 text-[9px] text-[var(--color-text-secondary)] animate-pulse">ukládám…</span>
                                    )}
                                </div>

                                {/* Photo grid */}
                                <div className="p-4">
                                    {loadingMedia ? (
                                        <div className="grid grid-cols-8 gap-1.5">
                                            {[...Array(16)].map((_, i) => <div key={i} className="aspect-square bg-[var(--color-bg-card)] rounded animate-pulse"/>)}
                                        </div>
                                    ) : tripMedia.length === 0 ? (
                                        <div className="text-center py-8 text-[var(--color-text-secondary)]">
                                            <Camera size={28} className="mx-auto mb-2 opacity-30"/>
                                            <p className="text-xs">Žádné fotky v cestě</p>
                                            {!suggestion && (
                                                <p className="text-[10px] mt-1 opacity-60">
                                                    Fotky pořízené {fmtRange(selected.start_date, selected.end_date)} jsou k dispozici — obnovte stránku.
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        <>
                                            <p className="text-[10px] text-[var(--color-text-secondary)] mb-2">{tripMedia.length} fotografií</p>
                                            <div className="grid grid-cols-5 sm:grid-cols-7 lg:grid-cols-9 xl:grid-cols-11 gap-1">
                                                {tripMedia.map(m => (
                                                    <a key={m.uuid} href={`/media/${m.uuid}`} target="_blank" rel="noopener noreferrer" className="aspect-square block">
                                                        <img src={m.thumbnail_url} alt="" className="w-full h-full object-cover rounded hover:opacity-90 transition-opacity"/>
                                                    </a>
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                ) : (
                    /* Empty / no trip selected */
                    <div className="flex-1 flex items-center justify-center text-[var(--color-text-secondary)]">
                        <div className="text-center max-w-xs">
                            <Route size={48} className="mx-auto mb-4 opacity-20"/>
                            <p className="text-sm font-medium">Vyberte cestu</p>
                            <p className="text-xs mt-1 opacity-60 leading-relaxed">
                                Cesty seskupují fotky a místa z vašich výletů.
                                Fotografie jsou přiřazeny automaticky podle data.
                            </p>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
