@extends('layouts.app')

@section('title', 'Job Order Complete — '.$order->order_number)
@section('page-title', 'Complete Job Order Document')

@section('content')
@php
    $jo = $order->jobOrder;
    $mockupTask = $order->tasks->firstWhere('department', 'Final mockup');
    $layoutTask = $order->tasks->firstWhere('department', 'Layout');
    $usingMockup = $mockupTask && $mockupTask->files->isNotEmpty();
    $imgTask = $usingMockup ? $mockupTask : $layoutTask;
    // Label the first page for what it actually is — LAYOUT until the mockup exists.
    $designLabel = $usingMockup ? 'MOCKUP' : 'LAYOUT';
    $mockupFiles = $imgTask?->files->where('round', ($imgTask->revision_count ?? 0) + 1) ?? collect();
    // The round filter can come back empty (e.g. files logged without a round) —
    // fall back to every file on the task so the page is never blank.
    if ($mockupFiles->isEmpty()) { $mockupFiles = $imgTask?->files ?? collect(); }

    // Page 3: the production template the artist made.
    $templateTask = $order->tasks->firstWhere('department', 'Production template');
    $templateFiles = $templateTask?->files->where('round', ($templateTask->revision_count ?? 0) + 1) ?? collect();
    if ($templateFiles->isEmpty()) { $templateFiles = $templateTask?->files ?? collect(); }
    // On submit the mockup is copied onto the template task as well, so drop it
    // here or the template page shows the mockup twice (it has its own page).
    $templateFiles = $templateFiles->reject(fn ($f) => str_starts_with((string) $f->label, 'Mockup (from'));

    // Per-station scope: what this operator needs to see. Null = full package
    // (leader / account officer). Otherwise only the relevant pages are shown.
    $scope = in_array(request('for'), ['printer', 'sticker', 'embroidery', 'production'], true) ? request('for') : null;
    $allFiles = $order->tasks->flatMap->files;
    // One single Export step now provides the print, sticker AND embroidery
    // files, so every station reads the same Export file. (Older orders may still
    // have the separate labels — fall back to those.)
    $exportFile = $allFiles->firstWhere('label', 'Export file')
        ?? $allFiles->firstWhere('label', 'Print file (TIFF)');
    $tiffFile = $exportFile;
    $stickerFile = $allFiles->firstWhere('label', 'Sticker file') ?? $exportFile;
    $embroideryFile = $allFiles->firstWhere('label', 'Embroidery file') ?? $exportFile;
    $pageCount = $scope ? 2 : 4;   // scoped = JO + one page; full = 4 pages

    // Cutting defaults
    $selectedCut = old('cutting_type', $order->cutting_type);
    if (! $selectedCut) {
        foreach (\App\Models\JobOrder::PRINT_TYPES as $pt) {
            if (strtolower($pt['label']) === strtolower((string) $jobOrder->print_type)) { $selectedCut = $pt['cutting']; break; }
        }
    }
    $rawMaterials = old('raw_materials', $jobOrder->rawMaterialsList());
    $rawMaterials = array_values(array_filter((array) $rawMaterials, fn ($v) => filled($v)));
    if (empty($rawMaterials)) { $rawMaterials = ['']; }
    
    $artistName = optional($order->tasks->first(fn ($t) => $t->team === \App\Models\User::JOB_ARTIST && $t->assignee))->assignee?->name ?? '—';
    $y = fn ($v) => filled($v) ? $v : '';
@endphp

<style>
    .complete-doc { font-family: var(--font-body); counter-reset: pg; background: #e9edf3; padding: 1.2rem 0; }
    /* On screen each section is drawn as its own A4 sheet, so you can SEE the
       4 separate pages instead of one long scroll. */
    .page-section {
        counter-increment: pg;
        position: relative;
        width: 210mm;
        max-width: 100%;
        min-height: 297mm;
        margin: 0 auto 1.4rem;
        padding: 10mm;
        background: #fff;
        box-shadow: 0 2px 6px rgba(15, 23, 42, .12), 0 12px 28px rgba(15, 23, 42, .14);
        page-break-after: always;
    }
    .page-section::before {
        content: 'PAGE ' counter(pg) ' OF {{ $pageCount }}';
        position: absolute; top: 4mm; right: 10mm;
        font-size: 10px; font-weight: 700; letter-spacing: .08em; color: #94a3b8;
    }
    .last-page { page-break-after: auto; }
    
    /* Job Order Sheet Styles */
    .jo-sheet { max-width: 900px; margin: 0 auto; background: #fff; color: #111; border: 2px solid #111; }
    .jo-sheet * { box-sizing: border-box; }
    .jo-title { text-align: center; padding: 0.6rem; border-bottom: 2px solid #111; }
    .jo-title .t1 { font-size: 1.6rem; font-weight: 800; letter-spacing: 0.02em; }
    .jo-title .t1 .pri { color: #d00; }
    .jo-title .t2 { font-size: 1.2rem; font-weight: 800; color: #d00; margin-top: 0.15rem; }
    table.jo { width: 100%; border-collapse: collapse; }
    table.jo td, table.jo th { border: 1px solid #111; padding: 0.3rem 0.5rem; font-size: 0.8rem; vertical-align: top; }
    .lbl { background: #cfcfcf; font-weight: 700; text-align: center; font-size: 0.72rem; text-transform: uppercase; }
    .lbl-l { background: #cfcfcf; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; }
    .yellow { background: #ffef00 !important; font-weight: 700; text-align: center; }
    .ctr { text-align: center; }
    .red { color: #d00; font-weight: 700; }
    .sec { background: #cfcfcf; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .mock-box { min-height: 150px; text-align: center; }
    .mock-box img { max-width: 100%; max-height: 260px; border: 1px solid #999; }
    
    /* Mockup page styles */
    .mockup-section { text-align: center; padding: 2rem; }
    .mockup-section img { max-width: 95%; height: auto; border-radius: 8px; }
    
    /* Production details — labels and values line up down the column. */
    .prod-details td { vertical-align: top; }
    .prod-details .lbl-l { width: 32%; }
    .prod-details .yellow { text-align: left; }
    .production-section { padding: 2rem; }
    .production-section h2 { font-size: 1.2rem; font-weight: 700; margin: 1rem 0 0.5rem 0; }
    .production-section h3 { font-size: 1rem; font-weight: 600; color: #666; margin: 0.5rem 0; }
    .field-row { margin: 0.8rem 0; }
    .field-row label { display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 0.9rem; }
    .field-row input, .field-row select { padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem; }
    .field-row input { width: 100%; max-width: 400px; }
    .field-row select { width: 100%; max-width: 350px; }
    
    @media print {
        @page { size: A4 portrait; margin: 10mm; }

        /* The app shell is a FLEX layout, and page-break-after is ignored inside
           a flex formatting context — that's why all 4 pages ran together as one.
           Reset the shell to normal block flow so the page breaks apply. */
        html, body { height: auto !important; background: #fff !important; }
        .shell { display: block !important; min-height: 0 !important; }
        .main { display: block !important; min-width: 0 !important; }
        .content { padding: 0 !important; max-width: none !important; width: auto !important; animation: none !important; }
        .sidebar, .topbar, .scrim, .no-print, .jo-actions, .page-head { display: none !important; }
        .complete-doc { display: block !important; }

        /* One printed page per section, and no trailing blank page. The screen
           A4 framing is dropped here — @page already defines the paper. */
        .complete-doc { background: none !important; padding: 0 !important; }
        .page-section {
            width: auto !important; max-width: none !important; min-height: 0 !important;
            margin: 0 !important; padding: 0 !important; box-shadow: none !important;
            page-break-after: always !important; break-after: page !important;
        }
        .page-section::before { display: none !important; }
        .page-section:last-child, .last-page { page-break-after: auto !important; break-after: auto !important; }

        .jo-sheet { max-width: none !important; margin: 0 !important; }
        table.jo, table.jo tr, table.jo td { page-break-inside: avoid; break-inside: avoid; }

        /* Keep each photo within its own page. The inline flex + 85vh on the
           mockup block is overridden here — vh units and flex both break
           pagination in print. */
        .mockup-section { display: block !important; min-height: 0 !important; padding: 0.5rem !important; text-align: center !important; }
        .mockup-section img { max-width: 100% !important; max-height: 225mm !important; object-fit: contain; }

        /* Greys, yellows and header fills must actually print. */
        .yellow, .lbl, .lbl-l, .sec, .jo-title, table.jo td, table.jo th {
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
    }
</style>

<div class="page-head no-print">
    <div class="grow">
        <h1>Complete Job Order Document</h1>
        <p class="muted">{{ $order->order_number }} · {{ $order->customer_name }} — All 4 pages for artist</p>
    </div>
    <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨 Print All</button>
    {{-- The single export file the artist produced, for the printer to open. --}}
    @if ($exportFile && ! $exportFile->isExternal())
        <a href="{{ route('tasks.file.download', $exportFile) }}" class="btn btn-primary btn-sm">🖨 Download export file</a>
    @endif
    {{-- Definite destination (url()->previous() pointed back at this same page).
         Leaders open this from Approvals; sales from the job order sheet. --}}
    @php
        $u = auth()->user();

        // Send everyone back where they actually came from: leaders from
        // Approvals, the floor from the station board, sales from the job order.
        [$backUrl, $backLabel] = match (true) {
            $u->isLeader() => [route('approvals'), 'approvals'],
            // The mover has no station and no task list — her way in is the
            // conversation about the job.
            $u->isMover() => [route('messages.show', $order), 'messages'],
            $u->canUseStations() => [route('stations.index'), 'stations'],
            $u->canManageInventory() => [route('inventory.requests'), 'material requests'],
            $u->canManageProducts() => [route('products.index'), 'inventory'],
            default => [route('orders.job-order', $order), 'job order'],
        };
    @endphp
    <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">← Back to {{ $backLabel }}</a>
</div>

{{-- The operator opening this sheet is the one who can act on a late job, so
     the warning rides along with the work. Screen only — it isn't part of the
     printed document. --}}
<div class="no-print" style="max-width:820px; margin:0 auto;">
    @include('partials.delay-alert', ['order' => $order, 'size' => 'big'])
</div>

<div class="complete-doc">
    @unless ($scope)
    {{-- PAGE 1: MOCKUP (or LAYOUT until the mockup exists) --}}
    <div class="page-section">
        <div style="text-align: center; padding: 1rem;">
            <h1 style="font-size: 2rem; margin: 0 0 1rem 0;">{{ $designLabel }}</h1>
        </div>
        @if ($mockupFiles->isNotEmpty())
            <div class="mockup-section" style="display: flex; align-items: center; justify-content: center; min-height: 85vh;">
                @foreach ($mockupFiles as $f)
                    @include('partials.task-file-view', ['file' => $f, 'maxH' => '80vh'])
                @endforeach
            </div>
        @else
            <div style="text-align: center; padding: 3rem; color: #999;">
                <p>No mockup available yet</p>
            </div>
        @endif
    </div>

    {{-- PAGE 2: TEMPLATE --}}
    <div class="page-section">
        <div style="text-align: center; padding: 1rem;">
            <h1 style="font-size: 2rem; margin: 0 0 1rem 0;">TEMPLATE</h1>
        </div>
        @if ($templateFiles->isNotEmpty())
            <div class="mockup-section">
                @foreach ($templateFiles as $tf)
                    @include('partials.task-file-view', ['file' => $tf, 'maxH' => '80vh'])
                @endforeach
            </div>
        @else
            <div style="text-align: center; padding: 3rem; color: #999;">
                <p>No production template available yet</p>
            </div>
        @endif

    </div>
    @endunless

    {{-- JOB ORDER SHEET — shown to every station and in the full package. --}}
    <div class="page-section">
        @include('partials.job-order-sheet', ['order' => $order])
    </div>

    {{-- Printer station: just the print file (TIFF). --}}
    @if ($scope === 'printer')
        <div class="page-section">
            <div style="text-align:center; padding:1rem;"><h1 style="font-size:2rem; margin:0 0 1rem;">PRINT FILE (TIFF)</h1></div>
            @include('partials.station-file', ['file' => $tiffFile, 'label' => 'print file (TIFF)'])
        </div>
    @endif

    {{-- Sticker station: just the sticker file. --}}
    @if ($scope === 'sticker')
        <div class="page-section">
            <div style="text-align:center; padding:1rem;"><h1 style="font-size:2rem; margin:0 0 1rem;">STICKER FILE</h1></div>
            @include('partials.station-file', ['file' => $stickerFile, 'label' => 'sticker file'])
        </div>
    @endif

    {{-- Embroidery station: just the embroidery file. --}}
    @if ($scope === 'embroidery')
        <div class="page-section">
            <div style="text-align:center; padding:1rem;"><h1 style="font-size:2rem; margin:0 0 1rem;">EMBROIDERY FILE</h1></div>
            @include('partials.station-file', ['file' => $embroideryFile, 'label' => 'embroidery file'])
        </div>
    @endif

    {{-- PRODUCTION DETAILS — full package and the press/cutting/pairing/sewing/QC
         stations. Not shown to the printer or sticker stations. --}}
    @if (! $scope || $scope === 'production')
    <div class="page-section last-page">
        <div class="production-section">
            <h1 style="font-size: 2rem; margin: 0 0 1rem 0;">PRODUCTION DETAILS</h1>
            <p style="color: #666; margin-bottom: 2rem;">{{ $order->order_number }} · {{ $order->customer_name }}</p>

            {{-- Production details: press, embroidery, cutting and raw materials.
                 Every value cell is left-aligned so the column reads straight
                 down — .yellow centres by default, which looked ragged next to
                 the multi-line embroidery note and materials list. --}}
            <table class="jo prod-details" style="max-width: 620px;">
                @php
                    // Fabric press (merges the print onto the fabric) and the
                    // decoration (a press, embroidery, or none).
                    $fabricKey = $jo?->fabric_press ?: $jo?->defaultFabricPress();
                    $fabricVal = $fabricKey ? (\App\Models\JobOrder::pressOptions()[$fabricKey] ?? $fabricKey) : 'NO PRESS';

                    // The add-on the client ordered, plus the press that does it.
                    $decoKey = $jo?->press;
                    $pressName = $decoKey ? (\App\Models\JobOrder::pressOptions()[$decoKey] ?? $decoKey) : null;
                    $addonName = $jo?->addonLabel();

                    if ($addonName) {
                        $decoVal = $addonName.($pressName ? ' ('.$pressName.')' : '');
                        // What the add-on covers, then what to embroider — the
                        // treatment on its own doesn't say where it goes.
                        if (filled($jo?->addon_note)) {
                            $decoVal .= ' — '.$jo->addon_note;
                        }
                    } elseif ($decoKey === 'embroidery') {
                        $decoVal = 'EMBROIDERY';
                    } elseif ($decoKey) {
                        $decoVal = $pressName;
                    } else {
                        $decoVal = 'NONE';
                    }
                @endphp
                <tr>
                    <td class="lbl-l" style="width: 32%;">Fabric Press</td>
                    <td class="yellow">{{ strtoupper($fabricVal) }}</td>
                </tr>
                <tr>
                    <td class="lbl-l">Add-on</td>
                    {{-- Value output tight to the <td> — with white-space:pre-line, any
                         leading newline/indent from Blade would show as a blank line. --}}
                    <td class="yellow" style="white-space: pre-line;">{{ strtoupper($decoVal) }}</td>
                </tr>
                <tr>
                    <td class="lbl-l">Cutting</td>
                    <td class="yellow">{{ strtoupper($y(\App\Models\ProductionOrder::CUTTING_TYPES[$selectedCut] ?? $selectedCut)) ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="lbl-l">Raw Materials</td>
                    <td class="yellow">
                        @php $rmList = array_values(array_filter($rawMaterials, fn ($v) => filled($v))); @endphp
                        @if ($rmList)
                            @foreach ($rmList as $rm)
                                <div>{{ strtoupper($rm) }}</div>
                            @endforeach
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="lbl-l">Back Pocket</td>
                    <td class="yellow">
                        @if ($order->backPocketCount() > 0)
                            {{ number_format($order->backPocketCount()) }} PC{{ $order->backPocketCount() == 1 ? '' : 'S' }}{{ $order->backPocketCount() == $order->quantity ? ' (ALL)' : ' OF '.number_format($order->quantity) }}
                        @else
                            NONE
                        @endif
                    </td>
                </tr>
            </table>

        </div>
    </div>
    @endif
</div>

@endsection
