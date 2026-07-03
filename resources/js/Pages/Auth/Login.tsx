import { Head, useForm } from '@inertiajs/react';
import { Images } from 'lucide-react';
import { FormEvent } from 'react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email:    '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Přihlášení" />
            <div className="min-h-screen flex items-center justify-center bg-[var(--color-bg-primary)] px-4">
                <div className="w-full max-w-sm">
                    {/* Logo */}
                    <div className="flex flex-col items-center mb-8">
                        <div className="w-14 h-14 rounded-2xl bg-[var(--color-accent)] flex items-center justify-center mb-4 shadow-lg shadow-[var(--color-accent)]/30">
                            <Images size={28} className="text-white" />
                        </div>
                        <h1 className="text-xl font-semibold text-white">Stanektech Gallery</h1>
                        <p className="text-sm text-[var(--color-text-secondary)] mt-1">Soukromá galerie</p>
                    </div>

                    {/* Form */}
                    <form onSubmit={submit} className="glass rounded-2xl p-6 space-y-4">
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

                        <div>
                            <label className="block text-sm text-[var(--color-text-secondary)] mb-1.5">Heslo</label>
                            <input
                                type="password"
                                value={data.password}
                                onChange={e => setData('password', e.target.value)}
                                required
                                className="w-full bg-white/5 border border-[var(--color-border)] rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-[var(--color-accent)] transition-colors"
                                placeholder="••••••••"
                            />
                            {errors.password && <p className="text-red-400 text-xs mt-1">{errors.password}</p>}
                        </div>

                        <div className="flex items-center justify-between">
                            <label className="flex items-center gap-2 text-sm text-[var(--color-text-secondary)] cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={e => setData('remember', e.target.checked)}
                                    className="rounded"
                                />
                                Zapamatovat
                            </label>
                            <a href="/forgot-password" className="text-sm text-[var(--color-accent)] hover:underline">
                                Zapomenuté heslo?
                            </a>
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)] disabled:opacity-60 text-white font-medium py-2.5 rounded-lg text-sm transition-colors"
                        >
                            {processing ? 'Přihlašuji...' : 'Přihlásit se'}
                        </button>
                    </form>

                    <p className="text-center text-xs text-[var(--color-text-secondary)] mt-6">
                        Přístup pouze po pozvánce
                    </p>
                </div>
            </div>
        </>
    );
}
