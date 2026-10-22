@extends('layouts.app')

@section('title', 'Follow-ups — Imprint Production')
@section('page-title', 'Follow-ups')

@section('content')

@php $me = auth()->user(); @endphp

<div class="page-head">
    <div>
        <p class="sub">
            People who asked and have not ordered. A name leaves this list one way only — by ordering.
            @if ($me->leadsTeam())
                You are seeing the whole {{ strtoupper($me->team) }} team, by officer.
            @endif
        </p>
    </div>

    <a href="{{ route('inquiries.create') }}" class="btn btn-primary">+ New inquiry</a>
</div>

@php
    $kind = $kind ?? '';
    $team = $team ?? '';
    // What is on now. Each filter link starts from this and changes only its
    // own key, so choosing a team does not quietly throw away the kind — and
    // the empty values drop out, which is what "All" is.
    $now = array_filter(['q' => $search ?? '', 'kind' => $kind, 'team' => $team, 'officer' => $officerId ?? 0]);
    $filterLink = fn (array $change) => route('inquiries.index', array_filter(array_merge($now, $change)));
    // The artist leader reads this page for different reasons than the office
    // does: he is looking for work to hand out, not clients to ring. The two
    // things that sort that work for him are whether a drawing is new or has
    // come back, and which team it belongs to.
    $isArtistLead = $me->isArtistLead();
@endphp

{{-- One toolbar across the top holding everything that narrows the list. The
     search sits at one end and the filters at the other, so the bar fills the
     width without a search box stretched half across the screen to fill it. --}}
<div class="list-toolbar{{ $isArtistLead ? ' is-filtered' : '' }}">

@include('partials.list-search', [
    // Clearing the search leaves the kind filter standing — they are two
    // separate questions, and clearing one should not silently answer the
    // other. The hidden field is what carries it through a search; a GET form
    // drops the action's own query string when it submits.
    'action' => route('inquiries.index', array_filter(['kind' => $kind, 'team' => $team])),
    'value' => $search ?? '',
    'placeholder' => 'Client, company, number, or what they asked for…',
    'label' => 'Search follow-ups',
    'keep' => ['kind' => $kind, 'team' => $team, 'officer' => $officerId ?? 0],
])

@if ($isArtistLead)
    {{-- Two segmented controls rather than six loose buttons: each one is a
         single question with one answer on, which is what a row of separate
         pills does not say. --}}
    <div class="toolbar-filters">
        <div class="seg" role="group" aria-label="Show new designs or revisions">
            @foreach ([
                '' => 'All',
                \App\Models\Inquiry::KIND_NEW => 'New designs',
                \App\Models\Inquiry::KIND_REVISION => 'Revisions',
            ] as $value => $label)
                <a href="{{ $filterLink(['kind' => $value]) }}"
                   @if ($kind === $value) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div>

        <div class="seg" role="group" aria-label="Show one team">
            @foreach (['' => 'All teams', 'meta' => 'META', 'vip' => 'VIP'] as $value => $label)
                <a href="{{ $filterLink(['team' => $value]) }}"
                   @if ($team === $value) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div>
    </div>
@endif

</div>

@if (($officers ?? collect())->isNotEmpty())
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem;" aria-label="Filter follow-ups by account officer">
        <a class="btn {{ empty($officerId) ? 'btn-primary' : 'btn-ghost' }} btn-sm" href="{{ $filterLink(['officer' => null]) }}">All officers</a>
        @foreach ($officers as $officer)
            <a class="btn {{ ($officerId ?? 0) === $officer->id ? 'btn-primary' : 'btn-ghost' }} btn-sm"
               href="{{ $filterLink(['officer' => $officer->id]) }}"
               @if (($officerId ?? 0) === $officer->id) aria-current="page" @endif>{{ $officer->name }}</a>
        @endforeach
    </div>
@endif

@if ($me->canTransferFollowUps())
    <details class="card panel" style="margin-bottom:1rem;">
        <summary style="cursor:pointer; font-weight:700;">Transfer follow-ups to another officer</summary>
        <p class="sub" style="margin-top:0.75rem;">Transfer all pending inquiries belonging to the selected officer. Designs, files, and previous follow-up notes stay attached. Existing production orders keep their current owner.</p>
        <form method="POST" action="{{ route('inquiries.transfer-follow-ups') }}"
              onsubmit="return confirm('Transfer ALL pending follow-ups from the selected officer to the new officer? This includes inquiries outside the current search filters.')">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label for="from_officer">From officer</label>
                    <select id="from_officer" name="from_officer" required>
                        <option value="">Choose current officer</option>
                        @foreach ($officers as $officer)
                            <option value="{{ $officer->id }}">{{ $officer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="to_officer">Transfer to</label>
                    <select id="to_officer" name="to_officer" required>
                        <option value="">Choose receiving officer</option>
                        @foreach ($transferOfficers as $officer)
                            <option value="{{ $officer->id }}">{{ $officer->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <button class="btn btn-primary" type="submit">Transfer all pending follow-ups</button>
        </form>
    </details>
@endif

@if ($followUps->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin: 0;">
            {{-- An empty list means two different things. Telling somebody who
                 searched for a name that every inquiry has become an order
                 reads as "that client ordered already", which is the opposite
                 of what happened. --}}
            {{-- An empty list means several different things, and saying the
                 wrong one is worse than saying nothing. "Every inquiry has
                 become an order" in front of somebody who has a team filter
                 on reads as good news about a list they cannot see. --}}
            @php $on = trim(($team !== '' ? strtoupper($team).' ' : '')); @endphp

            @if (filled($search ?? ''))
                Nobody on the follow-up list matches “{{ $search }}”.
            @elseif ($kind === \App\Models\Inquiry::KIND_REVISION)
                No {{ $on }} layout has been sent back. Nothing is waiting on a revision.
            @elseif ($kind === \App\Models\Inquiry::KIND_NEW)
                Nothing new on {{ $on ?: 'the list' }}. Every brief has already been sent back once.
            @elseif ($team !== '')
                Nobody on {{ $on }} is waiting.
            @else
                Nobody is waiting. Every inquiry taken so far has become an order.
            @endif

            @if ($team !== '' || $kind !== '')
                <a href="{{ route('inquiries.index', array_filter(['q' => $search ?? ''])) }}">Show everything</a>.
            @endif
        </p>
    </div>

@elseif ($isArtistLead)
    {{-- META and VIP are two different books of clients with two different
         sets of officers behind them. Run together the artist leader cannot
         see at a glance which team's work is piling up, which is half of what
         he is deciding when he hands a brief out. Named teams first and in
         order, then whatever has no team on it — there is no sense putting
         the odd one at the top. --}}
    @php
        $byTeam = $followUps
            ->groupBy(fn ($inq) => strtoupper(trim((string) $inq->team)))
            ->sortBy(fn ($list, $team) => $team === '' ? 'zzz' : $team);
    @endphp

    @foreach ($byTeam as $team => $list)
        <div class="card panel" style="margin-bottom: 1.1rem;">
            <div class="officer-head">
                <h2>{{ $team !== '' ? $team : 'No team' }}</h2>
                <span class="officer-count">
                    {{ $list->count() }} {{ Str::plural('client', $list->count()) }} waiting
                </span>
            </div>

            @include('partials.follow-ups', ['followUps' => $list, 'user' => $me])
        </div>
    @endforeach

@elseif ($me->leadsTeam() || $me->isLeader())
    {{-- A team leader is looking at several people's work at once. Run
         together in one list it is a pile of names; split by whose it is, it
         says who on the team is carrying what — which is the thing a leader
         is looking at the page to find out. Their own first, then the rest by
         name, so the leader's own list does not get lost in the middle. --}}
    @php
        $byOfficer = $followUps
            ->groupBy(fn ($inq) => $inq->officer?->name ?? 'Unassigned')
            ->sortBy(fn ($list, $name) => $name === $me->name ? '' : mb_strtolower($name));
    @endphp

    @foreach ($byOfficer as $officer => $list)
        <div class="card panel" style="margin-bottom: 1.1rem;">
            <div class="officer-head">
                <h2>
                    {{ $officer }}
                    @if ($officer === $me->name)
                        <span class="officer-you">you</span>
                    @endif
                </h2>
                <span class="officer-count">
                    {{ $list->count() }} {{ Str::plural('client', $list->count()) }} waiting
                </span>
            </div>

            @include('partials.follow-ups', [
                'followUps' => $list,
                'user' => $me,
                // The officer's name is the heading above; repeating it on
                // every row underneath is noise.
                'showOfficer' => false,
            ])
        </div>
    @endforeach

@else
    <div class="card panel">
        @include('partials.follow-ups', ['followUps' => $followUps, 'user' => $me])
    </div>
@endif

@endsection
