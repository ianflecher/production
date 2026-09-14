@extends('layouts.app')

@section('title', 'People — Imprint Production')
@section('page-title', 'People')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>People</h1>
        <p class="muted">Everyone with an employee record. Hiring somebody through HR creates theirs.</p>
    </div>
    <a href="{{ route('hr.employees.create') }}" class="btn btn-primary btn-sm">Add somebody</a>
</div>

@include('partials.list-search', [
    'action' => route('hr.employees.index'),
    'value' => $search,
    'placeholder' => 'Name, email, or position…',
    'label' => 'Search people',
])

@if ($employees->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin:0;">
            @if (filled($search))
                Nobody matches that.
            @else
                Nobody is on the books yet. Hiring through HR creates a record when
                the offer is accepted — for staff who were already here, add them.
            @endif
        </p>
    </div>
@else
    <div class="card panel">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Name</th><th>Position</th><th>Pay</th><th>Started</th><th>Owes</th></tr></thead>
                <tbody>
                    @foreach ($employees as $e)
                        <tr>
                            <td style="font-weight:600;">
                                <a href="{{ route('hr.employees.show', $e) }}">{{ $e->user?->name ?? '—' }}</a>
                                <div class="sub">{{ $e->user?->email }}</div>
                            </td>
                            <td>{{ $e->position ?: '—' }}</td>
                            <td>{{ $e->salary ? '₱'.number_format((float) $e->salary, 2) : '—' }}</td>
                            <td>{{ $e->started_on?->format('M j, Y') ?? '—' }}</td>
                            <td>{{ $e->loanBalance() > 0 ? '₱'.number_format($e->loanBalance(), 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
