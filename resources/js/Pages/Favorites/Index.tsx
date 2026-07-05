import { MediaCardData, MediaGrid } from '@/Components/MediaGrid';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Heart } from 'lucide-react';
import { useCallback, useState } from 'react';

interface Member { id: number; name: string; is_me: boolean; }

interface Props {
    my_items:      MediaCardData[];
    shared_items:  MediaCardData[];
    partner_items: MediaCardData[];
    members:       Member[];
}

type Tab = 'my' | 'shared' | 'partner';

export default function FavoritesIndex({ my_items, shared_items, partner_items, members }: Props) {
    const [tab,         setTab]        = useState<Tab>('my');
    const [myItems,     setMyItems]    = useState<MediaCardData[]>(my_items);
    const [sharedItems, setSharedItems] = useState<MediaCardData[]>(shared_items);
    const [partItems,   setPartItems]  = useState<MediaCardData[]>(partner_items);
    const [selected,    setSelected]   = useState<Set<string>>(new Set());

    const partner = members.find(m => !m.is_me);
    const me      = members.find(m => m.is_me);

    const currentItems = tab === 'my' ? myItems : tab === 'shared' ? sharedItems : partItems;
    const setCurrentItems = tab === 'my' ? setMyItems : tab === 'shared' ? setSharedItems : setPartItems;

    const toggleSelect = useCallback((uuid: string, sel: boolean) => {
        setSelected(prev => { const n = new Set(prev); sel ? n.add(uuid) : n.delete(uuid); return n; });
    }, []);

    const unfavorite = async (uuid: string) => {
        const res = await axios.post(`/api/v1/favorites/${uuid}/toggle`);
        if (!res.data.is_my_favorite) {
            setMyItems(prev => prev.filter(i => i.uuid !== uuid));
            setSharedItems(prev => prev.filter(i => i.uuid !== uuid));
        }
    };

    const TABS: { key: Tab; label: string; count: number; shared?: boolean }[] = [
        { key: 'my',      label: `❤️ ${me?.name ?? 'Moje'}`,           count: myItems.length },
        { key: 'partner', label: `❤️ ${partner?.name ?? 'Partner'}`,   count: partItems.length },
        { key: 'shared',  label: '❤️❤️ Společné',                       count: sharedItems.length, shared: true },
    ];

    return (
        <AppLayout>
            <Head title="Oblíbené" />
            <div className="flex flex-col h-full min-h-0">

                {/* Header */}
                <div className="shrink-0 px-5 py-3 border-b border-[var(--color-border)]">
                    <div className="flex items-center justify-between gap-4">
                        <h1 className="text-sm font-semibold text-white flex items-center gap-2">
                            <Heart size={16} className="text-red-400 fill-red-400"/> Oblíbené
                        </h1>
                        {/* Tabs */}
                        <div className="flex gap-1">
                            {TABS.map(t => (
                                <button key={t.key} onClick={() => { setTab(t.key); setSelected(new Set()); }}
                                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-colors ${tab === t.key ? 'bg-[var(--color-accent)] text-white' : 'border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}>
                                    {t.label}
                                    <span className={`text-[10px] ${tab === t.key ? 'opacity-80' : 'opacity-60'}`}>({t.count})</span>
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Shared explanation */}
                    {tab === 'shared' && (
                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-1.5">
                            Fotky označené jako oblíbené od obou: {members.map(m => m.name).join(' a ')}
                        </p>
                    )}
                    {tab === 'partner' && partner && (
                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-1.5">
                            Fotky označené pouze {partner.name}
                        </p>
                    )}
                </div>

                {/* Grid */}
                <div className="flex-1 overflow-y-auto p-4">
                    <MediaGrid
                        items={currentItems}
                        selected={tab === 'my' ? selected : new Set()}
                        onSelect={tab === 'my' ? toggleSelect : undefined}
                        getHref={i => `/media/${i.uuid}`}
                        emptyState={
                            <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                                <Heart size={48} className="mb-3 opacity-20"/>
                                <p className="text-sm font-medium text-white mb-1">
                                    {tab === 'my' ? 'Žádné vlastní oblíbené' : tab === 'shared' ? 'Žádná společná oblíbená' : `${partner?.name ?? 'Partner'} zatím nic neoznačil/a`}
                                </p>
                                <p className="text-xs opacity-60">
                                    {tab === 'my' && 'Označte fotky srdíčkem ❤️ v prohlížeči (klávesa F)'}
                                    {tab === 'shared' && 'Když oba označíte stejnou fotku, zobrazí se zde ❤️❤️'}
                                    {tab === 'partner' && 'Partnerova/partnerčina oblíbená se zde zobrazí automaticky'}
                                </p>
                            </div>
                        }
                    />
                </div>
            </div>
        </AppLayout>
    );
}
