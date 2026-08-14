<?php

namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where the work is getting stuck.
 *
 * The board says what every station is doing right now and the calendar says
 * what is due. Neither answers the question a leader actually walks in with:
 * which part of the shop is holding everything up, and which job has been
 * sitting untouched the longest.
 *
 * Two different questions, so two sections:
 *
 *   - STUCK NOW: open steps ranked by how long they have been waiting. This is
 *     the one to act on — every row is a job somebody could go and unblock.
 *   - SLOWEST ON AVERAGE: finished steps grouped by department. This is the
 *     one to plan with; a step that is always slow needs another machine or
 *     another pair of hands, not a chase.
 *
 * Timing comes from released_at (when the step became available to work) to
 * approved_at (when it was signed off). Not created_at: a step is created the
 * moment the order is taken, so measuring from there would report weeks of
 * waiting for its turn as time spent working.
 */
class BottleneckReportController extends Controller
{
    /** How far back the averages look. Older work was a different shop. */
    private const WINDOW_DAYS = 90;

    /** Waiting longer than this is called out. */
    private const SLOW_DAYS = 3;

    public function index(Request $request): View
    {
        abort_unless($request->user()->isLeader(), 403);

        return view('reports.bottlenecks', [
            'stuck' => $this->stuckNow(),
            'slowest' => $this->slowestSteps(),
            'windowDays' => self::WINDOW_DAYS,
            'slowDays' => self::SLOW_DAYS,
        ]);
    }

    /**
     * Open steps, longest wait first.
     *
     * Only live orders: a step on a cancelled or held job is not stuck, it is
     * parked, and mixing the two buries the rows worth chasing.
     */
    private function stuckNow()
    {
        return Task::with(['order.client', 'assignee'])
            ->whereIn('status', \App\Services\Stations::RELEASED)
            ->whereNotNull('released_at')
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->orderBy('released_at')
            ->limit(25)
            ->get()
            ->map(fn (Task $t) => [
                'task' => $t,
                // Whole units: Carbon hands back fractions, and "waiting 9.0000014
                // days" is not something anybody needs to read.
                'days' => (int) $t->released_at->diffInDays(now()),
                'hours' => (float) $t->released_at->diffInHours(now()),
            ]);
    }

    /**
     * Average time each department takes to get through a step.
     *
     * Worked out in PHP rather than SQL: the date maths differs between MySQL
     * and SQLite, and the tests run on SQLite. Ninety days of finished steps is
     * a few thousand rows at most — cheap next to keeping two versions of a
     * query correct.
     */
    private function slowestSteps()
    {
        $done = Task::query()
            ->whereNotNull('released_at')
            ->whereNotNull('approved_at')
            ->where('approved_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->get(['department', 'released_at', 'approved_at', 'production_order_id']);

        return $done
            ->groupBy('department')
            ->map(function ($rows, $department) {
                // A clock that runs backwards is a data fault, not a fast step.
                $hours = $rows
                    ->map(fn ($t) => $t->released_at->diffInHours($t->approved_at, false))
                    ->filter(fn ($h) => $h >= 0)
                    ->values();

                if ($hours->isEmpty()) {
                    return null;
                }

                $sorted = $hours->sort()->values();

                return [
                    'department' => $department,
                    'count' => $hours->count(),
                    'average' => $hours->avg(),
                    // The average alone hides a step that is usually quick and
                    // occasionally catastrophic; the middle and the worst
                    // between them say which kind of slow this is.
                    'median' => $sorted[intdiv($sorted->count(), 2)],
                    'worst' => $sorted->last(),
                ];
            })
            ->filter()
            ->sortByDesc('average')
            ->values();
    }

    /** Hours as something readable on a wall screen. */
    public static function forHumans(float $hours): string
    {
        if ($hours < 1) {
            return max(1, (int) round($hours * 60)).' min';
        }

        if ($hours < 48) {
            return round($hours, 1).' hr';
        }

        return round($hours / 24, 1).' days';
    }
}
