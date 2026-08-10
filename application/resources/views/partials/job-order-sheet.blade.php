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

        /* Wherever it was dragged to is where it prints — that is the point of
           dragging it. The grab cursor and outline are screen-only. */
        .jo-mockup { cursor: default !important; }
        .jo-mockup:hover { outline: none !important; }
        .jo-mockup .jo-mockup-hint { display: none !important; }
    }

    /* A field that carries its own printed label — "Sewer:", "Thread Color:".
       White like the paper form; only the group headers above it are grey. */
    .jo .fld { background: #fff; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; }
    /* …and the value written into it, in normal case so a thread code or a
       sewer's name reads as it was typed. */
    .jo .fill { font-weight: 700; text-transform: none; color: #111; }

    .jo-footnote {
        text-align: center; padding: 0.5rem 0.25rem;
        font-weight: 800; font-size: 0.9rem; letter-spacing: .02em;
        color: #c0392b;
    }
    @media print { .jo-footnote { font-size: 0.68rem !important; padding: 0.25rem !important; } }

    .jo-mockup {
        position: absolute; top: 0; left: 0; right: 0;
        text-align: center; z-index: 1;
        cursor: grab; touch-action: none;
        transition: outline-color .12s;
        outline: 2px dashed transparent; outline-offset: 3px;
    }
    .jo-mockup:hover { outline-color: rgba(37, 99, 235, .45); }
    .jo-mockup.is-dragging { cursor: grabbing; outline-color: rgba(37, 99, 235, .8); z-index: 5; }
    .jo-mockup img {
        max-width: 60%; max-height: 220px; display: block; margin: 0 auto;
        user-select: none; -webkit-user-drag: none;
    }
    .jo-mockup-hint {
        position: absolute; left: 50%; transform: translateX(-50%);
        bottom: -1.15rem; white-space: nowrap;
        font-size: 0.62rem; font-weight: 600; letter-spacing: .04em;
        color: #2563eb; opacity: 0; transition: opacity .12s; pointer-events: none;
    }
    .jo-mockup:hover .jo-mockup-hint { opacity: 1; }
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
                        {{-- Draggable: the design sits over the description column
                             and can cover the very text somebody needs to read.
                             Drag it clear; double-click puts it back. --}}
                        <div class="jo-mockup" data-order="{{ $order->id }}"
                             data-save="{{ route('orders.mockup-offset', $order) }}"
                             style="transform: translate({{ (int) $order->mockup_offset_x }}px, {{ (int) $order->mockup_offset_y }}px);">
                            @foreach ($mockupFiles as $f)
                                @if ($f->isImage())
                                    <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label }}" draggable="false">
                                @endif
                            @endforeach
                            <span class="jo-mockup-hint">drag to move &middot; double-click to reset</span>
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
                        {{-- Draggable: the design sits over the description column
                             and can cover the very text somebody needs to read.
                             Drag it clear; double-click puts it back. --}}
                        <div class="jo-mockup" data-order="{{ $order->id }}"
                             data-save="{{ route('orders.mockup-offset', $order) }}"
                             style="transform: translate({{ (int) $order->mockup_offset_x }}px, {{ (int) $order->mockup_offset_y }}px);">
                            @foreach ($mockupFiles as $f)
                                @if ($f->isImage())
                                    <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label }}" draggable="false">
                                @endif
                            @endforeach
                            <span class="jo-mockup-hint">drag to move &middot; double-click to reset</span>
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
        {{-- No fabric press, add-on or embroidery row. All three are asked for
             and answered in the production details, and the paper form doesn't
             carry them — repeating them here only gave the two places somewhere
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
        {{-- Size on the two that are cut to a measurement, thread colour on the
             two that are stitched on. --}}
        <tr>
            <td class="fld">Size: <span class="fill">{{ strtoupper($y($jo?->neck_size)) }}</span></td>
            <td class="fld">Size: <span class="fill">{{ strtoupper($y($jo?->cuff_size)) }}</span></td>
            <td class="fld">Thread Color: <span class="fill">{{ strtoupper($y($jo?->neck_label_thread)) }}</span></td>
            <td class="fld">Thread Color: <span class="fill">{{ strtoupper($y($jo?->bottom_hem_thread)) }}</span></td>
        </tr>

        {{-- Each seam group names the sewer who ran it and the thread they used,
             so a fault found later can be traced back to the machine it came off. --}}
        <tr>
            <td class="lbl">Neckbond Shoulder</td>
            <td class="lbl">Top / Neck / Hangtag Woven</td>
            <td class="lbl">Flatbed</td>
            <td class="lbl">Close Side Body &amp; Sleeve</td>
        </tr>
        <tr>
            <td class="fld">Sewer: <span class="fill">{{ strtoupper($y($jo?->neckbond_sewer)) }}</span></td>
            <td class="fld">Sewer: <span class="fill">{{ strtoupper($y($jo?->hangtag_woven_sewer)) }}</span></td>
            <td class="fld">Sewer: <span class="fill">{{ strtoupper($y($jo?->flatbed_sewer)) }}</span></td>
            <td class="fld">Sewer: <span class="fill">{{ strtoupper($y($jo?->close_side_sewer)) }}</span></td>
        </tr>
        <tr>
            <td class="fld">Thread Code/Color: <span class="fill">{{ strtoupper($y($jo?->neckbond_thread)) }}</span></td>
            <td class="fld">Thread Code/Color: <span class="fill">{{ strtoupper($y($jo?->hangtag_woven_thread)) }}</span></td>
            <td class="fld">Thread Code/Color: <span class="fill">{{ strtoupper($y($jo?->flatbed_thread)) }}</span></td>
            <td class="fld">Thread Color: <span class="fill">{{ strtoupper($y($jo?->close_side_thread)) }}</span></td>
        </tr>

        <tr>
            <td class="lbl">Attached Sleeve / Cuffs</td>
            <td class="lbl">Topping Side / Sleeve</td>
            <td class="lbl">Pipping</td>
            {{-- The spare column, named on the form for whatever this garment
                 needed — blank on the paper version. --}}
            <td class="lbl">{{ strtoupper($y($jo?->extra_seam_label)) }}</td>
        </tr>
        <tr>
            <td class="fld">Sewer: <span class="fill">{{ strtoupper($y($jo?->attached_sleeve_sewer)) }}</span></td>
            <td class="fld">Sewer: <span class="fill">{{ strtoupper($y($jo?->topping_side_sewer)) }}</span></td>
            <td class="fld">Sewer: <span class="fill">{{ strtoupper($y($jo?->pipping_sewer)) }}</span></td>
            <td class="fld"><span class="fill">{{ strtoupper($y($jo?->extra_seam_note)) }}</span></td>
        </tr>
        <tr>
            <td class="fld">Thread Color: <span class="fill">{{ strtoupper($y($jo?->attached_sleeve_thread)) }}</span></td>
            <td class="fld">Thread Color: <span class="fill">{{ strtoupper($y($jo?->topping_side_thread)) }}</span></td>
            <td class="fld">Thread Color: <span class="fill">{{ strtoupper($y($jo?->pipping_thread)) }}</span></td>
            <td class="fld">Sewer: <span class="fill">{{ strtoupper($y($jo?->extra_seam_sewer)) }}</span></td>
        </tr>

        {{-- Whoever closed the sewing step, for the seams the form doesn't break
             out by name. --}}
        @if ($who(['Sewing']))
            <tr><td class="fld" colspan="4">Sewing Station: <span class="fill">{{ $who(['Sewing']) }}</span></td></tr>
        @endif

        <tr>
            <td class="fld red" colspan="4" style="text-align: left; white-space: pre-line;">Notes from Sewer: <span class="fill">{{ $y($jo?->sewer_notes) }}</span></td>
        </tr>
    </table>

    {{-- QUALITY CHECK --}}
    <table class="jo">
        <tr><td colspan="4" class="sec">Quality Check</td></tr>
        {{-- The checker's standing list — what "checked" is supposed to mean. --}}
        <tr>
            <td colspan="4" class="lbl-l red" style="text-align: left;">
                Quality Control: full mock up approved design / thread stiches / needle mark / wrinkle / stain / standard size / special instructions
            </td>
        </tr>
        <tr>
            <td class="lbl" style="width: 25%;">Packaging</td>
            <td class="lbl" style="width: 25%;">Quality Checked By:</td>
            <td class="lbl" colspan="2">Notes from QC:</td>
        </tr>
        <tr>
            <td class="yellow">{{ strtoupper($y($jo?->packaging)) }}</td>
            <td class="ctr">{{ $who(['Quality control']) }}</td>
            <td colspan="2" class="fld" style="text-align: left; white-space: pre-line;"><span class="fill">{{ $y($jo?->qc_notes) }}</span></td>
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

    {{-- Only in the package document, where the mockup really is the next page.
         On the standalone sheet the design is on this page already. --}}
    @unless ($showMockup)
        <div class="jo-footnote">FULL MOCK UP DESIGN — PLEASE CHECK THE NEXT PAGE!</div>
    @endunless

</div>

<script>
    /* Drag the design where you want it.
     *
     * It is positioned over the description column, which means on a busy sheet
     * it can cover the very lines somebody is trying to read -- and it prints
     * that way too. Dragging it is the fix, and where it is left is where it
     * prints.
     *
     * The position is saved on the ORDER, not in this browser, because the
     * person who moves it and the person who prints it are usually not at the
     * same machine. Double-click puts it back.
     */
    (function () {
        var token = document.querySelector('meta[name="csrf-token"]')?.content;

        document.querySelectorAll('.jo-mockup').forEach(function (box) {
            var startX = 0, startY = 0, baseX = 0, baseY = 0, dragging = false;

            function current() {
                var m = /translate\(([-\d.]+)px,\s*([-\d.]+)px\)/.exec(box.style.transform || '');
                return m ? { x: Math.round(parseFloat(m[1])), y: Math.round(parseFloat(m[2])) } : { x: 0, y: 0 };
            }

            function save(pos) {
                if (!box.dataset.save || !token) return;

                fetch(box.dataset.save, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(pos)
                }).catch(function () {
                    /* A failed save leaves it where it was dropped for this
                       viewer and back where it was for everyone else. Better
                       than an alert over a printed sheet. */
                });
            }

            box.addEventListener('pointerdown', function (e) {
                dragging = true;
                box.classList.add('is-dragging');
                box.setPointerCapture(e.pointerId);

                var pos = current();
                baseX = pos.x; baseY = pos.y;
                startX = e.clientX; startY = e.clientY;
                e.preventDefault();
            });

            box.addEventListener('pointermove', function (e) {
                if (!dragging) return;
                box.style.transform = 'translate(' + (baseX + (e.clientX - startX)) + 'px, '
                                                   + (baseY + (e.clientY - startY)) + 'px)';
            });

            function stop(e) {
                if (!dragging) return;
                dragging = false;
                box.classList.remove('is-dragging');
                try { box.releasePointerCapture(e.pointerId); } catch (err) { /* ignore */ }
                save(current());
            }

            box.addEventListener('pointerup', stop);
            box.addEventListener('pointercancel', stop);

            // Back to where it started, for when it has been dragged somewhere daft.
            box.addEventListener('dblclick', function () {
                box.style.transform = 'translate(0px, 0px)';
                save({ x: 0, y: 0 });
            });
        });
    })();
</script>
