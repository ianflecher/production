@extends('layouts.app')

@section('title', 'Finance — Payments')
@section('page-title', 'Finance')

@section('content')
<style>
    .fin-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.9rem; margin-bottom: 1.4rem; }
    .fin-stat { padding: 1.15rem 1.25rem; position: relative; overflow: hidden; }
    .fin-stat::before { content: ''; position: absolute; inset: 0 auto 0 0; width: 4px; background: var(--brand, #E31B23); border-radius: 4px 0 0 4px; }
    .fin-stat:nth-child(2)::before { background: #18A957; }
    .fin-stat:nth-child(3)::before { background: #2D7FF0; }
    .fin-stat .lbl { font-size: 0.72rem; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 0.4rem; }
    .fin-stat .val { font-size: 1.7rem; font-weight: 800; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
    .fin-stat .note { font-size: 0.74rem; color: var(--ink-3); margin-top: 0.35rem; }
    .fin-toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
    .fin-toolbar input, .fin-toolbar select { height: auto; }
    .badge-kind { text-transform: capitalize; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>Payments</h1>
        <p class="muted">Every payment recorded across all orders, with proof.</p>
    </div>
    <a href="{{ route('finance.export', request()->only('q', 'method')) }}" class="btn btn-primary">⬇ Download Excel</a>
</div>

<div class="fin-stats">
    <div class="card fin-stat">
        <div class="lbl">Total collected</div>
        <div class="val">₱{{ number_format($totalCollected, 2) }}</div>
        <div class="note">All recorded payments</div>
    </div>
    <div class="card fin-stat">
        <div class="lbl">Collected this month</div>
        <div class="val">₱{{ number_format($thisMonth, 2) }}</div>
        <div class="note">{{ now()->format('F Y') }}</div>
    </div>
    <div class="card fin-stat">
        <div class="lbl">Payment records</div>
        <div class="val">{{ number_format($paymentCount) }}</div>
        <div class="note">On file</div>
    </div>
</div>

<div class="card panel">
    <h2>Payment ledger</h2>
    <p class="sub">Search by order number or client. Click a proof to view it.</p>

    <form method="GET" action="{{ route('finance.index') }}" class="fin-toolbar">
        <input type="text" name="q" value="{{ $search }}" placeholder="Order # or client…" class="no-caps" style="max-width: 260px;">
        <select name="method" style="max-width: 180px;">
            <option value="">All methods</option>
            @foreach (\App\Models\Payment::METHODS as $m)
                <option value="{{ $m }}" @selected($method === $m)>{{ $m }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        @if ($search !== '' || $method)
            <a href="{{ route('finance.index') }}" class="btn btn-ghost btn-sm">Clear</a>
        @endif
    </form>

    @if ($payments->isEmpty())
        <p class="muted">No payments found.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Client</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Type</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Proof</th>
                        <th>Recorded by</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $p)
                        <tr>
                            <td style="font-weight:600;">{{ $p->order?->order_number ?? '—' }}</td>
                            <td>{{ $p->order?->clientName() ?: '—' }}</td>
                            <td style="text-align:right; font-weight:600; font-variant-numeric: tabular-nums;">₱{{ number_format((float) $p->amount, 2) }}</td>
                            <td><span class="badge badge-kind" style="background: var(--accent-soft); color: #1d4ed8;">{{ $p->kind ?? 'payment' }}</span></td>
                            <td>{{ $p->method ?? '—' }}</td>
                            <td>{{ $p->reference ?? '—' }}</td>
                            <td>
                                @if ($p->hasProof())
                                    @php $ext = strtolower(pathinfo($p->proof_name ?? '', PATHINFO_EXTENSION)); @endphp
                                    @if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']))
                                        <a href="{{ route('finance.proof', $p) }}" target="_blank" rel="noopener" title="Open full size">
                                            <img src="{{ route('finance.proof', $p) }}" alt="Proof"
                                                 style="width:64px; height:64px; object-fit:cover; border:1px solid var(--border); border-radius:6px; display:block;">
                                        </a>
                                    @else
                                        <a href="{{ route('finance.proof', $p) }}" target="_blank" rel="noopener">📄 {{ $ext ? strtoupper($ext) : 'View' }}</a>
                                    @endif
                                @else
                                    <span style="color: var(--ink-3);">—</span>
                                @endif
                            </td>
                            <td style="font-size:0.82rem;">{{ $p->recorder?->name ?? '—' }}</td>
                            <td style="font-size:0.82rem; color: var(--ink-3); white-space:nowrap;">{{ $p->paid_at?->format('M j, Y g:i A') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="margin-top: 1rem;">{{ $payments->links() }}</div>
    @endif
</div>
@endsection
