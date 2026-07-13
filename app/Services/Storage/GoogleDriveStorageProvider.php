<?php

namespace App\Services\Storage;

use App\Contracts\StorageProviderInterface;
use App\Models\StorageConnection;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Support\Facades\Log;

class GoogleDriveStorageProvider implements StorageProviderInterface
{
    private GoogleClient $client;
    private Drive $service;
    private StorageConnection $connection;

    public function __construct(StorageConnection $connection)
    {
        $this->connection = $connection;
        $this->client     = $this->buildClient();
        $this->service    = new Drive($this->client);
    }

    private function buildClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));
        $client->setScopes([Drive::DRIVE_FILE]);
        $client->setAccessType('offline');

        $accessToken = $this->connection->getAccessToken();
        if ($accessToken) {
            $client->setAccessToken(json_decode($accessToken, true) ?: ['access_token' => $accessToken]);
        }

        return $client;
    }

    public function getAuthenticatedClient(): GoogleClient
    {
        if ($this->client->isAccessTokenExpired()) {
            $this->refreshCredentials();
        }
        return $this->client;
    }

    public function connect(array $credentials): bool
    {
        return true; // Handled by OAuth flow
    }

    public function disconnect(): bool
    {
        try {
            if ($token = $this->connection->getAccessToken()) {
                $this->client->revokeToken($token);
            }
            $this->connection->markStatus('disconnected');
            return true;
        } catch (\Throwable $e) {
            Log::error('GoogleDrive disconnect failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function refreshCredentials(): bool
    {
        try {
            $refreshToken = $this->connection->getRefreshToken();
            if (!$refreshToken) {
                $this->connection->markStatus('refresh_required');
                return false;
            }

            $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
            $newToken = $this->client->getAccessToken();

            if (isset($newToken['error'])) {
                if ($newToken['error'] === 'invalid_grant') {
                    $this->connection->markStatus('refresh_required');
                }
                return false;
            }

            $this->connection->setAccessToken(json_encode($newToken));
            $this->connection->update([
                'token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
            ]);
            $this->connection->markHealthy();

            return true;
        } catch (\Throwable $e) {
            Log::error('GoogleDrive token refresh failed', ['error' => $e->getMessage()]);
            $this->connection->markError('refresh_failed', $e->getMessage());
            return false;
        }
    }

    public function healthCheck(): array
    {
        try {
            $client  = $this->getAuthenticatedClient();
            $service = new Drive($client);
            $about   = $service->about->get(['fields' => 'user,storageQuota']);
            $quota   = $about->getStorageQuota();

            $this->connection->update([
                'account_email'  => $about->getUser()->getEmailAddress(),
                'quota_total'    => $quota->getLimit(),
                'quota_used'     => $quota->getUsage(),
                'quota_refreshed_at' => now(),
            ]);
            $this->connection->markHealthy();

            return [
                'status'       => 'healthy',
                'email'        => $about->getUser()->getEmailAddress(),
                'quota_total'  => $quota->getLimit(),
                'quota_used'   => $quota->getUsage(),
            ];
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleError($e);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getAbout(): array
    {
        $about = $this->service->about->get(['fields' => 'user,storageQuota']);
        return [
            'email'       => $about->getUser()->getEmailAddress(),
            'quota_total' => $about->getStorageQuota()->getLimit(),
            'quota_used'  => $about->getStorageQuota()->getUsage(),
        ];
    }

    public function getQuota(): array
    {
        return $this->getAbout();
    }

    public function createFolder(string $name, ?string $parentId = null): array
    {
        $meta = new DriveFile([
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => $parentId ? [$parentId] : [],
        ]);

        $file = $this->service->files->create($meta, ['fields' => 'id,name,parents,createdTime']);

        $this->connection->markHealthy();

        return [
            'id'          => $file->getId(),
            'name'        => $file->getName(),
            'parents'     => $file->getParents() ?? [],
            'created_time' => $file->getCreatedTime(),
        ];
    }

    public function renameFolder(string $folderId, string $newName): array
    {
        $meta = new DriveFile(['name' => $newName]);
        $file = $this->service->files->update($folderId, $meta, ['fields' => 'id,name']);
        $this->connection->markHealthy();
        return ['id' => $file->getId(), 'name' => $file->getName()];
    }

    public function moveFolder(string $folderId, string $newParentId): array
    {
        $file = $this->service->files->get($folderId, ['fields' => 'parents']);
        $oldParents = implode(',', $file->getParents() ?? []);

        $updated = $this->service->files->update($folderId, new DriveFile(), [
            'addParents'    => $newParentId,
            'removeParents' => $oldParents,
            'fields'        => 'id,parents',
        ]);

        $this->connection->markHealthy();
        return ['id' => $updated->getId(), 'parents' => $updated->getParents()];
    }

    public function moveFile(string $fileId, string $newParentId): array
    {
        return $this->moveFolder($fileId, $newParentId);
    }

    public function deleteFolder(string $folderId, bool $permanent = false): bool
    {
        if ($permanent) {
            $this->service->files->delete($folderId);
        } else {
            $this->service->files->update($folderId, new DriveFile(['trashed' => true]));
        }
        $this->connection->markHealthy();
        return true;
    }

    public function listFolder(string $folderId, int $pageSize = 100, ?string $pageToken = null): array
    {
        $params = [
            'q'         => "'{$folderId}' in parents and trashed = false",
            'pageSize'  => $pageSize,
            'fields'    => 'nextPageToken,files(id,name,mimeType,size,createdTime,modifiedTime,md5Checksum,parents)',
        ];
        if ($pageToken) $params['pageToken'] = $pageToken;

        $result = $this->service->files->listFiles($params);
        $this->connection->markHealthy();

        return [
            'files'      => array_map(fn($f) => $this->fileToArray($f), $result->getFiles()),
            'next_token' => $result->getNextPageToken(),
        ];
    }

    public function getFile(string $fileId): array
    {
        $file = $this->service->files->get($fileId, [
            'fields' => 'id,name,mimeType,size,createdTime,modifiedTime,md5Checksum,parents,trashed',
        ]);
        $this->connection->markHealthy();
        return $this->fileToArray($file);
    }

    public function getMetadata(string $fileId): array
    {
        return $this->getFile($fileId);
    }

    public function find(string $parentId, string $name): ?array
    {
        $safeName = addslashes($name);
        $result = $this->service->files->listFiles([
            'q'        => "'{$parentId}' in parents and name = '{$safeName}' and trashed = false",
            'pageSize' => 1,
            'fields'   => 'files(id,name,mimeType,size)',
        ]);

        $files = $result->getFiles();
        return count($files) > 0 ? $this->fileToArray($files[0]) : null;
    }

    public function upload(string $localPath, string $remoteName, string $parentId, string $mimeType): array
    {
        $meta   = new DriveFile(['name' => $remoteName, 'parents' => [$parentId]]);
        $content = file_get_contents($localPath);

        $file = $this->service->files->create($meta, [
            'data'        => $content,
            'mimeType'    => $mimeType,
            'uploadType'  => 'multipart',
            'fields'      => 'id,name,size,md5Checksum,parents',
        ]);

        $this->connection->markHealthy();
        return $this->fileToArray($file);
    }

    public function uploadSmallFile(string $localPath, string $remoteName, string $parentId, string $mimeType): array
    {
        return $this->upload($localPath, $remoteName, $parentId, $mimeType);
    }

    public function createResumableSession(string $remoteName, string $parentId, string $mimeType, int $totalSize): string
    {
        // The resumable endpoint itself creates the remote file after the last
        // chunk. Calling files->create first made an empty duplicate for every
        // video and could leave the real upload without the expected session.
        $httpClient = $this->getAuthenticatedClient()->authorize();
        $response   = $httpClient->request(
            'POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
            [
                'headers' => [
                    'Content-Type'            => 'application/json',
                    'X-Upload-Content-Type'   => $mimeType,
                    'X-Upload-Content-Length' => $totalSize,
                ],
                'body'    => json_encode(['name' => $remoteName, 'parents' => [$parentId]]),
            ]
        );

        $sessionUri = $response->getHeaderLine('Location');
        if (!$sessionUri) {
            throw new \RuntimeException('Failed to create resumable upload session: no Location header');
        }

        return $sessionUri;
    }

    public function uploadChunk(string $sessionUri, string $data, int $rangeStart, int $rangeEnd, int $totalSize): array
    {
        $httpClient  = $this->getAuthenticatedClient()->authorize();
        $contentLen  = strlen($data);
        $response    = $httpClient->request('PUT', $sessionUri, [
            'headers' => [
                'Content-Length' => $contentLen,
                'Content-Range'  => "bytes {$rangeStart}-{$rangeEnd}/{$totalSize}",
            ],
            'body'    => $data,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 308) {
            return ['status' => 'incomplete', 'range' => $response->getHeaderLine('Range')];
        }

        if (in_array($statusCode, [200, 201])) {
            $body = json_decode($response->getBody()->getContents(), true);
            $this->connection->markHealthy();
            return ['status' => 'complete', 'file' => $body];
        }

        throw new \RuntimeException("Unexpected upload status: {$statusCode}");
    }

    public function queryResumableStatus(string $sessionUri, int $totalSize): array
    {
        $httpClient = $this->getAuthenticatedClient()->authorize();
        $response   = $httpClient->request('PUT', $sessionUri, [
            'headers' => [
                'Content-Length' => 0,
                'Content-Range'  => "bytes */{$totalSize}",
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 308) {
            $range = $response->getHeaderLine('Range');
            $uploaded = $range ? (int) explode('-', $range)[1] + 1 : 0;
            return ['status' => 'incomplete', 'uploaded_bytes' => $uploaded];
        }

        if (in_array($statusCode, [200, 201])) {
            $body = json_decode($response->getBody()->getContents(), true);
            return ['status' => 'complete', 'file' => $body];
        }

        return ['status' => 'unknown', 'code' => $statusCode];
    }

    public function resumeUpload(string $sessionUri, string $localPath, int $alreadyUploaded, int $totalSize): array
    {
        $chunkSize = 8 * 1024 * 1024; // 8 MB
        $handle    = fopen($localPath, 'rb');
        if (!$handle) throw new \RuntimeException("Cannot open file: {$localPath}");

        fseek($handle, $alreadyUploaded);
        $result = [];

        while (!feof($handle)) {
            $chunk      = fread($handle, $chunkSize);
            $chunkLen   = strlen($chunk);
            $rangeStart = $alreadyUploaded;
            $rangeEnd   = $alreadyUploaded + $chunkLen - 1;

            $result = $this->uploadChunk($sessionUri, $chunk, $rangeStart, $rangeEnd, $totalSize);
            $alreadyUploaded += $chunkLen;

            if ($result['status'] === 'complete') break;
        }

        fclose($handle);
        return $result;
    }

    public function download(string $fileId): Stream
    {
        $response = $this->service->files->get($fileId, ['alt' => 'media']);
        $this->connection->markHealthy();
        return $response->getBody();
    }

    public function stream(string $fileId): mixed
    {
        return $this->download($fileId);
    }

    public function trash(string $fileId): bool
    {
        $this->service->files->update($fileId, new DriveFile(['trashed' => true]));
        $this->connection->markHealthy();
        return true;
    }

    public function restore(string $fileId): bool
    {
        $this->service->files->update($fileId, new DriveFile(['trashed' => false]));
        $this->connection->markHealthy();
        return true;
    }

    public function trashFile(string $fileId): bool
    {
        return $this->trash($fileId);
    }
    public function restoreFile(string $fileId): bool
    {
        return $this->restore($fileId);
    }

    public function deletePermanently(string $fileId): bool
    {
        $this->service->files->delete($fileId);
        $this->connection->markHealthy();
        return true;
    }

    public function deleteFilePermanently(string $fileId): bool
    {
        return $this->deletePermanently($fileId);
    }

    public function watchChanges(string $webhookUrl, string $channelId, string $token): array
    {
        return $this->createWatchChannel($webhookUrl, $channelId, $token);
    }

    public function createWatchChannel(string $webhookUrl, string $channelId, string $token): array
    {
        $channel = new \Google\Service\Drive\Channel([
            'id'      => $channelId,
            'type'    => 'web_hook',
            'address' => $webhookUrl,
            'token'   => $token,
        ]);

        $result = $this->service->files->watch('root', $channel);
        $this->connection->markHealthy();

        return [
            'channel_id'  => $result->getId(),
            'resource_id' => $result->getResourceId(),
            'expiration'  => $result->getExpiration(),
        ];
    }

    public function renewWatchChannel(string $webhookUrl, string $channelId, string $token): array
    {
        return $this->createWatchChannel($webhookUrl, $channelId, $token);
    }

    public function stopWatchChannel(string $channelId, string $resourceId): bool
    {
        $channel = new \Google\Service\Drive\Channel([
            'id'         => $channelId,
            'resourceId' => $resourceId,
        ]);
        $this->service->channels->stop($channel);
        return true;
    }

    public function stopWatch(string $channelId, string $resourceId): bool
    {
        return $this->stopWatchChannel($channelId, $resourceId);
    }

    public function getStartPageToken(): string
    {
        $result = $this->service->changes->getStartPageToken();
        return $result->getStartPageToken();
    }

    public function listChanges(?string $pageToken = null): array
    {
        $params = [
            'pageToken' => $pageToken ?? $this->getStartPageToken(),
            'spaces'    => 'drive',
            'fields'    => 'kind,nextPageToken,newStartPageToken,changes(fileId,removed,time,file(id,name,mimeType,parents,trashed,size,md5Checksum))',
        ];

        $result = $this->service->changes->listChanges($params['pageToken'], $params);
        $this->connection->markHealthy();

        return [
            'changes'         => array_map(fn($c) => [
                'file_id'  => $c->getFileId(),
                'removed'  => $c->getRemoved(),
                'time'     => $c->getTime(),
                'file'     => $c->getFile() ? $this->fileToArray($c->getFile()) : null,
            ], $result->getChanges()),
            'next_page_token' => $result->getNextPageToken(),
            'new_start_token' => $result->getNewStartPageToken(),
        ];
    }

    public function syncChanges(?string $pageToken = null): array
    {
        return $this->listChanges($pageToken);
    }

    private function fileToArray(\Google\Service\Drive\DriveFile $file): array
    {
        return [
            'id'           => $file->getId(),
            'name'         => $file->getName(),
            'mime_type'    => $file->getMimeType(),
            'size'         => $file->getSize(),
            'md5_checksum' => $file->getMd5Checksum(),
            'parents'      => $file->getParents() ?? [],
            'created_time' => $file->getCreatedTime(),
            'modified_time' => $file->getModifiedTime(),
            'trashed'      => $file->getTrashed(),
        ];
    }

    private function handleGoogleError(\Google\Service\Exception $e): void
    {
        $code = $e->getCode();
        $message = $e->getMessage();

        $this->connection->markError((string) $code, $message);

        match (true) {
            $code === 401                                          => $this->connection->markStatus('refresh_required'),
            $code === 403 && str_contains($message, 'admin_policy') => $this->connection->markStatus('admin_blocked'),
            $code === 403 && str_contains($message, 'rate')      => $this->connection->markStatus('rate_limited'),
            $code === 429                                          => $this->connection->markStatus('rate_limited'),
            $code >= 500                                          => $this->connection->markStatus('error'),
            default                                               => null,
        };

        Log::warning('GoogleDrive API error', [
            'code'    => $code,
            'message' => $message,
            'status'  => $this->connection->connection_status,
        ]);
    }
}
