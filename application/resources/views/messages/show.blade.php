@extends('layouts.app')

@section('title', 'Messages — '.$order->order_number)
@section('page-title', 'Messages')

@section('content')
<style>
    .thread { display: flex; flex-direction: column; gap: 0.7rem; max-height: 56vh; overflow-y: auto; padding: 0.4rem; }
    /* Shrink to the words. Without this the row stretches its child, so "ok"
       got the same slab as a paragraph. */
    .bubble-row { display: flex; flex-direction: column; align-items: flex-start; }
    .bubble-row.mine { align-items: flex-end; }
    .who { font-size: 0.72rem; color: var(--ink-3); margin-bottom: 0.18rem; padding: 0 0.35rem; }
    /* pre-wrap belongs on the message text alone. On the bubble it also kept
       the template's own newlines and indentation, so a two-letter message
       came out as tall as a paragraph. */
    .bubble { max-width: min(68ch, 82%); padding: 0.5rem 0.8rem; border-radius: 14px; background: var(--border); font-size: 0.9rem; line-height: 1.45; word-break: break-word; }
    /* Inline so a short message and its time share one line instead of the
       time claiming a whole row to itself. */
    .bubble .text { white-space: pre-wrap; display: inline; }
    .bubble-row.mine .bubble { background: var(--sidebar-bg); color: #fff; }
    /* The time tucks in beside short messages instead of taking a line of its
       own, and drops below only when the text actually fills the width. */
    .bubble .when { float: right; margin: 0.35rem 0 0 0.6rem; font-size: 0.68rem; opacity: 0.6; }
    .bubble::after { content: ''; display: block; clear: both; }

    /* Name box for shared logins. */
    .who-am-i { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin: 0.6rem 0 0.2rem; }
    .who-am-i label { font-size: 0.8rem; font-weight: 600; color: var(--ink-1); margin: 0; }
    .who-am-i input { width: 190px; }
    .who-am-i .hint { font-size: 0.76rem; color: var(--ink-3); }
    .thread-closed { margin-top: 0.9rem; padding: 0.7rem 0.95rem; border-radius: 10px;
                     background: var(--border); color: var(--ink-2); font-size: 0.88rem; }

    /* Where the job is, sitting above the conversation about it. */
    .pipeline-peek { margin-bottom: 1rem; padding: 0.85rem 1.05rem; }
    .pipeline-peek-head { display: flex; align-items: baseline; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.5rem; font-size: 0.88rem; }
    .pipeline-peek-now { color: var(--ink-1); }
    .pipeline-peek .lbl { text-transform: uppercase; font-size: 0.66rem; letter-spacing: 0.06em; font-weight: 700; color: var(--ink-3); }
    .pipeline-peek .arrow { opacity: 0.45; margin: 0 0.15rem; }
    .pipeline-peek .muted-inline { color: var(--ink-3); }
    .pipeline-peek-all { margin-top: 0.6rem; }
    .pipeline-peek-all > summary { cursor: pointer; font-size: 0.8rem; font-weight: 600; color: var(--accent-ink, #1d4ed8); }
    .pipeline-peek-all ol { margin: 0.5rem 0 0; padding-left: 1.2rem; font-size: 0.82rem; }
    .pipeline-peek-all li { padding: 0.15rem 0; display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap; }
    .pipeline-peek-all li.is-done .dept { color: var(--ink-3); text-decoration: line-through; }
    .pipeline-peek-all li.is-now { font-weight: 700; }
    .mention { font-weight: 700; color: #1d4ed8; background: rgba(29,78,216,0.10); border-radius: 5px; padding: 0 3px; }
    .msg-photo { display: block; margin-top: 0.45rem; }
    .msg-photo img { max-width: 260px; max-height: 260px; width: auto; height: auto; border-radius: 10px; display: block; }
    .msg-file { display: inline-block; margin-top: 0.45rem; padding: 0.4rem 0.6rem; border-radius: 8px; background: rgba(0,0,0,0.06); text-decoration: none; color: inherit; font-size: 0.83rem; }
    /* The global a:hover underline would otherwise line these through. */
    .msg-file:hover, .msg-photo:hover { text-decoration: none; }
    .bubble-row.mine .msg-file { background: rgba(255,255,255,0.18); }
    .msg-file .sz { opacity: 0.7; font-size: 0.75rem; }
    .previews { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.4rem; }
    .previews img { width: 56px; height: 56px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); }
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
            {{ $order->clientName() }}
            · {{ number_format($order->quantity) }} pcs
            @if ($order->due_date) · due {{ $order->due_date->format('M j, Y') }} @endif
        </p>
    </div>
    <a href="{{ route('messages.index') }}" class="btn btn-ghost">← All messages</a>
    {{-- The job order SHEET, not the order admin page — that one opens on
         payments and pricing, which is the account officer's business, not the
         floor's. --}}
    <a href="{{ route('orders.job-order', $order) }}" class="btn btn-primary">Open job order</a>
</div>

@include('partials.delay-alert', ['order' => $order])

{{-- Where the job actually is, right above the conversation about it. Nearly
     every thread here is somebody asking how far it has got, so the answer sits
     with the question instead of a page away. --}}
@php
    // The mover is shown her slice of the line — printer through inventory —
    // so the count and the bar describe what she is actually following.
    $steps = $order->stepsVisibleTo(auth()->user());
    $totalSteps = $steps->count();
    $doneSteps = $steps->where('status', 'complete')->count();
    $pct = $totalSteps ? round($doneSteps / $totalSteps * 100) : 0;

    $currentStep = $steps->whereIn('status', ['ready', 'in_progress', 'for_checking', 'revision_required'])->first();
    $nextStep = $currentStep
        ? $steps->first(fn ($t) => $t->stage > $currentStep->stage && ! in_array($t->status, ['complete', 'cancelled'], true))
        : $steps->first(fn ($t) => ! in_array($t->status, ['complete', 'cancelled'], true));
@endphp

<div class="card panel pipeline-peek">
    <div class="pipeline-peek-head">
        <strong>{{ $doneSteps }} of {{ $totalSteps }} steps done</strong>
        <span class="pipeline-peek-now">
            @if ($totalSteps === 0)
                Not on the floor yet
            @elseif ($doneSteps === $totalSteps)
                Finished
            @else
                <span class="lbl">Now at</span> {{ $currentStep?->department ?? 'Not started' }}
                @if ($who = ($currentStep?->operator_name ?: $currentStep?->assignee?->name))<span class="muted-inline">— {{ $who }}</span>@endif
                @if ($nextStep)
                    <span class="arrow">&rarr;</span> <span class="lbl">Next</span> {{ $nextStep->department }}
                @endif
            @endif
        </span>
    </div>

    <div class="progress" style="height:9px;">
        <div style="width: {{ $pct }}%; background: linear-gradient(90deg, var(--brand), #a855f7 50%, var(--accent));"></div>
    </div>

    <details class="pipeline-peek-all">
        <summary>Every step</summary>
        <ol>
            @foreach ($steps as $t)
                <li @class(['is-done' => $t->status === 'complete', 'is-now' => $t->id === $currentStep?->id])>
                    <span class="dept">{{ $t->department }}</span>
                    @include('partials.status', ['status' => $t->status])
                    @if ($t->operator_name || $t->assignee)
                        <span class="muted-inline">{{ $t->operator_name ?: $t->assignee?->name }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </details>
</div>

<div class="card panel">
    <div class="thread" id="thread">
        @forelse ($messages as $m)
            @php
                // On a shared login "mine" is not the same as "this account's":
                // another mover's message would otherwise sit on my side of the
                // thread with nobody's name on it.
                $mine = $m->sender_id === auth()->id()
                    && (! auth()->user()->sharesAccount()
                        || $m->sender_name === session('sender_name'));
            @endphp
            <div class="bubble-row {{ $mine ? 'mine' : '' }}">
                @if (! $mine || auth()->user()->sharesAccount())
                    <div class="who">{{ $m->senderLabel() }}</div>
                @endif
                <div class="bubble">
                    @if (filled($m->body))<div class="text">{!! $m->bodyWithMentions() !!}</div>@endif

                    @foreach ($m->files as $f)
                        @if ($f->isImage())
                            <a href="{{ route('messages.file', $f) }}" target="_blank" rel="noopener" class="msg-photo">
                                <img src="{{ route('messages.file', $f) }}" alt="{{ $f->original_name }}" loading="lazy">
                            </a>
                        @else
                            <a href="{{ route('messages.file', $f) }}" target="_blank" rel="noopener" class="msg-file">
                                📎 {{ $f->original_name }} <span class="sz">{{ $f->sizeForHumans() }}</span>
                            </a>
                        @endif
                    @endforeach

                    <span class="when">{{ $m->created_at?->format('M j, g:i a') }}</span>
                </div>
            </div>
        @empty
            <p class="muted" style="text-align:center; padding: 2rem 0;">
                No messages on this job order yet — start the conversation.
            </p>
        @endforelse
        <div id="end"></div>
    </div>

    @if ($order->conversationClosed())
        {{-- Finished business. The thread stays readable as the record of what
             happened; nothing more gets added to it. --}}
        <div class="thread-closed">
            🔒 This job order is <strong>{{ $order->statusLabel() }}</strong> — the conversation is closed.
        </div>
    @else
    @if (auth()->user()->sharesAccount())
        {{-- Several people share this login, so a message signed with the
             account name tells nobody who to answer. Typed once and kept for
             the shift, the way an operator's name is at a station. --}}
        <div class="who-am-i">
            <label for="senderName">Your name</label>
            <input id="senderName" name="sender_name" form="composerForm" type="text" maxlength="100" required
                   value="{{ old('sender_name', session('sender_name')) }}"
                   placeholder="e.g. Louiza" autocomplete="off">
            <span class="hint">This account is shared — messages are signed with the name you type.</span>
        </div>
    @endif

    <form method="POST" action="{{ route('messages.store', $order) }}" class="composer" id="composerForm" enctype="multipart/form-data">
        @csrf
        <div style="flex:1; min-width:200px;">
            <textarea name="body" id="composerBody" rows="2" maxlength="5000" autocomplete="off"
                      placeholder="Message everyone on {{ $order->order_number }}… type @ to mention someone">{{ old('body') }}</textarea>

            {{-- Thumbnails of what is about to be sent. --}}
            <div class="previews" id="previews"></div>
        </div>

        {{-- Filled in by the script below from the participant list. --}}
        <div class="mention-box" id="mentionBox"></div>

        <div style="display:flex; flex-direction:column; gap:0.4rem;">
            <label class="btn btn-ghost btn-sm" style="cursor:pointer; text-align:center;">
                📷 Photo
                <input type="file" name="files[]" id="msgFiles" multiple
                       accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" style="display:none;">
            </label>
            <button type="submit" class="btn btn-primary">Send</button>
        </div>
    </form>

    <div>
        <div style="font-size: 0.74rem; color: var(--ink-3); margin-top: 0.9rem;">In this conversation:</div>
        @endif

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

    // Show what is about to be sent, so nobody attaches the wrong photo.
    (function () {
        var input = document.getElementById('msgFiles');
        var box = document.getElementById('previews');
        if (!input || !box) return;

        input.addEventListener('change', function () {
            box.innerHTML = '';
            Array.prototype.forEach.call(input.files, function (file) {
                if (file.type.indexOf('image/') !== 0) {
                    var tag = document.createElement('span');
                    tag.className = 'msg-file';
                    tag.textContent = '📎 ' + file.name;
                    box.appendChild(tag);
                    return;
                }
                var img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.onload = function () { URL.revokeObjectURL(img.src); };
                box.appendChild(img);
            });
        });
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
