@extends('layouts.app')

@section('title', 'Design brief — '.$order->order_number)
@section('page-title', 'Design brief — '.$order->order_number)

@section('content')
@php
    $refs = $order->jobOrder?->referenceFiles ?? collect();
    // Reference files the client attached (peg / logo) — offered for download.
    $clientImages = $refs->whereIn('kind', ['peg', 'logo']);
    $productLabel = $order->productLabel() ?? 'custom apparel';
    $qty = number_format($order->quantity);

    // Plain-text questionnaire the account officer can send to the client
    // (Messenger / Viber). Numbered, with an "Answer N:" line for each so the
    // client's reply can be imported straight back into the form below.
    $tpl  = "Hi! For your {$productLabel} ({$qty} pcs), please answer these questions so we can design it. Just type your answer after each \"Answer\" line:\n";
    $i = 1;
    foreach ($questions as $key => $q) {
        $tpl .= "\n{$i}. {$q['label']}";
        if (! empty($q['help']))       $tpl .= "\n   ({$q['help']})";
        if ($q['type'] === 'choice')   $tpl .= "\n   Choices: ".implode(' / ', array_values($q['options']));
        if (! empty($q['files']))      $tpl .= "\n   (You can also send reference photos)";
        $tpl .= "\nAnswer {$i}: \n";
        $i++;
    }

    // Order + type of each question, in order, so the importer can map an
    // "Answer N:" back to the right field (and match choice options).
    $qMeta = [];
    foreach ($questions as $key => $q) {
        $qMeta[] = ['key' => $key, 'type' => $q['type'], 'options' => $q['type'] === 'choice' ? $q['options'] : null];
    }
@endphp

<style>
    /* Scoped to the design-brief page (this <style> only renders here). */
    .db-head-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .db-step { display: flex; align-items: center; gap: 0.55rem; }
    .db-step .n {
        width: 24px; height: 24px; border-radius: 999px; flex-shrink: 0;
        background: var(--accent); color: #fff; font-size: 0.8rem; font-weight: 700;
        display: grid; place-items: center;
    }
    .db-step .n.done { background: var(--success-ink); }
    .db-tools { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
    @media (max-width: 760px) { .db-tools { grid-template-columns: 1fr; } }
    .db-tools .tool { border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.05rem; background: var(--surface-2); }
    .db-tools h3 { font-family: var(--font-head); font-size: 0.98rem; font-weight: 600; margin-bottom: 0.15rem; }
    .db-tools p.hint { font-size: 0.8rem; color: var(--ink-2); margin-bottom: 0.7rem; }
    .db-tools textarea { width: 100%; font-size: 0.82rem; }
    .db-copyq { font-family: ui-monospace, Consolas, monospace; }
    .db-q { padding: 1rem 0; border-top: 1px solid var(--border); }
    .db-q:first-of-type { border-top: none; padding-top: 0.4rem; }
    .db-q .q-label { display: flex; gap: 0.55rem; align-items: baseline; font-weight: 600; }
    .db-q .q-num { color: var(--accent); font-weight: 700; font-variant-numeric: tabular-nums; min-width: 1.4rem; }
    .db-q .q-help { font-size: 0.78rem; color: var(--ink-3); margin: 0.2rem 0 0.4rem 1.95rem; }
    .db-q .q-input { margin-left: 1.95rem; }
    @media (max-width: 560px) { .db-q .q-help, .db-q .q-input { margin-left: 0; } }
    .db-fill-note { font-size: 0.82rem; font-weight: 600; margin-top: 0.5rem; }
    /* Locked (read-only) answers: muted look, no interaction until "Edit". */
    #briefFields.locked input, #briefFields.locked textarea, #briefFields.locked select {
        background: var(--surface-2); color: var(--ink-2); cursor: default;
    }
    #briefFields.locked select { pointer-events: none; }
    #briefFields.locked input[type="file"] { display: none; }
</style>

<div class="page-head">
    <div class="grow">
        <h1>Client design questionnaire</h1>
        <p class="muted">{{ $order->order_number }} · {{ $order->clientName() }} — collect the client's answers, then build a ChatGPT prompt from them.</p>
    </div>
    <div class="db-head-actions">
        <a href="{{ route('orders.show', $order) }}" class="btn btn-ghost btn-sm">← Back to order</a>
    </div>
</div>

@if ($errors->any())
    <div class="alert-error" style="margin-bottom: 1rem;">
        @foreach ($errors->all() as $e){{ $e }}<br>@endforeach
    </div>
@endif

@if ($prompt)
    <div class="card panel" style="margin-bottom: 1.4rem; border-left: 4px solid var(--success-ink);">
        <h2>ChatGPT prompt</h2>
        <p class="sub" style="margin-bottom: 0.7rem;">Copy this and paste it into ChatGPT (or any image AI) to generate the design concept.</p>
        <textarea id="promptBox" readonly rows="16" style="width:100%; font-family: ui-monospace, Consolas, monospace; font-size: 0.82rem;">{{ $prompt }}</textarea>
        <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap; margin-top:0.6rem;">
            <button type="button" class="btn btn-primary btn-sm" onclick="copyPrompt()">📋 Copy prompt</button>
            @if ($clientImages->isNotEmpty())
                <button type="button" class="btn btn-ghost btn-sm" onclick="downloadClientImages()">⬇ Download client images ({{ $clientImages->count() }})</button>
            @endif
            <span id="copyOk" style="display:none; color: var(--success-ink); font-weight:600; font-size:0.85rem;">Copied.</span>
        </div>

        @if ($clientImages->isNotEmpty())
            <div style="display:flex; flex-wrap:wrap; gap:0.6rem; margin-top:0.9rem; padding-top:0.9rem; border-top:1px solid var(--border);">
                @foreach ($clientImages as $ref)
                    <div style="border:1px solid var(--border); border-radius:8px; padding:0.45rem; width:120px; text-align:center;">
                        <a href="{{ route('job-order-files.view', $ref) }}" target="_blank" rel="noopener">
                            @if ($ref->isImage())
                                <img src="{{ route('job-order-files.view', $ref) }}" alt="{{ $ref->original_name }}" class="design-preview" style="max-width:100%; max-height:80px; border-radius:4px; display:block; margin:0 auto;">
                            @else
                                <div style="font-size:1.7rem;">📄</div>
                            @endif
                        </a>
                        <a href="{{ route('job-order-files.view', $ref) }}" download="{{ $ref->original_name }}" class="btn btn-ghost btn-sm dl-client" data-name="{{ $ref->original_name }}" style="margin-top:0.35rem; padding:0.18rem 0.5rem; font-size:0.68rem;">⬇ Save</a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif

{{-- Step 1 & 2: send the questions to the client, then paste their reply back. --}}
<div class="card panel" style="margin-bottom: 1.4rem;">
    <div class="db-step" style="margin-bottom: 0.2rem;">
        <span class="n">1</span>
        <h2 style="margin: 0;">Ask the client</h2>
    </div>
    <p class="sub" style="margin: 0.15rem 0 1rem 0;">Send the client a link to fill in themselves — their answers appear here automatically. Or copy the questions to send in chat.</p>

    {{-- Primary: a shareable form link (like a Google Form). Single-use. --}}
    <div class="tool" style="border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.05rem; margin-bottom: 1.1rem; border-left: 4px solid {{ $clientSubmittedAt ? 'var(--ink-3)' : 'var(--accent)' }};">
        <h3 style="font-family: var(--font-head); font-size: 0.98rem; font-weight: 600; margin-bottom: 0.15rem;">🔗 Share a form link with the client</h3>

        @if ($clientSubmittedAt)
            <div style="display: flex; align-items: center; gap: 0.5rem; background: var(--success-soft); border: 1px solid var(--success-border); border-left: 4px solid var(--success-ink); color: var(--success-ink); border-radius: 8px; padding: 0.6rem 0.85rem; font-size: 0.84rem; font-weight: 600; margin-bottom: 0.7rem;">
                <span aria-hidden="true">🔒</span> The client submitted on {{ $clientSubmittedAt->format('M j, Y \a\t g:i A') }} — the link is now closed.
            </div>
            <p style="font-size: 0.8rem; color: var(--ink-2); margin-bottom: 0.7rem;">Reopen it only if the client needs to change their answers. It becomes single-use again.</p>
            <form method="POST" action="{{ route('orders.design-brief.reopen', $order) }}" onsubmit="return confirm('Reopen the client form for one more submission?');">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">↺ Reopen client form</button>
            </form>
        @else
            <p style="font-size: 0.8rem; color: var(--ink-2); margin-bottom: 0.7rem;">The client opens this private link, fills the form on their phone, and submits — no login needed. <strong>The link works once</strong>; it closes automatically after they submit. Their answers appear here (the page auto-updates when you're not typing).</p>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <input type="text" id="clientLink" class="no-caps" readonly value="{{ $clientLink }}" style="flex: 1; min-width: 220px; font-size: 0.82rem;">
                <button type="button" class="btn btn-primary btn-sm" onclick="copyClientLink()">📋 Copy link</button>
                <a href="{{ $clientLink }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">↗ Open</a>
                <span id="copyLinkOk" style="display:none; color: var(--success-ink); font-weight:600; font-size:0.82rem;">Copied.</span>
            </div>
            @if ($clientLinkIsPrivate)
                {{-- The tunnel isn't running, so this link is an in-house
                     address. Sending it to a client would simply not open. --}}
                <p style="font-size: 0.8rem; margin-top: 0.6rem; padding: 0.6rem 0.75rem; border-radius: 8px; background: var(--danger-soft, #fef2f2); border: 1px solid var(--danger-border, #fecaca); color: var(--danger-ink, #b91c1c);">
                    ⚠️ <strong>Do not send this link yet — it only works inside the office.</strong>
                    The public address is not available right now, so the link above points at an
                    in-house address your client cannot open. Start the Cloudflare tunnel
                    (<code>start-imprint.bat</code>) and reload this page to get a shareable link.
                </p>
            @endif
            @if ($clientLinkExpiresAt)
                <p style="font-size: 0.78rem; color: {{ $order->briefExpired() ? 'var(--danger-ink)' : 'var(--ink-3)' }}; margin-top: 0.55rem;">
                    ⏰ {{ $order->briefExpired() ? 'This link expired on' : 'This link expires on' }}
                    <strong>{{ $clientLinkExpiresAt->format('M j, Y') }}</strong>.
                </p>
            @endif
        @endif
    </div>

    <details>
        <summary style="cursor:pointer; font-size:0.85rem; color:var(--ink-2); font-weight:600; margin-bottom:0.8rem;">Prefer chat? Copy the questions or paste the client's reply</summary>
    <div class="db-tools">
        <div class="tool">
            <h3>Send these questions</h3>
            <p class="hint">Copy and send to the client on Messenger / Viber. They type their answer after each line.</p>
            <textarea id="clientQuestions" class="db-copyq" rows="8" readonly>{{ $tpl }}</textarea>
            <div style="display:flex; gap:0.5rem; align-items:center; margin-top:0.5rem;">
                <button type="button" class="btn btn-primary btn-sm" onclick="copyClientQuestions()">📋 Copy questions</button>
                <span id="copyQOk" style="display:none; color: var(--success-ink); font-weight:600; font-size:0.82rem;">Copied.</span>
            </div>
        </div>

        <div class="tool">
            <h3>Paste the client's answers</h3>
            <p class="hint">Paste the client's reply (with the “Answer 1:”, “Answer 2:” … lines) and it fills the form below.</p>
            <textarea id="importAnswers" rows="8" class="no-caps" placeholder="Paste the client's reply here…"></textarea>
            <div style="display:flex; gap:0.5rem; align-items:center; margin-top:0.5rem;">
                <button type="button" class="btn btn-ghost btn-sm" onclick="fillFromAnswers()">↧ Fill the form</button>
                <span id="fillNote" class="db-fill-note" style="display:none;"></span>
            </div>
        </div>
    </div>
    </details>
</div>

<form method="POST" action="{{ route('orders.design-brief.save', $order) }}" enctype="multipart/form-data">
    @csrf
    <div class="card panel" style="margin-bottom: 1.4rem;">
        <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.2rem;">
            <div class="db-step" style="margin: 0;">
                <span class="n">2</span>
                <h2 style="margin: 0;">Client answers</h2>
            </div>
            @if (! empty($answers))
                <button type="button" id="editBtn" class="btn btn-ghost btn-sm" style="margin-left: auto;" onclick="toggleEdit()">✎ Edit answers</button>
            @endif
        </div>
        <p class="sub" style="margin: 0.15rem 0 1rem 0;">Leave anything blank the client didn't answer — only answered questions go into the prompt.</p>

        @if (! empty($answers))
            <div style="display: flex; align-items: center; gap: 0.5rem; background: var(--success-soft); border: 1px solid var(--success-border); border-left: 4px solid var(--success-ink); color: var(--success-ink); border-radius: 10px; padding: 0.7rem 0.95rem; font-weight: 600; font-size: 0.88rem; margin-bottom: 1rem;">
                <span aria-hidden="true">✓</span> The client's answers are in and locked for safety. The prompt is ready above — click <strong>Edit answers</strong> only if you need to change something.
            </div>
        @endif

        {{-- Already known from the order, so it isn't asked again. --}}
        <div style="background: var(--accent-soft); border-radius: 8px; padding: 0.6rem 0.9rem; margin-bottom: 1.2rem; font-size: 0.86rem;">
            <strong>Apparel:</strong> {{ $order->productLabel() ?? '—' }}
            · <strong>Quantity:</strong> {{ number_format($order->quantity) }} pcs
            <span class="muted" style="font-size: 0.78rem;">— taken from the order, added to the prompt automatically.</span>
        </div>

        <div id="briefFields">
        @php $qn = 0; @endphp
        @foreach ($questions as $key => $q)
            @php $val = old('brief.'.$key, $answers[$key] ?? ''); $qn++; @endphp
            <div class="db-q">
                <label for="brief_{{ $key }}" class="q-label"><span class="q-num">{{ $qn }}.</span> {{ $q['label'] }}</label>
                @if (! empty($q['help']))
                    <div class="q-help">{{ $q['help'] }}</div>
                @endif

                <div class="q-input">
                    @if ($q['type'] === 'choice')
                        <select id="brief_{{ $key }}" name="brief[{{ $key }}]" style="max-width: 460px;">
                            <option value="">— No answer —</option>
                            @foreach ($q['options'] as $ok => $ol)
                                <option value="{{ $ok }}" @selected($val === $ok)>{{ $ol }}</option>
                            @endforeach
                        </select>
                    @elseif ($q['type'] === 'textarea')
                        <textarea id="brief_{{ $key }}" name="brief[{{ $key }}]" rows="3" maxlength="2000">{{ $val }}</textarea>
                    @else
                        <input id="brief_{{ $key }}" type="text" name="brief[{{ $key }}]" maxlength="2000" value="{{ $val }}">
                    @endif

                    {{-- Questions that ask for files get their own upload right here. --}}
                    @if (! empty($q['files']))
                        @php $mine = $refs->where('kind', $q['files']); @endphp
                        @if ($mine->isNotEmpty())
                            <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem;">
                                @foreach ($mine as $ref)
                                    <div style="border: 1px solid var(--border); border-radius: 8px; padding: 0.4rem; text-align: center; width: 130px;">
                                        <a href="{{ route('job-order-files.view', $ref) }}" target="_blank">
                                            @if ($ref->isImage())
                                                <img src="{{ route('job-order-files.view', $ref) }}" alt="{{ $ref->original_name }}" class="design-preview"
                                                     style="max-width: 100%; max-height: 90px; border-radius: 4px; display: block; margin: 0 auto;">
                                            @else
                                                <div style="font-size: 1.8rem;">📄</div>
                                            @endif
                                        </a>
                                        <div style="font-size: 0.68rem; color: var(--ink-3); margin-top: 0.25rem; word-break: break-all;">{{ $ref->original_name }}</div>
                                        <a href="{{ route('job-order-files.view', $ref) }}" download="{{ $ref->original_name }}" class="btn btn-ghost btn-sm" style="margin-top: 0.35rem; padding: 0.2rem 0.55rem; font-size: 0.7rem;">⬇ Download</a>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-top: 0.5rem;">
                            <input type="file" name="files[{{ $q['files'] }}][]" multiple
                                   accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.ai,.psd,.eps,.cdr,.zip">
                            <span style="font-size: 0.72rem; color: var(--ink-3);">Attached when you save below. Several files at once is fine.</span>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
        </div>{{-- /#briefFields --}}
    </div>

    <div style="display: flex; gap: 0.75rem;">
        {{-- New brief: build the prompt. Existing brief: hidden until "Edit". --}}
        <button type="submit" id="saveBtn" class="btn btn-primary" @if (! empty($answers)) style="display: none;" @endif>
            {{ ! empty($answers) ? '💾 Save changes & rebuild prompt' : '⚡ Create prompt' }}
        </button>
        <a href="{{ route('orders.show', $order) }}" class="btn btn-ghost">Done</a>
    </div>
</form>

<script>
    // Ordered question metadata, used to map "Answer N:" back to the right field.
    const DB_QUESTIONS = @json($qMeta);

    // ---- Read-only answers with an Edit toggle -------------------------------
    // Existing answers load locked (read-only). "Edit answers" unlocks them and
    // reveals the save button; values still submit either way (readonly, not
    // disabled) so nothing is lost.
    (function () {
        const box = document.getElementById('briefFields');
        if (!box) return;
        const hasAnswers = @json(! empty($answers));
        let locked = hasAnswers;

        function apply() {
            box.classList.toggle('locked', locked);
            box.querySelectorAll('input:not([type=file]), textarea').forEach(function (el) { el.readOnly = locked; });
            box.querySelectorAll('select').forEach(function (el) { el.tabIndex = locked ? -1 : 0; });
            const editBtn = document.getElementById('editBtn');
            const saveBtn = document.getElementById('saveBtn');
            if (editBtn) editBtn.textContent = locked ? '✎ Edit answers' : '✕ Cancel edit';
            if (saveBtn) saveBtn.style.display = (locked && hasAnswers) ? 'none' : '';
        }

        window.toggleEdit = function () { locked = !locked; apply(); };
        apply();
    })();

    // Trigger a download for each client image (staggered so browsers allow it).
    function downloadClientImages() {
        var links = document.querySelectorAll('a.dl-client');
        links.forEach(function (a, idx) {
            setTimeout(function () {
                var tmp = document.createElement('a');
                tmp.href = a.getAttribute('href');
                tmp.download = a.getAttribute('data-name') || '';
                document.body.appendChild(tmp);
                tmp.click();
                document.body.removeChild(tmp);
            }, idx * 400);
        });
    }

    function copyPrompt() {
        const box = document.getElementById('promptBox');
        if (!box) return;
        box.select();
        navigator.clipboard.writeText(box.value).then(() => {
            const ok = document.getElementById('copyOk');
            ok.style.display = 'inline';
            setTimeout(() => { ok.style.display = 'none'; }, 2000);
        }).catch(() => document.execCommand('copy'));
    }

    function copyClientLink() {
        const box = document.getElementById('clientLink');
        if (!box) return;
        box.select();
        const done = () => {
            const ok = document.getElementById('copyLinkOk');
            ok.style.display = 'inline';
            setTimeout(() => { ok.style.display = 'none'; }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(box.value).then(done).catch(() => { document.execCommand('copy'); done(); });
        } else {
            document.execCommand('copy'); done();
        }
    }

    function copyClientQuestions() {
        const box = document.getElementById('clientQuestions');
        if (!box) return;
        box.select();
        const done = () => {
            const ok = document.getElementById('copyQOk');
            ok.style.display = 'inline';
            setTimeout(() => { ok.style.display = 'none'; }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(box.value).then(done).catch(() => { document.execCommand('copy'); done(); });
        } else {
            document.execCommand('copy'); done();
        }
    }

    // Parse a pasted client reply and fill the matching fields. Reads each
    // "Answer N: …" block (answers may span multiple lines) and maps N to the
    // Nth question. For choice fields it matches the option value or its label.
    function fillFromAnswers() {
        const raw = document.getElementById('importAnswers').value || '';
        const re = /Answer\s*(\d+)\s*:\s*([\s\S]*?)(?=(?:\n\s*Answer\s*\d+\s*:)|$)/gi;
        let m, filled = 0;
        while ((m = re.exec(raw)) !== null) {
            const idx = parseInt(m[1], 10) - 1;
            const val = (m[2] || '').trim();
            const q = DB_QUESTIONS[idx];
            if (!q || !val) continue;
            const el = document.getElementById('brief_' + q.key);
            if (!el) continue;

            if (q.type === 'choice') {
                let matched = '';
                const opts = q.options || {};
                for (const ov in opts) {
                    if (ov.toLowerCase() === val.toLowerCase() ||
                        String(opts[ov]).toLowerCase() === val.toLowerCase()) { matched = ov; break; }
                }
                if (matched) { el.value = matched; filled++; }
            } else {
                el.value = val;
                filled++;
            }
        }

        const note = document.getElementById('fillNote');
        if (filled > 0) {
            note.textContent = '✓ Filled ' + filled + ' answer' + (filled === 1 ? '' : 's') + ' — review below and save.';
            note.style.color = 'var(--success-ink)';
        } else {
            note.textContent = 'No “Answer N:” lines found — check the pasted text.';
            note.style.color = 'var(--danger-ink)';
        }
        note.style.display = 'inline';
    }
</script>
@endsection
