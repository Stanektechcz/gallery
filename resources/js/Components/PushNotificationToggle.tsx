import axios from 'axios';
import { Bell, BellOff, LoaderCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Turns notifications on for this device.
 *
 * Reminders were already being computed and recorded; nothing subscribed a device, so
 * they only ever appeared inside the open application. Permission is requested from a
 * real click — browsers reject, and users resent, a prompt that appears on page load.
 */
export default function PushNotificationToggle({ publicKey }: { publicKey?: string | null }) {
    const [supported, setSupported] = useState(true);
    const [subscribed, setSubscribed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const refresh = useCallback(async () => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            setSupported(false);
            return;
        }
        try {
            const registration = await navigator.serviceWorker.ready;
            setSubscribed(Boolean(await registration.pushManager.getSubscription()));
        } catch { /* Reported through the buttons instead. */ }
    }, []);

    useEffect(() => { void refresh(); }, [refresh]);

    const subscribe = async () => {
        setBusy(true); setError('');
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                setError(permission === 'denied'
                    ? 'Upozornění jsou pro tento web zakázaná. Povolte je v nastavení prohlížeče.'
                    : 'Bez povolení upozornění nelze zapnout.');
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey!),
            });

            const json = subscription.toJSON();
            await axios.post('/api/v1/calendar/push-subscriptions', {
                endpoint: json.endpoint,
                keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
            });
            setSubscribed(true);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Upozornění se nepodařilo zapnout.');
        } finally { setBusy(false); }
    };

    const unsubscribe = async () => {
        setBusy(true); setError('');
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                // The server row goes first: a deleted subscription can no longer tell us its endpoint.
                await axios.delete('/api/v1/calendar/push-subscriptions', { data: { endpoint: subscription.endpoint } });
                await subscription.unsubscribe();
            }
            setSubscribed(false);
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Upozornění se nepodařilo vypnout.');
        } finally { setBusy(false); }
    };

    if (!supported) {
        return (
            <p className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-3 text-xs text-[var(--color-text-secondary)]">
                Tenhle prohlížeč upozornění na pozadí nepodporuje. Připomínky uvidíte v aplikaci.
            </p>
        );
    }

    if (!publicKey) {
        return (
            <p className="rounded-xl border border-amber-400/25 bg-amber-500/10 p-3 text-xs text-amber-100">
                Upozornění na pozadí zatím nejsou nastavená správcem. Připomínky se zobrazují v aplikaci.
            </p>
        );
    }

    return (
        <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <div className="flex items-start gap-3">
                <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${subscribed ? 'bg-emerald-500/15 text-emerald-300' : 'bg-[var(--color-surface-muted)] text-[var(--color-text-secondary)]'}`}>
                    {subscribed ? <Bell size={18} /> : <BellOff size={18} />}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-[var(--color-text-primary)]">
                        Upozornění na tomto zařízení
                    </p>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                        {subscribed
                            ? 'Připomínky dorazí, i když máte aplikaci zavřenou.'
                            : 'Zapněte, ať vám připomínky nedojdou jen tehdy, když máte aplikaci otevřenou.'}
                    </p>
                    {error && <p role="alert" className="mt-2 text-xs text-red-300">{error}</p>}

                    <button
                        type="button"
                        onClick={subscribed ? unsubscribe : subscribe}
                        disabled={busy}
                        className={`mt-3 inline-flex min-h-10 items-center gap-2 rounded-xl px-4 text-sm font-medium disabled:opacity-40 ${subscribed ? 'border border-[var(--color-border)] text-[var(--color-text-primary)]' : 'bg-[var(--color-accent)] text-white'}`}
                    >
                        {busy ? <LoaderCircle size={14} className="animate-spin" /> : subscribed ? <BellOff size={14} /> : <Bell size={14} />}
                        {subscribed ? 'Vypnout' : 'Zapnout upozornění'}
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * The VAPID public key travels base64url-encoded; the browser wants raw bytes.
 * Backed by an explicit ArrayBuffer so the result satisfies BufferSource.
 */
function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);

    return bytes;
}
