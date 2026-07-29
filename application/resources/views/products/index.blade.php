@extends('layouts.app')

@section('title', 'Inventory — Imprint Production')
@section('page-title', 'Product Inventory')

@section('content')
<style>
    .inv-search { position: relative; }
    .inv-search svg {
        position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%);
        width: 15px; height: 15px; color: var(--ink-3); pointer-events: none;
    }
    .inv-search input {
        width: 220px; max-width: 100%;
        padding: 0.45rem 0.7rem 0.45rem 2rem; font-size: 0.86rem;
    }
    tr.is-out td { background: #fef4f4; }
    tr.is-out:hover td { background: #fdeaea; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>Product inventory</h1>
        <p class="muted">Finished products in stock. Items are added here automatically when an order is completed — received under <strong>To receive</strong> below.</p>
    </div>
</div>

<div class="alert-success" style="margin-bottom: 1.4rem;">
    When an order is completed, its products appear under <strong>To receive</strong> below — confirm how many you actually got to add them to stock. Use <strong>Release</strong> when a client receives their products.
</div>

@if ($pending->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.4rem; border-left: 4px solid var(--accent);">
        <h2>To receive <span style="font-weight: 400; font-size: 0.8rem; color: var(--ink-3);">({{ $pending->count() }})</span></h2>
        <p class="sub">Products from completed orders. Enter how many you actually received in person, then confirm — that number is what's added to stock.</p>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Order</th>
                        <th>Expected</th>
                        <th>Confirm what you received</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pending as $r)
                        <tr>
                            <td style="font-weight: 600;">{{ $r->name }}</td>
                            <td>
                                <span style="color: var(--accent); font-weight: 600;">{{ $r->order?->order_number ?? '—' }}</span>
                                <div style="font-size: 0.78rem; color: var(--ink-3);">{{ $r->order?->customer_name }}</div>
                            </td>
                            <td style="white-space: nowrap;">{{ $r->expectedForHumans() }} {{ $r->unit }}</td>
                            <td>
                                <form method="POST" action="{{ route('products.receive', $r) }}"
                                      data-product="{{ $r->name }}"
                                      onsubmit="return confirmReceive(this);"
                                      style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
                                    @csrf
                                    <input type="text" name="operator_name" required maxlength="100" placeholder="Receiver name *"
                                           style="width: 150px; padding: 0.35rem 0.5rem; font-size: 0.85rem;">
                                    <button class="btn btn-success btn-sm">✓ Received</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function confirmReceive(form) {
            var nameInput = form.operator_name;
            var name = (nameInput.value || '').trim();
            var product = form.getAttribute('data-product') || 'this product';

            if (name === '') {
                alert('Enter the receiver name.');
                nameInput.focus();
                return false;
            }

            return window.confirm(
                'Mark ' + product + ' as received?\n\nReceived by: ' + name
            );
        }
    </script>
@endif

<div class="card panel" style="margin-bottom: 1.4rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
        <h2 style="margin-bottom: 0;">Stock on hand
            <span style="font-weight: 400; font-size: 0.8rem; color: var(--ink-3);">({{ $items->count() }} product{{ $items->count() === 1 ? '' : 's' }})</span>
        </h2>
        @if ($items->isNotEmpty())
            @php $outCount = $items->filter(fn ($i) => (float) $i->quantity <= 0)->count(); @endphp
            <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                @if ($outCount > 0)
                    <span class="badge" style="background: #fef2f2; color: #b91c1c;">{{ $outCount }} out of stock</span>
                @endif
                <div class="inv-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="search" id="invSearch" placeholder="Search product…" autocomplete="off" aria-label="Search products">
                </div>
                <span id="invCount" style="font-size: 0.8rem; color: var(--ink-3); white-space: nowrap;"></span>
            </div>
        @endif
    </div>

    @if ($items->isEmpty())
        <p class="muted">No products in stock yet. They appear automatically when an order is completed — confirm them under “To receive” above.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Stock</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="invBody">
                    @foreach ($items as $item)
                        @php $isOut = (float) $item->quantity <= 0; @endphp
                        <tr data-search="{{ strtolower($item->name.' '.$item->unit) }}" class="{{ $isOut ? 'is-out' : '' }}">
                            <td style="font-weight: 600;">{{ $item->name }}</td>
                            <td>
                                @if ($isOut)
                                    <span class="badge" style="background: #fef2f2; color: #b91c1c;">OUT OF STOCK</span>
                                @else
                                    <span style="font-weight: 700;">{{ $item->qtyForHumans() }}</span>
                                    <span style="color: var(--ink-3); font-size: 0.82rem;">{{ $item->unit }}</span>
                                @endif
                            </td>
                            <td style="text-align: right;">
                                {{-- Opens the centered release modal. --}}
                                <button type="button" class="btn btn-success btn-sm js-release-open" @disabled($isOut)
                                        data-action="{{ route('products.deduct', $item) }}"
                                        data-name="{{ $item->name }}"
                                        data-unit="{{ $item->unit }}"
                                        data-max="{{ (float) $item->quantity }}"
                                        data-stock="{{ $item->qtyForHumans() }}">↗ Release</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div id="invEmpty" hidden style="text-align: center; color: var(--ink-3); padding: 1.5rem;">No products match your search.</div>
    @endif
</div>

<div class="card panel">
    <h2>Recent activity</h2>
    <p class="sub">The latest stock movements — produced into stock and released to clients.</p>
    @if ($movements->isEmpty())
        <p class="muted">No stock movements yet.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr><th>Product</th><th>Change</th><th>Reason</th><th>By</th><th>Order</th><th>When</th></tr>
                </thead>
                <tbody>
                    @foreach ($movements as $m)
                        <tr>
                            <td style="font-weight: 600;">{{ $m->item?->name ?? '—' }}</td>
                            <td>
                                <span style="font-weight: 700; color: {{ $m->isIn() ? 'var(--success-ink)' : 'var(--danger-ink)' }};">{{ $m->signedQty() }}</span>
                            </td>
                            <td style="color: var(--ink-2); text-transform: capitalize;">{{ $m->reason }}</td>
                            <td style="color: var(--ink-2);">{{ $m->operator() }}</td>
                            <td>
                                @if ($m->order)
                                    <span style="color: var(--accent); font-weight: 600;">{{ $m->order->order_number }}</span>
                                @else
                                    <span style="color: var(--ink-3);">—</span>
                                @endif
                            </td>
                            <td style="color: var(--ink-3); font-size: 0.8rem; white-space: nowrap;">{{ $m->created_at?->format('M j, g:i A') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Centered release modal (replaces the old inline popup + native prompt). --}}
<div id="releaseModal" class="rel-overlay" hidden>
    <div class="rel-box" role="dialog" aria-modal="true" aria-labelledby="relTitle">
        <h3 id="relTitle">Release product</h3>
        <form method="POST" id="relForm">
            @csrf
            <div class="field">
                <label>How many? <span id="relStock" style="color: var(--ink-3); font-weight: 400;"></span></label>
                <input type="number" id="relQty" name="quantity" step="0.01" min="0.01" required autocomplete="off">
            </div>
            <div class="field">
                <label>Note (optional)</label>
                <input type="text" name="note" maxlength="255" placeholder="e.g. Picked up by client" class="no-caps">
            </div>
            <div class="field">
                <label>Released by <span style="color: var(--danger-ink);">*</span></label>
                <input type="text" id="relName" name="operator_name" maxlength="100" required placeholder="Your name">
            </div>
            <div class="rel-actions">
                <button type="button" class="btn btn-ghost btn-sm" id="relCancel">Cancel</button>
                <button class="btn btn-success btn-sm">✓ Confirm release</button>
            </div>
        </form>
    </div>
</div>

<style>
    .rel-overlay { position: fixed; inset: 0; z-index: 3000; background: rgba(15, 23, 42, .5); -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px); display: grid; place-items: center; padding: 1rem; }
    .rel-overlay[hidden] { display: none; }
    .rel-box { background: #fff; width: min(430px, 100%); border-radius: 16px; padding: 1.4rem 1.5rem; box-shadow: 0 24px 60px rgba(15, 23, 42, .35); animation: relIn .16s ease both; }
    @keyframes relIn { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
    .rel-box h3 { font-family: var(--font-head); font-size: 1.1rem; margin-bottom: 1rem; }
    .rel-box .field { margin-bottom: 0.8rem; }
    .rel-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.4rem; }
</style>

@if ($items->isNotEmpty())
<script>
    /* Centered release modal — opened by each row's Release button. No native prompt. */
    (function () {
        var modal = document.getElementById('releaseModal');
        if (!modal) return;
        var form = document.getElementById('relForm');
        var title = document.getElementById('relTitle');
        var stock = document.getElementById('relStock');
        var qty = document.getElementById('relQty');
        var nameEl = document.getElementById('relName');
        var noteEl = form.querySelector('[name="note"]');

        function openModal(btn) {
            form.action = btn.getAttribute('data-action');
            title.textContent = 'Release ' + btn.getAttribute('data-name');
            stock.textContent = '(' + btn.getAttribute('data-stock') + ' ' + btn.getAttribute('data-unit') + ' in stock)';
            qty.max = btn.getAttribute('data-max');
            qty.value = btn.getAttribute('data-max');   // whole JO by default
            nameEl.value = '';
            noteEl.value = '';
            modal.hidden = false;
            setTimeout(function () { nameEl.focus(); }, 30);
        }
        function closeModal() { modal.hidden = true; }

        document.querySelectorAll('.js-release-open').forEach(function (b) {
            b.addEventListener('click', function () { openModal(b); });
        });
        document.getElementById('relCancel').addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });
    })();

    (function () {
        var search = document.getElementById('invSearch');
        var body = document.getElementById('invBody');
        var emptyMsg = document.getElementById('invEmpty');
        var countEl = document.getElementById('invCount');
        if (!search || !body) return;
        var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));
        var total = rows.length;

        function apply() {
            var q = search.value.trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (row) {
                var visible = !q || row.getAttribute('data-search').indexOf(q) !== -1;
                row.hidden = !visible;
                if (visible) shown++;
            });
            emptyMsg.hidden = shown !== 0;
            countEl.textContent = q ? 'Showing ' + shown + ' of ' + total : '';
        }

        search.addEventListener('input', apply);
        apply();
    })();
</script>
@endif
@endsection
