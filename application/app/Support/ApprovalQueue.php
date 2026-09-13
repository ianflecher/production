<?php

namespace App\Support;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What is actually waiting for this person's approval.
 *
 * Three places say this number — the sidebar badge, the dashboard card, and
 * the Approvals page itself — and they have to agree, because the first two
 * are links to the third. A badge saying 1 over a page saying "nothing to
 * check" is not a small inconsistency: it is read as the page being broken,
 * and then the badge stops being read at all.
 *
 * They did not agree. The page asks for approver_role = leader; the dashboard
 * counted every task sitting at "for checking" whoever it was waiting on, so a
 * tech pack still with the account officer was counted as the leader's and
 * opened an empty page. The sidebar had already been fixed once for the same
 * reason. This is that rule, written once.
 */
class ApprovalQueue
{
    /**
     * Every row the Approvals page would show this person.
     *
     * One query, then split up here. Asking the database twice — once for the
     * packs and once for everything else — is two round trips for one
     * question, and this is read on every page load through the sidebar.
     *
     * A pack is one row, not two: the mockup and the template of a single
     * order are one approval, and older data can carry a renamed legacy
     * template row for the same deliverable.
     */
    public static function rowsFor(User $user): Collection
    {
        $mockup = ProductionOrder::STAGE_MOCKUP;

        $tasks = Task::query()
            ->where('status', 'for_checking')
            ->where('approver_role', 'leader')
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->get();

        $packages = $tasks
            ->filter(fn (Task $t) => $t->stage === $mockup
                && (str_starts_with((string) $t->department, 'Tech pack')
                    || str_starts_with((string) $t->department, 'Production template')))
            ->groupBy('production_order_id')
            // He draws at the bench himself, and nobody checks their own work.
            // The page drops these, so counting them put a number on a link to
            // a page that would not show them.
            ->reject(fn ($group) => $user->isArtistLead()
                && $group->contains('assigned_to', $user->id))
            ->values();

        // The artist leader checks the artists' work and nothing else; the
        // rest of the floor still answers to the leader.
        if ($user->isArtistLead()) {
            return $packages;
        }

        $singles = $tasks
            ->filter(fn (Task $t) => $t->stage !== $mockup)
            ->values();

        return $packages->concat($singles);
    }

    /** How many rows the Approvals page would show this person. */
    public static function countFor(User $user): int
    {
        return self::rowsFor($user)->count();
    }

    /**
     * The individual rows behind that number, for a page that lists a few of
     * them. Ordered oldest first: the one that has been waiting longest is
     * the one somebody should look at.
     */
    public static function tasksFor(User $user): Builder
    {
        $query = Task::query()
            ->where('status', 'for_checking')
            ->where('approver_role', 'leader')
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->orderBy('submitted_at');

        if ($user->isArtistLead()) {
            $query->where('stage', ProductionOrder::STAGE_MOCKUP)
                ->where(fn ($q) => $q
                    ->whereNull('assigned_to')
                    ->orWhere('assigned_to', '!=', $user->id));
        }

        return $query;
    }
}
