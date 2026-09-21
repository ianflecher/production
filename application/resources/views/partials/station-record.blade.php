{{-- What the floor writes when it finishes a step.

     The sewing half of the job order sheet was twenty-one boxes named after
     seams — neckbond, flatbed, topping side, pipping — each wanting a sewer and
     a thread code. Every garment is different, so most were blank on most jobs
     and the ones that mattered were somewhere in the grid. Five slots instead:
     what was done, and who did it. Quality gets one of the same.

     Expects: $order, $station (key), $task (the step being finished, or null). --}}
@php
    $jo = $order->jobOrder;
    $isSewing = str_starts_with($station, 'sewing_');
    $isQc = str_starts_with($station, 'qc_');
    $log = $jo?->sewingLog() ?? [];
@endphp

<div class="rec-card">
    @if ($isSewing)
        {{-- The record is the garment's own list of operations now, with a
             name beside each. See partials/sewing-operations. --}}
        @if ($sewingSheet ?? null)
            @include('partials.sewing-operations', [
                'sheet' => $sewingSheet,
                'garment' => $sewingGarment,
                'rows' => $sewingRows,
                'canEdit' => $canEditSewingSheet ?? false,
            ])
        @endif
    @elseif ($isQc)
        <h3 class="rec-head">The quality check</h3>
        <p class="rec-hint">
            Mockup matched · thread stitches · needle marks · wrinkles · stains ·
            standard size · special instructions.
        </p>

        <div class="rec-grid rec-grid-one">
            <div class="rec-slot">
                <span class="rec-num">✓</span>
                <input type="text" name="sheet[qc_notes]" maxlength="1000"
                       value="{{ $jo?->qc_notes }}" placeholder="What you checked, and anything you found"
                       autocomplete="off">
                <input type="text" name="sheet[qc_checked_by]" maxlength="100"
                       value="{{ $jo?->qc_checked_by }}" placeholder="Your name"
                       list="dl_sheet_sewer" autocomplete="off">
            </div>
        </div>
    @endif

    @if ($isSewing)
        {{-- The sewer's own line, the same box the sheet prints. --}}
        <label class="rec-note">
            <span>Notes from the sewer <em>(optional)</em></span>
            <textarea name="sheet[sewer_notes]" rows="2" maxlength="1000"
                      placeholder="Anything about the sewing itself">{{ $jo?->sewer_notes }}</textarea>
        </label>
    @endif

    {{-- Every step gets one, sewing and quality included: what happened here
         that the boxes above have no room for. --}}
    <label class="rec-note">
        <span>Notes on this step <em>(optional)</em></span>
        <textarea name="task_note" rows="2" maxlength="1000"
                  placeholder="Anything worth flagging — a machine down, a material short, a change you had to make">{{ $task?->note }}</textarea>
    </label>
</div>
