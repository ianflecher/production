@extends('layouts.app')

@section('title', 'Messages — '.$order->order_number)
@section('page-title', 'Messages')

@section('content')
<style>
    .thread { display: flex; flex-direction: column; gap: 0.7rem; max-height: 56vh; overflow-y: auto; padding: 0.4rem; }
    .bubble-row { display: flex; flex-direction: column; }
    .bubble-row.mine { align-items: flex-end; }
    .who { font-size: 0.72rem; color: var(--ink-3); margin-bottom: 0.18rem; padding: 0 0.35rem; }
    .bubble { max-width: min(68ch, 82%); padding: 0.6rem 0.85rem; border-radius: 14px; background: var(--border); font-size: 0.9rem; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
    .bubble-row.mine .bubble { background: var(--sidebar-bg); color: #fff; }
    .bubble .when { display: block; margin-top: 0.3rem; font-size: 0.7rem; opacity: 0.7; }
    .composer { display: flex; gap: 0.5rem; align-items: flex-start; margin-top: 0.9rem; }
    .composer textarea { flex: 1; min-width: 200px; }
    .people { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.5rem; }
    .chip { font-size: 0.74rem; padding: 0.15rem 0.55rem; border-radius: 99px; background: var(--border); color: var(--ink-2); }
</style>

<div class="page-head">
    <div class="grow">
        <h1>{{ $order->order_number }}</h1>
        <p class="muted">
            {{ $order->client?->name ?? $order->customer_name }}
            · {{ number_format($order->quantity) }} pcs
            @if ($order->due_date) · due {{ $order->due_date->format('M j, Y') }} @endif
        </p>
    </div>
    <a href="{{ route('messages.index') }}" class="btn btn-ghost">← All messages</a>
    <a href="{{ route('orders.show', $order) }}" class="btn btn-primary">Open job order</a>
</div>

<div class="card panel">
    <div class="thread" id="thread">
        @forelse ($messages as $m)
            @php $mine = $m->sender_id === auth()->id(); @endphp
            <div class="bubble-row {{ $mine ? 'mine' : '' }}">
                @unless ($mine)
                    <div class="who">{{ $m->sender?->name ?? 'Someone' }}</div>
                @endunless
                <div class="bubble">{{ $m->body }}<span class="when">{{ $m->created_at?->format('M j, g:i a') }}</span></div>
            </div>
        @empty
            <p class="muted" style="text-align:center; padding: 2rem 0;">
                No messages on this job order yet — start the conversation.
            </p>
        @endforelse
        <div id="end"></div>
    </div>

    <form method="POST" action="{{ route('messages.store', $order) }}" class="composer">
        @csrf
        <textarea name="body" rows="2" maxlength="5000" required
                  placeholder="Message everyone on {{ $order->order_number }}…">{{ old('body') }}</textarea>
        <button type="submit" class="btn btn-primary">Send</button>
    </form>

    <div>
        <div style="font-size: 0.74rem; color: var(--ink-3); margin-top: 0.9rem;">In this conversation:</div>
        <div class="people">
            @foreach ($participants as $p)
                <span class="chip">{{ $p->name }}</span>
            @endforeach
        </div>
    </div>
</div>

<script>
    (function () {
        var t = document.getElementById('thread');
        if (t) t.scrollTop = t.scrollHeight;
    })();
</script>
@endsection
