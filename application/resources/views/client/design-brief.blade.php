<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Design questionnaire — {{ $order->order_number }}</title>
    @include('partials.fonts')
    <style>
        :root {
            /* Mirrors css/app.css — this page renders outside the app shell. */
            --font-body: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            --font-head: 'Space Grotesk', 'Inter', system-ui, sans-serif;
            --bg: #F4F6F9; --surface: #fff; --border: #E5E9F0; --ink: #17202E;
            --ink-2: #566172; --ink-3: #94A0AE; --brand: #E31B23; --brand-hover: #B5141A;
            --accent-soft: #eff6ff; --success: #15803d; --success-soft: #f0fdf4;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-body);
            background: var(--bg); color: var(--ink); font-size: 16px;
            -webkit-font-smoothing: antialiased; line-height: 1.5;
        }
        .wrap { max-width: 680px; margin: 0 auto; padding: 1.5rem 1.1rem 4rem; }
        .brand { display: flex; align-items: center; gap: 0.65rem; padding: 0.5rem 0 1.25rem; }
        .brand .mark {
            width: 42px; height: 42px; border-radius: 9px; background: var(--brand);
            display: grid; place-items: center; color: #fff; font-weight: 800; font-size: 1rem;
            box-shadow: 0 2px 8px rgba(227,27,35,.3);
        }
        .brand .txt strong { display: block; font-family: var(--font-head); font-size: 1.05rem; font-weight: 700; line-height: 1; }
        .brand .txt small { display: block; font-size: 0.66rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-3); margin-top: 3px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 1.4rem 1.3rem; box-shadow: 0 1px 2px rgba(19,30,51,.04), 0 2px 10px rgba(19,30,51,.05); }
        h1 { font-family: var(--font-head); font-size: 1.4rem; letter-spacing: -0.02em; margin-bottom: 0.3rem; }
        .lead { color: var(--ink-2); font-size: 0.95rem; margin-bottom: 1rem; }
        .meta { background: var(--accent-soft); border-radius: 8px; padding: 0.65rem 0.9rem; font-size: 0.9rem; margin-bottom: 1.4rem; }
        .saved {
            display: flex; align-items: flex-start; gap: 0.6rem;
            background: var(--success-soft); border: 1px solid #bbf7d0; border-left: 4px solid #22c55e;
            color: var(--success); border-radius: 10px; padding: 0.85rem 1rem; font-weight: 500;
            margin-bottom: 1.4rem;
        }
        .q { padding: 1.15rem 0; border-top: 1px solid var(--border); }
        .q:first-of-type { border-top: none; padding-top: 0.3rem; }
        .q label.qlabel { display: block; font-weight: 600; font-size: 1rem; margin-bottom: 0.15rem; }
        .q .num { color: var(--brand); font-weight: 700; }
        .q .help { font-size: 0.85rem; color: var(--ink-3); margin-bottom: 0.55rem; }
        input[type=text], select, textarea {
            width: 100%; padding: 0.72rem 0.85rem; font-size: 1rem; font-family: inherit;
            background: #fff; border: 1.5px solid #cbd5e1; border-radius: 8px; color: var(--ink);
            transition: border-color .12s, box-shadow .12s; min-height: 46px;
        }
        textarea { min-height: 92px; resize: vertical; }
        input:focus, select:focus, textarea:focus {
            outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(227,27,35,.14);
        }
        .actions { position: sticky; bottom: 0; background: linear-gradient(180deg, transparent, var(--bg) 40%); padding-top: 1.2rem; margin-top: 0.5rem; }
        .btn-submit {
            width: 100%; padding: 0.95rem; font-size: 1.05rem; font-weight: 700; font-family: inherit;
            background: var(--brand); color: #fff; border: none; border-radius: 10px; cursor: pointer;
            box-shadow: 0 2px 10px rgba(227,27,35,.28); transition: background .14s;
        }
        .btn-submit:hover { background: var(--brand-hover); }
        .foot { text-align: center; color: var(--ink-3); font-size: 0.8rem; margin-top: 1.4rem; }
        /* Upload control */
        .upload { margin-top: 0.6rem; }
        .upload input[type=file] { display: none; }
        .upload-btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.6rem 0.95rem; font-size: 0.92rem; font-weight: 600; cursor: pointer;
            background: #fff; color: #17202E; border: 1.5px dashed #cbd5e1; border-radius: 8px;
            min-height: 44px; transition: border-color .12s, background .12s;
        }
        .upload-btn:hover { border-color: var(--brand); background: #fff7f7; }
        .picked { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.6rem; }
        .picked .chip { font-size: 0.78rem; background: var(--accent-soft); color: #1d4ed8; border-radius: 6px; padding: 0.25rem 0.5rem; word-break: break-all; align-self: center; }
        .picked figure { width: 76px; margin: 0; text-align: center; }
        .picked figure img { width: 76px; height: 76px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); display: block; }
        .picked figure figcaption { font-size: 0.64rem; color: var(--ink-3); margin-top: 0.2rem; word-break: break-all; line-height: 1.2; max-height: 2.4em; overflow: hidden; }
        .hint-sm { font-size: 0.76rem; color: var(--ink-3); margin-top: 0.35rem; }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <div class="mark">IC</div>
            <div class="txt">
                <strong>Imprint Customs</strong>
                <small>Design questionnaire</small>
            </div>
        </div>

        @if ($justSaved)
            <div class="card" style="text-align: center;">
                <div style="width: 64px; height: 64px; border-radius: 999px; background: var(--success-soft); color: var(--success); display: grid; place-items: center; font-size: 2rem; margin: 0.5rem auto 1rem;">✓</div>
                <h1 style="margin-bottom: 0.4rem;">Thank you!</h1>
                <p class="lead" style="margin-bottom: 1.4rem;">Your answers were sent to our team. This form is now closed — you can close this tab.</p>
                <button type="button" class="btn-submit" style="max-width: 240px; margin: 0 auto;" onclick="window.close()">Close this tab</button>
                <p class="foot" style="margin-top: 1rem;">If the tab doesn't close, you can safely close it yourself.</p>
            </div>
            <script>
                // Best effort — browsers only allow closing tabs opened by script,
                // so this may not close a tab the client opened from a chat link.
                setTimeout(function () { try { window.close(); } catch (e) {} }, 700);
            </script>
        @elseif ($closed)
            <div class="card" style="text-align: center;">
                <div style="width: 64px; height: 64px; border-radius: 999px; background: var(--accent-soft); color: #1d4ed8; display: grid; place-items: center; font-size: 2rem; margin: 0.5rem auto 1rem;">🔒</div>
                <h1 style="margin-bottom: 0.4rem;">This form is closed</h1>
                <p class="lead">Your answers have already been received. If you need to make a change, please message our team and we'll reopen the form for you.</p>
            </div>
        @elseif ($expired)
            <div class="card" style="text-align: center;">
                <div style="width: 64px; height: 64px; border-radius: 999px; background: #fff4df; color: #b45309; display: grid; place-items: center; font-size: 2rem; margin: 0.5rem auto 1rem;">⏰</div>
                <h1 style="margin-bottom: 0.4rem;">This link has expired</h1>
                <p class="lead">For your security, this questionnaire link is no longer active. Please message our team and we'll send you a fresh link.</p>
            </div>
        @else
        <div class="card">
            @php $clientName = $order->clientName(); @endphp
            <h1>Tell us about your design</h1>
            <p class="lead">{{ $clientName ? 'Hi '.$clientName.'! ' : '' }}Please answer what you can — anything you leave blank is fine. This helps our artist design exactly what you want.</p>

            <div class="meta">
                @if ($clientName)<strong>For {{ $clientName }}</strong> · @endif<strong>Order {{ $order->order_number }}</strong> · {{ $order->productLabel() ?? 'Custom apparel' }} · {{ number_format($order->quantity) }} pcs
            </div>

            @php $refFiles = $order->jobOrder->referenceFiles ?? collect(); @endphp
            <form method="POST" action="{{ $submitUrl }}" enctype="multipart/form-data">
                @csrf
                @php $n = 0; @endphp
                @foreach ($questions as $key => $q)
                    @php $n++; $val = $answers[$key] ?? ''; @endphp
                    <div class="q">
                        <label class="qlabel" for="c_{{ $key }}"><span class="num">{{ $n }}.</span> {{ $q['label'] }}</label>
                        @if (! empty($q['help']))
                            <div class="help">{{ $q['help'] }}</div>
                        @endif

                        @if ($q['type'] === 'choice')
                            <select id="c_{{ $key }}" name="brief[{{ $key }}]">
                                <option value="">— Choose —</option>
                                @foreach ($q['options'] as $ok => $ol)
                                    <option value="{{ $ok }}" @selected($val === $ok)>{{ $ol }}</option>
                                @endforeach
                            </select>
                        @elseif ($q['type'] === 'textarea')
                            <textarea id="c_{{ $key }}" name="brief[{{ $key }}]" rows="3" maxlength="2000" placeholder="Type your answer…">{{ $val }}</textarea>
                        @else
                            <input id="c_{{ $key }}" type="text" name="brief[{{ $key }}]" maxlength="2000" value="{{ $val }}" placeholder="Type your answer…">
                        @endif

                        {{-- Photo / file upload for questions that ask for references. --}}
                        @if (! empty($q['files']))
                            @php $already = $refFiles->where('kind', $q['files']); @endphp
                            <div class="upload">
                                <label class="upload-btn" for="f_{{ $q['files'] }}">📎 Add photos / files</label>
                                <input id="f_{{ $q['files'] }}" type="file" name="files[{{ $q['files'] }}][]" multiple
                                       accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.ai,.psd,.eps,.cdr,.zip"
                                       onchange="showPicked(this)">
                                <div class="picked" id="picked_{{ $q['files'] }}"></div>
                                <div class="hint-sm">You can attach more than one. JPG, PNG, PDF, AI, PSD, EPS, CDR or ZIP.</div>
                                @if ($already->isNotEmpty())
                                    <div class="hint-sm" style="margin-top:.3rem; color: var(--success);">✓ Already received: {{ $already->pluck('original_name')->implode(', ') }}</div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach

                <div class="actions">
                    <button type="submit" class="btn-submit" onclick="return confirmSend(event)">Send my answers</button>
                </div>
            </form>
        </div>
        @endif

        <p class="foot">Imprint Customs · This link is private to your order.</p>
    </div>

    <script>
        // Double-check with the client before sending.
        function confirmSend(e) {
            var ok = window.confirm('Please review your answers first.\n\nAre you sure you want to send them now?');
            if (!ok && e) { e.preventDefault(); }
            return ok;
        }

        // Preview the files the client picked — a thumbnail for images, a name
        // chip for other file types — so they can see the upload worked.
        function showPicked(input) {
            var box = document.getElementById('picked_' + input.id.replace('f_', ''));
            if (!box) return;
            box.innerHTML = '';
            for (var i = 0; i < input.files.length; i++) {
                var file = input.files[i];
                if (file.type && file.type.indexOf('image/') === 0) {
                    var fig = document.createElement('figure');
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.alt = file.name;
                    img.onload = function () { URL.revokeObjectURL(this.src); };
                    var cap = document.createElement('figcaption');
                    cap.textContent = file.name;
                    fig.appendChild(img);
                    fig.appendChild(cap);
                    box.appendChild(fig);
                } else {
                    var chip = document.createElement('span');
                    chip.className = 'chip';
                    chip.textContent = '📄 ' + file.name;
                    box.appendChild(chip);
                }
            }
        }
    </script>
</body>
</html>
