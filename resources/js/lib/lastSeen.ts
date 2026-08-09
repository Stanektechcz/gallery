/**
 * "Naposledy aktivní" in words, in Czech, with the cases right.
 *
 * Czech needs three forms depending on the number — 1 minutou, 2–4 minutami, 5+ minutami —
 * so a naive `${n} minutami` reads wrong for exactly the values people see most.
 */
export function lastSeenLabel(iso: string | null | undefined, online: boolean): string {
    if (online) return 'online';
    if (!iso) return 'zatím nebyl v aplikaci';

    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 90) return 'aktivní právě teď';

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `aktivní před ${minutes} ${plural(minutes, 'minutou', 'minutami', 'minutami')}`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `aktivní před ${hours} ${plural(hours, 'hodinou', 'hodinami', 'hodinami')}`;

    const days = Math.floor(hours / 24);
    if (days < 7) return `aktivní před ${days} ${plural(days, 'dnem', 'dny', 'dny')}`;

    return `naposledy ${new Date(iso).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' })}`;
}

const plural = (count: number, one: string, few: string, many: string) =>
    count === 1 ? one : count < 5 ? few : many;
