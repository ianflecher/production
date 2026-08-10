@extends('layouts.app')

@section('title', 'Job Order production details — '.$order->order_number)
@section('page-title', 'Production details — '.$order->order_number)

@section('content')
@php
    // Cutting defaults from the print type chosen on the sheet, but is overridable.
    $selectedCut = old('cutting_type', $order->cutting_type);
    if (! $selectedCut) {
        foreach (\App\Models\JobOrder::PRINT_TYPES as $pt) {
            if (strtolower($pt['label']) === strtolower((string) $jobOrder->print_type)) { $selectedCut = $pt['cutting']; break; }
        }
    }
    $rawMaterials = old('raw_materials', $jobOrder->rawMaterialsList());
    $rawMaterials = array_values(array_filter((array) $rawMaterials, fn ($v) => filled($v)));
    if (empty($rawMaterials)) { $rawMaterials = ['']; }
    $refs = $jobOrder->referenceFiles;
@endphp

<div class="page-head">
    <div class="grow">
        <h1>Production details</h1>
        <p class="muted">{{ $order->order_number }} · {{ $order->customer_name }} — set the raw materials &amp; cutting so production isn't left unfilled. (Not printed on the job order sheet.)</p>
    </div>
</div>

@if ($order->backPocketCount() > 0)
    <div class="card panel" style="margin-bottom: 1.4rem; border-left: 4px solid var(--accent);">
        <h2>Back pocket</h2>
        <p style="font-size: 0.9rem; margin-top: 0.2rem;">
            <strong style="color: var(--danger-ink);">{{ number_format($order->backPocketCount()) }} of {{ number_format($order->quantity) }} pcs</strong>
            need a back pocket{{ $order->backPocketCount() == $order->quantity ? ' — all pieces' : '' }}. Make sure sewing adds them.
        </p>
    </div>
@endif

@if ($errors->any())
    <div class="alert-error" style="margin-bottom: 1rem;">
        @foreach ($errors->all() as $e){{ $e }}<br>@endforeach
    </div>
@endif

{{-- Raw materials & cutting --}}
<form method="POST" action="{{ route('job-orders.production.update', $order) }}" class="form-steps">
    @csrf

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Raw materials <span style="font-weight: 400; font-size: 0.8rem; color: var(--ink-3);">(client preference — add one per item)</span></h2>
        <div id="rawMaterialsList" style="display: flex; flex-direction: column; gap: 0.5rem; max-width: 480px;">
            @foreach ($rawMaterials as $rm)
                <div class="raw-row" style="display: flex; gap: 0.4rem;">
                    <input type="text" name="raw_materials[]" list="dl_raw_materials" maxlength="255" value="{{ $rm }}" placeholder="e.g. lanyard, cloth, ribbing" style="flex: 1;">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.raw-row').remove()">✕</button>
                </div>
            @endforeach
        </div>
        <button type="button" class="btn btn-ghost btn-sm" style="margin-top: 0.5rem;" onclick="addRawMaterial()">+ Add material</button>
        {{-- Past raw materials, so you type once then pick next time. --}}
        <datalist id="dl_raw_materials">
            @foreach (($rawMaterialSuggestions ?? []) as $v)<option value="{{ $v }}"></option>@endforeach
        </datalist>
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Cutting <span style="font-weight: 400; font-size: 0.8rem; color: var(--ink-3);">(defaults from print type — overridable)</span></h2>
        <div class="field" style="max-width: 300px;">
            <select name="cutting_type">
                <option value="">— Select cutting —</option>
                @foreach (\App\Models\ProductionOrder::CUTTING_TYPES as $k => $l)
                    <option value="{{ $k }}" @selected($selectedCut === $k)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Step 3 — Fabric press</h2>
        @php
            $selectedFabric = old('fabric_press', $jobOrder->fabric_press ?: $jobOrder->defaultFabricPress());
            // Add-ons are a toggle: on = pick a press or embroidery; off = none.
            // (The form field is still named decoration_on — renaming the stored
            // column would be a data migration, and only the wording changed.)
            $decoOn = (bool) old('decoration_on', $jobOrder->press !== null);
            $selectedDeco = old('press', $jobOrder->press ?: \App\Models\JobOrder::DECORATION_PRESS_DEFAULT);
            $selectedAddon = old('addon', $jobOrder->addon ?: 'embroidery');

            // The cap press is hidden unless this job is actually a cap — but
            // never hidden out from under a value the order already holds.
            $fabricOptions = \App\Models\JobOrder::pressOptionsFor($order, $selectedFabric);
            $addonPressOptions = \App\Models\JobOrder::pressOptionsFor($order, $selectedDeco);
        @endphp

        {{-- Step 3: presses the print onto the fabric. Always needed. --}}
        <div class="field" style="max-width: 360px;">
            <label>Fabric press <span style="color: var(--danger-ink);">*</span></label>
            <select name="fabric_press" id="fabricPress" required>
                @foreach ($fabricOptions as $k => $l)
                    <option value="{{ $k }}" @selected($selectedFabric === $k)>{{ $l }}</option>
                @endforeach
            </select>
            <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.3rem;">
                Presses the print onto the fabric after printing. Auto-matches the
                print type ({{ $jobOrder->printTypeLabel() ?: '—' }}) — overridable.
            </div>
        </div>
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Step 4 — Add-ons</h2>

        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 500; max-width: 360px; margin-bottom: 0.6rem;">
            <input type="checkbox" id="decorationToggle" name="decoration_on" value="1" style="width: auto; margin: 0;" @checked($decoOn)>
            Add add-ons to this order
        </label>

        {{-- Everything below appears only once the tick is on. --}}
        <div id="addonFields">
            {{-- What the add-on covers, before which one it is. The dropdown only
                 says the treatment — sublimated, reflectorized — and the floor
                 still has to be told WHERE it goes. --}}
            <div class="field" style="max-width: 480px;">
                <label for="addon_note">What is the add-on for?</label>
                <textarea id="addon_note" name="addon_note" rows="2" maxlength="500"
                          placeholder="e.g. sleeves only, left chest logo, collar and cuffs">{{ old('addon_note', $jobOrder->addon_note) }}</textarea>
                <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.3rem;">
                    Where it goes and what it covers, so the floor doesn't have to ask.
                </div>
            </div>

            <div class="field" style="max-width: 360px;">
                <label>Add-on type</label>
                <select name="addon" id="addonSelect">
                    @foreach (\App\Models\JobOrder::ADDONS as $k => $cfg)
                        <option value="{{ $k }}"
                                data-press="{{ $cfg['press'] }}"
                                @selected($selectedAddon === $k)>{{ $cfg['label'] }}</option>
                    @endforeach
                </select>
                <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.3rem;">
                    Picks the press automatically — you can still change it below.
                </div>
            </div>

            {{-- Only for "Others": say what the add-on actually is. --}}
            <div class="field" id="addonOtherField" style="max-width: 480px;">
                <label for="addon_other">What is the add-on?</label>
                <input type="text" id="addon_other" name="addon_other" maxlength="255"
                       placeholder="e.g. Rubberized print, studs, piping"
                       value="{{ old('addon_other', $jobOrder->addon_other) }}">
            </div>

            <div class="field" style="max-width: 360px;">
                <label>Add-on price (₱)</label>
                <input type="number" name="addon_price" id="addonPrice" step="0.01" min="0"
                       placeholder="0.00" value="{{ old('addon_price', $jobOrder->addon_price) }}">
                <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.3rem;">
                    What this add-on is charged at.
                </div>
            </div>

            <div class="field" style="max-width: 360px;">
                <label>Press for the add-on</label>
                <select name="press" id="decorationPress">
                    @foreach ($addonPressOptions as $k => $l)
                        <option value="{{ $k }}" @selected($selectedDeco === $k)>{{ $l }}</option>
                    @endforeach
                </select>
                <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.3rem;">
                    Matched to the add-on automatically — overridable.
                </div>
            </div>

        </div>

        <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.3rem;">
            The fabric press and decoration run after printing.
        </div>

        @unless ($order->canEditRouting())
            {{-- Decoration happens before cutting, so once cutting is done these
                 can be recorded but no longer added to the pipeline. --}}
            <div class="alert-error" style="margin-top: 0.8rem; font-size: 0.82rem;">
                This order is already past cutting, so changing the press, embroidery or cutting
                here will be <strong>saved on the job order but not added to the pipeline</strong> —
                the garments have already been cut.
            </div>
        @endunless
    </div>

    <div style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
        <button type="submit" class="btn btn-primary">💾 Save production details</button>
        <a href="{{ route('orders.show', $order) }}" class="btn btn-ghost">Done</a>
    </div>
</form>

<script>
    // Decoration is a checkbox: show the press/embroidery method only when it's
    // ticked, and only ask "what needs to be embroidered?" when embroidery is
    // actually chosen (as the fabric press, or as the decoration method).
    (function () {
        var fabric = document.getElementById('fabricPress');
        var deco = document.getElementById('decorationPress');
        var decoToggle = document.getElementById('decorationToggle');
        var addonFields = document.getElementById('addonFields');
        var addonSelect = document.getElementById('addonSelect');
        var otherField = document.getElementById('addonOtherField');

        // Follow the add-on's press until the user picks one themselves; after
        // that their choice sticks (overridable), until the add-on changes again.
        function matchPress() {
            if (!addonSelect || !deco) return;
            var opt = addonSelect.options[addonSelect.selectedIndex];
            var press = opt ? opt.getAttribute('data-press') : '';
            // "Others" has no matched press — leave whatever is selected.
            if (press) deco.value = press;
        }

        function refresh() {
            var on = decoToggle ? decoToggle.checked : false;

            // The whole add-on block only exists once the tick is on.
            if (addonFields) addonFields.style.display = on ? '' : 'none';
            // Nothing inside it should be submitted while it is hidden. The
            // fabric press is Step 3 and stays enabled either way.
            if (deco) deco.disabled = !on;
            if (addonSelect) addonSelect.disabled = !on;

            var isOther = on && addonSelect && addonSelect.value === 'others';
            if (otherField) otherField.style.display = isOther ? '' : 'none';
        }

        if (addonSelect) addonSelect.addEventListener('change', function () {
            matchPress();   // automatic…
            refresh();
        });
        if (fabric) fabric.addEventListener('change', refresh);
        if (deco) deco.addEventListener('change', refresh);   // …but overridable
        if (decoToggle) decoToggle.addEventListener('change', refresh);
        refresh();
    })();

    function addRawMaterial() {
        const list = document.getElementById('rawMaterialsList');
        const row = document.createElement('div');
        row.className = 'raw-row';
        row.style.cssText = 'display: flex; gap: 0.4rem;';
        row.innerHTML = '<input type="text" name="raw_materials[]" list="dl_raw_materials" maxlength="255" placeholder="e.g. lanyard, cloth, ribbing" style="flex: 1;">'
            + '<button type="button" class="btn btn-ghost btn-sm">✕</button>';
        row.querySelector('button').addEventListener('click', () => row.remove());
        list.appendChild(row);
        row.querySelector('input').focus();
    }
</script>
@endsection
