@extends('layouts.app')

@section('title', 'Job Order — '.$order->order_number)
@section('page-title', 'Job Order — '.$order->order_number)

@section('content')
@php
    $artistName = optional($order->tasks->first(fn ($t) => $t->team === \App\Models\User::JOB_ARTIST && $t->assignee))->assignee?->name;
    $old = fn ($k, $v) => old($k, $v);
@endphp

<style>
    .jo-sheet { max-width: 900px; margin: 0 auto; background: #fff; color: #111; border: 2px solid #111; }
    .jo-sheet * { box-sizing: border-box; }
    .jo-title { text-align: center; padding: 0.6rem; border-bottom: 2px solid #111; }
    .jo-title .t1 { font-size: 1.5rem; font-weight: 800; }
    .jo-title .t1 .pri { color: #d00; }
    .jo-title .t2 { font-size: 1.15rem; font-weight: 800; color: #d00; margin-top: 0.15rem; }
    table.jo { width: 100%; border-collapse: collapse; }
    table.jo td { border: 1px solid #111; padding: 0.3rem 0.5rem; font-size: 0.8rem; vertical-align: middle; }
    .lbl { background: #cfcfcf; font-weight: 700; text-align: center; font-size: 0.72rem; text-transform: uppercase; }
    .lbl-l { background: #cfcfcf; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; }
    .yellow { background: #ffef00 !important; padding: 0 !important; }
    .yellow input, .yellow select, .yellow textarea {
        width: 100%; border: none; background: #ffef00; font-weight: 700; text-align: center;
        font-size: 0.8rem; text-transform: uppercase; padding: 0.4rem 0.3rem; outline: none;
    }
    .yellow textarea { text-transform: none; text-align: left; resize: vertical; min-height: 90px; }
    /* A field that carries its own printed label — "Sewer:", "Thread Color:".
       White like the paper form; only the group headers above it are grey. */
    .fld { background: #fff; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; }
    /* The spare seam column: nothing on it is preprinted, so the whole column
       is fill-in and the cell itself is yellow. */
    .fld-extra { background: #ffef00; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; }

    /* The box you type into, sitting under (or inside) its label rather than
       filling its own cell — the sewing block is twenty of these, and a
       full-width cell each would double the height of the sheet. Yellow like
       every other fill-in box, including the one in the grey header cell where
       the spare seam is named. */
    .jo-sheet .inline {
        border: 1px solid #111; border-radius: 3px;
        background: #ffef00; color: #111; font-weight: 700;
        font-size: 0.75rem; text-transform: uppercase;
        padding: 0.2rem 0.35rem; outline: none;
        width: 100%; max-width: 100%; margin-top: 0.15rem;
    }
    .jo-sheet textarea.inline { text-transform: none; resize: vertical; }
    .jo-sheet .inline::placeholder { color: #a09000; font-weight: 400; }
    .jo-sheet .inline:focus { box-shadow: 0 0 0 2px rgba(17, 17, 17, .35); }
    /* A box this form does not own. The sewer and the checker fill their own
       parts of the sheet at the station, where they are holding the garment —
       the account officer cannot know them weeks earlier. */
    .at-station {
        font-weight: 400; font-size: 0.68rem; text-transform: none;
        color: #777; font-style: italic;
    }
    .ctr { text-align: center; }
    .red { color: #d00; font-weight: 700; }
    .sec { background: #cfcfcf; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; }
    .mock-box { min-height: 130px; text-align: center; color: #999; font-size: 0.78rem; }
    .jo-bar { max-width: 900px; margin: 0 auto 1rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
</style>

<div class="jo-bar">
    <div>
        <h1 style="margin:0;">Fill up the job order</h1>
        <p class="muted" style="margin:0;">Type into the <span style="background:#ffef00; padding:0 0.3rem; font-weight:700;">yellow</span> boxes. Raw materials, cutting &amp; the client reference are on the next page.</p>
    </div>
    <a href="{{ route('orders.show', $order) }}" class="btn btn-ghost btn-sm">← Back to order</a>
</div>

@if ($errors->any())
    <div class="alert-error" style="max-width: 900px; margin: 0 auto 1rem;">
        @foreach ($errors->all() as $e){{ $e }}<br>@endforeach
    </div>
@endif

<form method="POST" action="{{ route('job-orders.update', $order) }}">
    @csrf
    <div class="jo-sheet">
        <div class="jo-title">
            <div class="t1">{{ $order->massprod_priority ? 'MASSPROD - ' : '' }}<span class="pri">{{ $order->massprod_priority ? 'PRIORITY' : 'JOB ORDER' }}</span></div>
            <div class="t2">JOB ORDER #&nbsp;&nbsp;{{ $order->order_number }}</div>
        </div>

        {{-- Header — auto-filled --}}
        <table class="jo">
            <tr>
                <td class="lbl-l" style="width:18%;">Client Name:</td>
                <td class="ctr" style="width:32%;">{{ $order->client?->name ?? $order->customer_name }}</td>
                <td class="lbl-l" style="width:18%;">Date Ordered:</td>
                <td class="ctr red" style="width:32%;">{{ $order->created_at->format('n/j/Y') }}</td>
            </tr>
            <tr>
                <td class="lbl-l">FB/Viber/GC Name:</td>
                <td class="yellow"><input type="text" name="fb_viber_gc" list="dl_fb_viber_gc" maxlength="255" value="{{ old('fb_viber_gc', $jobOrder->fb_viber_gc) }}" placeholder="FB / Viber / GC name"></td>
                <td class="lbl-l">Delivery Date:</td>
                <td class="ctr red">{{ $order->due_date?->format('m/j/Y') ?? '—' }}</td>
            </tr>
            <tr>
                <td class="lbl-l">Type of Apparel:</td>
                <td class="ctr" style="font-weight:700;">{{ strtoupper($order->productLabel() ?? '—') }}</td>
                <td class="lbl-l">Account Officer:</td>
                <td class="ctr red">{{ strtoupper($order->creator?->name ?? '—') }}</td>
            </tr>
            <tr>
                <td class="lbl-l">Artist Name:</td>
                <td class="ctr" style="font-weight:700;">{{ strtoupper($artistName ?? '—') }}</td>
                <td class="lbl-l">Team:</td>
                <td class="ctr" style="font-weight:700;">{{ strtoupper($order->creator?->teamLabel() ?? '—') }}</td>
            </tr>
        </table>

        {{-- Each description lines up with its own size / quantity row. --}}
        <table class="jo">
            <tr>
                <td class="lbl" style="width:50%;">Description</td>
                <td class="lbl" style="width:25%;">Size</td>
                <td class="lbl" style="width:25%;">Quantity</td>
            </tr>
            @php $items = $order->itemsInSizeOrder(); @endphp
            @forelse ($items as $item)
                <tr>
                    <td class="yellow" style="padding:0;">
                        <input type="text" name="item_desc[{{ $item->id }}]" maxlength="255"
                               value="{{ old('item_desc.'.$item->id, $item->description) }}"
                               placeholder="MAMANGUN SHIRT"
                               style="width:100%; text-align:right; font-weight:700; background:transparent; border:none; padding:0.3rem 0.5rem;">
                    </td>
                    <td class="ctr">{{ $item->size }}</td>
                    <td class="ctr">{{ $item->quantity }}</td>
                </tr>
            @empty
                <tr><td class="yellow"></td><td class="ctr">—</td><td class="ctr"></td></tr>
            @endforelse
            <tr><td></td><td class="lbl-l" style="text-align:right;">TOTAL</td><td class="ctr" style="font-weight:800;">{{ $order->quantity }}</td></tr>
        </table>

        {{-- PRODUCTION --}}
        <table class="jo">
            <tr><td colspan="4" class="sec">Production</td></tr>
            <tr>
                <td class="lbl" style="width:25%;">Print Type</td>
                <td class="lbl" style="width:25%;">Printer</td>
                <td class="lbl" style="width:25%;">Fabric</td>
                <td class="lbl" style="width:25%;">Free Logo Sticker</td>
            </tr>
            <tr>
                <td class="yellow">
                    <input type="text" name="print_type" id="printType" list="printTypeList" required maxlength="255"
                        value="{{ $old('print_type', $jobOrder->printTypeLabel()) }}" placeholder="FULL SUBLIMATION" autocomplete="off">
                    <datalist id="printTypeList">
                        @foreach (\App\Models\JobOrder::PRINT_TYPES as $pt)<option value="{{ $pt['label'] }}"></option>@endforeach
                        @foreach (($suggest['print_type'] ?? []) as $v)<option value="{{ $v }}"></option>@endforeach
                    </datalist>
                </td>
                <td class="yellow">
                    <select name="printer" id="printer" required>
                        <option value="">— printer —</option>
                        @foreach (\App\Models\JobOrder::PRINTERS as $k => $l)<option value="{{ $k }}" @selected($old('printer', $jobOrder->printer) === $k)>{{ strtoupper($l) }}</option>@endforeach
                    </select>
                </td>
                <td class="yellow"><input type="text" name="fabric" list="dl_fabric" required maxlength="255" value="{{ $old('fabric', $jobOrder->fabric) }}" placeholder="AIRCOOL"></td>
                <td class="yellow"><input type="text" name="free_logo_sticker" list="dl_free_logo_sticker" maxlength="255" value="{{ $old('free_logo_sticker', $jobOrder->free_logo_sticker) }}" placeholder="IC STICKER"></td>
            </tr>
            <tr><td class="lbl-l">Printer Operator:</td><td colspan="3"></td></tr>
            <tr><td class="lbl-l">Press Operator:</td><td colspan="3"></td></tr>
            <tr><td class="lbl-l">Lazer Cutter Operator:</td><td colspan="3"></td></tr>
            <tr><td class="lbl-l">Pairing:</td><td colspan="3"></td></tr>
            <tr><td class="lbl-l">Mover:</td><td colspan="3"></td></tr>
        </table>

        {{-- SEWING --}}
        <table class="jo">
            <tr><td colspan="4" class="sec">Sewing</td></tr>
            <tr>
                <td class="lbl" style="width:25%;">Neck</td>
                <td class="lbl" style="width:25%;">Cuff / Arm Sleeves</td>
                <td class="lbl" style="width:25%;">Neck Label</td>
                <td class="lbl" style="width:25%;">Bottom Hem</td>
            </tr>
            <tr>
                <td class="yellow"><input type="text" name="neck" list="dl_neck" maxlength="255" value="{{ $old('neck', $jobOrder->neck) }}" placeholder="PRINTED RIBBINGS"></td>
                <td class="yellow"><input type="text" name="cuff_arm_sleeves" list="dl_cuff_arm_sleeves" maxlength="255" value="{{ $old('cuff_arm_sleeves', $jobOrder->cuff_arm_sleeves) }}" placeholder="TUPI"></td>
                <td class="yellow"><input type="text" name="neck_label" list="dl_neck_label" maxlength="255" value="{{ $old('neck_label', $jobOrder->neck_label) }}" placeholder="IC FLAT BED"></td>
                <td class="yellow"><input type="text" name="bottom_hem" list="dl_bottom_hem" maxlength="255" value="{{ $old('bottom_hem', $jobOrder->bottom_hem) }}" placeholder="STRAIGHT"></td>
            </tr>
            {{-- Size on the two cut to a measurement, thread colour on the two
                 stitched on — same as the paper form. --}}
            <tr>
                <td class="fld">Size: <input type="text" name="neck_size" list="dl_neck_size" maxlength="255" value="{{ $old('neck_size', $jobOrder->neck_size) }}" class="inline" placeholder="—"></td>
                <td class="fld">Size: <input type="text" name="cuff_size" list="dl_cuff_size" maxlength="255" value="{{ $old('cuff_size', $jobOrder->cuff_size) }}" class="inline" placeholder="—"></td>
                <td class="fld">Thread Color: <span class="at-station">filled at sewing</span></td>
                <td class="fld">Thread Color: <span class="at-station">filled at sewing</span></td>
            </tr>

            {{-- Each seam group: who sewed it and with what. --}}
            @php
                $seamGroups = [
                    [
                        ['Neckbond Shoulder', 'neckbond', 'Thread Code/Color'],
                        ['Top / Neck / Hangtag Woven', 'hangtag_woven', 'Thread Code/Color'],
                        ['Flatbed', 'flatbed', 'Thread Code/Color'],
                        ['Close Side Body & Sleeve', 'close_side', 'Thread Color'],
                    ],
                    [
                        ['Attached Sleeve / Cuffs', 'attached_sleeve', 'Thread Color'],
                        ['Topping Side / Sleeve', 'topping_side', 'Thread Color'],
                        ['Pipping', 'pipping', 'Thread Color'],
                        'extra',   // the spare column — name it yourself
                    ],
                ];
            @endphp
            @foreach ($seamGroups as $group)
                {{-- Header: printed for the known seams, typed for the spare one. --}}
                <tr>
                    @foreach ($group as $seam)
                        @if ($seam === 'extra')
                            <td class="fld-extra" style="padding: 0.15rem;">
                                <input type="text" name="extra_seam_label" maxlength="255"
                                       value="{{ $old('extra_seam_label', $jobOrder->extra_seam_label) }}"
                                       class="inline" placeholder="OTHER SEAM…" style="text-align: center;">
                            </td>
                        @else
                            <td class="lbl">{{ $seam[0] }}</td>
                        @endif
                    @endforeach
                </tr>
                <tr>
                    @foreach ($group as $seam)
                        <td class="{{ $seam === 'extra' ? 'fld-extra' : 'fld' }}">
                            @if ($seam === 'extra')
                                <span class="at-station">filled at sewing</span>
                            @else
                                Sewer: <span class="at-station">filled at sewing</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
                <tr>
                    @foreach ($group as $seam)
                        <td class="{{ $seam === 'extra' ? 'fld-extra' : 'fld' }}">
                            @if ($seam === 'extra')
                                Sewer: <span class="at-station">filled at sewing</span>
                            @else
                                {{ $seam[2] }}: <span class="at-station">filled at sewing</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach

            {{-- No IC Woven / Tag Placement row. The paper form doesn't have one,
                 and the Top/Neck/Hangtag Woven column above now covers it. The
                 column stays in the database so old orders keep what they had. --}}
            <tr>
                <td class="fld red" colspan="4" style="text-align:left;">
                    Notes from Sewer: <span class="at-station">filled at sewing</span>
                </td>
            </tr>
        </table>

        {{-- QUALITY CHECK --}}
        <table class="jo">
            <tr><td colspan="4" class="sec">Quality Check</td></tr>
            <tr>
                <td class="lbl" style="width:25%;">Packaging</td>
                <td class="lbl" style="width:25%;">Quality Checked By:</td>
                <td class="lbl" colspan="2">Notes from QC:</td>
            </tr>
            <tr>
                <td class="yellow"><input type="text" name="packaging" list="dl_packaging" maxlength="255" value="{{ $old('packaging', $jobOrder->packaging) }}" placeholder="ORDINARY"></td>
                <td></td>
                <td colspan="2"></td>
            </tr>
            <tr>
                <td class="lbl">Agent</td><td class="lbl">Artist</td><td class="lbl">Supply Chain</td><td class="lbl">Inventory Incharge</td>
            </tr>
            <tr>
                <td class="ctr">{{ $order->creator?->name ?? '' }}</td>
                <td class="ctr">{{ $artistName ?? '' }}</td>
                <td></td><td></td>
            </tr>
        </table>

        {{-- SPECIAL INSTRUCTIONS --}}
        <table class="jo">
            <tr><td class="sec red" style="background:#fff; text-align:left;">Special Instructions / Notes from Agent</td></tr>
            <tr>
                <td class="yellow"><textarea name="special_instructions" maxlength="5000" placeholder="WALA NA PONG SAMPLE&#10;WITH NAMES PO SA LEFT SLEEVE&#10;WITH NUMBERS SA LOWER BACK">{{ $old('special_instructions', $jobOrder->special_instructions) }}</textarea></td>
            </tr>
        </table>
    </div>

    <div class="jo-bar" style="margin-top: 1rem;">
        <span></span>
        <button type="submit" class="btn btn-primary">Save &amp; next: production details →</button>
    </div>
</form>

{{-- Autocomplete: past entries for each field, so you type once then pick next time. --}}
@foreach (['fb_viber_gc', 'fabric', 'free_logo_sticker', 'neck', 'neck_size', 'cuff_arm_sleeves', 'cuff_size', 'neck_label', 'bottom_hem', 'packaging', 'sewer', 'thread'] as $field)
    <datalist id="dl_{{ $field }}">
        @foreach (($suggest[$field] ?? []) as $v)<option value="{{ $v }}"></option>@endforeach
    </datalist>
@endforeach

<script>
    // Printer defaults from a known print type, but you can override it.
    const PRINT_TYPE_DEFAULTS = @json(collect(\App\Models\JobOrder::PRINT_TYPES)
        ->mapWithKeys(fn ($pt) => [strtolower($pt['label']) => $pt['printer']]));
    (function () {
        const pt = document.getElementById('printType');
        const printerEl = document.getElementById('printer');
        if (!pt || !printerEl) return;
        let locked = !!printerEl.value;
        printerEl.addEventListener('change', () => { locked = true; });
        pt.addEventListener('input', function () {
            const p = PRINT_TYPE_DEFAULTS[pt.value.trim().toLowerCase()];
            if (p && !locked) printerEl.value = p;
        });
    })();
</script>
@endsection
