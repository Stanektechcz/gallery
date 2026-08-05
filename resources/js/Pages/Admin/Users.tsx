import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Mail, ShieldCheck, UserPlus, Users as UsersIcon } from 'lucide-react';
import { FormEvent } from 'react';

/** Props mirror AdminController::users(). */
interface AdminUser {
    id: number;
    uuid: string;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    invitation_accepted_at?: string | null;
    last_login_at?: string | null;
    created_at: string;
}

type Props = { users: AdminUser[] };

const ROLE_LABEL: Record<string, string> = {
    owner: 'Vlastník',
    admin: 'Administrátor',
    partner: 'Partner',
    viewer: 'Pouze čtení',
};

const date = (value?: string | null) =>
    value ? new Date(value).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

export default function AdminUsers({ users }: Props) {
    const flash = usePage().props.flash;
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        role: 'viewer',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/admin/users/invite', { onSuccess: () => reset() });
    }

    const field = 'w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none';

    return (
        <AppLayout>
            <Head title="Uživatelé" />
            <main className="mx-auto max-w-6xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Správa systému</p>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">Uživatelé</h1>
                <p className="mt-2 max-w-3xl text-sm text-[var(--color-text-secondary)]">
                    Přehled účtů a pozvánky do galerie. Nový účet vzniká pozvánkou, heslo si nastaví sám příjemce.
                </p>

                {flash?.success && (
                    <p className="mt-4 rounded-xl border border-emerald-400/25 bg-emerald-500/10 p-3 text-xs text-emerald-100">{flash.success}</p>
                )}
                {flash?.error && (
                    <p className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{flash.error}</p>
                )}

                <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-5">
                    <div className="flex items-center gap-2">
                        <UserPlus size={17} className="text-[var(--color-accent)]" />
                        <h2 className="font-semibold text-[var(--color-text-primary)]">Pozvat nového uživatele</h2>
                    </div>
                    <form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_180px_auto]">
                        <div>
                            <input value={data.name} onChange={e => setData('name', e.target.value)} required placeholder="Jméno" className={field} />
                            {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
                        </div>
                        <div>
                            <input type="email" value={data.email} onChange={e => setData('email', e.target.value)} required placeholder="e-mail@domena.cz" className={field} />
                            {errors.email && <p className="mt-1 text-xs text-red-400">{errors.email}</p>}
                        </div>
                        <div>
                            <select value={data.role} onChange={e => setData('role', e.target.value)} className={field}>
                                <option value="viewer">Pouze čtení</option>
                                <option value="partner">Partner</option>
                                <option value="admin">Administrátor</option>
                            </select>
                            {errors.role && <p className="mt-1 text-xs text-red-400">{errors.role}</p>}
                        </div>
                        <button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 rounded-lg bg-[var(--color-accent)] px-5 text-sm font-medium text-[var(--color-text-primary)] transition-colors hover:bg-[var(--color-accent-hover)] disabled:opacity-60"
                        >
                            {processing ? 'Odesílám…' : 'Odeslat pozvánku'}
                        </button>
                    </form>
                </section>

                <section className="mt-6">
                    <div className="mb-3 flex items-center gap-2">
                        <UsersIcon size={17} className="text-[var(--color-accent)]" />
                        <h2 className="font-semibold text-[var(--color-text-primary)]">Účty ({users.length})</h2>
                    </div>

                    <div className="overflow-x-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="border-b border-[var(--color-border)] text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Jméno</th>
                                    <th className="px-4 py-3 font-medium">Role</th>
                                    <th className="px-4 py-3 font-medium">Stav</th>
                                    <th className="px-4 py-3 font-medium">Poslední přihlášení</th>
                                    <th className="px-4 py-3 font-medium">Vytvořen</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map(user => (
                                    <tr key={user.id} className="border-b border-[var(--color-border)] last:border-0">
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-[var(--color-text-primary)]">{user.name}</p>
                                            <p className="flex items-center gap-1 text-xs text-[var(--color-text-secondary)]">
                                                <Mail size={11} />{user.email}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex items-center gap-1 rounded-full bg-[var(--color-accent)]/10 px-2 py-1 text-xs text-[var(--color-accent)]">
                                                <ShieldCheck size={11} />{ROLE_LABEL[user.role] ?? user.role}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {!user.is_active
                                                ? <span className="text-xs text-red-300">Deaktivován</span>
                                                : user.invitation_accepted_at
                                                    ? <span className="text-xs text-emerald-300">Aktivní</span>
                                                    : <span className="text-xs text-amber-300">Čeká na pozvánku</span>}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">{date(user.last_login_at)}</td>
                                        <td className="px-4 py-3 text-xs text-[var(--color-text-secondary)]">{date(user.created_at)}</td>
                                    </tr>
                                ))}
                                {!users.length && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-sm text-[var(--color-text-secondary)]">
                                            Zatím tu není žádný účet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </AppLayout>
    );
}
