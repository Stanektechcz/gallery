import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { clsx } from 'clsx';
import { Filter, Search, X } from 'lucide-react';
import { useCallback, useState } from 'react';

interface SearchFilters {
    q: string;
    media_type: string;
    date_from: string;
    date_to: string;
    has_gps: boolean;
    min_rating: string;
    favorites_only: boolean;
    archived: boolean;
    extension: string;
    camera: string;
    orientation: string;
}

const defaultFilters: SearchFilters = {
    q: '',
    media_type: '',
    date_from: '',
    date_to: '',
    has_gps: false,
    min_rating: '',
    favorites_only: false,
    archived: false,
    extension: '',
    camera: '',
    orientation: '',
};

export default function SearchIndex() {
    const [filters, setFilters] = useState<SearchFilters>(defaultFilters);
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [submitted, setSubmitted] = useState(false);

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ['search', filters],
        queryFn: async () => {
            const params: Record<string, string> = {};
            Object.entries(filters).forEach(([k, v]) => {
                if (v !== '' && v !== false) params[k] = String(v);
            });
            const res = await axios.get('/api/v1/search', { params });
            return res.data;
        },
        enabled: submitted && (filters.q.length >= 2 || Object.values(filters).some(v => v !== '' && v !== false && v !== filters.q)),
    });

    const setFilter = useCallback(<K extends keyof SearchFilters>(key: K, value: SearchFilters[K]) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    }, []);

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        setSubmitted(true);
    }

    function clearFilters() {
        setFilters(defaultFilters);
        setSubmitted(false);
    }

    const results = data?.data ?? [];
    const total   = data?.meta?.total ?? 0;

    return (
        <AppLayout>
            <Head title="Hledat" />

            <div className="max-w-4xl mx-auto px-4 py-6">
                {/* Search bar */}
                <form onSubmit={handleSearch} className="mb-6">
                    <div className="flex gap-2">
                        <div className="relative flex-1">
                            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-secondary)]" />
                            <input
                                type="search"
                                value={filters.q}
                                onChange={e => setFilter('q', e.target.value)}
                                placeholder="Hledat fotky, alba, místa, tagy…"
                                className="w-full bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl pl-9 pr-4 py-2.5 text-white text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                            />
                        </div>
                        <button
                            type="button"
                            onClick={() => setShowAdvanced(!showAdvanced)}
                            className={clsx(
                                'px-3 py-2.5 rounded-xl border text-sm transition-colors flex items-center gap-2',
                                showAdvanced
                                    ? 'bg-[var(--color-accent)] border-[var(--color-accent)] text-white'
                                    : 'bg-[var(--color-bg-card)] border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'
                            )}
                        >
                            <Filter size={14} />
                            Filtry
                        </button>
                        <button
                            type="submit"
                            className="bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)] text-white px-5 py-2.5 rounded-xl text-sm font-medium transition-colors"
                        >
                            Hledat
                        </button>
                    </div>

                    {/* Advanced filters */}
                    {showAdvanced && (
                        <div className="mt-3 p-4 bg-[var(--color-bg-card)] rounded-xl border border-[var(--color-border)] grid grid-cols-2 md:grid-cols-3 gap-3">
                            <div>
                                <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Typ</label>
                                <select
                                    value={filters.media_type}
                                    onChange={e => setFilter('media_type', e.target.value)}
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-white text-xs"
                                >
                                    <option value="">Vše</option>
                                    <option value="photo">Fotografie</option>
                                    <option value="video">Videa</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Od data</label>
                                <input
                                    type="date"
                                    value={filters.date_from}
                                    onChange={e => setFilter('date_from', e.target.value)}
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-white text-xs"
                                />
                            </div>
                            <div>
                                <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Do data</label>
                                <input
                                    type="date"
                                    value={filters.date_to}
                                    onChange={e => setFilter('date_to', e.target.value)}
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-white text-xs"
                                />
                            </div>
                            <div>
                                <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Orientace</label>
                                <select
                                    value={filters.orientation}
                                    onChange={e => setFilter('orientation', e.target.value)}
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-white text-xs"
                                >
                                    <option value="">Libovolná</option>
                                    <option value="landscape">Na šířku</option>
                                    <option value="portrait">Na výšku</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Min. hodnocení</label>
                                <select
                                    value={filters.min_rating}
                                    onChange={e => setFilter('min_rating', e.target.value)}
                                    className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-2 py-1.5 text-white text-xs"
                                >
                                    <option value="">Libovolné</option>
                                    {[1,2,3,4,5].map(r => <option key={r} value={r}>{'★'.repeat(r)}</option>)}
                                </select>
                            </div>
                            <div className="flex flex-col gap-2">
                                <label className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)] cursor-pointer">
                                    <input type="checkbox" checked={filters.has_gps} onChange={e => setFilter('has_gps', e.target.checked)} />
                                    Pouze s GPS
                                </label>
                                <label className="flex items-center gap-2 text-xs text-[var(--color-text-secondary)] cursor-pointer">
                                    <input type="checkbox" checked={filters.favorites_only} onChange={e => setFilter('favorites_only', e.target.checked)} />
                                    Pouze oblíbené
                                </label>
                            </div>
                            <div className="col-span-full flex justify-end">
                                <button type="button" onClick={clearFilters} className="text-xs text-[var(--color-text-secondary)] hover:text-white flex items-center gap-1">
                                    <X size={12} /> Vymazat filtry
                                </button>
                            </div>
                        </div>
                    )}
                </form>

                {/* Results */}
                {submitted && (
                    <>
                        <div className="text-xs text-[var(--color-text-secondary)] mb-3">
                            {isFetching ? 'Hledám…' : `${total} výsledků`}
                        </div>

                        {results.length > 0 && (
                            <div className="grid grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2">
                                {results.map((item: any) => {
                                    const thumb = item.variants?.find((v: any) => v.type === 'thumbnail');
                                    return (
                                        <a
                                            key={item.id}
                                            href={`/media/${item.uuid}`}
                                            className="aspect-square rounded-lg overflow-hidden bg-[var(--color-bg-card)] hover:opacity-90 transition-opacity"
                                        >
                                            {thumb ? (
                                                <img src={thumb.url} alt="" className="w-full h-full object-cover" />
                                            ) : (
                                                <div className="w-full h-full flex items-center justify-center text-[var(--color-text-secondary)]">
                                                    <Search size={20} />
                                                </div>
                                            )}
                                        </a>
                                    );
                                })}
                            </div>
                        )}

                        {!isLoading && results.length === 0 && (
                            <div className="text-center py-12 text-[var(--color-text-secondary)]">
                                <p>Nic nenalezeno</p>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
