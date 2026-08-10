import axios from 'axios';
import { Copy, KeyRound, Loader2 } from 'lucide-react';
import { useState } from 'react';

/** Groups of four, because people type this by hand off a screen. */
const readable = (secret: string) => secret.match(/.{1,4}/g)?.join(' ') ?? secret;

/**
 * Turning on the second factor.
 *
 * There is no QR code, and that is a choice rather than an omission: drawing one needs a
 * library this project would then carry forever, and every authenticator app accepts a
 * typed secret. The secret is shown in groups of four for exactly that reason.
 *
 * Recovery codes appear once. They are stored hashed, so nobody — including us — can show
 * them again, which is the property that makes them worth having.
 */
export default function TwoFactorSetup({ enabled, onChanged }: {
    enabled: boolean;
    onChanged: () => void;
}) {
    const [password, setPassword] = useState('');
    const [secret, setSecret] = useState<string | null>(null);
    const [code, setCode] = useState('');
    const [codes, setCodes] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const fail = (reason: any, fallback: string) =>
        setError(reason?.response?.data?.errors?.current_password?.[0]
            ?? reason?.response?.data?.errors?.code?.[0]
            ?? reason?.response?.data?.message ?? fallback);

    const begin = async () => {
        if (!password) { setError('Zadejte heslo.'); return; }
        setBusy(true); setError('');
        try {
            const response = await axios.post('/api/v1/ucet/2fa', { current_password: password });
            setSecret(response.data.secret);
            setPassword('');
        } catch (reason) { fail(reason, 'Nepodařilo se začít.'); }
        finally { setBusy(false); }
    };

    const confirm = async () => {
        setBusy(true); setError('');
        try {
            const response = await axios.post('/api/v1/ucet/2fa/potvrdit', { code });
            setCodes(response.data.recovery_codes ?? []);
            setSecret(null);
            setCode('');
            onChanged();
        } catch (reason) { fail(reason, 'Kód se nepodařilo ověřit.'); }
        finally { setBusy(false); }
    };

    const disable = async () => {
        if (!password) { setError('Zadejte heslo.'); return; }
        setBusy(true); setError('');
        try {
            await axios.delete('/api/v1/ucet/2fa', { data: { current_password: password } });
            setPassword('');
            onChanged();
        } catch (reason) { fail(reason, 'Nepodařilo se vypnout.'); }
        finally { setBusy(false); }
    };

    return (
        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]">
                <KeyRound size={17} className="text-[var(--color-accent)]" /> Dvoufázové ověření
            </h2>
            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                {enabled
                    ? 'Zapnuto. Při přihlášení se kromě hesla ptáme na kód z telefonu.'
                    : 'Kromě hesla bude potřeba kód z aplikace v telefonu. Ukradené heslo pak samo nestačí.'}
            </p>

            {error && <p role="alert" className="mt-3 rounded-lg bg-red-500/10 p-2 text-xs text-red-200">{error}</p>}

            {codes && (
                <div className="mt-3 rounded-xl border border-emerald-400/30 bg-emerald-500/10 p-3">
                    <p className="text-xs text-emerald-100">
                        Uložte si záložní kódy. Každý funguje jednou a znovu je nikdy neuvidíte.
                    </p>
                    <div className="mt-2 grid grid-cols-2 gap-1 font-mono text-xs text-emerald-50">
                        {codes.map(item => <span key={item}>{item}</span>)}
                    </div>
                    <button
                        type="button"
                        onClick={() => void navigator.clipboard?.writeText(codes.join('\n'))}
                        className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-emerald-400/30 px-2 py-1 text-[11px] text-emerald-100"
                    >
                        <Copy size={12} /> Zkopírovat
                    </button>
                </div>
            )}

            {secret && (
                <div className="mt-3 rounded-xl border border-[var(--color-border)] p-3">
                    <p className="text-xs text-[var(--color-text-secondary)]">
                        Zadejte tento klíč do aplikace (Google Authenticator, Aegis, 1Password…) a opište kód, který ukáže.
                    </p>
                    <p className="mt-2 select-all font-mono text-sm tracking-wider text-[var(--color-text-primary)]">
                        {readable(secret)}
                    </p>
                    <input
                        value={code}
                        onChange={event => setCode(event.target.value)}
                        inputMode="numeric"
                        placeholder="123456"
                        className="mt-3 w-40 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-center tracking-widest text-[var(--color-text-primary)]"
                    />
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void confirm()}
                        className="ml-2 inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm text-[var(--color-accent-contrast)] disabled:opacity-50"
                    >
                        {busy && <Loader2 size={14} className="animate-spin" />} Zapnout
                    </button>
                </div>
            )}

            {!secret && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <input
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={event => setPassword(event.target.value)}
                        placeholder="Současné heslo"
                        className="w-full max-w-xs rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-[var(--color-text-primary)]"
                    />
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void (enabled ? disable() : begin())}
                        className={`inline-flex min-h-10 items-center gap-2 rounded-xl px-4 text-sm disabled:opacity-50 ${enabled
                            ? 'border border-red-500/40 text-red-300'
                            : 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)]'}`}
                    >
                        {busy && <Loader2 size={14} className="animate-spin" />}
                        {enabled ? 'Vypnout' : 'Zapnout ověření'}
                    </button>
                </div>
            )}
        </section>
    );
}
