<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Drive\ProcessDriveWebhookJob;
use App\Models\DriveChangeChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class GoogleDriveWebhookController extends Controller
{
    /**
     * POST /webhooks/google-drive
     * Receives Google Drive push notifications.
     * Must return 2xx quickly — all processing is in a queue job.
     */
    public function handle(Request $request): Response
    {
        // Verify required headers
        $channelId   = $request->header('X-Goog-Channel-Id');
        $channelToken = $request->header('X-Goog-Channel-Token');
        $state       = $request->header('X-Goog-Resource-State');
        $resourceId  = $request->header('X-Goog-Resource-Id');
        $messageNum  = $request->header('X-Goog-Message-Number');

        if (!$channelId) {
            return response('', 400);
        }

        // Verify channel exists and token matches
        $channel = DriveChangeChannel::where('channel_id', $channelId)
            ->where('is_active', true)
            ->first();

        if (!$channel) {
            Log::warning("Unknown Drive webhook channel: {$channelId}");
            return response('', 404);
        }

        if ($channelToken && $channel->channel_token && !hash_equals($channel->channel_token, $channelToken)) {
            Log::warning("Drive webhook token mismatch for channel {$channelId}");
            return response('', 403);
        }

        // Skip sync notifications (initial ping from Google)
        if ($state === 'sync') {
            return response('', 200);
        }

        // Dispatch to queue immediately
        ProcessDriveWebhookJob::dispatch(
            $channel->storage_connection_id,
            $channelId,
            $state ?? 'change',
            $resourceId,
            (int) $messageNum,
        )->onQueue('high');

        return response('', 200);
    }
}
