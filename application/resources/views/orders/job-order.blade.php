@extends('layouts.app')

@section('title', 'Job Order '.$order->order_number)
@section('page-title', 'Job Order')

@section('content')
@php
    $jo = $order->jobOrder;
    // Show the FINAL MOCKUP once the artist has made it (that happens after the
    // job order is sent). Before that exists, fall back to the approved LAYOUT so
    // the sheet always shows the current design instead of a blank box.
    $mockupTask = $order->tasks->firstWhere('department', 'Final mockup');
    $layoutTask = $order->tasks->firstWhere('department', 'Layout');
    $imgTask = ($mockupTask && $mockupTask->files->isNotEmpty()) ? $mockupTask : $layoutTask;
    $artistName = optional($order->tasks->first(fn ($t) => $t->team === \App\Models\User::JOB_ARTIST && $t->assignee))->assignee?->name ?? '—';
    $mockupFiles = $imgTask?->files->where('round', ($imgTask->revision_count ?? 0) + 1) ?? collect();
    $y = fn ($v) => filled($v) ? $v : '';
@endphp

<style>
    .jo-sheet { max-width: 900px; margin: 0 auto; background: #fff; color: #111; border: 2px solid #111; }
    .jo-sheet * { box-sizing: border-box; }
    .jo-title { text-align: center; padding: 0.6rem; border-bottom: 2px solid #111; }
    .jo-title .t1 { font-size: 1.6rem; font-weight: 800; letter-spacing: 0.02em; }
    .jo-title .t1 .pri { color: #d00; }
    .jo-title .t2 { font-size: 1.2rem; font-weight: 800; color: #d00; margin-top: 0.15rem; }
    table.jo { width: 100%; border-collapse: collapse; }
    table.jo td, table.jo th { border: 1px solid #111; padding: 0.3rem 0.5rem; font-size: 0.8rem; vertical-align: top; }
    .lbl { background: #cfcfcf; font-weight: 700; text-align: center; font-size: 0.72rem; text-transform: uppercase; }
    .lbl-l { background: #cfcfcf; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; }
    .yellow { background: #ffef00 !important; font-weight: 700; text-align: center; }
    .ctr { text-align: center; }
    .red { color: #d00; font-weight: 700; }
    .sec { background: #cfcfcf; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .mock-box { min-height: 150px; text-align: center; }
    .mock-box img { max-width: 100%; max-height: 260px; border: 1px solid #999; }
    .jo-actions { max-width: 900px; margin: 0 auto 1rem; display: flex; gap: 0.5rem; justify-content: flex-end; }
    @media print {
        .sidebar, .topbar, .no-print, .jo-actions { display: none !important; }
        .content { padding: 0 !important; max-width: none !important; }
        .jo-sheet { border-color: #000; }
    }
</style>

<div class="no-print">
    @include('partials.delay-alert', ['order' => $order, 'size' => 'big'])
</div>

<div class="jo-actions no-print">
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
    @php $backUrl = (auth()->user()->isSales() || auth()->user()->isLeader()) ? route('orders.show', $order) : route('tasks.mine'); @endphp
    <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">← Back</a>
</div>

@include('partials.job-order-sheet', ['order' => $order])
@endsection
