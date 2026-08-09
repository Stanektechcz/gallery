import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Game {
    uuid: string; kind: string; name: string; status: string;
    state: any; my_turn: boolean; turn_user_id: number | null;
    winner_user_id: number | null; i_won: boolean; is_draw: boolean;
}

const RPS: Array<[string, string]> = [['kamen', '✊'], ['nuzky', '✌️'], ['papir', '✋']];

/**
 * A game played inside the conversation.
 *
 * It polls its own state on the same rhythm as the chat while it is somebody else's
 * turn, and stops the moment the game ends — a finished board has nothing left to say.
 */
export default function ChatGame({ uuid, meId }: { uuid: string; meId: number }) {
    const [game, setGame] = useState<Game | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try { setGame((await axios.get(`/api/v1/hry/${uuid}`)).data); }
        catch { setError('Hru se nepodařilo načíst.'); }
    }, [uuid]);

    useEffect(() => { void load(); }, [load]);

    // Waiting for the other player is the only reason to keep asking.
    useEffect(() => {
        if (!game || game.status !== 'playing' || game.my_turn) return;
        const handle = window.setInterval(() => void load(), 3000);
        return () => window.clearInterval(handle);
    }, [game, load]);

    const move = async (payload: Record<string, unknown>) => {
        setBusy(true); setError('');
        try { setGame((await axios.post(`/api/v1/hry/${uuid}/tah`, payload)).data); }
        catch (reason: any) { setError(reason?.response?.data?.message ?? 'Tah se nepodařilo zahrát.'); }
        finally { setBusy(false); }
    };

    if (!game) return <div className="flex justify-center py-3"><Loader2 size={16} className="animate-spin text-[var(--color-accent)]" /></div>;

    const finished = game.status === 'finished';
    const verdict = finished
        ? (game.is_draw ? 'Remíza' : game.i_won ? 'Vyhráli jste 🎉' : 'Tentokrát ne')
        : game.kind === 'piskvorky'
            ? (game.my_turn ? 'Jste na tahu' : 'Čeká se na soupeře')
            : (game.state?.i_have_chosen ? 'Čeká se na soupeře' : 'Vyberte');

    return (
        <div className="w-56 max-w-full">
            <p className="mb-1.5 flex items-center justify-between text-[11px]">
                <span className="font-medium">{game.name}</span>
                <span className="opacity-75">{verdict}</span>
            </p>

            {game.kind === 'piskvorky' && (
                <div className="grid grid-cols-3 gap-1">
                    {(game.state?.board ?? []).map((owner: number | null, square: number) => (
                        <button
                            key={square}
                            type="button"
                            disabled={busy || finished || !game.my_turn || owner !== null}
                            onClick={() => void move({ square })}
                            aria-label={`Pole ${square + 1}`}
                            className="flex h-12 items-center justify-center rounded-lg bg-[var(--color-bg-card)]/80 text-xl disabled:opacity-70"
                        >
                            {owner === null ? '' : (game.state?.marks?.[owner] ?? '·')}
                        </button>
                    ))}
                </div>
            )}

            {game.kind === 'kamen' && (
                <>
                    <div className="flex gap-1">
                        {RPS.map(([value, glyph]) => (
                            <button
                                key={value}
                                type="button"
                                disabled={busy || finished || game.state?.i_have_chosen}
                                onClick={() => void move({ choice: value })}
                                aria-label={value}
                                className="flex h-12 flex-1 items-center justify-center rounded-lg bg-[var(--color-bg-card)]/80 text-xl disabled:opacity-50"
                            >
                                {glyph}
                            </button>
                        ))}
                    </div>
                    <p className="mt-1.5 text-[11px] opacity-75">
                        Kolo {game.state?.round ?? 1} · {Object.entries(game.state?.score ?? {})
                            .map(([id, points]) => `${Number(id) === meId ? 'vy' : 'soupeř'} ${points}`).join(' – ')}
                        {' · '}na tři vítězství
                    </p>
                </>
            )}

            {error && <p className="mt-1.5 text-[10px] text-red-200">{error}</p>}
        </div>
    );
}
