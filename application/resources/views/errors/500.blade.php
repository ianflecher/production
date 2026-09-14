{{--
    The one error page that must not have a dependency.

    Everything else here extends layouts.app, which calls
    auth()->user()->canSeeDesignBoard() and a dozen more like it, and counts
    unread messages and pending approvals out of the database. That is fine on
    a 403 or a 404, where the app is working and only this request went wrong.

    A 500 is the opposite: the app itself is in trouble, and the likeliest
    causes - the database being down, a broken model, a migration half applied -
    are exactly the things the layout needs in order to render. An error page
    that throws its own error leaves a white screen, which is the worst
    possible answer to "something went wrong".

    So this is plain HTML. No layout, no auth, no queries, no CSS file - the
    styles are inline because even the asset path is one more thing that could
    be wrong. It will render on a server that can do almost nothing else.

    It deliberately shows no detail of the failure. APP_DEBUG is off in the
    shop, and a stack trace naming tables and paths is not something to put in
    front of a floor that cannot act on it anyway - the trace is in the log,
    which is where somebody who can act on it will look.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Something went wrong — Imprint Production</title>
</head>
<body style="margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
             background:#F4F6F9; color:#16202e;
             font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

    <div style="max-width:520px; margin:1.5rem; padding:2.5rem 2rem; background:#fff;
                border:1px solid #d4dbe4; border-radius:16px; text-align:center;
                box-shadow:0 10px 30px rgba(22,32,46,.07);">

        <div style="font-size:2.4rem; line-height:1;">⚠️</div>

        <h1 style="margin:.8rem 0 .4rem; font-size:1.5rem;">Something went wrong at our end</h1>

        <p style="color:#5a6a7d; font-size:.92rem; line-height:1.55; margin:0 0 .6rem;">
            This is not something you did, and trying again usually works — the
            page failed, not your work.
        </p>

        <p style="color:#5a6a7d; font-size:.85rem; line-height:1.55; margin:0 0 1.6rem;">
            If you had just pressed save, check the list before typing it again:
            it may well have gone through. If this keeps happening, tell whoever
            looks after the system and say what you were doing at the time.
        </p>

        <div style="display:flex; gap:.6rem; justify-content:center; flex-wrap:wrap;">
            <a href="javascript:location.reload()"
               style="display:inline-block; padding:.7rem 1.2rem; border-radius:9px;
                      background:#E62129; color:#fff; text-decoration:none; font-weight:700; font-size:.92rem;">
                Try again
            </a>
            <a href="/"
               style="display:inline-block; padding:.7rem 1.2rem; border-radius:9px;
                      background:#fff; color:#16202e; border:1px solid #d4dbe4;
                      text-decoration:none; font-weight:600; font-size:.92rem;">
                Go to my dashboard
            </a>
        </div>

        {{-- The one detail worth showing. Laravel puts a unique id on every
             logged exception, so somebody reading the log can find THIS
             failure rather than guessing which of the day's it was. --}}
        @isset($exception)
            @php $ref = method_exists($exception, 'getTraceAsString') ? substr(md5($exception->getFile().$exception->getLine()), 0, 8) : null; @endphp
            @if ($ref)
                <p style="color:#98a5b5; font-size:.75rem; margin:1.5rem 0 0;">
                    Reference {{ $ref }} — quote this if you report it.
                </p>
            @endif
        @endisset
    </div>
</body>
</html>
