{{-- The HR desk's hiring, as it appears on the dashboard.

     Expects: $hr (from App\Support\HrOverview::for). Everything here is a
     thing to DO — an applicant nobody has read, a round nobody has done —
     rather than a count, because a total nobody can act on gets ignored. --}}

@if ($hr['mine']->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.1rem;">
        <h2>Your interviews</h2>
        <p class="sub">Booked with you and not done yet.</p>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Applicant</th><th>Round</th><th>When</th><th>Wants</th></tr></thead>
                <tbody>
                    @foreach ($hr['mine'] as $i)
                        <tr>
                            <td style="font-weight:600;">
                                <a href="{{ route('hr.applicants.show', $i->applicant) }}">{{ $i->applicant?->fullName() }}</a>
                            </td>
                            <td>{{ $i->round }} of {{ \App\Models\HrInterview::MAX_ROUNDS }}</td>
                            <td>{{ $i->scheduled_at?->format('M j, g:ia') ?? 'No date set' }}</td>
                            <td>{{ $i->applicant?->position ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- Deadlines and requests first: both are somebody waiting on the office. --}}
@if ($hr['deadlines']->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.1rem;">
        <div style="display:flex; align-items:baseline; gap:0.6rem; flex-wrap:wrap;">
            <h2 style="margin:0;">Deadlines</h2>
            <a href="{{ route('hr.deadlines.index') }}" class="muted" style="font-size:0.82rem;">all deadlines →</a>
        </div>
        <p class="sub">Due this week or already past.</p>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Due</th><th>What</th><th>Kind</th></tr></thead>
                <tbody>
                    @foreach ($hr['deadlines'] as $d)
                        <tr>
                            <td style="{{ $d->isOverdue() ? 'color:#b91c1c; font-weight:700;' : 'font-weight:600;' }}">
                                {{ $d->due_on->format('M j') }}
                            </td>
                            <td>{{ $d->label }}</td>
                            <td>{{ $d->kindLabel() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($hr['requests']->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.1rem;">
        <h2>Requests waiting</h2>
        <p class="sub">Filed by staff and not answered yet.</p>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Who</th><th>What</th><th>When</th><th>Why</th></tr></thead>
                <tbody>
                    @foreach ($hr['requests'] as $r)
                        <tr>
                            <td style="font-weight:600;">
                                <a href="{{ route('hr.employees.show', $r->hr_employee_id) }}">{{ $r->employee?->user?->name ?? '—' }}</a>
                            </td>
                            <td>{{ $r->typeLabel() }}</td>
                            <td>{{ $r->whenLabel() }}</td>
                            <td class="sub">{{ Str::limit($r->reason, 50) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($hr['newApplicants']->isNotEmpty() || $hr['upcoming']->isNotEmpty() || $hr['passed']->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.1rem;">
        <div style="display:flex; align-items:baseline; gap:0.6rem; flex-wrap:wrap;">
            <h2 style="margin:0;">Hiring</h2>
            <a href="{{ route('hr.applicants.index') }}" class="muted" style="font-size:0.82rem;">all applicants →</a>
        </div>
        <p class="sub">
            @if ($hr['interviewing'] > 0) {{ $hr['interviewing'] }} being interviewed. @endif
        </p>

        @if ($hr['newApplicants']->isNotEmpty())
            <strong style="font-size:0.85rem;">New applications</strong>
            <div class="tbl-wrap" style="margin: 0.4rem 0 1rem;">
                <table class="tbl">
                    <thead><tr><th></th><th>Name</th><th>Wants</th><th>Contact</th><th>Applied</th></tr></thead>
                    <tbody>
                        @foreach ($hr['newApplicants'] as $a)
                            <tr>
                                <td style="width:54px;">
                                    @if ($a->hasPhoto())
                                        <img src="{{ route('hr.applicants.photo', $a) }}" alt=""
                                             style="width:42px;height:42px;object-fit:cover;border-radius:8px;display:block;">
                                    @endif
                                </td>
                                <td style="font-weight:600;">
                                    <a href="{{ route('hr.applicants.show', $a) }}">{{ $a->fullName() }}</a>
                                </td>
                                <td>{{ $a->position ?: '—' }}</td>
                                <td>{{ $a->contact_number }}</td>
                                <td>{{ $a->applied_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($hr['upcoming']->isNotEmpty())
            <strong style="font-size:0.85rem;">Interviews booked</strong>
            <div class="tbl-wrap" style="margin: 0.4rem 0 1rem;">
                <table class="tbl">
                    <thead><tr><th>Applicant</th><th>Round</th><th>Who</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($hr['upcoming'] as $i)
                            <tr>
                                <td style="font-weight:600;">
                                    <a href="{{ route('hr.applicants.show', $i->applicant) }}">{{ $i->applicant?->fullName() }}</a>
                                </td>
                                <td>{{ $i->round }} of {{ \App\Models\HrInterview::MAX_ROUNDS }}</td>
                                <td>{{ $i->interviewer?->name ?? '—' }}</td>
                                <td>{{ $i->scheduled_at?->format('M j, g:ia') ?? 'No date set' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($hr['passed']->isNotEmpty())
            <strong style="font-size:0.85rem;">Passed — waiting for an offer</strong>
            <div class="tbl-wrap" style="margin-top: 0.4rem;">
                <table class="tbl">
                    <thead><tr><th>Name</th><th>Wants</th><th>Contact</th></tr></thead>
                    <tbody>
                        @foreach ($hr['passed'] as $a)
                            <tr>
                                <td style="font-weight:600;">
                                    <a href="{{ route('hr.applicants.show', $a) }}">{{ $a->fullName() }}</a>
                                </td>
                                <td>{{ $a->position ?: '—' }}</td>
                                <td>{{ $a->contact_number }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
