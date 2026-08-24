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

    // Fields this viewer may type into, named as they are on the job order.
    // Passed in by the station's finish page so the sewer fills the sheet
    // itself rather than a separate list of the same questions underneath it.
    // Everywhere else this is empty and the sheet is read-only, as before.
    $editable = $editable ?? [];

    // At a station the tech pack is already on the page above this, so the
    // spec half of the sheet is the same answers twice — and twice as far to
    // scroll past to reach the seam you are writing. Record only: the sewing
    // grid and the quality check, which is what the floor fills in.
    $recordOnly = $recordOnly ?? false;

    // One filled-in box: the value as printed, or a box to type it into if
    // this viewer owns that field.
    $fill = function (string $field, bool $shout = true) use ($jo, $editable) {
        $value = (string) ($jo?->$field ?? '');

        if (! in_array($field, $editable, true)) {
            return '<span class="fill">'.e($shout ? strtoupper($value) : $value).'</span>';
        }

        if (str_contains($field, 'notes')) {
            return '<textarea class="fill-in" name="sheet['.$field.']" rows="2" maxlength="1000"'
                .' placeholder="Anything worth flagging">'.e($value).'</textarea>';
        }

        $list = str_contains($field, 'thread')
            ? ' list="dl_sheet_thread"'
            : (str_contains($field, 'sewer') ? ' list="dl_sheet_sewer"' : '');

        return '<input type="text" class="fill-in" name="sheet['.$field.']" maxlength="1000"'
            .' value="'.e($value).'"'.$list.' placeholder="'.chr(8212).'" autocomplete="off">';
    };

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
    /* The sheet's own look — the ruled boxes, the grey labels, the red dates.
       This lived in the two pages that happened to include this partial, so any
       new page including it got the markup with none of the ruling and the job
       order came out as a wall of plain text. It belongs here, with the sheet. */
    .jo-sheet { max-width: 900px; margin: 0 auto; background: #fff; color: #111; border: 2px solid #111; }
    .jo-sheet * { box-sizing: border-box; }
    .jo-title { text-align: center; padding: 0.6rem; border-bottom: 2px solid #111; }
    .jo-title .t1 { font-size: 1.6rem; font-weight: 800; letter-spacing: 0.02em; }
    .jo-title .t1 .pri { color: #d00; }
    .jo-title .t2 { font-size: 1.2rem; font-weight: 800; color: #d00; margin-top: 0.15rem; }
    table.jo { width: 100%; border-collapse: collapse; }
    table.jo td, table.jo th { border: 1px solid #111; padding: 0.3rem 0.5rem; font-size: 0.8rem; vertical-align: top; }
    .jo-sheet .lbl { background: #cfcfcf; font-weight: 700; text-align: center; font-size: 0.72rem; text-transform: uppercase; }
    .jo-sheet .lbl-l { background: #cfcfcf; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; }
    /* A filled-in value. White like the paper form — the yellow belongs on the
       entry form, where it means "still to type in". Here it is already typed
       in, and a printed sheet should look like the printed sheet. */
    .jo-sheet .yellow { background: #fff !important; font-weight: 700; text-align: center; }
    .jo-sheet .ctr { text-align: center; }
    .jo-sheet .red { color: #d00; font-weight: 700; }
    .jo-sheet .sec { background: #cfcfcf; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .jo-sheet .mock-box { min-height: 150px; text-align: center; }
    .jo-sheet .mock-box img { max-width: 100%; max-height: 260px; border: 1px solid #999; }

    /* Keep the whole sheet on ONE printed page: tighter rows, smaller type, and
       the app shell reset to block flow (page rules are ignored inside flex). */
    @media print {
        /* A NAMED page, not the document's. This partial is included under the
           tech pack, and its inline style loads after the pack's stylesheet —
           so setting the document default here turned the pack's landscape page
           portrait and printed it half a page wide. */
        @page record-page { size: A4 portrait; margin: 6mm; }
        .jo-sheet { page: record-page; }
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

    /* The same box, when the person looking at it is the one who fills it in.
       Yellow, because on this sheet yellow has always meant "still to write". */
    .jo .fill-in {
        display: block; width: 100%; margin-top: 0.15rem;
        background: #ffef00; color: #111;
        border: 1px solid #111; border-radius: 3px;
        padding: 0.25rem 0.35rem;
        font-size: 0.8rem; font-weight: 700; font-family: inherit;
        outline: none;
    }
    .jo textarea.fill-in { font-weight: 400; resize: vertical; min-height: 3rem; }
    .jo .fill-in::placeholder { color: #a09000; font-weight: 400; }
    .jo .fill-in:focus { box-shadow: 0 0 0 2px rgba(17, 17, 17, .35); }
    /* On paper it is just the value — no box, no yellow. */
    @media print {
        .jo .fill-in {
            background: #fff !important; border: none !important;
            padding: 0 !important; box-shadow: none !important;
        }
    }

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
    @unless ($recordOnly)
    {{-- Title --}}
    <div class="jo-title">
        <div class="t1">{{ $order->massprod_priority ? 'MASSPROD - ' : '' }}<span class="pri">{{ $order->massprod_priority ? 'PRIORITY' : 'JOB ORDER' }}</span></div>
        <div class="t2">JOB ORDER #&nbsp;&nbsp;{{ $order->order_number }}</div>
    </div>

    {{-- Header info --}}
    <table class="jo">
        <tr>
            <td class="lbl-l" style="width: 18%;">Client Name:</td>
            <td style="width: 32%;" class="ctr">{{ $order->clientName() }}</td>
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

    @endunless

    {{-- SEWING — who did what.

         Laid out the way the station types it: five slots across, what was
         done above the name of whoever did it. It was twenty-one boxes named
         after seams, each wanting a sewer and a thread code; every garment is
         different, so most printed blank and the ones that mattered were lost
         in the grid. --}}
    @php
        $slots = $jo?->sewingLog() ?? [];
        // The floor fills these in on their own page, and corrects them here.
        $logEditable = in_array('sewing_log', $editable, true);
    @endphp
    <table class="jo">
        <tr><td colspan="5" class="sec">Sewing</td></tr>
        <tr>
            @foreach ($slots as $row)
                <td class="lbl" style="width: 20%;">What was done</td>
            @endforeach
        </tr>
        <tr>
            @foreach ($slots as $i => $row)
                <td class="yellow">
                    @if ($logEditable)
                        <input type="text" class="fill-in" name="sheet[sewing_log][{{ $i }}][work]"
                               maxlength="255" value="{{ $row['work'] }}" list="dl_sheet_work"
                               placeholder="&mdash;" autocomplete="off">
                    @else
                        {{ strtoupper($row['work']) }}
                    @endif
                </td>
            @endforeach
        </tr>
        <tr>
            @foreach ($slots as $row)
                <td class="lbl">Who did it</td>
            @endforeach
        </tr>
        <tr>
            @foreach ($slots as $i => $row)
                <td class="yellow">
                    @if ($logEditable)
                        <input type="text" class="fill-in" name="sheet[sewing_log][{{ $i }}][name]"
                               maxlength="100" value="{{ $row['name'] }}" list="dl_sheet_sewer"
                               placeholder="&mdash;" autocomplete="off">
                    @else
                        {{ strtoupper($row['name']) }}
                    @endif
                </td>
            @endforeach
        </tr>
        <tr>
            <td colspan="5" class="fld" style="text-align: left;">
                Notes from sewer: {!! $fill('sewer_notes', false) !!}
            </td>
        </tr>
    </table>

    @unless ($recordOnly)
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
