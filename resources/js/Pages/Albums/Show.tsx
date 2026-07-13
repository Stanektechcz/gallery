import AlbumEvent from '@/Components/AlbumEvent';
import LocationPicker from '@/Components/LocationPicker';
import SmartAlbumEditor from '@/Components/SmartAlbumEditor';
import UploadZone from '@/Components/UploadZone';
import AppLayout from '@/Layouts/AppLayout';
import AlbumStory from '@/Pages/Albums/Story';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowUpDown, BookOpen, ChevronRight, Clock, Edit3, Film, FolderOpen, FolderPlus, Grid3X3, Image, MapPin, Search, SortAsc, SortDesc, Trash2, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';

interface MediaItem {
    id: number;
    uuid: string;
    media_type: string;
    taken_at: string | null;
    size_bytes: number;
    original_filename: string;
    variants: Array<{ type: string; url: string; dominant_color?: string }>;
    is_favorite: boolean;
}

interface Album {
    id: number;
    uuid: string;
    title: string;
    description?: string;
    event_date_start?: string;
    media_count: number;
    descendant_count: number;
    sync_status: string;
    color?: string;
    story_mode?: boolean;
    album_type?: 'physical' | 'smart';
    smart_rules?: any;
    location_name?: string;
    latitude?: number;
    longitude?: number;
    location_country?: string;
    cover?: { variants: Array<{ type: string; url: string }> } | null;
}

interface Filters { sort: string; dir: string; type: string | null; search: string | null }

interface Props {
    album: Album;
    breadcrumb: Array<{ id: number; uuid: string; title: string; slug: string }>;
    children: Album[];
    media: { data: MediaItem[]; current_page: number; last_page: number; total: number; links: any[] };
    filters: Filters;
}

export default function AlbumShow({ album, breadcrumb, children, media, filters: rawFilters }: Props) {
    const filters: Filters = rawFilters ?? { sort: 'taken_at', dir: 'desc', type: null, search: null };
    const [uploadOpen, setUploadOpen] = useState(false);
    const [search, setSearch]         = useState(filters.search ?? '');
    const [deleting, setDeleting]     = useState(false);
    const [activeTab, setActiveTab]   = useState<'grid' | 'story'>('grid');
    const [storyEdit, setStoryEdit]   = useState(false);
    const [isStoryMode, setIsStoryMode] = useState(album.story_mode ?? false);
    const [showSmartEditor, setShowSmartEditor] = useState(false);
    const [albumType, setAlbumType]   = useState(album.album_type ?? 'physical');
    const [showLocationEdit, setShowLocationEdit] = useState(false);
    const [locationVal, setLocationVal] = useState({
        location_name:         album.location_name ?? '',
        latitude:              album.latitude ?? '' as number | '',
        longitude:             album.longitude ?? '' as number | '',
        location_country:      album.location_country ?? '',
        location_country_code: '',
    });
    const [savingLocation, setSavingLocation] = useState(false);
    const galleryItems = useMemo(() => media.data.map(item => {
        const thumbnail = item.variants?.find(v => v.type === 'thumbnail')
            ?? item.variants?.find(v => v.type === 'video_poster');
        const original = item.variants?.find(v => v.type === 'original');
        const placeholder = item.variants?.find(v => v.type === 'placeholder');

        return { item, displayUrl: thumbnail?.url ?? (item.media_type === 'photo' ? original?.url : null), placeholder };
    }), [media.data]);

    const applyFilter = (patch: Partial<Filters>) => {
        router.get(`/albums/${album.uuid}`, { ...filters, ...patch, search: search || undefined } as any, { preserveScroll: true, preserveState: true });
    };

    const toggleDir = () => applyFilter({ dir: filters.dir === 'asc' ? 'desc' : 'asc' });

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilter({ search: search || undefined } as any);
    };

    const deleteAlbum = async () => {
        if (!confirm(`Opravdu smazat album „${album.title}"? Média zůstanou zachována.`)) return;
        setDeleting(true);
        try {
            await axios.delete(`/albums/${album.uuid}`);
            router.visit('/albums');
        } finally { setDeleting(false); }
    };

    const handleUploadComplete = () => router.reload({ only: ['media', 'album'] });

    const saveLocation = async () => {
        setSavingLocation(true);
        try {
            await axios.patch(`/albums/${album.uuid}`, {
                location_name:    locationVal.location_name || null,
                latitude:         locationVal.latitude || null,
                longitude:        locationVal.longitude || null,
                location_country: locationVal.location_country || null,
            });
            setShowLocationEdit(false);
            router.reload({ only: ['album'] });
        } finally { setSavingLocation(false); }
    };

    const SortBtn = ({ value, label }: { value: string; label: string }) => (
        <button
            onClick={() => applyFilter({ sort: value })}
            className={`px-2 py-1 rounded text-xs transition-colors ${filters.sort === value ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-text-secondary)] hover:text-white'}`}
        >{label}</button>
    );

    return (
        <AppLayout>
            <Head title={album.title} />
            <div className="p-4">

                {/* Breadcrumb */}
                <nav className="flex items-center gap-1 text-xs text-[var(--color-text-secondary)] mb-4 flex-wrap">
                    <Link href="/albums" className="hover:text-white transition-colors">Alba</Link>
                    {breadcrumb.slice(0,-1).map(c => (
                        <span key={c.id} className="flex items-center gap-1">
                            <ChevronRight size={12} />
                            <Link href={`/albums/${c.uuid}`} className="hover:text-white">{c.title}</Link>
                        </span>
                    ))}
                    <span className="flex items-center gap-1"><ChevronRight size={12} /><span className="text-white font-medium">{album.title}</span></span>
                </nav>

                {/* Event Mode bar + detection banner */}
                <AlbumEvent albumUuid={album.uuid}/>

                {/* Smart album editor */}
                {showSmartEditor && (
                    <SmartAlbumEditor
                        albumUuid={album.uuid}
                        initialRules={album.smart_rules}
                        albumType={albumType}
                        onSaved={(type, rules) => {
                            setAlbumType(type);
                            setShowSmartEditor(false);
                            router.reload({ only: ['album', 'media'] });
                        }}
                    />
                )}

                {/* Header */}
                <div className="flex items-start justify-between mb-4 gap-3">
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold text-white mb-1 flex items-center gap-2 truncate">
                            {album.title}
                            {albumType === 'smart' && (
                                <span className="text-[10px] bg-[var(--color-accent)]/20 text-[var(--color-accent)] px-2 py-0.5 rounded-full font-medium shrink-0">✨ Dynamické</span>
                            )}
                        </h1>
                        {album.description && <p className="text-sm text-[var(--color-text-secondary)] mb-1">{album.description}</p>}
                        <div className="flex flex-wrap items-center gap-3 mt-1 text-xs text-[var(--color-text-secondary)]">
                            {album.media_count > 0 && <span className="flex items-center gap-1"><Image size={12}/>{album.media_count} médií</span>}
                            {album.descendant_count > 0 && <span className="flex items-center gap-1"><FolderOpen size={12}/>{album.descendant_count} alb</span>}
                            {album.event_date_start && <span className="flex items-center gap-1"><Clock size={12}/>{new Date(album.event_date_start).toLocaleDateString('cs-CZ')}</span>}
                            {/* Location display */}
                            {album.location_name ? (
                                <button onClick={() => setShowLocationEdit(v=>!v)}
                                    className="flex items-center gap-1 text-[var(--color-accent)] hover:underline">
                                    <MapPin size={11}/> {album.location_name}{album.location_country ? `, ${album.location_country}` : ''}
                                </button>
                            ) : (
                                <button onClick={() => setShowLocationEdit(v=>!v)}
                                    className="flex items-center gap-1 text-[var(--color-border)] hover:text-[var(--color-text-secondary)] transition-colors">
                                    <MapPin size={11}/> Přidat lokalitu
                                </button>
                            )}
                        </div>

                        {/* Inline location editor */}
                        {showLocationEdit && (
                            <div className="mt-3 p-3 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl space-y-2">
                                <LocationPicker
                                    label="Lokalita alba"
                                    value={locationVal}
                                    onChange={setLocationVal}
                                />
                                <div className="flex gap-2">
                                    <button onClick={saveLocation} disabled={savingLocation}
                                        className="text-xs bg-[var(--color-accent)] text-white px-3 py-1.5 rounded-lg hover:opacity-90 disabled:opacity-40 flex items-center gap-1.5">
                                        {savingLocation ? '…' : '💾'} Uložit
                                    </button>
                                    <button onClick={() => setShowLocationEdit(false)}
                                        className="text-xs border border-[var(--color-border)] text-[var(--color-text-secondary)] px-3 py-1.5 rounded-lg hover:text-white">
                                        Zrušit
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                    <div className="flex items-center gap-2 shrink-0 flex-wrap justify-end">
                        <Link href={`/albums/create?parent=${album.uuid}`} className="flex items-center gap-1.5 rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white hover:border-[var(--color-accent)]/60 px-3 py-2 text-sm font-medium transition-all">
                            <FolderPlus size={14}/> Podalbu
                        </Link>
                        <button onClick={() => setShowSmartEditor(v=>!v)}
                            className={`flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-all ${showSmartEditor ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white hover:border-[var(--color-accent)]/60'}`}>
                            ✨ {albumType === 'smart' ? 'Pravidla' : 'Typ alba'}
                        </button>
                        <button onClick={() => setUploadOpen(v=>!v)} className={`flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-all ${uploadOpen ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white hover:border-[var(--color-accent)]/60'}`}>
                            <Upload size={14}/> Nahrát
                        </button>
                        <button onClick={deleteAlbum} disabled={deleting} title="Smazat album" className="flex items-center gap-1.5 rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] text-red-400 hover:border-red-500/60 px-3 py-2 text-sm font-medium transition-all disabled:opacity-50">
                            <Trash2 size={14}/> Smazat
                        </button>
                    </div>
                </div>

                {/* Tab bar: Fotografie | Příběh */}
                <div className="flex items-center gap-0 mb-4 border-b border-[var(--color-border)]">
                    <button onClick={() => setActiveTab('grid')}
                        className={`flex items-center gap-1.5 px-4 py-2.5 text-sm border-b-2 transition-colors -mb-px ${activeTab === 'grid' ? 'border-[var(--color-accent)] text-white' : 'border-transparent text-[var(--color-text-secondary)] hover:text-white'}`}>
                        <Grid3X3 size={14}/> Fotografie
                    </button>
                    <button onClick={() => setActiveTab('story')}
                        className={`flex items-center gap-1.5 px-4 py-2.5 text-sm border-b-2 transition-colors -mb-px ${activeTab === 'story' ? 'border-[var(--color-accent)] text-white' : 'border-transparent text-[var(--color-text-secondary)] hover:text-white'}`}>
                        <BookOpen size={14}/> Příběh
                    </button>
                    {activeTab === 'story' && (
                        <div className="ml-auto flex items-center gap-2 pb-1">
                            <button onClick={() => setStoryEdit(v => !v)}
                                className={`flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg border transition-colors ${storyEdit ? 'bg-[var(--color-accent)] border-[var(--color-accent)] text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                                <Edit3 size={12}/> {storyEdit ? 'Náhled' : 'Upravit příběh'}
                            </button>
                        </div>
                    )}
                </div>

                {/* Upload panel */}
                {uploadOpen && (
                    <div className="mb-6 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                        <UploadZone albumId={album.id} onUploadComplete={handleUploadComplete} />
                    </div>
                )}

                {/* Story tab content */}
                {activeTab === 'story' && (
                    <AlbumStory
                        albumUuid={album.uuid}
                        albumMedia={media.data.map(m => ({
                            uuid:      m.uuid,
                            thumb_url: m.variants.find(v => v.type === 'thumbnail')?.url ?? m.variants[0]?.url ?? '',
                            media_type: m.media_type,
                            is_video:  m.media_type === 'video',
                        }))}
                        editMode={storyEdit}
                        coverDate={album.event_date_start}
                        title={album.title}
                    />
                )}

                {/* Grid tab content */}
                {activeTab === 'grid' && (<>

                {/* Filter/Sort bar */}
                <div className="flex flex-wrap items-center gap-2 mb-5 p-3 rounded-xl bg-[var(--color-bg-card)] border border-[var(--color-border)]">
                    {/* Search */}
                    <form onSubmit={handleSearch} className="flex items-center gap-1.5 flex-1 min-w-[160px]">
                        <Search size={13} className="text-[var(--color-text-secondary)]" />
                        <input
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder="Hledat soubor…"
                            className="bg-transparent text-white text-xs placeholder-[var(--color-text-secondary)] outline-none flex-1 min-w-0"
                        />
                    </form>

                    <div className="w-px h-4 bg-[var(--color-border)]" />

                    {/* Type filter */}
                    <div className="flex gap-1">
                        <button onClick={() => applyFilter({type: undefined as any})} className={`px-2 py-1 rounded text-xs transition-colors ${!filters.type ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-text-secondary)] hover:text-white'}`}>Vše</button>
                        <button onClick={() => applyFilter({type:'photo'})} className={`px-2 py-1 rounded text-xs transition-colors ${filters.type==='photo' ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-text-secondary)] hover:text-white'}`}><Image size={11} className="inline mr-1"/>Fotky</button>
                        <button onClick={() => applyFilter({type:'video'})} className={`px-2 py-1 rounded text-xs transition-colors ${filters.type==='video' ? 'bg-[var(--color-accent)] text-white' : 'text-[var(--color-text-secondary)] hover:text-white'}`}><Film size={11} className="inline mr-1"/>Videa</button>
                    </div>

                    <div className="w-px h-4 bg-[var(--color-border)]" />

                    {/* Sort */}
                    <div className="flex items-center gap-1">
                        <ArrowUpDown size={12} className="text-[var(--color-text-secondary)]" />
                        <SortBtn value="taken_at" label="Datum" />
                        <SortBtn value="uploaded_at" label="Nahrání" />
                        <SortBtn value="size_bytes" label="Velikost" />
                        <SortBtn value="original_filename" label="Název" />
                        <button onClick={toggleDir} title="Změnit směr řazení" className="ml-1 text-[var(--color-text-secondary)] hover:text-white">
                            {filters.dir === 'asc' ? <SortAsc size={14}/> : <SortDesc size={14}/>}
                        </button>
                    </div>

                    {media.total > 0 && (
                        <span className="ml-auto text-xs text-[var(--color-text-secondary)]">{media.total} položek</span>
                    )}
                </div>

                {/* Sub-albums */}
                {children.length > 0 && (
                    <section className="mb-8">
                        <h2 className="text-xs font-medium text-[var(--color-text-secondary)] mb-3 uppercase tracking-wider">Podalba</h2>
                        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                            {children.map(child => {
                                const thumb = child.cover?.variants?.find(v => v.type === 'thumbnail');
                                return (
                                    <Link key={child.id} href={`/albums/${child.uuid}`} className="group relative rounded-xl overflow-hidden bg-[var(--color-bg-card)] border border-[var(--color-border)] hover:border-[var(--color-accent)]/50 transition-all">
                                        <div className="aspect-video bg-[var(--color-bg-secondary)] relative overflow-hidden">
                                            {thumb ? <img src={thumb.url} alt={child.title} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                                                   : <div className="w-full h-full flex items-center justify-center"><FolderOpen size={28} className="text-[var(--color-text-secondary)]" style={{ color: child.color ?? undefined }} /></div>}
                                        </div>
                                        <div className="p-2.5">
                                            <p className="text-sm font-medium text-white truncate">{child.title}</p>
                                            <p className="text-xs text-[var(--color-text-secondary)]">{child.media_count > 0 ? `${child.media_count} médií` : 'Prázdné'}</p>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    </section>
                )}

                {/* Media grid */}
                {media.data.length > 0 ? (
                    <section>
                        <h2 className="text-xs font-medium text-[var(--color-text-secondary)] mb-3 uppercase tracking-wider">Média</h2>
                        <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fill, minmax(150px, 1fr))', gap:'4px' }}>
                            {galleryItems.map(({ item, displayUrl, placeholder }) => {
                                return (
                                    <Link
                                        key={item.id}
                                        href={`/media/${item.uuid}`}
                                        className="relative group aspect-square rounded overflow-hidden bg-[var(--color-bg-card)]"
                                        style={{ contentVisibility: 'auto', contain: 'layout paint style', containIntrinsicSize: '150px 150px' }}
                                    >
                                        {placeholder?.dominant_color && !displayUrl && <div className="absolute inset-0" style={{ backgroundColor: placeholder.dominant_color }} />}
                                        {displayUrl
                                            ? <img src={displayUrl} alt="" loading="lazy" decoding="async" fetchPriority="low" draggable={false} className="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                                            : <div className="absolute inset-0 flex items-center justify-center bg-[var(--color-bg-secondary)]">
                                                {item.media_type === 'video' ? <Film size={28} className="text-[var(--color-text-secondary)] opacity-60" /> : <Image size={28} className="text-[var(--color-text-secondary)] opacity-60" />}
                                              </div>
                                        }
                                        {item.media_type === 'video' && displayUrl && (
                                            <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                                                <div className="w-8 h-8 rounded-full bg-black/50 flex items-center justify-center">
                                                    <Film size={16} className="text-white" />
                                                </div>
                                            </div>
                                        )}
                                    </Link>
                                );
                            })}
                        </div>

                        {/* Pagination */}
                        {media.last_page > 1 && (
                            <div className="flex items-center justify-center gap-1 mt-6">
                                {media.links.map((link, i) => (
                                    <button key={i} disabled={!link.url || link.active} onClick={() => link.url && router.get(link.url, {}, { preserveScroll: false })}
                                        className={`px-3 py-1.5 rounded text-xs transition-colors ${link.active ? 'bg-[var(--color-accent)] text-white' : !link.url ? 'text-[var(--color-text-secondary)] opacity-40' : 'bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white hover:border-[var(--color-accent)]/50'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                ) : (
                    children.length === 0 && (
                        <div className="flex flex-col items-center justify-center h-48 text-[var(--color-text-secondary)]">
                            <FolderOpen size={40} className="mb-3 opacity-30" />
                            <p>{filters.search || filters.type ? 'Žádné výsledky' : 'Album je prázdné'}</p>
                            {!filters.search && !filters.type && (
                                <button onClick={() => setUploadOpen(true)} className="mt-3 flex items-center gap-2 rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white hover:opacity-90 transition-opacity">
                                    <Upload size={15} /> Nahrát fotky nebo videa
                                </button>
                            )}
                        </div>
                    )
                )}
                </>)}
            </div>
        </AppLayout>
    );
}
