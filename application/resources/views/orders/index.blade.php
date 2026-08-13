@extends('layouts.app')

@section('title', 'Production Orders — Imprint Production')
@section('page-title', 'Production Orders')

@section('content')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/orders-index.css') }}?v={{ filemtime(public_path('css/orders-index.css')) }}">
@endpush


@php
    // The cards count every order the person can see; the table shows one page.
    $summaryCards = [
        ['status' => '',          'icon' => '▣', 'label' => 'Total orders', 'count' => $totalOrders,              'class' => 'summary-total'],
        ['status' => 'active',    'icon' => '●', 'label' => 'Active',       'count' => $counts['active'] ?? 0,    'class' => 'summary-active'],
        ['status' => 'on_hold',   'icon' => 'Ⅱ', 'label' => 'On hold',      'count' => $counts['on_hold'] ?? 0,   'class' => 'summary-hold'],
        ['status' => 'complete',  'icon' => '✓', 'label' => 'Completed',    'count' => $counts['complete'] ?? 0,  'class' => 'summary-complete'],
    ];

    $isFiltered = $search !== '' || $status !== '';
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

    @if ($totalOrders > 0)
        <div class="order-summary-grid">

            @foreach ($summaryCards as $card)
                <a
                    href="{{ route('orders.index', array_filter([
                        'status' => $card['status'],
                        'q' => $search,
                    ])) }}"
                    class="order-summary-card {{ $card['class'] }} {{ $status === $card['status'] ? 'is-active' : '' }}"
                    @if ($status === $card['status']) aria-current="true" @endif
                >
                    <span class="order-summary-icon">{{ $card['icon'] }}</span>

                    <span>
                        <span class="order-summary-label">
                            {{ $card['label'] }}
                        </span>

                        <span class="order-summary-value">
                            {{ number_format($card['count']) }}
                        </span>
                    </span>
                </a>
            @endforeach

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

        @if ($totalOrders === 0)

            <div class="orders-empty">
                <div class="orders-empty-icon">▣</div>

                <h3>No production orders yet</h3>

                <p>
                    Create the first order to start the production pipeline.
                </p>
            </div>

        @else

            {{-- Searching and filtering happen on the server, so they reach
                 every order ever made — not only the page on screen. --}}
            <form
                method="GET"
                action="{{ route('orders.index') }}"
                id="ordersFilterForm"
                class="orders-toolbar"
            >

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
                        name="q"
                        value="{{ $search }}"
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
                    name="status"
                    class="orders-filter-select"
                    aria-label="Filter orders by status"
                >
                    <option value="">All statuses</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="on_hold" @selected($status === 'on_hold')>On hold</option>
                    <option value="complete" @selected($status === 'complete')>Complete</option>
                    <option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
                </select>

                <button type="submit" class="btn btn-primary">
                    Search
                </button>

                @if ($isFiltered)
                    <a
                        href="{{ route('orders.index') }}"
                        class="orders-clear-button"
                    >
                        Clear filters
                    </a>
                @endif

                <span class="orders-count" aria-live="polite">
                    @if ($orders->total() === 0)
                        No matching orders
                    @elseif ($orders->hasPages())
                        Showing {{ number_format($orders->firstItem()) }}–{{ number_format($orders->lastItem()) }}
                        of {{ number_format($orders->total()) }}
                    @else
                        {{ number_format($orders->total()) }}
                        {{ \Illuminate\Support\Str::plural('order', $orders->total()) }}
                    @endif
                </span>

            </form>

        @endif

        @if ($totalOrders > 0 && $orders->isEmpty())

            <div class="orders-empty">
                <div class="orders-empty-icon">⌕</div>

                <h3>No matching orders</h3>

                <p>
                    Change your search or clear the selected status filter.
                </p>
            </div>

        @elseif ($totalOrders > 0)

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

                                // Whole days only: diffInDays returns a float, and
                                // a float 0.0 never matches the "due today" test
                                // below — which is how a job due today ended up
                                // reading "Due in 0 days".
                                $daysUntilDue = $dueDate
                                    ? (int) now()
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
                                aria-label="Open order {{ $order->order_number }}"
                            >

                                <td>
                                    <span class="order-number">
                                        {{ $order->order_number }}
                                    </span>
                                </td>

                                <td>
                                    <span class="order-customer">
                                        {{ $order->clientName() }}
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

            @if ($orders->hasPages())
                <div class="orders-pager">
                    {{ $orders->links() }}
                </div>
            @endif

        @endif

    </div>

</div>

@if ($totalOrders > 0)
<script>
    (function () {
        'use strict';

        var form = document.getElementById('ordersFilterForm');
        var search = document.getElementById('orderSearch');
        var statusFilter =
            document.getElementById('orderStatusFilter');

        var rows = Array.prototype.slice.call(
            document.querySelectorAll('.order-row')
        );

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

        // Picking a status is a whole-list question, so ask the server at once
        // rather than making the person also press Search.
        statusFilter.addEventListener(
            'change',
            function () {
                form.submit();
            }
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
                    search.value = '';
                }
            }
        );
    })();
</script>
@endif

@endsection