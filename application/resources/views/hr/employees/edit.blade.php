@extends('layouts.app')

@section('title', $employee->user?->name.' — Imprint Production')
@section('page-title', 'Employment file')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>{{ $employee->user?->name ?? 'Employment file' }}</h1>
        <p class="muted">What the shop pays them and what they are allowed off.</p>
    </div>
    <a href="{{ route('hr.employees.show', $employee) }}" class="btn btn-ghost btn-sm">← Back to their file</a>
</div>

<div class="card panel">
    <form method="POST" action="{{ route('hr.employees.update', $employee) }}" style="display:grid; gap:0.9rem; max-width:660px;">
        @csrf
        @method('PUT')

        <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
            <div style="flex:1 1 240px;">
                <label for="position">Position</label>
                <input type="text" id="position" name="position"
                       value="{{ old('position', $employee->position) }}" maxlength="120">
            </div>
            <div style="flex:0 1 160px;">
                <label for="started_on">Started</label>
                <input type="date" id="started_on" name="started_on"
                       value="{{ old('started_on', $employee->started_on?->format('Y-m-d')) }}">
            </div>
        </div>

        <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
            <div style="flex:1 1 180px;">
                <label for="salary">Salary</label>
                <input type="number" id="salary" name="salary"
                       value="{{ old('salary', $employee->salary) }}" step="0.01" min="0">
            </div>
            <div style="flex:1 1 180px;">
                <label for="salary_period">Paid</label>
                <select id="salary_period" name="salary_period" required>
                    @foreach ($periods as $key => $label)
                        <option value="{{ $key }}" @selected(old('salary_period', $employee->salary_period) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:flex; gap:0.7rem; flex-wrap:wrap;">
            <div style="flex:1 1 180px;">
                <label for="vacation_credits">Vacation leave (days a year)</label>
                <input type="number" id="vacation_credits" name="vacation_credits"
                       value="{{ old('vacation_credits', $employee->vacation_credits) }}"
                       min="0" max="365" placeholder="Leave blank if not set">
            </div>
            <div style="flex:1 1 180px;">
                <label for="sick_credits">Sick leave (days a year)</label>
                <input type="number" id="sick_credits" name="sick_credits"
                       value="{{ old('sick_credits', $employee->sick_credits) }}"
                       min="0" max="365" placeholder="Leave blank if not set">
            </div>
        </div>
        <p class="sub" style="margin:-.4rem 0 0;">
            Left blank means no allowance has been set, and they are told nothing
            about a balance. Zero means none — the two are not the same.
            Changing this does not alter leave already granted.
        </p>

        {{-- Kept apart from the rest: filling it in ends their employment, and
             that should not sit between two boxes about pay. --}}
        <div style="border-top:1px solid var(--border); padding-top:.9rem;">
            <label for="ended_on">Last day (leave blank while they still work here)</label>
            <input type="date" id="ended_on" name="ended_on" style="max-width:200px;"
                   value="{{ old('ended_on', $employee->ended_on?->format('Y-m-d')) }}">
        </div>

        <div>
            <button class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
@endsection
