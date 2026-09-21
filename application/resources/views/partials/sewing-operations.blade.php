{{-- The sewing sheet: pick the garment, read the work.

     Every garment on the sheet is rendered once and shown by the picker,
     because switching should be instant for somebody standing at a machine
     holding the garment — not a page load each time they change their mind.

     Expects:
       $sheet   garment => operations, from SewingOperation::sheet()
       $garment the one showing
       $canEdit whether this person may write on the sheet
       $withAdd whether each row gets an ADD button (the station page, where
                there is a sewing log for it to go into) --}}
@php
    $withAdd = $withAdd ?? false;
    $canEdit = $canEdit ?? false;
@endphp

<style>
    .so-card {
        background: var(--surface); border: 1px solid var(--border-strong);
        border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: 1.1rem;
    }
    .so-card h3 { margin: 0 0 0.2rem; font-size: 1.05rem; }
    .so-hint { margin: 0 0 0.9rem; font-size: 0.83rem; color: var(--ink-2); line-height: 1.5; }
    .so-pick { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.9rem; }
    .so-pick select {
        font-size: 1rem; padding: 0.5rem 0.7rem; min-width: 260px;
        border: 1px solid var(--border-strong); border-radius: 7px;
        background: var(--surface); color: var(--ink);
    }
    .so-count { font-size: 0.82rem; color: var(--ink-3); }

    .so-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
    .so-table th {
        background: #3f3f46; color: #fff; text-align: left; font-weight: 700;
        padding: 0.5rem 0.6rem; font-size: 0.74rem; letter-spacing: 0.03em;
    }
    .so-table th.num, .so-table td.num { text-align: right; width: 88px; }
    .so-table th.act, .so-table td.act { text-align: center; width: 62px; }
    .so-table td {
        padding: 0.42rem 0.6rem; border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }
    .so-table tbody tr:nth-child(even) td { background: rgba(127, 127, 127, 0.05); }
    .so-table td.op { font-weight: 600; }
    .so-untimed { color: var(--ink-3); }
    .so-added { font-size: 0.72rem; color: var(--ink-3); font-weight: 400; }
    .so-foot td { font-weight: 700; border-top: 2px solid var(--border-strong); background: none !important; }

    .so-use {
        border: 1px solid var(--border-strong); background: var(--surface);
        border-radius: 6px; padding: 0.15rem 0.5rem; cursor: pointer;
        font-size: 0.9rem; font-weight: 700; line-height: 1.4; color: var(--ink);
    }
    .so-use:hover { background: var(--accent, #E31B23); color: #fff; border-color: transparent; }
    .so-use.is-used { opacity: 0.45; }

    .so-add { margin-top: 1rem; padding-top: 0.9rem; border-top: 1px dashed var(--border-strong); }
    .so-add .row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; }
    .so-add label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.76rem; color: var(--ink-2); }
    .so-add input {
        padding: 0.45rem 0.6rem; border: 1px solid var(--border-strong);
        border-radius: 7px; background: var(--surface); color: var(--ink); font-size: 0.9rem;
    }
    .so-add .op-name { min-width: 260px; }
    .so-add .op-sam { width: 110px; }
    .so-add .op-garment { min-width: 190px; }

    @media (max-width: 640px) {
        .so-pick select, .so-add .op-name, .so-add .op-garment { min-width: 0; width: 100%; }
        .so-add label { flex: 1 1 100%; }
    }
</style>

<div class="so-card">
    <h3>What this garment takes</h3>
    <p class="so-hint">
        The shop's sewing sheet. Pick the garment and it lists the operations and
        the standard allowing minute against each one, against a
        {{ \App\Models\SewingOperation::MINUTES_A_DAY }}-minute day.
        @if ($withAdd)
            <br><strong>+</strong> on a line writes it into the next empty
            &ldquo;what they did&rdquo; box below.
        @endif
    </p>

    <div class="so-pick">
        <select id="soGarment" aria-label="Which garment">
            @foreach ($sheet as $name => $operations)
                <option value="{{ $name }}" @selected($name === $garment)>{{ $name }}</option>
            @endforeach
        </select>
        <span class="so-count" id="soCount"></span>
    </div>

    @foreach ($sheet as $name => $operations)
        @php $totals = \App\Models\SewingOperation::totals($operations); @endphp
        <div class="so-sheet" data-garment="{{ $name }}"
             data-count="{{ $operations->count() }}"
             style="{{ $name === $garment ? '' : 'display:none;' }}">
            <table class="so-table">
                <thead>
                    <tr>
                        <th style="width:38px;">#</th>
                        <th>{{ $name }}</th>
                        <th class="num">SAM</th>
                        <th class="num">PCS/DAY</th>
                        @if ($withAdd)<th class="act">ADD</th>@endif
                        @if ($canEdit)<th class="act"></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($operations as $i => $operation)
                        <tr>
                            <td class="so-untimed">{{ $i + 1 }}</td>
                            <td class="op">
                                {{ $operation->name }}
                                @if ($operation->added_by)
                                    <span class="so-added">· added by {{ $operation->added_by }}</span>
                                @endif
                            </td>
                            <td class="num {{ $operation->sam === null ? 'so-untimed' : '' }}">
                                {{ $operation->samLabel() }}
                            </td>
                            <td class="num so-untimed">
                                {{-- How many of this one operation a sewer is
                                     expected to get through in a day. Blank
                                     where nobody has timed it: a dash is
                                     honest, a number would not be. --}}
                                @if ($operation->sam > 0)
                                    {{ number_format(\App\Models\SewingOperation::MINUTES_A_DAY / (float) $operation->sam) }}
                                @else
                                    &mdash;
                                @endif
                            </td>
                            @if ($withAdd)
                                <td class="act">
                                    <button type="button" class="so-use"
                                            data-work="{{ $operation->name }}"
                                            title="Put this in the sewing log below">+</button>
                                </td>
                            @endif
                            @if ($canEdit)
                                <td class="act">
                                    <button type="submit" form="soDrop{{ $operation->id }}" class="so-use"
                                            title="Take this line off the sheet">&times;</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="so-foot">
                        <td></td>
                        <td>
                            {{ $operations->count() }} operations
                            @if ($totals['untimed'])
                                <span class="so-added">({{ $totals['untimed'] }} never timed, not counted)</span>
                            @endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format($totals['minutes'], 4, '.', ''), '0'), '.') ?: '0' }}</td>
                        <td class="num">{{ $totals['a_day'] ? number_format($totals['a_day'], 1) : '—' }}</td>
                        @if ($withAdd)<td></td>@endif
                        @if ($canEdit)<td></td>@endif
                    </tr>
                </tfoot>
            </table>
        </div>
    @endforeach

    @if ($canEdit)
        {{-- Adding a line. The garment box is free text with the sheet behind
             it, so a garment the shop starts making on a Tuesday can be written
             down on the Tuesday rather than waiting for somebody with a
             database. Spelling is squared up on the way in, so "polo shirt"
             lands on POLO SHIRT rather than beside it. --}}
        <div class="so-add">
            <form method="POST" action="{{ route('sewing-operations.store') }}">
                @csrf
                <div class="row">
                    <label>
                        <span>Garment</span>
                        <input type="text" name="garment" class="op-garment" id="soAddGarment"
                               value="{{ old('garment', $garment) }}" list="soGarments"
                               maxlength="120" autocomplete="off" required>
                    </label>
                    <label>
                        <span>Operation</span>
                        <input type="text" name="name" class="op-name"
                               value="{{ old('name') }}" placeholder="e.g. ATTACH CUFF"
                               maxlength="255" autocomplete="off" required>
                    </label>
                    <label>
                        <span>SAM <em>(minutes, optional)</em></span>
                        <input type="number" name="sam" class="op-sam" step="0.0001" min="0"
                               value="{{ old('sam') }}" placeholder="e.g. 1.609">
                    </label>
                    <button class="btn btn-success btn-sm">+ Add to the sheet</button>
                </div>
            </form>

            <datalist id="soGarments">
                @foreach ($sheet as $name => $operations)<option value="{{ $name }}"></option>@endforeach
            </datalist>

            {{-- One form per line, outside the table, because a form cannot sit
                 inside a <tr> and the remove buttons live in one. --}}
            @foreach ($sheet as $name => $operations)
                @foreach ($operations as $operation)
                    <form method="POST" id="soDrop{{ $operation->id }}"
                          action="{{ route('sewing-operations.destroy', $operation) }}"
                          onsubmit="return confirm('Take {{ addslashes($operation->name) }} off the {{ addslashes($name) }} sheet?');">
                        @csrf
                        @method('DELETE')
                    </form>
                @endforeach
            @endforeach
        </div>
    @endif
</div>

<script>
(function () {
    var picker = document.getElementById('soGarment');
    var count = document.getElementById('soCount');
    var addGarment = document.getElementById('soAddGarment');

    if (!picker) return;

    function show(garment) {
        var shown = null;

        document.querySelectorAll('.so-sheet').forEach(function (sheet) {
            var mine = sheet.dataset.garment === garment;
            sheet.style.display = mine ? '' : 'none';
            if (mine) shown = sheet;
        });

        if (count && shown) {
            count.textContent = shown.dataset.count + ' operations';
        }

        // The add form follows the picker, so adding a line to the garment on
        // screen does not need a second decision about which garment it is.
        if (addGarment) addGarment.value = garment;
    }

    picker.addEventListener('change', function () { show(picker.value); });
    show(picker.value);

    // "+" puts the operation in the first empty box of the sewing log, which is
    // the only thing on this page it could mean. When all five are full it says
    // so rather than quietly overwriting somebody's line.
    document.querySelectorAll('.so-use[data-work]').forEach(function (button) {
        button.addEventListener('click', function () {
            var boxes = document.querySelectorAll('input[name^="sheet[sewing_log]"][name$="[work]"]');
            var free = null;

            boxes.forEach(function (box) {
                if (free === null && !box.value.trim()) free = box;
            });

            if (!free) {
                alert('All ' + boxes.length + ' lines are full.\n\nClear one first, or finish this step and start another.');
                return;
            }

            free.value = button.dataset.work;
            free.dispatchEvent(new Event('input', { bubbles: true }));
            button.classList.add('is-used');

            // Straight to the name box beside it: the next thing anybody wants
            // to type is who did it.
            var name = free.parentElement.querySelector('input[name$="[name]"]');
            if (name) name.focus();
        });
    });
})();
</script>
