/**
 * UploadManager — Singleton service for managing all file uploads.
 * Features: concurrent (3 parallel), pause/resume, cancel, retry,
 * SHA-256 duplicate detection, network recovery, persistence.
 */

import axios from "axios";

// Musí bezpečně projít i výchozí konfigurací PHP (upload_max_filesize=2M).
// Multipart obálka má vlastní režii, proto nepoužíváme hranici 2 MiB.
const CHUNK_SIZE = 1 * 1024 * 1024; // 1 MiB
const MAX_CONCURRENT = 3;
const SHA256_LIMIT = 200 * 1024 * 1024; // Only hash files < 200 MB

export type UploadStatus =
    | "waiting"
    | "hashing"
    | "duplicate"
    | "uploading"
    | "paused"
    | "offline"
    | "processing"
    | "done"
    | "error"
    | "cancelled";

export interface ManagedUpload {
    id: string;
    file: File;
    filename: string;
    size: number;
    albumId: number | null;
    status: UploadStatus;
    percent: number;
    error?: string;
    thumb?: string; // ObjectURL preview
    mediaUuid?: string; // Set after done or duplicate
    sha256?: string;
    /** Server-side session UUID (set after initiate) */
    sessionUuid?: string;
    /** Chunks already confirmed by server */
    uploadedChunks: number[];
    totalChunks: number;
}

// ─── Manager ──────────────────────────────────────────────────────────────

class UploadManagerClass extends EventTarget {
    private queue: ManagedUpload[] = [];
    private aborts = new Map<string, AbortController>();
    private pausedIds = new Set<string>();
    private globalPause = false;
    private online = navigator.onLine;

    constructor() {
        super();
        window.addEventListener("online", () => {
            this.online = true;
            this.handleOnline();
        });
        window.addEventListener("offline", () => {
            this.online = false;
            this.handleOffline();
        });
    }

    // ── Public API ────────────────────────────────────────────────────────

    enqueue(files: File[], albumId: number | null = null): void {
        for (const file of files) {
            const id = crypto.randomUUID();
            const thumb = file.type.startsWith("image/")
                ? URL.createObjectURL(file)
                : undefined;
            const chunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
            const item: ManagedUpload = {
                id,
                file,
                filename: file.name,
                size: file.size,
                albumId,
                status: "waiting",
                percent: 0,
                thumb,
                uploadedChunks: [],
                totalChunks: chunks,
            };
            this.queue.push(item);
        }
        this.emit();
        this.process();
    }

    pause(id: string): void {
        const item = this.find(id);
        if (!item || !["uploading", "waiting"].includes(item.status)) return;
        this.pausedIds.add(id);
        if (item.status === "uploading") {
            this.aborts.get(id)?.abort();
            this.aborts.delete(id);
        }
        item.status = "paused";
        this.emit();
    }

    resume(id: string): void {
        const item = this.find(id);
        if (!item) return;
        this.pausedIds.delete(id);
        if (item.status === "paused" || item.status === "offline") {
            item.status = "waiting";
            this.emit();
            this.process();
        }
    }

    cancel(id: string): void {
        const item = this.find(id);
        if (!item) return;
        this.pausedIds.delete(id);
        this.aborts.get(id)?.abort();
        this.aborts.delete(id);
        if (item.thumb) {
            URL.revokeObjectURL(item.thumb);
        }
        item.status = "cancelled";
        item.percent = 0;
        this.emit();
        this.process();
    }

    retry(id: string): void {
        const item = this.find(id);
        if (!item || !["error", "cancelled"].includes(item.status)) return;
        item.status = "waiting";
        item.percent = 0;
        item.error = undefined;
        item.sessionUuid = undefined;
        item.uploadedChunks = [];
        this.pausedIds.delete(id);
        this.emit();
        this.process();
    }

    remove(id: string): void {
        const item = this.find(id);
        if (!item) return;
        this.cancel(id);
        this.queue = this.queue.filter((u) => u.id !== id);
        this.emit();
    }

    pauseAll(): void {
        this.globalPause = true;
        const uploading = this.queue.filter((u) => u.status === "uploading");
        for (const u of uploading) {
            this.pausedIds.add(u.id);
            this.aborts.get(u.id)?.abort();
            this.aborts.delete(u.id);
            u.status = "paused";
        }
        // Mark waiting as paused too
        this.queue
            .filter((u) => u.status === "waiting")
            .forEach((u) => {
                u.status = "paused";
                this.pausedIds.add(u.id);
            });
        this.emit();
    }

    resumeAll(): void {
        this.globalPause = false;
        this.pausedIds.clear();
        this.queue
            .filter((u) => u.status === "paused")
            .forEach((u) => {
                u.status = "waiting";
            });
        this.emit();
        this.process();
    }

    clearDone(): void {
        const removed = this.queue.filter((u) =>
            ["done", "duplicate", "cancelled"].includes(u.status),
        );
        removed.forEach((u) => {
            if (u.thumb) URL.revokeObjectURL(u.thumb);
        });
        this.queue = this.queue.filter(
            (u) => !["done", "duplicate", "cancelled"].includes(u.status),
        );
        this.emit();
    }

    getAll(): ManagedUpload[] {
        return [...this.queue];
    }

    getStats() {
        const all = this.queue;
        return {
            total: all.length,
            waiting: all.filter((u) => u.status === "waiting").length,
            uploading: all.filter((u) =>
                ["uploading", "hashing"].includes(u.status),
            ).length,
            done: all.filter((u) => ["done", "duplicate"].includes(u.status))
                .length,
            error: all.filter((u) => u.status === "error").length,
            paused: all.filter((u) => u.status === "paused").length,
            cancelled: all.filter((u) => u.status === "cancelled").length,
            percent: this.overallPercent(),
            allPaused: this.globalPause,
        };
    }

    // ── Private ───────────────────────────────────────────────────────────

    private find(id: string): ManagedUpload | undefined {
        return this.queue.find((u) => u.id === id);
    }

    private activeCount(): number {
        return Array.from(this.aborts.keys()).filter((id) => {
            const item = this.find(id);
            return item && item.status === "uploading";
        }).length;
    }

    private process(): void {
        if (this.globalPause || !this.online) return;
        while (this.activeCount() < MAX_CONCURRENT) {
            const next = this.queue.find(
                (u) => u.status === "waiting" && !this.pausedIds.has(u.id),
            );
            if (!next) break;
            void this.runUpload(next);
        }
    }

    private async runUpload(item: ManagedUpload): Promise<void> {
        const ctrl = new AbortController();
        this.aborts.set(item.id, ctrl);

        try {
            // ── SHA-256 duplicate check (skip for very large files) ──
            if (item.size <= SHA256_LIMIT && !item.sha256) {
                item.status = "hashing";
                this.emit();
                item.sha256 = await this.sha256(item.file);
            }

            if (ctrl.signal.aborted) return;

            if (item.sha256) {
                const dupRes = await axios.post(
                    "/api/v1/uploads/check-duplicate",
                    { sha256: item.sha256, target_album_id: item.albumId ?? null },
                    { signal: ctrl.signal },
                );
                if (dupRes.data.exists) {
                    item.status = "duplicate";
                    item.percent = 100;
                    item.mediaUuid = dupRes.data.media_uuid;
                    this.aborts.delete(item.id);
                    this.emit();
                    this.process();
                    return;
                }
            }

            if (ctrl.signal.aborted) return;

            // ── Initiate or resume server session ──
            item.status = "uploading";
            this.emit();

            let sessionUuid = item.sessionUuid;
            let alreadyUploaded: number[] = item.uploadedChunks;

            if (!sessionUuid) {
                const init = await axios.post(
                    "/api/v1/uploads",
                    {
                        filename: item.filename,
                        mime_type: item.file.type || "application/octet-stream",
                        total_size: item.size,
                        total_chunks: item.totalChunks,
                        sha256: item.sha256 ?? null,
                        target_album_id: item.albumId ?? null,
                    },
                    { signal: ctrl.signal },
                );

                sessionUuid = init.data.uuid as string;
                item.sessionUuid = sessionUuid;
            } else {
                // Resume: ask server which chunks it has
                const statusRes = await axios.get(
                    `/api/v1/uploads/${sessionUuid}`,
                    { signal: ctrl.signal },
                );
                alreadyUploaded = statusRes.data.received_indexes ?? [];
                item.uploadedChunks = alreadyUploaded;
            }

            // ── Upload chunks ──
            for (let idx = 0; idx < item.totalChunks; idx++) {
                if (ctrl.signal.aborted) return;
                if (alreadyUploaded.includes(idx)) continue;
                // Check if this item got paused mid-flight
                if (this.pausedIds.has(item.id)) {
                    item.status = "paused";
                    this.emit();
                    return;
                }

                const start = idx * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, item.size);
                const blob = item.file.slice(start, end);
                const form = new FormData();
                form.append("chunk", blob, `chunk_${idx}`);

                await axios.put(
                    `/api/v1/uploads/${sessionUuid}/chunks/${idx}`,
                    form,
                    {
                        headers: { "Content-Type": "multipart/form-data" },
                        timeout: 120_000,
                        signal: ctrl.signal,
                    },
                );

                item.uploadedChunks = [...item.uploadedChunks, idx];
                item.percent = Math.round(
                    (item.uploadedChunks.length / item.totalChunks) * 90,
                );
                this.emit();
            }

            // ── Complete ──
            if (ctrl.signal.aborted) return;
            item.status = "processing";
            item.percent = 95;
            this.emit();

            const completeRes = await axios.post(
                `/api/v1/uploads/${sessionUuid}/complete`,
                {},
                { signal: ctrl.signal },
            );
            item.mediaUuid = completeRes.data.media_uuid;
            item.status = "done";
            item.percent = 100;
        } catch (err: any) {
            if (ctrl.signal.aborted || err?.code === "ERR_CANCELED") {
                // paused or cancelled — status already set
                if (item.status === "uploading" || item.status === "hashing") {
                    item.status = this.pausedIds.has(item.id)
                        ? "paused"
                        : "cancelled";
                }
            } else if (!navigator.onLine) {
                item.status = "offline";
            } else {
                item.status = "error";
                item.error =
                    err?.response?.data?.message ??
                    err?.message ??
                    "Upload failed";
            }
        } finally {
            this.aborts.delete(item.id);
            this.emit();
            this.process();
        }
    }

    private handleOnline(): void {
        // Resume any offline items
        this.queue
            .filter((u) => u.status === "offline")
            .forEach((u) => {
                u.status = "waiting";
            });
        this.emit();
        this.process();
    }

    private handleOffline(): void {
        this.queue
            .filter((u) => u.status === "uploading")
            .forEach((u) => {
                this.aborts.get(u.id)?.abort();
                this.aborts.delete(u.id);
                u.status = "offline";
            });
        this.emit();
    }

    private overallPercent(): number {
        const relevant = this.queue.filter(
            (u) => !["cancelled"].includes(u.status),
        );
        if (relevant.length === 0) return 0;
        const total = relevant.reduce(
            (sum, u) =>
                sum +
                (u.status === "done" || u.status === "duplicate"
                    ? 100
                    : u.percent),
            0,
        );
        return Math.round(total / relevant.length);
    }

    private emit(): void {
        this.dispatchEvent(
            new CustomEvent("change", { detail: { uploads: [...this.queue] } }),
        );
    }

    private async sha256(file: File): Promise<string> {
        const buf = await file.arrayBuffer();
        const hash = await crypto.subtle.digest("SHA-256", buf);
        return Array.from(new Uint8Array(hash))
            .map((b) => b.toString(16).padStart(2, "0"))
            .join("");
    }
}

export const uploadManager = new UploadManagerClass();
