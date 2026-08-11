import AppLayout, { navGroups, PINNED_NAV_KEY } from '@/Layouts/AppLayout';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { ArrowDown, ArrowUp, Eye, EyeOff, FolderPlus, Loader2, RotateCcw, Save } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Row {
    href: string | null;
    label: string | null;
    defaultLabel: string;
    hidden: boolean;
    group: boolean;
    parent: string | null;
}

/**
 * Rearranging the menu.
 *
 * Buttons rather than dragging, deliberately: a drag target on a phone is fiddly and
 * needs a keyboard equivalent anyway, so the arrows are the honest version of the same
 * thing rather than a fallback nobody tests.
 */
export default function NavigationSettings() {
    const page = usePage().props as {
        navigation?: Array<{ href: string | null; label: string | null; hidden: boolean; group: boolean; parent: string | null }> | null;
        features?: string[] | null;
    };

    /**
     * Every item the sidebar can show, taken from the sidebar itself.
     *
     * This previously read a `navigationDefaults` prop that nothing on the server ever
     * sent, so the list was empty and there was nothing to arrange. Importing the real
     * menu means the two can no longer disagree, and a newly shipped item appears here
     * without anybody remembering to add it in a second place.
     *
     * Items the plan does not include are left out: offering to rearrange something the
     * person cannot open would be arranging a menu they will never see.
     */
    const defaults = useMemo(() => {
        const features = page.features ?? null;

        return navGroups.flatMap(group => group.items
            .filter(item => !item.feature || !features || features.includes(item.feature))
            .map(item => ({ href: item.href, label: item.label, section: group.label })));
    }, [page.features]);

    const [rows, setRows] = useState<Row[]>(() => {
        const saved = page.navigation ?? [];
        const byHref = new Map(saved.filter(row => row.href).map(row => [row.href!, row]));

        const fromDefaults: Row[] = defaults.map(item => {
            const row = byHref.get(item.href);

            return {
                href: item.href,
                label: row?.label ?? null,
                defaultLabel: item.label,
                hidden: row?.hidden ?? false,
                group: false,
                parent: row?.parent ?? null,
            };
        });

        // Custom headings have no default to hang from, so they are carried through.
        const groups: Row[] = saved.filter(row => row.group).map(row => ({
            href: null, label: row.label, defaultLabel: 'Sekce', hidden: false, group: true, parent: null,
        }));

        const order = new Map(saved.map((row, index) => [row.href ?? `#${row.label}`, index]));

        return [...groups, ...fromDefaults].sort(
            (a, b) => (order.get(a.href ?? `#${a.label}`) ?? 999) - (order.get(b.href ?? `#${b.label}`) ?? 999),
        );
    });

    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');

    const [pinned, setPinned] = useState<string[]>(() => {
        try { return JSON.parse(window.localStorage.getItem(PINNED_NAV_KEY) ?? '[]'); }
        catch { return []; }
    });

    const move = (index: number, by: number) => {
        setRows(current => {
            const next = [...current];
            const target = index + by;
            if (target < 0 || target >= next.length) return current;
            [next[index], next[target]] = [next[target], next[index]];

            return next;
        });
    };

    const save = async () => {
        setBusy(true); setNotice('');
        try {
            await axios.put('/api/v1/navigace', {
                items: rows.map(row => ({
                    href: row.href,
                    label: row.label || null,
                    hidden: row.hidden,
                    group: row.group,
                    // Parent is an index into this same list; see the controller.
                    parent: row.parent ? rows.findIndex(candidate => candidate.group && candidate.label === row.parent) : null,
                })),
            });
            setNotice('Menu uloženo.');
            // The sidebar reads a shared prop, which only a reload refreshes.
            router.reload({ only: ['navigation'] });
        } catch { setNotice('Uložit se nepodařilo.'); }
        finally { setBusy(false); }
    };

    /**
     * The handful of items pinned to the top of the sidebar.
     *
     * Kept in localStorage and not on the server, which is deliberate: pins are about the
     * device you are on. The key is imported from the sidebar so the two cannot drift —
     * writing the same string in two files is how a preference silently stops applying.
     *
     * Six, because a shortcut list you have to read is not a shortcut.
     */
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

    const groups = useMemo(() => rows.filter(row => row.group), [rows]);

    return (
        <AppLayout title="Uspořádání menu">
            <Head title="Uspořádání menu" />
            <main className="mx-auto max-w-2xl p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Nastavení</p>
                <h1 className="mt-1 text-2xl font-bold text-[var(--color-text-primary)]">Uspořádání menu</h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Platí jen pro váš účet. Nové funkce se objeví samy — vaše uspořádání o ně nepřijde.
                </p>

                {notice && <p className="mt-4 rounded-xl border border-emerald-400/25 bg-emerald-500/10 p-3 text-xs text-emerald-100">{notice}</p>}

                <div className="mt-4 flex flex-wrap gap-2">
                    <button type="button" onClick={() => setRows(current => [{ href: null, label: 'Nová sekce', defaultLabel: 'Sekce', hidden: false, group: true, parent: null }, ...current])} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--color-border)] px-3 text-xs text-[var(--color-text-primary)]">
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
                        Nejvýš šest položek — zkratka, kterou je potřeba číst, není zkratka.
                        Platí pro toto zařízení, ne pro účet.
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

                <div className="mt-4 space-y-1">
                    {rows.map((row, index) => (
                        <div key={row.href ?? `group-${index}`} className={`flex items-center gap-2 rounded-xl border p-2 ${row.group ? 'border-[var(--color-accent)]/40 bg-[var(--color-accent)]/5' : 'border-[var(--color-border)] bg-[var(--color-bg-card)]'} ${row.hidden ? 'opacity-50' : ''}`}>
                            <div className="flex shrink-0 flex-col">
                                <button type="button" onClick={() => move(index, -1)} aria-label="Nahoru" className="text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]"><ArrowUp size={13} /></button>
                                <button type="button" onClick={() => move(index, 1)} aria-label="Dolů" className="text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]"><ArrowDown size={13} /></button>
                            </div>

                            <input
                                value={row.label ?? ''}
                                placeholder={row.defaultLabel}
                                onChange={event => setRows(current => current.map((item, at) => at === index ? { ...item, label: event.target.value } : item))}
                                className="min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2.5 py-2 text-xs text-[var(--color-text-primary)]"
                            />

                            {!row.group && groups.length > 0 && (
                                <select
                                    value={row.parent ?? ''}
                                    onChange={event => setRows(current => current.map((item, at) => at === index ? { ...item, parent: event.target.value || null } : item))}
                                    className="min-h-9 shrink-0 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-2 text-[11px] text-[var(--color-text-primary)]"
                                >
                                    <option value="">bez sekce</option>
                                    {groups.map(group => <option key={group.label} value={group.label ?? ''}>{group.label}</option>)}
                                </select>
                            )}

                            {!row.group && (
                                <button
                                    type="button"
                                    onClick={() => setRows(current => current.map((item, at) => at === index ? { ...item, hidden: !item.hidden } : item))}
                                    aria-label={row.hidden ? 'Zobrazit' : 'Skrýt'}
                                    aria-pressed={row.hidden}
                                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                                >
                                    {row.hidden ? <EyeOff size={15} /> : <Eye size={15} />}
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            </main>
        </AppLayout>
    );
}
