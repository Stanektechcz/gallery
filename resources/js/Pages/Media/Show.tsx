import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { clsx } from 'clsx';
import {
    Archive,
    Camera,
    ChevronLeft, ChevronRight,
    Clock,
    Download,
    ExternalLink,
    Heart,
    Info, MapPin,
    Star,
    Tag,
    Trash2,
    Users
} from 'lucide-react';
import { useState } from 'react';

interface Variant {
    id: number;
    type: string;
    url: string;
    width?: number;
    height?: number;
    format?: string;
    dominant_color?: string;
    blur_hash?: string;
}

interface Tag { id: number; name: string; slug: string; color?: string }
interface Person { id: number; name: string }
interface Place { id: number; name: string; city?: string; country?: string; latitude?: number; longitude?: number }

interface MediaItem {
    id: number;
    uuid: string;
    gallery_space_id: number;
    media_type: 'photo' | 'video';
    original_filename: string;
    display_title?: string;
    description?: string;
    caption?: string;
    notes?: string;
    extension: string;
    mime_type: string;
    size_bytes: number;
    width?: number;
    height?: number;
    duration_ms?: number;
    taken_at?: string;
    taken_at_timezone?: string;
    latitude?: number;
    longitude?: number;
    altitude?: number;
    camera_make?: string;
    camera_model?: string;
    lens_model?: string;
    iso?: number;
    aperture?: string;
    shutter_speed?: string;
    focal_length?: string;
    rating?: number;
    is_favorite: boolean;
    is_archived: boolean;
    trashed_at?: string;
    status: string;
    variants: Variant[];
    tags: Tag[];
    people: Person[];
    places: Place[];
}

interface Props {
    media: MediaItem;
    breadcrumb: Array<{ id: number; uuid: string; title: string }>;
    prev: { id: number; uuid: string } | null;
    next: { id: number; uuid: string } | null;
}

function formatBytes(b: number): string {
    if (b < 1024) return `${b} B`;
    if (b < 1024 ** 2) return `${(b / 1024).toFixed(0)} KB`;
    if (b < 1024 ** 3) return `${(b / 1024 ** 2).toFixed(1)} MB`;
    return `${(b / 1024 ** 3).toFixed(2)} GB`;
}

function formatDate(d?: string): string {
    if (!d) return '—';
    return new Date(d).toLocaleString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function ProgressiveImage({ uuid, fullUrl, thumbUrl, alt, width, height, dominantColor }: {
    uuid: string; fullUrl: string; thumbUrl?: string;
    alt: string; width?: number; height?: number; dominantColor?: string;
}) {
    const [loaded, setLoaded] = useState(false);
    const [error, setError]   = useState(false);

    return (
        <div className="relative max-h-full max-w-full flex items-center justify-center">
            {/* Color placeholder */}
            {dominantColor && !loaded && (
                <div className="absolute inset-0 rounded" style={{ backgroundColor: dominantColor }} />
            )}
            {/* Blurred thumb (instant) */}
            {thumbUrl && !loaded && (
                <img src={thumbUrl} alt="" aria-hidden className="absolute inset-0 w-full h-full object-contain blur-sm opacity-60 transition-opacity duration-300" />
            )}
            {/* Full resolution (from Drive or local) */}
            {!error ? (
                <img
                    key={uuid}
                    src={fullUrl}
                    alt={alt}
                    onLoad={() => setLoaded(true)}
                    onError={() => { setError(true); }}
                    className={`max-h-[calc(100vh-120px)] max-w-full object-contain relative z-10 transition-opacity duration-500 ${loaded ? 'opacity-100' : 'opacity-0'}`}
                    style={{ aspectRatio: width && height ? `${width}/${height}` : undefined }}
                />
            ) : thumbUrl ? (
                <img
                    src={thumbUrl}
                    alt={alt}
                    className="max-h-[calc(100vh-120px)] max-w-full object-contain relative z-10"
                    style={{ aspectRatio: width && height ? `${width}/${height}` : undefined }}
                />
            ) : (
                <div className="flex flex-col items-center gap-2 text-[var(--color-text-secondary)]">
                    <Clock size={24} />
                    <p className="text-sm">Fotografie není dostupná</p>
                </div>
            )}
            {/* Loading spinner */}
            {!loaded && !error && (
                <div className="absolute bottom-4 right-4 z-20">
                    <div className="w-4 h-4 rounded-full border-2 border-white/40 border-t-white animate-spin" />
                </div>
            )}
        </div>
    );
}

export default function MediaShow({ media, breadcrumb, prev, next }: Props) {
    const [item, setItem]        = useState(media);
    const [infoOpen, setInfo]    = useState(false);
    const [rating, setRating]    = useState(media.rating ?? 0);
    const [hovRating, setHovR]   = useState(0);
    const [saving, setSaving]    = useState(false);

    const largeVar    = item.variants.find(v => v.type === 'large')
                    ?? item.variants.find(v => v.type === 'medium')
                    ?? item.variants.find(v => v.type === 'small')
                    ?? item.variants.find(v => v.type === 'thumbnail')
                    ?? item.variants.find(v => v.type === 'original');

    const originalVar = item.variants.find(v => v.type === 'original');
    const placeholder = item.variants.find(v => v.type === 'placeholder');
    const compatVideo = item.variants.find(v => v.type === 'video_compat');
    const poster      = item.variants.find(v => v.type === 'video_poster') ?? item.variants.find(v => v.type === 'thumbnail');

    // Full-res URL: always stream via /media/{uuid}/full (local first, Drive fallback)
    const fullUrl  = `/media/${item.uuid}/full`;
    // Thumb for progressive loading placeholder
    const thumbUrl = item.variants.find(v => v.type === 'thumbnail')?.url
                  ?? item.variants.find(v => v.type === 'original')?.url;

    const toggleFavorite = async () => {
        setSaving(true);
        try {
            const res = await axios.post(`/api/v1/favorites/${item.uuid}/toggle`);
            setItem(prev => ({ ...prev, is_favorite: res.data.is_favorite }));
        } finally {
            setSaving(false);
        }
    };

    const setRatingValue = async (val: number) => {
        const newVal = val === rating ? 0 : val;
        setSaving(true);
        try {
            await axios.patch(`/api/v1/media/${item.uuid}`, { rating: newVal });
            setRating(newVal);
            setItem(prev => ({ ...prev, rating: newVal }));
        } finally {
            setSaving(false);
        }
    };

    const archiveItem = async () => {
        const res = await axios.post(`/media/${item.uuid}/archive`);
        if (res.data.is_archived) {
            router.visit('/archive');
        }
    };

    const trashItem = async () => {
        if (!confirm('Přesunout do koše?')) return;
        try {
            await axios.delete(`/media/${item.uuid}`);
            router.visit('/timeline');
        } catch (e: any) {
            const msg = e?.response?.data?.message ?? e?.message ?? 'Chyba p\u0159i p\u0159esunu do ko\u0161e';
            alert(msg);
        }
    };

    const downloadItem = () => {
        window.open(`/media/${item.uuid}/download?original=1`, '_blank');
    };

    const downloadLocal = () => {
        window.open(`/media/${item.uuid}/download`, '_blank');
    };

    return (
        <AppLayout>
            <Head title={item.display_title ?? item.original_filename} />

            <div className="flex flex-col h-full min-h-0">
                {/* Top bar */}
                <div className="shrink-0 px-4 py-2 border-b border-[var(--color-border)] flex items-center justify-between gap-2 bg-[var(--color-bg-secondary)]">
                    {/* Back + breadcrumb */}
                    <div className="flex items-center gap-2 min-w-0">
                        <Link href="/timeline" className="text-[var(--color-text-secondary)] hover:text-white p-1 rounded">
                            <ChevronLeft size={16} />
                        </Link>
                        {breadcrumb.length > 0 && (
                            <div className="flex items-center gap-1 text-xs text-[var(--color-text-secondary)] min-w-0">
                                {breadcrumb.map((crumb, i) => (
                                    <span key={crumb.id} className="flex items-center gap-1">
                                        {i > 0 && <ChevronRight size={10} />}
                                        <Link href={`/albums/${crumb.uuid}`} className="hover:text-white truncate max-w-24">
                                            {crumb.title}
                                        </Link>
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-1 shrink-0">
                        {/* Favorite */}
                        <button
                            onClick={toggleFavorite}
                            disabled={saving}
                            className={clsx('p-2 rounded-lg hover:bg-white/10 transition-colors', item.is_favorite ? 'text-red-400' : 'text-[var(--color-text-secondary)]')}
                        >
                            <Heart size={16} className={item.is_favorite ? 'fill-red-400' : ''} />
                        </button>

                        {/* Rating */}
                        <div className="flex items-center gap-0.5">
                            {[1,2,3,4,5].map(n => (
                                <button
                                    key={n}
                                    onClick={() => setRatingValue(n)}
                                    onMouseEnter={() => setHovR(n)}
                                    onMouseLeave={() => setHovR(0)}
                                    className="p-1 hover:scale-110 transition-transform"
                                >
                                    <Star
                                        size={14}
                                        className={clsx(
                                            'transition-colors',
                                            (hovRating || rating) >= n ? 'text-yellow-400 fill-yellow-400' : 'text-[var(--color-text-secondary)]'
                                        )}
                                    />
                                </button>
                            ))}
                        </div>

                        <div className="w-px h-4 bg-[var(--color-border)] mx-1" />

                        <button onClick={() => setInfo(!infoOpen)} className={clsx('p-2 rounded-lg hover:bg-white/10 transition-colors', infoOpen ? 'text-white bg-white/10' : 'text-[var(--color-text-secondary)]')}>
                            <Info size={16} />
                        </button>
                        <button onClick={downloadItem} title="Stáhnout originál z Drive" className="p-2 rounded-lg hover:bg-white/10 text-[var(--color-text-secondary)] hover:text-white transition-colors">
                            <Download size={16} />
                        </button>
                        <button onClick={downloadLocal} title="Stáhnout lokální kopii" className="p-2 rounded-lg hover:bg-white/10 text-[var(--color-text-secondary)] hover:text-white transition-colors">
                            <ExternalLink size={16} />
                        </button>
                        <button onClick={archiveItem} className="p-2 rounded-lg hover:bg-white/10 text-[var(--color-text-secondary)] hover:text-white transition-colors" title="Archivovat">
                            <Archive size={16} />
                        </button>
                        <button onClick={trashItem} className="p-2 rounded-lg hover:bg-red-500/20 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors">
                            <Trash2 size={16} />
                        </button>
                    </div>
                </div>

                {/* Main content */}
                <div className="flex flex-1 min-h-0 overflow-hidden">
                    {/* Media viewer */}
                    <div className="flex-1 flex items-center justify-center bg-black relative overflow-hidden">
                        {/* Prev / Next */}
                        {prev && (
                            <Link href={`/media/${prev.uuid}`} className="absolute left-3 z-10 p-2 rounded-full bg-black/40 hover:bg-black/60 text-white transition-colors">
                                <ChevronLeft size={20} />
                            </Link>
                        )}
                        {next && (
                            <Link href={`/media/${next.uuid}`} className="absolute right-3 z-10 p-2 rounded-full bg-black/40 hover:bg-black/60 text-white transition-colors">
                                <ChevronRight size={20} />
                            </Link>
                        )}

                        {item.media_type === 'video' ? (
                            <video
                                key={item.uuid}
                                controls
                                className="max-h-full max-w-full"
                                poster={poster?.url}
                                preload="metadata"
                            >
                                {compatVideo && <source src={compatVideo.url} type="video/mp4" />}
                                {originalVar && <source src={originalVar.url} type={item.mime_type} />}
                                <source src={`/media/${item.uuid}/stream`} type={item.mime_type} />
                            </video>
                        ) : item.media_type === 'photo' ? (
                            <ProgressiveImage
                                uuid={item.uuid}
                                fullUrl={fullUrl}
                                thumbUrl={thumbUrl}
                                alt={item.display_title ?? item.original_filename}
                                width={item.width}
                                height={item.height}
                                dominantColor={placeholder?.dominant_color}
                            />
                        ) : (
                            <div className="flex flex-col items-center gap-3 text-[var(--color-text-secondary)]">
                                <div className="w-16 h-16 rounded-xl bg-[var(--color-bg-card)] flex items-center justify-center">
                                    <Clock size={24} />
                                </div>
                                <p className="text-sm">Náhled se připravuje…</p>
                                <p className="text-xs">{item.original_filename}</p>
                            </div>
                        )}
                    </div>

                    {/* Info panel */}
                    {infoOpen && (
                        <div className="w-72 shrink-0 border-l border-[var(--color-border)] bg-[var(--color-bg-secondary)] overflow-y-auto p-4 space-y-4">
                            {/* File */}
                            <section>
                                <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Soubor</h3>
                                <div className="space-y-1.5 text-xs">
                                    <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Název</span><span className="text-white truncate ml-2 max-w-36">{item.original_filename}</span></div>
                                    <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Velikost</span><span className="text-white">{formatBytes(item.size_bytes)}</span></div>
                                    {item.width && item.height && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Rozlišení</span><span className="text-white">{item.width} × {item.height}</span></div>}
                                    {item.duration_ms && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Délka</span><span className="text-white">{Math.round(item.duration_ms / 1000)}s</span></div>}
                                </div>
                            </section>

                            {/* Date */}
                            {item.taken_at && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <Clock size={10} /> Datum
                                    </h3>
                                    <p className="text-xs text-white">{formatDate(item.taken_at)}</p>
                                </section>
                            )}

                            {/* Camera */}
                            {(item.camera_make || item.camera_model) && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <Camera size={10} /> Fotoaparát
                                    </h3>
                                    <div className="space-y-1 text-xs">
                                        {item.camera_make && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Výrobce</span><span className="text-white">{item.camera_make}</span></div>}
                                        {item.camera_model && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Model</span><span className="text-white">{item.camera_model}</span></div>}
                                        {item.lens_model && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Objektiv</span><span className="text-white truncate ml-2 max-w-32">{item.lens_model}</span></div>}
                                        {item.aperture && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Clona</span><span className="text-white">{item.aperture}</span></div>}
                                        {item.shutter_speed && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Čas závěrky</span><span className="text-white">{item.shutter_speed}</span></div>}
                                        {item.iso && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">ISO</span><span className="text-white">{item.iso}</span></div>}
                                        {item.focal_length && <div className="flex justify-between"><span className="text-[var(--color-text-secondary)]">Ohnisko</span><span className="text-white">{item.focal_length}</span></div>}
                                    </div>
                                </section>
                            )}

                            {/* GPS */}
                            {item.latitude && item.longitude && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <MapPin size={10} /> GPS
                                    </h3>
                                    <p className="text-xs text-white">{item.latitude.toFixed(6)}, {item.longitude.toFixed(6)}</p>
                                    <a
                                        href={`https://maps.google.com/maps?q=${item.latitude},${item.longitude}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-xs text-[var(--color-accent)] hover:underline mt-1 inline-block"
                                    >
                                        Otevřít v Google Maps →
                                    </a>
                                </section>
                            )}

                            {/* Tags */}
                            {item.tags.length > 0 && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <Tag size={10} /> Tagy
                                    </h3>
                                    <div className="flex flex-wrap gap-1">
                                        {item.tags.map(tag => (
                                            <span key={tag.id} className="text-[10px] bg-white/10 text-white px-2 py-0.5 rounded-full">
                                                {tag.name}
                                            </span>
                                        ))}
                                    </div>
                                </section>
                            )}

                            {/* People */}
                            {item.people.length > 0 && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2 flex items-center gap-1">
                                        <Users size={10} /> Osoby
                                    </h3>
                                    <div className="flex flex-wrap gap-1">
                                        {item.people.map(person => (
                                            <span key={person.id} className="text-[10px] bg-[var(--color-accent)]/20 text-[var(--color-accent)] px-2 py-0.5 rounded-full">
                                                {person.name}
                                            </span>
                                        ))}
                                    </div>
                                </section>
                            )}

                            {/* Description */}
                            {item.description && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Popis</h3>
                                    <p className="text-xs text-white">{item.description}</p>
                                </section>
                            )}

                            {/* Albums */}
                            {breadcrumb.length > 0 && (
                                <section>
                                    <h3 className="text-xs font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider mb-2">Album</h3>
                                    <Link href={`/albums/${breadcrumb[breadcrumb.length - 1]?.uuid}`} className="text-xs text-[var(--color-accent)] hover:underline">
                                        {breadcrumb.map(b => b.title).join(' / ')}
                                    </Link>
                                </section>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
