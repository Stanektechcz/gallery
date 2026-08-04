<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\UploadSession;
use App\Support\SpaceContext;
use Inertia\Inertia;
use Inertia\Response;

class StorageRiskController extends Controller
{
    public function index(): Response
    {
        $connection = StorageConnection::where('provider', 'google_drive')->first();

        // Operator view: counts cover the whole installation, not just this admin's spaces.
        $onDrive = fn () => MediaItem::withoutGlobalScope(SpaceContext::SCOPE)->whereNotNull('drive_file_id');
        $originalsOnDrive = $onDrive()->count();
        $totalOriginalSize = $onDrive()->sum('size_bytes');
        $pendingUploads   = UploadSession::whereIn('status', ['pending', 'assembling'])->count();
        $failedUploads    = UploadSession::where('status', 'failed')->count();

        $lastIntegrity = \App\Models\SystemSetting::get('last_drive_integrity_scan');
        $lastBackup    = \App\Models\SystemSetting::get('last_metadata_backup');

        return Inertia::render('Admin/StorageRisk', [
            'connection'       => $connection ? [
                'status'       => $connection->connection_status,
                'account_email' => $connection->account_email,
                'quota_total'  => $connection->quota_total,
                'quota_used'   => $connection->quota_used,
                'last_ok'      => $connection->last_successful_request_at,
                'connected_at' => $connection->connected_at,
                'last_error'   => $connection->last_error_message,
            ] : null,
            'originals_count'   => $originalsOnDrive,
            'originals_size'    => $totalOriginalSize,
            'pending_uploads'   => $pendingUploads,
            'failed_uploads'    => $failedUploads,
            'last_integrity'    => $lastIntegrity,
            'last_backup'       => $lastBackup,
        ]);
    }
}
