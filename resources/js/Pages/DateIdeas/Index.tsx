import DateIdeaGenerator from '@/Components/DateIdeaGenerator';
import AppLayout from '@/Layouts/AppLayout';
import usePrimaryGallerySpace from '@/hooks/usePrimaryGallerySpace';
import { Head, Link } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';

export default function DateIdeasIndex() {
    const { spaceId, loading, error, reload } = usePrimaryGallerySpace();

    return <AppLayout><Head title="Randíčka"/><main className="mx-auto max-w-6xl p-4 sm:p-6">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><p className="text-xs font-medium uppercase tracking-wider text-pink-200">Nápady jen pro vás dva</p><h1 className="mt-1 flex items-center gap-2 text-xl font-semibold text-white"><Sparkles size={22} className="text-pink-300"/>Generátor randíček</h1><p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Unikátní program podle rozpočtu, času, dopravy, počasí, míst a vašich předchozích reakcí.</p></div><Link href="/calendar" className="text-sm text-pink-200">Společný kalendář →</Link></div>
        {loading && <div className="mt-5 h-44 animate-pulse rounded-3xl bg-white/5"/>}
        {error && <div className="mt-5 rounded-xl bg-red-500/10 p-4 text-sm text-red-200">{error} <button type="button" onClick={reload} className="ml-2 underline">Načíst znovu</button></div>}
        {spaceId && <DateIdeaGenerator spaceId={spaceId}/>} 
    </main></AppLayout>;
}
