@extends('layouts.app')

@section('title', 'Add to the HR books — Imprint Production')
@section('page-title', 'Add to the HR books')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Add somebody to the HR books</h1>
        <p class="muted">
            For staff who were already working here when HR arrived. Anyone hired
            through HR gets their record when they accept the offer.
        </p>
    </div>
    <a href="{{ route('hr.employees.index') }}" class="btn btn-ghost btn-sm">← All people</a>
</div>

@if ($candidates->isEmpty())
    <div class="card panel">
        <h2>Everybody is on the books</h2>
        <p class="sub" style="margin:0;">
            Every active login already has an employee record. A new person gets
            theirs by being hired through Applicants.
        </p>
    </div>
@else
<div class="card panel">
    <form method="POST" action="{{ route('hr.employees.store') }}" style="display:grid; gap:0.9rem; max-width:660px;">
        @csrf

        <div>
            <label for="user_id">Who</label>
            <select id="user_id" name="user_id" required>
                <option value="">Choose a person…</option>
                @foreach ($candidates as $c)
                    <option value="{{ $c->id }}" @selected(old('user_id') == $c->id)>
                        {{ $c->name }} — {{ $c->job_role }}
                    </option>
                @endforeach
            </select>
            <p class="sub" style="margin:.3rem 0 0;">
                Only people without a record are listed. {{ $candidates->count() }}
                {{ Str::plural('person', $candidates->count()) }} left.
            </p>
        </div>

        <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
            <div style="flex:1 1 240px;">
                <label for="position">Position</label>
                <input type="text" id="position" name="position" value="{{ old('position') }}"
                       maxlength="120" placeholder="Printer, Artist, Account Officer…">
            </div>
            <div style="flex:0 1 160px;">
                <label for="started_on">Started</label>
                <input type="date" id="started_on" name="started_on" value="{{ old('started_on') }}">
            </div>
        </div>

        <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
            <div style="flex:1 1 180px;">
                <label for="salary">Salary</label>
                <input type="number" id="salary" name="salary" value="{{ old('salary') }}"
                       step="0.01" min="0" placeholder="0.00">
            </div>
            <div style="flex:1 1 180px;">
                <label for="salary_period">Paid</label>
                <select id="salary_period" name="salary_period" required>
                    @foreach ($periods as $key => $label)
                        <option value="{{ $key }}" @selected(old('salary_period', 'monthly') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
            <div style="flex:1 1 180px;">
                <label for="vacation_credits">Vacation leave (days a year)</label>
                <input type="number" id="vacation_credits" name="vacation_credits"
                       value="{{ old('vacation_credits') }}" min="0" max="365" placeholder="Leave blank if not set">
            </div>
            <div style="flex:1 1 180px;">
                <label for="sick_credits">Sick leave (days a year)</label>
                <input type="number" id="sick_credits" name="sick_credits"
                       value="{{ old('sick_credits') }}" min="0" max="365" placeholder="Leave blank if not set">
            </div>
        </div>
        <p class="sub" style="margin:-.4rem 0 0;">
            Left blank means no allowance has been set, and they are told nothing
            about a balance. Zero means none — the two are not the same.
        </p>

        <div>
            <button class="btn btn-primary">Add to the books</button>
        </div>
    </form>
</div>
@endif
@endsection
