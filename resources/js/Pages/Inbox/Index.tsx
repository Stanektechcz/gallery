import { MediaGrid } from '@/Components/MediaGrid';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { CheckSquare, Gift, Inbox, MapPinned, Sparkles } from 'lucide-react';

interface ActionItem {
    key: string;
    type: string;
    title: string;
    due_at?: string | null;
    href: string;
    tone: 'violet' | 'sky' | 'pink' | 'emerald';
}
interface LifeEvent {
    uuid: string;
    kind: string;
    title: string;
    source: string;
    occurred_at?: string | null;
    href: string;
}
interface Props {
    media: { data: any[]; current_page: number; last_page: number; total: number; links: any[] };
    actionItems: ActionItem[];
    recentLifeEvents: LifeEvent[];
}

const tone = {
    violet: 'border-violet-400/25 bg-violet-500/10 text-violet-100',
    sky: 'border-sky-400/25 bg-sky-500/10 text-sky-100',
    pink: 'border-pink-400/25 bg-pink-500/10 text-pink-100',
    emerald: 'border-emerald-400/25 bg-emerald-500/10 text-emerald-100',
};

function ActionIcon({ type }: { type: string }) {
    if (type === 'Cestovní podklad') return <MapPinned size={16} />;
    if (type === 'Dárek') return <Gift size={16} />;
    if (type === 'Společný úkol') return <CheckSquare size={16} />;
    return <Sparkles size={16} />;
}

const eventKindLabel: Record<string, string> = {
    'calendar.event.created': 'Nová událost',
    'planning.todo.created': 'Společný úkol',
    'shopping.item.created': 'Položka nákupu',
    'watchlist.proposed': 'Na seznam ke zhlédnutí',
    'recipe.created': 'Nový recept',
    'trip.created': 'Nová cesta',
    'trip.itinerary.drafted': 'Návrh itineráře',
    'finance.expense.recorded': 'Zapsaný výdaj',
    'gift.idea.created': 'Nápad na dárek',
    'milestone.created': 'Výročí',
    'gift.lifecycle.idea': 'Dárek: nápad',
    'gift.lifecycle.planned': 'Dárek: naplánován',
    'gift.lifecycle.purchased': 'Dárek: koupen',
    'gift.lifecycle.wrapped': 'Dárek: zabalen',
    'gift.lifecycle.given': 'Dárek: předán',
};

function eventSourceLabel(source: string) {
    return source === 'assistant' ? 'Chat' : source === 'manual' ? 'Ruční zápis' : 'Kalendář';
}

export default function InboxIndex({ media, actionItems, recentLifeEvents }: Props) {
    return <AppLayout><Head title="Akční inbox" /><main className="mx-auto w-full max-w-none p-4 sm:p-6 xl:p-8">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-3"><div className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--color-accent)]/20"><Inbox size={20} className="text-[var(--color-accent)]" /></div><div><h1 className="text-xl font-semibold text-[var(--color-text-primary)]">Akční inbox</h1><p className="text-sm text-[var(--color-text-secondary)]">Věci, které čekají na zařazení, rozhodnutí nebo dokončení.</p></div></div>
            <Link href="/planning" className="inline-flex min-h-10 items-center justify-center rounded-xl border border-[var(--color-border)] px-3 text-sm text-[var(--color-text-primary)] hover:border-[var(--color-accent)]">Otevřít plánování</Link>
        </div>

        <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:p-5">
            <div className="flex items-center justify-between gap-3"><div><h2 className="font-semibold text-[var(--color-text-primary)]">Co potřebuje pozornost</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Každá položka vede přímo do místa, kde ji lze vyřešit.</p></div><span className="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-1 text-xs text-[var(--color-text-secondary)]">{actionItems.length}</span></div>
            {actionItems.length === 0 ? <div className="py-8 text-center text-sm text-[var(--color-text-secondary)]"><CheckSquare size={28} className="mx-auto mb-2 text-emerald-300" />Nic rozpracovaného tu teď není. Můžete naplánovat další společnou věc.</div> : <div className="mt-4 grid gap-2 lg:grid-cols-2">{actionItems.map((item) => <Link key={item.key} href={item.href} className={`flex min-h-16 items-center gap-3 rounded-xl border p-3 transition hover:brightness-110 ${tone[item.tone]}`}><span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--color-surface-muted)]"><ActionIcon type={item.type} /></span><span className="min-w-0 flex-1"><span className="block text-[10px] font-medium uppercase tracking-wide opacity-70">{item.type}</span><span className="block truncate text-sm font-medium">{item.title}</span></span>{item.due_at && <time className="shrink-0 text-[10px] opacity-70">{new Date(`${item.due_at}`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' })}</time>}</Link>)}</div>}
        </section>

        <section className="mt-6 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4 sm:p-5">
            <div className="flex items-center justify-between gap-3"><div><h2 className="font-semibold text-[var(--color-text-primary)]">Poslední společné záznamy</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">Jednotná historie ukazuje, odkud položka přišla a kde ji dále spravovat.</p></div><span className="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-1 text-xs text-[var(--color-text-secondary)]">{recentLifeEvents.length}</span></div>
            {recentLifeEvents.length === 0 ? (
                <p className="py-6 text-center text-sm text-[var(--color-text-secondary)]">První společný zápis se zde objeví po uložení aktivity, cesty, receptu nebo výdaje.</p>
            ) : (
                <div className="mt-4 grid gap-2 lg:grid-cols-2">
                    {recentLifeEvents.map((event) => (
                        <Link key={event.uuid} href={event.href} className="flex min-h-14 items-center gap-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3 hover:border-[var(--color-accent)]/50">
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--color-accent)]/15 text-[var(--color-accent)]"><Sparkles size={16}/></span>
                            <span className="min-w-0 flex-1"><span className="block text-[10px] uppercase tracking-wide text-[var(--color-text-secondary)]">{eventKindLabel[event.kind] ?? event.kind.replaceAll('.', ' · ')} · {eventSourceLabel(event.source)}</span><span className="block truncate text-sm font-medium text-[var(--color-text-primary)]">{event.title}</span></span>
                            {event.occurred_at && <time className="shrink-0 text-[10px] text-[var(--color-text-secondary)]">{new Date(event.occurred_at).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' })}</time>}
                        </Link>
                    ))}
                </div>
            )}
        </section>

        <section className="mt-6">
            <div className="flex items-end justify-between gap-3"><div><h2 className="text-lg font-semibold text-[var(--color-text-primary)]">Média bez alba</h2><p className="mt-1 text-xs text-[var(--color-text-secondary)]">{media.total} médií čeká na zařazení do příběhu nebo alba.</p></div></div>
            {media.data.length === 0 ? <div className="mt-4 flex h-40 flex-col items-center justify-center rounded-2xl border border-dashed border-[var(--color-border)] text-[var(--color-text-secondary)]"><Inbox size={36} className="mb-3 opacity-30" /><p>Všechna média jsou zařazena do alb.</p></div> : <><div className="mt-4"><MediaGrid items={media.data} getHref={(item) => `/media/${item.uuid}`} /></div>{media.last_page > 1 && <div className="mt-6 flex justify-center gap-2">{media.links.map((link, index) => <button key={index} disabled={!link.url || link.active} onClick={() => link.url && router.get(link.url)} className={`min-h-10 rounded-lg px-3 text-xs transition-colors ${link.active ? 'bg-[var(--color-accent)] text-[var(--color-text-primary)]' : !link.url ? 'text-[var(--color-text-secondary)] opacity-40' : 'border border-[var(--color-border)] bg-[var(--color-bg-card)] text-[var(--color-text-primary)] hover:border-[var(--color-accent)]/50'}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}</>}
        </section>
    </main></AppLayout>;
}