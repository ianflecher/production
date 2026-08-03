<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message in a job order's conversation. The thread belongs to the ORDER,
 * and everyone connected to that order shares it — the account officer who owns
 * it, leaders/admin, and anyone assigned to one of its tasks.
 */
class Message extends Model
{
    protected $fillable = ['production_order_id', 'sender_id', 'body'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /**
     * Who may read and post in an order's thread. Same rule the job-order files
     * already use, so the chat never widens access to an order.
     */
    public static function canAccess(User $user, ProductionOrder $order): bool
    {
        return $user->isLeader()
            || ($user->isSales() && $order->created_by === $user->id)
            || $order->tasks()->where('assigned_to', $user->id)->exists();
    }

    /** The orders whose threads this person is part of. */
    public static function accessibleOrderIds(User $user): \Illuminate\Support\Collection
    {
        return ProductionOrder::query()
            ->when(! $user->isLeader(), function ($q) use ($user) {
                $q->where(function ($w) use ($user) {
                    $w->where('created_by', $user->id)
                        ->orWhereHas('tasks', fn ($t) => $t->where('assigned_to', $user->id));
                });
            })
            ->pluck('id');
    }

    /** Unread messages for this person in one order (their own don't count). */
    public static function unreadInOrder(User $user, int $orderId): int
    {
        $lastRead = MessageRead::where('user_id', $user->id)
            ->where('production_order_id', $orderId)
            ->value('last_read_at');

        return self::where('production_order_id', $orderId)
            ->where('sender_id', '!=', $user->id)
            ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
            ->count();
    }

    /** Total unread across every thread this person can see (nav badge). */
    public static function unreadFor(int $userId): int
    {
        $user = User::find($userId);
        if (! $user) {
            return 0;
        }

        $reads = MessageRead::where('user_id', $user->id)
            ->pluck('last_read_at', 'production_order_id');

        return self::whereIn('production_order_id', self::accessibleOrderIds($user))
            ->where('sender_id', '!=', $user->id)
            ->get(['production_order_id', 'created_at'])
            ->filter(function ($m) use ($reads) {
                $seen = $reads[$m->production_order_id] ?? null;

                return $seen === null || $m->created_at > $seen;
            })
            ->count();
    }

    /** Mark an order's thread as read up to now. */
    public static function markRead(User $user, int $orderId): void
    {
        MessageRead::updateOrCreate(
            ['user_id' => $user->id, 'production_order_id' => $orderId],
            ['last_read_at' => now()],
        );
    }
}
