import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { addLocalizedBaseLayer, localizedCountry } from '@/lib/localizedMap';
import { ArrowDown, ArrowUp, Calendar, Camera, GripVertical, MapPin, Plus, RefreshCw, Route, Search, Trash2, X } from 'lucide-react';
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
    name: string; display_name: string; country: string; country_code?: string;
    latitude: number; longitude: number; category: string;
}

const MONTHS_CS = ['ledna','února','března','dubna','května','června','července','srpna','září','října','listopadu','prosince'];

// ── Price estimation (calibrated to CZ/SK market 2025) ─────────────────────
interface PriceEstimate {
    carrier: string; icon: string; minPrice: number; maxPrice?: number;
    currency: string; note?: string; bookUrl?: string; mode: TransportMode;
}
function estimatePrices(km: number, from: string, to: string, isoDate: string): PriceEstimate[] {
    if (!km || km < 8) return [];
    const f = encodeURIComponent(from), t = encodeURIComponent(to);
    const r: PriceEstimate[] = [];

    // ČD (eJízdenka with early purchase ~35% off, base ~1.2 Kč/km)
    const cdBase = Math.max(30, Math.round(1.2 * km / 10) * 10);
    r.push({ carrier: 'České dráhy', icon: '🚂', currency: 'Kč', mode: 'train',
        minPrice: Math.round(cdBase * 0.65 / 5) * 5,
        maxPrice: cdBase,
        note: 'eJízdenka',
        bookUrl: `https://idos.idnes.cz/vlak/spojeni/?f=${f}&t=${t}&date=${isoDate}&time=0600`,
    });

    // RegioJet Bus (~0.58 Kč/km, min 49 Kč)
    const rjBus = Math.max(49, Math.round(0.58 * km / 5) * 5);
    r.push({ carrier: 'RegioJet Bus', icon: '🟡', currency: 'Kč', mode: 'bus',
        minPrice: rjBus,
        maxPrice: Math.round(rjBus * 1.4 / 5) * 5,
        bookUrl: `https://www.regiojet.cz/vlaky-a-autobusy/jizdenky-online/?f=${from}&t=${to}&date=${isoDate}`,
    });

    // RegioJet vlak (~0.9 Kč/km, min 79 Kč) — only if plausible route
    if (km > 25) {
        const rjTrain = Math.max(79, Math.round(0.9 * km / 5) * 5);
        r.push({ carrier: 'RegioJet vlak', icon: '🟡', currency: 'Kč', mode: 'train',
            minPrice: rjTrain,
            maxPrice: Math.round(rjTrain * 1.3 / 5) * 5,
            bookUrl: `https://www.regiojet.cz/vlaky-a-autobusy/jizdenky-online/?f=${from}&t=${to}&date=${isoDate}`,
        });
    }

    // FlixBus (~0.5 Kč/km, min 99 Kč — often flash sales)
    if (km > 70) {
        const flix = Math.max(99, Math.round(0.5 * km / 5) * 5);
        r.push({ carrier: 'FlixBus', icon: '🟢', currency: 'Kč', mode: 'bus',
            minPrice: Math.round(flix * 0.55 / 5) * 5,
            maxPrice: Math.round(flix * 1.8 / 5) * 5,
            note: 'flash výprodeje',
            bookUrl: `https://shop.flixbus.cz/search?departureCity=${f}&arrivalCity=${t}&rideDate=${isoDate}&adult=1`,
        });
    }

    // Car per person (2 people sharing, 7L/100km × 42 Kč/L)
    const carPP = Math.max(30, Math.round(1.47 * km / 10) * 10);
    r.push({ carrier: 'Auto /os. (2 os.)', icon: '🚗', currency: 'Kč', mode: 'car',
        minPrice: carPP,
        note: 'palivo',
        bookUrl: `https://www.google.com/maps/dir/${encodeURIComponent(from)}/${encodeURIComponent(to)}`,
    });

    // Plane — only for long routes
    if (km > 350) {
        r.push({ carrier: 'Letadlo', icon: '✈️', currency: 'EUR', mode: 'plane',
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
    const fLat = from.latitude, fLng = from.longitude;
    const tLat = to.latitude,   tLng = to.longitude;
    return [
        { label: 'Porovnat vše', icon: '🎫', mode: 'train' as TransportMode, url: `/tickets?from=${f}&to=${t}&date=${iso}` },
        { label: 'ČD / IDOS',   icon: '🚂', mode: 'train' as TransportMode, url: `https://idos.idnes.cz/vlak/spojeni/?f=${f}&t=${t}&date=${iso}&time=0600` },
        { label: 'RegioJet',    icon: '🟡', mode: 'train' as TransportMode, url: 'https://regiojet.cz/' },
        { label: 'FlixBus',     icon: '🟢', mode: 'bus' as TransportMode, url: 'https://www.flixbus.cz/' },
        { label: 'Leo Express', icon: '⚫', mode: 'train' as TransportMode, url: 'https://www.leoexpress.com/cs/rezervace' },
        { label: 'Google Maps', icon: '🗺️', mode: 'car' as TransportMode,
            url: fLat && fLng && tLat && tLng
                ? `https://www.google.com/maps/dir/${fLat},${fLng}/${tLat},${tLng}/`
                : `https://www.google.com/maps/dir/?api=1&origin=${f}&destination=${t}&travelmode=driving` },
        { label: 'Waze',        icon: '🔵', mode: 'car' as TransportMode,
            url: tLat && tLng ? `https://waze.com/ul?navigate=yes&to=${tLat},${tLng}&from=${fLat},${fLng}` : null },
        { label: 'Mapy.cz',    icon: '📍', mode: 'walk' as TransportMode,
            url: fLat && fLng && tLat && tLng
                ? `https://mapy.cz/zakladni?planovani-trasy&planovani[0][x]=${fLng}&planovani[0][y]=${fLat}&planovani[1][x]=${tLng}&planovani[1][y]=${tLat}`
                : `https://mapy.cz/` },
        { label: 'Skyscanner',  icon: '✈️', mode: 'plane' as TransportMode, url: 'https://www.skyscanner.cz/' },
        { label: 'IDOS autobus',icon: '🚌', mode: 'bus' as TransportMode, url: `https://idos.idnes.cz/autobus/spojeni/?f=${f}&t=${t}&date=${iso}&time=0600` },
    ].filter(l => l.url !== null) as {label:string;icon:string;mode:TransportMode;url:string}[];
}

interface WaypointDraft { place_name: string; latitude?: number; longitude?: number; }
const ALL_TRANSPORT_MODES = Object.keys(TRANSPORT) as TransportMode[];

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
    const [pendingWaypoints, setPendingWaypoints] = useState<WaypointDraft[]>([]);
    const [savingWaypoints, setSavingWaypoints] = useState(false);

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

    // Live pricing (keyed by "{from}|{to}|{date}")
    const [livePrices,     setLivePrices]     = useState<Record<string, PriceEstimate[] | null>>({});
    const [fetchingPrices, setFetchingPrices] = useState<Record<string, boolean>>({});
    const [transportFilters, setTransportFilters] = useState<TransportMode[]>(ALL_TRANSPORT_MODES);

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
        addLocalizedBaseLayer(L, map);

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
        const place_name = r.name || r.display_name;
        setPendingWaypoints(prev => prev.some(w => w.place_name === place_name && w.latitude === r.latitude)
            ? prev
            : [...prev, { place_name, latitude: r.latitude, longitude: r.longitude }]);
        setWpSearch('');
        setWpForm({ place_name: '', latitude: '', longitude: '' });
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

    const queueTypedWaypoint = (e: React.FormEvent) => {
        e.preventDefault();
        const place_name = wpForm.place_name.trim();
        if (!place_name) return;
        setPendingWaypoints(prev => [...prev, {
            place_name,
            latitude: wpForm.latitude ? parseFloat(wpForm.latitude) : undefined,
            longitude: wpForm.longitude ? parseFloat(wpForm.longitude) : undefined,
        }]);
        setWpForm({ place_name: '', latitude: '', longitude: '' });
        setWpSearch('');
        setWpResults([]);
    };

    const saveWaypoints = async () => {
        if (!selectedId || pendingWaypoints.length === 0) return;
        setWpError(null);
        setSavingWaypoints(true);
        try {
            const r = await axios.post(`/api/v1/trips/${selectedId}/waypoints`, {
                waypoints: pendingWaypoints,
            });
            const created = Array.isArray(r.data) ? r.data : [r.data];
            setTrips(prev => prev.map(t => t.id === selectedId ? { ...t, waypoints: [...t.waypoints, ...created] } : t));
            setPendingWaypoints([]);
            setShowWpForm(false);
        } catch (err: any) {
            const msg = err?.response?.data?.message ?? err?.response?.data?.error ?? 'Chyba ukládání';
            if (msg.includes('migrate') || err?.response?.status === 503) {
                setWpError('⚠️ Server potřebuje spustit: php artisan migrate');
            } else {
                setWpError(`Chyba: ${msg}`);
            }
        } finally { setSavingWaypoints(false); }
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

    // Fetch live prices from RegioJet + FlixBus when a leg is opened
    const fetchLivePrices = useCallback(async (from: Waypoint, to: Waypoint, date: string) => {
        const key = `${from.place_name}|${to.place_name}|${date.substring(0,10)}`;
        if (livePrices[key] !== undefined || fetchingPrices[key]) return;
        setFetchingPrices(prev => ({ ...prev, [key]: true }));
        try {
            const r = await axios.get('/api/v1/trips/transport-prices', {
                params: { from: from.place_name, to: to.place_name, date: date.substring(0, 10) },
            });
            const normalized: PriceEstimate[] = (r.data ?? []).map((price: any) => ({
                carrier: price.carrier,
                icon: price.icon,
                minPrice: price.min_price,
                maxPrice: price.max_price,
                currency: price.currency === 'CZK' ? 'Kč' : price.currency,
                note: price.note,
                bookUrl: price.book_url,
                mode: price.carrier?.toLowerCase().includes('vlak') ? 'train' : 'bus',
            }));
            setLivePrices(prev => ({ ...prev, [key]: normalized.length ? normalized : null }));
        } catch {
            setLivePrices(prev => ({ ...prev, [key]: null }));
        } finally {
            setFetchingPrices(prev => ({ ...prev, [key]: false }));
        }
    }, [livePrices, fetchingPrices]);

    const toggleTransportFilter = (mode: TransportMode) => {
        setTransportFilters(prev => prev.includes(mode)
            ? (prev.length === 1 ? prev : prev.filter(item => item !== mode))
            : [...prev, mode]);
    };

    // Auto-fetch OSRM routes when selection changes
    useEffect(() => {        if (!selected) return;
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
    const persistWaypointOrder = async (wps: Waypoint[]) => {
        if (!selectedId || !selected) return;
        const previous = selected.waypoints;
        setWpError(null);
        setTrips(items => items.map(trip => trip.id === selectedId ? { ...trip, waypoints: wps } : trip));
        try {
            await axios.put(`/api/v1/trips/${selectedId}/waypoints/reorder`, { order: wps.map(wp => wp.id) });
        } catch (error: any) {
            setTrips(items => items.map(trip => trip.id === selectedId ? { ...trip, waypoints: previous } : trip));
            setWpError(error?.response?.data?.message ?? 'Pořadí zastávek se nepodařilo uložit.');
        }
    };
    const moveWaypoint = (from: number, direction: -1 | 1) => {
        const to = from + direction;
        if (!selected || to < 0 || to >= selected.waypoints.length) return;
        const wps = [...selected.waypoints];
        [wps[from], wps[to]] = [wps[to], wps[from]];
        void persistWaypointOrder(wps);
    };
    const handleDrop = async (e: React.DragEvent, toIdx: number) => {
        e.preventDefault(); setDragOver(null);
        if (!selectedId || dragIdx.current === null || dragIdx.current === toIdx) return;
        const from = dragIdx.current; dragIdx.current = null;
        const wps = [...(selected?.waypoints ?? [])];
        const [moved] = wps.splice(from, 1);
        wps.splice(toIdx, 0, moved);
        await persistWaypointOrder(wps);
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
            <div className="flex flex-col lg:flex-row h-full min-h-0">

                {/* ── Left: trip list ──────────────────────────────────── */}
                <div className={`${selected ? 'hidden lg:flex' : 'flex'} w-full lg:w-72 min-h-0 shrink-0 flex-col border-r border-[var(--color-border)] overflow-hidden`}>

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
                    <div className="flex-1 flex flex-col min-h-0 overflow-y-auto xl:overflow-hidden">

                        {/* Trip header */}
                        <div className="px-3 sm:px-5 py-3 border-b border-[var(--color-border)] shrink-0">
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
                                <div className="flex shrink-0 items-center gap-2">
                                    <Link href={`/trips/${selected.id}/plan`} className="flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-3 text-xs font-medium text-white">
                                        <Calendar size={14}/> Plán dne
                                    </Link>
                                    <button onClick={() => setSelectedId(null)} className="flex min-h-10 min-w-10 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white">
                                        <X size={16}/>
                                    </button>
                                </div>
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
                        <div className="flex-1 min-h-0 flex flex-col overflow-visible xl:overflow-hidden">

                            {/* Upper half: map left, waypoints right */}
                            <div className="flex flex-col xl:flex-row border-b border-[var(--color-border)] shrink-0 xl:h-[52%] xl:min-h-[320px]">

                                {/* Leaflet map */}
                                <div className="h-56 sm:h-72 xl:h-auto xl:flex-1 relative shrink-0 xl:shrink">
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
                                <div className="w-full xl:w-80 shrink-0 border-t xl:border-t-0 xl:border-l border-[var(--color-border)] flex flex-col overflow-hidden max-h-[70vh] xl:max-h-none">
                                    <div className="px-3 py-2 border-b border-[var(--color-border)] flex items-center justify-between shrink-0">
                                        <div>
                                            <p className="text-[10px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider">Trasa</p>
                                            <p className="text-[9px] text-[var(--color-text-secondary)]/60">{selected.waypoints.length} zastávek</p>
                                        </div>
                                        <button onClick={() => setShowWpForm(v => !v)} title="Přidat místo"
                                            className="min-w-9 min-h-9 flex items-center justify-center rounded-lg bg-[var(--color-accent)] text-white hover:opacity-90 transition-opacity">
                                            <Plus size={16}/>
                                        </button>
                                    </div>

                                    <div className="px-3 py-2 border-b border-[var(--color-border)] shrink-0">
                                        <div className="flex items-center justify-between gap-2 mb-1.5">
                                            <p className="text-[9px] font-medium text-[var(--color-text-secondary)]">Filtrovat nabídky dopravy</p>
                                            <button type="button" onClick={() => setTransportFilters(ALL_TRANSPORT_MODES)}
                                                className="text-[9px] text-[var(--color-accent)] hover:text-white">
                                                Vybrat vše
                                            </button>
                                        </div>
                                        <div className="flex gap-1.5 overflow-x-auto scrollbar-hide pb-0.5">
                                            {ALL_TRANSPORT_MODES.map(mode => {
                                                const active = transportFilters.includes(mode);
                                                return (
                                                    <button key={mode} type="button" onClick={() => toggleTransportFilter(mode)}
                                                        aria-pressed={active} title={TRANSPORT[mode].label}
                                                        className={`min-w-9 min-h-8 px-2 rounded-lg border text-sm shrink-0 transition-colors ${active ? 'bg-[var(--color-accent)]/20 border-[var(--color-accent)] text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] opacity-50'}`}>
                                                        {TRANSPORT[mode].icon}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    {/* Add waypoint form */}
                                    {showWpForm && (
                                        <form onSubmit={queueTypedWaypoint} className="p-3 border-b border-[var(--color-border)] space-y-2 shrink-0 bg-[var(--color-bg-secondary)]">
                                            <div className="relative">
                                                <Search size={10} className="absolute left-2 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                                                <input
                                                    value={wpSearch}
                                                    onChange={e => handleWpSearch(e.target.value)}
                                                    onFocus={() => wpResults.length > 0 && setWpDropdown(true)}
                                                    onBlur={() => setTimeout(() => setWpDropdown(false), 150)}
                                                    placeholder="Hledat místo…" autoFocus
                                                    className="w-full min-h-10 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg pl-7 pr-7 py-2 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"
                                                />
                                                {wpLoading && <RefreshCw size={10} className="absolute right-2 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] animate-spin"/>}
                                                {wpDropdown && wpResults.length > 0 && (
                                                    <div className="absolute z-50 top-full mt-1 left-0 right-0 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded shadow-xl max-h-36 overflow-y-auto">
                                                        {wpResults.map((r, i) => (
                                                            <button key={i} type="button" onMouseDown={e => { e.preventDefault(); selectWpResult(r); }}
                                                                className="w-full min-h-11 text-left px-3 py-2 hover:bg-[var(--color-bg-secondary)] border-b border-[var(--color-border)] last:border-0">
                                                                <p className="text-xs font-medium text-white truncate">{r.name || r.display_name}</p>
                                                                <p className="text-[10px] text-[var(--color-text-secondary)] truncate">{localizedCountry(r.country, r.country_code)}</p>
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                            {pendingWaypoints.length > 0 && (
                                                <div className="space-y-1.5">
                                                    <p className="text-[9px] text-[var(--color-text-secondary)]">Připravená místa ({pendingWaypoints.length})</p>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {pendingWaypoints.map((place, index) => (
                                                            <span key={`${place.place_name}-${index}`} className="inline-flex items-center gap-1 rounded-full bg-[var(--color-accent)]/15 border border-[var(--color-accent)]/30 pl-2.5 pr-1 py-1 text-[10px] text-white max-w-full">
                                                                <span className="truncate">{index + 1}. {place.place_name}</span>
                                                                <button type="button" aria-label={`Odebrat ${place.place_name}`} onClick={() => setPendingWaypoints(prev => prev.filter((_, i) => i !== index))}
                                                                    className="w-6 h-6 rounded-full flex items-center justify-center hover:bg-white/10 shrink-0"><X size={11}/></button>
                                                            </span>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                            <div className="flex flex-wrap gap-2">
                                                <button type="submit" disabled={!wpForm.place_name}
                                                    className="min-h-10 px-3 border border-[var(--color-border)] text-white text-[10px] rounded-lg hover:border-[var(--color-accent)] disabled:opacity-40">
                                                    + Zařadit napsané
                                                </button>
                                                <button type="button" onClick={saveWaypoints} disabled={pendingWaypoints.length === 0 || savingWaypoints}
                                                    className="flex-1 min-h-10 bg-[var(--color-accent)] text-white text-[10px] px-3 rounded-lg hover:opacity-90 disabled:opacity-40">
                                                    {savingWaypoints ? 'Ukládám…' : `Uložit ${pendingWaypoints.length || ''} ${pendingWaypoints.length === 1 ? 'místo' : 'místa'}`}
                                                </button>
                                                <button type="button" onClick={() => { setShowWpForm(false); setWpSearch(''); setWpForm({ place_name:'', latitude:'', longitude:'' }); setPendingWaypoints([]); }}
                                                    className="min-w-10 min-h-10 text-[var(--color-text-secondary)] border border-[var(--color-border)] rounded-lg hover:text-white">✕</button>
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
                                                            <div className="flex shrink-0 gap-0.5">
                                                                <button type="button" disabled={idx === 0} onClick={() => moveWaypoint(idx, -1)} aria-label={`Posunout ${wp.place_name} nahoru`}
                                                                    className="flex h-8 w-8 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white disabled:opacity-20"><ArrowUp size={13}/></button>
                                                                <button type="button" disabled={idx === selected.waypoints.length - 1} onClick={() => moveWaypoint(idx, 1)} aria-label={`Posunout ${wp.place_name} dolů`}
                                                                    className="flex h-8 w-8 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white disabled:opacity-20"><ArrowDown size={13}/></button>
                                                            </div>
                                                            <button onClick={() => removeWaypoint(wp.id)}
                                                                className="flex h-8 w-8 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-red-500/10 hover:text-red-400 transition-all shrink-0 sm:opacity-0 sm:group-hover/wp:opacity-100">
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
                                                                    <button onClick={() => {
                                                                        setEditLegIdx(isOpen ? null : idx);
                                                                        if (!isOpen) fetchLivePrices(wp, nextWp, selected.start_date);
                                                                    }}
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

                                                                            {/* Price estimates — live first, static fallback */}
                                                                            {(() => {
                                                                                const roadKm   = leg?.osrm?.distance_km ?? leg?.km ?? null;
                                                                                const priceKey = `${wp.place_name}|${nextWp.place_name}|${selected.start_date.substring(0,10)}`;
                                                                                const isFetching = fetchingPrices[priceKey] ?? false;
                                                                                const live  = livePrices[priceKey];
                                                                                const fallback = roadKm ? estimatePrices(roadKm, wp.place_name, nextWp.place_name, selected.start_date.substring(0,10)) : [];
                                                                                const prices = (live ?? fallback).filter(price => transportFilters.includes(price.mode) && Boolean(price.bookUrl));
                                                                                const isLive = !!live;

                                                                                return (
                                                                                    <div>
                                                                                        <p className="text-[9px] text-[var(--color-text-secondary)] mb-1 flex items-center gap-1.5">
                                                                                            💰 Ceny jízdenek
                                                                                            {isFetching && <RefreshCw size={8} className="animate-spin opacity-60"/>}
                                                                                            {isLive
                                                                                                ? <span className="text-green-400 font-medium">● živé ceny</span>
                                                                                                : !isFetching && <span className="opacity-40">odhad dle vzdálenosti</span>
                                                                                            }
                                                                                        </p>
                                                                                        {isFetching && prices.length === 0 ? (
                                                                                            <div className="space-y-1">
                                                                                                {[1,2,3].map(i => <div key={i} className="h-8 bg-[var(--color-bg-secondary)] rounded-lg animate-pulse"/>)}
                                                                                            </div>
                                                                                        ) : prices.length > 0 ? (
                                                                                            <div className="space-y-1">
                                                                                                {prices.map((p, pi) => (
                                                                                                    <a key={pi} href={p.bookUrl!} target="_blank" rel="noopener noreferrer"
                                                                                                        className="flex items-center gap-2 px-2 py-1.5 rounded-lg bg-[var(--color-bg-secondary)] hover:bg-white/5 transition-colors group/price">
                                                                                                        <span className="text-base shrink-0">{p.icon}</span>
                                                                                                        <div className="flex-1 min-w-0">
                                                                                                            <p className="text-[10px] font-medium text-white truncate">{p.carrier}</p>
                                                                                                            {p.note && <p className="text-[9px] text-[var(--color-text-secondary)]">{p.note}</p>}
                                                                                                        </div>
                                                                                                        <div className="text-right shrink-0">
                                                                                                            <p className={`text-[10px] font-semibold ${isLive ? 'text-green-400' : 'text-[var(--color-accent)]'}`}>
                                                                                                                od {p.minPrice} {p.currency}
                                                                                                            </p>
                                                                                                            {p.maxPrice && ! isLive && (
                                                                                                                <p className="text-[9px] text-[var(--color-text-secondary)]">
                                                                                                                    do {p.maxPrice} {p.currency}
                                                                                                                </p>
                                                                                                            )}
                                                                                                        </div>
                                                                                                        <span className="text-[9px] text-[var(--color-text-secondary)] opacity-0 group-hover/price:opacity-100 shrink-0">↗</span>
                                                                                                    </a>
                                                                                                ))}
                                                                                                {! isLive && roadKm && (
                                                                                                    <p className="text-[8px] text-[var(--color-text-secondary)] opacity-40 mt-0.5">
                                                                                                        * odhad dle vzdálenosti, načítám živé ceny…
                                                                                                    </p>
                                                                                                )}
                                                                                            </div>
                                                                                        ) : (
                                                                                            <p className="text-[9px] text-[var(--color-text-secondary)] opacity-60 italic">
                                                                                                {transportFilters.length < ALL_TRANSPORT_MODES.length
                                                                                                    ? 'Pro vybrané druhy dopravy nejsou dostupné cenové nabídky.'
                                                                                                    : 'Zadejte GPS souřadnice zastávek pro ceník.'}
                                                                                            </p>
                                                                                        )}
                                                                                    </div>
                                                                                );
                                                                            })()}

                                                                            {/* Transport booking links */}
                                                                            <div>
                                                                                <p className="text-[9px] text-[var(--color-text-secondary)] mb-1">Přímé vyhledávání:</p>
                                                                                <div className="flex flex-wrap gap-1">
                                                                                    {buildTransportLinks(wp, nextWp, selected.start_date)
                                                                                        .filter(link => transportFilters.includes(link.mode))
                                                                                        .map(link => (
                                                                                        <a key={link.label} href={link.url} target="_blank" rel="noopener noreferrer"
                                                                                            className="flex min-h-8 items-center gap-1 px-2 rounded-lg text-[9px] bg-[var(--color-bg-secondary)] border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white hover:border-white/20 transition-colors">
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
                            <div className="flex-1 overflow-visible xl:overflow-y-auto min-h-0">
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
                                        <div className="grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-8 gap-1.5">
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
