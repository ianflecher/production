@extends('layouts.app')

@section('title', 'Dashboard — Imprint Production')
@section('page-title', 'Dashboard')

@section('content')

<style>
    :root {
        --dash-red: #e31b23;
        --dash-red-dark: #b5141a;
        --dash-blue: #2d7ff0;
        --dash-green: #18a957;
        --dash-amber: #e59a18;
        --dash-purple: #7957c8;
        --dash-ink: #152033;
        --dash-ink-2: #526176;
        --dash-muted: #8996a8;
        --dash-border: #dfe6ed;
        --dash-soft-border: #edf1f5;
        --dash-surface: #ffffff;
        --dash-soft: #f8fafc;
    }

    .dash-page-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .dash-page-head h1 {
        margin: 0;
        color: var(--dash-ink);
    }

    .dash-page-head p {
        margin: 0.35rem 0 0;
    }

    .dash-role-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        margin-top: 0.65rem;
        padding: 0.32rem 0.65rem;
        color: #b5141a;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        background: #fdebec;
        border: 1px solid #f6cdd0;
        border-radius: 999px;
    }

    .dash-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .dash-stat {
        --stat-color: var(--dash-red);
        --stat-soft: #fdebec;

        position: relative;
        display: flex;
        align-items: center;
        gap: 0.9rem;
        min-width: 0;
        min-height: 108px;
        padding: 1rem 1.1rem;
        overflow: hidden;
        background: linear-gradient(135deg, var(--stat-soft) 0%, #ffffff 78%);
        border: 1px solid color-mix(in srgb, var(--stat-color) 28%, #ffffff);
        border-radius: 14px;
        box-shadow: 0 6px 20px color-mix(in srgb, var(--stat-color) 14%, transparent);
        transition: transform .15s ease, box-shadow .15s ease;
    }

    .dash-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 28px color-mix(in srgb, var(--stat-color) 22%, transparent);
    }

    .dash-stat::after {
        content: "";
        position: absolute;
        right: 0;
        bottom: 0;
        left: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--stat-color), color-mix(in srgb, var(--stat-color) 55%, #ffffff));
    }

    .dash-stat.red {
        --stat-color: var(--dash-red);
        --stat-soft: #fdebec;
    }

    .dash-stat.blue {
        --stat-color: var(--dash-blue);
        --stat-soft: #e8f1ff;
    }

    .dash-stat.green {
        --stat-color: var(--dash-green);
        --stat-soft: #e8f7ef;
    }

    .dash-stat.amber {
        --stat-color: var(--dash-amber);
        --stat-soft: #fff4df;
    }

    .dash-stat.purple {
        --stat-color: var(--dash-purple);
        --stat-soft: #f0ebfa;
    }

    .dash-stat-icon {
        display: grid;
        place-items: center;
        flex: 0 0 46px;
        width: 46px;
        height: 46px;
        color: #ffffff;
        font-size: 1.2rem;
        font-weight: 900;
        background: linear-gradient(135deg, color-mix(in srgb, var(--stat-color) 82%, #ffffff), var(--stat-color));
        border-radius: 12px;
        box-shadow: 0 4px 12px color-mix(in srgb, var(--stat-color) 40%, transparent);
    }

    .dash-stat-body {
        min-width: 0;
    }

    .dash-stat-label {
        color: #68788f;
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .dash-stat-value {
        margin-top: 0.15rem;
        overflow: hidden;
        color: color-mix(in srgb, var(--stat-color) 78%, #1a2438);
        font-size: 1.75rem;
        font-weight: 850;
        line-height: 1.05;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    .dash-stat-value.is-text {
        font-size: 1.05rem;
    }

    .dash-stat-note {
        margin-top: 0.35rem;
        overflow: hidden;
        color: var(--dash-muted);
        font-size: 0.73rem;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dash-grid-main {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 320px;
        gap: 1rem;
        align-items: start;
    }

    .dash-grid-two {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    .dash-stack {
        display: grid;
        gap: 1rem;
    }

    .dash-card {
        padding: 1.1rem;
        background: var(--dash-surface);
        border: 1px solid var(--dash-border);
        border-radius: 12px;
        box-shadow: 0 5px 18px rgba(15, 23, 42, 0.045);
    }

    .dash-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.9rem;
    }

    .dash-card-head h2 {
        margin: 0;
        color: var(--dash-ink);
        font-size: 1rem;
    }

    .dash-card-head p {
        margin: 0.25rem 0 0;
        color: var(--dash-muted);
        font-size: 0.75rem;
    }

    .dash-card-link {
        flex-shrink: 0;
        color: var(--dash-red);
        font-size: 0.75rem;
        font-weight: 800;
        text-decoration: none;
    }

    .dash-card-link:hover {
        color: var(--dash-red-dark);
        text-decoration: underline;
    }

    .dash-table-wrap {
        overflow-x: auto;
    }

    .dash-table {
        width: 100%;
        border-collapse: collapse;
    }

    .dash-table th {
        padding: 0.65rem 0.7rem;
        color: #8a98aa;
        font-size: 0.66rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-align: left;
        text-transform: uppercase;
        border-bottom: 1px solid var(--dash-border);
    }

    .dash-table td {
        padding: 0.72rem 0.7rem;
        color: #3f4d61;
        font-size: 0.77rem;
        border-bottom: 1px solid var(--dash-soft-border);
        vertical-align: middle;
    }

    .dash-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .dash-order-link {
        color: #1264dc;
        font-weight: 750;
        text-decoration: none;
    }

    .dash-order-link:hover {
        text-decoration: underline;
    }

    .dash-subtext {
        margin-top: 0.12rem;
        color: #8a98aa;
        font-size: 0.7rem;
    }

    .dash-warning {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        color: #d92d35;
        font-weight: 750;
    }

    .dash-progress-line {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        min-width: 140px;
    }

    .dash-progress-track {
        flex: 1;
        height: 6px;
        overflow: hidden;
        background: #e8edf3;
        border-radius: 999px;
    }

    .dash-progress-fill {
        height: 100%;
        background: var(--dash-green);
        border-radius: inherit;
    }

    .dash-progress-count {
        color: #8794a6;
        font-size: 0.69rem;
        white-space: nowrap;
    }

    .dash-alert-list,
    .dash-summary-list,
    .dash-activity-list {
        display: grid;
    }

    .dash-alert-list {
        gap: 0.65rem;
    }

    .dash-alert {
        display: grid;
        grid-template-columns: 38px minmax(0, 1fr) auto;
        gap: 0.7rem;
        align-items: center;
        padding: 0.75rem;
        color: inherit;
        text-decoration: none;
        background: var(--dash-soft);
        border: 1px solid #e8edf2;
        border-radius: 9px;
    }

    .dash-alert:hover {
        background: #fff;
        border-color: #ccd6e2;
    }

    .dash-alert-icon {
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        font-weight: 900;
        border-radius: 9px;
    }

    .dash-alert.red .dash-alert-icon {
        color: #d92d35;
        background: #fdebec;
    }

    .dash-alert.amber .dash-alert-icon {
        color: #d48508;
        background: #fff4df;
    }

    .dash-alert.blue .dash-alert-icon {
        color: var(--dash-blue);
        background: #e8f1ff;
    }

    .dash-alert-title {
        display: block;
        color: #263449;
        font-size: 0.78rem;
        font-weight: 750;
        line-height: 1.3;
    }

    .dash-alert-note {
        display: block;
        margin-top: 0.1rem;
        color: #7b8899;
        font-size: 0.69rem;
    }

    .dash-alert-arrow {
        color: #8491a3;
        font-size: 1.1rem;
    }

    .dash-summary-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        min-height: 43px;
        border-bottom: 1px solid var(--dash-soft-border);
    }

    .dash-summary-row:last-child {
        border-bottom: 0;
    }

    .dash-summary-label {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        color: #46556a;
        font-size: 0.76rem;
    }

    .dash-summary-icon {
        display: grid;
        place-items: center;
        width: 27px;
        height: 27px;
        color: var(--dash-blue);
        font-size: 0.75rem;
        font-weight: 900;
        background: #edf4ff;
        border-radius: 7px;
    }

    .dash-summary-value {
        color: var(--dash-ink);
        font-weight: 850;
        font-variant-numeric: tabular-nums;
    }

    .dash-empty {
        padding: 1rem;
        color: #748195;
        font-size: 0.76rem;
        text-align: center;
        background: var(--dash-soft);
        border: 1px dashed #d7e0e9;
        border-radius: 9px;
    }

    .pipeline {
        display: flex;
        align-items: flex-start;
        overflow-x: auto;
        padding: 0.25rem 0 0.6rem;
        scrollbar-width: thin;
    }

    .step {
        display: flex;
        flex-direction: column;
        align-items: center;
        min-width: 96px;
        text-align: center;
    }

    .step-dot {
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        color: #7c8ba0;
        font-size: 0.78rem;
        font-weight: 800;
        background: #f5f8fb;
        border: 2px solid #dbe4ed;
        border-radius: 50%;
    }

    .step-dot.busy {
        color: #1264dc;
        background: #edf4ff;
        border-color: #2d7ff0;
        box-shadow: 0 0 0 4px rgba(45, 127, 240, 0.09);
    }

    .step-name {
        margin-top: 0.5rem;
        color: #56657a;
        font-size: 0.68rem;
        font-weight: 650;
        line-height: 1.2;
    }

    .step-link {
        flex: 1;
        min-width: 18px;
        height: 2px;
        margin-top: 19px;
        background: #dce4ec;
    }

    .dash-chart-grid {
        display: grid;
        grid-template-columns: minmax(280px, 0.8fr) minmax(420px, 1.35fr);
        gap: 1rem;
        margin-top: 1rem;
    }

    .dash-donut-layout {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        min-height: 200px;
    }

    .dash-donut {
        position: relative;
        flex: 0 0 138px;
        width: 138px;
        height: 138px;
        border-radius: 50%;
    }

    .dash-donut::after {
        content: "";
        position: absolute;
        inset: 26px;
        z-index: 1;
        background: #fff;
        border-radius: 50%;
    }

    .dash-donut-center {
        position: absolute;
        inset: 0;
        z-index: 2;
        display: grid;
        place-content: center;
        text-align: center;
    }

    .dash-donut-center strong {
        color: var(--dash-ink);
        font-size: 1.65rem;
        line-height: 1;
    }

    .dash-donut-center span {
        margin-top: 0.25rem;
        color: #8390a2;
        font-size: 0.68rem;
    }

    .dash-legend {
        display: grid;
        flex: 1;
        gap: 0.6rem;
    }

    .dash-legend-row {
        display: grid;
        grid-template-columns: 9px minmax(0, 1fr) auto;
        gap: 0.5rem;
        align-items: center;
        color: #526176;
        font-size: 0.73rem;
    }

    .dash-legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
    }

    .dash-line-chart svg {
        display: block;
        width: 100%;
        height: 200px;
    }

    .dash-chart-labels {
        display: flex;
        justify-content: space-between;
        color: #8b98a9;
        font-size: 0.67rem;
    }

    .dash-activity-row {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr) auto;
        gap: 0.7rem;
        align-items: center;
        min-height: 50px;
        color: inherit;
        text-decoration: none;
        border-bottom: 1px solid var(--dash-soft-border);
    }

    .dash-activity-row:last-child {
        border-bottom: 0;
    }

    .dash-activity-icon {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        color: var(--dash-blue);
        font-weight: 850;
        background: #e8f1ff;
        border-radius: 8px;
    }

    .dash-activity-text {
        color: #344156;
        font-size: 0.76rem;
    }

    .dash-activity-time {
        color: #8996a8;
        font-size: 0.67rem;
        white-space: nowrap;
    }

    .dash-workspace-hero {
        position: relative;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 1.5rem;
        align-items: center;
        padding: 1.4rem;
        overflow: hidden;
        background:
            linear-gradient(135deg, #ffffff 0%, #ffffff 65%, #fff4f4 100%);
        border: 1px solid var(--dash-border);
        border-left: 4px solid var(--dash-red);
        border-radius: 12px;
        box-shadow: 0 5px 18px rgba(15, 23, 42, 0.05);
    }

    .dash-workspace-hero::after {
        content: "";
        position: absolute;
        right: -45px;
        bottom: -65px;
        width: 190px;
        height: 190px;
        background: rgba(227, 27, 35, 0.055);
        border-radius: 50%;
    }

    .dash-workspace-hero h2 {
        margin: 0;
        color: var(--dash-ink);
    }

    .dash-workspace-hero p {
        max-width: 720px;
        margin: 0.35rem 0 0;
        color: #637187;
        font-size: 0.8rem;
        line-height: 1.55;
    }

    .dash-workspace-action {
        position: relative;
        z-index: 1;
    }

    .dash-focus-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .dash-focus-card {
        padding: 1rem;
        background: #fff;
        border: 1px solid var(--dash-border);
        border-radius: 11px;
    }

    .dash-focus-icon {
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        margin-bottom: 0.75rem;
        color: var(--dash-red);
        font-weight: 900;
        background: #fdebec;
        border-radius: 9px;
    }

    .dash-focus-title {
        color: var(--dash-ink);
        font-size: 0.82rem;
        font-weight: 800;
    }

    .dash-focus-text {
        margin-top: 0.3rem;
        color: #7c899a;
        font-size: 0.72rem;
        line-height: 1.45;
    }


    .dash-status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 23px;
        padding: 0.2rem 0.58rem;
        font-size: 0.64rem;
        font-weight: 850;
        letter-spacing: 0.025em;
        line-height: 1;
        text-transform: uppercase;
        border: 1px solid transparent;
        border-radius: 999px;
        white-space: nowrap;
    }

    .dash-status-badge::before {
        content: "";
        width: 6px;
        height: 6px;
        margin-right: 0.35rem;
        background: currentColor;
        border-radius: 50%;
    }

    .dash-status-badge.active {
        color: #1264dc;
        background: #edf4ff;
        border-color: #d4e4fb;
    }

    .dash-status-badge.complete {
        color: #138447;
        background: #e8f7ef;
        border-color: #cdeedc;
    }

    .dash-status-badge.warning {
        color: #b56c00;
        background: #fff4df;
        border-color: #f6dfb1;
    }

    .dash-status-badge.danger {
        color: #c5262f;
        background: #fdebec;
        border-color: #f6cdd0;
    }

    .dash-status-badge.neutral {
        color: #657489;
        background: #f1f4f7;
        border-color: #e0e6ec;
    }

    @media (max-width: 1150px) {
        .dash-stat-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .dash-grid-main {
            grid-template-columns: 1fr;
        }

        .dash-grid-two,
        .dash-chart-grid {
            grid-template-columns: 1fr;
        }

        .dash-focus-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 700px) {
        .dash-page-head,
        .dash-workspace-hero {
            grid-template-columns: 1fr;
            flex-direction: column;
        }

        .dash-page-head {
            display: flex;
        }

        .dash-page-head .btn,
        .dash-workspace-action .btn {
            width: 100%;
            justify-content: center;
        }

        .dash-stat-grid,
        .dash-focus-grid {
            grid-template-columns: 1fr;
        }

        .dash-donut-layout {
            align-items: flex-start;
            flex-direction: column;
        }

        .dash-activity-row {
            grid-template-columns: 34px minmax(0, 1fr);
        }

        .dash-activity-time {
            grid-column: 2;
        }
    }

    /* =====================================================================
       COLORFUL DASHBOARD  —  extra colour on the cards, headers and tables.
       ===================================================================== */

    /* Dashboard title: solid dark ink. */
    .dash-page-head h1 { color: var(--dash-ink); }

    /* Cards: soft gradient body, stronger shadow, and a colored top rail. */
    .dash-card {
        background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
        border: 1px solid #e4eaf2;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.07);
    }
    .dash-card:hover { box-shadow: 0 14px 36px rgba(15, 23, 42, 0.11); }

    /* Card headings become a colorful gradient with a divider under them. */
    .dash-card-head {
        border-bottom: 1px solid #eef2f8;
        padding-bottom: 0.75rem;
    }
    .dash-card-head h2 { color: var(--dash-ink); }

    /* Tables inside cards get a tinted blue→violet header. */
    .dash-table th {
        background: linear-gradient(120deg, #eef4fe, #f2edfe);
        color: #55507e;
        border-bottom: 1px solid #e0e7f3;
    }
    .dash-table tbody tr:hover td { background: color-mix(in srgb, var(--dash-blue) 7%, #ffffff); }

    /* Status badges: a touch more saturated. */
    .dash-status-badge.active   { background: #dbeafe; border-color: #bfdbfe; }
    .dash-status-badge.complete { background: #dcfce7; border-color: #bbf7d0; }
    .dash-status-badge.warning  { background: #fef3c7; border-color: #fcd34d; }
    .dash-status-badge.danger   { background: #fee2e2; border-color: #fecaca; }

    /* The role badge gets a subtle gradient. */
    .dash-role-badge {
        background: linear-gradient(90deg, #fdebec, #f3e9fb);
        border-color: #f3cdd6;
    }
</style>

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
        ->pluck('customer_name')
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
                                {{ $order->customer_name }}
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

    @if ($totalOrders > 0)
        <div class="dash-chart-grid">
<div class="dash-card">
    <div class="dash-card-head">
        <div>
            <h2>Orders by status</h2>
            <p>Distribution of the orders loaded on this dashboard.</p>
        </div>
    </div>

    <div class="dash-donut-layout">
        <div
            class="dash-donut"
            style="
                background:
                conic-gradient(
                    #2d7ff0 0% {{ $stopOne }}%,
                    #e59a18 {{ $stopOne }}% {{ $stopTwo }}%,
                    #18a957 {{ $stopTwo }}% {{ $stopThree }}%,
                    #dce3ea {{ $stopThree }}% 100%
                );
            "
        >
            <div class="dash-donut-center">
                <strong>{{ $totalOrders }}</strong>
                <span>Total orders</span>
            </div>
        </div>

        <div class="dash-legend">
            <div class="dash-legend-row">
                <span class="dash-legend-dot" style="background: #2d7ff0;"></span>
                <span>In design</span>
                <strong>{{ $designCount }}</strong>
            </div>

            <div class="dash-legend-row">
                <span class="dash-legend-dot" style="background: #e59a18;"></span>
                <span>In production</span>
                <strong>{{ $productionCount }}</strong>
            </div>

            <div class="dash-legend-row">
                <span class="dash-legend-dot" style="background: #18a957;"></span>
                <span>Completed</span>
                <strong>{{ $completedCount }}</strong>
            </div>

            <div class="dash-legend-row">
                <span class="dash-legend-dot" style="background: #dce3ea;"></span>
                <span>Other</span>
                <strong>{{ $otherCount }}</strong>
            </div>
        </div>
    </div>
</div>

<div class="dash-card">
    <div class="dash-card-head">
        <div>
            <h2>Orders created</h2>
            <p>Last eight days from the orders loaded on this dashboard.</p>
        </div>
    </div>

    <div class="dash-line-chart">
        <svg
            viewBox="0 0 800 180"
            role="img"
            aria-label="Orders created during the last eight days"
        >
            <defs>
                <linearGradient
                    id="ordersArea"
                    x1="0"
                    y1="0"
                    x2="0"
                    y2="1"
                >
                    <stop
                        offset="0%"
                        stop-color="#e31b23"
                        stop-opacity="0.24"
                    />

                    <stop
                        offset="100%"
                        stop-color="#e31b23"
                        stop-opacity="0"
                    />
                </linearGradient>
            </defs>

            <line x1="20" y1="40" x2="780" y2="40" stroke="#edf1f5" />
            <line x1="20" y1="80" x2="780" y2="80" stroke="#edf1f5" />
            <line x1="20" y1="120" x2="780" y2="120" stroke="#edf1f5" />
            <line x1="20" y1="165" x2="780" y2="165" stroke="#dfe6ed" />

            <polygon
                points="{{ $areaPoints }}"
                fill="url(#ordersArea)"
            />

            <polyline
                points="{{ $linePoints }}"
                fill="none"
                stroke="#e31b23"
                stroke-width="4"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>

        <div class="dash-chart-labels">
            <span>{{ $chartDays->first()->format('M j') }}</span>
            <span>{{ $chartDays->get(3)->format('M j') }}</span>
            <span>{{ $chartDays->last()->format('M j') }}</span>
        </div>
    </div>
</div>

        </div>
    @endif

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
                                {{ $order->customer_name }}
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

    @if ($totalOrders > 0)
        <div class="dash-chart-grid">
<div class="dash-card">
    <div class="dash-card-head">
        <div>
            <h2>Orders by status</h2>
            <p>Distribution of the orders loaded on this dashboard.</p>
        </div>
    </div>

    <div class="dash-donut-layout">
        <div
            class="dash-donut"
            style="
                background:
                conic-gradient(
                    #2d7ff0 0% {{ $stopOne }}%,
                    #e59a18 {{ $stopOne }}% {{ $stopTwo }}%,
                    #18a957 {{ $stopTwo }}% {{ $stopThree }}%,
                    #dce3ea {{ $stopThree }}% 100%
                );
            "
        >
            <div class="dash-donut-center">
                <strong>{{ $totalOrders }}</strong>
                <span>Total orders</span>
            </div>
        </div>

        <div class="dash-legend">
            <div class="dash-legend-row">
                <span class="dash-legend-dot" style="background: #2d7ff0;"></span>
                <span>In design</span>
                <strong>{{ $designCount }}</strong>
            </div>

            <div class="dash-legend-row">
                <span class="dash-legend-dot" style="background: #e59a18;"></span>
                <span>In production</span>
                <strong>{{ $productionCount }}</strong>
            </div>

            <div class="dash-legend-row">
                <span class="dash-legend-dot" style="background: #18a957;"></span>
                <span>Completed</span>
                <strong>{{ $completedCount }}</strong>
            </div>

            <div class="dash-legend-row">
                <span class="dash-legend-dot" style="background: #dce3ea;"></span>
                <span>Other</span>
                <strong>{{ $otherCount }}</strong>
            </div>
        </div>
    </div>
</div>

<div class="dash-card">
    <div class="dash-card-head">
        <div>
            <h2>Orders created</h2>
            <p>Last eight days from the orders loaded on this dashboard.</p>
        </div>
    </div>

    <div class="dash-line-chart">
        <svg
            viewBox="0 0 800 180"
            role="img"
            aria-label="Orders created during the last eight days"
        >
            <defs>
                <linearGradient
                    id="ordersArea"
                    x1="0"
                    y1="0"
                    x2="0"
                    y2="1"
                >
                    <stop
                        offset="0%"
                        stop-color="#e31b23"
                        stop-opacity="0.24"
                    />

                    <stop
                        offset="100%"
                        stop-color="#e31b23"
                        stop-opacity="0"
                    />
                </linearGradient>
            </defs>

            <line x1="20" y1="40" x2="780" y2="40" stroke="#edf1f5" />
            <line x1="20" y1="80" x2="780" y2="80" stroke="#edf1f5" />
            <line x1="20" y1="120" x2="780" y2="120" stroke="#edf1f5" />
            <line x1="20" y1="165" x2="780" y2="165" stroke="#dfe6ed" />

            <polygon
                points="{{ $areaPoints }}"
                fill="url(#ordersArea)"
            />

            <polyline
                points="{{ $linePoints }}"
                fill="none"
                stroke="#e31b23"
                stroke-width="4"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>

        <div class="dash-chart-labels">
            <span>{{ $chartDays->first()->format('M j') }}</span>
            <span>{{ $chartDays->get(3)->format('M j') }}</span>
            <span>{{ $chartDays->last()->format('M j') }}</span>
        </div>
    </div>
</div>

        </div>
    @endif

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
                        {{ $order->customer_name }}
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
                                    <td>{{ $r['order']->customer_name }}</td>
                                    <td>{{ $r['station'] }}</td>
                                    <td>{{ $r['order']->productLabel() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <a href="{{ route('stations.index') }}" class="btn btn-ghost btn-sm" style="margin-top: 0.75rem;">Open station board →</a>
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
                                            {{ $task->order->customer_name ?? '' }}
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
<style>
    .dash-table tbody tr.dash-row-clickable { cursor: pointer; }
    .dash-table tbody tr.dash-row-clickable:hover { background: #fafcff; }
</style>
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