<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A desktop alert for someone to act on — an artist getting a task, a material
 * request coming in, a submission waiting for approval. Delivered to the browser
 * on its next poll, shown as an OS notification, then marked delivered.
 */
class AppNotification extends Model
{
    protected $fillable = ['user_id', 'role', 'title', 'body', 'url', 'delivered_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    /** Queue an alert for one user. */
    public static function toUser(?int $userId, string $title, ?string $body = null, ?string $url = null): void
    {
        if ($userId) {
            self::deliverPush(self::create(['user_id' => $userId, 'title' => $title, 'body' => $body, 'url' => $url]));
        }
    }

    /** Queue an alert for everyone in a job role (first to see it can act). */
    public static function toRole(string $role, string $title, ?string $body = null, ?string $url = null): void
    {
        self::deliverPush(self::create(['role' => $role, 'title' => $title, 'body' => $body, 'url' => $url]));
    }

    /**
     * Try to deliver by Web Push right away. If a browser accepts it, stamp it
     * delivered so the in-page poll never shows the same alert again — that's
     * the no-repeat guarantee. If nothing accepts (nobody subscribed / offline),
     * it stays pending and the poll picks it up when a tab is open.
     */
    protected static function deliverPush(self $n): void
    {
        try {
            if (\App\Services\WebPushSender::deliver($n)) {
                $n->forceFill(['delivered_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            // Never let a notification break the action that triggered it.
            \Illuminate\Support\Facades\Log::warning('push failed: '.$e->getMessage());
        }
    }

    /**
     * Undelivered alerts for a user — their own, plus their role's — that were
     * raised in the last few minutes (so a login doesn't replay stale events).
     */
    public static function pendingFor(User $user)
    {
        return self::query()
            ->whereNull('delivered_at')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('role', $user->job_role);
            })
            ->orderBy('id')
            ->get();
    }
}
