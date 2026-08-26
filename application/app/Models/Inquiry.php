<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody asked, and has not ordered yet.
 *
 * The inquiry is created the moment the client's details are taken, before
 * anyone knows whether it will become a job. It stays on the follow-up list
 * until it does — that is the whole point of it.
 */
class Inquiry extends Model
{
    /** @var string */
    protected $table = 'inquiries';

    public const STATUS_OPEN = 'open';
    public const STATUS_ORDERED = 'ordered';

    protected $fillable = [
        'client_id', 'created_by', 'team', 'status', 'production_order_id',
        'what_they_want', 'next_follow_up_on', 'closed_at', 'closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'next_follow_up_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** Newest first: the follow-up history reads like a conversation. */
    public function followUps(): HasMany
    {
        return $this->hasMany(InquiryFollowUp::class)->latest('created_at');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Still being chased. */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * The follow-up list, in the order it should be worked: whoever has been
     * waiting longest, first. There are no scheduled dates — a name is on the
     * list from the day they ask until the day they order, and that is the
     * whole of it.
     */
    public function scopeForFollowUp($query)
    {
        return $query->open()->orderBy('created_at');
    }

    /**
     * Everything this person is allowed to chase.
     *
     * An officer sees their own. A team leader sees their whole team's — that
     * is what leading the team means here. Leaders and the admin see all.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isLeader()) {
            return $query;
        }

        if ($user->leadsTeam()) {
            return $query->where(fn ($q) => $q
                ->where('team', $user->team)
                ->orWhere('created_by', $user->id));
        }

        return $query->where('created_by', $user->id);
    }

    /**
     * They ordered. The inquiry is answered: it keeps the job it became, and
     * comes off the follow-up list — which is the only way a name leaves it.
     */
    public function markOrdered(ProductionOrder $order): void
    {
        $this->update([
            'status' => self::STATUS_ORDERED,
            'production_order_id' => $order->id,
            'closed_at' => now(),
        ]);
    }
}
