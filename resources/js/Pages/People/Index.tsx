import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Film, Image, User } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Person {
    id: number;
    name: string;
    photo_count?: number;
    video_count?: number;
    latest_thumb?: string;
}

export default function PeopleIndex() {
    const [people, setPeople] = useState<Person[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        axios.get('/api/v1/people').then(r => {
            setPeople(r.data.data ?? r.data ?? []);
        }).finally(() => setLoading(false));
    }, []);

    return (
        <AppLayout>
            <Head title="Lidé" />
            <div className="p-4">
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-9 h-9 rounded-lg bg-[var(--color-accent)]/20 flex items-center justify-center">
                        <User size={18} className="text-[var(--color-accent)]" />
                    </div>
                    <div>
                        <h1 className="text-lg font-semibold text-white">Lidé</h1>
                        <p className="text-xs text-[var(--color-text-secondary)]">{people.length} osob</p>
                    </div>
                </div>

                {loading ? (
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                        {Array.from({length:8}).map((_,i) => (
                            <div key={i} className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 animate-pulse h-24" />
                        ))}
                    </div>
                ) : people.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-48 text-[var(--color-text-secondary)]">
                        <User size={40} className="mb-3 opacity-30" />
                        <p>Žádné osoby</p>
                        <p className="text-sm mt-1">Přiřaďte osoby k fotografiím v detailu fotografie</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                        {people.map(person => (
                            <Link key={person.id} href={`/search?person_id=${person.id}`}
                                className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-4 hover:border-[var(--color-accent)]/50 transition-colors text-center">
                                <div className="w-14 h-14 rounded-full bg-[var(--color-accent)]/20 flex items-center justify-center mx-auto mb-3">
                                    {person.latest_thumb
                                        ? <img src={person.latest_thumb} alt="" className="w-full h-full rounded-full object-cover" />
                                        : <span className="text-xl font-bold text-[var(--color-accent)]">{person.name[0]?.toUpperCase()}</span>
                                    }
                                </div>
                                <p className="text-sm font-medium text-white truncate">{person.name}</p>
                                <div className="flex items-center justify-center gap-3 mt-1 text-[10px] text-[var(--color-text-secondary)]">
                                    {person.photo_count ? <span className="flex items-center gap-0.5"><Image size={10}/>{person.photo_count}</span> : null}
                                    {person.video_count ? <span className="flex items-center gap-0.5"><Film size={10}/>{person.video_count}</span> : null}
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
