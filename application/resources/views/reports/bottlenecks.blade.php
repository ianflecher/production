@extends('layouts.app')

@section('title', 'Where work gets stuck — Imprint Production')
@section('page-title', 'Where work gets stuck')

@section('content')
@php
    use App\Http\Controllers\BottleneckReportController as Report;
@endphp

<style>
    .bn-note { font-size: 0.82rem; color: var(--ink-2); line-height: 1.55; }
    .bn-days { font-weight: 700; white-space: nowrap; }
    .bn-bar {
        height: 8px; border-radius: 99px; background: var(--border);
        overflow: hidden; min-width: 90px;
    }
    .bn-bar span { display: block; height: 100%; background: var(--accent); }
    tr.bn-late td { background: #fef4f4; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>Where work gets stuck</h1>
        <p class="muted">
            Which part of the shop is holding jobs up — the ones waiting right now,
            and the steps that are slow every time.
        </p>
    </div>
</div>

{{-- The one to ACT on. Every row is a job somebody could go and unblock. --}}
<div class="card panel" style="margin-bottom: 1.4rem;">
    <h2>Waiting the longest right now
        <span style="font-weight: 400; font-size: 0.8rem; color: var(--ink-3);">({{ $stuck->count() }})</span>
    </h2>
    <p class="sub">
        Open steps on live jobs, longest wait first. Counted from when the step
        became available to work, not from when the order was taken.
    </p>

    @if ($stuck->isEmpty())
        <p class="muted">Nothing is waiting — every live job is moving.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Waiting</th>
                        <th>Order</th>
                        <th>Stuck at</th>
                        <th>Status</th>
                        <th>With</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stuck as $row)
                        @php $t = $row['task']; $late = $row['days'] >= $slowDays; @endphp
                        <tr class="{{ $late ? 'bn-late' : '' }}">
                            <td class="bn-days" style="color: {{ $late ? 'var(--danger-ink)' : 'var(--ink-1)' }};">
                                {{ Report::forHumans($row['hours']) }}
                            </td>
                            <td>
                                <a href="{{ route('orders.show', $t->order) }}" style="font-weight: 600;">{{ $t->order->order_number }}</a>
                                <div style="font-size: 0.78rem; color: var(--ink-3);">{{ $t->order->clientName() }}</div>
                            </td>
                            <td style="font-weight: 600;">{{ $t->department }}</td>
                            <td>@include('partials.status', ['status' => $t->status])</td>
                            <td style="color: var(--ink-2);">
                                {{ $t->operator_name ?: ($t->assignee?->name ?? '—') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- The one to PLAN with. A step that is always slow needs another machine or
     another pair of hands, not somebody chasing it. --}}
<div class="card panel">
    <h2>Slowest steps on average</h2>
    <p class="sub">
        Finished steps from the last {{ $windowDays }} days, by how long each took from
        being released to being signed off.
    </p>

    @if ($slowest->isEmpty())
        <p class="muted">Nothing has been finished in the last {{ $windowDays }} days yet.</p>
    @else
        @php $top = $slowest->first()['average'] ?: 1; @endphp
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Step</th>
                        <th>Average</th>
                        <th></th>
                        <th>Typical</th>
                        <th>Worst</th>
                        <th>Finished</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($slowest as $row)
                        <tr>
                            <td style="font-weight: 600;">{{ $row['department'] }}</td>
                            <td class="bn-days">{{ Report::forHumans($row['average']) }}</td>
                            <td style="width: 130px;">
                                <div class="bn-bar">
                                    <span style="width: {{ max(3, round($row['average'] / $top * 100)) }}%;"></span>
                                </div>
                            </td>
                            {{-- The average alone hides a step that is usually
                                 quick and occasionally catastrophic. --}}
                            <td style="color: var(--ink-2);">{{ Report::forHumans($row['median']) }}</td>
                            <td style="color: var(--ink-2);">{{ Report::forHumans($row['worst']) }}</td>
                            <td style="color: var(--ink-3);">{{ number_format($row['count']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="bn-note" style="margin-top: 0.9rem;">
            <strong>Typical</strong> is the middle job, so a single disaster does not
            drag it. Where <strong>worst</strong> is far above <strong>average</strong>,
            the step is usually fine and occasionally stalls — worth finding the one
            job rather than adding a machine.
        </p>
    @endif
</div>
@endsection
