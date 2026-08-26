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

@if ($followUps->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin: 0;">
            Nobody is waiting. Every inquiry taken so far has become an order.
        </p>
    </div>

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
