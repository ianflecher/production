@extends('layouts.app')

@section('title', 'Sample Review — Imprint Production')
@section('page-title', 'Sample Review')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>For your review</h1>
    </div>
</div>

@if ($tasks->isEmpty())
    <div class="card panel" style="text-align: center; padding: 2.5rem;">
        <p class="muted">No samples waiting.</p>
    </div>
@else
    <div style="display: grid; gap: 1.1rem;">
        @foreach ($tasks as $task)
            @php
                $latest = $task->files->where('round', $task->revision_count + 1);
                // A physical sample is a garment on a table. There is no file to
                // attach and there never has been on any of them, so the missing
                // one is not news — it just printed a red warning under every
                // sample the shop has ever sewn.
                $isPhysicalSample = $task->department === 'Produce sample for client';
            @endphp
            <div class="card panel">
                <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:flex-start;">
                    <div>
                        <h2 style="margin-bottom:0.2rem;">
                            <a href="{{ route('orders.show', $task->order) }}">{{ $task->order->order_number }}</a>
                            — {{ $task->order->clientName() }}
                        </h2>
                        <p class="muted" style="font-size:0.85rem;">
                            {{ $task->department }} · by {{ $task->assignee?->name ?? 'unassigned' }} ·
                            submitted {{ $task->submitted_at?->format('M j, g:i A') }}
                            @if ($task->order->client?->contact_number) · 📱 {{ $task->order->client->contact_number }} @endif
                        </p>
                    </div>
                    <div style="text-align:right;">
                        @include('partials.status', ['status' => $task->status])
                        <div style="font-size:0.75rem; color: var(--ink-3); margin-top:0.3rem;">
                            Revisions used: {{ $task->revision_count }}/{{ \App\Models\Task::MAX_REVISIONS }}
                        </div>
                    </div>
                </div>

                @if ($task->revision_note)
                    <div class="alert-error" style="margin-top:0.9rem;"><strong>Last revision note:</strong> {{ $task->revision_note }}</div>
                @endif

                <div style="margin-top: 1rem; display:flex; gap:0.8rem; flex-wrap:wrap; align-items:center;">
                    @forelse ($latest as $f)
                        @if ($f->isImage())
                            <a href="{{ route('tasks.file.view', $f) }}" target="_blank" title="Click to view full size" style="text-align:center;">
                                <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label }}" class="design-preview" style="max-height: 200px; max-width: 260px; border: 1px solid var(--border); border-radius: 8px; display:block;">
                                <span style="font-size:0.72rem; color: var(--ink-3);">{{ $f->label ?? 'Sample' }}</span>
                            </a>
                        @else
                            <a href="{{ route('tasks.file.view', $f) }}" target="_blank" class="btn btn-primary btn-sm">👁 View {{ $f->label ?? 'file' }}</a>
                        @endif
                    @empty
                        @if ($isPhysicalSample)
                            {{-- Nothing to look at on screen, so say where the thing
                                 actually is. Without this the card reads as broken:
                                 a review page with nothing to review on it. --}}
                            <span style="font-size:0.85rem; color: var(--ink-2);">
                                📦 The sample garment is on its way to you — please wait for it to arrive,
                                then check it in person. Something wrong with it? Use
                                <strong>Send back to production</strong> below, or
                                <a href="{{ route('messages.show', $task->order) }}">message the team</a>.
                            </span>
                        @else
                            {{-- Still worth saying on an ARTIST's step: a layout sent
                                 for review with no artwork on it is a real fault. --}}
                            <span style="font-size:0.85rem; color: var(--danger-ink);">No file attached.</span>
                        @endif
                    @endforelse
                    {{-- The job order isn't relevant while the client is still
                         reviewing the LAYOUT (no downpayment, job order still a
                         draft) — only show it for later sample reviews. --}}
                    @if ($task->order->jobOrder && $task->order->mockupApproved())
                        <a href="{{ route('orders.job-order', $task->order) }}" class="btn btn-ghost btn-sm">📋 View tech pack</a>
                    @endif
                </div>

                @php
                    // Nothing leaves the shop unpaid. Say so here rather than
                    // letting them click and be refused.
                    $heldForPayment = $task->department === 'Release to client' && ! $task->order->isFullyPaid();
                @endphp

                <div style="margin-top: 1.1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                    @if ($heldForPayment)
                        @php $bal = $task->order->balance(); @endphp
                        <div class="alert-error" style="margin-bottom: 1rem;">
                            <strong>Not paid in full — cannot release.</strong>
                            @if ($bal === null)
                                This order has no total price set, so there is nothing to check the payments against.
                            @else
                                ₱{{ number_format($bal, 2) }} is still outstanding
                                (paid ₱{{ $task->order->totalPaid() }} of ₱{{ number_format($task->order->total_price, 2) }}).
                            @endif
                            <a href="{{ route('orders.show', $task->order) }}#payment-section">Record the payment</a>, then release.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('tasks.approve', $task) }}"
                          onsubmit="return confirm('{{ $isPhysicalSample ? 'Client approved the sample? Mass production will start.' : 'Client approved this sample?' }}');" style="margin-bottom: 1rem;">
                        @csrf
                        <button class="btn btn-success btn-sm" @disabled($heldForPayment)>✓ Client approved</button>
                    </form>

                    @if ($isPhysicalSample)
                        {{-- A physical sample goes back to the production step that got
                             it wrong — not to an artist. --}}
                        @php
                            $stages = $task->order->tasks
                                ->whereBetween('stage', [3, 8])
                                ->sortBy('sequence');
                        @endphp
                        <form method="POST" action="{{ route('tasks.return-to-stage', $task) }}"
                              onsubmit="return confirm('Send the sample back? That step and everything after it will run again.');">
                            @csrf
                            <label style="font-weight: 600; font-size: 0.9rem;">Send back to</label>
                            <select name="department" required style="max-width: 320px; margin-top: 0.35rem;">
                                <option value="">— Which step? —</option>
                                @foreach ($stages as $s)
                                    <option value="{{ $s->department }}">{{ $s->department }}</option>
                                @endforeach
                            </select>
                            <textarea name="revision_note" rows="3" required maxlength="2000"
                                      placeholder="What needs fixing?"
                                      style="margin-top: 0.5rem;"></textarea>
                            <button class="btn btn-danger btn-sm" style="margin-top: 0.5rem;">↩ Send back to production</button>
                        </form>
                    @elseif ($task->canRequestRevision())
                        <form method="POST" action="{{ route('tasks.revision', $task) }}">
                            @csrf
                            <label style="font-weight: 600; font-size: 0.9rem;">Revision note ({{ $task->revisionsLeft() }} left)</label>
                            <textarea name="revision_note" rows="3" required maxlength="2000" placeholder="What needs fixing?" style="margin-top: 0.35rem;"></textarea>
                            <button class="btn btn-danger btn-sm" style="margin-top: 0.5rem;">↩ Send back to artist</button>
                        </form>
                    @else
                        <span style="font-size:0.82rem; color: var(--danger-ink);">
                            ⚠ Revision limit ({{ \App\Models\Task::MAX_REVISIONS }}) reached — approve it or ask the leader to step in.
                        </span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
