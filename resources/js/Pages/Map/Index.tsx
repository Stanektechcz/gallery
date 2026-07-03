import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Image, MapPin } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface MapPoint {
    id: number;
    uuid: string;
    latitude: number;
    longitude: number;
    taken_at: string | null;
    media_type: string;
    variants: Array<{ type: string; url: string }>;
}

export default function MapIndex() {
    const mapRef = useRef<HTMLDivElement>(null);
    const [points, setPoints] = useState<MapPoint[]>([]);
    const [selected, setSelected] = useState<MapPoint | null>(null);
    const [mapLoaded, setMapLoaded] = useState(false);

    useEffect(() => {
        axios.get('/api/v1/timeline/map').then(res => {
            setPoints(res.data.points ?? []);
        });
    }, []);

    useEffect(() => {
        if (!mapRef.current || !mapLoaded) return;

        // Initialize Leaflet map
        const L = (window as any).L;
        if (!L) return;

        const map = L.map(mapRef.current, {
            center: [50.08, 14.44], // Prague default
            zoom: 8,
            zoomControl: true,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        // Add markers
        for (const point of points) {
            const marker = L.circleMarker([point.latitude, point.longitude], {
                radius: 6,
                fillColor: '#6c63ff',
                color: '#fff',
                weight: 2,
                opacity: 1,
                fillOpacity: 0.9,
            }).addTo(map);

            marker.on('click', () => setSelected(point));
        }

        return () => map.remove();
    }, [mapLoaded, points]);

    // Load Leaflet dynamically
    useEffect(() => {
        const link = document.createElement('link');
        link.rel  = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);

        const script = document.createElement('script');
        script.src  = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => setMapLoaded(true);
        document.head.appendChild(script);

        return () => {
            document.head.removeChild(link);
            document.head.removeChild(script);
        };
    }, []);

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
                            <div className="text-[var(--color-text-secondary)]">Načítám mapu…</div>
                        </div>
                    )}

                    {/* Selected photo popup */}
                    {selected && (
                        <div className="absolute bottom-4 left-1/2 -translate-x-1/2 z-50 glass rounded-xl p-3 flex items-center gap-3 min-w-48 max-w-xs shadow-xl">
                            {selected.variants?.find(v => v.type === 'thumbnail')?.url ? (
                                <img
                                    src={selected.variants.find(v => v.type === 'thumbnail')!.url}
                                    className="w-14 h-14 rounded-lg object-cover shrink-0"
                                    alt=""
                                />
                            ) : (
                                <div className="w-14 h-14 rounded-lg bg-[var(--color-bg-card)] flex items-center justify-center shrink-0">
                                    <Image size={20} className="text-[var(--color-text-secondary)]" />
                                </div>
                            )}
                            <div className="flex-1 min-w-0">
                                <p className="text-xs text-white font-medium truncate">
                                    {selected.taken_at
                                        ? new Date(selected.taken_at).toLocaleDateString('cs-CZ')
                                        : 'Neznámé datum'}
                                </p>
                                <p className="text-xs text-[var(--color-text-secondary)]">
                                    {selected.latitude.toFixed(4)}, {selected.longitude.toFixed(4)}
                                </p>
                                <a
                                    href={`/media/${selected.uuid}`}
                                    className="text-xs text-[var(--color-accent)] hover:underline"
                                >
                                    Zobrazit detail →
                                </a>
                            </div>
                            <button onClick={() => setSelected(null)} className="text-[var(--color-text-secondary)] hover:text-white shrink-0">✕</button>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
