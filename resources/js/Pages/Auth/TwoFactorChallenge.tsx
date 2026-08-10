import { Head, useForm } from '@inertiajs/react';
import { KeyRound, Loader2 } from 'lucide-react';
import { useState } from 'react';

/**
 * The step between the password and the account.
 *
 * Deliberately bare: nothing to navigate to, because nobody is signed in yet. The only
 * two things to do here are enter a code and fall back to a recovery code, and anything
 * else on the page is a way to leave without finishing.
 */
export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    return (
        <main className="flex min-h-screen items-center justify-center bg-[var(--color-bg-primary)] p-4">
            <Head title="Ověření přihlášení" />

            <form
                onSubmit={event => { event.preventDefault(); post('/login/overeni'); }}
                className="w-full max-w-sm rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-6"
            >
                <h1 className="flex items-center gap-2 text-lg font-semibold text-[var(--color-text-primary)]">
                    <KeyRound size={19} className="text-[var(--color-accent)]" /> Ještě jedno ověření
                </h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    {recovery
                        ? 'Zadejte jeden ze záložních kódů, které jste dostali při zapnutí.'
                        : 'Opište šestimístný kód z aplikace v telefonu.'}
                </p>

                <input
                    autoFocus
                    value={data.code}
                    onChange={event => setData('code', event.target.value)}
                    inputMode={recovery ? 'text' : 'numeric'}
                    autoComplete="one-time-code"
                    placeholder={recovery ? 'XXXXX-XXXXX' : '123456'}
                    className="mt-4 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-center text-lg tracking-widest text-[var(--color-text-primary)]"
                />

                {errors.code && <p role="alert" className="mt-2 text-xs text-red-300">{errors.code}</p>}

                <button
                    type="submit"
                    disabled={processing}
                    className="mt-4 flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-[var(--color-accent)] text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50"
                >
                    {processing && <Loader2 size={15} className="animate-spin" />} Pokračovat
                </button>

                <button
                    type="button"
                    onClick={() => { setRecovery(current => !current); setData('code', ''); }}
                    className="mt-3 w-full text-center text-xs text-[var(--color-accent)] hover:underline"
                >
                    {recovery ? 'Zpět na kód z aplikace' : 'Telefon nemám po ruce'}
                </button>
            </form>
        </main>
    );
}
