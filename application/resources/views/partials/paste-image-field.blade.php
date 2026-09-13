{{-- A file input you can paste a screenshot into.

     The same idea as the tech pack's reference boxes, and for the same reason:
     the proof of a GCash or bank transfer is already in the officer's
     clipboard when they come to record it. Saving it to the desktop first,
     then finding it again in a file dialog, is three steps for something they
     are already holding. Ctrl+V puts it in.

     Choosing a file by hand still works — and has to, because a PDF receipt
     cannot be pasted. Dropping one on the box works too.

     Given $ocrTarget, the picture is also READ: the reference number is
     pulled out of it and put in that field as a suggestion the officer still
     has to agree with. It is a suggestion and never more than one — a number
     off a photograph is a guess, and this one is attached to money.

     Expects: $name. Optional: $id, $accept, $required, $hint, $ocrTarget.

     The paste is caught on the whole document rather than on the box, because
     a box cannot hold the caret and so never receives the event itself. Only
     a box that is ON SCREEN takes it — this one lives inside a <details> that
     is usually shut, and a paste landing in a form nobody can see is a file
     silently attached to a payment the officer has not opened yet. --}}
@php
    $id = $id ?? 'paste_'.$name;
    $accept = $accept ?? '.jpg,.jpeg,.png,.webp,.pdf';
    $required = $required ?? false;
    $hint = $hint ?? 'Paste a screenshot, drop a file, or choose one.';
    // The name of a field on the same form to read out of the picture, or
    // null to just take the file and ask nothing of it.
    $ocrTarget = $ocrTarget ?? null;
@endphp

<div class="paste-drop" data-paste-drop @if ($ocrTarget) data-ocr-target="{{ $ocrTarget }}" @endif>
    <input type="file" id="{{ $id }}" name="{{ $name }}" accept="{{ $accept }}"
           class="paste-drop-input" @if ($required) required @endif>

    <div class="paste-drop-hint">
        <kbd>Ctrl</kbd>+<kbd>V</kbd> {{ $hint }}
    </div>

    <div class="paste-drop-preview" hidden>
        <img alt="" class="paste-drop-thumb" hidden>
        <span class="paste-drop-name"></span>
        <button type="button" class="paste-drop-clear" title="Remove this file">Remove</button>
    </div>

    @if ($ocrTarget)
        <div class="paste-drop-ocr" data-ocr-status hidden></div>
    @endif
</div>

@once
<script>
/* ------------------------------------------------------------------
   Paste, drop, or choose — all three end up in the same file input, and
   all three show the same preview so the officer can see what is
   attached before they record the money.
   ------------------------------------------------------------------ */
document.addEventListener('DOMContentLoaded', function () {
    if (!window.DataTransfer) return;

    /* Read after the page is built, so every field on it is picked up
       whatever order they were rendered in. */
    var boxes = document.querySelectorAll('[data-paste-drop]');
    if (!boxes.length) return;

    /* Can they actually see it? A shut <details> is the case that matters —
       this field lives in one — and its contents still measure as laid out,
       so offsetParent and getClientRects both answer yes for a popup nobody
       has opened. The <details> element's own state is the honest test. */
    function visible(box) {
        for (var d = box.closest('details'); d; d = d.parentElement && d.parentElement.closest('details')) {
            if (!d.open) return false;
        }
        return !!(box.offsetParent || box.getClientRects().length);
    }

    var hovered = null;

    /* ---- reading the number off the picture ----------------------------

       The engine is fetched only when a form that wants it is opened, and
       only once. It runs in this browser: the receipt is never uploaded
       anywhere to be read.                                              */
    var TESSERACT_SRC = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
    var engine = null;

    function loadEngine() {
        if (engine) return engine;

        engine = new Promise(function (resolve, reject) {
            if (window.Tesseract) { resolve(window.Tesseract); return; }

            var tag = document.createElement('script');
            tag.src = TESSERACT_SRC;
            tag.async = true;
            tag.onload = function () {
                window.Tesseract ? resolve(window.Tesseract) : reject(new Error('no engine'));
            };
            tag.onerror = function () { reject(new Error('offline')); };
            document.head.appendChild(tag);
        });

        return engine;
    }

    /* The reference number out of everything the engine read.

       A receipt is mostly money and dates, and both are long runs of digits,
       so those are thrown out before anything is chosen. A labelled number
       wins outright; with no label the longest remaining run is the same
       guess a person makes reading it. */
    function referenceIn(text) {
        var cleaned = String(text || '')
            .replace(/[‘’“”]/g, '')
            /* A stroke read as a letter is the usual mistake on a number. */
            .replace(/[|lI]/g, '1')
            .replace(/[Oo](?=\d)|(?<=\d)[Oo]/g, '0');

        var withoutMoney = cleaned
            .replace(/[₱$]\s*[\d,]+(?:\.\d{2})?/g, ' ')
            .replace(/[\d,]+\.\d{2}/g, ' ')
            .replace(/\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}/g, ' ')
            .replace(/\d{1,2}:\d{2}(?::\d{2})?/g, ' ');

        var labelled = withoutMoney.match(
            /ref(?:erence)?\.?[ \t]*(?:no\.?|num(?:ber)?|#)?[ \t]*[:\-]?[ \t]*([0-9][0-9 \t-]{5,})/i
        );

        if (labelled) {
            var tagged = labelled[1].replace(/[^0-9]/g, '');
            if (tagged.length >= 6) return tagged;
        }

        var best = '';
        (withoutMoney.match(/\d[\d \t-]{4,}\d/g) || []).forEach(function (run) {
            var digits = run.replace(/[^0-9]/g, '');
            if (digits.length > best.length) best = digits;
        });

        return best.length >= 6 ? best : '';
    }

    function readNumberFrom(box, file) {
        var wanted = box.getAttribute('data-ocr-target');
        var status = box.querySelector('[data-ocr-status]');
        var form = box.closest('form');
        var field = (wanted && form) ? form.querySelector('[name="' + wanted + '"]') : null;
        if (!wanted || !status || !field) return;

        function say(state, words) {
            status.hidden = false;
            status.className = 'paste-drop-ocr is-' + state;
            status.textContent = words;
        }

        say('working', 'Reading the reference number off the picture…');

        loadEngine().then(function (T) {
            return T.recognize(file, 'eng');
        }).then(function (result) {
            var found = referenceIn(result && result.data ? result.data.text : '');

            if (!found) {
                say('none', 'No reference number found in the picture — please type it in.');
                return;
            }

            /* Never over-type what the officer put there themselves. Their
               own number is the one that has been checked. */
            if (field.value && !field.classList.contains('is-suggested')) {
                say('none', 'The picture reads “' + found + '” — you have typed something else.');
                return;
            }

            field.value = found;
            field.classList.add('is-suggested');
            say('found', 'Read “' + found + '” off the picture — check it matches the receipt before recording.');

            field.focus();
            field.select();
        }).catch(function () {
            say('none', 'Could not read the picture — please type the reference number in.');
        });
    }

    boxes.forEach(function (box) {
        var input = box.querySelector('.paste-drop-input');
        var preview = box.querySelector('.paste-drop-preview');
        var thumb = box.querySelector('.paste-drop-thumb');
        var nameOut = box.querySelector('.paste-drop-name');
        var clear = box.querySelector('.paste-drop-clear');
        if (!input) return;

        box.addEventListener('mouseenter', function () { hovered = box; });
        box.addEventListener('mouseleave', function () { if (hovered === box) hovered = null; });

        function show() {
            var file = input.files && input.files[0];
            if (!file) {
                preview.hidden = true;
                thumb.hidden = true;
                if (thumb.src) { URL.revokeObjectURL(thumb.src); thumb.removeAttribute('src'); }
                box.classList.remove('has-file');
                return;
            }

            nameOut.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
            preview.hidden = false;
            box.classList.add('has-file');

            /* A PDF receipt has no thumbnail to show; its name is the proof
               that something is attached. */
            if (/^image\//.test(file.type)) {
                if (thumb.src) URL.revokeObjectURL(thumb.src);
                thumb.src = URL.createObjectURL(file);
                thumb.hidden = false;
            } else {
                thumb.hidden = true;
            }

            /* Automatic: the officer pasted a receipt, so read it. Every way
               a file arrives comes through here, so choosing one by hand is
               read too. A PDF is not — the engine wants a picture. */
            if (box.hasAttribute('data-ocr-target') && /^image\//.test(file.type)) {
                readNumberFrom(box, file);
            }
        }

        function put(file) {
            if (!file) return false;
            var t = new DataTransfer();
            t.items.add(file);
            input.files = t.files;
            /* change does not fire for a programmatic assignment. */
            show();
            box.classList.add('just-pasted');
            setTimeout(function () { box.classList.remove('just-pasted'); }, 900);
            return true;
        }

        input.addEventListener('change', show);

        clear.addEventListener('click', function () {
            input.value = '';
            show();

            var status = box.querySelector('[data-ocr-status]');
            if (status) { status.hidden = true; status.textContent = ''; }
        });

        /* The suggestion stops being a suggestion the moment they touch it. */
        var wanted = box.getAttribute('data-ocr-target');
        var form = box.closest('form');
        var field = (wanted && form) ? form.querySelector('[name="' + wanted + '"]') : null;
        if (field) {
            field.addEventListener('input', function () { field.classList.remove('is-suggested'); });
        }

        /* Opening the form is the cue to fetch the engine, so it is ready by
           the time they have the screenshot in the clipboard. */
        var det = box.closest('details');
        if (det && wanted) {
            det.addEventListener('toggle', function () {
                if (det.open) loadEngine().catch(function () {});
            });
        }

        /* Dropping a file from Explorer onto the box. */
        ['dragenter', 'dragover'].forEach(function (type) {
            box.addEventListener(type, function (e) {
                if (!e.dataTransfer || !e.dataTransfer.types) return;
                if (Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') < 0) return;
                e.preventDefault();
                box.classList.add('is-dragging');
            });
        });

        ['dragleave', 'dragend'].forEach(function (type) {
            box.addEventListener(type, function () { box.classList.remove('is-dragging'); });
        });

        box.addEventListener('drop', function (e) {
            if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
            e.preventDefault();
            box.classList.remove('is-dragging');
            put(e.dataTransfer.files[0]);
        });

        box._pasteInto = put;
    });

    document.addEventListener('paste', function (e) {
        /* Typing in a field means the paste is theirs — the reference number
           is pasted into this very form. */
        var el = document.activeElement;
        if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) && el.type !== 'file') return;

        var items = (e.clipboardData || {}).items || [];
        var file = null;
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
                file = items[i].getAsFile();
                break;
            }
        }
        if (!file) return;

        /* The box under the pointer, or the only one they can see. */
        var open = Array.prototype.filter.call(boxes, visible);
        var target = (hovered && visible(hovered)) ? hovered : (open.length === 1 ? open[0] : null);
        if (!target || !target._pasteInto) return;

        if (target._pasteInto(file)) {
            e.preventDefault();
            target.scrollIntoView({ block: 'nearest' });
        }
    });
});
</script>
@endonce
