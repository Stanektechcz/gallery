import { MediaGrid } from '@/Components/MediaGrid';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';

interface Props {
    media: {
        data: any[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
}

export default function InboxIndex({ media }: Props) {
    return (
        <AppLayout>
            <Head title="Nezařazené" />
            <div className="p-4">
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-9 h-9 rounded-lg bg-[var(--color-accent)]/20 flex items-center justify-center">
                        <Inbox size={18} className="text-[var(--color-accent)]" />
                    </div>
                    <div>
                        <h1 className="text-lg font-semibold text-white">Nezařazené</h1>
                        <p className="text-xs text-[var(--color-text-secondary)]">{media.total} médií bez alba</p>
                    </div>
                </div>

                {media.data.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-48 text-[var(--color-text-secondary)]">
                        <Inbox size={40} className="mb-3 opacity-30" />
                        <p>Všechna média jsou zařazena do alb.</p>
                    </div>
                ) : (
                    <>
                        <MediaGrid items={media.data} getHref={i => `/media/${i.uuid}`} />
                        {media.last_page > 1 && (
                            <div className="flex justify-center gap-2 mt-6">
                                {media.links.map((link, i) => (
                                    <button key={i} disabled={!link.url || link.active}
                                        onClick={() => link.url && router.get(link.url)}
                                        className={`px-3 py-1.5 rounded text-xs transition-colors ${link.active ? 'bg-[var(--color-accent)] text-white' : !link.url ? 'text-[var(--color-text-secondary)] opacity-40' : 'bg-[var(--color-bg-card)] border border-[var(--color-border)] text-white hover:border-[var(--color-accent)]/50'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
