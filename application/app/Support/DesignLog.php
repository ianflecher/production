<?php

namespace App\Support;

use App\Models\InquiryDesign;
use App\Models\ProductionOrder;
use Illuminate\Support\Collection;

/**
 * The designing board, the way the artists' sheet keeps it.
 *
 * The team ran this as a spreadsheet: one row per design, grouped by the day
 * it came in, with the client, the agent who took it, the artist drawing it,
 * the day it reached them, the day they finished, and where it has got to.
 * Every one of those is something the system already knows — the sheet was a
 * second copy of it, kept by hand, and a second copy is wrong the moment
 * somebody forgets to update it.
 *
 * So nothing here is typed in. The rows appear when a brief goes to an artist
 * and keep themselves current. If a status reads wrong, the job's own state
 * says so, which is the thing worth finding out.
 */
class DesignLog
{
    /** Where the mass production half of a pipeline starts. */
    private const MASSPROD_STAGE = 10;

    /**
     * How many days a design may sit in one place before the board says so.
     *
     * A layout goes out and the client answers the same day or the next one;
     * an artist who has had a brief for a working week is stuck on it. Three
     * is where the shop starts chasing, and it is a number rather than a rule
     * — change it here and the whole board moves with it.
     */
    public const SITTING_TOO_LONG = 3;

    /**
     * One row per design, newest day first.
     *
     * Everything the board shows is loaded up front: a board is the exact
     * shape of page that turns into a query per row if the relations are
     * read as they are drawn.
     */
    public static function rows(int $days = 45): Collection
    {
        return InquiryDesign::query()
            ->with([
                'inquiry.client',
                'inquiry.officer',
                'artist',
                // Both answers are folded into the orders query rather than
                // asked per order. Whether the batch has started is what
                // separates a sample from mass production; whether the money
                // landed is what separates "waiting DP" from work in progress,
                // and hasDownpayment() reads payments_exists when it is there
                // instead of going back to the database for every row.
                'order' => fn ($q) => $q
                    ->withCount([
                        'tasks as massprod_started' => fn ($t) => $t
                            ->where('stage', '>=', self::MASSPROD_STAGE)
                            ->whereNotIn('status', ['todo', 'cancelled']),
                    ])
                    // Two questions about the money, not one. A job with no
                    // payment at all is waiting on the client; a job with a
                    // payment nobody has confirmed is waiting on finance, and
                    // those are two different people to go and ask.
                    // When the officer last recorded money on it. Finance's
                    // wait starts there, not when the job was written.
                    ->withMax('payments as payment_recorded_at', 'created_at')
                    ->withExists([
                        // This one keeps its default name on purpose:
                        // hasDownpayment() looks for payments_exists and
                        // answers from it instead of going back to the
                        // database for every row.
                        'payments' => fn ($p) => $p->whereNotNull('confirmed_at'),
                        'payments as has_any_payment' => fn ($p) => $p,
                    ])
                    // The step the job is actually standing on. Loaded with
                    // the orders rather than asked per row, and narrowed to
                    // the open ones so it is a short list however long the
                    // pipeline is. Nothing else may read tasks off these
                    // orders: what is loaded here is a filtered slice, not
                    // the pipeline - see step().
                    ->with(['tasks' => fn ($t) => $t
                        ->whereIn('status', ['ready', 'in_progress'])
                        ->orderBy('stage')->orderBy('id')]),
            ])
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (InquiryDesign $design) => self::row($design));
    }

    /** The same rows, under the day they came in — how the sheet reads. */
    public static function byDay(int $days = 45): Collection
    {
        return self::rows($days)->groupBy(fn (array $row) => $row['date']->toDateString());
    }

    private static function row(InquiryDesign $design): array
    {
        $inquiry = $design->inquiry;
        $order = $design->order;

        return [
            'design' => $design,
            'date' => $design->created_at ?? $inquiry?->created_at,
            'client' => $inquiry?->client?->fullName() ?? '—',
            'description' => self::description($design),
            // The shop's own copy of the brief, not the client's form. Whoever
            // is reading this board is reading it to find out what was asked
            // for; the tokenised link is the thing you SEND a client, and
            // opening it from here answered the questionnaire as them.
            'brief' => $inquiry ? route('inquiries.design-brief', $inquiry) : null,
            'agent' => self::agent($design),
            'artist' => $design->artist?->name,
            'received' => $design->sent_at,
            'finished' => $design->submitted_at,
            'status' => $status = self::status($design, $order),
            'notes' => $notes = self::notes($design, $order),
            'order' => $order,
            'revisions' => (int) $design->revision_count,
            'since' => $since = self::since($design, $order, $notes),
            'waiting' => $since ? (int) $since->diffInDays(now()) : null,
        ];
    }

    /**
     * When it last moved — the clock behind the "waiting" column.
     *
     * The sheet carried the day the artist got it and the day they finished,
     * and neither answers the question anybody actually asks it: how long has
     * this one been sitting? A design handed back on Friday and a design
     * handed back three weeks ago read identically as "Waiting For Approval",
     * and only one of them is a problem.
     *
     * So the clock starts when the row entered the state it is in now, and it
     * runs only where work STOPS. A job in the middle of its pipeline has
     * dates of its own and its own board to be late on; this one is for the
     * four places a design quietly stalls — nobody sent it, nobody drew it,
     * nobody answered it, nobody paid for it.
     */
    private static function since(InquiryDesign $design, ?ProductionOrder $order, string $notes): ?\Illuminate\Support\Carbon
    {
        if ($order) {
            return match ($notes) {
                // Finance has had it since the officer wrote the payment down.
                'Waiting for finance' => $order->payment_recorded_at
                    ? \Illuminate\Support\Carbon::parse($order->payment_recorded_at)
                    : $order->created_at,
                // Nothing recorded at all: the job has been unable to start
                // since the day it was written.
                'Waiting DP' => $order->created_at,
                default => null,
            };
        }

        return match ($design->status) {
            // Said yes, and nobody has written the job up.
            InquiryDesign::STATUS_APPROVED => $design->approved_at ?? $design->submitted_at,
            // Handed back, and the client has not answered.
            InquiryDesign::STATUS_SUBMITTED => $design->submitted_at,
            // On somebody's desk - or not even sent to one yet.
            InquiryDesign::STATUS_WITH_ARTIST => $design->sent_at ?? $design->created_at,
            // Never sent to anybody. The first design on a brief IS the brief -
            // you raise an enquiry by describing one thing you want - so the
            // wait started when the enquiry did, not when its row was written.
            // Two of these read five days old on a board where one had been
            // sitting since August: their rows were backfilled when a brief
            // stopped being one design and started being several, and the row
            // is younger than the work. A SECOND design added to an old brief
            // is different - nobody has failed at anything yet - so that one
            // still starts from its own creation.
            default => ((int) $design->position === 0 && $design->inquiry?->created_at)
                ? $design->inquiry->created_at
                : $design->created_at,
        };
    }

    /**
     * A fresh drawing, or one asked for against a job already written.
     *
     * The inquiry carries the job it became. A design whose inquiry already
     * points at some OTHER order was asked for after that order existed —
     * which is what "for mock up" means on the sheet.
     */
    private static function description(InquiryDesign $design): string
    {
        $existing = $design->inquiry?->production_order_id;

        return ($existing && $existing !== $design->order?->id)
            ? 'For Mock Up'
            : 'New Design';
    }

    /** The sheet writes these as VIP / Pau and META / Kyson. */
    private static function agent(InquiryDesign $design): string
    {
        $team = strtoupper(trim((string) $design->inquiry?->team));
        $officer = $design->inquiry?->officer?->name;

        return collect([$team ?: null, $officer])->filter()->implode(' / ') ?: '—';
    }

    /**
     * Where it has got to, in the sheet's own words.
     *
     * The first half is the drawing and the second half is the job it became,
     * which is why the sheet's status column mixes "waiting for approval" with
     * "massprod": they are the same row at different times of its life.
     */
    private static function status(InquiryDesign $design, ?ProductionOrder $order): string
    {
        if ($order) {
            if ($order->status === 'cancelled') {
                return 'Cancelled';
            }

            if ($order->status === 'complete') {
                return 'Delivered';
            }

            return ((int) ($order->massprod_started ?? 0) > 0 || $order->skip_sample)
                ? 'Massprod'
                : 'Sample';
        }

        return match ($design->status) {
            InquiryDesign::STATUS_APPROVED => 'Approved',
            InquiryDesign::STATUS_SUBMITTED => 'Waiting For Approval',
            InquiryDesign::STATUS_WITH_ARTIST => $design->sent_at ? 'Designing' : 'Not yet sent',
            default => 'Brief',
        };
    }

    /**
     * The step a job is standing on, in the pipeline's own words.
     *
     * The earliest open one: a job with the printer and the cutter both ready
     * is doing the earlier of the two, and that is the one somebody would
     * name if you asked. Only "ready" and "in_progress" count - a "todo" step
     * is locked behind a gate it has not passed, so naming it would say the
     * job is somewhere it has not reached.
     *
     * Nothing open at all means every step so far is finished and the next is
     * waiting on a gate, which the caller words for itself.
     */
    private static function step(ProductionOrder $order): ?string
    {
        if (! $order->relationLoaded('tasks')) {
            return null;
        }

        return $order->tasks
            ->sortBy([['stage', 'asc'], ['id', 'asc']])
            ->first()?->department;
    }

    /**
     * The note the sheet keeps beside the status — and it is the useful half.
     *
     * "Waiting for orderlist" and "Waiting DP" are the two places a finished
     * design sits and stops, and neither is anybody's fault until somebody
     * can see how long it has been there.
     */
    private static function notes(InquiryDesign $design, ?ProductionOrder $order): string
    {
        if ($order) {
            return match (true) {
                $order->status === 'cancelled' => 'Cancelled',
                $order->status === 'complete' => 'Delivered',
                $order->status === 'on_hold' => 'On hold',
                // The money is in but nobody has agreed it landed. The job
                // does not start on this, and the person to chase is finance
                // rather than the client - which "Waiting DP" did not say.
                ! $order->hasDownpayment() && $order->has_any_payment => 'Waiting for finance',
                ! $order->hasDownpayment() => 'Waiting DP',
                // Otherwise it is moving, and the useful thing is WHERE.
                // "Work in progress" was true of every job on the board and
                // told nobody anything; "Final mockup" or "Sewing" is a place
                // a person can go and look.
                default => self::step($order) ?? 'Work in progress',
            };
        }

        return match ($design->status) {
            // Approved and nothing written yet: this is the gap the sheet
            // calls "waiting for orderlist".
            InquiryDesign::STATUS_APPROVED => 'Waiting For Orderlist',
            InquiryDesign::STATUS_SUBMITTED => 'Waiting For Approval',
            InquiryDesign::STATUS_WITH_ARTIST => $design->revision_count > 0
                ? 'On revision '.$design->revision_count
                : 'Work in progress',
            default => 'Writing the brief',
        };
    }
}
