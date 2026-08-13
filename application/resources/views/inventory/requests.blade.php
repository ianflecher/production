@extends('layouts.app')

@section('title', 'Material Requests — Imprint Production')
@section('page-title', 'Material Requests')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Material requests</h1>
        <p class="muted">Materials requested by job orders. Approve to issue stock (deducted automatically) — reject if there isn't enough.</p>
    </div>
    <a href="{{ route('inventory.index') }}" class="btn btn-ghost btn-sm">← Back to inventory</a>
</div>

@include('partials.list-search', [
    'action' => route('inventory.requests'),
    'value' => $search ?? '',
    'placeholder' => 'Search material, order number, or client',
    'label' => 'Search material requests',
])

@if ($pending->isEmpty())
    <div class="card panel" style="text-align: center; padding: 2.5rem; margin-bottom: 1.4rem;">
        <p class="muted">No pending requests. When an account officer sends a job order, its raw materials appear here.</p>
    </div>
@else
    <div style="display: grid; gap: 1.1rem; margin-bottom: 1.6rem;">
        @foreach ($pending as $req)
            @php
                $match = $items->first(fn ($i) => strcasecmp($i->name, $req->material) === 0);
            @endphp
            <div class="card panel">
                <div style="display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: flex-start;">
                    <div>
                        <h2 style="margin-bottom: 0.15rem;">{{ $req->material }}</h2>
                        <p class="muted" style="font-size: 0.85rem;">
                            for <a href="{{ route('orders.show', $req->order) }}" style="font-weight: 600;">{{ $req->order->order_number }}</a>
                            · {{ $req->order->customer_name }} · {{ number_format($req->order->quantity) }} pcs
                            · requested {{ $req->created_at->diffForHumans() }}
                        </p>
                    </div>
                    @if ($match)
                        <span class="badge" style="background: {{ (float) $match->quantity > 0 ? '#f0fdf4' : '#fef2f2' }}; color: {{ (float) $match->quantity > 0 ? '#15803d' : '#b91c1c' }};">
                            In stock: {{ $match->qtyForHumans() }} {{ $match->unit }}
                        </span>
                    @else
                        <span class="badge" style="background: #fef9c3; color: #854d0e;">Not in inventory</span>
                    @endif
                </div>

                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border); display: flex; gap: 1.4rem; flex-wrap: wrap; align-items: flex-end;">
                    <form method="POST" action="{{ route('inventory.requests.approve', $req) }}"
                          data-order="{{ $req->order?->order_number ?? 'this order' }}"
                          onsubmit="return confirmIssue(this);"
                          style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end;">
                        @csrf
                        <div>
                            <label style="font-size: 0.75rem;">Issue from stock</label>
                            <select name="inventory_item_id" required style="min-width: 200px; padding: 0.42rem 0.6rem; font-size: 0.85rem;">
                                <option value="">— Select material —</option>
                                @foreach ($items as $i)
                                    <option value="{{ $i->id }}" @selected($match && $match->id === $i->id)>{{ $i->name }} ({{ $i->qtyForHumans() }} {{ $i->unit }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem;">Quantity</label>
                            <input type="number" name="quantity" step="0.01" min="0.01" required placeholder="0" style="width: 110px; padding: 0.42rem 0.6rem; font-size: 0.85rem;">
                        </div>
                        {{-- Who physically hands the materials out. --}}
                        <div>
                            <label style="font-size: 0.75rem;">Issued by <span style="color: var(--danger-ink);">*</span></label>
                            <input type="text" name="operator_name" required maxlength="100" placeholder="Your name"
                                   style="width: 150px; padding: 0.42rem 0.6rem; font-size: 0.85rem;">
                        </div>
                        <button class="btn btn-success btn-sm">✓ Approve &amp; deduct</button>
                    </form>

                    <details class="inline-form">
                        <summary class="btn btn-danger btn-sm">✕ Reject</summary>
                        <div class="pop">
                            <form method="POST" action="{{ route('inventory.requests.reject', $req) }}">
                                @csrf
                                <label>Why is it rejected?</label>
                                <textarea name="note" rows="2" required maxlength="500" placeholder="e.g. out of stock — restock arriving Tuesday"></textarea>
                                {{-- Shared login, so the decision needs a person
                                     against it — the same question issuing asks. --}}
                                <label style="margin-top:0.5rem;">Rejected by <span style="color: var(--danger-ink);">*</span></label>
                                <input type="text" name="operator_name" maxlength="100" required
                                       placeholder="e.g. {{ auth()->user()->name }}">
                                <button class="btn btn-danger btn-sm" style="margin-top: 0.5rem;">Reject request</button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>
        @endforeach
    </div>

    @if ($pending->hasPages())
        <div class="list-pager" style="margin-bottom: 1.6rem;">{{ $pending->links() }}</div>
    @endif
@endif

<div class="card panel">
    <h2>Recent decisions</h2>
    @if ($decided->isEmpty())
        <p class="muted">Nothing decided yet.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Order</th>
                        <th>Decision</th>
                        <th>Issued</th>
                        <th>By</th>
                        <th>When</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($decided as $d)
                        <tr>
                            <td style="font-weight: 600;">{{ $d->material }}</td>
                            <td><a href="{{ route('orders.show', $d->order) }}">{{ $d->order->order_number }}</a></td>
                            <td>
                                @if ($d->status === 'approved')
                                    <span class="badge" style="background: #f0fdf4; color: #15803d;">APPROVED</span>
                                @else
                                    <span class="badge" style="background: #fef2f2; color: #b91c1c;">REJECTED</span>
                                    @if ($d->note)<div style="font-size: 0.75rem; color: var(--danger-ink); margin-top: 0.2rem;">{{ $d->note }}</div>@endif
                                @endif
                            </td>
                            <td>{{ $d->status === 'approved' ? rtrim(rtrim(number_format((float) $d->quantity, 2), '0'), '.').' '.($d->item?->unit ?? '') : '—' }}</td>
                            <td>{{ $d->decidedByLabel() }}</td>
                            <td style="font-size: 0.8rem; color: var(--ink-3);">{{ $d->decided_at?->format('M j, g:i A') }}</td>
                            <td style="text-align: right;">
                                {{-- A rejected request can be issued once the material is restocked. --}}
                                @if ($d->status === 'rejected')
                                    <details class="inline-form">
                                        <summary class="btn btn-success btn-sm">↻ Restocked — approve</summary>
                                        <div class="pop" style="min-width: 260px;">
                                            <form method="POST" action="{{ route('inventory.requests.approve', $d) }}"
                                                  data-order="{{ $d->order?->order_number ?? 'this order' }}"
                                                  onsubmit="return confirmIssue(this);">
                                                @csrf
                                                <div class="field">
                                                    <label>Issue from stock</label>
                                                    <select name="inventory_item_id" required>
                                                        <option value="">— Select material —</option>
                                                        @foreach ($items as $i)
                                                            <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->qtyForHumans() }} {{ $i->unit }})</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Quantity</label>
                                                    <input type="number" name="quantity" step="0.01" min="0.01" required placeholder="0">
                                                </div>
                                                <div class="field">
                                                    <label>Issued by <span style="color: var(--danger-ink);">*</span></label>
                                                    <input type="text" name="operator_name" required maxlength="100" placeholder="Your name">
                                                </div>
                                                <button class="btn btn-success btn-sm" style="margin-top: 0.4rem;">✓ Approve &amp; deduct</button>
                                            </form>
                                        </div>
                                    </details>
                                @endif

                                {{-- Issuing is typed by hand and the request never
                                     says how many are needed, so handing out too
                                     much is routine. This is the way back. --}}
                                @if ($d->status === 'approved' && $d->item && (float) $d->quantity > 0)
                                    <details class="inline-form">
                                        <summary class="btn btn-ghost btn-sm">↩ Return unused</summary>
                                        <div class="pop" style="min-width: 260px;">
                                            <form method="POST" action="{{ route('inventory.requests.return', $d) }}">
                                                @csrf
                                                <p style="font-size: 0.78rem; color: var(--ink-2); margin: 0 0 0.5rem;">
                                                    {{ rtrim(rtrim(number_format((float) $d->quantity, 2), '0'), '.') }}
                                                    {{ $d->item->unit }} went out on this request.
                                                    Put back whatever the job did not use.
                                                </p>
                                                <div class="field">
                                                    <label>Coming back</label>
                                                    <input type="number" name="quantity" step="0.01" min="0.01"
                                                           max="{{ (float) $d->quantity }}" required placeholder="0">
                                                </div>
                                                <div class="field">
                                                    <label>Reason (optional)</label>
                                                    <input type="text" name="note" maxlength="255"
                                                           class="no-caps" placeholder="e.g. issued too many">
                                                </div>
                                                <div class="field">
                                                    <label>Returned by <span style="color: var(--danger-ink);">*</span></label>
                                                    <input type="text" name="operator_name" required maxlength="100" placeholder="Your name">
                                                </div>
                                                <button class="btn btn-primary btn-sm" style="margin-top: 0.4rem;">↩ Put back in stock</button>
                                            </form>
                                        </div>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<script>
    /* Issuing materials takes them out of stock for good — confirm the amount,
       the material and who handed it over before deducting. */
    function confirmIssue(form) {
        var qty = (form.quantity.value || '').trim();
        var name = (form.operator_name.value || '').trim();
        var select = form.inventory_item_id;
        var material = select && select.selectedIndex > 0
            ? select.options[select.selectedIndex].text.replace(/\s*\([^)]*\)\s*$/, '')
            : '';
        var order = form.getAttribute('data-order') || 'this order';

        if (material === '') {
            alert('Choose which material to issue.');
            select.focus();
            return false;
        }
        if (qty === '' || isNaN(Number(qty)) || Number(qty) <= 0) {
            alert('Enter how much you are issuing.');
            form.quantity.focus();
            return false;
        }
        if (name === '') {
            alert('Enter your name.');
            form.operator_name.focus();
            return false;
        }

        return window.confirm(
            'Is the number correct?\n\n' +
            qty + ' of ' + material + ' for ' + order + '\n' +
            'Issued by: ' + name + '\n\n' +
            'This takes it out of stock and cannot be undone from here.'
        );
    }
</script>
@endsection
