@extends('layouts.app')

@section('title', $task->department.' — Imprint Production')
@section('page-title', 'Task — '.$task->department)

@section('content')
<div class="page-head">
    <div class="grow">
        <h1 style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
            {{ $task->department }}
            @include('partials.status', ['status' => $task->status])
        </h1>
        <p class="muted">Step {{ $task->sequence }} of {{ $task->order->tasks->count() }} · {{ $task->order->order_number }} · {{ $task->order->clientName() }}</p>
    </div>
</div>

<div class="card panel" style="margin-bottom: 1.4rem;">
    <h2>Order details</h2>
    <div class="tbl-wrap">
        <table class="tbl">
            <tbody>
                <tr><td style="color: var(--ink-3); width: 140px;">Order</td><td style="font-weight: 600;">{{ $task->order->order_number }}</td></tr>
                <tr><td style="color: var(--ink-3);">Customer</td><td>{{ $task->order->clientName() }}</td></tr>
                <tr>
                    <td style="color: var(--ink-3);">Account officer</td>
                    <td>
                        {{ $task->order->creator?->name ?? '—' }}
                        @if ($task->order->creator?->teamLabel())
                            <span class="badge" style="background: var(--accent-soft); color: #1d4ed8; margin-left: 0.3rem;">{{ $task->order->creator->teamLabel() }}</span>
                        @endif
                    </td>
                </tr>
                <tr><td style="color: var(--ink-3);">Quantity</td><td>{{ number_format($task->order->quantity) }} pcs</td></tr>
                <tr><td style="color: var(--ink-3);">Due date</td><td>{{ $task->order->due_date?->format('M j, Y') ?? '—' }}</td></tr>
                @if ($task->order->description)
                    <tr><td style="color: var(--ink-3);">Description</td><td style="white-space: pre-line;">{{ $task->order->description }}</td></tr>
                @endif
                @if ($task->instructions)
                    <tr><td style="color: var(--ink-3);">Instructions</td><td style="white-space: pre-line;">{{ $task->instructions }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>
</div>

{{-- The approved design from earlier steps, to work the current one from
     (e.g. the approved layout while making the final mockup). --}}
@if ($task->team === \App\Models\User::JOB_ARTIST)
    @php
        $priorDesign = $task->order->tasks
            ->where('team', \App\Models\User::JOB_ARTIST)
            ->where('sequence', '<', $task->sequence)
            ->where('status', 'complete')
            ->sortBy('sequence');
    @endphp
    @if ($priorDesign->isNotEmpty())
        <div class="card panel" style="margin-bottom: 1.4rem; border-left: 4px solid var(--success-ink);">
            <h2>Approved design to work from</h2>
            @foreach ($priorDesign as $pt)
                @php
                    $imgs = $pt->files->where('round', $pt->revision_count + 1)->filter(fn ($f) => $f->isImage());
                    if ($imgs->isEmpty()) { $imgs = $pt->files->filter(fn ($f) => $f->isImage()); }
                @endphp
                @if ($imgs->isNotEmpty())
                    <div style="font-size: 0.8rem; color: var(--ink-3); font-weight: 600; margin: 0.4rem 0;">✓ {{ $pt->department }}</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.8rem; margin-bottom: 0.6rem;">
                        @foreach ($imgs as $f)
                            <a href="{{ route('tasks.file.view', $f) }}" title="Open full size">
                                <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $pt->department }}" class="design-preview" style="max-height: 240px; max-width: 100%; border: 1px solid var(--border); border-radius: 8px; display: block;">
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    @endif
@endif

@if ($task->status === 'revision_required' && $task->revision_note)
    <div class="alert-error">
        <strong>Changes requested{{ $task->approver_role === 'sales' ? ' by the client' : ' by your leader' }}:</strong><br>
        {{ $task->revision_note }}
        <div style="margin-top:0.4rem; font-size:0.8rem;">
            Revision {{ $task->revision_count }} of {{ \App\Models\Task::MAX_REVISIONS }}
            @if ($task->revisionsLeft() === 0) — this is the last round. @endif
        </div>
    </div>
@endif

@if ($task->files->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Files you submitted</h2>
        @php
            // Network-path files (exports etc.) can't be previewed as images —
            // they live on the shared drive. Only real uploads get thumbnails.
            $externalFiles = $task->files->filter(fn ($f) => $f->isExternal());
            $uploadedFiles = $task->files->filter(fn ($f) => ! $f->isExternal());
            $imageFiles = $uploadedFiles->filter(fn ($f) => $f->isImage());
        @endphp

        {{-- Files referenced by their location on the shared drive. --}}
        @if ($externalFiles->isNotEmpty())
            <div style="display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 1rem;">
                @foreach ($externalFiles as $f)
                    <div style="border: 1px solid var(--border); border-radius: 10px; padding: 0.7rem 0.9rem; background: var(--surface-2);">
                        <div style="font-weight: 700; font-size: 0.85rem; margin-bottom: 0.35rem;">
                            📁 {{ $f->label ?? 'File' }}@if ($f->round) <span style="color: var(--ink-3); font-weight: 500;">· round {{ $f->round }}</span>@endif
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                            <code style="flex: 1; min-width: 200px; word-break: break-all; font-family: ui-monospace, Consolas, monospace; font-size: 0.8rem; background: #fff; border: 1px solid var(--border); border-radius: 6px; padding: 0.4rem 0.6rem;">{{ $f->external_path }}</code>
                            @if ($f->isWebLink())
                                <a href="{{ $f->external_path }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">↗ Open</a>
                            @endif
                            <button type="button" class="btn btn-ghost btn-sm" onclick="navigator.clipboard.writeText(@js($f->external_path)); this.textContent='✓ Copied';">📋 Copy path</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Real uploaded images — thumbnail previews. --}}
        @if ($imageFiles->isNotEmpty())
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
                @foreach ($imageFiles as $f)
                    <div style="text-align: center; width: 220px;">
                        <a href="{{ route('tasks.file.view', $f) }}" target="_blank">
                            <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label ?? $f->original_name }}" class="design-preview" style="max-width: 100%; max-height: 240px; border: 1px solid var(--border); border-radius: 8px; display: block; margin: 0 auto;">
                        </a>
                        <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.35rem;">
                            {{ $f->label ?? $f->original_name }}@if ($f->round) · round {{ $f->round }}@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- The upload table (network-path files are shown above, not here). --}}
        @if ($uploadedFiles->isNotEmpty())
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>What</th><th>File</th><th>Round</th><th>Size</th><th>Uploaded</th></tr></thead>
                    <tbody>
                        @foreach ($uploadedFiles as $f)
                            <tr>
                                <td style="font-weight: 600;">{{ $f->label ?? '—' }}</td>
                                <td><a href="{{ route('tasks.file.download', $f) }}">{{ $f->original_name }}</a></td>
                                <td>{{ $f->round }}</td>
                                <td style="color: var(--ink-3);">{{ $f->sizeForHumans() }}</td>
                                <td style="color: var(--ink-3); font-size: 0.8rem;">{{ $f->created_at->format('M j, g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

{{-- Every step of the order, not just the one clicked.

     The list outside shows one card per order and opens whatever step is
     current, so the rest of the job was invisible: an artist could not see what
     they had already done on it, what is running now, or what is still to come
     without leaving for another page. Their own steps are links; everybody
     else's are there to be read, since the artist does not work them. --}}
<div class="card panel" style="margin-bottom: 1.4rem;">
    <h2 style="margin-bottom: 0.3rem;">Steps on {{ $task->order->order_number }}</h2>
    <p class="sub" style="margin-bottom: 0.8rem;">
        {{ $task->order->clientName() }}
        @if ($task->order->quantity) · {{ number_format($task->order->quantity) }} pcs @endif
    </p>

    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>#</th><th>Step</th><th>Status</th><th>Who</th></tr></thead>
            <tbody>
                @foreach ($task->order->tasks->sortBy('sequence') as $step)
                    <tr @class(['is-current' => $step->id === $task->id])
                        style="{{ $step->id === $task->id ? 'background: var(--accent-soft);' : '' }}">
                        <td style="color: var(--ink-3);">{{ $step->sequence }}</td>
                        <td style="font-weight: {{ $step->id === $task->id ? '700' : '600' }};">
                            @if ($step->assigned_to === auth()->id() && $step->id !== $task->id)
                                <a href="{{ route('tasks.show', $step->id) }}">{{ $step->department }}</a>
                            @else
                                {{ $step->department }}
                            @endif
                            @if ($step->id === $task->id)
                                <span style="font-size: 0.72rem; color: var(--accent); font-weight: 700;">· you are here</span>
                            @endif
                        </td>
                        <td>@include('partials.status', ['status' => $step->status])</td>
                        <td style="color: var(--ink-2); font-size: 0.82rem;">
                            {{ $step->operator_name ?: ($step->assignee?->name ?? '—') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- The export paths for this ORDER, wherever you came in.

     They belong to the export step, and this page only ever showed the files of
     the step you clicked — so opening Layout, or any other step, showed nothing
     and the paths were only findable by knowing which step to open. They are
     what production works from, so they are worth having on any step of the
     job. Read-only here; the export step itself is where they are changed. --}}
@php
    $orderExport = $task->order->tasks->first(fn ($t) => $t->isExportStep());
    $orderExportFiles = $orderExport && $orderExport->id !== $task->id
        ? $orderExport->files->filter(fn ($f) => $f->isExternal())
        : collect();
@endphp

@if ($orderExportFiles->isNotEmpty())
    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2 style="margin-bottom: 0.3rem;">📤 Export files for this order</h2>
        <p class="sub" style="margin-bottom: 0.8rem;">
            Where the print-ready files were saved. This is what production opens.
        </p>

        <div style="display: flex; flex-direction: column; gap: 0.6rem;">
            @foreach ($orderExportFiles as $f)
                <div style="border: 1px solid var(--border); border-radius: 10px; padding: 0.7rem 0.9rem; background: var(--surface-2);">
                    <div style="font-weight: 700; font-size: 0.85rem; margin-bottom: 0.35rem;">
                        📁 {{ $f->label ?? 'File' }}
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <code style="flex: 1; min-width: 200px; word-break: break-all; font-family: ui-monospace, Consolas, monospace; font-size: 0.8rem; background: #fff; border: 1px solid var(--border); border-radius: 6px; padding: 0.4rem 0.6rem;">{{ $f->external_path }}</code>
                        <button type="button" class="btn btn-ghost btn-sm"
                                onclick="navigator.clipboard.writeText(@js($f->external_path)); this.textContent='✓ Copied';">📋 Copy path</button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Changed in one place, on the step that owns them, so two steps of
             the same order cannot end up claiming different paths. Lands with
             the form already open: asking to edit the path and then being asked
             again on arrival is the same click twice. --}}
        <a href="{{ route('tasks.show', ['taskId' => $orderExport->id, 'edit' => 'path']) }}#edit-path"
           style="display: inline-block; margin-top: 0.7rem; font-size: 0.8rem; font-weight: 600;">
            ✎ Edit path and send again
        </a>
    </div>
@endif

@if ($task->team === \App\Models\User::JOB_ARTIST)
    {{-- The job order only reaches the artist once it's been filled and sent.
         On the layout step they work from the design/reference alone. --}}
    @php $joSent = $task->order->jobOrder?->status === 'sent_to_artist'; @endphp
    <div class="alert-success" style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
        <span>{{ $joSent ? '📋 Complete every manual field in this Tech Pack, then send it to the account officer.' : '🖼 The design to make for this order.' }}</span>
        <span style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <a href="{{ route('tasks.references', $task->id) }}" class="btn btn-primary btn-sm">🖼 Design to make</a>
            @if ($joSent)
                <a href="{{ route('tasks.job-order', $task->id) }}" class="btn btn-ghost btn-sm">📋 Open tech pack</a>
            @endif
        </span>
    </div>

    @if (filled($task->order->jobOrder?->reference_note))
        <div class="card panel" style="margin-bottom: 1.4rem; border-left: 4px solid var(--accent);">
            <h2 style="margin-bottom: 0.4rem;">📝 Notes from the account officer</h2>
            <p style="white-space: pre-line;">{{ $task->order->jobOrder->reference_note }}</p>
        </div>
    @endif
@endif

<div id="task-action-{{ $task->id }}" class="card panel" style="border-left: 4px solid var(--accent); scroll-margin-top: 1.5rem;">
    <h2>What to do now</h2>
    @include('partials.task-action', ['task' => $task])
</div>


@if (isset($nextTask) && $nextTask)
    <div class="card panel" style="margin-top: 1.4rem; border-left: 4px solid var(--success-ink);">
        <h2>Next step — {{ $nextTask->department }} @include('partials.status', ['status' => $nextTask->status])</h2>
        @include('partials.task-action', ['task' => $nextTask])
    </div>
@endif

<div style="margin-top: 1.4rem;">
    <a href="{{ route('tasks.mine') }}" class="btn btn-ghost btn-sm">← Back to my tasks</a>
</div>

<script>
    /* Show a thumbnail + name when the artist picks a file to submit, so they
       can confirm it's the right one before sending it for checking. */
    (function () {
        function humanSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        document.querySelectorAll('.js-file-preview').forEach(function (input) {
            var box = input.nextElementSibling;
            if (!box || !box.classList.contains('file-preview')) return;

            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                box.innerHTML = '';

                if (!file) { box.hidden = true; return; }
                box.hidden = false;

                if (file.type.indexOf('image/') === 0) {
                    var img = document.createElement('img');
                    img.className = 'design-preview';
                    img.alt = 'Selected file preview';
                    img.style.cssText =
                        'max-height:160px;max-width:100%;border:1px solid var(--border);' +
                        'border-radius:8px;display:block;margin-top:0.55rem;';
                    var reader = new FileReader();
                    reader.onload = function (e) { img.src = e.target.result; };
                    reader.readAsDataURL(file);
                    box.appendChild(img);
                }

                var meta = document.createElement('div');
                meta.style.cssText =
                    'font-size:0.78rem;color:var(--ink-2);margin-top:0.4rem;' +
                    'display:flex;align-items:center;gap:0.35rem;overflow-wrap:anywhere;';
                meta.textContent = (file.type.indexOf('image/') === 0 ? '🖼 ' : '📎 ') +
                    file.name + ' · ' + humanSize(file.size);
                box.appendChild(meta);
            });
        });
    })();
</script>
@endsection
