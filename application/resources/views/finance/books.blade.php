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
    /* Twelve columns rather than auto-fit.

       The form has fifteen fields now, and auto-fit packed them wherever they
       happened to land - a date next to a TIN next to an amount, at whatever
       width was left over. Every field below says how many columns it wants,
       so things that belong together sit together and a narrow one stays
       narrow. */
    .bk-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }

    /* Only the expense form. The petty cash form beside it is four fields and
       was perfectly happy with auto-fit; handing it twelve columns squeezed
       every box to eighty pixels. */
    .bk-form.bk-form-wide { grid-template-columns: repeat(12, 1fr); gap: 0.75rem 0.8rem; }
    .bk-form .full { grid-column: 1 / -1; }
    .bk-form .c2 { grid-column: span 2; }
    .bk-form .c3 { grid-column: span 3; }
    .bk-form .c4 { grid-column: span 4; }
    .bk-form .c6 { grid-column: span 6; }
    .bk-form label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem; }
    .bk-form input, .bk-form select, .bk-form textarea { width: 100%; }

    /* A heading every few fields. Fifteen boxes in a row is a wall; the same
       fifteen under "Who supplied it" and "The paper" is a form. */
    .bk-form .bk-leg {
        grid-column: 1 / -1;
        margin: 0.5rem 0 -0.15rem;
        font-size: 0.72rem; font-weight: 800; letter-spacing: 0.07em;
        text-transform: uppercase; color: var(--ink-3);
        border-top: 1px solid var(--border); padding-top: 0.8rem;
    }
    .bk-form .bk-leg:first-child { margin-top: 0; border-top: 0; padding-top: 0; }

    .bk-hint { font-size: 0.72rem; color: var(--ink-3); margin-top: 0.25rem; line-height: 1.35; }

    /* The account title picker.

       A filter box and a list, drawn as ONE control - the input's bottom edge
       is the list's top edge - so it reads as a searchable field rather than
       as a text box that happens to sit above a scrolling list box. */
    .bk-picker {
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #fff;
        overflow: hidden;
    }
    .bk-picker:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-soft); }
    .bk-picker input[type="search"] {
        border: 0; border-bottom: 1px solid var(--border); border-radius: 0;
        padding: 0.6rem 0.75rem; font-size: 0.9rem;
    }
    .bk-picker input[type="search"]:focus { outline: none; box-shadow: none; }
    .bk-picker select {
        border: 0; border-radius: 0; height: 190px; padding: 0.35rem 0.25rem;
        font-size: 0.86rem; background: #fff;
    }
    .bk-picker select:focus { outline: none; box-shadow: none; }
    .bk-picker optgroup { font-size: 0.74rem; letter-spacing: 0.04em; color: var(--ink-3); }
    .bk-picker option { padding: 0.18rem 0.5rem; color: var(--ink); }
    .bk-picker option:checked { background: var(--brand-soft); color: var(--ink); font-weight: 700; }

    .bk-picked {
        display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
        border-top: 1px solid var(--border); padding: 0.45rem 0.75rem;
        font-size: 0.8rem; background: #fafbfd;
    }
    .bk-picked strong { color: var(--ink); }

    @media (max-width: 760px) {
        /* One field per row on a phone. Twelve columns of 30px is not a form. */
        .bk-form.bk-form-wide { grid-template-columns: 1fr; }
        .bk-form .c2, .bk-form .c3, .bk-form .c4, .bk-form .c6 { grid-column: 1 / -1; }
    }
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

{{-- The petty cash tin.
     Its balance is a running one and deliberately ignores the month picker:
     money left in the tin on the 31st is still in it on the 1st. The top-ups
     listed underneath are the chosen month's, like everything else here. --}}
<div class="card panel">
    <h2>Petty cash</h2>
    <p class="sub">
        The tin the shop spends small amounts from. Put money in here, then record an expense
        with <strong>Petty cash</strong> as the method and it comes straight back out.
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
            <div class="note">All top-ups, all time</div>
        </div>
        <div class="card bk-stat">
            <div class="lbl">Spent from it</div>
            <div class="val">₱{{ number_format($pettyCashOut, 2) }}</div>
            <div class="note">Expenses paid with petty cash</div>
        </div>
    </div>

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
                <thead><tr><th>Date</th><th>Amount</th><th>Note</th><th>Added by</th></tr></thead>
                <tbody>
                    @foreach ($pettyCashTopups as $t)
                        <tr>
                            <td>{{ $t->occurred_at?->format('M j, Y') }}</td>
                            <td>₱{{ number_format((float) $t->amount, 2) }}</td>
                            <td>{{ $t->note ?: '—' }}</td>
                            <td>{{ $t->recorder?->name ?? '—' }}</td>
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

    <form method="POST" action="{{ route('books.expenses.store') }}" enctype="multipart/form-data" class="bk-form bk-form-wide">
        @csrf

        <div class="bk-leg">When, and who asked</div>

        <div class="c3">
            <label for="spent_at">Order date *</label>
            <input type="date" id="spent_at" name="spent_at" required
                   value="{{ old('spent_at', now()->format('Y-m-d')) }}">
        </div>

        <div class="c3">
            <label for="paid_at">Date paid</label>
            <input type="date" id="paid_at" name="paid_at" value="{{ old('paid_at') }}">
            <div class="bk-hint">Blank until it is actually paid.</div>
        </div>

        <div class="c6">
            <label for="ordered_by">Ordered by</label>
            <input type="text" id="ordered_by" name="ordered_by" maxlength="120"
                   value="{{ old('ordered_by') }}" placeholder="Who asked for it">
        </div>

        <div class="bk-leg">What it was</div>

        {{-- Seventy-eight titles is too many to scroll, so the box at the top
             filters the list under it as you type. It is a real <select> with
             the four groups intact, so the field validates, submits and works
             with the keyboard whether or not the script runs. --}}
        <div class="c6">
            <label for="accountTitleFilter">Account title *</label>
            <div class="bk-picker">
                <input type="search" id="accountTitleFilter" class="no-caps"
                       placeholder="Type to filter — fabric, BIR, rent…" autocomplete="off">
                <select id="account_title" name="account_title" size="8" required>
                    @foreach ($accountTitles as $group => $titles)
                        <optgroup label="{{ $group }}">
                            @foreach ($titles as $title)
                                <option value="{{ $title }}" @selected(old('account_title') === $title)>{{ $title }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <div class="bk-picked">
                    <span id="accountTitlePicked">Nothing chosen yet</span>
                    <span id="accountTitleCount" style="color:var(--ink-3);"></span>
                </div>
            </div>
        </div>

        <div class="c6">
            <label for="description">What was it for? *</label>
            <input type="text" id="description" name="description" required maxlength="255"
                   placeholder="e.g. 20 yards cotton fabric from Divisoria" value="{{ old('description') }}">

            <label for="amount" style="margin-top:.8rem;">Amount (₱) *</label>
            <input type="number" id="amount" name="amount" step="0.01" min="0.01" required
                   placeholder="0.00" value="{{ old('amount') }}">

            <label for="method" style="margin-top:.8rem;">Payment method</label>
            <select id="method" name="method">
                <option value="">— not specified —</option>
                @foreach ($methods as $m)
                    <option value="{{ $m }}" @selected(old('method') === $m)>{{ $m }}</option>
                @endforeach
            </select>

            <label for="vat_status" style="margin-top:.8rem;">VAT / N-VAT</label>
            <select id="vat_status" name="vat_status">
                <option value="">— neither —</option>
                @foreach ($vatStatuses as $v)
                    <option value="{{ $v }}" @selected(old('vat_status') === $v)>{{ $v }}</option>
                @endforeach
            </select>
        </div>

        <div class="bk-leg">Who supplied it</div>

        <div class="c5" style="grid-column: span 5;">
            <label for="supplier">Supplier / vendor</label>
            <input type="text" id="supplier" name="supplier" maxlength="255"
                   value="{{ old('supplier') }}">
        </div>

        <div class="c3">
            <label for="tin">Their TIN</label>
            <input type="text" id="tin" name="tin" maxlength="40" class="no-caps"
                   value="{{ old('tin') }}" placeholder="000-000-000-000">
        </div>

        <div style="grid-column: span 4;">
            <label for="business_address">Their business address</label>
            <input type="text" id="business_address" name="business_address" maxlength="255"
                   value="{{ old('business_address') }}">
        </div>

        <div class="bk-leg">The paper</div>

        <div class="c2">
            <label for="reference_type">Ref. type</label>
            <select id="reference_type" name="reference_type">
                <option value="">— none —</option>
                @foreach ($referenceTypes as $t)
                    <option value="{{ $t }}" @selected(old('reference_type') === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>

        <div class="c3">
            <label for="reference">Reference no.</label>
            <input type="text" id="reference" name="reference" maxlength="255"
                   class="no-caps" value="{{ old('reference') }}">
            <div class="bk-hint">Comes out as PO-0042.</div>
        </div>

        <div class="c3">
            <label for="si_cr_no">SI / CR no.</label>
            <input type="text" id="si_cr_no" name="si_cr_no" maxlength="255"
                   class="no-caps" value="{{ old('si_cr_no') }}">
        </div>

        <div class="c4">
            <label for="receipt">Receipt *</label>
            <input type="file" id="receipt" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
            <div class="bk-hint">Required — image or PDF.</div>
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

{{-- Filtering seventy-eight account titles.

     Plain DOM on a real <select>: the options and their four groups are in
     the HTML, so the field validates, submits and works with the keyboard
     whether or not this runs. All the script does is hide what does not
     match, which is why an empty box puts everything back rather than
     needing a reset.

     Matching is done on a lower-cased haystack of the title AND its group,
     so typing "admin" finds the AE lines even though none of them contain
     the word, and "cos" finds the cost of sales ones. --}}
<script>
    (function () {
        var box = document.getElementById('accountTitleFilter');
        var list = document.getElementById('account_title');
        var count = document.getElementById('accountTitleCount');

        if (!box || !list) { return; }

        var options = Array.prototype.map.call(list.querySelectorAll('option'), function (opt) {
            var group = opt.parentNode.tagName === 'OPTGROUP' ? opt.parentNode.label : '';
            return { el: opt, group: opt.parentNode, hay: (opt.textContent + ' ' + group).toLowerCase() };
        });

        var total = options.length;

        function apply() {
            var needle = box.value.trim().toLowerCase();
            var shown = 0;

            options.forEach(function (o) {
                var hit = needle === '' || o.hay.indexOf(needle) !== -1;
                o.el.hidden = !hit;
                if (hit) { shown++; }
            });

            // A group whose every option is hidden should go too, or the
            // list reads as four headings above nothing.
            Array.prototype.forEach.call(list.querySelectorAll('optgroup'), function (g) {
                var any = Array.prototype.some.call(g.querySelectorAll('option'), function (o) { return !o.hidden; });
                g.hidden = !any;
            });

            count.textContent = needle === ''
                ? total + ' titles'
                : shown + ' of ' + total;

            // One left and nothing chosen yet: pick it, so the common case is
            // type-three-letters-and-move-on.
            if (shown === 1 && needle !== '') {
                options.forEach(function (o) { if (!o.el.hidden) { o.el.selected = true; } });
            }
        }

        // What is chosen, spelled out under the list. A highlighted row
        // scrolled out of view is not an answer to "which one did I pick".
        var picked = document.getElementById('accountTitlePicked');

        function showPicked() {
            picked.innerHTML = list.value
                ? 'Chosen: <strong>' + list.value.replace(/[<>&]/g, '') + '</strong>'
                : 'Nothing chosen yet';
        }

        list.addEventListener('change', showPicked);
        box.addEventListener('input', function () { apply(); showPicked(); });

        // Enter in the filter box would otherwise submit the whole form
        // while the person is still choosing.
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); }
        });

        apply();
        showPicked();
    })();
</script>
@endsection
