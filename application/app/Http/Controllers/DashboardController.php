<?php

namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $hour = (int) now()->format('G');
        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        $activeTasks = fn () => Task::whereHas('order', fn ($q) => $q->where('status', 'active'));

        if ($user->isLeader()) {
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
                ['label' => 'Tasks for checking', 'value' => $activeTasks()->where('status', 'for_checking')->count(), 'note' => 'Waiting for your approval'],
                ['label' => 'Revisions requested', 'value' => $activeTasks()->where('status', 'revision_required')->count(), 'note' => 'Back with the agents'],
                ['label' => 'Active agents', 'value' => $presentAgents, 'note' => 'Present and working today'],
            ];

            // Only tasks actually sitting at a department right now — not locked
            // (todo) future steps or finished (complete) ones.
            $pipelineCounts = $activeTasks()
                ->whereIn('status', ['ready', 'in_progress', 'for_checking', 'revision_required'])
                ->get()
                ->countBy('stage');

            $forChecking = Task::with(['order', 'assignee'])
                ->where('status', 'for_checking')
                ->whereHas('order', fn ($q) => $q->where('status', 'active'))
                ->orderBy('submitted_at')
                ->limit(5)
                ->get();

            $recentOrders = ProductionOrder::with('tasks')
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            return view('dashboard', compact('user', 'greeting', 'stats', 'pipelineCounts', 'forChecking', 'recentOrders'));
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

            // ---- Orders-by-status donut (each order lands in exactly one bucket) ----
            $bucketOf = function ($o) use ($currentStage) {
                if ($o->status === 'complete') return 'Completed';
                if ($o->status !== 'active') return 'On hold / other';
                if (! $o->layoutReleased()) return 'Awaiting design';
                $st = $currentStage($o);
                return ($st !== null && $st >= ProductionOrder::STAGE_MOCKUP + 1) ? 'In production' : 'In design';
            };
            $counts = $myOrders->groupBy($bucketOf)->map->count();
            $palette = [
                'In design' => '#2D7FF0', 'In production' => '#E59A18',
                'Completed' => '#18A957', 'Awaiting design' => '#E31B23', 'On hold / other' => '#94A0AE',
            ];
            $statusBreakdown = collect($palette)
                ->map(fn ($color, $label) => ['label' => $label, 'value' => $counts->get($label, 0), 'color' => $color])
                ->filter(fn ($s) => $s['value'] > 0)
                ->values()
                ->all();
            $statusTotal = $myOrders->count();

            // ---- Alerts (only real, actionable ones) ----
            $needsDp = $myOrders->filter(fn ($o) => in_array($o->status, ['active', 'on_hold']) && $o->layoutApproved() && ! $o->hasDownpayment());
            $nearDue = $myOrders->filter(fn ($o) => $o->due_date && ! in_array($o->status, ['complete', 'cancelled'])
                && $o->due_date->betweenIncluded(now()->startOfDay(), now()->copy()->addDays(3)->endOfDay()));
            $awaitApproval = $myOrders->filter(fn ($o) => in_array($o->status, ['active', 'on_hold']) && $o->layoutReleased() && ! $o->layoutApproved());

            $alerts = [];
            if ($needsDp->isNotEmpty()) {
                $alerts[] = ['tone' => 'error', 'title' => $needsDp->count().' '.\Illuminate\Support\Str::plural('order', $needsDp->count()).' need downpayment',
                    'sub' => $needsDp->first()->order_number, 'url' => route('orders.show', $needsDp->first())];
            }
            if ($nearDue->isNotEmpty()) {
                $alerts[] = ['tone' => 'warning', 'title' => $nearDue->count().' '.\Illuminate\Support\Str::plural('order', $nearDue->count()).' near due date',
                    'sub' => 'Due within 3 days', 'url' => route('orders.index')];
            }
            if ($awaitApproval->isNotEmpty()) {
                $alerts[] = ['tone' => 'info', 'title' => $awaitApproval->count().' '.\Illuminate\Support\Str::plural('order', $awaitApproval->count()).' await client approval',
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
                if ($o->created_at) $activity->push(['type' => 'new', 'text' => 'New order '.$o->order_number.' created', 'at' => $o->created_at, 'url' => route('orders.show', $o)]);
                if ($o->completed_at) $activity->push(['type' => 'done', 'text' => 'Order '.$o->order_number.' marked complete', 'at' => $o->completed_at, 'url' => route('orders.show', $o)]);
                foreach ($o->payments as $p) {
                    if ($p->paid_at) $activity->push(['type' => 'pay', 'text' => 'Payment recorded for '.$o->order_number, 'at' => $p->paid_at, 'url' => route('orders.show', $o)]);
                }
            }
            $recentActivity = $activity->sortByDesc('at')->take(6)->values();

            return view('dashboard', compact('user', 'greeting', 'stats', 'recentOrders',
                'statusBreakdown', 'statusTotal', 'alerts', 'quickSummary', 'monthSeries', 'recentActivity'));
        }

        // ---- Finance desk: all payments across every order ----------------
        if ($user->isFinance()) {
            $stats = [
                ['label' => 'Total collected', 'value' => '₱'.number_format((float) \App\Models\Payment::sum('amount'), 2), 'note' => 'All recorded payments'],
                ['label' => 'This month', 'value' => '₱'.number_format((float) \App\Models\Payment::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('amount'), 2), 'note' => 'Collected in '.now()->format('F')],
                ['label' => 'Payment records', 'value' => \App\Models\Payment::count(), 'note' => 'On file'],
            ];
            $desk = ['url' => route('finance.index'), 'action' => 'Open finance',
                'title' => 'Finance', 'text' => 'Review every payment and its proof across all orders.'];

            return view('dashboard', compact('user', 'greeting', 'stats', 'desk'));
        }

        // ---- Desks that don't work from a task list ----------------------
        // Only artists open work from "My Tasks". The raw-materials and finished
        // products desks work from their own pages, and machine operators from
        // the station board — so show each of them their own numbers.
        if (! $user->isArtist()) {
            if ($user->canManageInventory()) {
                $stats = [
                    ['label' => 'Material requests', 'value' => \App\Models\MaterialRequest::where('status', 'pending')->count(), 'note' => 'Waiting for you to issue or reject'],
                    ['label' => 'Out of stock', 'value' => \App\Models\InventoryItem::where('quantity', '<=', 0)->count(), 'note' => 'Materials at zero'],
                    ['label' => 'Materials tracked', 'value' => \App\Models\InventoryItem::count(), 'note' => 'Items in raw materials'],
                ];
                $desk = ['url' => route('inventory.requests'), 'action' => 'Open material requests',
                    'title' => 'Raw materials', 'text' => 'Issue the materials each job order asked for, or reject when stock is short.'];
            } elseif ($user->canManageProducts()) {
                $stats = [
                    ['label' => 'To receive', 'value' => \App\Models\ProductReceipt::pending()->count(), 'note' => 'Finished orders waiting to be counted in'],
                    ['label' => 'Out of stock', 'value' => \App\Models\ProductItem::where('quantity', '<=', 0)->count(), 'note' => 'Products at zero'],
                    ['label' => 'Products tracked', 'value' => \App\Models\ProductItem::count(), 'note' => 'Items in inventory'],
                ];
                $desk = ['url' => route('products.index'), 'action' => 'Open inventory',
                    'title' => 'Product inventory', 'text' => 'Count in what production finished, then release products when a client receives them.'];
            } else {
                $stationKeys = \App\Services\Stations::forUser($user);
                $all = \App\Services\Stations::all();
                $sessions = \App\Models\StationSession::whereNull('ended_at')
                    ->whereIn('station', $stationKeys)->with('order')->get()->keyBy('station');

                // One card per station the operator covers: what's waiting + who's on it.
                $stationCards = collect($stationKeys)->map(function ($key) use ($all, $sessions) {
                    return [
                        'label' => $all[$key]['label'] ?? $key,
                        'group' => $all[$key]['group'] ?? '',
                        'waiting' => \App\Http\Controllers\StationController::eligibleOrders($key)->count(),
                        'running' => $sessions->get($key),
                    ];
                })->values();

                // The jobs actually waiting for this operator, with their step.
                $waitingList = collect($stationKeys)->flatMap(function ($key) use ($all) {
                    return \App\Http\Controllers\StationController::eligibleOrders($key)->with('jobOrder')->get()
                        ->map(fn ($o) => ['order' => $o, 'station' => $all[$key]['label'] ?? $key]);
                })->unique(fn ($r) => $r['order']->id.'-'.$r['station'])->take(12)->values();

                $stats = [
                    ['label' => 'Jobs waiting', 'value' => collect($stationKeys)
                        ->sum(fn ($s) => \App\Http\Controllers\StationController::eligibleOrders($s)->count()), 'note' => 'Ready for you to run'],
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
