{{--
    The way out of an error page.

    Not history.back(). The board opens a brief in its own tab, so the
    commonest way to arrive at one of these is in a tab with nothing behind it,
    where back() does nothing at all and the button sits there looking broken.

    The page that sent us is a real address and works from a fresh tab, so that
    is what the button becomes. It survives the link's rel="noopener" - that
    withholds the opener, not the referrer - and it is checked against our own
    host, because a referrer is whatever the other side chose to send.

    Shared by the 403 and the 404 so the two behave the same. The 500 does not
    use it: that page must not depend on anything, this partial included.

    @param $id  a prefix, so two of these on one page cannot collide
--}}
@php $id = $id ?? 'err'; @endphp

<div style="display: flex; gap: 0.6rem; justify-content: center; flex-wrap: wrap;">
    <a href="{{ url('/') }}" id="{{ $id }}Back" class="btn btn-primary" style="display:none;">
        ← Back
    </a>
    <a href="{{ url('/') }}" id="{{ $id }}Home" class="btn btn-primary">Go to my dashboard</a>
</div>

<p id="{{ $id }}Tab" class="muted" style="display:none; font-size:0.8rem; margin:1rem 0 0;">
    This opened in its own tab — closing it puts you back where you were.
</p>

<script>
    (function () {
        var back = document.getElementById(@json($id.'Back'));
        var home = document.getElementById(@json($id.'Home'));
        var tabNote = document.getElementById(@json($id.'Tab'));
        var from = document.referrer;

        var ours = false;
        try {
            ours = !!from && new URL(from).origin === window.location.origin
                && new URL(from).pathname !== window.location.pathname;
        } catch (e) { ours = false; }

        if (ours) {
            back.href = from;
            back.style.display = '';
            home.className = 'btn btn-ghost';
            return;
        }

        // Nowhere to send them but home. Say why there is no Back, so its
        // absence reads as deliberate rather than as something missing.
        if (window.history.length <= 1) {
            tabNote.style.display = '';
        }
    })();
</script>
