import { Head, useForm } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import { FormEvent } from 'react';

/** Props mirror PasswordResetController::reset(). */
type Props = { token: string; email?: string | null };

export default function ResetPassword({ token, email }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/reset-password');
    }

    const field = 'w-full bg-white/5 border border-[var(--color-border)] rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors';

    return (
        <>
            <Head title="Nové heslo" />
            <div className="min-h-screen flex items-center justify-center bg-[var(--color-bg-primary)] px-4">
                <div className="w-full max-w-sm">
                    <div className="flex flex-col items-center mb-8">
                        <div className="w-14 h-14 rounded-2xl bg-[var(--color-accent)] flex items-center justify-center mb-4 shadow-lg shadow-[var(--color-accent)]/30">
                            <LockKeyhole size={28} className="text-white" />
                        </div>
                        <h1 className="text-xl font-semibold text-white">Nové heslo</h1>
                        <p className="text-sm text-[var(--color-text-secondary)] mt-1">Zvolte si heslo alespoň o 8 znacích.</p>
                    </div>

                    <form onSubmit={submit} className="glass rounded-2xl p-6 space-y-4">
                        <div>
                            <label className="block text-sm text-[var(--color-text-secondary)] mb-1.5">E-mail</label>
                            <input
                                type="email"
                                value={data.email}
                                onChange={e => setData('email', e.target.value)}
                                required
                                autoFocus={!email}
                                className={field}
                                placeholder="vas@email.cz"
                            />
                            {errors.email && <p className="text-red-400 text-xs mt-1">{errors.email}</p>}
                        </div>

                        <div>
                            <label className="block text-sm text-[var(--color-text-secondary)] mb-1.5">Nové heslo</label>
                            <input
                                type="password"
                                value={data.password}
                                onChange={e => setData('password', e.target.value)}
                                required
                                minLength={8}
                                autoFocus={!!email}
                                className={field}
                                placeholder="••••••••"
                            />
                            {errors.password && <p className="text-red-400 text-xs mt-1">{errors.password}</p>}
                        </div>

                        <div>
                            <label className="block text-sm text-[var(--color-text-secondary)] mb-1.5">Heslo znovu</label>
                            <input
                                type="password"
                                value={data.password_confirmation}
                                onChange={e => setData('password_confirmation', e.target.value)}
                                required
                                minLength={8}
                                className={field}
                                placeholder="••••••••"
                            />
                            {errors.password_confirmation && <p className="text-red-400 text-xs mt-1">{errors.password_confirmation}</p>}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)] disabled:opacity-60 text-white font-medium py-2.5 rounded-lg text-sm transition-colors"
                        >
                            {processing ? 'Ukládám...' : 'Nastavit heslo'}
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
