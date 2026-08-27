@extends('layouts.app')

@section('title', 'Dashboard — Imprint Production')
@section('page-title', 'Dashboard')

@section('content')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ filemtime(public_path('css/dashboard.css')) }}">
@endpush



@php
    $dashboardOrders = collect($recentOrders ?? []);
    $approvalTasks = collect($forChecking ?? []);
    $openTasks = collect($myOpenTasks ?? []);
    $pipelineData = collect($pipelineCounts ?? []);

    $isLeader = $user->isLeader();
    $isSales = $user->isSales();
    $hasDesk = isset($desk);

    $roleLabel = $isLeader
        ? 'Production Leader'
        : ($isSales
            ? 'Sales / Account Officer'
            : ($hasDesk
                ? ($desk['title'] ?? 'Workspace')
                : (strtoupper((string) ($user->role ?? 'Team Member')))));

    $dashboardRows = $dashboardOrders->map(function ($order) {
        [$done, $total] = method_exists($order, 'progress')
            ? $order->progress()
            : [0, 0];

        $currentTask = collect($order->tasks ?? [])->first(function ($task) {
            return ! in_array(
                strtolower((string) ($task->status ?? '')),
                ['complete', 'completed', 'cancelled'],
                true
            );
        });

        return [
            'order' => $order,
            'done' => $done,
            'total' => $total,
            'status' => strtolower((string) ($order->status ?? '')),
            'department' => strtolower((string) ($currentTask->department ?? '')),
            'current_task' => $currentTask,
        ];
    });

    $completedCount = $dashboardRows->filter(
        fn ($row) => in_array($row['status'], ['complete', 'completed'], true)
    )->count();

    $activeCount = $dashboardRows->reject(
        fn ($row) => in_array(
            $row['status'],
            ['complete', 'completed', 'cancelled'],
            true
        )
    )->count();

    $designKeywords = [
        'design',
        'layout',
        'artist',
        'mockup',
        'approval',
        'review',
    ];

    $designCount = $dashboardRows->filter(function ($row) use ($designKeywords) {
        if (in_array($row['status'], ['complete', 'completed', 'cancelled'], true)) {
            return false;
        }

        return \Illuminate\Support\Str::contains(
            $row['department'],
            $designKeywords
        );
    })->count();

    $productionCount = $dashboardRows->filter(function ($row) use ($designKeywords) {
        if (in_array($row['status'], ['complete', 'completed', 'cancelled'], true)) {
            return false;
        }

        return $row['department'] !== ''
            && ! \Illuminate\Support\Str::contains(
                $row['department'],
                $designKeywords
            );
    })->count();

    $stalledCount = $dashboardRows->filter(function ($row) {
        $task = $row['current_task'];

        return $task
            && method_exists($task, 'isStuckNoStaff')
            && $task->isStuckNoStaff();
    })->count();

    $needsDownpaymentCount = $dashboardOrders->filter(function ($order) {
        return method_exists($order, 'layoutApproved')
            && method_exists($order, 'hasDownpayment')
            && in_array(
                strtolower((string) ($order->status ?? '')),
                ['active', 'on_hold'],
                true
            )
            && $order->layoutApproved()
            && ! $order->hasDownpayment();
    })->count();

    $nearDueCount = $dashboardOrders->filter(function ($order) {
        $rawDate = data_get($order, 'delivery_date')
            ?? data_get($order, 'due_date');

        if (! $rawDate) {
            return false;
        }

        try {
            $dueDate = \Illuminate\Support\Carbon::parse($rawDate)->startOfDay();

            return $dueDate->greaterThanOrEqualTo(now()->startOfDay())
                && $dueDate->lessThanOrEqualTo(now()->addDays(3)->endOfDay());
        } catch (\Throwable $exception) {
            return false;
        }
    })->count();

    $awaitingApprovalCount = $dashboardRows->filter(function ($row) {
        return \Illuminate\Support\Str::contains(
            $row['department'],
            ['approval', 'review', 'checking', 'final mockup']
        );
    })->count();

    $totalOrders = $dashboardOrders->count();
    $totalCustomers = $dashboardOrders
        ->map(fn ($o) => $o->clientName())
        ->filter()
        ->unique()
        ->count();

    $ordersThisMonth = $dashboardOrders->filter(function ($order) {
        try {
            return $order->created_at
                && \Illuminate\Support\Carbon::parse($order->created_at)
                    ->isCurrentMonth();
        } catch (\Throwable $exception) {
            return false;
        }
    })->count();

    $completedThisMonth = $dashboardOrders->filter(function ($order) {
        if (! in_array(
            strtolower((string) ($order->status ?? '')),
            ['complete', 'completed'],
            true
        )) {
            return false;
        }

        try {
            $date = $order->updated_at ?? $order->created_at;

            return $date
                && \Illuminate\Support\Carbon::parse($date)->isCurrentMonth();
        } catch (\Throwable $exception) {
            return false;
        }
    })->count();

    $pipelineOpenCount = $pipelineData->sum();
    // Count job packages, not raw tasks: the mockup + template of one order are a
    // single approval (one row on the Approvals page), so they count as 1.
    $approvalCount = $approvalTasks
        ->groupBy(fn ($t) => $t->stage === \App\Models\ProductionOrder::STAGE_MOCKUP
            ? 'pkg-'.$t->production_order_id
            : 'task-'.$t->id)
        ->count();

    $openTaskCount = $openTasks->count();
    $readyTaskCount = $openTasks->filter(
        fn ($task) => strtolower((string) ($task->status ?? '')) === 'ready'
    )->count();

    $revisionTaskCount = $openTasks->filter(function ($task) {
        return \Illuminate\Support\Str::contains(
            strtolower((string) ($task->status ?? '')),
            ['revision', 'revise']
        );
    })->count();

    $inProgressTaskCount = $openTasks->filter(function ($task) {
        return in_array(
            strtolower((string) ($task->status ?? '')),
            ['in_progress', 'in progress', 'working', 'started'],
            true
        );
    })->count();

    $chartTotal = max(1, $totalOrders);
    $otherCount = max(
        0,
        $totalOrders - $designCount - $productionCount - $completedCount
    );

    $designPercent = round(($designCount / $chartTotal) * 100, 2);
    $productionPercent = round(($productionCount / $chartTotal) * 100, 2);
    $completedPercent = round(($completedCount / $chartTotal) * 100, 2);

    $stopOne = $designPercent;
    $stopTwo = $stopOne + $productionPercent;
    $stopThree = $stopTwo + $completedPercent;

    $chartDays = collect(range(7, 0))->map(
        fn ($offset) => now()->subDays($offset)->startOfDay()
    );

    $chartCounts = $chartDays->map(function ($day) use ($dashboardOrders) {
        return $dashboardOrders->filter(function ($order) use ($day) {
            try {
                return $order->created_at
                    && \Illuminate\Support\Carbon::parse($order->created_at)
                        ->isSameDay($day);
            } catch (\Throwable $exception) {
                return false;
            }
        })->count();
    });

    $maximumChartValue = max(1, (int) $chartCounts->max());
    $chartPointCount = max(1, $chartCounts->count() - 1);

    $linePoints = $chartCounts->values()->map(function (
        $count,
        $index
    ) use (
        $maximumChartValue,
        $chartPointCount
    ) {
        $x = 20 + ($index * (760 / $chartPointCount));
        $y = 155 - (($count / $maximumChartValue) * 115);

        return round($x, 1).','.round($y, 1);
    })->implode(' ');

    $areaPoints = '20,165 '.$linePoints.' 780,165';
@endphp

<div class="dash-page-head">
    <div>
        <h1>{{ $greeting }}, {{ $user->name }} 👋</h1>

        <p class="muted">
            @if ($isLeader)
                Here is what needs your attention across production today.
            @elseif ($isSales)
                Here are the latest updates from your production orders.
            @elseif ($hasDesk)
                Your workspace is ready for today's operations.
            @else
                Here is where your assigned work stands today.
            @endif
        </p>

        <span class="dash-role-badge">
            {{ $roleLabel }}
        </span>
    </div>

    @if ($user->canCreateOrders())
        <a href="{{ route('orders.create') }}" class="btn btn-primary">
            + New order
        </a>
    @endif
</div>

@if ($isLeader)
    <div class="dash-stat-grid">
        <div class="dash-stat red">
            <div class="dash-stat-icon">▦</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">Open pipeline tasks</div>
                <div class="dash-stat-value">{{ $pipelineOpenCount }}</div>
                <div class="dash-stat-note">Across all production departments</div>
            </div>
        </div>

        <div class="dash-stat blue">
            <div class="dash-stat-icon">✓</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">Awaiting approval</div>
                <div class="dash-stat-value">{{ $approvalCount }}</div>
                <div class="dash-stat-note">Submissions waiting for checking</div>
            </div>
        </div>

        <div class="dash-stat amber">
            <div class="dash-stat-icon">⚙</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">Active orders</div>
                <div class="dash-stat-value">{{ $activeCount }}</div>
                <div class="dash-stat-note">Recent active production orders</div>
            </div>
        </div>

        <div class="dash-stat green">
            <div class="dash-stat-icon">!</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">Blocked orders</div>
                <div class="dash-stat-value">{{ $stalledCount }}</div>
                <div class="dash-stat-note">Tasks needing staffing attention</div>
            </div>
        </div>
    </div>

    <div class="dash-card" style="margin-bottom: 1rem;">
        <div class="dash-card-head">
            <div>
                <h2>Production pipeline</h2>
                <p>Open tasks currently sitting at each department.</p>
            </div>
        </div>

        <div class="pipeline">
            @foreach (\App\Models\Task::DEPARTMENTS as $seq => $dept)
                @php
                    $count = $pipelineData->get($seq, 0);
                @endphp

                @if ($seq > 1)
                    <div class="step-link"></div>
                @endif

                <div class="step">
                    <div class="step-dot {{ $count > 0 ? 'busy' : '' }}">
                        {{ $count }}
                    </div>

                    <div class="step-name">{{ $dept }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="dash-grid-main">
        <div class="dash-grid-two">
            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <h2>Waiting for approval</h2>
                        <p>Oldest submissions first.</p>
                    </div>

                    <a href="{{ route('approvals') }}" class="dash-card-link">
                        Open approvals
                    </a>
                </div>

                @if ($approvalTasks->isEmpty())
                    <div class="dash-empty">
                        Nothing is waiting for approval right now.
                    </div>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <tbody>
                                @foreach ($approvalTasks as $task)
                                    <tr>
                                        <td>
                                            <a
                                                href="{{ route('orders.show', $task->order) }}"
                                                class="dash-order-link"
                                            >
                                                {{ $task->order->order_number }}
                                            </a>

                                            <div class="dash-subtext">
                                                {{ $task->department }}
                                                ·
                                                {{ $task->assignee?->name ?? 'Unassigned' }}
                                            </div>
                                        </td>

                                        <td style="text-align: right;">
                                            {{ $task->submitted_at?->diffForHumans() }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <h2>Recent orders</h2>
                        <p>The latest production orders.</p>
                    </div>

                    <a href="{{ route('orders.index') }}" class="dash-card-link">
                        All orders
                    </a>
                </div>

@if ($dashboardOrders->isEmpty())
    <div class="dash-empty">No recent orders are available.</div>
@else
    <div class="dash-table-wrap">
        <table class="dash-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Progress</th>
                    <th style="text-align: right;">Status</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($dashboardOrders->take(5) as $order)
                    @php
                        [$done, $total] = method_exists($order, 'progress')
                            ? $order->progress()
                            : [0, 0];

                        $percent = $total
                            ? min(100, round(($done / $total) * 100))
                            : 0;
                    @endphp

                    <tr>
                        <td>
                            <a
                                href="{{ route('orders.show', $order) }}"
                                class="dash-order-link"
                            >
                                {{ $order->order_number }}
                            </a>

                            <div class="dash-subtext">
                                {{ $order->clientName() }}
                            </div>
                        </td>

                        <td>
                            <div class="dash-progress-line">
                                <div class="dash-progress-track">
                                    <div
                                        class="dash-progress-fill"
                                        style="width: {{ $percent }}%;"
                                    ></div>
                                </div>

                                <span class="dash-progress-count">
                                    {{ $done }}/{{ $total }}
                                </span>
                            </div>
                        </td>

                        <td style="text-align: right;">
                            @php
                                $statusValue = strtolower((string) ($order->status ?? 'unknown'));

                                $statusClass = match (true) {
                                    in_array($statusValue, ['complete', 'completed'], true) => 'complete',
                                    in_array($statusValue, ['cancelled', 'canceled', 'rejected'], true) => 'danger',
                                    in_array($statusValue, ['pending', 'on_hold', 'on hold'], true) => 'warning',
                                    in_array($statusValue, ['active', 'in_progress', 'in progress'], true) => 'active',
                                    default => 'neutral',
                                };

                                $statusLabel = strtoupper(
                                    str_replace('_', ' ', $statusValue)
                                );
                            @endphp

                            <span class="dash-status-badge {{ $statusClass }}">
                                {{ $statusLabel }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

            </div>
        </div>

        <aside class="dash-stack">
            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <h2>Production alerts</h2>
                        <p>Items that may need action.</p>
                    </div>
                </div>

                <div class="dash-alert-list">
                    @if ($approvalCount > 0)
                        <a href="{{ route('approvals') }}" class="dash-alert blue">
                            <span class="dash-alert-icon">✓</span>
                            <span>
                                <span class="dash-alert-title">
                                    {{ $approvalCount }} awaiting approval
                                </span>
                                <span class="dash-alert-note">
                                    Review submitted production work
                                </span>
                            </span>
                            <span class="dash-alert-arrow">›</span>
                        </a>
                    @endif

                    @if ($stalledCount > 0)
                        <a href="{{ route('orders.index') }}" class="dash-alert red">
                            <span class="dash-alert-icon">!</span>
                            <span>
                                <span class="dash-alert-title">
                                    {{ $stalledCount }} blocked
                                    {{ \Illuminate\Support\Str::plural('order', $stalledCount) }}
                                </span>
                                <span class="dash-alert-note">
                                    No available staff at the current step
                                </span>
                            </span>
                            <span class="dash-alert-arrow">›</span>
                        </a>
                    @endif

                    @if ($nearDueCount > 0)
                        <a href="{{ route('orders.index') }}" class="dash-alert amber">
                            <span class="dash-alert-icon">◷</span>
                            <span>
                                <span class="dash-alert-title">
                                    {{ $nearDueCount }} near due date
                                </span>
                                <span class="dash-alert-note">
                                    Due within the next three days
                                </span>
                            </span>
                            <span class="dash-alert-arrow">›</span>
                        </a>
                    @endif

                    @if ($approvalCount === 0 && $stalledCount === 0 && $nearDueCount === 0)
                        <div class="dash-empty">
                            No urgent production alerts.
                        </div>
                    @endif
                </div>
            </div>

            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <h2>Quick summary</h2>
                        <p>Current dashboard totals.</p>
                    </div>
                </div>

                <div class="dash-summary-list">
                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">▣</span>
                            Recent orders
                        </span>
                        <strong class="dash-summary-value">{{ $totalOrders }}</strong>
                    </div>

                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">⚙</span>
                            Pipeline tasks
                        </span>
                        <strong class="dash-summary-value">{{ $pipelineOpenCount }}</strong>
                    </div>

                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">✓</span>
                            Completed
                        </span>
                        <strong class="dash-summary-value">{{ $completedCount }}</strong>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    @include('partials.dashboard-order-charts')

@elseif ($isSales)
    <div class="dash-stat-grid">
        <div class="dash-stat red">
            <div class="dash-stat-icon">▣</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">My active orders</div>
                <div class="dash-stat-value">{{ $activeCount }}</div>
                <div class="dash-stat-note">Currently moving through production</div>
            </div>
        </div>

        <div class="dash-stat blue">
            <div class="dash-stat-icon">✎</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">In design</div>
                <div class="dash-stat-value">{{ $designCount }}</div>
                <div class="dash-stat-note">Layout, mockup or client review</div>
            </div>
        </div>

        <div class="dash-stat amber">
            <div class="dash-stat-icon">⚙</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">In production</div>
                <div class="dash-stat-value">{{ $productionCount }}</div>
                <div class="dash-stat-note">Past the design stage</div>
            </div>
        </div>

        <div class="dash-stat green">
            <div class="dash-stat-icon">✓</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">Completed</div>
                <div class="dash-stat-value">{{ $completedCount }}</div>
                <div class="dash-stat-note">Finished production orders</div>
            </div>
        </div>
    </div>

    @include('partials.follow-ups-summary')

    <div class="dash-grid-main">
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h2>My orders</h2>
                    <p>The orders you created, newest first.</p>
                </div>

                <a href="{{ route('orders.index') }}" class="dash-card-link">
                    All orders
                </a>
            </div>

@if ($dashboardOrders->isEmpty())
    <div class="dash-empty">
        You have not created any orders yet. Use “+ New order” to begin.
    </div>
@else
    <div class="dash-table-wrap">
        <table class="dash-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Current step</th>
                    <th>Progress</th>
                    <th style="text-align: right;">Status</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($dashboardOrders as $order)
                    @php
                        [$done, $total] = method_exists($order, 'progress')
                            ? $order->progress()
                            : [0, 0];

                        $percent = $total
                            ? min(100, round(($done / $total) * 100))
                            : 0;

                        $current = collect($order->tasks ?? [])->first(function ($task) {
                            return ! in_array(
                                strtolower((string) ($task->status ?? '')),
                                ['complete', 'completed', 'cancelled'],
                                true
                            );
                        });

                        $needsDownpayment = method_exists($order, 'layoutApproved')
                            && method_exists($order, 'hasDownpayment')
                            && in_array(
                                strtolower((string) ($order->status ?? '')),
                                ['active', 'on_hold'],
                                true
                            )
                            && $order->layoutApproved()
                            && ! $order->hasDownpayment();

                        $isStuck = $current
                            && method_exists($current, 'isStuckNoStaff')
                            && $current->isStuckNoStaff();
                    @endphp

                    <tr>
                        <td>
                            <a
                                href="{{ route('orders.show', $order) }}"
                                class="dash-order-link"
                            >
                                {{ $order->order_number }}
                            </a>

                            <div class="dash-subtext">
                                {{ $order->clientName() }}
                            </div>
                        </td>

                        <td>
                            @if ($needsDownpayment)
                                <span class="dash-warning">
                                    ⚠ Needs downpayment
                                </span>
                            @elseif ($isStuck)
                                <span class="dash-warning">
                                    ⚠ {{ $current->department }} — no one present
                                </span>
                            @else
                                {{ $current?->department ?? 'All steps done' }}
                            @endif
                        </td>

                        <td>
                            <div class="dash-progress-line">
                                <div class="dash-progress-track">
                                    <div
                                        class="dash-progress-fill"
                                        style="width: {{ $percent }}%;"
                                    ></div>
                                </div>

                                <span class="dash-progress-count">
                                    {{ $done }}/{{ $total }}
                                </span>
                            </div>
                        </td>

                        <td style="text-align: right;">
                            @php
                                $statusValue = strtolower((string) ($order->status ?? 'unknown'));

                                $statusClass = match (true) {
                                    in_array($statusValue, ['complete', 'completed'], true) => 'complete',
                                    in_array($statusValue, ['cancelled', 'canceled', 'rejected'], true) => 'danger',
                                    in_array($statusValue, ['pending', 'on_hold', 'on hold'], true) => 'warning',
                                    in_array($statusValue, ['active', 'in_progress', 'in progress'], true) => 'active',
                                    default => 'neutral',
                                };

                                $statusLabel = strtoupper(
                                    str_replace('_', ' ', $statusValue)
                                );
                            @endphp

                            <span class="dash-status-badge {{ $statusClass }}">
                                {{ $statusLabel }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

        </div>

        <aside class="dash-stack">
            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <h2>Alerts</h2>
                        <p>Orders needing your attention.</p>
                    </div>
                </div>

                <div class="dash-alert-list">
                    @if ($needsDownpaymentCount > 0)
                        <a href="{{ route('orders.index') }}" class="dash-alert red">
                            <span class="dash-alert-icon">!</span>
                            <span>
                                <span class="dash-alert-title">
                                    {{ $needsDownpaymentCount }}
                                    {{ \Illuminate\Support\Str::plural('order', $needsDownpaymentCount) }}
                                    need downpayment
                                </span>
                                <span class="dash-alert-note">Payment action required</span>
                            </span>
                            <span class="dash-alert-arrow">›</span>
                        </a>
                    @endif

                    @if ($nearDueCount > 0)
                        <a href="{{ route('orders.index') }}" class="dash-alert amber">
                            <span class="dash-alert-icon">◷</span>
                            <span>
                                <span class="dash-alert-title">
                                    {{ $nearDueCount }} near due date
                                </span>
                                <span class="dash-alert-note">
                                    Due within the next three days
                                </span>
                            </span>
                            <span class="dash-alert-arrow">›</span>
                        </a>
                    @endif

                    @if ($awaitingApprovalCount > 0)
                        <a href="{{ route('orders.index') }}" class="dash-alert blue">
                            <span class="dash-alert-icon">◉</span>
                            <span>
                                <span class="dash-alert-title">
                                    {{ $awaitingApprovalCount }} await approval
                                </span>
                                <span class="dash-alert-note">
                                    Client review may be needed
                                </span>
                            </span>
                            <span class="dash-alert-arrow">›</span>
                        </a>
                    @endif

                    @if (
                        $needsDownpaymentCount === 0
                        && $nearDueCount === 0
                        && $awaitingApprovalCount === 0
                    )
                        <div class="dash-empty">No urgent alerts right now.</div>
                    @endif
                </div>
            </div>

            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <h2>Quick summary</h2>
                        <p>Your latest order activity.</p>
                    </div>
                </div>

                <div class="dash-summary-list">
                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">▣</span>
                            Recent orders
                        </span>
                        <strong class="dash-summary-value">{{ $totalOrders }}</strong>
                    </div>

                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">♙</span>
                            Customers
                        </span>
                        <strong class="dash-summary-value">{{ $totalCustomers }}</strong>
                    </div>

                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">◷</span>
                            This month
                        </span>
                        <strong class="dash-summary-value">{{ $ordersThisMonth }}</strong>
                    </div>

                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">✓</span>
                            Completed this month
                        </span>
                        <strong class="dash-summary-value">{{ $completedThisMonth }}</strong>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    @include('partials.dashboard-order-charts')

    <div class="dash-card" style="margin-top: 1rem;">
        <div class="dash-card-head">
            <div>
                <h2>Recent activity</h2>
                <p>Your latest created orders.</p>
            </div>
        </div>

        <div class="dash-activity-list">
            @forelse ($dashboardOrders->take(4) as $order)
                <a
                    href="{{ route('orders.show', $order) }}"
                    class="dash-activity-row"
                >
                    <span class="dash-activity-icon">▣</span>
                    <span class="dash-activity-text">
                        Order {{ $order->order_number }} was created for
                        {{ $order->clientName() }}
                    </span>
                    <span class="dash-activity-time">
                        {{ $order->created_at?->diffForHumans() }}
                    </span>
                </a>
            @empty
                <div class="dash-empty">No recent activity.</div>
            @endforelse
        </div>
    </div>

@elseif ($hasDesk)
    <div class="dash-workspace-hero">
        <div>
            <h2>{{ $desk['title'] }}</h2>
            <p>{{ $desk['text'] }}</p>
        </div>

        <div class="dash-workspace-action">
            <a href="{{ $desk['url'] }}" class="btn btn-primary">
                {{ $desk['action'] }} →
            </a>
        </div>
    </div>

    @if (isset($stationCards))
        {{-- Station operator: a card per machine they run — waiting count + who's on it. --}}
        <div class="dash-focus-grid">
            @forelse ($stationCards as $sc)
                <div class="dash-focus-card">
                    <div class="dash-focus-title">{{ $sc['label'] }}</div>
                    <div class="dash-focus-text">
                        @if ($sc['running'])
                            <span style="color:#E31B23; font-weight:700;">▶ Running</span> —
                            {{ $sc['running']->operator_name ?: 'in use' }}@if ($sc['running']->order) · {{ $sc['running']->order->order_number }}@endif
                        @elseif ($sc['waiting'] > 0)
                            <strong>{{ $sc['waiting'] }}</strong> job{{ $sc['waiting'] == 1 ? '' : 's' }} waiting to run
                        @else
                            Idle — nothing waiting
                        @endif
                    </div>
                </div>
            @empty
                <div class="dash-focus-card">
                    <div class="dash-focus-title">No stations assigned</div>
                    <div class="dash-focus-text">Ask your leader to set your job role so stations appear here.</div>
                </div>
            @endforelse
        </div>

        @if ($waitingList->isNotEmpty())
            <div class="card panel" style="margin-top: 1.4rem;">
                <h2>Jobs waiting for you</h2>
                <p class="sub">Ready at your stations — open the board to start one.</p>
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>Order</th><th>Client</th><th>Station</th><th>Product</th></tr></thead>
                        <tbody>
                            @foreach ($waitingList as $r)
                                <tr>
                                    <td style="font-weight: 600;">{{ $r['order']->order_number }}</td>
                                    <td>{{ $r['order']->clientName() }}</td>
                                    <td>{{ $r['station'] }}</td>
                                    <td>{{ $r['order']->productLabel() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (auth()->user()->canUseStations())
                    <a href="{{ route('stations.index') }}" class="btn btn-ghost btn-sm" style="margin-top: 0.75rem;">Open station board →</a>
                @endif
            </div>
        @endif
    @else
    <div class="dash-focus-grid">
        <div class="dash-focus-card">
            <div class="dash-focus-icon">✓</div>
            <div class="dash-focus-title">Workspace ready</div>
            <div class="dash-focus-text">
                Open your assigned module and continue today's operational work.
            </div>
        </div>

        <div class="dash-focus-card">
            <div class="dash-focus-icon">▣</div>
            <div class="dash-focus-title">Use live records</div>
            <div class="dash-focus-text">
                Record actual movements and updates directly in the system.
            </div>
        </div>

        <div class="dash-focus-card">
            <div class="dash-focus-icon">!</div>
            <div class="dash-focus-title">Report exceptions</div>
            <div class="dash-focus-text">
                Escalate missing stock, blocked jobs or incorrect records promptly.
            </div>
        </div>
    </div>
    @endif

@else
    <div class="dash-stat-grid">
        <div class="dash-stat red">
            <div class="dash-stat-icon">▣</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">Open tasks</div>
                <div class="dash-stat-value">{{ $openTaskCount }}</div>
                <div class="dash-stat-note">Current assigned workload</div>
            </div>
        </div>

        <div class="dash-stat blue">
            <div class="dash-stat-icon">▶</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">Ready to start</div>
                <div class="dash-stat-value">{{ $readyTaskCount }}</div>
                <div class="dash-stat-note">Tasks ready for action</div>
            </div>
        </div>

        <div class="dash-stat amber">
            <div class="dash-stat-icon">↺</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">Revisions</div>
                <div class="dash-stat-value">{{ $revisionTaskCount }}</div>
                <div class="dash-stat-note">Tasks returned for changes</div>
            </div>
        </div>

        <div class="dash-stat green">
            <div class="dash-stat-icon">⚙</div>
            <div class="dash-stat-body">
                <div class="dash-stat-label">In progress</div>
                <div class="dash-stat-value">{{ $inProgressTaskCount }}</div>
                <div class="dash-stat-note">Tasks currently being worked on</div>
            </div>
        </div>
    </div>

    <div class="dash-grid-main">
        <div class="dash-card">
            <div class="dash-card-head">
                <div>
                    <h2>My open tasks</h2>
                    <p>Revisions and ready tasks should be handled first.</p>
                </div>

                <a href="{{ route('tasks.mine') }}" class="dash-card-link">
                    All my tasks
                </a>
            </div>

            @if ($openTasks->isEmpty())
                <div class="dash-empty">
                    No open tasks right now. New assignments will appear here.
                </div>
            @else
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Order</th>
                                <th style="text-align: right;">Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($openTasks as $task)
                                <tr>
                                    <td>
                                        <a
                                            href="{{ route('tasks.show', $task->id) }}"
                                            class="dash-order-link"
                                        >
                                            {{ $task->department }}
                                        </a>
                                    </td>

                                    <td>
                                        {{ $task->order->order_number ?? '—' }}
                                        <div class="dash-subtext">
                                            {{ $task->order->clientName() ?? '' }}
                                        </div>
                                    </td>

                                    <td style="text-align: right;">
                                        @php
                                            $statusValue = strtolower((string) ($task->status ?? 'unknown'));

                                            $statusClass = match (true) {
                                                in_array($statusValue, ['complete', 'completed'], true) => 'complete',
                                                in_array($statusValue, ['cancelled', 'canceled', 'rejected'], true) => 'danger',
                                                in_array($statusValue, ['pending', 'on_hold', 'on hold', 'revision'], true) => 'warning',
                                                in_array($statusValue, ['active', 'ready', 'in_progress', 'in progress'], true) => 'active',
                                                default => 'neutral',
                                            };

                                            $statusLabel = strtoupper(
                                                str_replace('_', ' ', $statusValue)
                                            );
                                        @endphp

                                        <span class="dash-status-badge {{ $statusClass }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <aside class="dash-stack">
            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <h2>Today's focus</h2>
                        <p>Suggested order of work.</p>
                    </div>
                </div>

                <div class="dash-summary-list">
                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">↺</span>
                            Handle revisions
                        </span>
                        <strong class="dash-summary-value">{{ $revisionTaskCount }}</strong>
                    </div>

                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">▶</span>
                            Start ready tasks
                        </span>
                        <strong class="dash-summary-value">{{ $readyTaskCount }}</strong>
                    </div>

                    <div class="dash-summary-row">
                        <span class="dash-summary-label">
                            <span class="dash-summary-icon">⚙</span>
                            Continue active work
                        </span>
                        <strong class="dash-summary-value">{{ $inProgressTaskCount }}</strong>
                    </div>
                </div>
            </div>

            <div class="dash-card">
                <div class="dash-card-head">
                    <div>
                        <h2>Work reminder</h2>
                        <p>Keep production records accurate.</p>
                    </div>
                </div>

                <div class="dash-empty" style="text-align: left;">
                    Open the task before starting work, upload the required output,
                    and update the status only after the actual work is completed.
                </div>
            </div>
        </aside>
    </div>
@endif

{{-- Make the whole order row clickable (not just the order-number link). Any
     row in a dashboard table that has an order link opens that order when
     clicked anywhere except on another link/button/control. --}}
<script>
    (function () {
        document.querySelectorAll('.dash-table tbody tr').forEach(function (row) {
            var link = row.querySelector('.dash-order-link');
            if (!link) return;

            row.classList.add('dash-row-clickable');
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button, input, select, textarea, form, summary, details, label')) return;
                window.location.href = link.getAttribute('href');
            });
        });
    })();
</script>

@endsection