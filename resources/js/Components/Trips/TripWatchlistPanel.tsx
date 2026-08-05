import axios from 'axios';
import { Download, Film, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Title = { uuid: string; title: string; media_type: 'movie' | 'series'; runtime_minutes?: number | null; watch_provider?: string | null; status: string; };
type Item = { id: number; entertainment_uuid: string; title: string; media_type: 'movie' | 'series'; runtime_minutes?: number | null; watch_provider?: string | null; offline_status: 'later' | 'ready' | 'unavailable'; note?: string | null; added_by_name?: string | null; };
type Payload = { items: Item[]; available: Title[] };

const STATUS = { later: 'Stáhnout později', ready: 'Připraveno offline', unavailable: 'Nelze offline' } as const;

export default function TripWatchlistPanel({ tripId }: { tripId: number }) {
    const [data, setData] = useState<Payload>({ items: [], available: [] });
    const [selected, setSelected] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const selectedTitle = useMemo(() => data.available.find(title => title.uuid === selected), [data.available, selected]);
    const load = async () => {
        try { const response = await axios.get<Payload>(`/api/v1/trips/${tripId}/watchlist`); setData(response.data); }
        catch (error: any) { setMessage(error?.response?.data?.message ?? 'Seznam titulů se nepodařilo načíst.'); }
    };
    useEffect(() => { void load(); }, [tripId]);
    const add = async () => {
        if (!selected) return;
        setBusy(true); setMessage('');
        try {
            await axios.post(`/api/v1/trips/${tripId}/watchlist`, { entertainment_uuid: selected, watch_provider: selectedTitle?.watch_provider ?? null });
            setSelected(''); await load();
        } catch (error: any) { setMessage(error?.response?.data?.message ?? 'Titul se nepodařilo přidat.'); }
        finally { setBusy(false); }
    };
    const update = async (item: Item, patch: Partial<Item>) => {
        setBusy(true); setMessage('');
        try { await axios.patch(`/api/v1/trips/${tripId}/watchlist/${item.id}`, patch); await load(); }
        catch (error: any) { setMessage(error?.response?.data?.message ?? 'Stav se nepodařilo uložit.'); }
        finally { setBusy(false); }
    };
    const remove = async (item: Item) => {
        setBusy(true); try { await axios.delete(`/api/v1/trips/${tripId}/watchlist/${item.id}`); await load(); }
        finally { setBusy(false); }
    };
    return <section className="rounded-2xl border border-violet-400/25 bg-violet-500/5 p-4">
        <div className="flex items-start gap-2"><Film size={16} className="mt-0.5 text-violet-200"/><div><h2 className="text-xs font-semibold text-[var(--color-text-primary)]">Na sledování na cestě</h2><p className="mt-1 text-[10px] leading-relaxed text-[var(--color-text-secondary)]">Výběr z vašeho watchlistu. Stav říká, zda je titul skutečně připravený offline – aplikace obsah sama nestahuje.</p></div></div>
        <div className="mt-3 flex gap-2"><select value={selected} onChange={event => setSelected(event.target.value)} className="min-h-10 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-[var(--color-text-primary)]"><option value="">Vyberte titul</option>{data.available.filter(title => !data.items.some(item => item.entertainment_uuid === title.uuid)).map(title => <option key={title.uuid} value={title.uuid}>{title.media_type === 'series' ? '📺' : '🎬'} {title.title}{title.runtime_minutes ? ` · ${title.runtime_minutes} min` : ''}</option>)}</select><button disabled={busy || !selected} onClick={add} className="inline-flex min-h-10 items-center gap-1 rounded-lg bg-violet-500 px-3 text-xs font-medium text-white disabled:opacity-50"><Plus size={14}/>Přidat</button></div>
        <div className="mt-3 space-y-2">{data.items.map(item => <div key={item.id} className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3"><div className="flex gap-2"><div className="min-w-0 flex-1"><p className="truncate text-xs font-medium text-[var(--color-text-primary)]">{item.media_type === 'series' ? '📺' : '🎬'} {item.title}</p><p className="mt-0.5 text-[10px] text-[var(--color-text-secondary)]">{[item.watch_provider, item.runtime_minutes ? `${item.runtime_minutes} min` : null, item.added_by_name ? `navrhl/a ${item.added_by_name}` : null].filter(Boolean).join(' · ') || 'Platformu doplňte podle potřeby'}</p></div><button disabled={busy} onClick={() => remove(item)} aria-label={`Odebrat ${item.title} z cesty`} className="min-h-8 min-w-8 rounded-lg text-[var(--color-text-secondary)] hover:bg-red-500/10 hover:text-red-300"><Trash2 size={14}/></button></div><div className="mt-2 flex gap-2"><select disabled={busy} value={item.offline_status} onChange={event => update(item, { offline_status: event.target.value as Item['offline_status'] })} className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-[10px] text-[var(--color-text-primary)]">{Object.entries(STATUS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select><input value={item.watch_provider ?? ''} onBlur={event => { if (event.target.value !== (item.watch_provider ?? '')) void update(item, { watch_provider: event.target.value || null }); }} placeholder="Platforma" className="min-h-9 min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-[10px] text-[var(--color-text-primary)]"/></div></div>)}{!data.items.length && <p className="rounded-xl border border-dashed border-violet-400/25 p-3 text-center text-[10px] text-[var(--color-text-secondary)]"><Download size={15} className="mx-auto mb-1"/>Vyberte filmy nebo seriály, které chcete mít během cesty připravené.</p>}</div>
        {message && <p role="status" className="mt-2 text-[10px] text-amber-200">{message}</p>}
    </section>;
}