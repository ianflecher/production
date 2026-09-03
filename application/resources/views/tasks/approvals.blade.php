@extends('layouts.app')

@section('title', 'Approvals — Imprint Production')
@section('page-title', 'Approvals')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Waiting for your approval</h1>
        <p class="muted">Work submitted FOR CHECKING, oldest first. Approving marks the step COMPLETE and unlocks the next department.</p>
    </div>
</div>

@php
    // One shared tile for a submitted file.
    $fileTile = function ($f) {
        return $f;
    };
@endphp

@if ($packages->isEmpty() && $singles->isEmpty())
    <div class="card panel" style="text-align: center; padding: 2.5rem;">
        <p class="muted">Nothing to check right now. Submitted work will appear here.</p>
    </div>
@endif

@if ($packages->isNotEmpty() || $singles->isNotEmpty())
    <div class="card">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>What</th>
                        {{-- Who did the work. The artist leader's list is
                             scoped to the artists, so for them it is always an
                             artist and says so. A full leader's list also holds
                             printing, sewing and QC, where "Artist" would be a
                             lie about the person named in the row. --}}
                        <th>{{ auth()->user()->isArtistLead() ? 'Artist' : 'Done by' }}</th>
                        <th>Submitted</th>
                        <th>Submitted work</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Tech Packs already checked by their account officers. --}}
                    @foreach ($packages as $orderId => $group)
                        @php $order = $group->first()->order; @endphp
                        <tr>
                            <td>
                                <a href="{{ route('orders.show', $order) }}" style="font-weight: 600;">{{ $order->order_number }}</a>
                                <div style="font-size: 0.78rem; color: var(--ink-3);">{{ $order->clientName() }}</div>
                            </td>
                            <td>
                                <strong>Tech Pack</strong>
                                <div style="font-size: 0.74rem; color: var(--ink-3);">completed by artist · approved by account officer</div>
                            </td>
                            <td>{{ $group->first()->assignee?->name ?? '—' }}</td>
                            <td style="font-size: 0.84rem;">{{ $group->min('submitted_at')?->diffForHumans() ?? '—' }}</td>
                            <td>
                                {{-- Production details live in the package document now. --}}
                                <div style="display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-start;">
                                    @if ($order->jobOrder)
                                        {{-- One document: mockup, template, job order, production details. --}}
                                        <a href="{{ route('orders.package', $order) }}" class="btn btn-primary btn-sm" style="font-size: 0.72rem;">📄 Open full document</a>
                                    @endif
                                    {{-- No thumbnails here — the design is reviewed by opening
                                         the document above. Non-image files still need a link. --}}
                                    @foreach ($group->sortBy('department') as $task)
                                        @php
                                            $latest = $task->files->where('round', $task->revision_count + 1)
                                                ->reject(fn ($f) => str_starts_with((string) $f->label, 'Mockup (from'))
                                                ->reject(fn ($f) => $f->isImage());
                                        @endphp
                                        @foreach ($latest as $f)
                                            <div style="text-align:center;">
                                                @if ($f->isPdf())
                                                    <a href="{{ route('tasks.file.view', $f) }}" target="_blank" style="font-size: 0.82rem;">📄 Open PDF</a>
                                                @else
                                                    <a href="{{ route('tasks.file.download', $f) }}" style="font-size: 0.82rem;">⬇ {{ Str::limit($f->original_name, 14) }}</a>
                                                @endif
                                                <div style="font-size: 0.68rem; color: var(--ink-3); margin-top: 0.15rem;">{{ $task->department }}</div>
                                            </div>
                                        @endforeach
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                    <form method="POST" action="{{ route('tasks.approve-package', $order) }}"
                                          onsubmit="return confirm('Give this Tech Pack final approval and release production?');">
                                        @csrf
                                        <button class="btn btn-success btn-sm">Approve Tech Pack ✓</button>
                                    </form>
                                    <details class="inline-form">
                                        <summary class="btn btn-danger btn-sm">Request revision</summary>
                                        <div class="pop">
                                            <form method="POST" action="{{ route('tasks.revise-package', $order) }}">
                                                @csrf
                                                <input type="hidden" name="items[]" value="template">
                                                <p class="muted" style="font-size:0.82rem; margin-bottom:0.6rem;">The artist will correct the Tech Pack, then it returns through the account officer before coming back to you.</p>
                                                <label>What needs to be fixed?</label>
                                                <textarea name="revision_note" rows="3" required maxlength="2000" placeholder="Explain the problem…"></textarea>
                                                <button class="btn btn-danger btn-sm" style="margin-top: 0.5rem;">Send back for revision</button>
                                            </form>
                                        </div>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    {{-- Everything else — one row each. --}}
                    @foreach ($singles as $task)
                        <tr>
                            <td>
                                <a href="{{ route('orders.show', $task->order) }}" style="font-weight: 600;">{{ $task->order->order_number }}</a>
                                <div style="font-size: 0.78rem; color: var(--ink-3);">{{ $task->order->clientName() }}</div>
                            </td>
                            <td>
                                {{ $task->department }}
                                @if ($task->revision_count > 0)
                                    <div style="font-size: 0.72rem; color: var(--ink-3);">revisions: {{ $task->revision_count }}/{{ \App\Models\Task::MAX_REVISIONS }}</div>
                                @endif
                            </td>
                            <td>{{ $task->assignee?->name ?? '—' }}</td>
                            <td style="font-size: 0.84rem;">{{ $task->submitted_at?->diffForHumans() ?? '—' }}</td>
                            <td>
                                @php $latest = $task->files->where('round', $task->revision_count + 1); @endphp
                                @if ($task->auto_submit)
                                    <a href="{{ route('orders.job-order', $task->order) }}" class="btn btn-primary btn-sm">📋 Open tech pack</a>
                                @else
                                    @forelse ($latest as $f)
                                        <div style="margin-bottom: 0.5rem;">
                                            @if ($f->isImage())
                                                <a href="{{ route('tasks.file.view', $f) }}" target="_blank">
                                                    <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label ?? $f->original_name }}" class="design-preview"
                                                         style="max-width: 130px; max-height: 100px; border: 1px solid var(--border); border-radius: 8px; display: block;">
                                                </a>
                                            @elseif ($f->isPdf())
                                                <a href="{{ route('tasks.file.view', $f) }}" target="_blank" style="font-size: 0.82rem;">📄 Open PDF</a>
                                            @else
                                                <a href="{{ route('tasks.file.download', $f) }}" style="font-size: 0.82rem;">⬇ {{ Str::limit($f->original_name, 16) }}</a>
                                            @endif
                                        </div>
                                    @empty
                                        <span style="color: var(--ink-3);">—</span>
                                    @endforelse
                                @endif
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                    <form method="POST" action="{{ route('tasks.approve', $task) }}">
                                        @csrf
                                        <button class="btn btn-success btn-sm">Approve ✓</button>
                                    </form>
                                    @if ($task->canRequestRevision())
                                        <details class="inline-form">
                                            <summary class="btn btn-danger btn-sm">Request revision</summary>
                                            <div class="pop">
                                                <form method="POST" action="{{ route('tasks.revision', $task) }}">
                                                    @csrf
                                                    <label>What needs to be fixed?</label>
                                                    <textarea name="revision_note" rows="3" required maxlength="2000" placeholder="Explain the problem for the agent…"></textarea>
                                                    <button class="btn btn-danger btn-sm" style="margin-top: 0.5rem;">Send back for revision</button>
                                                </form>
                                            </div>
                                        </details>
                                    @else
                                        <span style="font-size: 0.75rem; color: var(--danger-ink);">revision limit reached</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- Already checked. This queue is shared — Maam Carla and the artist leader
     both work from it — so a pack one of them signs off has to stay readable
     to the other instead of just disappearing off the page. --}}
@if ($checked->isNotEmpty())
    <div class="page-head" style="margin-top: 1.5rem;">
        <div class="grow">
            <h2 style="margin:0;">Already checked</h2>
            <p class="muted">Signed off in the last 7 days. Nothing left to do here — this is the record of what went through.</p>
        </div>
    </div>

    <div class="card">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>What</th>
                        <th>Artist</th>
                        <th>Approved</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($checked as $group)
                        @php
                            $order = $group->first()->order;
                            $when = $group->max('approved_at');
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('orders.show', $order) }}" style="font-weight: 600;">{{ $order->order_number }}</a>
                                <div style="font-size: 0.78rem; color: var(--ink-3);">{{ $order->clientName() }}</div>
                            </td>
                            <td>
                                <strong>Tech Pack</strong>
                                <div style="font-size: 0.74rem; color: var(--ink-3);">artist → account officer → leader</div>
                            </td>
                            <td>{{ $group->first(fn ($t) => $t->assignee)?->assignee?->name ?? '—' }}</td>
                            <td style="font-size: 0.84rem;">
                                <span class="pill pill-success">Approved</span>
                                <div style="font-size: 0.78rem; color: var(--ink-3);">{{ $when?->diffForHumans() ?? '—' }}</div>
                            </td>
                            <td>
                                <a href="{{ route('orders.job-order', $order) }}" class="btn btn-ghost btn-sm">📋 Open tech pack</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- The artists' bench.

     Who has what, and the place to change it. The only assign control used to
     be on the order page, which the artist leader cannot open — so the person
     who knows which artist is free could only steer it sideways, by marking
     who was in and letting the rotation choose. --}}
@if ($bench->isNotEmpty())
    <div class="card panel">
        <h2>The artists' work</h2>
        <p class="sub">Every open artist step and who has it. Changing it here hands the step over.</p>

        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr><th>Job order</th><th>Step</th><th>Status</th><th>Who has it</th></tr>
                </thead>
                <tbody>
                    @foreach ($bench as $step)
                        <tr>
                            <td style="font-weight:600;">{{ $step->order?->order_number ?? '—' }}
                                <div style="font-size:0.76rem; color:var(--ink-3);">{{ $step->order?->clientName() }}</div>
                            </td>
                            <td>{{ $step->department }}</td>
                            <td style="font-size:0.82rem;">{{ \App\Models\Task::STATUS_LABELS[$step->status] ?? $step->status }}</td>
                            <td>
                                <form method="POST" action="{{ route('tasks.assign', $step) }}"
                                      style="display:flex; gap:0.4rem; align-items:center;">
                                    @csrf
                                    <select name="assigned_to"
                                            style="width:auto; min-width:150px; padding:0.35rem 0.5rem; font-size:0.82rem;">
                                        <option value="">— Unassigned —</option>
                                        @foreach ($artists as $artist)
                                            <option value="{{ $artist->id }}" @selected($step->assigned_to === $artist->id)>{{ $artist->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-ghost btn-sm">Save</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
