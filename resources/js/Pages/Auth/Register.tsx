import { Head, useForm } from '@inertiajs/react';
import { Images } from 'lucide-react';
import { FormEvent } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        space_name: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/registrace');
    }

    const field = 'w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)] transition-colors focus:border-[var(--color-accent)] focus:outline-none';

    return (
        <>
            <Head title="Registrace" />
            <div className="flex min-h-screen items-center justify-center bg-[var(--color-bg-primary)] px-4 py-10">
                <div className="w-full max-w-sm">
                    <div className="mb-8 flex flex-col items-center">
                        <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--color-accent)] shadow-lg shadow-[var(--color-accent)]/30">
                            <Images size={28} className="text-[var(--color-text-primary)]" />
                        </div>
                        <h1 className="text-xl font-semibold text-[var(--color-text-primary)]">Založit galerii</h1>
                        <p className="mt-1 text-center text-sm text-[var(--color-text-secondary)]">
                            Vytvoříme vám vlastní prostor. Za chvíli můžete nahrávat.
                        </p>
                    </div>

                    <form onSubmit={submit} className="glass space-y-4 rounded-2xl p-6">
                        <div>
                            <label className="mb-1.5 block text-sm text-[var(--color-text-secondary)]">Vaše jméno</label>
                            <input value={data.name} onChange={e => setData('name', e.target.value)} required autoFocus className={field} placeholder="Jan Novák" />
                            {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm text-[var(--color-text-secondary)]">E-mail</label>
                            <input type="email" value={data.email} onChange={e => setData('email', e.target.value)} required className={field} placeholder="vas@email.cz" />
                            {errors.email && <p className="mt-1 text-xs text-red-400">{errors.email}</p>}
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm text-[var(--color-text-secondary)]">Název galerie</label>
                            <input value={data.space_name} onChange={e => setData('space_name', e.target.value)} required className={field} placeholder="Naše vzpomínky" />
                            {errors.space_name && <p className="mt-1 text-xs text-red-400">{errors.space_name}</p>}
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm text-[var(--color-text-secondary)]">Heslo</label>
                            <input type="password" value={data.password} onChange={e => setData('password', e.target.value)} required minLength={8} className={field} placeholder="Alespoň 8 znaků" />
                            {errors.password && <p className="mt-1 text-xs text-red-400">{errors.password}</p>}
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm text-[var(--color-text-secondary)]">Heslo znovu</label>
                            <input type="password" value={data.password_confirmation} onChange={e => setData('password_confirmation', e.target.value)} required minLength={8} className={field} placeholder="••••••••" />
                            {errors.password_confirmation && <p className="mt-1 text-xs text-red-400">{errors.password_confirmation}</p>}
                        </div>

                        <button type="submit" disabled={processing} className="w-full rounded-lg bg-[var(--color-accent)] py-2.5 text-sm font-medium text-[var(--color-text-primary)] transition-colors hover:bg-[var(--color-accent-hover)] disabled:opacity-60">
                            {processing ? 'Zakládám…' : 'Založit galerii'}
                        </button>
                    </form>

                    <p className="mt-6 text-center text-xs text-[var(--color-text-secondary)]">
                        Už máte účet? <a href="/login" className="text-[var(--color-accent)] hover:underline">Přihlásit se</a>
                        {' · '}
                        <a href="/cenik" className="text-[var(--color-accent)] hover:underline">Ceník</a>
                    </p>
                </div>
            </div>
        </>
    );
}
