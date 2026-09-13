@extends('layouts.app')

@section('title', 'Designing board — Imprint Production')
@section('page-title', 'Designing board')

@section('content')

@php
    // The sheet's own colours, because the team reads this board by colour
    // before they read a word of it: green is moving, amber is waiting on
    // money, red is waiting on paperwork, blue is finished.
    $statusTone = [
        'Massprod' => 'massprod',
        'Sample' => 'sample',
        'Delivered' => 'delivered',
        'Approved' => 'approved',
        'Waiting For Approval' => 'waiting',
        'Designing' => 'designing',
        'Not yet sent' => 'idle',
        'Cancelled' => 'cancelled',
        'Brief' => 'idle',
    ];
    $noteTone = [
        'Waiting For Orderlist' => 'orderlist',
        'Waiting DP' => 'dp',
        'Delivered' => 'delivered',
        'Cancelled' => 'cancelled',
        'On hold' => 'dp',
    ];
    $filterLink = fn (array $change) => route('design.log', array_filter(array_merge(
        array_filter(['days' => $days, 'artist' => $artist, 'team' => $team]), $change
    )));
    // Moving a design between artists is the leader's call once the brief has
    // gone out — the same rule the handover has always had. The board only
    // offers what the endpoint would accept.
    $canMove = auth()->user()->isLeader();
@endphp

<div class="page-head">
    <div class="grow">
        <p class="sub" style="margin:0;">Read off the work itself — nothing here is typed in.</p>
    </div>
</div>

{{-- What the whole board adds up to. The team's first question every morning
     is how many are stuck, not which ones, and a count answers it faster than
     a scroll. --}}
@if ($tally->isNotEmpty())
    <div class="dl-tally">
        @foreach ($tally as $name => $count)
            <span class="dl-tally-item is-{{ $statusTone[$name] ?? 'idle' }}">
                <strong>{{ $count }}</strong> {{ $name }}
            </span>
        @endforeach
    </div>
@endif

<div class="list-toolbar">
    <div class="toolbar-filters" style="margin-left:0;">
        <div class="seg" role="group" aria-label="How far back">
            @foreach ($dayChoices as $choice)
                <a href="{{ $filterLink(['days' => $choice]) }}"
                   @if ($days === $choice) aria-current="page" @endif>{{ $choice }}d</a>
            @endforeach
        </div>

        <div class="seg" role="group" aria-label="Show one team">
            @foreach (['' => 'All teams', 'meta' => 'META', 'vip' => 'VIP'] as $value => $label)
                <a href="{{ $filterLink(['team' => $value]) }}"
                   @if ($team === $value) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div>

        @if ($artists->isNotEmpty())
            <div class="seg" role="group" aria-label="Show one artist">
                <a href="{{ $filterLink(['artist' => '']) }}"
                   @if ($artist === '') aria-current="page" @endif>All artists</a>
                @foreach ($artists as $name)
                    <a href="{{ $filterLink(['artist' => $name]) }}"
                       @if ($artist === $name) aria-current="page" @endif>{{ $name }}</a>
                @endforeach
            </div>
        @endif
    </div>

    <span class="list-search-note" style="margin-left:auto;">
        {{ $total }} {{ Str::plural('design', $total) }}
    </span>
</div>

@if ($byDay->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin:0;">
            Nothing on the board for this stretch.
            @if ($artist !== '' || $team !== '')
                <a href="{{ route('design.log', ['days' => $days]) }}">Show everybody</a>.
            @endif
        </p>
    </div>
@else
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="tbl-wrap">
            <table class="tbl design-log">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Kind</th>
                        <th>Brief</th>
                        <th>Agent</th>
                        <th>Artist</th>
                        <th class="dl-num">Received</th>
                        <th class="dl-num">Finished</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byDay as $day => $rows)
                        @php $when = \Illuminate\Support\Carbon::parse($day); @endphp
                        <tr class="dl-day">
                            <th colspan="10">
                                <span class="dl-day-name">{{ $when->format('l') }}</span>
                                <span class="dl-day-date">{{ $when->format('j M Y') }}</span>
                                @if ($when->isToday())
                                    <span class="dl-day-today">today</span>
                                @endif
                                <span class="dl-day-count">{{ $rows->count() }}</span>
                            </th>
                        </tr>

                        @foreach ($rows as $row)
                            <tr>
                                <td class="dl-num dl-dim">{{ $row['date']->format('j M') }}</td>
                                <td class="dl-client">
                                    <span>{{ $row['client'] }}</span>
                                    @if ($row['design']->label)
                                        <small>{{ $row['design']->label }}</small>
                                    @endif
                                </td>
                                <td>
                                    <span class="dl-kind {{ $row['description'] === 'For Mock Up' ? 'is-mockup' : '' }}">
                                        {{ $row['description'] }}
                                    </span>
                                </td>
                                <td>
                                    @if ($row['brief'])
                                        {{-- The same link the client is sent: the quickest way to
                                             see what was actually asked for. --}}
                                        <a href="{{ $row['brief'] }}" target="_blank" rel="noopener" class="dl-brief" title="Open the creative brief">Brief ↗</a>
                                    @else
                                        <span class="dl-dim">—</span>
                                    @endif
                                </td>
                                <td class="dl-agent">{{ $row['agent'] }}</td>
                                <td>
                                    @if ($canMove && $bench->isNotEmpty())
                                        {{-- Changed here rather than three pages away. It posts to
                                             the same endpoint the brief page uses, so the rules and
                                             the note to the new artist are the same ones. --}}
                                        <form method="POST"
                                              action="{{ route('inquiries.designs.artist', [$row['design']->inquiry_id, $row['design']->id]) }}"
                                              class="dl-artist-form">
                                            @csrf
                                            <select name="artist_id" onchange="this.form.submit()" aria-label="Who is drawing it">
                                                <option value="" disabled @selected(! $row['design']->artist_id)>— nobody —</option>
                                                @foreach ($bench as $person)
                                                    <option value="{{ $person->id }}" @selected($row['design']->artist_id === $person->id)>
                                                        {{ $person->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </form>
                                    @else
                                        {{ $row['artist'] ?? '—' }}
                                    @endif

                                    @if ($row['revisions'] > 0)
                                        <span class="dl-rev" title="Sent back {{ $row['revisions'] }} time(s)">R{{ $row['revisions'] }}</span>
                                    @endif
                                </td>
                                <td class="dl-num dl-dim">{{ optional($row['received'])->format('j M') ?? '—' }}</td>
                                <td class="dl-num dl-dim">{{ optional($row['finished'])->format('j M') ?? '—' }}</td>
                                <td><span class="dl-tag is-{{ $statusTone[$row['status']] ?? 'idle' }}">{{ $row['status'] }}</span></td>
                                <td><span class="dl-tag is-{{ $noteTone[$row['notes']] ?? 'plain' }}">{{ $row['notes'] }}</span></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
