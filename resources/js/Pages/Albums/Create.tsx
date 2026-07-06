import LocationPicker from '@/Components/LocationPicker';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronLeft, FolderPlus, Loader2 } from 'lucide-react';

interface AlbumOption {
    id: number;
    uuid: string;
    title: string;
}

interface Props {
    allAlbums: AlbumOption[];
    parentAlbum: AlbumOption | null;
}

export default function AlbumsCreate({ allAlbums, parentAlbum }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        title:                 '',
        parent_id:             parentAlbum?.id ?? ('' as number | ''),
        description:           '',
        event_date_start:      '',
        event_date_end:        '',
        color:                 '',
        visibility:            'private' as 'private' | 'shared' | 'public',
        sort_mode:             'date_taken' as string,
        sort_direction:        'asc'       as 'asc' | 'desc',
        // Location
        location_name:         '',
        latitude:              '' as number | '',
        longitude:             '' as number | '',
        location_country:      '',
        location_country_code: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/albums');
    }

    return (
        <AppLayout>
            <Head title="Nové album" />

            <div className="max-w-xl mx-auto p-4">
                {/* Back link */}
                <Link
                    href={parentAlbum ? `/albums/${parentAlbum.uuid}` : '/albums'}
                    className="flex items-center gap-1.5 text-sm text-[var(--color-text-secondary)] hover:text-white mb-6 transition-colors"
                >
                    <ChevronLeft size={16} />
                    {parentAlbum ? `Zpět do „${parentAlbum.title}"` : 'Zpět na alba'}
                </Link>

                <div className="flex items-center gap-3 mb-6">
                    <div className="w-9 h-9 rounded-lg bg-[var(--color-accent)]/20 flex items-center justify-center">
                        <FolderPlus size={18} className="text-[var(--color-accent)]" />
                    </div>
                    <h1 className="text-lg font-semibold text-white">Nové album</h1>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    {/* Title */}
                    <div>
                        <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                            Název <span className="text-red-400">*</span>
                        </label>
                        <input
                            type="text"
                            value={data.title}
                            onChange={e => setData('title', e.target.value)}
                            placeholder="Např. Dovolená 2026"
                            autoFocus
                            className="w-full rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white placeholder-[var(--color-text-secondary)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                        />
                        {errors.title && <p className="text-red-400 text-xs mt-1">{errors.title}</p>}
                    </div>

                    {/* Parent album */}
                    <div>
                        <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                            Nadřazené album
                        </label>
                        <select
                            value={data.parent_id}
                            onChange={e => setData('parent_id', e.target.value ? Number(e.target.value) : '')}
                            className="w-full rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                        >
                            <option value="">— Kořenové album —</option>
                            {allAlbums.map(a => (
                                <option key={a.id} value={a.id}>{a.title}</option>
                            ))}
                        </select>
                        {errors.parent_id && <p className="text-red-400 text-xs mt-1">{errors.parent_id}</p>}
                    </div>

                    {/* Description */}
                    <div>
                        <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                            Popis
                        </label>
                        <textarea
                            value={data.description}
                            onChange={e => setData('description', e.target.value)}
                            rows={3}
                            placeholder="Volitelný popis alba…"
                            className="w-full rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white placeholder-[var(--color-text-secondary)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors resize-none"
                        />
                    </div>

                    {/* Location with Nominatim autocomplete */}
                    <LocationPicker
                        label="Lokalita alba"
                        value={{
                            location_name:         data.location_name,
                            latitude:              data.latitude,
                            longitude:             data.longitude,
                            location_country:      data.location_country,
                            location_country_code: data.location_country_code,
                        }}
                        onChange={v => {
                            setData('location_name',         v.location_name);
                            setData('latitude',              v.latitude);
                            setData('longitude',             v.longitude);
                            setData('location_country',      v.location_country ?? '');
                            setData('location_country_code', v.location_country_code ?? '');
                        }}
                    />

                    {/* Dates */}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                                Datum od
                            </label>
                            <input
                                type="date"
                                value={data.event_date_start}
                                onChange={e => setData('event_date_start', e.target.value)}
                                className="w-full rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                            />
                            {errors.event_date_start && <p className="text-red-400 text-xs mt-1">{errors.event_date_start}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                                Datum do
                            </label>
                            <input
                                type="date"
                                value={data.event_date_end}
                                onChange={e => setData('event_date_end', e.target.value)}
                                className="w-full rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                            />
                            {errors.event_date_end && <p className="text-red-400 text-xs mt-1">{errors.event_date_end}</p>}
                        </div>
                    </div>

                    {/* Visibility + Sort */}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                                Viditelnost
                            </label>
                            <select
                                value={data.visibility}
                                onChange={e => setData('visibility', e.target.value as 'private'|'shared'|'public')}
                                className="w-full rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                            >
                                <option value="private">Soukromé</option>
                                <option value="shared">Sdílené</option>
                                <option value="public">Veřejné</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                                Řazení médií
                            </label>
                            <select
                                value={data.sort_mode}
                                onChange={e => setData('sort_mode', e.target.value)}
                                className="w-full rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                            >
                                <option value="date_taken">Datum pořízení</option>
                                <option value="date_uploaded">Datum nahrání</option>
                                <option value="title">Název</option>
                                <option value="manual">Ručně</option>
                            </select>
                        </div>
                    </div>

                    {/* Color tag */}
                    <div>
                        <label className="block text-xs font-medium text-[var(--color-text-secondary)] mb-1.5">
                            Barva (volitelně)
                        </label>
                        <div className="flex items-center gap-2">
                            {['#6366f1','#ec4899','#f97316','#22c55e','#06b6d4','#a855f7','#eab308'].map(color => (
                                <button
                                    key={color}
                                    type="button"
                                    onClick={() => setData('color', data.color === color ? '' : color)}
                                    className={`w-6 h-6 rounded-full transition-all ${data.color === color ? 'ring-2 ring-white ring-offset-2 ring-offset-[var(--color-bg-card)]' : ''}`}
                                    style={{ backgroundColor: color }}
                                />
                            ))}
                            {data.color && (
                                <button
                                    type="button"
                                    onClick={() => setData('color', '')}
                                    className="text-xs text-[var(--color-text-secondary)] hover:text-white ml-1"
                                >
                                    Zrušit
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Submit */}
                    <div className="flex gap-3 pt-2">
                        <button
                            type="submit"
                            disabled={processing || !data.title.trim()}
                            className="flex items-center gap-2 bg-[var(--color-accent)] hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-opacity"
                        >
                            {processing && <Loader2 size={14} className="animate-spin" />}
                            Vytvořit album
                        </button>
                        <Link
                            href={parentAlbum ? `/albums/${parentAlbum.uuid}` : '/albums'}
                            className="text-sm text-[var(--color-text-secondary)] hover:text-white px-4 py-2.5 rounded-lg border border-[var(--color-border)] hover:border-[var(--color-accent)]/50 transition-colors"
                        >
                            Zrušit
                        </Link>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
