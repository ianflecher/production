@extends('layouts.app')

@section('title', 'Change your password — Imprint Production')
@section('page-title', 'Change your password')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Choose your own password</h1>
        <p class="muted">
            @if ($forced)
                The password you were given is known to whoever handed it to you. Pick one only you know —
                you cannot use the rest of the app until you do.
            @else
                Pick a new password for your account.
            @endif
        </p>
    </div>
</div>

@if ($errors->any())
    <div class="alert-error" style="max-width: 520px; margin-bottom: 1rem;">
        @foreach ($errors->all() as $e) {{ $e }}<br> @endforeach
    </div>
@endif

<div class="card panel" style="max-width: 520px;">
    <form method="POST" action="{{ route('password.change.save') }}" style="display:grid; gap:0.9rem;">
        @csrf
        <div>
            <label for="current_password">The password you signed in with</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div>
            <label for="password">New password</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
            <p class="sub" style="margin-top:0.3rem;">At least 8 characters.</p>
        </div>
        <div>
            <label for="password_confirmation">New password again</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>
        <div><button class="btn btn-primary">Save my password</button></div>
    </form>
</div>
@endsection
