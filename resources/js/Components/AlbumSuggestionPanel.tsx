import axios from 'axios';
import { Link } from '@inertiajs/react';
import { Check, ChevronDown, Images, Sparkles, Video, X } from 'lucide-react';
import { useState } from 'react';

export interface AlbumSuggestionMedia {
    uuid: string;
    title: string;
    media_type: 'photo' | 'video';
    thumbnail_url?: string | null;
    taken_at: string;
    score: number;
}

export interface AlbumSuggestion {
    fingerprint: string;
    title: string;
    description: string;
    reason: string;
    starts_at: string;
    ends_at: string;
    media_count: number;
    photo_count: number;
    video_count: number;
    context?: { type: 'event' | 'trip' | 'place'; id: number; uuid?: string | null; name: string } | null;
    target_album?: { id: number; uuid: string; title: string } | null;
    media: AlbumSuggestionMedia[];
    preview: AlbumSuggestionMedia[];
}

interface Props {
    gallerySpaceId: number;
    initialSuggestions: AlbumSuggestion[];
    available?: boolean;
}

function SuggestionCard({ suggestion, gallerySpaceId, onRemove }: { suggestion: AlbumSuggestion; gallerySpaceId: number; onRemove: (fingerprint: string) => void }) {
    const [expanded, setExpanded] = useState(false);
    const [title, setTitle] = useState(suggestion.title);
    const [description, setDescription] = useState(suggestion.description);
    const [selected, setSelected] = useState(() => new Set(suggestion.media.map(item => item.uuid)));
    const [cover, setCover] = useState(suggestion.preview[0]?.uuid ?? suggestion.media[0]?.uuid);
    const [busy, setBusy] = useState<'accept' | 'dismiss' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<{ uuid: string; title: string; media_count: number } | null>(null);

    const toggle = (uuid: string) => {
        const next = new Set(selected);
        if (next.has(uuid)) {
            if (next.size === 1) return;
            next.delete(uuid);
            if (cover === uuid) setCover([...next][0]);
        } else next.add(uuid);
        setSelected(next);
    };

    const accept = async () => {
        setBusy('accept'); setError(null);
        try {
            const response = await axios.post(`/api/v1/album-suggestions/${suggestion.fingerprint}/accept`, {
                gallery_space_id: gallerySpaceId, title, description,
                media_uuids: suggestion.media.filter(item => selected.has(item.uuid)).map(item => item.uuid),
                cover_media_uuid: cover, create_memory: true,
            });
            setResult(response.data.album);
        } catch (e: any) {
            setError(e.response?.data?.message ?? 'Návrh se nepodařilo uložit. Zkuste jej obnovit.');
        } finally { setBusy(null); }
    };

    const dismiss = async () => {
        setBusy('dismiss'); setError(null);
        try {
            await axios.post(`/api/v1/album-suggestions/${suggestion.fingerprint}/dismiss`, { gallery_space_id: gallerySpaceId });
            onRemove(suggestion.fingerprint);
        } catch (e: any) {
            setError(e.response?.data?.message ?? 'Návrh se nepodařilo skrýt.');
        } finally { setBusy(null); }
    };

    if (result) return (
        <div className="rounded-2xl border border-emerald-400/25 bg-emerald-500/10 p-4">
            <div className="flex items-start gap-3"><span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-400/15 text-emerald-200"><Check size={18}/></span><div>
                <p className="font-medium text-white">{suggestion.target_album ? 'Album bylo doplněno' : 'Společné album je připravené'}</p>
                <p className="mt-1 text-xs text-emerald-100">{result.title} · {result.media_count} položek · příběh a vzpomínka jsou propojené.</p>
                <Link href={`/albums/${result.uuid}`} className="mt-3 inline-flex text-sm font-medium text-emerald-200">Otevřít album →</Link>
            </div></div>
        </div>
    );

    return (
        <article className="overflow-hidden rounded-2xl border border-violet-400/20 bg-black/15">
            <div className="grid grid-cols-5 gap-1 bg-[var(--color-bg-secondary)] p-1">
                {suggestion.preview.map((item, index) => <div key={item.uuid} className={`relative overflow-hidden rounded-lg bg-black/20 ${index === 0 ? 'col-span-2 row-span-2 aspect-square' : 'aspect-square'}`}>
                    {item.thumbnail_url ? <img src={item.thumbnail_url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover"/> : <div className="flex h-full items-center justify-center text-white/25"><Images size={18}/></div>}
                    {item.media_type === 'video' && <Video size={13} className="absolute bottom-1 right-1 text-white drop-shadow"/>}
                </div>)}
            </div>
            <div className="p-4">
                <div className="flex items-start justify-between gap-3"><div className="min-w-0"><h3 className="truncate font-semibold text-white">{suggestion.title}</h3><p className="mt-1 text-xs text-violet-100">{suggestion.reason}</p></div><span className="shrink-0 rounded-full bg-violet-500/10 px-2 py-1 text-[10px] text-violet-100">{suggestion.target_album ? 'doplnit album' : 'nové album'}</span></div>
                <div className="mt-3 flex flex-wrap gap-2 text-[11px] text-[var(--color-text-secondary)]"><span>{suggestion.media_count} momentů</span><span>·</span><span>{suggestion.photo_count} fotografií</span>{suggestion.video_count > 0 && <><span>·</span><span>{suggestion.video_count} videí</span></>}</div>
                <button type="button" onClick={() => setExpanded(value => !value)} className="mt-3 inline-flex min-h-9 items-center gap-1 text-xs font-medium text-violet-200">{expanded ? 'Skrýt výběr' : 'Zkontrolovat a upravit'}<ChevronDown size={14} className={expanded ? 'rotate-180' : ''}/></button>

                {expanded && <div className="mt-3 space-y-3 border-t border-white/10 pt-3">
                    <label className="block"><span className="text-xs text-[var(--color-text-secondary)]">Název alba</span><input value={title} onChange={e => setTitle(e.target.value)} maxLength={255} className="mt-1 w-full rounded-xl border border-[var(--color-border)] bg-black/20 px-3 py-2 text-sm text-white"/></label>
                    <label className="block"><span className="text-xs text-[var(--color-text-secondary)]">Popis společné vzpomínky</span><textarea value={description} onChange={e => setDescription(e.target.value)} rows={2} maxLength={5000} className="mt-1 w-full resize-y rounded-xl border border-[var(--color-border)] bg-black/20 px-3 py-2 text-sm text-white"/></label>
                    <div><div className="mb-2 flex items-center justify-between text-xs"><span className="text-[var(--color-text-secondary)]">Vybráno {selected.size} z {suggestion.media.length}</span><span className="text-violet-200">Klepnutím vyřadíte</span></div><div className="grid max-h-64 grid-cols-4 gap-2 overflow-y-auto pr-1 sm:grid-cols-6 lg:grid-cols-8">
                        {suggestion.media.map(item => <button type="button" key={item.uuid} onClick={() => toggle(item.uuid)} className={`group relative aspect-square overflow-hidden rounded-lg border-2 ${selected.has(item.uuid) ? 'border-violet-400' : 'border-transparent opacity-40'}`} title={item.title}>
                            {item.thumbnail_url ? <img src={item.thumbnail_url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover"/> : <div className="flex h-full items-center justify-center bg-white/5 text-white/30"><Images size={16}/></div>}
                            {selected.has(item.uuid) && <span className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-violet-500 text-white"><Check size={12}/></span>}
                            {selected.has(item.uuid) && <span onClick={e => { e.stopPropagation(); setCover(item.uuid); }} className={`absolute bottom-1 left-1 rounded px-1 py-0.5 text-[9px] ${cover === item.uuid ? 'bg-amber-400 text-black' : 'bg-black/70 text-white'}`}>{cover === item.uuid ? 'titulní' : 'zvolit titulní'}</span>}
                        </button>)}
                    </div></div>
                </div>}
                {error && <p className="mt-3 text-xs text-red-300">{error}</p>}
                <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" disabled={busy !== null} onClick={dismiss} className="inline-flex min-h-10 items-center justify-center gap-1 rounded-xl border border-white/10 px-3 text-xs text-[var(--color-text-secondary)] disabled:opacity-50"><X size={14}/>{busy === 'dismiss' ? 'Skrývám…' : 'Tento návrh nechci'}</button><button type="button" disabled={busy !== null || selected.size === 0 || !title.trim()} onClick={accept} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-violet-500 px-4 text-sm font-medium text-white hover:bg-violet-400 disabled:opacity-50"><Sparkles size={15}/>{busy === 'accept' ? 'Propojuji…' : suggestion.target_album ? 'Doplnit propojené album' : 'Vytvořit album a vzpomínku'}</button></div>
            </div>
        </article>
    );
}

export default function AlbumSuggestionPanel({ gallerySpaceId, initialSuggestions, available = true }: Props) {
    const [suggestions, setSuggestions] = useState(initialSuggestions);
    if (!available || suggestions.length === 0) return null;
    return (
        <section id="album-suggestions" className="mb-7 scroll-mt-20 rounded-3xl border border-violet-400/20 bg-gradient-to-br from-violet-500/10 to-[var(--color-bg-card)] p-4 sm:p-5">
            <div className="mb-4 flex items-start gap-3"><span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-violet-400/15 text-violet-200"><Sparkles size={20}/></span><div><p className="text-xs font-medium uppercase tracking-wider text-violet-200">Nalezené společné zážitky</p><h2 className="mt-1 text-base font-semibold text-white">Fotografie a videa, ze kterých může vzniknout album</h2><p className="mt-1 max-w-3xl text-xs text-[var(--color-text-secondary)]">Systém spojil nezařazená média podle času, místa, kalendáře a cest. Nic nepřesune bez vašeho potvrzení.</p></div></div>
            <div className="grid gap-4 lg:grid-cols-2">{suggestions.map(suggestion => <SuggestionCard key={suggestion.fingerprint} suggestion={suggestion} gallerySpaceId={gallerySpaceId} onRemove={fingerprint => setSuggestions(current => current.filter(item => item.fingerprint !== fingerprint))}/>)}</div>
        </section>
    );
}
