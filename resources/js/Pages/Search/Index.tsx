import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { BookmarkPlus, CalendarDays, Filter, Grid3X3, List, Map, Pin, Search, Sparkles, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

type ViewType = 'grid' | 'timeline' | 'map' | 'calendar' | 'table';
interface SearchFilters {
    q: string; media_type: string; date_from: string; date_to: string; has_gps: boolean;
    min_rating: string; favorites_only: boolean; archived: boolean; extension: string;
    camera: string; orientation: string; sort_by: string; sort_direction: string;
}
interface SavedView { id: number; name: string; icon?: string; filters_json: SearchFilters; view_type: ViewType; is_pinned: boolean; user_id: number; }
interface Suggestion { type: string; id: number; label: string; icon: string; url?: string; filters?: Partial<SearchFilters>; }

const defaultFilters: SearchFilters = {
    q: '', media_type: '', date_from: '', date_to: '', has_gps: false, min_rating: '', favorites_only: false,
    archived: false, extension: '', camera: '', orientation: '', sort_by: 'taken_at', sort_direction: 'desc',
};

const QUICK_FILTERS = [
    { label: 'Oblíbené', patch: { favorites_only: true }, icon: '💜' },
    { label: 'Videa', patch: { media_type: 'video' }, icon: '🎬' },
    { label: 'S polohou', patch: { has_gps: true }, icon: '📍' },
    { label: 'Tento rok', patch: { date_from: `${new Date().getFullYear()}-01-01`, date_to: `${new Date().getFullYear()}-12-31` }, icon: '✨' },
];

function paramsFor(filters: SearchFilters) {
    const params: Record<string, string> = {};
    Object.entries(filters).forEach(([key, value]) => {
        if (value !== '' && value !== false) params[key] = String(value);
    });
    return params;
}

export default function SearchIndex() {
    const [filters, setFilters] = useState<SearchFilters>(defaultFilters);
    const [submitted, setSubmitted] = useState(false);
    const [showFilters, setShowFilters] = useState(false);
    const [viewType, setViewType] = useState<ViewType>('grid');
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [showSave, setShowSave] = useState(false);
    const [viewName, setViewName] = useState('');
    const [savingView, setSavingView] = useState(false);
    const suggestionTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const initialViewApplied = useRef(false);

    const viewsQuery = useQuery({ queryKey: ['saved-searches'], queryFn: async () => (await axios.get('/api/v1/saved-searches')).data as SavedView[] });
    const searchQuery = useQuery({
        queryKey: ['search', filters],
        queryFn: async () => (await axios.get('/api/v1/search', { params: paramsFor(filters) })).data,
        enabled: submitted,
    });

    useEffect(() => {
        if (initialViewApplied.current || !viewsQuery.data) return;
        const id = Number(new URLSearchParams(window.location.search).get('view'));
        const view = viewsQuery.data.find(item => item.id === id);
        if (view) {
            setFilters({ ...defaultFilters, ...view.filters_json });
            setViewType(view.view_type ?? 'grid');
            setSubmitted(true);
            axios.get(`/api/v1/saved-searches/${view.id}`).catch(() => undefined);
        } else {
            const params = new URLSearchParams(window.location.search);
            const patch: Partial<SearchFilters> = {};
            (['q', 'media_type', 'date_from', 'date_to', 'min_rating', 'extension', 'camera', 'orientation', 'sort_by', 'sort_direction'] as const).forEach(key => {
                const value = params.get(key); if (value !== null) patch[key] = value;
            });
            (['has_gps', 'favorites_only', 'archived'] as const).forEach(key => { if (params.has(key)) patch[key] = params.get(key) !== 'false'; });
            if (Object.keys(patch).length > 0) { setFilters(previous => ({ ...previous, ...patch })); setSubmitted(true); }
        }
        initialViewApplied.current = true;
    }, [viewsQuery.data]);

    useEffect(() => {
        if (suggestionTimer.current) clearTimeout(suggestionTimer.current);
        if (filters.q.trim().length < 2) { setSuggestions([]); return; }
        suggestionTimer.current = setTimeout(async () => {
            const response = await axios.get('/api/v1/search/suggestions', { params: { q: filters.q } });
            setSuggestions(response.data ?? []);
            setShowSuggestions(true);
        }, 250);
        return () => { if (suggestionTimer.current) clearTimeout(suggestionTimer.current); };
    }, [filters.q]);

    const activeCount = useMemo(() => Object.entries(filters).filter(([key, value]) => !['q', 'sort_by', 'sort_direction'].includes(key) && value !== '' && value !== false).length, [filters]);
    const results = searchQuery.data?.data ?? [];
    const meta = searchQuery.data?.meta;
    const facets = searchQuery.data?.facets;
    const interpreted = searchQuery.data?.interpreted;

    const search = (event?: React.FormEvent) => {
        event?.preventDefault();
        setShowSuggestions(false);
        setSubmitted(true);
        searchQuery.refetch();
    };
    const applyPatch = (patch: Partial<SearchFilters>) => {
        setFilters(previous => ({ ...previous, ...patch }));
        setSubmitted(true);
        setShowSuggestions(false);
    };
    const applyView = (view: SavedView) => {
        setFilters({ ...defaultFilters, ...view.filters_json });
        setViewType(view.view_type ?? 'grid');
        setSubmitted(true);
        axios.get(`/api/v1/saved-searches/${view.id}`).catch(() => undefined);
    };
    const saveView = async () => {
        if (!viewName.trim()) return;
        setSavingView(true);
        await axios.post('/api/v1/saved-searches', { name: viewName.trim(), filters_json: filters, view_type: viewType, icon: '✨' });
        setViewName(''); setShowSave(false); setSavingView(false); viewsQuery.refetch();
    };
    const togglePin = async (view: SavedView) => {
        await axios.patch(`/api/v1/saved-searches/${view.id}`, { is_pinned: !view.is_pinned });
        viewsQuery.refetch();
    };

    return (
        <AppLayout>
            <Head title="Hledat a objevovat" />
            <div className="mx-auto min-h-full max-w-6xl px-3 py-4 pb-24 sm:px-6 sm:py-7">
                <header className="mb-5">
                    <div className="flex items-center gap-2 text-[var(--color-accent)]"><Sparkles size={16} /><span className="text-xs font-semibold uppercase tracking-wider">Objevovat</span></div>
                    <h1 className="mt-1 text-2xl font-bold text-white">Najděte okamžik, ne soubor</h1>
                    <p className="mt-1 text-sm text-[var(--color-text-secondary)]">Zkuste „videa z Itálie s Maki v létě 2025“ nebo použijte přesné filtry.</p>
                </header>

                <form onSubmit={search} className="relative z-30">
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <div className="relative flex-1">
                            <Search size={19} className="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]" />
                            <input value={filters.q} onChange={event => setFilters(previous => ({ ...previous, q: event.target.value }))}
                                onFocus={() => suggestions.length > 0 && setShowSuggestions(true)}
                                placeholder="Fotky, lidé, místa, cesty, text…"
                                className="min-h-14 w-full rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] pl-12 pr-12 text-base text-white shadow-lg outline-none transition focus:border-[var(--color-accent)]" />
                            {filters.q && <button type="button" onClick={() => setFilters(previous => ({ ...previous, q: '' }))} className="absolute right-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5"><X size={16} /></button>}
                            {showSuggestions && suggestions.length > 0 && (
                                <div className="absolute left-0 right-0 top-full mt-2 overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] shadow-2xl">
                                    <p className="px-4 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-wider text-[var(--color-text-secondary)]">Související obsah</p>
                                    {suggestions.map(suggestion => suggestion.url ? (
                                        <Link key={`${suggestion.type}-${suggestion.id}`} href={suggestion.url} className="flex min-h-12 items-center gap-3 px-4 text-sm text-white hover:bg-white/5"><span>{suggestion.icon}</span><span className="flex-1">{suggestion.label}</span><span className="text-[10px] uppercase text-[var(--color-text-secondary)]">{suggestion.type}</span></Link>
                                    ) : (
                                        <button key={`${suggestion.type}-${suggestion.id}`} type="button" onClick={() => applyPatch({ ...suggestion.filters, q: suggestion.label })} className="flex min-h-12 w-full items-center gap-3 px-4 text-left text-sm text-white hover:bg-white/5"><span>{suggestion.icon}</span><span className="flex-1">{suggestion.label}</span><span className="text-[10px] uppercase text-[var(--color-text-secondary)]">{suggestion.type}</span></button>
                                    ))}
                                </div>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setShowFilters(value => !value)} className={`relative flex min-h-12 flex-1 items-center justify-center gap-2 rounded-2xl border px-4 text-sm sm:min-h-14 sm:flex-none ${showFilters ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/15 text-white' : 'border-[var(--color-border)] bg-[var(--color-bg-card)] text-[var(--color-text-secondary)]'}`}><Filter size={16} /> Filtry{activeCount > 0 && <span className="rounded-full bg-[var(--color-accent)] px-1.5 text-[10px] text-white">{activeCount}</span>}</button>
                            <button type="submit" className="min-h-12 flex-1 rounded-2xl bg-[var(--color-accent)] px-6 text-sm font-semibold text-white sm:min-h-14 sm:flex-none">Hledat</button>
                        </div>
                    </div>
                </form>

                <div className="mt-3 flex gap-2 overflow-x-auto pb-2 scrollbar-hide">
                    {QUICK_FILTERS.map(item => <button key={item.label} onClick={() => applyPatch(item.patch as Partial<SearchFilters>)} className="flex min-h-10 shrink-0 items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] px-3 text-xs text-[var(--color-text-secondary)] hover:text-white"><span>{item.icon}</span>{item.label}</button>)}
                </div>

                {(viewsQuery.data?.length ?? 0) > 0 && (
                    <section className="mt-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]/60 p-3">
                        <div className="mb-2 flex items-center justify-between"><p className="text-xs font-semibold text-white">Vaše pohledy</p><span className="text-[10px] text-[var(--color-text-secondary)]">Jedna kolekce, různé zobrazení</span></div>
                        <div className="flex gap-2 overflow-x-auto scrollbar-hide">
                            {viewsQuery.data?.map(view => <div key={view.id} className="flex shrink-0 overflow-hidden rounded-xl border border-[var(--color-border)]"><button onClick={() => applyView(view)} className="flex min-h-10 items-center gap-2 px-3 text-xs text-white hover:bg-white/5"><span>{view.icon ?? '✨'}</span>{view.name}</button><button onClick={() => togglePin(view)} className={`min-w-9 border-l border-[var(--color-border)] ${view.is_pinned ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'}`}><Pin size={12} className="mx-auto" /></button></div>)}
                        </div>
                    </section>
                )}

                {showFilters && (
                    <section className="mt-4 grid grid-cols-1 gap-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:grid-cols-2 lg:grid-cols-4">
                        <FilterSelect label="Typ" value={filters.media_type} onChange={value => setFilters(p => ({ ...p, media_type: value }))} options={[['', 'Vše'], ['photo', 'Fotografie'], ['video', 'Videa']]} />
                        <FilterSelect label="Orientace" value={filters.orientation} onChange={value => setFilters(p => ({ ...p, orientation: value }))} options={[['', 'Libovolná'], ['landscape', 'Na šířku'], ['portrait', 'Na výšku'], ['square', 'Čtverec']]} />
                        <FilterSelect label="Hodnocení" value={filters.min_rating} onChange={value => setFilters(p => ({ ...p, min_rating: value }))} options={[['', 'Libovolné'], ['3', '3★ a více'], ['4', '4★ a více'], ['5', 'Pouze 5★']]} />
                        <FilterSelect label="Řazení" value={filters.sort_by} onChange={value => setFilters(p => ({ ...p, sort_by: value }))} options={[["taken_at", "Datum pořízení"], ["uploaded_at", "Datum nahrání"], ["rating", "Hodnocení"], ["size_bytes", "Velikost"]]} />
                        <FilterInput label="Od" type="date" value={filters.date_from} onChange={value => setFilters(p => ({ ...p, date_from: value }))} />
                        <FilterInput label="Do" type="date" value={filters.date_to} onChange={value => setFilters(p => ({ ...p, date_to: value }))} />
                        <FilterInput label="Fotoaparát" value={filters.camera} onChange={value => setFilters(p => ({ ...p, camera: value }))} placeholder="Apple, Sony…" />
                        <FilterInput label="Přípona" value={filters.extension} onChange={value => setFilters(p => ({ ...p, extension: value }))} placeholder="jpg, heic, raw…" />
                        <label className="flex min-h-11 items-center gap-2 text-xs text-[var(--color-text-secondary)]"><input type="checkbox" checked={filters.has_gps} onChange={e => setFilters(p => ({ ...p, has_gps: e.target.checked }))} /> Pouze s GPS</label>
                        <label className="flex min-h-11 items-center gap-2 text-xs text-[var(--color-text-secondary)]"><input type="checkbox" checked={filters.favorites_only} onChange={e => setFilters(p => ({ ...p, favorites_only: e.target.checked }))} /> Pouze oblíbené</label>
                        <button onClick={() => { setFilters(defaultFilters); setSubmitted(false); }} className="min-h-11 text-left text-xs text-red-400">Vymazat všechny filtry</button>
                    </section>
                )}

                {submitted && (
                    <section className="mt-6">
                        <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div><p className="text-sm font-semibold text-white">{searchQuery.isLoading ? 'Hledám…' : `${meta?.total ?? 0} výsledků`}</p>{interpreted?.labels?.length > 0 && <div className="mt-1 flex flex-wrap gap-1">{interpreted.labels.map((label: string) => <span key={label} className="rounded-full bg-[var(--color-accent)]/15 px-2 py-0.5 text-[10px] text-[var(--color-accent)]">Rozpoznáno: {label}</span>)}</div>}</div>
                            <div className="flex items-center gap-2">
                                <div className="flex overflow-hidden rounded-xl border border-[var(--color-border)]">
                                    <ViewButton active={viewType === 'grid'} onClick={() => setViewType('grid')} icon={<Grid3X3 size={14} />} label="Mřížka" />
                                    <ViewButton active={viewType === 'table'} onClick={() => setViewType('table')} icon={<List size={14} />} label="Seznam" />
                                    <ViewButton active={viewType === 'map'} onClick={() => setViewType('map')} icon={<Map size={14} />} label="Mapa" />
                                    <ViewButton active={viewType === 'calendar'} onClick={() => setViewType('calendar')} icon={<CalendarDays size={14} />} label="Kalendář" />
                                </div>
                                <button onClick={() => setShowSave(true)} className="flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-white"><BookmarkPlus size={14} /> Uložit</button>
                            </div>
                        </div>

                        {facets && <div className="mb-4 flex gap-2 overflow-x-auto scrollbar-hide">{[['Fotografie', facets.photos], ['Videa', facets.videos], ['Oblíbené', facets.favorites], ['S GPS', facets.with_gps]].map(([label, count]) => <span key={String(label)} className="shrink-0 rounded-lg bg-white/5 px-2.5 py-1 text-[10px] text-[var(--color-text-secondary)]">{label} · {count}</span>)}</div>}

                        {results.length > 0 ? viewType === 'table' ? <ResultList results={results} /> : viewType === 'map' ? <ViewNotice icon="🗺️" text="Mapový pohled používá stejnou uloženou kolekci. Otevřete ji na mapě pro prostorové clustery." href="/map" /> : viewType === 'calendar' ? <ViewNotice icon="📅" text="Kalendář zobrazí stejnou kolekci podle data pořízení." href="/calendar" /> : <ResultGrid results={results} /> : !searchQuery.isLoading && <div className="rounded-2xl border border-dashed border-[var(--color-border)] py-16 text-center"><Search className="mx-auto mb-3 text-[var(--color-text-secondary)]" /><p className="text-sm text-white">Nic jsme nenašli</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Zkuste ubrat filtr nebo použít obecnější popis.</p></div>}
                    </section>
                )}

                {showSave && <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-4"><div className="w-full max-w-sm rounded-t-3xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5 sm:rounded-3xl"><h2 className="font-semibold text-white">Uložit jako pohled</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Filtry i způsob zobrazení zůstanou spolu.</p><input autoFocus value={viewName} onChange={e => setViewName(e.target.value)} placeholder="Např. Nejlepší fotky z cest" className="mt-4 min-h-12 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white outline-none" /><div className="mt-4 flex gap-2"><button onClick={() => setShowSave(false)} className="min-h-11 flex-1 rounded-xl border border-[var(--color-border)] text-sm text-[var(--color-text-secondary)]">Zrušit</button><button onClick={saveView} disabled={!viewName.trim() || savingView} className="min-h-11 flex-1 rounded-xl bg-[var(--color-accent)] text-sm text-white disabled:opacity-40">{savingView ? 'Ukládám…' : 'Uložit'}</button></div></div></div>}
            </div>
        </AppLayout>
    );
}

function FilterSelect({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: string[][] }) { return <label className="text-xs text-[var(--color-text-secondary)]">{label}<select value={value} onChange={e => onChange(e.target.value)} className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white">{options.map(([key, text]) => <option key={key} value={key}>{text}</option>)}</select></label>; }
function FilterInput({ label, value, onChange, type = 'text', placeholder }: { label: string; value: string; onChange: (value: string) => void; type?: string; placeholder?: string }) { return <label className="text-xs text-[var(--color-text-secondary)]">{label}<input type={type} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} className="mt-1 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-xs text-white" /></label>; }
function ViewButton({ active, onClick, icon, label }: { active: boolean; onClick: () => void; icon: React.ReactNode; label: string }) { return <button onClick={onClick} title={label} className={`flex min-h-10 min-w-10 items-center justify-center border-r border-[var(--color-border)] last:border-0 ${active ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-text-secondary)]'}`}>{icon}</button>; }
function ResultGrid({ results }: { results: any[] }) { return <div className="grid grid-cols-3 gap-1 sm:grid-cols-4 sm:gap-2 lg:grid-cols-6">{results.map(item => <Link key={item.id} href={`/media/${item.uuid}`} className="group relative aspect-square overflow-hidden rounded-lg bg-[var(--color-bg-card)]">{item.variants?.[0]?.url ? <img src={item.variants[0].url} alt="" loading="lazy" className="h-full w-full object-cover transition-transform group-hover:scale-105" /> : <div className="flex h-full items-center justify-center"><Search size={18} /></div>}</Link>)}</div>; }
function ResultList({ results }: { results: any[] }) { return <div className="overflow-hidden rounded-2xl border border-[var(--color-border)]">{results.map(item => <Link key={item.id} href={`/media/${item.uuid}`} className="flex min-h-16 items-center gap-3 border-b border-[var(--color-border)] px-3 last:border-0 hover:bg-white/5"><div className="h-12 w-12 overflow-hidden rounded-lg bg-[var(--color-bg-secondary)]">{item.variants?.[0]?.url && <img src={item.variants[0].url} alt="" className="h-full w-full object-cover" />}</div><div className="min-w-0 flex-1"><p className="truncate text-sm text-white">{item.display_title || item.original_filename}</p><p className="text-xs text-[var(--color-text-secondary)]">{item.taken_at ? new Date(item.taken_at).toLocaleDateString('cs-CZ') : 'Bez data'} · {item.media_type === 'video' ? 'Video' : 'Fotografie'}</p></div>{item.rating > 0 && <span className="text-xs text-amber-400">{'★'.repeat(item.rating)}</span>}</Link>)}</div>; }
function ViewNotice({ icon, text, href }: { icon: string; text: string; href: string }) { return <div className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-8 text-center"><span className="text-4xl">{icon}</span><p className="mx-auto mt-3 max-w-md text-sm text-[var(--color-text-secondary)]">{text}</p><Link href={href} className="mt-4 inline-flex min-h-11 items-center rounded-xl bg-[var(--color-accent)] px-4 text-sm text-white">Otevřít pohled</Link></div>; }
