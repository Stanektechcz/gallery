import AppLayout from '@/Layouts/AppLayout';
import DeleteButton from '@/Components/DeleteButton';
import { hlaska } from '@/Components/Hlasky';
import { pocet } from '@/lib/cestina';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Check, FolderTree, Link2, LoaderCircle, Pencil, Plus, Tag, X } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface TagItem { id: number; name: string; slug: string; color?: string; depth: number; parent_id?: number | null; media_count?: number; albums_count?: number; connections_count?: number; children?: TagItem[] }
interface Connection { entity_type: string; label: string; items: Array<{ id: number; title: string; url: string }> }

function TagRow({ tag, level = 0, onOpen, onChanged }: {
    tag: TagItem; level?: number; onOpen: (tag: TagItem) => void; onChanged: () => void;
}) {
    const [prejmenovava, setPrejmenovava] = useState(false);
    const [nazev, setNazev] = useState(tag.name);
    const [uklada, setUklada] = useState(false);

    const pouziti = tag.connections_count ?? ((tag.media_count ?? 0) + (tag.albums_count ?? 0));

    const uloz = async () => {
        const novy = nazev.trim();

        if (! novy || novy === tag.name) { setPrejmenovava(false); return; }

        setUklada(true);

        try {
            await axios.put(`/api/v1/tags/${tag.id}`, { name: novy });
            setPrejmenovava(false);
            hlaska(`Štítek se teď jmenuje „${novy}".`, 'uspech');
            onChanged();
        } catch (problem: any) {
            // Server hlídá, že se dva štítky nejmenují stejně. Tuhle hlášku má smysl
            // ukázat doslova, protože říká přesně, co je špatně.
            hlaska(problem?.response?.data?.message ?? 'Štítek se nepodařilo přejmenovat.', 'chyba');
        } finally {
            setUklada(false);
        }
    };

    // Potomci patří pod řádek, ne do něj. Dřív se vykreslovali uvnitř téhož
    // `flex items-center`, takže vnořený štítek skončil vedle rodiče na jedné řádce —
    // odsazení podle úrovně se počítalo, ale nebylo ho na čem vidět.
    return <div>
        <div className="flex items-center gap-2 rounded-xl px-2 py-1.5 hover:bg-[var(--color-surface-hover)]">
            {prejmenovava ? (
                <div className="flex min-w-0 flex-1 items-center gap-2" style={{ marginLeft: level * 16 }}>
                    <input value={nazev} onChange={e => setNazev(e.target.value)} autoFocus
                        onKeyDown={e => { if (e.key === 'Enter') void uloz(); if (e.key === 'Escape') { setNazev(tag.name); setPrejmenovava(false); } }}
                        aria-label={`Název štítku ${tag.name}`}
                        className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-accent)]/40 bg-[var(--color-bg-primary)] px-2.5 text-sm text-[var(--color-text-primary)] focus:outline-none"/>
                    <button type="button" onClick={() => void uloz()} disabled={uklada}
                        className="inline-flex min-h-9 shrink-0 items-center gap-1 rounded-lg bg-[var(--color-accent)] px-2.5 text-[11px] font-medium text-[var(--color-accent-contrast)] disabled:opacity-40">
                        <Check size={13}/>Uložit
                    </button>
                    <button type="button" onClick={() => { setNazev(tag.name); setPrejmenovava(false); }}
                        className="min-h-9 shrink-0 rounded-lg px-2 text-[11px] text-[var(--color-text-secondary)]">Zpět</button>
                </div>
            ) : (
                <>
                    <Link href={`/search?tag_ids=${tag.id}`} className="flex min-w-0 flex-1 items-center gap-2 py-1.5" style={{ marginLeft: level * 16 }}>
                        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: tag.color || 'var(--color-accent)' }} />
                        <span className="truncate text-sm text-[var(--color-text-primary)]">{tag.name}</span>
                    </Link>
                    <button type="button" onClick={() => onOpen(tag)} className="inline-flex min-h-9 shrink-0 items-center gap-1 rounded-lg border border-[var(--color-border)] px-2 text-[10px] text-[var(--color-text-secondary)] hover:border-[var(--color-accent)] hover:text-[var(--color-text-primary)]" title="Zobrazit vazby štítku">
                        <Link2 size={12} />{pouziti}
                    </button>
                    <button type="button" onClick={() => setPrejmenovava(true)} aria-label={`Přejmenovat štítek ${tag.name}`}
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-muted)] hover:text-[var(--color-text-primary)]">
                        <Pencil size={13}/>
                    </button>
                    {/* Smazání štítek odebere ze všeho, k čemu je připnutý — vazby jsou
                        v databázi kaskádové. Kolika věcí se to týká, stojí rovnou v popisku,
                        protože po potvrzení už to nikdo nezjistí. */}
                    <DeleteButton
                        label={pouziti > 0 ? `Smazat štítek ${tag.name} — odepne se z ${pocet(pouziti, 'jedné položky', 'položek', 'položek')}` : `Smazat štítek ${tag.name}`}
                        onDelete={async () => { await axios.delete(`/api/v1/tags/${tag.id}`); hlaska(`Štítek „${tag.name}" je smazaný.`, 'uspech'); onChanged(); }}/>
                </>
            )}
        </div>
        {tag.children?.map(child => <TagRow key={child.id} tag={child} level={level + 1} onOpen={onOpen} onChanged={onChanged} />)}
    </div>;
}

export default function TagsIndex() {
    const [tags, setTags] = useState<TagItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [name, setName] = useState('');
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const [active, setActive] = useState<TagItem | null>(null);
    const [connections, setConnections] = useState<Connection[]>([]);
    const [loadingConnections, setLoadingConnections] = useState(false);

    const load = async () => {
        setLoading(true);
        try { const response = await axios.get('/api/v1/tags'); setTags(response.data.data ?? response.data ?? []); }
        finally { setLoading(false); }
    };
    useEffect(() => { void load(); }, []);

    const create = async (event: FormEvent) => {
        event.preventDefault();
        if (!name.trim() || saving) return;
        setSaving(true); setMessage('');
        try { await axios.post('/api/v1/tags', { name: name.trim() }); setName(''); setMessage('Štítek je připravený pro celý společný systém.'); await load(); }
        catch (error: any) { setMessage(error.response?.data?.message ?? 'Štítek se nepodařilo vytvořit.'); }
        finally { setSaving(false); }
    };
    const open = async (tag: TagItem) => {
        setActive(tag); setConnections([]); setLoadingConnections(true);
        try { const response = await axios.get(`/api/v1/tags/${tag.id}/connections`); setConnections(response.data.connections ?? []); }
        finally { setLoadingConnections(false); }
    };
    const map: Record<number, TagItem> = {};
    tags.forEach(tag => { map[tag.id] = { ...tag, children: [] }; });
    const roots: TagItem[] = [];
    tags.forEach(tag => { if (tag.parent_id && map[tag.parent_id]) map[tag.parent_id].children?.push(map[tag.id]); else roots.push(map[tag.id]); });

    return <AppLayout>
        <Head title="Společné štítky" />
        <main className="min-h-full px-3 py-4 pb-24 sm:px-6 sm:py-7">
            <header className="w-full">
                <div className="flex items-center gap-2 text-[var(--color-accent)]"><FolderTree size={16}/><span className="text-xs font-semibold uppercase tracking-wider">Společný kontext</span></div>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)]">Štítky napříč systémem</h1>
                <p className="mt-1 max-w-2xl text-sm text-[var(--color-text-secondary)]">Jeden štítek může spojit fotky, kalendář, cesty, recepty, filmy, úkoly i finance. V chatu jej přidáte jako <code>#léto2026</code>.</p>
                <form onSubmit={create} className="mt-5 flex max-w-xl gap-2">
                    <label className="sr-only" htmlFor="new-tag">Nový štítek</label><input id="new-tag" value={name} onChange={event => setName(event.target.value)} maxLength={100} placeholder="Např. léto 2026" className="min-h-11 min-w-0 flex-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] px-3 text-sm text-[var(--color-text-primary)] outline-none focus:border-[var(--color-accent)]" />
                    <button disabled={saving || !name.trim()} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-semibold text-[var(--color-accent-contrast)] disabled:opacity-40"><Plus size={16}/>{saving ? 'Vytvářím…' : 'Přidat štítek'}</button>
                </form>
                {message && <p className="mt-2 text-xs text-[var(--color-text-secondary)]">{message}</p>}
            </header>
            <div className="mx-auto mt-6 grid max-w-6xl gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(340px,1.2fr)]">
                <section className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3">
                    <div className="mb-2 flex items-center justify-between px-2"><h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Vaše štítky</h2><span className="text-xs text-[var(--color-text-secondary)]">{tags.length}</span></div>
                    {loading ? <div className="flex min-h-40 items-center justify-center text-[var(--color-text-secondary)]"><LoaderCircle size={18} className="animate-spin"/></div> : roots.length ? roots.map(tag => <TagRow key={tag.id} tag={tag} onOpen={open} onChanged={load}/>) : <div className="px-4 py-12 text-center"><Tag size={34} className="mx-auto text-[var(--color-text-primary)]/20"/><p className="mt-3 text-sm text-[var(--color-text-primary)]">Ještě nemáte žádný štítek.</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Vytvořte jej zde nebo napište do chatu třeba <code>#společně</code>.</p></div>}
                </section>
                <section className="min-h-52 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                    {!active ? <div className="flex min-h-44 flex-col items-center justify-center text-center"><Link2 size={30} className="text-[var(--color-text-primary)]/20"/><h2 className="mt-3 text-sm font-semibold text-[var(--color-text-primary)]">Vyberte štítek</h2><p className="mt-1 max-w-sm text-xs text-[var(--color-text-secondary)]">Uvidíte vše, co s ním souvisí, i mimo galerii fotografií.</p></div> : <><div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="text-xs text-[var(--color-text-secondary)]">Vazby štítku</p><h2 className="mt-1 flex items-center gap-2 text-lg font-semibold text-[var(--color-text-primary)]"><span className="h-3 w-3 rounded-full" style={{ backgroundColor: active.color || 'var(--color-accent)' }}/>{active.name}</h2></div><button type="button" onClick={() => setActive(null)} aria-label="Zavřít vazby" className="grid h-9 w-9 place-items-center rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"><X size={17}/></button></div>{loadingConnections ? <div className="flex min-h-36 items-center justify-center"><LoaderCircle size={18} className="animate-spin text-[var(--color-text-secondary)]"/></div> : connections.length ? <div className="mt-4 space-y-4">{connections.map(group => <div key={group.entity_type}><h3 className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">{group.label}</h3><div className="mt-2 flex flex-wrap gap-2">{group.items.map(item => <Link key={`${group.entity_type}-${item.id}`} href={item.url} className="max-w-full truncate rounded-lg border border-[var(--color-border)] px-3 py-2 text-xs text-[var(--color-text-primary)] hover:border-[var(--color-accent)]">{item.title}</Link>)}</div></div>)}</div> : <p className="mt-6 rounded-xl border border-dashed border-[var(--color-border)] p-4 text-center text-xs text-[var(--color-text-secondary)]">Tento štítek zatím nemá žádné vazby. Přidejte ho k zápisu v chatu například jako <code>#{active.slug}</code>.</p>}</>}
                </section>
            </div>
        </main>
    </AppLayout>;
}