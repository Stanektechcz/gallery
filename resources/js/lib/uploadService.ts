/**
 * Chunked upload service — handles browser-to-Laravel resumable uploads.
 * Uses IndexedDB queue for persistence across page refreshes.
 */

import axios from "axios";
import {
    UploadQueueItem,
    enqueueUpload,
    getFileForUpload,
    markChunkUploaded,
    updateUpload,
} from "./uploadQueue";

const CHUNK_SIZE = 8 * 1024 * 1024; // 8 MB

export interface UploadProgress {
    id: string;
    filename: string;
    percent: number;
    status: string;
    mediaId?: number;
}

type ProgressCallback = (progress: UploadProgress) => void;

/**
 * Start uploading a file. Returns the upload session ID.
 */
export async function startUpload(
    file: File,
    targetAlbumId: number | null,
    onProgress?: ProgressCallback,
): Promise<string> {
    // Enqueue in IndexedDB
    const queueItem = await enqueueUpload(file, targetAlbumId, CHUNK_SIZE);

    // Initiate server-side session
    const initResponse = await axios.post("/api/v1/uploads", {
        filename: file.name,
        mime_type: file.type || "application/octet-stream",
        total_size: file.size,
        total_chunks: queueItem.totalChunks,
        target_album_id: targetAlbumId,
    });

    const serverSessionUuid = initResponse.data.uuid as string;

    // Store server UUID in local queue item (using the server UUID as the id)
    await updateUpload(queueItem.id, {
        id: serverSessionUuid,
        status: "uploading",
    });

    // Upload chunks
    await uploadChunks(serverSessionUuid, file, queueItem, onProgress);

    return serverSessionUuid;
}

/**
 * Resume an interrupted upload.
 */
export async function resumeUpload(
    item: UploadQueueItem,
    onProgress?: ProgressCallback,
): Promise<void> {
    const file = await getFileForUpload(item.localFileId);
    if (!file) {
        await updateUpload(item.id, {
            status: "failed",
            error: "Local file no longer available",
        });
        return;
    }

    // Get server-side status
    const statusResponse = await axios.get(`/api/v1/uploads/${item.id}`);
    const receivedIndexes = statusResponse.data.received_indexes as number[];

    await updateUpload(item.id, {
        uploadedChunks: receivedIndexes,
        status: "uploading",
    });

    await uploadChunks(
        item.id,
        file,
        { ...item, uploadedChunks: receivedIndexes },
        onProgress,
    );
}

async function uploadChunks(
    sessionId: string,
    file: File,
    item: UploadQueueItem,
    onProgress?: ProgressCallback,
): Promise<void> {
    const totalChunks = item.totalChunks;

    for (let i = 0; i < totalChunks; i++) {
        // Skip already uploaded chunks
        if (item.uploadedChunks.includes(i)) continue;

        const start = i * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, file.size);
        const blob = file.slice(start, end);

        const formData = new FormData();
        formData.append("chunk", blob, `chunk_${i}`);

        await axios.put(`/api/v1/uploads/${sessionId}/chunks/${i}`, formData, {
            headers: { "Content-Type": "multipart/form-data" },
            timeout: 120000,
        });

        await markChunkUploaded(sessionId, i);

        const percent = Math.round(((i + 1) / totalChunks) * 90); // 90% for upload phase
        onProgress?.({
            id: sessionId,
            filename: file.name,
            percent,
            status: "uploading",
        });
    }

    // Complete — server assembles synchronously and returns media_id immediately
    const completeRes = await axios.post(`/api/v1/uploads/${sessionId}/complete`);
    const completeData = completeRes.data as { status: string; media_id?: number };

    if (completeData.status === 'completed') {
        await updateUpload(sessionId, { status: 'completed' });
        onProgress?.({
            id: sessionId,
            filename: file.name,
            percent: 100,
            status: 'done',
            mediaId: completeData.media_id,
        });
        return;
    }

    // Fallback: poll if server returned assembling (e.g. async queue mode)
    await updateUpload(sessionId, { status: "processing" });
    onProgress?.({
        id: sessionId,
        filename: file.name,
        percent: 95,
        status: "processing",
    });
    await pollCompletion(sessionId, file.name, onProgress);
}

async function pollCompletion(
    sessionId: string,
    filename: string,
    onProgress?: ProgressCallback,
): Promise<void> {
    const maxAttempts = 120; // 10 minutes
    let attempts = 0;

    while (attempts < maxAttempts) {
        await new Promise((r) => setTimeout(r, 5000));

        try {
            const status = await axios.get(`/api/v1/uploads/${sessionId}`);
            const data = status.data;

            if (
                data.status === "completed" ||
                (data.status === "completed" && data.media_id)
            ) {
                await updateUpload(sessionId, { status: "completed" });
                onProgress?.({
                    id: sessionId,
                    filename,
                    percent: 100,
                    status: "completed",
                    mediaId: data.media_id,
                });
                return;
            }

            if (data.status === "failed") {
                await updateUpload(sessionId, {
                    status: "failed",
                    error: "Processing failed on server",
                });
                onProgress?.({
                    id: sessionId,
                    filename,
                    percent: 0,
                    status: "failed",
                });
                return;
            }
        } catch {
            // Network error — will retry
        }

        attempts++;
    }

    await updateUpload(sessionId, {
        status: "failed",
        error: "Timeout waiting for processing",
    });
}
