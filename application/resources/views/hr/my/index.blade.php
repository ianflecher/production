@extends('layouts.app')

@section('title', 'My HR — Imprint Production')
@section('page-title', 'My HR')

@section('content')
@if (! $employee)
    {{-- Staff who were here before HR existed have no employee record. Saying
         so plainly beats an empty page that looks broken. --}}
    <div class="card panel">
        <h2>Nothing here yet</h2>
        <p class="sub" style="margin:0;">
            HR has not set up your employee record. Ask them to add you and your payslips,
            loans and requests will appear here.
        </p>
    </div>
@else
<div class="page-head">
    <div class="grow">
        <h1>My HR</h1>
        <p class="muted">
            {{ $employee->position ?: 'Staff' }}@if ($employee->started_on) · started {{ $employee->started_on->format('M j, Y') }}@endif
        </p>
    </div>
</div>

{{-- ---------- Today's clock ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>My time today</h2>

    @php
        $in = $today?->time_in;
        $out = $today?->time_out;
    @endphp

    <div class="dl-tally" style="margin:.2rem 0 .8rem;">
        <span class="dl-tally-item">
            <strong>{{ $today?->clockedIn() ?? '—' }}</strong> in
        </span>
        <span class="dl-tally-item">
            <strong>{{ $today?->clockedOut() ?? '—' }}</strong> out
        </span>
        @if ($today?->wasLate())
            <span class="dl-tally-item is-orderlist">
                <strong>{{ $today->late_minutes }}</strong> min late
            </span>
        @endif
        @if ($today && $today->overtime_minutes > 0)
            <span class="dl-tally-item"><strong>{{ $today->overtime_minutes }}</strong> min over</span>
        @endif
        @if ($today && $today->undertime_minutes > 0)
            <span class="dl-tally-item"><strong>{{ $today->undertime_minutes }}</strong> min under</span>
        @endif
    </div>

    <p class="sub" style="margin:0 0 .7rem;">
        The shift runs {{ \Illuminate\Support\Carbon::parse(config('shift.start'))->format('g:i A') }}
        to {{ \Illuminate\Support\Carbon::parse(config('shift.end'))->format('g:i A') }},
        with {{ config('shift.grace_minutes') }} minutes' grace.
    </p>

    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        @if (! $in)
            <form method="POST" action="{{ route('hr.my.clock-in') }}">
                @csrf
                <button class="btn btn-primary">Clock in</button>
            </form>
        @elseif (! $out)
            <form method="POST" action="{{ route('hr.my.clock-out') }}">
                @csrf
                <button class="btn btn-primary">Clock out</button>
            </form>
        @else
            <span class="sub">Your day is closed off. Nothing else to press.</span>
        @endif
    </div>

    @if ($recent->isNotEmpty())
        <div class="tbl-wrap" style="margin-top:1rem;">
            <table class="tbl">
                <thead><tr><th>Day</th><th>In</th><th>Out</th><th>Late</th><th>Over</th></tr></thead>
                <tbody>
                    @foreach ($recent as $a)
                        <tr>
                            <td style="font-weight:600;">{{ $a->date->format('D, M j') }}</td>
                            <td>{{ $a->clockedIn() }}</td>
                            <td>{{ $a->clockedOut() }}</td>
                            <td>{{ $a->late_minutes > 0 ? $a->late_minutes.' min' : '—' }}</td>
                            <td>{{ $a->overtime_minutes > 0 ? $a->overtime_minutes.' min' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ---------- File a request ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>Ask the office for something</h2>
    <p class="sub">Leave, a change of schedule, undertime, overtime, or official business.</p>

    <form method="POST" action="{{ route('hr.my.requests.store') }}" style="display:grid; gap:0.8rem; max-width:640px;">
        @csrf
        <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
            <div style="flex:1 1 210px;">
                <label for="type">What are you asking for</label>
                <select id="type" name="type" id="reqType" required>
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:0 1 160px;">
                <label for="starts_on">First day</label>
                <input type="date" id="starts_on" name="starts_on" value="{{ old('starts_on', now()->toDateString()) }}" required>
            </div>
            <div style="flex:0 1 160px;">
                <label for="ends_on">Last day <span style="font-weight:400; color:var(--ink-3);">(if more than one)</span></label>
                <input type="date" id="ends_on" name="ends_on" value="{{ old('ends_on') }}">
            </div>
        </div>

        {{-- Undertime, overtime and a half-day OB are measured in hours. The
             boxes are always here rather than hidden by script — a form that
             rearranges itself is a form people mistrust. --}}
        <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
            <div style="flex:0 1 160px;">
                <label for="starts_at">From <span style="font-weight:400; color:var(--ink-3);">(hours only)</span></label>
                <input type="time" id="starts_at" name="starts_at" value="{{ old('starts_at') }}">
            </div>
            <div style="flex:0 1 160px;">
                <label for="ends_at">To</label>
                <input type="time" id="ends_at" name="ends_at" value="{{ old('ends_at') }}">
            </div>
        </div>

        <div>
            <label for="reason">Why</label>
            <textarea id="reason" name="reason" rows="2" maxlength="2000" required>{{ old('reason') }}</textarea>
        </div>

        <div><button class="btn btn-primary">File it</button></div>
    </form>
</div>

{{-- ---------- My requests ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>What I have asked for</h2>
    @if ($employee->requests->isEmpty())
        <p class="sub" style="margin:0;">You have not filed anything yet.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>What</th><th>When</th><th>Why</th><th>Answer</th><th></th></tr></thead>
                <tbody>
                    @foreach ($employee->requests as $r)
                        <tr>
                            <td style="font-weight:600;">{{ $r->typeLabel() }}</td>
                            <td>{{ $r->whenLabel() }}</td>
                            <td class="sub">{{ Str::limit($r->reason, 60) }}</td>
                            <td>
                                {{ $r->statusLabel() }}
                                @if ($r->decision_note)
                                    <div class="sub">{{ $r->decision_note }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($r->isPending())
                                    <form method="POST" action="{{ route('hr.my.requests.withdraw', $r) }}"
                                          onsubmit="return confirm('Take this back?')">
                                        @csrf
                                        <button class="btn btn-ghost btn-sm">Withdraw</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ---------- What is left, so somebody can plan rather than file and hope ---------- --}}
@if ($leave || $sickLeave)
    <div class="card panel" style="margin-bottom: 1.1rem;">
        <h2>My leave</h2>
        <p class="sub" style="margin:0 0 .7rem;">
            For {{ now()->format('Y') }}. Vacation and sick days are counted apart —
            being ill does not spend a holiday. Overtime, undertime, official business
            and a change of schedule draw down neither.
        </p>

        @foreach ([['Vacation', $leave], ['Sick', $sickLeave]] as [$label, $b])
            @if ($b)
                <div style="margin-bottom:.5rem;">
                    <div class="sub" style="margin:0 0 .25rem; font-weight:600;">{{ $label }}</div>
                    <div class="dl-tally" style="margin:0;">
                        <span class="dl-tally-item"><strong>{{ $b['allowed'] }}</strong> allowed</span>
                        <span class="dl-tally-item"><strong>{{ $b['taken'] }}</strong> taken</span>
                        <span class="dl-tally-item {{ $b['left'] <= 0 ? 'is-orderlist' : '' }}">
                            <strong>{{ $b['left'] }}</strong> left
                        </span>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endif

{{-- ---------- Payslips ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>My payslips</h2>
    @php $released = $employee->payslips->filter->isReleased(); @endphp
    @if ($released->isEmpty())
        <p class="sub" style="margin:0;">No payslip has been released to you yet.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Period</th><th>Gross</th><th>Taken off</th><th>Net</th></tr></thead>
                <tbody>
                    @foreach ($released as $p)
                        <tr>
                            <td style="font-weight:600;">{{ $p->period_start->format('M j') }}–{{ $p->period_end->format('M j, Y') }}</td>
                            <td>₱{{ number_format((float) $p->gross, 2) }}</td>
                            <td>
                                @forelse ($p->deductions ?? [] as $d)
                                    <div class="sub">{{ $d['label'] }} · ₱{{ number_format((float) $d['amount'], 2) }}</div>
                                @empty
                                    <span class="sub">—</span>
                                @endforelse
                            </td>
                            <td style="font-weight:700;">₱{{ number_format((float) $p->net, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ---------- Loans ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>My loans</h2>
    @if ($employee->loans->isEmpty())
        <p class="sub" style="margin:0;">You have no loans on record.</p>
    @else
        <p class="sub">Still owed in total: <strong>₱{{ number_format($employee->loanBalance(), 2) }}</strong></p>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Borrowed</th><th>Amount</th><th>Paid back</th><th>Still owed</th><th>What for</th></tr></thead>
                <tbody>
                    @foreach ($employee->loans as $l)
                        <tr>
                            <td>{{ $l->borrowed_on?->format('M j, Y') }}</td>
                            <td>₱{{ number_format((float) $l->principal, 2) }}</td>
                            <td>₱{{ number_format($l->paid(), 2) }}</td>
                            <td style="font-weight:700;">₱{{ number_format($l->balance(), 2) }}</td>
                            <td class="sub">{{ $l->reason ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ---------- Incidents ---------- --}}
<div class="card panel">
    <h2>Written about me</h2>
    @if ($employee->incidents->isEmpty())
        <p class="sub" style="margin:0;">Nothing on file.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>When</th><th>What</th><th>Details</th><th></th></tr></thead>
                <tbody>
                    @foreach ($employee->incidents as $i)
                        <tr>
                            <td>{{ $i->occurred_on?->format('M j, Y') }}</td>
                            <td style="font-weight:600;">{{ $i->kindLabel() }}</td>
                            <td>
                                {{ $i->description }}
                                @if ($i->action_taken)
                                    <div class="sub">Action: {{ $i->action_taken }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($i->isAcknowledged())
                                    <span class="sub">Read {{ $i->acknowledged_at->format('M j') }}</span>
                                @else
                                    <form method="POST" action="{{ route('hr.my.incidents.read', $i) }}">
                                        @csrf
                                        <button class="btn btn-ghost btn-sm">I have read this</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endif
@endsection
