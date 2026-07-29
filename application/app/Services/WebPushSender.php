<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends an AppNotification to a user's subscribed browsers via Web Push, so it
 * arrives even when the tab (or browser) is closed.
 *
 * No-repeat is guaranteed two ways:
 *   1. Each notification is pushed AT MOST ONCE — on success it's stamped
 *      delivered_at, so neither push nor the in-page poll fires it again.
 *   2. The service worker shows it with a stable tag (the notification id), so
 *      even a rare double never stacks into two pop-ups.
 */
class WebPushSender
{
    /** Configured and ready to send? */
    public static function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    /**
     * Push one notification to every browser the target has subscribed. Returns
     * true if it was accepted by at least one browser (so the caller can mark it
     * delivered and stop the poll from repeating it).
     */
    public static function deliver(AppNotification $n): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $userIds = self::targetUserIds($n);
        if (empty($userIds)) {
            return false;
        }

        $subs = PushSubscription::whereIn('user_id', $userIds)->get();
        if ($subs->isEmpty()) {
            return false;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ]]);
        } catch (\Throwable $e) {
            Log::warning('WebPush init failed: '.$e->getMessage());

            return false;
        }

        // One id per notification => the browser replaces rather than stacks.
        $payload = json_encode([
            'id' => $n->id,
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->url,
        ]);

        $byEndpoint = [];
        foreach ($subs as $sub) {
            $byEndpoint[$sub->endpoint] = $sub;
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => ['p256dh' => $sub->public_key, 'auth' => $sub->auth_token],
                ]),
                $payload,
            );
        }

        $accepted = false;

        try {
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $accepted = true;
                } elseif ($report->isSubscriptionExpired()) {
                    // Prune dead subscriptions so we don't keep pushing to them.
                    $ep = $report->getEndpoint();
                    optional($byEndpoint[$ep] ?? null)->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WebPush flush failed: '.$e->getMessage());

            return false;
        }

        return $accepted;
    }

    /** Which users a notification is for (a specific user, or a whole role). */
    private static function targetUserIds(AppNotification $n): array
    {
        if ($n->user_id) {
            return [$n->user_id];
        }

        if ($n->role) {
            return User::where('job_role', $n->role)->where('is_active', true)->pluck('id')->all();
        }

        return [];
    }
}
