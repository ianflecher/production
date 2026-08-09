@extends('layouts.app')

@section('title', 'Log in — Imprint Production')

@section('content')
{{-- Full-bleed dark product hero with the sign-in form floating over the empty
     space on the right. Only presentation is custom — the form action, fields,
     CSRF and scripts are unchanged. --}}
<style>
    .guest-main { padding: 0; background: #0D0D0D; }

    .login {
        --lg-bg: #0D0D0D;
        --lg-surface: #171717;
        --lg-border: #393939;
        --lg-ink: #ffffff;
        --lg-ink-2: #b8b8b8;
        --lg-ink-3: #7d7d7d;
        --lg-red: #E62129;
        --lg-red-dark: #A80F16;
        font-family: var(--font-body);
        position: relative;
        width: 100%;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding: 2rem clamp(1.5rem, 6vw, 6rem);
        background: #0D0D0D url('{{ asset('login.png') }}') center center / cover no-repeat;
        color: var(--lg-ink);
        overflow: hidden;
    }
    /* Gentle vignette so the form stays readable without hiding the product shot. */
    .login::before {
        content: ''; position: absolute; inset: 0; pointer-events: none;
        background:
            linear-gradient(90deg, rgba(13,13,13,.10) 0%, rgba(13,13,13,.35) 55%, rgba(13,13,13,.78) 100%),
            linear-gradient(0deg, rgba(13,13,13,.45) 0%, transparent 40%);
    }

    /* ---------- Floating form card ---------- */
    .login-box {
        position: relative; z-index: 1;
        width: 100%; max-width: 380px;
        background: rgba(17, 17, 17, .82);
        -webkit-backdrop-filter: blur(14px) saturate(140%);
                backdrop-filter: blur(14px) saturate(140%);
        border: 1.5px solid var(--lg-border);
        border-radius: 12px;
        padding: 2.4rem 2.2rem;
        box-shadow: 0 24px 70px rgba(0,0,0,.6);
    }

    .login-brand { display: flex; align-items: center; gap: .7rem; margin-bottom: 2rem; }
    .login-brand .mark {
        width: 46px; height: 46px; border-radius: 6px;
        background: var(--lg-red);
        display: grid; place-items: center;
        font-family: var(--font-head); font-weight: 700; font-size: 1.15rem; color: #fff;
        box-shadow: 3px 3px 0 rgba(0,0,0,.5);
    }
    .login-brand .txt strong {
        display: block; font-family: var(--font-head); font-weight: 600;
        text-transform: uppercase; letter-spacing: .05em; font-size: 1.25rem; line-height: 1; color: #fff;
    }
    .login-brand .txt small {
        display: block; margin-top: 4px; font-size: .62rem; font-weight: 600;
        letter-spacing: .28em; text-transform: uppercase; color: var(--lg-red);
    }

    .login-title {
        font-family: var(--font-head); font-weight: 600; text-transform: uppercase;
        font-size: 1.7rem; letter-spacing: .01em; line-height: 1; margin-bottom: .4rem; color: #fff;
    }
    .login-sub { font-size: .88rem; color: var(--lg-ink-2); margin-bottom: 1.6rem; }

    .login .field { margin-bottom: 1.05rem; }
    .login label {
        display: block; font-size: .72rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .1em; color: var(--lg-ink-2); margin-bottom: .45rem;
    }
    .login input[type="email"],
    .login input[type="password"],
    .login input[type="text"] {
        width: 100%;
        padding: .8rem .9rem;
        background: var(--lg-surface);
        border: 1.5px solid var(--lg-border);
        border-radius: 6px;
        color: #fff; font-size: .95rem; font-family: inherit;
        text-transform: none;
        transition: border-color .14s, box-shadow .14s, background .14s;
    }
    .login input::placeholder { color: var(--lg-ink-3); }
    .login input:hover:not(:focus) { border-color: #4d4d4d; }
    .login input:focus {
        outline: none; border-color: var(--lg-red); background: #1c1c1c;
        box-shadow: 0 0 0 3px rgba(230,33,41,.22);
    }

    .login .pw-wrap { position: relative; }
    .login .pw-toggle {
        position: absolute; right: .4rem; top: 50%; transform: translateY(-50%);
        background: none; border: none; cursor: pointer;
        color: var(--lg-ink-3); font-size: .68rem; font-weight: 700; letter-spacing: .08em;
        text-transform: uppercase; padding: .4rem .55rem; border-radius: 4px;
    }
    .login .pw-toggle:hover { color: #fff; }

    .login .remember {
        display: flex; align-items: center; gap: .55rem; text-transform: none;
        font-size: .85rem; font-weight: 400; letter-spacing: 0; color: var(--lg-ink-2);
        margin: .2rem 0 1.4rem;
    }
    .login .remember input {
        width: auto; margin: 0; accent-color: var(--lg-red); width: 16px; height: 16px;
    }

    .login .btn-login {
        width: 100%;
        display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
        padding: .9rem 1rem;
        background: var(--lg-red); color: #fff;
        border: none; border-radius: 6px;
        font-family: var(--font-head); font-weight: 600; font-size: 1.02rem;
        text-transform: uppercase; letter-spacing: .06em;
        cursor: pointer;
        box-shadow: 4px 4px 0 rgba(0,0,0,.5);
        transition: background .14s, transform .08s, box-shadow .14s;
    }
    .login .btn-login:hover { background: var(--lg-red-dark); }
    .login .btn-login:active { transform: translate(2px, 2px); box-shadow: 2px 2px 0 rgba(0,0,0,.5); }
    .login .btn-login:focus-visible { outline: none; box-shadow: 4px 4px 0 rgba(0,0,0,.5), 0 0 0 3px rgba(230,33,41,.4); }

    .login .alert-error {
        display: flex; align-items: flex-start; gap: .5rem;
        background: #2a1416; border: 1.5px solid #5a1f22; border-left: 4px solid var(--lg-red);
        color: #ff8a8d; border-radius: 6px; padding: .75rem .9rem; font-size: .85rem;
        margin-bottom: 1.3rem;
    }

    .login-foot {
        margin-top: 1.8rem; padding-top: 1.2rem; border-top: 1px solid var(--lg-border);
        text-align: center; font-size: .74rem; color: var(--lg-ink-3); letter-spacing: .04em;
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 720px) {
        .login { justify-content: center; padding: 1.5rem; background-position: center top; }
        .login::before {
            background:
                linear-gradient(0deg, rgba(13,13,13,.55) 0%, rgba(13,13,13,.35) 100%);
        }
        /* iOS zooms the page in when a tapped field is under 16px, leaving the
           login box shifted off-centre. Hold the fields at 16px on phones. */
        .login input[type="email"],
        .login input[type="password"],
        .login input[type="text"] { font-size: 16px; }
    }
    @media (max-width: 420px) {
        .login-box { padding: 2rem 1.5rem; }
        .login-brand .mark { width: 40px; height: 40px; }
    }
</style>

<div class="login">
    <div class="login-box">
        <div class="login-brand">
            <div class="mark">IC</div>
            <div class="txt">
                <strong>Imprint</strong>
                <small>Production</small>
            </div>
        </div>

        <h2 class="login-title">Welcome back</h2>
        <p class="login-sub">Sign in with your work account to continue.</p>

        @if ($errors->any())
            <div class="alert-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}">
            @csrf

            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="you@imprintcustoms.ph">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••••" style="padding-right: 3.4rem;">
                    <button type="button" id="togglePassword" class="pw-toggle" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>

            <label class="remember">
                <input type="checkbox" name="remember" value="1">
                Keep me signed in on this device
            </label>

            <button type="submit" class="btn-login">Log in</button>
        </form>

        <p class="login-foot">
            Internal system &middot; Authorized Imprint Customs staff only
        </p>
    </div>
</div>

<script>
    (function () {
        var btn = document.getElementById('togglePassword');
        var input = document.getElementById('password');
        if (!btn || !input) return;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            input.focus();
        });
    })();
</script>
@endsection
