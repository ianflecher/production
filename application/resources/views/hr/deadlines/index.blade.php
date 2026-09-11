@extends('layouts.app')

@section('title', 'HR deadlines — Imprint Production')
@section('page-title', 'HR deadlines')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Deadlines</h1>
        <p class="muted">Payslip cut-offs and government remittances. Soonest first; overdue ones are marked.</p>
    </div>
</div>

<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>Still to do</h2>
    @if ($outstanding->isEmpty())
        <p class="sub" style="margin:0;">Nothing outstanding.</p>
    @else
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Due</th><th>What</th><th>Kind</th><th>Note</th><th></th></tr></thead>
                <tbody>
                    @foreach ($outstanding as $d)
                        <tr>
                            <td style="{{ $d->isOverdue() ? 'color:#b91c1c; font-weight:700;' : 'font-weight:600;' }}">
                                {{ $d->due_on->format('M j, Y') }}
                                @if ($d->isOverdue())<div class="sub" style="color:#b91c1c;">{{ $d->due_on->diffForHumans() }}</div>@endif
                            </td>
                            <td>{{ $d->label }}</td>
                            <td>{{ $d->kindLabel() }}</td>
                            <td class="sub">{{ $d->note ?: '—' }}</td>
                            <td>
                                <div style="display:flex; gap:0.35rem;">
                                    <form method="POST" action="{{ route('hr.deadlines.toggle', $d) }}">
                                        @csrf<button class="btn btn-success btn-sm">Done</button>
                                    </form>
                                    <form method="POST" action="{{ route('hr.deadlines.destroy', $d) }}"
                                          onsubmit="return confirm('Remove this deadline?')">
                                        @csrf<button class="btn btn-ghost btn-sm">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="card panel" style="margin-bottom: 1.1rem;">
    <h2>Add a deadline</h2>
    <form method="POST" action="{{ route('hr.deadlines.store') }}" style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:flex-end;">
        @csrf
        <div style="flex:1 1 220px;">
            <label for="label">What</label>
            <input type="text" id="label" name="label" maxlength="120" placeholder="e.g. SSS remittance, September" required>
        </div>
        <div style="flex:0 1 170px;">
            <label for="kind">Kind</label>
            <select id="kind" name="kind" required>
                @foreach ($kinds as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div style="flex:0 1 170px;">
            <label for="due_on">Due</label>
            <input type="date" id="due_on" name="due_on" value="{{ now()->toDateString() }}" required>
        </div>
        <div style="flex:1 1 200px;">
            <label for="note">Note</label>
            <input type="text" id="note" name="note" maxlength="1000">
        </div>
        <button class="btn btn-primary">Add</button>
    </form>
</div>

@if ($done->isNotEmpty())
    <details class="card panel">
        <summary style="cursor:pointer; font-weight:700;">Done ({{ $done->count() }})</summary>
        <div class="tbl-wrap" style="margin-top:0.7rem;">
            <table class="tbl">
                <thead><tr><th>Due</th><th>What</th><th>Kind</th><th></th></tr></thead>
                <tbody>
                    @foreach ($done as $d)
                        <tr>
                            <td>{{ $d->due_on->format('M j, Y') }}</td>
                            <td>{{ $d->label }}</td>
                            <td>{{ $d->kindLabel() }}</td>
                            <td>
                                <form method="POST" action="{{ route('hr.deadlines.toggle', $d) }}">
                                    @csrf<button class="btn btn-ghost btn-sm">Put back</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
@endsection
