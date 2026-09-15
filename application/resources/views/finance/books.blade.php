@extends('layouts.app')

@section('title', 'Bookkeeping — '.$month->format('F Y'))
@section('page-title', 'Bookkeeping')

@section('content')
<style>
    .bk-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.9rem; margin-bottom: 1.4rem; }
    .bk-stat { padding: 1.15rem 1.25rem; position: relative; overflow: hidden; }
    .bk-stat::before { content: ''; position: absolute; inset: 0 auto 0 0; width: 4px; border-radius: 4px 0 0 4px; background: var(--ink-3); }
    .bk-stat.out::before  { background: #E31B23; }
    .bk-stat .lbl { font-size: 0.72rem; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 0.4rem; }
    .bk-stat .val { font-size: 1.7rem; font-weight: 800; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
    .bk-stat .note { font-size: 0.74rem; color: var(--ink-3); margin-top: 0.35rem; }
    .bk-toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
    .bk-cat { display: grid; grid-template-columns: 1fr auto; gap: 0.35rem 1rem; align-items: center; }
    .bk-cat .bar { grid-column: 1 / -1; height: 6px; border-radius: 99px; background: var(--border); overflow: hidden; margin-bottom: 0.5rem; }
    .bk-cat .bar span { display: block; height: 100%; background: #E31B23; border-radius: 99px; }
    .bk-cat .nm { font-size: 0.85rem; font-weight: 600; }
    .bk-cat .amt { font-size: 0.85rem; font-variant-numeric: tabular-nums; color: var(--ink-2); }
    .num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }

    /* The expense rows carry a Remove button, so the last two columns need room
       of their own — without it the button sat on top of the recorder's name. */
    .bk-expenses .bk-by { font-size: 0.8rem; color: var(--ink-3); white-space: nowrap; }
    /* No button on this table wraps.

       The rows grew an Edit button, the table ran out of width, and the
       column that gave it up was the receipt - so "View" was breaking across
       two lines as "Vie / w". The narrow columns claim only what they need
       and the description keeps the slack. */
    .bk-expenses .btn { white-space: nowrap; }
    .bk-expenses .bk-receipt { width: 1%; white-space: nowrap; }
    .bk-action { width: 1%; text-align: right; white-space: nowrap; }
    .bk-action form { margin: 0; }
    /* Edit beside Remove, not above it: a form is a block, and without this
       the button dropped onto its own line and doubled the row height. */
    .bk-actions { display: flex; gap: 0.35rem; justify-content: flex-end; align-items: center; }

    /* The row that opens under a top-up when it is being corrected. */
    .pc-edit-row[hidden] { display: none; }
    .pc-edit-row > td { background: #fafbfd; }
    .pc-edit-form { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-end; margin: 0; }
    .pc-edit-form label { display: block; font-size: 0.7rem; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
    .pc-edit-form .grow { flex: 1 1 200px; }
    .pc-edit-form input { width: 100%; }

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
        <p class="muted">Everything the shop paid for in {{ $month->format('F Y') }}.</p>
    </div>
    <a href="{{ route('books.export', ['month' => $monthValue, 'q' => $search]) }}" class="btn btn-primary">⬇ Download Excel</a>
</div>

{{-- Month picker --}}
<form method="GET" action="{{ route('books.index') }}" class="bk-toolbar">
    <label for="month" style="font-size:0.8rem; font-weight:600; color:var(--ink-2);">Month</label>
    <input type="month" id="month" name="month" value="{{ $monthValue }}" style="max-width: 190px;">
    {{-- Searched across the supplier, the invoice number, the account title
         and who ordered it, not just the description - "find the Divisoria
         fabric one" is how the question actually arrives, and the answer is
         as likely to be the supplier as the words somebody typed. --}}
    <label for="q" style="font-size:0.8rem; font-weight:600; color:var(--ink-2);">Find</label>
    <input type="search" id="q" name="q" value="{{ $search }}" class="no-caps"
           placeholder="Supplier, account title, SI no., who ordered…" style="min-width:0; flex:1 1 220px;">

    <button type="submit" class="btn btn-ghost btn-sm">Show</button>
    @if ($search !== '')
        <a href="{{ route('books.index', ['month' => $monthValue]) }}" class="btn btn-ghost btn-sm">Clear</a>
    @endif
    @if ($monthValue !== now()->format('Y-m'))
        <a href="{{ route('books.index') }}" class="btn btn-ghost btn-sm">This month</a>
    @endif
</form>

{{-- One figure, and it is the month's spending.

     Money in and the profit that came off it used to sit here beside it.
     They are a different question and a different ledger - client payments
     are Finance's - and putting them on the expense book made this page look
     like a profit statement it was never keeping. --}}
<div class="bk-stats">
    <div class="card bk-stat out">
        <div class="lbl">Money out</div>
        <div class="val">₱{{ number_format($expenseTotal, 2) }}</div>
        <div class="note">
            {{ $expenses->count() }} expense{{ $expenses->count() === 1 ? '' : 's' }}
            in {{ $month->format('F Y') }}
        </div>
    </div>
</div>

{{-- The petty cash tin.
     Its balance is a running one and deliberately ignores the month picker:
     money left in the tin on the 31st is still in it on the 1st. The top-ups
     listed underneath are the chosen month's, like everything else here. --}}
<div class="card panel">
    <h2>Petty cash</h2>
    <p class="sub">
        The tin the shop spends small amounts from. Money goes in two ways — topped up here, or
        a client paying in <strong>cash</strong>, which lands in the same drawer. Record an expense
        with <strong>Cash (Petty Cash Fund)</strong> as the method and it comes straight back out.
    </p>

    <div class="bk-stats" style="margin-bottom: 1.1rem;">
        <div class="card bk-stat {{ $pettyCash > 0 ? 'in' : 'out' }}">
            <div class="lbl">In the tin</div>
            <div class="val">₱{{ number_format($pettyCash, 2) }}</div>
            <div class="note">Available to spend right now</div>
        </div>
        <div class="card bk-stat">
            <div class="lbl">Put in</div>
            <div class="val">₱{{ number_format($pettyCashIn, 2) }}</div>
            {{-- Broken down, because the two halves are reconciled against
                 different things: a top-up against a bank withdrawal, client
                 cash against the job it was paid on. --}}
            <div class="note">
                ₱{{ number_format($pettyCashToppedUp, 2) }} topped up
                @if ($pettyCashFromClients > 0)
                    &middot; ₱{{ number_format($pettyCashFromClients, 2) }} cash from clients
                @endif
            </div>
        </div>
        <div class="card bk-stat">
            <div class="lbl">Spent from it</div>
            <div class="val">₱{{ number_format($pettyCashOut, 2) }}</div>
            <div class="note">Expenses paid with petty cash</div>
        </div>
    </div>

    {{-- Cash Finance has not confirmed yet.

         It is in the drawer, so the notes and the screen disagree by exactly
         this much - and without saying so, that gap is found by somebody
         counting the tin and assuming the system is broken. It is deliberately
         NOT added to the balance: a payment is a claim until Finance agrees,
         and the balance is what decides whether an expense may be recorded
         against the tin. Counting a claim lets the shop spend money nobody
         has been shown. --}}
    @if ($pettyCashUnconfirmed > 0)
        <div class="alert-warning" style="margin-bottom: 1.1rem;">
            ⏳ ₱{{ number_format($pettyCashUnconfirmed, 2) }} of client cash is waiting for Finance to
            confirm it. It is not counted in the tin until they do, so the drawer holds that much
            more than the figure above.
        </div>
    @endif

    <form method="POST" action="{{ route('books.petty-cash.store') }}" class="bk-form">
        @csrf
        <div>
            <label for="pc_amount">Add money</label>
            <input type="number" step="0.01" min="0.01" id="pc_amount" name="amount"
                   value="{{ old('amount') }}" placeholder="0.00" required>
        </div>
        <div>
            <label for="pc_occurred_at">Date</label>
            <input type="date" id="pc_occurred_at" name="occurred_at"
                   value="{{ old('occurred_at', now()->toDateString()) }}" required>
        </div>
        <div style="flex: 1 1 260px;">
            <label for="pc_note">Note <span style="font-weight:400; color:var(--ink-3);">(optional)</span></label>
            <input type="text" id="pc_note" name="note" value="{{ old('note') }}"
                   maxlength="255" placeholder="Where the money came from">
        </div>
        <div style="align-self: flex-end;">
            <button class="btn btn-primary">Add to petty cash</button>
        </div>
    </form>

    @if ($pettyCashTopups->isNotEmpty())
        <div class="tbl-wrap" style="margin-top: 1.1rem;">
            <table class="tbl">
                <thead><tr><th>Date</th><th>Amount</th><th>Note</th><th>Added by</th><th></th></tr></thead>
                <tbody>
                    @foreach ($pettyCashTopups as $t)
                        <tr>
                            <td>{{ $t->occurred_at?->format('M j, Y') }}</td>
                            <td>₱{{ number_format((float) $t->amount, 2) }}</td>
                            <td>{{ $t->note ?: '—' }}</td>
                            <td>{{ $t->recorder?->name ?? '—' }}</td>
                            <td class="bk-action">
                                <div class="bk-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" data-pc-edit="{{ $t->id }}">Edit</button>
                                    <form method="POST" action="{{ route('books.petty-cash.destroy', $t) }}"
                                          onsubmit="return confirm('Are you sure? This takes the ₱{{ number_format((float) $t->amount, 2) }} top-up back out, and the tin will hold that much less.');">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        {{-- Opens under the row it belongs to, and stays open
                             when the correction was refused, so the figure
                             being argued about is still on screen beside the
                             reason it was refused. --}}
                        <tr class="pc-edit-row" id="pc-edit-{{ $t->id }}" @unless (session('reopenTopup') === $t->id) hidden @endunless>
                            <td colspan="5">
                                <form method="POST" action="{{ route('books.petty-cash.update', $t) }}" class="pc-edit-form">
                                    @csrf
                                    <div style="flex: 0 0 140px;">
                                        <label for="pc_amount_{{ $t->id }}">Amount</label>
                                        <input type="number" step="0.01" min="0.01" id="pc_amount_{{ $t->id }}"
                                               name="amount" value="{{ number_format((float) $t->amount, 2, '.', '') }}" required>
                                    </div>
                                    <div style="flex: 0 0 170px;">
                                        <label for="pc_date_{{ $t->id }}">Date</label>
                                        <input type="date" id="pc_date_{{ $t->id }}" name="occurred_at"
                                               value="{{ $t->occurred_at?->format('Y-m-d') }}" required>
                                    </div>
                                    <div class="grow">
                                        <label for="pc_note_{{ $t->id }}">Note</label>
                                        <input type="text" id="pc_note_{{ $t->id }}" name="note" maxlength="255"
                                               value="{{ $t->note }}" placeholder="Where the money came from">
                                    </div>
                                    <div class="bk-actions">
                                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                        <button type="button" class="btn btn-ghost btn-sm" data-pc-cancel="{{ $t->id }}">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- The month's client cash, so the tin's figure can be traced back to the
         jobs it came off. Not editable here: a payment is Finance's record and
         is corrected on the order it belongs to, not on this page. --}}
    @if ($cashPayments->isNotEmpty())
        <h3 style="font-size:0.82rem; font-weight:800; letter-spacing:0.06em; text-transform:uppercase;
                   color:var(--ink-3); margin:1.3rem 0 0.5rem;">
            Cash from clients — {{ $month->format('F Y') }}
        </h3>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Date</th><th>Amount</th><th>Job</th><th>Client</th><th>For</th></tr></thead>
                <tbody>
                    @foreach ($cashPayments as $p)
                        <tr>
                            <td>{{ $p->paid_at?->format('M j, Y') }}</td>
                            <td>₱{{ number_format((float) $p->amount, 2) }}</td>
                            <td>{{ $p->order?->order_number ?? '—' }}</td>
                            <td>{{ $p->order?->clientName() ?? '—' }}</td>
                            <td>{{ $p->kind === 'full' ? 'Full payment' : ucfirst((string) $p->kind) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Where the money went --}}
@if ($byCategory->isNotEmpty())
    <div class="card panel">
        <h2>Where the money went</h2>
        <p class="sub">{{ $month->format('F Y') }}, biggest first.</p>
        {{-- The four groups first, because that is the shape a month is read
             in, then every title under them. --}}
        <div class="bk-cat" style="margin-bottom:1rem;">
            @foreach ($byGroup as $group => $amount)
                <div class="nm" style="font-weight:700;">{{ $group }}</div>
                <div class="amt">₱{{ number_format($amount, 2) }}
                    <span style="color:var(--ink-3);">({{ $expenseTotal > 0 ? round($amount / $expenseTotal * 100) : 0 }}%)</span>
                </div>
                <div class="bar"><span style="width: {{ $expenseTotal > 0 ? max(2, round($amount / $expenseTotal * 100)) : 0 }}%;"></span></div>
            @endforeach
        </div>

        <p class="sub" style="margin:0 0 .4rem;">By account title</p>
        <div class="bk-cat">
            @foreach ($byCategory as $key => $amount)
                <div class="nm">{{ $key ?: '—' }}</div>
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

    @include('finance.partials.expense-form')
</div>

{{-- The month's expenses --}}
<div class="card panel">
    <h2>Expenses — {{ $month->format('F Y') }}</h2>
    @if ($search !== '')
        <p class="sub" style="margin:0 0 .6rem;">
            {{ $expenses->count() }} {{ Str::plural('expense', $expenses->count()) }}
            matching “{{ $search }}”, out of ₱{{ number_format($expenseTotal, 2) }} for the month.
        </p>
    @endif

    @if ($expenses->isEmpty())
        <p class="muted" style="padding: 1.5rem 0; text-align: center;">
            No expenses recorded for {{ $month->format('F Y') }} yet.
        </p>
    @else
        <div class="tbl-wrap">
            <table class="tbl bk-expenses">
                <thead>
                    <tr>
                        <th>Order date</th>
                        <th>Account title</th>
                        <th>Description</th>
                        <th>Supplier</th>
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
                            <td>
                                {{ $e->account_title ?: '—' }}
                                @if ($e->vat_status)
                                    <div class="bk-by">{{ $e->vat_status }}</div>
                                @endif
                            </td>
                            <td>
                                {{ $e->description }}
                                @if ($e->reference || $e->reference_type)
                                    <div style="font-size: 0.74rem; color: var(--ink-3);">
                                        Ref: {{ trim(($e->reference_type ? $e->reference_type.'-' : '').$e->reference, '-') }}
                                    </div>
                                @endif
                                @if ($e->note)
                                    <div style="font-size: 0.74rem; color: var(--ink-3);">{{ $e->note }}</div>
                                @endif
                            </td>
                            <td>
                                {{ $e->supplier ?: '—' }}
                                @if ($e->si_cr_no)
                                    <div class="bk-by">SI/CR {{ $e->si_cr_no }}</div>
                                @endif
                            </td>
                            <td class="num" style="font-weight: 700;">₱{{ number_format((float) $e->amount, 2) }}</td>
                            <td>{{ $e->method ?: '—' }}</td>
                            <td class="bk-receipt">
                                @if ($e->hasReceipt())
                                    <a href="{{ route('books.expenses.receipt', $e) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">View</a>
                                @else
                                    <span style="color: var(--ink-3);">—</span>
                                @endif
                            </td>
                            <td class="bk-by">{{ $e->recorder?->name ?? '—' }}</td>
                            <td class="bk-action">
                                <div class="bk-actions">
                                    {{-- Correcting a supplier's name used to mean
                                         removing the row and typing all fifteen
                                         fields again, which nobody does at five
                                         o'clock - so the wrong figure went to the
                                         bookkeeper. --}}
                                    <a href="{{ route('books.expenses.edit', $e) }}" class="btn btn-ghost btn-sm">Edit</a>
                                    <form method="POST" action="{{ route('books.expenses.destroy', $e) }}"
                                          onsubmit="return confirm('Are you sure? This removes the ₱{{ number_format((float) $e->amount, 2) }} expense, and it will no longer count towards the month.');">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="bk-total-label">
                            {{ $search !== '' ? 'Total shown' : 'Total' }}
                        </td>
                        <td class="num bk-total-value">₱{{ number_format($expenses->sum('amount'), 2) }}</td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>

{{-- Opening a top-up for correction.

     A row of boxes under the one being fixed, rather than a page of its own:
     a top-up is three fields, and the reason for changing it is almost always
     the row above or below it. --}}
<script>
    (function () {
        function row(id) { return document.getElementById('pc-edit-' + id); }

        document.querySelectorAll('[data-pc-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var r = row(btn.getAttribute('data-pc-edit'));
                if (!r) { return; }
                r.hidden = false;
                var first = r.querySelector('input');
                if (first) { first.focus(); first.select(); }
            });
        });

        document.querySelectorAll('[data-pc-cancel]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var r = row(btn.getAttribute('data-pc-cancel'));
                if (r) { r.hidden = true; }
            });
        });
    })();
</script>
@endsection
