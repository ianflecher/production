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

    /* The two things a brief can be to the artist who picks it up: a drawing
       nobody has asked for changes on yet, or one that has come back. They are
       different work — a revision has a note saying what to change and is
       already late in the client's eyes — so the artist leader can ask for one
       kind at a time. */
    public const KIND_NEW = 'new';
    public const KIND_REVISION = 'revision';

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

    /**
     * Has this brief been sent back at all?
     *
     * Asked at both levels on purpose. layout_revision_count is bumped when
     * the officer sends the whole lot back; a single design sent back on its
     * own only moves that design's own count. Reading one of them alone calls
     * half the revisions on the board new work.
     */
    public function isRevision(): bool
    {
        return (int) $this->layout_revision_count > 0
            || $this->designs->contains(fn (InquiryDesign $d) => (int) $d->revision_count > 0);
    }

    /** New drawings, or ones that have come back. @see isRevision() */
    public function scopeOfDesignKind($query, string $kind)
    {
        $sentBack = fn ($q) => $q
            ->where('layout_revision_count', '>', 0)
            ->orWhereHas('designs', fn ($d) => $d->where('revision_count', '>', 0));

        return $kind === self::KIND_REVISION
            ? $query->where($sentBack)
            : $query->whereNot($sentBack);
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

    /**
     * Every order written from this brief.
     *
     * A client who wants five products gets five orders off one enquiry - one
     * per design - so the shop keeps quoting and chasing them as one job.
     */
    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductionOrder::class)->orderBy('id');
    }

    /**
     * Approved designs that nobody has written an order for yet.
     *
     * This is what the follow-up list waits on: a brief is finished with when
     * every design the client said yes to has become a job.
     */
    public function designsAwaitingAnOrder(): \Illuminate\Support\Collection
    {
        $designs = $this->relationLoaded('designs') ? $this->designs : $this->designs()->get();

        $written = $this->orders()->pluck('inquiry_design_id')->filter()->all();

        return $designs
            ->filter(fn ($design) => $design->approved())
            ->reject(fn ($design) => in_array($design->id, $written, true))
            ->values();
    }

    /** Every design under this brief, in the order the officer listed them. */
    public function designs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InquiryDesign::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Where the brief as a whole stands, worked out from its designs.
     *
     * One design used to BE the layout, so its state was the enquiry's. With
     * six of them the enquiry is only as finished as its least finished
     * design: still with the artists until every one is handed back, and
     * approved only when the client has said yes to all of them - which is
     * what opens the job order, and must not open on a partial yes.
     *
     * An enquiry whose brief has not been sent yet has no designs, and answers
     * with the state stored on itself.
     */
    public function layoutStatus(): string
    {
        $designs = $this->relationLoaded('designs') ? $this->designs : $this->designs()->get();

        if ($designs->isEmpty()) {
            return $this->layout_status ?: self::LAYOUT_BRIEF;
        }

        if ($designs->every(fn ($d) => $d->approved())) {
            return self::LAYOUT_APPROVED;
        }

        if ($designs->every(fn ($d) => $d->submitted() || $d->approved())) {
            return self::LAYOUT_SUBMITTED;
        }

        return self::LAYOUT_WITH_ARTIST;
    }

    /**
     * Write the worked-out state back onto the enquiry.
     *
     * The column is still read by the officer's lists and the artist queue's
     * filters, which run in the database and cannot call the method above.
     * Called whenever a design moves.
     */
    public function syncLayoutStatus(): void
    {
        $this->load('designs');

        $status = $this->layoutStatus();

        $this->forceFill([
            'layout_status' => $status,
            'layout_submitted_at' => $status === self::LAYOUT_SUBMITTED
                ? ($this->layout_submitted_at ?: now())
                : $this->layout_submitted_at,
            'layout_approved_at' => $status === self::LAYOUT_APPROVED
                ? ($this->layout_approved_at ?: now())
                : null,
        ])->save();
    }

    /** The designs still to be handed back or still to be answered. */
    public function designsOutstanding(): \Illuminate\Support\Collection
    {
        return $this->designs->reject(fn ($d) => $d->approved());
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

    /**
     * A job order may be prepared once the client has approved any design.
     *
     * A brief with no design list is its own single design, and answers with
     * its own layout status - the same fallback layoutStatus() makes. Without
     * that, a layout approved before the list existed could never become a job
     * order: the guard asked for an approved design row, and there was none to
     * find. Two of the shop's own briefs were in exactly that state.
     */
    public function hasApprovedDesign(): bool
    {
        $designs = $this->relationLoaded('designs') ? $this->designs : $this->designs()->get();

        if ($designs->isEmpty()) {
            return $this->layoutApproved();
        }

        return $designs->contains(fn ($design) => $design->approved());
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

    /**
     * List one or more designs on this brief.
     *
     * Shared by the two buttons that can do it, because they used to be two
     * rules that agreed by luck: "+ Add design" listed them, and "Send to
     * artist" quietly made one of its own with none of the same fields. An
     * officer who typed the notes into the add box and pressed Send got a
     * design with no notes on it and no sign that anything had been dropped.
     *
     * Several at a time because a kit is listed in one go, and a numbered
     * name for each so "Jersey" becomes "Jersey 1" and "Jersey 2" rather than
     * two rows nobody can tell apart.
     */
    public function addDesigns(
        ?string $label,
        ?string $description,
        ?\App\Models\User $artist,
        int $howMany = 1
    ): int {
        $howMany = max(1, min(20, $howMany));

        $next = (int) $this->designs()->max('position');
        $next = $this->designs()->count() ? $next + 1 : 0;

        for ($i = 0; $i < $howMany; $i++) {
            $this->designs()->create([
                'label' => $howMany > 1 && filled($label)
                    ? $label.' '.($i + 1)
                    : ($label ?: null),
                'position' => $next + $i,
                'artist_id' => $artist?->id,
                'status' => InquiryDesign::STATUS_WITH_ARTIST,
                'description' => filled($description) ? trim($description) : null,
                // Already sent? Then this one is on their desk from now.
                'sent_at' => $this->layout_sent_at ? now() : null,
            ]);
        }

        return $howMany;
    }

    /** Still being chased. */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * The follow-up list, in the order it should be worked: whoever has been
     * waiting longest, first. A multi-design inquiry stays visible until each
     * approved design has its own order; the first order must not hide the
     * other approved designs that still need to be written up.
     */
    public function scopeForFollowUp($query)
    {
        return $query
            ->where(function ($visible) {
                $visible->where('status', self::STATUS_OPEN)
                    // A design that has not become a job yet, whatever stage it
                    // is at. This asked for an APPROVED one, which quietly lost
                    // the client who has ordered before and come back: their
                    // inquiry is marked ordered, so the first half does not
                    // catch them, and a design still being drawn did not
                    // satisfy the second. They fell off the list until the
                    // client approved the drawing - and the officer chasing
                    // that order had nowhere to see them in the meantime.
                    //
                    // A design still being written up ("brief") is not out of
                    // anybody's hands yet and is left off.
                    ->orWhereHas('designs', fn ($designs) => $designs
                        ->whereIn('status', [
                            InquiryDesign::STATUS_WITH_ARTIST,
                            InquiryDesign::STATUS_SUBMITTED,
                            InquiryDesign::STATUS_APPROVED,
                        ])
                        ->whereDoesntHave('order'));
            })
            ->orderBy('created_at');
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
