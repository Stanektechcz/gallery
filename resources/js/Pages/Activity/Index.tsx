import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Activity } from 'lucide-react';

interface AuditEntry {
    id: number;
    event: string;
    user_name: string;
    description: string;
    created_at: string;
}

interface Props {
    logs: {
        data: AuditEntry[];
        current_page: number;
        last_page: number;
        links: any[];
    };
}

const EVENT_ICONS: Record<string, string> = {
    'media.upload':    '📸',
    'media.trash':     '🗑️',
    'media.restore':   '♻️',
    'media.purge':     '💥',
    'album.create':    '📁',
    'album.update':    '✏️',
    'album.delete':    '🗑️',
    'storage.google.connect':    '🔗',
    'storage.google.disconnect': '🔌',
};

const EVENT_LABELS: Record<string, string> = {
    'media.upload':    'nahrál/a fotografii',
    'media.trash':     'přesunul/a do koše',
    'media.restore':   'obnovil/a z koše',
    'media.purge':     'trvale smazal/a',
    'album.create':    'vytvořil/a album',
    'album.update':    'upravil/a album',
    'album.delete':    'smazal/a album',
    'storage.google.connect':    'připojil/a Google Drive',
    'storage.google.disconnect': 'odpojil/a Google Drive',
};

function timeAgo(d: string): string {
    const diff = Date.now() - new Date(d).getTime();
    const min = Math.floor(diff / 60000);
    if (min < 1)  return 'právě teď';
    if (min < 60) return `před ${min} min`;
    const h = Math.floor(min / 60);
    if (h < 24)  return `před ${h} h`;
    return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' });
}

export default function ActivityIndex({ logs }: Props) {
    return (
        <AppLayout>
            <Head title="Aktivita" />
            <div className="p-4 max-w-2xl mx-auto">
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-9 h-9 rounded-lg bg-[var(--color-accent)]/20 flex items-center justify-center">
                        <Activity size={18} className="text-[var(--color-accent)]" />
                    </div>
                    <h1 className="text-lg font-semibold text-white">Aktivita</h1>
                </div>

                {logs.data.length === 0 ? (
                    <div className="text-center text-[var(--color-text-secondary)] py-12">
                        <Activity size={40} className="mx-auto mb-3 opacity-30" />
                        <p>Žádná aktivita</p>
                    </div>
                ) : (
                    <div className="space-y-1">
                        {logs.data.map(log => (
                            <div key={log.id} className="flex items-start gap-3 px-3 py-2.5 rounded-lg hover:bg-[var(--color-bg-card)] transition-colors">
                                <span className="text-lg shrink-0 mt-0.5">{EVENT_ICONS[log.event] ?? '📋'}</span>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm text-white">
                                        <span className="font-medium text-[var(--color-accent)]">{log.user_name}</span>
                                        {' '}{EVENT_LABELS[log.event] ?? log.event}
                                        {log.description && <span className="text-[var(--color-text-secondary)]"> — {log.description}</span>}
                                    </p>
                                </div>
                                <span className="text-xs text-[var(--color-text-secondary)] shrink-0 mt-0.5">{timeAgo(log.created_at)}</span>
                            </div>
                        ))}
                    </div>
                )}

                {logs.last_page > 1 && (
                    <div className="flex justify-center gap-2 mt-6">
                        {logs.links.map((link, i) => (
                            <button key={i} disabled={!link.url || link.active}
                                onClick={() => link.url && (window.location.href = link.url)}
                                className={`px-3 py-1.5 rounded text-xs ${link.active ? 'bg-[var(--color-accent)] text-white' : !link.url ? 'opacity-40 text-[var(--color-text-secondary)]' : 'bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
