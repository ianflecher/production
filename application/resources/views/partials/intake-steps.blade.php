{{-- Where this job has got to, on the three pages that take it in.

     "Step 2 of 3" written in a sentence tells you the number but not what is
     behind or ahead of you — which page you came from, what is still to do,
     and whether the thing you are looking at is the last one. Drawn out, it
     answers all three at a glance.

     Expects: $on — 1, 2 or 3. --}}
@php
    $steps = [
        1 => ['label' => 'Client', 'note' => 'Who is asking'],
        2 => ['label' => 'Design', 'note' => 'What the artist works from'],
        3 => ['label' => 'Job order', 'note' => 'What is being made'],
    ];
@endphp

<ol class="intake-steps">
    @foreach ($steps as $n => $step)
        <li @class([
            'intake-step',
            'is-done' => $n < $on,
            'is-now' => $n === $on,
        ])>
            <span class="intake-step-n">{{ $n < $on ? '✓' : $n }}</span>
            <span class="intake-step-body">
                <span class="intake-step-label">{{ $step['label'] }}</span>
                <span class="intake-step-note">{{ $step['note'] }}</span>
            </span>
        </li>
    @endforeach
</ol>
