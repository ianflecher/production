{{-- The expense form: one copy, used to record a new one and to fix an old one.

     Kept as two copies the fifteen fields and two scripts would drift the
     first time a field was added, and an edit form quietly missing a field is
     how a field stops being editable without anybody deciding it should be.

     Pass $expense to edit; leave it out to record. --}}
@php
    $expense = $expense ?? null;
    $editing = (bool) $expense;
@endphp

<style>
    /* Twelve columns rather than auto-fit.

       The form has fifteen fields, and auto-fit packed them wherever they
       happened to land - a date next to a TIN next to an amount, at whatever
       width was left over. Every field below says how many columns it wants,
       so things that belong together sit together and a narrow one stays
       narrow. */
    .bk-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }

    /* Only the expense form. The petty cash form beside it on the books page
       is four fields and was perfectly happy with auto-fit; handing it twelve
       columns squeezed every box to eighty pixels. */
    .bk-form.bk-form-wide { grid-template-columns: repeat(12, 1fr); gap: 0.75rem 0.8rem; }
    .bk-form .full { grid-column: 1 / -1; }
    .bk-form .c2 { grid-column: span 2; }
    .bk-form .c3 { grid-column: span 3; }
    .bk-form .c4 { grid-column: span 4; }
    .bk-form .c6 { grid-column: span 6; }
    .bk-form label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem; }
    .bk-form input, .bk-form select, .bk-form textarea { width: 100%; }

    /* A heading every few fields. Fifteen boxes in a row is a wall; the same
       fifteen under "Who supplied it" and "The paper" is a form. */
    .bk-form .bk-leg {
        grid-column: 1 / -1;
        margin: 0.5rem 0 -0.15rem;
        font-size: 0.72rem; font-weight: 800; letter-spacing: 0.07em;
        text-transform: uppercase; color: var(--ink-3);
        border-top: 1px solid var(--border); padding-top: 0.8rem;
    }
    .bk-form .bk-leg:first-child { margin-top: 0; border-top: 0; padding-top: 0; }

    .bk-hint { font-size: 0.72rem; color: var(--ink-3); margin-top: 0.25rem; line-height: 1.35; }

    /* The account title picker.

       A filter box and a list, drawn as ONE control - the input's bottom edge
       is the list's top edge - so it reads as a searchable field rather than
       as a text box that happens to sit above a scrolling list box. */
    .bk-picker {
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #fff;
        overflow: hidden;
    }
    .bk-picker:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-soft); }
    .bk-picker input[type="search"] {
        border: 0; border-bottom: 1px solid var(--border); border-radius: 0;
        padding: 0.6rem 0.75rem; font-size: 0.9rem;
    }
    .bk-picker input[type="search"]:focus { outline: none; box-shadow: none; }
    .bk-picker select {
        border: 0; border-radius: 0; height: 190px; padding: 0.35rem 0.25rem;
        font-size: 0.86rem; background: #fff;
    }
    .bk-picker select:focus { outline: none; box-shadow: none; }
    .bk-picker optgroup { font-size: 0.74rem; letter-spacing: 0.04em; color: var(--ink-3); }
    .bk-picker option { padding: 0.18rem 0.5rem; color: var(--ink); }
    .bk-picker option:checked { background: var(--brand-soft); color: var(--ink); font-weight: 700; }

    .bk-picked {
        display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
        border-top: 1px solid var(--border); padding: 0.45rem 0.75rem;
        font-size: 0.8rem; background: #fafbfd;
    }
    .bk-picked strong { color: var(--ink); }

    @media (max-width: 760px) {
        /* One field per row on a phone. Twelve columns of 30px is not a form. */
        .bk-form.bk-form-wide { grid-template-columns: 1fr; }
        .bk-form .c2, .bk-form .c3, .bk-form .c4, .bk-form .c6 { grid-column: 1 / -1; }
    }
</style>

<form method="POST"
      action="{{ $editing ? route('books.expenses.update', $expense) : route('books.expenses.store') }}"
      enctype="multipart/form-data" class="bk-form bk-form-wide">
    @csrf

    <div class="bk-leg">When, and who asked</div>

    {{-- Deliberately empty rather than today's date.

         A date already in the box is an answer, and an answer nobody typed
         gets left alone: expenses ordered last week were going in dated the
         day they were entered, which puts them in the wrong month at the end
         of it. Blank asks the question. --}}
    <div class="c3">
        <label for="spent_at">Order date *</label>
        <input type="date" id="spent_at" name="spent_at" required
               value="{{ old('spent_at', $expense?->spent_at?->format('Y-m-d')) }}">
        <div class="bk-hint">The day it was ordered.</div>
    </div>

    <div class="c3">
        <label for="paid_at">Date paid</label>
        <input type="date" id="paid_at" name="paid_at"
               value="{{ old('paid_at', $expense?->paid_at?->format('Y-m-d')) }}">
        <div class="bk-hint">Blank until it is actually paid.</div>
    </div>

    <div class="c6">
        <label for="ordered_by">Ordered by</label>
        <input type="text" id="ordered_by" name="ordered_by" maxlength="120"
               value="{{ old('ordered_by', $expense?->ordered_by) }}" placeholder="Who asked for it">
    </div>

    <div class="bk-leg">What it was</div>

    {{-- Seventy-eight titles is too many to scroll, so the box at the top
         filters the list under it as you type. It is a real <select> with
         the four groups intact, so the field validates, submits and works
         with the keyboard whether or not the script runs. --}}
    <div class="c6">
        <label for="accountTitleFilter">Account title *</label>
        <div class="bk-picker">
            <input type="search" id="accountTitleFilter" class="no-caps"
                   placeholder="Type to filter — fabric, BIR, rent…" autocomplete="off">
            <select id="account_title" name="account_title" size="8" required>
                @foreach ($accountTitles as $group => $titles)
                    <optgroup label="{{ $group }}">
                        @foreach ($titles as $title)
                            <option value="{{ $title }}" @selected(old('account_title', $expense?->account_title) === $title)>{{ $title }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <div class="bk-picked">
                <span id="accountTitlePicked">Nothing chosen yet</span>
                <span id="accountTitleCount" style="color:var(--ink-3);"></span>
            </div>
        </div>
    </div>

    <div class="c6">
        <label for="description">What was it for? *</label>
        <input type="text" id="description" name="description" required maxlength="255"
               placeholder="e.g. 20 yards cotton fabric from Divisoria"
               value="{{ old('description', $expense?->description) }}">

        <label for="amount" style="margin-top:.8rem;">Amount (₱) *</label>
        <input type="number" id="amount" name="amount" step="0.01" min="0.01" required
               placeholder="0.00" value="{{ old('amount', $expense?->amount) }}">

        <label for="method" style="margin-top:.8rem;">Payment method</label>
        <select id="method" name="method">
            <option value="">— not specified —</option>
            @foreach ($methods as $m)
                <option value="{{ $m }}" @selected(old('method', $expense?->method) === $m)>{{ $m }}</option>
            @endforeach
        </select>

        <label for="vat_status" style="margin-top:.8rem;">VAT / N-VAT</label>
        <select id="vat_status" name="vat_status">
            <option value="">— neither —</option>
            @foreach ($vatStatuses as $v)
                <option value="{{ $v }}" @selected(old('vat_status', $expense?->vat_status) === $v)>{{ $v }}</option>
            @endforeach
        </select>
    </div>

    <div class="bk-leg">Who supplied it</div>

    <div style="grid-column: span 5;">
        <label for="supplier">Supplier / vendor</label>
        <input type="text" id="supplier" name="supplier" maxlength="255"
               value="{{ old('supplier', $expense?->supplier) }}">
    </div>

    <div class="c3">
        <label for="tin">Their TIN</label>
        <input type="text" id="tin" name="tin" maxlength="40" class="no-caps"
               value="{{ old('tin', $expense?->tin) }}" placeholder="000-000-000-000">
    </div>

    <div style="grid-column: span 4;">
        <label for="business_address">Their business address</label>
        <input type="text" id="business_address" name="business_address" maxlength="255"
               value="{{ old('business_address', $expense?->business_address) }}">
    </div>

    <div class="bk-leg">The paper</div>

    <div class="c2">
        <label for="reference_type">Ref. type</label>
        <select id="reference_type" name="reference_type">
            <option value="">— none —</option>
            @foreach ($referenceTypes as $t)
                <option value="{{ $t }}" @selected(old('reference_type', $expense?->reference_type) === $t)>{{ $t }}</option>
            @endforeach
        </select>
    </div>

    <div class="c3">
        <label for="reference">Reference no.</label>
        <input type="text" id="reference" name="reference" maxlength="255"
               class="no-caps" value="{{ old('reference', $expense?->reference) }}">
        <div class="bk-hint" id="referenceHint">Pick a type and it fills in here.</div>
    </div>

    <div class="c3">
        <label for="si_cr_no">SI / CR no.</label>
        <input type="text" id="si_cr_no" name="si_cr_no" maxlength="255"
               class="no-caps" value="{{ old('si_cr_no', $expense?->si_cr_no) }}">
    </div>

    <div class="c4">
        <label for="receipt">Receipt{{ $editing ? '' : ' *' }}</label>
        <input type="file" id="receipt" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf" @unless ($editing) required @endunless>
        @if ($editing)
            <div class="bk-hint">
                @if ($expense->hasReceipt())
                    Keeping
                    <a href="{{ route('books.expenses.receipt', $expense) }}" target="_blank" rel="noopener">{{ $expense->receipt_name ?: 'the file on record' }}</a>
                    unless you choose another.
                @else
                    None on record — image or PDF.
                @endif
            </div>
        @else
            <div class="bk-hint">Required — image or PDF.</div>
        @endif
    </div>

    <div class="full">
        <label for="note">Note</label>
        <textarea id="note" name="note" rows="2" maxlength="2000" placeholder="Anything worth remembering later…">{{ old('note', $expense?->note) }}</textarea>
    </div>

    <div class="full">
        <button type="submit" class="btn btn-primary">{{ $editing ? 'Save changes' : '+ Record expense' }}</button>
        @if ($editing)
            <a href="{{ route('books.index', ['month' => $expense->spent_at?->format('Y-m')]) }}"
               class="btn btn-ghost" style="margin-left:.4rem;">Cancel</a>
        @endif
    </div>
</form>

{{-- Filtering seventy-eight account titles.

     Plain DOM on a real <select>: the options and their four groups are in
     the HTML, so the field validates, submits and works with the keyboard
     whether or not this runs. All the script does is hide what does not
     match, which is why an empty box puts everything back rather than
     needing a reset.

     Matching is done on a lower-cased haystack of the title AND its group,
     so typing "admin" finds the AE lines even though none of them contain
     the word, and "cos" finds the cost of sales ones. --}}
<script>
    (function () {
        var box = document.getElementById('accountTitleFilter');
        var list = document.getElementById('account_title');
        var count = document.getElementById('accountTitleCount');

        if (!box || !list) { return; }

        var options = Array.prototype.map.call(list.querySelectorAll('option'), function (opt) {
            var group = opt.parentNode.tagName === 'OPTGROUP' ? opt.parentNode.label : '';
            return { el: opt, group: opt.parentNode, hay: (opt.textContent + ' ' + group).toLowerCase() };
        });

        var total = options.length;

        function apply() {
            var needle = box.value.trim().toLowerCase();
            var shown = 0;

            options.forEach(function (o) {
                var hit = needle === '' || o.hay.indexOf(needle) !== -1;
                o.el.hidden = !hit;
                if (hit) { shown++; }
            });

            // A group whose every option is hidden should go too, or the
            // list reads as four headings above nothing.
            Array.prototype.forEach.call(list.querySelectorAll('optgroup'), function (g) {
                var any = Array.prototype.some.call(g.querySelectorAll('option'), function (o) { return !o.hidden; });
                g.hidden = !any;
            });

            count.textContent = needle === ''
                ? total + ' titles'
                : shown + ' of ' + total;

            // One left and nothing chosen yet: pick it, so the common case is
            // type-three-letters-and-move-on.
            if (shown === 1 && needle !== '') {
                options.forEach(function (o) { if (!o.el.hidden) { o.el.selected = true; } });
            }
        }

        // What is chosen, spelled out under the list. A highlighted row
        // scrolled out of view is not an answer to "which one did I pick".
        var picked = document.getElementById('accountTitlePicked');

        function showPicked() {
            picked.innerHTML = list.value
                ? 'Chosen: <strong>' + list.value.replace(/[<>&]/g, '') + '</strong>'
                : 'Nothing chosen yet';
        }

        list.addEventListener('change', showPicked);
        box.addEventListener('input', function () { apply(); showPicked(); });

        // Enter in the filter box would otherwise submit the whole form
        // while the person is still choosing.
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); }
        });

        apply();
        showPicked();

        // Editing: the chosen title is somewhere in a list eight rows tall,
        // and one already scrolled past is indistinguishable from none.
        if (list.selectedIndex >= 0 && list.options[list.selectedIndex]) {
            list.options[list.selectedIndex].scrollIntoView({ block: 'nearest' });
        }
    })();
</script>

{{-- Choosing a reference type writes it into the reference box.

     The two are stored apart - the type in its own column so it can be
     sorted and counted - but they are ONE thing on paper, and typing "PO-"
     before every number is the sort of small friction that ends with the
     type being left blank. So the box shows "PO-" the moment the type is
     chosen, with the caret after it, and the server strips the prefix back
     off when it saves.

     Changing the type rewrites the prefix rather than stacking a second one,
     and clearing it takes the prefix away and leaves the number. --}}
<script>
    (function () {
        var type = document.getElementById('reference_type');
        var ref = document.getElementById('reference');
        var hint = document.getElementById('referenceHint');

        if (!type || !ref) { return; }

        // Every prefix this could already be carrying, longest first, so
        // "Others-" is matched before anything that starts the same way.
        var prefixes = Array.prototype.map.call(type.options, function (o) { return o.value; })
            .filter(Boolean)
            .sort(function (a, b) { return b.length - a.length; })
            .map(function (v) { return v.toUpperCase() + '-'; });

        function bareNumber() {
            var v = ref.value.trim();

            for (var i = 0; i < prefixes.length; i++) {
                if (v.toUpperCase().indexOf(prefixes[i]) === 0) {
                    return v.slice(prefixes[i].length);
                }
            }

            return v;
        }

        function sync(focus) {
            var number = bareNumber();

            if (!type.value) {
                ref.value = number;
                hint.textContent = 'Pick a type and it fills in here.';
                return;
            }

            ref.value = type.value + '-' + number;
            hint.textContent = 'Type the number after ' + type.value + '-';

            if (focus) {
                ref.focus();
                // After the prefix, not before it, or the number lands in
                // front of the type.
                ref.setSelectionRange(ref.value.length, ref.value.length);
            }
        }

        type.addEventListener('change', function () { sync(true); });

        // Somebody who types over the prefix by hand gets it put back when
        // they leave the box, so what is on screen matches what is saved.
        ref.addEventListener('blur', function () { sync(false); });

        sync(false);
    })();
</script>
