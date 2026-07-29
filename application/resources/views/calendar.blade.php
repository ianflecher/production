@extends('layouts.app')

@section('title', 'Calendar — Imprint Production')
@section('page-title', 'Calendar')

@section('content')

@php
    $monthStart = $cursor->copy()->startOfMonth();
    $monthEnd = $cursor->copy()->endOfMonth();
    $monthDays = collect(
        \Carbon\CarbonPeriod::create($monthStart, $monthEnd)
    );

    $monthBookedPieces = 0;
    $monthOrderCount = 0;
    $bookedDays = 0;
    $warningDays = 0;
    $fullDays = 0;
    $overCapacityDays = 0;
    $busiestDay = null;
    $busiestQuantity = 0;

    foreach ($monthDays as $summaryDay) {
        $summaryKey = $summaryDay->toDateString();
        $summaryQuantity = (int) $quantityByDay->get($summaryKey, 0);
        $summaryOrders = (int) $orderCountByDay->get($summaryKey, 0);

        $monthBookedPieces += $summaryQuantity;
        $monthOrderCount += $summaryOrders;

        if ($summaryQuantity > 0) {
            $bookedDays++;
        }

        if ($summaryQuantity > $dailyCapacity) {
            $overCapacityDays++;
        } elseif ($summaryQuantity === $dailyCapacity && $summaryQuantity > 0) {
            $fullDays++;
        } elseif ($summaryQuantity >= 400) {
            $warningDays++;
        }

        if ($summaryQuantity > $busiestQuantity) {
            $busiestQuantity = $summaryQuantity;
            $busiestDay = $summaryDay->copy();
        }
    }

    $monthCapacity = max(1, $dailyCapacity * $monthDays->count());
    $monthUtilization = min(
        999,
        (int) round(($monthBookedPieces / $monthCapacity) * 100)
    );

    $upcomingOverdue = $upcoming->filter(
        fn ($order) => $order->due_date->lt($today)
    )->count();

    $upcomingDueSoon = $upcoming->filter(
        fn ($order) => $order->due_date->isBetween(
            $today,
            $today->copy()->addDays(7),
            true
        )
    )->count();
@endphp

<style>
    .calendar-page {
        --cal-blue: #2563eb;
        --cal-blue-soft: #eff6ff;
        --cal-violet: #7c3aed;
        --cal-green: #16a34a;
        --cal-amber: #d97706;
        --cal-red: #dc2626;
        --cal-slate: #64748b;
        display: grid;
        gap: 1rem;
    }

    .calendar-hero {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.2rem 1.35rem;
        border: 1px solid rgba(99, 102, 241, 0.16);
        border-radius: 18px;
        background:
            radial-gradient(circle at 90% 15%, rgba(124, 58, 237, 0.14), transparent 28%),
            radial-gradient(circle at 15% 110%, rgba(37, 99, 235, 0.12), transparent 36%),
            var(--surface, #fff);
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.06);
    }

    .calendar-hero::after {
        content: '';
        position: absolute;
        right: -48px;
        bottom: -68px;
        width: 180px;
        height: 180px;
        border: 28px solid rgba(99, 102, 241, 0.055);
        border-radius: 50%;
        pointer-events: none;
    }

    .calendar-title-wrap {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 0.9rem;
        min-width: 0;
    }

    .calendar-title-icon {
        flex: 0 0 auto;
        width: 46px;
        height: 46px;
        display: grid;
        place-items: center;
        border-radius: 14px;
        color: #fff;
        background: linear-gradient(135deg, var(--cal-blue), var(--cal-violet));
        box-shadow: 0 9px 22px rgba(79, 70, 229, 0.25);
    }

    .calendar-title-icon svg {
        width: 23px;
        height: 23px;
    }

    .calendar-title-copy {
        min-width: 0;
    }

    .calendar-eyebrow {
        margin: 0 0 0.18rem;
        color: var(--cal-violet);
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.11em;
        text-transform: uppercase;
    }

    .calendar-title-copy h1 {
        margin: 0;
        color: var(--ink-1);
        font-size: clamp(1.35rem, 2vw, 1.9rem);
        line-height: 1.15;
    }

    .calendar-title-copy p {
        margin: 0.35rem 0 0;
        color: var(--ink-3);
        font-size: 0.82rem;
    }

    .calendar-nav {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 0.42rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .calendar-nav .btn {
        min-height: 36px;
        border-radius: 10px;
    }

    .calendar-today-btn {
        color: #fff !important;
        background: linear-gradient(135deg, var(--cal-blue), #4f46e5) !important;
        border-color: transparent !important;
        box-shadow: 0 7px 17px rgba(37, 99, 235, 0.2);
    }

    .calendar-kpis {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 0.75rem;
    }

    .calendar-kpi {
        position: relative;
        overflow: hidden;
        min-width: 0;
        padding: 0.95rem 1rem;
        border: 1px solid var(--border);
        border-radius: 15px;
        background:
            radial-gradient(circle at 92% 8%, color-mix(in srgb, var(--kpi-color, var(--cal-blue)) 15%, transparent), transparent 34%),
            linear-gradient(145deg, color-mix(in srgb, var(--kpi-color, var(--cal-blue)) 5%, #ffffff), #ffffff 65%);
        box-shadow: 0 5px 18px color-mix(in srgb, var(--kpi-color, var(--cal-blue)) 9%, transparent);
    }

    .calendar-kpi::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 3px;
        background: var(--kpi-color, var(--cal-blue));
    }

    .calendar-kpi-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.4rem;
        color: var(--ink-3);
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.055em;
        text-transform: uppercase;
    }

    .calendar-kpi-icon {
        width: 28px;
        height: 28px;
        display: grid;
        place-items: center;
        border-radius: 9px;
        color: var(--kpi-color, var(--cal-blue));
        background: color-mix(in srgb, var(--kpi-color, var(--cal-blue)) 10%, transparent);
    }

    .calendar-kpi-icon svg {
        width: 15px;
        height: 15px;
    }

    .calendar-kpi-value {
        margin-top: 0.35rem;
        color: var(--ink-1);
        font-size: 1.35rem;
        font-weight: 800;
        line-height: 1;
    }

    .calendar-kpi-note {
        margin-top: 0.35rem;
        color: var(--ink-3);
        font-size: 0.7rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .calendar-board {
        overflow: hidden;
        padding: 0;
        border: 1px solid var(--border);
        border-radius: 18px;
        background: var(--surface, #fff);
        box-shadow: 0 6px 24px rgba(15, 23, 42, 0.055);
    }

    .calendar-board-head {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.8rem;
        padding: 0.95rem 1rem;
        border-bottom: 1px solid rgba(99, 102, 241, 0.16);
        background:
            radial-gradient(circle at 10% 10%, rgba(59, 130, 246, 0.18), transparent 32%),
            radial-gradient(circle at 88% 20%, rgba(236, 72, 153, 0.16), transparent 30%),
            linear-gradient(135deg, #f8fbff 0%, #f7f3ff 46%, #fff7fb 100%);
    }

    .calendar-board-head::after {
        content: '';
        position: absolute;
        right: -28px;
        top: -44px;
        width: 120px;
        height: 120px;
        border: 20px solid rgba(124, 58, 237, 0.05);
        border-radius: 50%;
        pointer-events: none;
    }

    .calendar-board-heading {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        min-width: 0;
        color: #667085;
        font-size: 0.78rem;
        font-weight: 700;
    }

    .calendar-board-heading strong {
        color: #172554;
        font-size: 0.83rem;
    }

    .calendar-board-icon {
        flex: 0 0 auto;
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        border-radius: 10px;
        color: #fff;
        background: linear-gradient(135deg, #2563eb, #7c3aed 55%, #ec4899);
        box-shadow: 0 7px 16px rgba(99, 102, 241, 0.22);
    }

    .calendar-board-icon svg {
        width: 15px;
        height: 15px;
    }

    .calendar-board-live {
        position: relative;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        gap: 0.38rem;
        padding: 0.34rem 0.58rem;
        border: 1px solid rgba(22, 163, 74, 0.16);
        border-radius: 999px;
        color: #15803d;
        background: rgba(240, 253, 244, 0.88);
        font-size: 0.65rem;
        font-weight: 800;
        white-space: nowrap;
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.08);
    }

    .calendar-board-live i {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.13);
    }

    .calendar-scroll {
        overflow-x: auto;
        padding: 0.55rem;
        scrollbar-width: thin;
    }

    .cal-grid {
        width: 100%;
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 6px;
    }

    .cal-grid th {
        padding: 0.5rem 0.4rem;
        border: 1px solid transparent;
        border-radius: 10px;
        font-size: 0.66rem;
        font-weight: 800;
        letter-spacing: 0.075em;
        text-align: center;
        text-transform: uppercase;
        box-shadow: 0 3px 9px rgba(15, 23, 42, 0.04);
    }

    .cal-grid th:nth-child(1) { color: #be123c; background: #fff1f2; border-color: #fecdd3; }
    .cal-grid th:nth-child(2) { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
    .cal-grid th:nth-child(3) { color: #6d28d9; background: #f5f3ff; border-color: #ddd6fe; }
    .cal-grid th:nth-child(4) { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
    .cal-grid th:nth-child(5) { color: #b45309; background: #fffbeb; border-color: #fde68a; }
    .cal-grid th:nth-child(6) { color: #0e7490; background: #ecfeff; border-color: #a5f3fc; }
    .cal-grid th:nth-child(7) { color: #c026d3; background: #fdf4ff; border-color: #f5d0fe; }

    .cal-grid td {
        position: relative;
        width: 14.285%;
        height: 174px;
        padding: 0.52rem;
        vertical-align: top;
        border: 1px solid #e7ebf2;
        border-radius: 13px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.035);
        transition: border-color 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease;
    }

    .cal-grid td:not(.cal-out):hover {
        z-index: 2;
        border-color: #cbd5e1;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.1);
        transform: translateY(-2px);
    }

    .cal-grid td:nth-child(1):not(.cal-out):not(.cal-today) { background: linear-gradient(180deg, #fff7f9, #ffffff 36%); }
    .cal-grid td:nth-child(2):not(.cal-out):not(.cal-today) { background: linear-gradient(180deg, #f6faff, #ffffff 36%); }
    .cal-grid td:nth-child(3):not(.cal-out):not(.cal-today) { background: linear-gradient(180deg, #faf8ff, #ffffff 36%); }
    .cal-grid td:nth-child(4):not(.cal-out):not(.cal-today) { background: linear-gradient(180deg, #f4fdf9, #ffffff 36%); }
    .cal-grid td:nth-child(5):not(.cal-out):not(.cal-today) { background: linear-gradient(180deg, #fffaf1, #ffffff 36%); }
    .cal-grid td:nth-child(6):not(.cal-out):not(.cal-today) { background: linear-gradient(180deg, #f2fcfd, #ffffff 36%); }
    .cal-grid td:nth-child(7):not(.cal-out):not(.cal-today) { background: linear-gradient(180deg, #fff7ff, #ffffff 36%); }

    .cal-grid td:nth-child(1):not(.cal-out) .cal-day-num { color: #be123c; background: #fff1f2; }
    .cal-grid td:nth-child(2):not(.cal-out) .cal-day-num { color: #1d4ed8; background: #eff6ff; }
    .cal-grid td:nth-child(3):not(.cal-out) .cal-day-num { color: #6d28d9; background: #f5f3ff; }
    .cal-grid td:nth-child(4):not(.cal-out) .cal-day-num { color: #047857; background: #ecfdf5; }
    .cal-grid td:nth-child(5):not(.cal-out) .cal-day-num { color: #b45309; background: #fffbeb; }
    .cal-grid td:nth-child(6):not(.cal-out) .cal-day-num { color: #0e7490; background: #ecfeff; }
    .cal-grid td:nth-child(7):not(.cal-out) .cal-day-num { color: #c026d3; background: #fdf4ff; }

    .cal-out {
        border-color: #eef1f5 !important;
        background: #f7f8fa !important;
        box-shadow: none !important;
    }

    .cal-out::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: repeating-linear-gradient(
            -45deg,
            transparent,
            transparent 6px,
            rgba(148, 163, 184, 0.025) 6px,
            rgba(148, 163, 184, 0.025) 12px
        );
        pointer-events: none;
    }

    .cal-day-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.35rem;
        min-height: 25px;
        margin-bottom: 0.38rem;
    }

    .cal-day-num {
        width: 25px;
        height: 25px;
        display: grid;
        place-items: center;
        border-radius: 8px;
        color: var(--ink-2);
        font-size: 0.76rem;
        font-weight: 800;
    }

    .cal-day-label {
        color: var(--ink-3);
        font-size: 0.58rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .cal-out .cal-day-num,
    .cal-out .cal-day-label {
        opacity: 0.38;
    }

    .cal-today {
        border-color: rgba(37, 99, 235, 0.48) !important;
        background:
            linear-gradient(180deg, rgba(37, 99, 235, 0.075), transparent 42%),
            #fff !important;
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.15), 0 8px 22px rgba(37, 99, 235, 0.08) !important;
    }

    .cal-today .cal-day-num {
        color: #fff;
        background: linear-gradient(135deg, var(--cal-blue), var(--cal-violet));
        box-shadow: 0 5px 12px rgba(79, 70, 229, 0.25);
    }

    .cal-today .cal-day-label {
        color: var(--cal-blue);
    }

    .cal-capacity {
        margin-bottom: 0.42rem;
        padding: 0.42rem 0.45rem;
        border: 1px solid currentColor;
        border-radius: 9px;
        font-size: 0.62rem;
        cursor: default;
    }

    .cal-capacity-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.3rem;
        margin-bottom: 0.18rem;
        font-weight: 800;
    }

    .cal-capacity-main {
        white-space: nowrap;
    }

    .cal-capacity-status {
        max-width: 48%;
        overflow: hidden;
        font-size: 0.57rem;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .cal-capacity-meta {
        display: flex;
        justify-content: space-between;
        gap: 0.3rem;
        margin-bottom: 0.28rem;
        opacity: 0.82;
        font-size: 0.56rem;
    }

    .cal-capacity-track {
        height: 4px;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.24);
    }

    .cal-capacity-bar {
        height: 100%;
        border-radius: inherit;
        transition: width 0.28s ease;
    }

    .cap-empty {
        color: #64748b;
        border-color: #e2e8f0;
        background: #f8fafc;
    }

    .cap-empty .cal-capacity-bar { background: #cbd5e1; }

    .cap-open {
        color: #1d4ed8;
        border-color: #bfdbfe;
        background: #eff6ff;
    }

    .cap-open .cal-capacity-bar {
        background: linear-gradient(90deg, #60a5fa, var(--cal-blue));
    }

    .cap-warning {
        color: #b45309;
        border-color: #fed7aa;
        background: #fff7ed;
    }

    .cap-warning .cal-capacity-bar {
        background: linear-gradient(90deg, #fb923c, #f97316);
    }

    .cap-full {
        color: #854d0e;
        border-color: #fde68a;
        background: #fffbeb;
    }

    .cap-full .cal-capacity-bar {
        background: linear-gradient(90deg, #facc15, #ca8a04);
    }

    .cap-over {
        color: #b91c1c;
        border-color: #fecaca;
        background: #fef2f2;
    }

    .cap-over .cal-capacity-bar {
        background: linear-gradient(90deg, #fb7185, var(--cal-red));
    }

    .cal-orders {
        display: grid;
        gap: 3px;
    }

    .cal-pill {
        position: relative;
        display: block;
        overflow: hidden;
        padding: 0.3rem 0.42rem 0.3rem 0.56rem;
        border: 1px solid transparent;
        border-radius: 8px;
        color: inherit;
        background: var(--pill-bg);
        font-size: 0.61rem;
        font-weight: 700;
        line-height: 1.2;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: nowrap;
        transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease;
    }

    .cal-pill::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 3px;
        background: var(--pill-color);
    }

    .cal-pill:hover {
        color: inherit;
        border-color: color-mix(in srgb, var(--pill-color) 25%, transparent);
        box-shadow: 0 6px 14px color-mix(in srgb, var(--pill-color) 14%, transparent);
        transform: translateX(2px);
        text-decoration: none;
    }

    .cal-pill.st-active {
        --pill-color: var(--cal-blue);
        --pill-bg: #eff6ff;
        color: #1e40af;
    }

    .cal-pill.st-overdue {
        --pill-color: var(--cal-red);
        --pill-bg: #fef2f2;
        color: #991b1b;
    }

    .cal-pill.st-complete {
        --pill-color: var(--cal-green);
        --pill-bg: #f0fdf4;
        color: #166534;
    }

    .cal-pill.st-on_hold {
        --pill-color: #ca8a04;
        --pill-bg: #fefce8;
        color: #854d0e;
    }

    .cal-pill.st-cancelled {
        --pill-color: #94a3b8;
        --pill-bg: #f8fafc;
        color: #64748b;
        text-decoration: line-through;
    }

    .cal-more {
        display: inline-flex;
        align-items: center;
        margin-top: 0.1rem;
        padding: 0.18rem 0.35rem;
        border-radius: 7px;
        color: var(--ink-3);
        background: #f8fafc;
        font-size: 0.58rem;
        font-weight: 700;
    }

    .calendar-legend {
        display: flex;
        align-items: center;
        gap: 0.48rem;
        flex-wrap: wrap;
        padding: 0.75rem 1rem 0.9rem;
        border-top: 1px solid var(--border);
    }

    .legend-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.34rem;
        padding: 0.28rem 0.48rem;
        border: 1px solid color-mix(in srgb, var(--dot) 20%, var(--border));
        border-radius: 999px;
        color: color-mix(in srgb, var(--dot) 70%, #334155);
        background: color-mix(in srgb, var(--dot) 7%, #ffffff);
        font-size: 0.64rem;
        font-weight: 700;
    }

    .legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--dot);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--dot) 12%, transparent);
    }

    .calendar-lower-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.55fr) minmax(260px, 0.65fr);
        gap: 1rem;
        align-items: start;
    }

    .deadline-card,
    .capacity-insight-card {
        overflow: hidden;
        border: 1px solid var(--border);
        border-radius: 18px;
        background: var(--surface, #fff);
        box-shadow: 0 5px 20px rgba(15, 23, 42, 0.05);
    }

    .section-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.8rem;
        padding: 1rem 1.05rem 0.8rem;
        border-bottom: 1px solid var(--border);
    }

    .section-head h2 {
        margin: 0;
        font-size: 1rem;
    }

    .section-head p {
        margin: 0.25rem 0 0;
        color: var(--ink-3);
        font-size: 0.74rem;
    }

    .section-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        height: 28px;
        padding: 0 0.45rem;
        border-radius: 9px;
        color: var(--cal-blue);
        background: var(--cal-blue-soft);
        font-size: 0.7rem;
        font-weight: 800;
    }

    .deadline-list {
        display: grid;
    }

    .deadline-item {
        display: grid;
        grid-template-columns: 62px minmax(0, 1fr) auto;
        gap: 0.75rem;
        align-items: center;
        padding: 0.72rem 1rem;
        border-bottom: 1px solid var(--border);
        color: inherit;
        text-decoration: none;
        transition: background 0.14s ease;
    }

    .deadline-item:last-child {
        border-bottom: 0;
    }

    .deadline-item:hover {
        color: inherit;
        background: #f8fafc;
        text-decoration: none;
    }

    .deadline-date {
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        text-align: center;
    }

    .deadline-date-month {
        padding: 0.2rem;
        color: #fff;
        background: var(--cal-blue);
        font-size: 0.55rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .deadline-date-day {
        padding: 0.2rem;
        color: var(--ink-1);
        font-size: 1.05rem;
        font-weight: 800;
        line-height: 1;
    }

    .deadline-date.is-overdue {
        border-color: #fecaca;
    }

    .deadline-date.is-overdue .deadline-date-month {
        background: var(--cal-red);
    }

    .deadline-main {
        min-width: 0;
    }

    .deadline-order {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        min-width: 0;
        color: var(--ink-1);
        font-size: 0.78rem;
        font-weight: 800;
    }

    .deadline-number {
        flex: 0 0 auto;
        color: var(--cal-blue);
    }

    .deadline-customer {
        overflow: hidden;
        color: var(--ink-2);
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .deadline-meta {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        flex-wrap: wrap;
        margin-top: 0.3rem;
        color: var(--ink-3);
        font-size: 0.66rem;
    }

    .deadline-qty {
        white-space: nowrap;
        color: var(--ink-2);
        font-size: 0.72rem;
        font-weight: 800;
    }

    .empty-state {
        padding: 2.2rem 1rem;
        color: var(--ink-3);
        text-align: center;
    }

    .empty-state-icon {
        width: 45px;
        height: 45px;
        display: grid;
        place-items: center;
        margin: 0 auto 0.65rem;
        border-radius: 14px;
        color: var(--cal-green);
        background: #f0fdf4;
    }

    .empty-state strong {
        display: block;
        margin-bottom: 0.2rem;
        color: var(--ink-1);
        font-size: 0.85rem;
    }

    .capacity-insights {
        display: grid;
        gap: 0.72rem;
        padding: 1rem;
    }

    .insight-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.7rem;
        padding-bottom: 0.7rem;
        border-bottom: 1px dashed var(--border);
    }

    .insight-row:last-child {
        padding-bottom: 0;
        border-bottom: 0;
    }

    .insight-label {
        color: var(--ink-3);
        font-size: 0.7rem;
    }

    .insight-value {
        color: var(--ink-1);
        font-size: 0.76rem;
        font-weight: 800;
        text-align: right;
    }

    .insight-progress {
        height: 8px;
        overflow: hidden;
        margin-top: 0.28rem;
        border-radius: 999px;
        background: #eef2f7;
    }

    .insight-progress > span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, var(--cal-blue), var(--cal-violet));
    }

    .calendar-mobile-agenda {
        display: none;
    }

    #cal-tip {
        position: fixed;
        z-index: 4000;
        max-width: 300px;
        padding: 0.58rem 0.72rem;
        border: 1px solid #e5eaf2;
        border-left: 3px solid var(--cal-blue);
        border-radius: 11px;
        color: #172033;
        background: #fff;
        box-shadow: 0 14px 35px rgba(15, 23, 42, 0.18);
        font-size: 0.72rem;
        font-weight: 650;
        line-height: 1.42;
        opacity: 0;
        pointer-events: none;
        transform: translateY(7px);
        transition: opacity 0.13s ease, transform 0.13s ease;
    }

    #cal-tip.show {
        opacity: 1;
        transform: translateY(0);
    }

    #cal-tip.tip-overdue { border-left-color: var(--cal-red); }
    #cal-tip.tip-complete { border-left-color: var(--cal-green); }
    #cal-tip.tip-on_hold { border-left-color: #ca8a04; }
    #cal-tip.tip-info { border-left-color: var(--cal-violet); }

    @media (max-width: 1180px) {
        .calendar-kpis {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .calendar-lower-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 820px) {
        .calendar-board-head {
            align-items: flex-start;
            flex-direction: column;
        }

        .calendar-board-live {
            margin-left: 2.55rem;
        }

        .calendar-hero {
            align-items: stretch;
            flex-direction: column;
        }

        .calendar-nav {
            justify-content: flex-start;
        }

        .calendar-kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .calendar-desktop {
            display: none;
        }

        .calendar-mobile-agenda {
            display: grid;
            gap: 0.55rem;
            padding: 0.65rem;
        }

        .mobile-day {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--surface, #fff);
        }

        .mobile-day.is-today {
            border-color: #93c5fd;
            box-shadow: inset 3px 0 0 var(--cal-blue);
        }

        .mobile-day-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            padding: 0.65rem 0.75rem;
            background: #f8fafc;
        }

        .mobile-date {
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }

        .mobile-date-number {
            width: 35px;
            height: 35px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            color: var(--ink-1);
            background: #fff;
            box-shadow: 0 2px 7px rgba(15, 23, 42, 0.08);
            font-weight: 800;
        }

        .mobile-day.is-today .mobile-date-number {
            color: #fff;
            background: linear-gradient(135deg, var(--cal-blue), var(--cal-violet));
        }

        .mobile-date-copy strong {
            display: block;
            color: var(--ink-1);
            font-size: 0.78rem;
        }

        .mobile-date-copy span {
            color: var(--ink-3);
            font-size: 0.64rem;
        }

        .mobile-capacity {
            color: var(--ink-2);
            font-size: 0.67rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .mobile-orders {
            display: grid;
            gap: 0.42rem;
            padding: 0.65rem 0.75rem 0.75rem;
        }

        .mobile-order {
            display: grid;
            grid-template-columns: 4px minmax(0, 1fr) auto;
            gap: 0.55rem;
            align-items: center;
            padding: 0.58rem 0.62rem;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: inherit;
            background: #fff;
            text-decoration: none;
        }

        .mobile-order:hover {
            color: inherit;
            background: #f8fafc;
            text-decoration: none;
        }

        .mobile-order-stripe {
            align-self: stretch;
            border-radius: 999px;
            background: var(--order-color);
        }

        .mobile-order-main {
            min-width: 0;
        }

        .mobile-order-main strong {
            display: block;
            color: var(--ink-1);
            font-size: 0.72rem;
        }

        .mobile-order-main span {
            display: block;
            overflow: hidden;
            margin-top: 0.15rem;
            color: var(--ink-3);
            font-size: 0.64rem;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .mobile-order-qty {
            color: var(--ink-2);
            font-size: 0.67rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .mobile-empty {
            padding: 0.8rem;
            color: var(--ink-3);
            font-size: 0.68rem;
            text-align: center;
        }

        .calendar-view-controls {
            display: none;
        }
    }

    @media (max-width: 520px) {
        .calendar-page {
            gap: 0.75rem;
        }

        .calendar-hero {
            padding: 1rem;
            border-radius: 15px;
        }

        .calendar-title-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
        }

        .calendar-title-copy p {
            display: none;
        }

        .calendar-nav {
            display: grid;
            grid-template-columns: 1fr 1.15fr 1fr;
        }

        .calendar-nav .btn {
            justify-content: center;
            width: 100%;
            padding-inline: 0.42rem;
        }

        .calendar-kpis {
            grid-template-columns: 1fr 1fr;
            gap: 0.55rem;
        }

        .calendar-kpi {
            padding: 0.78rem 0.82rem;
            border-radius: 13px;
        }

        .calendar-kpi-value {
            font-size: 1.12rem;
        }

        .calendar-kpi:nth-child(5) {
            grid-column: 1 / -1;
        }

        .deadline-item {
            grid-template-columns: 54px minmax(0, 1fr);
        }

        .deadline-qty {
            display: none;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .cal-grid td,
        .cal-pill,
        #cal-tip,
        .cal-capacity-bar {
            transition: none !important;
        }
    }
</style>

<div class="calendar-page">
    <section class="calendar-hero">
        <div class="calendar-title-wrap">
            <div class="calendar-title-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="17" rx="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                    <path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"></path>
                </svg>
            </div>

            <div class="calendar-title-copy">
                <p class="calendar-eyebrow">Production planner</p>
                <h1>{{ $cursor->format('F Y') }}</h1>
                <p>Company-wide capacity and deadlines in one monthly view.</p>
            </div>
        </div>

        <nav class="calendar-nav" aria-label="Calendar navigation">
            <a
                href="{{ route('calendar', ['month' => $prevMonth]) }}"
                class="btn btn-ghost btn-sm"
                aria-label="Previous month"
            >
                ← Prev
            </a>

            <a
                href="{{ route('calendar') }}"
                class="btn btn-sm calendar-today-btn"
            >
                Today
            </a>

            <a
                href="{{ route('calendar', ['month' => $nextMonth]) }}"
                class="btn btn-ghost btn-sm"
                aria-label="Next month"
            >
                Next →
            </a>
        </nav>
    </section>

    <section class="calendar-kpis" aria-label="Monthly production summary">
        <article class="calendar-kpi" style="--kpi-color: #2563eb;">
            <div class="calendar-kpi-label">
                <span>Booked pieces</span>
                <span class="calendar-kpi-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M7 3v6M17 3v6M5 11h14v9H5z"/></svg>
                </span>
            </div>
            <div class="calendar-kpi-value">{{ number_format($monthBookedPieces) }}</div>
            <div class="calendar-kpi-note">{{ number_format($monthOrderCount) }} company {{ Str::plural('order', $monthOrderCount) }}</div>
        </article>

        <article class="calendar-kpi" style="--kpi-color: #7c3aed;">
            <div class="calendar-kpi-label">
                <span>Month utilization</span>
                <span class="calendar-kpi-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V9M10 19V5M16 19v-7M22 19V2"/></svg>
                </span>
            </div>
            <div class="calendar-kpi-value">{{ number_format($monthUtilization) }}%</div>
            <div class="calendar-kpi-note">Based on {{ number_format($dailyCapacity) }} pcs daily capacity</div>
        </article>

        <article class="calendar-kpi" style="--kpi-color: #16a34a;">
            <div class="calendar-kpi-label">
                <span>Booked days</span>
                <span class="calendar-kpi-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
                </span>
            </div>
            <div class="calendar-kpi-value">{{ number_format($bookedDays) }}</div>
            <div class="calendar-kpi-note">of {{ $monthDays->count() }} calendar days have work</div>
        </article>

        <article class="calendar-kpi" style="--kpi-color: #f97316;">
            <div class="calendar-kpi-label">
                <span>Capacity alerts</span>
                <span class="calendar-kpi-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.3 2.9 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
                </span>
            </div>
            <div class="calendar-kpi-value">{{ number_format($warningDays + $fullDays + $overCapacityDays) }}</div>
            <div class="calendar-kpi-note">{{ $overCapacityDays }} over · {{ $fullDays }} full · {{ $warningDays }} near limit</div>
        </article>

        <article class="calendar-kpi" style="--kpi-color: #dc2626;">
            <div class="calendar-kpi-label">
                <span>Deadline alerts</span>
                <span class="calendar-kpi-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </span>
            </div>
            <div class="calendar-kpi-value">{{ number_format($upcomingOverdue + $upcomingDueSoon) }}</div>
            <div class="calendar-kpi-note">{{ $upcomingOverdue }} overdue · {{ $upcomingDueSoon }} due within 7 days</div>
        </article>
    </section>

    <section class="calendar-board">
        <div class="calendar-board-head">
            <div class="calendar-board-heading">
                <span class="calendar-board-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="5" width="18" height="16" rx="2" />
                        <path d="M16 3v4M8 3v4M3 10h18" />
                    </svg>
                </span>
                <div>
                    <strong>Monthly schedule</strong>
                    <span> · Hover a capacity card or order for details</span>
                </div>
            </div>

            <span class="calendar-board-live">
                <i aria-hidden="true"></i>
                Live production capacity
            </span>
        </div>

        <div class="calendar-desktop">
            <div class="calendar-scroll">
                <table class="cal-grid" id="productionCalendar">
                    <thead>
                        <tr>
                            <th>Sunday</th>
                            <th>Monday</th>
                            <th>Tuesday</th>
                            <th>Wednesday</th>
                            <th>Thursday</th>
                            <th>Friday</th>
                            <th>Saturday</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($weeks as $week)
                            <tr>
                                @foreach ($week as $day)
                                    @php
                                        $dateKey = $day->toDateString();
                                        $inMonth = $day->month === $cursor->month
                                            && $day->year === $cursor->year;
                                        $isToday = $day->isSameDay($today);
                                        $dayOrders = $ordersByDay->get($dateKey, collect());
                                        $dayQuantity = (int) $quantityByDay->get($dateKey, 0);
                                        $companyOrderCount = (int) $orderCountByDay->get($dateKey, 0);
                                        $remaining = $dailyCapacity - $dayQuantity;
                                        $capacityPercent = $dailyCapacity > 0
                                            ? min(100, (int) round(($dayQuantity / $dailyCapacity) * 100))
                                            : 0;

                                        if ($dayQuantity > $dailyCapacity) {
                                            $capacityClass = 'cap-over';
                                            $capacityStatus = '+' . number_format($dayQuantity - $dailyCapacity) . ' over';
                                        } elseif ($dayQuantity === $dailyCapacity && $dayQuantity > 0) {
                                            $capacityClass = 'cap-full';
                                            $capacityStatus = 'Full';
                                        } elseif ($dayQuantity >= 400) {
                                            $capacityClass = 'cap-warning';
                                            $capacityStatus = number_format($remaining) . ' left';
                                        } elseif ($dayQuantity > 0) {
                                            $capacityClass = 'cap-open';
                                            $capacityStatus = number_format($remaining) . ' left';
                                        } else {
                                            $capacityClass = 'cap-empty';
                                            $capacityStatus = 'Available';
                                        }

                                        $visibleOrders = $dayOrders->take(3);
                                        $hiddenOrderCount = max(0, $dayOrders->count() - 3);
                                    @endphp

                                    <td class="{{ $inMonth ? '' : 'cal-out' }} {{ $isToday ? 'cal-today' : '' }}">
                                        <div class="cal-day-head">
                                            <span class="cal-day-num">{{ $day->day }}</span>
                                            <span class="cal-day-label">
                                                {{ $isToday ? 'Today' : ($day->day === 1 ? $day->format('M') : '') }}
                                            </span>
                                        </div>

                                        @if ($inMonth)
                                            <div
                                                class="cal-capacity {{ $capacityClass }}"
                                                data-tip="{{ $day->format('F j') }}: {{ number_format($dayQuantity) }} of {{ number_format($dailyCapacity) }} pieces booked across {{ number_format($companyOrderCount) }} {{ Str::plural('order', $companyOrderCount) }}."
                                            >
                                                <div class="cal-capacity-top">
                                                    <span class="cal-capacity-main">
                                                        {{ number_format($dayQuantity) }}/{{ number_format($dailyCapacity) }} pcs
                                                    </span>
                                                    <span class="cal-capacity-status">{{ $capacityStatus }}</span>
                                                </div>

                                                <div class="cal-capacity-meta">
                                                    <span>{{ number_format($companyOrderCount) }} {{ Str::plural('order', $companyOrderCount) }}</span>
                                                    <span>{{ $capacityPercent }}%</span>
                                                </div>

                                                <div class="cal-capacity-track" aria-hidden="true">
                                                    <div class="cal-capacity-bar" style="width: {{ $capacityPercent }}%;"></div>
                                                </div>
                                            </div>
                                        @endif

                                        <div class="cal-orders">
                                            @foreach ($visibleOrders as $order)
                                                @php
                                                    $class = match (true) {
                                                        $order->status === 'complete' => 'st-complete',
                                                        $order->status === 'cancelled' => 'st-cancelled',
                                                        $order->status === 'on_hold' => 'st-on_hold',
                                                        $order->due_date->lt($today) => 'st-overdue',
                                                        default => 'st-active',
                                                    };
                                                @endphp

                                                <a
                                                    href="{{ route('orders.show', $order) }}"
                                                    class="cal-pill {{ $class }}"
                                                    data-tip="{{ $order->order_number }} · {{ $order->customer_name }} · {{ number_format($order->quantity) }} pcs · due {{ $order->due_date->format('M j') }}"
                                                >
                                                    {{ $order->order_number }} · {{ number_format($order->quantity) }} pcs
                                                </a>
                                            @endforeach
                                        </div>

                                        @if ($hiddenOrderCount > 0)
                                            <span class="cal-more">+{{ $hiddenOrderCount }} more visible</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="calendar-mobile-agenda">
            @foreach ($monthDays as $mobileDay)
                @php
                    $mobileKey = $mobileDay->toDateString();
                    $mobileOrders = $ordersByDay->get($mobileKey, collect());
                    $mobileQuantity = (int) $quantityByDay->get($mobileKey, 0);
                    $mobileCompanyOrders = (int) $orderCountByDay->get($mobileKey, 0);
                    $mobileIsToday = $mobileDay->isSameDay($today);
                @endphp

                @if ($mobileQuantity > 0 || $mobileOrders->isNotEmpty() || $mobileIsToday)
                    <article class="mobile-day {{ $mobileIsToday ? 'is-today' : '' }}">
                        <div class="mobile-day-head">
                            <div class="mobile-date">
                                <span class="mobile-date-number">{{ $mobileDay->day }}</span>
                                <div class="mobile-date-copy">
                                    <strong>{{ $mobileDay->format('l') }}</strong>
                                    <span>{{ $mobileDay->format('F j, Y') }}{{ $mobileIsToday ? ' · Today' : '' }}</span>
                                </div>
                            </div>

                            <div class="mobile-capacity">
                                {{ number_format($mobileQuantity) }}/{{ number_format($dailyCapacity) }} pcs
                            </div>
                        </div>

                        @if ($mobileOrders->isEmpty())
                            <div class="mobile-empty">
                                No individually visible orders for this date.
                                @if ($mobileCompanyOrders > 0)
                                    Company total: {{ $mobileCompanyOrders }} {{ Str::plural('order', $mobileCompanyOrders) }}.
                                @endif
                            </div>
                        @else
                            <div class="mobile-orders">
                                @foreach ($mobileOrders as $order)
                                    @php
                                        $mobileClass = match (true) {
                                            $order->status === 'complete' => 'complete',
                                            $order->status === 'cancelled' => 'cancelled',
                                            $order->status === 'on_hold' => 'on_hold',
                                            $order->due_date->lt($today) => 'overdue',
                                            default => 'active',
                                        };

                                        $mobileColor = match ($mobileClass) {
                                            'complete' => '#16a34a',
                                            'cancelled' => '#94a3b8',
                                            'on_hold' => '#ca8a04',
                                            'overdue' => '#dc2626',
                                            default => '#2563eb',
                                        };
                                    @endphp

                                    <a
                                        href="{{ route('orders.show', $order) }}"
                                        class="mobile-order"
                                        style="--order-color: {{ $mobileColor }};"
                                    >
                                        <span class="mobile-order-stripe" aria-hidden="true"></span>
                                        <span class="mobile-order-main">
                                            <strong>{{ $order->order_number }}</strong>
                                            <span>{{ $order->customer_name }} · {{ str_replace('_', ' ', ucfirst($order->status)) }}</span>
                                        </span>
                                        <span class="mobile-order-qty">{{ number_format($order->quantity) }} pcs</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endif
            @endforeach
        </div>

        <div class="calendar-legend" aria-label="Calendar legend">
            <span class="legend-chip"><i class="legend-dot" style="--dot: #2563eb;"></i>On track</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #dc2626;"></i>Overdue</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #ca8a04;"></i>On hold</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #16a34a;"></i>Completed</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #f97316;"></i>Near capacity</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #b91c1c;"></i>Over capacity</span>
        </div>
    </section>

    <section class="calendar-lower-grid">
        <div class="deadline-card">
            <div class="section-head">
                <div>
                    <h2>Upcoming deadlines</h2>
                    <p>Open orders due during the next 30 days.</p>
                </div>
                <span class="section-count">{{ $upcoming->count() }}</span>
            </div>

            @if ($upcoming->isEmpty())
                <div class="empty-state">
                    <div class="empty-state-icon" aria-hidden="true">✓</div>
                    <strong>No upcoming deadlines</strong>
                    <span>Your next 30 days are currently clear.</span>
                </div>
            @else
                <div class="deadline-list">
                    @foreach ($upcoming as $order)
                        @php
                            $overdue = $order->due_date->lt($today);
                        @endphp

                        <a href="{{ route('orders.show', $order) }}" class="deadline-item">
                            <span class="deadline-date {{ $overdue ? 'is-overdue' : '' }}">
                                <span class="deadline-date-month">{{ $order->due_date->format('M') }}</span>
                                <span class="deadline-date-day">{{ $order->due_date->format('j') }}</span>
                            </span>

                            <span class="deadline-main">
                                <span class="deadline-order">
                                    <span class="deadline-number">{{ $order->order_number }}</span>
                                    <span class="deadline-customer">{{ $order->customer_name }}</span>
                                </span>
                                <span class="deadline-meta">
                                    <span>{{ $overdue ? 'Overdue' : $order->due_date->diffForHumans() }}</span>
                                    <span>·</span>
                                    <span>{{ str_replace('_', ' ', ucfirst($order->status)) }}</span>
                                    <span>·</span>
                                    <span>{{ $order->due_date->format('D, M j, Y') }}</span>
                                </span>
                            </span>

                            <span class="deadline-qty">{{ number_format($order->quantity) }} pcs</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <aside class="capacity-insight-card">
            <div class="section-head">
                <div>
                    <h2>Capacity insights</h2>
                    <p>Quick read for {{ $cursor->format('F') }}.</p>
                </div>
            </div>

            <div class="capacity-insights">
                <div>
                    <div class="insight-row">
                        <span class="insight-label">Monthly utilization</span>
                        <span class="insight-value">{{ number_format($monthUtilization) }}%</span>
                    </div>
                    <div class="insight-progress" aria-label="Monthly utilization {{ $monthUtilization }} percent">
                        <span style="width: {{ min(100, $monthUtilization) }}%;"></span>
                    </div>
                </div>

                <div class="insight-row">
                    <span class="insight-label">Busiest production day</span>
                    <span class="insight-value">
                        @if ($busiestDay)
                            {{ $busiestDay->format('M j') }} · {{ number_format($busiestQuantity) }} pcs
                        @else
                            No bookings
                        @endif
                    </span>
                </div>

                <div class="insight-row">
                    <span class="insight-label">Available day capacity</span>
                    <span class="insight-value">{{ number_format(max(0, $monthCapacity - $monthBookedPieces)) }} pcs</span>
                </div>

                <div class="insight-row">
                    <span class="insight-label">Days over capacity</span>
                    <span class="insight-value" style="color: {{ $overCapacityDays > 0 ? '#dc2626' : 'inherit' }};">
                        {{ number_format($overCapacityDays) }}
                    </span>
                </div>

                <div class="insight-row">
                    <span class="insight-label">Average order size</span>
                    <span class="insight-value">
                        {{ $monthOrderCount > 0 ? number_format((int) round($monthBookedPieces / $monthOrderCount)) : 0 }} pcs
                    </span>
                </div>
            </div>
        </aside>
    </section>
</div>

<script>
    (function () {
        var tip = document.createElement('div');
        tip.id = 'cal-tip';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);

        var current = null;

        function statusClass(el) {
            var cls = el.className || '';
            if (cls.indexOf('st-overdue') > -1) return 'tip-overdue';
            if (cls.indexOf('st-complete') > -1) return 'tip-complete';
            if (cls.indexOf('st-on_hold') > -1) return 'tip-on_hold';
            if (cls.indexOf('cal-capacity') > -1) return 'tip-info';
            return 'tip-active';
        }

        function place(event) {
            var padding = 12;
            var rect = tip.getBoundingClientRect();
            var x = event.clientX + 14;
            var y = event.clientY + 16;

            if (x + rect.width + padding > window.innerWidth) {
                x = event.clientX - rect.width - 14;
            }

            if (y + rect.height + padding > window.innerHeight) {
                y = event.clientY - rect.height - 16;
            }

            tip.style.left = Math.max(padding, x) + 'px';
            tip.style.top = Math.max(padding, y) + 'px';
        }

        function showTip(el, event) {
            current = el;
            tip.textContent = el.getAttribute('data-tip');
            tip.className = statusClass(el);
            place(event);
            requestAnimationFrame(function () {
                tip.classList.add('show');
            });
        }

        function hideTip() {
            tip.classList.remove('show');
            current = null;
        }

        document.addEventListener('mouseover', function (event) {
            var el = event.target.closest('[data-tip]');
            if (!el) return;
            showTip(el, event);
        });

        document.addEventListener('mousemove', function (event) {
            if (current && current.contains(event.target)) place(event);
        });

        document.addEventListener('mouseout', function (event) {
            var el = event.target.closest('[data-tip]');
            if (el && el === current) hideTip();
        });

        window.addEventListener('scroll', hideTip, true);
        window.addEventListener('resize', hideTip);
    })();
</script>

@endsection