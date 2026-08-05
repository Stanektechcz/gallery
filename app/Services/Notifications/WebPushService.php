<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers a notification to a member's devices while the application is closed.
 *
 * Push is best-effort by design: a failure here must never stop a reminder being
 * recorded, since the in-app notification is the reliable channel and this is the
 * convenience on top. Everything is therefore caught and logged rather than thrown.
 */
class WebPushService
{
    public function configured(): bool
    {
        return filled(config('push.public_key'))
            && filled(config('push.private_key'))
            && filled(config('push.subject'));
    }

    /**
     * @param  array{title:string, body:string, url?:string, tag?:string}  $payload
     * @return int Number of devices the message reached.
     */
    public function sendToUser(User $user, array $payload): int
    {
        if (! $this->configured() || ! Schema::hasTable('push_subscriptions')) return 0;

        $rows = DB::table('push_subscriptions')->where('user_id', $user->id)->get();
        if ($rows->isEmpty()) return 0;

        try {
            $push = new WebPush(['VAPID' => [
                'subject'    => config('push.subject'),
                'publicKey'  => config('push.public_key'),
                'privateKey' => config('push.private_key'),
            ]], ['TTL' => config('push.ttl')]);
        } catch (\Throwable $exception) {
            report($exception);

            return 0;
        }

        $body = json_encode([
            'title' => $payload['title'],
            'body'  => $payload['body'],
            'url'   => $payload['url'] ?? '/',
            // Devices collapse notifications sharing a tag, so a re-sent reminder
            // replaces the earlier one instead of stacking up.
            'tag'   => $payload['tag'] ?? 'maki',
        ], JSON_UNESCAPED_UNICODE);

        foreach ($rows as $row) {
            $keys = json_decode($row->keys ?? '{}', true);
            if (! is_array($keys) || empty($keys['p256dh']) || empty($keys['auth'])) continue;

            try {
                $push->queueNotification(
                    Subscription::create([
                        'endpoint' => $row->endpoint,
                        'keys' => ['p256dh' => $keys['p256dh'], 'auth' => $keys['auth']],
                    ]),
                    $body
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $delivered = 0;
        try {
            foreach ($push->flush() as $report) {
                if ($report->isSuccess()) { $delivered++; continue; }

                // 404 or 410 means the browser dropped the subscription for good;
                // keeping it would mean retrying a dead endpoint forever.
                if ($report->isSubscriptionExpired()) {
                    DB::table('push_subscriptions')->where('endpoint', $report->getEndpoint())->delete();
                    continue;
                }

                Log::info('Push nedoručen', [
                    'endpoint' => mb_substr($report->getEndpoint(), 0, 80),
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $delivered;
    }
}
