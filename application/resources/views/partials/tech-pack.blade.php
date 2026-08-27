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
    // Same box on every copy — the read-only one just cannot be typed in.
    //
    // It used to print the value as bare text where the artist had a box, so
    // the two sheets were different shapes: a box is taller and wider than the
    // words in it. Everything the artist positioned against THEIR shape — a
    // note dragged beside a picture, the end of a leader line — landed
    // somewhere else on the sheet the floor reads. No name on the read-only
    // one: there is no form under it to post to.
    /* The account officer's rows: the client's order, written down.

       Everybody READS the whole sheet. Only the officer types in these, and
       only the artist types in the rest — the placements, the sizes the print
       comes out at, the tag captions, the file path, their own name. Each half
       is the answer of somebody who was there; the other one is guessing. */
    $officerRows = [
        'design_name', 'fitting', 'item_style', 'quality', 'print_tech',
        'tshirt_color', 'print_label', 'thread_color', 'stitch_thread',
        'cutting_method', 'size_range', 'zipper_type', 'lip_pocket_color',
        // Rows the job order record still carries.
        'fabric', 'neck', 'cuff_arm_sleeves', 'neck_label', 'bottom_hem',
        'packaging', 'free_logo_sticker', 'print_type',
    ];

    $canType = function (string $field) use ($textEditable, $mode, $officerRows) {
        if (! $textEditable) {
            return false;
        }

        $theirs = in_array($field, $officerRows, true);

        return $mode === 'officer' ? $theirs : ! $theirs;
    };

    $fill = function (string $field, string $placeholder = '', int $max = 120, $on = null) use ($tp, $canType) {
        $model = $on ?? $tp; $value = (string) ($model?->$field ?? '');

        if (! $canType($field)) {
            return '<input class="tp-in is-printed" type="text" value="'.e($value).'" readonly tabindex="-1">';
        }

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

    // The × empties a box first and takes it away on the second press, so it
    // has to say which of the two this press will do. Called "Remove this box"
    // on both, it promised to take the panel off the sheet and then merely
    // rubbed the picture out — the artist pressed again to make it work, and
    // lost the box they wanted.
    $slotTextField = fn (string $slot) => match ($slot) {
        'text_tag_1' => 'tag_1_details',
        'text_tag_2' => 'tag_2_details',
        'text_banner' => 'placing_title',
        default => null,
    };
    $clearBtn = function (string $slot, $src = null) use ($imageEditable, $slotTextField, $tp) {
        if (! $imageEditable) {
            return '';
        }

        $field = $slotTextField($slot);

        $title = match (true) {
            filled($src) => 'Clear this picture',
            $field !== null && filled($tp->$field) => 'Clear this text',
            default => 'Remove this box',
        };

        return '<button type="button" class="tp-ref-clear" name="remove_image" value="'.$slot.'"'
            .' title="'.$title.'" aria-label="'.$title.'">&times;</button>';
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
                  style="--pin-x:{{ $line['from']['x'] }}; --pin-y:{{ $line['from']['y'] }}; width:{{ $lineLength }}cqw; transform:rotate({{ $lineAngle }}deg);"></span>
            <span class="tp-ref-pin tp-ref-pin-from"
                  data-pin-slot="{{ $slot }}" data-pin-end="from"
                  style="--pin-x:{{ $line['from']['x'] }}; --pin-y:{{ $line['from']['y'] }};"
                  title="Line starts at the edge of its box"></span>
        @endif
        <span class="tp-ref-pin{{ $imageEditable ? ' is-pin-movable' : '' }}"
              data-pin-slot="{{ $slot }}" data-pin-end="to"
              @if(isset($line['mockup'])) data-mockup-x="{{ $line['mockup']['x'] }}" data-mockup-y="{{ $line['mockup']['y'] }}" @endif
              style="--pin-x:{{ $line['to']['x'] }}; --pin-y:{{ $line['to']['y'] }};"
              title="{{ $imageEditable ? 'Drag onto the garment' : '' }}"></span>
    @endforeach

    @php $mockupSrc=$slotSrc('front_mockup'); @endphp
    <section class="tp-ref-mockups">
        <div class="tp-ref-title">Approved mockup</div>
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
        </table>
    </header>

    @php $sampleBoxes = $tp->sampleBoxes(); @endphp
    <section class="tp-ref-flats"><div class="tp-ref-black-title">Sample</div><div class="tp-ref-flat-grid">
        @php
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

    <section class="tp-ref-materials"><div class="tp-ref-black-title">Size list and quantity</div>
        <div class="tp-ref-sizelist">
            <table class="tp-ref-table">
                <tr><th>Size</th><th class="tp-ref-qty-head">Quantity</th></tr>
                @forelse ($order->itemsInSizeOrder() as $item)
                    <tr><td>{{ $item->size ?: 'One size' }}</td><td>{{ $item->quantity }}</td></tr>
                @empty
                    <tr><td>&mdash;</td><td>&mdash;</td></tr>
                @endforelse
                <tr class="tp-ref-size-total"><td>Total</td><td>{{ number_format($order->quantity) }}</td></tr>
            </table>
        </div><div class="tp-ref-black-title">Materials and components</div>
        <table class="tp-ref-table">
        <tr><th>Neck type</th><td>{!! $textEditable?$fill('neck','Round neck / 1 x 1 ribbings',100,$jo):e($val(trim(($jo?->neck??'').($jo?->neck_size?' / '.$jo->neck_size:'')))) !!}</td></tr>
        <tr><th>Cuff / arm slv</th><td>{!! $fill('cuff_arm_sleeves','Tupi',100,$jo) !!}</td></tr>
        {{-- Four rows where there were two dropdowns. Each of those changed
             what its row was CALLED — print label or neck label, t-shirt colour
             or thread colour — so a garment that wants both could only say one,
             and the sheet could not tell the floor that a black shirt is sewn
             with white thread. --}}
        <tr><th>Print label</th><td>{!! $fill('print_label','IC DTF - original fit',120) !!}</td></tr>
        <tr><th>Neck label</th><td>{!! $fill('neck_label','IC DTF - original fit',120,$jo) !!}</td></tr>
        <tr><th>T-shirt color</th><td>{!! $fill('tshirt_color','Black',60) !!}</td></tr>
        <tr><th>Thread color</th><td>{!! $fill('thread_color','White',60) !!}</td></tr>
        <tr><th>Stitch thread</th><td>{!! $fill('stitch_thread','N/A',60) !!}</td></tr>
        <tr><th>Cutting method</th><td>{!! $fill('cutting_method','Straight cut',60) !!}</td></tr><tr><th>Packaging</th><td>{!! $fill('packaging','Polybag',120,$jo) !!}</td></tr>
        <tr><th>Zipper type</th><td>{!! $fill('zipper_type','e.g. Metal, nylon, none',60) !!}</td></tr>
        <tr><th>Bottom hem</th><td>{!! $fill('bottom_hem','e.g. Straight',100,$jo) !!}</td></tr>
        <tr><th>Lip pocket color</th><td>{!! $fill('lip_pocket_color','Pocket color',60) !!}</td></tr>
        <tr><th>Size range</th><td>{!! $fill('size_range','M-2XL',60) !!}</td></tr><tr class="tp-ref-sticker"><th>Sticker / extra</th><td>{!! $fill('free_logo_sticker','IC sticker',120,$jo) !!}</td></tr>

        {{-- Raw materials are not here.

             The list raises the request the materials desk answers, and what a
             job is cut from is production's business — the sheet the floor
             works the garment to says what the garment IS, not what stock it
             came off. It was enterable here for the officer and hidden from
             everybody else, which made two homes for one answer. Production
             details is the home: it has the amounts, the past values to pick
             from, and a way to take a row out. --}}

            {{-- What was ordered, read like the size chart it came from: the
                 sizes along the top, the count for each one directly under it.
                 Off the order's own lines rather than retyped, so the sheet
                 cannot disagree with the order it was made from. --}}
            @php $sizeLines = $order->itemsInSizeOrder(); @endphp

    </table>


    </section>

    <section class="tp-ref-artwork"><div class="tp-ref-two-images">
        @foreach (['back_artwork'=>'Back artwork','front_artwork'=>'Front artwork'] as $slot=>$label)
            @php $src=$slotSrc($slot); $back=str_starts_with($slot,'back'); @endphp
            @continue ($tp->boxIsHidden($slot))
            <div class="tp-ref-art-column"><div class="tp-ref-image tp-ref-image-artwork {{ $imageEditable ? 'is-resizable' : '' }}" data-size-slot="{{ $slot }}" data-move-slot="{{ $slot }}" style="{{ $tp->imageSizeStyle($slot) }}{{ $tp->boxPositionStyle($slot) }}" title="{{ $imageEditable ? 'Drag the lower-right corner to resize' : '' }}">@if($imageEditable)<label for="tp_image_{{ $slot }}">@endif<img id="tp_preview_{{ $slot }}" src="{{ $src?:'' }}" alt="{{ $label }}" class="{{ $src?'':'is-empty' }}">{!! $clearBtn($slot, $src) !!}{!! $lineBtn($slot) !!}<span class="tp-ref-placeholder" @if($src) hidden @endif><strong>{{ $label }}</strong><small>{{ $imageEditable?'Click to upload; drag corner to resize':'No image yet' }}</small></span>@if($imageEditable)<input id="tp_image_{{ $slot }}" type="file" name="tech_pack_images[{{ $slot }}]" accept=".jpg,.jpeg,.png,.webp" class="tp-image-input" data-preview="tp_preview_{{ $slot }}"></label>@endif</div></div>
        @endforeach
    </div>
        <div class="tp-ref-tags">
        @foreach (['tag_1'=>'tag_1_details','tag_2'=>'tag_2_details'] as $slot=>$field)
            @php $src=$slotSrc($slot); @endphp
            @continue ($tp->boxIsHidden($slot))
            <div class="tp-ref-tag-row"><div class="tp-ref-image tp-ref-image-tag {{ $imageEditable ? 'is-resizable' : '' }}" data-size-slot="{{ $slot }}" data-move-slot="{{ $slot }}" style="{{ $tp->imageSizeStyle($slot) }}{{ $tp->boxPositionStyle($slot) }}" title="{{ $imageEditable ? 'Drag the lower-right corner to resize' : '' }}">@if($imageEditable)<label for="tp_image_{{ $slot }}">@endif<img id="tp_preview_{{ $slot }}" src="{{ $src?:'' }}" alt="{{ $slot }}" class="{{ $src?'':'is-empty' }}">{!! $clearBtn($slot, $src) !!}{!! $lineBtn($slot) !!}<span class="tp-ref-placeholder" @if($src) hidden @endif><strong>{{ strtoupper(str_replace('_',' ',$slot)) }}</strong></span>@if($imageEditable)<input id="tp_image_{{ $slot }}" type="file" name="tech_pack_images[{{ $slot }}]" accept=".jpg,.jpeg,.png,.webp" class="tp-image-input" data-preview="tp_preview_{{ $slot }}"></label>@endif</div>{{-- No grip here: the tag's line sits right beside its picture, and a pair of
     dots between the two read as a stray mark on the sheet. It moves with the
     tag picture instead. --}}
                @unless ($tp->boxIsHidden('text_'.$slot))
                <div class="tp-ref-tag-text" data-move-slot="text_{{ $slot }}" style="{{ $tp->boxPositionStyle('text_'.$slot) }}">@if($imageEditable)<span class="tp-ref-grip no-print" title="Drag textbox to move">&#8942;&#8942;</span>@endif{!! $clearBtn('text_'.$slot) !!}@if($canType($field))<textarea class="tp-in tp-ref-note-in" name="{{ $field }}" maxlength="120" rows="1" wrap="off" placeholder="Type tag details">{{ $tp->$field }}</textarea>@else<textarea class="tp-in tp-ref-note-in is-printed" rows="1" wrap="off" readonly tabindex="-1">{{ $tp->$field }}</textarea>@endif</div>
                @endunless
            </div>
        @endforeach
    </div>
    </section>



    <div class="tp-ref-banner" data-move-slot="text_banner" style="{{ $tp->boxPositionStyle('text_banner') }}">@if($imageEditable)<span class="tp-ref-grip no-print" title="Drag to move">&#8942;&#8942;</span>@endif @if($canType('placing_title'))<input type="text" name="placing_title" maxlength="160" value="{{ $tp->placing_title }}" placeholder="Placing note (optional) — e.g. {{ $defaultBanner }}">@else{{ $banner }}@endif</div>
    @php $fileLocationImage=$slotSrc('file_location_image'); @endphp
    <section class="tp-ref-file-notes">
        <div class="tp-ref-light-title">File location</div>
        {{-- A new note opens this panel, above the path.

             It used to be a child of the sheet's own grid with no row of its
             own, so the browser placed it after the last band — which put it
             through the File location text rather than anywhere anybody chose.
             Given a home at the top of the panel it lands somewhere the artist
             expects, and the grip still drags it onto the garment. --}}
        @foreach ($tp->extraNotes() as $i => $note)
            @php $slot = 'note_'.$i; @endphp
            {{-- The block keeps the line breaks somebody typed (white-space:
                 pre-line), which means it also keeps the ones in this file — a
                 newline after the opening tag printed as a blank line above
                 every note. --}}
            <div class="tp-ref-extra-note" data-move-slot="{{ $slot }}"
                 style="{{ $tp->boxPositionStyle($slot) }}">@if ($imageEditable)<span class="tp-ref-grip no-print" title="Drag to move">&#8942;&#8942;</span><textarea class="tp-in tp-ref-note-in" name="extra_notes[{{ $i }}]" maxlength="200" rows="1" wrap="off" placeholder="Write the note">{{ $note }}</textarea><button type="button" class="tp-ref-clear" name="remove_note" value="{{ $i }}" title="Remove this note" aria-label="Remove this note">&times;</button>@else<textarea class="tp-in tp-ref-note-in is-printed" rows="1" wrap="off" readonly tabindex="-1">{{ $note }}</textarea>@endif</div>
        @endforeach
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
             {{-- The artist's path, so only the artist types it. An officer
                  editing the sheet was able to overwrite where the files
                  actually are, from a desk that cannot see that machine. --}}
            @if($imageEditable)
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



{{-- A box is as big as what is written in it — on every copy of the sheet.

     This has to run for the floor's copy too, not just the artist's. The two
     sheets carry the same boxes so they stay the same shape; a box left at the
     browser's default width on one of them cuts the note off halfway and puts
     everything positioned against it out of place. --}}
<script>
(function () {
    function fitArea(area) {
        var lines = area.value.split('\n');
        var widest = lines.reduce(function (w, line) { return Math.max(w, line.length); }, 0);

        area.cols = Math.max(widest, 14);
        area.rows = 1;
        area.style.height = 'auto';
        area.style.height = area.scrollHeight + 'px';
    }

    function fitInput(input) {
        input.size = Math.max(input.value.length, 14);
    }

    document.querySelectorAll('.tp-ref-note-in').forEach(function (area) {
        area.addEventListener('input', function () { fitArea(area); });
        fitArea(area);
    });

    document.querySelectorAll('.tp-ref-tag-text input.tp-in').forEach(function (input) {
        input.addEventListener('input', function () { fitInput(input); });
        fitInput(input);
    });
})();
</script>

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

    function clamp(value, low, high) {
        return Math.max(low, Math.min(high, value));
    }

    /* Meet the box at its EDGE, in the direction of the garment pin. The old
       saved start was a point on the whole sheet; after a resize or a layout
       change it stayed behind and the red line appeared to start in mid-air. */
    function edgeOf(owner, target, sheetBox) {
        var r = owner.getBoundingClientRect();
        var cx = r.left + r.width / 2 - sheetBox.left;
        var cy = r.top + r.height / 2 - sheetBox.top;
        var dx = target.x - cx;
        var dy = target.y - cy;

        if (!dx && !dy) { return { x: cx, y: cy }; }

        var tx = dx ? (r.width / 2) / Math.abs(dx) : Infinity;
        var ty = dy ? (r.height / 2) / Math.abs(dy) : Infinity;
        var scale = Math.min(tx, ty);

        return { x: cx + dx * scale, y: cy + dy * scale };
    }

    /* A pin's place is kept as two plain numbers, each a share of the sheet's
       WIDTH — the same pair that is saved. They are handed over as custom
       properties rather than as left/top so the stylesheet decides what they
       mean: across, that share of the width; down, the same share of the width
       but never past the bottom of the sheet. On paper the sheet is a very
       different shape from the screen, and a mark that reads as mid-sheet here
       is off the end of the page there, which is what was printing blank
       pages. */
    function setPin(el, x, y) {
        el.style.setProperty('--pin-x', x.toFixed(2));
        el.style.setProperty('--pin-y', y.toFixed(2));
    }

    function readPin(el, axis) {
        return parseFloat(el.style.getPropertyValue('--pin-' + axis)) || 0;
    }

    /* A value set small enough to be read whole.

       These boxes are one line and take a hundred characters, so a long answer
       ran past the edge and the rest of it was simply gone. Shrinking is the
       trade the shop asked for: the row keeps its height, the words keep their
       place, and the type gets smaller until it fits — but only down to the
       floor below, because an answer nobody can read is no better than one
       nobody can see. */
    var FIT_FLOOR = 0.62, FIT_STEP = 0.04;

    function fitValue(el) {
        el.style.fontSize = '';

        // Nothing to do until it is actually laid out (a hidden copy, a box
        // with no width yet) — measuring then only produces a wrong answer.
        if (! el.offsetWidth) { return; }

        var size = 1;

        while (el.scrollWidth > el.clientWidth + 1 && size > FIT_FLOOR) {
            size -= FIT_STEP;
            el.style.fontSize = size.toFixed(2) + 'em';
        }
    }

    function fitValues() {
        sheet.querySelectorAll('input.tp-in').forEach(fitValue);
    }

    function draw() {
        fitValues();

        var box = sheet.getBoundingClientRect();
        svg.setAttribute('viewBox', '0 0 ' + box.width + ' ' + box.height);
        svg.innerHTML = '';

        var mockup = sheet.querySelector('.tp-ref-image-mockup');
        var mockupBox = mockup && mockup.getBoundingClientRect();

        sheet.querySelectorAll('.tp-ref-pin[data-pin-end="to"]').forEach(function (pin) {
            var slot = pin.dataset.pinSlot;
            var owner = sheet.querySelector('[data-size-slot="' + slot + '"]');

            // BOTH ends of the line, together. A callout draws two dots — the
            // one on the garment and the one at the edge of its box — and they
            // have to appear and disappear as a pair. Hiding only the garment
            // end left the other sitting at the position it was saved at,
            // which is how a red circle came to be printed in the middle of the
            // sheet and another over the artist's name, attached to nothing.
            var near = sheet.querySelector('.tp-ref-pin[data-pin-slot="' + slot + '"][data-pin-end="from"]');

            var hideLine = function () {
                pin.style.display = 'none';
                if (near) { near.style.display = 'none'; }
            };

            // They come back every redraw: a box that was taken away can be put
            // back, and its line with it.
            pin.style.display = '';
            if (near) { near.style.display = ''; }

            // A line whose box is not on this copy has nothing to point from.
            if (owner && !owner.offsetParent) { hideLine(); return; }

            var to = centre(pin);

            // A leader line describes a place on the approved garment. Older
            // saved points were allowed outside the page, which is why a line
            // could continue below the Tech Pack. Bring those points back into
            // the mockup and keep future redraws there too.
            if (mockupBox) {
                if (pin.dataset.mockupX !== undefined && pin.dataset.mockupY !== undefined) {
                    // One semantic point on the garment, reconstructed inside
                    // whichever copy of the Tech Pack is being viewed.
                    to.x = mockupBox.left - box.left + mockupBox.width * clamp(parseFloat(pin.dataset.mockupX) || 0, 0, 100) / 100;
                    to.y = mockupBox.top - box.top + mockupBox.height * clamp(parseFloat(pin.dataset.mockupY) || 0, 0, 100) / 100;
                } else {
                    // Legacy whole-sheet point: make it safe now and remember
                    // its mockup-relative equivalent when the Artist next saves.
                    to.x = clamp(to.x, mockupBox.left - box.left + 4, mockupBox.right - box.left - 4);
                    to.y = clamp(to.y, mockupBox.top - box.top + 4, mockupBox.bottom - box.top - 4);
                    pin.dataset.mockupX = ((to.x - (mockupBox.left - box.left)) / mockupBox.width * 100).toFixed(2);
                    pin.dataset.mockupY = ((to.y - (mockupBox.top - box.top)) / mockupBox.height * 100).toFixed(2);
                }
                setPin(pin, to.x / box.width * 100, to.y / box.width * 100);
            }

            var start;

            if (owner) {
                start = edgeOf(owner, to, box);

                // Keep the visible source dot attached to that edge too. It is
                // a marker now, not a second draggable point that can drift.
                if (near) {
                    setPin(near, start.x / box.width * 100, start.y / box.width * 100);
                }
            } else if (near && near.offsetParent) {
                start = centre(near);
            } else {
                // No box and no near end: nothing to draw from, so neither dot
                // belongs on the sheet.
                hideLine();

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

    // Shared with the editing script below, which lives in its own <script>
    // and cannot see anything declared in here.
    // Typing a long answer shrinks it as it goes, rather than at the next
    // redraw — otherwise the end of the word you are writing is invisible.
    sheet.addEventListener('input', function (e) {
        if (e.target.matches('input.tp-in')) { fitValue(e.target); }
    });

    window.tpSetPin = setPin;
    window.tpReadPin = readPin;

    window.tpDrawLines = redraw;
    draw();

    if (window.ResizeObserver) { new ResizeObserver(redraw).observe(sheet); }

    window.addEventListener('load', redraw);
    // Web fonts and pictures land after the first paint and move things.
    setTimeout(redraw, 250);
    setTimeout(redraw, 1000);

    /* Printing is a different layout, and it has to be drawn on the spot.

       The print sheet is not the screen sheet scaled down: the mockup is a
       fixed 142mm, the tables are padded in millimetres, the bands fall in
       different places. The lines live in an SVG that stretches with the sheet
       while the dots are placed in cqw off the old measurements, so the two
       came apart — a circle sitting over the artist's name with its line
       running somewhere else.

       A redraw was queued, but through requestAnimationFrame: the browser
       takes its picture of the page without ever running that frame, so the
       print copy went out with the screen's numbers. These draw straight away
       instead, once the print styles are already applying. */
    window.addEventListener('beforeprint', draw);
    window.addEventListener('afterprint', draw);

    if (window.matchMedia) {
        var printing = window.matchMedia('print');
        var onPrintMedia = function (e) { if (e.matches) { draw(); } };

        // Safari and older Chrome only have the deprecated form.
        if (printing.addEventListener) {
            printing.addEventListener('change', onPrintMedia);
        } else if (printing.addListener) {
            printing.addListener(onPrintMedia);
        }
    }
})();
</script>

@if($imageEditable)
<script>
var setPin = window.tpSetPin || function (el, x, y) {
    el.style.setProperty('--pin-x', (+x).toFixed(2));
    el.style.setProperty('--pin-y', (+y).toFixed(2));
};
var readPin = window.tpReadPin || function (el, axis) {
    return parseFloat(el.style.getPropertyValue('--pin-' + axis)) || 0;
};
(function(){
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
function saveUpload(input){const form=input.form;if(!form||form.dataset.imageSaving==='1')return;form.dataset.imageSaving='1';const button=form.querySelector('button[type="submit"],button:not([type])');if(button){button.disabled=true;button.textContent='Saving image…';}setTimeout(()=>form.requestSubmit(),0);}
function preview(input){const file=input.files&&input.files[0],image=document.getElementById(input.dataset.preview);if(!file||!image)return;const url=URL.createObjectURL(file);image.onload=function(){URL.revokeObjectURL(url);saveUpload(input);};image.src=url;image.classList.remove('is-empty');const p=image.parentElement.querySelector('.tp-ref-placeholder');if(p)p.hidden=true;}
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
    function keep(f,slot,axis,value){
        var name='box_positions['+slot+']['+axis+']',
            field=f.querySelector('input[name="'+name+'"]');
        if(!field){field=document.createElement('input');field.type='hidden';field.name=name;f.appendChild(field);}
        field.value=value.toFixed(2);
    }
    /* Across the sheet as a share of its WIDTH, down it as a share of its
       HEIGHT. A text block is pinned to a point, and a point held by width
       alone only stays put while the sheet keeps one shape — the printed sheet
       is a different height for the same width, so the tag's text slid down
       into the File location panel on paper. The width figure is still written
       so an older reader of this pack finds what it expects. */
    function remember(slot,x,y,ox,oy){
        var f=form();if(!f)return;
        keep(f,slot,'x',x);
        keep(f,slot,'y',y);
        if(ox!==undefined){keep(f,slot,'ox',ox);keep(f,slot,'oy',oy);}
    }
    /* What a block has already been nudged by, in its own widths and heights. */
    function nudgeOf(box){
        var at=box.style.transform.match(/translate\(([-\d.]+)%,\s*([-\d.]+)%\)/);
        return at?{x:parseFloat(at[1]),y:parseFloat(at[2])}:{x:0,y:0};
    }

    document.querySelectorAll('[data-move-slot]').forEach(function(box){
        var slot=box.dataset.moveSlot;
        if(!/^(text_|note_)/.test(slot))return;
        if(box.style.position!=='absolute')return;
        if(box.style.top.slice(-1)==='%')return;

        var down=sheet.offsetHeight/100;
        if(!down)return;

        var was=parseFloat(box.style.top)||0,
            here=(box.getBoundingClientRect().top-sheet.getBoundingClientRect().top)/down;

        box.style.top=here.toFixed(2)+'%';
        remember(slot,parseFloat(box.style.left)||0,was,here);
    });

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
                /* It stays in its row and is nudged from there. Its own box is
                   the ruler: the same words in the same font are the same size
                   on every copy, so the nudge means the same thing on each. */
                var already=nudgeOf(box);
                baseX=already.x;baseY=already.y;
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
            nearBaseX=nearPin?readPin(nearPin,'x'):0;
            nearBaseY=nearPin?readPin(nearPin,'y'):0;

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
                var ownW=box.offsetWidth||1,ownH=box.offsetHeight||1;
                box.style.transform='translate('+(baseX+dx/ownW*100).toFixed(2)+'%,'
                    +(baseY+dy/ownH*100).toFixed(2)+'%)';
            }else{
                box.style.transform='translate('+(baseX+dx/per).toFixed(2)+'cqw,'+(baseY+dy/per).toFixed(2)+'cqw)';
            }

            if(nearPin){
                setPin(nearPin,nearBaseX+dx/per,nearBaseY+dy/per);
                if(window.tpDrawLines)window.tpDrawLines();
            }
        });

        ['pointerup','pointercancel'].forEach(function(name){
            box.addEventListener(name,function(e){
                if(!dragging)return;
                dragging=false;box.classList.remove('is-moving');
                if(!moved)return;
                if(isText){
                    /* Where it ended up, in its own widths and heights. The old
                       sheet-relative pair is still written so a reader that
                       only knows those finds something sane. */
                    /* NOT named `moved`: that is the drag flag this handler
                       has already tested, and `var` hoists to the top of the
                       function — shadowing it made every text drag bail out
                       before it could be saved. */
                    var nudge=nudgeOf(box),on=sheet.getBoundingClientRect(),
                        here=box.getBoundingClientRect(),unit=sheet.offsetWidth/100;
                    remember(
                        slot,
                        unit?((here.left-on.left)/unit):0,
                        unit?((here.top-on.top)/unit):0,
                        nudge.x,
                        nudge.y
                    );
                }else{
                    var at=box.style.transform.match(/translate\(([-\d.]+)cqw,\s*([-\d.]+)cqw\)/);
                    if(at)remember(slot,parseFloat(at[1]),parseFloat(at[2]));
                }

                // The line came along, so its new start is saved with the move.
                if(nearPin&&window.tpRememberPin){
                    window.tpRememberPin(slot,'from',readPin(nearPin,'x'),readPin(nearPin,'y'));
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
/* The boxes size themselves on every copy — see the script above the sheet.
   Left in here, the read-only copies never ran it: their boxes kept the
   browser's default width and cut the note off at "LOGO WITH". */
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

        if(end==='to'){
            var pin=sheet.querySelector('.tp-ref-pin[data-pin-slot="'+slot+'"][data-pin-end="to"]');
            var mockup=sheet.querySelector('.tp-ref-image-mockup');
            if(pin&&mockup){
                var p=pin.getBoundingClientRect(),m=mockup.getBoundingClientRect();
                var relative=[
                    Math.max(0,Math.min(100,(p.left+p.width/2-m.left)/m.width*100)),
                    Math.max(0,Math.min(100,(p.top+p.height/2-m.top)/m.height*100))
                ];
                ['mx','my'].forEach(function(key,i){
                    var name='callouts['+slot+']['+key+']';
                    var field=form.querySelector('input[name="'+name+'"]');
                    if(!field){field=document.createElement('input');field.type='hidden';field.name=name;form.appendChild(field);}
                    field.value=relative[i].toFixed(2);
                });
                pin.dataset.mockupX=relative[0].toFixed(2);
                pin.dataset.mockupY=relative[1].toFixed(2);
            }
        }
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
            var fx=readPin(from,'x'),fy=readPin(from,'y'),
                tx=readPin(to,'x'),ty=readPin(to,'y'),
                dx=tx-fx,dy=ty-fy;
            line.style.setProperty('--pin-x',fx);line.style.setProperty('--pin-y',fy);
            line.style.width=Math.hypot(dx,dy)+'cqw';
            line.style.transform='rotate('+(Math.atan2(dy,dx)*180/Math.PI)+'deg)';
        });
    }

    function pinAt(slot,end,x,y){
        var pin=document.createElement('span');
        pin.className='tp-ref-pin is-pin-movable'+(end==='from'?' tp-ref-pin-from':'');
        pin.dataset.pinSlot=slot;pin.dataset.pinEnd=end;
        setPin(pin,x,y);
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
            baseX=readPin(pin,'x');baseY=readPin(pin,'y');
            startX=e.clientX;startY=e.clientY;dragging=true;
            pin.setPointerCapture(e.pointerId);
            pin.classList.add('is-moving');
        });

        pin.addEventListener('pointermove',function(e){
            if(!dragging)return;
            var sheetBox=sheet.getBoundingClientRect(),per=sheetBox.width/100;
            var nextX=baseX+(e.clientX-startX)/per;
            var nextY=baseY+(e.clientY-startY)/per;

            // The far end belongs on the approved mockup, never outside the
            // page or over an unrelated detail box.
            if(pin.dataset.pinEnd==='to'){
                var mockup=sheet.querySelector('.tp-ref-image-mockup');
                if(mockup){
                    var m=mockup.getBoundingClientRect();
                    nextX=Math.max((m.left-sheetBox.left+4)/per,Math.min((m.right-sheetBox.left-4)/per,nextX));
                    nextY=Math.max((m.top-sheetBox.top+4)/per,Math.min((m.bottom-sheetBox.top-4)/per,nextY));
                }
            }

            setPin(pin,nextX,nextY);

            // While it moves, the shared coordinate follows it. The next draw
            // therefore reconstructs this same point instead of snapping back
            // to the value from before the drag.
            if(pin.dataset.pinEnd==='to'){
                var m=sheet.querySelector('.tp-ref-image-mockup')?.getBoundingClientRect();
                if(m){
                    var absoluteX=sheetBox.left+nextX*per;
                    var absoluteY=sheetBox.top+nextY*per;
                    pin.dataset.mockupX=(Math.max(0,Math.min(100,(absoluteX-m.left)/m.width*100))).toFixed(2);
                    pin.dataset.mockupY=(Math.max(0,Math.min(100,(absoluteY-m.top)/m.height*100))).toFixed(2);
                }
            }
            draw();
        });

        ['pointerup','pointercancel'].forEach(function(name){
            pin.addEventListener(name,function(){
                if(!dragging)return;
                dragging=false;pin.classList.remove('is-moving');
                remember(pin.dataset.pinSlot,pin.dataset.pinEnd,readPin(pin,'x'),readPin(pin,'y'));
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

    // Saving any part of the Artist's pack upgrades legacy leader lines too.
    // Their currently visible garment point becomes the shared relative point,
    // even when the artist did not need to drag that particular line today.
    var lineForm=sheet.closest('form');
    if(lineForm){
        lineForm.addEventListener('submit',function(){
            sheet.querySelectorAll('.tp-ref-pin[data-pin-end="to"]').forEach(function(pin){
                remember(pin.dataset.pinSlot,'to',readPin(pin,'x'),readPin(pin,'y'));
            });
        });
    }
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
document.querySelectorAll('.tp-image-input').forEach(function(input){input.addEventListener('change',()=>preview(input));const box=input.closest('.tp-ref-image');if(!box)return;box.classList.add('is-uploadable');box.addEventListener('click',function(e){if(e.target===input||e.target.closest('.tp-ref-clear,.tp-ref-line-btn,.tp-ref-grip,button,input,textarea,select'))return;const edge=box.getBoundingClientRect();if(box.classList.contains('is-resizable')&&e.clientX>edge.right-22&&e.clientY>edge.bottom-22)return;input.click();});if(!window.DataTransfer)return;box.addEventListener('dragover',e=>{e.preventDefault();box.classList.add('is-dragover')});box.addEventListener('dragleave',()=>box.classList.remove('is-dragover'));box.addEventListener('drop',function(e){e.preventDefault();box.classList.remove('is-dragover');const file=e.dataTransfer?.files?.[0];if(!file||!/^image\//.test(file.type))return;const transfer=new DataTransfer();transfer.items.add(file);input.files=transfer.files;preview(input);});});})();</script>
@endif

@unless ($imageEditable)
{{-- Click a picture to see it big.

     Everybody downstream of the artist reads this on a screen at a station,
     where a garment flat is a couple of centimetres across and the print size
     written on it is unreadable. Not on the artist's copy: there a click opens
     the file picker, which is what they want it to do. --}}
{{-- Make the sheet bigger.

     Only on the copies nobody is filling in. The artist places pins and drags
     captions against what is on screen, so a scaled sheet puts every one of
     those measurements out by the scale factor; and a typing box that has been
     scaled is a poor thing to type in. $imageEditable alone is not the
     question — the officer types on a sheet where it is false. --}}
@unless ($textEditable || $imageEditable)
<div class="tp-scale no-print" data-tp-scale>
    <button type="button" data-tp-scale-step="-1" aria-label="Smaller">&minus;</button>
    <output data-tp-scale-now>100%</output>
    <button type="button" data-tp-scale-step="1" aria-label="Bigger">+</button>
    <button type="button" data-tp-scale-reset>Fit</button>
</div>

<script>
(function () {
    var bar = document.querySelector('[data-tp-scale]');
    var sheet = document.querySelector('.tp-reference-sheet');
    if (!bar || !sheet) { return; }

    /* The sheet is scaled, so the room it needs grows with it. Without a frame
       that scrolls, the right-hand side of an enlarged sheet is simply off the
       page with no way to reach it. */
    var frame = document.createElement('div');
    frame.className = 'tp-scale-frame';
    sheet.parentNode.insertBefore(frame, sheet);
    frame.appendChild(sheet);

    var STEPS = [1, 1.25, 1.5, 2, 2.5, 3];
    var at = 0;

    function apply() {
        var scale = STEPS[at];

        sheet.style.transformOrigin = 'top left';
        sheet.style.transform = scale === 1 ? '' : 'scale(' + scale + ')';

        /* Reserve the room the drawn sheet takes. A transform paints outside
           the box without changing it, so the frame would scroll to the
           unscaled width and cut the rest off. */
        frame.style.height = scale === 1 ? '' : (sheet.offsetHeight * scale) + 'px';
        bar.querySelector('[data-tp-scale-now]').value = Math.round(scale * 100) + '%';
    }

    bar.addEventListener('click', function (e) {
        var step = e.target.closest('[data-tp-scale-step]');

        if (step) {
            at = Math.min(STEPS.length - 1, Math.max(0, at + Number(step.dataset.tpScaleStep)));
            apply();
        }

        if (e.target.closest('[data-tp-scale-reset]')) { at = 0; apply(); }
    });

    /* Printing draws the sheet at the page's size, so a scale meant for
       reading on screen has no business travelling with it. */
    window.addEventListener('beforeprint', function () { sheet.style.transform = ''; frame.style.height = ''; });
    window.addEventListener('afterprint', apply);
})();
</script>
@endunless

<div id="tpZoom" class="tp-zoom no-print" hidden>
    <img id="tpZoomImg" src="" alt="">
    <button type="button" class="tp-zoom-close" aria-label="Close">&times;</button>
</div>

<script>
(function () {
    var box = document.getElementById('tpZoom');
    var shown = document.getElementById('tpZoomImg');
    if (!box) { return; }

    /* Moved out to the body first.

       A transformed ancestor makes position:fixed behave like position:absolute
       — and the page wrapper carries a load animation. Left where it was, the
       viewer sized itself to the whole document instead of the window, so the
       picture opened somewhere below the fold at no size at all. */
    if (box.parentElement !== document.body) { document.body.appendChild(box); }

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
