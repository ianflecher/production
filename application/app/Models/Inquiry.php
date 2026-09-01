<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    /* Where the layout has got to. The job order does not open until the last
       of these, because an order written before the client likes the design is
       a number on the books for something nobody has agreed to yet. */
    public const LAYOUT_BRIEF = 'brief';         // still being written up
    public const LAYOUT_WITH_ARTIST = 'with_artist'; // an artist is drawing it
    public const LAYOUT_SUBMITTED = 'submitted';     // drawn, waiting on the client
    public const LAYOUT_APPROVED = 'approved';       // client said yes

    /* How many times a layout may be sent back before the officer has to stop.
       The client is told three; a leader can still allow a fourth, because the
       call to give one away for free is theirs to make, not the form's. */
    public const LAYOUT_REVISION_LIMIT = 3;

    protected $fillable = [
        'client_id', 'created_by', 'team', 'status', 'production_order_id',
        'what_they_want', 'next_follow_up_on', 'closed_at', 'closed_reason',
        'layout_reference_note', 'layout_files', 'layout_brief_completed_at', 'design_brief',
        'layout_status', 'layout_artist_id', 'layout_sent_at', 'layout_submitted_at',
        'layout_approved_at', 'layout_revision_note', 'layout_revision_count',
        'brief_token', 'brief_expires_at', 'client_brief_submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'next_follow_up_on' => 'date',
            'closed_at' => 'datetime',
            'layout_files' => 'array',
            'design_brief' => 'array',
            'layout_brief_completed_at' => 'datetime',
            'layout_sent_at' => 'datetime',
            'layout_submitted_at' => 'datetime',
            'layout_approved_at' => 'datetime',
            'brief_expires_at' => 'datetime',
            'client_brief_submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $inquiry) {
            $inquiry->brief_token ??= Str::random(32);
            $inquiry->brief_expires_at ??= now()->addDays(30);
        });
    }

    /** True once the three revisions an officer may ask for are used up. */
    public function revisionsUsedUp(): bool
    {
        return (int) $this->layout_revision_count >= self::LAYOUT_REVISION_LIMIT;
    }

    /** How many rounds are left to an officer — never below zero. */
    public function revisionsLeft(): int
    {
        return max(0, self::LAYOUT_REVISION_LIMIT - (int) $this->layout_revision_count);
    }

    public function briefExpired(): bool
    {
        return $this->brief_expires_at !== null && $this->brief_expires_at->isPast();
    }

    public function regenerateBriefLink(): void
    {
        $this->update(['brief_token' => Str::random(32), 'brief_expires_at' => now()->addDays(30)]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * What was said about this layout while it was being drawn. They keep this
     * inquiry after the job order is written, so the early part of a thread
     * can still be told apart from the rest.
     */
    public function messages()
    {
        return $this->hasMany(Message::class, 'inquiry_id');
    }

    public function layoutArtist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'layout_artist_id');
    }

    public function layoutStatus(): string
    {
        return $this->layout_status ?: self::LAYOUT_BRIEF;
    }

    public function layoutWithArtist(): bool
    {
        return $this->layoutStatus() === self::LAYOUT_WITH_ARTIST;
    }

    public function layoutSubmitted(): bool
    {
        return $this->layoutStatus() === self::LAYOUT_SUBMITTED;
    }

    /** The one thing that opens the job order. */
    public function layoutApproved(): bool
    {
        return $this->layoutStatus() === self::LAYOUT_APPROVED;
    }

    /** The finished drawing, as opposed to the officer's brief material. */
    public function layoutDrawings()
    {
        return collect($this->layout_files ?? [])->where('kind', 'layout');
    }

    /** An artist's queue: what they have been given and not yet drawn. */
    public function scopeDrawnBy($query, User $artist)
    {
        return $query->where('layout_artist_id', $artist->id)
            ->whereIn('layout_status', [self::LAYOUT_WITH_ARTIST, self::LAYOUT_SUBMITTED])
            ->where('status', self::STATUS_OPEN);
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
