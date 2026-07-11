import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { addLocalizedBaseLayer } from '@/lib/localizedMap';
import { ExternalLink, FolderOpen, Image, MapPin, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface MapPoint {
    id: number; uuid: string; latitude: number; longitude: number;
    taken_at: string | null; media_type: string; original_filename: string;
    primary_album?: { id: number; uuid: string; title: string } | null;
    variants: Array<{ type: string; url: string }>;
}

interface MapAlbum {
    id: number; uuid: string; title: string;
    latitude: number; longitude: number;
    location_name?: string; location_country?: string;
    event_date_start?: string; event_date_end?: string;
    color?: string; media_count: number; cover_thumb?: string;
}

export default function MapIndex() {
    const mapRef     = useRef<HTMLDivElement>(null);
    const mapObj     = useRef<any>(null);
    const [points,       setPoints]       = useState<MapPoint[]>([]);
    const [albums,       setAlbums]       = useState<MapAlbum[]>([]);
    const [selected,     setSelected]     = useState<MapPoint | null>(null);
    const [selectedAlbum, setSelectedAlbum] = useState<MapAlbum | null>(null);
    const [popupPos,     setPopupPos]     = useState<{ x: number; y: number } | null>(null);
    const [mapLoaded,    setMapLoaded]    = useState(false);
    const [showAlbums,   setShowAlbums]   = useState(true);
    const [showPhotos,   setShowPhotos]   = useState(true);

    useEffect(() => {
        axios.get('/api/v1/timeline/map').then(res => {
            setPoints(res.data.points ?? []);
            setAlbums(res.data.albums ?? []);
        });
    }, []);

    useEffect(() => {
        if (!mapRef.current || !mapLoaded) return;
        const allLats = [
            ...(showPhotos ? points.map(p => p.latitude) : []),
            ...(showAlbums ? albums.map(a => a.latitude) : []),
        ];
        if (allLats.length === 0) return;
        const L = (window as any).L;
        if (!L) return;

        if (mapObj.current) { mapObj.current.remove(); mapObj.current = null; }

        const allLngs = [
            ...(showPhotos ? points.map(p => p.longitude) : []),
            ...(showAlbums ? albums.map(a => a.longitude) : []),
        ];

        const cLat = (Math.min(...allLats) + Math.max(...allLats)) / 2;
        const cLng = (Math.min(...allLngs) + Math.max(...allLngs)) / 2;

        const map = L.map(mapRef.current, {
            center: [cLat, cLng],
            zoom: allLats.length === 1 ? 14 : 10,
            zoomControl: true,
        });
        mapObj.current = map;

        addLocalizedBaseLayer(L, map);

        const allBounds = [...(showPhotos ? points : []).map(p => [p.latitude, p.longitude]), ...(showAlbums ? albums : []).map(a => [a.latitude, a.longitude])];
        if (allBounds.length > 1) {
            map.fitBounds(L.latLngBounds(allBounds), { padding: [40, 40] });
        }

        points.forEach(point => {
            const thumb = point.variants?.find(v => v.type === 'thumbnail')?.url;
            const icon = L.divIcon({
                className: '',
                html: thumb
                    ? `<div style="width:44px;height:44px;border-radius:50%;border:3px solid #6c63ff;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.5);cursor:pointer;background:#1a1a2e">
                         <img src="${thumb}" style="width:100%;height:100%;object-fit:cover"/>
                       </div>`
                    : `<div style="width:36px;height:36px;border-radius:50%;background:#6c63ff;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;cursor:pointer">
                         <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21,15 16,10 5,21"/></svg>
                       </div>`,
                iconSize: [44, 44], iconAnchor: [22, 22],
            });

            const marker = L.marker([point.latitude, point.longitude], { icon }).addTo(map);
            marker.on('click', (e: any) => {
                const cp = map.latLngToContainerPoint(e.latlng);
                setPopupPos({ x: cp.x, y: cp.y });
                setSelected(point); setSelectedAlbum(null);
            });
        });

        // Album markers — folder icons
        if (showAlbums) {
            albums.forEach(album => {
                const color = album.color ?? '#22c55e';
                const icon = L.divIcon({
                    className: '',
                    html: album.cover_thumb
                        ? `<div style="width:48px;height:48px;border-radius:8px;border:3px solid ${color};overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.5);cursor:pointer;position:relative">
                             <img src="${album.cover_thumb}" style="width:100%;height:100%;object-fit:cover"/>
                             <div style="position:absolute;bottom:1px;right:2px;font-size:10px">📁</div>
                           </div>`
                        : `<div style="width:42px;height:42px;border-radius:8px;background:${color};border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px">📁</div>`,
                    iconSize: [48, 48], iconAnchor: [24, 24],
                });
                const m = L.marker([album.latitude, album.longitude], { icon }).addTo(map);
                m.on('click', (e: any) => {
                    const cp = map.latLngToContainerPoint(e.latlng);
                    setPopupPos({ x: cp.x, y: cp.y });
                    setSelectedAlbum(album); setSelected(null);
                });
            });
        }

        map.on('click', () => { setSelected(null); setSelectedAlbum(null); setPopupPos(null); });

        return () => { map.remove(); mapObj.current = null; };
    }, [mapLoaded, points, albums, showAlbums, showPhotos]);

    // Load Leaflet
    useEffect(() => {
        if ((window as any).L) { setMapLoaded(true); return; }
        const link = document.createElement('link');
        link.rel = 'stylesheet'; link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => setMapLoaded(true);
        document.head.appendChild(script);
    }, []);

    const thumb = selected?.variants?.find(v => v.type === 'thumbnail')?.url;

    return (
        <AppLayout>
            <Head title="Mapa" />
            <div className="relative h-full flex flex-col">
                {/* Header */}
                <div className="px-4 py-3 border-b border-[var(--color-border)] flex items-center justify-between shrink-0">
                    <h1 className="text-sm font-semibold text-white">Mapa</h1>
                    <div className="flex items-center gap-3 text-xs text-[var(--color-text-secondary)]">
                        <button onClick={() => setShowPhotos(v=>!v)}
                            className={`flex items-center gap-1 px-2 py-1 rounded-lg transition-colors ${showPhotos ? 'bg-[var(--color-accent)]/20 text-[var(--color-accent)]' : 'hover:text-white'}`}>
                            <Image size={11}/> Fotky ({points.length})
                        </button>
                        <button onClick={() => setShowAlbums(v=>!v)}
                            className={`flex items-center gap-1 px-2 py-1 rounded-lg transition-colors ${showAlbums ? 'bg-green-500/20 text-green-400' : 'hover:text-white'}`}>
                            <FolderOpen size={11}/> Alba ({albums.length})
                        </button>
                    </div>
                </div>

                {/* Map */}
                <div className="flex-1 relative">
                    <div ref={mapRef} className="w-full h-full" />

                    {!mapLoaded && (
                        <div className="absolute inset-0 flex items-center justify-center bg-[var(--color-bg-primary)]">
                            <div className="flex flex-col items-center gap-2 text-[var(--color-text-secondary)]">
                                <div className="w-6 h-6 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin" />
                                <span className="text-sm">Načítám mapu…</span>
                            </div>
                        </div>
                    )}

                    {mapLoaded && points.length === 0 && (
                        <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                            <div className="bg-[var(--color-bg-secondary)]/90 backdrop-blur rounded-xl p-6 text-center">
                                <MapPin size={32} className="mx-auto mb-2 text-[var(--color-text-secondary)]" />
                                <p className="text-white font-medium">Žádné fotky s GPS</p>
                                <p className="text-xs text-[var(--color-text-secondary)] mt-1">Nahrajte fotky s GPS daty (HEIC z mobilu)</p>
                            </div>
                        </div>
                    )}

                    {/* Popup card */}
                    {selected && popupPos && (
                        <div className="absolute z-[1000] pointer-events-auto"
                            style={{
                                left: Math.min(popupPos.x + 24, (mapRef.current?.clientWidth ?? 600) - 280),
                                top:  Math.max(popupPos.y - 160, 8),
                            }}>
                            <div className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-xl shadow-2xl w-64 overflow-hidden">
                                <div className="relative aspect-video bg-[var(--color-bg-card)]">
                                    {thumb
                                        ? <img src={thumb} alt="" className="w-full h-full object-cover" />
                                        : <div className="w-full h-full flex items-center justify-center"><Image size={28} className="text-[var(--color-text-secondary)]" /></div>
                                    }
                                    <button onClick={() => { setSelected(null); setPopupPos(null); }}
                                        className="absolute top-2 right-2 w-6 h-6 rounded-full bg-black/60 flex items-center justify-center text-white hover:bg-black/80">
                                        <X size={12} />
                                    </button>
                                </div>
                                <div className="p-3 space-y-2">
                                    <p className="text-xs text-white font-medium">
                                        {selected.taken_at
                                            ? new Date(selected.taken_at).toLocaleDateString('cs-CZ', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
                                            : 'Neznámé datum'}
                                    </p>
                                    <p className="text-[10px] text-[var(--color-text-secondary)] font-mono">
                                        {selected.latitude.toFixed(6)}°, {selected.longitude.toFixed(6)}°
                                    </p>
                                    {selected.primary_album && (
                                        <Link href={`/albums/${selected.primary_album.uuid}`}
                                            className="flex items-center gap-1.5 text-xs text-[var(--color-accent)] hover:underline"
                                            onClick={() => setSelected(null)}>
                                            <MapPin size={10} /> {selected.primary_album.title}
                                        </Link>
                                    )}
                                    <div className="flex gap-2 pt-1">
                                        <Link href={`/media/${selected.uuid}`}
                                            className="flex-1 flex items-center justify-center gap-1.5 bg-[var(--color-accent)] text-white text-xs py-1.5 rounded-lg hover:opacity-90"
                                            onClick={() => setSelected(null)}>
                                            <ExternalLink size={11} /> Otevřít
                                        </Link>
                                        <a href={`https://maps.google.com/maps?q=${selected.latitude},${selected.longitude}`}
                                            target="_blank" rel="noopener noreferrer"
                                            className="flex items-center justify-center gap-1.5 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-xs py-1.5 px-3 rounded-lg transition-colors">
                                            <MapPin size={11} /> GMaps
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Album popup card */}
                    {selectedAlbum && popupPos && (
                        <div className="absolute z-[1000] pointer-events-auto"
                            style={{
                                left: Math.min(popupPos.x + 24, (mapRef.current?.clientWidth ?? 600) - 280),
                                top:  Math.max(popupPos.y - 160, 8),
                            }}>
                            <div className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-xl shadow-2xl w-64 overflow-hidden">
                                <div className="relative aspect-video bg-[var(--color-bg-card)]">
                                    {selectedAlbum.cover_thumb
                                        ? <img src={selectedAlbum.cover_thumb} alt="" className="w-full h-full object-cover"/>
                                        : <div className="w-full h-full flex items-center justify-center text-4xl opacity-30">📁</div>
                                    }
                                    <button onClick={() => { setSelectedAlbum(null); setPopupPos(null); }}
                                        className="absolute top-2 right-2 w-6 h-6 rounded-full bg-black/60 flex items-center justify-center text-white hover:bg-black/80">
                                        <X size={12}/>
                                    </button>
                                    <div className="absolute bottom-2 left-2 bg-black/60 text-white text-[10px] px-2 py-0.5 rounded-full">
                                        📁 Album
                                    </div>
                                </div>
                                <div className="p-3 space-y-1.5">
                                    <p className="text-sm text-white font-semibold">{selectedAlbum.title}</p>
                                    {(selectedAlbum.location_name || selectedAlbum.location_country) && (
                                        <p className="text-xs text-[var(--color-text-secondary)] flex items-center gap-1">
                                            <MapPin size={10}/> {selectedAlbum.location_name}{selectedAlbum.location_country ? `, ${selectedAlbum.location_country}` : ''}
                                        </p>
                                    )}
                                    {selectedAlbum.event_date_start && (
                                        <p className="text-[10px] text-[var(--color-text-secondary)]">
                                            {new Date(selectedAlbum.event_date_start).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' })}
                                        </p>
                                    )}
                                    <p className="text-[10px] text-[var(--color-text-secondary)]">📸 {selectedAlbum.media_count} médií</p>
                                    <div className="flex gap-2 pt-1">
                                        <Link href={`/albums/${selectedAlbum.uuid}`}
                                            className="flex-1 flex items-center justify-center gap-1.5 bg-[var(--color-accent)] text-white text-xs py-1.5 rounded-lg hover:opacity-90"
                                            onClick={() => setSelectedAlbum(null)}>
                                            <FolderOpen size={11}/> Otevřít album
                                        </Link>
                                        <a href={`https://maps.google.com/maps?q=${selectedAlbum.latitude},${selectedAlbum.longitude}`}
                                            target="_blank" rel="noopener noreferrer"
                                            className="flex items-center justify-center gap-1.5 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-xs py-1.5 px-3 rounded-lg transition-colors">
                                            <MapPin size={11}/> GMaps
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
