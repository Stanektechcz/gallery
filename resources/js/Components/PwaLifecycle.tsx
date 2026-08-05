import { router } from '@inertiajs/react';
import { flushWorkspaceWrites, workspaceWriteSummary } from '@/lib/workspaceWriteQueue';
import { usePwaInstall } from '@/Contexts/PwaInstallContext';
import { useQueryClient } from '@tanstack/react-query';
import { Download, RefreshCw, WifiOff, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
const LEGACY_CACHE_NAMES = ['timeline-cache', 'variants-cache', 'trip-plans-cache', 'calendar-cache'];

/**
 * Global Android/PWA bridge. It deliberately caches no authenticated pages,
 * API responses or private media; those must always reflect the server and
 * must not remain readable after a user signs out on a shared device.
 */
export default function PwaLifecycle() {
    const queryClient = useQueryClient();
    const { install, installing, showInstallBanner, dismissInstallBanner } = usePwaInstall();
    const [online, setOnline] = useState(() => navigator.onLine);
    const [updateRegistration, setUpdateRegistration] = useState<ServiceWorkerRegistration | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const [offlineWrites, setOfflineWrites] = useState({ pending: 0, needsAttention: 0 });
    const reloading = useRef(false);

    useEffect(() => {
        let active = true;
        const refreshWriteState = async () => { const summary = await workspaceWriteSummary(); if (active) setOfflineWrites(summary); };
        const becameOnline = () => {
            setOnline(true);
            void flushWorkspaceWrites().then(async () => { await refreshWriteState(); await queryClient.invalidateQueries(); });
        };
        const becameOffline = () => { setOnline(false); void refreshWriteState(); };
        window.addEventListener('online', becameOnline);
        window.addEventListener('offline', becameOffline);
        void refreshWriteState();
        if (navigator.onLine) void flushWorkspaceWrites().then(refreshWriteState);

        return () => {
            active = false;
            window.removeEventListener('online', becameOnline);
            window.removeEventListener('offline', becameOffline);
        };
    }, [queryClient]);

    useEffect(() => {
        if (!('serviceWorker' in navigator)) return;

        let active = true;
        let registration: ServiceWorkerRegistration | null = null;
        let updateTimer: number | undefined;

        const watchRegistration = (current: ServiceWorkerRegistration) => {
            registration = current;
            if (current.waiting && navigator.serviceWorker.controller) {
                setUpdateRegistration(current);
            }

            current.addEventListener('updatefound', () => {
                const worker = current.installing;
                if (!worker) return;
                worker.addEventListener('statechange', () => {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller && active) {
                        setUpdateRegistration(current);
                    }
                });
            });
        };

        const register = async () => {
            try {
                const registrations = await navigator.serviceWorker.getRegistrations();
                await Promise.all(registrations
                    .filter(item => new URL(item.scope).pathname.startsWith('/build/'))
                    .map(item => item.unregister()));

                if ('caches' in window) {
                    await Promise.all(LEGACY_CACHE_NAMES.map(name => window.caches.delete(name)));
                }

                const current = await navigator.serviceWorker.register('/sw.js', {
                    scope: '/',
                    updateViaCache: 'none',
                });
                if (!active) return;
                watchRegistration(current);
                await current.update();
                updateTimer = window.setInterval(() => void current.update(), 60 * 60 * 1000);
            } catch (error) {
                // The web application remains fully usable when SW registration is blocked.
                console.warn('PWA service worker se nepodařilo zaregistrovat.', error);
            }
        };

        const checkForUpdate = () => {
            if (document.visibilityState === 'visible' && navigator.onLine) void registration?.update();
        };
        const controllerChanged = () => {
            if (reloading.current) return;
            reloading.current = true;
            window.location.reload();
        };

        document.addEventListener('visibilitychange', checkForUpdate);
        window.addEventListener('online', checkForUpdate);
        navigator.serviceWorker.addEventListener('controllerchange', controllerChanged);
        void register();

        return () => {
            active = false;
            if (updateTimer) window.clearInterval(updateTimer);
            document.removeEventListener('visibilitychange', checkForUpdate);
            window.removeEventListener('online', checkForUpdate);
            navigator.serviceWorker.removeEventListener('controllerchange', controllerChanged);
        };
    }, []);

    const applyUpdate = useCallback(() => {
        const waiting = updateRegistration?.waiting;
        if (!waiting) return;
        setRefreshing(true);
        waiting.postMessage({ type: 'SKIP_WAITING' });
    }, [updateRegistration]);

    const refreshAfterReconnect = useCallback(() => {
        setRefreshing(true);
        void queryClient.invalidateQueries().finally(() => {
            router.reload();
            window.setTimeout(() => setRefreshing(false), 1200);
        });
    }, [queryClient]);

    if (online && !showInstallBanner && !updateRegistration && offlineWrites.pending === 0 && offlineWrites.needsAttention === 0) return null;

    return (
        <aside
            aria-live="polite"
            className="fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-[900] mx-auto max-w-lg rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-secondary)]/98 p-3 shadow-2xl backdrop-blur-xl md:bottom-4"
        >
            {!online ? (
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/15 text-amber-300"><WifiOff size={19}/></span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-[var(--color-text-primary)]">Jste offline</p>
                        <p className="text-xs text-[var(--color-text-secondary)]">Rozpracovaná data zůstanou na zařízení.{offlineWrites.pending ? ` Čeká ${offlineWrites.pending} zápisů.` : ''}</p>
                    </div>
                </div>
            ) : offlineWrites.needsAttention > 0 ? (
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-500/15 text-red-200">!</span>
                    <div className="min-w-0 flex-1"><p className="text-sm font-semibold text-[var(--color-text-primary)]">Offline zápis vyžaduje kontrolu</p><p className="text-xs text-[var(--color-text-secondary)]">{offlineWrites.needsAttention} položek se nepodařilo bezpečně sloučit.</p></div>
                    <button type="button" onClick={() => router.visit('/calendar')} className="min-h-10 rounded-xl border border-red-300/30 px-3 text-xs text-red-100">Otevřít</button>
                </div>
            ) : offlineWrites.pending > 0 ? (
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/15 text-amber-200">⌛</span>
                    <div className="min-w-0 flex-1"><p className="text-sm font-semibold text-[var(--color-text-primary)]">Čeká synchronizace</p><p className="text-xs text-[var(--color-text-secondary)]">{offlineWrites.pending} {offlineWrites.pending === 1 ? 'zápis je bezpečně uložený v zařízení' : 'zápisů je bezpečně uloženo v zařízení'}.</p></div>
                </div>
            ) : updateRegistration ? (
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--color-accent)]/15 text-[var(--color-accent)]"><RefreshCw size={19} className={refreshing ? 'animate-spin' : ''}/></span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-[var(--color-text-primary)]">Je dostupná nová verze</p>
                        <p className="text-xs text-[var(--color-text-secondary)]">Aktualizace zachová stejná data a načte nejnovější web.</p>
                    </div>
                    <button type="button" onClick={applyUpdate} disabled={refreshing} className="min-h-10 rounded-xl bg-[var(--color-accent)] px-3 text-xs font-medium text-[var(--color-text-primary)] disabled:opacity-60">
                        Aktualizovat
                    </button>
                </div>
            ) : showInstallBanner ? (
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--color-accent)]/15 text-[var(--color-accent)]"><Download size={19}/></span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-[var(--color-text-primary)]">Nainstalovat Maki do zařízení</p>
                        <p className="text-xs text-[var(--color-text-secondary)]">Celá partnerská aplikace na ploše telefonu i tabletu, vždy napojená na aktuální web.</p>
                    </div>
                    <button type="button" onClick={() => void install()} disabled={installing} className="min-h-10 rounded-xl bg-[var(--color-accent)] px-3 text-xs font-medium text-[var(--color-text-primary)] disabled:opacity-60">{installing ? 'Instaluji…' : 'Nainstalovat'}</button>
                    <button type="button" onClick={dismissInstallBanner} aria-label="Skrýt nabídku instalace" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"><X size={18}/></button>
                </div>
            ) : null}
        </aside>
    );
}
