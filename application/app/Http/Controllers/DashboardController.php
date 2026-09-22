<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\ProductItem;
use App\Models\ProductReceipt;
use App\Models\StationSession;
use App\Models\Task;
use App\Models\User;
use App\Services\Stations;
use App\Support\ApprovalQueue;
use App\Support\DesignLog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * How many waiting material requests the dashboard lists.
     *
     * Enough to see what the morning looks like without turning the front page
     * into the queue itself, which has its own page, its own search and its own
     * paging.
     */
    private const MATERIAL_QUEUE_SHOWN = 8;

    /**
     * Orders grouped by the step they are actually sitting on.
     *
     * The donut used to have one wedge called "In production" holding every
     * order past the design stage — which on a shop where everything is past
     * the design stage is one colour filling the chart and saying nothing. The
     * useful question is WHERE the work is: eleven at Sewing and two at the
     * printer is a different morning from the reverse.
     *
     * Finished and parked orders keep their own wedges, because "how much is
     * done" is the other thing the chart is asked.
     *
     * @param  Collection<int, ProductionOrder>  $orders
     * @return array{slices: array<int, array{label: string, value: int, color: string}>, total: int}
     */
    private function stepBreakdown($orders): array
    {
        $label = function (ProductionOrder $o): string {
            if ($o->status === 'complete') {
                return 'Completed';
            }
            if ($o->status === 'cancelled') {
                return 'Cancelled';
            }
            if ($o->status === 'on_hold') {
                return 'On hold';
            }

            $step = $o->tasks
                ->sortBy('sequence')
                ->first(fn ($t) => ! in_array($t->status, ['complete', 'cancelled'], true));

            return $step->department ?? 'Not started';
        };

        $counts = $orders->groupBy($label)->map->count();

        // Finished, parked and not-yet-started are not steps on the floor, so
        // they sit at the end in fixed colours rather than competing with the
        // departments for the top of the list.
        $fixed = [
            'Completed' => '#18A957',
            'On hold' => '#E59A18',
            'Cancelled' => '#94A0AE',
            'Not started' => '#CBD5E1',
        ];

        $stepColours = ['#2D7FF0', '#E31B23', '#7C3AED', '#0891B2', '#DB2777', '#65A30D', '#EA580C', '#4F46E5'];

        $steps = $counts->reject(fn ($n, $name) => isset($fixed[$name]))
            ->sortDesc();

        $slices = [];

        foreach ($steps->take(count($stepColours)) as $name => $n) {
            $slices[] = ['label' => $name, 'value' => $n, 'color' => $stepColours[count($slices)]];
        }

        // Whatever did not fit, added up rather than dropped — a chart that
        // quietly leaves orders out is worse than one with a grey wedge.
        $rest = (int) $steps->slice(count($stepColours))->sum();

        if ($rest > 0) {
            $slices[] = ['label' => 'Other steps', 'value' => $rest, 'color' => '#94A3B8'];
        }

        foreach ($fixed as $name => $colour) {
            if (($n = (int) $counts->get($name, 0)) > 0) {
                $slices[] = ['label' => $name, 'value' => $n, 'color' => $colour];
            }
        }

        return ['slices' => $slices, 'total' => $orders->count()];
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        // The HR desk's hiring, on the dashboard rather than a page of its own.
        // Shared rather than passed: index() returns from several branches -
        // leader, finance, the desks - and adding it to each compact() is how
        // one of them quietly ends up without it.

        // The designing board's top of page. Shared for the same reason as the
        // block above: index() returns from a different branch for each trade,
        // and adding it to each compact() is how one of them quietly misses it.
        //
        // NOT the floor. "Everybody who used to read the spreadsheet" was the
        // design side and the leaders chasing them; the roller press operator
        // opened his dashboard to eight rows of other people's drawings above
        // the machines he actually runs. His page is the station board.
        //
        // canSeeDesignBoard() is the same question the sidebar link and the
        // board itself ask, so all three agree about whose board this is.
        //
        // A week, and the newest handful of it. The whole board is a click
        // away; what belongs on a dashboard is what moved today.
        view()->share('designBoard', $user->canSeeDesignBoard()
            ? DesignLog::rows(7)->take(8)
            : null);

        $hour = (int) now()->format('G');
        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        $activeTasks = fn () => Task::whereHas('order', fn ($q) => $q->where('status', 'active'));

        if ($user->isLeader()) {
            // The one question the tile, the alert card and the sidebar badge
            // all ask. Asked once here and handed on — it is a query to find
            // out, and the page was asking it twice.
            $approvalCount = ApprovalQueue::countFor($user);

            // "Active agents" = production staff actually present/working today
            // (matches how work is assigned), not just every enabled account.
            $presentAgents = User::agents()
                ->where('is_active', true)
                ->with('attendances')
                ->get()
                ->filter->isPresentToday()
                ->count();

            $stats = [
                ['label' => 'Active orders', 'value' => ProductionOrder::where('status', 'active')->count(), 'note' => 'In production now'],
                // What is waiting on THIS person, not every task sitting at
                // "for checking" — a tech pack still with the account officer
                // is not the leader's to approve, and counting it here said
                // she had work that her own Approvals page would not show.
                ['label' => 'Tasks for checking', 'value' => $approvalCount, 'note' => 'Waiting for your approval'],
                ['label' => 'Revisions requested', 'value' => $activeTasks()->where('status', 'revision_required')->count(), 'note' => 'Back with the agents'],
                ['label' => 'Active agents', 'value' => $presentAgents, 'note' => 'Present and working today'],
            ];

            // Only tasks actually sitting at a department right now — not locked
            // (todo) future steps or finished (complete) ones.
            $pipelineCounts = $activeTasks()
                ->whereIn('status', ['ready', 'in_progress', 'for_checking', 'revision_required'])
                ->get()
                ->countBy('stage');

            // Five for the list underneath; the count above is the real
            // total, which is why it is worked out separately. Deriving the
            // number from this list capped it at five however much was
            // actually waiting.
            $forChecking = ApprovalQueue::tasksFor($user)
                ->with(['order', 'assignee'])
                ->limit(5)
                ->get();

            // Payments come too: the row says whether an order still needs a
            // downpayment or is only waiting on Finance to confirm one, and
            // both questions are answered off the loaded payments rather than
            // a query per row.
            $recentOrders = ProductionOrder::with(['tasks', 'payments'])
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            // Drawn from every order, not the five most recent that happen to
            // be listed below it — a distribution of five is not a distribution.
            $byStep = $this->stepBreakdown(
                ProductionOrder::with('tasks')->whereIn('status', ['active', 'on_hold', 'complete'])->get()
            );

            return view('dashboard', compact(
                'user', 'greeting', 'stats', 'pipelineCounts', 'forChecking', 'approvalCount', 'recentOrders'
            ) + ['stepSlices' => $byStep['slices'], 'stepTotal' => $byStep['total']]);
        }

        if ($user->isSales()) {
            $myOrders = ProductionOrder::with(['tasks', 'payments', 'client'])
                ->where('created_by', $user->id)
                ->orderByDesc('id')
                ->get();

            // Stages 1-3 are the artist design steps (layout, mockup, template);
            // stage 4+ is production.
            $currentStage = fn ($o) => optional($o->tasks->first(fn ($t) => ! in_array($t->status, ['complete', 'cancelled'])))->stage;

            $recentOrders = $myOrders->take(6);

            // Kept so the existing summary tiles keep working during the redesign.
            $stats = [
                ['label' => 'My active orders', 'value' => $myOrders->where('status', 'active')->count(), 'note' => 'In production now'],
                ['label' => 'In design', 'value' => $myOrders->where('status', 'active')->filter(fn ($o) => ($currentStage($o) ?? 99) <= ProductionOrder::STAGE_MOCKUP)->count(), 'note' => 'Layout / mockup / template'],
                ['label' => 'In production', 'value' => $myOrders->where('status', 'active')->filter(fn ($o) => ($currentStage($o) ?? 0) >= ProductionOrder::STAGE_MOCKUP + 1)->count(), 'note' => 'Past the design stage'],
                ['label' => 'Completed', 'value' => $myOrders->where('status', 'complete')->count(), 'note' => 'Finished orders'],
            ];

            // ---- Orders by the step they are on ----
            $byStep = $this->stepBreakdown($myOrders);
            $statusBreakdown = $byStep['slices'];
            $statusTotal = $byStep['total'];
            $stepSlices = $byStep['slices'];
            $stepTotal = $byStep['total'];

            // ---- Alerts (only real, actionable ones) ----
            $needsDp = $myOrders->filter(fn ($o) => in_array($o->status, ['active', 'on_hold']) && $o->layoutApproved() && ! $o->hasDownpayment());
            $nearDue = $myOrders->filter(fn ($o) => $o->due_date && ! in_array($o->status, ['complete', 'cancelled'])
                && $o->due_date->betweenIncluded(now()->startOfDay(), now()->copy()->addDays(3)->endOfDay()));
            $awaitApproval = $myOrders->filter(fn ($o) => in_array($o->status, ['active', 'on_hold']) && $o->layoutReleased() && ! $o->layoutApproved());

            $alerts = [];
            if ($needsDp->isNotEmpty()) {
                $alerts[] = ['tone' => 'error', 'title' => $needsDp->count().' '.Str::plural('order', $needsDp->count()).' need downpayment',
                    'sub' => $needsDp->first()->order_number, 'url' => route('orders.show', $needsDp->first())];
            }
            if ($nearDue->isNotEmpty()) {
                $alerts[] = ['tone' => 'warning', 'title' => $nearDue->count().' '.Str::plural('order', $nearDue->count()).' near due date',
                    'sub' => 'Due within 3 days', 'url' => route('orders.index')];
            }
            if ($awaitApproval->isNotEmpty()) {
                $alerts[] = ['tone' => 'info', 'title' => $awaitApproval->count().' '.Str::plural('order', $awaitApproval->count()).' await client approval',
                    'sub' => 'Layout with the client', 'url' => route('orders.index')];
            }

            // ---- Quick summary ----
            $quickSummary = [
                ['label' => 'Total Orders', 'value' => $myOrders->count(), 'icon' => 'orders'],
                ['label' => 'Total Customers', 'value' => $myOrders->pluck('client_id')->filter()->unique()->count()
                    ?: $myOrders->pluck('customer_name')->filter()->unique()->count(), 'icon' => 'customers'],
                ['label' => 'Orders This Month', 'value' => $myOrders->filter(fn ($o) => $o->created_at && $o->created_at->isSameMonth(now()))->count(), 'icon' => 'month'],
                ['label' => 'Completed This Month', 'value' => $myOrders->filter(fn ($o) => $o->completed_at && $o->completed_at->isSameMonth(now()))->count(), 'icon' => 'done'],
            ];

            // ---- Orders this month (daily counts, for the line chart) ----
            $byDay = $myOrders->filter(fn ($o) => $o->created_at && $o->created_at->isSameMonth(now()))
                ->groupBy(fn ($o) => (int) $o->created_at->day)->map->count();
            $monthSeries = [];
            for ($d = 1, $end = now()->daysInMonth; $d <= $end; $d++) {
                $monthSeries[] = ['day' => $d, 'count' => (int) $byDay->get($d, 0)];
            }

            // ---- Recent activity (real timestamped events) ----
            $activity = collect();
            foreach ($myOrders as $o) {
                if ($o->created_at) {
                    $activity->push(['type' => 'new', 'text' => 'New order '.$o->order_number.' created', 'at' => $o->created_at, 'url' => route('orders.show', $o)]);
                }
                if ($o->completed_at) {
                    $activity->push(['type' => 'done', 'text' => 'Order '.$o->order_number.' marked complete', 'at' => $o->completed_at, 'url' => route('orders.show', $o)]);
                }
                foreach ($o->payments as $p) {
                    if ($p->paid_at) {
                        $activity->push(['type' => 'pay', 'text' => 'Payment recorded for '.$o->order_number, 'at' => $p->paid_at, 'url' => route('orders.show', $o)]);
                    }
                }
            }
            $recentActivity = $activity->sortByDesc('at')->take(6)->values();

            // ---- Follow-ups: who asked and has not ordered -------------
            // Everyone still waiting, longest first. A team leader's list is
            // the whole team's, which is what leading one amounts to here.
            $followUps = Inquiry::with(['client', 'officer', 'followUps.user'])
                ->visibleTo($user)
                ->forFollowUp()
                ->get();

            return view('dashboard', compact('user', 'greeting', 'stats', 'recentOrders',
                'statusBreakdown', 'statusTotal', 'stepSlices', 'stepTotal',
                'alerts', 'quickSummary', 'monthSeries', 'recentActivity',
                'followUps'));
        }

        // ---- Finance desk: all payments across every order ----------------
        if ($user->isFinance()) {
            // What the desk is actually FOR, asked first. The three money
            // totals underneath are worth knowing and none of them is a piece
            // of work: the job is confirming what the officers recorded, and
            // until it is done the shop cannot start - hasDownpayment() counts
            // confirmed money only, so an unconfirmed deposit holds the mockup
            // shut. It was only findable by paging through every payment ever
            // taken, so it is put on the desk's own front page.
            // Through the scope, so this list and the sidebar badge that
            // points at it can never come to disagree - see
            // Payment::scopeAwaitingConfirmation.
            $toConfirm = Payment::with(['order.client', 'recorder'])
                ->awaitingConfirmation()
                ->orderBy('paid_at')
                ->orderBy('id')
                ->get();

            $stats = [
                ['label' => 'Total collected', 'value' => '₱'.number_format((float) Payment::sum('amount'), 2), 'note' => 'All recorded payments'],
                ['label' => 'This month', 'value' => '₱'.number_format((float) Payment::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('amount'), 2), 'note' => 'Collected in '.now()->format('F')],
                ['label' => 'Payment records', 'value' => Payment::count(), 'note' => 'On file'],
            ];

            $desk = ['url' => route('finance.index'), 'action' => 'Open finance',
                'title' => 'Finance', 'text' => $toConfirm->isEmpty()
                    ? 'Nothing is waiting to be confirmed. Review every payment and its proof across all orders.'
                    : 'Confirm what the officers have recorded — a job cannot start until its deposit is confirmed.'];

            return view('dashboard', compact('user', 'greeting', 'stats', 'desk', 'toConfirm'));
        }

        // ---- The HR desk --------------------------------------------------
        // Without this she fell through to the branch below and was handed the
        // ---- Desks that don't work from a task list ----------------------
        // Only artists open work from "My Tasks". The raw-materials and finished
        // products desks work from their own pages, and machine operators from
        // the station board — so show each of them their own numbers.
        if (! $user->isArtist()) {
            if ($user->canManageInventory()) {
                // Her fabric or the desk's ready-made stock, and not each
                // other's. The numbers counted both shelves, so the supervisor
                // was told about requests she cannot open and stock she does
                // not keep — the same fault the queue itself had. Super admin
                // has no shelf and still sees the lot.
                $shelf = $user->inventoryShelf();
                $mine = fn ($query) => $query->when($shelf !== null, fn ($q) => $q->where('kind', $shelf));

                $stats = [
                    ['label' => 'Material requests', 'value' => $mine(MaterialRequest::where('status', 'pending'))->count(), 'note' => 'Waiting for you to issue or reject'],
                    ['label' => 'Out of stock', 'value' => $mine(InventoryItem::where('quantity', '<=', 0))->count(), 'note' => 'Materials at zero'],
                    ['label' => 'Materials tracked', 'value' => $mine(InventoryItem::query())->count(), 'note' => 'Items on your shelf'],
                ];

                $desk = ['url' => route('inventory.requests'), 'action' => 'Open material requests',
                    'title' => 'Raw materials', 'text' => 'Issue the materials each job order asked for, or reject when stock is short.'];

                // The queue itself, not a button to it. What is waiting is the
                // whole of this desk's work, and a dashboard that says only
                // "open material requests" is a door with nothing written on
                // it: she had to go through to find out whether anything was
                // there.
                $materialQueue = $mine(MaterialRequest::where('status', 'pending'))
                    ->with(['order' => fn ($q) => $q->select('id', 'order_number', 'customer_name', 'client_id', 'due_date')->with('client')])
                    ->orderBy('id')
                    ->limit(self::MATERIAL_QUEUE_SHOWN)
                    ->get();

                $queueTotal = $mine(MaterialRequest::where('status', 'pending'))->count();

                return view('dashboard', compact('user', 'greeting', 'stats', 'desk', 'materialQueue', 'queueTotal'));
            } elseif ($user->canManageProducts()) {
                $stats = [
                    ['label' => 'To receive', 'value' => ProductReceipt::pending()->count(), 'note' => 'Finished orders waiting to be counted in'],
                    ['label' => 'Out of stock', 'value' => ProductItem::where('quantity', '<=', 0)->count(), 'note' => 'Products at zero'],
                    ['label' => 'Products tracked', 'value' => ProductItem::count(), 'note' => 'Items in inventory'],
                ];
                $desk = ['url' => route('products.index'), 'action' => 'Open inventory',
                    'title' => 'Product inventory', 'text' => 'Count in what production finished, then release products when a client receives them.'];
            } elseif (! $user->canUseStations()) {
                // Not a machine operator at all — the mover, for one. They were
                // dropped into the station operator's desk and handed a link to
                // a board they are not allowed to open, which answered
                // Forbidden. Their work is the orders and the conversation.
                $live = ProductionOrder::where('status', 'active');

                $stats = [
                    ['label' => 'Jobs on the floor', 'value' => (clone $live)->count(), 'note' => 'Open job orders'],
                    ['label' => 'Running late', 'value' => (clone $live)->whereDate('due_date', '<', now())->count(), 'note' => 'Past their delivery date'],
                    ['label' => 'Due today', 'value' => (clone $live)->whereDate('due_date', now())->count(), 'note' => 'Deliver or explain'],
                ];

                $desk = ['url' => route('orders.index'), 'action' => 'Open production orders',
                    'title' => 'Following the floor', 'text' => 'Every job order and where it has got to.'];
            } else {
                $stationKeys = Stations::forUser($user);
                $all = Stations::all();
                $sessions = StationSession::whereNull('ended_at')
                    ->whereIn('station', $stationKeys)->with('order')->get()->keyBy('station');

                // One card per station the operator covers: what's waiting + who's on it.
                $stationCards = collect($stationKeys)->map(function ($key) use ($all, $sessions) {
                    return [
                        'label' => $all[$key]['label'] ?? $key,
                        'group' => $all[$key]['group'] ?? '',
                        'waiting' => StationController::eligibleOrders($key)->count(),
                        'running' => $sessions->get($key),
                    ];
                })->values();

                // The jobs actually waiting for this operator, with their step.
                $waitingList = collect($stationKeys)->flatMap(function ($key) use ($all) {
                    return StationController::eligibleOrders($key)->with('jobOrder')->get()
                        ->map(fn ($o) => ['order' => $o, 'station' => $all[$key]['label'] ?? $key]);
                })->unique(fn ($r) => $r['order']->id.'-'.$r['station'])->take(12)->values();

                $stats = [
                    ['label' => 'Jobs waiting', 'value' => collect($stationKeys)
                        ->sum(fn ($s) => StationController::eligibleOrders($s)->count()), 'note' => 'Ready for you to run'],
                    ['label' => 'Running now', 'value' => $sessions->count(), 'note' => 'Your stations in use'],
                    ['label' => 'Your stations', 'value' => count($stationKeys), 'note' => 'Machines you can run'],
                ];
                $desk = ['url' => route('stations.index'), 'action' => 'Open station board',
                    'title' => 'Your stations', 'text' => 'Start a job order on your machine, and mark it finished when the run is done.'];

                return view('dashboard', compact('user', 'greeting', 'stats', 'desk', 'stationCards', 'waitingList'));
            }

            return view('dashboard', compact('user', 'greeting', 'stats', 'desk'));
        }

        $mine = fn () => Task::where('assigned_to', $user->id)
            ->whereHas('order', fn ($q) => $q->where('status', 'active'));

        $stats = [
            ['label' => 'Ready to start', 'value' => $mine()->where('status', 'ready')->count(), 'note' => 'Unlocked and waiting for you'],
            ['label' => 'In progress', 'value' => $mine()->where('status', 'in_progress')->count(), 'note' => 'Currently on your bench'],
            ['label' => 'For checking', 'value' => $mine()->where('status', 'for_checking')->count(), 'note' => 'Waiting for leader approval'],
            ['label' => 'Revisions requested', 'value' => $mine()->where('status', 'revision_required')->count(), 'note' => 'Needs your rework'],
            ['label' => 'Completed', 'value' => Task::where('assigned_to', $user->id)
                ->where('status', 'complete')
                ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->count(), 'note' => 'Work you have finished'],
        ];

        $myOpenTasks = Task::with('order')
            ->where('assigned_to', $user->id)
            ->whereIn('status', ['revision_required', 'ready', 'in_progress', 'for_checking'])
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->sortBy(fn ($t) => array_search($t->status, ['revision_required', 'ready', 'in_progress', 'for_checking']))
            ->values();

        return view('dashboard', compact('user', 'greeting', 'stats', 'myOpenTasks'));
    }
}
