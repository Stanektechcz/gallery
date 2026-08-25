import { pocet } from '@/lib/cestina';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Bot, Check, ChevronDown, Compass, Gift, ImagePlus, LoaderCircle, Search, Send, Sparkles, Trash2, X } from 'lucide-react';
import UploadZone from '@/Components/UploadZone';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { flushAssistantActions, pendingAssistantActionCount, queueAssistantAction } from '@/lib/assistantActionQueue';

/** A match offered by the movie database for a title detected in the message. */
type TitleCandidate = { external_id: string | null; title: string | null; media_type: string; release_year?: number | null; poster_url?: string | null; overview?: string | null };
type Plan = {
    date: string;
    activity_date: string;
    activities: string[];
    titles: { title: string; type: string; candidates?: TitleCandidate[] }[];
    recipe: string;
    recipe_details?: { ingredients:string[]; steps:string[]; servings?:number|null; prep_minutes?:number|null; cook_minutes?:number|null; source_url?:string|null; notes?:string|null };
    expense?: { amount: number; currency: string } | null;
    trip?: { name: string; start_date: string; end_date: string; waypoints: string[] } | null;
    gift?: { title: string; occasion?: string | null; due_date?: string | null; budget?: number | null } | null;
    milestone?: { title: string; occurred_on: string } | null;
    todo?: { title: string; items?: string[]; due_at?: string | null; priority: string; kind: string } | null;
    itinerary?: { trip_name: string; items: string[] } | null;
    tags?: string[];
    search?: string | null;
    warnings?: string[];
    clarification?: { kind: string; question: string } | null;
};
type ActionKey = 'activities' | 'titles' | 'recipe' | 'expense' | 'trip' | 'gift' | 'milestone' | 'todo' | 'itinerary';
type Message = { role: 'assistant' | 'user'; text: string; plan?: Plan; source?: string; mediaUuids?: string[]; applied?: boolean; syncState?: 'queued' | 'synced'; requestId?: string; titleChoices?: Record<string, string> };
const initialMessages: Message[] = [{ role: 'assistant', text: 'Ahoj, připravím zápis do společného systému a vždy nejdřív ukážu náhled. Můžete psát přirozeně, nebo použít rychlé bubliny.' }];
const CHAT_STORAGE_KEY = 'maki-assistant-messages';

const actionOptions = (plan: Plan): Array<{ key:ActionKey; label:string; module:string }> => [
    plan.activities.length ? { key:'activities', label:`Aktivity (${plan.activities.length})`, module:'Kalendář' } : null,
    plan.titles.length ? { key:'titles', label:`Ke zhlédnutí (${plan.titles.length})`, module:'Filmy a seriály' } : null,
    plan.recipe ? { key:'recipe', label:'Recept', module:'Kuchařka' } : null,
    plan.expense ? { key:'expense', label:'Výdaj', module:'Finance' } : null,
    plan.trip ? { key:'trip', label:'Cesta', module:'Cesty' } : null,
    plan.gift ? { key:'gift', label:'Dárek', module:'Dárky a výročí' } : null,
    plan.milestone ? { key:'milestone', label:'Výročí', module:'Dárky a výročí' } : null,
    plan.todo ? { key:'todo', label:plan.todo.kind === 'shopping' ? `Nákup (${plan.todo.items?.length ?? 1})` : 'Úkol', module:'Společné plánování' } : null,
    plan.itinerary ? { key:'itinerary', label:'Itinerář', module:'Cesty' } : null,
].filter(Boolean) as Array<{ key:ActionKey; label:string; module:string }>;

const navigationForCommand = (text: string): { href:string; text:string } | null => {
    const match = text.trim().match(/^\/(dnes|týden|tyden|fotka|místo|misto|otevřít|otevrit)\b\s*(.*)$/iu);
    if (!match) return null;
    const command = match[1].toLocaleLowerCase('cs-CZ');
    const argument = match[2].trim();
    if (command === 'dnes') return { href:'/calendar', text:'Otevírám dnešní kalendář.' };
    if (command === 'týden' || command === 'tyden') return { href:'/weekly', text:'Otevírám náš aktuální týden.' };
    if (command === 'fotka') return { href:'/timeline', text:'Otevírám nahrávání a časovou osu fotografií.' };
    if (command === 'místo' || command === 'misto') return { href:`/search?q=${encodeURIComponent(argument || 'místa')}`, text:`Hledám ${argument || 'místa'} v systému.` };
    const target = argument.toLocaleLowerCase('cs-CZ');
    const routes: Array<[string[], string, string]> = [
        [['kalendář', 'kalendar'], '/calendar', 'Otevírám kalendář.'],
        [['týden', 'tyden'], '/weekly', 'Otevírám náš týden.'],
        [['cesty', 'cesta'], '/trips', 'Otevírám cesty.'],
        [['recepty', 'kuchařka', 'kucharka'], '/recipes', 'Otevírám kuchařku.'],
        [['filmy', 'seriály', 'serialy'], '/watchlist', 'Otevírám filmy a seriály.'],
        [['plánování', 'planovani', 'úkoly', 'ukoly', 'nákup', 'nakup'], '/planning', 'Otevírám společné plánování.'],
        [['finance', 'rozpočet', 'rozpocet'], '/finances', 'Otevírám finance.'],
    ];
    const resolved = routes.find(([aliases]) => aliases.includes(target));
    return resolved ? { href:resolved[1], text:resolved[2] } : null;
};
const prompts = [
    { label: 'Hledat', icon: Search, value: '/hledat ' },
    { label: 'Film', icon: Sparkles, value: '/film ' },
    { label: 'Seriál', icon: Sparkles, value: '/seriál ' },
    { label: 'Recept', icon: Sparkles, value: '/recept ' },
    { label: 'Cesta', icon: Compass, value: '/cesta Název | 2026-08-10 | 2026-08-14 | místo 1, místo 2' },
    { label: 'Dárek', icon: Gift, value: '/dárek Název dárku | příležitost | 2026-12-01 | 1500' },
    { label: 'Výročí', icon: Sparkles, value: '/výročí Název výročí | 2020-08-17 | poznámka' },
    { label: 'Itinerář', icon: Compass, value: '/itinerář Název cesty | bod 1, bod 2, bod 3' },
    { label: 'Úkol', icon: Check, value: '/úkol Název úkolu | 2026-08-10 | normal' },
    { label: 'Nákup', icon: Gift, value: '/nákup Káva, mléko, pečivo | 2026-08-10 | normal' },
];

const commandHints = [
    { command: '/hledat', description: 'Otevře hledání v celém systému.', value: '/hledat ', example: '/hledat naše výlety' },
    { command: '/cesta', description: 'Založí cestu, kalendář a přípravy.', value: '/cesta Název | 2026-08-10 | 2026-08-14 | místo 1, místo 2', example: '/cesta Vídeň | 2026-08-10 | 2026-08-14 | Stephansplatz, Prater' },
    { command: '/itinerář', description: 'Přidá body k uložené cestě.', value: '/itinerář Název cesty | bod 1, bod 2, bod 3', example: '/itinerář Vídeň | snídaně, muzeum, večeře' },
    { command: '/dárek', description: 'Uloží nápad včetně termínu a rozpočtu.', value: '/dárek Název dárku | příležitost | 2026-12-01 | 1500', example: '/dárek Kniha | narozeniny | 2026-12-01 | 500' },
    { command: '/výročí', description: 'Založí společný milník s připomínkou.', value: '/výročí Název výročí | 2020-08-17 | poznámka', example: '/výročí První rande | 2020-08-17 | naše kavárna' },
    { command: '/film', description: 'Přidá film do společného seznamu.', value: '/film ', example: '/film Duna: Část druhá' },
    { command: '/seriál', description: 'Přidá seriál do společného seznamu.', value: '/seriál ', example: '/seriál The Bear' },
    { command: '/recept', description: 'Založí recept v kuchařce.', value: '/recept ', example: '/recept Těstoviny s rajčaty' },
    { command: '/úkol', description: 'Vytvoří společný úkol s termínem.', value: '/úkol Název úkolu | 2026-08-10 | normal', example: '/úkol Objednat dárek | 2026-08-10 | high' },
    { command: '/nákup', description: 'Vytvoří jednu či více položek společného nákupu.', value: '/nákup Káva, mléko, pečivo | 2026-08-10 | normal', example: '/nákup Káva, mléko, pečivo | 2026-08-10 | normal' },
    { command: '/přidat', description: 'Zapíše přirozeně popsanou položku.', value: '/přidat ', example: '/přidat nákup: Káva, mléko' },
    { command: '/dnes', description: 'Otevře dnešní kalendář.', value: '/dnes', example: '/dnes' },
    { command: '/týden', description: 'Otevře aktuální týden.', value: '/týden', example: '/týden' },
    { command: '/otevřít', description: 'Přejde do vybraného modulu.', value: '/otevřít ', example: '/otevřít kuchařka' },
    { command: '/místo', description: 'Vyhledá místo nebo podnik.', value: '/místo ', example: '/místo naše kavárna' },
    { command: '/fotka', description: 'Otevře nahrávání fotografií.', value: '/fotka', example: '/fotka' },
    { command: '/pomoc', description: 'Ukáže stručnou nápovědu.', value: '/pomoc', example: '/pomoc' },
];

function planSummary(plan: Plan): string {
    const recipeMeta = [plan.recipe_details?.servings ? pocet(plan.recipe_details.servings, 'porce', 'porce', 'porcí') : '', plan.recipe_details?.prep_minutes ? `příprava ${plan.recipe_details.prep_minutes} min` : '', plan.recipe_details?.cook_minutes ? `vaření ${plan.recipe_details.cook_minutes} min` : '', plan.recipe_details?.source_url ? 'zdroj uložen' : ''].filter(Boolean);
    const details = [
        plan.activities.length ? `Aktivity: ${plan.activities.join(', ')}.` : '',
        plan.titles.length ? `Ke zhlédnutí: ${plan.titles.map((item) => item.title).join(', ')}.` : '',
        plan.recipe ? `Recept: ${plan.recipe}${recipeMeta.length ? ` · ${recipeMeta.join(' · ')}` : ''}.` : '',
        plan.trip ? `Cesta: ${plan.trip.name} (${plan.trip.start_date} až ${plan.trip.end_date})${plan.trip.waypoints.length ? ` · ${plan.trip.waypoints.join(' → ')}` : ''}.` : '',
        plan.gift ? `Dárek: ${plan.gift.title}${plan.gift.occasion ? ` · ${plan.gift.occasion}` : ''}.` : '',
        plan.milestone ? `Výročí: ${plan.milestone.title} (${plan.milestone.occurred_on}).` : '',
        plan.todo ? `${plan.todo.kind === 'shopping' ? 'Nákup' : 'Úkol'}: ${(plan.todo.items?.length ? plan.todo.items.join(', ') : plan.todo.title)}${plan.todo.due_at ? ` (${plan.todo.due_at})` : ''}.` : '',
        plan.itinerary ? `Itinerář pro ${plan.itinerary.trip_name}: ${plan.itinerary.items.join(' → ')}.` : '',
        plan.expense ? `Výdaj: ${new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: plan.expense.currency }).format(plan.expense.amount)}.` : '',
        plan.tags?.length ? `Štítky: ${plan.tags.map(tag => `#${tag}`).join(', ')}.` : '',
        plan.clarification ? plan.clarification.question : '',
        ...((plan.warnings ?? []).map((warning) => `Poznámka: ${warning}`)),
    ].filter(Boolean);
    return details.length ? details.join(' ') : 'Této zprávě zatím nerozumím jako zápisu. Zkuste popsat aktivitu nebo použijte bubliny níže.';
}

export default function WorkspaceAssistant() {
    const [open, setOpen] = useState(false);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [pendingSync, setPendingSync] = useState(0);
    const [showAttachments, setShowAttachments] = useState(false);
    const [attachmentUuids, setAttachmentUuids] = useState<string[]>([]);
    const [selectedActions, setSelectedActions] = useState<Record<number, ActionKey[]>>({});
    const [recentCommands, setRecentCommands] = useState<string[]>(() => {
        try { return JSON.parse(window.localStorage.getItem('maki-assistant-recent-commands') ?? '[]'); } catch { return []; }
    });
    const [messages, setMessages] = useState<Message[]>(() => {
        if (typeof window === 'undefined') return initialMessages;
        try {
            const saved = JSON.parse(window.localStorage.getItem(CHAT_STORAGE_KEY) ?? '[]');
            return Array.isArray(saved) && saved.length ? saved.slice(-40) : initialMessages;
        } catch { return initialMessages; }
    });
    const end = useRef<HTMLDivElement>(null);
    useEffect(() => {
        try { window.localStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(messages.slice(-40))); } catch { /* Storage is optional. */ }
    }, [messages]);
    useEffect(() => {
        const openAssistant = (event: Event) => {
            const text = (event as CustomEvent<{ prefill?: string }>).detail?.prefill;
            if (text) setInput(text);
            setOpen(true);
        };
        const closeAssistant = () => setOpen(false);
        window.addEventListener('maki:assistant-open', openAssistant);
        window.addEventListener('maki:assistant-close', closeAssistant);
        return () => {
            window.removeEventListener('maki:assistant-open', openAssistant);
            window.removeEventListener('maki:assistant-close', closeAssistant);
        };
    }, []);
    useEffect(() => {
        const url = new URL(window.location.href);
        if (url.searchParams.get('assistant') === '1') {
            setOpen(true);
            url.searchParams.delete('assistant');
            window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
        }
    }, []);
    useEffect(() => {
        const sync = async () => {
            setPendingSync(await pendingAssistantActionCount());
            const synced = await flushAssistantActions((created, actionId) => setMessages((value) => [...value.map((message) => message.requestId === actionId ? { ...message, syncState: 'synced' as const } : message), { role: 'assistant', text: `Offline zápis synchronizován: ${created.join(' · ')}.` }]));
            if (synced) setPendingSync(await pendingAssistantActionCount());
        };
        void sync();
        window.addEventListener('online', sync);
        return () => window.removeEventListener('online', sync);
    }, []);
    const rememberCommand = (command:string) => {
        if (!commandHints.some((hint) => hint.command === command)) return;
        setRecentCommands((current) => {
            const next = [command, ...current.filter((item) => item !== command)].slice(0, 5);
            window.localStorage.setItem('maki-assistant-recent-commands', JSON.stringify(next));
            return next;
        });
    };
    const chooseCommand = (hint: typeof commandHints[number]) => {
        rememberCommand(hint.command);
        setInput(hint.value);
    };
    const commandQuery = input.startsWith('/') ? input.trim().split(/\s/, 1)[0].toLowerCase() : '';
    const matchingCommands = commandQuery
        ? commandHints.filter((hint) => hint.command.includes(commandQuery) || hint.description.toLowerCase().includes(commandQuery.slice(1)))
            .sort((left, right) => (recentCommands.indexOf(left.command) === -1 ? 99 : recentCommands.indexOf(left.command)) - (recentCommands.indexOf(right.command) === -1 ? 99 : recentCommands.indexOf(right.command))).slice(0, 7)
        : [];

    const preview = async (event: FormEvent) => {
        event.preventDefault();
        const text = input.trim() || (attachmentUuids.length ? 'Fotky z chatu' : '');
        if (!text || loading) return;
        if (text.startsWith('/')) rememberCommand(text.split(/\s/, 1)[0].toLowerCase());
        const attached = attachmentUuids;
        setInput('');
        setAttachmentUuids([]);
        setShowAttachments(false);
        setMessages((value) => [...value, { role: 'user', text: attached.length ? text + ' · ' + attached.length + ' přiložených fotek' : text }]);
        if (text.toLocaleLowerCase('cs-CZ') === '/pomoc') {
            setMessages((value) => [...value, { role: 'assistant', text:'Můžete zapisovat přirozeně nebo použít příkazy. Pro zápis: /přidat, /cesta, /itinerář, /film, /seriál, /recept, /dárek, /výročí, /úkol a /nákup. Pro rychlý pohyb: /dnes, /týden, /otevřít kuchařka, /místo Název a /fotka. Každý zápis nejdřív zkontrolujete v náhledu.' }]);
            return;
        }
        const navigation = navigationForCommand(text);
        if (navigation) {
            setMessages((value) => [...value, { role: 'assistant', text:navigation.text }]);
            router.visit(navigation.href);
            return;
        }
        const source = text.replace(/^\/přidat\s+/iu, '').trim();
        if (!source) {
            setMessages((value) => [...value, { role: 'assistant', text:'Za /přidat napište, co chcete uložit, například „/přidat nákup: Káva, mléko“.' }]);
            return;
        }
        setLoading(true);
        try {
            const response = await axios.post('/api/v1/assistant/preview', { message: source });
            const plan = response.data as Plan;
            if (plan.search) {
                setMessages((value) => [...value, { role: 'assistant', text: `Otevírám hledání pro „${plan.search}“.`, plan, source }]);
                router.visit(`/search?q=${encodeURIComponent(plan.search)}`);
                return;
            }
            setMessages((value) => [...value, { role: 'assistant', text: attached.length ? planSummary(plan) + ' Fotky připojím po potvrzení do společného alba této události.' : planSummary(plan), plan, source, mediaUuids: attached }]);
        } catch (error: any) {
            setMessages((value) => [...value, { role: 'assistant', text: error.response?.data?.message ?? 'Náhled se nepodařilo vytvořit.' }]);
        } finally {
            setLoading(false);
            setTimeout(() => end.current?.scrollIntoView({ behavior: 'smooth' }), 20);
        }
    };
    const apply = async (message: Message, selected: ActionKey[]) => {
        if (!message.source || message.applied) return;
        const requestId = crypto.randomUUID();
        const markApplied = (syncState: 'queued' | 'synced') => setMessages((value) => value.map((item) => item === message ? { ...item, applied: true, syncState, requestId } : item));
        const queueForLater = async () => {
            await queueAssistantAction({ id: requestId, message: message.source!, createdAt: new Date().toISOString(), selectedActions: selected });
            markApplied('queued');
            setPendingSync(await pendingAssistantActionCount());
            setMessages((value) => [...value, { role: 'assistant', text: 'Jste offline. Potvrzený zápis je bezpečně uložen v zařízení a odešle se po návratu připojení.' }]);
        };
        setSaving(true);
        try {
            if (!navigator.onLine) {
                if (message.mediaUuids?.length) { setMessages((value) => [...value, { role: 'assistant', text: 'Přiložené fotky vyžadují připojení, aby se bezpečně spojily s albem. Zápis zatím nebyl potvrzen.' }]); return; }
                await queueForLater();
                return;
            }
            // 'manual' keeps the plain name; anything else is the chosen movie-database entry.
            const titleChoices = Object.entries(message.titleChoices ?? {}).map(([title, choice]) => ({
                title,
                external_id: choice === 'manual' ? null : choice,
                media_type: message.plan?.titles.find(item => item.title === title)?.type ?? 'movie',
            }));
            const response = await axios.post('/api/v1/assistant/apply', { message: message.source, request_id: requestId, selected_actions: selected, media_uuids: message.mediaUuids ?? [], title_choices: titleChoices });
            const created = (response.data.created as string[]).join(' · ');
            markApplied('synced');
            setMessages((value) => [...value, { role: 'assistant', text: `Hotovo. Uloženo: ${created}.` }]);
        } catch (error: any) {
            if (!navigator.onLine) await queueForLater();
            else setMessages((value) => [...value, { role: 'assistant', text: error.response?.data?.message ?? 'Zápis se nepodařilo uložit.' }]);
        } finally {
            setSaving(false);
            setTimeout(() => end.current?.scrollIntoView({ behavior: 'smooth' }), 20);
        }
    };

    const clearChat = () => {
        setMessages(initialMessages);
        setSelectedActions({});
        try { window.localStorage.removeItem(CHAT_STORAGE_KEY); } catch { /* Storage is optional. */ }
    };
    return (
        <div className="fixed bottom-[4.5rem] right-4 z-[700] md:bottom-5 md:right-5">
            {open && <section aria-label="Maki pomocník" className="mb-3 flex h-[min(640px,74dvh)] w-[min(410px,calc(100vw-2rem))] flex-col overflow-hidden rounded-3xl border border-violet-400/30 bg-[var(--color-bg-secondary)] shadow-2xl shadow-black/50">
                <header className="flex items-center gap-3 border-b border-[var(--color-border)] bg-gradient-to-r from-violet-500/15 to-pink-500/10 p-4">
                    <span className="grid h-10 w-10 place-items-center rounded-2xl bg-violet-500 text-white"><Sparkles size={18} /></span>
                    <div className="min-w-0 flex-1"><h2 className="font-semibold text-[var(--color-text-primary)]">Maki pomocník</h2><p className="text-xs text-violet-100">Lokální asistent · vždy s potvrzením</p></div>
                    <button type="button" onClick={clearChat} aria-label="Vyčistit historii chatu" title="Vyčistit historii chatu" className="rounded-xl p-2 text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"><Trash2 size={16} /></button><button onClick={() => setOpen(false)} aria-label="Zavřít chat" className="rounded-xl p-2 text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"><X size={18} /></button>
                </header>
                <div className="border-b border-[var(--color-border)] p-3">
                    <div className="flex gap-2 overflow-x-auto pb-1" aria-label="Rychlé příkazy">
                        {prompts.map(({ label, icon: Icon, value }) => <button key={label} type="button" onClick={() => setInput(value)} className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-violet-400/30 bg-violet-500/10 px-3 py-1.5 text-xs text-violet-100 hover:bg-violet-500/20"><Icon size={13} />{label}</button>)}
                    </div>
                </div>
                <div className="flex-1 space-y-3 overflow-y-auto p-3">
                    {messages.map((message, index) => {
                        const options = message.plan ? actionOptions(message.plan) : [];
                        const selected = selectedActions[index] ?? options.map((option) => option.key);
                        const hasMedia = Boolean(message.mediaUuids?.length);
                        const canApply = Boolean(!message.applied && message.source && ((selected.length && options.length) || hasMedia));
                        return <article key={index} className={`max-w-[94%] rounded-2xl p-3 text-sm ${message.role === 'user' ? 'ml-auto bg-violet-600 text-white' : 'bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]'}`}>
                        <p>{message.text}</p>
                        {message.plan && !message.plan.search && !message.applied && (options.length > 0 || hasMedia) && <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                            {hasMedia && <p className="mb-2 rounded-lg bg-violet-500/10 px-2 py-1.5 text-[10px] text-violet-100">{message.mediaUuids?.length} přiložených fotek → společné album</p>}{options.length > 1 && <div className="mb-3"><p className="mb-2 text-[10px] font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Co chcete uložit?</p><div className="flex flex-wrap gap-1.5">{options.map((option) => <button key={option.key} type="button" aria-pressed={selected.includes(option.key)} onClick={() => setSelectedActions((current) => { const currentSelection = current[index] ?? options.map((item) => item.key); const next = currentSelection.includes(option.key) ? currentSelection.filter((item) => item !== option.key) : [...currentSelection, option.key]; return { ...current, [index]: next }; })} className={`min-h-8 rounded-lg border px-2.5 text-[10px] ${selected.includes(option.key) ? 'border-emerald-300/45 bg-emerald-500/15 text-emerald-100' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}><span>{selected.includes(option.key) ? '✓ ' : ''}{option.label}</span><span className="opacity-65">→ {option.module}</span></button>)}</div></div>}
                            {selected.includes('titles') && message.plan.titles.some(item => item.candidates?.length) && <div className="mb-3">
                                <p className="mb-2 text-[10px] font-medium uppercase tracking-wide text-[var(--color-text-secondary)]">Který film to je?</p>
                                {message.plan.titles.filter(item => item.candidates?.length).map(item => {
                                    const chosen = message.titleChoices?.[item.title] ?? item.candidates![0].external_id ?? 'manual';
                                    const choose = (value: string) => setMessages(current => current.map(entry => entry === message
                                        ? { ...entry, titleChoices: { ...(entry.titleChoices ?? {}), [item.title]: value } }
                                        : entry));
                                    return <div key={item.title} className="mb-2">
                                        <p className="mb-1 text-[10px] text-[var(--color-text-secondary)]">„{item.title}"</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {item.candidates!.map(candidate => <button key={candidate.external_id ?? candidate.title} type="button" aria-pressed={chosen === candidate.external_id} onClick={() => choose(candidate.external_id ?? 'manual')} className={`flex min-h-9 items-center gap-1.5 rounded-lg border px-2 text-[10px] ${chosen === candidate.external_id ? 'border-violet-300/50 bg-violet-500/20 text-violet-50' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}>
                                                {candidate.poster_url && <img src={candidate.poster_url} alt="" loading="lazy" className="h-8 w-6 rounded object-cover"/>}
                                                <span className="max-w-40 truncate">{candidate.title}{candidate.release_year ? ` (${candidate.release_year})` : ''}</span>
                                            </button>)}
                                            <button type="button" aria-pressed={chosen === 'manual'} onClick={() => choose('manual')} className={`min-h-9 rounded-lg border px-2 text-[10px] ${chosen === 'manual' ? 'border-white/50 bg-[var(--color-surface-muted)] text-[var(--color-text-primary)]' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}>Jen název</button>
                                        </div>
                                    </div>;
                                })}
                            </div>}
                            <button disabled={saving || !canApply} onClick={() => apply(message, selected)} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-emerald-500 px-3 text-xs font-medium text-white disabled:opacity-40">
                                {saving ? <LoaderCircle size={14} className="animate-spin" /> : <Check size={14} />} Potvrdit vybrané a uložit
                            </button>
                        </div>}
                        {message.applied && <p className={`mt-3 text-xs ${message.syncState === 'queued' ? 'text-amber-200' : 'text-emerald-200'}`}>{message.syncState === 'queued' ? '⌛ Uloženo v zařízení · čeká na synchronizaci' : '✓ Zápis je uložený a znovu se neodešle.'}</p>}                    </article>;
                    })}
                    {loading && <div className="inline-flex items-center gap-2 rounded-2xl bg-[var(--color-surface-muted)] p-3 text-xs text-[var(--color-text-secondary)]"><LoaderCircle size={14} className="animate-spin" /> Připravuji náhled…</div>}
                    <div ref={end} />
                </div>
                <form onSubmit={preview} className="border-t border-[var(--color-border)] p-3">
                    {showAttachments && <div className="mb-2 rounded-xl border border-violet-400/25 bg-[var(--color-surface-muted)] p-2"><UploadZone albumId={null} onUploadComplete={(uuids) => setAttachmentUuids(current => Array.from(new Set([...current, ...uuids])))} /><p className="mt-2 text-[10px] text-violet-100">Nahrané fotky se po potvrzení připojí k albu dané události. Vybráno: {attachmentUuids.length}.</p></div>}
                    <div className="flex gap-2"><textarea value={input} onChange={(event) => setInput(event.target.value)} rows={2} placeholder="Popište, co se stalo nebo naplánujte…" className="min-h-11 flex-1 resize-none rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-[var(--color-text-primary)] placeholder:text-[var(--color-text-secondary)]" /><button type="button" onClick={() => setShowAttachments(value => !value)} aria-label="Přiložit fotografie" title="Přiložit fotografie" className={`grid h-11 w-11 place-items-center rounded-xl border ${showAttachments || attachmentUuids.length ? 'border-violet-300 bg-violet-500/20 text-violet-100' : 'border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}><ImagePlus size={16}/></button><button disabled={(!input.trim() && !attachmentUuids.length) || loading} aria-label="Odeslat" className="grid h-11 w-11 place-items-center rounded-xl bg-violet-500 text-white disabled:opacity-40"><Send size={16} /></button></div>
                    {matchingCommands.length > 0 && <div className="mt-2 overflow-hidden rounded-xl border border-violet-400/25 bg-[var(--color-surface-muted)]"><div className="flex items-center justify-between border-b border-[var(--color-border)] px-3 py-1.5 text-[10px] text-[var(--color-text-secondary)]"><span>Katalog příkazů</span>{recentCommands.length > 0 && <span>Nejčastější nahoře</span>}</div>{matchingCommands.map((hint) => <button key={hint.command} type="button" onClick={() => chooseCommand(hint)} className="flex w-full gap-3 border-b border-[var(--color-border)] px-3 py-2 text-left text-xs last:border-0 hover:bg-violet-500/10"><code className="shrink-0 font-semibold text-violet-200">{hint.command}</code><span className="min-w-0"><span className="block text-[var(--color-text-primary)]">{hint.description}</span><span className="mt-0.5 block truncate text-[10px] text-[var(--color-text-secondary)]">{hint.example}</span></span></button>)}</div>}
                    {pendingSync > 0 && <p className="mt-2 rounded-lg bg-amber-500/10 px-2 py-1.5 text-[10px] text-amber-100">Čeká na synchronizaci: {pendingSync} {pendingSync === 1 ? 'zápis' : 'zápisy'}.</p>}
                    <p className="mt-2 text-[10px] text-[var(--color-text-secondary)]">/hledat · /film · /seriál · /recept · /cesta · /dárek · /výročí · /úkol · /pomoc</p>
                </form>
            </section>}
            <button onClick={() => setOpen((value) => !value)} aria-expanded={open} aria-label="Otevřít Maki pomocníka" className="grid h-14 w-14 place-items-center rounded-full bg-violet-600 text-white shadow-lg shadow-violet-950/60 transition hover:scale-105">{open ? <ChevronDown size={23} /> : <Bot size={23} />}</button>
        </div>
    );
}