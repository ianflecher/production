@extends('layouts.app')

@section('title', $doc->typeLabel().' — '.$order->order_number)
@section('page-title', $doc->typeLabel())

@section('content')
@php
    $isPq = $doc->isVat();
    $f = fn ($k, $d = null) => $doc->field($k, $d);
    $t = $doc->totals();
    $peso = fn ($n) => '₱'.number_format((float) $n, 2);
    // Total Balance = this sheet's gross minus what's actually been paid on the
    // order (never below zero). Computed, not typed, so it's always correct.
    // Every order this sheet prices, keyed by id, so a line can be drawn under
    // the garment it belongs to - and so the money below is the brief's.
    // each->loadMissing, not ->load: an order with no brief behind it comes
    // back as a plain collection of one, and a plain collection has no load().
    $covered = $order->quotationOrders();
    $covered->each->loadMissing(['payments', 'tasks.files']);
    $covered = $covered->keyBy('id');
    $sharedSheet = $covered->count() > 1;

    $docPaid = (float) $covered->sum(fn ($o) => (float) $o->payments->sum('amount'));

    // A printed sheet per garment that has a drawing, in the order they are
    // priced. One brief is quoted on one sheet now, so one drawing behind it
    // showed the client a page headed "Mockup" for the shirt and nothing at
    // all for the polo and the hoodie they are also paying for.
    //
    // The first page keeps the original design_rotated key, so an orientation
    // saved before the sheet covered the whole brief still applies to it.
    $designPages = $covered->values()
        ->map(fn ($part) => [
            'order' => $part,
            'files' => $part->documentDesignFiles(),
            'label' => $part->documentDesignLabel(),
        ])
        ->filter(fn ($page) => $page['files']->isNotEmpty())
        ->values()
        ->map(fn ($page, $n) => $page + [
            'key' => $n === 0 ? 'design_rotated' : 'design_rotated_'.$page['order']->id,
        ]);
    $docBalance = max(0, round($t['net'] - $docPaid, 2));
    $rows = collect($doc->items ?? [])->values();
    $hasJobOrder = filled($f('print_type')) || filled($f('materials'));
    // Company logo — first match wins, so either extension works.
    $logo = collect(['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.webp'])
        ->first(fn ($n) => file_exists(public_path($n)));

    // The design the client is paying for: the final mockup once it exists,
    // otherwise the layout they approved.
    //
    // Pick the TASK first, then its files. Filtering to images while choosing
    // meant a layout uploaded as a PDF looked like "no design at all", so the
    // page fell back to nothing and still called itself the mockup.
    $designLabel = $order->documentDesignLabel();
    $designFiles = $order->documentDesignFiles();



    // The flatlay photo shown beside the mockup in the header.
    $flatlay = $doc->flatlay;

    // Only real lines — blank rows used to push this to several pages. The design
    // no longer overlays the description column (it lives in the header now), so
    // the only area still needing padding is the PQ's blank right-hand block
    // where the contract / payment proof / signed copy sit (~10 rows).
    $attachments = $doc->attachmentList();

    // Image attachments are drawn OVER the blank rows on the right of the PQ, so
    // reserve enough blank rows for them no matter how many item lines there are.
    // ($minRows counts total rows, so a long order used to leave them nowhere to
    // sit and they spilled over the totals block.) ~21px per row, 3 per line.
    $attachImages = collect($attachments)
        ->filter(fn ($a) => str_starts_with($a['mime'] ?? '', 'image/'))
        ->count();
    $attachRows = ($isPq && $attachImages > 0) ? 10 * (int) ceil($attachImages / 3) : 0;

    $minRows = $isPq ? 15 : 5;
    $blank = max($rows->isEmpty() ? 3 : 0, $minRows - $rows->count(), $attachRows);

    // One page, whatever the brief holds.
    //
    // The sheet is a fixed block — header, bill-to, terms, totals and the
    // signatures — plus one row per line and one heading per garment. A brief
    // quoted on one sheet now carries all of them, so Stephanie Moto's four
    // garments came to 22 lines and 4 headings and pushed the terms and
    // signatures onto a second page, where a quotation's signature block is
    // no use to anybody.
    //
    // Estimated in millimetres against A4's 281mm of usable height at the 8mm
    // margins set below, and the whole sheet is zoomed to fit. Floored at 0.55
    // so a very long brief prints small rather than unreadable.
    $groupHeadings = $rows->pluck('group')->filter()->unique()->count();
    $estimatedMm = 133 + (5 * ($rows->count() + $blank)) + (16 * $groupHeadings);
    $printZoom = max(0.55, min(1.0, round(281 / max(1, $estimatedMm), 3)));
@endphp

<style>
    .doc { max-width:1000px; margin:0 auto; background:#fff; color:#111; padding:0.6rem 0.7rem 1rem; }
    .doc * { box-sizing:border-box; }
    .doc table { width:100%; border-collapse:collapse; table-layout:fixed; }
    .doc td { border:1px solid #999; padding:0.15rem 0.35rem; font-size:0.72rem;
              vertical-align:middle; word-break:break-word; overflow-wrap:anywhere; }
    .doc table.plain td { border:none; padding:0; }
    .doc input { width:100%; border:none; background:transparent; font-size:0.72rem;
                 padding:0.05rem 0.1rem; font-family:inherit; color:#111; }
    .doc .doc-number input { text-align:center; font-weight:700; }
    .doc input:focus { outline:1px solid #2563eb; background:#eff6ff; }
    .doc input.num, .doc .num { text-align:right; }
    .doc .ctr { text-align:center; }
    /* Company block — NOT .brand (the sidebar owns that class). */
    .doc .doc-brand { display:block; }
    .doc .doc-brand .name { display:block; font-weight:800; font-size:1.05rem; margin-bottom:0.2rem; }
    .doc .doc-brand div { display:block; font-size:0.63rem; color:#1f4e79; line-height:1.5; }
    .doc .sec { text-align:center; font-weight:700; font-size:0.7rem; }
    .doc .lbl { background:#f2f2f2; font-weight:700; font-size:0.66rem; text-transform:uppercase; }
    .doc .items th { border:1px solid #999; background:#595959; color:#fff; font-weight:700;
                     font-size:0.68rem; text-transform:uppercase; padding:0.2rem; text-align:center; }
    .doc .bar td { background:#000; height:10px; padding:0; border-color:#000; }
    .doc .terms td { background:#e6b8b7; font-size:0.68rem; }
    .doc .terms .head { color:#c00000; font-weight:800; font-size:0.95rem; text-align:center; }
    .doc .olbl { background:#e36c0a; color:#fff; font-weight:700; font-size:0.62rem;
                 text-transform:uppercase; text-align:center; }
    .doc .yval { background:#ffff00; font-weight:800; text-align:right; }
    .doc .pval { background:#fbd5b5; text-align:right; }
    .doc .sig td { border:none; font-size:0.66rem; font-weight:700; text-transform:uppercase; padding:0.18rem 0; }
    .doc .sig .line { border-bottom:1px solid #111; }
    .doc-actions { max-width:1000px; margin:0 auto 1rem; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; }
    /* The design sheet that prints after the quotation. */
    .design-page {
        max-width:1000px; margin:1.4rem auto 0; background:#fff;
        border:1px solid var(--border); border-radius:8px; padding:1rem; text-align:center;
    }
    .design-page h2 { font-size:1.1rem; font-weight:800; letter-spacing:0.04em; text-transform:uppercase; margin-bottom:0.8rem; color:#111; }
    .design-page img { max-width:100%; max-height:600px; object-fit:contain; display:block; margin:0 auto 0.6rem; }
    .design-page .rot-wrap { display:flex; align-items:center; justify-content:center; overflow:hidden; margin:0 auto 0.6rem; }
    .design-page.is-rotated .rot-wrap { height:560px; }
    .design-page.is-rotated .rot-wrap img { transform:rotate(90deg); max-width:520px; max-height:380px; margin:0; }
    @media print {
        @page { size: A4 portrait; margin: 8mm; }
        html, body { height:auto !important; background:#fff !important; }
        /* The app shell is a FLEX layout and page breaks are ignored inside a
           flex container — reset to block flow or the design page won't split
           onto its own sheet. */
        .shell { display:block !important; min-height:0 !important; }
        .main { display:block !important; min-width:0 !important; }
        .scrim { display:none !important; }
        .sidebar, .topbar, .no-print, .doc-actions { display:none !important; }
        .content { padding:0 !important; max-width:none !important; width:auto !important; animation:none !important; }
        /* Shrunk to fit one sheet. Only the quotation itself — the mockup
           pages below are whole pages of their own and must not shrink. */
        .doc { max-width:none !important; padding:0 !important; zoom: var(--print-zoom, 1); }
        /* Page 2: the mockup / layout on its own portrait sheet. The image is
           turned sideways (landscape) so a wide design fills the tall page. */
        .design-page {
            page-break-before:always !important; break-before:page !important;
            max-width:none !important; margin:0 !important; padding:0 !important;
            border:none !important; border-radius:0 !important;
        }
        .design-page .rot-wrap {
            width:194mm !important; height:255mm !important;   /* A4 portrait usable area */
            display:flex !important; align-items:center; justify-content:center;
            overflow:hidden; margin:0 auto;
        }
        .design-page .rot-wrap img {
            max-width:250mm !important;   /* becomes the vertical extent once rotated */
            max-height:190mm !important;  /* becomes the horizontal extent once rotated */
            margin:0 !important;
        }
        .design-page.is-rotated .rot-wrap img { transform: rotate(90deg); }
        .doc table, .doc tr, .doc td { page-break-inside:avoid; break-inside:avoid; }
        .doc input { border:none !important; background:transparent !important; }
        .doc input::-webkit-calendar-picker-indicator,
        .doc input::-webkit-inner-spin-button,
        .doc input::-webkit-outer-spin-button { display:none !important; -webkit-appearance:none; }
        .doc td, .doc .items th { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
</style>

<form method="POST" action="{{ route('orders.document.save', [$order, $doc->type]) }}">
    @csrf

    <div class="doc-actions no-print">
        <button type="submit" class="btn btn-primary btn-sm">💾 Save</button>
        @if ($designPages->isNotEmpty())
            <a href="#designPage0" class="btn btn-ghost btn-sm">
                🖼 {{ $designPages->count() > 1 ? $designPages->count().' mockup pages' : $designLabel.' page' }}
            </a>
        @endif
        @if ($flatlay)
            <a href="#flatlayPage" class="btn btn-ghost btn-sm">📸 Flatlay page</a>
        @endif
        <button type="button" onclick="window.print()" class="btn btn-ghost btn-sm">🖨 Print</button>
        <button type="button" onclick="downloadDoc()" class="btn btn-ghost btn-sm"
                title="Opens the print dialog — choose 'Save as PDF' as the destination">⬇ Download PDF</button>
        <button type="submit" class="btn btn-ghost btn-sm"
                formaction="{{ route('orders.document.refresh', [$order, $doc->type]) }}"
                onclick="return confirm('Re-fill this document from the order? Typed changes will be replaced.');">
            ↻ Re-fill from order
        </button>
        <a href="{{ route('orders.show', $order) }}" class="btn btn-ghost btn-sm">← Back to order</a>
        <span style="margin-left:auto; font-size:0.8rem; color:var(--ink-3);">
            @if ($isPq) 12% VAT @else No VAT @endif ·
            {{ $hasJobOrder ? 'Tech Pack details included.' : 'Tech Pack not filled yet.' }}
        </span>
    </div>

    <div class="doc" style="--print-zoom:{{ $printZoom }};">
        {{-- Left: address then logo · Right: mockup + flatlay --}}
        <table class="plain">
            <tr>
                <td style="width:40%; vertical-align:top;">
                    <div class="doc-brand">
                        @if ($isPq)<div style="font-weight:700; color:#111; font-size:0.7rem;">GKLASAM, OPC</div>@endif
                        <span class="name">Imprint Customs</span>
                        @if ($isPq)<div>TIN #: 769-693-063</div>@endif
                        <div>Mobile number: +63917 911 7526</div>
                        <div>Email: imprint.customs@gmail.com</div>
                        <div>Website: www.imprintcustomsph.com</div>
                        @unless ($isPq)
                            <div>Facebook: www.facebook.com/imprintcustoms</div>
                            <div>Instagram: www.instagram.com/imprintcustoms</div>
                            <div>TIN #: 769-693-063</div>
                        @endunless
                    </div>
                </td>
                <td style="width:22%; text-align:center; vertical-align:top;">
                    @if ($logo)
                        <img src="{{ asset($logo) }}" alt="Imprint Customs" style="max-height:105px; max-width:100%;">
                    @else
                        <div class="no-print" style="border:1px dashed #bbb; color:#999; font-size:0.65rem; padding:1.6rem 0.5rem; border-radius:6px;">
                            Logo slot — save it as<br><code>public/logo.png</code>
                        </div>
                    @endif
                </td>
                <td style="width:38%; vertical-align:top; padding-left:0.5rem;">
                    <div style="display:flex; gap:0.3rem; align-items:flex-start;">
                        {{-- Product mockup.

                             Only when the sheet prices ONE garment. A shared
                             sheet carries several, and this corner has room
                             for one — so it showed the shirt's drawing at the
                             top of a quotation that also prices the polo and
                             the hoodie, which reads as if the whole sheet were
                             for the shirt. Each garment has its own page and
                             its own picture beside its own lines. --}}
                        @unless ($sharedSheet)
                        <div style="flex:1; text-align:center; min-width:0;">
                            @php $firstDesign = $designFiles->first(fn ($d) => $d->isImage()); @endphp
                            @if ($firstDesign && ! ($firstDesign->isExternal() && ! $firstDesign->isWebLink()))
                                <a href="{{ route('tasks.file.view', $firstDesign) }}" target="_blank" title="Open {{ strtolower($designLabel) }} full size">
                                    <img src="{{ route('tasks.file.view', $firstDesign) }}" alt="{{ $designLabel }}"
                                         style="max-width:100%; max-height:92px; object-fit:contain; border:1px solid #ccc;">
                                </a>
                            @elseif ($firstDesign)
                                {{-- Design lives on the shared drive — don't expose the path on a client doc. --}}
                                <div style="border:1px solid #ccc; color:#666; font-size:0.55rem; padding:1.5rem 0.2rem;">See {{ strtolower($designLabel) }} on file</div>
                            @else
                                <div class="no-print" style="border:1px dashed #ccc; color:#aaa; font-size:0.55rem; padding:1.5rem 0.2rem;">No {{ strtolower($designLabel) }}</div>
                            @endif
                            <div style="font-size:0.52rem; color:#666; text-transform:uppercase; letter-spacing:0.05em; margin-top:0.1rem;">{{ $designLabel }}</div>
                        </div>
                        @endunless
                        {{-- Flatlay --}}
                        <div style="flex:1; text-align:center; min-width:0;">
                            @if ($flatlay)
                                <a href="{{ route('orders.document.flatlay', [$order, $doc->type]) }}"
                                   target="_blank"
                                   title="Open flatlay full size">
                                    <img src="{{ route('orders.document.flatlay', [$order, $doc->type]) }}"
                                         alt="Flatlay"
                                         style="max-width:100%; max-height:92px; object-fit:contain; border:1px solid #ccc;">
                                </a>

                                <div style="font-size:0.52rem; color:#666; text-transform:uppercase; letter-spacing:0.05em; margin-top:0.1rem;">
                                    Flatlay
                                </div>
                            @else
                                <div class="no-print"
                                     style="border:1px dashed #ccc; color:#aaa; font-size:0.55rem; padding:1.5rem 0.2rem;">
                                    No flatlay
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="no-print" style="text-align:center; margin-top:0.3rem;">
                        <button type="button" class="btn btn-ghost btn-xs" style="padding:0.2rem 0.5rem; font-size:0.6rem;"
                                onclick="document.getElementById('flatlayInput').click();">📸 Upload Flatlay</button>
                    </div>
                </td>
            </tr>
        </table>

        {{-- The mockup and flatlay now sit in the header (top-left), so the big
             centred mockup box is gone — it ate most of the first page. --}}

        {{-- The flatlay upload form lives OUTSIDE this form (see below the sheet) —
             a form inside a form is invalid HTML and the browser throws it away,
             which is why uploading a flatlay silently did nothing. --}}

        {{-- Bill to (left) + document details (right) --}}
        <table style="margin-top:0.35rem;">
            <tr>
                <td colspan="2" class="sec" style="border:none;">BILL TO:</td>
                @unless ($isPq)
                    <td class="sec" style="width:15%; border:none; background:transparent;">Quotation #:</td>
                    <td class="doc-number" style="width:19%; border:none;"><input type="text" name="number" value="{{ old('number', $doc->number) }}" aria-label="Quotation number"></td>
                @else
                    <td colspan="2" style="border:none;"></td>
                @endunless
            </tr>
            @php
                $left = $isPq
                    ? [['Company Name','company_name'],['Company Address','company_address'],['TIN Number','bill_tin'],['Contact Person','contact_person'],['Contact Number','contact_number']]
                    : [['Name','bill_name'],['Address','bill_address'],['Contact Number','contact_number'],['Artist','artist']];
                $right = $isPq
                    ? [['Invoice Number','__number'],['Date Ordered:','date_ordered','date'],['Delivery Date:','delivery_date','date'],['Fabric:','materials'],['Print Type:','print_type']]
                    : [['Date Ordered:','date_ordered','date'],['Delivery Date:','delivery_date','date'],['Account Officer:','account_officer'],['Print Type:','print_type']];
                $maxRows = max(count($left), count($right));
            @endphp
            @for ($i = 0; $i < $maxRows; $i++)
                <tr>
                    @if (isset($left[$i]))
                        <td class="lbl" style="width:15%; text-align:right;">{{ $left[$i][0] }}:</td>
                        <td style="width:51%;"><input type="text" name="fields[{{ $left[$i][1] }}]" value="{{ old('fields.'.$left[$i][1], $f($left[$i][1])) }}"></td>
                    @else
                        <td style="border:none; background:transparent;"></td><td style="border:none; background:transparent;"></td>
                    @endif

                    @if (isset($right[$i]))
                        <td class="lbl" style="width:15%; text-align:center;">{{ $right[$i][0] }}</td>
                        <td style="width:19%;">
                            @if ($right[$i][1] === '__number')
                                <input type="text" name="number" value="{{ old('number', $doc->number) }}">
                            @else
                                <input type="{{ $right[$i][2] ?? 'text' }}" name="fields[{{ $right[$i][1] }}]" value="{{ old('fields.'.$right[$i][1], $f($right[$i][1])) }}">
                            @endif
                        </td>
                    @else
                        <td style="border:none; background:transparent;"></td><td style="border:none; background:transparent;"></td>
                    @endif
                </tr>
            @endfor
        </table>

        {{-- Line items --}}
        <table class="items" style="margin-top:0.35rem;">
            <tr>
                <th style="width:{{ $isPq ? '32%' : '46%' }};">{{ $isPq ? 'Order Description' : 'Description' }}</th>
                <th style="width:10%;">Size</th>
                <th style="width:11%;">Quantity</th>
                <th style="width:12%;">Unit Price</th>
                <th style="width:13%;">Total Amount</th>
                @if ($isPq)
                    <th style="width:10%;">12% VAT</th>
                    <th style="width:12%;">Total Net Price</th>
                @endif
            </tr>
            @php $shownGroup = null; @endphp
            @foreach ($rows as $i => $row)
                @php
                    $q = (float) ($row['quantity'] ?? 0); $u = (float) ($row['unit_price'] ?? 0);
                    $amt = $q * $u; $rowVat = $isPq ? $amt * 0.12 : 0;

                    // A shared sheet prices several garments, so each one is
                    // headed by its own drawing — the client reads down the
                    // page and sees what they are paying for beside the price,
                    // which is how the office has always typed it out.
                    $groupHere = $sharedSheet && ($row['group'] ?? null) !== null
                        && ($row['group'] ?? null) !== $shownGroup;
                    if ($groupHere) { $shownGroup = $row['group']; }
                    $groupOrder = $groupHere ? $covered->get($row['order_id'] ?? null) : null;
                    $groupFile = $groupOrder?->documentDesignFiles()->first();
                @endphp
                @if ($groupHere)
                    <tr class="group-row">
                        <td colspan="{{ $isPq ? 7 : 5 }}" style="background:#f3f4f6; padding:0.25rem 0.4rem;">
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                                @if ($groupFile)
                                    <img src="{{ route('tasks.file.view', $groupFile) }}" alt="{{ $shownGroup }}"
                                         style="height:54px; width:auto; max-width:120px; object-fit:contain; border:1px solid #d1d5db; background:#fff;">
                                @endif
                                <strong style="font-size:0.78rem; letter-spacing:0.02em;">{{ $shownGroup }}</strong>
                            </div>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td>
                        <input type="text" name="items[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}">
                        @if (! empty($row['addon']))<input type="hidden" name="items[{{ $i }}][addon]" value="1">@endif
                        @if (isset($row['order_id']))<input type="hidden" name="items[{{ $i }}][order_id]" value="{{ $row['order_id'] }}">@endif
                        @if (isset($row['group']))<input type="hidden" name="items[{{ $i }}][group]" value="{{ $row['group'] }}">@endif
                    </td>
                    <td><input type="text" name="items[{{ $i }}][size]" value="{{ $row['size'] ?? '' }}" style="text-align:center;"></td>
                    <td><input type="number" step="1" min="0" name="items[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? '' }}" class="num"></td>
                    <td><input type="number" step="0.01" min="-10000000" name="items[{{ $i }}][unit_price]" value="{{ $row['unit_price'] ?? '' }}" class="num"></td>
                    {{-- Blank on empty lines — ₱0.00 everywhere was just noise. --}}
                    <td class="num">{{ $amt != 0.0 ? $peso($amt) : '' }}</td>
                    @if ($isPq)
                        <td class="num">{{ $amt != 0.0 ? $peso($rowVat) : '' }}</td>
                        <td class="num">{{ $amt != 0.0 ? $peso($amt + $rowVat) : '' }}</td>
                    @endif
                </tr>
            @endforeach
            @for ($j = 0; $j < $blank; $j++)
                @php
                    $i = $rows->count() + $j;
                    // Attachments overlay the free space on the right of the PQ.
                    $attachHere = $isPq && $j === 0 && $attachments;
                @endphp
                <tr>
                    <td>
                        <input type="text" name="items[{{ $i }}][description]">
                    </td>
                    <td><input type="text" name="items[{{ $i }}][size]" style="text-align:center;"></td>
                    <td><input type="number" step="1" min="0" name="items[{{ $i }}][quantity]" class="num"></td>
                    <td @if ($attachHere) style="position:relative;" @endif>
                        <input type="number" step="0.01" min="0" name="items[{{ $i }}][unit_price]" class="num">
                        @if ($attachHere)
                            {{-- Sits over the empty right-hand columns (width ~4 columns).
                                 Draggable — the offset is stored on the document. --}}
                            <input type="hidden" name="fields[attach_x]" id="attachX" value="{{ (float) $f('attach_x', 0) }}">
                            <input type="hidden" name="fields[attach_y]" id="attachY" value="{{ (float) $f('attach_y', 0) }}">
                            <div id="attachOverlay"
                                 style="position:absolute; top:0; left:0; width:390%; text-align:center; z-index:1;
                                        cursor:move; user-select:none; -webkit-user-select:none;
                                        transform:translate({{ (float) $f('attach_x', 0) }}px, {{ (float) $f('attach_y', 0) }}px);">
                                @foreach ($attachments as $ai => $att)
                                    @if (str_starts_with($att['mime'] ?? '', 'image/'))
                                        <img src="{{ route('orders.document.attach.view', [$order, $doc->type, $ai]) }}"
                                             alt="{{ $att['name'] }}" draggable="false"
                                             style="max-height:190px; max-width:31%; object-fit:contain; display:inline-block; margin:0.1rem; vertical-align:top;">
                                    @else
                                        <a href="{{ route('orders.document.attach.view', [$order, $doc->type, $ai]) }}" target="_blank"
                                           style="display:inline-block; margin:0.3rem; font-size:0.7rem;">📄 {{ $att['name'] }}</a>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="num"></td>
                    @if ($isPq)<td class="num"></td><td class="num"></td>@endif
                </tr>
            @endfor
            <tr class="bar"><td colspan="{{ $isPq ? 7 : 5 }}"></td></tr>
        </table>
        <div class="no-print" style="margin:0.3rem 0 0.5rem;">
            <button type="button" class="btn btn-ghost btn-sm" onclick="addDocRow()">+ Add row</button>
            <span style="font-size:0.75rem; color:var(--ink-3); margin-left:0.4rem;">Empty rows aren't printed.</span>
        </div>

        {{-- Terms + totals: three side-by-side blocks, each its own table so the
             columns can't drift out of alignment. --}}
        @php
            $termLines = [
                'a. 50% downpayment must be settled to start the project',
                'b. FULL PAYMENT must be settled '.($isPq ? 'before' : 'one day before').' shipping/delivery',
                'c. items can be paid in FULL upon pick-up',
                $isPq ? 'd. defective' : 'd. additional 12% for VAT if Official Receipt is required',
                'e. NO RUSH orders.',
            ];
            // [label, field key (null = computed), computed value, colour]
            $moneyRows = $isPq
                ? [['Total Quantity', null, number_format($t['quantity']), 'y'],
                   ['Total Amount Due', null, $peso($t['amount']), 'yr'],
                   ['Total VAT', null, $peso($t['vat']), 'y'],
                   ['Gross', null, $peso($t['net']), 'y'],
                   ['Downpayment', 'downpayment', null, 'p'],
                   ['Fullpayment', 'full_payment', null, 'p'],
                   ['W/ Holding Tax'.($order->withholding_rate ? ' '.$order->withholding_rate.'%' : ' 2307'), 'withholding_tax', null, 'p'],
                   ['Total Balance', 'total_balance', null, 'y']]
                : [['Total Quantity', null, number_format($t['quantity']), 'y'],
                   ['Total Amount Due', null, $peso($t['amount']), 'yr'],
                   ['Down Payment', 'downpayment', null, 'y'],
                   ['Partial Payment', 'partial_payment', null, 'y'],
                   ['Full Payment', 'full_payment', null, 'y'],
                   ['Total Balance', 'total_balance', null, 'y']];
        @endphp
        <table class="plain">
            <tr>
                {{-- Terms --}}
                <td style="width:{{ $isPq ? '48%' : '38%' }}; vertical-align:top; padding-right:2px;">
                    <table>
                        <tr><td class="terms head" style="background:#e6b8b7;">TERMS:</td></tr>
                        @foreach ($termLines as $line)
                            <tr><td class="terms" style="background:#e6b8b7;">{{ $line }}</td></tr>
                        @endforeach
                    </table>
                </td>

                {{-- Payment date verified (delivery receipt only) --}}
                @unless ($isPq)
                    <td style="width:22%; vertical-align:top; padding-right:2px;">
                        <table style="height:100%;">
                            <tr><td class="terms head" style="background:#e6b8b7; font-size:0.72rem;">PAYMENT DATE VERIFIED</td></tr>
                            <tr><td style="height:120px; vertical-align:top;">
                                <input type="text" name="fields[payment_date_verified]" value="{{ old('fields.payment_date_verified', $f('payment_date_verified')) }}">
                            </td></tr>
                        </table>
                    </td>
                @endunless

                {{-- Money column --}}
                <td style="width:{{ $isPq ? '52%' : '40%' }}; vertical-align:top;">
                    <table>
                        @foreach ($moneyRows as [$label, $key, $val, $style])
                            @php
                                $mkey = match ($label) {
                                    'Total Quantity' => 'qty',
                                    'Total Amount Due' => 'amount',
                                    'Total VAT' => 'vat',
                                    'Gross' => 'net',
                                    default => null,
                                };
                            @endphp
                            <tr>
                                <td class="olbl" style="width:52%;">{{ $label }}</td>
                                <td class="{{ $style === 'p' ? 'pval' : 'yval' }}" @if ($style === 'yr') style="color:#c00000;" @endif>
                                    @if ($key === 'total_balance')
                                        {{-- Auto-computed: gross − downpayment (live). --}}
                                        <span data-money="balance">{{ $peso($docBalance) }}</span>
                                        <input type="hidden" name="fields[total_balance]" value="{{ $docBalance }}">
                                    @elseif ($key)
                                        <input type="text" name="fields[{{ $key }}]" value="{{ old('fields.'.$key, $f($key)) }}" class="num js-money">
                                    @elseif ($mkey)
                                        <span data-money="{{ $mkey }}">{{ $val }}</span>
                                    @else
                                        {{ $val }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>

        {{-- Signatures --}}
        <table class="sig" style="margin-top:0.8rem;">
            @php
                $sigLeft = $isPq
                    ? [['Signature', null], ['Prepared By', 'prepared_by'], ['Date Prepared', 'date_prepared', 'date']]
                    : [['Signature', null], ['Account Officer', 'signed_account_officer'], ['Date Approved', 'date_approved', 'date'],
                       ['Dispatch Date', 'dispatch_date', 'date'], ['Delivered By', 'delivered_by'], ['Received By', 'received_by'],
                       ['Signature', null], ['S&M Supervisor', 'sm_supervisor'], ['Date Approved', 'sm_date_approved', 'date']];
                $sigRight = $isPq
                    ? [['Signature', null], ['Approved By', 'approved_by'], ['Date Approved', 'date_approved_2', 'date']]
                    : [['Signature', null], ['Payment Verified By', 'payment_verified_by'], ['Date Verified', 'date_verified', 'date']];
                $sigRows = max(count($sigLeft), count($sigRight));
            @endphp
            @for ($i = 0; $i < $sigRows; $i++)
                <tr>
                    <td style="width:16%;">{{ $sigLeft[$i][0] ?? '' }}{{ isset($sigLeft[$i]) ? ':' : '' }}</td>
                    <td style="width:34%;" class="line">
                        @if (isset($sigLeft[$i]) && $sigLeft[$i][1])
                            <input type="{{ $sigLeft[$i][2] ?? 'text' }}" name="fields[{{ $sigLeft[$i][1] }}]" value="{{ old('fields.'.$sigLeft[$i][1], $f($sigLeft[$i][1])) }}">
                        @endif
                    </td>
                    <td style="width:4%;"></td>
                    <td style="width:18%;">{{ $sigRight[$i][0] ?? '' }}{{ isset($sigRight[$i]) ? ':' : '' }}</td>
                    <td style="width:28%;" class="{{ isset($sigRight[$i]) ? 'line' : '' }}">
                        @if (isset($sigRight[$i]) && $sigRight[$i][1])
                            <input type="{{ $sigRight[$i][2] ?? 'text' }}" name="fields[{{ $sigRight[$i][1] }}]" value="{{ old('fields.'.$sigRight[$i][1], $f($sigRight[$i][1])) }}">
                        @endif
                    </td>
                </tr>
            @endfor
        </table>
    </div>
    {{-- Kept inside the form so 💾 Save stores each design page's orientation. --}}
    @foreach ($designPages as $n => $page)
        <input type="hidden" name="fields[{{ $page['key'] }}]" id="designRotated{{ $n }}"
               value="{{ $f($page['key'], '1') }}">
    @endforeach
    <input type="hidden" name="fields[flatlay_rotated]" id="flatlayRotated" value="{{ $f('flatlay_rotated', '0') }}">
</form>

{{-- Flatlay upload — its own form, outside the sheet form, because this one has
     to be multipart and forms can't be nested. --}}
<form method="POST" action="{{ route('orders.document.uploadFlatlay', [$order, $doc->type]) }}"
      enctype="multipart/form-data" class="no-print" style="display:none;">
    @csrf
    <input type="file" id="flatlayInput" name="flatlay" accept="image/*"
           onchange="this.form.submit();">
</form>

{{-- PAGE 2 ONWARDS — one garment's drawing per printed sheet, in the order
     they are priced above (both DR and PQ). --}}
@foreach ($designPages as $n => $page)
    @php
        $pageOrder = $page['order'];
        $designLabel = $page['label'];
        $designFiles = $page['files'];
    @endphp
    <div class="design-page {{ $f($page['key'], '1') === '0' ? '' : 'is-rotated' }}"
         id="designPage{{ $n }}" data-rotate-store="designRotated{{ $n }}">
        <h2>
            {{ $designLabel }}@if ($sharedSheet) — {{ $pageOrder->quotationGroupLabel() }}@endif
        </h2>
        <div class="no-print" style="margin-bottom:0.6rem;">
            <button type="button" class="rotate-btn btn btn-ghost btn-sm" style="font-size:0.7rem;">⟳ Turn sideways</button>
            <span style="font-size:0.7rem; color:#888; margin-left:0.3rem;">Affects the printed page</span>
        </div>
        <div class="rot-wrap">
            @foreach ($designFiles as $dp)
                @if ($dp->isExternal() && ! $dp->isWebLink())
                    {{-- Path-based design — kept on the shared drive, not printed on the client doc. --}}
                    <div style="text-align:center; padding:2rem 1rem; color:#666;">Design file is kept on the shared drive.</div>
                @elseif ($dp->isImage())
                    <img src="{{ route('tasks.file.view', $dp) }}" alt="{{ $designLabel }}">
                @else
                    {{-- A design uploaded as PDF/AI can't be drawn here — link to it
                         rather than leaving the page blank. --}}
                    <div style="text-align:center; padding:2rem 1rem;">
                        <div style="font-size:2.5rem; line-height:1;">📄</div>
                        <a href="{{ route('tasks.file.view', $dp) }}" target="_blank" style="font-weight:700;">
                            Open {{ strtolower($designLabel) }} — {{ $dp->original_name }}
                        </a>
                        <div class="no-print" style="font-size:0.75rem; color:#888; margin-top:0.4rem;">
                            This {{ $designLabel }} is a {{ strtoupper(pathinfo($dp->original_name, PATHINFO_EXTENSION)) }} file, so it opens separately and won't print on this page.
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
        <div style="font-size:0.7rem; color:#666;">
            {{ $pageOrder->order_number }} · {{ $order->clientName() }}@if ($sharedSheet) · {{ $n + 1 }} of {{ $designPages->count() }}@endif
        </div>
    </div>
@endforeach

{{-- LAST PAGE — the flatlay photo, on its own printed sheet (both DR and PQ). --}}
@if ($flatlay)
    <div class="design-page {{ $f('flatlay_rotated', '0') === '0' ? '' : 'is-rotated' }}" id="flatlayPage">
        <h2>Flatlay</h2>
        <div class="no-print" style="margin-bottom:0.6rem;">
            <button type="button" id="flatlayRotateBtn" class="btn btn-ghost btn-sm" style="font-size:0.7rem;">⟳ Turn sideways</button>
            <span style="font-size:0.7rem; color:#888; margin-left:0.3rem;">Affects the printed page</span>
        </div>
        <div class="rot-wrap">
            <img src="{{ route('orders.document.flatlay', [$order, $doc->type]) }}" alt="Flatlay">
        </div>
        <div style="font-size:0.7rem; color:#666;">
            {{ $order->order_number }} · {{ $order->clientName() }}
        </div>
    </div>
@endif

{{-- Attachments placed on the sheet. Separate form — the sheet form isn't multipart. --}}
@if ($isPq)
    <div class="no-print card panel" style="max-width:1000px; margin:1rem auto 0;">
        <h2 style="font-size:0.95rem;">Contract, payment proof &amp; signed copy</h2>
        <p class="sub" style="margin-bottom:0.7rem;">These sit in the empty space on the right of the quotation, and print with it.</p>

        @if ($attachments)
            <div style="display:flex; flex-wrap:wrap; gap:0.7rem; margin-bottom:0.8rem;">
                @foreach ($attachments as $ai => $att)
                    <div style="border:1px solid var(--border); border-radius:8px; padding:0.4rem; width:140px; text-align:center;">
                        <a href="{{ route('orders.document.attach.view', [$order, $doc->type, $ai]) }}" target="_blank">
                            @if (str_starts_with($att['mime'] ?? '', 'image/'))
                                <img src="{{ route('orders.document.attach.view', [$order, $doc->type, $ai]) }}" alt="{{ $att['name'] }}"
                                     style="max-width:100%; max-height:90px; border-radius:4px; display:block; margin:0 auto;">
                            @else
                                <div style="font-size:1.8rem;">📄</div>
                            @endif
                        </a>
                        <div style="font-size:0.66rem; color:var(--ink-3); margin-top:0.25rem; word-break:break-all;">{{ $att['name'] }}</div>
                        <form method="POST" action="{{ route('orders.document.attach.delete', [$order, $doc->type, $ai]) }}"
                              onsubmit="return confirm('Remove this attachment?');" style="margin-top:0.3rem;">
                            @csrf
                            <button class="btn btn-danger btn-sm" style="padding:0.15rem 0.45rem; font-size:0.68rem;">✕ Remove</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('orders.document.attach', [$order, $doc->type]) }}" enctype="multipart/form-data"
              style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center;">
            @csrf
            <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf"
                   onchange="if(this.files.length){ this.form.submit(); }">
            <button type="submit" class="btn btn-primary btn-sm">⬆ Attach to document</button>
        </form>
    </div>
@endif

<script>
    // Extra line rows, typed by hand. Blank ones are dropped when saved.
    let docRowIndex = {{ $rows->count() + $blank }};
    function addDocRow() {
        const table = document.querySelector('.doc table.items');
        const bar = table.querySelector('tr.bar');
        const isPq = {{ $isPq ? 'true' : 'false' }};
        const tr = document.createElement('tr');
        const i = docRowIndex++;
        let html =
            '<td><input type="text" name="items[' + i + '][description]"></td>' +
            '<td><input type="text" name="items[' + i + '][size]" style="text-align:center;"></td>' +
            '<td><input type="number" step="1" min="0" name="items[' + i + '][quantity]" class="num"></td>' +
            '<td><input type="number" step="0.01" min="-10000000" name="items[' + i + '][unit_price]" class="num"></td>' +
            '<td class="num"></td>';
        if (isPq) { html += '<td class="num"></td><td class="num"></td>'; }
        tr.innerHTML = html;
        bar.parentNode.insertBefore(tr, bar);
        tr.querySelector('input').focus();
        if (window.__docRecalc) window.__docRecalc();
    }

    /* Live totals — recompute every row's amount/VAT, the summary, the Total
       Balance and the payment as you type or add rows (no need to save first). */
    (function () {
        var isPq = {{ $isPq ? 'true' : 'false' }};
        function money(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function num(v) { return parseFloat(('' + (v || '')).replace(/[^0-9.\-]/g, '')) || 0; }
        function setSpan(key, text) { var el = document.querySelector('[data-money="' + key + '"]'); if (el) el.textContent = text; }

        function recalcAll() {
            var totalQty = 0, totalAmt = 0, totalVat = 0;
            document.querySelectorAll('.doc table.items tr').forEach(function (tr) {
                var qEl = tr.querySelector('[name*="[quantity]"]');
                var uEl = tr.querySelector('[name*="[unit_price]"]');
                if (!qEl || !uEl) return;
                var q = num(qEl.value), u = num(uEl.value), amt = q * u, vat = isPq ? amt * 0.12 : 0;
                var cells = tr.querySelectorAll('td.num');   // the display cells (not the input tds)
                if (cells[0]) cells[0].textContent = amt !== 0 ? money(amt) : '';
                if (isPq) {
                    if (cells[1]) cells[1].textContent = amt !== 0 ? money(vat) : '';
                    if (cells[2]) cells[2].textContent = amt !== 0 ? money(amt + vat) : '';
                }
                totalQty += q; totalAmt += amt; totalVat += vat;
            });
            var totalNet = totalAmt + totalVat;
            setSpan('qty', Number(totalQty).toLocaleString('en-PH'));
            setSpan('amount', money(totalAmt));
            setSpan('vat', money(totalVat));
            setSpan('net', money(totalNet));

            // Total Balance = Gross − everything entered in the payment section.
            var paid = 0;
            ['downpayment', 'partial_payment', 'full_payment', 'withholding_tax'].forEach(function (k) {
                var el = document.querySelector('[name="fields[' + k + ']"]');
                if (el) paid += num(el.value);
            });
            var balance = Math.max(0, totalNet - paid);
            setSpan('balance', money(balance));
            var balHidden = document.querySelector('[name="fields[total_balance]"]');
            if (balHidden) balHidden.value = balance.toFixed(2);
        }

        document.addEventListener('input', function (e) {
            var t = e.target;
            if (t.matches('[name*="[quantity]"], [name*="[unit_price]"]') || t.classList.contains('js-money')) {
                recalcAll();
            }
        });
        window.__docRecalc = recalcAll;
        // Don't recompute on load — the server-rendered values are authoritative
        // until the user edits an item, the downpayment, or adds a row.
    })();

    /* Money fields (downpayment, balance, etc.) display as ₱ with commas like the
       computed totals, show a plain number while you type, and submit clean. */
    (function () {
        var fields = document.querySelectorAll('.js-money');
        if (!fields.length) return;
        function peso(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function raw(v) { return (v || '').toString().replace(/[^0-9.\-]/g, ''); }
        function fmt(el) { var r = raw(el.value); el.value = r === '' ? '' : peso(parseFloat(r) || 0); }
        fields.forEach(function (el) {
            fmt(el);
            el.addEventListener('focus', function () { el.value = raw(el.value); });
            el.addEventListener('blur', function () { fmt(el); });
        });
        var form = fields[0].closest('form');
        if (form) form.addEventListener('submit', function () { fields.forEach(function (el) { el.value = raw(el.value); }); });
    })();

    // No PDF engine on the server, so use the browser's print-to-PDF.
    function downloadDoc() {
        alert('In the print dialog, set Destination to "Save as PDF", then Save.');
        window.print();
    }

    /* Turn a design page's image sideways (landscape) or upright. Remembered
       on Save via that page's hidden design_rotated field.

       Once per page: a brief with four garments prints four of these, and one
       handler bound to a single id turned the first sheet and left the rest. */
    document.querySelectorAll('.design-page[data-rotate-store]').forEach(function (page) {
        var btn = page.querySelector('.rotate-btn');
        var store = document.getElementById(page.dataset.rotateStore);
        if (!btn) return;

        function sync() {
            var on = page.classList.contains('is-rotated');
            btn.textContent = on ? '⟳ Turn upright' : '⟳ Turn sideways';
            if (store) store.value = on ? '1' : '0';
        }

        btn.addEventListener('click', function () {
            page.classList.toggle('is-rotated');
            sync();
        });

        sync();
    });

    /* Same rotate control for the flatlay page. */
    (function () {
        var btn = document.getElementById('flatlayRotateBtn');
        var page = document.getElementById('flatlayPage');
        var store = document.getElementById('flatlayRotated');
        if (!btn || !page) return;

        function sync() {
            var on = page.classList.contains('is-rotated');
            btn.textContent = on ? '⟳ Turn upright' : '⟳ Turn sideways';
            if (store) store.value = on ? '1' : '0';
        }

        btn.addEventListener('click', function () {
            page.classList.toggle('is-rotated');
            sync();
        });

        sync();
    })();

    /* Drag the proof / attachment image to reposition it on the sheet. The
       offset is saved with the document, so it stays put and prints there. */
    (function () {
        var box = document.getElementById('attachOverlay');
        var fx = document.getElementById('attachX');
        var fy = document.getElementById('attachY');
        if (!box || !fx || !fy) return;

        var x = parseFloat(fx.value) || 0;
        var y = parseFloat(fy.value) || 0;
        var startX = 0, startY = 0, originX = 0, originY = 0, dragging = false;

        function apply() { box.style.transform = 'translate(' + x + 'px,' + y + 'px)'; }

        box.addEventListener('mousedown', function (e) {
            dragging = true;
            startX = e.clientX; startY = e.clientY;
            originX = x; originY = y;
            box.style.opacity = '0.75';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            x = originX + (e.clientX - startX);
            y = originY + (e.clientY - startY);
            apply();
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            box.style.opacity = '';
            fx.value = Math.round(x);
            fy.value = Math.round(y);
        });

        // Double-click puts it back where it started.
        box.addEventListener('dblclick', function () {
            x = 0; y = 0; apply();
            fx.value = 0; fy.value = 0;
        });

        apply();
    })();
</script>
@endsection
