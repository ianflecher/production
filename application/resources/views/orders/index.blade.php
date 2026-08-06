@extends('layouts.app')

@section('title', 'Production Orders — Imprint Production')
@section('page-title', 'Production Orders')

@section('content')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/orders-index.css') }}?v={{ filemtime(public_path('css/orders-index.css')) }}">
@endpush


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

                                        {{-- A job that is late, or due today and
                                             still on the floor, plus where it is
                                             stuck — so the row says who to chase. --}}
                                        @if ($delay = $order->delayState())
                                            <span class="delay-chip {{ $delay === 'delayed' ? 'is-late' : 'is-at-risk' }}">
                                                <span class="delay-alert-dot" aria-hidden="true"></span>
                                                {{ $order->delayLabel() }}
                                            </span>
                                            <span class="due-date-note" style="display:block; margin-top:0.15rem;">
                                                at {{ $order->currentStepLabel() }}@if ($n = $order->nextStepLabel()) &rarr; {{ $n }}@endif
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