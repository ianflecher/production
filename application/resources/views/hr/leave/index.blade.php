@extends('layouts.app')

@section('title', 'Leave — Imprint Production')
@section('page-title', 'Leave')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Leave</h1>
        <p class="muted">
            Every request in the shop. Until this screen they could only be
            answered from inside each person's own file.
        </p>
    </div>
    <a href="{{ route('hr.employees.index') }}" class="btn btn-ghost btn-sm">All people</a>
</div>

{{-- ---------- What the desk wants at a glance ----------
     The shape is TGIF's: icon chip, figure, title, explanation. The colours
     are this app's - only the thing that wants doing wears brand red, and
     only while there is something to do. --}}
<div class="hr-stats">
    <div class="hr-stat {{ $waiting > 0 ? 'is-waiting' : '' }}">
        <div class="hr-stat-head">
            <div class="hr-stat-icon">⏳</div>
            <div class="hr-stat-figure">{{ $waiting }}</div>
        </div>
        <div class="hr-stat-title">Waiting on you</div>
        <div class="hr-stat-sub">
            @if ($waiting === 0)
                No leave is waiting for an answer.
            @else
                Leave filed and not yet answered.
            @endif
            @if ($waitingArrangements > 0)
                <br>{{ $waitingArrangements }} {{ Str::plural('arrangement', $waitingArrangements) }} too.
            @endif
        </div>
    </div>

    <div class="hr-stat is-quiet">
        <div class="hr-stat-head">
            <div class="hr-stat-icon">📅</div>
            <div class="hr-stat-figure">{{ (float) $thisMonth }}</div>
        </div>
        <div class="hr-stat-title">Days off this month</div>
        <div class="hr-stat-sub">Approved, counted in working days.</div>
    </div>

    <div class="hr-stat {{ $offToday->isNotEmpty() ? 'is-good' : '' }}">
        <div class="hr-stat-head">
            <div class="hr-stat-icon">🏖️</div>
            <div class="hr-stat-figure">{{ $offToday->count() }}</div>
        </div>
        <div class="hr-stat-title">Off today</div>
        <div class="hr-stat-sub">
            @if ($offToday->isEmpty())
                Everybody is in.
            @else
                @foreach ($offToday->take(3) as $r){{ $r->employee?->displayName() }}{{ ! $loop->last ? ', ' : '' }}@endforeach{{ $offToday->count() > 3 ? ' and '.($offToday->count() - 3).' more' : '' }}
            @endif
        </div>
    </div>
</div>

{{-- ---------- Narrowing it down ---------- --}}
<div class="card panel" style="margin-bottom: 1.1rem;">
    <form method="GET" action="{{ route('hr.leave.index') }}"
          style="display:flex; gap:.6rem; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:0 1 160px;">
            <label for="status">Showing</label>
            <select id="status" name="status">
                @foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'declined' => 'Declined', 'all' => 'Everything'] as $k => $label)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div style="flex:0 1 200px;">
            <label for="type">Kind</label>
            <select id="type" name="type">
                <option value="">All leave</option>
                @foreach ($types as $k => $label)
                    @if (in_array($k, \App\Models\HrRequest::DAYS_AWAY, true))
                        <option value="{{ $k }}" @selected($type === $k)>{{ $label }}</option>
                    @endif
                @endforeach
                <option value="arrangements" @selected($type === 'arrangements')>
                    Arrangements (overtime, undertime, OB, schedule){{ $waitingArrangements > 0 ? ' — '.$waitingArrangements.' waiting' : '' }}
                </option>
            </select>
        </div>
        <div style="flex:1 1 200px;">
            <label for="q">Whose</label>
            <input type="search" id="q" name="q" value="{{ $search }}" placeholder="Name…">
        </div>
        <div><button class="btn btn-ghost">Show</button></div>
    </form>
</div>

{{-- ---------- The requests ---------- --}}
<div class="card panel">
    @if ($requests->isEmpty())
        <p class="sub" style="margin:0;">
            @if ($status === 'pending')
                Nobody is waiting on an answer.
            @else
                Nothing matches that.
            @endif
        </p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr><th>Who</th><th>What</th><th>When</th><th>Days</th><th>Why</th><th>Answer</th></tr>
                </thead>
                <tbody>
                    @foreach ($requests as $r)
                        @php $balances = \App\Http\Controllers\Hr\HrLeaveController::balancesFor($r->employee); @endphp
                        <tr>
                            <td style="font-weight:600;">
                                <a href="{{ route('hr.employees.show', $r->employee) }}">
                                    {{ $r->employee?->displayName() ?? '—' }}
                                </a>
                                <div class="sub" style="font-weight:400;">{{ $r->employee?->position }}</div>
                            </td>
                            <td>
                                {{ $types[$r->type] ?? $r->type }}
                                @if ($r->hasAttachment())
                                    <div class="sub">
                                        <a href="{{ route('hr.leave.attachment', $r) }}">
                                            📎 {{ Str::limit($r->attachment_name, 22) }}
                                        </a>
                                    </div>
                                @endif
                            </td>
                            <td>
                                {{ $r->starts_on?->format('M j') }}@if ($r->ends_on && ! $r->ends_on->isSameDay($r->starts_on))–{{ $r->ends_on->format('M j') }}@endif
                                <div class="sub">{{ $r->starts_on?->format('Y') }}</div>
                            </td>
                            <td>
                                @if ($r->working_days !== null)
                                    {{ (float) $r->working_days }}
                                    {{-- What it would leave them, so the desk is not
                                         deciding blind. Only for the two kinds that
                                         draw on anything. --}}
                                    @php
                                        $pool = $r->type === \App\Models\HrRequest::TYPE_SICK ? $balances['sick'] : ($r->type === \App\Models\HrRequest::TYPE_LEAVE ? $balances['vacation'] : null);
                                    @endphp
                                    @if ($pool)
                                        <div class="sub {{ $pool['left'] < (float) $r->working_days ? 'is-orderlist' : '' }}">
                                            {{ $pool['left'] }} left
                                        </div>
                                    @endif
                                @else
                                    <span class="sub">—</span>
                                @endif
                            </td>
                            <td style="max-width:260px;">
                                <span class="sub">{{ Str::limit($r->reason, 90) }}</span>
                            </td>
                            <td>
                                @if ($r->isPending())
                                    <form method="POST" action="{{ route('hr.requests.decide', $r) }}"
                                          style="display:flex; gap:.3rem; flex-wrap:wrap; align-items:center;">
                                        @csrf
                                        <input type="text" name="decision_note" placeholder="A word back (optional)"
                                               maxlength="500" style="flex:1 1 130px; min-width:0;">
                                        <button name="status" value="approved" class="btn btn-success btn-sm">Approve</button>
                                        <button name="status" value="declined" class="btn btn-ghost btn-sm">Decline</button>
                                    </form>
                                @else
                                    <span class="badge {{ $r->status === 'approved' ? 'badge-success' : '' }}">
                                        {{ ucfirst($r->status) }}
                                    </span>
                                    @if ($r->decidedBy)
                                        <div class="sub">by {{ $r->decidedBy->name }}</div>
                                    @endif
                                    @if ($r->decision_note)
                                        <div class="sub">“{{ Str::limit($r->decision_note, 40) }}”</div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
