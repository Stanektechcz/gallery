import axios from 'axios';
import { LockKeyhole, Save, UsersRound } from 'lucide-react';
import { useEffect, useState } from 'react';

type NotesPayload = {
    shared: { content: string; updated_at?: string | null } | null;
    mine: { content: string; updated_at?: string | null } | null;
};

export default function PlaceNotesPanel({ placeId }: { placeId: number }) {
    const [shared, setShared] = useState('');
    const [personal, setPersonal] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState<'shared' | 'personal' | null>(null);
    const [message, setMessage] = useState('');

    useEffect(() => {
        let active = true;
        setLoading(true);
        axios.get<NotesPayload>(`/api/v1/places/${placeId}/notes`)
            .then(({ data }) => {
                if (!active) return;
                setShared(data.shared?.content ?? '');
                setPersonal(data.mine?.content ?? '');
            })
            .catch(() => active && setMessage('Poznámky se nepodařilo načíst.'))
            .finally(() => active && setLoading(false));
        return () => { active = false; };
    }, [placeId]);

    const save = async (visibility: 'shared' | 'personal') => {
        setSaving(visibility);
        setMessage('');
        try {
            await axios.put(`/api/v1/places/${placeId}/notes`, {
                visibility,
                content: visibility === 'shared' ? shared : personal,
            });
            setMessage(visibility === 'shared' ? 'Společná poznámka je uložena.' : 'Soukromá poznámka je uložena.');
        } catch (error: any) {
            setMessage(error?.response?.data?.message ?? 'Poznámku se nepodařilo uložit.');
        } finally {
            setSaving(null);
        }
    };

    return (
        <section className="border-b border-[var(--color-border)] bg-[var(--color-bg-primary)] px-6 py-5">
            <div className="mb-4">
                <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Poznámky k místu</h2>
                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">Společné tipy pro vás oba i vlastní soukromé poznámky bez míchání obsahu.</p>
            </div>
            {loading ? <div className="text-sm text-[var(--color-text-secondary)]">Načítám poznámky…</div> : (
                <div className="grid gap-4 lg:grid-cols-2">
                    <NoteEditor icon={<UsersRound size={15} />} title="Společná poznámka" hint="Viditelná pro oba. Hodí se na doporučení, tipy a domluvu." value={shared} onChange={setShared} saving={saving === 'shared'} onSave={() => save('shared')} />
                    <NoteEditor icon={<LockKeyhole size={15} />} title="Moje soukromá poznámka" hint="Uvidíte ji pouze vy – druhému člověku se nikdy nezobrazí." value={personal} onChange={setPersonal} saving={saving === 'personal'} onSave={() => save('personal')} privateNote />
                </div>
            )}
            {message && <p className="mt-3 text-xs text-[var(--color-text-secondary)]" role="status">{message}</p>}
        </section>
    );
}

function NoteEditor({ icon, title, hint, value, onChange, saving, onSave, privateNote = false }: {
    icon: React.ReactNode; title: string; hint: string; value: string; onChange: (value: string) => void;
    saving: boolean; onSave: () => void; privateNote?: boolean;
}) {
    return (
        <div className={`rounded-xl border p-4 ${privateNote ? 'border-violet-500/30 bg-violet-500/5' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'}`}>
            <div className="flex items-center gap-2 text-sm font-medium text-[var(--color-text-primary)]">{icon}{title}</div>
            <p className="mt-1 text-[11px] leading-relaxed text-[var(--color-text-secondary)]">{hint}</p>
            <textarea value={value} onChange={event => onChange(event.target.value)} rows={4} maxLength={10000} placeholder={privateNote ? 'Například co si chcete pamatovat jen vy…' : 'Například co zde příště vyzkoušet…'} className="mt-3 w-full resize-y rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 py-2 text-sm text-[var(--color-text-primary)] outline-none placeholder:text-[var(--color-text-secondary)] focus:border-[var(--color-accent)]" />
            <div className="mt-2 flex items-center justify-between gap-3">
                <span className="text-[11px] text-[var(--color-text-secondary)]">{value.length}/10 000</span>
                <button onClick={onSave} disabled={saving} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-[var(--color-accent)] px-3 text-xs font-medium text-[var(--color-accent-contrast)] disabled:opacity-50"><Save size={13} />{saving ? 'Ukládám…' : 'Uložit'}</button>
            </div>
        </div>
    );
}