import { BulkActionBar } from '@/Components/BulkActionBar';
import Slideshow, { type SlideshowItem } from '@/Components/Slideshow';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { Grid3X3, Heart, Layers, Map, Maximize2, Play, Trash2 } from 'lucide-react';
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';

const GRID_SIZES = [120, 160, 200, 260];
const MONTHS_CS = ['Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];

interface MediaCard {
    id: number; uuid: string; media_type: 'photo' | 'video';
    taken_at: string | null; width: number | null; height: number | null;
    is_favorite: boolean; rating: number | null;
    primary_album?: { id: number; uuid: string; title: string } | null;
    stacks?: Array<{ uuid: string; items_count: number }>;
    variants: Array<{ type: string; url: string; dominant_color?: string; aspect_ratio?: number }>;
}
interface TimelineGroup { date: string; label: string; month: string; year: string; items: MediaCard[] }

const MediaCardComponent = memo(function MediaCardComponent({ item, size, selected, onFav, onTrash, onSlideshow, onSelect }: {
    item: MediaCard; size: number; selected: boolean;
    onFav: (uuid: string, cur: boolean) => void; onTrash: (uuid: string) => void;
    onSlideshow: (uuid: string) => void; onSelect: (uuid: string) => void;
}) {
    const thumb = item.variants?.find(v => v.type === 'thumbnail');
    const thumbUrl = thumb?.url ?? `/files/media/${item.uuid}/${item.media_type === 'video' ? 'video_poster.jpg' : 'thumbnail.jpg'}`;
    const dom   = item.variants?.find(v => v.type === 'placeholder')?.dominant_color;
    return (
        <div
            className={`media-timeline-card relative group cursor-pointer rounded overflow-hidden bg-[var(--color-bg-card)] shrink-0 ${selected ? 'ring-2 ring-[var(--color-accent)] ring-offset-1 ring-offset-[var(--color-bg-primary)]' : ''}`}
            style={{ '--media-grid-size': `${size}px`, contentVisibility: 'auto', contain: 'layout paint style', containIntrinsicSize: `${size}px ${size}px` } as React.CSSProperties}
            onClick={e => { if (e.ctrlKey||e.metaKey||e.shiftKey||selected) { e.preventDefault(); onSelect(item.uuid); } else router.visit(`/media/${item.uuid}`); }}
        >
            {dom && <div className="absolute inset-0" style={{ backgroundColor: dom }} />}
            <img src={thumbUrl} alt="" loading="lazy" decoding="async" fetchPriority="low" draggable={false} className="absolute inset-0 w-full h-full object-cover" />
            <div className={`absolute inset-0 transition-colors ${selected ? 'bg-[var(--color-accent)]/20' : 'bg-black/0 group-hover:bg-black/25'}`} />
            {item.media_type === 'video' && !selected && <div className="absolute top-1.5 right-1.5 bg-black/60 rounded-full p-0.5"><Play size={9} className="text-white fill-white" /></div>}
            {(item.stacks?.[0]?.items_count ?? 0) > 1 && !selected && <div className="absolute top-1.5 right-1.5 bg-black/70 rounded-full px-1.5 py-1 flex items-center gap-1 text-[9px] text-white" title="Seskupené fotografie"><Layers size={10} />{item.stacks![0].items_count}</div>}
            {item.is_favorite && !selected && <Heart size={11} className="absolute top-1.5 left-1.5 text-red-400 fill-red-400" />}
            <div className={`absolute top-1.5 left-1.5 w-6 h-6 rounded border-2 flex items-center justify-center transition-all ${selected ? 'bg-[var(--color-accent)] border-[var(--color-accent)]' : 'bg-black/45 border-white/70 opacity-75 md:opacity-0 md:group-hover:opacity-100'}`}
                onClick={e => { e.stopPropagation(); onSelect(item.uuid); }}>
                {selected && <span className="text-white text-[10px] font-bold">✓</span>}
            </div>
            {!selected && (
                <div className="absolute bottom-0 left-0 right-0 p-1 flex justify-between opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onClick={e => { e.stopPropagation(); onFav(item.uuid, item.is_favorite); }} className="w-6 h-6 rounded-full bg-black/60 flex items-center justify-center hover:bg-black/80">
                        <Heart size={10} className={item.is_favorite ? 'text-red-400 fill-red-400' : 'text-white'} />
                    </button>
                    <div className="flex gap-1">
                        <button onClick={e => { e.stopPropagation(); onSlideshow(item.uuid); }} className="w-6 h-6 rounded-full bg-black/60 flex items-center justify-center hover:bg-black/80"><Maximize2 size={10} className="text-white" /></button>
                        <button onClick={e => { e.stopPropagation(); onTrash(item.uuid); }} className="w-6 h-6 rounded-full bg-black/60 flex items-center justify-center hover:bg-red-500/80"><Trash2 size={10} className="text-white" /></button>
                    </div>
                </div>
            )}
            {item.primary_album && !selected && (
                <div className="absolute bottom-0 left-0 right-0 px-1.5 py-1 bg-gradient-to-t from-black/70 to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
                    <span className="text-[9px] text-white/80 truncate block">{item.primary_album.title}</span>
                </div>
            )}
        </div>
    );
});

function groupByDate(items: MediaCard[]): TimelineGroup[] {
    const groups: Record<string, MediaCard[]> = {};
    for (const item of items) {
        const key = item.taken_at ? item.taken_at.substring(0, 10) : '__nodate__';
        if (!groups[key]) groups[key] = [];
        groups[key].push(item);
    }
    return Object.entries(groups).map(([key, its]) => {
        const d = its[0].taken_at ? new Date(its[0].taken_at) : null;
        return {
            date: key,
            label: d ? d.toLocaleDateString('cs-CZ', { weekday: 'long', day: 'numeric', month: 'long' }) : 'Bez data',
            month: d ? `${MONTHS_CS[d.getMonth()]} ${d.getFullYear()}` : '',
            year: d ? String(d.getFullYear()) : '',
            items: its,
        };
    });
}

export default function TimelineIndex() {
    const sentinelRef = useRef<HTMLDivElement>(null);
    const queryClient = useQueryClient();
    const [localItems, setLocalItems]   = useState<Record<string, Partial<MediaCard>>>({});
    const [gridSizeIdx, setGridSizeIdx] = useState(1);
    const [selected,    setSelected]    = useState<Set<string>>(new Set());
    const [slideshowItems, setSlideshowItems] = useState<MediaCard[]|null>(null);
    const [slideshowIdx,   setSlideshowIdx]   = useState(0);
    const gridSize = GRID_SIZES[gridSizeIdx];

    const toggleFav = useCallback(async (uuid: string, cur: boolean) => {
        setLocalItems(p => ({ ...p, [uuid]: { is_favorite: !cur } }));
        try { await axios.post(`/api/v1/favorites/${uuid}/toggle`); }
        catch { setLocalItems(p => ({ ...p, [uuid]: { is_favorite: cur } })); }
    }, []);
    const trashItem = useCallback(async (uuid: string) => {
        if (!confirm('Přesunout do koše?')) return;
        setLocalItems(p => ({ ...p, [uuid]: { ...(p[uuid]??{}), _trashed: true } as any }));
        try { await axios.delete(`/media/${uuid}`); queryClient.invalidateQueries({ queryKey: ['timeline'] }); }
        catch (e: any) { setLocalItems(p => { const n={...p}; delete n[uuid]; return n; }); alert(e?.response?.data?.message??'Chyba'); }
    }, [queryClient]);
    const toggleSelect = useCallback((uuid: string) => setSelected(prev => { const n=new Set(prev); n.has(uuid)?n.delete(uuid):n.add(uuid); return n; }), []);
    const clearSelect  = useCallback(() => setSelected(new Set()), []);

    const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isLoading } = useInfiniteQuery({
        queryKey: ['timeline'],
        queryFn: async ({ pageParam }) => {
            const params: Record<string,string> = { per_page: '48' };
            if (pageParam) params.cursor = String(pageParam);
            return (await axios.get('/api/v1/timeline', { params })).data;
        },
        initialPageParam: undefined as string|undefined,
        getNextPageParam: lp => lp.meta?.next_cursor ?? undefined,
        staleTime: 60_000,
        gcTime: 10 * 60_000,
        refetchOnWindowFocus: false,
    });

    useEffect(() => {
        const obs = new IntersectionObserver(
            e => { if (e[0].isIntersecting && hasNextPage && !isFetchingNextPage) fetchNextPage(); },
            { rootMargin: '800px' }
        );
        if (sentinelRef.current) obs.observe(sentinelRef.current);
        return () => obs.disconnect();
    }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

    // Keyboard shortcuts
    useEffect(() => {
        if (!slideshowItems) return;
        const h = (e: KeyboardEvent) => {
            if (e.key==='ArrowRight') setSlideshowIdx(i=>Math.min(i+1, slideshowItems.length-1));
            if (e.key==='ArrowLeft')  setSlideshowIdx(i=>Math.max(i-1,0));
            if (e.key==='Escape')     setSlideshowItems(null);
        };
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, [slideshowItems]);
    useEffect(() => {
        const h = (e: KeyboardEvent) => { if (e.key==='Escape' && selected.size>0) clearSelect(); };
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, [selected]);

    const allItems: MediaCard[] = useMemo(() =>
        (data?.pages.flatMap(p=>p.data)??[])
            .filter(i => !(localItems[i.uuid] as any)?._trashed)
            .map(i => ({ ...i, ...(localItems[i.uuid]??{}) })),
        [data, localItems]
    );
    const groups   = useMemo(() => groupByDate(allItems), [allItems]);
    const sections = useMemo(() => {
        const m: Record<string, TimelineGroup[]> = {};
        for (const g of groups) { const k=g.month||'Bez data'; if(!m[k])m[k]=[]; m[k].push(g); }
        return m;
    }, [groups]);
    const scrubBuckets = useMemo(() => {
        const seen=new Set<string>(), list: {label:string;key:string}[]=[];
        for (const g of groups) { const k=g.month||g.year; if(!seen.has(k)){ seen.add(k); list.push({label:k,key:k}); } }
        return list;
    }, [groups]);
    const selectAll = useCallback(() => setSelected(new Set(allItems.map(i => i.uuid))), [allItems]);

    const startSlideshow = useCallback((uuid: string) => {
        setSlideshowItems(allItems);
        setSlideshowIdx(allItems.findIndex(i=>i.uuid===uuid)||0);
    }, [allItems]);

    // Map Timeline items to SlideshowItem
    const slideshowMapped: SlideshowItem[] = useMemo(() => (slideshowItems ?? []).map(i => ({
        uuid:          i.uuid,
        media_type:    i.media_type as 'photo' | 'video',
        thumb_url:     i.variants?.find(v => v.type === 'thumbnail')?.url,
        display_title: undefined,
        taken_at:      i.taken_at ?? undefined,
        rating:        i.rating ?? undefined,
    })), [slideshowItems]);

    return (
        <AppLayout>
            <Head title="Fotky" />

            {/* New Slideshow */}
            {slideshowItems && (
                <Slideshow
                    items={slideshowMapped}
                    initialIndex={slideshowIdx}
                    onClose={() => setSlideshowItems(null)}
                />
            )}

            <div className="flex min-h-full min-w-0">
                <div className="min-w-0 flex-1">
                    {/* Header */}
                    <div className="sticky top-0 z-20 flex flex-col gap-2 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/95 px-2 py-2 backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between sm:px-4 sm:py-2.5">
                        <div className="flex items-center gap-3">
                            <h1 className="text-sm font-semibold text-white">Fotky</h1>
                            <span className="text-xs text-[var(--color-text-secondary)]">{allItems.length} položek</span>
                        </div>
                        <div className="flex w-full items-center justify-between gap-2 overflow-x-auto scrollbar-hide sm:w-auto sm:justify-end">
                            <div className="flex items-center gap-0.5 bg-[var(--color-bg-card)] rounded-lg p-0.5 border border-[var(--color-border)]">
                                {GRID_SIZES.map((_,i) => (
                                    <button key={i} onClick={()=>setGridSizeIdx(i)}
                                        className={`px-2 py-1 rounded text-xs transition-colors ${i===gridSizeIdx?'bg-[var(--color-accent)] text-white':'text-[var(--color-text-secondary)] hover:text-white'}`}
                                    >{i===0?'XS':i===1?'S':i===2?'M':'L'}</button>
                                ))}
                            </div>
                            <Link href="/map" className="p-1.5 rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white transition-colors" title="Mapa"><Map size={14} /></Link>
                            <button onClick={()=>allItems.length&&startSlideshow(allItems[0].uuid)} disabled={!allItems.length}
                                className="p-1.5 rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white transition-colors disabled:opacity-40" title="Slideshow">
                                <Maximize2 size={14} />
                            </button>
                            <Grid3X3 size={14} className="text-[var(--color-accent)]" />
                        </div>
                    </div>

                    {/* Bulk action bar (floating, bottom-fixed) */}
                    {selected.size > 0 && (
                        <BulkActionBar
                            selectedUuids={Array.from(selected)}
                            totalCount={allItems.length}
                            onSelectAll={selectAll}
                            onClearAll={clearSelect}
                            onDone={(msg) => {
                                clearSelect();
                                queryClient.invalidateQueries({ queryKey: ['timeline'] });
                            }}
                        />
                    )}

                    {isLoading && (
                        <div className="grid grid-cols-2 gap-0.5 p-2 sm:grid-cols-[repeat(auto-fill,minmax(140px,1fr))] sm:p-4">
                            {Array.from({length:24}).map((_,i)=><div key={i} className="aspect-square rounded bg-[var(--color-bg-card)] animate-pulse"/>)}
                        </div>
                    )}

                    {!isLoading && allItems.length===0 && (
                        <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                            <p className="text-lg mb-2">Zatím žádné fotky</p>
                            <p className="text-sm">Nahrajte první fotografie nebo videa</p>
                        </div>
                    )}

                    {Object.entries(sections).map(([monthLabel, dayGroups]) => (
                        <section key={monthLabel} id={`section-${monthLabel.replace(/\s/g,'_')}`}>
                            <div className="sticky top-[83px] z-10 bg-[var(--color-bg-primary)]/95 px-2 py-2 backdrop-blur border-b border-[var(--color-border)]/50 sm:top-[41px] sm:px-4">
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-semibold text-white">{monthLabel}</h2>
                                    <button onClick={()=>{
                                        const uuids = dayGroups.flatMap(g=>g.items.map(i=>i.uuid));
                                        setSelected(prev=>{
                                            const n=new Set(prev);
                                            const allIn = uuids.every(u=>n.has(u));
                                            uuids.forEach(u => allIn?n.delete(u):n.add(u));
                                            return n;
                                        });
                                    }} className="text-[10px] text-[var(--color-text-secondary)] hover:text-white transition-colors">
                                        vybrat měsíc
                                    </button>
                                </div>
                            </div>
                            {dayGroups.map(group => (
                                <div key={group.date} className="px-2 pb-4 sm:px-4" style={{contentVisibility:'auto',containIntrinsicSize:'auto 420px'}}>
                                    <div className="py-2 flex items-center gap-2">
                                        <span className="text-xs font-medium text-[var(--color-text-secondary)]">{group.label}</span>
                                        <span className="text-xs text-[var(--color-text-secondary)]/60">— {group.items.length} médií</span>
                                    </div>
                                    <div className="flex flex-wrap gap-0.5">
                                        {group.items.map(item => (
                                            <MediaCardComponent key={item.id} item={item} size={gridSize}
                                                selected={selected.has(item.uuid)}
                                                onFav={toggleFav} onTrash={trashItem}
                                                onSlideshow={startSlideshow} onSelect={toggleSelect}
                                            />
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </section>
                    ))}

                    <div ref={sentinelRef} className="h-16 flex items-center justify-center">
                        {isFetchingNextPage && <div className="w-5 h-5 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin"/>}
                    </div>
                </div>

                {/* Right scrubber */}
                {scrubBuckets.length > 2 && (
                    <div className="hidden lg:flex flex-col items-center py-4 w-12 shrink-0 border-l border-[var(--color-border)] overflow-y-auto">
                        {scrubBuckets.map(b => (
                            <button key={b.key}
                                onClick={() => document.getElementById(`section-${b.key.replace(/\s/g,'_')}`)?.scrollIntoView({behavior:'smooth',block:'start'})}
                                className="text-[9px] text-[var(--color-text-secondary)] hover:text-white py-1 leading-tight text-center w-full" title={b.label}
                            >
                                {b.label.split(' ')[0].substring(0,3)}<br/>
                                <span className="text-[8px]">{b.label.split(' ')[1]}</span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
