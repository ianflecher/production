@extends('layouts.app')

@section('title', 'Imprint Customs')

@section('content')
{{-- The same hero as the sign-in page, because this is the step before it and
     should not look like a different building. Only the card differs. --}}
<style>
    .guest-main { padding: 0; background: #0D0D0D; }

    .gate {
        --g-ink: #ffffff;
        --g-ink-2: #b8b8b8;
        --g-ink-3: #7d7d7d;
        --g-border: #393939;
        --g-red: #E62129;
        --g-red-dark: #A80F16;
        font-family: var(--font-body);
        position: relative;
        width: 100%;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding: 2rem clamp(1.5rem, 6vw, 6rem);
        background: #0D0D0D url('{{ asset('login.png') }}') center center / cover no-repeat;
        color: var(--g-ink);
        overflow: hidden;
    }
    .gate::before {
        content: ''; position: absolute; inset: 0; pointer-events: none;
        background:
            linear-gradient(90deg, rgba(13,13,13,.10) 0%, rgba(13,13,13,.35) 55%, rgba(13,13,13,.78) 100%),
            linear-gradient(0deg, rgba(13,13,13,.45) 0%, transparent 40%);
    }

    .gate-box {
        position: relative; z-index: 1;
        width: 100%; max-width: 420px;
        background: rgba(17, 17, 17, .82);
        border: 1px solid var(--g-border);
        border-radius: 14px;
        padding: 2.4rem 2.1rem;
        backdrop-filter: blur(10px);
        box-shadow: 0 24px 60px rgba(0,0,0,.5);
    }

    .gate-brand { display: flex; align-items: center; gap: .7rem; margin-bottom: 1.6rem; }
    .gate-brand .mark {
        width: 38px; height: 38px; border-radius: 9px; background: var(--g-red);
        display: grid; place-items: center; font-weight: 800; font-size: .95rem; letter-spacing: .02em;
    }
    .gate-brand .txt strong { display: block; font-size: 1.02rem; letter-spacing: .01em; }
    .gate-brand .txt small { display: block; font-size: .7rem; color: var(--g-ink-3); letter-spacing: .18em; text-transform: uppercase; }

    .gate-title { font-family: var(--font-display, inherit); font-size: 1.5rem; margin: 0 0 .35rem; }
    .gate-sub { color: var(--g-ink-2); font-size: .88rem; margin: 0 0 1.6rem; line-height: 1.5; }

    /* Two doors, equally weighted. Neither is the small print of the other. */
    .gate-choice {
        display: block;
        border: 1px solid var(--g-border);
        border-radius: 11px;
        padding: 1.05rem 1.1rem;
        margin-bottom: .75rem;
        color: var(--g-ink);
        text-decoration: none;
        background: rgba(255,255,255,.03);
        transition: border-color .15s, background .15s, transform .08s;
    }
    .gate-choice:hover { border-color: var(--g-red); background: rgba(230,33,41,.08); }
    .gate-choice:active { transform: translateY(1px); }
    .gate-choice:focus-visible { outline: none; border-color: var(--g-red); box-shadow: 0 0 0 3px rgba(230,33,41,.35); }

    .gate-choice strong { display: block; font-size: 1rem; margin-bottom: .2rem; }
    .gate-choice span { display: block; font-size: .8rem; color: var(--g-ink-2); line-height: 1.45; }

    .gate-foot { margin: 1.4rem 0 0; font-size: .72rem; color: var(--g-ink-3); text-align: center; }

    @media (max-width: 560px) {
        .gate { justify-content: center; padding: 1.5rem; }
        .gate-box { padding: 2rem 1.5rem; }
    }
</style>

<div class="gate">
    <div class="gate-box">
        <div class="gate-brand">
            <div class="mark">IC</div>
            <div class="txt">
                <strong>Imprint</strong>
                <small>Customs</small>
            </div>
        </div>

        <h2 class="gate-title">Which are you?</h2>
        <p class="gate-sub">
            So we send you to the right place.
        </p>

        <a href="{{ route('login') }}" class="gate-choice">
            <strong>I already work here</strong>
            <span>Sign in with your work account — your jobs, payslips and time.</span>
        </a>

        <a href="{{ route('hr.apply') }}" class="gate-choice">
            <strong>I'm applying for a job</strong>
            <span>Fill in an application. No account needed, and you can do it from a phone.</span>
        </a>

        <p class="gate-foot">Imprint Customs</p>
    </div>
</div>
@endsection
