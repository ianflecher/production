{{-- The job order sheet. Shared by orders/job-order.blade.php (standalone) and
     job-orders/complete.blade.php (page 3 of the package document) so the two
     can never drift apart.

     Expects: $order.  Optional: $showMockup (default true) — the design overlay
     is hidden in the package document because the mockup has its own page. --}}
@php
    $jo = $order->jobOrder;
    $showMockup = $showMockup ?? true;

    $mockupTask = $order->tasks->firstWhere('department', 'Final mockup');
    $layoutTask = $order->tasks->firstWhere('department', 'Layout');
    $imgTask = ($mockupTask && $mockupTask->files->isNotEmpty()) ? $mockupTask : $layoutTask;
    $mockupFiles = $showMockup
        ? ($imgTask?->files->where('round', ($imgTask->revision_count ?? 0) + 1) ?? collect())
        : collect();

    $artistName = optional($order->tasks->first(fn ($t) => $t->team === \App\Models\User::JOB_ARTIST && $t->assignee))->assignee?->name ?? '—';
    $y = fn ($v) => filled($v) ? $v : '';

    // Who actually did each step. Floor accounts are shared, so prefer the name
    // typed at the station over the account the task sits on.
    $who = function (array $departments) use ($order) {
        return $order->tasks
            ->whereIn('department', $departments)
            ->map(fn ($t) => $t->operator_name ?: $t->assignee?->name)
            ->filter()
            ->unique()
            ->implode(', ');
    };
@endphp

<style>
    /* Keep the whole sheet on ONE printed page: tighter rows, smaller type, and
       the app shell reset to block flow (page rules are ignored inside flex). */
    @media print {
        @page { size: A4 portrait; margin: 6mm; }
        html, body { height: auto !important; background: #fff !important; }
        .shell { display: block !important; min-height: 0 !important; }
        .main { display: block !important; }
        .content { padding: 0 !important; max-width: none !important; animation: none !important; }
        .sidebar, .topbar, .scrim, .no-print, .jo-actions { display: none !important; }

        .jo-sheet { max-width: none !important; margin: 0 !important; border-width: 1px !important; }
        .jo-title { padding: 0.2rem !important; }
        .jo-title .t1 { font-size: 0.95rem !important; }
        .jo-title .t2 { font-size: 0.75rem !important; margin-top: 0 !important; }

        table.jo td, table.jo th {
            padding: 0.08rem 0.25rem !important;
            font-size: 0.58rem !important;
            line-height: 1.15 !important;
        }
        .lbl, .lbl-l { font-size: 0.52rem !important; }
        .sec { font-size: 0.6rem !important; }
        table.jo { page-break-inside: avoid; break-inside: avoid; }

        /* The design overlay is the one thing that can push it over a page. */
        table.jo td img { max-height: 150px !important; }

        /* Fills must actually print. */
        .yellow, .lbl, .lbl-l, .sec, table.jo td, table.jo th {
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
    }
</style>
<div class="jo-sheet">
    {{-- Title --}}
    <div class="jo-title">
        <div class="t1">{{ $order->massprod_priority ? 'MASSPROD - ' : '' }}<span class="pri">{{ $order->massprod_priority ? 'PRIORITY' : 'JOB ORDER' }}</span></div>
        <div class="t2">JOB ORDER #&nbsp;&nbsp;{{ $order->order_number }}</div>
    </div>

    {{-- Header info --}}
    <table class="jo">
        <tr>
            <td class="lbl-l" style="width: 18%;">Client Name:</td>
            <td style="width: 32%;" class="ctr">{{ $order->client?->name ?? $order->customer_name }}</td>
            <td class="lbl-l" style="width: 18%;">Date Ordered:</td>
            <td style="width: 32%;" class="ctr red">{{ $order->created_at->format('n/j/Y') }}</td>
        </tr>
        <tr>
            <td class="lbl-l">FB/Viber/GC Name:</td>
            <td class="ctr">{{ $jo?->fb_viber_gc ?? '—' }}</td>
            <td class="lbl-l">Delivery Date:</td>
            <td class="ctr red">{{ $order->due_date?->format('m/j/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl-l">Type of Apparel:</td>
            <td class="ctr" style="font-weight: 700;">{{ strtoupper($order->productLabel() ?? '—') }}</td>
            <td class="lbl-l">Account Officer:</td>
            <td class="ctr red">{{ strtoupper($order->creator?->name ?? '—') }}</td>
        </tr>
        <tr>
            <td class="lbl-l">Artist Name:</td>
            <td class="ctr" style="font-weight: 700;">{{ strtoupper($artistName) }}</td>
            <td class="lbl-l">Team:</td>
            <td class="ctr" style="font-weight: 700;">{{ $order->creator?->teamLabel() ?? '—' }}</td>
        </tr>
        @if ($order->backPocketCount() > 0)
        <tr>
            <td class="lbl-l">Back Pocket:</td>
            <td class="ctr red" style="font-weight: 800;" colspan="3">
                {{ $order->backPocketCount() }} PC{{ $order->backPocketCount() == 1 ? '' : 'S' }}{{ $order->backPocketCount() == $order->quantity ? ' — ALL PIECES' : ' OF '.$order->quantity }}
            </td>
        </tr>
        @endif
    </table>

    {{-- Each description lines up with its size / quantity row; the mockup
         overlays the top of the description column (may cover part of it). --}}
    <table class="jo">
        <tr>
            <td class="lbl" style="width: 50%;">Description</td>
            <td class="lbl" style="width: 25%;">Size</td>
            <td class="lbl" style="width: 25%;">Quantity</td>
        </tr>
        @php
            $items = $order->itemsInSizeOrder();
            // Always keep at least 10 rows so the mockup has room to sit in the
            // description column instead of spilling into the Production section.
            $baseRows = 10;
            $blankRows = max(0, $baseRows - $items->count());
        @endphp
        @foreach ($items as $item)
            <tr>
                <td class="ctr" style="{{ $loop->first && $mockupFiles->isNotEmpty() ? 'position: relative;' : '' }} font-weight: 700; text-align: right; white-space: pre-line;">
                    {{-- Per-line description, else the order's overall description on the first row. --}}
                    {{ strtoupper($item->description ?: ($loop->first ? ($order->description ?? '') : '')) }}
                    @if ($loop->first && $mockupFiles->isNotEmpty())
                        <div style="position: absolute; top: 0; left: 0; right: 0; text-align: center; z-index: 1;">
                            @foreach ($mockupFiles as $f)
                                @if ($f->isImage())
                                    <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label }}" style="max-width: 60%; max-height: 220px; display: block; margin: 0 auto;">
                                @endif
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="ctr">{{ $item->size }}</td>
                <td class="ctr">{{ $item->quantity }}</td>
            </tr>
        @endforeach

        {{-- Blank base rows — on the FIRST one the mockup shows if there were no
             size items above to host it. --}}
        @for ($i = 0; $i < $blankRows; $i++)
            <tr>
                <td class="ctr" style="{{ $i === 0 && $items->isEmpty() && $mockupFiles->isNotEmpty() ? 'position: relative;' : '' }} height: 1.6rem;">
                    @if ($i === 0 && $items->isEmpty() && $mockupFiles->isNotEmpty())
                        <div style="position: absolute; top: 0; left: 0; right: 0; text-align: center; z-index: 1;">
                            @foreach ($mockupFiles as $f)
                                @if ($f->isImage())
                                    <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label }}" style="max-width: 60%; max-height: 220px; display: block; margin: 0 auto;">
                                @endif
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="ctr"></td>
                <td class="ctr"></td>
            </tr>
        @endfor

        <tr>
            <td></td>
            <td class="lbl-l" style="text-align: right;">TOTAL</td>
            <td class="ctr" style="font-weight: 800;">{{ $order->quantity }}</td>
        </tr>
    </table>

    {{-- PRODUCTION --}}
    <table class="jo">
        <tr><td colspan="4" class="sec">Production</td></tr>
        <tr>
            <td class="lbl" style="width: 25%;">Print Type</td>
            <td class="lbl" style="width: 25%;">Printer</td>
            <td class="lbl" style="width: 25%;">Fabric</td>
            <td class="lbl" style="width: 25%;">Free Logo Sticker</td>
        </tr>
        <tr>
            <td class="yellow">{{ strtoupper($y($jo?->printTypeLabel())) }}</td>
            <td class="yellow">{{ strtoupper($y($jo?->printerLabel())) }}</td>
            <td class="yellow">{{ strtoupper($y($jo?->fabric)) }}</td>
            <td class="yellow">{{ $order->needs_sticker ? strtoupper($y($jo?->free_logo_sticker)) : '' }}</td>
        </tr>
        {{-- The two presses: one merges the print onto the fabric, one decorates. --}}
        <tr><td class="lbl-l">Fabric Press:</td><td colspan="3" class="yellow" style="text-align: left;">{{ strtoupper($y($jo?->fabricPressLabel())) }}</td></tr>
        {{-- No add-on row. It is asked for and answered in the production
             details, and repeating it here only gave the two places somewhere
             to disagree. --}}
        {{-- Filled in from whoever ran each station, so the sheet doesn't have to
             be written up by hand after the job. --}}
        <tr><td class="lbl-l">Printer Operator:</td><td colspan="3">{{ $who(['Printer', 'Sticker', 'Mass production']) }}</td></tr>
        <tr><td class="lbl-l">Press Operator:</td><td colspan="3">{{ $who(array_values(\App\Models\ProductionOrder::DECORATION_METHODS)) }}</td></tr>
        <tr><td class="lbl-l">Lazer Cutter Operator:</td><td colspan="3">{{ $who(['Laser cutting', 'Manual cutting']) }}</td></tr>
        <tr><td class="lbl-l">Pairing:</td><td colspan="3">{{ $who(['Pairing']) }}</td></tr>
        {{-- The mover closes no step, so there is no operator name to read off
             one. Who followed this job is who signed a message on it — several
             people share the one login, and each types their own name. --}}
        <tr><td class="lbl-l">Mover:</td><td colspan="3">{{ $order->moverNames() ?: '' }}</td></tr>
        @if ($jo?->needs_embroidery)
            <tr>
                <td class="lbl-l">Embroidery:</td>
                <td colspan="3" class="yellow" style="text-align: left;">YES</td>
            </tr>
        @endif
    </table>

    {{-- SEWING --}}
    <table class="jo">
        <tr><td colspan="4" class="sec">Sewing</td></tr>
        <tr>
            <td class="lbl" style="width: 25%;">Neck</td>
            <td class="lbl" style="width: 25%;">Cuff / Arm Sleeves</td>
            <td class="lbl" style="width: 25%;">Neck Label</td>
            <td class="lbl" style="width: 25%;">Bottom Hem</td>
        </tr>
        <tr>
            <td class="yellow">{{ strtoupper($y($jo?->neck)) }}</td>
            <td class="yellow">{{ strtoupper($y($jo?->cuff_arm_sleeves)) }}</td>
            <td class="yellow">{{ strtoupper($y($jo?->neck_label)) }}</td>
            <td class="yellow">{{ strtoupper($y($jo?->bottom_hem)) }}</td>
        </tr>
        <tr>
            <td class="lbl-l" colspan="2">Sewer:</td>
            <td colspan="2">{{ $who(['Sewing']) }}</td>
        </tr>
        <tr>
            <td class="lbl-l">Thread Color:</td>
            <td class="lbl-l">Thread Color:</td>
            <td class="lbl-l">Thread Color:</td>
            <td class="lbl-l">Thread Color:</td>
        </tr>
        <tr>
            <td class="lbl-l" colspan="3">IC Woven / Tag Placement:</td>
            <td class="yellow">{{ strtoupper($y($jo?->ic_placement)) }}</td>
        </tr>
        <tr><td class="lbl-l" colspan="4">Notes from Sewer:</td></tr>
    </table>

    {{-- QUALITY CHECK --}}
    <table class="jo">
        <tr><td colspan="4" class="sec">Quality Check</td></tr>
        <tr>
            <td class="lbl" style="width: 25%;">Packaging</td>
            <td class="lbl" style="width: 25%;">Quality Checked By:</td>
            <td class="lbl" colspan="2">Notes from QC:</td>
        </tr>
        <tr>
            <td class="yellow">{{ strtoupper($y($jo?->packaging)) }}</td>
            <td class="ctr">{{ $who(['Quality control']) }}</td>
            <td colspan="2"></td>
        </tr>
        <tr>
            <td class="lbl">Agent</td>
            <td class="lbl">Artist</td>
            <td class="lbl">Supply Chain</td>
            <td class="lbl">Inventory Incharge</td>
        </tr>
        <tr>
            <td class="ctr">{{ $order->creator?->name ?? '' }}</td>
            <td class="ctr">{{ $artistName !== '—' ? $artistName : '' }}</td>
            <td class="ctr">{{ $who(['Raw materials']) }}</td>
            <td class="ctr">{{ $who(['Inventory']) }}</td>
        </tr>
    </table>

    {{-- SPECIAL INSTRUCTIONS --}}
    <table class="jo">
        <tr><td class="sec red" style="background: #fff; text-align: left; border-bottom: none;">Special Instructions / Notes from Agent</td></tr>
        <tr>
            <td style="min-height: 120px; white-space: pre-line; text-align: center; padding: 1.2rem; font-weight: 600;">{{ $jo?->special_instructions ?? $order->description }}</td>
        </tr>
    </table>

</div>
