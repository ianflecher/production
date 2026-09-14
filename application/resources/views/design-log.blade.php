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
        array_filter(['days' => $days, 'artist' => $artist, 'team' => $team, 'q' => $search,
            'status' => $status, 'mine' => $mine ? 1 : null]),
        $change
    )));
    // A count nobody can click is a fact; a count that opens the rows behind
    // it is the next thing they were going to do anyway. Pressing the one
    // already on turns it off, so the strip needs no "all" button beside it.
    $tallyLink = fn (string $name) => $filterLink(['status' => $status === $name ? '' : $name]);
    // Reading the board is the whole design side's; moving a design between
    // artists is not. That is the artist leader's job and the leader's, and
    // the endpoint has always said so — the board only stops offering a
    // control that would be refused. See User::canReassignArtists.
    $canMove = auth()->user()->canReassignArtists();
@endphp

<div class="design-board-refresh">
<style>
    .design-board-refresh { --board-muted:#526176; }
    .design-board-refresh .page-head { margin-bottom:1.2rem; }
    .design-board-refresh .page-head .grow { background:none; padding:0; border:0; box-shadow:none; }
    .design-board-refresh .dl-tally { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:.7rem; margin-bottom:1.2rem; }
    .design-board-refresh .dl-tally-item { display:flex; flex-direction:column; align-items:flex-start; gap:.35rem; padding:1rem; border-radius:12px; background:#fff; border:1px solid #e2e8f0; color:#526176; font-size:.8rem; }
    .design-board-refresh .dl-tally-item strong { font-size:1.65rem; line-height:1.1; }
    .design-board-refresh .dl-tally-item.is-waiting strong { color:#a16207; }
    .design-board-refresh .dl-tally-item.is-approved strong { color:#15803d; }
    .design-board-refresh .dl-tally-item[aria-current] { background:#eff6ff; border-color:#2563eb; color:#1e40af; box-shadow:0 0 0 1px #2563eb; }
    .design-board-refresh .dl-tally-item[aria-current] strong { color:#1e40af; }
    .design-board-refresh .list-toolbar { padding:1rem; gap:1rem; background:#fff; border:1px solid #e2e8f0; box-shadow:none; border-radius:12px; margin-bottom:1.1rem; }
    .design-board-refresh .list-search { flex:1 1 70%; margin:0; }
    .design-board-refresh input[type="search"] { width:100%; min-height:42px; padding:.65rem .8rem .65rem 2.3rem; border:1px solid #cbd5e1; border-radius:8px; font:inherit; background:#f8fafc; }
    .design-board-refresh .toolbar-filters { flex:1 1 100%; flex-wrap:wrap; padding-top:1rem; border-top:1px solid #edf2f7; gap:.7rem; }
    .design-board-refresh .seg { flex-wrap:wrap; }
    .design-board-refresh .seg a { padding:.5rem .75rem; }
    .design-board-refresh .seg a[aria-current] { background:#eff6ff; color:#1d4ed8; }
    .design-board-refresh .tbl-wrap { max-height:70vh; overflow:auto; }
    .design-board-refresh .design-log td { border:0; border-bottom:1px solid #e8edf3; padding:.85rem .8rem; font-size:.85rem; background:#fff; }
    .design-board-refresh .design-log thead th { background:#f1f5f9; color:#526176; border:0; border-bottom:1px solid #cbd5e1; padding:.8rem; z-index:3; }
    .design-board-refresh .design-log tr.dl-day th { position:static; background:#f8fafc; border:0; border-bottom:1px solid #e2e8f0; padding:.65rem .8rem; }
    .design-board-refresh .dl-client > span:first-child { color:#172033; font-size:.9rem; font-weight:650; }
    .design-board-refresh .dl-client small { color:#526176; margin-top:.3rem; text-transform:none; letter-spacing:0; font-size:.8rem; }
    .design-board-refresh .dl-dim, .design-board-refresh .dl-agent { color:#526176; }
    .design-board-refresh .dl-tag { padding:.3rem .55rem; border-radius:6px; font-size:.75rem; }
    .design-board-refresh .dl-tag.is-waiting { background:#fef3c7; color:#92400e; }
    .design-board-refresh .dl-tag.is-approved { background:#dcfce7; color:#166534; }
    .design-board-refresh .dl-needs-attention td:first-child { box-shadow:inset 3px 0 #d97706; background:#fffbeb; }
    .design-board-refresh .dl-artist-edit summary { cursor:pointer; font-weight:600; color:#334155; list-style:none; }
    .design-board-refresh .dl-artist-edit summary span { color:#2563eb; font-size:.72rem; margin-left:.5rem; }
    .design-board-refresh .dl-artist-form { margin-top:.5rem; }
    .design-board-refresh .dl-date-line { display:block; font-size:.76rem; line-height:1.65; }
    @media(max-width:700px) { .design-board-refresh .dl-tally { grid-template-columns:repeat(2,1fr); } .design-board-refresh .tbl-wrap { max-height:none; } }
</style>
<div class="page-head">
    <div class="grow">
        <h1>Design overview</h1>
        <p class="sub" style="margin:.3rem 0 0;">Track each design, review waiting work, and manage artist assignments.</p>
    </div>
</div>

{{-- What the whole board adds up to. The team's first question every morning
     is how many are stuck, not which ones, and a count answers it faster than
     a scroll. --}}
@if ($tally->isNotEmpty())
    <div class="dl-tally">
        @foreach ($tally as $name => $count)
            <a href="{{ $tallyLink($name) }}"
               class="dl-tally-item is-{{ $statusTone[$name] ?? 'idle' }}"
               @if ($status === $name) aria-current="true" @endif
               title="{{ $status === $name ? 'Show them all again' : 'Show only these' }}">
                <strong>{{ $count }}</strong> {{ $name }}
            </a>
        @endforeach
    </div>
@endif

<div class="list-toolbar">
    @include('partials.list-search', [
        // The other choices are separate questions and a search should not
        // silently answer them, so they ride through as hidden fields.
        'action' => route('design.log'),
        'value' => $search,
        'placeholder' => 'Client, design, agent or artist…',
        'label' => 'Search the board',
        'keep' => ['days' => $days, 'team' => $team, 'artist' => $artist, 'status' => $status,
            'mine' => $mine ? 1 : null],
    ])

    <span class="list-search-note">{{ $total }} {{ Str::plural('design', $total) }}</span>

    <div class="toolbar-filters">
        @if ($hasOwnRows)
            {{-- First, because on a board of forty rows the commonest question
                 anybody brings to it is about their own. --}}
            <div class="seg" role="group" aria-label="Whose work">
                <a href="{{ $filterLink(['mine' => null]) }}"
                   @if (! $mine) aria-current="page" @endif>Everyone</a>
                <a href="{{ $filterLink(['mine' => 1]) }}"
                   @if ($mine) aria-current="page" @endif>Mine</a>
            </div>
        @endif

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

</div>

@if ($byDay->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin:0;">
            Nothing on the board for this stretch.
            @if ($artist !== '' || $team !== '' || $status !== '' || $search !== '' || $mine)
                <a href="{{ route('design.log', ['days' => $days]) }}">Show everything</a>.
            @endif
        </p>
    </div>
@else
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="tbl-wrap">
            <table class="tbl design-log dl-full">
                {{-- Stated widths, because the widest column by content
                     otherwise takes every spare pixel in the table. --}}
                <colgroup>
                    <col class="dl-c-wait"><col class="dl-c-client"><col class="dl-c-brief">
                    <col class="dl-c-agent"><col class="dl-c-artist"><col class="dl-c-date">
                    <col class="dl-c-status"><col class="dl-c-notes">
                </colgroup>
                <thead>
                    <tr>
                        <th class="dl-num" title="How long it has sat where it is">Waiting</th>
                        <th>Client</th>
                        <th>Brief</th>
                        <th>Agent</th>
                        <th>Artist</th>
                        <th class="dl-num" title="Reached the artist, and handed back">Drawn</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byDay as $day => $rows)
                        @php $when = \Illuminate\Support\Carbon::parse($day); @endphp
                        <tr class="dl-day">
                            <th colspan="8">
                                <span class="dl-day-name">{{ $when->format('l') }}</span>
                                <span class="dl-day-date">{{ $when->format('j M Y') }}</span>
                                @if ($when->isToday())
                                    <span class="dl-day-today">today</span>
                                @endif
                                <span class="dl-day-count">{{ $rows->count() }}</span>
                            </th>
                        </tr>

                        @foreach ($rows as $row)
                            <tr @class(['dl-needs-attention' => $row['waiting'] !== null && $row['waiting'] >= \App\Support\DesignLog::SITTING_TOO_LONG])>
                                {{-- The date used to be repeated here under a day band that
                                     already says it. This is the thing it could not tell you. --}}
                                <td class="dl-num" data-label="Waiting">
                                    @if ($row['waiting'] === null)
                                        <span class="dl-dim">—</span>
                                    @else
                                        <span class="{{ $row['waiting'] >= \App\Support\DesignLog::SITTING_TOO_LONG ? 'dl-wait is-long' : 'dl-wait' }}"
                                              title="Here since {{ $row['since']->format('j M') }}">
                                            {{ $row['waiting'] === 0 ? 'today' : $row['waiting'].'d' }}
                                        </span>
                                    @endif
                                </td>
                                {{-- "Kind" was a column of its own saying "New Design" on
                                     forty rows out of forty-two. The ordinary case needed no
                                     column; the exception belongs beside the thing it is an
                                     exception about, which is this design. --}}
                                <td class="dl-client" data-label="Client">
                                    <span>{{ $row['client'] }}</span>
                                    @if ($row['description'] === 'For Mock Up')
                                        <span class="dl-kind is-mockup" title="Asked for against a job already written">Mock up</span>
                                    @endif
                                    @if ($row['design']->label)
                                        <small>{{ $row['design']->label }}</small>
                                    @endif
                                </td>
                                <td data-label="Brief">
                                    @if ($row['brief'])
                                        {{-- The shop's own brief page. Opened in its own tab so
                                             reading one does not cost the reader their place on
                                             a board they have scrolled halfway down. --}}
                                        <a href="{{ $row['brief'] }}" target="_blank" rel="noopener" class="dl-brief" title="Open the brief">Brief ↗</a>
                                    @else
                                        <span class="dl-dim">—</span>
                                    @endif
                                </td>
                                <td class="dl-agent" data-label="Agent">{{ $row['agent'] }}</td>
                                <td data-label="Artist">
                                    @if ($canMove && $bench->isNotEmpty())
                                        <details class="dl-artist-edit">
                                            <summary>{{ $row['artist'] ?? 'Unassigned' }} <span>Edit</span></summary>
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
                                        </details>
                                    @else
                                        {{ $row['artist'] ?? '—' }}
                                    @endif

                                    @if ($row['revisions'] > 0)
                                        <span class="dl-rev" title="Sent back {{ $row['revisions'] }} time(s)">R{{ $row['revisions'] }}</span>
                                    @endif
                                </td>
                                {{-- Reached the artist, and came back: two columns for one
                                     fact, and the fact is the gap between them. --}}
                                <td class="dl-num dl-dim" data-label="Drawn">
                                    <span class="dl-date-line">{{ $row['received'] ? 'Started '.$row['received']->format('j M') : 'Not started' }}</span>
                                    @if ($row['finished'])
                                        <span class="dl-date-line">Done {{ $row['finished']->format('j M') }}</span>
                                    @endif
                                </td>
                                <td data-label="Status"><span class="dl-tag is-{{ $statusTone[$row['status']] ?? 'idle' }}">{{ $row['status'] }}</span></td>
                                {{-- A note that repeats the status beside it is a column of
                                     nothing: twenty rows reading "Waiting For Approval /
                                     Waiting For Approval" and one reading "Waiting DP", which
                                     is the only one anybody needed to see. So the note is
                                     printed when it says something the status does not. --}}
                                <td data-label="Note">
                                    @if ($row['notes'] !== $row['status'] && $row['notes'] !== 'Work in progress')
                                        <span class="dl-tag is-{{ $noteTone[$row['notes']] ?? 'plain' }}">{{ $row['notes'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

</div>
@endsection
