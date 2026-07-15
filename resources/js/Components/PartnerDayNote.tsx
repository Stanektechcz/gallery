import axios from 'axios';
import { NotebookPen } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function PartnerDayNote({ spaceId }: { spaceId?: number }) {
    const [note, setNote] = useState('');
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!spaceId) return;
        axios.get('/api/v1/calendar/day-note', { params: { gallery_space_id: spaceId } })
            .then(response => setNote(response.data?.content ?? ''))
            .catch(() => setError('Dnešní poznámku se nepodařilo načíst.'));
    }, [spaceId]);

    const save = async () => {
        if (!spaceId) return;
        setError('');
        try {
            await axios.put('/api/v1/calendar/day-note', { gallery_space_id: spaceId, content: note });
            setSaved(true);
            window.setTimeout(() => setSaved(false), 1800);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Poznámku se nepodařilo uložit.');
        }
    };

    return <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
        <h2 className="flex items-center gap-2 font-semibold text-white"><NotebookPen size={17} className="text-teal-300"/>Naše dnešní poznámka</h2>
        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Krátká společná poznámka uložená soukromě pro členy prostoru.</p>
        {error && <p className="mt-2 text-xs text-red-200">{error}</p>}
        <textarea value={note} onChange={event => setNote(event.target.value)} rows={4} placeholder="Co si dnes nechceme zapomenout?" className="mt-3 w-full rounded-lg border border-[var(--color-border)] bg-black/10 p-3 text-sm text-white"/>
        <button type="button" onClick={save} className="mt-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm text-white">{saved ? 'Uloženo ✓' : 'Uložit poznámku'}</button>
    </section>;
}
