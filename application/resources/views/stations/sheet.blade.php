@extends('layouts.app')

@section('title', 'Job order sheet — '.$order->order_number)
@section('page-title', 'Job order sheet — '.$order->order_number)

@section('content')
<style>
    .sh-wrap { max-width: 900px; margin: 0 auto; }
    .sh-head {
        background: var(--surface); border: 1px solid var(--border-strong);
        border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: 1.1rem;
    }
    .sh-head h2 { margin: 0 0 0.2rem; font-size: 1.15rem; }
    .sh-head .meta { color: var(--ink-2); font-size: 0.85rem; }
    .sh-bar { display: flex; gap: 0.7rem; flex-wrap: wrap; align-items: center; margin-top: 1.2rem; }
    .sh-bar .btn { font-size: 0.95rem; padding: 0.6rem 1.1rem; }
</style>

<div class="sh-wrap">
    <div class="sh-head">
        <h2>{{ $order->order_number }} — {{ $order->clientName() }}</h2>
        <div class="meta">
            Correcting the sewing and QC boxes · {{ $order->quantity }} pcs ·
            due {{ $order->due_date?->format('M j, Y') ?? '—' }}
        </div>
        <p style="margin:0.6rem 0 0; font-size:0.85rem; color:var(--ink-2); line-height:1.5;">
            This job order has already gone through here. The boxes below stay open
            until the whole order is finished, so a thread code or a name noticed
            later can still be put right.
            <br><strong>Anything you leave blank keeps what was already there.</strong>
        </p>
    </div>

    @if ($errors->any())
        <div class="alert-error" style="margin-bottom:1rem;">
            @foreach ($errors->all() as $e){{ $e }}<br>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('orders.sheet.update', $order) }}">
        @csrf

        @include('partials.job-order-sheet', [
            'order' => $order,
            'showMockup' => true,
            'editable' => $fields,
        ])

        <div class="sh-bar">
            <button class="btn btn-success">✓ Save corrections</button>
            <a href="{{ route('stations.index') }}" class="btn btn-ghost">← Back to stations</a>
        </div>
    </form>

    <datalist id="dl_sheet_sewer">
        @foreach (($suggest['sewer'] ?? []) as $n)<option value="{{ $n }}"></option>@endforeach
    </datalist>
    <datalist id="dl_sheet_thread">
        @foreach (($suggest['thread'] ?? []) as $t)<option value="{{ $t }}"></option>@endforeach
    </datalist>
</div>
@endsection
