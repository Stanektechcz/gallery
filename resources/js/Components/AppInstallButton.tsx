import { usePwaInstall } from '@/Contexts/PwaInstallContext';
import { router } from '@inertiajs/react';
import { Check, Download, LoaderCircle, Smartphone } from 'lucide-react';

export default function AppInstallButton({ compact = true, className = '' }: { compact?: boolean; className?: string }) {
    const { canInstall, installed, installing, install } = usePwaInstall();

    const open = async () => {
        if (canInstall) {
            const result = await install();
            if (result !== 'unavailable') return;
        }
        router.visit('/app');
    };

    const label = installed ? 'Aplikace je nainstalovaná' : canInstall ? 'Nainstalovat aplikaci' : 'Aplikace pro Android';
    const Icon = installing ? LoaderCircle : installed ? Check : canInstall ? Download : Smartphone;

    return (
        <button
            type="button"
            onClick={open}
            disabled={installing}
            aria-label={label}
            title={label}
            className={`relative inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--color-border)] bg-white/[0.035] text-[var(--color-text-secondary)] transition hover:border-[var(--color-accent)]/45 hover:bg-[var(--color-accent)]/10 hover:text-white disabled:opacity-60 ${compact ? 'h-10 w-10 p-0' : 'px-3 text-xs font-medium'} ${className}`}
        >
            <Icon size={18} className={installing ? 'animate-spin' : ''}/>
            {!compact && <span>{installed ? 'Nainstalováno' : canInstall ? 'Nainstalovat' : 'Aplikace'}</span>}
            {canInstall && !installed && <span className="absolute right-1 top-1 h-2 w-2 rounded-full bg-emerald-400 ring-2 ring-[var(--color-bg-secondary)]"/>}
        </button>
    );
}
