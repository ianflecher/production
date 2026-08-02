@extends('layouts.app')

@section('title', 'Users — Imprint Production')
@section('page-title', 'Users')

@section('content')

@php
    $todayAttendance = \App\Models\Attendance::whereDate(
        'date',
        now()->toDateString()
    )->pluck('status', 'user_id');
@endphp

<div class="page-head">
    <div class="grow">
        <h1>Users</h1>

        <p class="muted">
            Manage employee accounts, roles, teams, attendance,
            and passwords.
        </p>
        @if (!empty($managementScope))
            <p class="muted" style="margin-top:0.3rem; font-size:0.82rem;">
                👁 You supervise the <strong>{{ $managementScope === 'design' ? 'design side (account officers & artists)' : 'production side (printer → QC)' }}</strong> — only that staff is shown here.
            </p>
        @endif
    </div>

    <details class="inline-form">
        <summary class="btn btn-primary">
            + New account
        </summary>

        <div class="pop" style="min-width: 300px;">
            <form
                method="POST"
                action="{{ route('users.store') }}"
            >
                @csrf

                <div class="field">
                    <label for="new_user_name">
                        Full name
                    </label>

                    <input
                        id="new_user_name"
                        type="text"
                        name="name"
                        required
                        maxlength="255"
                        value="{{ old('name') }}"
                    >
                </div>

                <div class="field">
                    <label for="new_user_email">
                        Email
                    </label>

                    <input
                        id="new_user_email"
                        type="email"
                        name="email"
                        required
                        maxlength="255"
                        value="{{ old('email') }}"
                    >
                </div>

                <div class="field">
                    <label for="new_user_position">
                        Position
                    </label>

                    <select
                        id="new_user_position"
                        name="position"
                        required
                    >
                        <option value="artist">
                            Artist (design, mockup)
                        </option>

                        <option value="supply_chain">
                            Supply chain
                        </option>

                        <option value="production">
                            Production
                        </option>

                        @if (auth()->user()->isSuperAdmin())
                            <option value="sales">
                                Account Officer
                            </option>

                            <option value="finance">
                                Finance
                            </option>

                            <option value="leader">
                                Leader
                            </option>

                            <option value="super_admin">
                                Super Admin
                            </option>
                        @endif
                    </select>

                    @unless (auth()->user()->isSuperAdmin())
                        <div
                            style="
                                font-size: 0.75rem;
                                color: var(--ink-3);
                                margin-top: 0.3rem;
                            "
                        >
                            Only the Super Admin can create account
                            officer, leader, and super-admin accounts.
                        </div>
                    @endunless
                </div>

                <div class="field">
                    <label for="new_user_team">
                        Team (account officers only)
                    </label>

                    <select
                        id="new_user_team"
                        name="team"
                    >
                        <option value="">
                            — None —
                        </option>

                        @foreach (\App\Models\User::TEAMS as $key => $label)
                            <option
                                value="{{ $key }}"
                                @selected(old('team') === $key)
                            >
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>

                    <div
                        style="
                            font-size: 0.75rem;
                            color: var(--ink-3);
                            margin-top: 0.3rem;
                        "
                    >
                        Shown as the team on that officer's job orders.
                    </div>
                </div>

                <div class="field">
                    <label for="new_user_password">
                        Password (minimum 8 characters)
                    </label>

                    <input
                        id="new_user_password"
                        type="password"
                        name="password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                    >
                </div>

                <button
                    type="submit"
                    class="btn btn-primary btn-sm"
                >
                    Create account
                </button>
            </form>
        </div>
    </details>
</div>

@if ($users->isNotEmpty())
    @php
        $attTotal   = $presentToday + $absentToday;
        $attPct     = $attTotal ? round($presentToday / $attTotal * 100) : 0;
        $circ       = 2 * pi() * 54;
        $dash       = $attTotal ? ($presentToday / $attTotal) * $circ : 0;
        $deptMax    = max(1, $deptMix->max() ?? 0);
        $posMax     = max(1, $positionDist->max() ?? 0);
        $weekMax    = max(1, $sevenDay->max('count') ?? 0);
        $palette    = ['#2563eb', '#16a34a', '#d97706', '#7c3aed', '#dc2626', '#0d9488', '#db2777', '#0891b2', '#65a30d', '#64748b'];
    @endphp

    <style>
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1.4rem; }
        .anacard { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 1.1rem 1.2rem; box-shadow: 0 6px 20px rgba(19, 30, 51, .05); }
        .anacard h3 { font-family: var(--font-head); font-size: 0.95rem; margin-bottom: 0.9rem; color: #1a2438; }
        .ana-leg { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; margin-bottom: 0.3rem; }
        .ana-leg .dot { width: 11px; height: 11px; border-radius: 3px; display: inline-block; flex-shrink: 0; }
        .ana-leg b { margin-left: auto; }
        .ana-bar { display: flex; align-items: center; gap: 0.55rem; margin-bottom: 0.5rem; font-size: 0.8rem; }
        .ana-bar .lbl { width: 116px; flex-shrink: 0; color: var(--ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-transform: capitalize; }
        .ana-bar .track { flex: 1; height: 9px; background: #eef1f6; border-radius: 999px; overflow: hidden; }
        .ana-bar .fill { height: 100%; border-radius: 999px; }
        .ana-bar .val { width: 26px; text-align: right; font-weight: 700; color: #1a2438; }
        .ana-scroll { max-height: 220px; overflow-y: auto; padding-right: 0.2rem; }
    </style>

    <div class="analytics-grid">
        {{-- 1. Today's attendance donut --}}
        <div class="anacard">
            <h3>Today's attendance</h3>
            <div style="display: flex; align-items: center; gap: 1.1rem;">
                <svg viewBox="0 0 120 120" style="width: 118px; height: 118px; flex-shrink: 0;">
                    <circle cx="60" cy="60" r="54" fill="none" stroke="#eef1f6" stroke-width="13"/>
                    <circle cx="60" cy="60" r="54" fill="none" stroke="#16a34a" stroke-width="13" stroke-linecap="round"
                            stroke-dasharray="{{ $dash }} {{ $circ }}" transform="rotate(-90 60 60)"/>
                    <text x="60" y="57" text-anchor="middle" font-size="24" font-weight="800" fill="#16203a">{{ $attPct }}%</text>
                    <text x="60" y="75" text-anchor="middle" font-size="9" fill="#64748b" letter-spacing="1">PRESENT</text>
                </svg>
                <div style="min-width: 0;">
                    <div class="ana-leg"><span class="dot" style="background: #16a34a;"></span>Present <b>{{ $presentToday }}</b></div>
                    <div class="ana-leg"><span class="dot" style="background: #e5e9f0;"></span>Absent <b>{{ $absentToday }}</b></div>
                    <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.4rem;">of {{ $attTotal }} active staff</div>
                </div>
            </div>
        </div>

        {{-- 2. Department mix --}}
        <div class="anacard">
            <h3>Department mix</h3>
            @foreach ($deptMix as $dept => $count)
                <div class="ana-bar">
                    <span class="lbl">{{ $dept }}</span>
                    <div class="track"><div class="fill" style="width: {{ round($count / $deptMax * 100) }}%; background: {{ $palette[$loop->index % count($palette)] }};"></div></div>
                    <span class="val">{{ $count }}</span>
                </div>
            @endforeach
        </div>

        {{-- 3. Exact position distribution --}}
        <div class="anacard">
            <h3>Position distribution</h3>
            <div class="ana-scroll">
                @foreach ($positionDist as $role => $count)
                    <div class="ana-bar">
                        <span class="lbl">{{ \Illuminate\Support\Str::of($role)->replace('_', ' ')->title() }}</span>
                        <div class="track"><div class="fill" style="width: {{ round($count / $posMax * 100) }}%; background: {{ $palette[$loop->index % count($palette)] }};"></div></div>
                        <span class="val">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 4. Seven-day attendance activity --}}
        <div class="anacard">
            <h3>Attendance — last 7 days</h3>
            <div style="display: flex; align-items: flex-end; gap: 0.45rem; height: 118px;">
                @foreach ($sevenDay as $d)
                    <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.3rem; height: 100%;">
                        <div style="flex: 1; width: 100%; display: flex; align-items: flex-end;">
                            <div title="{{ $d['count'] }} present" style="width: 100%; border-radius: 6px 6px 0 0; background: linear-gradient(180deg, #3b82f6, #2563eb); height: {{ round($d['count'] / $weekMax * 100) }}%; min-height: {{ $d['count'] > 0 ? 6 : 2 }}px;"></div>
                        </div>
                        <span style="font-size: 0.67rem; color: var(--ink-3);">{{ $d['label'] }}</span>
                        <span style="font-size: 0.72rem; font-weight: 700; color: #1a2438;">{{ $d['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif


<div class="card">
    @if ($users->isNotEmpty())
        <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; padding: 0.85rem 1rem; border-bottom: 1px solid var(--border);">
            <div style="position: relative; flex: 1; min-width: 200px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.7rem; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--ink-3); pointer-events: none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="userSearch" placeholder="Search name, email or role…" autocomplete="off" aria-label="Search users" style="padding-left: 2.1rem; padding-top: 0.5rem; padding-bottom: 0.5rem;">
            </div>
            <span id="userCount" style="font-size: 0.8rem; color: var(--ink-3); white-space: nowrap;"></span>
        </div>
    @endif
    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Position</th>
                    <th>Today</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody id="usersBody">
                @forelse ($users as $user)
                    @php
                        $attendanceStatus =
                            $todayAttendance[$user->id] ?? null;

                        $loggedInToday =
                            $user->last_login_at?->isToday();
                    @endphp

                    <tr data-search="{{ strtolower($user->name.' '.$user->email.' '.$user->positionLabel()) }}">
                        {{-- User name --}}
                        <td style="font-weight: 600;">
                            {{ $user->name }}

                            @if ($user->id === auth()->id())
                                <span
                                    style="
                                        color: var(--ink-3);
                                        font-weight: 400;
                                    "
                                >
                                    (you)
                                </span>
                            @endif
                        </td>

                        {{-- Email --}}
                        <td>
                            {{ $user->email }}
                        </td>

                        {{-- Position and team --}}
                        <td>
                            {{ $user->positionLabel() }}

                            @if ($user->isSales() && $user->team)
                                <span
                                    class="badge"
                                    style="
                                        background: var(--accent-soft);
                                        color: #1d4ed8;
                                        margin-left: 0.3rem;
                                    "
                                >
                                    {{ $user->teamLabel() }}
                                </span>
                            @endif
                        </td>

                        {{-- Today's attendance --}}
                        <td>
                            @if ($attendanceStatus === 'present')
                                <span
                                    class="badge"
                                    style="
                                        background: #f0fdf4;
                                        color: #15803d;
                                    "
                                >
                                    ✓ Present
                                </span>

                                <div
                                    style="
                                        font-size: 0.68rem;
                                        color: var(--ink-3);
                                        margin-top: 0.15rem;
                                    "
                                >
                                    Marked by leader
                                </div>

                            @elseif ($attendanceStatus === 'absent')
                                <span
                                    class="badge"
                                    style="
                                        background: #fef2f2;
                                        color: #b91c1c;
                                    "
                                >
                                    ✕ Absent
                                </span>

                                <div
                                    style="
                                        font-size: 0.68rem;
                                        color: var(--ink-3);
                                        margin-top: 0.15rem;
                                    "
                                >
                                    Marked by leader
                                </div>

                            @elseif ($loggedInToday)
                                <span
                                    class="badge"
                                    style="
                                        background: #f0fdf4;
                                        color: #15803d;
                                    "
                                >
                                    ✓ Present
                                </span>

                                <div
                                    style="
                                        font-size: 0.68rem;
                                        color: var(--ink-3);
                                        margin-top: 0.15rem;
                                    "
                                >
                                    Logged in
                                    {{ $user->last_login_at->format('g:i A') }}
                                </div>

                            @else
                                <span
                                    style="
                                        color: var(--ink-3);
                                        font-size: 0.82rem;
                                    "
                                >
                                    Not in yet
                                </span>
                            @endif
                        </td>

                        {{-- Actions --}}
                        <td>
                            <div
                                style="
                                    display: flex;
                                    gap: 0.4rem;
                                    flex-wrap: wrap;
                                    align-items: center;
                                "
                            >
                                {{-- Attendance buttons --}}
                                <div
                                    style="
                                        display: flex;
                                        gap: 0.25rem;
                                    "
                                >
                                    <form
                                        method="POST"
                                        action="{{ route(
                                            'users.attendance',
                                            $user
                                        ) }}"
                                    >
                                        @csrf

                                        <input
                                            type="hidden"
                                            name="status"
                                            value="present"
                                        >

                                        <button
                                            type="submit"
                                            class="
                                                btn
                                                {{ $attendanceStatus === 'present'
                                                    ? 'btn-success'
                                                    : 'btn-ghost'
                                                }}
                                                btn-sm
                                            "
                                            style="
                                                padding: 0.35rem 0.6rem;
                                                font-size: 0.75rem;
                                            "
                                        >
                                            Present
                                        </button>
                                    </form>

                                    <form
                                        method="POST"
                                        action="{{ route(
                                            'users.attendance',
                                            $user
                                        ) }}"
                                    >
                                        @csrf

                                        <input
                                            type="hidden"
                                            name="status"
                                            value="absent"
                                        >

                                        <button
                                            type="submit"
                                            class="
                                                btn
                                                {{ $attendanceStatus === 'absent'
                                                    ? 'btn-danger'
                                                    : 'btn-ghost'
                                                }}
                                                btn-sm
                                            "
                                            style="
                                                padding: 0.35rem 0.6rem;
                                                font-size: 0.75rem;
                                            "
                                        >
                                            Absent
                                        </button>
                                    </form>
                                </div>


                                {{-- Team selector --}}
                                @if ($user->isSales())
                                    <form
                                        method="POST"
                                        action="{{ route(
                                            'users.team',
                                            $user
                                        ) }}"
                                        style="
                                            display: flex;
                                            gap: 0.25rem;
                                            align-items: center;
                                        "
                                    >
                                        @csrf

                                        <select
                                            name="team"
                                            style="
                                                width: auto;
                                                padding: 0.3rem 0.4rem;
                                                font-size: 0.75rem;
                                            "
                                            onchange="this.form.submit()"
                                        >
                                            <option value="">
                                                Team —
                                            </option>

                                            @foreach (
                                                \App\Models\User::TEAMS
                                                as $key => $label
                                            )
                                                <option
                                                    value="{{ $key }}"
                                                    @selected(
                                                        $user->team === $key
                                                    )
                                                >
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </form>
                                @endif


                                {{-- Password reset --}}
                                @if (
                                    $user->id !== auth()->id()
                                    && (
                                        ! $user->isSuperAdmin()
                                        || auth()->user()->isSuperAdmin()
                                    )
                                )
                                    <details class="inline-form">
                                        <summary
                                            class="btn btn-ghost btn-sm"
                                        >
                                            Reset password
                                        </summary>

                                        <div class="pop">
                                            <form
                                                method="POST"
                                                action="{{ route(
                                                    'users.reset',
                                                    $user
                                                ) }}"
                                            >
                                                @csrf

                                                <label>
                                                    New password for
                                                    {{ $user->name }}
                                                </label>

                                                <input
                                                    type="password"
                                                    name="password"
                                                    required
                                                    minlength="8"
                                                    autocomplete="new-password"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-primary btn-sm"
                                                    style="margin-top: 0.5rem;"
                                                >
                                                    Change password
                                                </button>
                                            </form>
                                        </div>
                                    </details>


                                    {{-- Deactivate / reactivate: keeps the account and
                                         its history, but blocks signing in. --}}
                                    <form
                                        method="POST"
                                        action="{{ route(
                                            'users.toggle',
                                            $user
                                        ) }}"
                                        onsubmit="
                                            return confirm(
                                                @if ($user->is_active)
                                                    'Deactivate {{ addslashes($user->name) }}? They will be signed out and cannot log in until reactivated.'
                                                @else
                                                    'Reactivate {{ addslashes($user->name) }} so they can sign in again?'
                                                @endif
                                            );
                                        "
                                    >
                                        @csrf

                                        <button
                                            type="submit"
                                            class="btn {{ $user->is_active ? 'btn-ghost' : 'btn-success' }} btn-sm"
                                        >
                                            {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                    </form>

                                    {{-- Remove user (soft delete — account is hidden but recoverable) --}}
                                    <form
                                        method="POST"
                                        action="{{ route(
                                            'users.destroy',
                                            $user
                                        ) }}"
                                        onsubmit="
                                            return confirm(
                                                'Remove {{ addslashes($user->name) }}? Their account will be deactivated and can no longer sign in. Orders, tasks and attendance are kept, and the account can be restored later.'
                                            );
                                        "
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button
                                            type="submit"
                                            class="btn btn-danger btn-sm"
                                        >
                                            Remove
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td
                            colspan="5"
                            style="
                                text-align: center;
                                color: var(--ink-3);
                                padding: 2rem;
                            "
                        >
                            No user accounts found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div id="usersEmpty" hidden style="text-align: center; color: var(--ink-3); padding: 1.5rem;">No users match your search.</div>
</div>

@if ($users->isNotEmpty())
<script>
    (function () {
        var search = document.getElementById('userSearch');
        var body = document.getElementById('usersBody');
        var emptyMsg = document.getElementById('usersEmpty');
        var countEl = document.getElementById('userCount');
        if (!search || !body) return;
        var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-search]'));
        var total = rows.length;

        function apply() {
            var q = search.value.trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (row) {
                var visible = !q || row.getAttribute('data-search').indexOf(q) !== -1;
                row.hidden = !visible;
                if (visible) shown++;
            });
            if (emptyMsg) emptyMsg.hidden = shown !== 0;
            countEl.textContent = q
                ? 'Showing ' + shown + ' of ' + total
                : total + (total === 1 ? ' account' : ' accounts');
        }

        search.addEventListener('input', apply);
        apply();
    })();
</script>
@endif

@endsection