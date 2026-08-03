import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout';
import usePrimaryGallerySpace from '@/hooks/usePrimaryGallerySpace';
import { Head, Link } from '@inertiajs/react';
import { CalendarCheck2, Clock3, Info, Power, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';

type Automation = {
    key: string;
    title: string;
    description: string;
    schedule: string;
    condition: string;
    enabled: boolean;
    last_run_at?: string | null;
};

const dateTime = (value?: string | null) => value ? new Intl.DateTimeFormat('cs-CZ', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'zatím neproběhlo';

export default function AutomationSettings() {
    const { spaceId, loading: spaceLoading, error: spaceError, reload: reloadSpace } = usePrimaryGallerySpace();
    const [items, setItems] = useState<Automation[]>([]);
    const [spaceName, setSpaceName] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState<string | null>(null);
    const [error, setError] = useState('');

    const load = async () => {
        if (!spaceId) return;
        setLoading(true); setError('');
        try {
            const response = await axios.get('/api/v1/automations', { params: { gallery_space_id: spaceId } });
            setItems(response.data?.automations ?? []); setSpaceName(response.data?.space?.name ?? '');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Automatizace se nepodařilo načíst.');
        } finally { setLoading(false); }
    };
    useEffect(() => { void load(); }, [spaceId]);

    const toggle = async (automation: Automation) => {
        if (!spaceId || saving) return;
        const enabled = !automation.enabled;
        setSaving(automation.key); setError('');
        setItems(current => current.map(item => item.key === automation.key ? { ...item, enabled } : item));
        try {
            const response = await axios.patch(`/api/v1/automations/${automation.key}`, { gallery_space_id: spaceId, enabled });
            setItems(current => current.map(item => item.key === automation.key ? response.data.automation : item));
        } catch (reason: any) {
            setItems(current => current.map(item => item.key === automation.key ? automation : item));
            setError(reason?.response?.data?.message ?? 'Změnu automatizace se nepodařilo uložit.');
        } finally { setSaving(null); }
    };

    return <AppLayout><Head title="Automatizace"/><main className="w-full p-4 sm:p-6 lg:p-8">
        <div className="w-full">
            <Link href="/settings/security" className="text-sm text-[var(--color-accent)] hover:underline">Nastavení</Link>
            <div className="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div><h1 className="flex items-center gap-3 text-2xl font-bold text-white"><Power className="text-[var(--color-accent)]"/>Automatizace prostoru</h1><p className="mt-2 max-w-3xl text-sm text-[var(--color-text-secondary)]">Pravidla šetří údržbu, ale zůstávají vždy pod vaší kontrolou. Vypnuté pravidlo nic automaticky nemění.</p></div>
                <button type="button" onClick={() => void load()} disabled={!spaceId || loading} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-[var(--color-border)] px-3 text-sm text-white hover:bg-white/5 disabled:opacity-50"><RefreshCw size={15} className={loading ? 'animate-spin' : ''}/>Obnovit</button>
            </div>
            <div className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 text-sm text-[var(--color-text-secondary)]"><span className="font-medium text-white">Spravovaný prostor:</span> {spaceName || (spaceLoading ? 'načítám…' : 'není dostupný')}</div>
            {(error || spaceError) && <div className="mt-4 rounded-xl border border-red-400/20 bg-red-500/10 p-3 text-sm text-red-100">{error || spaceError} <button type="button" onClick={() => void reloadSpace()} className="ml-2 underline">Zkusit znovu</button></div>}
            <section className="mt-5 grid gap-4">
                {items.map(automation => <article key={automation.key} className="rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:p-5">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"><div className="min-w-0 flex-1"><div className="flex items-center gap-2"><CalendarCheck2 size={19} className="shrink-0 text-emerald-300"/><h2 className="text-lg font-semibold text-white">{automation.title}</h2></div><p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--color-text-secondary)]">{automation.description}</p><dl className="mt-4 grid gap-2 sm:grid-cols-3"><div className="rounded-xl bg-black/10 p-3"><dt className="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-[var(--color-text-secondary)]"><Clock3 size={13}/>Spouštění</dt><dd className="mt-1 text-sm text-white">{automation.schedule}</dd></div><div className="rounded-xl bg-black/10 p-3"><dt className="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-[var(--color-text-secondary)]"><Info size={13}/>Podmínka</dt><dd className="mt-1 text-sm text-white">{automation.condition}</dd></div><div className="rounded-xl bg-black/10 p-3"><dt className="text-[11px] uppercase tracking-wide text-[var(--color-text-secondary)]">Naposledy</dt><dd className="mt-1 text-sm text-white">{dateTime(automation.last_run_at)}</dd></div></dl></div>
                        <label className="flex min-h-12 shrink-0 cursor-pointer items-center gap-3 rounded-xl border border-[var(--color-border)] px-3 text-sm text-white hover:bg-white/5"><span>{automation.enabled ? 'Zapnuto' : 'Vypnuto'}</span><input type="checkbox" checked={automation.enabled} disabled={saving === automation.key} onChange={() => void toggle(automation)} className="h-5 w-5 accent-emerald-500 disabled:opacity-50" aria-label={`${automation.title}: ${automation.enabled ? 'vypnout' : 'zapnout'}`}/></label>
                    </div>
                </article>)}
                {!loading && !items.length && <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center text-sm text-[var(--color-text-secondary)]">Pro tento prostor zatím není k dispozici žádná automatizace.</div>}
                {loading && <div className="rounded-2xl border border-[var(--color-border)] p-8 text-center text-sm text-[var(--color-text-secondary)]">Načítám automatizace…</div>}
            </section>
        </div>
    </main></AppLayout>;
}
