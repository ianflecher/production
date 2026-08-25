@extends('layouts.app')

@section('title', 'Edit '.$order->order_number.' — Imprint Production')
@section('page-title', 'Edit '.$order->order_number)

@section('content')
@php
    $curDeco = $order->decoration_methods ?? [];
    $canRouting = $order->canEditRouting();
@endphp

<div class="page-head">
    <div class="grow">
        <h1>Edit order {{ $order->order_number }}</h1>
        <p class="muted">Fix any mistake and save. @unless ($canRouting)<span style="color: var(--danger-ink);">Add-ons &amp; cutting are locked — those steps are already in production.</span>@endunless</p>
    </div>
</div>

<form method="POST" action="{{ route('orders.update', $order) }}" onsubmit="return confirmRush(this);">
    @csrf

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Client</h2>
        <div class="form-grid">
            <div class="field">
                <label for="client_name">First name <span style="color: var(--danger-ink);">*</span></label>
                <input id="client_name" type="text" name="client_name" value="{{ old('client_name', $order->client?->name ?? $order->customer_name) }}" maxlength="255" style="text-transform: capitalize;">
            </div>
            <div class="field">
                <label for="client_last_name">Last name <span style="color: var(--danger-ink);">*</span></label>
                <input id="client_last_name" type="text" name="client_last_name" value="{{ old('client_last_name', $order->client?->last_name) }}" maxlength="255" placeholder="e.g. Dela Cruz" style="text-transform: capitalize;">
            </div>
            <div class="field">
                <label for="client_contact">Contact number <span style="color: var(--danger-ink);">*</span></label>
                <input id="client_contact" type="text" name="client_contact" value="{{ old('client_contact', $order->client?->contact_number) }}" maxlength="255">
            </div>
            <div class="field">
                <label for="client_company">Company (optional)</label>
                <input id="client_company" type="text" name="client_company" value="{{ old('client_company', $order->client?->company) }}" maxlength="255" style="text-transform: capitalize;">
            </div>
            <div class="field">
                <label for="client_office_address">Office address <span style="color: var(--danger-ink);">*</span></label>
                <input id="client_office_address" type="text" name="client_office_address" value="{{ old('client_office_address', $order->client?->office_address) }}" maxlength="255" style="text-transform: capitalize;">
            </div>
            <div class="field">
                <label for="client_delivery_address">Delivery address <span style="color: var(--danger-ink);">*</span></label>
                <input id="client_delivery_address" type="text" name="client_delivery_address" value="{{ old('client_delivery_address', $order->client?->delivery_address) }}" maxlength="255" style="text-transform: capitalize;">
            </div>
            <div class="field">
                <label for="client_tin">TIN (optional — for invoice)</label>
                <input id="client_tin" type="text" name="client_tin" value="{{ old('client_tin', $order->client?->tin) }}" maxlength="50">
            </div>
        </div>
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Product &amp; price</h2>
        <p class="sub">Change the product or quantity and the price updates automatically.</p>

        @php $curSizes = $order->items->pluck('quantity', 'size'); @endphp
        @php $isCustomProduct = $order->product_type && ! array_key_exists($order->product_type, $products); @endphp
        <div class="field" style="max-width: 340px;">
            <label for="product_type">Product type</label>
            <select id="product_type" name="product_type" required onchange="updatePrice()">
                <option value="">— Choose product —</option>
                @foreach ($products as $key => $p)
                    <option value="{{ $key }}" @selected(old('product_type', $order->product_type) == $key)>{{ $p['label'] }}</option>
                @endforeach
                <option value="__other__" @selected(old('product_type', $isCustomProduct ? '__other__' : '') === '__other__')>Other apparel — type below (for quotation)</option>
            </select>
            <input type="text" id="product_type_custom" name="product_type_custom" maxlength="100"
                   value="{{ old('product_type_custom', $isCustomProduct ? $order->product_type : '') }}" placeholder="e.g. Rash Guard"
                   style="margin-top: 0.5rem; display: {{ old('product_type', $isCustomProduct ? '__other__' : '') === '__other__' ? 'block' : 'none' }};">
        </div>

        <div class="field">
            <label>Size breakdown — how many of each size?</label>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(78px, 1fr)); gap: 0.5rem;">
                @foreach (\App\Models\ProductionOrder::SIZES as $s)
                    <div style="border: 1px solid var(--border); border-radius: 8px; padding: 0.4rem 0.45rem; text-align: center; background: var(--surface);">
                        <div style="font-size: 0.7rem; font-weight: 700; color: var(--ink-2); letter-spacing: 0.04em;">{{ $s }}</div>
                        <input type="number" name="sizes[{{ $s }}]" min="0" max="100000" value="{{ old('sizes.'.$s, $curSizes[$s] ?? '') }}" placeholder="0"
                               class="size-input" style="padding: 0.3rem; text-align: center; font-size: 0.92rem; margin-top: 0.2rem;">
                    </div>
                @endforeach
            </div>
            {{-- Any size not on the chart is kept as a typed "Others" size. --}}
            @php $otherItem = $order->items->first(fn ($i) => ! in_array($i->size, \App\Models\ProductionOrder::SIZES, true)); @endphp
            <div style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:flex-end; margin-top:0.7rem;">
                <div style="flex:1; min-width:200px;">
                    <label for="other_size" style="font-size:0.78rem; color:var(--ink-2); font-weight:600;">Other size (not on the chart)</label>
                    <input id="other_size" type="text" name="other_size" maxlength="50" value="{{ old('other_size', $otherItem?->size) }}" placeholder="e.g. Kids 8">
                </div>
                <div style="width:120px;">
                    <label for="other_size_qty" style="font-size:0.78rem; color:var(--ink-2); font-weight:600;">Qty</label>
                    <input id="other_size_qty" type="number" name="other_size_qty" min="0" max="100000" value="{{ old('other_size_qty', $otherItem?->quantity) }}" placeholder="0" class="size-input" style="text-align:center;">
                </div>
            </div>

            <div style="margin-top: 0.7rem; font-size: 0.95rem;">
                Production total: <strong id="qtyOut">0</strong> pcs
            </div>
            <input type="hidden" id="quantity" value="{{ $order->quantity }}">
        </div>

        <div id="backPocketWrap" style="display: none; margin-bottom: 1rem;">
            <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 500; margin-bottom: 0.5rem;">
                <input type="checkbox" id="back_pocket" name="back_pocket" value="1" style="width:auto;margin:0;" @checked(old('back_pocket', $order->back_pocket)) onchange="onBackPocketToggle()">
                Add a back pocket (+₱{{ $backPocketFee }}/pc)
            </label>
            <div id="backPocketQtyWrap" style="display: {{ old('back_pocket', $order->back_pocket) ? 'flex' : 'none' }}; gap:0.6rem; align-items:flex-end; flex-wrap:wrap; margin-left:1.65rem;">
                <div style="width:150px;">
                    <label for="back_pocket_qty" style="font-size:0.78rem; color:var(--ink-2); font-weight:600;">How many pcs get one?</label>
                    <input id="back_pocket_qty" type="number" min="0" name="back_pocket_qty" value="{{ old('back_pocket_qty', $order->back_pocket_qty) }}" placeholder="all" class="no-caps" oninput="updatePrice()" style="text-align:center;">
                </div>
                <button type="button" class="btn btn-ghost btn-sm" onclick="setBackPocketAll()" style="margin-bottom:1px;">All pieces</button>
                <span id="backPocketNote" style="font-size:0.78rem; color:var(--ink-3);"></span>
            </div>
        </div>

        {{-- Rush order: the fee is agreed per job, so it is typed rather than
             taken from the price list. --}}
        <div style="margin-bottom: 1rem;">
            <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 500; margin-bottom: 0.5rem;">
                <input type="checkbox" id="rush" name="rush" value="1" style="width:auto;margin:0;"
                       @checked(old('rush', $order->rush)) onchange="onRushToggle()">
                🚨 Rush order
            </label>

            <div id="rushFeeWrap" style="display: {{ old('rush', $order->rush) ? 'flex' : 'none' }}; gap:0.6rem; align-items:flex-end; flex-wrap:wrap; margin-left:1.65rem;">
                <div style="width:190px;">
                    <label for="rush_fee" style="font-size:0.78rem; color:var(--ink-2); font-weight:600;">Rush fee (₱)</label>
                    <input id="rush_fee" type="number" name="rush_fee" step="0.01" min="0"
                           value="{{ old('rush_fee', (float) $order->rush_fee ?: '') }}" placeholder="e.g. 1500.00"
                           class="no-caps" oninput="updatePrice()">
                </div>
                <span style="font-size:0.78rem; color:var(--ink-3); margin-bottom:0.55rem;">added once to the order total</span>
            </div>
        </div>

        <div id="priceBox" style="background: var(--accent-soft); border: 1px solid #bfdbfe; border-radius: 10px; padding: 0.9rem 1.1rem;">
            <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                <div><div style="font-size:0.78rem;color:var(--ink-2);font-weight:600;">Price per piece</div><div id="unitOut" style="font-size:1.4rem;font-weight:600;">—</div></div>
                <div style="text-align:right;"><div style="font-size:0.78rem;color:var(--ink-2);font-weight:600;">Estimated total</div><div id="totalOut" style="font-size:1.4rem;font-weight:600;">—</div></div>
            </div>
            <div id="priceNote" style="font-size:0.78rem;color:var(--ink-2);margin-top:0.4rem;"></div>
        </div>

        {{-- Only relevant for custom apparel, a custom size (CS), or a quotation. --}}
        {{-- CS and a typed "other size" are not on the price list, so they get
             their own price per piece while the charted sizes keep the tier. --}}
        <div id="customSizeSection" class="field" style="margin-top:0.8rem; display:none; max-width:420px;">
            <label for="custom_size_price">Price / piece for the off-chart sizes <span id="customSizeWhich" style="font-weight:400; color:var(--ink-3);"></span></label>
            <input id="custom_size_price" type="number" name="custom_size_price" step="0.01" min="0"
                   value="{{ old('custom_size_price', $order->custom_size_price) }}" placeholder="e.g. 800.00" oninput="updatePrice()">
            <div id="customSizeNote" style="font-size:0.78rem; color:var(--ink-3); margin-top:0.35rem;"></div>
        </div>

        <div id="overrideSection" style="margin-top: 0.8rem; display: none;">
            <button type="button" id="overrideToggle" class="btn btn-ghost btn-sm" onclick="showOverride()" style="{{ $priceOverride !== null ? 'display:none;' : '' }}">Set custom price / quotation</button>
            <div id="overrideWrap" class="field" style="display: {{ old('unit_price_override', $priceOverride) !== null ? 'block' : 'none' }}; max-width: 340px; margin-top: 0.6rem;">
                <label for="unit_price_override">Custom price / piece</label>
                <input id="unit_price_override" type="number" name="unit_price_override" step="0.01" min="0" value="{{ old('unit_price_override', $priceOverride) }}" placeholder="e.g. 500.00" oninput="updatePrice()">
            </div>
        </div>

        {{-- Discount / sponsorship comes off the TOTAL, then VAT is added. --}}
        <div style="margin-top: 1rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
            <div class="field" style="width: 200px; margin:0;">
                <label for="discount_amount">Discount / sponsorship (₱ off total)</label>
                <input id="discount_amount" type="number" name="discount_amount" step="0.01" min="0" value="{{ old('discount_amount', (float) $order->discount_amount ?: '') }}" placeholder="0.00" oninput="updatePrice()">
            </div>
            <div class="field" style="flex:1; min-width:220px; margin:0;">
                <label for="discount_note">Reason (optional)</label>
                <input id="discount_note" type="text" name="discount_note" maxlength="255" value="{{ old('discount_note', $order->discount_note) }}" placeholder="e.g. team sponsorship">
            </div>
        </div>
        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:400; margin-top:0.8rem;">
            <input type="checkbox" id="vat_inclusive" name="vat_inclusive" value="1" style="width:auto;margin:0;" @checked(old('vat_inclusive', $order->vat_inclusive)) onchange="updatePrice()">
            VAT inclusive — add 12% to the total
        </label>
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Job details</h2>
        <div class="field" style="max-width: 340px;">
            <label for="due_date">Due date <span style="color: var(--danger-ink);">*</span></label>
            <input id="due_date" type="date" name="due_date" required value="{{ old('due_date', $order->due_date?->toDateString()) }}" onchange="checkCapacity(); onDueDatePicked();">
            <div id="capacityNote" style="font-size: 0.78rem; color: var(--ink-3); margin-top: 0.35rem;"></div>
            <div id="rushNote"></div>
            @error('due_date')<span class="error">{{ $message }}</span>@enderror
        </div>
    </div>

    @if($order->mockupApproved())
        <p class="sub" style="margin-bottom: 1.4rem;">Add-ons, cutting &amp; production specs are set on the <a href="{{ route('job-orders.edit', $order) }}">Tech Pack</a>.</p>
    @endif

    <div style="display: flex; gap: 0.75rem;">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="{{ route('orders.show', $order) }}" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<script>
    const PRICING = @json($products);
    const BACK_POCKET_FEE = {{ $backPocketFee }};
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function showOverride() {
        document.getElementById('overrideWrap').style.display = 'block';
        document.getElementById('overrideToggle').style.display = 'none';
        document.getElementById('unit_price_override').focus();
    }

    function updatePrice() {
        const type = document.getElementById('product_type').value;

        const customEl = document.getElementById('product_type_custom');
        if (customEl) {
            customEl.style.display = type === '__other__' ? 'block' : 'none';
            customEl.required = type === '__other__';
        }
        const qty = parseInt(document.getElementById('quantity').value) || 0;
        const product = PRICING[type];

        const bpWrap = document.getElementById('backPocketWrap');
        const bpBox = document.getElementById('back_pocket');
        if (product && product.back_pocket) { bpWrap.style.display = 'block'; }
        else { bpWrap.style.display = 'none'; bpBox.checked = false; }
        document.getElementById('backPocketQtyWrap').style.display = bpBox.checked ? 'flex' : 'none';

        const backPocket = bpBox.checked;

        // The custom price only applies to custom apparel, a custom size (CS), a
        // quotation (no tier price), or an order that already had one saved.
        const overrideEl = document.getElementById('unit_price_override');
        const csQty = parseInt(document.querySelector('input[name="sizes[CS]"]')?.value) || 0;
        const otherLabel = (document.getElementById('other_size')?.value || '').trim();
        const otherQty = otherLabel ? (parseInt(document.getElementById('other_size_qty')?.value) || 0) : 0;
        const offChartQty = csQty + otherQty;

        let tierBase = null;
        if (product && qty > 0) {
            for (const t of product.tiers) { if (qty >= t.min && qty <= t.max) { tierBase = Number(t.price); break; } }
        }
        const hadOverride = {{ $priceOverride !== null ? 'true' : 'false' }};

        // The whole order goes to a typed price when there is no list for the
        // product, the quantity is past the top tier, or one was already saved.
        const wholeOrderIsCustom = type === '__other__' || (qty > 0 && tierBase === null) || hadOverride;

        const csSec = document.getElementById('customSizeSection');
        const csEl = document.getElementById('custom_size_price');
        const showOffChart = offChartQty > 0 && !wholeOrderIsCustom;
        if (csSec) {
            csSec.style.display = showOffChart ? 'block' : 'none';
            if (showOffChart) {
                const which = [];
                if (csQty > 0) which.push(csQty + ' CS');
                if (otherQty > 0) which.push(otherQty + ' ' + otherLabel);
                document.getElementById('customSizeWhich').textContent = '(' + which.join(' + ') + ')';
            } else if (csEl.value) {
                csEl.value = '';
            }
        }
        const offChartUnit = showOffChart ? (parseFloat(csEl.value) || 0) : 0;

        const needsCustomPrice = wholeOrderIsCustom;
        const sec = document.getElementById('overrideSection');
        if (sec) {
            sec.style.display = needsCustomPrice ? 'block' : 'none';
            if (needsCustomPrice) {
                document.getElementById('overrideWrap').style.display = 'block';
                document.getElementById('overrideToggle').style.display = 'none';
            } else if (overrideEl.value) {
                overrideEl.value = '';
            }
        }

        const override = parseFloat(overrideEl.value);
        const unitOut = document.getElementById('unitOut');
        const totalOut = document.getElementById('totalOut');
        const note = document.getElementById('priceNote');

        let unit = null, noteText = '';
        if (!isNaN(override) && override > 0) {
            unit = override; noteText = 'Custom price (override).';
        } else if (product && qty > 0) {
            let base = null;
            for (const t of product.tiers) { if (qty >= t.min && qty <= t.max) { base = Number(t.price); break; } }
            if (base === null) { noteText = 'Over 100 pcs — enter a custom price for the quotation.'; }
            else {
                unit = base;   // garment price only; back pocket is separate
                noteText = 'Tier price for ' + qty + ' pcs.';
            }
        }

        // Back pocket is charged separately (its own line on the quotation).
        let pocketQty = 0, pocketAmount = 0;
        const bpNote = document.getElementById('backPocketNote');
        if (backPocket && product && product.back_pocket) {
            const raw = document.getElementById('back_pocket_qty').value;
            pocketQty = raw === '' ? qty : (parseInt(raw) || 0);
            pocketQty = Math.max(0, Math.min(pocketQty, qty));
            pocketAmount = pocketQty * BACK_POCKET_FEE;
            if (bpNote) bpNote.textContent = pocketQty > 0
                ? ((pocketQty === qty ? 'All ' + qty : pocketQty + ' of ' + qty) + ' pcs × ₱' + BACK_POCKET_FEE + ' = ' + peso(pocketAmount))
                : 'No pieces selected.';
        } else if (bpNote) { bpNote.textContent = ''; }

        // The rush fee is a one-off charge on the job, not a per-piece rate.
        const rushOn = document.getElementById('rush')?.checked;
        const rushFee = rushOn ? (parseFloat(document.getElementById('rush_fee')?.value) || 0) : 0;

        // Total = (unit x qty) + back pocket + rush, less discount, then +12% VAT when ticked.
        const discount = parseFloat(document.getElementById('discount_amount')?.value) || 0;
        const vatOn = document.getElementById('vat_inclusive')?.checked;

        if (unit !== null && qty > 0) {
            const chartedQty = showOffChart ? Math.max(0, qty - offChartQty) : qty;
            const offChartAmount = showOffChart ? offChartUnit * offChartQty : 0;
            const garment = (unit * chartedQty) + offChartAmount;

            const csNote = document.getElementById('customSizeNote');
            if (csNote) {
                csNote.textContent = showOffChart
                    ? (offChartUnit > 0
                        ? offChartQty + ' pcs x ' + peso(offChartUnit) + ' = ' + peso(offChartAmount)
                          + '  •  the other ' + chartedQty + ' pcs stay at ' + peso(unit)
                        : 'Set a price for these ' + offChartQty + ' pcs — the other ' + chartedQty + ' are at ' + peso(unit) + '.')
                    : '';
            }
            const subtotal = garment + pocketAmount + rushFee;
            const vatable = Math.max(0, subtotal - discount);
            const vat = vatOn ? vatable * 0.12 : 0;
            unitOut.textContent = peso(unit);
            totalOut.textContent = peso(vatable + vat);
            const bits = [];
            if (showOffChart && offChartUnit > 0) bits.push(offChartQty + ' off-chart pcs at ' + peso(offChartUnit));
            if (pocketAmount > 0) bits.push('back pocket ' + peso(pocketAmount));
            if (rushFee > 0) bits.push('rush ' + peso(rushFee));
            if (discount > 0) bits.push('less ' + peso(Math.min(discount, subtotal)) + ' discount');
            if (vatOn) bits.push('+12% VAT ' + peso(vat));
            noteText = (noteText ? noteText + ' ' : '') + 'Garment ' + peso(garment)
                + (bits.length ? ', ' + bits.join(', ') : '') + '.';
        } else { unitOut.textContent = unit !== null ? peso(unit) : '—'; totalOut.textContent = (pocketAmount > 0 && qty > 0) ? peso(pocketAmount) : '—'; }
        note.textContent = noteText;
    }

    // Reveal the "how many pcs" field when back pocket is ticked (defaults to all).
    function onBackPocketToggle() {
        const on = document.getElementById('back_pocket').checked;
        document.getElementById('backPocketQtyWrap').style.display = on ? 'flex' : 'none';
        const qtyEl = document.getElementById('back_pocket_qty');
        if (on && qtyEl.value === '') { qtyEl.value = document.getElementById('quantity').value || ''; }
        updatePrice();
    }
    // Reveal the fee box when the order is marked rush, and clear it when it
    // is unticked so an untouched fee can't linger on a normal job.
    function onRushToggle() {
        const on = document.getElementById('rush').checked;
        document.getElementById('rushFeeWrap').style.display = on ? 'flex' : 'none';
        if (! on) { document.getElementById('rush_fee').value = ''; }
        updatePrice();
    }
    function setBackPocketAll() {
        document.getElementById('back_pocket_qty').value = document.getElementById('quantity').value || 0;
        updatePrice();
    }

    // Show how full the chosen due date already is (this order excluded).
    function checkCapacity() {
        const el = document.getElementById('due_date');
        const out = document.getElementById('capacityNote');
        if (!el || !out || !el.value) { if (out) out.textContent = ''; return; }
        fetch('{{ route('orders.capacity') }}?except={{ $order->id }}&date=' + encodeURIComponent(el.value))
            .then(r => r.json())
            .then(d => {
                const qty = parseInt(document.getElementById('quantity').value) || 0;
                const over = d.booked + qty > d.capacity;
                out.textContent = d.booked + ' of ' + d.capacity + ' pcs already booked for this date'
                    + (over ? ' — this order (' + qty + ' pcs) will not fit. Pick another date.' : ' · ' + d.remaining + ' left');
                out.style.color = over ? 'var(--danger-ink)' : 'var(--ink-3)';
                out.style.fontWeight = over ? '600' : '400';
            })
            .catch(() => { out.textContent = ''; });
    }

    function updateQty() {
        let total = 0;
        document.querySelectorAll('.size-input').forEach(i => { total += parseInt(i.value) || 0; });
        document.getElementById('quantity').value = total;
        document.getElementById('qtyOut').textContent = total;
        updatePrice();
    }
    document.querySelectorAll('.size-input').forEach(i => i.addEventListener('input', updateQty));
    document.getElementById('other_size')?.addEventListener('input', updatePrice);
    updateQty();
    checkCapacity();

    // A due date inside the shop's lead time is a rush job. The calendar shows
    // it afterwards, in red, which is the wrong moment — by then the promise is
    // made. Say it while the date is still being picked, and again on the way
    // out, so it is a decision rather than something noticed later.
    const RUSH_DAYS = {{ \App\Models\ProductionOrder::RUSH_NOTICE_DAYS }};

    function daysUntilDue() {
        const el = document.getElementById('due_date');
        if (!el || !el.value) { return null; }

        // Compare dates, not moments: a date input has no time, so a plain
        // subtraction makes "tomorrow" look like today for most of the day.
        const due = new Date(el.value + 'T00:00:00');
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        return Math.round((due - today) / 86400000);
    }

    function showRushNote() {
        const out = document.getElementById('rushNote');
        if (!out) { return; }

        const days = daysUntilDue();

        if (days === null || days > RUSH_DAYS) { out.textContent = ''; return; }

        out.textContent = days < 0
            ? '⚠ That date has already passed.'
            : (days === 0 ? '⚠ Due today' : '⚠ Only ' + days + ' day' + (days === 1 ? '' : 's') + ' away')
                + ' — under the ' + RUSH_DAYS + '-day lead time. Tick Rush order if the client is paying for it.';
        out.style.color = 'var(--danger-ink)';
        out.style.fontWeight = '600';
        out.style.fontSize = '0.78rem';
        out.style.marginTop = '0.35rem';
    }

    // Asked the moment the date is picked, because that is when it can still be
    // changed painlessly — waiting for the submit means re-deciding at the end
    // of a long form. Saying no clears the box rather than leaving a date
    // nobody agreed to sitting in it.
    function onDueDatePicked() {
        showRushNote();

        const days = daysUntilDue();

        if (days === null || days > RUSH_DAYS) { rushAgreed = false; return; }

        window.icConfirm(rushQuestion()).then(ok => {
            const el = document.getElementById('due_date');

            // Saying no clears the box rather than leaving a date nobody
            // agreed to sitting in it.
            if (! ok) {
                el.value = '';
                rushAgreed = false;
                showRushNote();
                checkCapacity();
                el.focus();
                return;
            }

            // Already answered, so the submit does not ask a second time.
            rushAgreed = true;
        });
    }

    // Wording shared by the two moments it can be asked.
    function rushQuestion() {
        const days = daysUntilDue();
        const when = days < 0 ? 'a date that has already passed'
            : (days === 0 ? 'today' : days + ' day' + (days === 1 ? '' : 's') + ' from now');

        return {
            title: 'Are you sure?',
            message: 'This order is due ' + when + '.'
                + String.fromCharCode(10) + String.fromCharCode(10)
                + 'The shop needs about ' + RUSH_DAYS + ' days to take a job from layout '
                + 'to finished goods.',
            confirmText: 'Yes, keep this date',
            cancelText: 'Pick another date',
            tone: 'danger',
        };
    }

    // The submit is held, not blocked: the dialog answers later, so the form is
    // released once somebody has actually said yes.
    let rushAgreed = false;

    function confirmRush(form) {
        const days = daysUntilDue();

        if (rushAgreed || days === null || days > RUSH_DAYS) { return true; }

        window.icConfirm(rushQuestion()).then(ok => {
            if (! ok) {
                document.getElementById('due_date').focus();
                return;
            }

            rushAgreed = true;
            form.submit();
        });

        return false;
    }

    showRushNote();
</script>
@endsection
