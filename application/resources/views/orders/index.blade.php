@extends('layouts.app')

@section('title', 'Production Orders — Imprint Production')
@section('page-title', 'Production Orders')

@section('content')

@php
    $orderCollection = method_exists($orders, 'items')
        ? collect($orders->items())
        : collect($orders);

    $totalOrders = $orderCollection->count();

    $activeOrders = $orderCollection
        ->where('status', 'active')
        ->count();

    $onHoldOrders = $orderCollection
        ->where('status', 'on_hold')
        ->count();

    $completedOrders = $orderCollection
        ->filter(fn ($order) => in_array(
            strtolower((string) $order->status),
            ['complete', 'completed'],
            true
        ))
        ->count();
@endphp

<style>
    .orders-page {
        display: grid;
        gap: 1rem;
    }

    /* =========================================
       PAGE HEADER
       ========================================= */

    .orders-page-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
    }

    .orders-page-head h1 {
        margin: 0;
    }

    .orders-page-head p {
        margin: 0.3rem 0 0;
    }

    /* =========================================
       SUMMARY BOXES
       ========================================= */

    .order-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.8rem;
    }

    .order-summary-card {
        --summary-color: #2d7ff0;
        --summary-soft: #e8f1ff;

        position: relative;
        display: flex;
        align-items: center;
        gap: 0.8rem;
        min-height: 88px;
        padding: 0.95rem 1rem;
        overflow: hidden;
        text-align: left;
        background: linear-gradient(135deg, var(--summary-soft) 0%, #ffffff 78%);
        border: 1px solid color-mix(in srgb, var(--summary-color) 28%, #ffffff);
        border-radius: 12px;
        box-shadow: 0 6px 18px color-mix(in srgb, var(--summary-color) 14%, transparent);
        cursor: pointer;
        transition:
            transform 0.15s ease,
            border-color 0.15s ease,
            box-shadow 0.15s ease;
    }

    .order-summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 11px 26px color-mix(in srgb, var(--summary-color) 22%, transparent);
    }

    .order-summary-card.is-active {
        border-color: var(--summary-color);
        box-shadow: 0 0 0 3px color-mix(
            in srgb,
            var(--summary-color) 20%,
            transparent
        );
    }

    .order-summary-card::after {
        content: "";
        position: absolute;
        right: 0;
        bottom: 0;
        left: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--summary-color), color-mix(in srgb, var(--summary-color) 55%, #ffffff));
    }

    .order-summary-card.summary-total {
        --summary-color: #2d7ff0;
        --summary-soft: #e8f1ff;
    }

    .order-summary-card.summary-active {
        --summary-color: #e31b23;
        --summary-soft: #fdebec;
    }

    .order-summary-card.summary-hold {
        --summary-color: #e59a18;
        --summary-soft: #fff4df;
    }

    .order-summary-card.summary-complete {
        --summary-color: #18a957;
        --summary-soft: #e8f7ef;
    }

    .order-summary-icon {
        display: grid;
        place-items: center;
        flex: 0 0 42px;
        width: 42px;
        height: 42px;
        color: #ffffff;
        font-size: 1rem;
        font-weight: 800;
        background: linear-gradient(135deg, color-mix(in srgb, var(--summary-color) 82%, #ffffff), var(--summary-color));
        border-radius: 11px;
        box-shadow: 0 4px 12px color-mix(in srgb, var(--summary-color) 38%, transparent);
    }

    .order-summary-label {
        color: #647890;
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.045em;
        text-transform: uppercase;
    }

    .order-summary-value {
        margin-top: 0.1rem;
        color: color-mix(in srgb, var(--summary-color) 78%, #152033);
        font-size: 1.45rem;
        font-weight: 800;
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    /* =========================================
       ORDERS CARD
       ========================================= */

    .orders-card {
        overflow: hidden;
        background: #ffffff;
        border: 1px solid #dfe6ed;
        border-radius: 11px;
        box-shadow: 0 5px 18px rgba(15, 23, 42, 0.045);
    }

    .orders-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.1rem 0.3rem;
    }

    .orders-card-header h2 {
        margin: 0;
        color: #152033;
        font-size: 1rem;
    }

    .orders-card-header p {
        margin: 0.25rem 0 0;
        color: #8290a3;
        font-size: 0.75rem;
    }

    /* =========================================
       TOOLBAR
       ========================================= */

    .orders-toolbar {
        display: grid;
        grid-template-columns: minmax(280px, 1fr) auto auto auto;
        gap: 0.65rem;
        align-items: center;
        padding: 0.85rem 1.1rem 1rem;
        border-bottom: 1px solid #e8edf2;
    }

    .orders-search {
        position: relative;
        min-width: 0;
    }

    .orders-search svg {
        position: absolute;
        top: 50%;
        left: 0.8rem;
        width: 17px;
        height: 17px;
        color: #8d9aae;
        pointer-events: none;
        transform: translateY(-50%);
    }

    .orders-search input {
        width: 100%;
        min-height: 42px;
        padding: 0.6rem 3.2rem 0.6rem 2.4rem;
        color: #253247;
        font-size: 0.84rem;
        background: #f8fafc;
        border: 1px solid #dce3ea;
        border-radius: 8px;
        outline: none;
        transition:
            background 0.15s ease,
            border-color 0.15s ease,
            box-shadow 0.15s ease;
    }

    .orders-search input:focus {
        background: #ffffff;
        border-color: #2d7ff0;
        box-shadow: 0 0 0 3px rgba(45, 127, 240, 0.12);
    }

    .orders-search-shortcut {
        position: absolute;
        top: 50%;
        right: 0.7rem;
        padding: 0.12rem 0.38rem;
        color: #8794a6;
        font-size: 0.68rem;
        background: #ffffff;
        border: 1px solid #dfe6ed;
        border-radius: 5px;
        transform: translateY(-50%);
        pointer-events: none;
    }

    .orders-filter-select {
        min-width: 155px;
        min-height: 42px;
        padding: 0.55rem 2.3rem 0.55rem 0.8rem;
        color: #344156;
        font-size: 0.8rem;
        font-weight: 600;
        background-color: #ffffff;
        border: 1px solid #dce3ea;
        border-radius: 8px;
        outline: none;
        cursor: pointer;
    }

    .orders-filter-select:focus {
        border-color: #2d7ff0;
        box-shadow: 0 0 0 3px rgba(45, 127, 240, 0.12);
    }

    .orders-clear-button {
        min-height: 42px;
        padding: 0.55rem 0.85rem;
        color: #526176;
        font-size: 0.76rem;
        font-weight: 700;
        background: #ffffff;
        border: 1px solid #dce3ea;
        border-radius: 8px;
        cursor: pointer;
    }

    .orders-clear-button:hover {
        color: #e31b23;
        border-color: #e31b23;
    }

    .orders-clear-button[hidden] {
        display: none;
    }

    .orders-count {
        min-width: 70px;
        color: #8794a6;
        font-size: 0.75rem;
        font-weight: 600;
        text-align: right;
        white-space: nowrap;
    }

    /* =========================================
       TABLE
       ========================================= */

    .orders-table-wrap {
        overflow-x: auto;
    }

    .orders-table {
        width: 100%;
        min-width: 960px;
        border-collapse: collapse;
    }

    .orders-table thead {
        background: #f8fafc;
    }

    .orders-table th {
        position: sticky;
        top: 0;
        z-index: 2;
        padding: 0.72rem 0.9rem;
        color: #8290a3;
        font-size: 0.65rem;
        font-weight: 800;
        letter-spacing: 0.045em;
        text-align: left;
        text-transform: uppercase;
        background: #f8fafc;
        border-bottom: 1px solid #dfe6ed;
        white-space: nowrap;
    }

    .orders-table td {
        padding: 0.82rem 0.9rem;
        color: #3d4b60;
        font-size: 0.79rem;
        border-bottom: 1px solid #edf1f5;
        vertical-align: middle;
    }

    .orders-table tbody tr {
        cursor: pointer;
        transition: background 0.12s ease;
    }

    .orders-table tbody tr:hover {
        background: #fafcff;
    }

    .orders-table tbody tr:focus {
        position: relative;
        z-index: 1;
        outline: 2px solid #2d7ff0;
        outline-offset: -2px;
    }

    .orders-table tbody tr:last-child td {
        border-bottom: none;
    }

    .order-number {
        color: #1264dc;
        font-weight: 750;
    }

    .order-customer {
        color: #526176;
        font-weight: 600;
    }

    .quantity-badge {
        display: inline-flex;
        align-items: center;
        min-height: 25px;
        padding: 0.2rem 0.55rem;
        color: #47566b;
        font-size: 0.72rem;
        font-weight: 750;
        background: #f1f4f7;
        border-radius: 999px;
    }

    /* =========================================
       DUE DATE
       ========================================= */

    .due-date {
        display: grid;
        gap: 0.12rem;
    }

    .due-date-main {
        color: #47566b;
        font-weight: 600;
        white-space: nowrap;
    }

    .due-date-note {
        font-size: 0.66rem;
    }

    .due-date.overdue .due-date-main,
    .due-date.overdue .due-date-note {
        color: #d92d35;
        font-weight: 700;
    }

    .due-date.soon .due-date-main,
    .due-date.soon .due-date-note {
        color: #b66c00;
        font-weight: 700;
    }

    /* =========================================
       PROGRESS
       ========================================= */

    .order-progress {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        min-width: 145px;
    }

    .order-progress-track {
        flex: 1;
        height: 7px;
        overflow: hidden;
        background: #e8edf3;
        border-radius: 999px;
    }

    .order-progress-fill {
        height: 100%;
        background: #18a957;
        border-radius: inherit;
    }

    .order-progress-text {
        color: #8290a3;
        font-size: 0.68rem;
        white-space: nowrap;
    }

    /* =========================================
       CURRENT STEP
       ========================================= */

    .current-step {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        color: #445267;
        font-size: 0.76rem;
        font-weight: 600;
    }

    .current-step.warning {
        color: #d92d35;
        font-weight: 700;
    }

    .current-step.complete {
        color: #138447;
        font-weight: 700;
    }

    /* =========================================
       EMPTY STATES
       ========================================= */

    .orders-empty {
        padding: 3rem 1rem;
        text-align: center;
    }

    .orders-empty-icon {
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        margin: 0 auto 0.8rem;
        color: #8290a3;
        font-size: 1.25rem;
        background: #f1f4f7;
        border-radius: 12px;
    }

    .orders-empty h3 {
        margin: 0;
        color: #263449;
        font-size: 0.95rem;
    }

    .orders-empty p {
        margin: 0.35rem 0 0;
        color: #8794a6;
        font-size: 0.78rem;
    }

    #ordersNoResults[hidden] {
        display: none;
    }

    /* =========================================
       RESPONSIVE
       ========================================= */

    @media (max-width: 1000px) {
        .order-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .orders-toolbar {
            grid-template-columns: minmax(0, 1fr) auto;
        }

        .orders-clear-button,
        .orders-count {
            grid-row: 2;
        }

        .orders-count {
            justify-self: end;
        }
    }

    @media (max-width: 640px) {
        .orders-page-head {
            align-items: stretch;
            flex-direction: column;
        }

        .orders-page-head .btn {
            width: 100%;
            justify-content: center;
        }

        .order-summary-grid {
            grid-template-columns: 1fr;
        }

        .orders-toolbar {
            grid-template-columns: 1fr;
        }

        .orders-filter-select,
        .orders-clear-button {
            width: 100%;
        }

        .orders-count {
            grid-row: auto;
            justify-self: start;
            text-align: left;
        }

        .orders-search-shortcut {
            display: none;
        }
    }
</style>

<div class="orders-page">

    <div class="orders-page-head">
        <div>
            <h1>Production orders</h1>

            <p class="muted">
                Track every order and its current production stage.
            </p>
        </div>

        @if (auth()->user()->canCreateOrders())
            <a
                href="{{ route('orders.create') }}"
                class="btn btn-primary"
            >
                + New order
            </a>
        @endif
    </div>

    @if ($orderCollection->isNotEmpty())
        <div class="order-summary-grid">

            <button
                type="button"
                class="order-summary-card summary-total is-active"
                data-summary-status=""
            >
                <span class="order-summary-icon">▣</span>

                <span>
                    <span class="order-summary-label">
                        Total orders
                    </span>

                    <span class="order-summary-value">
                        {{ $totalOrders }}
                    </span>
                </span>
            </button>

            <button
                type="button"
                class="order-summary-card summary-active"
                data-summary-status="active"
            >
                <span class="order-summary-icon">●</span>

                <span>
                    <span class="order-summary-label">
                        Active
                    </span>

                    <span class="order-summary-value">
                        {{ $activeOrders }}
                    </span>
                </span>
            </button>

            <button
                type="button"
                class="order-summary-card summary-hold"
                data-summary-status="on_hold"
            >
                <span class="order-summary-icon">Ⅱ</span>

                <span>
                    <span class="order-summary-label">
                        On hold
                    </span>

                    <span class="order-summary-value">
                        {{ $onHoldOrders }}
                    </span>
                </span>
            </button>

            <button
                type="button"
                class="order-summary-card summary-complete"
                data-summary-status="complete"
            >
                <span class="order-summary-icon">✓</span>

                <span>
                    <span class="order-summary-label">
                        Completed
                    </span>

                    <span class="order-summary-value">
                        {{ $completedOrders }}
                    </span>
                </span>
            </button>

        </div>
    @endif

    <div class="orders-card">

        <div class="orders-card-header">
            <div>
                <h2>Order list</h2>

                <p>
                    Search, filter, and open an order to view its details.
                </p>
            </div>
        </div>

        @if ($orderCollection->isEmpty())

            <div class="orders-empty">
                <div class="orders-empty-icon">▣</div>

                <h3>No production orders yet</h3>

                <p>
                    Create the first order to start the production pipeline.
                </p>
            </div>

        @else

            <div class="orders-toolbar">

                <div class="orders-search">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="8"/>
                        <line
                            x1="21"
                            y1="21"
                            x2="16.65"
                            y2="16.65"
                        />
                    </svg>

                    <input
                        type="search"
                        id="orderSearch"
                        placeholder="Search order number or customer"
                        autocomplete="off"
                        aria-label="Search orders"
                    >

                    <span class="orders-search-shortcut">
                        /
                    </span>
                </div>

                <select
                    id="orderStatusFilter"
                    class="orders-filter-select"
                    aria-label="Filter orders by status"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="on_hold">On hold</option>
                    <option value="complete">Complete</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <button
                    type="button"
                    id="clearOrderFilters"
                    class="orders-clear-button"
                    hidden
                >
                    Clear filters
                </button>

                <span
                    class="orders-count"
                    id="orderCount"
                    aria-live="polite"
                ></span>

            </div>

            <div class="orders-table-wrap">

                <table class="orders-table">

                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Quantity</th>
                            <th>Due date</th>
                            <th>Progress</th>
                            <th>Current step</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody id="ordersBody">

                        @foreach ($orders as $order)

                            @php
                                [$done, $total] = $order->progress();

                                $progressPercent = $total
                                    ? min(
                                        100,
                                        round(($done / $total) * 100)
                                    )
                                    : 0;

                                $current = $order->tasks->first(
                                    fn ($task) => ! in_array(
                                        strtolower(
                                            (string) $task->status
                                        ),
                                        [
                                            'complete',
                                            'completed',
                                            'cancelled',
                                        ],
                                        true
                                    )
                                );

                                $dueDate = $order->due_date;

                                $daysUntilDue = $dueDate
                                    ? now()
                                        ->startOfDay()
                                        ->diffInDays(
                                            $dueDate->copy()->startOfDay(),
                                            false
                                        )
                                    : null;

                                $isOverdue = $daysUntilDue !== null
                                    && $daysUntilDue < 0
                                    && ! $order->completed_at;

                                $isDueSoon = $daysUntilDue !== null
                                    && $daysUntilDue >= 0
                                    && $daysUntilDue <= 3
                                    && ! $order->completed_at;

                                $dueClass = $isOverdue
                                    ? 'overdue'
                                    : ($isDueSoon ? 'soon' : '');
                            @endphp

                            <tr
                                class="order-row"
                                tabindex="0"
                                role="link"
                                data-url="{{ route('orders.show', $order) }}"
                                data-status="{{ strtolower((string) $order->status) }}"
                                data-text="{{ strtolower(
                                    $order->order_number
                                    .' '
                                    .$order->customer_name
                                ) }}"
                                aria-label="Open order {{ $order->order_number }}"
                            >

                                <td>
                                    <span class="order-number">
                                        {{ $order->order_number }}
                                    </span>
                                </td>

                                <td>
                                    <span class="order-customer">
                                        {{ $order->customer_name }}
                                    </span>
                                </td>

                                <td>
                                    <span class="quantity-badge">
                                        {{ number_format($order->quantity) }}
                                        pcs
                                    </span>
                                </td>

                                <td>
                                    <div class="due-date {{ $dueClass }}">

                                        <span class="due-date-main">
                                            {{ $dueDate?->format('M j, Y') ?? '—' }}
                                        </span>

                                        @if ($isOverdue)
                                            <span class="due-date-note">
                                                {{ abs($daysUntilDue) }}
                                                {{ \Illuminate\Support\Str::plural(
                                                    'day',
                                                    abs($daysUntilDue)
                                                ) }}
                                                overdue
                                            </span>
                                        @elseif ($isDueSoon)
                                            <span class="due-date-note">
                                                @if ($daysUntilDue === 0)
                                                    Due today
                                                @else
                                                    Due in
                                                    {{ $daysUntilDue }}
                                                    {{ \Illuminate\Support\Str::plural(
                                                        'day',
                                                        $daysUntilDue
                                                    ) }}
                                                @endif
                                            </span>
                                        @endif

                                    </div>
                                </td>

                                <td>
                                    <div class="order-progress">

                                        <div class="order-progress-track">
                                            <div
                                                class="order-progress-fill"
                                                style="width: {{ $progressPercent }}%;"
                                            ></div>
                                        </div>

                                        <span class="order-progress-text">
                                            {{ $done }}/{{ $total }}
                                        </span>

                                    </div>
                                </td>

                                <td>
                                    @if ($order->completed_at)

                                        <span class="current-step complete">
                                            ✓
                                            {{ $order->completed_at->format(
                                                'M j, g:i A'
                                            ) }}
                                        </span>

                                    @elseif (
                                        in_array(
                                            $order->status,
                                            ['active', 'on_hold'],
                                            true
                                        )
                                        && $order->layoutApproved()
                                        && ! $order->hasDownpayment()
                                    )

                                        <span class="current-step warning">
                                            ⚠ Needs downpayment
                                        </span>

                                    @elseif (
                                        $current
                                        && $current->isStuckNoStaff()
                                    )

                                        <span class="current-step warning">
                                            ⚠
                                            {{ $current->department }}
                                            — no one present
                                        </span>

                                    @else

                                        <span class="current-step">
                                            {{ $current?->department ?? '—' }}
                                        </span>

                                    @endif
                                </td>

                                <td>
                                    @include('partials.status', [
                                        'status' => $order->status
                                    ])
                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

            </div>

            <div
                id="ordersNoResults"
                class="orders-empty"
                hidden
            >
                <div class="orders-empty-icon">⌕</div>

                <h3>No matching orders</h3>

                <p>
                    Change your search or clear the selected status filter.
                </p>
            </div>

        @endif

    </div>

</div>

@if ($orderCollection->isNotEmpty())
<script>
    (function () {
        'use strict';

        var search = document.getElementById('orderSearch');
        var statusFilter =
            document.getElementById('orderStatusFilter');

        var clearButton =
            document.getElementById('clearOrderFilters');

        var tableBody =
            document.getElementById('ordersBody');

        var emptyMessage =
            document.getElementById('ordersNoResults');

        var countElement =
            document.getElementById('orderCount');

        var rows = Array.prototype.slice.call(
            tableBody.querySelectorAll('.order-row')
        );

        var summaryButtons = Array.prototype.slice.call(
            document.querySelectorAll(
                '[data-summary-status]'
            )
        );

        var total = rows.length;

        function setActiveSummary(status) {
            summaryButtons.forEach(function (button) {
                button.classList.toggle(
                    'is-active',
                    button.getAttribute(
                        'data-summary-status'
                    ) === status
                );
            });
        }

        function applyFilters() {
            var query = search.value
                .trim()
                .toLowerCase();

            var status = statusFilter.value;
            var shown = 0;

            rows.forEach(function (row) {
                var rowText =
                    row.getAttribute('data-text') || '';

                var rowStatus =
                    row.getAttribute('data-status') || '';

                var matchesSearch =
                    !query ||
                    rowText.indexOf(query) !== -1;

                var matchesStatus =
                    !status ||
                    rowStatus === status;

                var visible =
                    matchesSearch && matchesStatus;

                row.hidden = !visible;

                if (visible) {
                    shown++;
                }
            });

            emptyMessage.hidden = shown !== 0;

            countElement.textContent =
                shown === total
                    ? total +
                        (total === 1
                            ? ' order'
                            : ' orders')
                    : 'Showing ' +
                        shown +
                        ' of ' +
                        total;

            clearButton.hidden =
                !query && !status;

            setActiveSummary(status);
        }

        function clearFilters() {
            search.value = '';
            statusFilter.value = '';
            applyFilters();
            search.focus();
        }

        rows.forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (
                    event.target.closest(
                        'a, button, input, select'
                    )
                ) {
                    return;
                }

                window.location.href =
                    row.getAttribute('data-url');
            });

            row.addEventListener(
                'keydown',
                function (event) {
                    if (
                        event.key === 'Enter' ||
                        event.key === ' '
                    ) {
                        event.preventDefault();

                        window.location.href =
                            row.getAttribute(
                                'data-url'
                            );
                    }
                }
            );
        });

        summaryButtons.forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    statusFilter.value =
                        button.getAttribute(
                            'data-summary-status'
                        );

                    applyFilters();
                }
            );
        });

        search.addEventListener(
            'input',
            applyFilters
        );

        statusFilter.addEventListener(
            'change',
            applyFilters
        );

        clearButton.addEventListener(
            'click',
            clearFilters
        );

        document.addEventListener(
            'keydown',
            function (event) {
                var tagName =
                    document.activeElement.tagName;

                var isTyping =
                    tagName === 'INPUT' ||
                    tagName === 'TEXTAREA' ||
                    tagName === 'SELECT';

                if (
                    event.key === '/' &&
                    !isTyping
                ) {
                    event.preventDefault();
                    search.focus();
                }

                if (
                    event.key === 'Escape' &&
                    document.activeElement === search
                ) {
                    clearFilters();
                }
            }
        );

        applyFilters();
    })();
</script>
@endif

@endsection