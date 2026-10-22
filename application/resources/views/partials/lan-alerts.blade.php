<div id="lan-alerts" role="status" aria-live="polite" style="position:fixed;right:20px;bottom:20px;z-index:9999;width:min(360px,calc(100vw - 40px));"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var originalTitle = document.title;
    var cursor = 0;
    var key = 'imprint-alert-cursor-{{ auth()->id() }}';
    try { var saved = Number(sessionStorage.getItem(key)); if (Number.isSafeInteger(saved) && saved > 0) cursor = saved; } catch (e) {}
    var flash = null, unread = 0, busy = false;
    function pendingCount() {
        var count = 0;
        document.querySelectorAll('#sidebar .count-pill, #sidebar #msgBadge, #sidebar [data-payment-count]').forEach(function (badge) {
            count += parseInt(badge.textContent, 10) || 0;
        });
        return count;
    }
    function normalTitle() {
        var count = pendingCount();
        return count ? '🔔 (' + count + ') Imprint Production' : originalTitle;
    }
    document.title = normalTitle();
    function away() { return document.hidden || !document.hasFocus(); }
    function stop() {
        if (away()) return;
        clearInterval(flash); flash = null; unread = 0; document.title = normalTitle();
    }
    function show(alert) {
        var card = document.createElement('div');
        card.style.cssText = 'background:white;color:#172033;border:1px solid #cbd5e1;border-left:4px solid #ef233c;border-radius:12px;padding:14px;margin-top:8px;box-shadow:0 8px 30px #0003;';
        var close = document.createElement('button'); close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', 'Dismiss notification');
        close.style.cssText = 'float:right;border:0;background:transparent;cursor:pointer;font-size:20px;'; close.onclick = function () { card.remove(); };
        var title = document.createElement('strong'); title.textContent = alert.title || 'Imprint Production';
        var body = document.createElement('p'); body.textContent = alert.body || ''; body.style.margin = '6px 0';
        card.append(close, title, body);
        if (alert.url) {
            try {
                var url = new URL(alert.url, location.href);
                // LAN clients may use a different hostname than the server's APP_URL.
                if (url.protocol === 'http:' || url.protocol === 'https:') {
                    var link = document.createElement('a'); link.textContent = 'Open task'; link.href = url.pathname + url.search + url.hash; card.appendChild(link);
                }
            } catch (e) {}
        }
        var panel = document.getElementById('lan-alerts'); panel.appendChild(card);
        while (panel.children.length > 5) panel.firstElementChild.remove();
        if (away()) {
            unread++;
            document.title = '🔔 (' + Math.max(unread, pendingCount()) + ') Imprint Production';
            if (!flash) {
                var toggle = false;
                flash = setInterval(function () { toggle = !toggle; document.title = toggle ? '🔔 (' + Math.max(unread, pendingCount()) + ') Imprint Production' : '⚠ New work needs attention'; }, 1200);
            }
        } else setTimeout(function () { card.remove(); }, 15000);
    }
    async function check() {
        if (busy) return;
        busy = true;
        try {
            var response = await fetch(@json(route('notifications.alerts', [], false)) + '?since=' + cursor, {cache:'no-store', headers:{'Accept':'application/json'}});
            if (!response.ok) return;
            var data = await response.json();
            (data.alerts || []).forEach(function (alert) { show(alert); cursor = Math.max(cursor, alert.id); });
            try { sessionStorage.setItem(key, String(cursor)); } catch (e) {}
        } catch (e) {} finally { busy = false; }
    }
    document.addEventListener('visibilitychange', stop);
    window.addEventListener('focus', stop);
    window.addEventListener('focus', check);
    document.addEventListener('visibilitychange', check);
    check(); setInterval(check, 15000);
});
</script>
