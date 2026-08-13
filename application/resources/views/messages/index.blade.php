@extends('layouts.app')

@section('title', 'Messages')
@section('page-title', 'Messages')

@section('content')
<style>
    .msg-list { display: flex; flex-direction: column; }
    .msg-row { display: flex; gap: 0.85rem; align-items: center; padding: 0.85rem 0.4rem; border-bottom: 1px solid var(--border); text-decoration: none; color: inherit; }
    .msg-row:last-child { border-bottom: 0; }
    /* The whole row is a link, so the global a:hover underline would drag a
       line under all of it. The background tint is the hover cue instead. */
    .msg-row:hover { background: rgba(0,0,0,0.02); text-decoration: none; }
    .msg-row:hover .msg-name { text-decoration: none; }
    /* No avatar box: the order number is already the identity, so a chip
       repeating its last digits was only restating it. A small JO tag carries
       the "this is a job order" meaning without the extra weight. */
    /* Matches the user avatar in the top bar — same red gradient, white mark
       and glow — so a job order reads as the same kind of thing. */
    .jo-tag {
        flex: 0 0 auto; align-self: center;
        width: 46px; height: 46px; border-radius: 14px;
        display: grid; place-items: center;
        background: linear-gradient(135deg, #ff5860, var(--brand));
        color: #fff;
        font-weight: 800; font-size: 0.82rem; letter-spacing: 0.04em;
        box-shadow: 0 2px 6px rgba(227, 27, 35, 0.3);
    }
    .msg-mid { flex: 1; min-width: 0; }
    .msg-name { font-weight: 700; font-size: 0.92rem; }
    .msg-client { font-size: 0.78rem; color: var(--ink-3); }
    .msg-prev { font-size: 0.82rem; color: var(--ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 0.15rem; }
    .msg-prev.unread { color: var(--ink); font-weight: 600; }
    .msg-meta { text-align: right; flex: 0 0 auto; font-size: 0.74rem; color: var(--ink-3); }
    .msg-badge { display: inline-block; min-width: 20px; padding: 0 6px; border-radius: 99px; background: #E31B23; color: #fff; font-weight: 700; font-size: 0.72rem; line-height: 20px; text-align: center; }

    /* Where the job stands, beside its number. */
    .stage-tag {
        display: inline-block; margin-left: 0.4rem; padding: 0.05rem 0.5rem;
        border-radius: 99px; font-size: 0.68rem; font-weight: 700;
        letter-spacing: 0.02em; vertical-align: middle; white-space: nowrap;
    }
    .stage-tag.is-live { background: #e0edff; color: #1d4ed8; }
    .stage-tag.is-done { background: #dcfce7; color: #15803d; }
    .stage-tag.is-off { background: var(--border); color: var(--ink-3); }
</style>

<div class="page-head">
    <div class="grow">
        <h1>Messages</h1>
        <p class="muted">One conversation per job order — everyone working on it can see and reply.</p>
    </div>
</div>

@include('partials.list-search', [
    'action' => route('messages.index'),
    'value' => $search ?? '',
    'placeholder' => 'Search order number, client, or what was said',
    'label' => 'Search conversations',
])

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
                    {{-- The tag sits outside the text column so the order
                         number, client and preview all line up together. --}}
                    <span class="jo-tag">JO</span>

                    <div class="msg-mid">
                        <div class="msg-name">
                            {{ $t['order']->order_number }}

                            {{-- Where the job stands, so the inbox answers the
                                 question most of these threads are asking
                                 without anyone opening them. The mover's steps
                                 are her slice of the line, so her badge names a
                                 step she actually follows. --}}
                            @php
                                $order = $t['order'];
                                $steps = $order->stepsVisibleTo(auth()->user());
                                $atStep = $steps->whereIn('status', ['ready', 'in_progress', 'for_checking', 'revision_required'])->first();

                                [$stageLabel, $stageClass] = match (true) {
                                    $order->status === 'cancelled' => ['Cancelled', 'is-off'],
                                    $order->status === 'on_hold' => ['On hold', 'is-off'],
                                    $order->status === 'complete' => ['Complete', 'is-done'],
                                    $steps->isEmpty() => ['Not started', 'is-off'],
                                    $steps->every(fn ($s) => $s->status === 'complete') => ['Complete', 'is-done'],
                                    $atStep !== null => [$atStep->department, 'is-live'],
                                    default => ['Not started', 'is-off'],
                                };
                            @endphp
                            <span class="stage-tag {{ $stageClass }}">{{ $stageLabel }}</span>
                        </div>
                        <div class="msg-client">{{ $t['order']->clientName() }}</div>
                        <div class="msg-prev {{ $t['unread'] ? 'unread' : '' }}">
                            @if ($t['last'])
                                {{-- The person who typed it, not the login they
                                     used — several movers share one account. --}}
                                @php
                                    // "You" only when it really was you. On a
                                    // shared login the last message may be a
                                    // different mover's, so it gets their name.
                                    $mine = $t['last']->sender_id === auth()->id()
                                        && ! auth()->user()->sharesAccount();
                                @endphp
                                <span style="color: var(--ink-3);">{{ $mine ? 'You' : $t['last']->senderLabel() }}:</span>
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

        @if ($threads->hasPages())
            <div class="list-pager">
                {{ $threads->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
