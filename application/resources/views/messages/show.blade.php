@extends('layouts.app')

@section('title', 'Chat — '.$other->name)
@section('page-title', 'Messages')

@section('content')
<style>
    .thread { display: flex; flex-direction: column; gap: 0.6rem; max-height: 58vh; overflow-y: auto; padding: 0.4rem; }
    .bubble-row { display: flex; }
    .bubble-row.mine { justify-content: flex-end; }
    .bubble { max-width: min(68ch, 78%); padding: 0.6rem 0.85rem; border-radius: 14px; background: var(--border); font-size: 0.9rem; line-height: 1.45; }
    .bubble-row.mine .bubble { background: var(--sidebar-bg); color: #fff; }
    .bubble .when { display: block; margin-top: 0.3rem; font-size: 0.7rem; opacity: 0.7; }
    .jo-card { display: block; margin-top: 0.4rem; padding: 0.6rem 0.75rem; border-radius: 10px; background: rgba(255,255,255,0.9); border: 1px solid var(--border); color: var(--ink); text-decoration: none; }
    .bubble-row.mine .jo-card { background: rgba(255,255,255,0.14); border-color: rgba(255,255,255,0.25); color: #fff; }
    .jo-card .jo-num { font-weight: 800; font-size: 0.88rem; }
    .jo-card .jo-sub { font-size: 0.76rem; opacity: 0.85; }
    .jo-card.locked { opacity: 0.75; }
    .composer { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; margin-top: 0.9rem; }
    .composer textarea { flex: 1; min-width: 240px; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>{{ $other->name }}</h1>
        <p class="muted">{{ $other->positionLabel() }}</p>
    </div>
    <a href="{{ route('messages.index') }}" class="btn btn-ghost">← All messages</a>
</div>

<div class="card panel">
    <div class="thread" id="thread">
        @forelse ($messages as $m)
            @php $mine = $m->sender_id === auth()->id(); @endphp
            <div class="bubble-row {{ $mine ? 'mine' : '' }}">
                <div class="bubble">
                    @if ($m->body)
                        {{ $m->body }}
                    @endif

                    @if ($m->production_order_id)
                        @if ($m->canSeeOrder(auth()->user()) && $m->order)
                            <a href="{{ route('orders.show', $m->order) }}" class="jo-card">
                                <div class="jo-num">📋 {{ $m->order->order_number }}</div>
                                <div class="jo-sub">
                                    {{ $m->order->customer_name }} · {{ number_format($m->order->quantity) }} pcs
                                    @if ($m->order->due_date) · due {{ $m->order->due_date->format('M j') }} @endif
                                </div>
                            </a>
                        @else
                            {{-- Sent an order they are not on: show it exists, no details. --}}
                            <div class="jo-card locked">
                                <div class="jo-num">📋 {{ $m->order?->order_number ?? 'Job order' }}</div>
                                <div class="jo-sub">You are not assigned to this order, so it cannot be opened.</div>
                            </div>
                        @endif
                    @endif

                    <span class="when">{{ $m->created_at?->format('M j, g:i a') }}</span>
                </div>
            </div>
        @empty
            <p class="muted" style="text-align:center; padding: 2rem 0;">
                No messages yet — say hello.
            </p>
        @endforelse
        <div id="end"></div>
    </div>

    <form method="POST" action="{{ route('messages.store') }}" class="composer">
        @csrf
        <input type="hidden" name="recipient_id" value="{{ $other->id }}">

        <textarea name="body" rows="2" maxlength="5000" placeholder="Write a message…">{{ old('body') }}</textarea>

        <div style="display:flex; flex-direction:column; gap:0.4rem;">
            <select name="production_order_id" style="max-width: 240px;">
                <option value="">Attach a job order…</option>
                @foreach ($orders as $o)
                    <option value="{{ $o->id }}" @selected(old('production_order_id') == $o->id)>
                        {{ $o->order_number }} — {{ $o->customer_name }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary">Send</button>
        </div>
    </form>
</div>

<script>
    // Jump to the newest message, the way a chat should open.
    (function () {
        var t = document.getElementById('thread');
        if (t) t.scrollTop = t.scrollHeight;
    })();
</script>
@endsection
