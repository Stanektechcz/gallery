import EntertainmentWorkspace from '@/Components/Entertainment/EntertainmentWorkspace';
import AppLayout from '@/Layouts/AppLayout';
import usePrimaryGallerySpace from '@/hooks/usePrimaryGallerySpace';
import { Head, Link, usePage } from '@inertiajs/react';
import { Clapperboard } from 'lucide-react';

export default function WatchlistIndex() {
    const { url } = usePage();
    const { spaceId, loading, error, reload } = usePrimaryGallerySpace();
    const isSeries = url.includes('/series');
    const isTierlist = url.includes('/tierlist');
    const title = isTierlist ? (isSeries ? 'Tierlist seriálů' : 'Tierlist filmů') : (isSeries ? 'Seriály' : 'Filmy');

    return <AppLayout><Head title={title}/><main className="w-full p-4 sm:p-6 lg:p-8">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><p className="text-xs font-medium uppercase tracking-wider text-violet-200">Filmy, seriály a kino</p><h1 className="mt-1 flex items-center gap-2 text-xl font-semibold text-white"><Clapperboard size={22} className="text-violet-300"/>{title}</h1><p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Každý z vás může samostatně zapsat zhlédnutí i vlastní hodnocení; přehled zobrazuje oba názory vedle sebe.</p></div><Link href="/calendar" className="text-sm text-violet-200">Kalendář a akce →</Link></div>
        <nav aria-label="Sekce filmů a seriálů" className="mt-5 flex flex-wrap gap-2 rounded-2xl border border-violet-400/20 bg-violet-500/5 p-2 text-sm"><Link href="/watchlist/movies" className={`rounded-xl px-3 py-2 ${!isSeries && !isTierlist ? 'bg-violet-500 text-white' : 'text-violet-100 hover:bg-white/5'}`}>Filmy</Link><Link href="/watchlist/series" className={`rounded-xl px-3 py-2 ${isSeries && !isTierlist ? 'bg-violet-500 text-white' : 'text-violet-100 hover:bg-white/5'}`}>Seriály</Link><Link href="/watchlist/movies/tierlist" className={`rounded-xl px-3 py-2 ${!isSeries && isTierlist ? 'bg-violet-500 text-white' : 'text-violet-100 hover:bg-white/5'}`}>Tierlist filmů</Link><Link href="/watchlist/series/tierlist" className={`rounded-xl px-3 py-2 ${isSeries && isTierlist ? 'bg-violet-500 text-white' : 'text-violet-100 hover:bg-white/5'}`}>Tierlist seriálů</Link></nav>
        {loading && <div className="mt-5 h-44 animate-pulse rounded-2xl bg-white/5"/>}
        {error && <div className="mt-5 rounded-xl bg-red-500/10 p-4 text-sm text-red-200">{error} <button type="button" onClick={reload} className="ml-2 underline">Načíst znovu</button></div>}
        {spaceId && <div className="mt-5"><EntertainmentWorkspace spaceId={spaceId} initialType={isSeries ? 'series' : 'movie'} tierlist={isTierlist}/></div>}
    </main></AppLayout>;
}
