import axios from 'axios';
import { Gift, Plus } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

type Member = { id: number; name: string };
type GiftIdea = { uuid: string; title: string; due_date?: string | null; assigned_to?: number | null };

export default function GiftAutomation({ spaceId }: { spaceId?: number }) {
    const [gifts, setGifts] = useState<GiftIdea[]>([]);
    const [members, setMembers] = useState<Member[]>([]);
    const [title, setTitle] = useState('');
    const [dueDate, setDueDate] = useState('');
    const [assignedTo, setAssignedTo] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const load = async () => {
        if (!spaceId) return;
        const [giftResponse, pulseResponse] = await Promise.allSettled([
            axios.get('/api/v1/calendar/gifts'),
            axios.get('/api/v1/coordination/pulse', { params: { gallery_space_id: spaceId, limit: 1 } }),
        ]);
        if (giftResponse.status === 'fulfilled') setGifts(giftResponse.value.data ?? []);
        if (pulseResponse.status === 'fulfilled') setMembers(pulseResponse.value.data?.members ?? []);
        if (giftResponse.status === 'rejected') setError('Nápady na dárky se nepodařilo načíst.');
    };

    useEffect(() => { void load(); }, [spaceId]);

    const addGift = async (event: FormEvent) => {
        event.preventDefault();
        if (!spaceId || !title.trim()) return;
        setBusy(true); setError('');
        try {
            await axios.post('/api/v1/calendar/gifts', {
                gallery_space_id: spaceId,
                title: title.trim(),
                due_date: dueDate || null,
                assigned_to: assignedTo ? Number(assignedTo) : null,
            });
            setTitle(''); setDueDate(''); setAssignedTo('');
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Nápad na dárek se nepodařilo uložit.');
        } finally { setBusy(false); }
    };

    const assignGift = async (uuid: string, userId: string) => {
        try {
            await axios.patch(`/api/v1/calendar/gifts/${uuid}`, { assigned_to: userId ? Number(userId) : null });
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Odpovědnost se nepodařilo změnit.');
        }
    };

    return <section className="rounded-2xl border border-amber-400/20 bg-[var(--color-bg-card)] p-4 sm:p-5">
        <h2 className="flex items-center gap-2 font-semibold text-white"><Gift size={18} className="text-amber-300"/>Nápady na dárky</h2>
        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Termín se připomene 30, 7 a 1 den předem. Odpovědnost můžete rozdělit mezi oba členy společného prostoru.</p>
        {error && <p className="mt-3 rounded-lg bg-red-500/10 p-2 text-xs text-red-200">{error}</p>}
        <form onSubmit={addGift} className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            <input required value={title} onChange={event => setTitle(event.target.value)} placeholder="Nápad na dárek" className="min-h-10 min-w-0 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white xl:col-span-2"/>
            <input aria-label="Termín dárku" type="date" value={dueDate} onChange={event => setDueDate(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-black/10 px-3 text-sm text-white"/>
            <select aria-label="Kdo dárek zařídí" value={assignedTo} onChange={event => setAssignedTo(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-sm text-white"><option value="">Domluvit později</option>{members.map(member => <option key={member.id} value={member.id}>{member.name}</option>)}</select>
            <button disabled={busy} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-amber-600 px-3 text-sm text-white disabled:opacity-50 sm:col-span-2 xl:col-span-4"><Plus size={15}/>{busy ? 'Ukládám…' : 'Přidat nápad'}</button>
        </form>
        <div className="mt-4 grid gap-2 md:grid-cols-2">{gifts.map(gift => <div key={gift.uuid} className="grid gap-2 rounded-xl border border-[var(--color-border)] bg-black/10 p-3 text-sm sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"><div className="min-w-0"><p className="truncate text-white">{gift.title}</p><p className="text-xs text-[var(--color-text-secondary)]">{gift.due_date ? new Date(`${gift.due_date}T12:00:00`).toLocaleDateString('cs-CZ') : 'bez data'}</p></div><select aria-label={`Kdo zařídí ${gift.title}`} value={gift.assigned_to ?? ''} onChange={event => assignGift(gift.uuid, event.target.value)} className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"><option value="">Kdo zařídí?</option>{members.map(member => <option key={member.id} value={member.id}>{member.name}</option>)}</select></div>)}{!gifts.length && <p className="rounded-xl border border-dashed border-[var(--color-border)] p-5 text-center text-sm text-[var(--color-text-secondary)] md:col-span-2">Zatím tu není žádný nápad na dárek.</p>}</div>
    </section>;
}
