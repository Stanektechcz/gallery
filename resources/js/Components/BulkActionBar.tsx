import axios from 'axios';
import {
    Archive, ArrowRight, Calendar, Download,
    FolderPlus, Heart, Layers, MapPin, Printer, Star, Tag, Trash2, Users, X,
} from 'lucide-react';
import { useState } from 'react';

type Panel = null | 'album' | 'move' | 'tag' | 'person' | 'place' | 'date' | 'rating';

interface BulkActionBarProps {
    selectedUuids: string[];
    totalCount: number;
    onSelectAll: () => void;
    onClearAll: () => void;
    onDone: (message?: string) => void;
}

export function BulkActionBar({
    selectedUuids, totalCount, onSelectAll, onClearAll, onDone,
}: BulkActionBarProps) {
    const [panel,        setPanel]        = useState<Panel>(null);
    const [loading,      setLoading]      = useState(false);
    const [albums,       setAlbums]       = useState<any[]>([]);
    const [tags,         setTags]         = useState<any[]>([]);
    const [people,       setPeople]       = useState<any[]>([]);
    const [places,       setPlaces]       = useState<any[]>([]);
    const [albumSearch,  setAlbumSearch]  = useState('');
    const [tagSearch,    setTagSearch]    = useState('');
    const [personSearch, setPersonSearch] = useState('');
    const [placeSearch,  setPlaceSearch]  = useState('');
    const [hourOffset,   setHourOffset]   = useState(0);
    const [hoverRating,  setHoverRating]  = useState(0);

    const count = selectedUuids.length;

    const openPanel = async (p: Panel) => {
        setPanel(p === panel ? null : p);
        if ((p === 'album' || p === 'move') && albums.length === 0) {
            const r = await axios.get('/api/v1/albums');
            setAlbums(r.data?.data ?? r.data ?? []);
        }
        if (p === 'tag' && tags.length === 0) {
            const r = await axios.get('/api/v1/tags');
            setTags(r.data ?? []);
        }
        if (p === 'person' && people.length === 0) {
            const r = await axios.get('/api/v1/people');
            setPeople(r.data?.data ?? r.data ?? []);
        }
        if (p === 'place' && places.length === 0) {
            const r = await axios.get('/api/v1/places');
            setPlaces(r.data ?? []);
        }
    };

    const bulk = async (action: string, extra?: Record<string, any>) => {
        setLoading(true);
        setPanel(null);
        try {
            const r = await axios.post('/api/v1/media/bulk', {
                action,
                uuids: selectedUuids,
                ...extra,
            });
            onDone(`Hotovo: ${r.data.processed} médií`);
        } catch (e: any) {
            alert(e?.response?.data?.message ?? 'Chyba při hromadné operaci');
        } finally {
            setLoading(false);
        }
    };

    const handleDownload = async () => {
        setLoading(true);
        try {
            const r = await axios.post('/api/v1/exports', { uuids: selectedUuids });
            if (r.data?.id) {
                let attempts = 0;
                const poll = setInterval(async () => {
                    attempts++;
                    const s = await axios.get(`/api/v1/exports/${r.data.id}`).catch(() => null);
                    if (s?.data?.download_url || attempts > 60) {
                        clearInterval(poll);
                        if (s?.data?.download_url) window.open(s.data.download_url, '_blank');
                    }
                }, 2000);
            }
            onDone('Export zahájen — odkaz se otevře po přípravě');
        } catch {
            alert('Export se nepodařilo spustit');
        } finally {
            setLoading(false);
        }
    };

    const ACTION_BTNS = [
        { key: 'album',   icon: FolderPlus, label: 'Do alba',        panel: true,  cls: 'hover:border-blue-400/50 hover:text-blue-300' },
        { key: 'move',    icon: ArrowRight,  label: 'Přesunout',      panel: true,  cls: 'hover:border-purple-400/50 hover:text-purple-300' },
        { key: 'tag',     icon: Tag,         label: 'Přidat tag',     panel: true,  cls: 'hover:border-yellow-400/50 hover:text-yellow-300' },
        { key: 'person',  icon: Users,       label: 'Přidat osobu',   panel: true,  cls: 'hover:border-green-400/50 hover:text-green-300' },
        { key: 'place',   icon: MapPin,      label: 'Nastavit místo', panel: true,  cls: 'hover:border-cyan-400/50 hover:text-cyan-300' },
        { key: 'date',    icon: Calendar,    label: 'Posunout datum', panel: true,  cls: 'hover:border-orange-400/50 hover:text-orange-300' },
        { key: 'fav',     icon: Heart,       label: 'Oblíbené',       panel: false, cls: 'hover:border-red-400/50 hover:text-red-300' },
        { key: 'rating',  icon: Star,        label: 'Hodnocení',      panel: true,  cls: 'hover:border-yellow-400/50 hover:text-yellow-300' },
        { key: 'archive', icon: Archive,     label: 'Archivovat',     panel: false, cls: 'hover:border-gray-400/50 hover:text-gray-300' },
        { key: 'dl',      icon: Download,    label: 'Stáhnout',       panel: false, cls: 'hover:border-blue-400/50 hover:text-blue-300' },
        { key: 'trash',   icon: Trash2,      label: 'Koš',            panel: false, cls: 'text-red-400 bg-red-500/10 border-red-500/30 hover:bg-red-500/20' },
    ] as const;

    const ItemList = ({ items, search, onPick, emptyText }: {
        items: any[]; search: string; onPick: (item: any) => void; emptyText: string;
    }) => {
        const filtered = items.filter(i => !search || (i.name || i.title || '').toLowerCase().includes(search.toLowerCase()));
        return (
            <div className="overflow-y-auto max-h-48">
                {filtered.length === 0 ? (
                    <p className="p-4 text-center text-xs text-[var(--color-text-secondary)]">{emptyText}</p>
                ) : filtered.map((item, i) => (
                    <button key={item.id ?? item.uuid ?? i}
                        onClick={() => onPick(item)}
                        className="w-full text-left px-4 py-2.5 hover:bg-[var(--color-bg-secondary)] border-b border-[var(--color-border)] last:border-0 text-sm text-white truncate">
                        {item.name || item.title}
                        {item.country ? <span className="text-[var(--color-text-secondary)] ml-1">· {item.country}</span> : null}
                    </button>
                ))}
            </div>
        );
    };

    return (
        <div className="fixed bottom-0 left-0 right-0 z-[500] pointer-events-none">
            {/* Sub-panel */}
            {panel && (
                <div className="pointer-events-auto mx-4 mb-1 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden">
                    {/* Search header */}
                    {(panel === 'album' || panel === 'move' || panel === 'tag' || panel === 'person' || panel === 'place') && (
                        <div className="flex items-center gap-2 px-4 py-2.5 border-b border-[var(--color-border)] bg-[var(--color-bg-secondary)]">
                            <input
                                autoFocus
                                value={panel === 'album' || panel === 'move' ? albumSearch : panel === 'tag' ? tagSearch : panel === 'person' ? personSearch : placeSearch}
                                onChange={e => {
                                    const v = e.target.value;
                                    if (panel === 'album' || panel === 'move') setAlbumSearch(v);
                                    else if (panel === 'tag') setTagSearch(v);
                                    else if (panel === 'person') setPersonSearch(v);
                                    else setPlaceSearch(v);
                                }}
                                placeholder={panel === 'album' || panel === 'move' ? 'Hledat album…' : panel === 'tag' ? 'Hledat tag…' : panel === 'person' ? 'Hledat osobu…' : 'Hledat místo…'}
                                className="flex-1 bg-transparent text-sm text-white placeholder-[var(--color-text-secondary)] outline-none"
                            />
                            <button onClick={() => setPanel(null)} className="text-[var(--color-text-secondary)] hover:text-white"><X size={14}/></button>
                        </div>
                    )}

                    {(panel === 'album' || panel === 'move') && (
                        <ItemList items={albums} search={albumSearch}
                            onPick={a => bulk(panel === 'album' ? 'add_to_album' : 'move', { album_uuid: a.uuid })}
                            emptyText="Žádná alba" />
                    )}

                    {panel === 'tag' && (
                        <div className="p-3 flex flex-wrap gap-2 max-h-48 overflow-y-auto">
                            {tags.filter(t => !tagSearch || t.name.toLowerCase().includes(tagSearch.toLowerCase())).map(tag => (
                                <button key={tag.id} onClick={() => bulk('tag', { tag_id: tag.id })}
                                    className="px-3 py-1.5 rounded-full text-xs border border-[var(--color-border)] text-white hover:bg-[var(--color-accent)] hover:border-[var(--color-accent)] transition-colors">
                                    {tag.name}
                                </button>
                            ))}
                            {tags.length === 0 && <p className="text-xs text-[var(--color-text-secondary)]">Žádné tagy</p>}
                        </div>
                    )}

                    {panel === 'person' && (
                        <ItemList items={people} search={personSearch}
                            onPick={p => bulk('add_person', { person_id: p.id })}
                            emptyText="Žádné osoby" />
                    )}

                    {panel === 'place' && (
                        <ItemList items={places} search={placeSearch}
                            onPick={p => bulk('add_place', { place_id: p.id })}
                            emptyText="Žádná místa" />
                    )}

                    {panel === 'date' && (
                        <div className="p-4">
                            <div className="flex items-center justify-between mb-4">
                                <h3 className="text-sm font-semibold text-white">Posunout datum pořízení</h3>
                                <button onClick={() => setPanel(null)} className="text-[var(--color-text-secondary)] hover:text-white"><X size={14}/></button>
                            </div>
                            <div className="flex items-center gap-4 mb-4">
                                <button onClick={() => setHourOffset(h => Math.max(-720, h - 1))}
                                    className="w-9 h-9 rounded-full border border-[var(--color-border)] text-white hover:bg-white/10 flex items-center justify-center text-lg font-bold">−</button>
                                <div className="flex-1 text-center">
                                    <p className="text-2xl font-mono text-white font-bold">{hourOffset > 0 ? '+' : ''}{hourOffset}<span className="text-sm ml-1 text-[var(--color-text-secondary)]">h</span></p>
                                    <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">
                                        {hourOffset === 0 ? 'Bez posunu' : `${Math.abs(hourOffset)} hodin ${hourOffset > 0 ? 'dopředu' : 'dozadu'}`}
                                    </p>
                                </div>
                                <button onClick={() => setHourOffset(h => Math.min(720, h + 1))}
                                    className="w-9 h-9 rounded-full border border-[var(--color-border)] text-white hover:bg-white/10 flex items-center justify-center text-lg font-bold">+</button>
                            </div>
                            <div className="flex justify-center gap-2 mb-4">
                                {[-24, -12, -1, 1, 12, 24].map(h => (
                                    <button key={h} onClick={() => setHourOffset(h)}
                                        className={`px-2.5 py-1 text-xs rounded-lg border transition-colors ${hourOffset === h ? 'bg-[var(--color-accent)] border-[var(--color-accent)] text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                                        {h > 0 ? '+' : ''}{h}h
                                    </button>
                                ))}
                            </div>
                            <button onClick={() => bulk('shift_date', { hours_offset: hourOffset })} disabled={hourOffset === 0}
                                className="w-full bg-[var(--color-accent)] text-white text-sm py-2 rounded-lg hover:opacity-90 disabled:opacity-40">
                                Posunout u {count} médií
                            </button>
                        </div>
                    )}

                    {panel === 'rating' && (
                        <div className="p-4">
                            <div className="flex items-center justify-between mb-4">
                                <h3 className="text-sm font-semibold text-white">Nastavit hodnocení</h3>
                                <button onClick={() => setPanel(null)} className="text-[var(--color-text-secondary)] hover:text-white"><X size={14}/></button>
                            </div>
                            <div className="flex justify-center gap-3 mb-4">
                                {[1,2,3,4,5].map(n => (
                                    <button key={n}
                                        onMouseEnter={() => setHoverRating(n)}
                                        onMouseLeave={() => setHoverRating(0)}
                                        onClick={() => bulk('rate', { rating: n })}
                                        className="p-1.5 hover:scale-125 transition-transform">
                                        <Star size={30} className={hoverRating >= n ? 'text-yellow-400 fill-yellow-400' : 'text-[var(--color-border)]'}/>
                                    </button>
                                ))}
                            </div>
                            <button onClick={() => bulk('rate', { rating: 0 })}
                                className="w-full text-xs text-[var(--color-text-secondary)] hover:text-white py-1 border border-[var(--color-border)] rounded-lg">
                                Odebrat hodnocení
                            </button>
                        </div>
                    )}
                </div>
            )}

            {/* Main bar */}
            <div className="pointer-events-auto bg-[var(--color-bg-secondary)]/96 backdrop-blur-xl border-t border-[var(--color-border)] px-4 pt-2.5 pb-3 shadow-2xl">
                {/* Count row */}
                <div className="flex items-center gap-3 mb-2.5">
                    <span className="text-xs font-bold text-[var(--color-accent)]">{count} vybráno</span>
                    <button onClick={onSelectAll}
                        className="text-[10px] text-[var(--color-text-secondary)] hover:text-white underline underline-offset-2">
                        Vybrat vše ({totalCount})
                    </button>
                    <div className="flex-1"/>
                    {loading && <span className="text-[10px] text-[var(--color-text-secondary)] animate-pulse">Zpracovávám…</span>}
                    <button onClick={onClearAll}
                        className="flex items-center gap-1 text-[10px] text-[var(--color-text-secondary)] hover:text-white border border-[var(--color-border)] px-2 py-1 rounded-lg transition-colors">
                        <X size={10}/> Zrušit výběr
                    </button>
                </div>

                {/* Action buttons */}
                <div className="flex gap-1.5 overflow-x-auto pb-0.5 scrollbar-none -mx-1 px-1">
                    {/* Compare button — only when 2-4 items selected */}
                    {count >= 2 && count <= 4 && (
                        <a href={`/compare?uuids=${selectedUuids.slice(0, 4).join(',')}`}
                            className="flex items-center gap-1.5 whitespace-nowrap text-xs px-3 py-1.5 rounded-lg border transition-all shrink-0 text-[var(--color-accent)] bg-[var(--color-accent)]/10 border-[var(--color-accent)]/40 hover:bg-[var(--color-accent)]/20">
                            <Layers size={12}/>
                            Porovnat {count}
                        </a>
                    )}

                    {/* Add to print selection */}
                    <a href="/print"
                        onClick={async (e) => {
                            e.preventDefault();
                            const bookUuid = prompt('UUID fotoknihy (nechte prázdné pro přechod na /print):');
                            if (bookUuid) {
                                await axios.post(`/api/v1/books/${bookUuid}/items`, { media_uuids: selectedUuids });
                                onDone(`Přidáno ${count} fotek do výběru`);
                            } else {
                                window.open('/print', '_blank');
                            }
                        }}
                        className="flex items-center gap-1.5 whitespace-nowrap text-xs px-3 py-1.5 rounded-lg border transition-all shrink-0 text-[var(--color-text-secondary)] bg-[var(--color-bg-card)] border-[var(--color-border)] hover:text-white hover:border-orange-400/50">
                        <Printer size={12}/>
                        Do výběru
                    </a>

                    {ACTION_BTNS.map(a => {
                        const Icon = a.icon;
                        const isActive = panel === a.key;

                        const handleClick = () => {
                            if (loading) return;
                            if (a.key === 'fav')     { bulk('favorite'); return; }
                            if (a.key === 'archive') { bulk('archive'); return; }
                            if (a.key === 'dl')      { handleDownload(); return; }
                            if (a.key === 'trash') {
                                if (!confirm(`Přesunout ${count} médií do koše?`)) return;
                                bulk('trash'); return;
                            }
                            if (a.panel) openPanel(a.key as Panel);
                        };

                        return (
                            <button key={a.key} onClick={handleClick} disabled={loading}
                                className={`flex items-center gap-1.5 whitespace-nowrap text-xs px-3 py-1.5 rounded-lg border transition-all shrink-0 disabled:opacity-40 ${
                                    isActive
                                        ? 'bg-[var(--color-accent)] border-[var(--color-accent)] text-white'
                                        : `text-[var(--color-text-secondary)] bg-[var(--color-bg-card)] border-[var(--color-border)] ${a.cls}`
                                }`}>
                                <Icon size={12}/>
                                {a.label}
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
