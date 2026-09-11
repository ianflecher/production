@extends('layouts.app')

@section('title', $applicant->fullName().' — Imprint Production')
@section('page-title', 'Applicant')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>{{ $applicant->fullName() }}</h1>
        <p class="muted">
            Applied {{ $applicant->applied_at?->format('M j, Y \a\t g:ia') ?? '—' }} ·
            {{ $applicant->statusLabel() }}
        </p>
    </div>
    <a href="{{ route('hr.applicants.index') }}" class="btn btn-ghost btn-sm">← Back to applicants</a>
</div>

<div class="card panel" style="display: flex; gap: 1.4rem; flex-wrap: wrap;">
    <div style="flex: 0 0 220px;">
        @if ($applicant->hasPhoto())
            <img src="{{ route('hr.applicants.photo', $applicant) }}" alt="{{ $applicant->fullName() }}"
                 style="width:220px; height:280px; object-fit:cover; border-radius:12px; display:block;">
        @else
            <div style="width:220px; height:280px; border-radius:12px; background:var(--line, #E5E9F0); display:grid; place-items:center; color:var(--ink-3);">
                No photo
            </div>
        @endif
    </div>

    <div style="flex: 1 1 320px;">
        <table class="tbl">
            <tbody>
                <tr><th style="width: 40%;">Wants</th><td>{{ $applicant->position ?: '—' }}</td></tr>
                <tr><th>Contact number</th><td>{{ $applicant->contact_number }}</td></tr>
                <tr><th>Email</th><td>{{ $applicant->email ?: '—' }}</td></tr>
                <tr><th>Address</th><td>{{ $applicant->address ?: '—' }}</td></tr>
                <tr><th>Birthday</th><td>{{ $applicant->birthdate?->format('M j, Y') ?: '—' }}</td></tr>
            </tbody>
        </table>

        @if ($applicant->about)
            <div style="margin-top: 1rem;">
                <strong style="font-size: 0.85rem;">About them</strong>
                <p class="sub" style="white-space: pre-line; margin-top: 0.3rem;">{{ $applicant->about }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route('hr.applicants.status', $applicant) }}"
              style="display:flex; gap:0.5rem; align-items:center; margin-top: 1.2rem; flex-wrap: wrap;">
            @csrf
            <label for="status" style="font-size: 0.82rem; font-weight: 600;">Status</label>
            <select id="status" name="status" style="width:auto; min-width: 170px;">
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected($applicant->status === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn btn-primary btn-sm">Save</button>
        </form>

    </div>
</div>

<div class="card panel" style="margin-top: 1.1rem;">
    <h2>Interviews</h2>
    <p class="sub">
        Up to {{ \App\Models\HrInterview::MAX_ROUNDS }} rounds. Whoever you book it with is told straight away.
    </p>

    @if ($applicant->interviews->isEmpty())
        <p class="sub">No interview has been arranged yet.</p>
    @else
        <div class="tbl-wrap" style="margin-bottom: 1.1rem;">
            <table class="tbl">
                <thead><tr><th>Round</th><th>Who</th><th>When</th><th>How it went</th></tr></thead>
                <tbody>
                    @foreach ($applicant->interviews as $i)
                        <tr>
                            <td>{{ $i->round }} of {{ \App\Models\HrInterview::MAX_ROUNDS }}</td>
                            <td>{{ $i->interviewer?->name ?? '—' }}</td>
                            <td>{{ $i->scheduled_at?->format('M j, Y g:ia') ?? 'No date set' }}</td>
                            <td>
                                @if ($i->isDone())
                                    <strong>{{ $i->outcomeLabel() }}</strong>
                                    @if ($i->notes)
                                        <div class="sub" style="white-space: pre-line; margin-top:0.25rem;">{{ $i->notes }}</div>
                                    @endif
                                @else
                                    {{-- Not done yet, so this is where it gets written down. --}}
                                    <form method="POST" action="{{ route('hr.interviews.record', $i) }}"
                                          style="display:grid; gap:0.4rem; max-width: 380px;">
                                        @csrf
                                        <textarea name="notes" maxlength="2000" rows="2"
                                                  placeholder="How did it go?"></textarea>
                                        <div style="display:flex; gap:0.4rem;">
                                            <button class="btn btn-success btn-sm" name="outcome" value="passed">Passed</button>
                                            <button class="btn btn-danger btn-sm" name="outcome" value="failed">Did not pass</button>
                                        </div>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($applicant->canScheduleInterview())
        <form method="POST" action="{{ route('hr.interviews.store', $applicant) }}"
              style="display:flex; gap:0.6rem; align-items:flex-end; flex-wrap:wrap;">
            @csrf
            <div>
                <label for="interviewer_id" style="font-size:0.82rem; font-weight:600;">Round {{ $applicant->nextRound() }} — who is interviewing</label>
                <select id="interviewer_id" name="interviewer_id" style="min-width: 220px;" required>
                    <option value="">— choose somebody —</option>
                    @foreach ($interviewers as $person)
                        <option value="{{ $person->id }}">{{ $person->name }} · {{ $person->positionLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="scheduled_at" style="font-size:0.82rem; font-weight:600;">When <span style="font-weight:400; color:var(--ink-3);">(optional)</span></label>
                <input type="datetime-local" id="scheduled_at" name="scheduled_at">
            </div>
            <button class="btn btn-primary">Book it</button>
        </form>
    @elseif (in_array($applicant->status, [\App\Models\HrApplicant::STATUS_PASSED, \App\Models\HrApplicant::STATUS_HIRED], true))
        <p class="sub" style="margin:0;">Passed every round.</p>
    @elseif ($applicant->status === \App\Models\HrApplicant::STATUS_SET_ASIDE)
        <p class="sub" style="margin:0;">Set aside — no further rounds.</p>
    @else
        <p class="sub" style="margin:0;">A round is already booked. Write down how it went before arranging another.</p>
    @endif
</div>

<div class="card panel" style="margin-top: 1.1rem;">
    <h2>Job offer</h2>

    @forelse ($applicant->offers as $offer)
        <div style="display:flex; gap:0.8rem; align-items:center; flex-wrap:wrap; padding:0.5rem 0; border-bottom:1px solid var(--line, #E5E9F0);">
            <div style="flex:1 1 240px;">
                <strong>{{ $offer->position }}</strong>
                <div class="sub">
                    {{ $offer->salary ? '₱'.number_format((float) $offer->salary, 2).' '.$offer->periodLabel() : 'No salary set' }}
                    · {{ $offer->statusLabel() }}
                </div>
            </div>
            <a href="{{ route('hr.offers.edit', $offer) }}" class="btn btn-ghost btn-sm">Open the offer</a>
        </div>
    @empty
        <p class="sub">No offer has been written yet.</p>
    @endforelse

    @if ($applicant->status === \App\Models\HrApplicant::STATUS_PASSED
         && $applicant->offers->whereIn('status', ['draft', 'sent'])->isEmpty())
        <form method="POST" action="{{ route('hr.offers.store', $applicant) }}" style="margin-top:0.9rem;">
            @csrf
            <button class="btn btn-primary">Write a job offer</button>
        </form>
    @endif
</div>
@endsection
