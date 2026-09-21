{{-- The sewing record: the garment's operations, and who did each one.

     Pick the product and the record lays itself out the way the shop's own
     sheet does — one line per operation, a box beside it for the name. It was
     five blank slots and a memory of what a polo takes.

     Lines off the garment's list carry their operation as a hidden value and a
     flag saying where it came from: a listed line with nobody against it is an
     operation not done yet, and is not written down. See
     StationController::sewingRowsWorthKeeping().

     The blank lines at the end are for work the list has not got, and anything
     typed into one is kept whether or not it ends up with a name.

     It sits inside the job order sheet, which is one big form, so its own forms
     are pushed out to the end of the page and its inputs point back at them by
     id. A form cannot nest inside another.

     Expects:
       $sheet    garment => operations, from SewingOperation::sheet()
       $garment  the one this job order is being sewn as
       $rows     from JobOrder::sewingRows()
       $canEdit  whether this person may add to the garment's list --}}
@php
    $canEdit = $canEdit ?? false;
    $listed = collect($rows)->where('listed', true)->count();
@endphp

<style>
    .so-card {
        background: var(--surface); border: 1px solid var(--border-strong);
        border-radius: 10px; padding: 0.9rem 1.1rem; margin: 0 0 0.9rem;
    }
    .so-card h3 { margin: 0 0 0.2rem; font-size: 1rem; }
    .so-hint { margin: 0 0 0.8rem; font-size: 0.8rem; color: var(--ink-2); line-height: 1.5; }
    .so-pick { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.6rem; }
    .so-pick label { font-size: 0.75rem; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
    .so-pick select {
        font-size: 1rem; padding: 0.5rem 0.7rem; min-width: 250px;
        border: 1px solid var(--border-strong); border-radius: 7px;
        background: var(--surface); color: var(--ink);
    }
    .so-count { font-size: 0.82rem; color: var(--ink-3); }

    /* A windbreaker runs to thirty-four operations, which would push everything
       under it off the screen. The record scrolls inside itself instead. */
    .so-scroll { max-height: 460px; overflow: auto; border: 1px solid var(--border); border-radius: 8px; }
    .so-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .so-table thead th { position: sticky; top: 0; z-index: 1; }
    .so-table th {
        background: #3f3f46; color: #fff; text-align: left; font-weight: 700;
        padding: 0.45rem 0.6rem; font-size: 0.72rem; letter-spacing: 0.03em;
    }
    .so-table th.act, .so-table td.act { text-align: center; width: 46px; }
    .so-table td { padding: 0.3rem 0.6rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .so-table tbody tr:nth-child(even) td { background: rgba(127, 127, 127, 0.05); }
    .so-table td.op { font-weight: 600; }
    .so-num { color: var(--ink-3); width: 36px; }
    .so-added { font-size: 0.72rem; color: var(--ink-3); font-weight: 400; }

    /* The name box, and the box for work the list has not got. */
    .so-table input[type="text"] {
        width: 100%; box-sizing: border-box;
        padding: 0.3rem 0.5rem; border: 1px solid var(--border-strong);
        border-radius: 6px; background: var(--surface); color: var(--ink); font-size: 0.88rem;
    }
    .so-table tr.so-spare td.op input { font-weight: 600; }
    .so-who { width: 40%; }

    .so-use {
        border: 1px solid var(--border-strong); background: var(--surface);
        border-radius: 6px; padding: 0.1rem 0.45rem; cursor: pointer;
        font-size: 0.88rem; font-weight: 700; line-height: 1.4; color: var(--ink);
    }
    .so-use:hover { background: var(--accent, #E31B23); color: #fff; border-color: transparent; }

    .so-add { margin-top: 0.6rem; display: flex; gap: 0.4rem; flex-wrap: wrap; }
    .so-add input {
        flex: 1 1 200px; min-width: 0;
        padding: 0.42rem 0.6rem; border: 1px solid var(--border-strong);
        border-radius: 7px; background: var(--surface); color: var(--ink); font-size: 0.88rem;
    }
</style>

<div class="so-card no-print">
    <h3>What this garment takes, and who did it</h3>
    <p class="so-hint">
        Pick the product and the sheet lays out its operations. Write the name of
        whoever did each one beside it &mdash; the rest stay blank, and an
        operation nobody has done is not recorded as done.
    </p>

    <div class="so-pick">
        <label for="soGarment">Product</label>
        <select id="soGarment" aria-label="Which product this is">
            @foreach ($sheet as $name => $operations)
                <option value="{{ $name }}" @selected($name === $garment)>{{ $name }}</option>
            @endforeach
        </select>
        <span class="so-count">{{ $listed }} operations</span>
    </div>

    {{-- What the record is a record of. Saved with it, so whoever opens the job
         next gets the same list without picking again. --}}
    <input type="hidden" name="sheet[sewing_garment]" value="{{ $garment }}">

    <div class="so-scroll">
        <table class="so-table">
            <thead>
                <tr>
                    <th class="so-num">#</th>
                    <th>Operation</th>
                    <th class="so-who">Who did it</th>
                    @if ($canEdit)<th class="act"></th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr class="{{ $row['listed'] ? '' : 'so-spare' }}">
                        <td class="so-num">{{ $row['listed'] ? $i + 1 : '' }}</td>
                        <td class="op">
                            @if ($row['listed'])
                                {{ $row['work'] }}
                                <input type="hidden" name="sheet[sewing_log][{{ $i }}][work]" value="{{ $row['work'] }}">
                                <input type="hidden" name="sheet[sewing_log][{{ $i }}][listed]" value="1">
                            @else
                                <input type="text" name="sheet[sewing_log][{{ $i }}][work]"
                                       maxlength="255" value="{{ $row['work'] }}" list="dl_sheet_work"
                                       placeholder="Something else that was done" autocomplete="off">
                            @endif
                        </td>
                        <td>
                            <input type="text" name="sheet[sewing_log][{{ $i }}][name]"
                                   maxlength="100" value="{{ $row['name'] }}" list="dl_sheet_sewer"
                                   placeholder="Their name" autocomplete="off">
                        </td>
                        @if ($canEdit)
                            <td class="act">
                                @if ($row['listed'])
                                    <button type="submit" class="so-use"
                                            form="soDrop{{ $row['id'] }}"
                                            title="Take this operation off the {{ $garment }} list">&times;</button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($canEdit)
        {{-- Adding to the garment's list. The boxes are here; the form they
             belong to is at the end of the page, because this one sits inside
             the job order sheet's own form. The product box is free text with
             the sheet behind it, so a garment the shop starts making on a
             Tuesday can be written down on the Tuesday rather than waiting for
             somebody with a database. --}}
        <div class="so-add">
            <input type="text" name="garment" form="soAddSheet" style="flex:0 1 180px;"
                   value="{{ old('garment', $garment) }}" list="soGarments"
                   maxlength="120" autocomplete="off" placeholder="Product" required>
            <input type="text" name="name" form="soAddSheet" value="{{ old('name') }}"
                   maxlength="255" autocomplete="off" required
                   placeholder="An operation this list has not got">
            <button class="btn btn-success btn-sm" form="soAddSheet">+ Add to the list</button>
        </div>

        <datalist id="soGarments">
            @foreach ($sheet as $name => $operations)<option value="{{ $name }}"></option>@endforeach
        </datalist>
    @endif
</div>

@if ($canEdit)
    {{-- The forms themselves, pushed out past every form on the page. --}}
    @push('sewing-sheet-forms')
        <form method="POST" id="soAddSheet" action="{{ route('sewing-operations.store') }}">@csrf</form>

        @foreach (($sheet[$garment] ?? []) as $operation)
            <form method="POST" id="soDrop{{ $operation->id }}"
                  action="{{ route('sewing-operations.destroy', $operation) }}"
                  onsubmit="return confirm('Take {{ addslashes($operation->name) }} off the {{ addslashes($garment) }} list?

Anything already written against it on this job is kept.');">
                @csrf
                @method('DELETE')
            </form>
        @endforeach
    @endpush
@endif

<script>
(function () {
    var picker = document.getElementById('soGarment');

    if (!picker) return;

    // Changing the product changes which operations the record is laid out
    // against, which the page has to be redrawn to do. Anything typed and not
    // saved would go with it, so it asks first when there is something to lose.
    picker.addEventListener('change', function () {
        var typed = Array.prototype.filter.call(
            document.querySelectorAll('input[name^="sheet[sewing_log]"][name$="[name]"]'),
            function (box) { return box.value.trim() !== ''; }
        ).length;

        if (typed && !confirm(
            'Change the product to ' + picker.value + '?\n\n'
            + typed + ' name(s) typed here and not yet saved will be lost.'
        )) {
            picker.value = picker.dataset.was;
            return;
        }

        var url = new URL(window.location.href);
        url.searchParams.set('garment', picker.value);
        window.location.href = url.toString();
    });

    picker.dataset.was = picker.value;
})();
</script>
