import axios from 'axios';
import AnniversaryRecapPanel from '@/Components/AnniversaryRecapPanel';
import { CalendarHeart, Save, Timer } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

type AnniversaryEvent = { uuid: string; title: string; starts_at: string; recurrence_rule?: { frequency?: string } | null };

export default function RelationshipAnniversarySettings({ spaceId, showRecap = true }: { spaceId?: number; showRecap?: boolean }) {
    const [startedOn, setStartedOn] = useState('');
    const [events, setEvents] = useState<AnniversaryEvent[]>([]);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const [now, setNow] = useState(() => new Date());
    const [recapVersion, setRecapVersion] = useState(0);

    useEffect(() => {
        if (!spaceId) return;
        axios.get('/api/v1/relationship-milestones/relationship-anniversary', { params: { gallery_space_id: spaceId } })
            .then(response => { setStartedOn(response.data?.started_on ?? ''); setEvents(response.data?.events ?? []); })
            .catch(() => setError('Nastavení výročí se nepodařilo načíst.'));
    }, [spaceId]);

    // The start is intentionally a date (not a private timestamp). Update
    // once a minute so the hour value stays alive without needless renders.
    useEffect(() => {
        if (!startedOn) return;
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, [startedOn]);

    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (!spaceId || !startedOn) return;
        setSaving(true); setError(''); setNotice('');
        try {
            const response = await axios.put('/api/v1/relationship-milestones/relationship-anniversary', {
                gallery_space_id: spaceId, started_on: startedOn, reminder_days: [30, 7, 1],
            });
            setEvents(response.data?.events ?? []);
            setRecapVersion(value => value + 1);
            setNotice('Výročí je naplánováno v kalendáři pro vás oba.');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Výročí se nepodařilo uložit.');
        } finally { setSaving(false); }
    };

    const duration = (() => {
        if (!startedOn) return null;
        const startedAt = new Date(`${startedOn}T00:00:00`);
        if (Number.isNaN(startedAt.getTime()) || startedAt > now) return null;
        const elapsedMs = now.getTime() - startedAt.getTime();
        const days = Math.floor(elapsedMs / 86_400_000);
        const hours = Math.floor(elapsedMs / 3_600_000);
        let years = now.getFullYear() - startedAt.getFullYear();
        const anniversaryThisYear = new Date(now.getFullYear(), startedAt.getMonth(), startedAt.getDate());
        if (anniversaryThisYear > now) years--;
        return { years: Math.max(0, years), days, hours };
    })();

    const number = new Intl.NumberFormat('cs-CZ');
    return <section id="relationship-anniversary" className="scroll-mt-20 lg:col-span-2 rounded-2xl border border-pink-500/25 bg-gradient-to-br from-pink-500/10 to-[var(--color-bg-card)] p-4">
        <h2 className="flex items-center gap-2 font-semibold text-white"><CalendarHeart size={17} className="text-pink-300"/>Naše výročí</h2>
        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Zadejte den, kdy vztah začal. Přidáme 1. měsíc, půl roku a opakované roční výročí; oběma připomeneme 30, 7 a 1 den předem.</p>
        <form onSubmit={save} className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
            <label className="flex-1 text-xs text-pink-100">Začátek vztahu
                <input required max={new Date().toISOString().slice(0, 10)} type="date" value={startedOn} onChange={item => setStartedOn(item.target.value)} className="mt-1 block min-h-10 w-full rounded-lg border border-pink-300/25 bg-black/10 px-3 text-sm text-white"/>
            </label>
            <button disabled={!startedOn || saving} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-pink-600 px-4 text-sm text-white disabled:opacity-50"><Save size={15}/>{saving ? 'Ukládám…' : 'Naplánovat výročí'}</button>
        </form>
        {duration && <div className="mt-4 grid grid-cols-3 gap-2 rounded-xl border border-pink-300/20 bg-black/15 p-3 text-center">
            <div><p className="text-lg font-semibold text-white">{number.format(duration.years)}</p><p className="text-[10px] uppercase tracking-wide text-pink-200">let spolu</p></div>
            <div><p className="text-lg font-semibold text-white">{number.format(duration.days)}</p><p className="text-[10px] uppercase tracking-wide text-pink-200">dní spolu</p></div>
            <div><p className="text-lg font-semibold text-white">{number.format(duration.hours)}</p><p className="text-[10px] uppercase tracking-wide text-pink-200"><Timer className="mr-1 inline" size={11}/>hodin spolu</p></div>
        </div>}
        {notice && <p className="mt-3 text-sm text-emerald-200">{notice}</p>}
        {error && <p className="mt-3 text-sm text-red-200">{error}</p>}
        {events.length > 0 && <div className="mt-3 flex flex-wrap gap-2">{events.map(item => <span key={item.uuid} className="rounded-lg border border-pink-300/20 bg-black/10 px-3 py-2 text-xs text-pink-100">❤️ {item.title} · {new Date(item.starts_at).toLocaleDateString('cs-CZ')}{item.recurrence_rule?.frequency === 'yearly' ? ' · každý rok' : ''}</span>)}</div>}
        {showRecap && <AnniversaryRecapPanel spaceId={spaceId} startedOn={startedOn} refreshKey={recapVersion}/>}
    </section>;
}
