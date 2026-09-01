@extends('layouts.app')

@section('title', 'Calendar — Imprint Production')
@section('page-title', 'Calendar')

@section('content')

@php
    // Where a job on the calendar opens.
    //
    // The order page leads with payments, pricing and the client's details —
    // the account officer's and the leader's business. Everyone else clicking a
    // job on a calendar wants the job order sheet: what is being made, in what
    // sizes, on which machine.
    $viewer = auth()->user();
    $jobLink = fn ($order) => ($viewer->isSales() || $viewer->isLeader())
        ? route('orders.show', $order)
        : route('orders.job-order', $order);
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/calendar.css') }}?v={{ filemtime(public_path('css/calendar.css')) }}">
@endpush


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

        // A day counts as full when one of its PRODUCTS is at its own ceiling.
        // On the flat total a day of 300 shirts and 300 jerseys was "over" and
        // a day of 480 shirts was not, which had it exactly backwards.
        $summaryFullest = (int) $fullestByDay->get($summaryKey, 0);

        if ($summaryFullest > 100) {
            $overCapacityDays++;
        } elseif ($summaryFullest === 100) {
            $fullDays++;
        } elseif ($summaryFullest >= 80) {
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
            <div class="calendar-kpi-note">Each product against its own daily ceiling</div>
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
                                        // Capacity is per PRODUCT, the way the order form already
                                        // refuses it. The day's headline is its fullest bench:
                                        // 300 shirts beside 300 jerseys is two half-full days'
                                        // work, not one overbooked one.
                                        $dayProducts = $productLoadByDay->get($dateKey, []);
                                        $tightest = collect($dayProducts)->sortByDesc('percent')->first();
                                        $capacityPercent = (int) $fullestByDay->get($dateKey, 0);

                                        $remaining = $tightest ? max(0, $tightest['cap'] - $tightest['qty']) : 0;
                                        $overBy = $tightest ? max(0, $tightest['qty'] - $tightest['cap']) : 0;

                                        if ($overBy > 0) {
                                            $capacityClass = 'cap-over';
                                            $capacityStatus = '+' . number_format($overBy) . ' over';
                                        } elseif ($tightest && $tightest['over']) {
                                            $capacityClass = 'cap-full';
                                            $capacityStatus = 'Full';
                                        } elseif ($capacityPercent >= 80) {
                                            $capacityClass = 'cap-warning';
                                            $capacityStatus = number_format($remaining) . ' left';
                                        } elseif ($dayQuantity > 0) {
                                            $capacityClass = 'cap-open';
                                            $capacityStatus = number_format($remaining) . ' left';
                                        } else {
                                            $capacityClass = 'cap-empty';
                                            $capacityStatus = 'Available';
                                        }

                                        // The cell can only show the tightest bench, so the
                                        // tooltip carries every one of them.
                                        $capacityTip = collect($dayProducts)
                                            ->map(fn ($line) => $line['label'] . ' ' . number_format($line['qty'])
                                                . '/' . number_format($line['cap']))
                                            ->implode(' | ');

                                        // Every order the viewer may see is rendered; the ones past the
                                        // third start hidden and the toggle below reveals them. They used
                                        // to be dropped entirely, so a day with four jobs could only ever
                                        // show three and the "+1 more" label led nowhere.
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
                                                data-tip="{{ $day->format('F j') }}: {{ $capacityTip ?: 'nothing booked' }} - {{ number_format($companyOrderCount) }} {{ Str::plural('order', $companyOrderCount) }}."
                                            >
                                                <div class="cal-capacity-top">
                                                    <span class="cal-capacity-main">
                                                        @if ($tightest)
                                                            {{ number_format($tightest['qty']) }}/{{ number_format($tightest['cap']) }} {{ Str::limit($tightest['label'], 12) }}
                                                        @else
                                                            0 pcs
                                                        @endif
                                                    </span>
                                                    <span class="cal-capacity-status">{{ $capacityStatus }}</span>
                                                </div>

                                                <div class="cal-capacity-meta">
                                                    <span>
                                                        {{ number_format($companyOrderCount) }} {{ Str::plural('order', $companyOrderCount) }}
                                                        @if (count($dayProducts) > 1)
                                                            · {{ count($dayProducts) }} products
                                                        @endif
                                                    </span>
                                                    <span>{{ $capacityPercent }}%</span>
                                                </div>

                                                <div class="cal-capacity-track" aria-hidden="true">
                                                    <div class="cal-capacity-bar" style="width: {{ $capacityPercent }}%;"></div>
                                                </div>
                                            </div>
                                        @endif

                                        <div class="cal-orders">
                                            @foreach ($dayOrders as $order)
                                                @php
                                                    $class = match (true) {
                                                        $order->status === 'complete' => 'st-complete',
                                                        $order->status === 'cancelled' => 'st-cancelled',
                                                        $order->status === 'on_hold' => 'st-on_hold',
                                                        $order->due_date->lt($today) => 'st-overdue',
                                                        default => 'st-active',
                                                    };
                                                @endphp

                                                @php $canOpen = $order->openableBy($viewer); @endphp
                                                <{{ $canOpen ? 'a' : 'span' }}
                                                    @if ($canOpen) href="{{ $jobLink($order) }}" @endif
                                                    class="cal-pill {{ $class }} {{ $canOpen ? '' : 'is-locked' }} {{ $loop->index >= 3 ? 'is-extra' : '' }}"
                                                    data-tip="{{ $order->order_number }} · {{ $order->clientName() }} · {{ number_format($order->quantity) }} pcs · due {{ $order->due_date->format('M j') }}{{ $canOpen ? '' : ' · not your job order' }}"
                                                >
                                                    {{ $order->order_number }} · {{ number_format($order->quantity) }} pcs
                                                </{{ $canOpen ? 'a' : 'span' }}>
                                            @endforeach
                                        </div>

                                        @if ($hiddenOrderCount > 0)
                                            <button
                                                type="button"
                                                class="cal-more"
                                                data-more
                                                data-count="{{ $hiddenOrderCount }}"
                                                aria-expanded="false"
                                            >+{{ $hiddenOrderCount }} more</button>
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

                            @php
                                $mobileTightest = collect($productLoadByDay->get($mobileKey, []))
                                    ->sortByDesc('percent')->first();
                            @endphp
                            <div class="mobile-capacity">
                                @if ($mobileTightest)
                                    {{ number_format($mobileTightest['qty']) }}/{{ number_format($mobileTightest['cap']) }}
                                    {{ $mobileTightest['label'] }}
                                @else
                                    0 pcs
                                @endif
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

                                    @php $canOpen = $order->openableBy($viewer); @endphp
                                    <{{ $canOpen ? 'a' : 'span' }}
                                        @if ($canOpen) href="{{ $jobLink($order) }}" @endif
                                        class="mobile-order {{ $canOpen ? '' : 'is-locked' }}"
                                        style="--order-color: {{ $mobileColor }};"
                                    >
                                        <span class="mobile-order-stripe" aria-hidden="true"></span>
                                        <span class="mobile-order-main">
                                            <strong>{{ $order->order_number }}</strong>
                                            <span>{{ $order->clientName() }} · {{ str_replace('_', ' ', ucfirst($order->status)) }}</span>
                                        </span>
                                        <span class="mobile-order-qty">{{ number_format($order->quantity) }} pcs</span>
                                    </{{ $canOpen ? 'a' : 'span' }}>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endif
            @endforeach
        </div>

        {{-- Split in two: the first set colours the order pills, the second the
             day's capacity card. Read as one list they contradicted each other —
             the same yellow meant "on hold" here and "full" there. --}}
        <div class="calendar-legend" aria-label="Calendar legend">
            <span class="legend-group">Orders</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #2563eb;"></i>On track</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #dc2626;"></i>Overdue</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #ca8a04;"></i>On hold</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #16a34a;"></i>Completed</span>

            <span class="legend-sep" aria-hidden="true"></span>

            <span class="legend-group">Capacity</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #f97316;"></i>Near capacity</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #dc2626;"></i>Full</span>
            <span class="legend-chip"><i class="legend-dot" style="--dot: #7f1d1d;"></i>Over capacity</span>
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

                        <a href="{{ $jobLink($order) }}" class="deadline-item">
                            <span class="deadline-date {{ $overdue ? 'is-overdue' : '' }}">
                                <span class="deadline-date-month">{{ $order->due_date->format('M') }}</span>
                                <span class="deadline-date-day">{{ $order->due_date->format('j') }}</span>
                            </span>

                            <span class="deadline-main">
                                <span class="deadline-order">
                                    <span class="deadline-number">{{ $order->order_number }}</span>
                                    <span class="deadline-customer">{{ $order->clientName() }}</span>
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
    // "+N more" reveals the rest of that day's orders in place, and folds them
    // back again. Delegated, so it covers every day cell in the month.
    (function () {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-more]');
            if (!button) return;

            var cell = button.closest('td');
            if (!cell) return;

            var expanded = cell.classList.toggle('cal-day-expanded');

            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            button.textContent = expanded
                ? 'Show less'
                : '+' + button.getAttribute('data-count') + ' more';
        });
    })();

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