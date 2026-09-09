{{-- Sending the pack to the artist.

     Kept in one file because it belongs in two places: on the sheet the office
     reads, and on the officer's own editor. It used to be only the first, so an
     officer who had just finished filling the pack in was sent to production
     details, then to the order page, and had to go and open the pack AGAIN to
     find this button. The last thing they do is send it, so the button is where
     they finish.

     Expects $order and $jo. --}}

@if ($jo->status === 'draft')
    @php
        $canSend = $order->mockupApproved()
            && $order->hasDownpayment()
            && $jo->referenceFiles->isNotEmpty();
        $sendBlockReason = ! $order->mockupApproved()
            ? 'The final mockup must be approved first.'
            : (! $order->hasDownpayment()
                ? 'Record the downpayment before sending.'
                : (! $jo->referenceFiles->isNotEmpty()
                    ? 'Upload a client reference before sending.'
                    : null));
    @endphp
    @if ($canSend)
        {{-- Not "blank" any more. It said that when the officer had nothing to
             fill in and the pack went out empty for the artist to complete
             from the order form. The officer fills the spec in first now, so
             what they send is the sheet they have just written. --}}
        <form method="POST" action="{{ route('job-orders.send', $order) }}" onsubmit="return confirm('Send this Tech Pack to the artist?');" style="margin-right: auto;">
            @csrf
            <button type="submit" class="btn btn-success btn-sm">📤 Send the Tech Pack to the artist</button>
        </form>
    @else
        <span style="margin-right: auto; color: var(--danger-ink); font-weight: 600; font-size: 0.85rem;">⚠ {{ $sendBlockReason }}</span>
    @endif
@else
    @php
        /* WHO has it, and where it has got to.

           This said "Sent to the artist" and the date, for good - the same line
           on a pack sent an hour ago and one the leader signed off last week.
           It answered "did it leave my desk?" when the question being asked is
           "where is it now?", and it never said which artist had it, so the
           officer had to open the pipeline to find out.

           Read off the Tech Pack step, which is the thing that actually moves:
           the one still open, or the last one once the job is past it. */
        $packTask = $order->tasks->first(fn ($task) => $task->isTechPackStep()
                && ! in_array($task->status, ['complete', 'cancelled'], true))
            ?? $order->tasks->first(fn ($task) => $task->isTechPackStep());

        $who = $packTask?->assignee?->name;

        [$packState, $packTone] = match (true) {
            ! $packTask => ['✓ Sent to the artist', 'var(--success-ink)'],
            $packTask->status === 'complete' => ['✓ Tech pack approved', 'var(--success-ink)'],
            $packTask->status === 'revision_required' => [
                '↩ Sent back to '.($who ?? 'the artist'),
                'var(--danger-ink)',
            ],
            $packTask->status === 'for_checking' && $packTask->approver_role === 'leader' => [
                '⏳ With the leader for checking',
                'var(--ink-2)',
            ],
            $packTask->status === 'for_checking' => [
                '⏳ Handed back — waiting on the account officer',
                'var(--ink-2)',
            ],
            $packTask->status === 'in_progress' => [
                $who ? '✎ '.$who.' is drawing it' : '✎ With the artist',
                'var(--ink-2)',
            ],
            // Released and waiting to be picked up. Nobody assigned means the
            // rotation found no artist marked in today - worth saying, because
            // it is the one case where somebody has to act.
            default => $who
                ? ['✓ Sent to '.$who, 'var(--success-ink)']
                : ['⚠ Sent — but no artist is in today to take it', 'var(--danger-ink)'],
        };
    @endphp
    <span style="margin-right: auto; color: {{ $packTone }}; font-weight: 600; font-size: 0.85rem;">
        {{ $packState }}
        <span style="font-weight: 500; color: var(--ink-3);">— sent {{ $jo->sent_to_artist_at?->format('M j, g:i A') }}</span>
    </span>
@endif
