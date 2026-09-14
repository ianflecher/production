@extends('layouts.app')

@section('title', ($employee->user?->name ?? 'Employee').' — Imprint Production')
@section('page-title', 'Employee')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>{{ $employee->user?->name ?? '—' }}</h1>
        <p class="muted">
            {{ $employee->position ?: 'Staff' }}
            @if ($employee->salary) · ₱{{ number_format((float) $employee->salary, 2) }} {{ $employee->salary_period === 'semi_monthly' ? 'per cut-off' : ($employee->salary_period === 'daily' ? 'per day' : 'per month') }} @endif
            @if ($employee->started_on) · started {{ $employee->started_on->format('M j, Y') }} @endif
        </p>
    </div>
    <a href="{{ route('hr.employees.index') }}" class="btn btn-ghost btn-sm">← All people</a>
</div>

{{-- ---------- Timekeeping ---------- --}}
@php
    $lateDays = $attendance->filter->wasLate();
    $overtime = $attendance->sum('overtime_minutes');
@endphp
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>Timekeeping</h2>
    <p class="sub" style="margin:0 0 .6rem;">
        The last 30 days, clocked by {{ $employee->user?->name ?? 'them' }} themselves.
    </p>

    @if ($attendance->isEmpty())
        <p class="sub" style="margin:0;">Nothing clocked. Days marked present by a leader carry no times.</p>
    @else
        <div class="dl-tally" style="margin:0 0 .8rem;">
            <span class="dl-tally-item"><strong>{{ $attendance->count() }}</strong> days recorded</span>
            <span class="dl-tally-item {{ $lateDays->count() ? 'is-orderlist' : '' }}">
                <strong>{{ $lateDays->count() }}</strong> late
            </span>
            <span class="dl-tally-item"><strong>{{ $lateDays->sum('late_minutes') }}</strong> min late in all</span>
            <span class="dl-tally-item"><strong>{{ $overtime }}</strong> min overtime</span>
        </div>

        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Day</th><th>In</th><th>Out</th><th>Late</th><th>Under</th><th>Over</th></tr></thead>
                <tbody>
                    @foreach ($attendance as $a)
                        <tr>
                            <td style="font-weight:600;">{{ $a->date->format('D, M j') }}</td>
                            <td>{{ $a->clockedIn() }}</td>
                            <td>{{ $a->clockedOut() }}</td>
                            <td>{{ $a->late_minutes > 0 ? $a->late_minutes.' min' : '—' }}</td>
                            <td>{{ $a->undertime_minutes > 0 ? $a->undertime_minutes.' min' : '—' }}</td>
                            <td>{{ $a->overtime_minutes > 0 ? $a->overtime_minutes.' min' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ---------- Requests waiting on HR ---------- --}}
@php $pending = $employee->requests->where('status', 'pending'); @endphp
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>Requests</h2>
    @if ($employee->requests->isEmpty())
        <p class="sub" style="margin:0;">Nothing filed.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>What</th><th>When</th><th>Why</th><th>Answer</th></tr></thead>
                <tbody>
                    @foreach ($employee->requests as $r)
                        <tr>
                            <td style="font-weight:600;">{{ $r->typeLabel() }}</td>
                            <td>{{ $r->whenLabel() }}</td>
                            <td class="sub">{{ $r->reason }}</td>
                            <td>
                                @if ($r->isPending())
                                    <form method="POST" action="{{ route('hr.requests.decide', $r) }}"
                                          style="display:grid; gap:0.35rem; min-width:220px;">
                                        @csrf
                                        <input type="text" name="decision_note" maxlength="1000" placeholder="A word back (optional)">
                                        <div style="display:flex; gap:0.35rem;">
                                            <button class="btn btn-success btn-sm" name="status" value="approved">Approve</button>
                                            <button class="btn btn-danger btn-sm" name="status" value="declined">Decline</button>
                                        </div>
                                    </form>
                                @else
                                    {{ $r->statusLabel() }}
                                    <div class="sub">
                                        {{ $r->decider?->name }}@if ($r->decided_at) · {{ $r->decided_at->format('M j') }}@endif
                                        @if ($r->decision_note)<br>{{ $r->decision_note }}@endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ---------- Payslips ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>Payslips</h2>
    <p class="sub">
        What payroll worked out, written down. The net is added up from the lines, so the
        slip cannot disagree with itself. Nothing is visible to them until it is released.
    </p>

    @if ($employee->payslips->isNotEmpty())
        <div class="tbl-wrap" style="margin-bottom: 1.1rem;">
            <table class="tbl">
                <thead><tr><th>Period</th><th>Gross</th><th>Net</th><th>Released</th></tr></thead>
                <tbody>
                    @foreach ($employee->payslips as $p)
                        <tr>
                            <td style="font-weight:600;">{{ $p->period_start->format('M j') }}–{{ $p->period_end->format('M j, Y') }}</td>
                            <td>₱{{ number_format((float) $p->gross, 2) }}</td>
                            <td style="font-weight:700;">₱{{ number_format((float) $p->net, 2) }}</td>
                            <td>
                                @if ($p->isReleased())
                                    <span class="sub">{{ $p->released_at->format('M j, g:ia') }}</span>
                                @else
                                    <form method="POST" action="{{ route('hr.payslips.release', $p) }}">
                                        @csrf
                                        <button class="btn btn-primary btn-sm">Release to them</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <details>
        <summary class="btn btn-ghost btn-sm" style="display:inline-block;">+ Record a payslip</summary>
        <form method="POST" action="{{ route('hr.payslips.store', $employee) }}" style="display:grid; gap:0.8rem; max-width:680px; margin-top:0.9rem;">
            @csrf
            <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
                <div style="flex:0 1 170px;">
                    <label for="period_start">Period from</label>
                    <input type="date" id="period_start" name="period_start" required>
                </div>
                <div style="flex:0 1 170px;">
                    <label for="period_end">to</label>
                    <input type="date" id="period_end" name="period_end" required>
                </div>
                <div style="flex:0 1 170px;">
                    <label for="gross">Gross pay</label>
                    <input type="number" step="0.01" min="0" id="gross" name="gross"
                           value="{{ $employee->salary }}" required>
                </div>
            </div>

            <div>
                <strong style="font-size:0.82rem;">Added to it</strong>
                @for ($i = 0; $i < 3; $i++)
                    <div style="display:flex; gap:0.5rem; margin-top:0.35rem;">
                        <input type="text" name="earnings[{{ $i }}][label]" maxlength="60" placeholder="e.g. Overtime" style="flex:1 1 220px;">
                        <input type="number" step="0.01" name="earnings[{{ $i }}][amount]" placeholder="0.00" style="flex:0 1 140px;">
                    </div>
                @endfor
            </div>

            <div>
                <strong style="font-size:0.82rem;">Taken off it</strong>
                @for ($i = 0; $i < 5; $i++)
                    <div style="display:flex; gap:0.5rem; margin-top:0.35rem;">
                        <input type="text" name="deductions[{{ $i }}][label]" maxlength="60"
                               placeholder="{{ ['SSS','PhilHealth','Pag-IBIG','Loan','Late'][$i] }}" style="flex:1 1 220px;">
                        <input type="number" step="0.01" name="deductions[{{ $i }}][amount]" placeholder="0.00" style="flex:0 1 140px;">
                    </div>
                @endfor
            </div>

            <div>
                <label for="pnote">Note</label>
                <input type="text" id="pnote" name="note" maxlength="2000">
            </div>

            <label style="display:flex; gap:0.4rem; align-items:center; font-weight:600; font-size:0.85rem;">
                <input type="checkbox" name="release" value="1" style="width:auto;">
                Release it to them now
            </label>

            <div><button class="btn btn-primary">Save payslip</button></div>
        </form>
    </details>
</div>

{{-- ---------- Loans ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>Loans</h2>
    <p class="sub">Still owed in total: <strong>₱{{ number_format($employee->loanBalance(), 2) }}</strong></p>

    @foreach ($employee->loans as $l)
        <div style="border-bottom:1px solid var(--line, #E5E9F0); padding:0.7rem 0;">
            <div style="display:flex; gap:0.8rem; flex-wrap:wrap; align-items:baseline;">
                <strong>₱{{ number_format((float) $l->principal, 2) }}</strong>
                <span class="sub">{{ $l->reason ?: 'no reason given' }} · borrowed {{ $l->borrowed_on?->format('M j, Y') }}</span>
                <span style="margin-left:auto; font-weight:700;">
                    {{ $l->isSettled() ? 'Settled' : '₱'.number_format($l->balance(), 2).' owed' }}
                </span>
            </div>

            @unless ($l->isSettled())
                <form method="POST" action="{{ route('hr.loans.payments.store', $l) }}"
                      style="display:flex; gap:0.4rem; margin-top:0.5rem; flex-wrap:wrap; align-items:flex-end;">
                    @csrf
                    <div><label>Payment</label><input type="number" step="0.01" min="0.01" name="amount" placeholder="0.00" required style="width:130px;"></div>
                    <div><label>On</label><input type="date" name="paid_on" value="{{ now()->toDateString() }}" required style="width:150px;"></div>
                    <button class="btn btn-ghost btn-sm">Record payment</button>
                </form>
            @endunless
        </div>
    @endforeach

    <details style="margin-top:0.9rem;">
        <summary class="btn btn-ghost btn-sm" style="display:inline-block;">+ Record a loan</summary>
        <form method="POST" action="{{ route('hr.loans.store', $employee) }}" style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:flex-end; margin-top:0.9rem;">
            @csrf
            <div><label for="principal">Amount</label><input type="number" step="0.01" min="0.01" id="principal" name="principal" required style="width:150px;"></div>
            <div><label for="borrowed_on">Borrowed on</label><input type="date" id="borrowed_on" name="borrowed_on" value="{{ now()->toDateString() }}" required style="width:160px;"></div>
            <div><label for="per_payslip">Per payslip</label><input type="number" step="0.01" min="0" id="per_payslip" name="per_payslip" style="width:140px;"></div>
            <div style="flex:1 1 200px;"><label for="reason">What for</label><input type="text" id="reason" name="reason" maxlength="255"></div>
            <button class="btn btn-primary btn-sm">Save loan</button>
        </form>
    </details>
</div>

{{-- ---------- Incidents ---------- --}}
<div class="card panel">
    <h2>Incident reports</h2>

    @if ($employee->incidents->isNotEmpty())
        <div class="tbl-wrap" style="margin-bottom: 1.1rem;">
            <table class="tbl">
                <thead><tr><th>When</th><th>What</th><th>Details</th><th>Read</th></tr></thead>
                <tbody>
                    @foreach ($employee->incidents as $i)
                        <tr>
                            <td>{{ $i->occurred_on?->format('M j, Y') }}</td>
                            <td style="font-weight:600;">{{ $i->kindLabel() }}</td>
                            <td>
                                {{ $i->description }}
                                @if ($i->action_taken)<div class="sub">Action: {{ $i->action_taken }}</div>@endif
                            </td>
                            <td class="sub">{{ $i->isAcknowledged() ? $i->acknowledged_at->format('M j') : 'not yet' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <details>
        <summary class="btn btn-ghost btn-sm" style="display:inline-block;">+ Record something</summary>
        <form method="POST" action="{{ route('hr.incidents.store', $employee) }}" style="display:grid; gap:0.7rem; max-width:640px; margin-top:0.9rem;">
            @csrf
            <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                <div style="flex:0 1 170px;">
                    <label for="occurred_on">When</label>
                    <input type="date" id="occurred_on" name="occurred_on" value="{{ now()->toDateString() }}" required>
                </div>
                <div style="flex:1 1 200px;">
                    <label for="kind">What kind</label>
                    <select id="kind" name="kind" required>
                        @foreach ($kinds as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label for="description">What happened</label>
                <textarea id="description" name="description" rows="2" maxlength="2000" required></textarea>
            </div>
            <div>
                <label for="action_taken">What was done about it</label>
                <textarea id="action_taken" name="action_taken" rows="2" maxlength="2000"></textarea>
            </div>
            <div><button class="btn btn-primary">Save</button></div>
        </form>
    </details>
</div>
@endsection
