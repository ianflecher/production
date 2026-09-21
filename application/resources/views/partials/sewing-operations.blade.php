{{-- The sewing record: the product's operations, and who did each one.

     Pick the product and the record lays itself out the way the shop's own
     sheet does — one numbered line per operation, a box beside it for the name.
     Nothing else: an operation the list has not got is added to the list, where
     it gets a number like the rest.

     Each line carries its operation as a hidden value and a flag saying it came
     off the list, so the save can tell a line somebody signed from a line
     nobody has done yet. See StationController::sewingRowsWorthKeeping().

     It sits inside the job order sheet, which is one big form, so its own forms
     are pushed out to the end of the page and its inputs point back at them by
     id. A form cannot nest inside another.

     Expects:
       $sheet    garment => operations, from SewingOperation::sheet()
       $garment  the product this job is being sewn as, or '' if nobody has said
       $rows     from JobOrder::sewingRows()
       $canEdit  whether this person may add to the product's list --}}
@php
    $canEdit = $canEdit ?? false;
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
    .so-empty { padding: 1rem 0.7rem; font-size: 0.85rem; color: var(--ink-3); }

    .so-table input[type="text"] {
        width: 100%; box-sizing: border-box;
        padding: 0.3rem 0.5rem; border: 1px solid var(--border-strong);
        border-radius: 6px; background: var(--surface); color: var(--ink); font-size: 0.88rem;
    }
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
            {{-- Nobody has said what this is yet. It is not a t-shirt because
                 t-shirts come first on the sheet. --}}
            <option value="" @selected($garment === '')>&mdash; pick the product &mdash;</option>
            @foreach ($sheet as $name => $operations)
                <option value="{{ $name }}" @selected($name === $garment)>{{ $name }}</option>
            @endforeach
        </select>
        @if ($garment !== '')
            <span class="so-count">{{ count($rows) }} operations</span>
        @endif
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
                @forelse ($rows as $i => $row)
                    <tr>
                        <td class="so-num">{{ $i + 1 }}</td>
                        <td class="op">
                            {{ $row['work'] }}
                            <input type="hidden" name="sheet[sewing_log][{{ $i }}][work]" value="{{ $row['work'] }}">
                            <input type="hidden" name="sheet[sewing_log][{{ $i }}][listed]" value="1">
                        </td>
                        <td>
                            <input type="text" name="sheet[sewing_log][{{ $i }}][name]"
                                   maxlength="100" value="{{ $row['name'] }}" list="dl_sheet_sewer"
                                   placeholder="Their name" autocomplete="off">
                        </td>
                        @if ($canEdit)
                            <td class="act">
                                @if ($row['id'])
                                    <button type="submit" class="so-use"
                                            form="soDrop{{ $row['id'] }}"
                                            title="Take this operation off the {{ $garment }} list">&times;</button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canEdit ? 4 : 3 }}" class="so-empty">
                            Pick the product above and its operations will be listed here.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($canEdit && $garment !== '')
        {{-- Adding to the product's list: the operation, and nothing else. The
             product is the one chosen above, so asking for it again would be
             asking the same question twice and giving it two places to
             disagree. The box is here; the form it belongs to is at the end of
             the page, because this one sits inside the job order sheet's own
             form. --}}
        <div class="so-add">
            <input type="hidden" name="garment" form="soAddSheet" value="{{ $garment }}">
            <input type="text" name="name" form="soAddSheet" value="{{ old('name') }}"
                   maxlength="255" autocomplete="off" required
                   placeholder="An operation the {{ $garment }} list has not got">
            <button class="btn btn-success btn-sm" form="soAddSheet">+ Add to the list</button>
        </div>
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
    // against, which the page has to be redrawn to do. Names already written
    // belong to the product they were written against, so they go with it.
    picker.addEventListener('change', function () {
        var written = Array.prototype.filter.call(
            document.querySelectorAll('input[name^="sheet[sewing_log]"][name$="[name]"]'),
            function (box) { return box.value.trim() !== ''; }
        ).length;

        if (written && !confirm(
            'Change the product to ' + (picker.value || 'none') + '?\n\n'
            + written + ' name(s) written against the current one will be dropped.'
        )) {
            picker.value = picker.dataset.was;
            return;
        }

        var url = new URL(window.location.href);

        if (picker.value) {
            url.searchParams.set('garment', picker.value);
        } else {
            url.searchParams.delete('garment');
        }

        window.location.href = url.toString();
    });

    picker.dataset.was = picker.value;
})();
</script>
