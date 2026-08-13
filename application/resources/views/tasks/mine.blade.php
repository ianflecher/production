@extends('layouts.app')

@section('title', 'My Tasks — Imprint Production')
@section('page-title', 'My Tasks')

@section('content')

<div class="page-head">
    <div class="grow">
        <h1>My tasks</h1>
    </div>
</div>


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
        <h2
            style="
                font-size: 1rem;
                margin-bottom: 0.7rem;
            "
        >
            Waiting on the account officer
        </h2>

        <div
            style="
                display: grid;
                grid-template-columns:
                    repeat(auto-fill, minmax(320px, 1fr));
                gap: 0.9rem;
            "
        >
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

                    $layoutImages = $layoutTask
                        ? $layoutTask->files->filter(
                            function ($file) {
                                return $file->isImage();
                            }
                        )
                        : collect();
                @endphp

                <div
                    class="card panel"
                    style="
                        border-left: 4px solid var(--accent);
                    "
                >
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
                            Waiting for the <strong>job order</strong>.
                        @endif
                    </p>

                    @if ($layoutImages->isNotEmpty())
                        <div
                            style="
                                display: flex;
                                flex-wrap: wrap;
                                gap: 0.5rem;
                            "
                        >
                            @foreach ($layoutImages as $file)
                                <a
                                    href="{{ route('tasks.file.view', $file) }}"
                                    title="View approved layout"
                                >
                                    <img
                                        src="{{ route('tasks.file.view', $file) }}"
                                        alt="Approved layout"
                                        class="design-preview"
                                        style="
                                            max-height: 130px;
                                            max-width: 100%;
                                            border: 1px solid var(--border);
                                            border-radius: 8px;
                                            display: block;
                                        "
                                    >
                                </a>
                            @endforeach
                        </div>
                    @elseif ($layoutTask)
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
        <h2
            style="
                font-size: 1rem;
                margin-bottom: 0.7rem;
            "
        >
            Active orders
        </h2>

        <div
            style="
                display: grid;
                grid-template-columns:
                    repeat(auto-fill, minmax(330px, 1fr));
                gap: 0.9rem;
            "
        >
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

                    // The export step's files, shown on the active order card too
                    // (not just once the whole order is finished).
                    $exportTask = $sortedTasks->first(
                        function ($task) {
                            return $task->isExportStep()
                                && $task->status === 'complete'
                                && $task->files->isNotEmpty();
                        }
                    );
                @endphp

                @if ($currentTask)
                    <a
                        href="{{ route('tasks.show', $currentTask->id) }}"
                        class="card"
                        style="
                            padding: 1.1rem 1.2rem;
                            color: inherit;
                            display: block;
                            border-left:
                                4px solid
                                {{ $hasRevision
                                    ? '#dc2626'
                                    : 'var(--accent)' }};
                        "
                    >
                        {{-- Order header --}}
                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                align-items: flex-start;
                                gap: 0.75rem;
                                margin-bottom: 0.75rem;
                            "
                        >
                            <div style="min-width: 0;">
                                <div
                                    style="
                                        color: var(--accent);
                                        font-size: 1.05rem;
                                        font-weight: 700;
                                        line-height: 1.25;
                                    "
                                >
                                    {{ $orderNumber }}
                                </div>

                                <div
                                    style="
                                        color: var(--ink-2);
                                        font-size: 0.85rem;
                                        margin-top: 0.2rem;
                                        overflow-wrap: anywhere;
                                    "
                                >
                                    {{ $order->clientName() }}

                                    @if ($order->quantity)
                                        · {{ number_format($order->quantity) }}
                                        pcs
                                    @endif
                                </div>
                            </div>

                            <div style="flex-shrink: 0;">
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

                        {{-- Progress details --}}
                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                                gap: 0.5rem;
                                margin-bottom: 0.4rem;
                                font-size: 0.75rem;
                            "
                        >
                            <span style="color: var(--ink-2);">
                                Assigned tasks
                            </span>

                            <span
                                style="
                                    color: var(--ink-3);
                                    font-variant-numeric: tabular-nums;
                                    white-space: nowrap;
                                "
                            >
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

                        {{-- Footer: due date only (the task count is already
                             shown by the "X/Y complete" progress line above). --}}
                        @if ($order->due_date)
                            <div
                                style="
                                    margin-top: 0.7rem;
                                    font-size: 0.76rem;
                                    color: var(--ink-3);
                                "
                            >
                                Due {{ $order->due_date->format('M j, Y') }}
                            </div>
                        @endif

                        {{-- Export files stay visible on the active card too. --}}
                        @if ($exportTask)
                            <div
                                style="
                                    margin-top: 0.7rem;
                                    background: var(--accent-soft);
                                    border: 1px solid #cfe0fb;
                                    border-radius: 8px;
                                    padding: 0.6rem 0.7rem;
                                "
                            >
                                <div
                                    style="
                                        color: #1d4ed8;
                                        font-size: 0.66rem;
                                        font-weight: 700;
                                        letter-spacing: 0.07em;
                                        text-transform: uppercase;
                                        margin-bottom: 0.3rem;
                                    "
                                >
                                    📤 Export files
                                </div>
                                @foreach ($exportTask->files as $ef)
                                    <div style="font-size: 0.74rem; margin-bottom: 0.2rem; overflow-wrap: anywhere;">
                                        <span style="color: var(--ink-3);">{{ $ef->label ?? 'File' }}:</span>
                                        <code style="font-family: ui-monospace, Consolas, monospace; color: var(--ink);">{{ $ef->external_path ?? $ef->original_name }}</code>
                                    </div>
                                @endforeach
                            </div>
                        @endif
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

        <div
            style="
                display: grid;
                grid-template-columns:
                    repeat(auto-fill, minmax(330px, 1fr));
                gap: 0.9rem;
                margin-top: 0.7rem;
            "
        >
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

                    /*
                     * Preview priority:
                     * 1. Final Mockup image
                     * 2. Last completed task image
                     * 3. Any available image
                     */
                    $previewImage = null;

                    if ($openTask) {
                        $previewImage = $openTask->files->first(
                            function ($file) {
                                return $file->isImage();
                            }
                        );
                    }

                    if (! $previewImage) {
                        foreach (
                            $sortedCompletedTasks->reverse()
                            as $completedTask
                        ) {
                            $previewImage = $completedTask
                                ->files
                                ->first(
                                    function ($file) {
                                        return $file->isImage();
                                    }
                                );

                            if ($previewImage) {
                                break;
                            }
                        }
                    }

                    $completedDepartments = $sortedCompletedTasks
                        ->pluck('department')
                        ->unique()
                        ->values();

                    // The export step's files stay visible after completion so the
                    // artist can look back at (or re-copy) the paths they handed over.
                    $exportTask = $sortedCompletedTasks->first(
                        function ($task) {
                            return $task->isExportStep();
                        }
                    );

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
                        class="card"
                        style="
                            padding: 1rem 1.1rem;
                            color: inherit;
                            display: block;
                            border-left:
                                4px solid var(--success-ink);
                        "
                    >
                        {{-- Completed order header --}}
                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                align-items: flex-start;
                                gap: 0.75rem;
                                margin-bottom: 0.65rem;
                            "
                        >
                            <div style="min-width: 0;">
                                <div
                                    style="
                                        color: var(--accent);
                                        font-size: 1rem;
                                        font-weight: 700;
                                        line-height: 1.3;
                                    "
                                >
                                    {{ $orderNumber }}
                                </div>

                                <div
                                    style="
                                        color: var(--ink-2);
                                        font-size: 0.82rem;
                                        margin-top: 0.15rem;
                                        overflow-wrap: anywhere;
                                    "
                                >
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

                        {{-- Export files stay visible after completion. --}}
                        @if ($exportTask && $exportTask->files->isNotEmpty())
                            <div
                                style="
                                    background: var(--accent-soft);
                                    border: 1px solid #cfe0fb;
                                    border-radius: 8px;
                                    padding: 0.65rem 0.75rem;
                                    margin-bottom: 0.7rem;
                                "
                            >
                                <div
                                    style="
                                        color: #1d4ed8;
                                        font-size: 0.67rem;
                                        font-weight: 700;
                                        letter-spacing: 0.07em;
                                        text-transform: uppercase;
                                        margin-bottom: 0.35rem;
                                    "
                                >
                                    📤 Export files
                                </div>
                                @foreach ($exportTask->files as $ef)
                                    <div style="font-size: 0.76rem; margin-bottom: 0.25rem; overflow-wrap: anywhere;">
                                        <span style="color: var(--ink-3);">{{ $ef->label ?? 'File' }}:</span>
                                        <code style="font-family: ui-monospace, Consolas, monospace; color: var(--ink);">{{ $ef->external_path ?? $ef->original_name }}</code>
                                    </div>
                                @endforeach

                                {{-- A finished order has no open step, so without
                                     this there is no way back to a path that
                                     turned out wrong or whose file has moved. --}}
                                <a href="{{ route('tasks.show', $exportTask->id) }}"
                                   style="display:inline-block; margin-top:0.35rem; font-size:0.74rem; font-weight:600;">
                                    ✎ Edit path and send again
                                </a>
                            </div>
                        @endif

                        {{-- Main preview --}}
                        @if ($previewImage)
                            <img
                                src="{{ route(
                                    'tasks.file.view',
                                    $previewImage
                                ) }}"
                                alt="{{ $orderNumber }}"
                                class="design-preview"
                                style="
                                    width: 100%;
                                    height: 180px;
                                    object-fit: contain;
                                    border: 1px solid var(--border);
                                    border-radius: 7px;
                                    display: block;
                                    margin-bottom: 0.7rem;
                                "
                            >
                        @endif

                        {{-- Completed footer --}}
                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                                gap: 0.75rem;
                                flex-wrap: wrap;
                                color: var(--ink-3);
                                font-size: 0.75rem;
                            "
                        >
                            <span>
                                ✓ {{ $completedCount }}
                                completed
                                {{ Str::plural(
                                    'task',
                                    $completedCount
                                ) }}
                            </span>

                            @if ($latestApprovedAt)
                                <span>
                                    {{ $latestApprovedAt->format(
                                        'M j, Y'
                                    ) }}
                                </span>
                            @endif
                        </div>
                    </a>
                @endif
            @endforeach
        </div>
    </details>
@endif

@endsection