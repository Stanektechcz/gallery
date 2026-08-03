import axios from 'axios';
import { openDB } from 'idb';

export type QueuedCalendarEvent = {
    id: string;
    kind: 'calendar-event' | 'todo';
    title: string;
    payload: Record<string, unknown>;
    createdAt: string;
    status: 'pending' | 'needs_attention';
    error?: string;
};

const database = () => openDB('maki-offline-writes', 1, {
    upgrade(db) {
        if (!db.objectStoreNames.contains('writes')) db.createObjectStore('writes', { keyPath: 'id' });
    },
});

export async function queueCalendarEvent(payload: Record<string, unknown>, id = crypto.randomUUID()): Promise<QueuedCalendarEvent> {
    const action: QueuedCalendarEvent = {
        id,
        kind: 'calendar-event',
        title: String(payload.title ?? 'Nová akce'),
        payload,
        createdAt: new Date().toISOString(),
        status: 'pending',
    };
    const db = await database();
    await db.put('writes', action);
    return action;
}

export async function queueTodo(payload: Record<string, unknown>, id = crypto.randomUUID()): Promise<QueuedCalendarEvent> {
    const action: QueuedCalendarEvent = {
        id,
        kind: 'todo',
        title: String(payload.title ?? 'Nový úkol'),
        payload,
        createdAt: new Date().toISOString(),
        status: 'pending',
    };
    const db = await database();
    await db.put('writes', action);
    return action;
}

export async function workspaceWriteSummary(): Promise<{ pending: number; needsAttention: number }> {
    const actions = await (await database()).getAll('writes') as QueuedCalendarEvent[];
    return actions.reduce((summary, action) => {
        if (action.status === 'needs_attention') summary.needsAttention += 1;
        else summary.pending += 1;
        return summary;
    }, { pending: 0, needsAttention: 0 });
}

export async function workspaceWritesNeedingAttention(): Promise<QueuedCalendarEvent[]> {
    return ((await (await database()).getAll('writes')) as QueuedCalendarEvent[])
        .filter(action => action.status === 'needs_attention')
        .sort((left, right) => left.createdAt.localeCompare(right.createdAt));
}

export async function discardWorkspaceWrite(id: string): Promise<void> {
    await (await database()).delete('writes', id);
}

/**
 * Writes are deliberately sent in their original order. Each calendar write
 * carries a stable client_request_id, so reconnecting after a timeout cannot
 * create the same event twice.
 */
export async function flushWorkspaceWrites(onSynced?: (action: QueuedCalendarEvent) => void): Promise<number> {
    if (!navigator.onLine) return 0;
    const db = await database();
    const actions = (await db.getAll('writes') as QueuedCalendarEvent[])
        .sort((left, right) => left.createdAt.localeCompare(right.createdAt));
    let synced = 0;
    for (const action of actions) {
        try {
            if (action.kind === 'calendar-event') {
                await axios.post('/api/v1/calendar/events', { ...action.payload, client_request_id: action.id });
            } else if (action.kind === 'todo') {
                await axios.post('/api/v1/todos', { ...action.payload, client_request_id: action.id });
            }
            await db.delete('writes', action.id);
            synced += 1;
            onSynced?.(action);
        } catch (error: any) {
            // A client-side validation conflict cannot recover by retrying. Keep
            // the payload locally but make its state explicit to the user.
            if (error.response && error.response.status < 500) {
                await db.put('writes', { ...action, status: 'needs_attention', error: error.response?.data?.message ?? 'Zápis vyžaduje ruční kontrolu.' });
            }
            // Stop here; later writes may depend on this action.
            break;
        }
    }
    return synced;
}