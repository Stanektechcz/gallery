import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { Tag } from 'lucide-react';
import { useEffect, useState } from 'react';

interface TagItem { id: number; name: string; slug: string; color?: string; depth: number; media_count?: number; children?: TagItem[] }

function TagRow({ tag, level = 0 }: { tag: TagItem; level?: number }) {
    return (
        <>
            <Link href={`/search?tag_id=${tag.id}`}
                className="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-[var(--color-bg-card)] transition-colors group">
                <div style={{ marginLeft: level * 16 }} className="flex items-center gap-2 flex-1 min-w-0">
                    <div className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: tag.color || 'var(--color-accent)' }} />
                    <span className="text-sm text-white truncate">{tag.name}</span>
                    {level > 0 && <span className="text-[10px] text-[var(--color-text-secondary)]">/{tag.slug}</span>}
                </div>
                {tag.media_count ? (
                    <span className="text-xs text-[var(--color-text-secondary)] shrink-0">{tag.media_count}</span>
                ) : null}
            </Link>
            {tag.children?.map(c => <TagRow key={c.id} tag={c} level={level + 1} />)}
        </>
    );
}

export default function TagsIndex() {
    const [tags, setTags] = useState<TagItem[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        axios.get('/api/v1/tags').then(r => {
            setTags(r.data.data ?? r.data ?? []);
        }).finally(() => setLoading(false));
    }, []);

    // Build tree from flat list
    const buildTree = (items: TagItem[]): TagItem[] => {
        const map: Record<number, TagItem> = {};
        items.forEach(t => { map[t.id] = { ...t, children: [] }; });
        const roots: TagItem[] = [];
        items.forEach(t => {
            if (t.depth === 0) roots.push(map[t.id]);
            // Note: simple flat display since parent_id not always available
        });
        return roots.length > 0 ? roots : items.map(t => ({ ...t, children: [] }));
    };

    const tree = buildTree(tags);

    return (
        <AppLayout>
            <Head title="Tagy" />
            <div className="p-4">
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-9 h-9 rounded-lg bg-[var(--color-accent)]/20 flex items-center justify-center">
                        <Tag size={18} className="text-[var(--color-accent)]" />
                    </div>
                    <div>
                        <h1 className="text-lg font-semibold text-white">Tagy</h1>
                        <p className="text-xs text-[var(--color-text-secondary)]">{tags.length} tagů</p>
                    </div>
                </div>

                {loading ? (
                    <div className="space-y-2">
                        {Array.from({length:8}).map((_,i) => <div key={i} className="h-9 bg-[var(--color-bg-card)] rounded-lg animate-pulse"/>)}
                    </div>
                ) : tree.length === 0 ? (
                    <div className="text-center py-12 text-[var(--color-text-secondary)]">
                        <Tag size={40} className="mx-auto mb-3 opacity-30" />
                        <p>Žádné tagy</p>
                        <p className="text-sm mt-1">Přidejte tagy k fotografiím v detailu fotografie</p>
                    </div>
                ) : (
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl p-2">
                        {tree.map(t => <TagRow key={t.id} tag={t} />)}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
