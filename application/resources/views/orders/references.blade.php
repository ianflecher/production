@extends('layouts.app')

@section('title', 'Design to make — '.$order->order_number)
@section('page-title', 'Design to make')

@section('content')
@php
    $all = $order->jobOrder?->referenceFiles ?? collect();
    $note = $order->jobOrder?->reference_note;

    // What counts as "the design" is decided in one place — the step page
    // shows the same files, and two screens disagreeing about which file the
    // artist is meant to copy is the one thing neither of them may do.
    $design = $order->jobOrder?->designFiles() ?? collect();

    // Nothing drawn yet? designFiles() then shows everything, so the logo and
    // background lists would only repeat it.
    $nothingDrawnYet = $all->where('kind', 'layout')->isEmpty();

    // Logo files are needed to reproduce logos exactly; the rest — including
    // the officer's own brief material — is context for the drawing above.
    $logos = $nothingDrawnYet ? collect() : $all->where('kind', 'logo');
    $other = $nothingDrawnYet ? collect() : $all->filter(fn ($f) => ! in_array($f->kind, ['layout', 'logo'], true));
@endphp

<div class="page-head">
    <div class="grow">
        <h1>Design to make</h1>
        <p class="muted">{{ $order->order_number }} · {{ $order->clientName() }} — build the layout from the design below.</p>
    </div>
    {{-- Shown via orders.references (office) and tasks.references (artists). --}}
    @php
        $u = auth()->user();
        $backUrl = match (true) {
            $u->isSales() || $u->isLeader() => route('orders.show', $order),
            // No task list for the mover — she comes in from the conversation.
            $u->isMover() => route('messages.show', $order),
            default => route('tasks.mine'),
        };
    @endphp
    <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">← Back</a>
</div>

@if (filled($note))
    <div class="card panel" style="border-left: 4px solid var(--accent); margin-bottom: 1.4rem;">
        <strong>📝 Notes from the account officer:</strong>
        <span style="white-space: pre-line;">{{ $note }}</span>
    </div>
@endif

@if ($design->isEmpty() && $logos->isEmpty() && $other->isEmpty() && blank($note))
    <div class="card panel" style="text-align: center; padding: 2.5rem;">
        <p class="muted">Nothing uploaded for this order yet.</p>
    </div>
@endif

@if ($design->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>{{ $nothingDrawnYet ? 'Files for this order' : 'The design to make' }}</h2>
        <p class="sub" style="margin-bottom: 1rem;">
            @if ($nothingDrawnYet)
                No approved drawing on this order yet, so everything the officer put on it is here — check the notes above and ask them if unsure.
            @endif
            ⬇ Tap <strong>Download</strong> under an image to save it to your device.
        </p>
        <div style="display: flex; flex-wrap: wrap; gap: 1.2rem;">
            @foreach ($design as $ref)
                @include('partials.reference-file', ['ref' => $ref, 'width' => 300])
            @endforeach
        </div>
    </div>
@endif

@if ($logos->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Logo files</h2>
        <p class="sub" style="margin-bottom: 1rem;">Use these to reproduce the logo/s exactly — don't redraw them.</p>
        <div style="display: flex; flex-wrap: wrap; gap: 1.2rem;">
            @foreach ($logos as $ref)
                @include('partials.reference-file', ['ref' => $ref, 'width' => 220])
            @endforeach
        </div>
    </div>
@endif

@if ($other->isNotEmpty())
    <details class="card panel">
        <summary style="cursor: pointer; font-weight: 700;">Other files from the client ({{ $other->count() }})</summary>
        <p class="sub" style="margin: 0.6rem 0 1rem;">Background only — the design above is what to make.</p>
        <div style="display: flex; flex-wrap: wrap; gap: 1.2rem;">
            @foreach ($other as $ref)
                @include('partials.reference-file', ['ref' => $ref, 'width' => 220])
            @endforeach
        </div>
    </details>
@endif
@endsection
