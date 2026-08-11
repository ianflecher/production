@extends('layouts.app')

@section('title', 'Finish — '.$session->stationLabel())
@section('page-title', 'Finish at '.$session->stationLabel())

@section('content')
@php
    $order = $session->order;
    $isQc = str_starts_with($session->station, 'qc_');
@endphp

<style>
    .fin-wrap { max-width: 900px; margin: 0 auto; }
    .fin-head {
        background: var(--surface); border: 1px solid var(--border-strong);
        border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: 1.1rem;
    }
    .fin-head h2 { margin: 0 0 0.2rem; font-size: 1.15rem; }
    .fin-head .meta { color: var(--ink-2); font-size: 0.85rem; }
    .fin-head .what { margin: 0.6rem 0 0; font-size: 0.85rem; color: var(--ink-2); line-height: 1.5; }

    /* The question that decides whether the step closes. Deliberately hard to
       miss and deliberately not pre-answered "finished" — a job sent to QC with
       half its seams unsewn is a lot more expensive than one that waits. */
    .fin-ask {
        margin-top: 1.2rem; padding: 0.9rem 1rem;
        background: var(--surface-2); border: 1px solid var(--border-strong);
        border-radius: 10px;
    }
    .fin-ask .q { margin: 0 0 0.6rem; font-weight: 800; font-size: 0.95rem; }
    .fin-ask .opt {
        display: flex; gap: 0.6rem; align-items: flex-start;
        padding: 0.5rem 0.6rem; border-radius: 8px; cursor: pointer;
        font-size: 0.88rem; line-height: 1.45;
    }
    .fin-ask .opt:hover { background: var(--surface); }
    .fin-ask .opt input { margin-top: 0.2rem; flex-shrink: 0; width: 18px; height: 18px; }

    .fin-bar {
        display: flex; gap: 0.7rem; flex-wrap: wrap; align-items: center;
        margin-top: 1.2rem;
    }
    .fin-bar .btn { font-size: 0.95rem; padding: 0.6rem 1.1rem; }
</style>

<div class="fin-wrap">
    <div class="fin-head">
        <h2>{{ $order?->order_number ?? 'No job order' }} — {{ $order?->client?->name ?? $order?->customer_name }}</h2>
        <div class="meta">
            {{ $session->stationLabel() }} · run by <strong>{{ $session->operator() }}</strong>
            @if ($order) · {{ $order->quantity }} pcs · due {{ $order->due_date?->format('M j, Y') ?? '—' }} @endif
        </div>
        <p class="what">
            @if ($isQc)
                Write what you found in <strong>Notes from QC</strong> on the sheet below.
            @else
                Fill in <strong>your seams</strong> on the sheet below — who sewed each one
                and with what thread. A fault found later gets traced back through it.
            @endif
            <br>Anything you leave blank keeps what was already there, so filling one
            seam will not wipe another shift's work.
        </p>
    </div>

    @if ($errors->any())
        <div class="alert-error" style="margin-bottom:1rem;">
            @foreach ($errors->all() as $e){{ $e }}<br>@endforeach
        </div>
    @endif

    {{-- The job order sheet itself, with this station's own boxes live. The
         questions used to be repeated in a list underneath it, which meant
         reading the spec in one place and answering it in another, twice as
         long and easy to fill in against the wrong seam. The sheet IS the
         form. --}}
    <form method="POST" action="{{ route('stations.end', $session) }}">
        @csrf
        <input type="hidden" name="end_reason" value="done">

        @if ($order)
            @include('partials.job-order-sheet', [
                'order' => $order,
                'showMockup' => true,
                'editable' => $fields,
            ])
        @else
            <p class="muted">This run has no job order attached.</p>
        @endif

        @if (! $isQc)
            {{-- Several people sew different seams on the same job order, so
                 finishing a turn at the machine is not the same as finishing
                 the step. Ask, rather than assuming the first person to press
                 the button was the last one to work on it. --}}
            <div class="fin-ask">
                <p class="q">Is there another seam still to sew on this job order?</p>
                <label class="opt">
                    <input type="radio" name="more_seams" value="1" checked>
                    <span><strong>Yes — someone else still has seams to do.</strong>
                    Your part is saved and {{ $order?->order_number }} stays at sewing for them.</span>
                </label>
                <label class="opt">
                    <input type="radio" name="more_seams" value="0">
                    <span><strong>No — the sewing is finished.</strong>
                    The step closes and the job moves on to Quality Control.</span>
                </label>
            </div>
        @else
            <input type="hidden" name="more_seams" value="0">
        @endif

        <div class="fin-bar">
            <button class="btn btn-success">✓ Save{{ $isQc ? ' &amp; finish this step' : '' }}</button>
            <a href="{{ route('stations.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

    {{-- The same handful of people work every seam and the same thread codes go
         through all of them, so both boxes pick from one shared list. --}}
    <datalist id="dl_sheet_sewer">
        @foreach (($suggest['sewer'] ?? []) as $n)<option value="{{ $n }}"></option>@endforeach
    </datalist>
    <datalist id="dl_sheet_thread">
        @foreach (($suggest['thread'] ?? []) as $t)<option value="{{ $t }}"></option>@endforeach
    </datalist>
</div>
@endsection
