@extends('layouts.app')

@section('title', 'Job offer — '.$offer->applicant?->fullName())
@section('page-title', 'Job offer')

@section('content')
@php $applicant = $offer->applicant; @endphp

<div class="page-head">
    <div class="grow">
        <h1>{{ $applicant?->fullName() }}</h1>
        <p class="muted">{{ $offer->statusLabel() }}@if ($offer->sent_at) · given {{ $offer->sent_at->format('M j, Y') }}@endif</p>
    </div>
    <a href="{{ route('hr.applicants.show', $applicant) }}" class="btn btn-ghost btn-sm">← Back to applicant</a>
</div>

{{-- Shown once, right after the account is made. It is not stored in readable
     form anywhere, so this is the only time anybody can read it. --}}
@if (session('newAccount'))
    @php $acc = session('newAccount'); @endphp
    <div class="card panel" style="margin-bottom: 1.1rem; border-color: #15803d;">
        <h2>Their account is ready</h2>
        <p class="sub">
            Write this down or hand it over now — the password is not saved anywhere you can read it again.
            They will be made to choose their own the first time they sign in.
        </p>
        <table class="tbl" style="max-width: 460px;">
            <tbody>
                <tr><th style="width:40%;">Sign in with</th><td style="font-weight:600;">{{ $acc['email'] }}</td></tr>
                <tr><th>Temporary password</th><td style="font-family: ui-monospace, monospace; font-weight:700; font-size:1.05rem;">{{ $acc['password'] }}</td></tr>
            </tbody>
        </table>
    </div>
@endif

<div class="card panel">
    <h2>What is being offered</h2>
    <p class="sub">
        A starting point, not a form — every line can be changed before it is given to them.
    </p>

    @if ($offer->isOpen())
        <form method="POST" action="{{ route('hr.offers.update', $offer) }}" class="bk-form" style="display:grid; gap:0.9rem; max-width: 720px;">
            @csrf
            <div style="display:flex; gap:0.8rem; flex-wrap:wrap;">
                <div style="flex:1 1 260px;">
                    <label for="position">Position</label>
                    <input type="text" id="position" name="position" value="{{ old('position', $offer->position) }}" maxlength="120" required>
                </div>
                <div style="flex:0 1 180px;">
                    <label for="starts_on">Starts on</label>
                    <input type="date" id="starts_on" name="starts_on" value="{{ old('starts_on', $offer->starts_on?->toDateString()) }}">
                </div>
            </div>

            <div style="display:flex; gap:0.8rem; flex-wrap:wrap;">
                <div style="flex:0 1 200px;">
                    <label for="salary">Salary</label>
                    <input type="number" step="0.01" min="0" id="salary" name="salary" value="{{ old('salary', $offer->salary) }}" placeholder="0.00">
                </div>
                <div style="flex:0 1 200px;">
                    <label for="salary_period">Paid</label>
                    <select id="salary_period" name="salary_period">
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}" @selected(old('salary_period', $offer->salary_period) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label for="scope">Scope of the job</label>
                <textarea id="scope" name="scope" rows="5" maxlength="5000">{{ old('scope', $offer->scope) }}</textarea>
            </div>

            <div>
                <label for="terms">Terms</label>
                <textarea id="terms" name="terms" rows="4" maxlength="5000">{{ old('terms', $offer->terms) }}</textarea>
            </div>

            <div><button class="btn btn-primary">Save offer</button></div>
        </form>
    @else
        <table class="tbl" style="max-width: 720px;">
            <tbody>
                <tr><th style="width:30%;">Position</th><td>{{ $offer->position }}</td></tr>
                <tr><th>Salary</th><td>{{ $offer->salary ? '₱'.number_format((float) $offer->salary, 2).' '.$offer->periodLabel() : '—' }}</td></tr>
                <tr><th>Starts on</th><td>{{ $offer->starts_on?->format('M j, Y') ?? '—' }}</td></tr>
                <tr><th>Scope</th><td style="white-space: pre-line;">{{ $offer->scope ?: '—' }}</td></tr>
                <tr><th>Terms</th><td style="white-space: pre-line;">{{ $offer->terms ?: '—' }}</td></tr>
            </tbody>
        </table>
    @endif
</div>

@if ($offer->status === \App\Models\HrJobOffer::STATUS_DRAFT)
    <div class="card panel" style="margin-top: 1.1rem;">
        <h2>Give it to them</h2>
        <p class="sub">Marks the offer as handed over. You can still record a yes or a no afterwards.</p>
        <form method="POST" action="{{ route('hr.offers.send', $offer) }}">
            @csrf
            <button class="btn btn-primary">Mark as given to them</button>
        </form>
    </div>
@endif

@if ($offer->isOpen())
    <div class="card panel" style="margin-top: 1.1rem;">
        <h2>Their answer</h2>
        <p class="sub">
            A yes makes their account straight away. The address you put here is what they sign in with,
            and the password is shown to you once on the next screen.
        </p>

        <form method="POST" action="{{ route('hr.offers.accept', $offer) }}"
              style="display:flex; gap:0.6rem; align-items:flex-end; flex-wrap:wrap; margin-bottom: 1rem;">
            @csrf
            <div style="flex:1 1 260px;">
                <label for="email">Sign-in address</label>
                <input type="email" id="email" name="email" maxlength="180" required
                       value="{{ old('email', Str::slug($applicant?->fullName() ?? '', '.').'@imprintcustoms.ph') }}">
            </div>
            <div style="flex:0 1 220px;">
                <label for="job_role">Position on the floor</label>
                <select id="job_role" name="job_role" required>
                    @foreach (\App\Models\User::positionGroups() as $group => $items)
                        <optgroup label="{{ $group }}">
                            @foreach ($items as $value => $label)
                                <option value="{{ $value }}" @selected(strtolower($offer->position) === strtolower($label))>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-success">They accepted — make the account</button>
        </form>

        <form method="POST" action="{{ route('hr.offers.decline', $offer) }}"
              onsubmit="return confirm('Record that they turned the job down?')">
            @csrf
            <button class="btn btn-ghost btn-sm">They declined</button>
        </form>
    </div>
@endif
@endsection
