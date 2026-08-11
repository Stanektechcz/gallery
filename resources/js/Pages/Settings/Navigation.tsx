import AppLayout, { flattenNavItems, navGroups, PINNED_NAV_KEY } from '@/Layouts/AppLayout';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Eye, EyeOff,
    FolderPlus, GripVertical, Loader2, RotateCcw, Save,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface Row {
    /** Stable identity: href for a real item, `#n` for a heading somebody invented. */
    key: string;
    href: string | null;
    label: string | null;
    /** Sections only; the sidebar draws it under the heading. */
    description?: string | null;
    defaultLabel: string;
    hidden: boolean;
    group: boolean;
    /** How deep it sits. Nesting is unlimited; this is the whole of the tree. */
    depth: number;
}

/**
 * Rearranging the menu.
 *
 * The list is flat and each row carries a depth, rather than rows holding children. The
 * two describe the same tree, but only one of them can be dragged without the drop target
 * moving underneath the thing being dropped — which is why every outliner ever written
 * uses this shape.
 *
 * Dragging is for pointers. The arrows and the indent buttons do the same job and are
 * kept, not as a fallback but as the version that works on a phone and from a keyboard:
 * HTML5 drag events never fire on touch, and a screen reader has nothing to grab.
 */
export default function NavigationSettings() {
    const page = usePage().props as {
        navigation?: Array<{
            key?: string | null; href: string | null; label: string | null;
            description?: string | null;
            hidden: boolean; group: boolean; parent: string | null;
        }> | null;
        features?: string[] | null;
    };

    /**
     * Every item the sidebar can show, taken from the sidebar itself.
     *
     * This previously read a prop that nothing on the server ever sent, so the list was
     * empty. Importing the real menu means the two cannot disagree, and a newly shipped
     * item appears here without anybody remembering a second place to add it.
     *
     * Items the plan does not include are left out: offering to rearrange something the
     * person cannot open would be arranging a menu they will never see.
     */
    const defaults = useMemo(() => {
        const features = page.features ?? null;

        // Deduplicated by href, and this is not belt and braces: the menu briefly listed
        // one page under two labels, which gave this list two rows with one identity. React
        // keys them by href, so moving either made both jump — reported, correctly, as
        // items duplicating. A list that cannot hold the same href twice cannot do that.
        const seen = new Map<string, { href: string; label: string; section: string }>();

        for (const group of navGroups) {
            for (const item of flattenNavItems(group.items)) {
                if (item.feature && features && !features.includes(item.feature)) continue;
                if (!item.href || seen.has(item.href)) continue;

                // Where it lives by default, carried so the editor can say where a row
                // came from. Forty rows in one column is a list; forty rows that each say
                // which part of the app they belong to is something you can manage.
                seen.set(item.href, { href: item.href, label: item.label, section: group.label });
            }
        }

        return [...seen.values()];
    }, [page.features]);

    const sectionOf = useMemo(
        () => new Map(defaults.map(item => [item.href, item.section])),
        [defaults],
    );

    const [rows, setRows] = useState<Row[]>(() => {
        const saved = page.navigation ?? [];
        const keyOf = (row: typeof saved[number], index: number) => row.key ?? row.href ?? `#${index}`;

        // Depth comes from walking each row's parent chain. The step limit is defensive:
        // the server refuses cycles, but a menu that hangs the settings page would be a
        // poor way to find out it stopped doing so.
        const byKey = new Map(saved.map((row, index) => [keyOf(row, index), row]));
        const depthOf = (row: typeof saved[number]): number => {
            let depth = 0;
            let cursor = row.parent;
            while (cursor && depth < 50) { depth++; cursor = byKey.get(cursor)?.parent ?? null; }

            return depth;
        };

        const known = new Set(saved.map(row => row.href).filter(Boolean));

        const fromSaved: Row[] = saved
            .filter(row => row.group || (row.href && defaults.some(item => item.href === row.href)))
            .map((row, index) => ({
                key: keyOf(row, index),
                href: row.href,
                label: row.label,
                description: row.description ?? null,
                defaultLabel: defaults.find(item => item.href === row.href)?.label ?? 'Sekce',
                hidden: row.hidden,
                group: row.group,
                depth: depthOf(row),
            }));

        // Anything shipped since they last saved goes to the bottom rather than nowhere.
        const missing: Row[] = defaults
            .filter(item => !known.has(item.href))
            .map(item => ({
                key: item.href, href: item.href, label: null,
                defaultLabel: item.label, hidden: false, group: false, depth: 0,
            }));

        // Nothing saved yet: start from the sidebar as it stands, sections and all.
        //
        // A flat list of forty rows was the wrong starting point. It threw away the
        // grouping the app ships with before anybody had asked for anything different,
        // so the first save silently flattened a menu the person was happy with. The
        // editor's opening state should be what they are already looking at.
        if (fromSaved.length === 0) return seedFromSidebar(page.features ?? null);

        return [...fromSaved, ...missing];
    });

    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [dragging, setDragging] = useState<number | null>(null);
    const [over, setOver] = useState<number | null>(null);
    const [filter, setFilter] = useState('');

    /**
     * Searching narrows the list, and while it is narrowed nothing may be reordered.
     *
     * Position in this list *is* the arrangement, so moving a row up when the rows between
     * it and its neighbour are hidden would move it somewhere the person cannot see. Show
     * and hide still work, because those do not depend on what is next to what.
     */
    const filtering = filter.trim().length > 0;
    const needle = filter.trim().toLowerCase();

    const matches = (row: Row) =>
        !filtering
        || (row.label ?? row.defaultLabel).toLowerCase().includes(needle)
        || (row.href ?? '').toLowerCase().includes(needle)
        || (sectionOf.get(row.href ?? '') ?? '').toLowerCase().includes(needle);

    const hiddenCount = rows.filter(row => !row.group && row.hidden).length;

    const [pinned, setPinned] = useState<string[]>(() => {
        try { return JSON.parse(window.localStorage.getItem(PINNED_NAV_KEY) ?? '[]'); }
        catch { return []; }
    });

    /** How many rows below `index` belong to it, so a branch moves as one thing. */
    const branchLength = (list: Row[], index: number) => {
        let size = 1;
        while (index + size < list.length && list[index + size].depth > list[index].depth) size++;

        return size;
    };

    /** Moves a row and everything under it. Moving a parent onto its own child is refused. */
    const relocate = (from: number, to: number) => {
        setRows(current => {
            const size = branchLength(current, from);
            if (to > from && to < from + size) return current;

            const next = [...current];
            const branch = next.splice(from, size);
            const target = to > from ? to - size : to;
            next.splice(Math.max(0, Math.min(target, next.length)), 0, ...branch);

            return normalise(next);
        });
    };

    /**
     * A row may sit at most one level deeper than the row above it.
     *
     * Without this, dragging a deep branch to the top would leave items nested under
     * nothing — they would render at the top level anyway, so the list would be showing
     * an arrangement the sidebar does not have.
     */
    const normalise = (list: Row[]) => list.map((row, index) => ({
        ...row,
        depth: index === 0 ? 0 : Math.min(row.depth, list[index - 1].depth + 1),
    }));

    const move = (index: number, by: number) => {
        const size = branchLength(rows, index);
        relocate(index, by < 0 ? Math.max(0, index - 1) : index + size + 1);
    };

    const indent = (index: number, by: number) => {
        setRows(current => normalise(current.map((row, position) => {
            if (position < index || position >= index + branchLength(current, index)) return row;

            return { ...row, depth: Math.max(0, row.depth + by) };
        })));
    };

    const save = async () => {
        setBusy(true); setNotice('');
        try {
            await axios.put('/api/v1/navigace', {
                items: rows.map((row, index) => ({
                    href: row.href,
                    label: row.label || null,
                    // Only a section has one, so an item never carries a stale subtitle
                    // from having briefly been a heading.
                    description: row.group ? (row.description || null) : null,
                    hidden: row.hidden,
                    group: row.group,
                    // The parent is the nearest row above sitting one level shallower.
                    parent: parentIndex(rows, index),
                })),
            });
            setNotice('Menu uloženo.');
            router.reload({ only: ['navigation'] });
        } catch { setNotice('Uložit se nepodařilo.'); }
        finally { setBusy(false); }
    };

    const togglePin = (href: string) => {
        setPinned(current => {
            const next = current.includes(href)
                ? current.filter(item => item !== href)
                : current.length < 6 ? [...current, href] : current;

            try { window.localStorage.setItem(PINNED_NAV_KEY, JSON.stringify(next)); } catch { /* private mode */ }

            return next;
        });
    };

    const reset = async () => {
        if (!window.confirm('Vrátit menu do výchozího stavu?')) return;
        setBusy(true);
        try { await axios.delete('/api/v1/navigace'); router.reload(); }
        catch { setNotice('Obnovit se nepodařilo.'); }
        finally { setBusy(false); }
    };

    return (
        <AppLayout title="Uspořádání menu">
            <Head title="Uspořádání menu" />

            <main className="mx-auto max-w-2xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Nastavení</p>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)]">Uspořádání menu</h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Přesuňte položky myší, nebo šipkami. Odsazením je vnoříte pod položku nad nimi — do libovolné hloubky.
                    Platí jen pro váš účet; nové funkce se objeví samy.
                </p>

                {notice && <p className="mt-4 rounded-xl border border-emerald-400/25 bg-emerald-500/10 p-3 text-xs text-emerald-100">{notice}</p>}

                <div className="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => setRows(current => [
                            { key: `#${Date.now()}`, href: null, label: 'Nová sekce', description: null, defaultLabel: 'Sekce', hidden: false, group: true, depth: 0 },
                            ...current,
                        ])}
                        className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]"
                    >
                        <FolderPlus size={14} /> Přidat sekci
                    </button>
                    <button type="button" disabled={busy} onClick={() => void save()} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-xs font-medium text-[var(--color-accent-contrast)] disabled:opacity-50">
                        {busy ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} Uložit
                    </button>
                    <button type="button" disabled={busy} onClick={() => void reset()} className="inline-flex min-h-10 items-center gap-2 rounded-xl px-3 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] disabled:opacity-50">
                        <RotateCcw size={14} /> Výchozí
                    </button>
                </div>

                <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
                    <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">Připnuté nahoře</h2>
                    <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                        Nejvýš šest — zkratka, kterou je potřeba číst, není zkratka. Platí pro toto zařízení, ne pro účet.
                    </p>

                    <div className="mt-3 flex flex-wrap gap-1.5">
                        {defaults.map(item => {
                            const active = pinned.includes(item.href);

                            return (
                                <button
                                    key={item.href}
                                    type="button"
                                    onClick={() => togglePin(item.href)}
                                    disabled={!active && pinned.length >= 6}
                                    aria-pressed={active}
                                    className={`min-h-9 rounded-lg border px-2.5 text-xs transition-colors disabled:opacity-30 ${active
                                        ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 text-[var(--color-text-primary)]'
                                        : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'}`}
                                >
                                    {item.label}
                                </button>
                            );
                        })}
                    </div>

                    <p className="mt-2 text-[11px] text-[var(--color-text-secondary)]">{pinned.length} / 6</p>
                </section>

                <div className="mt-5 flex flex-wrap items-center gap-2">
                    <input
                        value={filter}
                        onChange={event => setFilter(event.target.value)}
                        placeholder="Najít položku, adresu nebo sekci…"
                        aria-label="Filtrovat položky menu"
                        className="min-w-0 flex-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2 text-sm text-[var(--color-text-primary)]"
                    />
                    {filtering && (
                        <button
                            type="button"
                            onClick={() => setRows(current => {
                                // Whatever the search is showing, flipped together. The
                                // target state comes from the first match so the button
                                // does one thing to all of them rather than inverting each.
                                const target = !current.find(row => !row.group && matches(row))?.hidden;

                                return current.map(row => row.group || !matches(row) ? row : { ...row, hidden: target });
                            })}
                            className="min-h-9 rounded-lg border border-[var(--color-border)] px-2.5 text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]"
                        >
                            Skrýt / zobrazit nalezené
                        </button>
                    )}

                    <span className="text-[11px] text-[var(--color-text-secondary)]">
                        {rows.filter(row => !row.group).length} položek
                        {hiddenCount > 0 && ` · ${hiddenCount} skrytých`}
                    </span>
                </div>

                {filtering && (
                    <p className="mt-2 rounded-lg bg-[var(--color-surface-muted)] p-2 text-[11px] text-[var(--color-text-secondary)]">
                        Při hledání nelze přesouvat — pořadí v tomto seznamu je samo uspořádáním menu.
                        Skrýt a zobrazit jde dál.
                    </p>
                )}

                <div className="mt-3 space-y-1">
                    {rows.map((row, index) => matches(row) && (
                        <div
                            key={row.key}
                            draggable={!filtering}
                            onDragStart={() => setDragging(index)}
                            onDragEnd={() => { setDragging(null); setOver(null); }}
                            onDragOver={event => { event.preventDefault(); setOver(index); }}
                            onDrop={event => {
                                event.preventDefault();
                                if (dragging !== null && dragging !== index) relocate(dragging, index);
                                setDragging(null); setOver(null);
                            }}
                            style={{ marginLeft: `${Math.min(row.depth, 6) * 1.25}rem` }}
                            className={`flex items-center gap-2 rounded-xl border p-2 transition-colors ${row.group
                                ? 'border-[var(--color-accent)]/40 bg-[var(--color-accent)]/5'
                                : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'} ${row.hidden ? 'opacity-50' : ''} ${
                                over === index && dragging !== null && dragging !== index ? 'border-[var(--color-accent)]' : ''} ${
                                dragging === index ? 'opacity-40' : ''}`}
                        >
                            <GripVertical size={15} className={`shrink-0 text-[var(--color-text-secondary)] ${filtering ? 'opacity-25' : 'cursor-grab'}`} aria-hidden />

                            {/* A bordered field, not a bare transparent one. The old input
                                looked like a label, so a renameable section read as fixed
                                text — the commonest report was that it could not be edited
                                at all, which was a matter of appearance rather than fact. */}
                            <div className="min-w-0 flex-1 space-y-1">
                                <input
                                    value={row.label ?? ''}
                                    placeholder={row.defaultLabel}
                                    onChange={event => setRows(current => current.map((candidate, position) =>
                                        position === index ? { ...candidate, label: event.target.value } : candidate))}
                                    aria-label={row.group ? `Název sekce ${row.defaultLabel}` : `Název položky ${row.defaultLabel}`}
                                    className={`w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-1.5 text-[var(--color-text-primary)] ${row.group ? 'text-sm font-medium' : 'text-sm'}`}
                                />

                                {row.group ? (
                                    <input
                                        value={row.description ?? ''}
                                        placeholder="Popis sekce (nepovinný)"
                                        onChange={event => setRows(current => current.map((candidate, position) =>
                                            position === index ? { ...candidate, description: event.target.value } : candidate))}
                                        aria-label={`Popis sekce ${row.defaultLabel}`}
                                        className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 py-1 text-[11px] text-[var(--color-text-secondary)]"
                                    />
                                ) : (
                                    <p className="truncate px-1 text-[10px] text-[var(--color-text-secondary)]">
                                        {sectionOf.get(row.href ?? '') ?? 'Mimo sekce'} · {row.href}
                                    </p>
                                )}
                            </div>

                            <button type="button" onClick={() => indent(index, -1)} disabled={filtering || row.depth === 0} aria-label="O úroveň výš" className="rounded-lg p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] disabled:opacity-25"><ChevronLeft size={14} /></button>
                            <button type="button" onClick={() => indent(index, 1)} disabled={filtering || index === 0 || row.depth > rows[index - 1].depth} aria-label="Vnořit pod položku výše" className="rounded-lg p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] disabled:opacity-25"><ChevronRight size={14} /></button>
                            <button type="button" onClick={() => move(index, -1)} disabled={filtering || index === 0} aria-label="Posunout výš" className="rounded-lg p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] disabled:opacity-25"><ArrowUp size={14} /></button>
                            <button type="button" onClick={() => move(index, 1)} disabled={filtering || index === rows.length - 1} aria-label="Posunout níž" className="rounded-lg p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] disabled:opacity-25"><ArrowDown size={14} /></button>

                            {!row.group && (
                                <button
                                    type="button"
                                    onClick={() => setRows(current => current.map((candidate, position) =>
                                        position === index ? { ...candidate, hidden: !candidate.hidden } : candidate))}
                                    aria-label={row.hidden ? 'Zobrazit' : 'Skrýt'}
                                    className="rounded-lg p-1.5 text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]"
                                >
                                    {row.hidden ? <EyeOff size={14} /> : <Eye size={14} />}
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            </main>
        </AppLayout>
    );
}

/**
 * The sidebar as it ships, as editor rows.
 *
 * Each built-in section becomes a heading and its items sit under it, nested exactly as
 * the sidebar draws them. Somebody who opens this screen sees the menu they already have
 * and changes what they want — rather than a flat list that quietly discards the grouping
 * the moment they press save.
 */
function seedFromSidebar(features: string[] | null): Row[] {
    const rows: Row[] = [];

    const walk = (items: NavigationLike[], depth: number) => {
        for (const item of items) {
            if (item.feature && features && !features.includes(item.feature)) continue;

            rows.push({
                key: item.href,
                href: item.href,
                label: null,
                defaultLabel: item.label,
                hidden: false,
                group: false,
                depth,
            });

            if (item.children?.length) walk(item.children, depth + 1);
        }
    };

    navGroups.forEach((group, index) => {
        const before = rows.length;
        rows.push({
            key: `#sekce-${index}`,
            href: null,
            label: group.label,
            description: group.description ?? null,
            defaultLabel: group.label,
            hidden: false,
            group: true,
            depth: 0,
        });

        walk(group.items, 1);

        // A section whose every item the plan withholds is a heading over nothing.
        if (rows.length === before + 1) rows.pop();
    });

    return rows;
}

interface NavigationLike {
    href: string;
    label: string;
    feature?: string;
    children?: NavigationLike[];
}

/** The nearest row above sitting one level shallower, as an index the server understands. */
function parentIndex(rows: Row[], index: number): number | null {
    for (let position = index - 1; position >= 0; position--) {
        if (rows[position].depth < rows[index].depth) return position;
    }

    return null;
}
