import UploadPanel from '@/Components/UploadPanel';
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
    { group: 'nav', label: 'Místa',               href: '/places',    keywords: 'mista places lokace' },
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
    data: {
        type:    string;
        message: string;
        link?:   string;
        icon?:   string;
    };
}

function NotificationBell() {
    const [notifs,     setNotifs]     = useState<GalleryNotif[]>([]);
    const [open,       setOpen]       = useState(false);
    const [loading,    setLoading]    = useState(false);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r = await axios.get('/api/v1/notifications');
            setNotifs(r.data?.data ?? r.data ?? []);
        } catch { /* ignore */ }
        finally { setLoading(false); }
    }, []);

    // Load on mount + every 60s
    useEffect(() => {
        load();
        pollRef.current = setInterval(load, 60_000);
        return () => { if (pollRef.current) clearInterval(pollRef.current); };
    }, [load]);

    const markRead = async (id: string) => {
        await axios.post(`/api/v1/notifications/${id}/read`).catch(() => {});
        setNotifs(prev => prev.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n));
    };

    const markAllRead = async () => {
        await axios.post('/api/v1/notifications/read-all').catch(() => {});
        setNotifs(prev => prev.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
    };

    const unread = notifs.filter(n => !n.read_at).length;

    const handleClick = (n: GalleryNotif) => {
        markRead(n.id);
        if (n.data.link) router.visit(n.data.link);
        setOpen(false);
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
            <button onClick={() => setOpen(v => !v)}
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
                    <div className="absolute right-0 top-full mt-2 w-80 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden z-50">
                        <div className="flex items-center justify-between px-4 py-3 border-b border-[var(--color-border)]">
                            <h3 className="text-sm font-semibold text-white">Notifikace</h3>
                            {unread > 0 && (
                                <button onClick={markAllRead} className="text-[10px] text-[var(--color-accent)] hover:underline">
                                    Označit vše jako přečtené
                                </button>
                            )}
                        </div>

                        <div className="max-h-80 overflow-y-auto">
                            {notifs.length === 0 ? (
                                <div className="px-4 py-8 text-center text-[var(--color-text-secondary)]">
                                    <Bell size={24} className="mx-auto mb-2 opacity-30"/>
                                    <p className="text-sm">Žádné notifikace</p>
                                </div>
                            ) : notifs.map(n => (
                                <button key={n.id}
                                    onClick={() => handleClick(n)}
                                    className={`w-full text-left px-4 py-3 border-b border-[var(--color-border)] last:border-0 hover:bg-white/5 transition-colors flex items-start gap-3 ${!n.read_at ? 'bg-[var(--color-accent)]/5' : ''}`}>
                                    <span className="text-xl shrink-0 mt-0.5">{n.data.icon ?? '🔔'}</span>
                                    <div className="flex-1 min-w-0">
                                        <p className={`text-xs leading-relaxed ${!n.read_at ? 'text-white font-medium' : 'text-[var(--color-text-secondary)]'}`}>
                                            {n.data.message}
                                        </p>
                                        <p className="text-[10px] text-[var(--color-text-secondary)] mt-0.5">{fmtTime(n.created_at)}</p>
                                    </div>
                                    {!n.read_at && (
                                        <div className="w-2 h-2 rounded-full bg-[var(--color-accent)] shrink-0 mt-1.5"/>
                                    )}
                                </button>
                            ))}
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

const navItems = [
    { href: '/',          label: 'Domů',        icon: Home },
    { href: '/timeline',  label: 'Fotky',       icon: Images },
    { href: '/albums',    label: 'Alba',         icon: FolderOpen },
    { href: '/calendar',  label: 'Kalendář',    icon: Calendar },
    { href: '/weekly', label: 'Náš týden', icon: Sparkles },
    { href: '/planning', label: 'Plánování', icon: Calendar },
    { href: '/finances', label: 'Finance', icon: CircleDollarSign },
    { href: '/watchlist', label: 'Watchlist', icon: Clapperboard },
    { href: '/date-ideas', label: 'Randíčka', icon: Sparkles },
    { href: '/anniversary-album', label: 'Výroční album', icon: Images },
    { href: '/gifts-anniversaries', label: 'Dárky a výročí', icon: Gift },
    { href: '/recipes',  label: 'Naše kuchařka', icon: ChefHat },
    { href: '/trips',     label: 'Cesty',        icon: Route },
    { href: '/tickets',   label: 'Jízdenky',     icon: Ticket },
    { href: '/map',       label: 'Mapa',         icon: Map },
    { href: '/search',    label: 'Hledat',       icon: Search },
    { href: '/favorites', label: 'Oblíbené',     icon: Heart },
    { href: '/memories',  label: 'Vzpomínky',    icon: Clock },
    { href: '/journey',    label: 'Naše cesta',   icon: BookHeart },
    { href: '/itinerary',  label: 'Itinerář světa', icon: Globe },
    { divider: true },
    { href: '/people',    label: 'Lidé',         icon: Users },
    { href: '/places',    label: 'Místa',        icon: MapPin },
    { href: '/tags',      label: 'Tagy',         icon: Tag },
    { href: '/inbox',     label: 'Nezařazené',   icon: Inbox },
    { href: '/activity',  label: 'Aktivita',     icon: Activity },
    { href: '/stats',     label: 'Statistiky',   icon: BarChart3 },
    { href: '/recovery',  label: 'Recovery',     icon: ShieldCheck },
    { href: '/privacy',   label: 'Soukromí',     icon: ShieldCheck },
    { divider: true },
    { href: '/archive',   label: 'Archiv',       icon: Archive },
    { href: '/vault',     label: 'Soukromý trezor', icon: LockKeyhole },
    { href: '/trash',     label: 'Koš',          icon: Trash2 },
    { divider: true },
    { href: '/shares',    label: 'Sdílené',      icon: Share2 },
    { href: '/print',     label: 'Výběry k tisku', icon: Printer },
    { href: '/tv',        label: 'TV režim',     icon: Monitor },
    { href: '/settings/storage/google', label: 'Nastavení', icon: Settings },
    { href: '/admin/integrations', label: 'Integrace a API', icon: KeyRound, adminOnly: true },
];

const mobileNav = [
    { href: '/',          label: 'Domů',     icon: Home },
    { href: '/timeline',  label: 'Fotky',    icon: Images },
    { href: '/search',    label: 'Hledat',   icon: Search },
    { href: '/trips',     label: 'Cesty',    icon: Route },
    { href: '/memories',  label: 'Pro vás',  icon: Sparkles },
];

export default function AppLayout({ children, title }: AppLayoutProps) {
    const { auth, flash } = usePage().props as any;
    const isAdmin = ['owner', 'admin'].includes(auth?.user?.role);
    const currentPath = window.location.pathname;
    // Exact match for root; prefix match for all others
    // (prevents '/' highlighting /timeline, /albums, /media/... etc.)
    const isActive = (href: string) =>
        href === '/' || href === '/home'
            ? currentPath === '/' || currentPath === '/home'
            : currentPath.startsWith(href);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [cmdOpen,    setCmdOpen]    = useState(false);
    const currentLabel = title
        ?? navItems.find(item => !('divider' in item) && isActive(item.href))?.label
        ?? 'Galerie';

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

    return (
        <div className="app-shell flex min-h-0 w-full overflow-hidden">
            <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} isAdmin={isAdmin} />
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
                    {navItems.map((item, i) => {
                        if ('divider' in item) {
                            return <div key={i} className="h-px bg-[var(--color-border)] mx-3 my-2" />;
                        }
                        if ('adminOnly' in item && item.adminOnly && !isAdmin) return null;
                        const Icon = item.icon;
                        const active = isActive(item.href);
                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={clsx(
                                    'flex items-center gap-3 mx-2 px-3 py-2 rounded-lg text-sm transition-colors',
                                    active
                                        ? 'bg-[var(--color-accent)] text-white font-medium'
                                        : 'text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white'
                                )}
                            >
                                <Icon size={16} className="shrink-0" />
                                {item.label}
                            </Link>
                        );
                    })}
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
                            {navItems.map((item, i) => {
                                if ('divider' in item) return <div key={i} className="h-px bg-[var(--color-border)] mx-3 my-2" />;
                                if ('adminOnly' in item && item.adminOnly && !isAdmin) return null;
                                const Icon = item.icon;
                                const active = isActive(item.href);
                                return (
                                    <Link key={item.href} href={item.href} onClick={() => setMobileOpen(false)}
                                        className={clsx('flex items-center gap-3 mx-2 px-3 py-2.5 rounded-lg text-sm transition-colors',
                                            active ? 'bg-[var(--color-accent)] text-white font-medium' : 'text-[var(--color-text-secondary)] hover:bg-white/5 hover:text-white'
                                        )}>
                                        <Icon size={18} className="shrink-0" />
                                        {item.label}
                                    </Link>
                                );
                            })}
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
