import UploadPanel from '@/Components/UploadPanel';
import AppInstallButton from '@/Components/AppInstallButton';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { clsx } from 'clsx';
import {
    Activity,
    Archive,
    BarChart3,
    Bell,
    BookHeart,
    Calendar,
    ChefHat,
    ChevronDown,
    CircleDollarSign,
    Clapperboard,
    Clock,
    FolderOpen,
    Globe,
    Gift,
    Heart,
    Home,
    Images,
    Inbox,
    KeyRound,
    Map,
    MapPin,
    LockKeyhole,
    Menu,
    Monitor,
    Printer,
    Route,
    Search,
    Settings,
    SlidersHorizontal,
    Share2,
    ShieldCheck,
    Sparkles,
    Tag,
    Ticket,
    Trash2,
    Users,
    X
} from 'lucide-react';
import { ReactNode, useCallback, useEffect, useRef, useState } from 'react';

// ─── Command Palette ────────────────────────────────────

type CommandGroup = 'nav' | 'action' | 'search';

interface Command {
    label:    string;
    keywords: string;
    group:    CommandGroup;
    href?:    string;
    action?:  () => void;
    icon?:    string;
    adminOnly?: boolean;
}

const NAV_COMMANDS: Command[] = [
    { group: 'nav', label: 'Domovská stránka',    href: '/',          keywords: 'home domov dashboard' },
    { group: 'nav', label: 'Fotky / Timeline',    href: '/timeline',  keywords: 'fotky timeline chronology' },
    { group: 'nav', label: 'Alba',                href: '/albums',    keywords: 'album folder slozka' },
    { group: 'nav', label: 'Kalendář',            href: '/calendar',  keywords: 'kalendar calendar' },
    { group: 'nav', label: 'Náš týden',           href: '/weekly', keywords: 'tyden plan ukoly prehled' },
    { group: 'nav', label: 'Společné plánování',  href: '/planning', keywords: 'planovani wishlist hlasovani sablony' },
    { group: 'nav', label: 'Finance a Revolut',   href: '/finances', keywords: 'finance revolut penize ucet transakce rozpocty grafy' },
    { group: 'nav', label: 'Watchlist',            href: '/watchlist', keywords: 'film serial kino watchlist program' },
    { group: 'nav', label: 'Generátor randíček',   href: '/date-ideas', keywords: 'randicko rande napad program pro dva' },
    { group: 'nav', label: 'Výroční album',        href: '/anniversary-album', keywords: 'vyroci album fotky rekapitulace vztah' },
    { group: 'nav', label: 'Dárky a výročí',       href: '/gifts-anniversaries', keywords: 'darek vyroci vztah pripominka' },
    { group: 'nav', label: 'Naše kuchařka',       href: '/recipes', keywords: 'recepty vareni jidlo kucharka porce suroviny' },
    { group: 'nav', label: 'Administrace',         href: '/admin', keywords: 'admin sprava system', adminOnly: true },
    { group: 'nav', label: 'Integrace a API klíče', href: '/admin/integrations', keywords: 'api klice gocardless revolut tmdb kino pocasi kurzy doprava administrace', adminOnly: true },
    { group: 'nav', label: 'Mapa',                href: '/map',       keywords: 'map gps lokace' },
    { group: 'nav', label: 'Oblíbené',            href: '/favorites', keywords: 'oblibene heart srdce' },
    { group: 'nav', label: 'Vzpomínky',           href: '/memories',  keywords: 'vzpominky memories' },
    { group: 'nav', label: 'Naše cesta',          href: '/journey',   keywords: 'nase cesta journey kronika' },
    { group: 'nav', label: 'Itinerář světa',      href: '/itinerary', keywords: 'itinerar svet travel' },
    { group: 'nav', label: 'Cesty',               href: '/trips',     keywords: 'cesty trips vylety' },
    { group: 'nav', label: 'Jízdenky',             href: '/tickets',   keywords: 'jizdenky tickets vlak autobus regiojet flixbus cd' },
    { group: 'nav', label: 'Místa a podniky',     href: '/places',    keywords: 'mista podniky restaurace kavarny places lokace hodnoceni' },
    { group: 'nav', label: 'Lidé',                href: '/people',    keywords: 'lide people osoby' },
    { group: 'nav', label: 'Tagy',                href: '/tags',      keywords: 'tagy tags' },
    { group: 'nav', label: 'Porovnání',           href: '/compare',   keywords: 'porovnat compare' },
    { group: 'nav', label: 'Slideshow',           href: '/timeline',  keywords: 'slideshow prezentace' },
    { group: 'nav', label: 'TV Režim',            href: '/tv',        keywords: 'tv televize mode' },
    { group: 'nav', label: 'Výběry k tisku',      href: '/print',     keywords: 'tisk print fotokniha' },
    { group: 'nav', label: 'Statistiky',          href: '/stats',     keywords: 'statistiky stats' },
    { group: 'nav', label: 'Nezařazené',          href: '/inbox',     keywords: 'nezarazene inbox' },
    { group: 'nav', label: 'Aktivita',            href: '/activity',  keywords: 'aktivita activity' },
    { group: 'nav', label: 'Recovery Center',     href: '/recovery',  keywords: 'recovery zdravi' },
    { group: 'nav', label: 'Soukromí a dědictví', href: '/privacy',   keywords: 'privacy soukromi dedictvi legacy' },
    { group: 'nav', label: 'Archiv',              href: '/archive',   keywords: 'archiv' },
    { group: 'nav', label: 'Koš',                 href: '/trash',     keywords: 'kos trash smazat' },
    { group: 'nav', label: 'Sdílené',             href: '/shares',    keywords: 'share sdilene' },
    { group: 'nav', label: 'Společné výběry',     href: '/curation',  keywords: 'vyber kurator kolekce hlasovani fotky' },
    { group: 'nav', label: 'Nastavení Drive',     href: '/settings/storage/google', keywords: 'settings nastaveni google drive' },
];

const ACTION_COMMANDS: Command[] = [
    { group: 'action', label: 'Vytvořit album',     href: '/albums/create', keywords: 'create album novy', icon: '📁' },
    { group: 'action', label: 'Nahrát fotografie',  href: '/timeline',      keywords: 'upload nahrat foto', icon: '📤' },
    { group: 'action', label: 'Nová fotokniha',     href: '/print',         keywords: 'fotokniha print selection', icon: '🖨️' },
    { group: 'action', label: 'Vytvořit recept',    href: '/recipes',       keywords: 'novy recept vareni kucharka', icon: '🍳' },
    { group: 'action', label: 'Přidat společný úkol', href: '/planning#todos', keywords: 'novy ukol todo checklist pripominka', icon: '✅' },
    { group: 'action', label: 'Synchronizovat Revolut', href: '/finances#connection', keywords: 'revolut sync banka ucet', icon: '💳' },
    { group: 'action', label: 'Naplánovat filmový večer', href: '/watchlist', keywords: 'film serial kino watchlist vecer', icon: '🎬' },
    { group: 'action', label: 'Vygenerovat randíčko', href: '/date-ideas', keywords: 'randicko rande napad pro dva', icon: '💞' },
    { group: 'action', label: 'Vytvořit výroční album', href: '/anniversary-album', keywords: 'vyroci album fotografie vzpominka', icon: '📸' },
    { group: 'action', label: 'Večer se vzpomínkami', href: '/memories#memory-evenings', keywords: 'vzpominky ritual album fotografie spolecny vecer', icon: '💞' },
];

const GROUP_LABELS: Record<CommandGroup, string> = {
    nav:    'Přejít na',
    action: 'Akce',
    search: 'Hledat',
};

function CommandPalette({ open, onClose, isAdmin }: { open: boolean; onClose: () => void; isAdmin: boolean }) {
    const [query,    setQuery]    = useState('');
    const [activeIdx, setActive]  = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => { if (!open) { setQuery(''); setActive(0); } }, [open]);
    useEffect(() => { if (open) inputRef.current?.focus(); }, [open]);

    const allCommands: Command[] = [...NAV_COMMANDS, ...ACTION_COMMANDS].filter(command => !command.adminOnly || isAdmin);

    // If query starts with known search triggers, add search command
    const searchCmd: Command | null = query.trim().length >= 2 ? {
        group: 'search',
        label: `Hledat „${query.trim()}"`,
        href: `/search?q=${encodeURIComponent(query.trim())}`,
        keywords: '',
        icon: '🔍',
    } : null;

    const filtered: Command[] = query.trim()
        ? [
            ...(searchCmd ? [searchCmd] : []),
            ...allCommands.filter(c =>
                c.label.toLowerCase().includes(query.toLowerCase()) ||
                c.keywords.includes(query.toLowerCase())
            ),
          ]
        : allCommands;

    // Groups for ungrouped display
    const go = useCallback((cmd: Command) => {
        onClose();
        if (cmd.action) { cmd.action(); return; }
        if (cmd.href) router.visit(cmd.href);
    }, [onClose]);

    const handleKey = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') { onClose(); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(i => Math.min(i + 1, filtered.length - 1)); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); setActive(i => Math.max(i - 1, 0)); }
        if (e.key === 'Enter' && filtered[activeIdx]) go(filtered[activeIdx]);
    };

    if (!open) return null;

    // Group results when no query
    const grouped = !query.trim()
        ? {
            action: filtered.filter(c => c.group === 'action'),
            nav:    filtered.filter(c => c.group === 'nav'),
          }
        : null;

    let globalIdx = 0;

    const renderCmd = (cmd: Command, idx: number) => {
        const isActive = idx === activeIdx;
        return (
            <button key={`${cmd.group}-${cmd.label}`}
                onClick={() => go(cmd)}
                onMouseEnter={() => setActive(idx)}
                className={`w-full text-left px-4 py-2 text-sm flex items-center gap-3 transition-colors ${isActive ? 'bg-[var(--color-accent)]/15 text-white' : 'text-[var(--color-text-secondary)] hover:text-white'}`}>
                {cmd.icon && <span className="text-base w-5 text-center shrink-0">{cmd.icon}</span>}
                <span className="flex-1">{cmd.label}</span>
                {isActive && <kbd className="text-[10px] text-[var(--color-text-secondary)] border border-[var(--color-border)] rounded px-1.5 py-0.5 shrink-0">↵</kbd>}
            </button>
        );
    };

    return (
        <div className="fixed inset-0 z-[800] flex items-start justify-center p-2 pt-[10dvh] sm:p-4 sm:pt-[12vh]">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose}/>
            <div className="relative z-10 w-full max-w-lg bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-2xl shadow-2xl overflow-hidden">
                {/* Search input */}
                <div className="flex items-center gap-3 px-4 py-3 border-b border-[var(--color-border)]">
                    <Search size={16} className="text-[var(--color-text-secondary)] shrink-0"/>
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={e => { setQuery(e.target.value); setActive(0); }}
                        onKeyDown={handleKey}
                        placeholder="Přejdi na stránku, hledej, proveď akci…"
                        className="flex-1 bg-transparent text-white text-sm outline-none placeholder-[var(--color-text-secondary)]"
                    />
                    <kbd className="text-[10px] text-[var(--color-text-secondary)] border border-[var(--color-border)] rounded px-1.5 py-0.5 shrink-0">ESC</kbd>
                </div>

                {/* Results */}
                <div className="max-h-80 overflow-y-auto py-1">
                    {filtered.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-[var(--color-text-secondary)]">Nic nenalezeno</p>
                    ) : grouped ? (
                        <>
                            {grouped.action.length > 0 && (
                                <>
                                    <p className="px-4 pt-2 pb-1 text-[10px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider">Akce</p>
                                    {grouped.action.map(cmd => { const i = globalIdx++; return renderCmd(cmd, i); })}
                                </>
                            )}
                            {grouped.nav.length > 0 && (
                                <>
                                    <p className="px-4 pt-2 pb-1 text-[10px] font-semibold text-[var(--color-text-secondary)] uppercase tracking-wider">Přejít na</p>
                                    {grouped.nav.map(cmd => { const i = globalIdx++; return renderCmd(cmd, i); })}
                                </>
                            )}
                        </>
                    ) : (
                        filtered.map((cmd, i) => renderCmd(cmd, i))
                    )}
                </div>

                {/* Footer hint */}
                <div className="px-4 py-2 border-t border-[var(--color-border)] flex items-center gap-4 text-[10px] text-[var(--color-text-secondary)]">
                    <span>↑↓ Navigace</span>
                    <span>↵ Otevřít</span>
                    <span>ESC Zavřít</span>
                    <div className="flex-1"/>
                    <span>Ctrl+K</span>
                </div>
            </div>
        </div>
    );
}

// ─── Notification Bell ───────────────────────────────────

interface GalleryNotif {
    id:         string;
    read_at:    string | null;
    created_at: string;
    category: string;
    category_label: string;
    priority: 'low' | 'normal' | 'high' | 'critical';
    context_key?: string | null;
    snoozed_until?: string | null;
    data: {
        type:    string;
        message: string;
        link?:   string;
        icon?:   string;
        extra?: {
            reminder_id?: number;
            actionable?: boolean;
            event_uuid?: string;
            starts_at?: string;
            [key: string]: unknown;
        };
    };
}

interface NotificationPreferences {
    categories: Record<string, boolean>;
    priority_floor: 'low' | 'normal' | 'high' | 'critical';
    quiet: { enabled:boolean; from:string; to:string };
    browser_notifications: boolean;
}

interface NotificationMeta {
    total: number;
    unread: number;
    important: number;
    critical: number;
    quiet_now: boolean;
    categories: Record<string, number>;
}

function NotificationBell() {
    const [notifs,     setNotifs]     = useState<GalleryNotif[]>([]);
    const [open,       setOpen]       = useState(false);
    const [loading,    setLoading]    = useState(false);
    const [actionBusy, setActionBusy] = useState('');
    const [actionError, setActionError] = useState('');
    const [focus, setFocus] = useState<'all'|'important'|'unread'>('all');
    const [categoryFilter, setCategoryFilter] = useState('all');
    const [showSettings, setShowSettings] = useState(false);
    const [preferences, setPreferences] = useState<NotificationPreferences|null>(null);
    const [preferenceDraft, setPreferenceDraft] = useState<NotificationPreferences|null>(null);
    const [categories, setCategories] = useState<Record<string,string>>({});
    const [meta, setMeta] = useState<NotificationMeta>({total:0,unread:0,important:0,critical:0,quiet_now:false,categories:{}});
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r = await axios.get('/api/v1/notifications', {params:{focus,category:categoryFilter==='all'?undefined:categoryFilter,limit:30}});
            const items:GalleryNotif[] = r.data?.data ?? [];
            const nextPreferences:NotificationPreferences|null = r.data?.preferences ?? null;
            setNotifs(items);
            setMeta(r.data?.meta ?? {total:items.length,unread:items.filter(item=>!item.read_at).length,important:0,critical:0,quiet_now:false,categories:{}});
            setCategories(r.data?.categories ?? {});
            if (nextPreferences) {
                setPreferences(nextPreferences);
                setPreferenceDraft(current => current ?? nextPreferences);
            }
            if (nextPreferences?.browser_notifications && !r.data?.meta?.quiet_now && 'Notification' in window && Notification.permission === 'granted') {
                const seen = new Set<string>(JSON.parse(sessionStorage.getItem('maki-notified') ?? '[]'));
                items.filter(item => !item.read_at && ['high','critical'].includes(item.priority) && !seen.has(item.id)).slice(0,3).forEach(item => {
                    const notification = new Notification(item.category_label || 'Maki Gallery', {body:item.data.message,tag:item.context_key ?? item.id,icon:'/favicon.ico'});
                    notification.onclick = () => { window.focus(); if (item.data.link) router.visit(item.data.link); notification.close(); };
                    seen.add(item.id);
                });
                sessionStorage.setItem('maki-notified', JSON.stringify(Array.from(seen).slice(-100)));
            }
        } catch { /* ignore */ }
        finally { setLoading(false); }
    }, [focus, categoryFilter]);

    // Load on mount + every 60s
    useEffect(() => {
        load();
        pollRef.current = setInterval(load, 60_000);
        return () => { if (pollRef.current) clearInterval(pollRef.current); };
    }, [load]);

    const markRead = async (id: string) => {
        const wasUnread = notifs.some(notification => notification.id === id && !notification.read_at);
        await axios.post(`/api/v1/notifications/${id}/read`).catch(() => {});
        setNotifs(prev => prev.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n));
        if (wasUnread) setMeta(current => ({...current,unread:Math.max(0,current.unread-1)}));
    };

    const markAllRead = async () => {
        await axios.post('/api/v1/notifications/read-all', categoryFilter === 'all' ? {} : {category:categoryFilter}).catch(() => {});
        setNotifs(prev => focus === 'unread' ? [] : prev.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
        if (categoryFilter === 'all') setMeta(current => ({...current,unread:0}));
        else await load();
    };

    const unread = meta.unread;

    const handleClick = (n: GalleryNotif) => {
        markRead(n.id);
        if (n.data.link) router.visit(n.data.link);
        setOpen(false);
    };

    const reminderAction = async (notification: GalleryNotif, action: 'snooze' | 'acknowledge', minutes?: number) => {
        const reminderId = notification.data.extra?.reminder_id;
        if (!reminderId) return;
        setActionBusy(`${notification.id}:${action}:${minutes ?? 0}`);
        setActionError('');
        try {
            await axios.post(`/api/v1/reminders/${reminderId}/${action}`, minutes ? { minutes } : {});
            setNotifs(current => current.filter(item => item.id !== notification.id));
            await load();
        } catch (reason: any) {
            setActionError(reason.response?.data?.message ?? 'Připomínku se nepodařilo změnit.');
        } finally {
            setActionBusy('');
        }
    };

    const manageNotification = async (notification:GalleryNotif, action:'snooze'|'archive', minutes = 60) => {
        setActionBusy(`${notification.id}:${action}`); setActionError('');
        try {
            await axios.post(`/api/v1/notifications/${notification.id}/${action}`, action === 'snooze' ? {minutes} : {});
            setNotifs(current => current.filter(item => item.id !== notification.id));
            await load();
        } catch (reason:any) { setActionError(reason.response?.data?.message ?? 'Notifikaci se nepodařilo změnit.'); }
        finally { setActionBusy(''); }
    };

    const savePreferences = async () => {
        if (!preferenceDraft) return;
        setActionBusy('preferences'); setActionError('');
        try {
            const response = await axios.patch('/api/v1/notifications/preferences', preferenceDraft);
            setPreferences(response.data.preferences); setPreferenceDraft(response.data.preferences);
            setMeta(current => ({...current,quiet_now:response.data.quiet_now ?? current.quiet_now}));
            setShowSettings(false); await load();
        } catch (reason:any) { setActionError(reason.response?.data?.message ?? 'Nastavení upozornění se nepodařilo uložit.'); }
        finally { setActionBusy(''); }
    };

    const enableBrowserNotifications = async () => {
        if (!preferenceDraft) return;
        if (!('Notification' in window)) { setActionError('Tento prohlížeč systémová upozornění nepodporuje.'); return; }
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') { setActionError('Prohlížeč nepovolil systémová upozornění.'); return; }
        setPreferenceDraft({...preferenceDraft,browser_notifications:true});
    };

    const fmtTime = (d: string) => {
        const diff = Date.now() - new Date(d).getTime();
        if (diff < 60_000) return 'Právě teď';
        if (diff < 3_600_000) return `${Math.floor(diff / 60_000)} min`;
        if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)} h`;
        return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' });
    };

    return (
        <div className="relative">
            <button type="button" aria-label="Otevřít partnerská upozornění" aria-expanded={open} onClick={() => setOpen(v => !v)}
                className={`relative p-2 rounded-lg hover:bg-white/10 transition-colors ${open ? 'text-white bg-white/10' : 'text-[var(--color-text-secondary)]'}`}>
                <Bell size={18}/>
                {unread > 0 && (
                    <span className="absolute top-0.5 right-0.5 w-4 h-4 bg-[var(--color-accent)] text-white text-[9px] font-bold rounded-full flex items-center justify-center">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <>
                    <div className="fixed inset-0 z-40" onClick={() => setOpen(false)}/>
                    <div className="absolute right-0 top-full mt-2 w-[min(25rem,calc(100vw-1rem))] bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden z-50">
                        <div className="flex items-center justify-between px-4 py-3 border-b border-[var(--color-border)]">
                            <div><h3 className="text-sm font-semibold text-white">Pro nás dva</h3><p className="mt-0.5 text-[10px] text-[var(--color-text-secondary)]">Plány, cesty, vzpomínky i společné finance</p></div>
                            <div className="flex items-center gap-1">{meta.quiet_now&&<span title="Tichý režim je aktivní" className="rounded-full bg-violet-500/15 px-2 py-1 text-[9px] text-violet-100">ticho</span>}<button aria-label="Nastavení upozornění" onClick={()=>setShowSettings(value=>!value)} className={`rounded-lg p-2 ${showSettings?'bg-white/10 text-white':'text-[var(--color-text-secondary)] hover:text-white'}`}><Settings size={15}/></button></div>
                        </div>

                        <div className="border-b border-[var(--color-border)] px-3 py-2"><div className="flex items-center gap-1 overflow-x-auto">{([['all','Vše',meta.total],['important','Důležité',meta.important],['unread','Nepřečtené',meta.unread]] as const).map(([value,label,count])=><button key={value} onClick={()=>setFocus(value)} className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] ${focus===value?'bg-[var(--color-accent)] text-white':'bg-white/5 text-[var(--color-text-secondary)]'}`}>{label} · {count}</button>)}<div className="flex-1"/>{unread>0&&<button onClick={markAllRead} className="shrink-0 text-[10px] text-[var(--color-accent)] hover:underline">Přečíst vše</button>}</div><select aria-label="Filtrovat druh upozornění" value={categoryFilter} onChange={event=>setCategoryFilter(event.target.value)} className="mt-2 min-h-8 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-[10px] text-white"><option value="all">Všechny oblasti</option>{Object.entries(categories).filter(([key])=>(meta.categories[key]??0)>0).map(([key,label])=><option key={key} value={key}>{label} · {meta.categories[key]??0}</option>)}</select></div>

                        {actionError && <p className="border-b border-[var(--color-border)] bg-red-500/10 px-4 py-2 text-[10px] text-red-200">{actionError}</p>}

                        {showSettings&&preferenceDraft&&<div className="max-h-[60dvh] overflow-y-auto border-b border-[var(--color-border)] bg-black/10 p-4"><p className="text-xs font-medium text-white">Co mi má systém připomínat</p><div className="mt-2 grid grid-cols-2 gap-1.5">{Object.entries(categories).map(([key,label])=><label key={key} className="flex min-w-0 items-center gap-2 rounded-lg bg-white/5 p-2 text-[10px] text-[var(--color-text-secondary)]"><input type="checkbox" checked={preferenceDraft.categories[key]??true} disabled={key==='system'} onChange={event=>setPreferenceDraft({...preferenceDraft,categories:{...preferenceDraft.categories,[key]:event.target.checked}})}/><span className="truncate">{label}</span></label>)}</div><label className="mt-3 block text-[10px] text-[var(--color-text-secondary)]">Nejnižší zobrazovaná priorita<select value={preferenceDraft.priority_floor} onChange={event=>setPreferenceDraft({...preferenceDraft,priority_floor:event.target.value as NotificationPreferences['priority_floor']})} className="mt-1 min-h-9 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 text-xs text-white"><option value="low">Všechna upozornění</option><option value="normal">Běžná a důležitá</option><option value="high">Jen důležitá</option><option value="critical">Jen kritická</option></select></label><label className="mt-3 flex items-center justify-between gap-3 text-xs text-white"><span>Tichý režim</span><input type="checkbox" checked={preferenceDraft.quiet.enabled} onChange={event=>setPreferenceDraft({...preferenceDraft,quiet:{...preferenceDraft.quiet,enabled:event.target.checked}})}/></label>{preferenceDraft.quiet.enabled&&<div className="mt-2 grid grid-cols-2 gap-2"><label className="text-[10px] text-[var(--color-text-secondary)]">Od<input type="time" value={preferenceDraft.quiet.from} onChange={event=>setPreferenceDraft({...preferenceDraft,quiet:{...preferenceDraft.quiet,from:event.target.value}})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-2 text-xs text-white"/></label><label className="text-[10px] text-[var(--color-text-secondary)]">Do<input type="time" value={preferenceDraft.quiet.to} onChange={event=>setPreferenceDraft({...preferenceDraft,quiet:{...preferenceDraft.quiet,to:event.target.value}})} className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] p-2 text-xs text-white"/></label></div>}<div className="mt-3 flex items-center justify-between gap-3"><div><p className="text-xs text-white">Upozornění zařízení</p><p className="text-[10px] text-[var(--color-text-secondary)]">Jen důležité, mimo tichý režim</p></div><button onClick={preferenceDraft.browser_notifications?()=>setPreferenceDraft({...preferenceDraft,browser_notifications:false}):enableBrowserNotifications} className={`rounded-lg px-2.5 py-1.5 text-[10px] ${preferenceDraft.browser_notifications?'bg-emerald-500/15 text-emerald-100':'border border-[var(--color-border)] text-[var(--color-text-secondary)]'}`}>{preferenceDraft.browser_notifications?'Zapnuto':'Povolit'}</button></div><button disabled={actionBusy==='preferences'} onClick={savePreferences} className="mt-4 min-h-9 w-full rounded-lg bg-[var(--color-accent)] text-xs font-medium text-white disabled:opacity-40">{actionBusy==='preferences'?'Ukládám…':'Uložit moje nastavení'}</button></div>}

                        <div className="max-h-[min(28rem,65dvh)] overflow-y-auto">
                            {notifs.length === 0 ? (
                                <div className="px-4 py-8 text-center text-[var(--color-text-secondary)]">
                                    <Bell size={24} className="mx-auto mb-2 opacity-30"/>
                                    <p className="text-sm">{loading?'Načítám upozornění…':'V této části je vše vyřízené'}</p>
                                </div>
                            ) : notifs.map(n => {
                                const actionableReminder = n.data.type === 'calendar.reminder'
                                    && n.data.extra?.actionable
                                    && !!n.data.extra?.reminder_id;
                                const startsAt = n.data.extra?.starts_at ? new Date(n.data.extra.starts_at).getTime() : 0;
                                const canSnoozeTomorrow = !startsAt || startsAt > Date.now() + 24 * 60 * 60 * 1000;
                                return <article key={n.id} className={`border-b border-[var(--color-border)] last:border-0 ${!n.read_at ? 'bg-[var(--color-accent)]/5' : ''}`}>
                                    <button onClick={() => handleClick(n)} className="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-white/5">
                                        <span className="mt-0.5 shrink-0 text-xl">{n.data.icon ?? '🔔'}</span>
                                        <div className="min-w-0 flex-1">
                                            <p className={`text-xs leading-relaxed ${!n.read_at ? 'font-medium text-white' : 'text-[var(--color-text-secondary)]'}`}>{n.data.message}</p>
                                            <p className="mt-1 flex flex-wrap items-center gap-1.5 text-[9px] text-[var(--color-text-secondary)]"><span>{n.category_label}</span><span>·</span><span>{fmtTime(n.created_at)}</span>{['high','critical'].includes(n.priority)&&<span className={`rounded-full px-1.5 py-0.5 ${n.priority==='critical'?'bg-red-500/15 text-red-200':'bg-amber-500/15 text-amber-100'}`}>{n.priority==='critical'?'kritické':'důležité'}</span>}</p>
                                        </div>
                                        {!n.read_at && <div className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[var(--color-accent)]"/>}
                                    </button>
                                    {actionableReminder && <div className="flex flex-wrap gap-1.5 px-4 pb-3 pl-12">
                                        <button disabled={!!actionBusy} onClick={() => reminderAction(n, 'snooze', 60)} className="min-h-8 rounded-lg border border-[var(--color-border)] px-2.5 text-[10px] text-[var(--color-text-secondary)] hover:text-white disabled:opacity-40">Za 1 hodinu</button>
                                        {canSnoozeTomorrow && <button disabled={!!actionBusy} onClick={() => reminderAction(n, 'snooze', 1440)} className="min-h-8 rounded-lg border border-[var(--color-border)] px-2.5 text-[10px] text-[var(--color-text-secondary)] hover:text-white disabled:opacity-40">Zítra</button>}
                                        <button disabled={!!actionBusy} onClick={() => reminderAction(n, 'acknowledge')} className="min-h-8 rounded-lg bg-emerald-500/15 px-2.5 text-[10px] text-emerald-100 disabled:opacity-40">Vyřízeno</button>
                                    </div>}
                                    {!actionableReminder&&<div className="flex flex-wrap gap-1.5 px-4 pb-3 pl-12"><button disabled={!!actionBusy} onClick={()=>manageNotification(n,'snooze',60)} className="inline-flex min-h-8 items-center gap-1 rounded-lg border border-[var(--color-border)] px-2 text-[10px] text-[var(--color-text-secondary)] disabled:opacity-40"><Clock size={11}/> Připomenout za hodinu</button><button disabled={!!actionBusy} onClick={()=>manageNotification(n,'archive')} className="ml-auto inline-flex min-h-8 items-center gap-1 rounded-lg px-2 text-[10px] text-[var(--color-text-secondary)] hover:bg-white/5 disabled:opacity-40"><Archive size={11}/> Archivovat</button></div>}
                                </article>;
                            })}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

interface AppLayoutProps {
    children: ReactNode;
    title?: string;
}

type NavigationItem = {
    href: string;
    label: string;
    icon: typeof Home;
    adminOnly?: boolean;
    exact?: boolean;
};

type NavigationGroup = {
    id: 'together' | 'travel' | 'library' | 'administration';
    label: string;
    description: string;
    icon: typeof Home;
    items: NavigationItem[];
};

const primaryNavItems: NavigationItem[] = [
    { href: '/', label: 'Domů', icon: Home, exact: true },
    { href: '/timeline', label: 'Fotky', icon: Images },
    { href: '/albums', label: 'Alba', icon: FolderOpen },
    { href: '/calendar', label: 'Kalendář', icon: Calendar },
    { href: '/weekly', label: 'Náš týden', icon: Sparkles },
];

const navGroups: NavigationGroup[] = [
    {
        id: 'together', label: 'Společně', description: 'Plány, zážitky a domácnost', icon: Heart,
        items: [
            { href: '/planning', label: 'Plánování a úkoly', icon: Calendar },
            { href: '/finances', label: 'Společné finance', icon: CircleDollarSign },
            { href: '/watchlist', label: 'Filmy a seriály', icon: Clapperboard },
            { href: '/date-ideas', label: 'Nápady na randíčka', icon: Sparkles },
            { href: '/anniversary-album', label: 'Výroční album', icon: Images },
            { href: '/gifts-anniversaries', label: 'Dárky a výročí', icon: Gift },
            { href: '/recipes', label: 'Naše kuchařka', icon: ChefHat },
            { href: '/memories', label: 'Vzpomínky', icon: Clock },
            { href: '/journey', label: 'Náš příběh', icon: BookHeart },
        ],
    },
    {
        id: 'travel', label: 'Cestování', description: 'Od inspirace po jízdenky', icon: Route,
        items: [
            { href: '/itinerary', label: 'Itinerář světa', icon: Globe },
            { href: '/map', label: 'Mapa', icon: Map },
            { href: '/trips', label: 'Cesty a výlety', icon: Route },
            { href: '/tickets', label: 'Jízdenky a doprava', icon: Ticket },
            { href: '/places', label: 'Místa a podniky', icon: MapPin },
        ],
    },
    {
        id: 'library', label: 'Knihovna', description: 'Organizace a výstupy galerie', icon: FolderOpen,
        items: [
            { href: '/search', label: 'Hledat', icon: Search },
            { href: '/favorites', label: 'Oblíbené', icon: Heart },
            { href: '/people', label: 'Lidé', icon: Users },
            { href: '/tags', label: 'Tagy', icon: Tag },
            { href: '/inbox', label: 'Nezařazené', icon: Inbox },
            { href: '/archive', label: 'Archiv', icon: Archive },
            { href: '/vault', label: 'Soukromý trezor', icon: LockKeyhole },
            { href: '/trash', label: 'Koš', icon: Trash2 },
            { href: '/shares', label: 'Sdílené', icon: Share2 },
            { href: '/print', label: 'Výběry k tisku', icon: Printer },
            { href: '/tv', label: 'TV režim', icon: Monitor },
        ],
    },
    {
        id: 'administration', label: 'Administrace', description: 'Kontrola, soukromí a integrace', icon: Settings,
        items: [
            { href: '/stats', label: 'Statistiky', icon: BarChart3 },
            { href: '/activity', label: 'Aktivita', icon: Activity },
            { href: '/recovery', label: 'Recovery centrum', icon: ShieldCheck },
            { href: '/privacy', label: 'Soukromí a dědictví', icon: ShieldCheck },
            { href: '/settings/security', label: 'Nastavení a zabezpečení', icon: Settings },
            { href: '/settings/storage/google', label: 'Úložiště a Google Drive', icon: Archive },
            { href: '/admin', label: 'Správa systému', icon: ShieldCheck, adminOnly: true, exact: true },
            { href: '/admin/integrations', label: 'Integrace a API', icon: KeyRound, adminOnly: true },
        ],
    },
];

const allNavItems = [...primaryNavItems, ...navGroups.flatMap(group => group.items)];
const customizableNavItems = navGroups.flatMap(group => group.items);
const PINNED_NAV_KEY = 'gallery.navigation.pinned.v1';
const OPEN_NAV_GROUPS_KEY = 'gallery.navigation.groups.v1';

const mobileNav = [
    { href: '/',          label: 'Domů',     icon: Home },
    { href: '/timeline',  label: 'Fotky',    icon: Images },
    { href: '/calendar',  label: 'Kalendář', icon: Calendar },
    { href: '/trips',     label: 'Cesty',    icon: Route },
];

function NavigationCustomizer({
    open,
    onClose,
    pinnedHrefs,
    onChange,
    isAdmin,
}: {
    open: boolean;
    onClose: () => void;
    pinnedHrefs: string[];
    onChange: (hrefs: string[]) => void;
    isAdmin: boolean;
}) {
    if (!open) return null;

    const available = customizableNavItems.filter(item => !item.adminOnly || isAdmin);
    const toggle = (href: string) => {
        if (pinnedHrefs.includes(href)) {
            onChange(pinnedHrefs.filter(item => item !== href));
            return;
        }
        if (pinnedHrefs.length < 6) onChange([...pinnedHrefs, href]);
    };
    const move = (href: string, direction: -1 | 1) => {
        const index = pinnedHrefs.indexOf(href);
        const target = index + direction;
        if (index < 0 || target < 0 || target >= pinnedHrefs.length) return;
        const next = [...pinnedHrefs];
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    };

    return (
        <div className="fixed inset-0 z-[850] flex items-end justify-center p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Přizpůsobit hlavní nabídku">
            <button type="button" className="absolute inset-0 bg-black/65 backdrop-blur-sm" onClick={onClose} aria-label="Zavřít přizpůsobení nabídky" />
            <section className="safe-area-pb relative z-10 flex max-h-[88dvh] w-full max-w-xl flex-col overflow-hidden rounded-t-3xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)] shadow-2xl sm:rounded-3xl">
                <header className="flex items-start justify-between gap-3 border-b border-[var(--color-border)] p-4 sm:p-5">
                    <div>
                        <h2 className="text-base font-semibold text-white">Moje rychlé zkratky</h2>
                        <p className="mt-1 text-xs leading-relaxed text-[var(--color-text-secondary)]">Vyberte až šest často používaných částí. Zobrazí se nad skupinami v postranní nabídce.</p>
                    </div>
                    <button type="button" onClick={onClose} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white" aria-label="Zavřít">
                        <X size={19}/>
                    </button>
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto p-3 sm:p-4">
                    <div className="mb-3 flex items-center justify-between gap-3 rounded-xl bg-white/[0.035] px-3 py-2 text-xs">
                        <span className="text-[var(--color-text-secondary)]">Vybráno</span>
                        <strong className="text-white">{pinnedHrefs.length} / 6</strong>
                    </div>
                    <div className="space-y-4">
                        {navGroups.map(group => {
                            const items = group.items.filter(item => available.some(candidate => candidate.href === item.href));
                            if (!items.length) return null;
                            return (
                                <div key={group.id}>
                                    <p className="mb-1.5 px-1 text-[10px] font-semibold uppercase tracking-[.16em] text-[var(--color-text-secondary)]">{group.label}</p>
                                    <div className="space-y-1">
                                        {items.map(item => {
                                            const Icon = item.icon;
                                            const checked = pinnedHrefs.includes(item.href);
                                            const pinIndex = pinnedHrefs.indexOf(item.href);
                                            return (
                                                <div key={item.href} className="flex min-h-12 items-center gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] px-2">
                                                    <button type="button" onClick={() => toggle(item.href)} disabled={!checked && pinnedHrefs.length >= 6} className="flex min-h-11 min-w-0 flex-1 items-center gap-3 px-1 text-left disabled:opacity-40" aria-pressed={checked}>
                                                        <span className={clsx('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', checked ? 'bg-[var(--color-accent)]/20 text-[var(--color-accent)]' : 'bg-white/5 text-[var(--color-text-secondary)]')}><Icon size={16}/></span>
                                                        <span className="min-w-0 flex-1 truncate text-sm text-white">{item.label}</span>
                                                        <span className={clsx('h-5 w-5 shrink-0 rounded-md border text-center text-xs leading-[18px]', checked ? 'border-[var(--color-accent)] bg-[var(--color-accent)] text-white' : 'border-[var(--color-border)] text-transparent')}>✓</span>
                                                    </button>
                                                    {checked && (
                                                        <div className="flex shrink-0 gap-1 border-l border-[var(--color-border)] pl-2">
                                                            <button type="button" onClick={() => move(item.href, -1)} disabled={pinIndex === 0} className="h-9 w-8 rounded-lg text-xs text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white disabled:opacity-25" aria-label={`Posunout ${item.label} výše`}>↑</button>
                                                            <button type="button" onClick={() => move(item.href, 1)} disabled={pinIndex === pinnedHrefs.length - 1} className="h-9 w-8 rounded-lg text-xs text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white disabled:opacity-25" aria-label={`Posunout ${item.label} níže`}>↓</button>
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
                <footer className="flex gap-2 border-t border-[var(--color-border)] p-3 sm:p-4">
                    <button type="button" onClick={() => onChange([])} disabled={!pinnedHrefs.length} className="min-h-11 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-secondary)] disabled:opacity-35">Vymazat zkratky</button>
                    <button type="button" onClick={onClose} className="min-h-11 flex-1 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-white">Hotovo</button>
                </footer>
            </section>
        </div>
    );
}

export default function AppLayout({ children, title }: AppLayoutProps) {
    const { auth, flash } = usePage().props as any;
    const isAdmin = ['owner', 'admin'].includes(auth?.user?.role);
    const currentPath = window.location.pathname;
    // Exact match for root; prefix match for all others
    // (prevents '/' highlighting /timeline, /albums, /media/... etc.)
    const isActive = (href: string, exact = false) =>
        href === '/' || href === '/home'
            ? currentPath === '/' || currentPath === '/home'
            : exact ? currentPath === href : currentPath === href || currentPath.startsWith(`${href}/`);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [cmdOpen,    setCmdOpen]    = useState(false);
    const [navEditorOpen, setNavEditorOpen] = useState(false);
    const [pinnedHrefs, setPinnedHrefs] = useState<string[]>(() => {
        try {
            const value = JSON.parse(localStorage.getItem(PINNED_NAV_KEY) ?? '[]');
            return Array.isArray(value) ? value.filter(href => typeof href === 'string').slice(0, 6) : [];
        } catch { return []; }
    });
    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(() => {
        try {
            const value = JSON.parse(localStorage.getItem(OPEN_NAV_GROUPS_KEY) ?? '{}');
            return value && typeof value === 'object' ? value : {};
        } catch { return {}; }
    });
    const currentLabel = title
        ?? [...allNavItems].sort((a, b) => b.href.length - a.href.length).find(item => isActive(item.href, item.exact))?.label
        ?? 'Galerie';

    useEffect(() => { localStorage.setItem(PINNED_NAV_KEY, JSON.stringify(pinnedHrefs)); }, [pinnedHrefs]);
    useEffect(() => { localStorage.setItem(OPEN_NAV_GROUPS_KEY, JSON.stringify(openGroups)); }, [openGroups]);

    useEffect(() => {
        const activeGroup = navGroups.find(group => group.items.some(item => isActive(item.href, item.exact)));
        if (activeGroup) setOpenGroups(previous => previous[activeGroup.id] ? previous : { ...previous, [activeGroup.id]: true });
    }, [currentPath]);

    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                setCmdOpen(v => !v);
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, []);

    useEffect(() => {
        setMobileOpen(false);
    }, [currentPath]);

    useEffect(() => {
        if (!mobileOpen) return;
        const close = (event: KeyboardEvent) => { if (event.key === 'Escape') setMobileOpen(false); };
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, [mobileOpen]);

    const pinnedItems = pinnedHrefs
        .map(href => customizableNavItems.find(item => item.href === href))
        .filter((item): item is NavigationItem => Boolean(item) && (!item?.adminOnly || isAdmin));

    const renderNavigationItem = (item: NavigationItem, nested = false, closeDrawer = false) => {
        if (item.adminOnly && !isAdmin) return null;
        const Icon = item.icon;
        const active = isActive(item.href, item.exact);
        return (
            <Link
                key={item.href}
                href={item.href}
                onClick={closeDrawer ? () => setMobileOpen(false) : undefined}
                className={clsx(
                    'mx-2 flex min-h-10 items-center gap-3 rounded-xl px-3 text-sm transition-colors',
                    nested && 'ml-5 text-[13px]',
                    active
                        ? 'bg-[var(--color-accent)] text-white font-medium shadow-sm'
                        : 'text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white'
                )}
            >
                <Icon size={nested ? 15 : 16} className="shrink-0" />
                <span className="min-w-0 flex-1 truncate">{item.label}</span>
            </Link>
        );
    };

    const renderNavigation = (instance: 'desktop' | 'mobile') => {
        const closeDrawer = instance === 'mobile';
        return (
            <>
                <div className="space-y-0.5">
                    {primaryNavItems.map(item => renderNavigationItem(item, false, closeDrawer))}
                </div>

                {pinnedItems.length > 0 && (
                    <div className="mt-3 border-t border-[var(--color-border)] pt-3">
                        <p className="mb-1 px-5 text-[9px] font-semibold uppercase tracking-[.16em] text-[var(--color-text-secondary)]">Moje zkratky</p>
                        <div className="space-y-0.5">{pinnedItems.map(item => renderNavigationItem(item, false, closeDrawer))}</div>
                    </div>
                )}

                <div className="mt-3 space-y-1 border-t border-[var(--color-border)] pt-3">
                    {navGroups.map(group => {
                        const items = group.items.filter(item => !item.adminOnly || isAdmin);
                        if (!items.length) return null;
                        const open = Boolean(openGroups[group.id]);
                        const active = items.some(item => isActive(item.href, item.exact));
                        const GroupIcon = group.icon;
                        return (
                            <div key={group.id}>
                                <button
                                    type="button"
                                    onClick={() => setOpenGroups(previous => ({ ...previous, [group.id]: !open }))}
                                    aria-expanded={open}
                                    aria-controls={`${instance}-nav-${group.id}`}
                                    className={clsx(
                                        'mx-2 flex min-h-12 w-[calc(100%-1rem)] items-center gap-3 rounded-xl px-3 text-left transition-colors',
                                        active ? 'bg-white/[0.055] text-white' : 'text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white'
                                    )}
                                >
                                    <GroupIcon size={17} className={clsx('shrink-0', active && 'text-[var(--color-accent)]')}/>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">{group.label}</span>
                                        <span className="block truncate text-[9px] text-[var(--color-text-secondary)]">{group.description}</span>
                                    </span>
                                    <ChevronDown size={15} className={clsx('shrink-0 transition-transform duration-200', open && 'rotate-180')}/>
                                </button>
                                {open && (
                                    <div id={`${instance}-nav-${group.id}`} className="mt-1 space-y-0.5 border-l border-[var(--color-border)] pb-1 ml-5">
                                        {items.map(item => renderNavigationItem(item, true, closeDrawer))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                <button
                    type="button"
                    onClick={() => { setMobileOpen(false); setNavEditorOpen(true); }}
                    className="mx-2 mt-3 flex min-h-11 w-[calc(100%-1rem)] items-center gap-3 rounded-xl border border-dashed border-[var(--color-border)] px-3 text-xs text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-accent)]/50 hover:bg-white/5 hover:text-white"
                >
                    <SlidersHorizontal size={16}/>
                    Přizpůsobit nabídku
                </button>
            </>
        );
    };

    return (
        <div className="app-shell flex min-h-0 w-full overflow-hidden">
            <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} isAdmin={isAdmin} />
            <NavigationCustomizer open={navEditorOpen} onClose={() => setNavEditorOpen(false)} pinnedHrefs={pinnedHrefs} onChange={setPinnedHrefs} isAdmin={isAdmin}/>
            {/* Sidebar — Desktop */}
            <aside className="hidden md:flex flex-col w-60 shrink-0 border-r border-[var(--color-border)] bg-[var(--color-bg-secondary)]">
                {/* Logo */}
                <div className="flex items-center gap-3 px-5 py-4 border-b border-[var(--color-border)]">
                    <div className="w-8 h-8 rounded-lg bg-[var(--color-accent)] flex items-center justify-center">
                        <Images size={16} className="text-white" />
                    </div>
                    <span className="font-semibold text-sm text-white truncate">Stanektech Gallery</span>
                </div>

                {/* Nav */}
                <nav className="flex-1 py-3 overflow-y-auto scrollbar-hide">
                    {renderNavigation('desktop')}
                </nav>

                {/* User + Notifications */}
                <div className="border-t border-[var(--color-border)] p-3">
                    <div className="flex items-center gap-2">
                        <div className="flex items-center gap-2 flex-1 px-2 py-2 rounded-lg hover:bg-white/5 cursor-pointer min-w-0">
                            <div className="w-7 h-7 rounded-full bg-[var(--color-accent)]/30 flex items-center justify-center text-xs font-bold text-[var(--color-accent)] shrink-0">
                                {auth?.user?.name?.[0]?.toUpperCase() ?? '?'}
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-xs font-medium text-white truncate">{auth?.user?.name}</p>
                                <p className="text-xs text-[var(--color-text-secondary)] truncate">{auth?.user?.role}</p>
                            </div>
                        </div>
                        <AppInstallButton/>
                        <NotificationBell/>
                    </div>
                </div>
            </aside>

            {/* Mobile Sidebar Overlay */}
            {mobileOpen && (
                <div className="fixed inset-0 z-[700] flex md:hidden" role="dialog" aria-modal="true" aria-label="Hlavní nabídka">
                    {/* Backdrop */}
                    <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={() => setMobileOpen(false)} />
                    {/* Drawer */}
                    <aside className="safe-area-pt relative z-10 flex h-full w-72 max-w-[88vw] flex-col border-r border-[var(--color-border)] bg-[var(--color-bg-secondary)] shadow-2xl">
                        <div className="flex items-center justify-between px-5 py-4 border-b border-[var(--color-border)]">
                            <div className="flex items-center gap-3">
                                <div className="w-8 h-8 rounded-lg bg-[var(--color-accent)] flex items-center justify-center">
                                    <Images size={16} className="text-white" />
                                </div>
                                <span className="font-semibold text-sm text-white">Stanektech Gallery</span>
                            </div>
                            <button onClick={() => setMobileOpen(false)} className="text-[var(--color-text-secondary)] hover:text-white p-1">
                                <X size={20} />
                            </button>
                        </div>
                        <nav className="flex-1 py-3 overflow-y-auto">
                            {renderNavigation('mobile')}
                        </nav>
                        <div className="border-t border-[var(--color-border)] p-4">
                            <div className="flex items-center gap-3">
                                <div className="w-9 h-9 rounded-full bg-[var(--color-accent)]/30 flex items-center justify-center text-sm font-bold text-[var(--color-accent)]">
                                    {auth?.user?.name?.[0]?.toUpperCase() ?? '?'}
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-white">{auth?.user?.name}</p>
                                    <p className="text-xs text-[var(--color-text-secondary)]">{auth?.user?.email}</p>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            )}

            {/* Main content */}
            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                {/* Mobile top bar — menu is reachable before any scrolling. */}
                <header className="safe-area-pt flex min-h-14 shrink-0 items-center gap-2 border-b border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-2 md:hidden">
                    <button type="button" onClick={() => setMobileOpen(true)} aria-label="Otevřít hlavní nabídku" aria-expanded={mobileOpen} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white">
                        <Menu size={21}/>
                    </button>
                    <p className="min-w-0 flex-1 truncate text-sm font-semibold text-white">{currentLabel}</p>
                    <button type="button" onClick={() => setCmdOpen(true)} aria-label="Otevřít rychlé hledání" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white">
                        <Search size={19}/>
                    </button>
                    <AppInstallButton/>
                    <NotificationBell/>
                </header>
                {/* Flash messages */}
                {flash?.success && (
                    <div className="px-4 py-2 bg-green-600/20 border-b border-green-600/30 text-green-400 text-sm">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="px-4 py-2 bg-red-600/20 border-b border-red-600/30 text-red-400 text-sm">
                        {flash.error}
                    </div>
                )}

                {/* Content */}
                <main id="app-scroll-container" className="app-scroll min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain">
                    {children}
                </main>

                {/* Global upload manager panel */}
                <UploadPanel />

                {/* Mobile Bottom Nav */}
                <nav className="mobile-bottom-nav safe-area-pb fixed inset-x-0 bottom-0 z-[650] flex shrink-0 items-center border-t border-[var(--color-border)] bg-[var(--color-bg-secondary)]/98 shadow-[0_-8px_30px_rgba(0,0,0,.28)] backdrop-blur-xl md:hidden" aria-label="Rychlá mobilní navigace">
                    {mobileNav.map(item => {
                        const Icon = item.icon;
                        const active = isActive(item.href);
                        return (
                            <Link key={item.href} href={item.href}
                                className={clsx('flex min-h-14 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-0.5 py-1 text-[10px] transition-colors',
                                    active ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'
                                )}>
                                <Icon size={20} />
                                {item.label}
                            </Link>
                        );
                    })}
                    <button onClick={() => setMobileOpen(true)}
                        aria-label="Otevřít další části aplikace" aria-expanded={mobileOpen}
                        className="flex min-h-14 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-0.5 py-1 text-[10px] text-[var(--color-text-secondary)]">
                        <Menu size={20} />
                        Více
                    </button>
                </nav>
            </div>
        </div>
    );
}
