import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { localizedCountry } from '@/lib/localizedMap';
import {
    ArrowDown, ArrowUp, CalendarDays, MapPin, Plus, RefreshCw, Search, Trash2, X
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface Place {
    id: number; name: string; type: string; country?: string; city?: string;
    latitude?: number; longitude?: number; radius_meters: number;
    description?: string; website_url?: string;
    photo_count: number; visit_count: number;
    first_visit?: string; last_visit?: string;
    album_count: number; cover_thumb?: string;
    is_rain_friendly?: boolean; is_accessible?: boolean; is_photogenic?: boolean; opens_early?: boolean;
    price_level?: number | null; estimated_visit_minutes?: number | null; personal_rating?: number | null;
    review_count?: number; review_average?: number | null; review_highlights?: {food?:number|null;service?:number|null;speed?:number|null;value?:number|null};
}
interface SearchResult {
    name: string; display_name: string; country: string; country_code: string;
    latitude: number; longitude: number; category: string; type: string;
    address?: string; city?: string; osm_id?: string; osm_type?: string;
}
interface PlannedOuting {
    id: number; uuid: string; title: string; trip_id: number;
    starts_at: string; ends_at: string; duration_minutes: number;
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

const EMPTY_FORM = {
    name: '', type: 'city', country: '', city: '', address: '',
    latitude: '', longitude: '', radius_meters: '500',
    description: '', website_url: '', osm_id: '', osm_type: '',
};

const nextSaturdayMorning = () => {
    const date = new Date();
    const days = (6 - date.getDay() + 7) % 7;
    date.setDate(date.getDate() + (days === 0 && date.getHours() >= 10 ? 7 : days));
    date.setHours(10, 0, 0, 0);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
    return local.toISOString().slice(0, 16);
};

export default function PlacesIndex() {
    const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [places,      setPlaces]      = useState<Place[]>([]);
    const [loading,     setLoading]     = useState(true);
    const [filterType,  setFilterType]  = useState<string>('all');
    const [quickFilter, setQuickFilter] = useState<'all' | 'rain' | 'photo' | 'early' | 'budget' | 'favorite'>('all');
    const [search,      setSearch]      = useState('');
    const [showCreate,  setShowCreate]  = useState(false);
    const [form,        setForm]        = useState({ ...EMPTY_FORM });
    const [creating,    setCreating]    = useState(false);
    const [planningMode, setPlanningMode] = useState(false);
    const [selectedPlaceIds, setSelectedPlaceIds] = useState<number[]>([]);
    const [planning, setPlanning] = useState(false);
    const [planningError, setPlanningError] = useState('');
    const [plannedOuting, setPlannedOuting] = useState<PlannedOuting | null>(null);
    const [outing, setOuting] = useState({
        title: '', starts_at: nextSaturdayMorning(), reminder_minutes: '1440', transfer_minutes: '30',
    });

    // Nominatim autocomplete in create form
    const [nominatimQ,   setNominatimQ]   = useState('');
    const [nominatimRes, setNominatimRes] = useState<SearchResult[]>([]);
    const [nominatimLoad, setNominatimLoad] = useState(false);
    const [showNomDrop,  setShowNomDrop]  = useState(false);

    useEffect(() => {
        axios.get('/api/v1/places').then(r => setPlaces(r.data ?? [])).finally(() => setLoading(false));
    }, []);

    const handleNominatim = useCallback((val: string) => {
        setNominatimQ(val);
        setForm(p => ({ ...p, name: val }));
        if (searchTimer.current) clearTimeout(searchTimer.current);
        if (val.length < 2) { setNominatimRes([]); setShowNomDrop(false); return; }
        searchTimer.current = setTimeout(async () => {
            setNominatimLoad(true);
            try {
                const r = await axios.get('/api/v1/itinerary/search', { params: { q: val } });
                setNominatimRes(r.data ?? []); setShowNomDrop(true);
            } catch { /* ignore */ }
            finally { setNominatimLoad(false); }
        }, 400);
    }, []);

    const selectNominatim = (r: SearchResult) => {
        setNominatimQ(r.name || r.display_name);
        setForm(p => ({
            ...p, name: r.name || r.display_name,
            country: localizedCountry(r.country, r.country_code), latitude: r.latitude.toString(),
            longitude: r.longitude.toString(), osm_id: r.osm_id ?? '',
            osm_type: r.osm_type ?? '',
            city: r.city ?? p.city, address: r.address ?? p.address,
            type: r.category === 'country' ? 'country' : r.category === 'city' ? 'city' : r.category === 'restaurant' ? 'restaurant' : ['landmark','museum','nature'].includes(r.category) ? 'business' : p.type,
        }));
        setNominatimRes([]); setShowNomDrop(false);
    };

    const createPlace = async (e: React.FormEvent) => {
        e.preventDefault(); setCreating(true);
        try {
            const payload = {
                ...form,
                latitude:      form.latitude      ? parseFloat(form.latitude)      : undefined,
                longitude:     form.longitude     ? parseFloat(form.longitude)     : undefined,
                radius_meters: form.radius_meters ? parseInt(form.radius_meters)   : 500,
            };
            const r = await axios.post('/api/v1/places', payload);
            setPlaces(prev => [r.data, ...prev]);
            setForm({ ...EMPTY_FORM }); setNominatimQ(''); setShowCreate(false);
        } finally { setCreating(false); }
    };

    const deletePlace = async (id: number, e: React.MouseEvent) => {
        e.stopPropagation();
        if (!confirm('Smazat místo? Fotografie zůstanou v galerii.')) return;
        await axios.delete(`/api/v1/places/${id}`);
        setPlaces(prev => prev.filter(p => p.id !== id));
    };

    const togglePlace = (id: number) => {
        setPlanningError(''); setPlannedOuting(null);
        setSelectedPlaceIds(current => current.includes(id)
            ? current.filter(placeId => placeId !== id)
            : current.length < 8 ? [...current, id] : current);
    };

    const movePlace = (id: number, direction: -1 | 1) => {
        setSelectedPlaceIds(current => {
            const from = current.indexOf(id); const to = from + direction;
            if (from < 0 || to < 0 || to >= current.length) return current;
            const reordered = [...current];
            [reordered[from], reordered[to]] = [reordered[to], reordered[from]];
            return reordered;
        });
    };

    const createOuting = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedPlaceIds.length) { setPlanningError('Vyberte alespoň jedno místo.'); return; }
        setPlanning(true); setPlanningError(''); setPlannedOuting(null);
        try {
            const response = await axios.post('/api/v1/places/plan-selection', {
                place_ids: selectedPlaceIds,
                starts_at: outing.starts_at,
                title: outing.title || undefined,
                reminder_minutes: Number(outing.reminder_minutes),
                transfer_minutes: Number(outing.transfer_minutes),
            });
            setPlannedOuting(response.data);
        } catch (error) {
            const message = axios.isAxiosError(error)
                ? error.response?.data?.errors?.place_ids?.[0] || error.response?.data?.message
                : null;
            setPlanningError(message || 'Výlet se nepodařilo vytvořit. Zkuste to prosím znovu.');
        } finally { setPlanning(false); }
    };

    const filtered = places.filter(p => {
        if (filterType !== 'all' && p.type !== filterType) return false;
        if (quickFilter === 'rain' && !p.is_rain_friendly) return false;
        if (quickFilter === 'photo' && !p.is_photogenic) return false;
        if (quickFilter === 'early' && !p.opens_early) return false;
        if (quickFilter === 'budget' && (!p.price_level || p.price_level > 2)) return false;
        if (quickFilter === 'favorite' && ((p.review_average ?? p.personal_rating ?? 0) < 4)) return false;
        if (search && !p.name.toLowerCase().includes(search.toLowerCase()) &&
            !p.country?.toLowerCase().includes(search.toLowerCase())) return false;
        return true;
    });

    const typeCounts: Record<string, number> = {};
    places.forEach(p => { typeCounts[p.type] = (typeCounts[p.type] ?? 0) + 1; });

    const fmtDate = (d?: string | null) =>
        d ? new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short', year: 'numeric' }) : null;
    const selectedPlaces = selectedPlaceIds.map(id => places.find(place => place.id === id)).filter((place): place is Place => Boolean(place));

    return (
        <AppLayout>
            <Head title="Místa" />
            <div className="p-6 max-w-6xl mx-auto">

                {/* Header */}
                <div className="flex items-center justify-between mb-6 gap-4 flex-wrap">
                    <div>
                        <h1 className="text-xl font-bold text-white flex items-center gap-2">
                            <MapPin size={20} className="text-[var(--color-accent)]"/> Místa
                        </h1>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
                            {places.length} míst · {places.reduce((s, p) => s + p.photo_count, 0)} fotografií
                        </p>
                    </div>
                    <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                        {/* Search */}
                        <div className="relative">
                            <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                            <input value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Hledat místo…"
                                className="w-full min-w-44 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg pl-8 pr-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] sm:w-52"/>
                        </div>
                        <button onClick={() => { setPlanningMode(value => !value); setPlanningError(''); setPlannedOuting(null); }}
                            className={`flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm transition-colors ${planningMode ? 'border-sky-400 bg-sky-500/15 text-sky-100' : 'border-[var(--color-border)] text-white hover:border-sky-400/60'}`}>
                            <CalendarDays size={14}/> {planningMode ? 'Ukončit výběr' : 'Naplánovat výlet'}
                        </button>
                        <button onClick={() => setShowCreate(v => !v)}
                            className="flex items-center gap-1.5 bg-[var(--color-accent)] text-white text-sm px-3 py-2 rounded-lg hover:opacity-90">
                            <Plus size={14}/> Najít podnik nebo místo
                        </button>
                    </div>
                </div>

                {/* Create form */}
                {showCreate && (
                    <form onSubmit={createPlace} className="mb-6 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4">
                        <h2 className="text-sm font-semibold text-white mb-1">Vyhledat konkrétní podnik nebo místo</h2>
                        <p className="mb-3 text-xs text-[var(--color-text-secondary)]">Našeptávač hledá restaurace, kavárny, bary, hotely i další místa; název lze zadat také ručně.</p>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            {/* Nominatim search / name */}
                            <div className="relative col-span-full sm:col-span-1">
                                <Search size={11} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] pointer-events-none"/>
                                <input value={nominatimQ} onChange={e => handleNominatim(e.target.value)}
                                    onFocus={() => nominatimRes.length > 0 && setShowNomDrop(true)}
                                    onBlur={() => setTimeout(() => setShowNomDrop(false), 150)}
                                    placeholder="Např. Infinit Maximus, kavárna nebo restaurace *" required
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg pl-7 pr-7 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                                {nominatimLoad && <RefreshCw size={11} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] animate-spin"/>}
                                {nominatimQ && !nominatimLoad && <button type="button" onMouseDown={e => e.preventDefault()} onClick={() => { setNominatimQ(''); setForm(p=>({...p,name:''})); setNominatimRes([]); setShowNomDrop(false); }} className="absolute right-2 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)] hover:text-white"><X size={11}/></button>}

                                {showNomDrop && nominatimRes.length > 0 && (
                                    <div className="absolute z-50 top-full mt-1 left-0 right-0 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg shadow-2xl overflow-hidden max-h-48 overflow-y-auto">
                                        {nominatimRes.map((r, i) => (
                                            <button key={i} type="button" onMouseDown={e => { e.preventDefault(); selectNominatim(r); }}
                                                className="w-full text-left px-3 py-2 hover:bg-[var(--color-bg-secondary)] border-b border-[var(--color-border)] last:border-0 flex items-start gap-2">
                                                <span className="text-sm shrink-0">{TYPES[r.category]?.emoji ?? '📍'}</span>
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-xs font-medium text-white truncate">{r.name || r.display_name}</p>
                                                    <p className="text-[10px] text-[var(--color-text-secondary)] truncate">{[r.address, r.city, localizedCountry(r.country, r.country_code)].filter(Boolean).join(' · ')}</p>
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <select value={form.type} onChange={e => setForm(p => ({ ...p, type: e.target.value }))}
                                className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white outline-none focus:border-[var(--color-accent)]">
                                {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v.emoji} {v.label}</option>)}
                            </select>

                            <input value={form.country} onChange={e => setForm(p => ({ ...p, country: e.target.value }))}
                                placeholder="Země" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                            <input value={form.latitude} onChange={e => setForm(p => ({ ...p, latitude: e.target.value }))} type="number" step="any"
                                placeholder="Zeměpisná šířka" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                            <input value={form.longitude} onChange={e => setForm(p => ({ ...p, longitude: e.target.value }))} type="number" step="any"
                                placeholder="Zeměpisná délka" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                            <input value={form.radius_meters} onChange={e => setForm(p => ({ ...p, radius_meters: e.target.value }))} type="number" min="10" max="50000"
                                placeholder="Poloměr (m, výchozí 500)" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>

                            <textarea value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))}
                                placeholder="Popis (volitelně)…" rows={2}
                                className="col-span-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none"/>

                            <input value={form.website_url} onChange={e => setForm(p => ({ ...p, website_url: e.target.value }))} type="url"
                                placeholder="Web (https://…)" className="col-span-full sm:col-span-1 bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                        </div>
                        <div className="flex gap-2 mt-3">
                            <button type="submit" disabled={creating || !form.name}
                                className="bg-[var(--color-accent)] text-white text-sm px-4 py-2 rounded-lg hover:opacity-90 disabled:opacity-40">
                                {creating ? 'Vytvářím…' : 'Vytvořit místo'}
                            </button>
                            <button type="button" onClick={() => { setShowCreate(false); setNominatimQ(''); setForm({ ...EMPTY_FORM }); }}
                                className="border border-[var(--color-border)] text-[var(--color-text-secondary)] text-sm px-4 py-2 rounded-lg hover:text-white">Zrušit</button>
                        </div>
                    </form>
                )}

                {planningMode && (
                    <form onSubmit={createOuting} className="mb-6 rounded-2xl border border-sky-400/30 bg-gradient-to-br from-sky-500/10 to-[var(--color-bg-card)] p-4 sm:p-5">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h2 className="flex items-center gap-2 font-semibold text-white"><CalendarDays size={17} className="text-sky-300"/> Výlet z vašich míst</h2>
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Klepněte na 1–8 míst v pořadí návštěvy. Vznikne sdílená událost, cesta, časový plán a připomínka pro oba.</p>
                            </div>
                            <span className="rounded-full bg-sky-500/15 px-2.5 py-1 text-xs text-sky-100">Vybráno {selectedPlaceIds.length}/8</span>
                        </div>

                        {selectedPlaces.length > 0 ? <div className="mt-4 space-y-2">
                            {selectedPlaces.map((place, index) => <div key={place.id} className="flex items-center gap-2 rounded-xl border border-[var(--color-border)] bg-black/10 p-2">
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-sky-500 text-xs font-bold text-white">{index + 1}</span>
                                <span className="min-w-0 flex-1 truncate text-sm text-white">{TYPES[place.type]?.emoji} {place.name}</span>
                                <span className="hidden text-[10px] text-[var(--color-text-secondary)] sm:inline">{place.estimated_visit_minutes || 90} min</span>
                                <button type="button" disabled={index === 0} onClick={() => movePlace(place.id, -1)} aria-label="Posunout výše" className="rounded p-1 text-[var(--color-text-secondary)] hover:text-white disabled:opacity-20"><ArrowUp size={14}/></button>
                                <button type="button" disabled={index === selectedPlaces.length - 1} onClick={() => movePlace(place.id, 1)} aria-label="Posunout níže" className="rounded p-1 text-[var(--color-text-secondary)] hover:text-white disabled:opacity-20"><ArrowDown size={14}/></button>
                                <button type="button" onClick={() => togglePlace(place.id)} aria-label="Odebrat místo" className="rounded p-1 text-[var(--color-text-secondary)] hover:text-red-300"><X size={14}/></button>
                            </div>)}
                        </div> : <div className="mt-4 rounded-xl border border-dashed border-sky-400/30 px-3 py-5 text-center text-sm text-[var(--color-text-secondary)]">Níže vyberte první místo výletu.</div>}

                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label className="text-xs text-[var(--color-text-secondary)]">Název výletu
                                <input value={outing.title} onChange={e => setOuting(current => ({...current, title: e.target.value}))} maxLength={160} placeholder="Doplní se automaticky" className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 px-3 py-2 text-sm text-white outline-none focus:border-sky-400"/>
                            </label>
                            <label className="text-xs text-[var(--color-text-secondary)]">Začátek
                                <input required type="datetime-local" value={outing.starts_at} onChange={e => setOuting(current => ({...current, starts_at: e.target.value}))} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-black/10 px-3 py-2 text-sm text-white outline-none focus:border-sky-400"/>
                            </label>
                            <label className="text-xs text-[var(--color-text-secondary)]">Přesun mezi místy
                                <select value={outing.transfer_minutes} onChange={e => setOuting(current => ({...current, transfer_minutes: e.target.value}))} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 py-2 text-sm text-white outline-none focus:border-sky-400"><option value="15">15 minut</option><option value="30">30 minut</option><option value="45">45 minut</option><option value="60">60 minut</option><option value="90">90 minut</option></select>
                            </label>
                            <label className="text-xs text-[var(--color-text-secondary)]">Připomenout oběma
                                <select value={outing.reminder_minutes} onChange={e => setOuting(current => ({...current, reminder_minutes: e.target.value}))} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 py-2 text-sm text-white outline-none focus:border-sky-400"><option value="120">2 hodiny předem</option><option value="1440">Den předem</option><option value="10080">Týden předem</option></select>
                            </label>
                        </div>
                        {planningError && <p className="mt-3 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-200">{planningError}</p>}
                        {plannedOuting && <div className="mt-3 flex flex-wrap items-center gap-2 rounded-xl border border-emerald-400/25 bg-emerald-500/10 p-3 text-sm text-emerald-100"><span className="mr-auto">Výlet „{plannedOuting.title}“ je připravený v kalendáři i cestách.</span><Link href={`/calendar/events/${plannedOuting.uuid}`} className="rounded-lg border border-emerald-300/30 px-3 py-1.5 hover:bg-emerald-500/10">Otevřít událost</Link><Link href={`/trips/${plannedOuting.trip_id}/plan`} className="rounded-lg bg-emerald-500 px-3 py-1.5 text-white">Upravit itinerář</Link></div>}
                        <button disabled={planning || !selectedPlaceIds.length} className="mt-4 rounded-lg bg-sky-500 px-4 py-2 text-sm font-medium text-white hover:bg-sky-400 disabled:opacity-40">{planning ? 'Připravuji celý plán…' : `Vytvořit sdílený výlet${selectedPlaceIds.length ? ` z ${selectedPlaceIds.length} míst` : ''}`}</button>
                    </form>
                )}

                {/* Type filter chips */}
                <div className="flex gap-2 mb-5 overflow-x-auto pb-1">
                    <button onClick={() => setFilterType('all')}
                        className={`text-xs px-3 py-1.5 rounded-full whitespace-nowrap transition-colors ${filterType === 'all' ? 'bg-[var(--color-accent)] text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                        Vše ({places.length})
                    </button>
                    {Object.entries(TYPES).filter(([k]) => typeCounts[k]).map(([k, v]) => (
                        <button key={k} onClick={() => setFilterType(k === filterType ? 'all' : k)}
                            className={`text-xs px-3 py-1.5 rounded-full whitespace-nowrap transition-colors ${filterType === k ? 'bg-[var(--color-accent)] text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                            {v.emoji} {v.label} ({typeCounts[k]})
                        </button>
                    ))}
                </div>

                <div className="mb-5 flex gap-2 overflow-x-auto pb-1" aria-label="Rychlé kolekce míst">
                    {([
                        ['all', 'Všechny nápady'], ['rain', '🌧️ Na déšť'], ['photo', '📸 Fotogenické'],
                        ['early', '🌅 Brzy ráno'], ['budget', '💸 Do rozpočtu'], ['favorite', '★ Oblíbené'],
                    ] as const).map(([key, label]) => <button key={key} onClick={() => setQuickFilter(key)} className={`min-h-9 shrink-0 rounded-full px-3 text-xs transition-colors ${quickFilter === key ? 'bg-[var(--color-accent)] text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>{label}</button>)}
                </div>

                {/* Places grid */}
                {loading ? (
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        {[...Array(8)].map((_, i) => <div key={i} className="h-40 bg-[var(--color-bg-card)] rounded-xl animate-pulse"/>)}
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="text-center py-16 text-[var(--color-text-secondary)]">
                        <MapPin size={40} className="mx-auto mb-3 opacity-20"/>
                        <p>Žádná místa{search ? ` pro „${search}"` : ''}</p>
                        <p className="text-sm mt-1 opacity-60">Přidejte první místo tlačítkem Přidat místo</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                        {filtered.map(place => (
                            <div key={place.id}
                                role="button" tabIndex={0}
                                onKeyDown={event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); planningMode ? togglePlace(place.id) : router.visit(`/places/${place.id}`); } }}
                                onClick={() => planningMode ? togglePlace(place.id) : router.visit(`/places/${place.id}`)}
                                className={`group relative cursor-pointer overflow-hidden rounded-xl border bg-[var(--color-bg-card)] transition-all hover:shadow-lg ${selectedPlaceIds.includes(place.id) ? 'border-sky-400 ring-2 ring-sky-400/30' : 'border-[var(--color-border)] hover:border-[var(--color-accent)]/50'}`}>

                                {/* Cover */}
                                <div className="aspect-video relative overflow-hidden bg-[var(--color-bg-secondary)]">
                                    {place.cover_thumb
                                        ? <img src={place.cover_thumb} alt="" className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"/>
                                        : <div className="w-full h-full flex items-center justify-center text-4xl opacity-20">{TYPES[place.type]?.emoji ?? '📍'}</div>
                                    }
                                    {/* Type badge */}
                                    <div className={`absolute top-2 bg-black/50 backdrop-blur-sm text-white text-[10px] px-1.5 py-0.5 rounded-full ${selectedPlaceIds.includes(place.id) ? 'left-10' : 'left-2'}`}>
                                        {TYPES[place.type]?.emoji} {TYPES[place.type]?.label}
                                    </div>
                                    {selectedPlaceIds.includes(place.id) && <span className="absolute left-2 top-2 flex h-6 w-6 items-center justify-center rounded-full bg-sky-500 text-xs font-bold text-white">{selectedPlaceIds.indexOf(place.id) + 1}</span>}
                                    {/* Delete button */}
                                    {!planningMode && <button onClick={e => deletePlace(place.id, e)}
                                        className="absolute top-2 right-2 p-1.5 bg-black/50 backdrop-blur-sm text-white rounded-full opacity-0 group-hover:opacity-100 hover:text-red-400 transition-all">
                                        <Trash2 size={12}/>
                                    </button>}
                                </div>

                                {/* Info */}
                                <div className="p-3">
                                    <h3 className="text-sm font-semibold text-white truncate">{place.name}</h3>
                                    {(place.city || place.country) && (
                                        <p className="text-[10px] text-[var(--color-text-secondary)] truncate mt-0.5">{[place.city, place.country].filter(Boolean).join(', ')}</p>
                                    )}

                                    {(place.is_rain_friendly || place.is_photogenic || place.price_level || place.personal_rating || place.review_average) && <p className="mt-1 text-[9px] text-[var(--color-text-secondary)]">{place.is_rain_friendly ? '🌧️ ' : ''}{place.is_photogenic ? '📸 ' : ''}{(place.review_average ?? place.personal_rating) ? `★${place.review_average ?? place.personal_rating}${place.review_count ? ` (${place.review_count})` : ''}` : ''}{place.price_level ? `${(place.review_average ?? place.personal_rating) ? ' · ' : ''}${'€'.repeat(place.price_level)}` : ''}</p>}

                                    <div className="flex items-center gap-3 mt-2 text-[10px] text-[var(--color-text-secondary)]">
                                        <span>📸 {place.photo_count}</span>
                                        {place.visit_count > 0 && <span>Navštíveno {place.visit_count}×</span>}
                                    </div>

                                    {place.last_visit && (
                                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-1">
                                            Naposledy: {fmtDate(place.last_visit)}
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
