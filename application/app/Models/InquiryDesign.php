<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * One design under an enquiry's brief.
 *
 * A client who wants six designs is ordinary - a moto team orders a jersey, a
 * jacket, shorts and one each for their riders - and five of them can be
 * Cristal's while the sixth is Mick's. Each is drawn, handed back, and
 * approved or sent back on its own, so "eight approved, two to redo" is
 * something the shop can write down rather than something it argues about.
 *
 * The brief above it belongs to the enquiry: it is the same brief for all of
 * them, and so is the client.
 */
class InquiryDesign extends Model
{
    use HasFactory;

    /**
     * Still being written up, and on nobody's desk.
     *
     * The column defaults to with_artist, so this arrives only from the
     * backfill that split one enquiry's layout into designs - it copied the
     * enquiry's own layout_status, and "brief" is one of those. It was a
     * status the model did not name, which is how a design sitting in it came
     * to be labelled "Port is drawing it" on the officer's screen while Port
     * had nothing at all.
     */
    public const STATUS_BRIEF = 'brief';

    /** The same three states a layout has always had, one design at a time. */
    public const STATUS_WITH_ARTIST = 'with_artist';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    /** What the client is promised, per design - see Inquiry::LAYOUT_REVISION_LIMIT. */
    public const REVISION_LIMIT = Inquiry::LAYOUT_REVISION_LIMIT;

    protected $fillable = [
        'inquiry_id', 'label', 'position', 'artist_id', 'status', 'files', 'description',
        'revision_note', 'revision_count', 'sent_at', 'submitted_at', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'sent_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    /** The job order written for this design, once somebody writes it. */
    public function order(): HasOne
    {
        return $this->hasOne(ProductionOrder::class, 'inquiry_design_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artist_id');
    }

    /**
     * What to call it on a list.
     *
     * A client who wants three of the same shirt has nothing to name them, so
     * an unnamed design is known by where it sits in the set.
     */
    public function name(): string
    {
        return filled($this->label) ? $this->label : 'Design '.($this->position + 1);
    }

    /**
     * Is this still in the brief, on nobody's desk?
     *
     * The design's own status, and NOT the enquiry's layout_sent_at. That
     * looked like the better question - it is the press that locks the brief
     * and notifies the artist - but the shop does not work that way: a design
     * is visible to its artist the moment it is added, and the live data has
     * layouts two revisions deep with two files attached whose brief was
     * never formally sent. Asking layout_sent_at would call those unsent and
     * take live work off an artist's queue.
     *
     * What actually bit was narrower: a design left at STATUS_BRIEF is on
     * nobody's queue, and the officer's screen still named an artist for it.
     */
    public function notSentYet(): bool
    {
        return $this->status === self::STATUS_BRIEF;
    }

    public function withArtist(): bool
    {
        return $this->status === self::STATUS_WITH_ARTIST;
    }

    public function submitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function approved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function revisionsUsedUp(): bool
    {
        return (int) $this->revision_count >= self::REVISION_LIMIT;
    }

    public function revisionsLeft(): int
    {
        return max(0, self::REVISION_LIMIT - (int) $this->revision_count);
    }

    /**
     * The drawing as it stands — not every version it has ever been.
     *
     * Submitting a redraw APPENDS, so the version the client rejected stays in
     * the list at an earlier index. Shown, the brief page offered the officer
     * two pictures of the same shirt with nothing saying which one was agreed,
     * and the rejected one came first. The same list is copied onto the job
     * order as the artist's references, so the floor was being handed it too.
     *
     * Files tagged "revision" never belong here at all: those are the client's
     * markups saying what to change.
     *
     * Keys are the position in the stored array, because that position IS the
     * file's address — see InquiryDesignController::file.
     */
    public function drawings(): Collection
    {
        $files = collect($this->files ?? [])
            // "revision" is the client's markup saying what to change;
            // "superseded" is a drawing somebody has taken off by hand.
            ->reject(fn ($file) => in_array($file['kind'] ?? 'layout', ['revision', 'superseded'], true));

        if ($files->isEmpty()) {
            return $files;
        }

        // Anything uploaded since rounds were recorded says which one it is.
        $rounds = $files->pluck('round')->filter()->map(fn ($r) => (int) $r);

        if ($rounds->isNotEmpty()) {
            $latest = $rounds->max();

            return $files->filter(fn ($file) => (int) ($file['round'] ?? 1) === $latest);
        }

        // Older files carry no round, and nothing else in them says which
        // version they are. Two tries at guessing from the count both got it
        // wrong on the shop's real designs: one hid a panel of a three-piece
        // windbreaker set, the next hid the hoodie from a design that is a
        // windbreaker AND a hoodie. A design of two files with one revision
        // looks identical whether it is a redraw or a pair of garments - only
        // the names tell them apart, and names are not a rule.
        //
        // So they are all shown. A drawing that really has been superseded is
        // taken off by hand, which is a person deciding rather than arithmetic
        // guessing - see InquiryDesignController::removeDrawing.
        return $files;
    }

    /** An artist's queue: the designs on their desk, not yet handed back. */
    public function scopeDrawnBy($query, User $artist)
    {
        return $query->where('artist_id', $artist->id)
            ->whereIn('status', [self::STATUS_WITH_ARTIST, self::STATUS_SUBMITTED]);
    }
}
