<dialog id="imprint-notifications" style="border:0;border-radius:18px;padding:1.5rem;max-width:420px;width:calc(100% - 2rem);box-shadow:0 20px 70px #0004;">
    <h2 style="margin:0 0 .7rem;">Enable Imprint Production notifications</h2>
    <p>Get notified when new work arrives at your station or a task needs your attention.</p>
    <p style="font-size:.85rem;color:#526176;">Click Enable notifications, then choose Allow in your browser’s permission prompt.</p>
    <p id="imprint-notification-status" role="status" style="font-size:.85rem;"></p>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" id="imprint-notification-enable">Enable notifications</button>
        <button type="button" class="btn btn-ghost" id="imprint-notification-later">Later</button>
    </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var dialog = document.getElementById('imprint-notifications');
    var button = document.getElementById('imprint-notification-enable');
    var status = document.getElementById('imprint-notification-status');
    var publicKey = @json(config('webpush.vapid.public_key'));
    if (!window.isSecureContext || !('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window) || !publicKey) return;

    function keyBytes(value) {
        var text = atob((value + '='.repeat((4 - value.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(text, function (c) { return c.charCodeAt(0); });
    }

    async function subscribe() {
        var registration = await navigator.serviceWorker.register('/sw.js');
        registration = await navigator.serviceWorker.ready;
        var subscription = await registration.pushManager.getSubscription();
        if (!subscription) subscription = await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: keyBytes(publicKey)});
        var response = await fetch(@json(route('push.subscribe')), {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
            body: JSON.stringify(subscription.toJSON())
        });
        if (!response.ok) throw new Error('Subscription failed');
    }

    document.getElementById('imprint-notification-later').onclick = function () {
        dialog.close();
    };
    button.onclick = async function () {
        button.disabled = true;
        try {
            if (Notification.permission === 'denied') {
                status.textContent = 'Notifications are blocked. Click the site settings icon beside the address bar, open Site settings, set Notifications to Allow, then refresh this page.';
                return;
            }
            var permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                status.textContent = permission === 'denied' ? 'Notifications are blocked. You can allow them in your browser’s site settings.' : 'You can enable notifications later.';
                return;
            }
            await subscribe();
            status.textContent = 'Imprint Production notifications are enabled.';
            setTimeout(function () { dialog.close(); }, 1500);
        } catch (e) {
            status.textContent = 'Could not enable notifications. Please try again.';
        } finally { button.disabled = false; }
    };

    if (Notification.permission === 'granted') {
        subscribe().catch(function () {});
    } else {
        if (Notification.permission === 'denied') {
            status.textContent = 'Imprint Production notifications are blocked. Click the site settings icon beside the address bar, open Site settings, set Notifications to Allow, then refresh this page.';
            button.textContent = 'How to allow notifications';
        }
        dialog.showModal();
    }
});
</script>
