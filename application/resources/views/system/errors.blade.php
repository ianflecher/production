@extends('layouts.app')

@section('title', 'System errors')
@section('page-title', 'System errors')

@section('content')
<style>
    /* The row IS the button, so the whole thing is clickable and it still
       works with the keyboard. It has to be un-styled back into a row. */
    .err-form { margin: 0; }
    .err-form:last-child .err-row { border-bottom: 0; }
    .err-row {
        display: block; width: 100%; text-align: left; position: relative;
        padding: 0.9rem 0; border: 0; border-bottom: 1px solid var(--border);
        background: none; font: inherit; color: inherit; cursor: pointer;
    }
    .err-row:hover, .err-row:focus-visible {
        background: var(--danger-soft, rgba(239,68,68,.05));
    }
    .err-clear {
        position: absolute; top: 0.9rem; right: 0;
        font-size: 0.72rem; font-weight: 700; letter-spacing: .04em;
        text-transform: uppercase; color: var(--ink-3); opacity: 0;
        transition: opacity .12s;
    }
    .err-row:hover .err-clear, .err-row:focus-visible .err-clear { opacity: 1; }
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
            {{-- Click one to say it has been dealt with. The log file is the
                 record and is never edited; this only remembers that somebody
                 looked, so the same failure happening again brings it back. --}}
            <form method="POST" action="{{ route('system.errors.dismiss') }}" class="err-form">
                @csrf
                <input type="hidden" name="fingerprint" value="{{ $e['fingerprint'] }}">
                <button type="submit" class="err-row" title="Clear this — it comes back if it happens again">
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
                <span class="err-clear">Clear</span>
                </button>
            </form>
        @endforeach
    @endif
</div>

<p class="muted" style="font-size:0.75rem; margin-top:0.9rem;">
    Read from {{ $logPath }} ({{ $logSize > 1048576 ? round($logSize / 1048576, 1).' MB' : max(1, round($logSize / 1024)).' KB' }}).
    Only the most recent part of the file is scanned.
</p>
@endsection
