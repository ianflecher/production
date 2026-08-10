@extends('layouts.app')

@section('title', 'Stations — Imprint Production')
@section('page-title', 'Stations')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Station board</h1>
        <p class="muted">Who is on each machine right now. Log breaks and shift changes so every run is accounted for.</p>
    </div>
</div>

@if ($errors->any())
    <div class="alert-error" style="margin-bottom:1rem;">
        @foreach ($errors->all() as $e){{ $e }}<br>@endforeach
    </div>
@endif

@php
    // Each station group gets its own accent colour so the board reads at a glance.
    $groupColors = [
        'Supply' => '#0d9488',
        'Printing' => '#2563eb',
        'Add-ons' => '#7c3aed',
        'Cutting' => '#d97706',
        'Production Line' => '#e31b23',
    ];
@endphp
@foreach ($groups as $group => $stations)
    @php $gc = $groupColors[$group] ?? '#2563eb'; @endphp
    <h2 style="font-size:1rem; margin:0 0 0.75rem; display:flex; align-items:center; gap:0.5rem;
               padding:0.5rem 0.9rem; border-radius:11px; color:{{ $gc }}; font-weight:700;
               background:linear-gradient(90deg, color-mix(in srgb, {{ $gc }} 16%, #fff), color-mix(in srgb, {{ $gc }} 4%, #fff));
               border:1px solid color-mix(in srgb, {{ $gc }} 28%, #fff); border-left:5px solid {{ $gc }};">
        {{ $group }}
    </h2>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(310px, 1fr)); gap:1rem; margin-bottom:1.6rem;">
        @foreach ($stations as $p)
            @php
                $s = $p['session'];
                // A station holding a job past its date gets a red edge, so a late
                // machine stands out from the grid without reading a word.
                $lateHere = collect($p['orders'])->contains(fn ($o) => $o->delayState() === 'delayed');
                $edge = $lateHere
                    ? 'var(--danger-ink, #dc2626)'
                    : ($s ? 'var(--success-ink)' : 'color-mix(in srgb, '.$gc.' 45%, #fff)');
            @endphp
            <div class="card panel" style="border-left:4px solid {{ $edge }};">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem; flex-wrap:wrap;">
                    <h2 style="font-size:0.95rem; margin:0;">{{ $p['label'] }}</h2>
                    @if ($s)
                        <span class="badge" style="background:#f0fdf4; color:#15803d;">RUNNING</span>
                    @else
                        <span class="badge" style="background:#f1f5f9; color:#64748b;">IDLE</span>
                    @endif

                    {{-- Work waiting on this machine, visible without opening the form. --}}
                    @php
                        $waiting = $p['orders']->count();
                        $lateCount = collect($p['orders'])->filter(fn ($o) => $o->delayState() === 'delayed')->count();
                    @endphp
                    @if ($lateCount > 0)
                        <span class="delay-chip is-late">
                            <span class="delay-alert-dot" aria-hidden="true"></span>
                            {{ $lateCount }} LATE
                        </span>
                    @endif
                    @if ($waiting > 0)
                        <span class="badge" style="background:#fff7ed; color:#c2410c; border:1px solid #fed7aa;">
                            <span class="dot"></span>{{ $waiting }} TO {{ $p['key'] === 'sticker' ? 'PRINT' : ($group === 'Printing' ? 'PRINT' : 'DO') }}
                        </span>
                    @endif
                </div>

                @if ($p['orders']->isNotEmpty())
                    {{-- Garments move through the line one at a time, so a piece
                         count is meaningless on most stations. The exceptions are
                         stickers (printed as a batch) and mass production, which
                         is the rest of the order after the approved sample. --}}
                    <div style="margin-top:0.5rem; font-size:0.78rem; color:var(--ink-2); line-height:1.5;">
                        {{-- One page of the queue, late jobs first. Sorted and paged
                             by the controller so the order survives paging. --}}
                        @php $queue = $p['queue']; @endphp
                        @foreach ($queue as $o)
                            @php $step = $o->station_step ?? null; @endphp
                            <div @class(['station-job', 'is-late' => $o->delayState()])>
                                <strong>{{ $o->order_number }}</strong>

                                {{-- The floor is who can actually do something about
                                     a late job, so the warning belongs here too. --}}
                                @if ($delay = $o->delayState())
                                    <span class="delay-chip {{ $delay === 'delayed' ? 'is-late' : 'is-at-risk' }}">
                                        <span class="delay-alert-dot" aria-hidden="true"></span>
                                        {{ $delay === 'delayed' ? 'DELAYED' : 'DUE TODAY' }}
                                    </span>
                                @endif

                                @if ($p['key'] === 'sticker')
                                    · <span style="font-weight:700;">{{ number_format($o->quantity) }} pcs</span>
                                    · {{ $o->jobOrder?->free_logo_sticker ?: '—' }}
                                @elseif ($step === 'Mass production')
                                    {{-- Garments are run one at a time, so no batch count. --}}
                                    · <span style="font-weight:700;">the rest of the batch</span>
                                @elseif ($step === 'Produce sample for client')
                                    · <span style="font-weight:700; color:var(--accent);">first sample</span>
                                @endif

                                @if (filled($o->jobOrder?->addon_note))
                                    <div style="color:var(--ink-2); font-weight:600;">🧵 {{ $o->jobOrder->addon_note }}</div>
                                @endif

                                {{-- Only what this station needs: the job order plus
                                     its own file (TIFF / sticker / embroidery) or the
                                     production details. --}}
                                <a href="{{ route('orders.package', [$o, 'for' => \App\Services\Stations::scope($p['key'])]) }}" style="margin-left:0.3rem; font-size:0.73rem;">📄 open work sheet</a>
                            </div>
                        @endforeach

                        @if ($queue->hasPages())
                            <div class="station-pager">
                                <span>Showing {{ $queue->firstItem() }}–{{ $queue->lastItem() }} of {{ number_format($queue->total()) }}</span>
                                {{ $queue->links() }}
                            </div>
                        @endif
                    </div>
                @endif

                @if ($s)
                    <p style="margin:0.5rem 0 0.2rem; font-weight:600;">👤 {{ $s->operator() }}</p>
                    <p style="font-size:0.82rem; color:var(--ink-3); margin:0;">
                        Since {{ $s->started_at->format('M j, g:i A') }} · {{ $s->duration() }}
                        @if ($s->order) · <a href="{{ route('orders.show', $s->order) }}">{{ $s->order->order_number }}</a> @endif
                    </p>
                    @if ($s->note)<p style="font-size:0.78rem; color:var(--ink-3); margin:0.3rem 0 0;">{{ $s->note }}</p>@endif

                    {{-- Finishing means the work on this station is DONE — it closes
                         the step and moves the order on. Breaks and shift changes
                         are recorded on "Take over" instead. --}}
                    <form method="POST" action="{{ route('stations.end', $s) }}"
                          onsubmit="return confirm('Finished {{ $s->order?->order_number ?? 'this job' }} on {{ addslashes($p['label']) }}?\n\nThis closes the step and moves the order to the next one.');"
                          style="margin-top:0.8rem; display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                        @csrf
                        <input type="hidden" name="end_reason" value="done">
                        <input type="text" name="note" maxlength="255" placeholder="Note (optional)" style="flex:1; min-width:110px; padding:0.3rem 0.5rem; font-size:0.82rem;">
                        <button class="btn btn-success btn-sm">✓ Finish</button>

                        {{-- This station's part of the job order sheet, asked of
                             the person holding the garment. Folded away so the
                             board stays a board; everything in it is optional,
                             and a box left alone keeps whatever an earlier shift
                             wrote. --}}
                        @php $sheetFields = \App\Http\Controllers\StationController::sheetFieldsFor($p['key']); @endphp
                        @if ($sheetFields !== [] && $s->order?->jobOrder)
                            @php $sjo = $s->order->jobOrder; @endphp
                            <details class="station-sheet" style="flex-basis:100%; margin-top:0.5rem;">
                                <summary style="cursor:pointer; font-size:0.8rem; font-weight:700; color:var(--accent);">
                                    ✎ Fill the job order sheet
                                    ({{ str_starts_with($p['key'], 'qc_') ? 'notes from QC' : 'sewers &amp; threads' }})
                                </summary>
                                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:0.4rem; margin-top:0.5rem;">
                                    @foreach ($sheetFields as $f)
                                        <label style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--ink-2);">
                                            {{ \App\Http\Controllers\StationController::sheetFieldLabel($f) }}
                                            <input type="text" name="sheet[{{ $f }}]" maxlength="1000"
                                                   value="{{ $sjo->$f }}"
                                                   list="{{ str_contains($f, 'thread') ? 'dl_station_thread' : (str_contains($f, 'sewer') ? 'dl_station_sewer' : '') }}"
                                                   style="width:100%; padding:0.25rem 0.4rem; font-size:0.8rem; font-weight:400; text-transform:none;">
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </form>
                @else
                    <p class="muted" style="margin:0.5rem 0 0; font-size:0.85rem;">Nobody on this station.</p>
                @endif

                {{-- Taking over automatically hands it off the current operator. --}}
                <details class="inline-form" style="margin-top:0.7rem;">
                    <summary class="btn {{ $s ? 'btn-ghost' : 'btn-primary' }} btn-sm">{{ $s ? 'Take over' : 'Start on this station' }}</summary>
                    <div class="pop">
                        <form method="POST" action="{{ route('stations.start') }}">
                            @csrf
                            <input type="hidden" name="station" value="{{ $p['key'] }}">

                            @if ($s)
                                {{-- Handing over: record why the current operator is stepping off. --}}
                                <label>Why is {{ $s->operator() }} coming off? <span style="color: var(--danger-ink);">*</span></label>
                                <select name="handover_reason" required style="margin-bottom:0.5rem;">
                                    <option value="shift_change">Shift change</option>
                                    <option value="break">On break</option>
                                </select>
                            @endif

                            <label>Who is running it {{ $s ? 'now' : '' }}? <span style="color: var(--danger-ink);">*</span></label>
                            <input type="text" name="operator_name" maxlength="100" required
                                   placeholder="e.g. {{ auth()->user()->name }}">

                            {{-- Only job orders the leader has released to this station. --}}
                            <label style="margin-top:0.5rem;">Job order <span style="color: var(--danger-ink);">*</span></label>
                            @if ($s && $s->order)
                                {{-- A take-over is a shift change on the SAME run, so the
                                     job order carries over instead of being picked again. --}}
                                <input type="hidden" name="production_order_id" value="{{ $s->order->id }}">
                                <div style="font-size:0.82rem; font-weight:600; padding:0.35rem 0.5rem; background:var(--bg); border:1px solid var(--border); border-radius:8px;">
                                    {{ $s->order->order_number }} — {{ $s->order->customer_name }}@if ($p['key'] === 'sticker') · {{ number_format($s->order->quantity) }} pcs @endif
                                </div>
                                <div style="font-size:0.72rem; color:var(--ink-3); margin-top:0.25rem;">
                                    Continuing the run already on this station. To switch jobs, come off first.
                                </div>
                            @elseif ($p['orders']->isEmpty())
                                <div style="font-size:0.78rem; color:var(--danger-ink); font-weight:600; padding:0.3rem 0;">
                                    No job order has reached this station yet.
                                </div>
                            @else
                                {{-- The list follows the queue above it, page for page.
                                     Offering every waiting job put thousands of options
                                     on this page and left the operator scrolling a
                                     dropdown hundreds long to find the job in front
                                     of them. Late work sorts first, so what they want
                                     is normally on the first page; if not, turning the
                                     page moves this list with it. --}}
                                <select name="production_order_id" required>
                                    <option value="">— Choose job order —</option>
                                    @foreach ($p['queue'] as $o)
                                        <option value="{{ $o->id }}">
                                            {{ $o->order_number }} — {{ $o->customer_name }}@if ($p['key'] === 'sticker') · {{ number_format($o->quantity) }} pcs @endif
                                        </option>
                                    @endforeach
                                </select>

                                @if ($p['queue']->hasPages())
                                    <div style="font-size:0.7rem; color:var(--ink-3); margin-top:0.25rem;">
                                        Showing {{ $p['queue']->firstItem() }}–{{ $p['queue']->lastItem() }}
                                        of {{ number_format($p['queue']->total()) }} waiting · turn the page above for the rest.
                                    </div>
                                @endif
                            @endif

                            <label style="margin-top:0.5rem;">Note (optional)</label>
                            <input type="text" name="note" maxlength="255" placeholder="e.g. continuing the night run">

                            <button class="btn btn-primary btn-sm" style="margin-top:0.6rem;"
                                    @disabled(! ($s && $s->order) && $p['orders']->isEmpty())>
                                {{ $s ? 'Take over from '.$s->operator() : 'Start' }}
                            </button>
                        </form>
                    </div>
                </details>

            </div>
        @endforeach
    </div>

    {{-- One handover log per station type — Printing, Cutting, Production Line… --}}
    @php $groupHistory = $historyByGroup[$group] ?? collect(); @endphp
    <div class="card panel" style="margin-bottom:1.8rem;">
        <h2>{{ $group }} — handover log</h2>
        <p class="sub">Every stint on a {{ strtolower($group) }} station — who ran it, on what, and why they came off.</p>

        @if ($groupHistory->isEmpty())
            <p class="muted" style="text-align:center; padding:1.2rem;">Nothing recorded yet.</p>
        @else
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead>
                        <tr><th>Station</th><th>Operator</th><th>Job order</th><th>Started</th><th>Ended</th><th>For</th><th>Came off</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($groupHistory->take(25) as $h)
                            <tr>
                                <td style="font-weight:600;">{{ $h->stationLabel() }}</td>
                                <td>
                                    {{ $h->operator() }}
                                </td>
                                <td>
                                    @if ($h->order)<a href="{{ route('orders.show', $h->order) }}">{{ $h->order->order_number }}</a>@else — @endif
                                </td>
                                <td style="font-size:0.8rem; color:var(--ink-3); white-space:nowrap;">{{ $h->started_at->format('M j, g:i A') }}</td>
                                <td style="font-size:0.8rem; color:var(--ink-3); white-space:nowrap;">{{ $h->ended_at?->format('M j, g:i A') ?? '—' }}</td>
                                <td style="font-size:0.8rem;">{{ $h->duration() }}</td>
                                <td style="font-size:0.82rem;">
                                    @if ($h->isRunning())
                                        <span style="color:var(--success-ink); font-weight:600;">still running</span>
                                    @else
                                        {{ $h->reasonLabel() }}
                                    @endif
                                    @if ($h->note)<div style="font-size:0.76rem; color:var(--ink-3);">{{ $h->note }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endforeach
{{-- Shared by every sewing station's sheet form: the same handful of people
     work every seam and the same thread codes go through all of them. --}}
<datalist id="dl_station_sewer">
    @foreach (($sewerNames ?? []) as $n)<option value="{{ $n }}"></option>@endforeach
</datalist>
<datalist id="dl_station_thread">
    @foreach (($threadCodes ?? []) as $t)<option value="{{ $t }}"></option>@endforeach
</datalist>
@endsection
