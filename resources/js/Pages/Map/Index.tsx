import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ExternalLink, Image, MapPin, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface MapPoint {
    id: number; uuid: string; latitude: number; longitude: number;
    taken_at: string | null; media_type: string; original_filename: string;
    primary_album?: { id: number; uuid: string; title: string } | null;
    variants: Array<{ type: string; url: string }>;
}

export default function MapIndex() {
    const mapRef    = useRef<HTMLDivElement>(null);
    const mapObj    = useRef<any>(null);
    const [points, setPoints]     = useState<MapPoint[]>([]);
    const [selected, setSelected] = useState<MapPoint | null>(null);
    const [popupPos, setPopupPos] = useState<{ x: number; y: number } | null>(null);
    const [mapLoaded, setMapLoaded] = useState(false);

    useEffect(() => {
        axios.get('/api/v1/timeline/map').then(res => setPoints(res.data.points ?? []));
    }, []);

    useEffect(() => {
        if (!mapRef.current || !mapLoaded || points.length === 0) return;
        const L = (window as any).L;
        if (!L) return;

        if (mapObj.current) { mapObj.current.remove(); mapObj.current = null; }

        const lats = points.map(p => p.latitude);
        const lngs = points.map(p => p.longitude);
        const cLat  = (Math.min(...lats) + Math.max(...lats)) / 2;
        const cLng  = (Math.min(...lngs) + Math.max(...lngs)) / 2;

        const map = L.map(mapRef.current, {
            center: [cLat, cLng],
            zoom: points.length === 1 ? 14 : 10,
            zoomControl: true,
        });
        mapObj.current = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors', maxZoom: 19,
        }).addTo(map);

        if (points.length > 1) {
            map.fitBounds(L.latLngBounds(points.map(p => [p.latitude, p.longitude])), { padding: [40, 40] });
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
                setSelected(point);
            });
        });

        map.on('click', () => { setSelected(null); setPopupPos(null); });

        return () => { map.remove(); mapObj.current = null; };
    }, [mapLoaded, points]);

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
                    <div className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)]">
                        <MapPin size={12} />
                        <span>{points.length} fotografií s GPS</span>
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
                </div>
            </div>
        </AppLayout>
    );
}

