/**
 * PWA Upload Queue — IndexedDB based
 * Stores upload state that survives page refresh, PWA restart, and short network outages.
 * No AI — classical queue management.
 */

import { IDBPDatabase, openDB } from "idb";

export interface UploadQueueItem {
    id: string; // UUID matching server upload session
    localFileId: string; // IndexedDB file reference key
    filename: string;
    mimeType: string;
    totalSize: number;
    totalChunks: number;
    uploadedChunks: number[];
    targetAlbumId: number | null;
    status:
        | "waiting"
        | "uploading"
        | "paused"
        | "offline"
        | "processing"
        | "completed"
        | "failed";
    error?: string;
    createdAt: number;
    updatedAt: number;
    sha256?: string;
}

const DB_NAME = "gallery-uploads";
const DB_VERSION = 1;
const STORE_NAME = "queue";
const FILE_STORE = "files";

let db: IDBPDatabase | null = null;

async function getDb(): Promise<IDBPDatabase> {
    if (db) return db;

    db = await openDB(DB_NAME, DB_VERSION, {
        upgrade(database) {
            if (!database.objectStoreNames.contains(STORE_NAME)) {
                const store = database.createObjectStore(STORE_NAME, {
                    keyPath: "id",
                });
                store.createIndex("status", "status");
                store.createIndex("createdAt", "createdAt");
            }
            if (!database.objectStoreNames.contains(FILE_STORE)) {
                database.createObjectStore(FILE_STORE, { keyPath: "id" });
            }
        },
    });

    return db;
}

/**
 * Add a new file to the upload queue.
 */
export async function enqueueUpload(
    file: File,
    targetAlbumId: number | null,
    chunkSize: number = 8 * 1024 * 1024,
): Promise<UploadQueueItem> {
    const database = await getDb();
    const id = crypto.randomUUID();
    const totalChunks = Math.ceil(file.size / chunkSize);

    // Store the File object reference
    await database.put(FILE_STORE, { id, file, storedAt: Date.now() });

    const item: UploadQueueItem = {
        id,
        localFileId: id,
        filename: file.name,
        mimeType: file.type || "application/octet-stream",
        totalSize: file.size,
        totalChunks,
        uploadedChunks: [],
        targetAlbumId,
        status: "waiting",
        createdAt: Date.now(),
        updatedAt: Date.now(),
    };

    await database.put(STORE_NAME, item);
    return item;
}

/**
 * Get all pending/waiting/paused upload items.
 */
export async function getPendingUploads(): Promise<UploadQueueItem[]> {
    const database = await getDb();
    const all = (await database.getAll(STORE_NAME)) as UploadQueueItem[];
    return all
        .filter((item) => !["completed", "failed"].includes(item.status))
        .sort((a, b) => a.createdAt - b.createdAt);
}

/**
 * Get all upload items (including completed/failed).
 */
export async function getAllUploads(): Promise<UploadQueueItem[]> {
    const database = await getDb();
    const all = (await database.getAll(STORE_NAME)) as UploadQueueItem[];
    return all.sort((a, b) => b.createdAt - a.createdAt);
}

/**
 * Update upload item status and progress.
 */
export async function updateUpload(
    id: string,
    updates: Partial<UploadQueueItem>,
): Promise<void> {
    const database = await getDb();
    const existing = (await database.get(STORE_NAME, id)) as
        | UploadQueueItem
        | undefined;
    if (!existing) return;

    await database.put(STORE_NAME, {
        ...existing,
        ...updates,
        updatedAt: Date.now(),
    });
}

/**
 * Mark a chunk as uploaded.
 */
export async function markChunkUploaded(
    id: string,
    chunkIndex: number,
): Promise<void> {
    const database = await getDb();
    const item = (await database.get(STORE_NAME, id)) as
        | UploadQueueItem
        | undefined;
    if (!item) return;

    if (!item.uploadedChunks.includes(chunkIndex)) {
        item.uploadedChunks.push(chunkIndex);
        item.uploadedChunks.sort((a, b) => a - b);
    }

    await database.put(STORE_NAME, { ...item, updatedAt: Date.now() });
}

/**
 * Get the File object for an upload item.
 */
export async function getFileForUpload(
    localFileId: string,
): Promise<File | null> {
    const database = await getDb();
    const stored = (await database.get(FILE_STORE, localFileId)) as
        | { id: string; file: File }
        | undefined;
    return stored?.file ?? null;
}

/**
 * Remove a completed or cancelled upload from the queue.
 */
export async function removeUpload(id: string): Promise<void> {
    const database = await getDb();
    await database.delete(STORE_NAME, id);
    await database.delete(FILE_STORE, id);
}

/**
 * Pause all currently uploading items (e.g., on offline event).
 */
export async function pauseAllUploads(): Promise<void> {
    const items = await getPendingUploads();
    const database = await getDb();

    for (const item of items) {
        if (item.status === "uploading") {
            await database.put(STORE_NAME, {
                ...item,
                status: "offline",
                updatedAt: Date.now(),
            });
        }
    }
}

/**
 * Resume offline items when connection is restored.
 */
export async function resumeOfflineUploads(): Promise<void> {
    const database = await getDb();
    const all = (await database.getAll(STORE_NAME)) as UploadQueueItem[];

    for (const item of all) {
        if (item.status === "offline" || item.status === "paused") {
            await database.put(STORE_NAME, {
                ...item,
                status: "waiting",
                updatedAt: Date.now(),
            });
        }
    }
}

/**
 * Calculate overall queue statistics.
 */
export async function getQueueStats(): Promise<{
    total: number;
    waiting: number;
    uploading: number;
    completed: number;
    failed: number;
}> {
    const all = await getAllUploads();
    return {
        total: all.length,
        waiting: all.filter((i) => i.status === "waiting").length,
        uploading: all.filter((i) => i.status === "uploading").length,
        completed: all.filter((i) => i.status === "completed").length,
        failed: all.filter((i) => i.status === "failed").length,
    };
}

// Listen for online/offline events
if (typeof window !== "undefined") {
    window.addEventListener("offline", () => pauseAllUploads());
    window.addEventListener("online", () => resumeOfflineUploads());
}
