import AnniversaryRecapPanel from '@/Components/AnniversaryRecapPanel';
import AppLayout from '@/Layouts/AppLayout';
import usePrimaryGallerySpace from '@/hooks/usePrimaryGallerySpace';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Images } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function AnniversaryAlbumIndex() {
    const { spaceId, loading: spaceLoading, error: spaceError, reload } = usePrimaryGallerySpace();
    const [startedOn, setStartedOn] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!spaceId) return;
        setLoading(true); setError('');
        axios.get('/api/v1/relationship-milestones/relationship-anniversary', { params: { gallery_space_id: spaceId } })
            .then(response => setStartedOn(response.data?.started_on ?? ''))
            .catch((reason: any) => setError(reason?.response?.data?.message ?? 'Nastavení vztahu se nepodařilo načíst.'))
            .finally(() => setLoading(false));
    }, [spaceId]);

    return <AppLayout><Head title="Výroční album"/><main className="mx-auto max-w-6xl p-4 sm:p-6">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><p className="text-xs font-medium uppercase tracking-wider text-pink-200">Fotografie, videa a společná vzpomínka</p><h1 className="mt-1 flex items-center gap-2 text-xl font-semibold text-white"><Images size={22} className="text-pink-300"/>Vytvořit výroční album</h1><p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Automatický návrh výběru za konkrétní rok vztahu můžete upravit, zvolit titulní snímek a jedním krokem propojit s příběhem i vzpomínkou.</p></div><Link href="/gifts-anniversaries" className="text-sm text-pink-200">Nastavení výročí →</Link></div>
        {(spaceLoading || loading) && <div className="mt-5 h-44 animate-pulse rounded-2xl bg-white/5"/>}
        {(spaceError || error) && <div className="mt-5 rounded-xl bg-red-500/10 p-4 text-sm text-red-200">{spaceError || error} <button type="button" onClick={reload} className="ml-2 underline">Načíst znovu</button></div>}
        {!spaceLoading && !loading && spaceId && !startedOn && <div className="mt-5 rounded-2xl border border-dashed border-pink-300/25 bg-pink-500/5 p-6 text-center"><p className="text-white">Nejprve nastavte datum začátku vztahu.</p><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Podle něj určíme období pro každý společný rok.</p><Link href="/gifts-anniversaries" className="mt-4 inline-flex min-h-10 items-center rounded-lg bg-pink-600 px-4 text-sm text-white">Nastavit výročí</Link></div>}
        {spaceId && startedOn && <section className="mt-5 rounded-2xl border border-pink-500/25 bg-gradient-to-br from-pink-500/10 to-[var(--color-bg-card)] p-4 sm:p-5"><AnniversaryRecapPanel spaceId={spaceId} startedOn={startedOn}/></section>}
    </main></AppLayout>;
}
