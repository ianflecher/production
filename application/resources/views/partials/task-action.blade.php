@if ($task->order->status !== 'active')
    <p class="muted">This order is <strong>{{ $task->order->statusLabel() }}</strong>.</p>
@elseif ($task->status === 'todo')
    <p class="muted">This step is <strong>blocked</strong>.</p>
@elseif ($task->status === 'ready')
    <form method="POST" action="{{ route('tasks.start', $task->id) }}">
        @csrf
        <button class="btn btn-primary">▶ Start working</button>
    </form>
@elseif ($task->status === 'revision_required')
    <form method="POST" action="{{ route('tasks.start', $task->id) }}">
        @csrf
        <button class="btn btn-primary">▶ Start rework</button>
    </form>
@elseif ($task->status === 'in_progress')
    @php $slots = $task->fileSlots(); @endphp
    <form method="POST" action="{{ route('tasks.submit', $task->id) }}" enctype="multipart/form-data">
        @csrf
        @if ($task->usesFilePath())
            {{-- Design/production files are referenced by their location on the
                 shared drive — not uploaded — to save space. The input is
                 pre-filled with the signed-in artist's own PC address so they
                 only type the folder + file after it (always back-slashes). --}}
            @php
                $artistIps = [
                    'rommel'  => '192.168.150.232',
                    'maru'    => '192.168.150.233',
                    'ian'     => '192.168.150.238',
                    'jc'      => '192.168.150.252',
                    'dave'    => '192.168.150.249',
                    'cristal' => '192.168.150.242',
                    'mick'    => '192.168.150.227',
                ];
                $me = strtolower(auth()->user()->name ?? '');
                $ipPrefix = '';
                foreach ($artistIps as $who => $ip) {
                    if (str_contains($me, $who)) { $ipPrefix = '\\\\'.$ip.'\\'; break; }
                }
            @endphp
            @foreach ($slots as $key => $label)
                <div class="field" style="max-width: 520px;">
                    <label for="{{ $key }}_{{ $task->id }}">{{ $label }} — file path <span style="color: var(--danger-ink);">*</span></label>
                    <input id="{{ $key }}_{{ $task->id }}" type="text" name="paths[{{ $key }}]" class="no-caps"
                           value="{{ $ipPrefix }}"
                           placeholder="\\{{ $ipPrefix ? trim($ipPrefix, '\\') : 'server' }}\FolderName\file..." required>
                </div>
            @endforeach
            <div style="font-size: 0.9rem; color: var(--ink-1); margin-bottom: 0.8rem; line-height: 1.6; background: var(--accent-soft); border-left: 4px solid var(--accent); border-radius: 6px; padding: 0.7rem 0.9rem;">
                Add the folder and file name after the IP — e.g. <code>\\192.168.1.1\sample\sample</code>.
                <strong>Use back-slashes <code>\\</code> — not <code>//</code>.</strong><br>
                Please make sure the folder is <strong>shared to Everyone</strong> and not private.

                {{-- The two things artists get stuck on, shown rather than
                     explained. Nothing downloads until the guide is opened. --}}
                <details class="path-help">
                    <summary>Show me how &mdash; sharing a folder and copying a file path</summary>

                    <div class="path-help-videos">
                        @foreach ([
                            'Share your folder so others can open it' => 'folder sharing.mp4',
                            'Copy the file path of your file' => 'folder file copy.mp4',
                        ] as $caption => $file)
                            <figure>
                                <figcaption>{{ $loop->iteration }}. {{ $caption }}</figcaption>
                                <video controls preload="none" playsinline
                                       src="{{ asset(rawurlencode($file)) }}">
                                    Your browser can't play this video —
                                    <a href="{{ asset(rawurlencode($file)) }}">download it instead</a>.
                                </video>
                            </figure>
                        @endforeach
                    </div>

                    <p style="margin: 0.6rem 0 0; font-size: 0.82rem; color: var(--ink-2);">
                        Still stuck after watching? Ask the IT administrator.
                    </p>
                </details>
            </div>
        @elseif ($slots !== [])
            @foreach ($slots as $key => $label)
                <div class="field" style="max-width: 420px;">
                    <label for="{{ $key }}_{{ $task->id }}">{{ $label }} <span style="color: var(--danger-ink);">*</span></label>
                    <input id="{{ $key }}_{{ $task->id }}" type="file" name="{{ $key }}" accept=".jpg,.jpeg,.png,.webp,.pdf,.ai,.psd,.eps,.cdr,.zip" class="js-file-preview" required>
                    <div class="file-preview" hidden></div>
                </div>
            @endforeach
            @if (count($slots) > 1)
                <p style="font-size: 0.78rem; color: var(--ink-3); margin: -0.4rem 0 0.9rem;">All {{ count($slots) }} files are required.</p>
            @endif
            <div style="font-size: 0.75rem; color: var(--ink-3); margin-bottom: 0.8rem;">Image, PDF, AI, PSD, EPS, CDR or ZIP.</div>
        @else
            <div class="field" style="max-width: 420px;">
                <label for="file_{{ $task->id }}">Attach a file (optional)</label>
                <input id="file_{{ $task->id }}" type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf,.ai,.psd,.eps,.cdr,.zip" class="js-file-preview">
                <div class="file-preview" hidden></div>
            </div>
            <div style="font-size: 0.75rem; color: var(--ink-3); margin-bottom: 0.8rem;">Image, PDF, AI, PSD, EPS, CDR or ZIP.</div>
        @endif
        @php
            // Export steps don't go "for checking" — they hand the files straight
            // to the production stations, so label the button with where it goes.
            if ($task->isExportStep()) {
                $dests = collect(array_keys($slots))
                    ->map(fn ($k) => ['print' => 'printer', 'sticker' => 'sticker', 'embroidery' => 'embroidery'][$k] ?? $k)
                    ->all();
                $last = array_pop($dests);
                $destLabel = $dests ? implode(', ', $dests).' and '.$last : $last;
                $submitLabel = 'Submit to '.$destLabel.' ✓';
                $submitConfirm = 'Send the export files to the '.$destLabel.'?';
            } else {
                $submitLabel = 'Submit for checking ✓';
                $submitConfirm = 'Are you sure you want to submit this for checking?';
            }
        @endphp
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <button class="btn btn-success" onclick="return confirm(@js($submitConfirm));">{{ $submitLabel }}</button>
            <button type="submit" formaction="{{ route('tasks.hold', $task->id) }}" formenctype="application/x-www-form-urlencoded" formnovalidate class="btn btn-ghost">⏸ On hold</button>
        </div>
    </form>
@elseif ($task->status === 'on_hold')
    <p class="muted" style="margin-bottom: 0.9rem;">This task is <strong>ON HOLD</strong>.</p>
    <form method="POST" action="{{ route('tasks.resume', $task->id) }}">
        @csrf
        <button class="btn btn-primary">▶ Resume work</button>
    </form>
@elseif ($task->status === 'for_checking')
    <p class="muted">Submitted {{ $task->submitted_at?->diffForHumans() }}. Waiting for approval.</p>
@elseif ($task->status === 'complete')
    <p class="muted">Approved {{ $task->approved_at?->diffForHumans() }}.</p>
@else
    <p class="muted">No actions available (status: {{ $task->statusLabel() }}).</p>
@endif
