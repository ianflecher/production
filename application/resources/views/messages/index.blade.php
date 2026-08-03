@extends('layouts.app')

@section('title', 'Messages')
@section('page-title', 'Messages')

@section('content')
<style>
    .msg-list { display: flex; flex-direction: column; }
    .msg-row { display: flex; gap: 0.85rem; align-items: center; padding: 0.85rem 0.4rem; border-bottom: 1px solid var(--border); text-decoration: none; color: inherit; }
    .msg-row:last-child { border-bottom: 0; }
    .msg-row:hover { background: rgba(0,0,0,0.02); }
    .msg-av { width: 44px; height: 44px; border-radius: 12px; background: var(--sidebar-bg); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.78rem; flex: 0 0 auto; }
    .msg-mid { flex: 1; min-width: 0; }
    .msg-name { font-weight: 700; font-size: 0.92rem; }
    .msg-client { font-size: 0.78rem; color: var(--ink-3); }
    .msg-prev { font-size: 0.82rem; color: var(--ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 0.15rem; }
    .msg-prev.unread { color: var(--ink); font-weight: 600; }
    .msg-meta { text-align: right; flex: 0 0 auto; font-size: 0.74rem; color: var(--ink-3); }
    .msg-badge { display: inline-block; min-width: 20px; padding: 0 6px; border-radius: 99px; background: #E31B23; color: #fff; font-weight: 700; font-size: 0.72rem; line-height: 20px; text-align: center; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>Messages</h1>
        <p class="muted">One conversation per job order — everyone working on it can see and reply.</p>
    </div>
</div>

<div class="card panel">
    <h2>Job order conversations</h2>
    <p class="sub">
        @if ($talkedAbout > 0)
            {{ $talkedAbout }} with messages. Orders you are on but nobody has written about yet are below them.
        @else
            Open any job order you are on to start its conversation.
        @endif
    </p>

    @if ($threads->isEmpty())
        <p class="muted" style="padding: 1.5rem 0; text-align: center;">
            You are not on any job orders yet, so there is nothing to talk about.
        </p>
    @else
        <div class="msg-list">
            @foreach ($threads as $t)
                <a href="{{ route('messages.show', $t['order']) }}" class="msg-row">
                    <div class="msg-av">{{ $t['order']->order_number ? \Illuminate\Support\Str::substr($t['order']->order_number, -5) : 'JO' }}</div>

                    <div class="msg-mid">
                        <div class="msg-name">{{ $t['order']->order_number }}</div>
                        <div class="msg-client">{{ $t['order']->client?->name ?? $t['order']->customer_name }}</div>
                        <div class="msg-prev {{ $t['unread'] ? 'unread' : '' }}">
                            @if ($t['last'])
                                <span style="color: var(--ink-3);">{{ $t['last']->sender_id === auth()->id() ? 'You' : $t['last']->sender?->name }}:</span>
                                {{ $t['last']->preview() }}
                            @else
                                <span style="font-style: italic;">No messages yet</span>
                            @endif
                        </div>
                    </div>

                    <div class="msg-meta">
                        @if ($t['last'])
                            <div>{{ $t['last']->created_at?->diffForHumans(short: true) }}</div>
                        @endif
                        @if ($t['unread'])
                            <div style="margin-top: 0.3rem;"><span class="msg-badge">{{ $t['unread'] }}</span></div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
