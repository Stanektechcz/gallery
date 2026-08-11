import type { ComponentType } from 'react';

export interface Arrangement {
    /** Stable identity: the href for a real item, `#uuid` for a heading somebody invented. */
    key?: string | null;
    href: string | null;
    label: string | null;
    icon: string | null;
    parent: string | null;
    hidden: boolean;
    group: boolean;
}

export interface Entry {
    href: string;
    label: string;
    /** Left loose on purpose: the sidebar owns the real icon type, this only carries it. */
    icon: any;
    adminOnly?: boolean;
    feature?: string;
    exact?: boolean;
    /** Nesting is unlimited, so this is the same shape all the way down. */
    children?: Entry[];
}

export interface ArrangedGroup {
    id: string;
    label: string;
    /** Carried through so the sidebar can keep drawing the group's icon. */
    icon?: ComponentType<{ size?: number; className?: string }>;
    description?: string;
    items: Entry[];
}

interface SourceGroup {
    id: string;
    label: string;
    icon?: ComponentType<{ size?: number; className?: string }>;
    description?: string;
    items: Entry[];
}

/** The key a row is known by, matching what the server emits. */
const keyOf = (row: Arrangement, index: number) => row.key ?? row.href ?? `#${index}`;

/**
 * Applies somebody's saved arrangement to the built-in navigation.
 *
 * The arrangement is a set of differences, so this walks the built-in menu and consults
 * it per item rather than replacing it. Anything the arrangement does not mention keeps
 * its default place — which is what lets a newly shipped feature appear for people who
 * customised their sidebar months ago.
 *
 * Nesting is unlimited. Rows point at their parent by key and this assembles the tree in
 * one pass, so a child three levels down costs no more than a child at the top. Rows whose
 * parent is missing are lifted to the top rather than dropped: losing an item is worse
 * than showing it in the wrong place, because one is visible and the other is not.
 */
export function applyArrangement(
    groups: SourceGroup[],
    arrangement: Arrangement[] | null,
): ArrangedGroup[] {
    if (!arrangement || arrangement.length === 0) {
        return groups.map(group => ({ ...group, items: group.items.map(item => ({ ...item })) }));
    }

    // Walked to the bottom: the built-in menu nests too, and a child nobody can look up
    // here is a child the arrangement silently drops.
    const flatten = (items: Entry[]): Entry[] =>
        items.flatMap(item => [item, ...flatten(item.children ?? [])]);

    const defaults = new Map(flatten(groups.flatMap(group => group.items)).map(item => [item.href, item]));

    // One node per row, children filled in below. Built first so a parent that appears
    // after its child in the list still resolves.
    const nodes = new Map<string, Entry & { children: Entry[] }>();
    const rows = new Map<string, Arrangement>();
    const order: string[] = [];

    arrangement.forEach((row, index) => {
        if (row.hidden) return;

        const key = keyOf(row, index);
        const base = row.href ? defaults.get(row.href) : undefined;

        // A row naming an href the menu no longer has is a feature that went away, and
        // there is nothing to draw for it. A row with no href is a heading, which is kept.
        if (row.href && !base) return;

        nodes.set(key, {
            ...(base ?? { href: '', label: '', icon: undefined }),
            label: row.label ?? base?.label ?? 'Sekce',
            children: [],
        });
        rows.set(key, row);
        order.push(key);
    });

    const roots: string[] = [];

    for (const key of order) {
        const parent = rows.get(key)?.parent ?? null;

        if (parent && nodes.has(parent) && parent !== key) nodes.get(parent)!.children.push(nodes.get(key)!);
        else roots.push(key);
    }

    // A heading at the top level is a section; a real item at the top level belongs to an
    // unnamed one. This keeps the sidebar's existing shape — groups holding trees — rather
    // than making it understand a forest.
    const arranged: ArrangedGroup[] = [];
    let loose: Entry[] = [];

    for (const key of roots) {
        const node = nodes.get(key)!;

        if (rows.get(key)?.group || !node.href) {
            if (loose.length > 0) {
                arranged.push({ id: `volne-${arranged.length}`, label: '', items: loose });
                loose = [];
            }
            if (node.children.length > 0) {
                // A heading that still carries a built-in section's name keeps its icon and
                // description. Somebody who never renamed "Spolu" should not find it turned
                // into a generic folder just because their arrangement now names it.
                const original = groups.find(group => group.label === node.label);

                arranged.push({
                    id: key,
                    label: node.label,
                    icon: original?.icon,
                    description: original?.description,
                    items: node.children,
                });
            }
            continue;
        }

        loose.push(node);
    }

    if (loose.length > 0) arranged.push({ id: `volne-${arranged.length}`, label: '', items: loose });

    // Anything the arrangement never mentions keeps its built-in group, so a feature
    // shipped after somebody customised their menu still appears.
    const mentioned = new Set(arrangement.map(row => row.href).filter(Boolean) as string[]);

    // Flattened before filtering: a built-in child whose parent was rearranged has lost
    // the branch it hung on, and would otherwise disappear rather than fall back.
    const untouched: ArrangedGroup[] = groups
        .map(group => ({
            ...group,
            items: flatten(group.items)
                .filter(item => item.href && !mentioned.has(item.href))
                .map(item => ({ ...item, children: undefined })),
        }))
        .filter(group => group.items.length > 0);

    return [...arranged, ...untouched];
}
