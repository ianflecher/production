@extends('layouts.app')

@section('title', 'Tech Pack Complete — '.$order->order_number)
@section('page-title', 'Complete Tech Pack Document')

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

    // Page 3: the garment flats, which live in the tech pack step.
    $templateTask = $order->tasks->first(fn ($t) => $t->isTechPackStep());
    $templateFiles = $templateTask?->files->where('round', ($templateTask->revision_count ?? 0) + 1) ?? collect();
    if ($templateFiles->isEmpty()) { $templateFiles = $templateTask?->files ?? collect(); }
    // On submit the mockup is copied onto the template task as well, so drop it
    // here or the template page shows the mockup twice (it has its own page).
    $templateFiles = $templateFiles->reject(fn ($f) => str_starts_with((string) $f->label, 'Mockup (from'));

    // Per-station scope: what this operator needs to see. Null = full package
    // (leader / account officer). Otherwise only the relevant pages are shown.
    $scope = in_array(request('for'), ['printer', 'sticker', 'embroidery', 'production'], true) ? request('for') : null;
    // No export step and no per-station file page: the print, sticker and
    // embroidery files all sit in one folder, and where that folder is lives on
    // the tech pack. A page per file was a page saying "no file yet" forever.
    // The full document is the tech pack. A station's copy is its own file
    // page instead — the production record and the routing details are their
    // own documents and no longer ride along in here.
    // The pack, the print-files folder when the artist has recorded one, then
    // the production details.
    $pageCount = (filled($order->techPack?->file_location_notes)
        && (in_array($scope, ['printer', 'sticker', 'embroidery'], true)
            || ($scope === null && (auth()->user()?->isLeader() || auth()->user()?->isArtist())))) ? 3 : 2;

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

<link rel="stylesheet" href="{{ asset('css/tech-pack.css') }}?v={{ filemtime(public_path('css/tech-pack.css')) }}">

<style>
    .complete-doc { font-family: var(--font-body); counter-reset: pg; background: #e9edf3; padding: 1.2rem 0; }

    /* The tech pack is a LANDSCAPE sheet; every other page here is portrait.
       A named page gives it its own paper instead of the two @page rules
       fighting and the pack losing. */
    @page tech-pack-page { size: A4 landscape; margin: 6mm; }
    .page-section.page-section-pack { width: 297mm; min-height: 210mm; }
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

    /* The print-files folder. Big enough to read across a workbench, and
       selectable in one click so it can be pasted into Explorer. */
    .pf-folder { max-width: 170mm; margin: 0 auto; text-align: center; }
    .pf-label {
        font-size: 0.78rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.06em; color: #6b7280; margin-bottom: 0.6rem;
    }
    .pf-path {
        display: block; padding: 1rem 1.1rem; margin-bottom: 1.1rem;
        background: #111318; color: #f8fafc; border-radius: 10px;
        font-family: ui-monospace, Consolas, monospace; font-size: 1.05rem;
        line-height: 1.6; word-break: break-all; user-select: all;
    }
    .pf-how {
        max-width: 320px; margin: 1.4rem auto 0; padding-left: 1.2rem;
        text-align: left; color: #374151; font-size: 0.92rem; line-height: 2;
    }
    .pf-how kbd {
        display: inline-block; padding: 0.08rem 0.42rem;
        border: 1px solid #9ca3af; border-bottom-width: 2px; border-radius: 5px;
        background: #f9fafb; font-family: inherit; font-size: 0.82rem; font-weight: 700;
    }

    @media print {
        .pf-path {
            background: #fff !important; color: #111 !important;
            border: 1px solid #111; border-radius: 0; font-size: 1rem;
        }
    }
    
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
    /* A filled-in value. White like the paper form — the yellow belongs on
       the entry form, where it means "still to type in". Here it is already
       typed in, and a printed sheet should look like the printed sheet. */
    .yellow { background: #fff !important; font-weight: 700; text-align: center; }
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
           a flex formatting context — that's why the pages ran together as one.
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

        /* The pack keeps its landscape paper and fills it. */
        .page-section-pack { page: tech-pack-page; }
        .page-section-pack .tp-reference-sheet {
            width: 100% !important; max-width: none !important; margin: 0 !important;
        }
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

<script>
    (function () {
        var button = document.querySelector('.pf-copy');
        if (!button) { return; }

        button.addEventListener('click', function () {
            var said = function (word) {
                button.textContent = word;
                setTimeout(function () { button.textContent = 'Copy path'; }, 1600);
            };

            // The clipboard API needs a secure context; over plain http on the
            // office network it is simply absent. Then the next best thing is
            // selecting it for them so Ctrl+C works.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(button.dataset.path).then(
                    function () { said('Copied'); },
                    function () { said('Press Ctrl+C'); }
                );
                return;
            }

            var range = document.createRange();
            range.selectNodeContents(document.getElementById('pfPath'));
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            said('Press Ctrl+C');
        });
    })();
</script>

<div class="page-head no-print">
    <div class="grow">
        <h1>Complete tech pack document</h1>
        <p class="muted">{{ $order->order_number }} · {{ $order->clientName() }} — the tech pack, then the production details</p>
    </div>
    <button type="button" onclick="window.printTechPack ? window.printTechPack() : window.print()" class="btn btn-ghost btn-sm">🖨 Print All</button>
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
            default => [route('orders.job-order', $order), 'tech pack'],
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
    {{-- PAGE 1: THE TECH PACK. Every copy leads with it, a station's included:
         the pack is what the floor works from, and its file location panel is
         where the print-ready path now lives. --}}
    {{-- PAGE 1: THE TECH PACK. The mockup, the flats and the spec used to be
         three separate pages here; they are one sheet now, and this document
         shows that sheet rather than rebuilding the same thing beside it. --}}
    <div class="page-section page-section-pack">
        @include('partials.tech-pack', ['order' => $order])
    </div>

    {{-- PRINT FILES — the folder, on its own page.

         The station opens this to PASTE a network path into Explorer, so it
         gets a page to itself rather than a line of small print inside the
         pack. There used to be a page per file here — print, sticker,
         embroidery — but the artist saves them all into one folder, so one
         page pointing at the folder is what the floor actually needs. --}}
    @php
        $printFolder = $order->techPack?->file_location_notes;

        // Only the people who open those files. A station copy carries it when
        // the station is a print-side one; the full document carries it for the
        // artist who recorded it and the leader who signs it off. A sewer, a
        // cutter, the mover and anybody the document is shown to have no use
        // for a network path, and a page of one is a page in their way.
        $needsFolder = in_array($scope, ['printer', 'sticker', 'embroidery'], true)
            || ($scope === null && (auth()->user()?->isLeader() || auth()->user()?->isArtist()));
    @endphp
    @if (filled($printFolder) && $needsFolder)
        <div class="page-section">
            <div style="text-align:center; padding:1rem;">
                <h1 style="font-size:2rem; margin:0 0 0.4rem;">PRINT FILES</h1>
                <p style="color:#666; margin:0 0 2rem;">{{ $order->order_number }} · {{ $order->clientName() }}</p>
            </div>
            <div class="pf-folder">
                <div class="pf-label">Open this folder</div>
                <code class="pf-path" id="pfPath">{{ $printFolder }}</code>
                <button type="button" class="btn btn-primary no-print pf-copy"
                        data-path="{{ $printFolder }}">Copy path</button>

                {{-- Said out loud, because the folder is no use to anybody who
                     does not know how to open it. Run is faster than clicking
                     through Explorer and does not care which drive is mapped. --}}
                <ol class="pf-how">
                    <li>Press <kbd>Windows</kbd> + <kbd>R</kbd></li>
                    <li>Paste the path (<kbd>Ctrl</kbd> + <kbd>V</kbd>)</li>
                    <li>Press <kbd>Enter</kbd> — the folder opens</li>
                </ol>
            </div>
        </div>
    @endif

    {{-- PRODUCTION DETAILS — full package and the press/cutting/pairing/sewing/QC
         stations. Not shown to the printer or sticker stations. --}}
    @if (! $scope || $scope === 'production')
    <div class="page-section last-page">
        <div class="production-section">
            <h1 style="font-size: 2rem; margin: 0 0 1rem 0;">PRODUCTION DETAILS</h1>
            <p style="color: #666; margin-bottom: 2rem;">{{ $order->order_number }} · {{ $order->clientName() }}</p>

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
