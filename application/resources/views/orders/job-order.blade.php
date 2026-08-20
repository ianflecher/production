@extends('layouts.app')

@section('title', 'Tech Pack '.$order->order_number)
@section('page-title', 'Tech Pack')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/tech-pack.css') }}?v={{ filemtime(public_path('css/tech-pack.css')) }}">
@endpush

@section('content')
@php
    $jo = $order->jobOrder;
@endphp


<div class="no-print">
    @include('partials.delay-alert', ['order' => $order, 'size' => 'big'])
</div>

<div class="tp-actions no-print">
    @if (auth()->user()->canCreateOrders() && $jo)
        @if ($jo->status === 'draft')
            @php
                $canSend = $order->layoutApproved()
                    && $order->hasDownpayment()
                    && $jo->isReadyToSend()
                    && $jo->referenceFiles->isNotEmpty();
                $sendBlockReason = ! $order->layoutApproved()
                    ? 'The client must approve the layout first.'
                    : (! $order->hasDownpayment()
                        ? 'Record the downpayment before sending.'
                        : (! $jo->isReadyToSend()
                            ? 'Fill in Print Type, Printer and Fabric before sending.'
                            : (! $jo->referenceFiles->isNotEmpty()
                                ? 'Upload a client reference before sending.'
                                : null)));
            @endphp
            @if ($canSend)
                <form method="POST" action="{{ route('job-orders.send', $order) }}" onsubmit="return confirm('Send this job order to the artist?');" style="margin-right: auto;">
                    @csrf
                    <button type="submit" class="btn btn-success btn-sm">📤 Send Job Order to Artist</button>
                </form>
            @else
                <span style="margin-right: auto; color: var(--danger-ink); font-weight: 600; font-size: 0.85rem;">⚠ {{ $sendBlockReason }}</span>
            @endif
            <a href="{{ route('job-orders.edit', $order) }}" class="btn btn-ghost btn-sm">✎ Edit job order</a>
        @else
            <span style="margin-right: auto; color: var(--success-ink); font-weight: 600; font-size: 0.85rem;">✓ Sent to the artist {{ $jo->sent_to_artist_at?->format('M j, g:i A') }}</span>
        @endif
        {{-- Production details stay reachable before and after sending. --}}
        <a href="{{ route('job-orders.production', $order) }}" class="btn btn-primary btn-sm">⚙ Production details</a>
    @endif
    <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨 Print</button>
    {{-- This sheet is shown to office staff (orders.job-order) AND to artists
         (tasks.job-order), so send each back somewhere they can actually open. --}}
    @php
        $u = auth()->user();

        // Send each person back somewhere they can actually use. The mover has
        // no task list — she works from the conversations, so back means the
        // thread for this job, not an empty My Tasks she has no link to.
        [$backUrl, $backLabel] = match (true) {
            $u->isSales() || $u->isLeader() => [route('orders.show', $order), 'order'],
            $u->isMover() => [route('messages.show', $order), 'messages'],
            default => [route('tasks.mine'), 'my tasks'],
        };
    @endphp
    <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">← Back to {{ $backLabel }}</a>
</div>

{{-- The artist types into the pack itself; everybody else reads it. --}}
@isset($techPackTask)
    <form method="POST" action="{{ route('tasks.tech-pack', $techPackTask->id) }}" enctype="multipart/form-data">
        @csrf
        @include('partials.tech-pack', ['order' => $order, 'editable' => true])

        <div class="tp-save no-print">
            <button class="btn btn-primary">Save tech pack</button>
            <span class="hint">
                Design name, fitting, thread, zipper, back pocket, colourways and the
                print sizes are yours to fill — the rest comes from the order.
            </span>
        </div>
    </form>
@else
    @include('partials.tech-pack', ['order' => $order])
@endisset

@php
    // The seam record lives under the tech pack rather than in it. The pack is
    // what the shop pins up: what to make and where the print goes. Who sewed
    // which seam is what the shop wrote down afterwards — production history,
    // needed, but not part of the spec.
    //
    // The floor can still correct its own boxes here: a seam typed against the
    // wrong row should not be permanent because somebody pressed Finish. Open
    // until the job order is finished; after that the sheet is a record, and
    // records do not change.
    $floorFields = \App\Http\Controllers\StationController::sheetFieldsForUser(auth()->user());
    $canCorrect = $order->jobOrder && $order->sheetStillEditable() && $floorFields !== [];
@endphp

<details class="tp-record" @if ($canCorrect) open @endif>
    <summary>Production record — seams, thread and quality check</summary>

    @if ($canCorrect)
        <form method="POST" action="{{ route('orders.sheet.update', $order) }}">
            @csrf
            @include('partials.job-order-sheet', ['order' => $order, 'editable' => $floorFields])
            <div class="no-print" style="max-width:900px; margin:0.9rem auto 0; display:flex; gap:0.7rem; align-items:center; flex-wrap:wrap;">
                <button class="btn btn-primary btn-sm">Save corrections</button>
                <span style="font-size:0.78rem; color:var(--ink-3);">
                    Sewing and QC boxes stay editable until this job order is finished.
                </span>
            </div>
        </form>
    @else
        @include('partials.job-order-sheet', ['order' => $order])
    @endif
</details>
@endsection
