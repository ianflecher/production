{{-- Paste an image straight into a file input that is already on the page.

     The artist has the drawing in the clipboard the moment they finish it, and
     the officer has the reference the moment they are given it. Saving to the
     desktop, then finding it again in a file dialog, is three steps for
     something they are already holding - and the file ends up named
     "Screenshot 2026-09-14 101530.png" in a folder nobody clears out.

     This is the sibling of partials/paste-image-field, which builds its own
     box and takes ONE file. These inputs already exist, already take several
     files, and already have things listening to them: the artist's form draws
     a list of what is picked with a remove button on each, and the officer's
     uploads the moment anything arrives. So this adds nothing of its own - it
     puts the file in and fires `change`, and whatever was already listening
     carries on as if a person had chosen it.

     Kept separate rather than folded into the other one on purpose. That field
     sits on the payment form and reads reference numbers off receipts; it is
     the money path, and this is a convenience for the design desk. Two small
     scripts that each do one thing beat one that does both and is frightening
     to change.

     Mark any file input with data-paste-into to opt in. --}}
@once
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* Needed to build a FileList by hand. Without it the browser is too old
       to accept a file it did not get from a dialog, and choosing one still
       works, which is the whole fallback. */
    if (!window.DataTransfer) return;

    var inputs = document.querySelectorAll('input[type="file"][data-paste-into]');
    if (!inputs.length) return;

    var hovered = null;

    /* A clipboard image is always called "image.png". Three of them are three
       files with the same name, in a list where the name is the only thing
       telling them apart - and they land in storage that way too. */
    function named(file, index) {
        var stamp = new Date().toISOString().slice(0, 19).replace(/[-:]/g, '').replace('T', '-');
        var ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');

        return new File([file], 'pasted-' + stamp + (index ? '-' + (index + 1) : '') + '.' + ext,
            { type: file.type });
    }

    function flash(input) {
        var box = input.closest('[data-paste-into-box]') || input;
        box.classList.add('just-pasted');
        setTimeout(function () { box.classList.remove('just-pasted'); }, 900);
    }

    function put(input, files) {
        var carry = new DataTransfer();

        /* Several files means several: a second paste adds to the first
           rather than replacing it, because a design can be a front and a
           back and they are chosen one at a time. */
        if (input.multiple) {
            Array.prototype.forEach.call(input.files || [], function (existing) {
                carry.items.add(existing);
            });
        }

        files.forEach(function (file, i) { carry.items.add(named(file, i)); });

        input.files = carry.files;

        /* Assigning files fires nothing, and everything that matters here is
           listening for change - the artist's picked-file list, and the
           officer's form, which uploads on it. */
        input.dispatchEvent(new Event('change', { bubbles: true }));
        flash(input);

        return true;
    }

    function visible(el) {
        for (var d = el.closest('details'); d; d = d.parentElement && d.parentElement.closest('details')) {
            if (!d.open) return false;
        }

        return !!(el.offsetParent || el.getClientRects().length);
    }

    Array.prototype.forEach.call(inputs, function (input) {
        var box = input.closest('[data-paste-into-box]') || input;

        box.addEventListener('mouseenter', function () { hovered = input; });
        box.addEventListener('mouseleave', function () { if (hovered === input) hovered = null; });

        /* Dropping one from Explorer, the same as pasting. */
        ['dragenter', 'dragover'].forEach(function (type) {
            box.addEventListener(type, function (e) {
                if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') < 0) return;
                e.preventDefault();
                box.classList.add('is-dragging');
            });
        });

        ['dragleave', 'dragend', 'drop'].forEach(function (type) {
            box.addEventListener(type, function () { box.classList.remove('is-dragging'); });
        });
    });

    document.addEventListener('paste', function (e) {
        /* Typing in a box means the paste belongs to that box. */
        var el = document.activeElement;
        if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) && el.type !== 'file') return;
        if (el && el.isContentEditable) return;

        var items = (e.clipboardData || {}).items || [];
        var files = [];

        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
                var f = items[i].getAsFile();
                if (f) files.push(f);
            }
        }

        if (!files.length) return;

        /* The one under the pointer, or the only one they can see. With
           several on screen and the mouse nowhere near them, guessing which
           design they meant would attach the drawing to the wrong one. */
        var open = Array.prototype.filter.call(inputs, visible);
        var target = (hovered && visible(hovered)) ? hovered
            : (open.length === 1 ? open[0] : null);

        if (!target) return;

        if (put(target, files)) {
            e.preventDefault();
            target.scrollIntoView({ block: 'nearest' });
        }
    });
});
</script>
@endonce
