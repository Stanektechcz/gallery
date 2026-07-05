import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Heart, MapPin, Music, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface JourneyEvent {
    id: number;
    title: string;
    story?: string;
    event_date: string;
    place_name?: string;
    emotion?: string;
    song_link?: string;
    photo_count: number;
    thumb?: string;
}

const EMOTIONS = ['❤️','😊','🥹','🎉','😂','🌟','🏖️','✈️','🏠','🎂'];

export default function JourneyIndex() {
    const [events, setEvents] = useState<JourneyEvent[]>([]);
    const [loading, setLoading] = useState(true);
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState({ title: '', story: '', event_date: '', place_name: '', emotion: '❤️', song_link: '' });

    useEffect(() => {
        axios.get('/api/v1/journey').then(r => setEvents(r.data ?? [])).finally(() => setLoading(false));
    }, []);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        const r = await axios.post('/api/v1/journey', form);
        setEvents(prev => [r.data, ...prev]);
        setForm({ title: '', story: '', event_date: '', place_name: '', emotion: '❤️', song_link: '' });
        setShowForm(false);
    };

    const del = async (id: number) => {
        if (!confirm('Smazat tuto vzpomínku?')) return;
        await axios.delete(`/api/v1/journey/${id}`);
        setEvents(prev => prev.filter(e => e.id !== id));
    };

    return (
        <AppLayout>
            <Head title="Naše cesta" />
            <div className="p-4 max-w-2xl mx-auto pb-8">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-xl font-bold text-white flex items-center gap-2">Naše cesta <Heart size={18} className="text-red-400 fill-red-400" /></h1>
                        <p className="text-xs text-[var(--color-text-secondary)] mt-0.5">Vaše společná digitální kronika</p>
                    </div>
                    <button onClick={() => setShowForm(v=>!v)}
                        className="flex items-center gap-1.5 bg-[var(--color-accent)] text-white text-sm px-3 py-2 rounded-lg hover:opacity-90 transition-opacity">
                        <Plus size={14} /> Přidat
                    </button>
                </div>

                {/* Add form */}
                {showForm && (
                    <form onSubmit={submit} className="mb-6 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 space-y-3">
                        <h2 className="text-sm font-semibold text-white">Nová vzpomínka</h2>
                        <input required value={form.title} onChange={e => setForm(p=>({...p,title:e.target.value}))}
                            placeholder="Nadpis *" className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]" />
                        <textarea value={form.story} onChange={e => setForm(p=>({...p,story:e.target.value}))}
                            placeholder="Příběh…" rows={3} className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)] resize-none" />
                        <div className="grid grid-cols-2 gap-3">
                            <input required type="date" value={form.event_date} onChange={e => setForm(p=>({...p,event_date:e.target.value}))}
                                className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white outline-none focus:border-[var(--color-accent)]" />
                            <input value={form.place_name} onChange={e => setForm(p=>({...p,place_name:e.target.value}))}
                                placeholder="Místo" className="bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]" />
                        </div>
                        <div className="flex gap-2 flex-wrap">
                            {EMOTIONS.map(em => (
                                <button key={em} type="button" onClick={() => setForm(p=>({...p,emotion:em}))}
                                    className={`text-xl p-1 rounded-lg transition-all ${form.emotion===em?'bg-[var(--color-accent)]/30 ring-1 ring-[var(--color-accent)]':''}`}>{em}</button>
                            ))}
                        </div>
                        <input value={form.song_link} onChange={e => setForm(p=>({...p,song_link:e.target.value}))}
                            placeholder="Odkaz na písničku (Spotify, YouTube…)" className="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm text-white placeholder-[var(--color-text-secondary)] outline-none focus:border-[var(--color-accent)]" />
                        <div className="flex gap-2">
                            <button type="submit" className="flex-1 bg-[var(--color-accent)] text-white text-sm py-2 rounded-lg hover:opacity-90">Uložit</button>
                            <button type="button" onClick={()=>setShowForm(false)} className="px-4 text-sm border border-[var(--color-border)] text-[var(--color-text-secondary)] rounded-lg hover:text-white">Zrušit</button>
                        </div>
                    </form>
                )}

                {/* Timeline */}
                {loading ? (
                    <div className="space-y-4">
                        {[1,2,3].map(i=><div key={i} className="h-24 bg-[var(--color-bg-card)] rounded-xl animate-pulse"/>)}
                    </div>
                ) : events.length === 0 ? (
                    <div className="text-center py-12 text-[var(--color-text-secondary)]">
                        <Heart size={40} className="mx-auto mb-3 opacity-30" />
                        <p>Vaše kronika je prázdná</p>
                        <p className="text-sm mt-1">Začněte přidáním první vzpomínky</p>
                    </div>
                ) : (
                    <div className="relative">
                        {/* Vertical line */}
                        <div className="absolute left-5 top-0 bottom-0 w-0.5 bg-[var(--color-border)]" />

                        <div className="space-y-6">
                            {events.map(ev => (
                                <div key={ev.id} className="flex gap-4">
                                    {/* Dot */}
                                    <div className="w-10 h-10 shrink-0 rounded-full bg-[var(--color-bg-secondary)] border-2 border-[var(--color-accent)] flex items-center justify-center text-lg z-10">
                                        {ev.emotion || '❤️'}
                                    </div>

                                    {/* Card */}
                                    <div className="flex-1 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 hover:border-[var(--color-accent)]/40 transition-colors">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex-1">
                                                <p className="text-xs text-[var(--color-text-secondary)] mb-1">
                                                    {new Date(ev.event_date).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' })}
                                                </p>
                                                <h3 className="text-sm font-semibold text-white">{ev.title}</h3>
                                                {ev.place_name && (
                                                    <p className="flex items-center gap-1 text-xs text-[var(--color-text-secondary)] mt-1">
                                                        <MapPin size={10} /> {ev.place_name}
                                                    </p>
                                                )}
                                                {ev.story && <p className="text-xs text-[var(--color-text-secondary)] mt-2 leading-relaxed">{ev.story}</p>}
                                                {ev.song_link && (
                                                    <a href={ev.song_link} target="_blank" rel="noopener noreferrer"
                                                        className="flex items-center gap-1 text-xs text-[var(--color-accent)] mt-2 hover:underline">
                                                        <Music size={10} /> Písička
                                                    </a>
                                                )}
                                            </div>
                                            <button onClick={() => del(ev.id)} className="p-1.5 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors shrink-0">
                                                <Trash2 size={13} />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
