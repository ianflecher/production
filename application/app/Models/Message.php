<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A direct message between two staff accounts. A message can carry a job order,
 * which renders as a card in the thread — but the card only opens for someone
 * who is actually on that order (see canSeeOrder()).
 */
class Message extends Model
{
    protected $fillable = ['sender_id', 'recipient_id', 'body', 'production_order_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** Every message exchanged between two people, oldest first. */
    public function scopeConversation(Builder $q, int $a, int $b): Builder
    {
        return $q->where(function ($w) use ($a, $b) {
            $w->where(fn ($x) => $x->where('sender_id', $a)->where('recipient_id', $b))
                ->orWhere(fn ($x) => $x->where('sender_id', $b)->where('recipient_id', $a));
        });
    }

    /** Messages involving this person, either direction. */
    public function scopeInvolving(Builder $q, int $userId): Builder
    {
        return $q->where(fn ($w) => $w->where('sender_id', $userId)->orWhere('recipient_id', $userId));
    }

    /**
     * Whether $user may open the attached job order. Same rule the job-order
     * files use: the officer who owns it, leaders/admin, or anyone assigned to
     * a task on it. Someone can be SENT an order they cannot open — they see
     * the number but not the details.
     */
    public function canSeeOrder(User $user): bool
    {
        if (! $this->production_order_id) {
            return false;
        }

        $order = $this->order;
        if (! $order) {
            return false;
        }

        return $user->isLeader()
            || ($user->isSales() && $order->created_by === $user->id)
            || $order->tasks()->where('assigned_to', $user->id)->exists();
    }

    /** How many unread messages this person has waiting. */
    public static function unreadFor(int $userId): int
    {
        return self::where('recipient_id', $userId)->whereNull('read_at')->count();
    }
}
