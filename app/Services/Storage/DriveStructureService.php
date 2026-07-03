<?php

namespace App\Services\Storage;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\StorageConnection;
use Illuminate\Support\Facades\Log;

/**
 * High-level Drive management service for gallery folder structure.
 */
class DriveStructureService
{
    private GoogleDriveStorageProvider $provider;
    private StorageConnection $connection;

    public function __construct(StorageConnection $connection)
    {
        $this->connection = $connection;
        $this->provider   = new GoogleDriveStorageProvider($connection);
    }

    /**
     * Initialize the root folder structure.
     */
    public function initializeRootStructure(): array
    {
        $rootName = config('gallery.drive_root_folder_name', 'Stanektech Gallery');

        // Create root if not exists
        $existing = $this->provider->find('root', $rootName);
        $root     = $existing ?? $this->provider->createFolder($rootName, 'root');

        $this->connection->update([
            'root_folder_id'   => $root['id'],
            'root_folder_name' => $rootName,
        ]);

        // Create sub-structure
        $libraries = $this->ensureFolder('Libraries', $root['id']);
        $system    = $this->ensureFolder('System', $root['id']);

        // Default gallery inside Libraries
        $nasgalerie = $this->ensureFolder('Naše galerie', $libraries['id']);

        // System folders
        $this->ensureFolder('Imports', $system['id']);
        $this->ensureFolder('Exports', $system['id']);
        $this->ensureFolder('Metadata Backups', $system['id']);
        $this->ensureFolder('Recovery', $system['id']);

        return [
            'root_id'        => $root['id'],
            'libraries_id'   => $libraries['id'],
            'system_id'      => $system['id'],
            'nas_galerie_id' => $nasgalerie['id'],
        ];
    }

    /**
     * Ensure a folder exists inside a parent (create if missing).
     */
    public function ensureFolder(string $name, string $parentId): array
    {
        $existing = $this->provider->find($parentId, $name);
        return $existing ?? $this->provider->createFolder($name, $parentId);
    }

    /**
     * Create Drive folder for an album.
     */
    public function createAlbumFolder(Album $album): string
    {
        $parent = $album->parent;
        $parentDriveId = $parent
            ? $parent->drive_folder_id
            : $this->getLibrariesDefaultFolderId();

        if (!$parentDriveId) {
            throw new \RuntimeException("Parent album Drive folder ID not set for album #{$album->id}");
        }

        $folder = $this->provider->createFolder($album->title, $parentDriveId);
        $album->update([
            'drive_folder_id'        => $folder['id'],
            'drive_parent_folder_id' => $parentDriveId,
            'sync_status'            => 'synced',
        ]);

        return $folder['id'];
    }

    /**
     * Rename a Drive folder for an album.
     */
    public function renameAlbumFolder(Album $album, string $newName): void
    {
        if (!$album->drive_folder_id) return;

        $this->provider->renameFolder($album->drive_folder_id, $newName);
        $album->update(['sync_status' => 'synced']);
    }

    /**
     * Move a Drive folder to a new parent.
     */
    public function moveAlbumFolder(Album $album, string $newParentDriveId): void
    {
        if (!$album->drive_folder_id) return;

        $this->provider->moveFolder($album->drive_folder_id, $newParentDriveId);
        $album->update([
            'drive_parent_folder_id' => $newParentDriveId,
            'sync_status'            => 'synced',
        ]);
    }

    /**
     * Run a diagnostic test on Drive connectivity.
     */
    public function runDiagnosticTest(): array
    {
        $results = [];

        // Test 1: Can get about info
        try {
            $about = $this->provider->getAbout();
            $results['get_about'] = ['pass' => true, 'detail' => $about['email']];
        } catch (\Throwable $e) {
            $results['get_about'] = ['pass' => false, 'detail' => $e->getMessage()];
            return $results;
        }

        // Test 2: Can create test folder
        $testFolderName = 'Gallery-Test-' . now()->timestamp;
        try {
            $rootFolderId = $this->connection->root_folder_id;
            if (!$rootFolderId) throw new \RuntimeException('Root folder not configured');

            $folder = $this->provider->createFolder($testFolderName, $rootFolderId);
            $results['create_folder'] = ['pass' => true, 'detail' => $folder['id']];
        } catch (\Throwable $e) {
            $results['create_folder'] = ['pass' => false, 'detail' => $e->getMessage()];
            return $results;
        }

        // Test 3: Can create a test file
        $testFileContent = 'Gallery connectivity test ' . now()->toIso8601String();
        $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_test_');
        file_put_contents($tmpPath, $testFileContent);

        try {
            $testFile = $this->provider->upload($tmpPath, 'test.txt', $folder['id'], 'text/plain');
            $results['upload_file'] = ['pass' => true, 'detail' => $testFile['id']];
        } catch (\Throwable $e) {
            $results['upload_file'] = ['pass' => false, 'detail' => $e->getMessage()];
            @unlink($tmpPath);
            // Cleanup folder
            try { $this->provider->deletePermanently($folder['id']); } catch (\Throwable) {}
            return $results;
        }

        @unlink($tmpPath);

        // Test 4: Can read file metadata
        try {
            $meta = $this->provider->getMetadata($testFile['id']);
            $results['read_file'] = ['pass' => true, 'detail' => $meta['name']];
        } catch (\Throwable $e) {
            $results['read_file'] = ['pass' => false, 'detail' => $e->getMessage()];
        }

        // Test 5: Clean up
        try {
            $this->provider->deletePermanently($testFile['id']);
            $this->provider->deletePermanently($folder['id']);
            $results['cleanup'] = ['pass' => true, 'detail' => 'deleted'];
        } catch (\Throwable $e) {
            $results['cleanup'] = ['pass' => false, 'detail' => $e->getMessage()];
        }

        return $results;
    }

    private function getLibrariesDefaultFolderId(): ?string
    {
        // Read from system settings
        return \App\Models\SystemSetting::get('drive_libraries_folder_id');
    }
}
