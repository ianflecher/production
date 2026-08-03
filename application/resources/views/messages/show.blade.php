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
    .mention { font-weight: 700; color: #1d4ed8; background: rgba(29,78,216,0.10); border-radius: 5px; padding: 0 3px; }
    .bubble-row.mine .mention { color: #fff; background: rgba(255,255,255,0.22); }
    .composer { display: flex; gap: 0.5rem; align-items: flex-start; margin-top: 0.9rem; position: relative; }
    .composer textarea { flex: 1; min-width: 200px; }
    /* @mention autocomplete */
    .mention-box { position: absolute; bottom: calc(100% + 4px); left: 0; z-index: 40; background: var(--card, #fff); border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-md, 0 8px 24px rgba(0,0,0,.12)); min-width: 240px; max-height: 210px; overflow-y: auto; display: none; }
    .mention-item { padding: 0.5rem 0.7rem; font-size: 0.85rem; cursor: pointer; display: flex; justify-content: space-between; gap: 0.75rem; }
    .mention-item:hover, .mention-item.active { background: rgba(0,0,0,0.06); }
    .mention-item .role { font-size: 0.72rem; color: var(--ink-3); }
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
                <div class="bubble">{!! $m->bodyWithMentions() !!}<span class="when">{{ $m->created_at?->format('M j, g:i a') }}</span></div>
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
        <textarea name="body" id="composerBody" rows="2" maxlength="5000" required autocomplete="off"
                  placeholder="Message everyone on {{ $order->order_number }}… type @ to mention someone">{{ old('body') }}</textarea>

        {{-- Filled in by the script below from the participant list. --}}
        <div class="mention-box" id="mentionBox"></div>

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

    // @mention autocomplete. Only people in this conversation can be tagged,
    // which is the same list the server validates against.
    (function () {
        var people = @json($participants->map(fn ($p) => ['name' => $p->name, 'role' => $p->positionLabel()])->values());
        var box = document.getElementById('mentionBox');
        var input = document.getElementById('composerBody');
        if (!box || !input) return;

        var matches = [], active = 0, tokenStart = -1;

        function hide() { box.style.display = 'none'; tokenStart = -1; }

        function render() {
            if (!matches.length) { hide(); return; }
            box.innerHTML = matches.map(function (p, i) {
                return '<div class="mention-item' + (i === active ? ' active' : '') + '" data-i="' + i + '">'
                    + '<span>' + p.name + '</span><span class="role">' + (p.role || '') + '</span></div>';
            }).join('');
            box.style.display = 'block';
        }

        // Find an "@word" the caret is sitting in.
        function currentToken() {
            var pos = input.selectionStart;
            var upto = input.value.slice(0, pos);
            var at = upto.lastIndexOf('@');
            if (at === -1) return null;
            // Must start the line or follow whitespace.
            if (at > 0 && !/\s/.test(upto[at - 1])) return null;
            var typed = upto.slice(at + 1);
            // A mention query stops at a newline; allow one space for full names.
            if (/[\n]/.test(typed) || typed.split(' ').length > 2) return null;
            return { at: at, typed: typed };
        }

        function refresh() {
            var tok = currentToken();
            if (!tok) { hide(); return; }
            tokenStart = tok.at;
            var q = tok.typed.toLowerCase();
            matches = people.filter(function (p) { return p.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 6);
            active = 0;
            render();
        }

        function choose(i) {
            var p = matches[i];
            if (!p || tokenStart < 0) return;
            var pos = input.selectionStart;
            input.value = input.value.slice(0, tokenStart) + '@' + p.name + ' ' + input.value.slice(pos);
            var caret = tokenStart + p.name.length + 2;
            input.focus();
            input.setSelectionRange(caret, caret);
            hide();
        }

        input.addEventListener('input', refresh);
        input.addEventListener('click', refresh);

        input.addEventListener('keydown', function (e) {
            if (box.style.display !== 'block' || !matches.length) return;

            if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % matches.length; render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + matches.length) % matches.length; render(); }
            else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); choose(active); }
            else if (e.key === 'Escape') { hide(); }
        });

        box.addEventListener('mousedown', function (e) {
            var item = e.target.closest('.mention-item');
            if (item) { e.preventDefault(); choose(parseInt(item.dataset.i, 10)); }
        });

        document.addEventListener('click', function (e) {
            if (!box.contains(e.target) && e.target !== input) hide();
        });
    })();
</script>
@endsection
