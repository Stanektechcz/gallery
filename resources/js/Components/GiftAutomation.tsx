import axios from 'axios';
import { Gift, Lock, Plus } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

type Member = { id: number; name: string };
type GiftStage = 'idea' | 'planned' | 'purchased' | 'wrapped' | 'given';
type GiftIdea = { uuid: string; title: string; due_date?: string | null; assigned_to?: number | null; status?: string; is_private?: boolean; can_toggle_privacy?: boolean; lifecycle?: Array<{ stage: GiftStage; at?: string | null }> };
const GIFT_STAGES: Array<{ key: GiftStage; label: string }> = [{ key: 'idea', label: 'Nápad' }, { key: 'planned', label: 'Vybráno' }, { key: 'purchased', label: 'Koupeno' }, { key: 'wrapped', label: 'Zabaleno' }, { key: 'given', label: 'Předáno' }];

export default function GiftAutomation({ spaceId }: { spaceId?: number }) {
    const [gifts, setGifts] = useState<GiftIdea[]>([]);
    const [members, setMembers] = useState<Member[]>([]);
    const [title, setTitle] = useState('');
    const [dueDate, setDueDate] = useState('');
    const [assignedTo, setAssignedTo] = useState('');
    const [isPrivate, setIsPrivate] = useState(false);
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
            await axios.post('/api/v1/calendar/gifts', { gallery_space_id: spaceId, title: title.trim(), due_date: dueDate || null, assigned_to: assignedTo ? Number(assignedTo) : null, is_private: isPrivate });
            setTitle(''); setDueDate(''); setAssignedTo(''); setIsPrivate(false);
            await load();
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Nápad na dárek se nepodařilo uložit.');
        } finally { setBusy(false); }
    };

    const assignGift = async (uuid: string, userId: string) => {
        try {
            await axios.patch(`/api/v1/calendar/gifts/${uuid}`, { assigned_to: userId ? Number(userId) : null });
            await load();
        } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Odpovědnost se nepodařilo změnit.'); }
    };

    const setStage = async (uuid: string, stage: GiftStage) => {
        setBusy(true); setError('');
        try {
            await axios.patch(`/api/v1/calendar/gifts/${uuid}`, { stage });
            await load();
        } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Etapu dárku se nepodařilo změnit.'); }
        finally { setBusy(false); }
    };

    const togglePrivacy = async (gift: GiftIdea) => {
        setBusy(true); setError('');
        try {
            await axios.patch(`/api/v1/calendar/gifts/${gift.uuid}`, { is_private: !gift.is_private });
            await load();
        } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Soukromí dárku se nepodařilo změnit.'); }
        finally { setBusy(false); }
    };

    return <section className="rounded-2xl border border-amber-400/20 bg-[var(--color-bg-card)] p-4 sm:p-5">
        <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]"><Gift size={18} className="text-amber-300"/>Nápady na dárky</h2>
        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Termín se připomene 30, 7 a 1 den předem. Soukromý dárek uvidí pouze jeho autor — nezobrazí se partnerovi ani ve společných přehledech.</p>
        {error && <p className="mt-3 rounded-lg bg-red-500/10 p-2 text-xs text-red-200">{error}</p>}
        <form onSubmit={addGift} className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            <input required value={title} onChange={event => setTitle(event.target.value)} placeholder="Nápad na dárek" className="min-h-10 min-w-0 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm text-[var(--color-text-primary)] xl:col-span-2"/>
            <input aria-label="Termín dárku" type="date" value={dueDate} onChange={event => setDueDate(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm text-[var(--color-text-primary)]"/>
            <select aria-label="Kdo dárek zařídí" value={assignedTo} onChange={event => setAssignedTo(event.target.value)} className="min-h-10 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-sm text-[var(--color-text-primary)]"><option value="">Domluvit později</option>{members.map(member => <option key={member.id} value={member.id}>{member.name}</option>)}</select>
            <label className="flex min-h-10 items-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-xs text-[var(--color-text-secondary)] sm:col-span-2 xl:col-span-2"><input type="checkbox" checked={isPrivate} onChange={event => setIsPrivate(event.target.checked)} className="accent-amber-400"/><Lock size={14}/>Soukromé překvapení</label>
            <button disabled={busy} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-amber-600 px-3 text-sm text-white disabled:opacity-50 sm:col-span-2 xl:col-span-2"><Plus size={15}/>{busy ? 'Ukládám…' : 'Přidat nápad'}</button>
        </form>
        <div className="mt-4 grid gap-2 md:grid-cols-2">{gifts.map(gift => { const currentStage = gift.lifecycle?.at(-1)?.stage ?? (gift.status === 'planned' ? 'planned' : gift.status === 'purchased' ? 'purchased' : 'idea'); return <div key={gift.uuid} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3 text-sm"><div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"><div className="min-w-0"><p className="flex items-center gap-1.5 truncate text-[var(--color-text-primary)]">{gift.is_private && <Lock size={13} className="shrink-0 text-amber-200"/>}<span className="truncate">{gift.title}</span></p><p className="text-xs text-[var(--color-text-secondary)]">{gift.due_date ? new Date(`${gift.due_date}T12:00:00`).toLocaleDateString('cs-CZ') : 'bez data'} · {GIFT_STAGES.find(stage => stage.key === currentStage)?.label}</p></div><select aria-label={`Kdo zařídí ${gift.title}`} value={gift.assigned_to ?? ''} onChange={event => assignGift(gift.uuid, event.target.value)} className="min-h-9 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"><option value="">Kdo zařídí?</option>{members.map(member => <option key={member.id} value={member.id}>{member.name}</option>)}</select></div><div className="mt-3 flex flex-wrap gap-1.5" aria-label={`Časová osa dárku ${gift.title}`}>{GIFT_STAGES.map(stage => { const done = GIFT_STAGES.findIndex(item => item.key === stage.key) <= GIFT_STAGES.findIndex(item => item.key === currentStage); const recorded = gift.lifecycle?.find(item => item.stage === stage.key); return <button key={stage.key} type="button" disabled={busy} onClick={() => setStage(gift.uuid, stage.key)} title={recorded?.at ? `${stage.label}: ${new Date(recorded.at).toLocaleString('cs-CZ')}` : stage.label} className={`min-h-8 rounded-lg border px-2 text-[10px] disabled:opacity-50 ${done ? 'border-amber-300/35 bg-amber-500/15 text-amber-100' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}>{done ? '✓ ' : ''}{stage.label}</button>; })}{gift.can_toggle_privacy && <button type="button" disabled={busy} onClick={() => togglePrivacy(gift)} className="inline-flex min-h-8 items-center gap-1 rounded-lg border border-[var(--color-border)] px-2 text-[10px] text-[var(--color-text-secondary)] disabled:opacity-50"><Lock size={12}/>{gift.is_private ? 'Soukromé' : 'Sdílené'}</button>}</div></div>; })}{!gifts.length && <p className="rounded-xl border border-dashed border-[var(--color-border)] p-5 text-center text-sm text-[var(--color-text-secondary)] md:col-span-2">Zatím tu není žádný nápad na dárek.</p>}</div>
    </section>;
}