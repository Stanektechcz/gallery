import { Head, useForm, usePage } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { FormEvent } from 'react';

export default function ForgotPassword() {
    const flash = usePage().props.flash;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <>
            <Head title="Zapomenuté heslo" />
            <div className="min-h-screen flex items-center justify-center bg-[var(--color-bg-primary)] px-4">
                <div className="w-full max-w-sm">
                    <div className="flex flex-col items-center mb-8">
                        <div className="w-14 h-14 rounded-2xl bg-[var(--color-accent)] flex items-center justify-center mb-4 shadow-lg shadow-[var(--color-accent)]/30">
                            <KeyRound size={28} className="text-white" />
                        </div>
                        <h1 className="text-xl font-semibold text-white">Zapomenuté heslo</h1>
                        <p className="text-sm text-[var(--color-text-secondary)] mt-1 text-center">
                            Pošleme vám odkaz pro nastavení nového hesla.
                        </p>
                    </div>

                    <form onSubmit={submit} className="glass rounded-2xl p-6 space-y-4">
                        {flash?.success && (
                            <p className="rounded-lg border border-emerald-400/25 bg-emerald-500/10 p-3 text-xs text-emerald-100">
                                {flash.success}
                            </p>
                        )}

                        <div>
                            <label className="block text-sm text-[var(--color-text-secondary)] mb-1.5">E-mail</label>
                            <input
                                type="email"
                                value={data.email}
                                onChange={e => setData('email', e.target.value)}
                                required
                                autoFocus
                                className="w-full bg-white/5 border border-[var(--color-border)] rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                                placeholder="vas@email.cz"
                            />
                            {errors.email && <p className="text-red-400 text-xs mt-1">{errors.email}</p>}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)] disabled:opacity-60 text-white font-medium py-2.5 rounded-lg text-sm transition-colors"
                        >
                            {processing ? 'Odesílám...' : 'Odeslat odkaz'}
                        </button>
                    </form>

                    <p className="text-center text-xs text-[var(--color-text-secondary)] mt-6">
                        <a href="/login" className="text-[var(--color-accent)] hover:underline">Zpět na přihlášení</a>
                    </p>
                </div>
            </div>
        </>
    );
}
