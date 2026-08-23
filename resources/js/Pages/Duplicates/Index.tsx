import AppLayout from '@/Layouts/AppLayout';
import { takenAtLabel } from '@/lib/takenAt';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Check, Copy, Heart, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Duplicity.
 *
 * Hledaly se týdně na pozadí a výsledek nebyl nikde vidět, takže si s ním nikdo nemohl
 * nic počít. Tady jsou pohromadě, po skupinách stejných souborů.
 *
 * Ve výchozím stavu je v každé skupině **první kopie ponechaná** a ostatní vybrané ke
 * smazání — to je odpověď, kterou člověk chce v devíti případech z deseti. Přednost
 * dostane kopie, která je v albu nebo je oblíbená; smazat tu zařazenou a nechat volnou
 * by znamenalo rozbít album kvůli úklidu.
 */

interface DuplicateItem {
    id: number;
    uuid: string;
    filename: string;
    taken_at: string | null;
    size_bytes: number;
    thumbnail_url: string | null;
    in_albums: number;
    is_favorite: boolean;
}

interface Group { sha256: string; count: number; items: DuplicateItem[] }

const velikost = (bytes: number): string => {
    if (bytes >= 1_073_741_824) return `${(bytes / 1_073_741_824).toFixed(1)} GB`;
    if (bytes >= 1_048_576) return `${(bytes / 1_048_576).toFixed(1)} MB`;

    return `${Math.round(bytes / 1024)} kB`;
};

/**
 * Kterou kopii ve skupině nechat.
 *
 * Zařazená v albu vyhrává nad volnou, oblíbená nad neoblíbenou a starší nad novější —
 * ta starší je skoro vždycky ta původní, ke které vedou odkazy.
 */
function keepFrom(items: DuplicateItem[]): number {
    return [...items].sort((a, b) => {
        if ((b.in_albums > 0 ? 1 : 0) !== (a.in_albums > 0 ? 1 : 0)) return (b.in_albums > 0 ? 1 : 0) - (a.in_albums > 0 ? 1 : 0);
        if (b.is_favorite !== a.is_favorite) return Number(b.is_favorite) - Number(a.is_favorite);

        return (a.taken_at ?? '').localeCompare(b.taken_at ?? '');
    })[0].id;
}

export default function DuplicatesIndex() {
    const [groups, setGroups] = useState<Group[]>([]);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const response = await axios.get('/api/v1/recovery/duplicates');
            const found: Group[] = response.data.groups ?? [];
            setGroups(found);

            // Předvybráno: v každé skupině zůstane jedna, zbytek jde pryč.
            const navrh = new Set<number>();
            for (const group of found) {
                const keep = keepFrom(group.items);
                group.items.forEach(item => { if (item.id !== keep) navrh.add(item.id); });
            }
            setSelected(navrh);
            setError('');
        } catch (problem: any) {
            setError(problem?.response?.status === 404
                ? 'Nejprve vytvořte nebo přijměte pozvánku do společného prostoru.'
                : 'Duplicity se nepodařilo načíst.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const usetreno = useMemo(
        () => groups.flatMap(g => g.items).filter(i => selected.has(i.id)).reduce((sum, i) => sum + i.size_bytes, 0),
        [groups, selected],
    );

    const toggle = (id: number) => setSelected(current => {
        const next = new Set(current);
        next.has(id) ? next.delete(id) : next.add(id);

        return next;
    });

    const remove = async () => {
        if (! selected.size) return;
        if (! confirm(`Přesunout ${selected.size} kopií do koše? Zůstanou tam 30 dní.`)) return;

        setBusy(true);
        try {
            // Endpoint bere nejvýš 500 najednou, takže po dávkách.
            const ids = Array.from(selected);
            let trashed = 0;

            for (let i = 0; i < ids.length; i += 500) {
                const response = await axios.delete('/api/v1/recovery/duplicates/trash', { data: { media_ids: ids.slice(i, i + 500) } });
                trashed += response.data.trashed ?? 0;
            }

            setDone(`Do koše přesunuto ${trashed} kopií · uvolní ${velikost(usetreno)}`);
            await load();
        } catch {
            setError('Kopie se nepodařilo odstranit.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <AppLayout>
            <Head title="Duplicity" />

            <div className="p-4 sm:p-6">
                <header className="mb-5">
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-[var(--color-text-primary)]">
                        <Copy size={20} className="text-[var(--color-accent)]"/> Duplicity
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm text-[var(--color-text-secondary)]">
                        Soubory se shodným obsahem, seskupené. V každé skupině je jedna kopie ponechaná — přednost
                        dostane ta zařazená v albu nebo oblíbená. Výběr můžete kdykoli změnit.
                    </p>
                </header>

                {loading && <p className="text-sm text-[var(--color-text-secondary)]">Hledám…</p>}
                {error && <p className="text-sm text-red-400">{error}</p>}
                {done && <p className="mb-4 rounded-xl bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200">{done}</p>}

                {! loading && ! error && groups.length === 0 && (
                    <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center">
                        <Check size={26} className="mx-auto text-emerald-300"/>
                        <p className="mt-2 text-sm text-[var(--color-text-primary)]">Žádné duplicity.</p>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Každý soubor v galerii je jedinečný.</p>
                    </div>
                )}

                {groups.length > 0 && (
                    <>
                        {/* Lišta zůstává na očích: kolik toho zmizí a kolik se uvolní. */}
                        <div className="sticky top-2 z-10 mb-4 flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]/95 p-3 backdrop-blur">
                            <span className="text-sm text-[var(--color-text-primary)]">
                                {groups.length} skupin · vybráno {selected.size} kopií
                            </span>
                            <span className="text-xs text-[var(--color-text-secondary)]">uvolní {velikost(usetreno)}</span>

                            <div className="ml-auto flex gap-2">
                                <button type="button" onClick={() => setSelected(new Set())} disabled={! selected.size}
                                    className="rounded-xl border border-[var(--color-border)] px-3 py-2 text-xs text-[var(--color-text-secondary)] disabled:opacity-40">
                                    Zrušit výběr
                                </button>
                                <button type="button" onClick={() => void remove()} disabled={busy || ! selected.size}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-red-500/90 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-40">
                                    <Trash2 size={15}/>{busy ? 'Odstraňuji…' : 'Přesunout do koše'}
                                </button>
                            </div>
                        </div>

                        <div className="space-y-4">
                            {groups.map(group => {
                                const keep = keepFrom(group.items);

                                return (
                                    <section key={group.sha256} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                                        <p className="mb-2 text-xs text-[var(--color-text-secondary)]">
                                            {group.count}× stejný soubor · {velikost(group.items[0]?.size_bytes ?? 0)} každý
                                        </p>

                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                            {group.items.map(item => {
                                                const smazat = selected.has(item.id);

                                                return (
                                                    <div key={item.id}
                                                        className={`overflow-hidden rounded-xl border-2 transition-colors ${
                                                            smazat ? 'border-red-400/70 opacity-60' : 'border-emerald-400/60'
                                                        }`}>
                                                        <button type="button" onClick={() => toggle(item.id)}
                                                            className="relative block aspect-square w-full bg-black/30"
                                                            title={smazat ? 'Ponechat tuhle kopii' : 'Označit ke smazání'}>
                                                            {item.thumbnail_url
                                                                ? <img src={item.thumbnail_url} alt="" loading="lazy" className="h-full w-full object-cover"/>
                                                                : <span className="flex h-full items-center justify-center text-[10px] text-[var(--color-text-secondary)]">bez náhledu</span>}

                                                            <span className={`absolute right-1 top-1 rounded-full px-1.5 py-0.5 text-[9px] font-medium ${
                                                                smazat ? 'bg-red-500 text-white' : 'bg-emerald-500 text-white'
                                                            }`}>
                                                                {smazat ? 'smazat' : 'ponechat'}
                                                            </span>

                                                            {item.id === keep && ! smazat && (
                                                                <span className="absolute left-1 top-1 rounded bg-black/70 px-1.5 py-0.5 text-[9px] text-white">doporučeno</span>
                                                            )}
                                                        </button>

                                                        <div className="space-y-0.5 px-2 py-1.5">
                                                            <Link href={`/media/${item.uuid}`} className="block truncate text-[11px] text-[var(--color-text-primary)] hover:underline">
                                                                {item.filename}
                                                            </Link>
                                                            <p className="flex flex-wrap items-center gap-x-1.5 text-[10px] text-[var(--color-text-secondary)]">
                                                                <span>{takenAtLabel(item.taken_at, { day: 'numeric', month: 'numeric', year: '2-digit' }, 'bez data')}</span>
                                                                {item.in_albums > 0 && <span className="text-emerald-300">{item.in_albums}× v albu</span>}
                                                                {item.is_favorite && <Heart size={9} className="fill-red-400 text-red-400"/>}
                                                            </p>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </section>
                                );
                            })}
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
