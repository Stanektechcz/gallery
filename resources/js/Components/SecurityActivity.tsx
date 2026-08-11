import axios from 'axios';
import { Loader2, ShieldAlert, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Event {
    action: string;
    at: string | null;
    ip: string | null;
    device: string;
    second_factor: boolean;
}

/** Plain Czech for each action. Anything unlisted falls back to its own name rather than vanishing. */
const LABEL: Record<string, string> = {
    'auth.login': 'Přihlášení',
    'auth.login.failed': 'Neúspěšný pokus o přihlášení',
    'auth.logout': 'Odhlášení',
    'auth.2fa.enabled': 'Zapnuto dvoufázové ověření',
    'auth.2fa.disabled': 'Vypnuto dvoufázové ověření',
    'auth.2fa.failed': 'Chybný ověřovací kód',
    'auth.2fa.recovery_used': 'Použit záložní kód',
    'auth.registered': 'Účet založen',
    'auth.invitation.accepted': 'Přijato pozvání',
};

const ALARMING = new Set(['auth.login.failed', 'auth.2fa.failed', 'auth.2fa.disabled', 'auth.2fa.recovery_used']);

const stamp = (value: string | null) =>
    value ? new Date(value).toLocaleString('cs-CZ', {
        day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }) : '—';

/**
 * What has happened to this account.
 *
 * Failed attempts are the reason this exists. "Where did I sign in from" is mildly
 * interesting; "did anybody else try" is the question worth answering, so those lines are
 * marked and counted rather than being one entry among many.
 *
 * Read from the audit log rather than kept separately — a security history that can
 * disagree with the audit trail would be believed, which makes it worse than none.
 */
export default function SecurityActivity() {
    const [events, setEvents] = useState<Event[]>([]);
    const [failed, setFailed] = useState(0);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [all, setAll] = useState(false);

    useEffect(() => {
        void axios.get('/api/v1/ucet/aktivita')
            .then(response => {
                setEvents(response.data.events ?? []);
                setFailed(response.data.failed_recently ?? 0);
            })
            .catch(() => setError('Historii se nepodařilo načíst.'))
            .finally(() => setLoading(false));
    }, []);

    const shown = all ? events : events.slice(0, 8);

    return (
        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            <h2 className="flex items-center gap-2 font-semibold text-[var(--color-text-primary)]">
                <ShieldCheck size={17} className="text-[var(--color-accent)]" /> Bezpečnostní historie
            </h2>
            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                Přihlášení, pokusy o ně a změny zabezpečení účtu.
            </p>

            {error && <p role="alert" className="mt-3 rounded-lg bg-red-500/10 p-2 text-xs text-red-200">{error}</p>}
            {loading && <div className="flex justify-center py-5"><Loader2 size={18} className="animate-spin text-[var(--color-accent)]" /></div>}

            {failed > 0 && (
                <p className="mt-3 flex items-start gap-2 rounded-lg bg-amber-500/10 p-2.5 text-xs text-amber-100">
                    <ShieldAlert size={15} className="mt-0.5 shrink-0" />
                    <span>
                        Za posledních sedm dní {failed === 1 ? 'proběhl 1 neúspěšný pokus' : `proběhlo ${failed} neúspěšných pokusů`} o přihlášení.
                        Pokud jste to nebyli vy, změňte si heslo a zapněte dvoufázové ověření.
                    </span>
                </p>
            )}

            <div className="mt-3 space-y-1.5">
                {shown.map((event, index) => (
                    <div
                        key={`${event.at}-${index}`}
                        className={`flex flex-wrap items-baseline gap-x-2 rounded-lg px-2 py-1.5 text-xs ${ALARMING.has(event.action) ? 'bg-red-500/5' : ''}`}
                    >
                        <span className={ALARMING.has(event.action) ? 'text-red-200' : 'text-[var(--color-text-primary)]'}>
                            {LABEL[event.action] ?? event.action}
                        </span>
                        {event.second_factor && <span className="text-[10px] text-[var(--color-accent)]">s ověřením</span>}
                        <span className="ml-auto text-[var(--color-text-secondary)]">{stamp(event.at)}</span>
                        <span className="w-full text-[10px] text-[var(--color-text-secondary)]">
                            {event.ip || 'IP není známá'}{event.device ? ` · ${event.device}` : ''}
                        </span>
                    </div>
                ))}

                {!loading && events.length === 0 && (
                    <p className="text-xs text-[var(--color-text-secondary)]">Zatím tu nic není.</p>
                )}
            </div>

            {events.length > 8 && (
                <button
                    type="button"
                    onClick={() => setAll(current => !current)}
                    className="mt-3 text-xs text-[var(--color-accent)] hover:underline"
                >
                    {all ? 'Zobrazit méně' : `Zobrazit všech ${events.length}`}
                </button>
            )}
        </section>
    );
}
