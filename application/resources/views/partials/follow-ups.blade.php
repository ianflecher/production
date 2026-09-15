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

                    {{-- Which of the two kinds of work this is. Only the
                         artist leader is shown it: he is the one who filters
                         on it, and on the "All" view a list with no marks on
                         it does not say which rows the filter would keep. --}}
                    @if ($user->isArtistLead())
                        @php $revision = $inq->isRevision(); @endphp
                        <span class="follow-up-kind {{ $revision ? 'is-revision' : 'is-new' }}">
                            {{ $revision ? 'Revision' : 'New design' }}
                        </span>
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

            {{-- What is actually outstanding on this name.

                 A brief whose products ARE its designs usually has nothing
                 typed above, so the row was a name and a number: nothing said
                 why it was still on the list, and one with four approved
                 designs waiting to be written up looked like one with none.

                 The approved count is the line that matters - those are jobs
                 nobody has written yet, and writing them is the only way the
                 name comes off this list. --}}
            @php
                $holding = $inq->designsHoldingTheFollowUp();
                $awaitingOrder = $holding->where('status', \App\Models\InquiryDesign::STATUS_APPROVED);
                $withClient = $holding->where('status', \App\Models\InquiryDesign::STATUS_SUBMITTED);
                $beingDrawn = $holding->where('status', \App\Models\InquiryDesign::STATUS_WITH_ARTIST);
                $notSentYet = $holding->where('status', \App\Models\InquiryDesign::STATUS_BRIEF);
            @endphp

            @if ($holding->isNotEmpty())
                <div class="follow-up-designs">
                    @if ($awaitingOrder->isNotEmpty())
                        <span class="fu-design is-approved">
                            &#10003; {{ $awaitingOrder->count() }} approved &mdash;
                            {{ $awaitingOrder->count() === 1 ? 'needs an order' : 'need orders' }}
                        </span>
                    @endif
                    @if ($withClient->isNotEmpty())
                        <span class="fu-design is-waiting">{{ $withClient->count() }} with the client</span>
                    @endif
                    @if ($beingDrawn->isNotEmpty())
                        <span class="fu-design is-drawing">{{ $beingDrawn->count() }} being drawn</span>
                    @endif
                    {{-- Still on the officer's own desk. Counted, and named
                         rather than lumped in with the rest, because this one
                         is the reader's own unfinished work and nobody else
                         is going to move it. --}}
                    @if ($notSentYet->isNotEmpty())
                        <span class="fu-design is-unsent">{{ $notSentYet->count() }} not sent yet</span>
                    @endif
                </div>
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

            {{-- Chasing the client is the office's job. The artist leader is
                 on this page to find a brief and move its layout, not to ring
                 anybody, and the route would refuse him anyway — a box that
                 answers 403 is worse than no box. --}}
            @unless ($user->isArtistLead())
                <form method="POST" action="{{ route('inquiries.follow-up', $inq) }}" class="follow-up-form">
                    @csrf
                    <input type="text" name="note" maxlength="2000" required
                           placeholder="What they said when you called…">
                    <button type="submit" class="btn btn-ghost btn-sm">Log</button>
                </form>
            @endunless
        </div>
    @endforeach
</div>
