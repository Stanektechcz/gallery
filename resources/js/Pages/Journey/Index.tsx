import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Camera, CheckCircle, Heart, MapPin, Music, Plus, RefreshCw, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';

interface JourneyEvent {
    id: number; title: string; story?: string; event_date: string;
    place_name?: string; place_display_name?: string; emotion?: string;
    song_link?: string; latitude?: number; longitude?: number;
    source: 'manual' | 'auto'; linked_itinerary_id?: number;
    photo_count: number; thumbs: string[];
}
interface Suggestion {
    key: string; visit_date: string; latitude: number; longitude: number;
    photo_count: number; first_photo: string; last_photo: string;
    place_hint?: string; thumb_urls: string[];
}

const EMOTIONS = ['❤️','😊','🥹','🎉','😂','🌟','🏖️','✈️','🏠','🎂','📸','⛰️','🌸'];
const EMPTY_FORM = { title:'', story:'', event_date:'', place_name:'', emotion:'❤️', song_link:'', latitude:'', longitude:'' };

export default function JourneyIndex() {
    const [events,        setEvents]        = useState<JourneyEvent[]>([]);
    const [loading,       setLoading]       = useState(true);
    const [showForm,      setShowForm]      = useState(false);
    const [form,          setForm]          = useState({ ...EMPTY_FORM });

    const [showSuggest,    setShowSuggest]    = useState(false);
    const [suggestions,    setSuggestions]    = useState<Suggestion[]>([]);
    const [loadingSuggest, setLoadingSuggest] = useState(false);
    const [selected,       setSelected]       = useState<Set<string>>(new Set());
    const [importTitles,   setImportTitles]   = useState<Record<string, string>>({});
    const [importEmotions, setImportEmotions] = useState<Record<string, string>>({});
    const [importing,      setImporting]      = useState(false);

    const [editingId,   setEditingId]   = useState<number | null>(null);
    const [editForm,    setEditForm]    = useState({ title:'', story:'', emotion:'❤️', song_link:'' });
    const [expandedId,  setExpandedId]  = useState<number | null>(null);
    const [eventPhotos, setEventPhotos] = useState<Record<number, any[]>>({});

    useEffect(() => {
        axios.get('/api/v1/journey').then(r => setEvents(r.data ?? [])).finally(() => setLoading(false));
    }, []);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        const r = await axios.post('/api/v1/journey', {
            ...form,
            latitude:  form.latitude  ? parseFloat(form.latitude)  : undefined,
            longitude: form.longitude ? parseFloat(form.longitude) : undefined,
        });
        setEvents(prev => [r.data, ...prev]);
        setForm({ ...EMPTY_FORM }); setShowForm(false);
    };

    const del = async (id: number) => {
        if (!confirm('Smazat tuto vzpomínku?')) return;
        await axios.delete(`/api/v1/journey/${id}`);
        setEvents(prev => prev.filter(e => e.id !== id));
    };

    const saveEdit = async (id: number) => {
        const r = await axios.patch(`/api/v1/journey/${id}`, editForm);
        setEvents(prev => prev.map(e => e.id === id ? { ...e, ...r.data } : e));
        setEditingId(null);
    };

    const loadSuggestions = async () => {
        setLoadingSuggest(true); setShowSuggest(true);
        try {
            const r = await axios.get('/api/v1/journey/auto-suggest');
            setSuggestions(r.data ?? []);
            const titles: Record<string, string> = {};
            const emotions: Record<string, string> = {};
            (r.data as Suggestion[]).forEach(s => {
                titles[s.key]   = s.place_hint ?? new Date(s.visit_date).toLocaleDateString('cs-CZ', { day:'numeric', month:'long', year:'numeric' });
                emotions[s.key] = '📸';
            });
            setImportTitles(titles); setImportEmotions(emotions); setSelected(new Set());
        } finally { setLoadingSuggest(false); }
    };

    const toggleSelected = (key: string) => setSelected(prev => {
        const n = new Set(prev); n.has(key) ? n.delete(key) : n.add(key); return n;
    });

    const importSelected = async () => {
        const toImport = suggestions.filter(s => selected.has(s.key)).map(s => ({
            title:      importTitles[s.key] || new Date(s.visit_date).toLocaleDateString('cs-CZ'),
            event_date: s.visit_date, latitude: s.latitude, longitude: s.longitude,
            place_name: s.place_hint ?? null, emotion: importEmotions[s.key] ?? '📸',
        }));
        if (!toImport.length) return;
        setImporting(true);
        try {
            await axios.post('/api/v1/journey/auto-import', { events: toImport });
            const r = await axios.get('/api/v1/journey');
            setEvents(r.data ?? []); setShowSuggest(false); setSuggestions([]);
        } finally { setImporting(false); }
    };

    const togglePhotos = async (id: number) => {
        if (expandedId === id) { setExpandedId(null); return; }
        setExpandedId(id);
        if (eventPhotos[id]) return;
        const r = await axios.get(`/api/v1/journey/${id}/photos`);
        setEventPhotos(prev => ({ ...prev, [id]: r.data }));
    };

    const years = [...new Set(events.map(e => new Date(e.event_date).getFullYear()))].sort((a, b) => b - a);

    return (
        <AppLayout>
            <Head title="Naše cesta" />
            <div className="p-4 max-w-3xl mx-auto pb-10">

                {/* Header */}
                <div className="flex items-center justify-between mb-6 flex-wrap gap-3">
                    <div>
                        <h1 className="text-xl font-bold text-white flex items-center gap-2">
                            Naše cesta <Heart size={18} className="text-red-400 fill-red-400"/>
                        </h1>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">
                            Vaše společná digitální kronika · {events.length} vzpomínek
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button onClick={loadSuggestions} disabled={loadingSuggest}
                            className="flex items-center gap-1.5 border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white text-sm px-3 py-2 rounded-lg transition-colors disabled:opacity-40">
                            <Camera size={14}/> Auto-detekce z fotek
                        </button>
                        <button onClick={() => setShowForm(v=>!v)}
                            className="flex items-center gap-1.5 bg-[var(--color-accent)] text-white text-sm px-3 py-2 rounded-lg hover:opacity-90">
                            <Plus size={14}/> Přidat
                        </button>
                    </div>
                </div>

                {/* Manual add form */}
                {showForm && (
                    <form onSubmit={submit} className="mb-6 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 space-y-3">
                        <h2 className="text-sm font-semibold text-white">Nová vzpomínka</h2>
                        <input required value={form.title} onChange={e=>setForm(p=>({...p,title:e.target.value}))}
                            placeholder="Nadpis *" className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                        <textarea value={form.story} onChange={e=>setForm(p=>({...p,story:e.target.value}))}
                            placeholder="Příběh…" rows={3} className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none"/>
                        <div className="grid grid-cols-2 gap-3">
                            <input required type="date" value={form.event_date} onChange={e=>setForm(p=>({...p,event_date:e.target.value}))}
                                className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white outline-none focus:border-[var(--color-accent)]"/>
                            <input value={form.place_name} onChange={e=>setForm(p=>({...p,place_name:e.target.value}))}
                                placeholder="Místo" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <input value={form.latitude} onChange={e=>setForm(p=>({...p,latitude:e.target.value}))} type="number" step="any"
                                placeholder="Zeměpisná šířka" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                            <input value={form.longitude} onChange={e=>setForm(p=>({...p,longitude:e.target.value}))} type="number" step="any"
                                placeholder="Zeměpisná délka" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                        </div>
                        <div className="flex gap-2 flex-wrap">
                            {EMOTIONS.map(em => (
                                <button key={em} type="button" onClick={()=>setForm(p=>({...p,emotion:em}))}
                                    className={`text-xl p-1 rounded-lg transition-all ${form.emotion===em?'bg-[var(--color-accent)]/30 ring-1 ring-[var(--color-accent)]':''}`}>{em}</button>
                            ))}
                        </div>
                        <input value={form.song_link} onChange={e=>setForm(p=>({...p,song_link:e.target.value}))}
                            placeholder="Odkaz na písničku (Spotify, YouTube…)" className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]"/>
                        <div className="flex gap-2">
                            <button type="submit" className="flex-1 bg-[var(--color-accent)] text-white text-sm py-2 rounded-lg hover:opacity-90">Uložit vzpomínku</button>
                            <button type="button" onClick={()=>setShowForm(false)} className="px-4 text-sm border border-[var(--color-border)] text-[var(--color-text-secondary)] rounded-lg hover:text-white">Zrušit</button>
                        </div>
                    </form>
                )}

                {/* Auto-suggest panel */}
                {showSuggest && (
                    <div className="mb-6 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl overflow-hidden">
                        <div className="flex items-center justify-between px-4 py-3 border-b border-[var(--color-border)]">
                            <div>
                                <h2 className="text-sm font-semibold text-white flex items-center gap-2">
                                    <Camera size={15} className="text-[var(--color-accent)]"/> Auto-detekce z fotek
                                </h2>
                                {!loadingSuggest && suggestions.length > 0 && (
                                    <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">
                                        {suggestions.length} nových dní s GPS · {selected.size} vybráno
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                {!loadingSuggest && suggestions.length > 0 && (
                                    <>
                                        <button onClick={()=>setSelected(new Set(suggestions.map(s=>s.key)))} className="text-xs text-[var(--color-accent)] hover:underline">Vybrat vše</button>
                                        <button onClick={()=>setSelected(new Set())} className="text-xs text-[var(--color-text-secondary)] hover:underline">Žádný</button>
                                    </>
                                )}
                                <button onClick={()=>setShowSuggest(false)} className="text-[var(--color-text-secondary)] hover:text-white"><X size={16}/></button>
                            </div>
                        </div>

                        {loadingSuggest ? (
                            <div className="p-8 text-center">
                                <RefreshCw size={24} className="mx-auto mb-2 text-[var(--color-accent)] animate-spin"/>
                                <p className="text-sm text-[var(--color-text-secondary)]">Analyzuji fotky a GPS data…</p>
                                <p className="text-xs text-[var(--color-text-secondary)] mt-1">Probíhá reverzní geokódování…</p>
                            </div>
                        ) : suggestions.length === 0 ? (
                            <div className="p-8 text-center text-[var(--color-text-secondary)]">
                                <Camera size={28} className="mx-auto mb-2 opacity-30"/>
                                <p className="text-sm">Žádné nové fotky s GPS daty</p>
                                <p className="text-xs mt-1">Všechna místa jsou již v kronice</p>
                            </div>
                        ) : (
                            <div className="max-h-96 overflow-y-auto divide-y divide-[var(--color-border)]">
                                {suggestions.map(s => (
                                    <div key={s.key} className={`p-3 transition-colors ${selected.has(s.key) ? 'bg-[var(--color-accent)]/10' : 'hover:bg-[var(--color-bg-secondary)]'}`}>
                                        <div className="flex items-start gap-3">
                                            <button onClick={()=>toggleSelected(s.key)}
                                                className={`mt-1 w-4 h-4 rounded border shrink-0 flex items-center justify-center transition-colors ${selected.has(s.key) ? 'bg-[var(--color-accent)] border-[var(--color-accent)]' : 'border-[var(--color-border)] hover:border-[var(--color-accent)]'}`}>
                                                {selected.has(s.key) && <CheckCircle size={12} className="text-white"/>}
                                            </button>
                                            <div className="flex gap-1 shrink-0">
                                                {s.thumb_urls.slice(0, 3).map((url, i) => (
                                                    <img key={i} src={url} alt="" className="w-10 h-10 object-cover rounded"/>
                                                ))}
                                                {s.photo_count > 3 && (
                                                    <div className="w-10 h-10 bg-[var(--color-bg-secondary)] rounded flex items-center justify-center text-[10px] text-[var(--color-text-secondary)]">+{s.photo_count-3}</div>
                                                )}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-[10px] text-[var(--color-text-secondary)] mb-1">
                                                    {new Date(s.visit_date).toLocaleDateString('cs-CZ', { day:'numeric', month:'long', year:'numeric' })} · 📸 {s.photo_count}
                                                    {s.place_hint && <> · <span className="text-[var(--color-accent)]">📍 {s.place_hint}</span></>}
                                                </p>
                                                <input
                                                    value={importTitles[s.key] ?? ''}
                                                    onChange={e=>setImportTitles(p=>({...p,[s.key]:e.target.value}))}
                                                    onFocus={()=>!selected.has(s.key)&&toggleSelected(s.key)}
                                                    placeholder="Název vzpomínky…"
                                                    className="w-full bg-transparent text-xs text-white placeholder-[var(--color-text-secondary)] outline-none border-b border-transparent focus:border-[var(--color-border)] pb-0.5"
                                                />
                                            </div>
                                            <div className="flex gap-0.5 shrink-0">
                                                {['📸','✈️','🏖️','❤️','🎉'].map(em => (
                                                    <button key={em} type="button" onClick={()=>setImportEmotions(p=>({...p,[s.key]:em}))}
                                                        className={`text-sm p-0.5 rounded transition-all ${importEmotions[s.key]===em?'bg-[var(--color-accent)]/30':'opacity-40 hover:opacity-100'}`}>
                                                        {em}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {!loadingSuggest && selected.size > 0 && (
                            <div className="px-4 py-3 border-t border-[var(--color-border)] flex justify-between items-center">
                                <p className="text-xs text-[var(--color-text-secondary)]">{selected.size} vzpomínek bude přidáno</p>
                                <button onClick={importSelected} disabled={importing}
                                    className="flex items-center gap-1.5 bg-[var(--color-accent)] text-white text-xs px-4 py-2 rounded-lg hover:opacity-90 disabled:opacity-40">
                                    {importing ? <RefreshCw size={12} className="animate-spin"/> : <CheckCircle size={12}/>}
                                    Importovat {selected.size}
                                </button>
                            </div>
                        )}
                    </div>
                )}

                {/* Timeline */}
                {loading ? (
                    <div className="space-y-4">{[1,2,3].map(i=><div key={i} className="h-24 bg-[var(--color-bg-card)] rounded-xl animate-pulse"/>)}</div>
                ) : events.length === 0 ? (
                    <div className="text-center py-12 text-[var(--color-text-secondary)]">
                        <Heart size={40} className="mx-auto mb-3 opacity-30"/>
                        <p>Vaše kronika je prázdná</p>
                        <p className="text-sm mt-1">Přidejte vzpomínku nebo použijte <strong>Auto-detekci z fotek</strong></p>
                    </div>
                ) : (
                    <div className="space-y-8">
                        {years.map(year => (
                            <div key={year}>
                                <h2 className="text-xs font-bold text-[var(--color-text-secondary)] uppercase tracking-widest mb-4 flex items-center gap-2">
                                    <span className="h-px flex-1 bg-[var(--color-border)]"/>
                                    {year}
                                    <span className="h-px flex-1 bg-[var(--color-border)]"/>
                                </h2>
                                <div className="relative">
                                    <div className="absolute left-5 top-0 bottom-0 w-0.5 bg-[var(--color-border)]"/>
                                    <div className="space-y-5">
                                        {events.filter(ev => new Date(ev.event_date).getFullYear() === year).map(ev => (
                                            <div key={ev.id} className="flex gap-4">
                                                <div className={`w-10 h-10 shrink-0 rounded-full flex items-center justify-center text-lg z-10 border-2 ${ev.source==='auto' ? 'border-[var(--color-border)] bg-[var(--color-bg-secondary)]' : 'border-[var(--color-accent)] bg-[var(--color-bg-secondary)]'}`}>
                                                    {ev.emotion || '❤️'}
                                                </div>
                                                <div className="flex-1 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl overflow-hidden hover:border-[var(--color-accent)]/40 transition-colors">
                                                    <div className="p-4">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div className="flex-1">
                                                                <p className="text-[10px] text-[var(--color-text-secondary)] mb-1 flex items-center gap-2 flex-wrap">
                                                                    {new Date(ev.event_date).toLocaleDateString('cs-CZ', { day:'numeric', month:'long' })}
                                                                    {ev.source === 'auto' && <span className="bg-[var(--color-bg-secondary)] px-1.5 py-0.5 rounded text-[9px]">auto</span>}
                                                                    {ev.linked_itinerary_id && <span className="text-[var(--color-accent)] text-[9px]">🌍 itinerář</span>}
                                                                    {ev.latitude && ev.longitude && <span className="text-[9px]">📍 {ev.latitude.toFixed(2)}°, {ev.longitude.toFixed(2)}°</span>}
                                                                </p>
                                                                {editingId === ev.id ? (
                                                                    <input autoFocus value={editForm.title} onChange={e=>setEditForm(p=>({...p,title:e.target.value}))}
                                                                        className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded px-2 py-1 text-sm text-white outline-none focus:border-[var(--color-accent)] mb-1"/>
                                                                ) : (
                                                                    <h3 className="text-sm font-semibold text-white">{ev.title}</h3>
                                                                )}
                                                                {(ev.place_name || ev.place_display_name) && (
                                                                    <p className="flex items-center gap-1 text-xs text-[var(--color-text-secondary)] mt-1">
                                                                        <MapPin size={10}/> {ev.place_name || ev.place_display_name}
                                                                    </p>
                                                                )}
                                                                {editingId === ev.id ? (
                                                                    <textarea value={editForm.story} onChange={e=>setEditForm(p=>({...p,story:e.target.value}))}
                                                                        placeholder="Příběh…" rows={3}
                                                                        className="w-full mt-2 bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded px-2 py-1 text-xs text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none"/>
                                                                ) : ev.story ? (
                                                                    <p className="text-xs text-[var(--color-text-secondary)] mt-2 leading-relaxed">{ev.story}</p>
                                                                ) : null}
                                                                {ev.song_link && (
                                                                    <a href={ev.song_link} target="_blank" rel="noopener noreferrer"
                                                                        className="flex items-center gap-1 text-xs text-[var(--color-accent)] mt-2 hover:underline">
                                                                        <Music size={10}/> Písička
                                                                    </a>
                                                                )}
                                                            </div>
                                                            <div className="flex flex-col gap-1 shrink-0">
                                                                {editingId === ev.id ? (
                                                                    <>
                                                                        <button onClick={()=>saveEdit(ev.id)} className="text-[10px] bg-[var(--color-accent)] text-white px-2 py-1 rounded hover:opacity-90">Uložit</button>
                                                                        <button onClick={()=>setEditingId(null)} className="text-[10px] border border-[var(--color-border)] text-[var(--color-text-secondary)] px-2 py-1 rounded hover:text-white">Zrušit</button>
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <button onClick={()=>{setEditingId(ev.id);setEditForm({title:ev.title,story:ev.story??'',emotion:ev.emotion??'❤️',song_link:ev.song_link??''});}}
                                                                            className="text-[10px] border border-[var(--color-border)] text-[var(--color-text-secondary)] px-2 py-1 rounded hover:text-white">✏️</button>
                                                                        <button onClick={()=>del(ev.id)} className="p-1.5 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors">
                                                                            <Trash2 size={13}/>
                                                                        </button>
                                                                    </>
                                                                )}
                                                            </div>
                                                        </div>
                                                        {ev.thumbs && ev.thumbs.length > 0 && (
                                                            <div className="flex gap-1.5 mt-3 flex-wrap">
                                                                {ev.thumbs.slice(0, 5).map((url, i) => (
                                                                    <img key={i} src={url} alt="" className="w-12 h-12 object-cover rounded-lg"/>
                                                                ))}
                                                                {ev.photo_count > 5 && (
                                                                    <button onClick={()=>togglePhotos(ev.id)}
                                                                        className="w-12 h-12 bg-[var(--color-bg-secondary)] rounded-lg flex items-center justify-center text-[10px] text-[var(--color-text-secondary)] hover:text-white transition-colors">
                                                                        +{ev.photo_count - 5}
                                                                    </button>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                    {expandedId === ev.id && eventPhotos[ev.id] && (
                                                        <div className="border-t border-[var(--color-border)] p-3">
                                                            <div className="flex items-center justify-between mb-2">
                                                                <p className="text-[10px] text-[var(--color-text-secondary)]">Všechny fotky z tohoto dne</p>
                                                                <button onClick={()=>setExpandedId(null)} className="text-[var(--color-text-secondary)] hover:text-white"><X size={13}/></button>
                                                            </div>
                                                            <div className="flex gap-1.5 flex-wrap">
                                                                {eventPhotos[ev.id].map((p: any) => (
                                                                    <a key={p.uuid} href={`/media/${p.uuid}`} target="_blank" rel="noopener noreferrer">
                                                                        <img src={p.thumbnail_url} alt={p.file_name} className="w-14 h-14 object-cover rounded hover:opacity-90 transition-opacity"/>
                                                                    </a>
                                                                ))}
                                                                {eventPhotos[ev.id].length === 0 && (
                                                                    <p className="text-xs text-[var(--color-text-secondary)]">Žádné fotky s GPS k tomuto záznamu</p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
