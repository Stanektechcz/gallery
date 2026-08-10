import type { ComponentType } from 'react';

export interface Arrangement {
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
}

export interface ArrangedGroup {
    id: string;
    label: string;
    /** Carried through so the sidebar can keep drawing the group's icon. */
    icon?: ComponentType<{ size?: number; className?: string }>;
    description?: string;
    items: Array<Entry & { children?: Entry[] }>;
}

/**
 * Applies somebody's saved arrangement to the built-in navigation.
 *
 * The arrangement is a set of differences, so this walks the built-in menu and consults
 * it per item rather than replacing it. Anything the arrangement does not mention keeps
 * its default place — which is what lets a newly shipped feature appear for people who
 * customised their sidebar months ago.
 *
 * Order comes from the arrangement where it says something, and from the built-in list
 * otherwise, with arranged items first: somebody who deliberately placed six items
 * expects those six at the top, not scattered through the defaults.
 */
export function applyArrangement(
    groups: Array<{ id: string; label: string; icon?: ComponentType<{ size?: number; className?: string }>; description?: string; items: Entry[] }>,
    arrangement: Arrangement[] | null,
): ArrangedGroup[] {
    if (!arrangement || arrangement.length === 0) {
        return groups.map(group => ({ ...group, items: group.items.map(item => ({ ...item })) }));
    }

    const byHref = new Map(arrangement.filter(row => row.href).map(row => [row.href!, row]));
    const rank = new Map(arrangement.map((row, index) => [row.href ?? `#${index}`, index]));

    // Custom headings the person invented, each collecting whatever names it as parent.
    const custom: ArrangedGroup[] = arrangement
        .filter(row => row.group)
        .map((row, index) => ({ id: `vlastni-${index}`, label: row.label ?? 'Sekce', items: [] }));

    const all = groups.flatMap(group => group.items);
    const claimed = new Set<string>();

    for (const row of arrangement) {
        if (!row.parent) continue;
        const parentIndex = arrangement.findIndex(candidate => candidate.group && candidate.label === row.parent)
            ?? -1;
        const entry = all.find(item => item.href === row.href);
        if (!entry || parentIndex < 0 || !custom[parentIndex]) continue;

        custom[parentIndex].items.push({ ...entry, label: row.label ?? entry.label });
        claimed.add(entry.href);
    }

    const arranged = groups.map(group => ({
        ...group,
        items: group.items
            .filter(item => !claimed.has(item.href))
            .filter(item => !byHref.get(item.href)?.hidden)
            .map(item => {
                const row = byHref.get(item.href);

                return { ...item, label: row?.label ?? item.label };
            })
            .sort((a, b) => (rank.get(a.href) ?? 999) - (rank.get(b.href) ?? 999)),
    }));

    // Custom headings lead, because they were made on purpose.
    return [...custom.filter(group => group.items.length > 0), ...arranged.filter(group => group.items.length > 0)];
}
