@extends('layouts.app')

@section('title', 'Cut-off — Imprint Production')
@section('page-title', 'Cut-off')

@section('content')
@php
    $from = \Illuminate\Support\Carbon::parse($start);
    $to = \Illuminate\Support\Carbon::parse($end);
    $unreleased = \App\Models\HrPayslip::whereNull('released_at')
        ->whereDate('period_start', $start)->whereDate('period_end', $end)->count();
@endphp

<div class="page-head">
    <div class="grow">
        <h1>Cut-off</h1>
        <p class="muted">
            Everybody's payslip for one period, worked out the same way a single
            one is. Nothing is written until you run it.
        </p>
    </div>
    <a href="{{ route('hr.employees.index') }}" class="btn btn-ghost btn-sm">← All people</a>
</div>

{{-- ---------- Which period ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>Which period</h2>

    <form method="GET" action="{{ route('hr.payroll.index') }}"
          style="display:flex; gap:.6rem; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:0 1 170px;">
            <label for="start">From</label>
            <input type="date" id="start" name="start" value="{{ $start }}" required>
        </div>
        <div style="flex:0 1 170px;">
            <label for="end">to</label>
            <input type="date" id="end" name="end" value="{{ $end }}" required>
        </div>
        <div><button class="btn btn-ghost">Show it</button></div>
    </form>

    <div style="display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.8rem;">
        @foreach ($cutoffs as $c)
            @php
                $cf = \Illuminate\Support\Carbon::parse($c['start']);
                $ct = \Illuminate\Support\Carbon::parse($c['end']);
                $isNow = $c['start'] === $start && $c['end'] === $end;
            @endphp
            <a href="{{ route('hr.payroll.index', ['start' => $c['start'], 'end' => $c['end']]) }}"
               class="btn btn-sm {{ $isNow ? 'btn-primary' : 'btn-ghost' }}">
                {{ $cf->format('M j') }}–{{ $ct->format('j') }}
            </a>
        @endforeach
    </div>
</div>

{{-- ---------- What it would pay ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>{{ $from->format('M j') }}–{{ $to->format('M j, Y') }}</h2>

    @if (empty($drafts))
        <p class="sub" style="margin:0;">
            Nobody to pay for this period. Either every payslip already exists, or
            nobody on the books can be paid — see below.
        </p>
    @else
        <div class="dl-tally" style="margin:.2rem 0 .9rem;">
            <span class="dl-tally-item"><strong>{{ count($drafts) }}</strong> to pay</span>
            <span class="dl-tally-item"><strong>₱{{ number_format($total, 2) }}</strong> in all</span>
        </div>

        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Who</th><th>Gross</th><th>Added</th><th>Taken off</th><th>Net</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($drafts as $d)
                        <tr>
                            <td style="font-weight:600;">
                                {{ $d['employee']->user?->name ?? '—' }}
                                <div class="sub" style="font-weight:400;">{{ $d['employee']->position }}</div>
                            </td>
                            <td>₱{{ number_format($d['gross'], 2) }}</td>
                            <td>
                                @forelse ($d['earnings'] as $l)
                                    <div class="sub">{{ $l['label'] }} · ₱{{ number_format($l['amount'], 2) }}</div>
                                @empty
                                    <span class="sub">—</span>
                                @endforelse
                            </td>
                            <td>
                                @forelse ($d['deductions'] as $l)
                                    <div class="sub">{{ $l['label'] }} · ₱{{ number_format($l['amount'], 2) }}</div>
                                @empty
                                    <span class="sub">—</span>
                                @endforelse
                            </td>
                            <td style="font-weight:700;">₱{{ number_format($d['net'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Clocked and never approved. Not paid, and not hidden either. --}}
        @php
            $unapproved = collect($drafts)
                ->filter(fn ($d) => ($d['clock']['overtime_unapproved'] ?? 0) > 0);
        @endphp
        @if ($unapproved->isNotEmpty())
            <p class="sub" style="margin:.9rem 0 0;">
                <strong>Overtime clocked but never approved</strong>, so not paid:
                @foreach ($unapproved as $d)
                    {{ $d['employee']->user?->name }}
                    ({{ \App\Support\Wage::sayMinutes($d['clock']['overtime_unapproved']) }}){{ ! $loop->last ? ',' : '' }}
                @endforeach
            </p>
        @endif

        <form method="POST" action="{{ route('hr.payroll.run') }}" style="margin-top:1rem;"
              onsubmit="return confirm('Record {{ count($drafts) }} payslips for {{ $from->format('M j') }}–{{ $to->format('M j') }}?')">
            @csrf
            <input type="hidden" name="period_start" value="{{ $start }}">
            <input type="hidden" name="period_end" value="{{ $end }}">

            <label style="display:flex; gap:.4rem; align-items:center; font-size:.85rem; margin-bottom:.6rem;">
                <input type="checkbox" name="release" value="1" style="width:auto;">
                Release them all now — otherwise nobody sees theirs until you do
            </label>

            <button class="btn btn-primary">Run the cut-off</button>
        </form>
    @endif
</div>

{{-- ---------- Who is left out, and why ---------- --}}
@if (! empty($skipped))
    <div class="card panel" style="margin-bottom: 1.1rem;">
        <h2>Left out of this run</h2>
        <p class="sub" style="margin:0 0 .6rem;">
            Somebody missing from a cut-off is somebody who does not get paid, so
            here is every one of them and why.
        </p>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Who</th><th>Why</th><th></th></tr></thead>
                <tbody>
                    @foreach ($skipped as $s)
                        <tr>
                            <td style="font-weight:600;">{{ $s['employee']->user?->name ?? '—' }}</td>
                            <td>{{ $s['why'] }}</td>
                            <td>
                                <a href="{{ route('hr.employees.show', $s['employee']) }}" class="btn btn-ghost btn-sm">
                                    Their file
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- ---------- Release what has already been run ---------- --}}
@if ($unreleased > 0)
    <div class="card panel">
        <h2>Waiting to be released</h2>
        <p class="sub" style="margin:0 0 .7rem;">
            {{ $unreleased }} {{ Str::plural('payslip', $unreleased) }} for this period
            {{ $unreleased === 1 ? 'is' : 'are' }} recorded but nobody can see
            {{ $unreleased === 1 ? 'it' : 'them' }} yet.
        </p>
        <form method="POST" action="{{ route('hr.payroll.release') }}"
              onsubmit="return confirm('Release {{ $unreleased }} payslips to the people they are about?')">
            @csrf
            <input type="hidden" name="period_start" value="{{ $start }}">
            <input type="hidden" name="period_end" value="{{ $end }}">
            <button class="btn btn-primary">Release all {{ $unreleased }}</button>
        </form>
    </div>
@endif
@endsection
