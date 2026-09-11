@extends('layouts.app')

@section('title', 'Applicants — Imprint Production')
@section('page-title', 'Applicants')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Who has applied</h1>
        <p class="muted">
            Sent in from the public application page. Newest first.
            @if ($newCount > 0)
                <strong>{{ $newCount }}</strong> still to look at.
            @endif
        </p>
    </div>
</div>

@include('partials.list-search', [
    'action' => route('hr.applicants.index'),
    'value' => $search,
    'placeholder' => 'Name, number, or the position they want…',
    'label' => 'Search applicants',
    'keep' => ['status' => $status],
])

<div class="tp-actions no-print" style="margin-bottom: 1rem;">
    <a href="{{ route('hr.applicants.index', ['q' => $search ?: null]) }}"
       class="btn btn-sm {{ $status === '' ? 'btn-primary' : 'btn-ghost' }}">All</a>
    @foreach ($statuses as $key => $label)
        <a href="{{ route('hr.applicants.index', ['status' => $key, 'q' => $search ?: null]) }}"
           class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-ghost' }}">{{ $label }}</a>
    @endforeach
</div>

@if ($applicants->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin: 0;">
            @if (filled($search) || filled($status))
                Nobody matches what you searched for.
            @else
                Nobody has applied yet. Anyone who fills in the public form appears here.
            @endif
        </p>
    </div>
@else
    <div class="card panel">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th></th>
                        <th>Name</th>
                        <th>Wants</th>
                        <th>Contact</th>
                        <th>Applied</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($applicants as $a)
                        <tr>
                            <td style="width: 54px;">
                                @if ($a->hasPhoto())
                                    <img src="{{ route('hr.applicants.photo', $a) }}" alt=""
                                         style="width:42px; height:42px; object-fit:cover; border-radius:8px; display:block;">
                                @else
                                    <div style="width:42px; height:42px; border-radius:8px; background:var(--line, #E5E9F0); display:grid; place-items:center; color:var(--ink-3); font-size:0.85rem;">
                                        {{ strtoupper(substr($a->first_name, 0, 1)) }}
                                    </div>
                                @endif
                            </td>
                            <td style="font-weight: 600;">
                                <a href="{{ route('hr.applicants.show', $a) }}">{{ $a->fullName() }}</a>
                            </td>
                            <td>{{ $a->position ?: '—' }}</td>
                            <td>{{ $a->contact_number }}</td>
                            <td>{{ $a->applied_at?->diffForHumans() ?? '—' }}</td>
                            <td>{{ $a->statusLabel() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
