@extends('layouts.app')

@section('title', 'Design to make — '.$order->order_number)
@section('page-title', 'Design to make')

@section('content')
@php
    $all = $order->jobOrder?->referenceFiles ?? collect();
    // The ChatGPT design output is what the artist works from. Logo files are
    // needed to reproduce logos exactly; everything else is background context.
    $design = $all->where('kind', 'output');
    $logos = $all->where('kind', 'logo');
    $other = $all->filter(fn ($f) => ! in_array($f->kind, ['output', 'logo'], true));
    $note = $order->jobOrder?->reference_note;

    // If no ChatGPT design was tagged, don't bury the files — show everything as
    // the design so the artist never lands on a page with nothing to work from.
    $noDesignYet = $design->isEmpty();
    if ($noDesignYet) {
        $design = $all;
        $logos = collect();
        $other = collect();
    }
@endphp

<div class="page-head">
    <div class="grow">
        <h1>Design to make</h1>
        <p class="muted">{{ $order->order_number }} · {{ $order->customer_name }} — build the layout from the design below.</p>
    </div>
    {{-- Shown via orders.references (office) and tasks.references (artists). --}}
    @php $backUrl = (auth()->user()->isSales() || auth()->user()->isLeader()) ? route('orders.show', $order) : route('tasks.mine'); @endphp
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
        <h2>{{ $noDesignYet ? 'Files for this order' : 'The design to make' }}</h2>
        <p class="sub" style="margin-bottom: 1rem;">
            @if ($noDesignYet)
                The account officer hasn't marked a final design yet — check the notes above and ask them if unsure.
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
