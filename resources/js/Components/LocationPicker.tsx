/**
 * Výběr místa.
 *
 * Dřív to bylo políčko, které při psaní posílalo dotaz na Nominatim a vrátilo osm holých
 * řádků. U galerie dvojice je to špatné pořadí otázky: většina fotek nevzniká na náhodných
 * místech světa, ale tam, kde už jednou byli. Ta místa aplikace zná, takže se nabízejí
 * první — a s vlastními jmény, která jim ti dva dali.
 *
 * Co k tomu ještě patřilo a chybělo:
 *
 * Souřadnice vlepené do políčka se přijmou tak, jak jsou. Lidé je kopírují z map i ze
 * zpráv a nutit je k převodu na jméno je práce navíc bez užitku.
 *
 * Hledá se v okolí bodu, který volající zná — u fotky je to místo sousedního snímku.
 * „Náměstí" jich je v každé zemi sto a to největší v republice skoro nikdy není to hledané.
 *
 * A pod nabídkou je ještě jedna možnost: píchnout do mapy. Adresa se k bodu dohledá zpětně,
 * což je jediná cesta u míst, která žádné jméno nemají — louka, vyhlídka, tábořiště.
 */

import axios from 'axios';
import { Crosshair, Loader2, MapPin, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface LocationValue {
    location_name:         string;
    latitude:              number | '';
    longitude:             number | '';
    location_country?:     string;
    location_country_code?: string;
}

interface Suggestion {
    id: number | null;
    name: string;
    label: string;
    detail: string;
    latitude: number;
    longitude: number;
    country: string | null;
    country_code: string | null;
    city: string | null;
    address: string | null;
    category: string;
    source?: string;
}

interface Props {
    value:    LocationValue;
    onChange: (v: LocationValue) => void;
    label?:   string;
    compact?: boolean;
    className?: string;
    /** Bod, kolem kterého hledat — u fotky poloha sousedního snímku. */
    near?:    { lat: number; lon: number } | null;
}

const IKONA: Record<string, string> = {
    city: '🏙️', country: '🌍', landmark: '🗺️', food: '☕', culture: '🏛️',
    nature: '🌿', stay: '🛏️', transport: '🚉', address: '🏠', shop: '🛍️',
    coordinates: '🎯', saved: '⭐', other: '📍',
};

export default function LocationPicker({ value, onChange, label, compact = false, className = '', near = null }: Props) {
    const [query, setQuery] = useState(value.location_name ?? '');
    const [results, setResults] = useState<Suggestion[]>([]);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [locating, setLocating] = useState(false);
    const [note, setNote] = useState('');

    // Poslední dotaz přeruší ten předchozí: bez toho doběhne odpověď na „Pra" po odpovědi
    // na „Praha" a nabídka skočí zpátky na výsledky, které už nikdo nechtěl.
    const abort = useRef<AbortController | null>(null);
    const timer = useRef<number | null>(null);

    useEffect(() => () => {
        if (timer.current) window.clearTimeout(timer.current);
        abort.current?.abort();
    }, []);

    const search = useCallback((text: string) => {
        if (timer.current) window.clearTimeout(timer.current);

        if (text.trim().length < 2) {
            setResults([]);
            setOpen(false);

            return;
        }

        // Nominatim si vyhrazuje nejvýš jeden dotaz za vteřinu a psaní jich vyrobí dvacet.
        // Slušnost ke službě, na které stojíme, je levnější než hledat náhradu.
        timer.current = window.setTimeout(async () => {
            abort.current?.abort();
            abort.current = new AbortController();
            setBusy(true);

            try {
                const response = await axios.get('/api/v1/mista/napoveda', {
                    params: { q: text, lat: near?.lat, lon: near?.lon },
                    signal: abort.current.signal,
                });

                setResults(response.data.results ?? []);
                setOpen(true);
            } catch {
                // Přerušený dotaz ani vypadlá cizí služba nejsou chyba, kterou by měl
                // někdo řešit — nabídka prostě zůstane, jaká byla.
            } finally {
                setBusy(false);
            }
        }, 450);
    }, [near?.lat, near?.lon]);

    const pick = (place: Suggestion) => {
        setQuery(place.name);
        setOpen(false);
        setNote('');
        onChange({
            location_name: place.name,
            latitude: place.latitude,
            longitude: place.longitude,
            location_country: place.country ?? '',
            location_country_code: place.country_code ?? '',
        });
    };

    const clear = () => {
        setQuery('');
        setResults([]);
        setOpen(false);
        setNote('');
        onChange({ location_name: '', latitude: '', longitude: '', location_country: '', location_country_code: '' });
    };

    /**
     * Poloha ze zařízení, dohledaná na adresu.
     *
     * U fotky pořízené před chvílí je „kde jsem teď" skoro vždycky správná odpověď a
     * ušetří psaní.
     */
    const useMyPosition = () => {
        if (! navigator.geolocation) { setNote('Prohlížeč polohu neposkytuje.'); return; }

        setLocating(true);
        setNote('');

        navigator.geolocation.getCurrentPosition(
            async position => {
                const { latitude, longitude } = position.coords;

                try {
                    const response = await axios.get('/api/v1/mista/adresa', { params: { lat: latitude, lon: longitude } });
                    const place: Suggestion | null = response.data.result;

                    if (place) pick(place);
                    else pick({
                        id: null, name: `${latitude.toFixed(5)}, ${longitude.toFixed(5)}`, label: '', detail: '',
                        latitude, longitude, country: null, country_code: null, city: null, address: null, category: 'coordinates',
                    });
                } finally {
                    setLocating(false);
                }
            },
            error => {
                setLocating(false);
                setNote(error.code === error.PERMISSION_DENIED
                    ? 'Přístup k poloze je zamítnutý. Povolte ho, nebo místo napište.'
                    : 'Polohu se nepodařilo zjistit.');
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    };

    const chosen = value.latitude !== '' && value.longitude !== '';

    return (
        <div className={`relative ${className}`}>
            {label && <label className="mb-1.5 block text-xs font-medium text-[var(--color-text-secondary)]">{label}</label>}

            <div className="flex gap-2">
                <div className="relative flex-1">
                    <MapPin size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]"/>
                    <input
                        value={query}
                        onChange={event => { setQuery(event.target.value); search(event.target.value); }}
                        onFocus={() => results.length && setOpen(true)}
                        placeholder="Město, podnik, adresa nebo 50.0755, 14.4378"
                        className={`w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-primary)] py-2 pl-9 text-sm text-[var(--color-text-primary)] placeholder-[var(--color-text-secondary)] focus:border-[var(--color-accent)] focus:outline-none ${chosen || query ? 'pr-9' : 'pr-3'}`}
                    />

                    {busy && <Loader2 size={14} className="absolute right-3 top-1/2 -translate-y-1/2 animate-spin text-[var(--color-text-secondary)]"/>}
                    {! busy && (chosen || query) && (
                        <button type="button" onClick={clear} title="Vymazat"
                            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
                            <X size={13}/>
                        </button>
                    )}
                </div>

                <button type="button" onClick={useMyPosition} disabled={locating} title="Použít moji polohu"
                    className="shrink-0 rounded-lg border border-[var(--color-border)] px-3 text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-accent)]/60 hover:text-[var(--color-text-primary)] disabled:opacity-50">
                    {locating ? <Loader2 size={15} className="animate-spin"/> : <Crosshair size={15}/>}
                </button>
            </div>

            {note && <p className="mt-1 text-[11px] text-amber-200">{note}</p>}

            {chosen && ! open && (
                <p className="mt-1 text-[11px] text-[var(--color-text-secondary)]">
                    {Number(value.latitude).toFixed(5)}, {Number(value.longitude).toFixed(5)}
                    {value.location_country ? ` · ${value.location_country}` : ''}
                </p>
            )}

            {open && results.length > 0 && (
                <>
                    {/* Klik mimo nabídku ji zavře. Bez toho zůstane viset přes obsah pod ní. */}
                    <button type="button" aria-hidden className="fixed inset-0 z-10 cursor-default" onClick={() => setOpen(false)}/>

                    <ul className={`absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] shadow-xl ${compact ? 'max-h-56' : 'max-h-72'} overflow-y-auto`}>
                        {results.map((place, index) => {
                            // Vlastní místa nahoře a odlišená: jsou to ta, na která ti dva
                            // opravdu chodí, a mají jména, která jim sami dali.
                            const vlastni = place.source === 'own';
                            const prvniCizi = ! vlastni && index > 0 && results[index - 1].source === 'own';

                            return (
                                <li key={`${place.source}-${place.id ?? index}-${place.latitude}`}>
                                    {prvniCizi && (
                                        <p className="border-t border-[var(--color-border)] px-3 pb-1 pt-2 text-[10px] uppercase tracking-wider text-[var(--color-text-secondary)]">
                                            Z mapy
                                        </p>
                                    )}
                                    <button type="button" onClick={() => pick(place)}
                                        className="flex w-full items-start gap-2.5 px-3 py-2 text-left transition-colors hover:bg-[var(--color-surface-hover)]">
                                        <span className="mt-0.5 shrink-0 text-base leading-none">{IKONA[place.category] ?? IKONA.other}</span>
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-1.5">
                                                <span className="truncate text-sm text-[var(--color-text-primary)]">{place.name}</span>
                                                {vlastni && <span className="shrink-0 rounded bg-amber-400/15 px-1.5 py-0.5 text-[9px] text-amber-200">uloženo</span>}
                                            </span>
                                            <span className="block truncate text-[11px] text-[var(--color-text-secondary)]">{place.detail}</span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}
        </div>
    );
}
