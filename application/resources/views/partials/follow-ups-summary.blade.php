{{-- Who is waiting, on the dashboard.

     Names only. The dashboard's job is to say that somebody is still waiting
     to be called; the Follow-ups tab is where the calling is done. Putting the
     whole working list in both places made the dashboard long enough that the
     orders below it fell off the screen.

     Expects: $followUps, $user. --}}
@if (isset($followUps) && $followUps->isNotEmpty())
    <div class="dash-card" id="follow-ups">
        <div class="dash-card-head">
            <div>
                <h2>Follow-ups</h2>
                <p>
                    {{ $followUps->count() }} {{ Str::plural('client', $followUps->count()) }}
                    asked and {{ $followUps->count() === 1 ? 'has' : 'have' }} not ordered.
                    @if ($user->leadsTeam())
                        Your whole {{ strtoupper($user->team) }} team.
                    @endif
                </p>
            </div>

            <a href="{{ route('inquiries.index') }}" class="dash-card-link">Follow them up</a>
        </div>

        <div class="follow-up-names">
            @foreach ($followUps->take(12) as $inq)
                <a href="{{ route('inquiries.index') }}" class="follow-up-name">
                    {{ $inq->client->fullName() }}
                    <small>{{ $inq->created_at->diffForHumans(short: true) }}</small>
                </a>
            @endforeach

            @if ($followUps->count() > 12)
                <span class="follow-up-more">+{{ $followUps->count() - 12 }} more</span>
            @endif
        </div>
    </div>
@endif
