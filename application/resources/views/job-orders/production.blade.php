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
        <h2>Decoration</h2>
        @php
            $pressOptions = \App\Models\JobOrder::pressOptions();
            $selectedFabric = old('fabric_press', $jobOrder->fabric_press ?: $jobOrder->defaultFabricPress());
            // Decoration is a toggle now: on = pick a press or embroidery; off = none.
            $decoOn = (bool) old('decoration_on', $jobOrder->press !== null);
            $selectedDeco = old('press', $jobOrder->press ?: \App\Models\JobOrder::DECORATION_PRESS_DEFAULT);
        @endphp

        <div class="field" style="max-width: 360px;">
            <label>Fabric press <span style="color: var(--danger-ink);">*</span></label>
            <select name="fabric_press" id="fabricPress" required>
                @foreach ($pressOptions as $k => $l)
                    <option value="{{ $k }}" @selected($selectedFabric === $k)>{{ $l }}</option>
                @endforeach
            </select>
            <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.3rem;">
                Presses the print onto the fabric after printing. Auto-matches the
                print type ({{ $jobOrder->printTypeLabel() ?: '—' }}) — overridable.
            </div>
        </div>

        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 500; max-width: 360px; margin-bottom: 0.6rem;">
            <input type="checkbox" id="decorationToggle" name="decoration_on" value="1" style="width: auto; margin: 0;" @checked($decoOn)>
            This order needs decoration
        </label>

        <div class="field" id="decorationMethodField" style="max-width: 360px;">
            <label>Decoration method</label>
            <select name="press" id="decorationPress">
                @foreach ($pressOptions as $k => $l)
                    <option value="{{ $k }}" @selected($selectedDeco === $k)>{{ $l }}</option>
                @endforeach
            </select>
            <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.3rem;">
                A press or embroidery — decoration can be either. Defaults to Heat press.
            </div>
        </div>

        {{-- What to embroider — shown when either press is set to embroidery. --}}
        <div class="field" id="embroideryNoteField" style="max-width: 480px; margin-top: 0.7rem;">
            <label for="embroidery_note">What needs to be embroidered?</label>
            <textarea id="embroidery_note" name="embroidery_note" rows="2" maxlength="500"
                      placeholder="e.g. IC logo on left chest, player name at the back, size 3 inches">{{ old('embroidery_note', $jobOrder->embroidery_note) }}</textarea>
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
        var methodField = document.getElementById('decorationMethodField');
        var noteField = document.getElementById('embroideryNoteField');

        function refresh() {
            var decoOn = decoToggle ? decoToggle.checked : false;
            if (methodField) methodField.style.display = decoOn ? '' : 'none';
            // When decoration is off it must not be submitted as a press.
            if (deco) deco.disabled = !decoOn;

            var embroidery = (fabric && fabric.value === 'embroidery')
                || (decoOn && deco && deco.value === 'embroidery');
            if (noteField) noteField.style.display = embroidery ? '' : 'none';
        }

        if (fabric) fabric.addEventListener('change', refresh);
        if (deco) deco.addEventListener('change', refresh);
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
