@extends('layouts.app')

@section('title', 'Layout conversation — Imprint Production')
@section('page-title', 'Layout conversation')

@section('content')

@php $me = auth()->user(); @endphp

<div class="page-head">
    <div>
        <h1 style="margin:0;">{{ $inquiry->client?->fullName() ?? 'Client' }}</h1>
        <p class="sub" style="margin:.2rem 0 0;">
            {{ $inquiry->what_they_want ?: 'Layout' }}
            @if ($inquiry->layoutArtist) · drawn by {{ $inquiry->layoutArtist->name }} @endif
            · <strong>no job order yet</strong> — this becomes its messages when one is written.
        </p>
    </div>

    <a href="{{ route('messages.index') }}" class="btn btn-ghost btn-sm">← All messages</a>
</div>

{{-- What the officer asked for, so the conversation has its subject in view
     rather than in another tab. --}}
@if (filled($inquiry->layout_reference_note))
    <div class="card panel" style="margin-bottom:1.1rem;">
        <strong>Notes from the officer</strong>
        @include('partials.note-lines', ['note' => $inquiry->layout_reference_note])
    </div>
@endif

@if (filled($inquiry->layout_revision_note))
    <div class="alert alert-error" style="margin-bottom:1.1rem;">
        <strong>Changes asked for:</strong>
        @include('partials.note-lines', ['note' => $inquiry->layout_revision_note])
    </div>
@endif

<div class="card panel">
    @if ($messages->isEmpty())
        <p class="sub" style="margin:0 0 .8rem;">Nothing said yet. Start it below.</p>
    @else
        <div class="layout-thread-list" style="max-height:none;">
            @foreach ($messages as $message)
                <div class="layout-thread-msg {{ $message->sender_id === $me->id ? 'is-mine' : '' }}">
                    <div class="layout-thread-who">
                        {{ $message->senderLabel() }}
                        <span class="layout-thread-when">{{ $message->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="layout-thread-body">{{ $message->body }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('inquiries.messages.store', $inquiry) }}"
          style="display:flex; gap:.5rem; align-items:flex-start; margin-top:.9rem;">
        @csrf
        <input type="text" name="body" maxlength="5000" required autofocus
               placeholder="Message the {{ $me->isArtist() ? 'officer' : 'artist' }}"
               style="flex:1; min-width:0;">
        <button type="submit" class="btn btn-primary btn-sm">Send</button>
    </form>
    @error('body')<div class="error" style="margin-top:.4rem;">{{ $message }}</div>@enderror
</div>

@endsection
