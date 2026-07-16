import { ReactNode, createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

interface InstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

interface PwaInstallContextValue {
    canInstall: boolean;
    installed: boolean;
    installing: boolean;
    showInstallBanner: boolean;
    install: () => Promise<'accepted' | 'dismissed' | 'unavailable'>;
    dismissInstallBanner: () => void;
}

const INSTALL_DISMISSED_AT = 'maki:pwa-install-dismissed-at';
const INSTALL_COOLDOWN_MS = 7 * 24 * 60 * 60 * 1000;

const PwaInstallContext = createContext<PwaInstallContextValue | null>(null);

function isStandalone(): boolean {
    return window.matchMedia('(display-mode: standalone)').matches
        || (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
}

function installBannerIsCoolingDown(): boolean {
    try {
        const dismissedAt = Number(window.localStorage.getItem(INSTALL_DISMISSED_AT) ?? 0);
        return Number.isFinite(dismissedAt) && Date.now() - dismissedAt < INSTALL_COOLDOWN_MS;
    } catch {
        return false;
    }
}

function rememberBannerDismissal(): void {
    try {
        window.localStorage.setItem(INSTALL_DISMISSED_AT, String(Date.now()));
    } catch {
        // Private browsing can deny storage access. Installation remains available from the header.
    }
}

export function PwaInstallProvider({ children }: { children: ReactNode }) {
    const [promptEvent, setPromptEvent] = useState<InstallPromptEvent | null>(null);
    const [installed, setInstalled] = useState(() => isStandalone());
    const [installing, setInstalling] = useState(false);
    const [bannerDismissed, setBannerDismissed] = useState(() => installBannerIsCoolingDown());

    useEffect(() => {
        const capture = (event: Event) => {
            event.preventDefault();
            if (isStandalone()) return;
            setPromptEvent(event as InstallPromptEvent);
            setBannerDismissed(installBannerIsCoolingDown());
        };
        const complete = () => {
            setInstalled(true);
            setPromptEvent(null);
            setBannerDismissed(true);
        };
        const displayMode = window.matchMedia('(display-mode: standalone)');
        const displayModeChanged = () => setInstalled(isStandalone());

        window.addEventListener('beforeinstallprompt', capture);
        window.addEventListener('appinstalled', complete);
        displayMode.addEventListener?.('change', displayModeChanged);

        return () => {
            window.removeEventListener('beforeinstallprompt', capture);
            window.removeEventListener('appinstalled', complete);
            displayMode.removeEventListener?.('change', displayModeChanged);
        };
    }, []);

    const install = useCallback(async (): Promise<'accepted' | 'dismissed' | 'unavailable'> => {
        if (!promptEvent || installing) return 'unavailable';
        setInstalling(true);
        try {
            await promptEvent.prompt();
            const choice = await promptEvent.userChoice;
            if (choice.outcome === 'dismissed') {
                rememberBannerDismissal();
                setBannerDismissed(true);
            }
            setPromptEvent(null);
            return choice.outcome;
        } finally {
            setInstalling(false);
        }
    }, [installing, promptEvent]);

    const dismissInstallBanner = useCallback(() => {
        rememberBannerDismissal();
        setBannerDismissed(true);
    }, []);

    const value = useMemo<PwaInstallContextValue>(() => ({
        canInstall: promptEvent !== null && !installed,
        installed,
        installing,
        showInstallBanner: promptEvent !== null && !installed && !bannerDismissed,
        install,
        dismissInstallBanner,
    }), [bannerDismissed, dismissInstallBanner, install, installed, installing, promptEvent]);

    return <PwaInstallContext.Provider value={value}>{children}</PwaInstallContext.Provider>;
}

export function usePwaInstall(): PwaInstallContextValue {
    const value = useContext(PwaInstallContext);
    if (!value) throw new Error('usePwaInstall musí být použit uvnitř PwaInstallProvider.');
    return value;
}
