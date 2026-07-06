/**
 * LocationPicker — Reusable location input with Nominatim autocomplete.
 * Searches world-wide cities, countries, landmarks.
 * Used in: Albums/Create, Albums/Show (edit), and anywhere location is needed.
 */

import axios from 'axios';
import { MapPin, RefreshCw, X } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

export interface LocationValue {
    location_name:         string;
    latitude:              number | '';
    longitude:             number | '';
    location_country?:     string;
    location_country_code?: string;
}

interface NominatimResult {
    name:         string;
    display_name: string;
    country:      string;
    country_code: string;
    latitude:     number;
    longitude:    number;
    category:     string;
}

interface Props {
    value:    LocationValue;
    onChange: (v: LocationValue) => void;
    label?:   string;
    compact?: boolean;
    className?: string;
}

const CAT_EMOJI: Record<string, string> = {
    city: '🏙️', country: '🌍', landmark: '🗺️',
    restaurant: '🍽️', museum: '🏛️', nature: '🌿', other: '📍',
};

export default function LocationPicker({ value, onChange, label = 'Lokalita', compact = false, className = '' }: Props) {
    const [query,    setQuery]    = useState(value.location_name ?? '');
    const [results,  setResults]  = useState<NominatimResult[]>([]);
    const [loading,  setLoading]  = useState(false);
    const [showDrop, setShowDrop] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const handleInput = useCallback((val: string) => {
        setQuery(val);
        onChange({ ...value, location_name: val });
        if (timer.current) clearTimeout(timer.current);
        if (val.length < 2) { setResults([]); setShowDrop(false); return; }
        timer.current = setTimeout(async () => {
            setLoading(true);
            try {
                const r = await axios.get('/api/v1/itinerary/search', { params: { q: val } });
                setResults(r.data ?? []);
                setShowDrop(true);
            } catch { /* ignore */ }
            finally { setLoading(false); }
        }, 400);
    }, [value, onChange]);

    const select = (r: NominatimResult) => {
        setQuery(r.name || r.display_name);
        setShowDrop(false);
        onChange({
            location_name:          r.name || r.display_name,
            latitude:               r.latitude,
            longitude:              r.longitude,
            location_country:       r.country,
            location_country_code:  r.country_code,
        });
    };

    const clear = () => {
        setQuery('');
        setResults([]);
        setShowDrop(false);
        onChange({ location_name: '', latitude: '', longitude: '', location_country: '', location_country_code: '' });
    };

    const inputCls = `w-full rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white placeholder-[var(--color-text-secondary)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors pl-7 pr-7`;

    return (
        <div className={`relative ${className}`}>
            {label && (
                <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                    <MapPin size={11} className="inline mr-1"/>{label}
                </label>
            )}

            {/* Search input */}
            <div className="relative">
                <MapPin size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                <input
                    value={query}
                    onChange={e => handleInput(e.target.value)}
                    onFocus={() => results.length > 0 && setShowDrop(true)}
                    onBlur={() => setTimeout(() => setShowDrop(false), 150)}
                    placeholder="Hledat město, stát, místo…"
                    className={inputCls}
                />
                {loading && <RefreshCw size={12} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] animate-spin"/>}
                {!loading && query && (
                    <button type="button" onMouseDown={e => e.preventDefault()} onClick={clear}
                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] hover:text-white">
                        <X size={12}/>
                    </button>
                )}

                {/* Dropdown */}
                {showDrop && results.length > 0 && (
                    <div className="absolute z-50 top-full mt-1 left-0 right-0 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden max-h-52 overflow-y-auto">
                        {results.map((r, i) => (
                            <button key={i} type="button"
                                onMouseDown={e => { e.preventDefault(); select(r); }}
                                className="w-full text-left px-3 py-2.5 hover:bg-[var(--color-bg-secondary)] border-b border-[var(--color-border)] last:border-0 flex items-start gap-2 transition-colors">
                                <span className="text-base shrink-0 mt-0.5">{CAT_EMOJI[r.category] ?? '📍'}</span>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-medium text-white truncate">{r.name || r.display_name}</p>
                                    <p className="text-[10px] text-[var(--color-text-secondary)] truncate">{r.country}</p>
                                </div>
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {/* GPS coordinates (shown when location is selected) */}
            {value.latitude && value.longitude && !compact && (
                <div className="mt-1.5 flex items-center gap-3 text-[10px] text-[var(--color-text-secondary)]">
                    <span className="font-mono">{Number(value.latitude).toFixed(5)}°, {Number(value.longitude).toFixed(5)}°</span>
                    {value.location_country && <span>· {value.location_country}</span>}
                    <a href={`https://www.google.com/maps?q=${value.latitude},${value.longitude}`}
                        target="_blank" rel="noopener noreferrer"
                        className="text-[var(--color-accent)] hover:underline ml-auto">
                        Ověřit ↗
                    </a>
                </div>
            )}

            {/* Manual lat/lng fallback */}
            {!compact && (
                <div className="grid grid-cols-2 gap-2 mt-2">
                    <input
                        type="number" step="any"
                        value={value.latitude ?? ''}
                        onChange={e => onChange({ ...value, latitude: e.target.value ? parseFloat(e.target.value) : '' })}
                        placeholder="Zeměpisná šířka"
                        className="rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white placeholder-[var(--color-text-secondary)] px-3 py-1.5 text-xs focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                    />
                    <input
                        type="number" step="any"
                        value={value.longitude ?? ''}
                        onChange={e => onChange({ ...value, longitude: e.target.value ? parseFloat(e.target.value) : '' })}
                        placeholder="Zeměpisná délka"
                        className="rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white placeholder-[var(--color-text-secondary)] px-3 py-1.5 text-xs focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                    />
                </div>
            )}
        </div>
    );
}
