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
                    @if (filled($message->body))
                        <div class="layout-thread-body">{{ $message->body }}</div>
                    @endif

                    {{-- What was sent with it. A photo on its own is a whole
                         message here: the artist screenshots the thing they are
                         asking about and asks nothing. --}}
                    @if ($message->files->isNotEmpty())
                        <div class="layout-thread-files">
                            @foreach ($message->files as $file)
                                <a href="{{ route('messages.file', $file) }}" target="_blank" class="layout-thread-file">
                                    @if (str_starts_with($file->mime ?? '', 'image/'))
                                        <img src="{{ route('messages.file', $file) }}" alt="{{ $file->original_name }}">
                                    @else
                                        <span class="layout-thread-file-icon">📄</span>
                                    @endif
                                    <span class="layout-thread-file-name">{{ $file->original_name }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('inquiries.messages.store', $inquiry) }}"
          enctype="multipart/form-data"
          style="display:flex; gap:.5rem; align-items:flex-start; margin-top:.9rem; flex-wrap:wrap;">
        @csrf
        {{-- Not required any more: a photo on its own is a message. --}}
        <input type="text" name="body" maxlength="5000" autofocus
               placeholder="Message the {{ $me->isArtist() ? 'officer' : 'artist' }}"
               style="flex:1; min-width:180px;">
        <input type="file" name="files[]" multiple
               accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.ai,.psd,.eps,.cdr,.zip"
               style="max-width:230px; font-size:.8rem;">
        <button type="submit" class="btn btn-primary btn-sm">Send</button>
    </form>
    @error('body')<div class="error" style="margin-top:.4rem;">{{ $message }}</div>@enderror
</div>

@endsection
