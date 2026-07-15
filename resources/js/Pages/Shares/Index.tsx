import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { clsx } from 'clsx';
import {
    CheckCircle,
    Clock,
    Copy,
    Download,
    ExternalLink,
    Eye, EyeOff,
    Link as LinkIcon,
    Lock,
    Plus,
    Share2,
    Trash2,
    Upload
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface SharedLink {
    id: number;
    token: string;
    name: string | null;
    target_type: string;
    target_id: number | null;
    allow_download: boolean;
    allow_guest_upload: boolean;
    hide_gps: boolean;
    show_metadata: boolean;
    password_hash: string | null;
    has_password: boolean;
    target?: { type:string; label:string; title:string; uuid?:string|null };
    max_uses: number | null;
    use_count: number;
    expires_at: string | null;
    is_active: boolean;
    created_at: string;
}

interface Props {
    shares: {
        data: SharedLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function isExpired(expiresAt: string | null): boolean {
    if (!expiresAt) return false;
    return new Date(expiresAt) < new Date();
}

interface NewLinkForm {
    name: string;
    target_type: string;
    password: string;
    expires_at: string;
    allow_download: boolean;
    allow_guest_upload: boolean;
    hide_gps: boolean;
    max_uses: string;
}

export default function SharesIndex({ shares }: Props) {
    const [items, setItems]         = useState<SharedLink[]>(shares.data);
    const [showCreate, setShowCreate] = useState(false);
    const [creating, setCreating]   = useState(false);
    const [copied, setCopied]       = useState<string | null>(null);
    const [guestUploads, setGuestUploads] = useState<any[]>([]);
    const [form, setForm]           = useState<NewLinkForm>({
        name: '',
        target_type: 'selection',
        password: '',
        expires_at: '',
        allow_download: true,
        allow_guest_upload: false,
        hide_gps: false,
        max_uses: '',
    });
    useEffect(() => { axios.get('/api/v1/guest-uploads').then(response => setGuestUploads(response.data.data.filter((item: any) => item.status === 'pending'))).catch(() => undefined); }, []);
    const reviewUpload = async (uuid: string, action: 'approve'|'reject') => {
        if (action === 'reject' && !confirm('Odmítnout a smazat tento soubor?')) return;
        await axios.post(`/api/v1/guest-uploads/${uuid}/${action}`);
        setGuestUploads(items => items.filter(item => item.uuid !== uuid));
    };

    const setF = <K extends keyof NewLinkForm>(key: K, val: NewLinkForm[K]) =>
        setForm(prev => ({ ...prev, [key]: val }));

    const createLink = async (e: React.FormEvent) => {
        e.preventDefault();
        setCreating(true);
        try {
            const payload: Record<string, unknown> = {
                target_type:        form.target_type,
                allow_download:     form.allow_download,
                allow_guest_upload: form.allow_guest_upload,
                hide_gps:           form.hide_gps,
            };
            if (form.name)       payload.name       = form.name;
            if (form.password)   payload.password   = form.password;
            if (form.expires_at) payload.expires_at = form.expires_at;
            if (form.max_uses)   payload.max_uses   = parseInt(form.max_uses);

            const res = await axios.post('/api/v1/shares', payload);
            // Reload to get the new link in the list
            window.location.reload();
        } catch (err: unknown) {
            alert('Nepodařilo se vytvořit odkaz');
        } finally {
            setCreating(false);
        }
    };

    const deleteLink = async (id: number) => {
        if (!confirm('Smazat sdílený odkaz?')) return;
        await axios.delete(`/api/v1/shares/${id}`);
        setItems(prev => prev.filter(l => l.id !== id));
    };

    const copyLink = (token: string) => {
        const url = `${window.location.origin}/s/${token}`;
        navigator.clipboard.writeText(url).then(() => {
            setCopied(token);
            setTimeout(() => setCopied(null), 2000);
        });
    };

    const openLink = (token: string) => {
        window.open(`/s/${token}`, '_blank');
    };

    return (
        <AppLayout>
            <Head title="Sdílené" />
            <div className="min-h-full">
                {/* Header */}
                <div className="sticky top-0 z-20 px-4 py-3 border-b border-[var(--color-border)] bg-[var(--color-bg-primary)]/90 backdrop-blur-sm">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Share2 size={16} className="text-[var(--color-accent)]" />
                            <h1 className="text-sm font-semibold text-white">Sdílené odkazy</h1>
                            <span className="text-xs text-[var(--color-text-secondary)]">{items.length} odkazů</span>
                        </div>
                        <button
                            onClick={() => setShowCreate(true)}
                            className="flex items-center gap-1.5 bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)] text-white text-sm px-3 py-1.5 rounded-lg transition-colors"
                        >
                            <Plus size={14} /> Nový odkaz
                        </button>
                    </div>
                </div>

                <div className="p-4 max-w-2xl mx-auto">
                    {guestUploads.length > 0 && <section className="mb-5 rounded-2xl border border-[var(--color-accent)]/30 bg-[var(--color-bg-card)] p-4"><div className="mb-3 flex items-center gap-2"><Upload size={16} className="text-[var(--color-accent)]"/><h2 className="text-sm font-semibold text-white">Čeká na schválení</h2><span className="rounded-full bg-[var(--color-accent)]/15 px-2 py-0.5 text-[10px] text-[var(--color-accent)]">{guestUploads.length}</span></div><div className="space-y-2">{guestUploads.map(upload => <div key={upload.uuid} className="flex flex-col gap-2 rounded-xl border border-[var(--color-border)] p-3 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><p className="truncate text-xs font-medium text-white">{upload.original_filename}</p><p className="text-[10px] text-[var(--color-text-secondary)]">{upload.contributor_name || 'Anonymní host'} · {Math.round(upload.size_bytes/1024/1024*10)/10} MB</p></div><div className="flex gap-2"><button onClick={() => reviewUpload(upload.uuid,'approve')} className="min-h-10 flex-1 rounded-lg bg-green-500/15 px-3 text-xs text-green-400">Přijmout</button><button onClick={() => reviewUpload(upload.uuid,'reject')} className="min-h-10 flex-1 rounded-lg bg-red-500/10 px-3 text-xs text-red-400">Odmítnout</button></div></div>)}</div></section>}
                    {items.length === 0 ? (
                        <div className="flex flex-col items-center justify-center h-64 text-[var(--color-text-secondary)]">
                            <Share2 size={48} className="mb-3 opacity-20" />
                            <p className="text-lg font-medium text-white mb-1">Žádné sdílené odkazy</p>
                            <p className="text-sm">Vytvořte odkaz pro sdílení alb nebo fotek</p>
                            <button
                                onClick={() => setShowCreate(true)}
                                className="mt-4 flex items-center gap-2 bg-[var(--color-accent)] text-white text-sm px-4 py-2 rounded-lg"
                            >
                                <Plus size={14} /> Vytvořit první odkaz
                            </button>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {items.map(link => {
                                const expired = isExpired(link.expires_at);
                                const url     = `${window.location.origin}/s/${link.token}`;

                                return (
                                    <div key={link.id} className={clsx('glass rounded-xl p-4 transition-all', expired && 'opacity-60')}>
                                        {/* Link header */}
                                        <div className="flex items-start justify-between gap-3 mb-3">
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2 mb-1">
                                                    <LinkIcon size={13} className="text-[var(--color-accent)] shrink-0" />
                                                    <p className="text-sm font-medium text-white truncate">
                                                        {link.name || link.target?.title || `Sdílený ${link.target_type}`}
                                                    </p>
                                                    {expired && (
                                                        <span className="text-[10px] bg-red-500/20 text-red-400 px-1.5 py-0.5 rounded">Vypršel</span>
                                                    )}
                                                    {!expired && link.is_active && (
                                                        <span className="text-[10px] bg-green-500/20 text-green-400 px-1.5 py-0.5 rounded">Aktivní</span>
                                                    )}
                                                </div>
                                                <p className="text-[10px] text-violet-300">{link.target?.label || 'Sdílený obsah'}{link.target?.title && link.target.title !== link.name ? ` · ${link.target.title}` : ''}</p>
                                                <p className="mt-0.5 truncate font-mono text-xs text-[var(--color-text-secondary)]">{url}</p>
                                            </div>
                                            <div className="flex items-center gap-1 shrink-0">
                                                <button
                                                    onClick={() => copyLink(link.token)}
                                                    title="Kopírovat odkaz"
                                                    className="p-1.5 rounded-lg hover:bg-white/10 text-[var(--color-text-secondary)] hover:text-white transition-colors"
                                                >
                                                    {copied === link.token
                                                        ? <CheckCircle size={14} className="text-green-400" />
                                                        : <Copy size={14} />
                                                    }
                                                </button>
                                                <button
                                                    onClick={() => openLink(link.token)}
                                                    title="Otevřít odkaz"
                                                    className="p-1.5 rounded-lg hover:bg-white/10 text-[var(--color-text-secondary)] hover:text-white transition-colors"
                                                >
                                                    <ExternalLink size={14} />
                                                </button>
                                                <button
                                                    onClick={() => deleteLink(link.id)}
                                                    title="Smazat odkaz"
                                                    className="p-1.5 rounded-lg hover:bg-red-500/20 text-[var(--color-text-secondary)] hover:text-red-400 transition-colors"
                                                >
                                                    <Trash2 size={14} />
                                                </button>
                                            </div>
                                        </div>

                                        {/* Link properties */}
                                        <div className="flex flex-wrap gap-2">
                                            {link.has_password && (
                                                <span className="flex items-center gap-1 text-[10px] bg-white/5 text-[var(--color-text-secondary)] px-2 py-0.5 rounded-full">
                                                    <Lock size={9} /> Zaheslováno
                                                </span>
                                            )}
                                            {!['recipe','place_review'].includes(link.target_type) && (link.allow_download ? (
                                                <span className="flex items-center gap-1 text-[10px] bg-white/5 text-[var(--color-text-secondary)] px-2 py-0.5 rounded-full">
                                                    <Download size={9} /> Stahování povoleno
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1 text-[10px] bg-white/5 text-[var(--color-text-secondary)] px-2 py-0.5 rounded-full">
                                                    <EyeOff size={9} /> Bez stahování
                                                </span>
                                            ))}
                                            {link.allow_guest_upload && (
                                                <span className="flex items-center gap-1 text-[10px] bg-white/5 text-[var(--color-text-secondary)] px-2 py-0.5 rounded-full">
                                                    <Upload size={9} /> Host může nahrát
                                                </span>
                                            )}
                                            {link.hide_gps && (
                                                <span className="flex items-center gap-1 text-[10px] bg-white/5 text-[var(--color-text-secondary)] px-2 py-0.5 rounded-full">
                                                    <EyeOff size={9} /> GPS skryto
                                                </span>
                                            )}
                                            {link.expires_at && (
                                                <span className={clsx('flex items-center gap-1 text-[10px] px-2 py-0.5 rounded-full', expired ? 'bg-red-500/10 text-red-400' : 'bg-white/5 text-[var(--color-text-secondary)]')}>
                                                    <Clock size={9} /> {expired ? 'Vypršelo' : 'Vyprší'} {formatDate(link.expires_at)}
                                                </span>
                                            )}
                                            <span className="flex items-center gap-1 text-[10px] bg-white/5 text-[var(--color-text-secondary)] px-2 py-0.5 rounded-full">
                                                <Eye size={9} /> {link.use_count}{link.max_uses ? `/${link.max_uses}` : ''} zobrazení
                                            </span>
                                            <span className="flex items-center gap-1 text-[10px] bg-white/5 text-[var(--color-text-secondary)] px-2 py-0.5 rounded-full">
                                                Vytvořeno {formatDate(link.created_at)}
                                            </span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Create Link Modal */}
                {showCreate && (
                    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/60 backdrop-blur-sm p-4">
                        <div className="glass rounded-2xl p-6 w-full max-w-md shadow-2xl max-h-[90vh] overflow-y-auto">
                            <div className="flex items-center justify-between mb-5">
                                <h2 className="text-base font-semibold text-white">Nový sdílený odkaz</h2>
                                <button onClick={() => setShowCreate(false)} className="text-[var(--color-text-secondary)] hover:text-white p-1">✕</button>
                            </div>

                            <form onSubmit={createLink} className="space-y-4">
                                <div>
                                    <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Název (volitelný)</label>
                                    <input
                                        type="text"
                                        value={form.name}
                                        onChange={e => setF('name', e.target.value)}
                                        placeholder="Např. Fotky z dovolené"
                                        className="w-full bg-white/5 border border-[var(--color-border)] rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-[var(--color-accent)]"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Heslo (volitelné)</label>
                                    <input
                                        type="password"
                                        value={form.password}
                                        onChange={e => setF('password', e.target.value)}
                                        placeholder="Ponechte prázdné pro veřejný odkaz"
                                        className="w-full bg-white/5 border border-[var(--color-border)] rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-[var(--color-accent)]"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Platí do (volitelné)</label>
                                    <input
                                        type="datetime-local"
                                        value={form.expires_at}
                                        onChange={e => setF('expires_at', e.target.value)}
                                        className="w-full bg-white/5 border border-[var(--color-border)] rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-[var(--color-accent)]"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs text-[var(--color-text-secondary)] mb-1">Max. zobrazení (volitelné)</label>
                                    <input
                                        type="number"
                                        value={form.max_uses}
                                        onChange={e => setF('max_uses', e.target.value)}
                                        placeholder="Neomezeno"
                                        min="1"
                                        className="w-full bg-white/5 border border-[var(--color-border)] rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-[var(--color-accent)]"
                                    />
                                </div>

                                {/* Toggles */}
                                <div className="space-y-3 pt-1">
                                    {[
                                        { key: 'allow_download' as const, label: 'Povolit stahování', icon: Download },
                                        { key: 'allow_guest_upload' as const, label: 'Povolit nahrání hostovi', icon: Upload },
                                        { key: 'hide_gps' as const, label: 'Skrýt GPS souřadnice', icon: EyeOff },
                                    ].map(({ key, label, icon: Icon }) => (
                                        <label key={key} className="flex items-center justify-between cursor-pointer">
                                            <div className="flex items-center gap-2">
                                                <Icon size={13} className="text-[var(--color-text-secondary)]" />
                                                <span className="text-sm text-[var(--color-text-secondary)]">{label}</span>
                                            </div>
                                            <div
                                                onClick={() => setF(key, !form[key])}
                                                className={clsx(
                                                    'w-10 h-5 rounded-full transition-colors cursor-pointer relative',
                                                    form[key] ? 'bg-[var(--color-accent)]' : 'bg-white/10'
                                                )}
                                            >
                                                <div className={clsx(
                                                    'absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform',
                                                    form[key] ? 'translate-x-5' : 'translate-x-0.5'
                                                )} />
                                            </div>
                                        </label>
                                    ))}
                                </div>

                                <div className="flex gap-3 pt-2">
                                    <button type="button" onClick={() => setShowCreate(false)} className="flex-1 bg-white/10 hover:bg-white/15 text-white text-sm py-2.5 rounded-lg">
                                        Zrušit
                                    </button>
                                    <button type="submit" disabled={creating} className="flex-1 bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)] disabled:opacity-50 text-white text-sm py-2.5 rounded-lg flex items-center justify-center gap-2">
                                        {creating ? (
                                            <div className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                        ) : (
                                            <><Plus size={14} /> Vytvořit odkaz</>
                                        )}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
