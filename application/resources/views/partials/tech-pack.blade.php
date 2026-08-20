{{-- The Imprint Customs tech pack.

     One sheet in place of two pages. The job order sheet carried the garment
     spec and the mockup page carried the picture, so making a shirt meant
     holding both open and reading across — and neither of them printed the
     thing production actually asks for, which is where the artwork goes and
     how big it comes out.

     Laid out like the shop's own tech pack: the mockup and its colourways down
     the left, the header and materials tables across the top right, the print
     placements with their real sizes in the middle, and where the files live at
     the foot.

     Expects: $order, with tasks.files, client and creator loaded. --}}
@php
    $jo = $order->jobOrder;

    // The FINAL MOCKUP once the artist has made one; before that the approved
    // layout, so the sheet always shows the current design rather than a blank.
    $mockupTask = $order->tasks->firstWhere('department', 'Final mockup');
    $layoutTask = $order->tasks->firstWhere('department', 'Layout');
    $imgTask = ($mockupTask && $mockupTask->files->isNotEmpty()) ? $mockupTask : $layoutTask;
    $art = $imgTask?->files
        ->where('round', ($imgTask->revision_count ?? 0) + 1)
        ->filter(fn ($f) => $f->isImage()) ?? collect();

    // The production template: the flats the artist draws, which is what the
    // middle panel of the shop's tech pack carries. It is a different thing
    // from the mockup — the mockup is what the client approved, the template
    // is what the floor cuts and prints to.
    $templateTask = $order->tasks->first(fn ($t) => str_starts_with((string) $t->department, 'Production template'));
    $templates = $templateTask?->files
        ->where('round', ($templateTask->revision_count ?? 0) + 1)
        ->filter(fn ($f) => $f->isImage()) ?? collect();

    if ($templates->isEmpty()) {
        $templates = $templateTask?->files->filter(fn ($f) => $f->isImage()) ?? collect();
    }

    $artist = $order->tasks
        ->first(fn ($t) => $t->team === \App\Models\User::JOB_ARTIST && $t->assignee)?->assignee?->name;

    // Where the print-ready files were saved. The paths are the truth; the
    // folder picture beside them is only what the shop is used to seeing.
    $exportTask = $order->tasks->first(fn ($t) => $t->isExportStep());
    $exportFiles = $exportTask?->files->filter(fn ($f) => $f->isExternal()) ?? collect();

    $placements = collect($jo?->print_placements ?? [])
        ->filter(fn ($p) => filled($p['label'] ?? null));

    $colorways = collect(explode(',', (string) ($jo?->colorways ?? '')))
        ->map(fn ($c) => trim($c))
        ->filter();

    // "STANDARD DTF PLACING FOR SHIRT" — built from the print method and the
    // garment rather than retyped on every sheet.
    $banner = strtoupper(trim(
        'Standard '.($jo?->printTypeLabel() ?: 'print')
        .' placing for '.($order->productLabel() ?: 'garment')
    ));

    // The artist fills the pack itself, the way the floor fills the seam
    // record: you read the spec and answer it in the same place, rather than
    // holding a form open beside the thing it describes.
    $editable = $editable ?? false;

    $val = fn ($v) => filled($v) ? $v : '—';

    // A printed value, or the box to type it into.
    $fill = function (string $field, string $placeholder = '', int $max = 120, $current = null) use ($jo, $editable) {
        $value = (string) ($current ?? $jo?->$field ?? '');

        if (! $editable) {
            return e($value !== '' ? $value : '—');
        }

        return '<input class="tp-in" type="text" name="'.$field.'"'
            .' value="'.e($value).'" maxlength="'.$max.'"'
            .' placeholder="'.e($placeholder).'">';
    };

    // Print type and printer stay a pick from the list rather than free text:
    // the pipeline routes on these, and "DTF " with a stray space is a job the
    // board cannot place.
    $choose = function (string $field, array $options, ?string $current, string $printed) use ($editable) {
        if (! $editable) {
            return e($printed !== '' ? $printed : '—');
        }

        $html = '<select class="tp-in" name="'.$field.'"><option value="">—</option>';

        foreach ($options as $key => $label) {
            $sel = ((string) $current === (string) $key) ? ' selected' : '';
            $html .= '<option value="'.e($key).'"'.$sel.'>'.e($label).'</option>';
        }

        return $html.'</select>';
    };

    // The due date belongs to the ORDER, not the job order — it drives the
    // calendar and the shop's capacity, so it is written back there.
    $dateBox = function (string $name, $date) use ($editable) {
        if (! $editable) {
            return $date ? e($date->format('F j, Y')) : '—';
        }

        return '<input class="tp-in" type="date" name="'.$name.'" value="'
            .($date ? $date->format('Y-m-d') : '').'">';
    };

    // A colourway named after a colour gets that colour; anything else gets a
    // neutral chip rather than a confidently wrong one.
    $swatch = function (string $name) {
        $known = [
            'black' => '#111111', 'white' => '#ffffff', 'red' => '#d21f26',
            'navy' => '#1e2a53', 'blue' => '#2563eb', 'royal blue' => '#1d4ed8',
            'green' => '#15803d', 'yellow' => '#eab308', 'orange' => '#ea580c',
            'grey' => '#9ca3af', 'gray' => '#9ca3af', 'maroon' => '#7f1d1d',
            'accent' => '#ea580c',
        ];

        return $known[strtolower(trim($name))] ?? '#d4d4d8';
    };
@endphp

<div class="tp-sheet">

    {{-- ============ LEFT: the approved mockup ============ --}}
    <section class="tp-mockup">
        <div class="tp-strip">Approved mockup: front and back</div>

        @if ($colorways->isNotEmpty() || $editable)
            <div class="tp-colorways">
                @foreach ($colorways as $c)
                    <div class="tp-cw">
                        <span class="tp-dot" style="background: {{ $swatch($c) }};"></span>
                        <span class="tp-cw-name">{{ $c }}</span>
                    </div>
                @endforeach

                @if ($editable)
                    <label class="tp-cw-edit">
                        <span>Colourways</span>
                        <input type="text" name="colorways" maxlength="200"
                               value="{{ $jo?->colorways }}" placeholder="Black, White, Accent">
                    </label>
                @endif
            </div>
        @endif

        <div class="tp-art">
            @forelse ($art as $f)
                <figure>
                    <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label ?? 'Mockup' }}">
                    @if ($f->label)<figcaption>{{ $f->label }}</figcaption>@endif
                </figure>
            @empty
                <div class="tp-empty">
                    No mockup yet.
                    <span>It appears here as soon as the artist submits one.</span>
                </div>
            @endforelse
        </div>
    </section>

    {{-- ============ RIGHT ============ --}}
    <section class="tp-body">

        <div class="tp-head">
            <div class="tp-brand">
                {{-- The shop's actual mark, not the name typed out. --}}
                <img class="tp-logo" src="{{ asset('logo.jpg') }}" alt="Imprint Customs">

                <div class="tp-sample">SAMPLE</div>

                {{-- The artist's production template goes here, under the
                     SAMPLE label: the flats the floor cuts and prints to. Not
                     the mockup — that is what the client approved, and it is
                     already down the left. --}}
                <div class="tp-template">
                    @forelse ($templates as $t)
                        <img src="{{ route('tasks.file.view', $t) }}" alt="Production template">
                    @empty
                        <div class="tp-template-empty">
                            Template goes here
                            <span>The artist uploads it on the Production template step.</span>
                        </div>
                    @endforelse
                </div>
            </div>

            <table class="tp-tbl tp-headtbl">
                <tr>
                    <th>Client</th><td>{{ $val($order->clientName()) }}</td>
                    <th>Design name</th><td class="tp-red">{!! $fill('design_name', 'e.g. Aerox Lifestyle — White') !!}</td>
                </tr>
                <tr>
                    <th>Agent</th><td>{{ $val($order->creator?->name) }}</td>
                    <th>Fitting</th><td>{!! $fill('fitting', 'e.g. Original fit', 60) !!}</td>
                </tr>
                <tr>
                    <th>Type / style</th><td>{!! $fill('product_type', 'e.g. Cotton shirt', 100, $order->productLabel()) !!}</td>
                    <th>Print type</th><td>{!! $choose('print_type', \App\Models\JobOrder::printTypeOptions(), $jo?->print_type, (string) $jo?->printTypeLabel()) !!}</td>
                </tr>
                <tr>
                    <th>Printer</th><td>{!! $choose('printer', \App\Models\JobOrder::PRINTERS, $jo?->printer, (string) $jo?->printerLabel()) !!}</td>
                    {{-- When the order was taken. A record, not a choice. --}}
                    <th>Date ordered</th><td>{{ $order->created_at?->format('F j, Y') ?? '—' }}</td>
                </tr>
                <tr>
                    <th>Fabric</th><td>{!! $fill('fabric', 'e.g. Cotton blend') !!}</td>
                    <th>Delivery date</th><td>{!! $dateBox('due_date', $order->due_date) !!}</td>
                </tr>
            </table>
        </div>

        <div class="tp-mid">
            {{-- The artwork at the size it actually prints. This is the part
                 production could get from neither of the old pages. --}}
            <div class="tp-print">
                <div class="tp-print-inner">
                    @if ($editable)
                        {{-- Typed where they are read. One spare row is always
                             offered, so adding a placement never means finding
                             a button first. --}}
                        @php $rows = $placements->values()->push(['label' => '', 'width' => '', 'height' => '']); @endphp

                        <div class="tp-place-edit" id="tpPlaces">
                            @foreach ($rows as $i => $p)
                                <div class="tp-place-row">
                                    <input type="text" name="placements[{{ $i }}][label]" maxlength="60"
                                           value="{{ $p['label'] ?? '' }}" placeholder="Placement, e.g. Back">
                                    <input type="number" step="0.001" min="0" max="999"
                                           name="placements[{{ $i }}][width]"
                                           value="{{ $p['width'] ?? '' }}" placeholder="W in">
                                    <span class="tp-x">&times;</span>
                                    <input type="number" step="0.001" min="0" max="999"
                                           name="placements[{{ $i }}][height]"
                                           value="{{ $p['height'] ?? '' }}" placeholder="H in">
                                </div>
                            @endforeach
                        </div>

                        <button type="button" class="tp-add" id="tpAddPlace">+ Another placement</button>
                    @else
                        @forelse ($placements as $p)
                            <div class="tp-place">
                                <div class="tp-place-label">{{ strtoupper($p['label']) }}</div>
                                <div class="tp-place-size">
                                    Actual size
                                    <strong>{{ $p['width'] ?? '?' }}" &times; {{ $p['height'] ?? '?' }}"</strong>
                                </div>
                            </div>
                        @empty
                            <div class="tp-place-empty">No print placements recorded yet.</div>
                        @endforelse
                    @endif
                </div>
                <div class="tp-banner">{{ $banner }}</div>
            </div>

            <div class="tp-materials">
                <div class="tp-strip tp-strip-dark">Materials and components</div>
                <table class="tp-tbl">
                    @if ($editable)
                        <tr><th>Neck type</th><td class="tp-pair">
                            {!! $fill('neck', 'e.g. Round neck', 100) !!}
                            {!! $fill('neck_size', 'size, e.g. 1 x 1 ribbings', 60) !!}
                        </td></tr>
                    @else
                        <tr><th>Neck type</th><td>{{ $val(trim(($jo?->neck ?? '').($jo?->neck_size ? ' / '.$jo->neck_size : ''))) }}</td></tr>
                    @endif
                    @if ($editable)
                        <tr><th>Cuff / arm slv</th><td class="tp-pair">
                            {!! $fill('cuff_arm_sleeves', 'e.g. Tupi', 100) !!}
                            {!! $fill('cuff_size', 'size, e.g. 3 inches', 60) !!}
                        </td></tr>
                    @else
                        <tr><th>Cuff / arm slv</th><td>{{ $val(trim(($jo?->cuff_arm_sleeves ?? '').($jo?->cuff_size ? ' / '.$jo->cuff_size : ''))) }}</td></tr>
                    @endif
                    <tr><th>Nape label</th><td>{!! $fill('neck_label', 'e.g. IC DTF — original fit') !!}</td></tr>
                    <tr><th>Thread color</th><td>{!! $fill('thread_color', 'e.g. Black', 60) !!}</td></tr>
                    <tr><th>Zipper type</th><td>{!! $fill('zipper_type', 'e.g. N/A', 60) !!}</td></tr>
                    <tr><th>Bottom hem</th><td>{!! $fill('bottom_hem', 'e.g. Straight cut') !!}</td></tr>
                    <tr><th>Packaging</th><td>{!! $fill('packaging', 'e.g. Ordinary') !!}</td></tr>
                    <tr><th>BP pocket color</th><td>{!! $fill('bp_pocket_color', 'e.g. N/A', 60) !!}</td></tr>
                    <tr><th>Sticker</th><td>{!! $fill('free_logo_sticker', 'e.g. IC sticker') !!}</td></tr>
                    @if (filled($jo?->ic_placement))
                        <tr><th>IC placement</th><td>{{ $jo->ic_placement }}</td></tr>
                    @endif
                    @if (filled($jo?->addon))
                        <tr><th>Add-on</th><td>{{ $jo->addonLabel() }}{{ $jo->addon_note ? ' — '.$jo->addon_note : '' }}</td></tr>
                    @endif
                </table>
            </div>
        </div>

        @if (filled($jo?->special_instructions))
            <div class="tp-note"><strong>Special instructions:</strong> {{ $jo->special_instructions }}</div>
        @endif

        {{-- ============ FILE LOCATION ============ --}}
        <div class="tp-files">
            <div class="tp-strip tp-strip-dark">File location</div>

            @if ($exportFiles->isNotEmpty())
                <table class="tp-tbl tp-filetbl">
                    <thead><tr><th>What</th><th>Where it was saved</th></tr></thead>
                    <tbody>
                        @foreach ($exportFiles as $f)
                            <tr>
                                <td>{{ $f->label ?? 'File' }}</td>
                                <td><code>{{ $f->external_path }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="tp-empty-line">Nothing exported yet — the paths appear once the artist hands the files over.</p>
            @endif

            @if (filled($jo?->folder_shot_path))
                <figure class="tp-shot">
                    <img src="{{ route('job-orders.folder-shot', $order) }}" alt="Export folder">
                    <figcaption>{{ $jo->folder_shot_name }}</figcaption>
                </figure>
            @endif

            @if ($editable)
                <div class="tp-shot-edit">
                    <label for="tp_shot">Picture of the export folder (optional)</label>
                    <input id="tp_shot" type="file" name="folder_shot" accept=".jpg,.jpeg,.png,.webp" class="no-caps">
                    <span>The paths above are printed from what you handed over. This is
                    only the familiar screenshot, and it will not update itself if the
                    files move.</span>
                </div>
            @endif
        </div>

        <div class="tp-foot">
            <div>Artist: <strong>{{ $val($artist) }}</strong></div>
            <div>{{ $order->order_number }} · {{ number_format($order->quantity) }} pcs</div>
            <div class="tp-foot-brand">Imprint Customs Tech Pack</div>
        </div>
    </section>
</div>

@if ($editable)
    <script>
        // One spare row is offered already; this adds more for a design with a
        // lot of placements, without a page round trip.
        (function () {
            var add = document.getElementById('tpAddPlace');
            var box = document.getElementById('tpPlaces');
            if (!add || !box) { return; }

            add.addEventListener('click', function () {
                var n = box.querySelectorAll('.tp-place-row').length;
                var row = document.createElement('div');
                row.className = 'tp-place-row';
                row.innerHTML =
                    '<input type="text" name="placements[' + n + '][label]" maxlength="60" placeholder="Placement, e.g. Left chest">' +
                    '<input type="number" step="0.001" min="0" max="999" name="placements[' + n + '][width]" placeholder="W in">' +
                    '<span class="tp-x">&times;</span>' +
                    '<input type="number" step="0.001" min="0" max="999" name="placements[' + n + '][height]" placeholder="H in">';
                box.appendChild(row);
                row.querySelector('input').focus();
            });
        })();
    </script>
@endif
