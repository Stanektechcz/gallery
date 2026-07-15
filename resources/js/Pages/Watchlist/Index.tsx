import EntertainmentWorkspace from '@/Components/Entertainment/EntertainmentWorkspace';
import AppLayout from '@/Layouts/AppLayout';
import usePrimaryGallerySpace from '@/hooks/usePrimaryGallerySpace';
import { Head, Link } from '@inertiajs/react';
import { Clapperboard } from 'lucide-react';

export default function WatchlistIndex() {
    const { spaceId, loading, error, reload } = usePrimaryGallerySpace();

    return <AppLayout><Head title="Watchlist"/><main className="mx-auto max-w-6xl p-4 sm:p-6">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><p className="text-xs font-medium uppercase tracking-wider text-violet-200">Filmy, seriály a kino</p><h1 className="mt-1 flex items-center gap-2 text-xl font-semibold text-white"><Clapperboard size={22} className="text-violet-300"/>Náš watchlist</h1><p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Vyhledávání titulů, společné hlasování, kino, návrhy termínů i zápis zhlédnutí na jednom místě.</p></div><Link href="/calendar" className="text-sm text-violet-200">Kalendář a akce →</Link></div>
        {loading && <div className="mt-5 h-44 animate-pulse rounded-2xl bg-white/5"/>}
        {error && <div className="mt-5 rounded-xl bg-red-500/10 p-4 text-sm text-red-200">{error} <button type="button" onClick={reload} className="ml-2 underline">Načíst znovu</button></div>}
        {spaceId && <div className="mt-5"><EntertainmentWorkspace spaceId={spaceId}/></div>}
    </main></AppLayout>;
}
