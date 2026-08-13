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


    .fin-bar {
        display: flex; gap: 0.7rem; flex-wrap: wrap; align-items: center;
        margin-top: 1.2rem;
    }
    .fin-bar .btn { font-size: 0.95rem; padding: 0.6rem 1.1rem; }
</style>

<div class="fin-wrap">
    <div class="fin-head">
        <h2>{{ $order?->order_number ?? 'No job order' }} — {{ $order?->clientName() }}</h2>
        <div class="meta">
            {{ $session->stationLabel() }} · run by <strong>{{ $session->operator() }}</strong>
            @if ($order) · {{ $order->quantity }} pcs · due {{ $order->due_date?->format('M j, Y') ?? '—' }} @endif
        </div>
        <p class="what">
            @if ($isQc)
                Write what you found in <strong>Notes from QC</strong> on the sheet below.
            @else
                Fill in <strong>the seams</strong> on the sheet below — who sewed each one
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

        <div class="fin-bar">
            <button class="btn btn-success">✓ Finish this step</button>
            {{-- Step away without finishing. A plain link threw away whatever
                 had been typed and not yet submitted, which on a sheet of
                 twenty boxes is somebody's whole shift of typing — so this
                 saves first and leaves the clock running. --}}
            <button name="keep_working" value="1" class="btn btn-ghost">← Save &amp; keep working</button>
        </div>
    </form>

    {{-- Putting it back is a different thing from finishing it: wrong job order,
         or called away. The clock stops, the step is left exactly as it was, and
         the job returns to the queue for whoever picks it up next. Its own form,
         so it cannot be hit by pressing Enter in the sheet above. --}}
    <form method="POST" action="{{ route('stations.end', $session) }}"
          onsubmit="return confirm('Put {{ $order?->order_number }} back?

The clock stops and the step stays exactly as it is. Anything you typed above is NOT saved.');"
          style="margin-top:0.9rem;">
        @csrf
        <input type="hidden" name="end_reason" value="cancelled">
        <button class="btn btn-danger btn-sm">✕ Put this job back</button>
        <span style="font-size:0.78rem; color:var(--ink-3); margin-left:0.5rem;">
            Stops the clock and leaves the step untouched.
        </span>
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
