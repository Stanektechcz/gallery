<?php

namespace App\Contracts;

interface StorageProviderInterface
{
    public function connect(array $credentials): bool;
    public function disconnect(): bool;
    public function refreshCredentials(): bool;
    public function healthCheck(): array;

    public function createFolder(string $name, ?string $parentId = null): array;
    public function renameFolder(string $folderId, string $newName): array;
    public function moveFolder(string $folderId, string $newParentId): array;
    public function deleteFolder(string $folderId, bool $permanent = false): bool;
    public function listFolder(string $folderId, int $pageSize = 100, ?string $pageToken = null): array;

    public function upload(string $localPath, string $remoteName, string $parentId, string $mimeType): array;
    public function createResumableSession(string $remoteName, string $parentId, string $mimeType, int $totalSize): string;
    public function uploadChunk(string $sessionUri, string $data, int $rangeStart, int $rangeEnd, int $totalSize): array;
    public function queryResumableStatus(string $sessionUri, int $totalSize): array;
    public function resumeUpload(string $sessionUri, string $localPath, int $alreadyUploaded, int $totalSize): array;

    public function download(string $fileId): \GuzzleHttp\Psr7\Stream;
    public function stream(string $fileId): mixed;
    public function trash(string $fileId): bool;
    public function restore(string $fileId): bool;
    public function deletePermanently(string $fileId): bool;

    public function getMetadata(string $fileId): array;
    public function find(string $parentId, string $name): ?array;
    public function getQuota(): array;

    public function watchChanges(string $webhookUrl, string $channelId, string $token): array;
    public function stopWatch(string $channelId, string $resourceId): bool;
    public function syncChanges(?string $pageToken = null): array;
    public function getStartPageToken(): string;
}
