{{-- Imprint Customs tech pack - matched to the supplied fillable PDF. --}}
@php
    $jo = $order->jobOrder;
    $tp = $order->techPackOrNew();
    $editable = $editable ?? false;
    $mode = $mode ?? null;
    $textEditable = $editable || $mode === 'officer';
    $imageEditable = $editable && $mode !== 'officer';
    $mockupTask = $order->tasks->firstWhere('department', 'Final mockup');
    $assignedArtist = $mockupTask?->assignee
        ?? $order->tasks->first(fn ($task) => $task->assignee?->isArtist())?->assignee;
    $artistName = $assignedArtist?->name
        ?? (($imageEditable && auth()->user()?->isArtist()) ? auth()->user()->name : null)
        ?? $tp->artist_name;
    // Mockup boxes are deliberately manual. A task upload may be a full layout
    // sheet rather than a clean garment image, so never insert it automatically.
    $templateTask = $order->tasks->first(fn ($t) => str_starts_with((string) $t->department, 'Production template') || $t->department === 'Tech pack');
    $templates = $templateTask?->files->filter(fn ($f) => $f->isImage())->values() ?? collect();
    $uploadedImages = $tp->image_uploads ?? [];
    $slotSrc = function (string $slot, $fallback = null) use ($uploadedImages, $order) {
        if (filled($uploadedImages[$slot]['path'] ?? null)) return route('job-orders.tech-pack-image', ['order' => $order, 'slot' => $slot]);
        return $fallback ? route('tasks.file.view', $fallback) : null;
    };
    $val = fn ($value) => filled($value) ? $value : '—';
    $fill = function (string $field, string $placeholder = '', int $max = 120, $on = null) use ($tp, $textEditable) {
        $model = $on ?? $tp; $value = (string) ($model?->$field ?? '');
        if (! $textEditable) return e($value !== '' ? $value : '—');
        return '<input class="tp-in" type="text" name="'.$field.'" value="'.e($value).'" maxlength="'.$max.'" placeholder="'.e($placeholder).'">';
    };
    // The × takes the BOX away, picture and all — a plain tee does not want two
    // empty boxes printed under it. It is a submit button, so it travels the
    // same road as an upload: post the form, the server drops the slot, deletes
    // the file, and the panel comes back one box shorter.
    // NOT type=submit. Pressing Enter in any text box submits a form using its
    // FIRST submit button — which was this ×, on the mockup, at the top of the
    // sheet. So a stray Enter while typing quietly took the mockup off the pack
    // and saved. The script posts the removal itself when one is clicked.
    // Starts a leader line from this box. The pin lands over the mockup and is
    // dragged to the exact spot from there.
    $lineBtn = fn (string $slot) => $imageEditable
        ? '<button type="button" class="tp-ref-line-btn" data-line-for="'.$slot.'"'
          .' title="Draw a line to the garment" aria-label="Draw a line to the garment">&#8599;</button>'
        : '';

    $clearBtn = fn (string $slot, $src = null) => $imageEditable
        ? '<button type="button" class="tp-ref-clear" name="remove_image" value="'.$slot.'"'
          .' title="Remove this box" aria-label="Remove this box">&times;</button>'
        : '';
    $swatch = function (?string $name) {
        $known = ['black'=>'#111111','white'=>'#ffffff','red'=>'#d21f26','orange'=>'#e8542a','navy'=>'#1e2a53','blue'=>'#2563eb','royal blue'=>'#1d4ed8','green'=>'#15803d','yellow'=>'#eab308','grey'=>'#9ca3af','gray'=>'#9ca3af','maroon'=>'#7f1d1d'];
        if (preg_match('/^#[0-9a-f]{6}$/i', trim((string) $name))) return trim((string) $name);
        return $known[strtolower(trim((string) $name))] ?? '#d4d4d8';
    };
    $defaultBanner = strtoupper(trim('Standard '.($tp->print_tech ?: $jo?->printTypeLabel() ?: 'print').' placing for '.($tp->item_style ?: $order->productLabel() ?: 'garment')));
    $banner = filled($tp->placing_title) ? strtoupper($tp->placing_title) : '';
@endphp

<div class="tp-sheet tp-reference-sheet{{ $imageEditable ? ' is-editing' : '' }}">
    {{-- The leader lines, drawn over the sheet. A pack in the trade points from
         the woven-label box to the collar and from the front-print box to the
         chest; without that the floor matches pictures to places by eye. Each
         line is drawn by script from wherever the box and the pin have ended
         up, so moving either redraws it. --}}
    <svg class="tp-ref-lines" preserveAspectRatio="none" aria-hidden="true"></svg>
    @foreach ($tp->callouts() as $slot => $line)
        @if ($line['from'])
            @php
                $lineDx = $line['to']['x'] - $line['from']['x'];
                $lineDy = $line['to']['y'] - $line['from']['y'];
                $lineLength = hypot($lineDx, $lineDy);
                $lineAngle = rad2deg(atan2($lineDy, $lineDx));
            @endphp
            <span class="tp-ref-static-line" data-static-line="{{ $slot }}"
                  style="left:{{ $line['from']['x'] }}cqw; top:{{ $line['from']['y'] }}cqw; width:{{ $lineLength }}cqw; transform:rotate({{ $lineAngle }}deg);"></span>
            <span class="tp-ref-pin tp-ref-pin-from{{ $imageEditable ? ' is-pin-movable' : '' }}"
                  data-pin-slot="{{ $slot }}" data-pin-end="from"
                  style="left:{{ $line['from']['x'] }}cqw; top:{{ $line['from']['y'] }}cqw;"
                  title="{{ $imageEditable ? 'Drag where the line starts' : '' }}"></span>
        @endif
        <span class="tp-ref-pin{{ $imageEditable ? ' is-pin-movable' : '' }}"
              data-pin-slot="{{ $slot }}" data-pin-end="to"
              style="left:{{ $line['to']['x'] }}cqw; top:{{ $line['to']['y'] }}cqw;"
              title="{{ $imageEditable ? 'Drag onto the garment' : '' }}"></span>
    @endforeach

    <section class="tp-ref-mockups">
        <div class="tp-ref-title">Approved mockup</div>
        <div class="tp-ref-colorways"><strong>Colorways</strong>
            @foreach ([1,2,3] as $n)
                @php $color=$tp->{'color_'.$n}; @endphp
                <div class="tp-ref-color" style="--swatch:{{ $swatch($color) }}">
                    <span class="tp-ref-color-chip" aria-hidden="true"></span>
                    @if ($textEditable)<input type="text" name="color_{{ $n }}" value="{{ $color }}" maxlength="40" placeholder="Color {{ $n }}">@else<strong>{{ $color ?: ' ' }}</strong>@endif
                </div>
            @endforeach
        </div>
        @php $mockupSrc=$slotSrc('front_mockup'); @endphp
        <div class="tp-ref-mockup-block">
            @unless ($tp->boxIsHidden('front_mockup'))
            <div class="tp-ref-image tp-ref-image-mockup">
                @if($imageEditable)<label for="tp_image_front_mockup">@endif
                <img id="tp_preview_front_mockup" src="{{ $mockupSrc ?: '' }}" alt="Approved mockup" class="{{ $mockupSrc ? '' : 'is-empty' }}">{!! $clearBtn('front_mockup', $mockupSrc) !!}
                <span class="tp-ref-placeholder" @if($mockupSrc) hidden @endif><strong>Approved mockup image</strong><small>{{ $imageEditable ? 'Click or drop one combined image' : 'No image yet' }}</small></span>
                @if($imageEditable)<input id="tp_image_front_mockup" type="file" name="tech_pack_images[front_mockup]" accept=".jpg,.jpeg,.png,.webp" class="tp-image-input" data-preview="tp_preview_front_mockup"></label>@endif
            </div>
            @endunless
        </div>
    </section>

    <header class="tp-ref-header">
        <div class="tp-ref-wordmark"><span>IMPRINT</span><span>CUSTOMS</span></div>
        <table class="tp-ref-table tp-ref-head-table">
            <tr><th>Client</th><td>{{ $val($order->clientName()) }}</td><th>Design name</th><td>{!! $fill('design_name','Design name') !!}</td></tr>
            <tr><th>Agent</th><td>{{ $val($order->creator?->name) }}</td><th>Fitting</th><td>{!! $fill('fitting','Original fit',60) !!}</td></tr>
            <tr><th>Type / style</th><td>{!! $textEditable?$fill('item_style','Cotton shirt',100):e($val($tp->item_style?:$order->productLabel())) !!}</td><th>Print type</th><td>{!! $textEditable?$fill('print_type','DTF',60,$jo):e($val($jo?->printTypeLabel())) !!}</td></tr>
            <tr><th>Printer</th><td>@if($mode === 'officer')<select class="tp-in" name="printer" required><option value="">Choose printer</option>@foreach(\App\Models\JobOrder::PRINTERS as $key=>$label)<option value="{{ $key }}" @selected($jo?->printer===$key)>{{ $label }}</option>@endforeach</select>@else{{ $val($jo?->printerLabel()) }}@endif</td><th>Date created</th><td>{{ $order->created_at?->format('F j, Y')??'—' }}</td></tr>
            <tr><th>Fabric</th><td>{!! $fill('fabric','Cotton blend',255,$jo) !!}</td><th>Delivery date</th><td>{{ $order->due_date?->format('F j, Y')??'—' }}</td></tr>
            {{-- What was ordered, read like the size chart it came from: the
                 sizes along the top, the count for each one directly under it.
                 Off the order's own lines rather than retyped, so the sheet
                 cannot disagree with the order it was made from. --}}
            @php $sizeLines = $order->itemsInSizeOrder(); @endphp
            <tr>
                <td colspan="4" class="tp-ref-sizecell">
                    {{-- A grid rather than a table: both rows share the same
                         columns, each column is as wide as its own size, and the
                         run packs against the right. A nested table kept handing
                         the spare width to whichever size came first. --}}
                    <div class="tp-ref-sizegrid" style="--sizes: {{ max($sizeLines->count(), 1) }};">
                        <span class="tp-ref-sizelbl">Size list</span>
                        <span class="tp-ref-sizegap"></span>
                        @forelse ($sizeLines as $item)
                            <span class="tp-ref-sizecol">{{ $item->size ?: 'One' }}</span>
                        @empty
                            <span class="tp-ref-sizecol">&mdash;</span>
                        @endforelse
                        <span class="tp-ref-sizecol tp-ref-size-tot">Total</span>

                        <span class="tp-ref-sizelbl">Quantity</span>
                        <span class="tp-ref-sizegap"></span>
                        @forelse ($sizeLines as $item)
                            <span class="tp-ref-sizecol">{{ $item->quantity }}</span>
                        @empty
                            <span class="tp-ref-sizecol">&mdash;</span>
                        @endforelse
                        <span class="tp-ref-sizecol tp-ref-size-tot">{{ number_format($order->quantity) }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </header>

    <section class="tp-ref-flats"><div class="tp-ref-black-title">Sample</div><div class="tp-ref-flat-grid">
        @php
            $sampleBoxes = $tp->sampleBoxes();
            $sampleLabels = ['front_flat' => 'Front flat', 'back_flat' => 'Back flat'];
        @endphp
        @foreach ($sampleBoxes as $i => $slot)
            @php
                $label = $sampleLabels[$slot] ?? 'Sample '.($i + 1);
                $src = $slotSrc($slot, $templates->get($i));
            @endphp
            <div class="tp-ref-image tp-ref-image-small {{ $imageEditable ? 'is-resizable' : '' }}" data-size-slot="{{ $slot }}" data-move-slot="{{ $slot }}" style="{{ $tp->imageSizeStyle($slot) }}{{ $tp->boxPositionStyle($slot) }}" title="{{ $imageEditable ? 'Drag the lower-right corner to resize' : '' }}">@if($imageEditable)<label for="tp_image_{{ $slot }}">@endif<img id="tp_preview_{{ $slot }}" src="{{ $src?:'' }}" alt="{{ $label }}" class="{{ $src?'':'is-empty' }}">{!! $clearBtn($slot, $src) !!}{!! $lineBtn($slot) !!}<span class="tp-ref-placeholder" @if($src) hidden @endif><strong>{{ $label }}</strong><small>{{ $imageEditable?'Click to upload; drag corner to resize':'No image yet' }}</small></span>@if($imageEditable)<input id="tp_image_{{ $slot }}" type="file" name="tech_pack_images[{{ $slot }}]" accept=".jpg,.jpeg,.png,.webp" class="tp-image-input" data-preview="tp_preview_{{ $slot }}"></label>@endif</div>
        @endforeach

    </div></section>

    <section class="tp-ref-materials"><div class="tp-ref-black-title">Materials and components</div><table class="tp-ref-table">
        <tr><th>Neck type</th><td>{!! $textEditable?$fill('neck','Round neck / 1 x 1 ribbings',100,$jo):e($val(trim(($jo?->neck??'').($jo?->neck_size?' / '.$jo->neck_size:'')))) !!}</td></tr>
        <tr><th>Cuff / hem style</th><td>{!! $fill('cuff_arm_sleeves','Tupi',100,$jo) !!}</td></tr><tr><th>@if($textEditable)<select class="tp-ref-label-select" name="label_type"><option value="print_label" @selected(($tp->label_type ?: 'print_label') === 'print_label')>Print label</option><option value="neck_label" @selected($tp->label_type === 'neck_label')>Neck label</option></select>@else{{ $tp->label_type === 'neck_label' ? 'Neck label' : 'Print label' }}@endif</th><td>{!! $fill('neck_label','IC DTF - original fit',120,$jo) !!}</td></tr>
        <tr><th>@if($textEditable)<select class="tp-ref-label-select" name="color_type"><option value="tshirt_color" @selected(($tp->color_type ?: 'tshirt_color') === 'tshirt_color')>T-shirt color</option><option value="thread_color" @selected($tp->color_type === 'thread_color')>Thread color</option></select>@else{{ $tp->color_type === 'thread_color' ? 'Thread color' : 'T-shirt color' }}@endif</th><td>{!! $fill('tshirt_color','Black',60) !!}</td></tr><tr><th>Stitch thread</th><td>{!! $fill('stitch_thread','N/A',60) !!}</td></tr>
        <tr><th>Cutting method</th><td>{!! $fill('cutting_method','Straight cut',60) !!}</td></tr><tr><th>Packaging</th><td>{!! $fill('packaging','Polybag',120,$jo) !!}</td></tr>
        <tr><th>Zipper type</th><td>{!! $fill('zipper_type','e.g. Metal, nylon, none',60) !!}</td></tr>
        <tr><th>Bottom hem</th><td>{!! $fill('bottom_hem','e.g. Straight',100,$jo) !!}</td></tr>
        <tr><th>Lip pocket color</th><td>{!! $fill('lip_pocket_color','Pocket color',60) !!}</td></tr>
        <tr><th>Size range</th><td>{!! $fill('size_range','M-2XL',60) !!}</td></tr><tr class="tp-ref-sticker"><th>Sticker / extra</th><td>{!! $fill('free_logo_sticker','IC sticker',120,$jo) !!}</td></tr>
    
        {{-- What the job is made of, and how much of each. The officer's half:
             this list is what raises the request the materials desk answers, and
             the amount beside it is what the desk is allowed to issue. A job
             with no list raises no request, and nobody is told. --}}
        @if ($mode === 'officer')
            @php
                $materials = old('raw_materials', $jo?->rawMaterialsList() ?: ['']);
                $materials = array_values(array_filter((array) $materials, fn ($m) => filled($m))) ?: [''];
            @endphp
            @foreach ($materials as $i => $material)
                <tr class="tp-ref-material-row">
                    <th>{{ $i === 0 ? 'Raw materials' : '' }}</th>
                    <td>
                        <span class="tp-ref-material">
                            <input class="tp-in" type="text" name="raw_materials[]" maxlength="255"
                                   value="{{ $material }}" placeholder="e.g. Cotton shirt blank">
                            <input class="tp-in tp-ref-material-qty" type="number" min="0" step="0.01"
                                   name="raw_material_qty[]" placeholder="How many"
                                   value="{{ $jo?->rawMaterialQuantity($material) }}">
                        </span>
                    </td>
                </tr>
            @endforeach
            <tr class="tp-ref-material-row no-print">
                <th></th>
                <td><button type="button" class="tp-ref-add-material">+ Another material</button></td>
            </tr>
        @elseif ($jo?->rawMaterialsList())
            @foreach ($jo->rawMaterialsList() as $i => $material)
                <tr>
                    <th>{{ $i === 0 ? 'Raw materials' : '' }}</th>
                    <td>{{ $material }}@if ($jo->rawMaterialQuantity($material)) &times; {{ rtrim(rtrim(number_format($jo->rawMaterialQuantity($material), 2), '0'), '.') }}@endif</td>
                </tr>
            @endforeach
        @endif
    </table>
    </section>

    <section class="tp-ref-artwork"><div class="tp-ref-two-images">
        @foreach (['back_artwork'=>'Back artwork','front_artwork'=>'Front artwork'] as $slot=>$label)
            @php $src=$slotSrc($slot); $back=str_starts_with($slot,'back'); @endphp
            @continue ($tp->boxIsHidden($slot))
            <div class="tp-ref-art-column"><div class="tp-ref-image tp-ref-image-artwork {{ $imageEditable ? 'is-resizable' : '' }}" data-size-slot="{{ $slot }}" data-move-slot="{{ $slot }}" style="{{ $tp->imageSizeStyle($slot) }}{{ $tp->boxPositionStyle($slot) }}" title="{{ $imageEditable ? 'Drag the lower-right corner to resize' : '' }}">@if($imageEditable)<label for="tp_image_{{ $slot }}">@endif<img id="tp_preview_{{ $slot }}" src="{{ $src?:'' }}" alt="{{ $label }}" class="{{ $src?'':'is-empty' }}">{!! $clearBtn($slot, $src) !!}{!! $lineBtn($slot) !!}<span class="tp-ref-placeholder" @if($src) hidden @endif><strong>{{ $label }}</strong><small>{{ $imageEditable?'Click to upload; drag corner to resize':'No image yet' }}</small></span>@if($imageEditable)<input id="tp_image_{{ $slot }}" type="file" name="tech_pack_images[{{ $slot }}]" accept=".jpg,.jpeg,.png,.webp" class="tp-image-input" data-preview="tp_preview_{{ $slot }}"></label>@endif</div></div>
        @endforeach
    </div></section>

    <section class="tp-ref-tags">
        @foreach (['tag_1'=>'tag_1_details','tag_2'=>'tag_2_details'] as $slot=>$field)
            @php $src=$slotSrc($slot); @endphp
            @continue ($tp->boxIsHidden($slot))
            <div class="tp-ref-tag-row"><div class="tp-ref-image tp-ref-image-tag {{ $imageEditable ? 'is-resizable' : '' }}" data-size-slot="{{ $slot }}" data-move-slot="{{ $slot }}" style="{{ $tp->imageSizeStyle($slot) }}{{ $tp->boxPositionStyle($slot) }}" title="{{ $imageEditable ? 'Drag the lower-right corner to resize' : '' }}">@if($imageEditable)<label for="tp_image_{{ $slot }}">@endif<img id="tp_preview_{{ $slot }}" src="{{ $src?:'' }}" alt="{{ $slot }}" class="{{ $src?'':'is-empty' }}">{!! $clearBtn($slot, $src) !!}{!! $lineBtn($slot) !!}<span class="tp-ref-placeholder" @if($src) hidden @endif><strong>{{ strtoupper(str_replace('_',' ',$slot)) }}</strong></span>@if($imageEditable)<input id="tp_image_{{ $slot }}" type="file" name="tech_pack_images[{{ $slot }}]" accept=".jpg,.jpeg,.png,.webp" class="tp-image-input" data-preview="tp_preview_{{ $slot }}"></label>@endif</div>{{-- No grip here: the tag's line sits right beside its picture, and a pair of
     dots between the two read as a stray mark on the sheet. It moves with the
     tag picture instead. --}}
                @unless ($tp->boxIsHidden('text_'.$slot))
                <div class="tp-ref-tag-text" data-move-slot="text_{{ $slot }}" style="{{ $tp->boxPositionStyle('text_'.$slot) }}">@if($imageEditable)<span class="tp-ref-grip no-print" title="Drag textbox to move">&#8942;&#8942;</span>@endif{!! $clearBtn('text_'.$slot) !!}@if($textEditable)<textarea class="tp-in tp-ref-note-in" name="{{ $field }}" maxlength="120" rows="1" wrap="off" placeholder="Type tag details">{{ $tp->$field }}</textarea>@else{{ $tp->$field ?: '—' }}@endif</div>
                @endunless
            </div>
        @endforeach
    </section>

    @foreach ($tp->extraNotes() as $i => $note)
        @php $slot = 'note_'.$i; @endphp
        {{-- Written tight against the tags on purpose. The block keeps the line
             breaks somebody typed (white-space: pre-line), which means it also
             keeps the ones in this file — a newline after the opening tag
             printed as a blank line above every note. --}}
        <div class="tp-ref-extra-note" data-move-slot="{{ $slot }}"
             style="{{ $tp->boxPositionStyle($slot) }}">@if ($imageEditable)<span class="tp-ref-grip no-print" title="Drag to move">&#8942;&#8942;</span><textarea class="tp-in tp-ref-note-in" name="extra_notes[{{ $i }}]" maxlength="200" rows="1" wrap="off" placeholder="Write the note">{{ $note }}</textarea><button type="button" class="tp-ref-clear" name="remove_note" value="{{ $i }}" title="Remove this note" aria-label="Remove this note">&times;</button>@else{{ $note }}@endif</div>
    @endforeach

    <div class="tp-ref-banner" data-move-slot="text_banner" style="{{ $tp->boxPositionStyle('text_banner') }}">@if($imageEditable)<span class="tp-ref-grip no-print" title="Drag to move">&#8942;&#8942;</span>@endif @if($textEditable)<input type="text" name="placing_title" maxlength="160" value="{{ $tp->placing_title }}" placeholder="Placing note (optional) — e.g. {{ $defaultBanner }}">@else{{ $banner }}@endif</div>
    @php $fileLocationImage=$slotSrc('file_location_image'); @endphp
    <section class="tp-ref-file-notes">
        <div class="tp-ref-light-title">File location</div>
        <div class="tp-ref-file-content">
            @unless ($tp->boxIsHidden('file_location_image'))
            <div class="tp-ref-image tp-ref-file-image">
                @if($imageEditable)<label for="tp_image_file_location_image">@endif
                <img id="tp_preview_file_location_image" src="{{ $fileLocationImage?:'' }}" alt="File location image" class="{{ $fileLocationImage?'':'is-empty' }}">{!! $clearBtn('file_location_image', $fileLocationImage) !!}
                <span class="tp-ref-placeholder" @if($fileLocationImage) hidden @endif><strong>File location image</strong><small>{{ $imageEditable?'Click or drop an image':'No image yet' }}</small></span>
                @if($imageEditable)<input id="tp_image_file_location_image" type="file" name="tech_pack_images[file_location_image]" accept=".jpg,.jpeg,.png,.webp" class="tp-image-input" data-preview="tp_preview_file_location_image"></label>@endif
            </div>
            @endunless
            @php
                // The path starts at the machine the artist is sitting at. Same
                // address the value is packed against when it saves, so the two
                // agree and the path keeps following them between PCs.
                // Where the request is actually coming from wins: that address
                // is a fact about right now. Only when it tells us nothing —
                // over the tunnel, or from the server's own browser, both of
                // which arrive as 127.0.0.1 — do we fall back to this machine,
                // which is where the shared drive lives. The artist's last
                // login address is the last resort: it is the one that goes
                // stale, and it is what was offering a PC nobody was sitting at.
                $ip = \App\Services\ServerIp::isPrivate((string) request()->ip())
                    ? request()->ip()
                    : (\App\Services\ServerIp::current() ?: \App\Services\ServerIp::ipForUser(auth()->user()));
                $ipPrefix = ($ip && \App\Services\ServerIp::isPrivate($ip)) ? '\\\\'.$ip.'\\' : '';
            @endphp
            @php
                // The address gets its own box, apart from the folder and file
                // name. It cannot be emptied — a path with no machine on the
                // front is a path nothing on the floor can open — but it CAN be
                // corrected, because the detection is only a good guess: reached
                // over the tunnel every request looks like 127.0.0.1, so the
                // server offers its own address rather than the artist's PC.
                //
                // What was saved wins over the guess: that address was typed by
                // somebody who could see the machine.
                $savedPath = (string) $tp->file_location_notes;
                $savedParts = preg_match('/^\\\\\\\\([^\\\\]+)\\\\?(.*)$/', $savedPath, $m) ? $m : null;
                $pathTail = $savedParts[2] ?? $savedPath;

                // The MACHINE NAME leads. \\IC-SERVER\FOR PRINT keeps working
                // when the router hands that PC a different address; an IP path
                // stops the day DHCP moves. The address stays underneath as the
                // alternative, for a PC that cannot resolve the name.
                $deviceName = \App\Services\ServerIp::deviceName();
                $savedHost = $savedParts[1] ?? null;
                $savedHostIsIp = $savedHost && filter_var($savedHost, FILTER_VALIDATE_IP);
                $pathHost = ($savedHost && ! $savedHostIsIp) ? $savedHost : ($deviceName ?: '');
                $altIp = $savedHostIsIp ? $savedHost : $ip;
            @endphp
            @if($textEditable)
                @php
                    // The machines this path could point at: this PC by name,
                    // by address, and whatever was already saved if it was
                    // neither. A list rather than a box, because there is
                    // nothing to type — it is this machine or it is not.
                    $hostOptions = collect([$deviceName, $altIp, $pathHost])
                        ->filter()
                        ->unique(fn ($h) => mb_strtolower($h))
                        ->values();
                @endphp
                <div class="tp-ref-path-line">
                    <span class="tp-ref-path-slashes">\\</span>
                    <select class="tp-ref-path-host" name="file_location_host"
                            title="The PC these files are on" aria-label="Machine">
                        @foreach ($hostOptions as $option)
                            <option value="{{ $option }}" @selected($pathHost === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                    <span class="tp-ref-path-slashes">\</span>
                    <input class="tp-ref-file-path" type="text" name="file_location_tail" maxlength="200"
                           value="{{ $pathTail }}"
                           placeholder="FolderName">
                </div>
            @else
                <div class="tp-ref-note-value">{{ $savedPath }}</div>
            @endif

            @if($imageEditable)
                {{-- The two things artists get stuck on, shown rather than
                     explained. Nothing downloads until the guide is opened. --}}
                <div class="tp-ref-path-help no-print">
                    Add the <strong>folder</strong> after the machine — not a file.
                    Use back-slashes, and share the folder to
                    <strong>Everyone</strong>, not private.
                    <details class="path-help">
                        <summary>Show me how — sharing a folder and copying its path</summary>
                        <div class="path-help-videos">
                            @foreach ([
                                'Share your folder so others can open it' => 'folder sharing.mp4',
                                'Copy the file path of your file' => 'folder file copy.mp4',
                            ] as $caption => $file)
                                <figure>
                                    <figcaption>{{ $loop->iteration }}. {{ $caption }}</figcaption>
                                    <video controls preload="none" playsinline src="{{ asset(rawurlencode($file)) }}">
                                        Your browser can't play this video —
                                        <a href="{{ asset(rawurlencode($file)) }}">download it instead</a>.
                                    </video>
                                </figure>
                            @endforeach
                        </div>
                    </details>
                </div>
            @endif
        </div>
    </section>
    <div class="tp-ref-artist">Artist: <strong>{{ $val($artistName) }}</strong></div>
    <div class="tp-ref-footer-brand">Imprint Customs Tech Pack</div>
</div>

{{-- Outside the sheet, under the Imprint Customs badge.

     These build the pack; they are not part of it. Inside the sheet they took a
     strip out of the materials column and left a gap on the paper. Out here
     they read as the workbench rather than the document. --}}
@if ($imageEditable)
    <div class="tp-build no-print">
        <div class="tp-ref-add-bar">
            @if ($tp->nextSampleSlot())
                <button type="submit" name="add_image_box" value="1"
                        title="Add another picture box">+ Picture</button>
            @endif
            <button type="submit" name="add_note_box" value="1"
                    title="Add a text block you can drag anywhere">+ Text</button>
        </div>

        @if ($tp->hiddenBoxes())
            {{-- What this pack does without, and one click to have it back. A
                 box taken off by mistake would otherwise be gone for good. --}}
            <div class="tp-ref-restore">
                <span>Removed:</span>
                @foreach ($tp->hiddenBoxes() as $slot)
                    <button type="submit" name="restore_image_box" value="{{ $slot }}"
                            title="Put this box back">+ {{ ucfirst(str_replace('_', ' ', $slot)) }}</button>
                @endforeach
            </div>
        @endif
    </div>
@endif



{{-- The leader lines, drawn on every copy of the sheet.

     From the box to the point on the garment, in the sheet's own coordinates,
     redrawn whenever anything moves. It cannot be a line saved with the pack:
     the display sheet leaves out the empty boxes, so its layout is not the
     artist's, and a line drawn from saved coordinates lands nowhere near the
     box it belongs to. --}}
<script>
(function () {
    var sheet = document.querySelector('.tp-reference-sheet');
    var svg = sheet && sheet.querySelector('.tp-ref-lines');
    if (!sheet || !svg) { return; }

    function centre(el) {
        var box = sheet.getBoundingClientRect(), r = el.getBoundingClientRect();
        return { x: r.left + r.width / 2 - box.left, y: r.top + r.height / 2 - box.top };
    }

    function draw() {
        var box = sheet.getBoundingClientRect();
        svg.setAttribute('viewBox', '0 0 ' + box.width + ' ' + box.height);
        svg.innerHTML = '';

        sheet.querySelectorAll('.tp-ref-pin[data-pin-end="to"]').forEach(function (pin) {
            var slot = pin.dataset.pinSlot;
            var owner = sheet.querySelector('[data-size-slot="' + slot + '"]');

            // A line whose box is not on this copy has nothing to point from.
            if (owner && !owner.offsetParent) { return; }

            var to = centre(pin);
            var near = sheet.querySelector('.tp-ref-pin[data-pin-slot="' + slot + '"][data-pin-end="from"]');
            var start;

            if (near && near.offsetParent) {
                start = centre(near);
            } else if (owner) {
                // No near end saved: leave from whichever side of the box faces
                // the pin, so the line does not cut back over its own picture.
                var r = owner.getBoundingClientRect();
                start = {
                    x: (to.x > r.left + r.width / 2 - box.left) ? (r.right - box.left) : (r.left - box.left),
                    y: r.top + r.height / 2 - box.top,
                };
            } else {
                return;
            }

            var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', start.x); line.setAttribute('y1', start.y);
            line.setAttribute('x2', to.x);    line.setAttribute('y2', to.y);
            line.setAttribute('class', 'tp-ref-line');
            svg.appendChild(line);
        });
    }

    // Redrawn on the next frame, never on the spot: drawing writes into the
    // SVG, and anything watching the sheet for changes would see that write and
    // ask for another draw — for ever. Nothing watches the sheet. The lines are
    // redrawn when the page settles, when the sheet changes size, and when the
    // artist finishes moving something (the edit script calls tpDrawLines).
    var queued = false;

    function redraw() {
        if (queued) { return; }
        queued = true;
        requestAnimationFrame(function () { queued = false; draw(); });
    }

    window.tpDrawLines = redraw;
    draw();

    if (window.ResizeObserver) { new ResizeObserver(redraw).observe(sheet); }

    window.addEventListener('load', redraw);
    // Web fonts and pictures land after the first paint and move things.
    setTimeout(redraw, 250);
    setTimeout(redraw, 1000);
})();
</script>

@if($imageEditable)
<script>(function(){
/* A box dragged bigger has to still be bigger tomorrow. The browser's own
   resize handle changes nothing but the element, so the size is written into a
   hidden field beside it and saved with the pack — as a share of the sheet's
   width, because the pack scales with whatever page it is drawn on. */
function rememberSizes(form){
    document.querySelectorAll('.tp-ref-image[data-size-slot]').forEach(function(box){
        var slot=box.dataset.sizeSlot, sheet=box.closest('.tp-reference-sheet');
        if(!sheet||!box.style.width) return;
        var w=(box.offsetWidth/sheet.offsetWidth*100).toFixed(2),
            h=(box.offsetHeight/sheet.offsetWidth*100).toFixed(2);
        ['w','h'].forEach(function(k,i){
            var name='image_sizes['+slot+']['+k+']',
                field=form.querySelector('input[name="'+name+'"]');
            if(!field){field=document.createElement('input');field.type='hidden';field.name=name;form.appendChild(field);}
            field.value=i===0?w:h;
        });
    });
}
document.addEventListener('submit',function(e){ if(e.target.querySelector('.tp-reference-sheet')) rememberSizes(e.target); },true);
function dominantColors(image){
    const canvas=document.createElement('canvas'),size=96;canvas.width=size;canvas.height=size;
    const ctx=canvas.getContext('2d',{willReadFrequently:true});ctx.drawImage(image,0,0,size,size);
    const data=ctx.getImageData(0,0,size,size).data,bins=new Map();
    for(let i=0;i<data.length;i+=16){const r=data[i],g=data[i+1],b=data[i+2],a=data[i+3];if(a<180)continue;const max=Math.max(r,g,b),min=Math.min(r,g,b);if(max>242&&min>235)continue;const key=[r,g,b].map(v=>Math.min(255,Math.round(v/32)*32)).join(',');bins.set(key,(bins.get(key)||0)+1);}
    const ranked=[...bins.entries()].sort((a,b)=>b[1]-a[1]),strongest=ranked[0]?.[1]||0,picked=[];
    for(const [key,count] of ranked){if(count<strongest*.18)break;const rgb=key.split(',').map(Number);if(picked.every(c=>Math.hypot(c[0]-rgb[0],c[1]-rgb[1],c[2]-rgb[2])>70))picked.push(rgb);if(picked.length===3)break;}
    return picked.map(c=>'#'+c.map(v=>v.toString(16).padStart(2,'0')).join('').toUpperCase());
}
const namedColors={Black:'#111111',White:'#FFFFFF',Gray:'#9CA3AF',Red:'#D21F26',Orange:'#F15A24',Yellow:'#EAB308',Green:'#15803D',Blue:'#2563EB',Navy:'#1E2A53',Purple:'#7E22CE',Pink:'#EC4899',Brown:'#7C4A2D',Maroon:'#7F1D1D'};
function colorName(hex){const rgb=[hex.slice(1,3),hex.slice(3,5),hex.slice(5,7)].map(v=>parseInt(v,16));return Object.entries(namedColors).sort((a,b)=>distance(rgb,a[1])-distance(rgb,b[1]))[0][0];}
function distance(rgb,hex){const c=[hex.slice(1,3),hex.slice(3,5),hex.slice(5,7)].map(v=>parseInt(v,16));return Math.hypot(rgb[0]-c[0],rgb[1]-c[1],rgb[2]-c[2]);}
function swatchFor(value){if(/^#[0-9a-f]{6}$/i.test(value))return value;const match=Object.entries(namedColors).find(([name])=>name.toLowerCase()===value.trim().toLowerCase());return match?match[1]:'#D4D4D8';}
function updateSwatch(field){field.closest('.tp-ref-color')?.style.setProperty('--swatch',swatchFor(field.value));}
function fillColors(image){const fields=[1,2,3].map(n=>document.querySelector('[name="color_'+n+'"]'));if(fields.every(f=>!f||f.value.trim()))return;dominantColors(image).forEach((color,i)=>{if(fields[i]&&!fields[i].value.trim()){fields[i].value=colorName(color);fields[i].dispatchEvent(new Event('input',{bubbles:true}));}});}
function saveUpload(input){const form=input.form;if(!form||form.dataset.imageSaving==='1')return;form.dataset.imageSaving='1';const button=form.querySelector('button[type="submit"],button:not([type])');if(button){button.disabled=true;button.textContent='Saving image…';}setTimeout(()=>form.requestSubmit(),0);}
function preview(input){const file=input.files&&input.files[0],image=document.getElementById(input.dataset.preview);if(!file||!image)return;const url=URL.createObjectURL(file);image.onload=function(){if(input.name==='tech_pack_images[front_mockup]')fillColors(image);URL.revokeObjectURL(url);saveUpload(input);};image.src=url;image.classList.remove('is-empty');const p=image.parentElement.querySelector('.tp-ref-placeholder');if(p)p.hidden=true;}
/* The × sits inside the <label> that opens the file picker, so the label
   swallowed the click and offered to REPLACE the picture instead of removing
   the box — worst on a tag, where the button covers most of the label. Taken
   in hand here: stop the label seeing it, then post the removal ourselves. */
/* Drag a box where the garment wants it.

   The pack was a fixed layout, so a job that wanted the artwork beside the flat
   rather than under it had no way to say so. Each box keeps its place in the
   grid and is NUDGED from there, and the nudge is kept as a share of the
   sheet's width — so a pack nobody has dragged prints exactly as it always did,
   and a box that was moved stays where it was put when the sheet is drawn wider
   or narrower.

   The boxes are also upload labels, so a drag must not end in the file picker:
   nothing counts as a drag until the pointer has actually travelled, and the
   click that ends a real drag is swallowed. */
(function(){
    var sheet=document.querySelector('.tp-reference-sheet');
    if(!sheet||!window.PointerEvent)return;

    function form(){return sheet.closest('form');}
    function remember(slot,x,y){
        var f=form();if(!f)return;
        ['x','y'].forEach(function(axis,i){
            var name='box_positions['+slot+']['+axis+']',
                field=f.querySelector('input[name="'+name+'"]');
            if(!field){field=document.createElement('input');field.type='hidden';field.name=name;f.appendChild(field);}
            field.value=(i===0?x:y).toFixed(2);
        });
    }

    document.querySelectorAll('[data-move-slot]').forEach(function(box){
        var slot=box.dataset.moveSlot,startX=0,startY=0,baseX=0,baseY=0,moved=false,dragging=false;
        var nearPin=null,nearBaseX=0,nearBaseY=0,isText=false;

        box.classList.add('is-movable');

        box.addEventListener('pointerdown',function(e){
            if(e.target.closest('.tp-ref-clear,button'))return;
            // A text block is picked up by its grip; anywhere else in it is the
            // box you type into, and typing must not drag the sheet about.
            if(e.target.closest('input,select,textarea')&&!e.target.closest('.tp-ref-grip'))return;
            if(box.querySelector('.tp-ref-grip')&&!e.target.closest('.tp-ref-grip'))return;
            if(e.button!==0)return;

            /* The bottom-right corner belongs to the browser's own resize
               handle. Starting a move there meant the box slid away the moment
               you tried to make it bigger — the two gestures were fighting over
               the same eighteen pixels. */
            if(box.classList.contains('is-resizable')){
                var edge=box.getBoundingClientRect(),grip=18;
                if(e.clientX>edge.right-grip&&e.clientY>edge.bottom-grip)return;
            }
            /* A text block is pinned to the sheet, a picture box is nudged
               from where the sheet puts it. The block is a typing box here and
               a line of print on everybody else's copy — different size, so a
               nudge lands somewhere else there. A pinned point does not. */
            isText=/^(text_|note_)/.test(slot);

            if(isText){
                if(box.style.position!=='absolute'){
                    var here=box.getBoundingClientRect(),on=sheet.getBoundingClientRect(),unit=sheet.offsetWidth/100;
                    box.style.position='absolute';
                    box.style.margin='0';
                    box.style.left=((here.left-on.left)/unit).toFixed(2)+'cqw';
                    box.style.top=((here.top-on.top)/unit).toFixed(2)+'cqw';
                }
                baseX=parseFloat(box.style.left)||0;
                baseY=parseFloat(box.style.top)||0;
            }else{
                var at=box.style.transform.match(/translate\(([-\d.]+)cqw,\s*([-\d.]+)cqw\)/);
                baseX=at?parseFloat(at[1]):0;baseY=at?parseFloat(at[2]):0;
            }
            startX=e.clientX;startY=e.clientY;moved=false;dragging=true;

            /* A leader line starts at its own box, so when the box moves the
               line has to come with it — otherwise the artist drags a picture
               across the sheet and leaves its line pointing at where it used to
               be. The FAR end stays where it is: that end names a place on the
               garment, and the garment has not moved. */
            nearPin=document.querySelector('.tp-ref-pin[data-pin-slot="'+slot+'"][data-pin-end="from"]');
            nearBaseX=nearPin?parseFloat(nearPin.style.left):0;
            nearBaseY=nearPin?parseFloat(nearPin.style.top):0;

            box.setPointerCapture(e.pointerId);
        });

        box.addEventListener('pointermove',function(e){
            if(!dragging)return;
            var dx=e.clientX-startX,dy=e.clientY-startY;
            // A few pixels of travel is a click with a shaky hand, not a drag.
            if(!moved&&Math.abs(dx)<4&&Math.abs(dy)<4)return;
            moved=true;box.classList.add('is-moving');
            var per=sheet.offsetWidth/100;

            if(isText){
                box.style.left=(baseX+dx/per).toFixed(2)+'cqw';
                box.style.top=(baseY+dy/per).toFixed(2)+'cqw';
            }else{
                box.style.transform='translate('+(baseX+dx/per).toFixed(2)+'cqw,'+(baseY+dy/per).toFixed(2)+'cqw)';
            }

            if(nearPin){
                nearPin.style.left=(nearBaseX+dx/per).toFixed(2)+'cqw';
                nearPin.style.top=(nearBaseY+dy/per).toFixed(2)+'cqw';
                if(window.tpDrawLines)window.tpDrawLines();
            }
        });

        ['pointerup','pointercancel'].forEach(function(name){
            box.addEventListener(name,function(e){
                if(!dragging)return;
                dragging=false;box.classList.remove('is-moving');
                if(!moved)return;
                if(isText){
                    remember(slot,parseFloat(box.style.left)||0,parseFloat(box.style.top)||0);
                }else{
                    var at=box.style.transform.match(/translate\(([-\d.]+)cqw,\s*([-\d.]+)cqw\)/);
                    if(at)remember(slot,parseFloat(at[1]),parseFloat(at[2]));
                }

                // The line came along, so its new start is saved with the move.
                if(nearPin&&window.tpRememberPin){
                    window.tpRememberPin(slot,'from',parseFloat(nearPin.style.left),parseFloat(nearPin.style.top));
                }
                nearPin=null;
                // Swallow the click this drag would otherwise finish with, or
                // the label under it opens the file picker.
                box.addEventListener('click',function once(ev){ev.preventDefault();ev.stopPropagation();box.removeEventListener('click',once,true);},true);
            });
        });
    });
})();
/* A box is as big as what is written in it — as wide as its longest line, and
   as tall as it has lines. Enter starts a new line; it does not hand the pack
   in half-written. */
(function(){
    var sheet=document.querySelector('.tp-reference-sheet');
    var form=sheet&&sheet.closest('form');
    if(!form)return;
    form.addEventListener('keydown',function(e){
        if(e.key!=='Enter')return;
        if(e.target.tagName==='TEXTAREA')return;   // a new line, as it should be
        if(e.target.tagName==='BUTTON')return;     // the button they pressed
        e.preventDefault();
    });
})();
document.querySelectorAll('.tp-ref-note-in').forEach(function(area){
    var fit=function(){
        var lines=area.value.split('\n');
        var widest=lines.reduce(function(w,line){return Math.max(w,line.length);},0);
        area.cols=Math.max(widest,14);
        area.rows=1;
        area.style.height='auto';
        area.style.height=area.scrollHeight+'px';
    };
    area.addEventListener('input',fit);
    fit();
});
document.querySelectorAll('.tp-ref-tag-text .tp-in').forEach(function(input){
    var fit=function(){input.size=Math.max(input.value.length,14);};
    input.addEventListener('input',fit);
    fit();
});
/* The leader lines.

   Drawn from the edge of a detail box to its pin, in the sheet's own
   coordinates, and redrawn whenever anything moves — a line that remembers
   where the box WAS is worse than no line at all. */
(function(){
    var sheet=document.querySelector('.tp-reference-sheet');
    var svg=sheet&&sheet.querySelector('.tp-ref-lines');
    if(!sheet||!svg)return;

    function draw(){ if(window.tpDrawLines){window.tpDrawLines();} }

    function remember(slot,end,x,y){
        var form=sheet.closest('form');if(!form)return;
        var keys=end==='from'?['fx','fy']:['tx','ty'];
        keys.forEach(function(key,i){
            var name='callouts['+slot+']['+key+']',
                field=form.querySelector('input[name="'+name+'"]');
            if(!field){field=document.createElement('input');field.type='hidden';field.name=name;form.appendChild(field);}
            field.value=(i===0?x:y).toFixed(2);
        });
    }

    // Printed lines use the pins' cqw coordinates directly instead of an SVG
    // viewBox. Sync them first so printing immediately after a drag uses the
    // current positions even before the form has been saved.
    function syncStaticLines(){
        sheet.querySelectorAll('.tp-ref-static-line').forEach(function(line){
            var slot=line.dataset.staticLine;
            var from=sheet.querySelector('.tp-ref-pin[data-pin-slot="'+slot+'"][data-pin-end="from"]');
            var to=sheet.querySelector('.tp-ref-pin[data-pin-slot="'+slot+'"][data-pin-end="to"]');
            if(!from||!to)return;
            var fx=parseFloat(from.style.left),fy=parseFloat(from.style.top),
                tx=parseFloat(to.style.left),ty=parseFloat(to.style.top),
                dx=tx-fx,dy=ty-fy;
            line.style.left=fx+'cqw';line.style.top=fy+'cqw';
            line.style.width=Math.hypot(dx,dy)+'cqw';
            line.style.transform='rotate('+(Math.atan2(dy,dx)*180/Math.PI)+'deg)';
        });
    }

    function pinAt(slot,end,x,y){
        var pin=document.createElement('span');
        pin.className='tp-ref-pin is-pin-movable'+(end==='from'?' tp-ref-pin-from':'');
        pin.dataset.pinSlot=slot;pin.dataset.pinEnd=end;
        pin.style.left=x.toFixed(2)+'cqw';pin.style.top=y.toFixed(2)+'cqw';
        pin.title=end==='from'?'Drag where the line starts':'Drag onto the garment';
        sheet.appendChild(pin);hold(pin);
        remember(slot,end,x,y);
        return pin;
    }

    // Starting one: the pin lands over the middle of the mockup, where it is
    // visible, and is dragged to the exact spot from there.
    document.querySelectorAll('.tp-ref-line-btn').forEach(function(button){
        button.addEventListener('click',function(e){
            e.preventDefault();e.stopPropagation();
            var slot=button.dataset.lineFor;

            // Pressing it again takes the line away: one button, on and off.
            if(sheet.querySelector('.tp-ref-pin[data-pin-slot="'+slot+'"]')){
                var form=sheet.closest('form');
                if(form){
                    var drop=document.createElement('input');
                    drop.type='hidden';drop.name='remove_callout';drop.value=slot;
                    form.appendChild(drop);
                    form.querySelectorAll('input[name^="callouts['+slot+']"]').forEach(function(f){f.remove();});
                }
                sheet.querySelectorAll('.tp-ref-pin[data-pin-slot="'+slot+'"]').forEach(function(p){p.remove();});
                draw();
                return;
            }

            var mockup=sheet.querySelector('.tp-ref-image-mockup'),box=sheet.getBoundingClientRect();
            var owner=sheet.querySelector('[data-size-slot="'+slot+'"]');
            var at=mockup?mockup.getBoundingClientRect():box;
            var per=box.width/100;

            // The near end starts on the edge of its own box, the far end over
            // the mockup. Both are dragged from there.
            if(owner){
                var o=owner.getBoundingClientRect();
                var nearX=(at.left>o.left?o.right:o.left)-box.left;
                pinAt(slot,'from',nearX/per,(o.top+o.height/2-box.top)/per);
            }

            // Not dead centre: that is where a hand goes to click the mockup,
            // and a pin sitting there swallows the click that uploads it.
            pinAt(slot,'to',(at.left+at.width*0.5-box.left)/per,(at.top+at.height*0.22-box.top)/per);
            draw();
        });
    });

    // Dragging one.
    function hold(pin){
        var startX=0,startY=0,baseX=0,baseY=0,dragging=false;

        pin.addEventListener('pointerdown',function(e){
            if(!pin.classList.contains('is-pin-movable'))return;
            if(e.button!==0)return;
            e.preventDefault();e.stopPropagation();
            baseX=parseFloat(pin.style.left)||0;baseY=parseFloat(pin.style.top)||0;
            startX=e.clientX;startY=e.clientY;dragging=true;
            pin.setPointerCapture(e.pointerId);
            pin.classList.add('is-moving');
        });

        pin.addEventListener('pointermove',function(e){
            if(!dragging)return;
            var per=sheet.getBoundingClientRect().width/100;
            pin.style.left=(baseX+(e.clientX-startX)/per).toFixed(2)+'cqw';
            pin.style.top=(baseY+(e.clientY-startY)/per).toFixed(2)+'cqw';
            draw();
        });

        ['pointerup','pointercancel'].forEach(function(name){
            pin.addEventListener(name,function(){
                if(!dragging)return;
                dragging=false;pin.classList.remove('is-moving');
                remember(pin.dataset.pinSlot,pin.dataset.pinEnd,parseFloat(pin.style.left),parseFloat(pin.style.top));
            });
        });

        // Double-click takes the line away again.
        pin.addEventListener('dblclick',function(e){
            if(!pin.classList.contains('is-pin-movable'))return;
            e.preventDefault();
            var slot=pin.dataset.pinSlot,form=sheet.closest('form');
            if(form){
                var drop=document.createElement('input');
                drop.type='hidden';drop.name='remove_callout';drop.value=slot;
                form.appendChild(drop);
                form.querySelectorAll('input[name^="callouts['+slot+']"]').forEach(function(f){f.remove();});
            }
            sheet.querySelectorAll('.tp-ref-pin[data-pin-slot="'+slot+'"]').forEach(function(p){p.remove();});
            draw();
        });
    }

    sheet.querySelectorAll('.tp-ref-pin').forEach(hold);
    draw();
    window.addEventListener('resize',draw);
    window.addEventListener('beforeprint',function(){syncStaticLines();draw();});
    window.addEventListener('afterprint',draw);
    /* No watcher here either. Drawing sets an attribute on the SVG, the SVG is
       inside the sheet, and a watcher on the sheet sees its own draw and asks
       for another — which is what pinned the page. The drawer redraws itself on
       resize and after every move; nothing needs to watch. */
})();
document.querySelectorAll('.tp-ref-clear').forEach(function(button){
    button.addEventListener('click',function(e){
        e.preventDefault();
        e.stopPropagation();
        var form=button.form||button.closest('form');
        if(!form)return;
        var field=document.createElement('input');
        field.type='hidden';field.name=button.name||'remove_image';field.value=button.value;
        form.appendChild(field);
        if(form.requestSubmit)form.requestSubmit();else form.submit();
    });
});
document.querySelectorAll('.tp-ref-color input').forEach(field=>field.addEventListener('input',()=>updateSwatch(field)));
document.querySelectorAll('.tp-image-input').forEach(function(input){input.addEventListener('change',()=>preview(input));const box=input.closest('.tp-ref-image');if(!box)return;box.classList.add('is-uploadable');box.addEventListener('click',function(e){if(e.target===input||e.target.closest('.tp-ref-clear,.tp-ref-line-btn,.tp-ref-grip,button,input,textarea,select'))return;const edge=box.getBoundingClientRect();if(box.classList.contains('is-resizable')&&e.clientX>edge.right-22&&e.clientY>edge.bottom-22)return;input.click();});if(!window.DataTransfer)return;box.addEventListener('dragover',e=>{e.preventDefault();box.classList.add('is-dragover')});box.addEventListener('dragleave',()=>box.classList.remove('is-dragover'));box.addEventListener('drop',function(e){e.preventDefault();box.classList.remove('is-dragover');const file=e.dataTransfer?.files?.[0];if(!file||!/^image\//.test(file.type))return;const transfer=new DataTransfer();transfer.items.add(file);input.files=transfer.files;preview(input);});});})();</script>
@endif

@unless ($imageEditable)
{{-- Click a picture to see it big.

     Everybody downstream of the artist reads this on a screen at a station,
     where a garment flat is a couple of centimetres across and the print size
     written on it is unreadable. Not on the artist's copy: there a click opens
     the file picker, which is what they want it to do. --}}
<div id="tpZoom" class="tp-zoom no-print" hidden>
    <img id="tpZoomImg" src="" alt="">
    <button type="button" class="tp-zoom-close" aria-label="Close">&times;</button>
</div>

<script>
(function () {
    var box = document.getElementById('tpZoom');
    var shown = document.getElementById('tpZoomImg');
    if (!box) { return; }

    function open(src, alt) {
        shown.src = src;
        shown.alt = alt || '';
        box.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function close() {
        box.hidden = true;
        shown.src = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
        var picture = e.target.closest('.tp-ref-image img, .tp-ref-file-image img');

        if (picture && picture.getAttribute('src') && !picture.classList.contains('is-empty')) {
            open(picture.currentSrc || picture.src, picture.alt);
            return;
        }

        if (e.target === box || e.target.closest('.tp-zoom-close')) { close(); }
    });

    document.addEventListener('keydown', function (e) {
        if (!box.hidden && e.key === 'Escape') { close(); }
    });
})();
</script>
@endunless

{{-- The read-only copies used to have a second, smaller renderer of their
     own here. Two renderers drawing into one canvas is one renderer too many:
     they took turns clearing each other's work, and the display copy ended up
     with no lines at all. The drawer above serves every copy. --}}
<script>
/* Print only after every saved picture has finished decoding. A protected,
   full-resolution mockup can still be loading when the user presses Print;
   Chromium snapshots the page immediately and otherwise leaves that box
   blank for the whole preview. */
window.printTechPack = async function () {
    var sheet = document.querySelector('.tp-reference-sheet');
    var pictures = sheet ? Array.from(sheet.querySelectorAll('img:not(.is-empty)')) : [];

    await Promise.all(pictures.map(function (picture) {
        if (picture.complete && picture.naturalWidth > 0) {
            return picture.decode ? picture.decode().catch(function () {}) : Promise.resolve();
        }

        return new Promise(function (resolve) {
            picture.addEventListener('load', resolve, { once: true });
            picture.addEventListener('error', resolve, { once: true });
        });
    }));

    requestAnimationFrame(function () {
        requestAnimationFrame(function () { window.print(); });
    });
};
</script>
