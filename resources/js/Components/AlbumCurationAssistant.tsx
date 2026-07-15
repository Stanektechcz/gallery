import axios from 'axios';
import { Check, ChevronDown, ChevronUp, CloudUpload, Heart, ImageOff, ShieldAlert, ShieldCheck, Sparkles, ThumbsDown, ThumbsUp, WandSparkles } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Candidate {
    id: number;
    uuid: string;
    title: string;
    media_type: 'photo' | 'video';
    thumbnail_url?: string | null;
    score: number;
    reasons: string[];
    risks: string[];
}

interface VoteSummary { selected: number; not_selected: number; my_vote: boolean | null }
interface BoardItem { id: number; media_uuid: string; title: string; thumbnail_url?: string | null; status: string; note?: string | null; votes: VoteSummary }
interface AssistantData {
    album: { current_cover_media_id?: number | null };
    summary: { media_count: number; photos: number; videos: number; candidate_limit_reached: boolean };
    cover_recommendation?: Candidate | null;
    shortlist: Candidate[];
    backup: { status: 'empty' | 'safe' | 'attention' | 'critical'; coverage_percent: number; cloud_backed: number; local_only: number; uploading: number; missing_original: number; verified: number; can_sync: boolean };
    quality: { missing_preview: number; missing_large: number; processing_failed: number; missing_taken_at: number; missing_dimensions: number };
    board?: { uuid: string; title: string; items: BoardItem[] } | null;
}

export default function AlbumCurationAssistant({ albumUuid }: { albumUuid: string }) {
    const [data, setData] = useState<AssistantData | null>(null);
    const [expanded, setExpanded] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);

    const load = async () => {
        try {
            const response = await axios.get(`/api/v1/albums/${albumUuid}/curation-assistant`);
            setData(response.data);
        } catch (error: any) {
            setMessage(error?.response?.data?.message ?? 'Stav výběru a zálohy se nepodařilo načíst.');
        }
    };

    useEffect(() => { void load(); }, [albumUuid]);

    const run = async (key: string, callback: () => Promise<any>, success: string) => {
        setBusy(key);
        setMessage(null);
        try {
            const response = await callback();
            setMessage(response?.data?.message ?? success);
            await load();
        } catch (error: any) {
            setMessage(error?.response?.data?.message ?? 'Akci se nepodařilo dokončit.');
        } finally {
            setBusy(null);
        }
    };

    if (!data && !message) {
        return <div className="mb-4 h-16 animate-pulse rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)]" />;
    }
    if (!data) {
        return <div className="mb-4 rounded-xl border border-amber-400/25 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">{message}</div>;
    }

    const safe = data.backup.status === 'safe';
    const cover = data.cover_recommendation;
    const issueCount = data.quality.missing_preview + data.quality.processing_failed + data.backup.missing_original;

    return (
        <section className="mb-5 overflow-hidden rounded-2xl border border-fuchsia-400/20 bg-gradient-to-br from-fuchsia-500/10 via-[var(--color-bg-card)] to-cyan-500/10">
            <button type="button" onClick={() => setExpanded(value => !value)} className="flex w-full items-center gap-3 p-4 text-left">
                <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${safe ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-200'}`}>
                    {safe ? <ShieldCheck size={21}/> : <ShieldAlert size={21}/>}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2 text-sm font-semibold text-white">
                        Společný výběr a bezpečí alba
                        {data.board && <span className="rounded-full bg-fuchsia-500/15 px-2 py-0.5 text-[10px] text-fuchsia-100">partnerské hlasování aktivní</span>}
                    </span>
                    <span className="mt-1 block text-xs text-[var(--color-text-secondary)]">
                        {data.summary.media_count === 0
                            ? 'Po nahrání médií doporučíme nejlepší záběry a zkontrolujeme originály.'
                            : `${data.backup.coverage_percent} % originálů v cloudu · ${cover ? 'doporučená titulní fotografie připravena' : 'čeká na vhodný titulní záběr'}${issueCount ? ` · ${issueCount} položek vyžaduje pozornost` : ''}`}
                    </span>
                </span>
                {expanded ? <ChevronUp size={18} className="text-[var(--color-text-secondary)]"/> : <ChevronDown size={18} className="text-[var(--color-text-secondary)]"/>}
            </button>

            {expanded && (
                <div className="border-t border-white/10 p-4">
                    {message && <p className="mb-3 rounded-lg bg-white/5 px-3 py-2 text-xs text-cyan-100">{message}</p>}

                    <div className="grid gap-3 md:grid-cols-3">
                        <div className="rounded-xl border border-white/10 bg-black/10 p-3">
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <span className="flex items-center gap-1.5 text-xs font-medium text-white"><ShieldCheck size={14}/> Originály</span>
                                <span className={`text-xs font-semibold ${safe ? 'text-emerald-300' : 'text-amber-200'}`}>{data.backup.coverage_percent} %</span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-white/10"><div className={`h-full rounded-full ${safe ? 'bg-emerald-400' : 'bg-amber-400'}`} style={{ width: `${data.backup.coverage_percent}%` }}/></div>
                            <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">{data.backup.cloud_backed} v cloudu · {data.backup.local_only} jen lokálně{data.backup.uploading ? ` · ${data.backup.uploading} se nahrává` : ''}</p>
                            {data.backup.local_only > 0 && data.backup.can_sync && (
                                <button onClick={() => run('backup', () => axios.post(`/api/v1/albums/${albumUuid}/backup`), 'Záloha byla zařazena.')} disabled={busy !== null} className="mt-3 flex items-center gap-1.5 rounded-lg bg-cyan-500/15 px-2.5 py-1.5 text-xs text-cyan-100 hover:bg-cyan-500/25 disabled:opacity-40">
                                    <CloudUpload size={13}/> {busy === 'backup' ? 'Zařazuji…' : 'Zálohovat chybějící'}
                                </button>
                            )}
                            {data.backup.local_only > 0 && !data.backup.can_sync && <p className="mt-2 text-[11px] text-amber-200">Pro automatickou zálohu připojte Google Drive v nastavení.</p>}
                        </div>

                        <div className="rounded-xl border border-white/10 bg-black/10 p-3">
                            <span className="flex items-center gap-1.5 text-xs font-medium text-white"><ImageOff size={14}/> Kvalita zpracování</span>
                            <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">{data.quality.missing_preview ? `${data.quality.missing_preview} bez náhledu` : 'Všechny náhledy připravené'} · {data.quality.processing_failed ? `${data.quality.processing_failed} chyb` : 'bez chyb'}</p>
                            {data.quality.missing_preview > 0 && (
                                <button onClick={() => run('previews', () => axios.post(`/api/v1/albums/${albumUuid}/repair-previews`), 'Oprava náhledů byla zařazena.')} disabled={busy !== null} className="mt-3 flex items-center gap-1.5 rounded-lg bg-amber-500/15 px-2.5 py-1.5 text-xs text-amber-100 hover:bg-amber-500/25 disabled:opacity-40">
                                    <WandSparkles size={13}/> {busy === 'previews' ? 'Zařazuji…' : 'Opravit náhledy'}
                                </button>
                            )}
                            {(data.quality.missing_taken_at > 0 || data.quality.missing_dimensions > 0) && <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">Metadata: {data.quality.missing_taken_at} bez data, {data.quality.missing_dimensions} bez rozměrů.</p>}
                        </div>

                        <div className="rounded-xl border border-white/10 bg-black/10 p-3">
                            <span className="flex items-center gap-1.5 text-xs font-medium text-white"><Sparkles size={14}/> Titulní záběr</span>
                            {cover ? (
                                <div className="mt-2 flex gap-2">
                                    <div className="h-14 w-14 shrink-0 overflow-hidden rounded-lg bg-white/5">{cover.thumbnail_url ? <img src={cover.thumbnail_url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover"/> : <Sparkles className="m-4 text-fuchsia-200" size={22}/>}</div>
                                    <div className="min-w-0"><p className="truncate text-xs text-white">{cover.title}</p><p className="mt-1 line-clamp-2 text-[10px] text-[var(--color-text-secondary)]">{cover.reasons.slice(0, 3).join(' · ') || 'Nejlepší dostupný záběr'}</p></div>
                                </div>
                            ) : <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">Zatím není co doporučit.</p>}
                            {cover && data.album.current_cover_media_id !== cover.id && (
                                <button onClick={() => run('cover', () => axios.put(`/api/v1/albums/${albumUuid}/cover`, { media_uuid: cover.uuid }), 'Titulní fotografie byla nastavena.')} disabled={busy !== null} className="mt-3 flex items-center gap-1.5 rounded-lg bg-fuchsia-500/20 px-2.5 py-1.5 text-xs text-fuchsia-100 hover:bg-fuchsia-500/30 disabled:opacity-40">
                                    <Check size={13}/> {busy === 'cover' ? 'Nastavuji…' : 'Použít jako titulní'}
                                </button>
                            )}
                        </div>
                    </div>

                    <div className="mt-4 rounded-xl border border-white/10 bg-black/10 p-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div><h3 className="text-sm font-medium text-white">Náš společný výběr</h3><p className="text-[11px] text-[var(--color-text-secondary)]">Asistent vynechá opakované záběry ze stejné série; konečné slovo máte vždy vy dva.</p></div>
                            {!data.board && data.shortlist.length > 0 && (
                                <button onClick={() => run('shortlist', () => axios.post(`/api/v1/albums/${albumUuid}/curation-shortlist`), 'Společný výběr je připraven.')} disabled={busy !== null} className="flex items-center gap-1.5 rounded-lg bg-fuchsia-500 px-3 py-2 text-xs font-medium text-white hover:bg-fuchsia-400 disabled:opacity-40">
                                    <Heart size={13}/> {busy === 'shortlist' ? 'Připravuji…' : 'Připravit partnerský výběr'}
                                </button>
                            )}
                        </div>

                        <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
                            {(data.board?.items ?? data.shortlist.slice(0, 8)).map((item: any) => (
                                <div key={item.media_uuid ?? item.uuid} className="w-28 shrink-0 overflow-hidden rounded-lg border border-white/10 bg-white/5">
                                    <a href={`/media/${item.media_uuid ?? item.uuid}`} className="block aspect-square bg-black/20">{item.thumbnail_url ? <img src={item.thumbnail_url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover"/> : <Sparkles className="m-auto mt-8 text-white/30" size={24}/>}</a>
                                    {data.board ? (
                                        <div className="flex items-center justify-between gap-1 p-1.5">
                                            <button title="Chci do výběru" onClick={() => run(`vote-${item.id}`, () => axios.put(`/api/v1/curation-boards/${data.board!.uuid}/items/${item.id}/vote`, { is_selected: true }), 'Hlas byl uložen.')} disabled={busy !== null} className={`flex items-center gap-1 rounded px-1.5 py-1 text-[10px] ${item.votes.my_vote === true ? 'bg-emerald-500/25 text-emerald-200' : 'text-[var(--color-text-secondary)] hover:bg-white/10'}`}><ThumbsUp size={11}/>{item.votes.selected}</button>
                                            <button title="Nezařazovat" onClick={() => run(`vote-${item.id}`, () => axios.put(`/api/v1/curation-boards/${data.board!.uuid}/items/${item.id}/vote`, { is_selected: false }), 'Hlas byl uložen.')} disabled={busy !== null} className={`flex items-center gap-1 rounded px-1.5 py-1 text-[10px] ${item.votes.my_vote === false ? 'bg-rose-500/25 text-rose-200' : 'text-[var(--color-text-secondary)] hover:bg-white/10'}`}><ThumbsDown size={11}/>{item.votes.not_selected}</button>
                                        </div>
                                    ) : <p className="truncate p-1.5 text-[10px] text-[var(--color-text-secondary)]">Skóre {item.score}</p>}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}
