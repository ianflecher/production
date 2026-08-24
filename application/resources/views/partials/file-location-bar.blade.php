{{-- Where the print-ready files are, on its own, with a button that copies it.

     The station has to PASTE this into Explorer. Reading it off the tech pack
     and retyping a network path by hand is how a printer ends up in the wrong
     folder — it is one long string of digits and back-slashes, and one wrong
     digit looks exactly like the right one.

     Only for the stations that OPEN those files — the printer, the sticker
     station, embroidery. A sewer or a QC checker has a garment in their hands,
     not a folder to find, and a network path on their page is one more thing
     between them and the seam they are looking at.

     Expects: $order and $station (the station key).
     Shows nothing until the artist has recorded a path. --}}
@php
    $filePath = $order->techPack?->file_location_notes;
    $opensFiles = in_array(
        \App\Services\Stations::scope($station ?? ''),
        ['printer', 'sticker', 'embroidery'],
        true
    );
@endphp

@if (filled($filePath) && $opensFiles)
    <div class="floc-bar no-print">
        <div class="floc-label">Print files</div>
        <code class="floc-path" id="flocPath">{{ $filePath }}</code>
        <button type="button" class="btn btn-primary btn-sm floc-copy"
                data-path="{{ $filePath }}">Copy path</button>
        {{-- How to open it, for whoever has not done it before. --}}
        <div class="floc-how">
            <kbd>Windows</kbd> + <kbd>R</kbd>, paste, <kbd>Enter</kbd>
        </div>
    </div>

    <script>
        (function () {
            var button = document.querySelector('.floc-copy');
            if (!button) { return; }

            button.addEventListener('click', function () {
                var path = button.dataset.path;
                var said = function (word) {
                    button.textContent = word;
                    setTimeout(function () { button.textContent = 'Copy path'; }, 1600);
                };

                // The clipboard API needs a secure context. Over plain http on
                // the office network it is not there, so fall back to selecting
                // the text — the operator can still hit Ctrl+C.
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(path).then(
                        function () { said('Copied'); },
                        function () { said('Press Ctrl+C'); }
                    );
                    return;
                }

                var node = document.getElementById('flocPath');
                var range = document.createRange();
                range.selectNodeContents(node);
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                said('Press Ctrl+C');
            });
        })();
    </script>
@endif
