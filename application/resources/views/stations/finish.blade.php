@extends('layouts.app')

@section('title', 'Finish — '.$session->stationLabel())
@section('page-title', 'Finish at '.$session->stationLabel())

@section('content')
@php
    $order = $session->order;
    $jo = $order?->jobOrder;
    $isQc = str_starts_with($session->station, 'qc_');
@endphp

<style>
    /* A shop-floor screen: big targets, one column of questions, nothing to
       hunt for. The boxes are yellow like every other fill-in box on the job
       order sheet, because that is the piece of paper this replaces. */
    .fin-wrap { max-width: 900px; margin: 0 auto; }
    .fin-head {
        background: var(--surface); border: 1px solid var(--border-strong);
        border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: 1.1rem;
    }
    .fin-head h2 { margin: 0 0 0.2rem; font-size: 1.15rem; }
    .fin-head .meta { color: var(--ink-2); font-size: 0.85rem; }

    .fin-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.9rem; }
    .fin-field { display: block; }
    .fin-field .cap {
        display: block; font-size: 0.72rem; font-weight: 800; letter-spacing: .03em;
        text-transform: uppercase; color: var(--ink-2); margin-bottom: 0.25rem;
    }
    .fin-field input, .fin-field textarea {
        width: 100%; background: #ffef00; color: #111;
        border: 1px solid #111; border-radius: 5px;
        padding: 0.55rem 0.6rem; font-size: 0.95rem; font-weight: 700;
    }
    .fin-field textarea { font-weight: 400; min-height: 90px; resize: vertical; }
    .fin-field input::placeholder, .fin-field textarea::placeholder { color: #a09000; font-weight: 400; }

    .fin-bar {
        display: flex; gap: 0.7rem; flex-wrap: wrap; align-items: center;
        margin-top: 1.3rem; padding-top: 1.1rem; border-top: 1px solid var(--border);
    }
    .fin-bar .btn { font-size: 0.95rem; padding: 0.6rem 1.1rem; }

    /* The sheet itself, foldable so a sewer who knows the job can collapse it
       and get straight to the boxes. */
    .fin-sheet { margin-bottom: 1.1rem; }
    .fin-sheet > summary {
        cursor: pointer; font-weight: 800; font-size: 0.9rem;
        padding: 0.65rem 0.9rem; border-radius: 10px;
        background: var(--surface); border: 1px solid var(--border-strong);
    }
    .fin-sheet[open] > summary { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
    .fin-sheet-body {
        border: 1px solid var(--border-strong); border-top: none;
        border-radius: 0 0 10px 10px; padding: 0.9rem;
        background: var(--surface); overflow-x: auto;
    }

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
</style>

<div class="fin-wrap">
    <div class="fin-head">
        <h2>{{ $order?->order_number ?? 'No job order' }} — {{ $order?->client?->name ?? $order?->customer_name }}</h2>
        <div class="meta">
            {{ $session->stationLabel() }} · run by <strong>{{ $session->operator() }}</strong>
            @if ($order) · {{ $order->quantity }} pcs · due {{ $order->due_date?->format('M j, Y') ?? '—' }} @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="alert-error" style="margin-bottom:1rem;">
            @foreach ($errors->all() as $e){{ $e }}<br>@endforeach
        </div>
    @endif

    {{-- The job order sheet as the account officer left it. On the same screen
         as the boxes below it, because the sewer needs the spec in front of
         them while they answer — sending them off to another page to read it
         is how a job gets sewn to the wrong collar. --}}
    @if ($order)
        <details class="fin-sheet" open>
            <summary>📋 Job order sheet — {{ $order->order_number }}</summary>
            <div class="fin-sheet-body">
                @include('partials.job-order-sheet', ['order' => $order, 'showMockup' => true])
            </div>
        </details>
    @endif

    <form method="POST" action="{{ route('stations.end', $session) }}">
        @csrf
        <input type="hidden" name="end_reason" value="done">

        <div class="card panel">
            <h2 style="margin-top:0;">{{ $isQc ? 'Notes from QC' : 'Sewers and threads' }}</h2>
            <p class="sub">
                @if ($isQc)
                    What did you find? This prints on the job order sheet, so whoever
                    picks the job up next can read it.
                @else
                    Who sewed each seam and with what thread. This prints on the job
                    order sheet, so a fault found later can be traced back.
                @endif
                <br><strong>Anything you leave blank keeps what was already there</strong> —
                filling one seam will not wipe another shift's work.
            </p>

            @if ($fields === [])
                <p class="muted">This station has nothing to record on the sheet.</p>
            @else
                <div class="fin-grid">
                    @foreach ($fields as $f)
                        @php $isNote = str_contains($f, 'notes'); @endphp
                        <label class="fin-field" @if ($isNote) style="grid-column: 1 / -1;" @endif>
                            <span class="cap">{{ \App\Http\Controllers\StationController::sheetFieldLabel($f) }}</span>
                            @if ($isNote)
                                <textarea name="sheet[{{ $f }}]" maxlength="1000"
                                          placeholder="{{ $isQc ? 'Anything the next person should know' : 'Anything worth flagging' }}">{{ $jo?->$f }}</textarea>
                            @else
                                <input type="text" name="sheet[{{ $f }}]" maxlength="1000"
                                       value="{{ $jo?->$f }}"
                                       list="{{ str_contains($f, 'thread') ? 'dl_fin_thread' : (str_contains($f, 'sewer') ? 'dl_fin_sewer' : '') }}"
                                       placeholder="—" autocomplete="off">
                            @endif
                        </label>
                    @endforeach
                </div>
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
        </div>
    </form>

    <datalist id="dl_fin_sewer">
        @foreach (($suggest['sewer'] ?? []) as $n)<option value="{{ $n }}"></option>@endforeach
    </datalist>
    <datalist id="dl_fin_thread">
        @foreach (($suggest['thread'] ?? []) as $t)<option value="{{ $t }}"></option>@endforeach
    </datalist>
</div>
@endsection
