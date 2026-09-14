@extends('layouts.app')

@section('title', 'Nothing at this address — Imprint Production')
@section('page-title', 'Nothing here')

{{--
    What the staff saw before this page existed was Laravel's bare
    "404 | NOT FOUND" — which reads like the system is broken, and the usual
    next move is to ask somebody whether the system is broken.

    Two quite different things land here and the page has to cover both
    without guessing wrong:

      the address is mistyped, or a link somewhere is stale;

      the thing WAS there and has since been cancelled, deleted, or replaced —
      a superseded order, a withdrawn request, a brief whose token expired.

    The second is much the commoner in a shop where work gets superseded, and
    it is the one that worries people, because "not found" sounds like data has
    been lost. So say plainly that nothing is lost, and send them to the list
    the thing would be on if it still exists.

    Deliberately never says WHAT was not found. A 404 is also the answer to
    "does order 51 exist", and answering that precisely for somebody who is
    not allowed to know is how a not-found page becomes a way to enumerate the
    shop's work.
--}}

@section('content')
<div class="card panel" style="max-width: 560px; margin: 2rem auto; text-align: center; padding: 2.5rem 2rem;">
    <div style="font-size: 2.4rem; line-height: 1;">🧭</div>

    <h1 style="margin: 0.8rem 0 0.4rem;">There is nothing at this address</h1>

    <p class="muted" style="margin-bottom: 0.4rem;">
        Either the link is wrong, or what used to be here has been cancelled or
        replaced since somebody saved it.
    </p>

    <p class="muted" style="font-size: 0.85rem; margin-bottom: 1.6rem;">
        Nothing has been lost and nothing needs reporting. If you followed this
        from a list inside the system, open the list again — a job that was
        superseded will be there under its new number.
    </p>

    @include('partials.error-way-back', ['id' => 'err404'])
</div>
@endsection
