import axios from 'axios';
import { Download, Loader2, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

const stamp = (value: string) =>
    new Date(value).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' });

/**
 * Taking your data out, and closing the account.
 *
 * Kept in one section because they are the same decision at two strengths, and somebody
 * about to close an account should see the export offered first — the commonest reason
 * people hesitate is that they do not want to lose what they wrote.
 *
 * The export is a plain link rather than a fetch: the server streams it as a download,
 * and pulling a multi-megabyte file through axios only to hand it back to the browser
 * would buy nothing and would hold it all in memory twice.
 */
export default function AccountData({ scheduledFor, onChanged }: {
    scheduledFor: string | null;
    /** Lets the page reload the profile, so the notice matches the server. */
    onChanged: () => void;
}) {
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [confirming, setConfirming] = useState(false);

    const schedule = async () => {
        if (!password) { setError('Zadejte heslo.'); return; }
        setBusy(true); setError('');
        try {
            await axios.post('/api/v1/ucet/zruseni', { current_password: password });
            setPassword('');
            setConfirming(false);
            onChanged();
        } catch (reason: any) {
            setError(reason?.response?.data?.errors?.current_password?.[0]
                ?? reason?.response?.data?.message
                ?? 'Zrušení se nepodařilo naplánovat.');
        } finally { setBusy(false); }
    };

    const cancel = async () => {
        setBusy(true); setError('');
        try { await axios.delete('/api/v1/ucet/zruseni'); onChanged(); }
        catch { setError('Zrušení se nepodařilo odvolat.'); }
        finally { setBusy(false); }
    };

    return (
        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]">
                <Download size={17} className="text-[var(--color-accent)]" /> Vaše data
            </h2>
            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                Stáhnete si svůj profil, deník, zprávy a seznam médií jako jeden soubor JSON.
                Fotografie samotné v souboru nejsou — je to soupis, ne záloha.
            </p>

            <a
                href="/api/v1/ucet/export"
                className="mt-3 inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)] hover:border-[var(--color-accent)]"
            >
                <Download size={15} /> Stáhnout export
            </a>

            <div className="mt-5 border-t border-[var(--color-border)] pt-4">
                <h3 className="flex items-center gap-2 text-sm font-semibold text-[var(--color-text-primary)]">
                    <TriangleAlert size={16} className="text-red-400" /> Zrušení účtu
                </h3>

                {error && <p role="alert" className="mt-2 rounded-lg bg-red-500/10 p-2 text-xs text-red-200">{error}</p>}

                {scheduledFor ? (
                    <>
                        <p className="mt-2 rounded-lg bg-red-500/10 p-3 text-xs text-red-200">
                            Účet je naplánován ke zrušení {stamp(scheduledFor)}. Do té doby jej můžete zachovat.
                        </p>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => void cancel()}
                            className="mt-3 inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)] disabled:opacity-50"
                        >
                            {busy && <Loader2 size={14} className="animate-spin" />} Zrušení odvolat
                        </button>
                    </>
                ) : !confirming ? (
                    <>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Účet se neruší hned. Dostanete čtrnáct dní, kdy si to můžete rozmyslet.
                        </p>
                        <button
                            type="button"
                            onClick={() => { setConfirming(true); setError(''); }}
                            className="mt-3 min-h-10 rounded-xl border border-red-500/40 px-4 text-sm text-red-300 hover:bg-red-500/10"
                        >
                            Zrušit účet
                        </button>
                    </>
                ) : (
                    <>
                        <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                            Potvrďte heslem. Po čtrnácti dnech se smaže profil, deník i zprávy — nevratně.
                        </p>
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={event => setPassword(event.target.value)}
                            placeholder="Současné heslo"
                            className="mt-3 w-full max-w-xs rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-[var(--color-text-primary)]"
                        />
                        <div className="mt-3 flex gap-2">
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => void schedule()}
                                className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-red-500/90 px-4 text-sm text-white disabled:opacity-50"
                            >
                                {busy && <Loader2 size={14} className="animate-spin" />} Potvrdit zrušení
                            </button>
                            <button
                                type="button"
                                onClick={() => { setConfirming(false); setPassword(''); setError(''); }}
                                className="min-h-10 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)]"
                            >
                                Ponechat účet
                            </button>
                        </div>
                    </>
                )}
            </div>
        </section>
    );
}
