@extends('layouts.app')

@section('title', 'Not yours to open — Imprint Production')
@section('page-title', 'Not yours to open')

{{--
    What the staff saw before this page existed was Laravel's bare
    "403 | FORBIDDEN" — no explanation, no way back, and no way to tell an
    account that is missing a permission from a link that was never theirs.
    It reads like the system is broken, and the usual next move is to ask
    somebody whether the system is broken.

    The board shows the whole shop's work on purpose, so a person will click
    through to a brief that is somebody else's. That is not a fault and should
    not be reported like one: say whose it is, and give them the way back.

    Whatever the refusal was raised with is shown when there is one — see
    InquiryController::assertMine, which names the officer.
--}}

@section('content')
<div class="card panel" style="max-width: 560px; margin: 2rem auto; text-align: center; padding: 2.5rem 2rem;">
    <div style="font-size: 2.4rem; line-height: 1;">🔒</div>

    <h1 style="margin: 0.8rem 0 0.4rem;">This one is not yours</h1>

    @php
        // Laravel puts its own generic wording here when nothing was said.
        $said = trim((string) ($exception?->getMessage() ?? ''));
        $said = in_array(strtolower($said), ['', 'forbidden', 'this action is unauthorized.', 'user does not have the right roles.'], true)
            ? null
            : $said;
    @endphp

    <p class="muted" style="margin-bottom: 0.4rem;">
        {{ $said ?? 'You can see it on the board, but opening it is not part of your account.' }}
    </p>

    <p class="muted" style="font-size: 0.85rem; margin-bottom: 1.6rem;">
        Nothing has gone wrong and nothing needs reporting. If you need it
        opened, the person named above can send it to you, or your leader can.
    </p>

    @include('partials.error-way-back', ['id' => 'err403'])
</div>
@endsection
