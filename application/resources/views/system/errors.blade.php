@extends('layouts.app')

@section('title', 'System errors')
@section('page-title', 'System errors')

@section('content')
<style>
    .err-row { padding: 0.9rem 0; border-bottom: 1px solid var(--border); }
    .err-row:last-child { border-bottom: 0; }
    .err-top { display: flex; gap: 0.7rem; align-items: baseline; flex-wrap: wrap; }
    .err-level { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; padding: 0.1rem 0.45rem; border-radius: 5px; background: #fee2e2; color: #b91c1c; }
    .err-level.CRITICAL, .err-level.ALERT, .err-level.EMERGENCY { background: #7f1d1d; color: #fff; }
    .err-count { font-size: 0.72rem; font-weight: 700; padding: 0.1rem 0.45rem; border-radius: 99px; background: var(--border); color: var(--ink-2); }
    .err-when { font-size: 0.75rem; color: var(--ink-3); margin-left: auto; }
    .err-msg { font-family: ui-monospace, Consolas, monospace; font-size: 0.82rem; line-height: 1.5; margin-top: 0.4rem; word-break: break-word; color: var(--ink); }
    .err-meta { font-size: 0.72rem; color: var(--ink-3); margin-top: 0.3rem; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>System errors</h1>
        <p class="muted">Anything the app failed at, newest first. The same failure is grouped into one row.</p>
    </div>
</div>

<form method="GET" action="{{ route('system.errors') }}" style="display:flex; gap:0.5rem; align-items:center; margin-bottom:1rem;">
    <label for="days" style="font-size:0.8rem; font-weight:600; color:var(--ink-2);">Show the last</label>
    <select name="days" id="days" onchange="this.form.submit()" style="max-width:150px;">
        <option value="1" @selected($days === 1)>24 hours</option>
        <option value="7" @selected($days === 7)>7 days</option>
        <option value="30" @selected($days === 30)>30 days</option>
    </select>
</form>

<div class="card panel">
    @if ($incidents->isEmpty())
        <div style="text-align:center; padding:2.5rem 1rem;">
            <div style="font-size:2.5rem; line-height:1;">✅</div>
            <h2 style="margin:0.6rem 0 0.3rem;">Nothing has gone wrong</h2>
            <p class="muted" style="margin:0;">No errors logged in the last
                {{ $days === 1 ? '24 hours' : $days.' days' }}.</p>
        </div>
    @else
        <h2>{{ number_format($total) }} error{{ $total === 1 ? '' : 's' }},
            {{ $incidents->count() }} distinct</h2>
        <p class="sub">If the same line keeps climbing, that is the one worth fixing.</p>

        @foreach ($incidents as $e)
            <div class="err-row">
                <div class="err-top">
                    <span class="err-level {{ $e['level'] }}">{{ $e['level'] }}</span>
                    @if ($e['count'] > 1)
                        <span class="err-count">×{{ number_format($e['count']) }}</span>
                    @endif
                    <span class="err-when">
                        last {{ $e['last']->diffForHumans() }}
                        @if ($e['count'] > 1)
                            · first {{ $e['first']->diffForHumans() }}
                        @endif
                    </span>
                </div>
                <div class="err-msg">{{ $e['message'] }}</div>
                <div class="err-meta">
                    {{ $e['last']->format('M j, Y g:i a') }}
                    @if ($e['environment'] !== 'production')
                        · logged as <strong>{{ $e['environment'] }}</strong>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
</div>

<p class="muted" style="font-size:0.75rem; margin-top:0.9rem;">
    Read from {{ $logPath }} ({{ $logSize > 1048576 ? round($logSize / 1048576, 1).' MB' : max(1, round($logSize / 1024)).' KB' }}).
    Only the most recent part of the file is scanned.
</p>
@endsection
