import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ExternalLink, FileText, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Invoice {
    uuid: string;
    number: string;
    description: string;
    amount: number;
    currency: string;
    issued_at: string | null;
    paid_at: string | null;
}

const money = (minor: number, currency: string) =>
    `${new Intl.NumberFormat('cs-CZ').format(Math.round(minor / 100))} ${currency}`;

const day = (value: string | null) =>
    value ? new Date(value).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' }) : '—';

/**
 * Everything the customer has been charged, on its own page.
 *
 * It also sits under the subscription, which is where somebody notices it exists. This is
 * where they come back to when they need a specific document a year later — and looking
 * for an invoice by scrolling past a price list is the reason people email support instead.
 *
 * A table rather than cards. Invoices are compared down a column: which month, how much,
 * paid or not.
 */
export default function Invoices() {
    const [invoices, setInvoices] = useState<Invoice[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        void axios.get('/api/v1/billing/faktury')
            .then(response => setInvoices(response.data.invoices ?? []))
            .catch(() => setError('Doklady se nepodařilo načíst.'))
            .finally(() => setLoading(false));
    }, []);

    const total = invoices.reduce((sum, invoice) => sum + invoice.amount, 0);

    return (
        <AppLayout>
            <Head title="Faktury a platby" />

            <main className="w-full p-4 sm:p-6">
                <p className="text-xs uppercase tracking-widest text-[var(--color-accent)]">Nastavení</p>
                <h1 className="mt-1 flex items-center gap-2 text-2xl font-bold text-[var(--color-text-primary)] sm:text-3xl">
                    <FileText size={23} className="text-[var(--color-accent)]" /> Faktury a platby
                </h1>
                <p className="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Doklady se otevřou jako stránka připravená k tisku — prohlížeč z ní udělá PDF tam, kam si řeknete.
                    {' '}<Link href="/settings/predplatne" className="text-[var(--color-accent)] hover:underline">Předplatné a moduly</Link>
                </p>

                {error && <p role="alert" className="mt-4 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-xs text-red-100">{error}</p>}
                {loading && <div className="mt-8 flex justify-center"><LoaderCircle className="animate-spin text-[var(--color-accent)]" /></div>}

                {!loading && invoices.length === 0 && !error && (
                    <div className="mt-6 rounded-2xl border border-dashed border-[var(--color-border)] p-8 text-center">
                        <FileText className="mx-auto text-[var(--color-text-secondary)]" size={22} />
                        <p className="mt-3 font-medium text-[var(--color-text-primary)]">Zatím žádný doklad</p>
                        <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                            Faktura vznikne při první platbě. Základní tarif je zdarma, takže se nic neúčtuje.
                        </p>
                    </div>
                )}

                {!loading && invoices.length > 0 && (
                    <>
                        <div className="mt-5 overflow-x-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)]">
                            <table className="w-full min-w-[34rem] text-sm">
                                <thead>
                                    <tr className="border-b border-[var(--color-border)] text-left text-xs uppercase tracking-wide text-[var(--color-text-secondary)]">
                                        <th className="px-4 py-3 font-medium">Číslo</th>
                                        <th className="px-4 py-3 font-medium">Vystaveno</th>
                                        <th className="px-4 py-3 font-medium">Za co</th>
                                        <th className="px-4 py-3 text-right font-medium">Částka</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.map(invoice => (
                                        <tr key={invoice.uuid} className="border-b border-[var(--color-border)] last:border-0">
                                            <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-[var(--color-text-secondary)]">{invoice.number}</td>
                                            <td className="whitespace-nowrap px-4 py-3 text-[var(--color-text-secondary)]">{day(invoice.issued_at)}</td>
                                            <td className="px-4 py-3 text-[var(--color-text-primary)]">
                                                {invoice.description}
                                                {/* Said only when it is not true; every other row being
                                                    marked "paid" is noise nobody reads. */}
                                                {!invoice.paid_at && <span className="ml-2 rounded-md bg-amber-500/15 px-1.5 py-0.5 text-[10px] text-amber-200">neuhrazeno</span>}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right text-[var(--color-text-primary)]">{money(invoice.amount, invoice.currency)}</td>
                                            <td className="px-4 py-3 text-right">
                                                <a
                                                    href={`/faktury/${invoice.uuid}`}
                                                    target="_blank"
                                                    rel="noopener"
                                                    aria-label={`Otevřít fakturu ${invoice.number}`}
                                                    className="inline-flex items-center gap-1 text-xs text-[var(--color-accent)] hover:underline"
                                                >
                                                    Otevřít <ExternalLink size={12} />
                                                </a>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <p className="mt-2 text-xs text-[var(--color-text-secondary)]">
                            {invoices.length} {invoices.length === 1 ? 'doklad' : invoices.length <= 4 ? 'doklady' : 'dokladů'}
                            {' · celkem '}{money(total, invoices[0].currency)}
                        </p>
                    </>
                )}
            </main>
        </AppLayout>
    );
}
