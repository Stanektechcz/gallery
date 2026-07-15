import GiftAutomation from '@/Components/GiftAutomation';
import RelationshipAnniversarySettings from '@/Components/RelationshipAnniversarySettings';
import AppLayout from '@/Layouts/AppLayout';
import usePrimaryGallerySpace from '@/hooks/usePrimaryGallerySpace';
import { Head, Link } from '@inertiajs/react';
import { CalendarHeart } from 'lucide-react';

export default function GiftsAnniversariesIndex() {
    const { spaceId, loading, error, reload } = usePrimaryGallerySpace();

    return <AppLayout><Head title="Dárky a výročí"/><main className="mx-auto max-w-6xl p-4 sm:p-6">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><p className="text-xs font-medium uppercase tracking-wider text-pink-200">Důležité společné okamžiky</p><h1 className="mt-1 flex items-center gap-2 text-xl font-semibold text-white"><CalendarHeart size={22} className="text-pink-300"/>Dárky a výročí</h1><p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Začátek vztahu, automatická měsíční i roční výročí, odpočet a připomínané nápady na dárky.</p></div><div className="flex flex-wrap gap-3 text-sm"><Link href="/milestones" className="text-pink-200">Narozeniny a svátky →</Link><Link href="/anniversary-album" className="text-pink-200">Výroční album →</Link></div></div>
        {loading && <div className="mt-5 h-44 animate-pulse rounded-2xl bg-white/5"/>}
        {error && <div className="mt-5 rounded-xl bg-red-500/10 p-4 text-sm text-red-200">{error} <button type="button" onClick={reload} className="ml-2 underline">Načíst znovu</button></div>}
        {spaceId && <div className="mt-5 space-y-5"><RelationshipAnniversarySettings spaceId={spaceId} showRecap={false}/><GiftAutomation spaceId={spaceId}/></div>}
    </main></AppLayout>;
}
