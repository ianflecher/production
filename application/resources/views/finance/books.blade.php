@extends('layouts.app')

@section('title', 'Bookkeeping — '.$month->format('F Y'))
@section('page-title', 'Bookkeeping')

@section('content')
<style>
    .bk-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.9rem; margin-bottom: 1.4rem; }
    .bk-stat { padding: 1.15rem 1.25rem; position: relative; overflow: hidden; }
    .bk-stat::before { content: ''; position: absolute; inset: 0 auto 0 0; width: 4px; border-radius: 4px 0 0 4px; background: var(--ink-3); }
    .bk-stat.in::before   { background: #18A957; }
    .bk-stat.out::before  { background: #E31B23; }
    .bk-stat.net::before  { background: #2D7FF0; }
    .bk-stat .lbl { font-size: 0.72rem; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 0.4rem; }
    .bk-stat .val { font-size: 1.7rem; font-weight: 800; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
    .bk-stat .note { font-size: 0.74rem; color: var(--ink-3); margin-top: 0.35rem; }
    .bk-loss { color: #b91c1c; }
    .bk-gain { color: #15803d; }
    .bk-toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
    .bk-cat { display: grid; grid-template-columns: 1fr auto; gap: 0.35rem 1rem; align-items: center; }
    .bk-cat .bar { grid-column: 1 / -1; height: 6px; border-radius: 99px; background: var(--border); overflow: hidden; margin-bottom: 0.5rem; }
    .bk-cat .bar span { display: block; height: 100%; background: #E31B23; border-radius: 99px; }
    .bk-cat .nm { font-size: 0.85rem; font-weight: 600; }
    .bk-cat .amt { font-size: 0.85rem; font-variant-numeric: tabular-nums; color: var(--ink-2); }
    .bk-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
    .bk-form .full { grid-column: 1 / -1; }
    .bk-form label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem; }
    .num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }

    /* The expense rows carry a Remove button, so the last two columns need room
       of their own — without it the button sat on top of the recorder's name. */
    .bk-expenses .bk-by { font-size: 0.8rem; color: var(--ink-3); white-space: nowrap; }
    .bk-expenses .bk-action { width: 1%; text-align: right; white-space: nowrap; }
    .bk-expenses .bk-action form { margin: 0; }

    /* Give the description the slack, since it is the one column that varies. */
    .bk-expenses td:nth-child(3) { min-width: 220px; }

    .bk-expenses tfoot td {
        background: #fafbfd;
        border-top: 2px solid var(--border);
        border-bottom: none;
        font-weight: 800;
    }
    .bk-expenses .bk-total-label { text-align: right; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.07em; color: var(--ink-3); }
    .bk-expenses .bk-total-value { font-size: 0.95rem; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>Bookkeeping</h1>
        <p class="muted">Money in against money out for {{ $month->format('F Y') }}.</p>
    </div>
    <a href="{{ route('books.export', ['month' => $monthValue]) }}" class="btn btn-primary">⬇ Download Excel</a>
</div>

{{-- Month picker --}}
<form method="GET" action="{{ route('books.index') }}" class="bk-toolbar">
    <label for="month" style="font-size:0.8rem; font-weight:600; color:var(--ink-2);">Month</label>
    <input type="month" id="month" name="month" value="{{ $monthValue }}" style="max-width: 190px;">
    <button type="submit" class="btn btn-ghost btn-sm">Show</button>
    @if ($monthValue !== now()->format('Y-m'))
        <a href="{{ route('books.index') }}" class="btn btn-ghost btn-sm">This month</a>
    @endif
</form>

<div class="bk-stats">
    <div class="card bk-stat in">
        <div class="lbl">Money in</div>
        <div class="val">₱{{ number_format($income, 2) }}</div>
        <div class="note">Client payments received</div>
    </div>
    <div class="card bk-stat out">
        <div class="lbl">Money out</div>
        <div class="val">₱{{ number_format($expenseTotal, 2) }}</div>
        <div class="note">{{ $expenses->count() }} expense{{ $expenses->count() === 1 ? '' : 's' }} recorded</div>
    </div>
    <div class="card bk-stat net">
        <div class="lbl">{{ $profit < 0 ? 'Loss' : 'Profit' }}</div>
        <div class="val {{ $profit < 0 ? 'bk-loss' : 'bk-gain' }}">
            {{ $profit < 0 ? '−' : '' }}₱{{ number_format(abs($profit), 2) }}
        </div>
        <div class="note">Money in minus money out</div>
    </div>
</div>

{{-- Where the money went --}}
@if ($byCategory->isNotEmpty())
    <div class="card panel">
        <h2>Where the money went</h2>
        <p class="sub">{{ $month->format('F Y') }}, biggest first.</p>
        <div class="bk-cat">
            @foreach ($byCategory as $key => $amount)
                <div class="nm">{{ $categories[$key] ?? $key }}</div>
                <div class="amt">₱{{ number_format($amount, 2) }}
                    <span style="color:var(--ink-3);">({{ $expenseTotal > 0 ? round($amount / $expenseTotal * 100) : 0 }}%)</span>
                </div>
                <div class="bar"><span style="width: {{ $expenseTotal > 0 ? max(2, round($amount / $expenseTotal * 100)) : 0 }}%;"></span></div>
            @endforeach
        </div>
    </div>
@endif

{{-- Record a new expense --}}
<div class="card panel">
    <h2>Record an expense</h2>
    <p class="sub">Anything the business paid for — materials, wages, rent, power, delivery.</p>

    <form method="POST" action="{{ route('books.expenses.store') }}" enctype="multipart/form-data" class="bk-form">
        @csrf

        <div>
            <label for="spent_at">Date *</label>
            <input type="date" id="spent_at" name="spent_at" required
                   value="{{ old('spent_at', now()->format('Y-m-d')) }}">
        </div>

        <div>
            <label for="category">Category *</label>
            <select id="category" name="category" required>
                @foreach ($categories as $key => $label)
                    <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="amount">Amount (₱) *</label>
            <input type="number" id="amount" name="amount" step="0.01" min="0.01" required
                   placeholder="0.00" value="{{ old('amount') }}">
        </div>

        <div>
            <label for="method">Paid with</label>
            <select id="method" name="method">
                <option value="">— not specified —</option>
                @foreach ($methods as $m)
                    <option value="{{ $m }}" @selected(old('method') === $m)>{{ $m }}</option>
                @endforeach
            </select>
        </div>

        <div class="full">
            <label for="description">What was it for? *</label>
            <input type="text" id="description" name="description" required maxlength="255"
                   placeholder="e.g. 20 yards cotton fabric from Divisoria" value="{{ old('description') }}">
        </div>

        <div>
            <label for="reference">Reference / OR no.</label>
            <input type="text" id="reference" name="reference" maxlength="255"
                   class="no-caps" value="{{ old('reference') }}">
        </div>

        <div>
            <label for="receipt">Receipt (optional)</label>
            <input type="file" id="receipt" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf">
        </div>

        <div class="full">
            <label for="note">Note</label>
            <textarea id="note" name="note" rows="2" maxlength="2000" placeholder="Anything worth remembering later…">{{ old('note') }}</textarea>
        </div>

        <div class="full">
            <button type="submit" class="btn btn-primary">+ Record expense</button>
        </div>
    </form>
</div>

{{-- The month's expenses --}}
<div class="card panel">
    <h2>Expenses — {{ $month->format('F Y') }}</h2>

    @if ($expenses->isEmpty())
        <p class="muted" style="padding: 1.5rem 0; text-align: center;">
            No expenses recorded for {{ $month->format('F Y') }} yet.
        </p>
    @else
        <div class="tbl-wrap">
            <table class="tbl bk-expenses">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th class="num">Amount</th>
                        <th>Method</th>
                        <th>Receipt</th>
                        <th>By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($expenses as $e)
                        <tr>
                            <td style="white-space: nowrap;">{{ $e->spent_at?->format('M j') }}</td>
                            <td>{{ $e->categoryLabel() }}</td>
                            <td>
                                {{ $e->description }}
                                @if ($e->reference)
                                    <div style="font-size: 0.74rem; color: var(--ink-3);">Ref: {{ $e->reference }}</div>
                                @endif
                                @if ($e->note)
                                    <div style="font-size: 0.74rem; color: var(--ink-3);">{{ $e->note }}</div>
                                @endif
                            </td>
                            <td class="num" style="font-weight: 700;">₱{{ number_format((float) $e->amount, 2) }}</td>
                            <td>{{ $e->method ?: '—' }}</td>
                            <td>
                                @if ($e->hasReceipt())
                                    <a href="{{ route('books.expenses.receipt', $e) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">View</a>
                                @else
                                    <span style="color: var(--ink-3);">—</span>
                                @endif
                            </td>
                            <td class="bk-by">{{ $e->recorder?->name ?? '—' }}</td>
                            <td class="bk-action">
                                <form method="POST" action="{{ route('books.expenses.destroy', $e) }}"
                                      onsubmit="return confirm('Remove this expense of ₱{{ number_format((float) $e->amount, 2) }}? It will no longer count towards the month.');">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="bk-total-label">Total</td>
                        <td class="num bk-total-value">₱{{ number_format($expenseTotal, 2) }}</td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
@endsection
