import axios from 'axios';
import { openDB } from 'idb';

type QueuedAssistantAction = {
    id: string;
    message: string;
    createdAt: string;
    selectedActions?: string[];
};

const database = () => openDB('maki-offline-actions', 1, {
    upgrade(db) {
        if (!db.objectStoreNames.contains('assistant-actions')) db.createObjectStore('assistant-actions', { keyPath: 'id' });
    },
});

export async function queueAssistantAction(action: QueuedAssistantAction) {
    const db = await database();
    await db.put('assistant-actions', action);
}

export async function pendingAssistantActionCount(): Promise<number> {
    const db = await database();
    return db.count('assistant-actions');
}

export async function flushAssistantActions(onApplied?: (created: string[], actionId: string) => void): Promise<number> {
    if (!navigator.onLine) return 0;
    const db = await database();
    const actions = await db.getAll('assistant-actions') as QueuedAssistantAction[];
    let synced = 0;
    for (const action of actions.sort((a, b) => a.createdAt.localeCompare(b.createdAt))) {
        try {
            const response = await axios.post('/api/v1/assistant/apply', { message: action.message, request_id: action.id, selected_actions: action.selectedActions });
            await db.delete('assistant-actions', action.id);
            synced += 1;
            onApplied?.(response.data.created ?? [], action.id);
        } catch (error: any) {
            if (!navigator.onLine || error.response?.status >= 500) break;
            // Validation errors need deliberate user correction and are kept in the queue.
            break;
        }
    }
    return synced;
}