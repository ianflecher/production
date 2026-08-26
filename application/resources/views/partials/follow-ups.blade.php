{{-- The working follow-up list: one block per person waiting.

     A block rather than a table row, because each one carries the name, where
     they are from, what they asked for, what was said last time, and the box
     to say what was said this time. A table made every one of those a column,
     and the box to type in had to hide behind a toggle to fit — which is how
     a list stops being worked.

     A name leaves this list one way only: by ordering. There is nothing to
     schedule and nothing to dismiss, so the box to log a call is simply open
     under every row, all the time.

     Expects: $followUps, $user. Optional: $showOfficer, to say whose each one
     is — off when the caller has already grouped them under that name. --}}
@php $showOfficer = $showOfficer ?? $user->leadsTeam(); @endphp

<div class="follow-up-list">
    @foreach ($followUps as $inq)
        <div class="follow-up">
            <div class="follow-up-head">
                <div>
                    <div class="follow-up-client">
                        {{ $inq->client->fullName() }}
                        @if ($inq->client->company)
                            <span class="follow-up-company">{{ $inq->client->company }}</span>
                        @endif
                    </div>

                    {{-- Where they are from. Taken on step 1 and then never
                         shown, which made two clients of the same name
                         impossible to tell apart and gave no clue whether a
                         job was local or a delivery. --}}
                    @php $from = $inq->client->office_address ?: $inq->client->delivery_address; @endphp
                    @if ($from)
                        <div class="follow-up-from">{{ $from }}</div>
                    @endif

                    <div class="follow-up-meta">
                        {{ $inq->client->contact_number ?: 'no number' }}
                        @if ($showOfficer && $inq->officer)
                            · {{ $inq->officer->name }}
                        @endif
                        · asked {{ $inq->created_at->diffForHumans() }}
                    </div>
                </div>

                <a href="{{ route('inquiries.layout', $inq) }}" class="btn btn-primary btn-sm">
                    Design brief
                </a>
            </div>

            @if ($inq->what_they_want)
                <div class="follow-up-ask">{{ $inq->what_they_want }}</div>
            @endif

            @if ($inq->followUps->isNotEmpty())
                <ul class="follow-up-log">
                    @foreach ($inq->followUps->take(3) as $log)
                        <li>
                            <span>{{ $log->created_at->format('M j') }}</span>
                            {{ $log->note }}
                            @if ($user->leadsTeam() && $log->user)
                                <em>— {{ $log->user->name }}</em>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('inquiries.follow-up', $inq) }}" class="follow-up-form">
                @csrf
                <input type="text" name="note" maxlength="2000" required
                       placeholder="What they said when you called…">
                <button type="submit" class="btn btn-ghost btn-sm">Log</button>
            </form>
        </div>
    @endforeach
</div>
