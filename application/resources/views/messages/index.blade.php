@extends('layouts.app')

@section('title', 'Messages')
@section('page-title', 'Messages')

@section('content')
<style>
    .msg-list { display: flex; flex-direction: column; }
    .msg-row { display: flex; gap: 0.85rem; align-items: center; padding: 0.85rem 0.4rem; border-bottom: 1px solid var(--border); text-decoration: none; color: inherit; }
    .msg-row:last-child { border-bottom: 0; }
    .msg-row:hover { background: var(--bg-soft, rgba(0,0,0,0.02)); }
    .msg-av { width: 42px; height: 42px; border-radius: 50%; background: var(--sidebar-bg); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex: 0 0 auto; }
    .msg-mid { flex: 1; min-width: 0; }
    .msg-name { font-weight: 700; font-size: 0.92rem; }
    .msg-prev { font-size: 0.82rem; color: var(--ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .msg-prev.unread { color: var(--ink); font-weight: 600; }
    .msg-meta { text-align: right; flex: 0 0 auto; font-size: 0.74rem; color: var(--ink-3); }
    .msg-badge { display: inline-block; min-width: 20px; padding: 0 6px; border-radius: 99px; background: #E31B23; color: #fff; font-weight: 700; font-size: 0.72rem; line-height: 20px; text-align: center; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>Messages</h1>
        <p class="muted">Message anyone on the team. You can attach a job order to a message.</p>
    </div>
</div>

{{-- Start a new conversation --}}
<div class="card panel">
    <h2>New message</h2>
    <form method="GET" action="{{ route('messages.index') }}" id="newChatForm"
          style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;"
          onsubmit="event.preventDefault(); var v=document.getElementById('toUser').value; if(v){ window.location='/messages/'+v; }">
        <select id="toUser" style="max-width: 320px;">
            <option value="">Choose someone…</option>
            @foreach ($people as $p)
                <option value="{{ $p->id }}">{{ $p->name }} — {{ $p->positionLabel() }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Open chat</button>
    </form>
</div>

<div class="card panel">
    <h2>Conversations</h2>

    @if ($conversations->isEmpty())
        <p class="muted" style="padding: 1.5rem 0; text-align: center;">
            No messages yet. Pick someone above to start a conversation.
        </p>
    @else
        <div class="msg-list">
            @foreach ($conversations as $c)
                <a href="{{ route('messages.show', $c['user']) }}" class="msg-row">
                    <div class="msg-av">{{ strtoupper(mb_substr($c['user']->name, 0, 1)) }}</div>
                    <div class="msg-mid">
                        <div class="msg-name">{{ $c['user']->name }}</div>
                        <div class="msg-prev {{ $c['unread'] ? 'unread' : '' }}">
                            @if ($c['last']->sender_id === auth()->id())
                                <span style="color: var(--ink-3);">You:</span>
                            @endif
                            @if ($c['last']->body)
                                {{ $c['last']->body }}
                            @else
                                📋 Job order {{ $c['last']->order?->order_number ?? '' }}
                            @endif
                        </div>
                    </div>
                    <div class="msg-meta">
                        <div>{{ $c['last']->created_at?->diffForHumans(short: true) }}</div>
                        @if ($c['unread'])
                            <div style="margin-top: 0.3rem;"><span class="msg-badge">{{ $c['unread'] }}</span></div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
