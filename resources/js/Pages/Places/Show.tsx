import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowLeft, Calendar, Camera, ExternalLink,
    FolderOpen, MapPin, RefreshCw, Settings2
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface PlaceDetail {
    id: number; name: string; type: string;
    country?: string; country_code?: string; city?: string; address?: string;
    latitude?: number; longitude?: number; radius_meters: number;
    description?: string; website_url?: string;
    photo_count: number; visit_count: number;
    first_visit?: string; last_visit?: string;
    album_count: number; cover_thumb?: string;
}
interface PlaceMedia {
    id: number; uuid: string; thumbnail_url: string;
    taken_at: string; latitude?: number; longitude?: number;
}
interface PlaceAlbum {
    id: number; uuid: string; title: string; cover_thumb?: string; media_count: number;
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

function fmtDate(d?: string | null, opts?: Intl.DateTimeFormatOptions) {
    if (!d) return null;
    return new Date(d).toLocaleDateString('cs-CZ', opts ?? { day: 'numeric', month: 'long', year: 'numeric' });
}

export default function PlaceShow() {
    const mapRef   = useRef<HTMLDivElement>(null);
    const mapObj   = useRef<any>(null);

    // Extract ID from URL
    const placeId = parseInt(window.location.pathname.split('/').pop() ?? '0', 10);

    const [place,      setPlace]      = useState<PlaceDetail | null>(null);
    const [media,      setMedia]      = useState<PlaceMedia[]>([]);
    const [albums,     setAlbums]     = useState<PlaceAlbum[]>([]);
    const [loading,    setLoading]    = useState(true);
    const [loadingMedia, setLoadingMedia] = useState(true);
    const [mapLoaded,  setMapLoaded]  = useState(false);
    const [autoLinking, setAutoLinking] = useState(false);
    const [tab,        setTab]        = useState<'photos' | 'albums'>('photos');
    const [editMode,   setEditMode]   = useState(false);
    const [editForm,   setEditForm]   = useState({ description: '', website_url: '', radius_meters: '500' });

    // Load place + media + albums in parallel
    useEffect(() => {
        if (!placeId) return;
        Promise.all([
            axios.get(`/api/v1/places/${placeId}`),
            axios.get(`/api/v1/places/${placeId}/media`),
            axios.get(`/api/v1/places/${placeId}/albums`),
        ]).then(([pR, mR, aR]) => {
            setPlace(pR.data);
            setMedia(mR.data ?? []);
            setAlbums(aR.data ?? []);
            setEditForm({
                description:   pR.data.description ?? '',
                website_url:   pR.data.website_url ?? '',
                radius_meters: String(pR.data.radius_meters ?? 500),
            });
        }).finally(() => { setLoading(false); setLoadingMedia(false); });
    }, [placeId]);

    // Build map when place + media is ready
    useEffect(() => {
        if (!mapRef.current || !mapLoaded || !place) return;
        const L = (window as any).L;
        if (!L) return;

        if (mapObj.current) { mapObj.current.remove(); mapObj.current = null; }

        const map = L.map(mapRef.current);
        mapObj.current = map;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 18 }).addTo(map);

        const bounds: [number, number][] = [];

        // Place marker
        if (place.latitude && place.longitude) {
            const icon = L.divIcon({
                className: '',
                html: `<div style="width:36px;height:36px;border-radius:50%;background:#6366f1;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;font-size:18px">${TYPES[place.type]?.emoji ?? '📍'}</div>`,
                iconSize: [36, 36], iconAnchor: [18, 18],
            });
            L.marker([place.latitude, place.longitude], { icon }).addTo(map)
                .bindPopup(`<b>${place.name}</b>`).openPopup();

            // Radius circle
            L.circle([place.latitude, place.longitude], {
                radius: place.radius_meters, color: '#6366f1', fillColor: '#6366f1',
                fillOpacity: 0.1, weight: 1.5,
            }).addTo(map);

            bounds.push([place.latitude, place.longitude]);
        }

        // Photo GPS dots
        media.filter(m => m.latitude && m.longitude).forEach(m => {
            L.circleMarker([m.latitude!, m.longitude!], {
                radius: 4, fillColor: '#22c55e', color: '#16a34a', weight: 1, fillOpacity: 0.7,
            }).addTo(map);
            bounds.push([m.latitude!, m.longitude!]);
        });

        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [40, 40] });
        } else {
            map.setView([50.08, 14.44], 8);
        }

        return () => { map.remove(); mapObj.current = null; };
    }, [mapLoaded, place, media]);

    // Load Leaflet
    useEffect(() => {
        if ((window as any).L) { setMapLoaded(true); return; }
        const link = document.createElement('link'); link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(link);
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => setMapLoaded(true); document.head.appendChild(script);
    }, []);

    const autoLink = async () => {
        setAutoLinking(true);
        try {
            const r = await axios.post(`/api/v1/places/${placeId}/auto-link`);
            if (r.data.linked > 0) {
                const mR = await axios.get(`/api/v1/places/${placeId}/media`);
                const pR = await axios.get(`/api/v1/places/${placeId}`);
                setMedia(mR.data ?? []); setPlace(pR.data);
                alert(`Propojeno ${r.data.linked} nových fotek!`);
            } else {
                alert('Žádné nové fotky v GPS poloměru nebyly nalezeny.');
            }
        } finally { setAutoLinking(false); }
    };

    const saveEdit = async () => {
        const r = await axios.patch(`/api/v1/places/${placeId}`, {
            description:   editForm.description || null,
            website_url:   editForm.website_url || null,
            radius_meters: editForm.radius_meters ? parseInt(editForm.radius_meters) : 500,
        });
        setPlace(r.data);
        setEditMode(false);
    };

    if (loading) {
        return (
            <AppLayout>
                <Head title="Místo…" />
                <div className="p-8 text-center text-[var(--color-text-secondary)]">
                    <div className="w-8 h-8 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin mx-auto mb-3"/>
                    Načítám místo…
                </div>
            </AppLayout>
        );
    }

    if (!place) {
        return (
            <AppLayout>
                <Head title="Místo nenalezeno" />
                <div className="p-8 text-center text-[var(--color-text-secondary)]">
                    <MapPin size={40} className="mx-auto mb-3 opacity-20"/>
                    <p>Místo nebylo nalezeno</p>
                    <button onClick={() => router.visit('/places')} className="mt-3 text-sm text-[var(--color-accent)] hover:underline">← Zpět na místa</button>
                </div>
            </AppLayout>
        );
    }

    const typeInfo = TYPES[place.type] ?? TYPES.custom;

    return (
        <AppLayout>
            <Head title={place.name} />
            <div className="flex flex-col h-full min-h-0 overflow-auto">

                {/* ── Header ──────────────────────────────────────────── */}
                <div className="px-6 py-4 border-b border-[var(--color-border)] shrink-0">
                    <div className="flex items-start gap-4">
                        {/* Back */}
                        <button onClick={() => router.visit('/places')}
                            className="p-1.5 text-[var(--color-text-secondary)] hover:text-white transition-colors shrink-0 mt-0.5">
                            <ArrowLeft size={18}/>
                        </button>

                        {/* Type icon */}
                        <div className="w-12 h-12 rounded-xl bg-[var(--color-bg-card)] flex items-center justify-center text-2xl shrink-0 border border-[var(--color-border)]">
                            {typeInfo.emoji}
                        </div>

                        {/* Title block */}
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-xl font-bold text-white">{place.name}</h1>
                                <span className="text-xs bg-[var(--color-bg-card)] border border-[var(--color-border)] px-2 py-0.5 rounded-full text-[var(--color-text-secondary)]">
                                    {typeInfo.label}
                                </span>
                            </div>
                            {(place.city || place.country) && (
                                <p className="text-sm text-[var(--color-text-secondary)] mt-0.5 flex items-center gap-1">
                                    <MapPin size={12}/>
                                    {[place.city, place.country].filter(Boolean).join(', ')}
                                </p>
                            )}

                            {/* Stats row */}
                            <div className="flex items-center gap-4 mt-2 text-xs text-[var(--color-text-secondary)] flex-wrap">
                                {place.visit_count > 0 && (
                                    <span className="flex items-center gap-1 text-white font-medium">
                                        Navštíveno {place.visit_count}×
                                    </span>
                                )}
                                {place.first_visit && (
                                    <span className="flex items-center gap-1">
                                        <Calendar size={11}/> První: {fmtDate(place.first_visit, { day: 'numeric', month: 'short', year: 'numeric' })}
                                    </span>
                                )}
                                {place.last_visit && (
                                    <span className="flex items-center gap-1">
                                        Poslední: {fmtDate(place.last_visit, { day: 'numeric', month: 'short', year: 'numeric' })}
                                    </span>
                                )}
                                <span className="flex items-center gap-1">
                                    <Camera size={11}/> {place.photo_count} fotek
                                </span>
                                {place.album_count > 0 && (
                                    <span className="flex items-center gap-1">
                                        <FolderOpen size={11}/> {place.album_count} alb
                                    </span>
                                )}
                            </div>
                        </div>

                        {/* Actions */}
                        <div className="flex gap-2 shrink-0">
                            {place.latitude && place.longitude && (
                                <button onClick={autoLink} disabled={autoLinking} title="Automaticky propojit fotky v GPS poloměru"
                                    className="flex items-center gap-1.5 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-xs px-3 py-1.5 rounded-lg transition-colors disabled:opacity-40">
                                    <RefreshCw size={12} className={autoLinking ? 'animate-spin' : ''}/> Auto-link
                                </button>
                            )}
                            <button onClick={() => setEditMode(v => !v)}
                                className="flex items-center gap-1.5 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-xs px-3 py-1.5 rounded-lg transition-colors">
                                <Settings2 size={12}/> Upravit
                            </button>
                            {place.website_url && (
                                <a href={place.website_url} target="_blank" rel="noopener noreferrer"
                                    className="flex items-center gap-1.5 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-xs px-3 py-1.5 rounded-lg transition-colors">
                                    <ExternalLink size={12}/> Web
                                </a>
                            )}
                        </div>
                    </div>

                    {/* Edit form (inline) */}
                    {editMode && (
                        <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <textarea value={editForm.description} onChange={e => setEditForm(p => ({ ...p, description: e.target.value }))}
                                placeholder="Popis místa…" rows={2}
                                className="col-span-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none"/>
                            <input value={editForm.website_url} onChange={e => setEditForm(p => ({ ...p, website_url: e.target.value }))} type="url"
                                placeholder="Web (https://…)"
                                className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                            <div className="flex items-center gap-2">
                                <input value={editForm.radius_meters} onChange={e => setEditForm(p => ({ ...p, radius_meters: e.target.value }))} type="number" min="10" max="50000"
                                    placeholder="Poloměr (m)"
                                    className="flex-1 bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                                <span className="text-xs text-[var(--color-text-secondary)] whitespace-nowrap">GPS poloměr</span>
                            </div>
                            <div className="col-span-full flex gap-2">
                                <button onClick={saveEdit} className="bg-[var(--color-accent)] text-white text-sm px-4 py-1.5 rounded-lg hover:opacity-90">Uložit</button>
                                <button onClick={() => setEditMode(false)} className="border border-[var(--color-border)] text-[var(--color-text-secondary)] text-sm px-4 py-1.5 rounded-lg hover:text-white">Zrušit</button>
                            </div>
                        </div>
                    )}

                    {/* Description */}
                    {!editMode && place.description && (
                        <p className="mt-3 text-sm text-[var(--color-text-secondary)] leading-relaxed max-w-3xl">{place.description}</p>
                    )}
                </div>

                {/* ── Content ──────────────────────────────────────────── */}
                <div className="flex flex-1 min-h-0 overflow-hidden">

                    {/* Mini map (left sidebar when has GPS) */}
                    {place.latitude && place.longitude && (
                        <div className="w-64 shrink-0 border-r border-[var(--color-border)] relative">
                            <div ref={mapRef} className="absolute inset-0"/>
                            {!mapLoaded && (
                                <div className="absolute inset-0 flex items-center justify-center bg-[var(--color-bg-secondary)]">
                                    <div className="w-5 h-5 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin"/>
                                </div>
                            )}
                            {/* GPS link */}
                            <div className="absolute bottom-2 right-2 z-[1000]">
                                <a href={`https://www.google.com/maps/search/?api=1&query=${place.latitude},${place.longitude}`}
                                    target="_blank" rel="noopener noreferrer"
                                    className="flex items-center gap-1 text-[10px] bg-[var(--color-bg-card)]/90 backdrop-blur-sm text-white px-2 py-1 rounded hover:opacity-90">
                                    <ExternalLink size={9}/> Google Maps
                                </a>
                            </div>
                        </div>
                    )}

                    {/* Main content: tabs */}
                    <div className="flex-1 flex flex-col overflow-hidden">
                        {/* Tab bar */}
                        <div className="flex gap-4 px-6 border-b border-[var(--color-border)] shrink-0">
                            <button onClick={() => setTab('photos')}
                                className={`flex items-center gap-1.5 py-3 text-sm border-b-2 transition-colors ${tab === 'photos' ? 'border-[var(--color-accent)] text-white' : 'border-transparent text-[var(--color-text-secondary)] hover:text-white'}`}>
                                <Camera size={14}/> Fotografie ({place.photo_count})
                            </button>
                            <button onClick={() => setTab('albums')}
                                className={`flex items-center gap-1.5 py-3 text-sm border-b-2 transition-colors ${tab === 'albums' ? 'border-[var(--color-accent)] text-white' : 'border-transparent text-[var(--color-text-secondary)] hover:text-white'}`}>
                                <FolderOpen size={14}/> Alba ({place.album_count})
                            </button>
                        </div>

                        {/* Tab content */}
                        <div className="flex-1 overflow-y-auto p-6">
                            {tab === 'photos' ? (
                                loadingMedia ? (
                                    <div className="grid grid-cols-5 sm:grid-cols-7 lg:grid-cols-9 gap-1.5">
                                        {[...Array(20)].map((_, i) => <div key={i} className="aspect-square bg-[var(--color-bg-card)] rounded animate-pulse"/>)}
                                    </div>
                                ) : media.length === 0 ? (
                                    <div className="text-center py-12 text-[var(--color-text-secondary)]">
                                        <Camera size={36} className="mx-auto mb-3 opacity-20"/>
                                        <p>Žádné fotografie</p>
                                        {place.latitude && (
                                            <p className="text-sm mt-1 opacity-60">Použijte Auto-link pro propojení fotek v GPS poloměru {place.radius_meters} m</p>
                                        )}
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-5 sm:grid-cols-7 lg:grid-cols-9 xl:grid-cols-11 gap-1">
                                        {media.map(m => (
                                            <a key={m.uuid} href={`/media/${m.uuid}`} target="_blank" rel="noopener noreferrer" className="aspect-square block">
                                                <img src={m.thumbnail_url} alt="" className="w-full h-full object-cover rounded hover:opacity-90 transition-opacity"/>
                                            </a>
                                        ))}
                                    </div>
                                )
                            ) : (
                                albums.length === 0 ? (
                                    <div className="text-center py-12 text-[var(--color-text-secondary)]">
                                        <FolderOpen size={36} className="mx-auto mb-3 opacity-20"/>
                                        <p>Žádná alba pro toto místo</p>
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                                        {albums.map(album => (
                                            <a key={album.uuid} href={`/albums/${album.uuid}`}
                                                className="group bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl overflow-hidden hover:border-[var(--color-accent)]/50 transition-all cursor-pointer">
                                                <div className="aspect-video bg-[var(--color-bg-secondary)] overflow-hidden">
                                                    {album.cover_thumb
                                                        ? <img src={album.cover_thumb} alt="" className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"/>
                                                        : <div className="w-full h-full flex items-center justify-center text-3xl opacity-20">📁</div>
                                                    }
                                                </div>
                                                <div className="p-2.5">
                                                    <p className="text-xs font-medium text-white truncate">{album.title}</p>
                                                    <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">📸 {album.media_count}</p>
                                                </div>
                                            </a>
                                        ))}
                                    </div>
                                )
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
