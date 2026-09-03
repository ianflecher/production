@extends('layouts.app')

@section('title', $order->order_number.' — Imprint Production')
@section('page-title', 'Order '.$order->order_number)

@section('content')
@php
    [$done, $total] = $order->progress();
    $isLeader = auth()->user()->isLeader();
    $layoutReleased = $order->layoutReleased();
    $layoutApproved = $order->layoutApproved();
    $mockupApproved = $order->mockupApproved();
@endphp

<style>
    /* Compact spacing — scoped to the order detail page (this <style> only
       renders here, so it affects no other screen). */
    .content .card.panel { padding: 1.05rem 1.2rem; }
    .content .panel h2 { font-size: 1rem; }
    .content .panel .sub { margin-bottom: 0.65rem; font-size: 0.8rem; }
    .content table.tbl { font-size: 0.85rem; }
    .content table.tbl td { padding: 0.36rem 0.65rem; }
    .content table.tbl th { padding: 0.4rem 0.65rem; }
    /* Key/value rows in the Client & job card read tighter with a narrower label. */
    .content table.tbl td:first-child { line-height: 1.25; }
    /* Client & job details flow into two columns on wider screens so the card
       uses the full width and takes about half the height. Long rows span both. */
    @media (min-width: 640px) {
        .cj table.tbl tbody { display: grid; grid-template-columns: 1fr 1fr; column-gap: 1.6rem; }
        .cj table.tbl tr { display: grid; grid-template-columns: 132px 1fr; align-items: baseline; }
        .cj table.tbl td:first-child { white-space: nowrap; }
        .cj table.tbl tr.full { grid-column: 1 / -1; }
    }
    .cj table.tbl td:first-child { color: var(--ink-3); }
</style>

<div class="page-head">
    <div class="grow">
        <h1 style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
            {{ $order->order_number }}
            @include('partials.status', ['status' => $order->status])
        </h1>
        <p class="muted">
            {{ $order->clientName() }} · {{ number_format($order->quantity) }} pcs
            @if ($order->due_date) · due {{ $order->due_date->format('M j, Y') }} @endif
            · created by {{ $order->creator->name }}
        </p>
        @if ($order->completed_at)
            <p style="margin-top: 0.35rem; font-size: 0.85rem; color: var(--success-ink); font-weight: 600;">
                ✓ Finished {{ $order->completed_at->format('M j, Y \a\t g:i A') }}
            </p>
        @endif
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        @if ($order->jobOrder && $mockupApproved)
            <a href="{{ route('orders.job-order', $order) }}" class="btn btn-ghost btn-sm">📋 Tech pack</a>
        @endif
        {{-- Client documents. Only once the layout is approved — that's when the
             client is ready to pay, so the design is already settled.
             The VAT version only shows when the order is marked VAT inclusive. --}}
        @if (auth()->user()->canCreateOrders() && $layoutApproved)
            @if ($order->vat_inclusive)
                <a href="{{ route('orders.document', [$order, 'pq']) }}" class="btn btn-primary btn-sm">🧾 Price Quotation with VAT</a>
            @else
                <a href="{{ route('orders.document', [$order, 'dr']) }}" class="btn btn-primary btn-sm">🧾 Price Quotation</a>
            @endif
        @endif
        @if (auth()->user()->canCreateOrders() && in_array($order->status, ['active', 'on_hold']))
            <a href="{{ route('orders.edit', $order) }}" class="btn btn-ghost btn-sm">✎ Edit order</a>
        @endif
    @if ($isLeader)
        @if ($order->status === 'active')
            <form method="POST" action="{{ route('orders.status', $order) }}">
                @csrf
                <input type="hidden" name="action" value="hold">
                <button class="btn btn-ghost btn-sm">Put on hold</button>
            </form>
        @elseif ($order->status === 'on_hold')
            <form method="POST" action="{{ route('orders.status', $order) }}">
                @csrf
                <input type="hidden" name="action" value="resume">
                <button class="btn btn-primary btn-sm">Resume order</button>
            </form>
        @endif
        @if (in_array($order->status, ['active', 'on_hold']))
            <form method="POST" action="{{ route('orders.status', $order) }}"
                  onsubmit="return confirm('Cancel this order? All unfinished tasks will be cancelled.');">
                @csrf
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-danger btn-sm">Cancel order</button>
            </form>
        @endif
    @endif
    </div>

</div>

@include('partials.delay-alert', ['order' => $order, 'size' => 'big'])

@php
    $canRecordPayment = auth()->user()->canCreateOrders();
    $decoLabels = collect($order->decoration_methods ?? [])->map(fn ($m) => \App\Models\ProductionOrder::DECORATION_METHODS[$m] ?? $m);
    $cutLabel = \App\Models\ProductionOrder::CUTTING_TYPES[$order->cutting_type] ?? null;
    $currentTask = $order->tasks->first(fn ($t) => ! in_array($t->status, ['complete', 'cancelled']));
    $hasReference = ($order->jobOrder?->referenceFiles()->count() ?? 0) > 0;

    // ---- "What to do next" — a single, prominent hint derived from the same
    // state the sections below already use. Read-only; changes no behaviour.
    $jobStatus = $order->jobOrder?->status;
    $nextStep = null;
    if ($order->status === 'complete') {
        $nextStep = ['tone' => 'done', 'label' => 'Order complete', 'title' => 'Nothing left to do', 'desc' => 'This order is finished.'];
    } elseif ($order->status === 'cancelled') {
        $nextStep = ['tone' => 'muted', 'label' => 'Cancelled', 'title' => 'This order was cancelled', 'desc' => 'No further action.'];
    } elseif ($order->status === 'on_hold') {
        $nextStep = ['tone' => 'warn', 'label' => 'On hold', 'title' => 'Order is on hold', 'desc' => 'Resume the order from the actions above to continue production.'];
    } elseif (! $layoutApproved && $layoutReleased) {
        $nextStep = ['tone' => 'wait', 'label' => 'In progress', 'title' => 'With the artist', 'desc' => 'Waiting on the layout and the client’s approval before the downpayment.'];
    } elseif ($layoutApproved && ! $order->hasDownpayment() && $order->hasPaymentAwaitingFinance()) {
        // Collected, not yet confirmed. Its own state: asking the officer to
        // collect what they have already collected read as the page not
        // knowing, and there is nothing for them to do but wait.
        $nextStep = ['tone' => 'wait', 'label' => 'Step 2 — Payment', 'title' => 'Waiting for Finance to confirm the payment', 'desc' => 'The downpayment is recorded and with Finance. The artist starts the final mockup once they have confirmed the money landed.'];
    } elseif ($canRecordPayment && $layoutApproved && ! $order->hasDownpayment()) {
        $nextStep = ['tone' => 'action', 'label' => 'Step 2 — Payment', 'title' => 'Collect the downpayment', 'desc' => 'The layout is approved — record the downpayment so the artist can prepare the final mockup.', 'cta' => ['label' => 'Go to payment', 'href' => '#payment-section']];
    } elseif ($order->hasDownpayment() && ! $mockupApproved) {
        $nextStep = ['tone' => 'wait', 'label' => 'Step 3 — Mockup', 'title' => 'Waiting for mockup approval', 'desc' => 'The Tech Pack will appear after the final mockup is approved.'];
    } elseif ($canRecordPayment && $mockupApproved && $jobStatus === 'draft') {
        $nextStep = ['tone' => 'action', 'label' => 'Step 3 — Tech Pack', 'title' => 'Send the Tech Pack to the artist', 'desc' => 'The artist completes every manual field. It returns to you for approval before going to the leader.', 'cta' => ['label' => 'Open Tech Pack', 'href' => route('orders.job-order', $order)]];
    } elseif ($currentTask) {
        $nextStep = ['tone' => 'info', 'label' => 'In production', 'title' => 'Now at: '.$currentTask->department, 'desc' => $done.' of '.$total.' steps complete — track live status in the pipeline below.'];
    }

    $toneColor = [
        'action' => 'var(--accent)', 'alert' => 'var(--danger-ink)', 'warn' => '#b45309',
        'wait' => 'var(--accent)', 'done' => 'var(--success-ink)', 'info' => 'var(--accent)', 'muted' => 'var(--ink-3)',
    ][$nextStep['tone'] ?? 'action'] ?? 'var(--accent)';
@endphp

@if ($nextStep)
    <div class="card" style="margin-bottom: 1.4rem; padding: 1.15rem 1.3rem; border-left: 4px solid {{ $toneColor }}; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
        <div style="width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0; display: grid; place-items: center; color: {{ $toneColor }}; background: color-mix(in srgb, {{ $toneColor }} 12%, transparent);">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>
        </div>
        <div style="flex: 1; min-width: 220px;">
            <div style="font-size: 0.68rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: {{ $toneColor }};">{{ $nextStep['label'] }}</div>
            <div style="font-family: var(--font-head); font-weight: 600; font-size: 1.12rem; letter-spacing: -0.01em; margin: 0.1rem 0 0.15rem;">{{ $nextStep['title'] }}</div>
            <div style="font-size: 0.85rem; color: var(--ink-2);">{{ $nextStep['desc'] }}</div>
        </div>
        @if (! empty($nextStep['cta']))
            <a href="{{ $nextStep['cta']['href'] }}" class="btn btn-primary" style="flex-shrink: 0;">{{ $nextStep['cta']['label'] }} →</a>
        @endif
    </div>
@endif

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.4rem; margin-bottom: 1.4rem; align-items: stretch;">
    <div class="card panel cj">
        <h2>Client &amp; job</h2>
        <div class="tbl-wrap">
            <table class="tbl">
                <tbody>
                    <tr><td style="width: 130px;">Client</td><td style="font-weight: 600;">{{ $order->clientName() }}</td></tr>
                    @if ($order->client?->contact_number)
                        <tr><td>Contact</td><td>{{ $order->client->contact_number }}</td></tr>
                    @endif
                    @if ($order->client?->company)
                        <tr><td>Company</td><td>{{ $order->client->company }}</td></tr>
                    @endif
                    {{-- One address is asked for now. An older client whose two
                         differ still shows both, so nothing already recorded is
                         hidden; a client saved since shows the single line. --}}
                    @php
                        $addr = $order->client?->delivery_address ?: $order->client?->office_address;
                        $otherAddr = $order->client?->office_address !== $order->client?->delivery_address
                            ? $order->client?->office_address : null;
                    @endphp
                    @if ($addr)
                        <tr><td>Address</td><td>{{ $addr }}</td></tr>
                    @endif
                    @if ($otherAddr)
                        <tr><td>Office address</td><td>{{ $otherAddr }}</td></tr>
                    @endif
                    @if ($order->client?->tin)
                        <tr><td>TIN</td><td>{{ $order->client->tin }}</td></tr>
                    @endif
                    <tr><td>Product</td><td>{{ $order->productLabel() ?? '—' }}@if ($order->back_pocket) <span class="muted">(+ back pocket)</span>@endif</td></tr>
                    <tr class="full">
                        <td>Sizes</td>
                        <td>
                            @if ($order->items->isNotEmpty())
                                <div style="display:flex; flex-wrap:wrap; gap:0.35rem;">
                                    @foreach ($order->itemsInSizeOrder() as $item)
                                        <span class="badge" style="background: var(--accent-soft); color: #1d4ed8;">{{ $item->size }} × {{ $item->quantity }}</span>
                                    @endforeach
                                </div>
                            @else
                                <span style="color: var(--ink-3);">—</span>
                            @endif
                        </td>
                    </tr>
                    <tr><td style="color: var(--ink-3);">Production total</td><td style="font-weight: 600;">{{ number_format($order->quantity) }} pcs</td></tr>
                    @if ($order->rush)
                        <tr class="full">
                            <td>Rush</td>
                            <td><span class="badge" style="background: #fee2e2; color: #991b1b;">🚨 RUSH ORDER — ₱{{ number_format((float) $order->rush_fee, 2) }} fee</span></td>
                        </tr>
                    @endif
                    <tr class="full">
                        <td>Production</td>
                        <td>
                            @if ($order->massprod_priority)
                                <span class="badge" style="background: #fef9c3; color: #854d0e;">MASSPROD PRIORITY — no first sample</span>
                            @else
                                <span style="font-size: 0.85rem;">Print first sample before mass production</span>
                            @endif
                        </td>
                    </tr>
                    @if ($order->description)
                        <tr class="full"><td>Brief</td><td style="white-space: pre-line;">{{ $order->description }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    <div id="payment-section" class="card panel" style="scroll-margin-top: 5rem;">
        <h2>Payment</h2>

        {{-- Price breakdown lives here with the rest of the money. --}}
        @php $pb = $order->pricingBreakdown(); @endphp
        <div class="tbl-wrap" style="margin-bottom: 0.9rem;">
            <table class="tbl">
                <tbody>
                    <tr>
                        <td style="color: var(--ink-3); width: 130px;">Price / piece</td>
                        <td style="text-align: right;">
                            {{ $order->unit_price !== null ? '₱'.number_format((float) $order->unit_price, 2) : 'For quotation' }}
                            @if (($pb['custom_size_qty'] ?? 0) > 0)
                                <div style="font-size: 0.78rem; color: var(--ink-3);">{{ $pb['charted_qty'] }} pcs on the price list</div>
                            @endif
                        </td>
                    </tr>
                    {{-- CS and the typed size are off the price list, so they are
                         priced by hand rather than at the tier rate. --}}
                    @if (($pb['custom_size_qty'] ?? 0) > 0)
                        <tr>
                            <td style="color: var(--ink-3);">Off-chart sizes ({{ $pb['custom_size_qty'] }} pcs)</td>
                            <td style="text-align: right;">
                                ₱{{ number_format((float) $order->custom_size_price, 2) }} / pc
                                <div style="font-size: 0.78rem; color: var(--ink-3);">₱{{ number_format($pb['custom_size_amount'], 2) }}</div>
                            </td>
                        </tr>
                    @endif
                    @if ($pb['subtotal'] !== null)
                        <tr><td style="color: var(--ink-3);">Subtotal</td><td style="text-align: right;">₱{{ number_format($pb['subtotal'], 2) }}</td></tr>
                        @if (($pb['back_pocket'] ?? 0) > 0)
                            <tr><td style="color: var(--ink-3);">Back pocket ({{ $pb['back_pocket_qty'] }} pcs)</td><td style="text-align: right;">+ ₱{{ number_format($pb['back_pocket'], 2) }}</td></tr>
                        @endif
                        @if (($pb['addon'] ?? 0) > 0)
                            <tr><td style="color: var(--ink-3);">{{ $pb['addon_label'] ?: 'Add-on' }}</td><td style="text-align: right;">+ ₱{{ number_format($pb['addon'], 2) }}</td></tr>
                        @endif
                        @if (($pb['rush'] ?? 0) > 0)
                            <tr><td style="color: var(--ink-3);">🚨 Rush fee</td><td style="text-align: right;">+ ₱{{ number_format($pb['rush'], 2) }}</td></tr>
                        @endif
                        @if ($pb['discount'] > 0)
                            <tr><td style="color: var(--ink-3);">Discount</td><td style="text-align: right; color: var(--danger-ink);">− ₱{{ number_format($pb['discount'], 2) }}@if ($order->discount_note) <span class="muted" style="font-size:0.8rem;">({{ $order->discount_note }})</span>@endif</td></tr>
                        @endif
                        @if ($order->vat_inclusive)
                            <tr><td style="color: var(--ink-3);">VAT (12%)</td><td style="text-align: right;">+ ₱{{ number_format($pb['vat'], 2) }}</td></tr>
                        @endif
                    @endif
                    <tr><td style="color: var(--ink-3); font-weight: 600;">Total price</td><td style="text-align: right; font-weight: 600;">{{ $order->total_price !== null ? '₱'.number_format((float) $order->total_price, 2) : 'For quotation' }}</td></tr>
                </tbody>
            </table>
        </div>

        {{-- The priced line items from the quotation / delivery receipt — includes
             any extra products added on the document. --}}
        @php
            $priceDoc = $order->documents->firstWhere('type', 'pq') ?? $order->documents->firstWhere('type', 'dr');
            $docItems = collect($priceDoc?->items ?? [])
                ->filter(fn ($r) => filled($r['description'] ?? null) && (float) ($r['quantity'] ?? 0) > 0)
                ->values();
        @endphp
        @if ($docItems->isNotEmpty())
            <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.05em; font-weight:800; color:var(--ink-3); margin:0.2rem 0 0.35rem;">Items — {{ $priceDoc->typeLabel() }}</div>
            <div class="tbl-wrap" style="margin-bottom: 0.9rem;">
                <table class="tbl">
                    <thead>
                        <tr><th>Product</th><th style="text-align:center;">Size</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Unit</th><th style="text-align:right;">Amount</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($docItems as $r)
                            @php $amt = (float) ($r['quantity'] ?? 0) * (float) ($r['unit_price'] ?? 0); @endphp
                            <tr>
                                <td style="font-weight:600;">{{ $r['description'] }}</td>
                                <td style="text-align:center;">{{ $r['size'] ?? '' }}</td>
                                <td style="text-align:right;">{{ number_format((float) ($r['quantity'] ?? 0)) }}</td>
                                <td style="text-align:right;">₱{{ number_format((float) ($r['unit_price'] ?? 0), 2) }}</td>
                                <td style="text-align:right; font-weight:600;">₱{{ number_format($amt, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($order->total_price !== null)
            <div style="display:flex; justify-content:space-between; gap:0.5rem; margin-bottom:0.5rem; font-size:0.9rem;">
                <span class="muted">Total ₱{{ number_format((float) $order->total_price, 2) }}</span>
                <span class="muted">Paid ₱{{ $order->totalPaid() }}</span>
                <span style="font-weight:600; {{ ($order->balance() ?? 0) > 0 ? 'color: var(--danger-ink);' : 'color: var(--success-ink);' }}">Balance ₱{{ number_format($order->balance() ?? 0, 2) }}</span>
            </div>
        @endif
        @if ($order->hasDownpayment())
            <p style="color: var(--success-ink); font-weight: 600; margin-bottom: 0.6rem;">✓ Downpayment recorded · total paid ₱{{ $order->totalPaid() }}</p>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>Amount</th><th>Method</th><th>Ref</th><th>Proof</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($order->payments as $p)
                            <tr>
                                <td>₱{{ number_format((float) $p->amount, 2) }}</td>
                                <td>{{ $p->method ?? '—' }}</td>
                                <td>{{ $p->reference ?? '—' }}</td>
                                <td>
                                    @if ($p->hasProof())
                                        <a href="{{ route('payments.proof', $p) }}" target="_blank" style="font-size:0.8rem;">📎 View</a>
                                    @else
                                        <span style="color: var(--ink-3);">—</span>
                                    @endif
                                </td>
                                <td style="font-size: 0.8rem; color: var(--ink-3);">{{ $p->paid_at?->format('M j, g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($layoutApproved)
            @if ($order->hasPaymentAwaitingFinance())
                <p class="muted" style="margin-bottom: 0.6rem;">
                    Recorded and waiting on <strong>Finance</strong> to confirm the money landed.
                    The artist starts the final mockup once they have.
                </p>
            @else
                <p class="muted" style="margin-bottom: 0.6rem;">Layout approved — collect the downpayment so the artist can prepare the final mockup.</p>
            @endif
        @else
            <p class="muted" style="margin-bottom: 0.6rem;">The downpayment is collected after the client approves the layout.</p>
        @endif

        @if ($canRecordPayment && ! $layoutApproved)
            {{-- Design comes first — no payment until the layout is approved. --}}
        @elseif ($canRecordPayment && in_array($order->status, ['active', 'on_hold']))
            @php $bal = $order->balance() ?? 0; @endphp
            @if ($order->total_price === null)
                <p class="muted" style="margin-top:0.4rem;">Set a price first to record a payment — <a href="{{ route('orders.edit', $order) }}">edit the order</a>.</p>
            @elseif ($bal <= 0)
                <p style="margin-top:0.4rem; color: var(--success-ink); font-weight:600;">✓ Fully paid.</p>
            @else
                {{-- Whether anything has been RECORDED, not whether Finance has
                     confirmed it. The button read "Record downpayment" in red
                     after the officer had already recorded one - which says
                     nothing was taken, while the line above says it is with
                     Finance. One of them had to be wrong, and it was this. --}}
                @php $anyRecorded = $order->hasDownpayment() || $order->hasPaymentAwaitingFinance(); @endphp
                <details class="inline-form" style="margin-top: 0.4rem;">
                    <summary class="btn {{ $anyRecorded ? 'btn-ghost' : 'btn-primary' }} btn-sm">
                        {{ $anyRecorded ? 'Record another payment' : 'Record downpayment' }}
                    </summary>
                    <div class="pop">
                        <form method="POST" action="{{ route('orders.payment', $order) }}" enctype="multipart/form-data">
                            @csrf
                            <label for="pay_method">Payment method</label>
                            <select id="pay_method" name="method" required>
                                <option value="">— Choose method —</option>
                                @foreach (\App\Models\Payment::METHODS as $m)
                                    <option value="{{ $m }}">{{ $m }}</option>
                                @endforeach
                            </select>

                            <label style="margin-top:0.5rem;">Reference (optional)</label>
                            <input type="text" name="reference" placeholder="Receipt / txn no.">

                            <label style="margin-top:0.5rem;">Proof of payment <span style="color: var(--danger-ink);">*</span></label>
                            <input type="file" name="proof" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                            <div style="font-size:0.72rem;color:var(--ink-3);margin-top:0.25rem;">Required — photo/screenshot or PDF.</div>

                            <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:0.8rem;">
                                @if (! $order->hasDownpayment())
                                    <button type="submit" name="portion" value="half" class="btn btn-primary btn-sm" style="width:100%;">Half downpayment — ₱{{ number_format($order->total_price / 2, 2) }}</button>
                                    <button type="submit" name="portion" value="full" class="btn btn-success btn-sm" style="width:100%;">Full payment — ₱{{ number_format($order->total_price, 2) }}</button>
                                @else
                                    <button type="submit" name="portion" value="balance" class="btn btn-success btn-sm" style="width:100%;">Pay remaining balance — ₱{{ number_format($bal, 2) }}</button>

                                    {{-- Partial top-ups are only offered once a downpayment exists. --}}
                                    <div style="display:flex; gap:0.4rem; align-items:stretch; margin-top:0.15rem;">
                                        <input type="number" step="0.01" min="1" max="{{ $bal }}" name="amount" placeholder="Other amount (₱)" class="no-caps" style="flex:1; min-width:0;">
                                        <button type="submit" name="portion" value="partial" class="btn btn-ghost btn-sm" style="white-space:nowrap;">Record partial</button>
                                    </div>
                                @endif
                            </div>
                        </form>
                    </div>
                </details>
            @endif
        @endif

        @if ($canRecordPayment)
            {{-- Compose the latest status as a ready-to-send message the account
                 officer copies and pastes into the client's Messenger / Viber. --}}
            @php
                $clientName = $order->clientName();
                $firstName = trim(explode(' ', trim((string) $clientName))[0] ?? '') ?: 'there';

                // Turn the internal stage name into a warm, client-facing phrase
                // ("we are already doing …"). Falls back to the plain stage name.
                $stagePhrases = [
                    'Layout'                    => 'creating the layout design for your order',
                    'Final mockup'              => 'finalizing the mockup of your design',
                    'Tech pack'                 => 'preparing the tech pack for the floor',
                    'Production template'       => 'preparing the tech pack for the floor',
                    'Raw materials'             => 'preparing the materials for your order',
                    'Printer'                   => 'printing your design',
                    'Sticker'                   => 'preparing the transfers for your design',
                    'Embroidery'                => 'doing the embroidery on your order',
                    'Cap press'                 => 'pressing your design onto the fabric',
                    'Heat press'                => 'pressing your design onto the fabric',
                    'Small press'               => 'pressing your design onto the fabric',
                    'Roller press'              => 'pressing your design onto the fabric',
                    'Manual cutting'            => 'cutting the fabric for your order',
                    'Laser cutting'             => 'cutting the fabric for your order',
                    'Cutting'                   => 'cutting the fabric for your order',
                    'Pairing'                   => 'preparing and pairing the pieces',
                    'Sewing'                    => 'sewing your order',
                    'Quality control'           => 'doing the quality checking',
                    'Produce sample for client' => 'preparing your sample',
                    'Mass production'           => 'in full production of your order',
                    'Inventory'                 => 'finalizing and packing your order',
                    'Release to client'         => 'getting your order ready for release',
                ];

                $dueLine = $order->due_date
                    ? "We are doing our best to complete it before your due date ({$order->due_date->format('M j, Y')}). Thank you for your understanding!"
                    : 'We are doing our best to complete it as soon as possible. Thank you for your understanding!';

                if ($order->status === 'complete') {
                    $body = 'good news — your order is now complete and ready for pickup/delivery! Thank you so much for your trust.';
                } elseif ($order->status === 'on_hold') {
                    $body = "your order is temporarily on hold. We'll update you as soon as it continues. Thank you for your understanding!";
                } else {
                    // Prefer a stage that's actively being worked; else the next
                    // one that's ready; else the earliest step still to be done —
                    // so the message always names a real stage, not just "preparing".
                    $activeTask = $order->tasks
                        ->whereIn('status', ['for_checking', 'in_progress', 'revision_required'])
                        ->sortByDesc('sequence')->first()
                        ?? $order->tasks->where('status', 'ready')->sortBy('sequence')->first()
                        ?? $order->tasks->whereNotIn('status', ['complete', 'cancelled'])->sortBy('sequence')->first();

                    $phrase = $activeTask
                        ? ($stagePhrases[$activeTask->department] ?? \Illuminate\Support\Str::lower($activeTask->department))
                        : 'preparing your order';

                    $body = "we are already {$phrase}. {$dueLine}";
                }

                $officerName = auth()->user()->name;
                $clientMessage = "Hi {$firstName}! 😊 Update on your order {$order->order_number}: {$body} — {$officerName}";
            @endphp

            <div class="client-update" style="margin-top: 1.2rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                <details class="inline-form">
                    <summary class="btn btn-ghost btn-sm">📤 Send update to client</summary>
                    <div style="margin-top: 0.7rem;">
                        <textarea rows="4" style="width:100%; font-size:0.9rem;">{{ $clientMessage }}</textarea>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.5rem;">
                            <button type="button" class="btn btn-primary btn-sm"
                                    onclick="const t=this.closest('.client-update').querySelector('textarea'); const done=()=>{const o=this.textContent; this.textContent='✓ Copied!'; setTimeout(()=>this.textContent=o,1500);}; if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t.value).then(done).catch(()=>{t.select();document.execCommand('copy');done();});}else{t.select();document.execCommand('copy');done();}">
                                📋 Copy message
                            </button>
                            @if ($order->client?->contact_number)
                                <span style="font-size:0.8rem; color:var(--ink-3); align-self:center;">📱 {{ $order->client->contact_number }}</span>
                            @endif
                        </div>
                    </div>
                </details>
            </div>
        @endif
    </div>
</div>

{{-- The artist's work: layout, final mockup and the tech pack — so the
     account officer can see and send them to the client. --}}
@php
    $designTasks = [
        'Layout' => $order->tasks->firstWhere('department', 'Layout'),
        'Final mockup' => $order->tasks->firstWhere('department', 'Final mockup'),
        'Tech pack' => $order->tasks->first(fn ($t) => $t->isTechPackStep()),
    ];
    $latestFiles = function ($task) {
        if (! $task) return collect();
        $latest = $task->files->where('round', ($task->revision_count ?? 0) + 1);
        return $latest->isNotEmpty() ? $latest : $task->files;
    };
    $hasDesign = collect($designTasks)->contains(fn ($t) => $latestFiles($t)->isNotEmpty());
@endphp
    {{-- Remakes. Kept next to the design package because that is where the
         leader is when they decide the pieces have to be made again. --}}
    @php $isBoss = auth()->user()->isLeader() || auth()->user()->isSuperAdmin(); @endphp

    @if ($order->isReplacement())
        <div class="alert-error" style="margin-bottom:1.4rem;">
            <strong>This is a remake of
                <a href="{{ route('orders.show', $order->replaces_order_id) }}">{{ $order->replaces?->order_number }}</a>.</strong>
            {{ $order->replacement_reason }}
            <br><span style="font-size:0.82rem;">It runs the full pipeline and carries no charge — the work is being done twice.</span>
        </div>
    @endif

    @if ($order->replacements()->exists())
        <div class="card panel" style="margin-bottom:1.4rem;">
            <h2>Remakes of this order</h2>
            <p class="sub">Pieces that had to be made again.</p>
            @foreach ($order->replacements as $r)
                <div style="font-size:0.85rem; margin:0.25rem 0;">
                    <a href="{{ route('orders.show', $r) }}"><strong>{{ $r->order_number }}</strong></a>
                    · {{ $r->quantity }} pcs · {{ $r->replacement_reason }}
                    @include('partials.status', ['status' => $r->status])
                </div>
            @endforeach
        </div>
    @endif

    @if ($isBoss && ! $order->isReplacement())
        <div class="card panel" style="margin-bottom:1.4rem;">
            <h2>Make these pieces again</h2>
            <p class="sub">
                A wrong colour, a damaged panel, a seam that failed the check. This makes a
                new job order that runs the same pipeline — printing, cutting, sewing,
                checking — pointed at this one. It carries <strong>no charge</strong>, so the
                shop can see what remaking work actually costs.
            </p>
            <form method="POST" action="{{ route('orders.replacement', $order) }}"
                  onsubmit="return confirm('Create a remake of {{ $order->order_number }}?');"
                  style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:0.7rem; align-items:end;">
                @csrf
                <label>
                    <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--ink-2);">What went wrong</span>
                    <input type="text" name="replacement_reason" maxlength="255" required
                           placeholder="e.g. wrong collar colour on 12 pcs" style="width:100%;">
                </label>
                <label>
                    <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--ink-2);">How many pieces</span>
                    <input type="number" name="quantity" min="1" max="{{ $order->quantity }}"
                           value="{{ $order->quantity }}" required style="width:100%;">
                </label>
                <label>
                    <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--ink-2);">Due</span>
                    <input type="date" name="due_date" required
                           value="{{ now()->addDays(7)->toDateString() }}" style="width:100%;">
                </label>
                <div><button class="btn btn-danger">Create remake</button></div>
            </form>
        </div>
    @endif

@if (($canRecordPayment || $isLeader) && $hasDesign && $order->jobOrder && $mockupApproved)
    @php $mockupPreview = $latestFiles($designTasks['Final mockup'] ?? null)->first(fn ($f) => $f->isImage())
        ?? $latestFiles($designTasks['Layout'] ?? null)->first(fn ($f) => $f->isImage()); @endphp
    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Design package</h2>
        <p class="sub">The Tech Pack, mockup and production template as one document — the same package the leader approves.</p>
        <div style="display:flex; gap:1.1rem; align-items:center; flex-wrap:wrap;">
            @if ($mockupPreview)
                <a href="{{ route('orders.package', $order) }}" style="flex-shrink:0;">
                    @if ($mockupPreview->isExternal() && ! $mockupPreview->isWebLink())
                        {{-- Network-path design — no preview; show a placeholder tile. --}}
                        <div style="width:120px; height:120px; display:grid; place-items:center; border:1px solid var(--border); border-radius:8px; font-size:2rem; background:var(--surface-2);">📁</div>
                    @else
                        <img src="{{ route('tasks.file.view', $mockupPreview) }}" alt="Mockup" class="design-preview"
                             style="width:120px; height:120px; object-fit:contain; border:1px solid var(--border); border-radius:8px; display:block;">
                    @endif
                </a>
            @endif
            <div style="flex:1; min-width:220px;">
                <div style="display:flex; flex-wrap:wrap; gap:0.5rem 1rem; margin-bottom:0.9rem;">
                    @foreach ($designTasks as $label => $task)
                        @if ($task)
                            <span style="font-size:0.8rem; color:var(--ink-2); display:inline-flex; align-items:center; gap:0.35rem;">
                                {{ $label }} @include('partials.status', ['status' => $task->status])
                            </span>
                        @endif
                    @endforeach
                </div>
                <a href="{{ route('orders.package', $order) }}" class="btn btn-primary btn-sm">📄 Open full tech pack document</a>
                @php
                    $exportFile = $order->tasks->flatMap->files->firstWhere('label', 'Export file')
                        ?? $order->tasks->flatMap->files->firstWhere('label', 'Print file (TIFF)');
                @endphp
                @if ($exportFile && ! $exportFile->isExternal())
                    <a href="{{ route('tasks.file.download', $exportFile) }}" class="btn btn-ghost btn-sm" style="margin-left: 0.4rem;">🖨 Export file</a>
                @endif
            </div>
        </div>
    </div>
@endif

@if ($order->materialRequests->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Material requests</h2>
        <p class="sub">Sent to the raw-materials account when the job order was released.</p>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Material</th><th>Status</th><th>Issued</th><th>Remarks</th></tr></thead>
                <tbody>
                    @foreach ($order->materialRequests as $mr)
                        <tr>
                            <td style="font-weight: 600;">{{ $mr->material }}</td>
                            <td>
                                @if ($mr->status === 'approved')
                                    <span class="badge" style="background: #f0fdf4; color: #15803d;">APPROVED</span>
                                @elseif ($mr->status === 'rejected')
                                    <span class="badge" style="background: #fef2f2; color: #b91c1c;">REJECTED</span>
                                @else
                                    <span class="badge" style="background: #fef9c3; color: #854d0e;">PENDING</span>
                                @endif
                            </td>
                            <td>{{ $mr->status === 'approved' ? rtrim(rtrim(number_format((float) $mr->quantity, 2), '0'), '.').' '.($mr->item?->unit ?? '') : '—' }}</td>
                            <td style="font-size: 0.82rem; color: {{ $mr->status === 'rejected' ? 'var(--danger-ink)' : 'var(--ink-3)' }};">{{ $mr->note ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="card panel" style="margin-bottom: 1.4rem;">
    <h2>Pipeline — {{ $done }} of {{ $total }} steps complete</h2>
    <p class="sub">
        @if ($isLeader)
            Approving a step unlocks only the next one. Use “Unlock early” to override a dependency.
        @else
            Live status of every department for this order.
        @endif
    </p>

    {{-- Colorful progress bar for the whole pipeline. --}}
    <div style="display:flex; align-items:center; gap:0.75rem; margin: 0 0 1.1rem;">
        <div class="progress" style="height:11px; flex:1;">
            <div style="width: {{ $total ? round($done / $total * 100) : 0 }}%;
                        background: linear-gradient(90deg, var(--brand), #a855f7 50%, var(--accent));"></div>
        </div>
        <span style="font-size:0.8rem; font-weight:800; color:#3b2a66; font-variant-numeric:tabular-nums;">
            {{ $total ? round($done / $total * 100) : 0 }}%
        </span>
    </div>

    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Due</th>
                    <th>Assigned to</th>
                    @if ($isLeader)<th>Actions</th>@endif
                </tr>
            </thead>
            <tbody>
                @php
                    // Each pipeline row gets a status colour so the table reads as a
                    // living timeline.
                    $rowColors = [
                        'complete' => '#16a34a',
                        'in_progress' => '#d97706',
                        'ready' => '#2563eb',
                        'for_checking' => '#7c3aed',
                        'revision_required' => '#dc2626',
                        'on_hold' => '#ca8a04',
                        'todo' => '#b3bdcc',
                        'cancelled' => '#cbd5e1',
                    ];
                @endphp
                @foreach ($order->tasks as $task)
                    @php
                        $rc = $rowColors[$task->status] ?? '#b3bdcc';
                        $isCurrentRow = $task->id === ($currentTask?->id ?? null);
                    @endphp
                    <tr style="{{ $isCurrentRow ? 'background: color-mix(in srgb, '.$rc.' 10%, transparent);' : '' }}">
                        <td style="font-weight: 800; color: {{ $rc }}; border-left: 4px solid {{ $rc }};">{{ $task->sequence }}</td>
                        <td>
                            <div style="font-weight: 600;">{{ $task->department }}</div>
                            @if ($hint = $task->whereItSits())
                                <div style="font-size: 0.75rem; color: var(--accent); margin-top: 0.15rem;">⏳ {{ $hint }}</div>
                            @endif
                            @if ($task->status === 'for_checking' && $task->approver_role === 'sales')
                                <div style="font-size: 0.75rem; color: var(--accent); font-weight: 600; margin-top: 0.15rem;">
                                    {{-- Only linked for the people that page lets
                                         in. A leader or a mover watching the
                                         pipeline was being handed a door that
                                         answered Forbidden. --}}
                                    @if (auth()->user()->canCreateOrders())
                                        → <a href="{{ route('sample.review') }}">Approve it on Sample Review</a>
                                    @else
                                        → waiting on the account officer to check it with the client
                                    @endif
                                </div>
                            @endif
                            @if ($task->id === $currentTask?->id && $task->isStuckNoStaff())
                                <div style="font-size: 0.75rem; color: var(--danger-ink); font-weight: 600; margin-top: 0.2rem;">⚠ No one present — nobody on the {{ $task->team === 'artist' ? 'artist' : str_replace('_', ' ', $task->team) }} team has logged in today</div>
                            @endif
                            @if ($task->status === 'revision_required' && $task->revision_note)
                                <div style="font-size: 0.78rem; color: var(--danger-ink); margin-top: 0.2rem;">↩ {{ $task->revision_note }}</div>
                            @endif
                            @if ($task->submitted_at && $task->status === 'for_checking')
                                <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.2rem;">submitted {{ $task->submitted_at->format('M j, g:i A') }}</div>
                            @endif
                            @if ($task->status === 'complete' && $task->approved_at)
                                <div style="font-size: 0.75rem; color: var(--success-ink); margin-top: 0.2rem;">✓ finished {{ $task->approved_at->format('M j, Y g:i A') }}</div>
                            @endif
                        </td>
                        <td>@include('partials.status', ['status' => $task->status])</td>
                        {{-- The step's own share of the time between the
                             confirmed downpayment and the order's due date.
                             Late is only late while the work is unfinished —
                             colouring a finished step red says nothing anybody
                             can act on. --}}
                        <td style="white-space: nowrap; font-size: 0.82rem;">
                            @if ($task->due_at)
                                @if ($task->isOverdue())
                                    <span style="color: var(--danger-ink, #b91c1c); font-weight: 700;">
                                        {{ $task->due_at->format('M j') }}
                                    </span>
                                    <div style="font-size: 0.72rem; color: var(--danger-ink, #b91c1c);">
                                        {{ $task->due_at->diffForHumans() }}
                                    </div>
                                @else
                                    <span style="color: var(--ink-2);">{{ $task->due_at->format('M j') }}</span>
                                @endif
                            @else
                                <span style="color: var(--ink-3);">—</span>
                            @endif
                        </td>
                        <td>
                            @if (! $isLeader || in_array($task->status, ['complete', 'cancelled']))
                                {{-- Floor accounts are shared, so show the name typed
                                     at the station when there is one. --}}
                                {{-- The person, not the login they borrowed. The
                                     account is still on the row for anyone who
                                     needs it. --}}
                                {{ $task->operator_name ?: ($task->assignee?->name ?? '—') }}
                            @else
                                @php $teamAgents = $task->team ? $agents->where('job_role', $task->team) : collect(); @endphp
                                @if ($task->team === null)
                                    <span style="color: var(--ink-3); font-size: 0.82rem;">Leader review</span>
                                @else
                                    <form method="POST" action="{{ route('tasks.assign', $task) }}" style="display: flex; gap: 0.4rem; align-items: center;">
                                        @csrf
                                        <select name="assigned_to" style="width: auto; min-width: 140px; padding: 0.35rem 0.5rem; font-size: 0.82rem;">
                                            <option value="">— Unassigned —</option>
                                            @foreach ($teamAgents as $agent)
                                                <option value="{{ $agent->id }}" @selected($task->assigned_to === $agent->id)>{{ $agent->name }}</option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-ghost btn-sm">Save</button>
                                    </form>
                                @endif
                            @endif
                        </td>
                        @if ($isLeader)
                        <td>
                            <div style="display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: center;">
                                @if ($task->status === 'for_checking')
                                    <form method="POST" action="{{ route('tasks.approve', $task) }}">
                                        @csrf
                                        <button class="btn btn-success btn-sm">Approve ✓</button>
                                    </form>
                                    <details class="inline-form">
                                        <summary class="btn btn-danger btn-sm">Request revision</summary>
                                        <div class="pop">
                                            <form method="POST" action="{{ route('tasks.revision', $task) }}">
                                                @csrf
                                                <label>What needs to be fixed?</label>
                                                <textarea name="revision_note" rows="3" required maxlength="2000" placeholder="Explain the problem for the agent…"></textarea>
                                                <button class="btn btn-danger btn-sm" style="margin-top: 0.5rem;">Send back for revision</button>
                                            </form>
                                        </div>
                                    </details>
                                @elseif ($task->status === 'todo')
                                    <form method="POST" action="{{ route('tasks.unlock', $task) }}">
                                        @csrf
                                        <button class="btn btn-ghost btn-sm">Unlock early</button>
                                    </form>
                                @endif

                                @if (! in_array($task->status, ['complete', 'cancelled', 'for_checking']))
                                    @php
                                        // Every other step can be re-run if it was
                                        // closed too early. Goods that left unpaid
                                        // cannot be un-released.
                                        $unpaidRelease = $task->department === 'Release to client'
                                            && ! $order->isFullyPaid();
                                    @endphp
                                    @if ($unpaidRelease)
                                        @php $bal = $order->balance(); @endphp
                                        <form method="POST" action="{{ route('tasks.force-complete', $task) }}"
                                              onsubmit="return confirm('Release this order with {{ $bal === null ? 'no price set' : '₱'.number_format($bal, 2) }} unpaid? This is recorded on the order.');"
                                              style="border:1px solid var(--danger-ink); border-radius:8px; padding:0.5rem; max-width:320px;">
                                            @csrf
                                            <div style="font-size:0.75rem; font-weight:700; color:var(--danger-ink); margin-bottom:0.35rem;">
                                                ⚠ {{ $bal === null ? 'No total price set' : '₱'.number_format($bal, 2).' unpaid' }}
                                            </div>
                                            <input type="text" name="override_reason" maxlength="500" required
                                                   placeholder="Why release before payment?"
                                                   style="width:100%; font-size:0.78rem; margin-bottom:0.35rem;">
                                            <button class="btn btn-danger btn-sm">Release anyway</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('tasks.force-complete', $task) }}"
                                              onsubmit="return confirm('Mark {{ $task->department }} COMPLETE without agent submission?');">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm">Mark complete</button>
                                        </form>
                                    @endif
                                @endif

                                @if ($task->status === 'complete' && $task->approved_at)
                                    <span style="font-size: 0.75rem; color: var(--ink-3);">done {{ $task->approved_at->diffForHumans() }}</span>
                                @endif
                            </div>
                        </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<a href="{{ route('orders.index') }}" class="btn btn-ghost btn-sm">← Back to all orders</a>

{{-- After a cross-page redirect (e.g. the account officer just approved the
     layout and is sent to #payment-section), some browsers don't scroll to the
     hash reliably once images/late content shift the layout. Force it. --}}
<script>
    (function () {
        var hash = window.location.hash;
        if (!hash || hash.length < 2) return;

        function jump() {
            var el = document.getElementById(hash.slice(1));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Once now, and again after everything (images) has loaded and settled.
        window.addEventListener('load', function () { setTimeout(jump, 60); });
        setTimeout(jump, 250);
    })();
</script>
@endsection
