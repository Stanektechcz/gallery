import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    CalendarCheck,
    CalendarPlus,
    Check,
    ChevronDown,
    ChevronUp,
    CircleAlert,
    Clapperboard,
    Clock3,
    ExternalLink,
    Film,
    Filter,
    Heart,
    ListFilter,
    LoaderCircle,
    MessageSquareText,
    Plus,
    RefreshCw,
    Search,
    Sparkles,
    Star,
    TicketCheck,
    Tv,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Member = { id: number; name: string };
type Vote = { user: Member; interest: number; cinema_preferred: boolean; note?: string | null };
type Proposal = {
    uuid: string;
    starts_at: string;
    venue: 'home' | 'cinema' | 'other';
    place_name?: string | null;
    note?: string | null;
    status: string;
    booking_url?: string | null;
    votes?: Record<string, number>;
    my_response?: string | null;
    event_uuid?: string | null;
};
type Review = {
    uuid?: string | null;
    user: Member;
    watched_at?: string | null;
    venue?: string | null;
    rating: number;
    story_rating?: number | null;
    acting_rating?: number | null;
    visual_rating?: number | null;
    sound_rating?: number | null;
    emotion_rating?: number | null;
    pace_rating?: number | null;
    recommendation?: 'yes' | 'maybe' | 'no' | null;
    review?: string | null;
    favorite_moment?: string | null;
    watch_again: boolean;
    session_note?: string | null;
};
type Title = {
    uuid: string;
    media_type: 'movie' | 'series';
    title: string;
    original_title?: string | null;
    overview?: string | null;
    poster_url?: string | null;
    backdrop_url?: string | null;
    trailer_url?: string | null;
    release_year?: number | null;
    runtime_minutes?: number | null;
    seasons_count?: number | null;
    genres?: string[];
    status: string;
    priority: string;
    watch_provider?: string | null;
    created_at?: string;
    joint_score?: number | null;
    votes: Vote[];
    my_vote?: Vote | null;
    proposals: Proposal[];
    reviews: Review[];
    review_summary?: Record<string, number | null> & { count?: number };
};
type Showing = {
    uuid: string;
    external_film_id?: string | null;
    title: string;
    release_year?: number | null;
    starts_at: string;
    poster_url?: string | null;
    runtime_minutes?: number | null;
    auditorium?: string | null;
    format?: string | null;
    original_language?: string | null;
    dubbed_language?: string | null;
    subtitles_language?: string | null;
    sold_out: boolean;
    availability_ratio?: number | null;
    booking_url?: string | null;
    source_url: string;
};
type SearchResult = Partial<Title> & {
    title: string;
    media_type: 'movie' | 'series';
    external_source?: string;
    external_id?: string;
    already_added?: boolean;
};
type Data = {
    titles: Title[];
    members: Member[];
    cinema: { name: string; source_url: string; showings: Showing[]; last_sync?: { status?: string; finished_at?: string; created_at?: string; last_error?: string } | null };
    integrations: { tmdb_configured: boolean };
    summary: Record<string, number>;
};
type PreferenceDraft = { interest: number; cinema_preferred: boolean; note: string };
type ReviewDraft = {
    watched_at: string;
    venue: 'home' | 'cinema' | 'other';
    rating: number;
    story_rating: string;
    acting_rating: string;
    visual_rating: string;
    sound_rating: string;
    emotion_rating: string;
    pace_rating: string;
    recommendation: 'yes' | 'maybe' | 'no';
    review: string;
    favorite_moment: string;
    note: string;
    watch_again: boolean;
};

const field = 'min-h-11 rounded-xl border border-[var(--color-border)] bg-black/15 px-3 text-sm text-white outline-none placeholder:text-[var(--color-text-secondary)] focus:border-violet-400/60';
const card = 'rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]';
const dayKey = (value: string) => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Prague', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(value));
const time = (value: string) => new Date(value).toLocaleTimeString('cs-CZ', { timeZone: 'Europe/Prague', hour: '2-digit', minute: '2-digit' });
const dateTime = (value?: string | null) => value ? new Date(value).toLocaleString('cs-CZ', { timeZone: 'Europe/Prague', dateStyle: 'medium', timeStyle: 'short' }) : 'bez termínu';
const todayInput = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
};
const statusLabels: Record<string, string> = { proposed: 'navržené', shortlisted: 've výběru', scheduled: 'naplánované', watching: 'rozkoukané', watched: 'zhlédnuté', paused: 'pozastavené', dropped: 'vyřazené' };
const priorityLabels: Record<string, string> = { urgent: 'musíme vidět', high: 'vysoká', normal: 'běžná', low: 'někdy' };

function emptyReview(): ReviewDraft {
    return { watched_at: todayInput(), venue: 'home', rating: 0, story_rating: '', acting_rating: '', visual_rating: '', sound_rating: '', emotion_rating: '', pace_rating: '', recommendation: 'yes', review: '', favorite_moment: '', note: '', watch_again: false };
}

export default function EntertainmentWorkspace({ spaceId }: { spaceId: number }) {
    const [data, setData] = useState<Data>({ titles: [], members: [], cinema: { name: '', source_url: '', showings: [] }, integrations: { tmdb_configured: false }, summary: {} });
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [manualType, setManualType] = useState<'movie' | 'series'>('movie');
    const [filters, setFilters] = useState({ search: '', type: 'all', status: 'active', priority: 'all', score: 'all', sort: 'score' });
    const [expanded, setExpanded] = useState<string | null>(null);
    const [preferences, setPreferences] = useState<Record<string, PreferenceDraft>>({});
    const [homeSuggestions, setHomeSuggestions] = useState<Record<string, Array<{ starts_at: string; venue: string; label?: string }>>>({});
    const [reviews, setReviews] = useState<Record<string, ReviewDraft>>({});
    const [reviewOpen, setReviewOpen] = useState<string | null>(null);
    const [selectedDay, setSelectedDay] = useState('');
    const [selectedShowings, setSelectedShowings] = useState<Record<string, string>>({});

    const load = async () => {
        try {
            const response = await axios.get('/api/v1/entertainment', { params: { gallery_space_id: spaceId } });
            setData(response.data);
            setError('');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Filmový plán se nepodařilo načíst.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { load(); }, [spaceId]);
    useEffect(() => {
        if (query.trim().length < 2) { setResults([]); return; }
        const timer = window.setTimeout(async () => {
            try {
                const response = await axios.get('/api/v1/entertainment/search', { params: { query: query.trim(), type: manualType === 'series' ? 'tv' : 'movie' } });
                setResults(response.data.results ?? []);
            } catch { setResults([]); }
        }, 300);
        return () => window.clearTimeout(timer);
    }, [query, manualType]);

    const cinemaDays = useMemo(() => {
        const groups = new Map<string, Showing[]>();
        data.cinema.showings.forEach(showing => {
            const key = dayKey(showing.starts_at);
            groups.set(key, [...(groups.get(key) ?? []), showing]);
        });
        return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
    }, [data.cinema.showings]);

    useEffect(() => {
        if (!cinemaDays.length) return;
        if (!selectedDay || !cinemaDays.some(([key]) => key === selectedDay)) setSelectedDay(cinemaDays[0][0]);
    }, [cinemaDays, selectedDay]);

    const visibleTitles = useMemo(() => {
        const activeStatuses = ['proposed', 'shortlisted', 'scheduled', 'watching', 'paused'];
        const normalized = filters.search.trim().toLocaleLowerCase('cs-CZ');
        const filtered = data.titles.filter(title => {
            if (normalized && !`${title.title} ${title.original_title ?? ''} ${(title.genres ?? []).join(' ')}`.toLocaleLowerCase('cs-CZ').includes(normalized)) return false;
            if (filters.type !== 'all' && title.media_type !== filters.type) return false;
            if (filters.status === 'active' && !activeStatuses.includes(title.status)) return false;
            if (!['all', 'active'].includes(filters.status) && title.status !== filters.status) return false;
            if (filters.priority !== 'all' && title.priority !== filters.priority) return false;
            if (filters.score !== 'all' && (title.joint_score ?? 0) < Number(filters.score)) return false;
            return true;
        });
        return [...filtered].sort((a, b) => {
            if (filters.sort === 'title') return a.title.localeCompare(b.title, 'cs');
            if (filters.sort === 'release') return (b.release_year ?? 0) - (a.release_year ?? 0);
            if (filters.sort === 'runtime') return (a.runtime_minutes ?? 9999) - (b.runtime_minutes ?? 9999);
            if (filters.sort === 'newest') return new Date(b.created_at ?? 0).getTime() - new Date(a.created_at ?? 0).getTime();
            return (b.joint_score ?? 0) - (a.joint_score ?? 0) || a.title.localeCompare(b.title, 'cs');
        });
    }, [data.titles, filters]);

    const selectedDayShowings = cinemaDays.find(([key]) => key === selectedDay)?.[1] ?? [];
    const cinemaGroups = useMemo(() => {
        const groups = new Map<string, { key: string; title: string; poster_url?: string | null; runtime_minutes?: number | null; release_year?: number | null; showings: Showing[] }>();
        selectedDayShowings.forEach(showing => {
            const key = showing.external_film_id || showing.title.toLocaleLowerCase('cs-CZ').replace(/\s+/g, '-');
            const current = groups.get(key) ?? { key, title: showing.title, poster_url: showing.poster_url, runtime_minutes: showing.runtime_minutes, release_year: showing.release_year, showings: [] };
            current.showings.push(showing);
            current.showings.sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());
            groups.set(key, current);
        });
        return [...groups.values()].sort((a, b) => a.title.localeCompare(b.title, 'cs'));
    }, [selectedDayShowings]);

    const cinemaProposals = useMemo(() => data.titles.flatMap(title => title.proposals.filter(proposal => proposal.venue === 'cinema' && proposal.status !== 'declined').map(proposal => ({ title, proposal }))).sort((a, b) => new Date(a.proposal.starts_at).getTime() - new Date(b.proposal.starts_at).getTime()), [data.titles]);

    const add = async (item: SearchResult) => {
        setBusy('add'); setError('');
        try {
            await axios.post('/api/v1/entertainment', { gallery_space_id: spaceId, ...item, status: undefined, proposals: undefined, votes: undefined, reviews: undefined, media_type: item.media_type ?? manualType });
            setQuery(''); setResults([]); setNotice(`${item.title} je ve společném watchlistu.`); await load();
        } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Titul se nepodařilo přidat.'); }
        finally { setBusy(null); }
    };

    const addManual = () => query.trim() && add({ title: query.trim(), media_type: manualType, external_source: 'manual' });
    const patchTitle = async (title: Title, payload: Record<string, unknown>) => { setBusy(`title:${title.uuid}`); try { await axios.patch(`/api/v1/entertainment/${title.uuid}`, payload); await load(); } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Změnu se nepodařilo uložit.'); } finally { setBusy(null); } };
    const preference = (title: Title): PreferenceDraft => preferences[title.uuid] ?? { interest: title.my_vote?.interest ?? 3, cinema_preferred: title.my_vote?.cinema_preferred ?? false, note: title.my_vote?.note ?? '' };
    const setPreference = (title: Title, update: Partial<PreferenceDraft>) => setPreferences(current => ({ ...current, [title.uuid]: { ...preference(title), ...update } }));
    const savePreference = async (title: Title) => { setBusy(`vote:${title.uuid}`); try { await axios.put(`/api/v1/entertainment/${title.uuid}/vote`, preference(title)); setNotice(`Tvoje preference pro „${title.title}“ jsou uložené.`); await load(); } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Hodnocení zájmu se nepodařilo uložit.'); } finally { setBusy(null); } };
    const findHomeDates = async (title: Title) => { setBusy(`dates:${title.uuid}`); try { const response = await axios.get(`/api/v1/entertainment/${title.uuid}/date-suggestions`); setHomeSuggestions(current => ({ ...current, [title.uuid]: response.data.home ?? [] })); } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Volné večery se nepodařilo najít.'); } finally { setBusy(null); } };
    const proposeHome = async (title: Title, suggestion: { starts_at: string; venue: string }) => { setBusy(`date:${title.uuid}`); try { await axios.post(`/api/v1/entertainment/${title.uuid}/date-proposals`, suggestion); await load(); } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Termín se nepodařilo navrhnout.'); } finally { setBusy(null); } };
    const voteProposal = async (uuid: string, response: string) => { await axios.put(`/api/v1/entertainment/date-proposals/${uuid}/vote`, { response }); await load(); };
    const selectProposal = async (uuid: string) => { setBusy(`proposal:${uuid}`); try { await axios.post(`/api/v1/entertainment/date-proposals/${uuid}/select`); setNotice('Termín je potvrzený a uložený v kalendáři.'); await load(); } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Termín se nepodařilo potvrdit.'); } finally { setBusy(null); } };
    const reviewDraft = (title: Title) => reviews[title.uuid] ?? emptyReview();
    const setReview = (title: Title, update: Partial<ReviewDraft>) => setReviews(current => ({ ...current, [title.uuid]: { ...reviewDraft(title), ...update } }));
    const recordReview = async (title: Title) => {
        const draft = reviewDraft(title); if (!draft.rating) { setError('Doplňte celkové hodnocení filmu nebo seriálu.'); return; }
        setBusy(`review:${title.uuid}`); setError('');
        try {
            const numeric = (value: string) => value ? Number(value) : null;
            await axios.post(`/api/v1/entertainment/${title.uuid}/sessions`, { ...draft, watched_at: new Date(draft.watched_at).toISOString(), story_rating: numeric(draft.story_rating), acting_rating: numeric(draft.acting_rating), visual_rating: numeric(draft.visual_rating), sound_rating: numeric(draft.sound_rating), emotion_rating: numeric(draft.emotion_rating), pace_rating: numeric(draft.pace_rating) });
            setReviews(current => ({ ...current, [title.uuid]: emptyReview() })); setReviewOpen(null); setNotice(`Zhlédnutí a podrobné hodnocení „${title.title}“ jsou uložené.`); await load();
        } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Zhlédnutí se nepodařilo uložit.'); }
        finally { setBusy(null); }
    };

    const syncCinema = async () => { setBusy('cinema-sync'); setError(''); try { const response = await axios.post('/api/v1/entertainment/cinema/sync', { days: 10 }); await load(); setNotice(`Program byl obnoven: ${response.data.count ?? 0} projekcí.`); if (response.data.warnings?.length) setError(`Část dní nebyla dostupná: ${response.data.warnings[0]}`); } catch (reason: any) { const message = reason?.response?.data?.message ?? 'Program kina se nepodařilo obnovit.'; setError(reason?.response?.data?.reason ? `${message} ${reason.response.data.reason}` : message); } finally { setBusy(null); } };
    const selectedShowing = (group: typeof cinemaGroups[number]) => group.showings.find(showing => showing.uuid === selectedShowings[group.key]) ?? group.showings.find(showing => !showing.sold_out) ?? group.showings[0];
    const proposeShowing = async (showing: Showing) => { setBusy(`showing:${showing.uuid}`); setError(''); try { await axios.post(`/api/v1/entertainment/cinema/showings/${showing.uuid}`, { gallery_space_id: spaceId, propose: true }); await load(); setNotice(`${showing.title} v ${time(showing.starts_at)} je navržený ke společné domluvě.`); window.setTimeout(() => document.getElementById('cinema-planning')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50); } catch (reason: any) { setError(reason?.response?.data?.message ?? 'Projekci se nepodařilo navrhnout.'); } finally { setBusy(null); } };

    if (loading) return <div className="space-y-4"><div className="h-44 animate-pulse rounded-2xl bg-white/5"/><div className="h-96 animate-pulse rounded-2xl bg-white/5"/></div>;

    return <div className="space-y-8">
        {error && <div className="flex items-start gap-2 rounded-xl border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-100"><CircleAlert size={17} className="mt-0.5 shrink-0"/><span>{error}</span><button type="button" onClick={() => setError('')} className="ml-auto text-xs underline">Skrýt</button></div>}
        {notice && <div className="flex items-start gap-2 rounded-xl border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-100"><Check size={17} className="mt-0.5 shrink-0"/><span>{notice}</span><button type="button" onClick={() => setNotice('')} className="ml-auto text-xs underline">Skrýt</button></div>}

        <section className={`${card} overflow-visible p-4 sm:p-5`}>
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div><p className="text-xs font-semibold uppercase tracking-wider text-violet-200">Společný výběr</p><h2 className="mt-1 text-xl font-semibold text-white">Co chceme vidět spolu</h2><p className="mt-1 max-w-3xl text-sm text-[var(--color-text-secondary)]">Preference obou partnerů, vlastní poznámky, domácí termíny a podrobné hodnocení po zhlédnutí.</p></div>
                <div className="grid grid-cols-3 gap-2 text-center text-xs"><Metric value={data.titles.filter(item => item.status !== 'watched').length} label="čeká"/><Metric value={data.summary.scheduled ?? 0} label="naplánováno"/><Metric value={data.titles.filter(item => item.status === 'watched').length} label="zhlédnuto"/></div>
            </div>

            <div className="relative z-30 mt-5">
                <div className="grid gap-2 sm:grid-cols-[140px_minmax(0,1fr)_auto]">
                    <select value={manualType} onChange={event => setManualType(event.target.value as 'movie' | 'series')} className={field}><option value="movie">Film</option><option value="series">Seriál</option></select>
                    <div className="relative"><Search size={17} className="absolute left-3 top-3.5 text-[var(--color-text-secondary)]"/><input value={query} onChange={event => setQuery(event.target.value)} placeholder="Najít film nebo seriál v globální databázi…" className={`${field} w-full pl-10`}/></div>
                    <button type="button" onClick={addManual} disabled={busy === 'add' || query.trim().length < 2} className="min-h-11 rounded-xl border border-violet-400/30 px-4 text-sm text-violet-100 disabled:opacity-40"><Plus size={15} className="mr-1 inline"/>Přidat ručně</button>
                </div>
                {query.trim().length >= 2 && <div className="absolute left-0 right-0 mt-1 max-h-96 overflow-y-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-2 shadow-2xl sm:left-[148px] sm:right-[145px]">{results.map((item, index) => <button type="button" key={`${item.external_id}-${index}`} disabled={item.already_added || busy === 'add'} onClick={() => add(item)} className="flex w-full items-center gap-3 rounded-xl p-2 text-left hover:bg-white/5 disabled:opacity-45">{item.poster_url ? <img src={item.poster_url} alt="" loading="lazy" className="h-16 w-11 rounded-lg object-cover"/> : <span className="grid h-16 w-11 place-items-center rounded-lg bg-violet-500/10"><Film size={17}/></span>}<span className="min-w-0 flex-1"><b className="block truncate text-sm text-white">{item.title}</b><small className="text-[var(--color-text-secondary)]">{item.media_type === 'series' ? 'seriál' : 'film'} {item.release_year ? `· ${item.release_year}` : ''}</small></span>{item.already_added ? <span className="text-xs text-emerald-300">už přidáno</span> : <Plus size={16}/>}</button>)}{!data.integrations.tmdb_configured && <p className="p-3 text-xs text-amber-200">Globální našeptávač vyžaduje TMDB klíč. <Link href="/admin/integrations#tmdb" className="font-medium underline">Otevřít konfiguraci</Link>.</p>}</div>}
            </div>

            <div className="mt-5 rounded-2xl border border-[var(--color-border)] bg-black/10 p-3">
                <div className="mb-3 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-[var(--color-text-secondary)]"><ListFilter size={15}/> Filtry watchlistu <span className="ml-auto normal-case tracking-normal text-white">{visibleTitles.length} výsledků</span></div>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <input value={filters.search} onChange={event => setFilters({ ...filters, search: event.target.value })} placeholder="Hledat v seznamu" className={field}/>
                    <select value={filters.type} onChange={event => setFilters({ ...filters, type: event.target.value })} className={field}><option value="all">Filmy i seriály</option><option value="movie">Jen filmy</option><option value="series">Jen seriály</option></select>
                    <select value={filters.status} onChange={event => setFilters({ ...filters, status: event.target.value })} className={field}><option value="active">Aktivní seznam</option><option value="all">Všechny stavy</option>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
                    <select value={filters.priority} onChange={event => setFilters({ ...filters, priority: event.target.value })} className={field}><option value="all">Všechny priority</option>{Object.entries(priorityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
                    <select value={filters.score} onChange={event => setFilters({ ...filters, score: event.target.value })} className={field}><option value="all">Jakékoliv skóre</option><option value="4">Společně alespoň 4★</option><option value="3">Společně alespoň 3★</option></select>
                    <select value={filters.sort} onChange={event => setFilters({ ...filters, sort: event.target.value })} className={field}><option value="score">Nejvyšší shoda</option><option value="newest">Nejnověji přidané</option><option value="title">Podle názvu</option><option value="release">Nejnovější filmy</option><option value="runtime">Nejkratší první</option></select>
                </div>
            </div>

            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                {visibleTitles.map(title => <WatchCard key={title.uuid} title={title} expanded={expanded === title.uuid} toggle={() => setExpanded(expanded === title.uuid ? null : title.uuid)} busy={busy} preference={preference(title)} setPreference={update => setPreference(title, update)} savePreference={() => savePreference(title)} patchTitle={payload => patchTitle(title, payload)} homeSuggestions={homeSuggestions[title.uuid] ?? []} findHomeDates={() => findHomeDates(title)} proposeHome={suggestion => proposeHome(title, suggestion)} voteProposal={voteProposal} selectProposal={selectProposal} reviewOpen={reviewOpen === title.uuid} toggleReview={() => setReviewOpen(reviewOpen === title.uuid ? null : title.uuid)} review={reviewDraft(title)} setReview={update => setReview(title, update)} recordReview={() => recordReview(title)}/>) }
                {!visibleTitles.length && <div className="rounded-2xl border border-dashed border-[var(--color-border)] p-10 text-center text-sm text-[var(--color-text-secondary)] xl:col-span-2"><Filter size={24} className="mx-auto mb-2 opacity-50"/>Filtrům neodpovídá žádný titul.</div>}
            </div>
        </section>

        <section id="cinema-planning" className={`${card} scroll-mt-5 border-violet-400/25 p-4 sm:p-5`}>
            <div className="flex items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-violet-500/15 text-violet-200"><Users size={19}/></span><div><p className="text-xs font-semibold uppercase tracking-wider text-violet-200">Domluva před rezervací</p><h2 className="mt-1 text-lg font-semibold text-white">Navržené termíny kina</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Vyberte společně konkrétní projekci. Potvrzený termín se uloží do kalendáře oběma partnerům.</p></div></div>
            <div className="mt-4 grid gap-3 lg:grid-cols-2">{cinemaProposals.map(({ title, proposal }) => <ProposalCard key={proposal.uuid} title={title} proposal={proposal} busy={busy === `proposal:${proposal.uuid}`} vote={response => voteProposal(proposal.uuid, response)} select={() => selectProposal(proposal.uuid)}/>)}{!cinemaProposals.length && <p className="rounded-xl border border-dashed border-violet-400/20 p-5 text-center text-sm text-[var(--color-text-secondary)] lg:col-span-2">Zatím není navržená žádná projekce. Vyberte níže den, film a konkrétní čas.</p>}</div>
        </section>

        <section className={`${card} p-4 sm:p-5`}>
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"><div><p className="text-xs font-semibold uppercase tracking-wider text-violet-200">Oficiální program</p><h2 className="mt-1 flex items-center gap-2 text-xl font-semibold text-white"><Clapperboard size={21}/> Cinema City Velký Špalíček</h2><p className="mt-1 text-sm text-[var(--color-text-secondary)]">Každý film je pro vybraný den pouze jednou. Nejprve zvolte čas projekce, poté jej navrhněte nebo rezervujte.</p></div><div className="flex flex-wrap gap-2"><a href={data.cinema.source_url} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-white">Oficiální program <ExternalLink size={13}/></a><button type="button" onClick={syncCinema} disabled={busy !== null} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-violet-400/30 px-3 text-xs text-violet-100 disabled:opacity-40">{busy === 'cinema-sync' ? <LoaderCircle size={14} className="animate-spin"/> : <RefreshCw size={14}/>} Obnovit program</button></div></div>

            <div className="mt-5 flex gap-2 overflow-x-auto pb-2">{cinemaDays.map(([key, showings]) => { const films = new Set(showings.map(item => item.external_film_id || item.title)).size; const date = new Date(`${key}T12:00:00`); const today = dayKey(new Date().toISOString()); const tomorrow = dayKey(new Date(Date.now() + 86400000).toISOString()); const label = key === today ? 'Dnes' : key === tomorrow ? 'Zítra' : date.toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric' }); return <button type="button" key={key} onClick={() => setSelectedDay(key)} className={`min-w-[104px] rounded-xl border px-3 py-2 text-left transition ${selectedDay === key ? 'border-violet-400 bg-violet-500/15 text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-white'}`}><span className="block text-sm font-medium capitalize">{label}</span><span className="text-[10px]">{films} filmů · {showings.length} časů</span></button>; })}</div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">{cinemaGroups.map(group => { const selected = selectedShowing(group); return <article key={group.key} className="overflow-hidden rounded-2xl border border-[var(--color-border)] bg-black/10"><div className="flex gap-3 p-3 sm:p-4">{group.poster_url ? <img src={group.poster_url} alt={`Plakát ${group.title}`} loading="lazy" className="h-36 w-24 shrink-0 rounded-xl object-cover"/> : <span className="grid h-36 w-24 shrink-0 place-items-center rounded-xl bg-violet-500/10"><Film/></span>}<div className="min-w-0 flex-1"><h3 className="font-semibold text-white">{group.title}</h3><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{group.release_year ?? 'rok neuveden'}{group.runtime_minutes ? ` · ${group.runtime_minutes} min` : ''} · {group.showings.length} {group.showings.length === 1 ? 'projekce' : 'projekcí'}</p><p className="mt-3 text-[10px] font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Vyberte čas</p><div className="mt-2 flex flex-wrap gap-2">{group.showings.map(showing => <button type="button" key={showing.uuid} disabled={showing.sold_out} onClick={() => setSelectedShowings(current => ({ ...current, [group.key]: showing.uuid }))} className={`rounded-lg border px-3 py-2 text-xs font-medium ${selected.uuid === showing.uuid ? 'border-violet-400 bg-violet-500/20 text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'} disabled:cursor-not-allowed disabled:line-through disabled:opacity-40`}>{time(showing.starts_at)}</button>)}</div></div></div><div className="border-t border-[var(--color-border)] p-3 sm:p-4"><div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--color-text-secondary)]"><span><Clock3 size={13} className="mr-1 inline"/>{time(selected.starts_at)}{selected.auditorium ? ` · ${selected.auditorium}` : ''}</span>{selected.format && <span>{selected.format}</span>}{selected.dubbed_language && <span>dabing {selected.dubbed_language.toUpperCase()}</span>}{selected.subtitles_language && <span>titulky {selected.subtitles_language.toUpperCase()}</span>}{selected.availability_ratio !== null && selected.availability_ratio !== undefined && <span>{Math.round(selected.availability_ratio * 100)} % míst dostupných</span>}</div><div className="mt-3 grid gap-2 sm:grid-cols-2"><button type="button" onClick={() => proposeShowing(selected)} disabled={busy !== null || selected.sold_out} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-violet-500 px-4 text-sm font-medium text-white disabled:opacity-40">{busy === `showing:${selected.uuid}` ? <LoaderCircle size={15} className="animate-spin"/> : <CalendarPlus size={15}/>} Navrhnout tento čas</button>{selected.booking_url ? <a href={selected.booking_url} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-violet-400/30 px-4 text-center text-sm font-medium text-violet-100">Pokračovat k rezervaci {time(selected.starts_at)} <TicketCheck size={15}/></a> : <span className="grid min-h-11 place-items-center rounded-xl border border-[var(--color-border)] text-xs text-[var(--color-text-secondary)]">Online prodej není dostupný</span>}</div></div></article>; })}{!cinemaGroups.length && <p className="rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center text-sm text-[var(--color-text-secondary)] lg:col-span-2">Pro tento den není zveřejněný žádný program.</p>}</div>
            <p className="mt-4 rounded-xl border border-emerald-400/15 bg-emerald-500/5 p-3 text-[10px] leading-relaxed text-emerald-100/80">Tlačítko rezervace otevře veřejný program Cinema City předfiltrovaný na vybraný den a film. Na jejich stránce potvrďte zvolený čas a pokračujte k sedadlům. Aplikace nepoužívá blokovanou interní doménu tickets.rel.</p>
        </section>

        <p className="text-center text-[10px] leading-relaxed text-[var(--color-text-secondary)]">This product uses the TMDB API but is not endorsed or certified by TMDB.</p>
    </div>;
}

function WatchCard({ title, expanded, toggle, busy, preference, setPreference, savePreference, patchTitle, homeSuggestions, findHomeDates, proposeHome, voteProposal, selectProposal, reviewOpen, toggleReview, review, setReview, recordReview }: {
    title: Title; expanded: boolean; toggle: () => void; busy: string | null; preference: PreferenceDraft; setPreference: (update: Partial<PreferenceDraft>) => void; savePreference: () => void; patchTitle: (payload: Record<string, unknown>) => void; homeSuggestions: Array<{ starts_at: string; venue: string; label?: string }>; findHomeDates: () => void; proposeHome: (suggestion: { starts_at: string; venue: string }) => void; voteProposal: (uuid: string, response: string) => void; selectProposal: (uuid: string) => void; reviewOpen: boolean; toggleReview: () => void; review: ReviewDraft; setReview: (update: Partial<ReviewDraft>) => void; recordReview: () => void;
}) {
    const homeProposals = title.proposals.filter(proposal => proposal.venue !== 'cinema' && proposal.status !== 'declined');
    return <article className={`${card} overflow-hidden`}>
        <div className="flex gap-3 p-3 sm:p-4">{title.poster_url ? <img src={title.poster_url} alt={`Plakát ${title.title}`} loading="lazy" className="h-40 w-28 shrink-0 rounded-xl object-cover"/> : <span className="grid h-40 w-28 shrink-0 place-items-center rounded-xl bg-violet-500/10">{title.media_type === 'series' ? <Tv/> : <Film/>}</span>}<div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h3 className="font-semibold text-white">{title.title}</h3><span className="rounded bg-violet-500/15 px-2 py-0.5 text-[10px] text-violet-100">{title.media_type === 'series' ? 'seriál' : 'film'}</span><span className="rounded bg-white/5 px-2 py-0.5 text-[10px] text-[var(--color-text-secondary)]">{priorityLabels[title.priority] ?? title.priority}</span></div><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{title.release_year ?? 'rok neuveden'}{title.runtime_minutes ? ` · ${title.runtime_minutes} min` : ''}{title.seasons_count ? ` · ${title.seasons_count} sérií` : ''}</p><p className="mt-2 line-clamp-3 text-xs leading-relaxed text-[var(--color-text-secondary)]">{title.overview || 'Bez anotace. Doplňte společné preference a rozhodněte, zda stojí za večer ve dvou.'}</p><div className="mt-3 flex flex-wrap items-center gap-2">{title.joint_score ? <span className="inline-flex items-center gap-1 rounded-lg bg-amber-500/10 px-2 py-1 text-xs text-amber-100"><Star size={13} className="fill-amber-300 text-amber-300"/> shoda {title.joint_score}/5</span> : <span className="rounded-lg bg-white/5 px-2 py-1 text-xs text-[var(--color-text-secondary)]">čeká na hodnocení</span>}{title.review_summary?.rating ? <span className="rounded-lg bg-emerald-500/10 px-2 py-1 text-xs text-emerald-100">po zhlédnutí {title.review_summary.rating}/5</span> : null}</div></div></div>
        <div className="border-t border-[var(--color-border)] px-3 py-2 sm:px-4"><button type="button" onClick={toggle} className="flex w-full items-center justify-between text-left text-xs text-white"><span>{expanded ? 'Skrýt preference a plánování' : 'Preference, domácí termín a hodnocení'}</span>{expanded ? <ChevronUp size={16}/> : <ChevronDown size={16}/>}</button></div>
        {expanded && <div className="space-y-4 border-t border-[var(--color-border)] p-3 sm:p-4">
            <div className="rounded-xl border border-violet-400/20 bg-violet-500/5 p-3"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p className="text-xs font-medium text-white">Moje preference</p><div className="mt-2 flex items-center gap-1">{[1, 2, 3, 4, 5].map(value => <button type="button" key={value} onClick={() => setPreference({ interest: value })} aria-label={`Zájem ${value} z 5`}><Star size={22} className={value <= preference.interest ? 'fill-amber-300 text-amber-300' : 'text-slate-600'}/></button>)}</div></div><label className="flex items-center gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-xs text-white"><input type="checkbox" checked={preference.cinema_preferred} onChange={event => setPreference({ cinema_preferred: event.target.checked })}/> raději v kině</label></div><textarea value={preference.note} onChange={event => setPreference({ note: event.target.value })} placeholder="Proč to chci vidět, očekávání nebo poznámka pro partnera…" rows={2} className={`${field} mt-3 h-auto w-full py-2`}/><button type="button" onClick={savePreference} disabled={busy !== null} className="mt-2 rounded-lg bg-violet-500 px-3 py-2 text-xs font-medium text-white disabled:opacity-40">Uložit moje preference</button><div className="mt-3 space-y-1">{title.votes.map(vote => <p key={vote.user.id} className="text-xs text-[var(--color-text-secondary)]"><b className="text-white">{vote.user.name}</b> · {vote.interest}/5{vote.cinema_preferred ? ' · chce kino' : ''}{vote.note ? ` · ${vote.note}` : ''}</p>)}</div></div>
            <div className="grid gap-2 sm:grid-cols-2"><label className="text-xs text-[var(--color-text-secondary)]">Stav<select value={title.status} onChange={event => patchTitle({ status: event.target.value })} className={`${field} mt-1 w-full`}>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><label className="text-xs text-[var(--color-text-secondary)]">Priorita<select value={title.priority} onChange={event => patchTitle({ priority: event.target.value })} className={`${field} mt-1 w-full`}>{Object.entries(priorityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
            <div className="rounded-xl border border-teal-400/20 bg-teal-500/5 p-3"><div className="flex flex-wrap items-center justify-between gap-2"><div><p className="text-xs font-medium text-white">Domácí filmový večer</p><p className="mt-1 text-[10px] text-[var(--color-text-secondary)]">Kino se vybírá samostatně v programu níže.</p></div><button type="button" onClick={findHomeDates} disabled={busy !== null} className="rounded-lg border border-teal-400/30 px-3 py-2 text-xs text-teal-100"><Sparkles size={13} className="mr-1 inline"/>Najít volné večery</button></div>{homeSuggestions.length > 0 && <div className="mt-3 flex flex-wrap gap-2">{homeSuggestions.map(suggestion => <button type="button" key={suggestion.starts_at} onClick={() => proposeHome(suggestion)} className="rounded-lg bg-teal-500/15 px-3 py-2 text-xs text-teal-100">{suggestion.label ?? dateTime(suggestion.starts_at)}</button>)}</div>}{homeProposals.map(proposal => <div key={proposal.uuid} className="mt-3 flex flex-col gap-2 rounded-lg bg-black/15 p-2 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-xs text-white">{dateTime(proposal.starts_at)} · doma</p><p className="text-[10px] text-[var(--color-text-secondary)]">ano {proposal.votes?.yes ?? 0} · možná {proposal.votes?.maybe ?? 0} · ne {proposal.votes?.no ?? 0}</p></div><ProposalActions proposal={proposal} vote={response => voteProposal(proposal.uuid, response)} select={() => selectProposal(proposal.uuid)}/></div>)}</div>
            <button type="button" onClick={toggleReview} className="flex w-full items-center justify-between rounded-xl border border-emerald-400/20 bg-emerald-500/5 p-3 text-left"><span><b className="block text-xs text-white">Zapsat zhlédnutí a podrobné hodnocení</b><small className="text-[10px] text-[var(--color-text-secondary)]">Příběh, herectví, obraz, zvuk, emoce, tempo a společná vzpomínka</small></span>{reviewOpen ? <ChevronUp size={16}/> : <MessageSquareText size={16}/>}</button>
            {reviewOpen && <ReviewForm title={title} draft={review} setDraft={setReview} save={recordReview} busy={busy === `review:${title.uuid}`}/>} 
            {title.reviews.length > 0 && <div><p className="text-xs font-medium text-white">Naše hodnocení po zhlédnutí</p><div className="mt-2 space-y-2">{title.reviews.map((item, index) => <ReviewCard key={`${item.user.id}-${item.uuid ?? index}`} review={item}/>)}</div></div>}
        </div>}
    </article>;
}

function ReviewForm({ title, draft, setDraft, save, busy }: { title: Title; draft: ReviewDraft; setDraft: (update: Partial<ReviewDraft>) => void; save: () => void; busy: boolean }) {
    const criteria: Array<[keyof ReviewDraft, string]> = [['story_rating', 'Příběh'], ['acting_rating', 'Herectví'], ['visual_rating', 'Obraz'], ['sound_rating', 'Zvuk'], ['emotion_rating', 'Emoce'], ['pace_rating', 'Tempo']];
    return <div className="rounded-xl border border-emerald-400/20 bg-emerald-500/5 p-3"><div className="grid gap-3 sm:grid-cols-2"><label className="text-xs text-[var(--color-text-secondary)]">Kdy jsme sledovali<input type="datetime-local" value={draft.watched_at} max={todayInput()} onChange={event => setDraft({ watched_at: event.target.value })} className={`${field} mt-1 w-full`}/></label><label className="text-xs text-[var(--color-text-secondary)]">Kde<select value={draft.venue} onChange={event => setDraft({ venue: event.target.value as ReviewDraft['venue'] })} className={`${field} mt-1 w-full`}><option value="home">Doma</option><option value="cinema">V kině</option><option value="other">Jinde</option></select></label></div><div className="mt-4"><p className="text-xs text-[var(--color-text-secondary)]">Celkové hodnocení</p><div className="mt-1 flex gap-1">{[1, 2, 3, 4, 5].map(value => <button type="button" key={value} onClick={() => setDraft({ rating: value })}><Star size={24} className={value <= draft.rating ? 'fill-amber-300 text-amber-300' : 'text-slate-600'}/></button>)}</div></div><div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">{criteria.map(([key, label]) => <label key={key} className="text-[10px] text-[var(--color-text-secondary)]">{label}<select value={String(draft[key])} onChange={event => setDraft({ [key]: event.target.value })} className={`${field} mt-1 w-full min-h-9 py-1 text-xs`}><option value="">nehodnotit</option>{[1, 2, 3, 4, 5].map(value => <option key={value} value={value}>{value}/5</option>)}</select></label>)}</div><textarea value={draft.review} onChange={event => setDraft({ review: event.target.value })} placeholder={`Jak na nás „${title.title}“ působilo? Co se povedlo a co ne?`} rows={3} className={`${field} mt-3 h-auto w-full py-2`}/><input value={draft.favorite_moment} onChange={event => setDraft({ favorite_moment: event.target.value })} placeholder="Nejoblíbenější moment nebo scéna" className={`${field} mt-2 w-full`}/><textarea value={draft.note} onChange={event => setDraft({ note: event.target.value })} placeholder="Vzpomínka na společný večer, občerstvení, nálada…" rows={2} className={`${field} mt-2 h-auto w-full py-2`}/><div className="mt-3 grid gap-2 sm:grid-cols-2"><label className="text-xs text-[var(--color-text-secondary)]">Doporučili bychom?<select value={draft.recommendation} onChange={event => setDraft({ recommendation: event.target.value as ReviewDraft['recommendation'] })} className={`${field} mt-1 w-full`}><option value="yes">Ano</option><option value="maybe">Možná</option><option value="no">Ne</option></select></label><label className="flex min-h-11 items-center gap-2 self-end rounded-xl border border-[var(--color-border)] px-3 text-xs text-white"><input type="checkbox" checked={draft.watch_again} onChange={event => setDraft({ watch_again: event.target.checked })}/> Chceme vidět znovu</label></div><button type="button" onClick={save} disabled={busy || !draft.rating} className="mt-3 inline-flex min-h-11 items-center gap-2 rounded-xl bg-emerald-500 px-4 text-sm font-medium text-white disabled:opacity-40">{busy ? <LoaderCircle size={15} className="animate-spin"/> : <Check size={15}/>} Uložit zhlédnutí a hodnocení</button></div>;
}

function ReviewCard({ review }: { review: Review }) {
    const criteria = [['Příběh', review.story_rating], ['Herectví', review.acting_rating], ['Obraz', review.visual_rating], ['Zvuk', review.sound_rating], ['Emoce', review.emotion_rating], ['Tempo', review.pace_rating]].filter(([, value]) => value !== null && value !== undefined);
    return <article className="rounded-xl border border-[var(--color-border)] bg-black/10 p-3"><div className="flex flex-wrap items-center justify-between gap-2"><p className="text-xs font-medium text-white">{review.user.name} · {review.rating}/5</p><p className="text-[10px] text-[var(--color-text-secondary)]">{dateTime(review.watched_at)} · {review.venue === 'cinema' ? 'kino' : review.venue === 'home' ? 'doma' : 'jinde'}</p></div>{criteria.length > 0 && <div className="mt-2 flex flex-wrap gap-1">{criteria.map(([label, value]) => <span key={String(label)} className="rounded bg-white/5 px-2 py-1 text-[10px] text-[var(--color-text-secondary)]">{label} {value}/5</span>)}</div>}{review.review && <p className="mt-2 text-xs leading-relaxed text-slate-300">{review.review}</p>}{review.favorite_moment && <p className="mt-2 text-xs text-amber-100">★ {review.favorite_moment}</p>}<div className="mt-2 flex flex-wrap gap-2 text-[10px] text-[var(--color-text-secondary)]">{review.watch_again && <span>chce vidět znovu</span>}{review.recommendation && <span>doporučení: {review.recommendation === 'yes' ? 'ano' : review.recommendation === 'maybe' ? 'možná' : 'ne'}</span>}</div></article>;
}

function ProposalCard({ title, proposal, busy, vote, select }: { title: Title; proposal: Proposal; busy: boolean; vote: (response: string) => void; select: () => void }) {
    return <article className="rounded-xl border border-violet-400/20 bg-violet-500/5 p-3"><div className="flex gap-3">{title.poster_url && <img src={title.poster_url} alt="" className="h-20 w-14 rounded-lg object-cover"/>}<div className="min-w-0 flex-1"><p className="font-medium text-white">{title.title}</p><p className="mt-1 text-xs text-violet-100">{dateTime(proposal.starts_at)}</p><p className="text-[10px] text-[var(--color-text-secondary)]">{proposal.place_name ?? 'Cinema City Velký Špalíček'} · ano {proposal.votes?.yes ?? 0} · možná {proposal.votes?.maybe ?? 0} · ne {proposal.votes?.no ?? 0}</p></div></div><div className="mt-3 flex flex-wrap gap-2">{proposal.event_uuid ? <Link href={`/calendar/events/${proposal.event_uuid}`} className="inline-flex min-h-9 items-center gap-1 rounded-lg border border-emerald-400/30 px-3 text-xs text-emerald-100"><CalendarCheck size={13}/> V kalendáři</Link> : <><ProposalActions proposal={proposal} vote={vote} select={select}/>{proposal.booking_url && <a href={proposal.booking_url} target="_blank" rel="noreferrer" className="inline-flex min-h-9 items-center gap-1 rounded-lg border border-violet-400/30 px-3 text-xs text-violet-100"><TicketCheck size={13}/> Otevřít veřejný program</a>}</>}{busy && <LoaderCircle size={15} className="animate-spin text-violet-200"/>}</div></article>;
}

function ProposalActions({ proposal, vote, select }: { proposal: Proposal; vote: (response: string) => void; select: () => void }) {
    return <div className="flex flex-wrap gap-1">{(['yes', 'maybe', 'no'] as const).map(response => <button type="button" key={response} onClick={() => vote(response)} className={`min-h-9 rounded-lg border px-2 text-[10px] ${proposal.my_response === response ? 'border-white bg-white/10 text-white' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}>{response === 'yes' ? 'mohu' : response === 'maybe' ? 'možná' : 'nemohu'}</button>)}<button type="button" onClick={select} className="min-h-9 rounded-lg bg-emerald-500 px-3 text-[10px] font-medium text-white">Potvrdit</button></div>;
}

function Metric({ value, label }: { value: number; label: string }) {
    return <div className="min-w-20 rounded-xl border border-[var(--color-border)] bg-black/10 px-3 py-2"><b className="block text-lg text-white">{value}</b><span className="text-[10px] text-[var(--color-text-secondary)]">{label}</span></div>;
}
