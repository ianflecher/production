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
                    ->withExists([
                        'payments' => fn ($p) => $p->whereNotNull('confirmed_at'),
                    ]),
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
            'brief' => $inquiry?->brief_token
                ? route('client.inquiry-design-brief', $inquiry->brief_token)
                : null,
            'agent' => self::agent($design),
            'artist' => $design->artist?->name,
            'received' => $design->sent_at,
            'finished' => $design->submitted_at,
            'status' => self::status($design, $order),
            'notes' => self::notes($design, $order),
            'order' => $order,
            'revisions' => (int) $design->revision_count,
        ];
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
                ! $order->hasDownpayment() => 'Waiting DP',
                default => 'Work in progress',
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
