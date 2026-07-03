import { Link, usePage } from '@inertiajs/react';
import { clsx } from 'clsx';
import {
    Archive,
    Clock,
    FolderOpen,
    Heart,
    Images,
    Map,
    Menu,
    Search,
    Settings,
    Share2,
    Trash2
} from 'lucide-react';
import { ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    title?: string;
}

const navItems = [
    { href: '/timeline',  label: 'Fotky',      icon: Images },
    { href: '/albums',    label: 'Alba',        icon: FolderOpen },
    { href: '/map',       label: 'Mapa',        icon: Map },
    { href: '/search',    label: 'Hledat',      icon: Search },
    { href: '/favorites', label: 'Oblíbené',    icon: Heart },
    { href: '/memories',  label: 'Vzpomínky',   icon: Clock },
    { divider: true },
    { href: '/archive',   label: 'Archiv',      icon: Archive },
    { href: '/trash',     label: 'Koš',         icon: Trash2 },
    { divider: true },
    { href: '/shares',    label: 'Sdílené',     icon: Share2 },
    { href: '/settings/storage/google', label: 'Nastavení', icon: Settings },
];

const mobileNav = [
    { href: '/timeline',  label: 'Fotky', icon: Images },
    { href: '/albums',    label: 'Alba',  icon: FolderOpen },
    { href: '/map',       label: 'Mapa',  icon: Map },
    { href: '/search',    label: 'Hledat',icon: Search },
];

export default function AppLayout({ children, title }: AppLayoutProps) {
    const { auth, flash } = usePage().props as any;
    const currentPath = window.location.pathname;

    return (
        <div className="flex h-screen overflow-hidden">
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

                {/* Mobile Bottom Nav */}
                <nav className="md:hidden flex items-center border-t border-[var(--color-border)] bg-[var(--color-bg-secondary)]">
                    {mobileNav.map(item => {
                        const Icon = item.icon;
                        const active = currentPath.startsWith(item.href);
                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={clsx(
                                    'flex-1 flex flex-col items-center py-3 gap-1 text-xs transition-colors',
                                    active ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-secondary)]'
                                )}
                            >
                                <Icon size={20} />
                                {item.label}
                            </Link>
                        );
                    })}
                    <Link
                        href="/settings/storage/google"
                        className="flex-1 flex flex-col items-center py-3 gap-1 text-xs text-[var(--color-text-secondary)]"
                    >
                        <Menu size={20} />
                        Více
                    </Link>
                </nav>
            </div>
        </div>
    );
}
