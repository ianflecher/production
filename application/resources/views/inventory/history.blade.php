@extends('layouts.app')

@section('title', 'Stock history — Imprint Production')
@section('page-title', 'Stock history')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Stock history</h1>
        <p class="muted">Every change to raw materials — who put stock in, and who took it out.</p>
    </div>
    <a href="{{ route('inventory.index') }}" class="btn btn-ghost btn-sm">← Back to inventory</a>
</div>

<div class="card panel" style="margin-bottom: 1.2rem;">
    <form method="GET" action="{{ route('inventory.history') }}" style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:flex-end;">
        <div class="field" style="margin:0; min-width:220px;">
            <label for="item">Material</label>
            <select id="item" name="item">
                <option value="">— All materials —</option>
                @foreach ($items as $i)
                    <option value="{{ $i->id }}" @selected($itemId === $i->id)>{{ $i->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0; min-width:160px;">
            <label for="direction">Movement</label>
            <select id="direction" name="direction">
                <option value="">— In and out —</option>
                <option value="in" @selected($direction === 'in')>Stock in</option>
                <option value="out" @selected($direction === 'out')>Stock out</option>
            </select>
        </div>
        <button class="btn btn-primary btn-sm">Filter</button>
        @if ($itemId || $direction)
            <a href="{{ route('inventory.history') }}" class="btn btn-ghost btn-sm">Clear</a>
        @endif
    </form>
</div>

<div class="card panel">
    @if ($movements->isEmpty())
        <p class="muted" style="text-align:center; padding:2rem;">No stock movements recorded yet.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Material</th>
                        <th>In / Out</th>
                        <th style="text-align:right;">Qty</th>
                        <th style="text-align:right;">Stock after</th>
                        <th>Who</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($movements as $m)
                        <tr>
                            <td style="font-size:0.8rem; color:var(--ink-3); white-space:nowrap;">
                                {{ $m->created_at->format('M j, Y g:i A') }}
                            </td>
                            <td style="font-weight:600;">{{ $m->item?->name ?? '—' }}</td>
                            <td>
                                @if ($m->isIn())
                                    <span class="badge" style="background:#f0fdf4; color:#15803d;">STOCK IN</span>
                                @else
                                    <span class="badge" style="background:#fef2f2; color:#b91c1c;">STOCK OUT</span>
                                @endif
                            </td>
                            <td style="text-align:right; font-weight:700; color:{{ $m->isIn() ? 'var(--success-ink)' : 'var(--danger-ink)' }};">
                                {{ $m->signedQty() }} {{ $m->item?->unit }}
                            </td>
                            <td style="text-align:right; color:var(--ink-2);">{{ rtrim(rtrim(number_format((float) $m->balance_after, 2), '0'), '.') }}</td>
                            <td>
                                {{ $m->operator() }}
                                @if ($m->loggedUnderDifferentAccount())
                                    <div style="font-size:0.72rem; color:var(--ink-3);">acct: {{ $m->user->name }}</div>
                                @endif
                            </td>
                            <td style="font-size:0.82rem; color:var(--ink-2);">
                                <strong>{{ ucfirst($m->reason) }}</strong>
                                @if ($m->order)
                                    · <a href="{{ route('orders.show', $m->order) }}">{{ $m->order->order_number }}</a>
                                @endif
                                @if ($m->note)<div style="font-size:0.76rem; color:var(--ink-3);">{{ $m->note }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:1rem;">{{ $movements->links() }}</div>
    @endif
</div>
@endsection
