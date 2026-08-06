<?php

namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends Controller
{
    private const DAILY_CAPACITY = 500;

    public function index(Request $request): View
    {
        /*
         * Determine which month to display.
         *
         * Example:
         * /calendar?month=2026-07
         */
        try {
            $cursor = $request->filled('month')
                ? Carbon::createFromFormat(
                    'Y-m',
                    $request->query('month')
                )->startOfMonth()
                : Carbon::now()->startOfMonth();
        } catch (\Throwable $exception) {
            $cursor = Carbon::now()->startOfMonth();
        }

        $monthStart = $cursor->copy()->startOfMonth();
        $monthEnd = $cursor->copy()->endOfMonth();

        $user = $request->user();

        /*
         * =========================================================
         * INDIVIDUAL ORDERS VISIBLE ON THE CALENDAR
         * =========================================================
         *
         * Everyone sees every job: the calendar is the company's capacity in
         * one view, and a half-empty one is misleading. Who may OPEN a job is a
         * separate question — see ProductionOrder::openableBy().
         */
        $visibleOrdersQuery = ProductionOrder::query()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ]);

        $ordersByDay = $visibleOrdersQuery
            ->with('tasks')
            ->orderBy('due_date')
            ->orderBy('order_number')
            ->get()
            ->groupBy(
                fn (ProductionOrder $order): string =>
                    $order->due_date->toDateString()
            );

        /*
         * =========================================================
         * COMPANY-WIDE DAILY CAPACITY
         * =========================================================
         *
         * Do not filter this query by created_by or current user.
         *
         * This totals all orders from all account officers and
         * sales agents for the selected month.
         *
         * Cancelled orders are excluded because they no longer use
         * production capacity.
         */
        $allCompanyOrders = ProductionOrder::query()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('status')
                    ->orWhere('status', '!=', 'cancelled');
            })
            ->get();

        /*
         * Example result:
         *
         * [
         *     '2026-07-22' => 350,
         *     '2026-07-23' => 525,
         * ]
         */
        $quantityByDay = $allCompanyOrders
            ->groupBy(
                fn (ProductionOrder $order): string =>
                    $order->due_date->toDateString()
            )
            ->map(
                fn ($orders): int =>
                    (int) $orders->sum('quantity')
            );

        /*
         * Number of all company orders booked on each date.
         */
        $orderCountByDay = $allCompanyOrders
            ->groupBy(
                fn (ProductionOrder $order): string =>
                    $order->due_date->toDateString()
            )
            ->map(
                fn ($orders): int =>
                    $orders->count()
            );

        /*
         * =========================================================
         * CALENDAR GRID
         * =========================================================
         *
         * Weeks begin on Sunday and end on Saturday.
         */
        $gridStart = $monthStart
            ->copy()
            ->startOfWeek(Carbon::SUNDAY);

        $gridEnd = $monthEnd
            ->copy()
            ->endOfWeek(Carbon::SATURDAY);

        $weeks = [];
        $day = $gridStart->copy();

        while ($day->lte($gridEnd)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $week[] = $day->copy();
                $day->addDay();
            }

            $weeks[] = $week;
        }

        /*
         * =========================================================
         * UPCOMING DEADLINES
         * =========================================================
         *
         * Sales agents see only their upcoming orders.
         * Leaders and administrators see all upcoming orders.
         */
        $today = Carbon::today();
        $upcomingEnd = $today->copy()->addDays(30);

        $upcomingQuery = ProductionOrder::query()
            ->whereNotNull('due_date')
            ->whereIn('status', [
                'active',
                'on_hold',
            ])
            ->whereBetween('due_date', [
                $today->toDateString(),
                $upcomingEnd->toDateString(),
            ]);

        // The grid is the whole shop's capacity; the deadline list is a
        // to-do. Nobody needs reminding of a deadline they cannot act on, so
        // this one is narrowed to the person's own jobs (all of them, for a
        // leader or the admin).
        $upcoming = $upcomingQuery
            ->with('tasks')
            ->orderBy('due_date')
            ->orderBy('order_number')
            ->get()
            ->filter(fn (ProductionOrder $order) => $order->openableBy($user))
            ->values();

        return view('calendar', [
            'cursor' => $cursor,
            'weeks' => $weeks,

            /*
             * Individual visible orders.
             */
            'ordersByDay' => $ordersByDay,

            /*
             * Company-wide capacity information.
             */
            'dailyCapacity' => self::DAILY_CAPACITY,
            'quantityByDay' => $quantityByDay,
            'orderCountByDay' => $orderCountByDay,

            'prevMonth' => $cursor
                ->copy()
                ->subMonth()
                ->format('Y-m'),

            'nextMonth' => $cursor
                ->copy()
                ->addMonth()
                ->format('Y-m'),

            'today' => $today,
            'upcoming' => $upcoming,
        ]);
    }
}