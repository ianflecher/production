@extends('layouts.app')

@section('title', 'Page expired — Imprint Production')
@section('page-title', 'Page expired')

{{--
    What the staff saw before this page existed was Laravel's bare "419 | PAGE
    EXPIRED" — no explanation, no way back, and no hint that pressing back and
    saving again would work. It reads like the system is broken.

    The page has not gone wrong: the form was open longer than the sign-in
    lasted. So say that, and give the two buttons that actually get the person
    moving again.
--}}

@section('content')
<div class="card panel" style="max-width: 560px; margin: 2rem auto; text-align: center; padding: 2.5rem 2rem;">
    <div style="font-size: 2.4rem; line-height: 1;">⏱️</div>

    <h1 style="margin: 0.8rem 0 0.4rem;">This page sat open too long</h1>

    <p class="muted" style="margin-bottom: 0.4rem;">
        Nothing is broken and nothing is lost yet — the sign-in behind this form
        timed out while it was open, so it would not save.
    </p>

    <p class="muted" style="font-size: 0.85rem; margin-bottom: 1.6rem;">
        Go back, and what you typed should still be in the boxes. Copy anything
        long somewhere safe first, then send it again.
    </p>

    <div style="display: flex; gap: 0.6rem; justify-content: center; flex-wrap: wrap;">
        <button type="button" class="btn btn-primary" onclick="history.back();">
            ← Back to what I was typing
        </button>
        <a href="{{ url('/') }}" class="btn btn-ghost">Start again from the top</a>
    </div>
</div>
@endsection
