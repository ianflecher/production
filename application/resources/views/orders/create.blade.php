@extends('layouts.app')

@section('title', 'New Job Order — Imprint Production')
@section('page-title', 'New Job Order')

@section('content')
<style>
    /* Everything after the product picker is locked until a product is chosen. */
    .gated { opacity: 0.4; pointer-events: none; filter: grayscale(0.25); user-select: none; }
    .gated * { cursor: not-allowed; }
    .product-hint {
        display: flex; align-items: center; gap: 0.5rem;
        background: linear-gradient(90deg, rgba(37,99,235,.10), rgba(139,92,246,.08));
        border: 1px dashed var(--accent); color: #1d4ed8;
        border-radius: 10px; padding: 0.7rem 0.95rem; font-size: 0.86rem; font-weight: 600;
        margin: 0.2rem 0 1rem;
    }

    /* ---- Size breakdown grid ---- */
    .size-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 0.55rem; }
    .size-cell {
        border: 1px solid var(--border); border-radius: 11px; padding: 0.5rem 0.4rem;
        text-align: center; background: linear-gradient(180deg, #fbfcff, #ffffff);
        transition: border-color .12s ease, box-shadow .12s ease, transform .12s ease;
    }
    .size-cell:focus-within {
        border-color: var(--accent); transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(37, 99, 235, .16);
    }
    .size-cell .size-name {
        font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; color: #6b7a92;
        text-transform: uppercase; transition: color .12s ease;
    }
    .size-cell:focus-within .size-name { color: var(--accent); }
    .size-input { width: 100%; padding: 0.34rem !important; text-align: center; border-radius: 8px; margin-top: 0.25rem; }
    /* A size with a number entered lights up so the filled sizes stand out. */
    .size-input:not(:placeholder-shown) {
        border-color: var(--accent); background: var(--accent-soft);
        font-weight: 800; color: #1d4ed8;
    }

    /* ---- Production total chip ---- */
    .total-chip {
        display: inline-flex; align-items: center; gap: 0.4rem;
        background: linear-gradient(120deg, rgba(22,163,74,.14), rgba(37,99,235,.12));
        border: 1px solid rgba(22,163,74,.3); border-radius: 999px;
        padding: 0.35rem 0.85rem; font-size: 0.9rem; font-weight: 700; color: #15803d;
    }
    .total-chip strong { font-size: 1.05rem; color: #0f5132; }

    /* ---- Price summary: prominent gradient card ---- */
    .price-box {
        background:
            linear-gradient(120deg, rgba(37,99,235,.12), rgba(139,92,246,.10) 55%, rgba(16,185,129,.10)),
            #ffffff;
        border: 1px solid #cdd9f2; border-radius: 16px; padding: 1.15rem 1.3rem;
        box-shadow: 0 10px 26px rgba(37, 99, 235, .10);
    }
    .price-box .pb-lbl { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 800; color: #5b6b8c; }
    .price-box .pb-val { font-size: 1.75rem; font-weight: 850; line-height: 1.1; }
    .price-box #unitOut { color: #1d4ed8; }
    .price-box #totalOut { color: #7c3aed; }
</style>
<div class="page-head">
    <div class="grow">
        <h1>New job order</h1>
        <p class="muted">Enter the job order number, client and job details. Next you'll upload the client reference and send it to an artist for the layout — the downpayment and job order come after the client approves it.</p>
    </div>
</div>

<form method="POST" action="{{ route('orders.store') }}" class="form-steps" onsubmit="return confirmRush();">
    @csrf

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Job order number</h2>
        <div class="field" style="max-width: 300px;">
            <label for="order_number">Job order #</label>
            <input id="order_number" type="text" name="order_number" required maxlength="50" value="{{ old('order_number', $nextNumber) }}" placeholder="e.g. IC2026-00016">
            @error('order_number')<span class="error">{{ $message }}</span>@enderror
        </div>
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Client</h2>
        <p class="sub">Pick an existing client, or add a new one.</p>

        <div class="field" style="max-width: 420px;">
            <label for="client_id">Existing client</label>
            <select id="client_id" name="client_id" onchange="toggleClientMode()">
                <option value="">— New client (fill in below) —</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->listName() }}@if ($client->company) — {{ $client->company }}@endif</option>
                @endforeach
            </select>
        </div>

        <div id="newClient" style="{{ old('client_id') ? 'display:none;' : '' }}">
            <div class="form-grid">
                <div class="field">
                    <label for="client_name">First name <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_name" type="text" name="client_name" value="{{ old('client_name') }}" maxlength="255" placeholder="e.g. Juan" style="text-transform: capitalize;">
                    @error('client_name')<span class="error">{{ $message }}</span>@enderror
                </div>
                {{-- Held apart from the first name so the client list sorts by
                     family name rather than by whatever was typed first. --}}
                <div class="field">
                    <label for="client_last_name">Last name <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_last_name" type="text" name="client_last_name" value="{{ old('client_last_name') }}" maxlength="255" placeholder="e.g. Dela Cruz" style="text-transform: capitalize;">
                    @error('client_last_name')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_contact">Contact number <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_contact" type="text" name="client_contact" value="{{ old('client_contact') }}" maxlength="255" placeholder="e.g. 0917-555-1234">
                    @error('client_contact')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_company">Company (optional)</label>
                    <input id="client_company" type="text" name="client_company" value="{{ old('client_company') }}" maxlength="255" placeholder="e.g. Falcon Riders" style="text-transform: capitalize;">
                </div>
                <div class="field">
                    <label for="client_office_address">Office address <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_office_address" type="text" name="client_office_address" value="{{ old('client_office_address') }}" maxlength="255" placeholder="e.g. 12 Rizal St., Angeles City" style="text-transform: capitalize;">
                    @error('client_office_address')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_delivery_address">Delivery address <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_delivery_address" type="text" name="client_delivery_address" value="{{ old('client_delivery_address') }}" maxlength="255" placeholder="Where the order is delivered" style="text-transform: capitalize;">
                    @error('client_delivery_address')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_tin">TIN (optional — for invoice)</label>
                    <input id="client_tin" type="text" name="client_tin" value="{{ old('client_tin') }}" maxlength="50" placeholder="e.g. 123-456-789-000">
                </div>
            </div>
        </div>
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Product &amp; price</h2>
        <p class="sub">Choose the product first, then the quantity — the price per piece is set automatically. Over 100 pcs needs a quotation; type a custom price below.</p>

        <div class="field" style="max-width: 340px;">
            <label for="product_type">Product type</label>
            <select id="product_type" name="product_type" required onchange="updatePrice()">
                <option value="">— Choose product —</option>
                @foreach ($products as $key => $p)
                    <option value="{{ $key }}" @selected(old('product_type') == $key)>{{ $p['label'] }}</option>
                @endforeach
                <option value="__other__" @selected(old('product_type') === '__other__')>Other apparel — type below (for quotation)</option>
            </select>
            <input type="text" id="product_type_custom" name="product_type_custom" maxlength="100"
                   value="{{ old('product_type_custom') }}" placeholder="e.g. Rash Guard"
                   style="margin-top: 0.5rem; display: {{ old('product_type') === '__other__' ? 'block' : 'none' }};">
        </div>

        <div id="productHint" class="product-hint" style="{{ old('product_type') ? 'display:none;' : '' }}">
            <span aria-hidden="true">①</span> Choose a product type above to set the sizes and price.
        </div>

        <div id="afterProduct" class="{{ old('product_type') ? '' : 'gated' }}">
        <div class="field">
            <label>Size breakdown — how many of each size?</label>
            <div class="size-grid">
                @foreach (\App\Models\ProductionOrder::SIZES as $s)
                    <div class="size-cell">
                        <div class="size-name">{{ $s }}</div>
                        <input type="number" name="sizes[{{ $s }}]" min="0" max="100000" value="{{ old('sizes.'.$s) }}" placeholder="0"
                               class="size-input" style="font-size: 0.92rem;">
                    </div>
                @endforeach
            </div>
            {{-- A size that isn't on the chart — type the name and quantity. --}}
            <div style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:flex-end; margin-top:0.7rem;">
                <div style="flex:1; min-width:200px;">
                    <label for="other_size" style="font-size:0.78rem; color:var(--ink-2); font-weight:600;">Other size (not on the chart)</label>
                    <input id="other_size" type="text" name="other_size" maxlength="50" value="{{ old('other_size') }}" placeholder="e.g. Kids 8">
                </div>
                <div style="width:120px;">
                    <label for="other_size_qty" style="font-size:0.78rem; color:var(--ink-2); font-weight:600;">Qty</label>
                    <input id="other_size_qty" type="number" name="other_size_qty" min="0" max="100000" value="{{ old('other_size_qty') }}" placeholder="0" class="size-input" style="text-align:center;">
                </div>
            </div>

            <div style="margin-top: 0.8rem; display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                <span class="total-chip">📦 Production total: <strong id="qtyOut">0</strong> pcs</span>
                <span style="font-size: 0.78rem; color: var(--ink-3);">leave sizes blank if not needed</span>
            </div>
            <input type="hidden" id="quantity" value="0">
        </div>

        <div id="backPocketWrap" style="display: none; margin-bottom: 1rem;">
            <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 500; margin-bottom: 0.5rem;">
                <input type="checkbox" id="back_pocket" name="back_pocket" value="1" style="width:auto;margin:0;" @checked(old('back_pocket')) onchange="onBackPocketToggle()">
                Add a back pocket (+₱{{ $backPocketFee }}/pc)
            </label>
            {{-- Revealed when ticked: how many of the pieces get a back pocket. --}}
            <div id="backPocketQtyWrap" style="display: {{ old('back_pocket') ? 'flex' : 'none' }}; gap:0.6rem; align-items:flex-end; flex-wrap:wrap; margin-left:1.65rem;">
                <div style="width:150px;">
                    <label for="back_pocket_qty" style="font-size:0.78rem; color:var(--ink-2); font-weight:600;">How many pcs get one?</label>
                    <input id="back_pocket_qty" type="number" min="0" name="back_pocket_qty" value="{{ old('back_pocket_qty') }}" placeholder="all" class="no-caps" oninput="updatePrice()" style="text-align:center;">
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
                       @checked(old('rush')) onchange="onRushToggle()">
                🚨 Rush order
            </label>

            <div id="rushFeeWrap" style="display: {{ old('rush') ? 'flex' : 'none' }}; gap:0.6rem; align-items:flex-end; flex-wrap:wrap; margin-left:1.65rem;">
                <div style="width:190px;">
                    <label for="rush_fee" style="font-size:0.78rem; color:var(--ink-2); font-weight:600;">Rush fee (₱)</label>
                    <input id="rush_fee" type="number" name="rush_fee" step="0.01" min="0"
                           value="{{ old('rush_fee') }}" placeholder="e.g. 1500.00"
                           class="no-caps" oninput="updatePrice()">
                </div>
                <span style="font-size:0.78rem; color:var(--ink-3); margin-bottom:0.55rem;">added once to the order total</span>
            </div>
        </div>

        <div id="priceBox" class="price-box">
            <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
                <div><div class="pb-lbl">Price per piece</div><div id="unitOut" class="pb-val">—</div></div>
                <div style="text-align:right;"><div class="pb-lbl">Estimated total</div><div id="totalOut" class="pb-val">—</div></div>
            </div>
            <div id="priceNote" style="font-size:0.8rem;color:#4b5b7a;margin-top:0.5rem;font-weight:500;"></div>
        </div>

        {{-- Only relevant for custom apparel, a custom size (CS), or a quotation. --}}
        <div id="overrideSection" style="margin-top: 0.8rem; display: none;">
            <button type="button" id="overrideToggle" class="btn btn-ghost btn-sm" onclick="showOverride()">Set custom price / quotation</button>
            <div id="overrideWrap" class="field" style="display: {{ old('unit_price_override') ? 'block' : 'none' }}; max-width: 340px; margin-top: 0.6rem;">
                <label for="unit_price_override">Custom price / piece</label>
                <input id="unit_price_override" type="number" name="unit_price_override" step="0.01" min="0" value="{{ old('unit_price_override') }}" placeholder="e.g. 500.00" oninput="updatePrice()">
            </div>
        </div>

        {{-- Discount / sponsorship comes off the TOTAL, then VAT is added. --}}
        <div style="margin-top: 1rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
            <div class="field" style="width: 200px; margin:0;">
                <label for="discount_amount">Discount / sponsorship (₱ off total)</label>
                <input id="discount_amount" type="number" name="discount_amount" step="0.01" min="0" value="{{ old('discount_amount') }}" placeholder="0.00" oninput="updatePrice()">
            </div>
            <div class="field" style="flex:1; min-width:220px; margin:0;">
                <label for="discount_note">Reason (optional)</label>
                <input id="discount_note" type="text" name="discount_note" maxlength="255" value="{{ old('discount_note') }}" placeholder="e.g. team sponsorship">
            </div>
        </div>
        <label style="display:flex; align-items:center; gap:0.5rem; font-weight:400; margin-top:0.8rem;">
            <input type="checkbox" id="vat_inclusive" name="vat_inclusive" value="1" style="width:auto;margin:0;" @checked(old('vat_inclusive')) onchange="updatePrice()">
            VAT inclusive — add 12% to the total
        </label>
        </div>{{-- /#afterProduct --}}
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Job details</h2>
        <div class="field" style="max-width: 340px;">
            <label for="due_date">Due date <span style="color: var(--danger-ink);">*</span></label>
            <input id="due_date" type="date" name="due_date" required value="{{ old('due_date') }}" onchange="checkCapacity(); showRushNote();">
            <div id="capacityNote" style="font-size: 0.78rem; color: var(--ink-3); margin-top: 0.35rem;"></div>
            <div id="rushNote"></div>
            @error('due_date')<span class="error">{{ $message }}</span>@enderror
        </div>

        <label style="display:flex; align-items:flex-start; gap:0.5rem; font-weight:500; margin-top:0.9rem; max-width:480px;">
            <input type="checkbox" id="skip_sample" name="skip_sample" value="1" style="width:auto; margin:0.2rem 0 0;" @checked(old('skip_sample'))>
            <span>No client sample — go straight to mass production
                <span style="display:block; font-weight:400; font-size:0.78rem; color:var(--ink-3);">Skips the first-sample run and its client approval; the full order is produced directly.</span>
            </span>
        </label>
    </div>

    <div style="display: flex; gap: 0.75rem;">
        <button type="submit" class="btn btn-primary">Create job order</button>
        <a href="{{ route('orders.index') }}" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<script>
    const PRICING = @json($products);
    const BACK_POCKET_FEE = {{ $backPocketFee }};
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // A new client needs a complete contact record. Existing clients already
    // have their saved details, so selecting one hides and disables this block.
    function toggleClientMode() {
        const isNew = !document.getElementById('client_id').value;
        const fields = document.querySelectorAll('#newClient input');
        document.getElementById('newClient').style.display = isNew ? 'block' : 'none';
        fields.forEach(field => { field.disabled = !isNew; });
        ['client_name', 'client_last_name', 'client_contact', 'client_office_address', 'client_delivery_address']
            .forEach(id => { document.getElementById(id).required = isNew; });
    }

    function showOverride() {
        document.getElementById('overrideWrap').style.display = 'block';
        document.getElementById('overrideToggle').style.display = 'none';
        document.getElementById('unit_price_override').focus();
    }

    // Lock the size/price section until a product type is chosen.
    function applyGate() {
        const has = !!document.getElementById('product_type').value;
        const wrap = document.getElementById('afterProduct');
        const hint = document.getElementById('productHint');
        if (wrap) wrap.classList.toggle('gated', !has);
        if (hint) hint.style.display = has ? 'none' : 'flex';
    }

    function updatePrice() {
        applyGate();
        const type = document.getElementById('product_type').value;
        const qty = parseInt(document.getElementById('quantity').value) || 0;
        const product = PRICING[type];

        // "Other" apparel is free-text and always goes to quotation.
        const customEl = document.getElementById('product_type_custom');
        if (customEl) {
            customEl.style.display = type === '__other__' ? 'block' : 'none';
            customEl.required = type === '__other__';
        }

        // Only show the back-pocket option for products that support it.
        const bpWrap = document.getElementById('backPocketWrap');
        const bpBox = document.getElementById('back_pocket');
        if (product && product.back_pocket) {
            bpWrap.style.display = 'block';
        } else {
            bpWrap.style.display = 'none';
            bpBox.checked = false;
        }
        document.getElementById('backPocketQtyWrap').style.display = bpBox.checked ? 'flex' : 'none';

        const backPocket = bpBox.checked;

        // The custom price only applies to custom apparel, a custom size (CS),
        // or when no tier price exists (over 100 pcs → quotation). Hide it
        // otherwise so standard products always use the automatic price.
        const overrideEl = document.getElementById('unit_price_override');
        const csQty = parseInt(document.querySelector('input[name="sizes[CS]"]')?.value) || 0;
        let tierBase = null;
        if (product && qty > 0) {
            for (const t of product.tiers) { if (qty >= t.min && qty <= t.max) { tierBase = Number(t.price); break; } }
        }
        const needsCustomPrice = type === '__other__' || csQty > 0 || (qty > 0 && tierBase === null);
        const sec = document.getElementById('overrideSection');
        if (sec) {
            sec.style.display = needsCustomPrice ? 'block' : 'none';
            if (needsCustomPrice) {
                // It's required in these cases, so open it straight away.
                document.getElementById('overrideWrap').style.display = 'block';
                document.getElementById('overrideToggle').style.display = 'none';
            } else if (overrideEl.value) {
                // Don't let a hidden field silently price the order.
                overrideEl.value = '';
            }
        }

        const override = parseFloat(overrideEl.value);
        const unitOut = document.getElementById('unitOut');
        const totalOut = document.getElementById('totalOut');
        const note = document.getElementById('priceNote');

        let unit = null, noteText = '';

        if (!isNaN(override) && override > 0) {
            unit = override;
            noteText = 'Custom price (override).';
        } else if (product && qty > 0) {
            let base = null;
            for (const t of product.tiers) {
                if (qty >= t.min && qty <= t.max) { base = Number(t.price); break; }
            }
            if (base === null) {
                noteText = 'Over 100 pcs — enter a custom price for the quotation.';
            } else {
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
            const garment = unit * qty;
            const subtotal = garment + pocketAmount + rushFee;
            const vatable = Math.max(0, subtotal - discount);
            const vat = vatOn ? vatable * 0.12 : 0;
            unitOut.textContent = peso(unit);
            totalOut.textContent = peso(vatable + vat);
            const bits = [];
            if (pocketAmount > 0) bits.push('back pocket ' + peso(pocketAmount));
            if (rushFee > 0) bits.push('rush ' + peso(rushFee));
            if (discount > 0) bits.push('less ' + peso(Math.min(discount, subtotal)) + ' discount');
            if (vatOn) bits.push('+12% VAT ' + peso(vat));
            noteText = (noteText ? noteText + ' ' : '') + 'Garment ' + peso(garment)
                + (bits.length ? ', ' + bits.join(', ') : '') + '.';
        } else {
            unitOut.textContent = unit !== null ? peso(unit) : '—';
            const extras = pocketAmount + rushFee;
            totalOut.textContent = (extras > 0 && qty > 0) ? peso(extras) : '—';
        }
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
    // isn't — a hidden value must never end up on the client's total.
    function onRushToggle() {
        const on = document.getElementById('rush').checked;
        const wrap = document.getElementById('rushFeeWrap');
        const feeEl = document.getElementById('rush_fee');
        wrap.style.display = on ? 'flex' : 'none';
        if (!on) { feeEl.value = ''; }
        if (on) { setTimeout(function () { feeEl.focus(); }, 30); }
        updatePrice();
    }
    function setBackPocketAll() {
        document.getElementById('back_pocket_qty').value = document.getElementById('quantity').value || 0;
        updatePrice();
    }

    // Show how full the chosen due date already is (server still enforces it).
    function checkCapacity() {
        const el = document.getElementById('due_date');
        const out = document.getElementById('capacityNote');
        if (!el || !out || !el.value) { if (out) out.textContent = ''; return; }
        fetch('{{ route('orders.capacity') }}?date=' + encodeURIComponent(el.value))
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
    // Size breakdown drives the quantity, which drives the price tier.
    function updateQty() {
        let total = 0;
        document.querySelectorAll('.size-input').forEach(i => { total += parseInt(i.value) || 0; });
        document.getElementById('quantity').value = total;
        document.getElementById('qtyOut').textContent = total;
        updatePrice();
    }
    document.querySelectorAll('.size-input').forEach(i => i.addEventListener('input', updateQty));
    toggleClientMode();
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

    function confirmRush() {
        const days = daysUntilDue();

        if (days === null || days > RUSH_DAYS) { return true; }

        const when = days < 0 ? 'a date that has already passed'
            : (days === 0 ? 'today' : days + ' day' + (days === 1 ? '' : 's') + ' from now');

        return window.confirm(
            'This order is due ' + when + '.

'
            + 'The shop needs about ' + RUSH_DAYS + ' days to take a job from layout to finished goods. '
            + 'Are you sure about this due date?'
        );
    }

    showRushNote();
</script>
@endsection
