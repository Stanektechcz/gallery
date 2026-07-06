import UploadPanel from '@/Components/UploadPanel';
import { Link, router, usePage } from '@inertiajs/react';
import { clsx } from 'clsx';
import {
    Activity,
    Archive,
    BarChart3,
    BookHeart,
    Calendar,
    Clock,
    FolderOpen,
    Globe,
    Heart,
    Home,
    Images,
    Inbox,
    Map,
    MapPin,
    Menu,
    Monitor,
    Printer,
    Route,
    Search,
    Settings,
    Share2,
    ShieldCheck,
    Tag,
    Trash2,
    Users,
    X
} from 'lucide-react';
import { ReactNode, useEffect, useState } from 'react';

// ─── Command Palette ────────────────────────────────────
const COMMANDS = [
    { label: 'Domovská stránka',    href: '/',          keywords: 'home domov' },
    { label: 'Fotky / Timeline',    href: '/timeline',  keywords: 'fotky timeline' },
    { label: 'Alba',                href: '/albums',    keywords: 'album folder' },
    { label: 'Nové album',          href: '/albums/create', keywords: 'new album' },
    { label: 'Kalendář',            href: '/calendar',  keywords: 'kalendar calendar' },
    { label: 'Mapa',                href: '/map',       keywords: 'map gps' },
    { label: 'Hledat',              href: '/search',    keywords: 'hledat search' },
    { label: 'Oblíbené',            href: '/favorites', keywords: 'oblibene heart' },
    { label: 'Vzpomínky',           href: '/memories',  keywords: 'vzpominky memories' },
    { label: 'Naše cesta',          href: '/journey',   keywords: 'nase cesta journey kronika' },
    { label: 'Itinerář světa',      href: '/itinerary', keywords: 'itinerar svet travel wishlist' },
    { label: 'Lidé',                href: '/people',    keywords: 'lide people osoby' },
    { label: 'Tagy',                href: '/tags',      keywords: 'tagy tags' },
    { label: 'Statistiky',          href: '/stats',     keywords: 'statistiky stats' },
    { label: 'Nezařazené',          href: '/inbox',     keywords: 'nezarazene inbox' },
    { label: 'Aktivita',            href: '/activity',  keywords: 'aktivita activity log' },
    { label: 'Recovery Center',     href: '/recovery',  keywords: 'recovery health oprava' },
    { label: 'Archiv',              href: '/archive',   keywords: 'archiv archive' },
    { label: 'Koš',                 href: '/trash',     keywords: 'kos trash delete' },
    { label: 'Sdílené',             href: '/shares',    keywords: 'share sdilene' },
    { label: 'Nastavení Drive',     href: '/settings/storage/google', keywords: 'settings google drive' },
];

function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
    const [query, setQuery] = useState('');

    useEffect(() => {
        if (!open) setQuery('');
    }, [open]);

    const filtered = query.trim()
        ? COMMANDS.filter(c =>
            c.label.toLowerCase().includes(query.toLowerCase()) ||
            c.keywords.includes(query.toLowerCase())
          )
        : COMMANDS;

    const go = (href: string) => { onClose(); router.visit(href); };

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-[500] flex items-start justify-center pt-[15vh]">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md bg-[var(--color-bg-secondary)] border border-[var(--color-border)] rounded-xl shadow-2xl overflow-hidden">
                <div className="flex items-center gap-3 px-4 py-3 border-b border-[var(--color-border)]">
                    <Search size={16} className="text-[var(--color-text-secondary)] shrink-0" />
                    <input
                        autoFocus
                        value={query}
                        onChange={e => setQuery(e.target.value)}
                        onKeyDown={e => {
                            if (e.key === 'Escape') onClose();
                            if (e.key === 'Enter' && filtered[0]) go(filtered[0].href);
                        }}
                        placeholder="Přejít na… (Ctrl+K)"
                        className="flex-1 bg-transparent text-white text-sm outline-none placeholder-[var(--color-text-secondary)]"
                    />
                    <kbd className="text-[10px] text-[var(--color-text-secondary)] border border-[var(--color-border)] rounded px-1.5 py-0.5">ESC</kbd>
                </div>
                <ul className="max-h-72 overflow-y-auto py-1">
                    {filtered.map((c, i) => (
                        <li key={c.href}>
                            <button
                                onClick={() => go(c.href)}
                                className="w-full text-left px-4 py-2.5 text-sm text-white hover:bg-white/10 flex items-center gap-3 transition-colors"
                            >
                                <span className="flex-1">{c.label}</span>
                                {i === 0 && query && (
                                    <kbd className="text-[10px] text-[var(--color-text-secondary)] border border-[var(--color-border)] rounded px-1.5 py-0.5">↵</kbd>
                                )}
                            </button>
                        </li>
                    ))}
                    {filtered.length === 0 && (
                        <li className="px-4 py-6 text-center text-sm text-[var(--color-text-secondary)]">Nic nenalezeno</li>
                    )}
                </ul>
            </div>
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
    { href: '/trips',     label: 'Cesty',        icon: Route },
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
    { divider: true },
    { href: '/archive',   label: 'Archiv',       icon: Archive },
    { href: '/trash',     label: 'Koš',          icon: Trash2 },
    { divider: true },
    { href: '/shares',    label: 'Sdílené',      icon: Share2 },
    { href: '/print',     label: 'Výběry k tisku', icon: Printer },
    { href: '/tv',        label: 'TV režim',     icon: Monitor },
    { href: '/settings/storage/google', label: 'Nastavení', icon: Settings },
];

const mobileNav = [
    { href: '/',          label: 'Domů',     icon: Home },
    { href: '/timeline',  label: 'Fotky',    icon: Images },
    { href: '/albums',    label: 'Alba',     icon: FolderOpen },
    { href: '/favorites', label: 'Oblíbené', icon: Heart },
    { href: '/map',       label: 'Mapa',     icon: Map },
    { href: '/search',    label: 'Hledat',   icon: Search },
];

export default function AppLayout({ children, title }: AppLayoutProps) {
    const { auth, flash } = usePage().props as any;
    const currentPath = window.location.pathname;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [cmdOpen,    setCmdOpen]    = useState(false);

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

    return (
        <div className="flex h-screen overflow-hidden">
            <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} />
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
                        const Icon = item.icon;
                        const active = currentPath.startsWith(item.href);
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

                {/* User */}
                <div className="border-t border-[var(--color-border)] p-3">
                    <div className="flex items-center gap-3 px-2 py-2 rounded-lg hover:bg-white/5 cursor-pointer">
                        <div className="w-7 h-7 rounded-full bg-[var(--color-accent)]/30 flex items-center justify-center text-xs font-bold text-[var(--color-accent)]">
                            {auth?.user?.name?.[0]?.toUpperCase() ?? '?'}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-medium text-white truncate">{auth?.user?.name}</p>
                            <p className="text-xs text-[var(--color-text-secondary)] truncate">{auth?.user?.role}</p>
                        </div>
                    </div>
                </div>
            </aside>

            {/* Mobile Sidebar Overlay */}
            {mobileOpen && (
                <div className="md:hidden fixed inset-0 z-50 flex">
                    {/* Backdrop */}
                    <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={() => setMobileOpen(false)} />
                    {/* Drawer */}
                    <aside className="relative z-10 flex flex-col w-72 h-full bg-[var(--color-bg-secondary)] border-r border-[var(--color-border)]">
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
                                const Icon = item.icon;
                                const active = currentPath.startsWith(item.href);
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
            <div className="flex-1 flex flex-col min-h-0 overflow-hidden">
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
                <main className="flex-1 overflow-y-auto">
                    {children}
                </main>

                {/* Global upload manager panel */}
                <UploadPanel />

                {/* Mobile Bottom Nav */}
                <nav className="md:hidden flex items-center border-t border-[var(--color-border)] bg-[var(--color-bg-secondary)] safe-area-pb">
                    {mobileNav.map(item => {
                        const Icon = item.icon;
                        const active = currentPath.startsWith(item.href);
                        return (
                            <Link key={item.href} href={item.href}
                                className={clsx('flex-1 flex flex-col items-center py-3 gap-0.5 text-[10px] transition-colors',
                                    active ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'
                                )}>
                                <Icon size={20} />
                                {item.label}
                            </Link>
                        );
                    })}
                    <button onClick={() => setMobileOpen(true)}
                        className="flex-1 flex flex-col items-center py-3 gap-0.5 text-[10px] text-[var(--color-text-secondary)]">
                        <Menu size={20} />
                        Více
                    </button>
                </nav>
            </div>
        </div>
    );
}

