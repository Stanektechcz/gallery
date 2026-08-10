import AccountData from '@/Components/AccountData';
import AvatarEditor from '@/Components/AvatarEditor';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { KeyRound, Loader2, Laptop, LogOut, ShieldCheck, UserRound } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Session {
    id: string; user_agent: string; ip_address: string | null;
    last_activity: string; is_current: boolean;
}

interface Profile {
    name: string; email: string; role: string;
    avatar_url: string | null;
    avatar_fallback: { preset: string | null; initial: string; colour: string };
    created_at: string | null; last_login_at: string | null;
    delete_requested_at?: string | null;
}

const stamp = (value: string | null) =>
    value ? new Date(value).toLocaleString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

/**
 * The whole account on one screen: who you are, how you look, how you sign in, and
 * where you are signed in.
 *
 * Previously this page held only the session list, so there was nowhere to change a name,
 * an address or a password at all.
 */
export default function ProfileSettings({ sessions = [] }: { sessions?: Session[] }) {
    const [profile, setProfile] = useState<Profile | null>(null);
    const [items, setItems] = useState<Session[]>(sessions);
    const [form, setForm] = useState({ name: '', email: '', current_password: '' });
    const [pass, setPass] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [busy, setBusy] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const response = await axios.get('/api/v1/profil');
            setProfile(response.data);
            setForm({ name: response.data.name ?? '', email: response.data.email ?? '', current_password: '' });
        } catch { setError('Profil se nepodařilo načíst.'); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const saveProfile = async () => {
        setBusy('profile'); setError(''); setNotice('');
        try {
            const response = await axios.patch('/api/v1/profil', form);
            setProfile(response.data);
            setForm(current => ({ ...current, current_password: '' }));
            setNotice('Profil uložen.');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Profil se nepodařilo uložit.');
        } finally { setBusy(null); }
    };

    const savePassword = async () => {
        setBusy('password'); setError(''); setNotice('');
        try {
            await axios.put('/api/v1/profil/heslo', pass);
            setPass({ current_password: '', password: '', password_confirmation: '' });
            setNotice('Heslo změněno. Ostatní zařízení zůstávají přihlášená — odhlásit je můžete níže.');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Heslo se nepodařilo změnit.');
        } finally { setBusy(null); }
    };

    const revoke = async (id: string) => {
        await axios.delete(`/settings/security/sessions/${id}`);
        setItems(current => current.filter(item => item.id !== id));
        setNotice('Zařízení bylo odhlášeno.');
    };

    const revokeOthers = async () => {
        await axios.post('/settings/security/sessions/revoke-others');
        setItems(current => current.filter(item => item.is_current));
        setNotice('Všechna ostatní zařízení byla odhlášena.');
    };

    const emailChanged = profile !== null && form.email !== profile.email;

    return (
        <AppLayout title="Profil a zabezpečení">
            <Head title="Profil a zabezpečení" />
            <main className="mx-auto max-w-3xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Nastavení</p>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">Profil a zabezpečení</h1>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}
                {notice && <p className="mt-4 rounded-xl border border-emerald-400/25 bg-emerald-500/10 p-3 text-xs text-emerald-100">{notice}</p>}
                {!profile && <div className="mt-8 flex justify-center"><Loader2 className="animate-spin text-[var(--color-accent)]" /></div>}

                {profile && (
                    <>
                        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]">
                                <UserRound size={17} className="text-[var(--color-accent)]" /> Váš profil
                            </h2>

                            <div className="mt-4">
                                <AvatarEditor
                                    url={profile.avatar_url}
                                    fallback={profile.avatar_fallback}
                                    onChange={next => setProfile(current => current ? { ...current, ...next } : current)}
                                />
                            </div>

                            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                <label className="block">
                                    <span className="text-[11px] text-[var(--color-text-secondary)]">Jméno</span>
                                    <input
                                        value={form.name}
                                        onChange={event => setForm({ ...form, name: event.target.value })}
                                        maxLength={120}
                                        className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                                    />
                                </label>
                                <label className="block">
                                    <span className="text-[11px] text-[var(--color-text-secondary)]">E-mail</span>
                                    <input
                                        value={form.email}
                                        onChange={event => setForm({ ...form, email: event.target.value })}
                                        type="email"
                                        className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                                    />
                                </label>
                            </div>

                            {emailChanged && (
                                <label className="mt-3 block">
                                    <span className="text-[11px] text-[var(--color-text-secondary)]">
                                        Změna adresy vyžaduje potvrzení heslem
                                    </span>
                                    <input
                                        value={form.current_password}
                                        onChange={event => setForm({ ...form, current_password: event.target.value })}
                                        type="password"
                                        autoComplete="current-password"
                                        className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                                    />
                                </label>
                            )}

                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <button type="button" disabled={busy === 'profile'} onClick={() => void saveProfile()} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                                    {busy === 'profile' && <Loader2 size={14} className="animate-spin" />} Uložit profil
                                </button>
                                <p className="text-[11px] text-[var(--color-text-secondary)]">
                                    Role: {profile.role} · účet od {stamp(profile.created_at)}
                                </p>
                            </div>
                        </section>

                        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]">
                                <KeyRound size={17} className="text-[var(--color-accent)]" /> Heslo
                            </h2>
                            <div className="mt-3 grid gap-3 sm:grid-cols-3">
                                {([
                                    ['current_password', 'Současné heslo', 'current-password'],
                                    ['password', 'Nové heslo (min. 10 znaků)', 'new-password'],
                                    ['password_confirmation', 'Nové heslo znovu', 'new-password'],
                                ] as const).map(([field, label, complete]) => (
                                    <label key={field} className="block">
                                        <span className="text-[11px] text-[var(--color-text-secondary)]">{label}</span>
                                        <input
                                            value={pass[field]}
                                            onChange={event => setPass({ ...pass, [field]: event.target.value })}
                                            type="password"
                                            autoComplete={complete}
                                            className="mt-1 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)]"
                                        />
                                    </label>
                                ))}
                            </div>
                            <button type="button" disabled={busy === 'password'} onClick={() => void savePassword()} className="mt-3 inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)] disabled:opacity-50">
                                {busy === 'password' && <Loader2 size={14} className="animate-spin" />} Změnit heslo
                            </button>
                        </section>

                        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                            <div className="flex items-center justify-between gap-3">
                                <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]">
                                    <ShieldCheck size={17} className="text-[var(--color-accent)]" /> Přihlášená zařízení
                                </h2>
                                <button type="button" onClick={() => void revokeOthers()} className="rounded-lg border border-[var(--color-border)] px-3 py-2 text-xs text-[var(--color-text-primary)]">Odhlásit ostatní</button>
                            </div>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                Poslední přihlášení {stamp(profile.last_login_at)}. Neznámé zařízení ihned odhlaste.
                            </p>

                            <div className="mt-3 space-y-2">
                                {items.map(item => (
                                    <div key={item.id} className="flex items-center gap-3 rounded-xl border border-[var(--color-border)] p-3">
                                        <Laptop size={17} className="shrink-0 text-[var(--color-accent)]" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm text-[var(--color-text-primary)]">{item.user_agent}{item.is_current ? ' · toto zařízení' : ''}</p>
                                            <p className="text-xs text-[var(--color-text-secondary)]">{item.ip_address || 'IP není dostupná'} · {stamp(item.last_activity)}</p>
                                        </div>
                                        {!item.is_current && (
                                            <button type="button" onClick={() => void revoke(item.id)} aria-label="Odhlásit zařízení" className="rounded-lg p-2 text-red-300 hover:bg-red-500/10"><LogOut size={16} /></button>
                                        )}
                                    </div>
                                ))}
                                {items.length === 0 && (
                                    <p className="text-sm text-[var(--color-text-secondary)]">
                                        Žádná další relace. Seznam se plní jen při ukládání relací do databáze.
                                    </p>
                                )}
                            </div>
                        </section>

                        <AccountData scheduledFor={profile.delete_requested_at ?? null} onChanged={() => void load()} />

                        <p className="mt-5 text-center text-xs text-[var(--color-text-secondary)]">
                            <Link href="/settings/propojeni" className="text-[var(--color-accent)] hover:underline">Propojení služeb</Link>
                            {' · '}
                            <Link href="/settings/vzhled" className="text-[var(--color-accent)] hover:underline">Vzhled a barvy</Link>
                        </p>
                    </>
                )}
            </main>
        </AppLayout>
    );
}
