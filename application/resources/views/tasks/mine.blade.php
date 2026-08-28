@extends('layouts.app')

@section('title', 'My Tasks — Imprint Production')
@section('page-title', 'My Tasks')

@section('content')

{{-- The cards live in a grid, so every card in a row is stretched to the
     tallest one. Their insides were not stretched with them, so the progress
     bar, the due date and the export paths each stopped wherever that card's
     text happened to end — three cards side by side with three different
     baselines, and a gap of dead space under the short ones.

     Each card is now a column: the variable middle grows, and the progress and
     footer are pushed to the bottom, so they line up straight across a row
     whatever is above them. --}}
<style>
    .mt-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
        gap: 0.9rem;
        align-items: stretch;
    }
    .mt-card {
        display: flex;
        flex-direction: column;
        height: 100%;
        padding: 1.1rem 1.2rem;
        color: inherit;
    }
    /* Everything from here down sits against the bottom of the card. */
    .mt-foot { margin-top: auto; padding-top: 0.7rem; }

    .mt-section-title { font-size: 1rem; margin-bottom: 0.7rem; }

    /* Order number and client, kept from pushing the badge off the row. */
    .mt-head {
        display: flex; justify-content: space-between;
        align-items: flex-start; gap: 0.75rem; margin-bottom: 0.75rem;
    }
    .mt-head-badge { flex-shrink: 0; }
    .mt-num { color: var(--accent); font-size: 1.05rem; font-weight: 700; line-height: 1.25; }
    .mt-client { color: var(--ink-2); font-size: 0.85rem; margin-top: 0.2rem; overflow-wrap: anywhere; }

    /* Counts line up as columns of digits rather than drifting with the text. */
    .mt-meta {
        display: flex; justify-content: space-between; align-items: center;
        gap: 0.5rem; font-size: 0.75rem; margin-bottom: 0.4rem;
    }
    .mt-meta .num { color: var(--ink-3); font-variant-numeric: tabular-nums; white-space: nowrap; }

    @media (max-width: 560px) {
        .mt-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="page-head">
    <div class="grow">
        <h1>My tasks</h1>
    </div>
</div>

@include('partials.list-search', [
    'action' => route('tasks.mine'),
    'value' => $search ?? '',
    'placeholder' => 'Search order number or client',
    'label' => 'Search my tasks',
])


{{-- =========================================================
     EMPTY STATE
========================================================= --}}
@if ($orders->isEmpty() && $waiting->isEmpty() && $completed->isEmpty())
    <div
        class="card panel"
        style="
            text-align: center;
            padding: 2.5rem;
        "
    >
        <p class="muted">Nothing assigned to you yet.</p>
    </div>
@endif


{{-- =========================================================
     WAITING FOR ACCOUNT OFFICER
========================================================= --}}
@if ($waiting->isNotEmpty())
    <section style="margin-bottom: 1.8rem;">
        <h2 class="mt-section-title">Waiting on the account officer</h2>

        <div class="mt-grid">
            @foreach ($waiting as $order)
                @php
                    $layoutTask = $order->tasks->first(
                        function ($task) {
                            return $task->status === 'complete'
                                && (
                                    str_starts_with(
                                        $task->department,
                                        'Layout'
                                    )
                                    || str_starts_with(
                                        $task->department,
                                        'Design'
                                    )
                                );
                        }
                    );

                @endphp

                <div class="card panel mt-card" style="border-left: 4px solid var(--accent);">
                    <div
                        style="
                            display: flex;
                            align-items: baseline;
                            gap: 0.5rem;
                            flex-wrap: wrap;
                            margin-bottom: 0.45rem;
                        "
                    >
                        <span
                            style="
                                font-weight: 700;
                                color: var(--accent);
                            "
                        >
                            {{ $order->order_number }}
                        </span>

                        <span
                            style="
                                color: var(--ink-2);
                                font-size: 0.85rem;
                            "
                        >
                            {{ $order->clientName() }}
                        </span>
                    </div>

                    <p
                        style="
                            font-size: 0.85rem;
                            color: var(--ink-2);
                            line-height: 1.5;
                            margin: 0 0 0.7rem;
                        "
                    >
                        ✓ Layout approved.
                        @if (! $order->hasDownpayment())
                            Waiting for <strong>downpayment</strong>.
                        @else
                            {{-- The job order SHEET is gone: the officer fills
                                 their half of the tech pack and sends that.
                                 Naming a document nobody can open any more
                                 left the artist waiting on nothing. --}}
                            Waiting for the <strong>tech pack</strong>.
                        @endif
                    </p>

                    {{-- The approved layouts were shown as thumbnails here, at
                         whatever height each one came out — the main reason no
                         two cards in this row were ever the same size. A link
                         to the step, where they are shown properly. --}}
                    @if ($layoutTask)
                        <a
                            href="{{ route('tasks.show', $layoutTask->id) }}"
                            class="btn btn-ghost btn-sm"
                        >
                            View my layout
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif


{{-- =========================================================
     ACTIVE TASKS
     ONE CARD PER ORDER NUMBER
========================================================= --}}
@if ($orders->isNotEmpty())
    <section style="margin-bottom: 1.8rem;">
        <h2 class="mt-section-title">Active orders</h2>

        <div class="mt-grid">
            @foreach ($orders as $orderNumber => $group)
                @php
                    $sortedTasks = $group
                        ->sortBy('sequence')
                        ->values();

                    /*
                     * Current task priority:
                     * 1. Revision required
                     * 2. In progress
                     * 3. Ready
                     * 4. Submitted
                     * 5. Any unfinished task
                     * 6. Last task as fallback
                     */
                    $currentTask = $sortedTasks->first(
                        function ($task) {
                            return $task->status === 'revision_required';
                        }
                    );

                    if (! $currentTask) {
                        $currentTask = $sortedTasks->first(
                            function ($task) {
                                return $task->status === 'in_progress';
                            }
                        );
                    }

                    if (! $currentTask) {
                        $currentTask = $sortedTasks->first(
                            function ($task) {
                                return $task->status === 'ready';
                            }
                        );
                    }

                    if (! $currentTask) {
                        $currentTask = $sortedTasks->first(
                            function ($task) {
                                return $task->status === 'submitted';
                            }
                        );
                    }

                    if (! $currentTask) {
                        $currentTask = $sortedTasks->first(
                            function ($task) {
                                return $task->status !== 'complete';
                            }
                        );
                    }

                    if (! $currentTask) {
                        $currentTask = $sortedTasks->last();
                    }

                    $order = $group->first()->order;

                    $totalTasks = $sortedTasks->count();

                    $completedTasks = $sortedTasks
                        ->filter(
                            function ($task) {
                                return $task->status === 'complete';
                            }
                        )
                        ->count();

                    $progress = $totalTasks > 0
                        ? round(
                            ($completedTasks / $totalTasks) * 100
                        )
                        : 0;

                    $hasRevision = $sortedTasks->contains(
                        function ($task) {
                            return $task->status === 'revision_required';
                        }
                    );

                    $hasInProgress = $sortedTasks->contains(
                        function ($task) {
                            return $task->status === 'in_progress';
                        }
                    );

                    $hasReady = $sortedTasks->contains(
                        function ($task) {
                            return $task->status === 'ready';
                        }
                    );

                    $departments = $sortedTasks
                        ->pluck('department')
                        ->unique()
                        ->values();

                @endphp

                @if ($currentTask)
                    <a
                        href="{{ route('tasks.show', $currentTask->id) }}"
                        class="card mt-card"
                        style="border-left: 4px solid {{ $hasRevision ? '#dc2626' : 'var(--accent)' }};"
                    >
                        {{-- Order header --}}
                        <div class="mt-head">
                            <div style="min-width: 0;">
                                <div class="mt-num">{{ $orderNumber }}</div>

                                <div class="mt-client">
                                    {{ $order->clientName() }}

                                    @if ($order->quantity)
                                        · {{ number_format($order->quantity) }}
                                        pcs
                                    @endif
                                </div>
                            </div>

                            <div class="mt-head-badge">
                                @if ($order->status === 'on_hold')
                                    <span
                                        class="badge"
                                        style="
                                            background: #fef9c3;
                                            color: #854d0e;
                                        "
                                    >
                                        ON HOLD
                                    </span>
                                @else
                                    @include('partials.status', [
                                        'status' => $currentTask->status
                                    ])
                                @endif
                            </div>
                        </div>

                        {{-- Current task --}}
                        <div
                            style="
                                background: var(--bg);
                                border: 1px solid var(--border);
                                border-radius: 9px;
                                padding: 0.75rem 0.8rem;
                                margin-bottom: 0.75rem;
                            "
                        >
                            <div
                                style="
                                    color: var(--ink-3);
                                    font-size: 0.68rem;
                                    font-weight: 700;
                                    letter-spacing: 0.07em;
                                    text-transform: uppercase;
                                    margin-bottom: 0.3rem;
                                "
                            >
                                Current task
                            </div>

                            <div
                                style="
                                    font-weight: 650;
                                    line-height: 1.35;
                                "
                            >
                                {{ $currentTask->sequence }}.
                                {{ $currentTask->department }}
                            </div>

                            @if ($hasRevision)
                                <div
                                    style="
                                        margin-top: 0.35rem;
                                        color: var(--danger-ink);
                                        font-size: 0.76rem;
                                        font-weight: 600;
                                    "
                                >
                                    Revision required
                                </div>
                            @elseif ($hasInProgress)
                                <div
                                    style="
                                        margin-top: 0.35rem;
                                        color: var(--accent);
                                        font-size: 0.76rem;
                                        font-weight: 600;
                                    "
                                >
                                    Work in progress
                                </div>
                            @elseif ($hasReady)
                                <div
                                    style="
                                        margin-top: 0.35rem;
                                        color: var(--success-ink);
                                        font-size: 0.76rem;
                                        font-weight: 600;
                                    "
                                >
                                    Ready to start
                                </div>
                            @endif
                        </div>

                        {{-- Revision note --}}
                        @if (
                            $currentTask->status === 'revision_required'
                            && $currentTask->revision_note
                        )
                            <div
                                style="
                                    color: var(--danger-ink);
                                    background: var(--danger-soft);
                                    border:
                                        1px solid var(--danger-border);
                                    border-radius: 8px;
                                    padding: 0.55rem 0.65rem;
                                    font-size: 0.8rem;
                                    line-height: 1.4;
                                    margin-bottom: 0.75rem;
                                "
                            >
                                ↩
                                {{ Str::limit(
                                    $currentTask->revision_note,
                                    110
                                ) }}
                            </div>
                        @endif

                        {{-- All stages --}}
                        <div
                            style="
                                color: var(--ink-2);
                                font-size: 0.77rem;
                                line-height: 1.45;
                                margin-bottom: 0.7rem;
                            "
                        >
                            <strong>Stages:</strong>
                            {{ $departments->implode(' · ') }}
                        </div>

                        {{-- Progress and everything under it hug the bottom, so
                             a row of cards shares one baseline. --}}
                        <div class="mt-foot">
                        <div class="mt-meta">
                            <span style="color: var(--ink-2);">
                                Assigned tasks
                            </span>

                            <span class="num">
                                {{ $completedTasks }}/{{ $totalTasks }}
                                complete
                            </span>
                        </div>

                        <div
                            class="progress"
                            style="width: 100%;"
                            role="progressbar"
                            aria-valuenow="{{ $progress }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="Task completion"
                        >
                            <div style="width: {{ $progress }}%;"></div>
                        </div>

                        {{-- Footer: this step's date first, then the order's.
                             The order is due weeks out and says nothing about
                             today; the step they are holding is what they are
                             working to. --}}
                        @php $mine = $currentTask ?? null; @endphp
                        @if ($mine?->due_at)
                            <div style="margin-top: 0.7rem;">
                                <span class="delay-chip {{ $mine->isOverdue() ? 'is-late' : ($mine->due_at->isToday() ? 'is-at-risk' : 'is-on-time') }}">
                                    @if ($mine->isOverdue())
                                        <span class="delay-alert-dot" aria-hidden="true"></span>
                                        DELAYED · was due {{ $mine->due_at->format('M j') }}
                                    @elseif ($mine->due_at->isToday())
                                        <span class="delay-alert-dot" aria-hidden="true"></span>
                                        DUE TODAY
                                    @else
                                        DUE {{ strtoupper($mine->due_at->format('M j')) }}
                                    @endif
                                </span>
                            </div>
                        @endif

                        @if ($order->due_date)
                            <div
                                style="
                                    margin-top: 0.45rem;
                                    font-size: 0.76rem;
                                    color: var(--ink-3);
                                "
                            >
                                Order due {{ $order->due_date->format('M j, Y') }}
                            </div>
                        @endif

                        {{-- The export paths used to be reproduced here. They are
                             long, they wrap to three lines, and they made every
                             card a different height for something the artist can
                             read on the step itself. --}}
                        </div>{{-- /.mt-foot --}}
                    </a>
                @endif
            @endforeach
        </div>
    </section>
@endif


{{-- =========================================================
     COMPLETED WORK
     ONE CARD PER ORDER NUMBER
========================================================= --}}
@if ($completed->isNotEmpty())
    @php
        $completedOrders = $completed->groupBy(
            function ($task) {
                return $task->order->order_number;
            }
        );
    @endphp

    <details
        open
        style="
            margin-top: 1.6rem;
        "
    >
        <summary
            style="
                cursor: pointer;
                font-size: 1rem;
                font-weight: 700;
                padding: 0.4rem 0;
            "
        >
            Completed orders ({{ $completedOrders->count() }})
        </summary>

        <div class="mt-grid" style="margin-top: 0.7rem;">
            @foreach ($completedOrders as $orderNumber => $tasks)
                @php
                    $sortedCompletedTasks = $tasks
                        ->sortBy('sequence')
                        ->values();

                    $order = $sortedCompletedTasks
                        ->first()
                        ->order;

                    /*
                     * Open Final Mockup when clicking the order card.
                     */
                    $openTask = $sortedCompletedTasks->first(
                        function ($task) {
                            return str_contains(
                                strtolower($task->department),
                                'final mockup'
                            );
                        }
                    );

                    if (! $openTask) {
                        $openTask = $sortedCompletedTasks->last();
                    }

                    $completedDepartments = $sortedCompletedTasks
                        ->pluck('department')
                        ->unique()
                        ->values();

                    $latestApprovedTask = $sortedCompletedTasks
                        ->filter(
                            function ($task) {
                                return ! empty($task->approved_at);
                            }
                        )
                        ->sortByDesc('approved_at')
                        ->first();

                    $latestApprovedAt = $latestApprovedTask
                        ? $latestApprovedTask->approved_at
                        : null;

                    $completedCount = $sortedCompletedTasks->count();
                @endphp

                @if ($openTask)
                    <a
                        href="{{ route('tasks.show', $openTask->id) }}"
                        class="card mt-card"
                        style="border-left: 4px solid var(--success-ink);"
                    >
                        {{-- Completed order header --}}
                        <div class="mt-head">
                            <div style="min-width: 0;">
                                <div class="mt-num" style="font-size: 1rem;">
                                    {{ $orderNumber }}
                                </div>

                                <div class="mt-client" style="font-size: 0.82rem;">
                                    {{ $order->clientName() }}

                                    @if ($order->quantity)
                                        · {{ number_format($order->quantity) }}
                                        pcs
                                    @endif
                                </div>
                            </div>

                            <span
                                class="badge"
                                style="
                                    background: var(--success-soft);
                                    color: var(--success-ink);
                                    flex-shrink: 0;
                                "
                            >
                                <span class="dot"></span>
                                COMPLETE
                            </span>
                        </div>

                        {{-- Completed stages --}}
                        <div
                            style="
                                background: var(--bg);
                                border: 1px solid var(--border);
                                border-radius: 8px;
                                padding: 0.65rem 0.75rem;
                                margin-bottom: 0.7rem;
                            "
                        >
                            <div
                                style="
                                    color: var(--ink-3);
                                    font-size: 0.67rem;
                                    font-weight: 700;
                                    letter-spacing: 0.07em;
                                    text-transform: uppercase;
                                    margin-bottom: 0.3rem;
                                "
                            >
                                Completed stages
                            </div>

                            <div
                                style="
                                    color: var(--ink-2);
                                    font-size: 0.8rem;
                                    line-height: 1.5;
                                "
                            >
                                {{ $completedDepartments->implode(' · ') }}
                            </div>
                        </div>

                        {{-- The preview image, the export paths and the link to
                             correct them were all here. The card is a summary;
                             every one of those now lives on the step behind it,
                             which opens with one click and has room to show them
                             properly. --}}

                        {{-- Completed footer, held against the bottom so the
                             count and the date line up across the row however
                             tall the preview above them turned out. --}}
                        <div class="mt-foot mt-meta" style="margin-bottom: 0; color: var(--ink-3);">
                            <span>
                                ✓ {{ $completedCount }}
                                completed
                                {{ Str::plural('task', $completedCount) }}
                            </span>

                            @if ($latestApprovedAt)
                                <span class="num">{{ $latestApprovedAt->format('M j, Y') }}</span>
                            @endif
                        </div>
                    </a>
                @endif
            @endforeach
        </div>
    </details>
@endif

@endsection