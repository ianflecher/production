/* Imprint Customs — Web Push service worker.
   Receives pushes even when the tab/browser is closed and shows one pop-up. */

self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) {}

    var title = data.title || 'Imprint Customs';
    var options = {
        body: data.body || '',
        // Same id => the OS REPLACES an existing pop-up instead of stacking,
        // so the same alert can never show twice.
        tag: 'ic-' + (data.id || title),
        renotify: false,
        data: { url: data.url || '/' },
        icon: '/logo.jpg',
        badge: '/logo.jpg',
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            // Focus an already-open tab if we have one, else open a new one.
            for (var i = 0; i < list.length; i++) {
                if ('focus' in list[i]) { list[i].focus(); if ('navigate' in list[i]) list[i].navigate(url); return; }
            }
            if (self.clients.openWindow) return self.clients.openWindow(url);
        })
    );
});
