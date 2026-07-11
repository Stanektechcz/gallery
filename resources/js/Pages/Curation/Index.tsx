import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useEffect, useState } from 'react';
import { Check, Plus, Trash2, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';

type Votes = { selected: number; not_selected: number; my_vote: boolean | null };
type BoardItem = { id: number; media_uuid: string; original_filename: string; display_title?: string | null; media_type: string; taken_at?: string | null; status: string; note?: string | null; votes: Votes };
type Board = { uuid: string; title: string; description?: string | null; items_count: number; status_counts: Record<string, number>; items?: BoardItem[] };

const statuses = [['pending', 'K projití'], ['shortlisted', 'Užší výběr'], ['selected', 'Vybráno'], ['rejected', 'Nevybírat']] as const;

export default function CurationIndex() {
    const [boards, setBoards] = useState<Board[]>([]);
    const [active, setActive] = useState<Board | null>(null);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [mediaUuid, setMediaUuid] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);

    const load = async (requestedUuid?: string) => {
        const { data } = await axios.get<Board[]>('/api/v1/curation-boards');
        setBoards(data);
        const uuid = requestedUuid ?? active?.uuid ?? data[0]?.uuid;
        setActive(uuid ? (await axios.get<Board>(`/api/v1/curation-boards/${uuid}`)).data : null);
    };

    useEffect(() => { load().catch(() => setError('Výběry se nepodařilo načíst.')).finally(() => setLoading(false)); }, []);

    const createBoard = async (event: FormEvent) => {
        event.preventDefault();
        try {
            const { data } = await axios.post<Board>('/api/v1/curation-boards', { title, description, visibility: 'shared' });
            setTitle(''); setDescription(''); await load(data.uuid);
        } catch (e: any) { setError(e?.response?.data?.message ?? 'Výběr se nepodařilo vytvořit.'); }
    };
    const addItems = async (event: FormEvent) => {
        event.preventDefault(); if (!active || !mediaUuid.trim()) return;
        try { await axios.post(`/api/v1/curation-boards/${active.uuid}/items`, { media_uuids: mediaUuid.split(/[\s,]+/).filter(Boolean) }); setMediaUuid(''); await load(active.uuid); }
        catch (e: any) { setError(e?.response?.data?.message ?? 'Fotografii se nepodařilo přidat.'); }
    };
    const updateItem = async (item: BoardItem, patch: Record<string, unknown>) => { if (active) { await axios.patch(`/api/v1/curation-boards/${active.uuid}/items/${item.id}`, patch); await load(active.uuid); } };
    const removeItem = async (item: BoardItem) => { if (active && confirm(`Odebrat „${item.display_title || item.original_filename}“?`)) { await axios.delete(`/api/v1/curation-boards/${active.uuid}/items/${item.id}`); await load(active.uuid); } };
    const vote = async (item: BoardItem, isSelected: boolean) => { if (active) { await axios.put(`/api/v1/curation-boards/${active.uuid}/items/${item.id}/vote`, { is_selected: isSelected }); await load(active.uuid); } };

    return <AppLayout><Head title="Společné výběry" />
        <main className="mx-auto max-w-6xl p-4 sm:p-6">
            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-sm text-[var(--color-text-secondary)]">Galerie · společné rozhodování</p><h1 className="text-2xl font-bold text-white">Společné výběry</h1><p className="mt-1 max-w-2xl text-sm text-[var(--color-text-secondary)]">Připravte fotoknihu, příběh nebo malou kolekci. Každý může hlasovat, psát poznámky a označit finální záběry.</p></div><Link href="/print" className="min-h-10 rounded-xl border border-[var(--color-border)] px-4 py-2 text-center text-sm text-white">Otevřít fotoknihu</Link></div>
            {error && <p className="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}
            <div className="grid gap-5 lg:grid-cols-[280px_1fr]"><aside className="space-y-3"><form onSubmit={createBoard} className="space-y-2 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3"><label className="text-xs font-medium text-white">Nový společný výběr</label><input required value={title} onChange={e => setTitle(e.target.value)} placeholder="Např. Fotokniha 2026" className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 py-2 text-sm text-white outline-none"/><textarea value={description} onChange={e => setDescription(e.target.value)} placeholder="Volitelná poznámka" rows={2} className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 py-2 text-sm text-white outline-none"/><button className="flex min-h-10 w-full items-center justify-center gap-2 rounded-lg bg-[var(--color-accent)] text-sm font-medium text-white"><Plus size={15}/>Vytvořit výběr</button></form><div className="space-y-2">{boards.map(board => <button key={board.uuid} onClick={() => load(board.uuid)} className={`w-full rounded-xl border p-3 text-left ${active?.uuid === board.uuid ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}><p className="truncate text-sm font-medium text-white">{board.title}</p><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{board.items_count} fotek · {board.status_counts?.selected ?? 0} vybráno</p></button>)}</div></aside>
                <section className="min-w-0 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:p-5">{loading ? <p className="text-sm text-[var(--color-text-secondary)]">Načítám výběry…</p> : !active ? <p className="text-sm text-[var(--color-text-secondary)]">Vytvořte první výběr. Poté lze přidat fotografie z detailu nebo vložením jejich UUID.</p> : <><div className="mb-4"><h2 className="text-lg font-semibold text-white">{active.title}</h2>{active.description && <p className="mt-1 text-sm text-[var(--color-text-secondary)]">{active.description}</p>}</div><form onSubmit={addItems} className="mb-5 flex flex-col gap-2 sm:flex-row"><input value={mediaUuid} onChange={e => setMediaUuid(e.target.value)} placeholder="UUID fotografie (lze vložit více, oddělené čárkou)" className="min-h-10 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 text-sm text-white outline-none"/><button className="min-h-10 rounded-lg bg-[var(--color-accent)] px-4 text-sm text-white">Přidat</button></form><div className="space-y-3">{active.items?.map(item => <article key={item.id} className="rounded-xl border border-[var(--color-border)] p-3"><div className="flex flex-col gap-3 sm:flex-row sm:items-center"><Link href={`/media/${item.media_uuid}`} className="min-w-0 flex-1"><p className="truncate text-sm font-medium text-white">{item.display_title || item.original_filename}</p><p className="text-xs text-[var(--color-text-secondary)]">{item.media_type === 'video' ? 'Video' : 'Fotografie'}{item.taken_at ? ` · ${new Date(item.taken_at).toLocaleDateString('cs-CZ')}` : ''}</p></Link><select value={item.status} onChange={e => updateItem(item, { status: e.target.value })} className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white">{statuses.map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select><button onClick={() => removeItem(item)} className="min-h-9 rounded-lg p-2 text-red-300 hover:bg-red-500/10" title="Odebrat"><Trash2 size={15}/></button></div><div className="mt-3 flex flex-wrap items-center gap-2"><button onClick={() => vote(item, true)} className={`min-h-9 rounded-lg px-3 text-xs ${item.votes.my_vote === true ? 'bg-green-500/20 text-green-300' : 'bg-white/5 text-[var(--color-text-secondary)]'}`}><Check className="mr-1 inline" size={13}/>Ano ({item.votes.selected})</button><button onClick={() => vote(item, false)} className={`min-h-9 rounded-lg px-3 text-xs ${item.votes.my_vote === false ? 'bg-red-500/15 text-red-200' : 'bg-white/5 text-[var(--color-text-secondary)]'}`}><X className="mr-1 inline" size={13}/>Ne ({item.votes.not_selected})</button><input defaultValue={item.note || ''} onBlur={e => e.target.value !== (item.note || '') && updateItem(item, { note: e.target.value })} placeholder="Společná poznámka…" className="min-h-9 min-w-40 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white outline-none"/></div></article>)}{active.items?.length === 0 && <p className="rounded-xl border border-dashed border-[var(--color-border)] p-6 text-center text-sm text-[var(--color-text-secondary)]">Výběr je zatím prázdný.</p>}</div></>}</section>
            </div>
        </main>
    </AppLayout>;
}
