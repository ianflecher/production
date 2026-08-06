{{-- Red alert on a job that is late, or about to be.

     Shows where the job is standing right now and what picks it up next, so
     whoever sees it knows who to chase without opening the pipeline. --}}
@php
    $state = $order->delayState();
    // 'big' is for the pages the floor actually works from, where it has to be
    // readable from arm's length.
    $big = ($size ?? null) === 'big';
@endphp

@if ($state)
    @php
        $late = $state === 'delayed';
        $days = $order->daysLate();
    @endphp
    <div @class([
        'delay-alert',
        'is-late' => $late,
        'is-at-risk' => ! $late,
        'is-big' => $big,
    ]) role="alert">
        <div class="delay-alert-head">
            <span class="delay-alert-dot" aria-hidden="true"></span>
            <strong>{{ $order->delayLabel() }}</strong>
            <span class="delay-alert-when">
                @if ($late)
                    due {{ $order->due_date->format('M j') }} &mdash;
                    {{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} past the deadline
                @else
                    due <strong>today</strong> and still on the floor
                @endif
            </span>
        </div>

        <div class="delay-alert-where">
            <span class="delay-alert-step">
                <span class="delay-alert-lbl">Now at</span>
                <strong>{{ $order->currentStepLabel() }}</strong>
                @if ($who = $order->currentStep()?->assignee?->name)
                    <span class="delay-alert-who">&mdash; {{ $who }}</span>
                @endif
            </span>

            @if ($next = $order->nextStepLabel())
                <span class="delay-alert-arrow" aria-hidden="true">&rarr;</span>
                <span class="delay-alert-step">
                    <span class="delay-alert-lbl">Next</span>
                    <strong>{{ $next }}</strong>
                </span>
            @else
                <span class="delay-alert-arrow" aria-hidden="true">&rarr;</span>
                <span class="delay-alert-step"><span class="delay-alert-lbl">Next</span> <strong>Done</strong></span>
            @endif
        </div>
    </div>
@endif
