{{--
    Too many tries in a short time.

    Standalone rather than extending layouts.app, and deliberately so: nearly
    every throttled route in this app is PUBLIC. The client design
    questionnaires and the job application form are rate-limited because
    anyone on the internet can reach them, so the person reading this is as
    likely to be a client filling in sizes, or somebody applying for a job, as
    a member of staff. "Go to my dashboard" would be nonsense to two of those
    three, and the staff sidebar would be worse.

    So it says nothing about who they are and offers nothing but the way
    forward, which is to wait.

    The wait is a real number. Laravel puts Retry-After on the response, and a
    page that says "try again shortly" to somebody who will press the button
    immediately is a page that produces another 429.
--}}
@php
    // ThrottleRequestsException carries the header; anything else that lands
    // here might not, so nothing depends on it being there.
    $seconds = null;

    if (isset($exception) && method_exists($exception, 'getHeaders')) {
        $seconds = (int) ($exception->getHeaders()['Retry-After'] ?? 0) ?: null;
    }

    $wait = match (true) {
        $seconds === null => null,
        $seconds <= 60 => $seconds.' '.($seconds === 1 ? 'second' : 'seconds'),
        default => ceil($seconds / 60).' '.(ceil($seconds / 60) === 1.0 ? 'minute' : 'minutes'),
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Too many tries — Imprint Customs</title>
</head>
<body style="margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
             background:#F4F6F9; color:#16202e;
             font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

    <div style="max-width:520px; margin:1.5rem; padding:2.5rem 2rem; background:#fff;
                border:1px solid #d4dbe4; border-radius:16px; text-align:center;
                box-shadow:0 10px 30px rgba(22,32,46,.07);">

        <div style="font-size:2.4rem; line-height:1;">🕒</div>

        <h1 style="margin:.8rem 0 .4rem; font-size:1.5rem;">Too many tries in a row</h1>

        <p style="color:#5a6a7d; font-size:.92rem; line-height:1.55; margin:0 0 .6rem;">
            @if ($wait)
                Wait about <strong>{{ $wait }}</strong> and try again.
            @else
                Wait a short while and try again.
            @endif
        </p>

        <p style="color:#5a6a7d; font-size:.85rem; line-height:1.55; margin:0 0 1.6rem;">
            Nothing is broken and nothing you sent has been lost. This is a limit
            on how often a page can be asked for, and it lifts on its own —
            pressing the button again before it does only starts the wait over.
        </p>

        <button id="retry" type="button" disabled
                style="display:inline-block; padding:.7rem 1.4rem; border:0; border-radius:9px;
                       background:#c9ced6; color:#fff; font-weight:700; font-size:.92rem;
                       font-family:inherit; cursor:not-allowed;">
            Try again
        </button>

        <p style="color:#98a5b5; font-size:.78rem; margin:1.5rem 0 0;">
            If you were sending a form, check before typing it again — it may
            have gone through.
        </p>
    </div>

    <script>
        (function () {
            var btn = document.getElementById('retry');
            // Laravel's own figure when there is one, and a sensible floor
            // when there is not, so the button is never dead for ever.
            var left = {{ (int) ($seconds ?? 30) }};

            function enable() {
                btn.disabled = false;
                btn.textContent = 'Try again';
                btn.style.background = '#E62129';
                btn.style.cursor = 'pointer';
                btn.onclick = function () { location.reload(); };
            }

            function tick() {
                if (left <= 0) { enable(); return; }
                btn.textContent = 'Try again in ' + left + 's';
                left--;
                setTimeout(tick, 1000);
            }

            tick();
        })();
    </script>
</body>
</html>
