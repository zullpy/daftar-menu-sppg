// ===== Service Worker Lifecycle =====
self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

// ===== Helper: fetch dengan timeout =====
function fetchWithTimeout(url, timeoutMs) {
    return new Promise(function (resolve, reject) {
        var timer = setTimeout(function () {
            reject(new Error('Fetch timeout'));
        }, timeoutMs);

        fetch(url)
            .then(function (response) {
                clearTimeout(timer);
                resolve(response);
            })
            .catch(function (err) {
                clearTimeout(timer);
                reject(err);
            });
    });
}

// ===== Push Event =====
self.addEventListener('push', function (event) {
    var swUrl = new URL(self.location.href);
    var baseUrl = swUrl.origin + swUrl.pathname.substring(0, swUrl.pathname.lastIndexOf('/') + 1);
    var notificationUrl = baseUrl + 'database/api-get-notification.php?_t=' + Date.now();

    var defaultTitle = 'MBG Logistik';
    var defaultBody = 'Ada transaksi pengiriman atau pengambilan baru di aplikasi.';

    // Check jika ada payload langsung dari event.data
    var payloadData = null;
    if (event.data) {
        try {
            payloadData = event.data.json();
        } catch (e) {
            try {
                var text = event.data.text();
                if (text) defaultBody = text;
            } catch (err) { }
        }
    }

    function buildOptions(title, body) {
        return {
            body: body || defaultBody,
            icon: baseUrl + 'assets/logo.png',
            badge: baseUrl + 'assets/favicon.ico',
            vibrate: [300, 100, 300],
            tag: 'mbg-notif-' + Date.now(),
            renotify: true,
            requireInteraction: false,
            silent: false,
            timestamp: Date.now(),
            data: {
                url: baseUrl
            }
        };
    }

    function broadcastToClients(t, b) {
        if ('clients' in self) {
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
                for (var i = 0; i < windowClients.length; i++) {
                    windowClients[i].postMessage({
                        type: 'PUSH_NOTIFICATION_RECEIVED',
                        title: t,
                        body: b
                    });
                }
            });
        }
    }

    // Jika sudah dapat payload dari push event
    if (payloadData && payloadData.title && payloadData.body) {
        broadcastToClients(payloadData.title, payloadData.body);
        event.waitUntil(
            self.registration.showNotification(payloadData.title, buildOptions(payloadData.title, payloadData.body))
        );
        return;
    }

    // Fetch data notifikasi dari DB dengan timeout 1 detik (Fast Desktop Response)
    event.waitUntil(
        fetchWithTimeout(notificationUrl, 1000)
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                var title = defaultTitle;
                var body = defaultBody;

                if (data && data.status === 'success' && data.title && data.body) {
                    title = data.title;
                    body = data.body;
                }

                broadcastToClients(title, body);
                return self.registration.showNotification(title, buildOptions(title, body));
            })
            .catch(function () {
                // Fallback INSTAN jika fetch lambat/gagal saat Chrome ditutup
                broadcastToClients(defaultTitle, defaultBody);
                return self.registration.showNotification(defaultTitle, buildOptions(defaultTitle, defaultBody));
            })
    );
});

// ===== Notification Click =====
self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var swUrl = new URL(self.location.href);
    var baseUrl = swUrl.origin + swUrl.pathname.substring(0, swUrl.pathname.lastIndexOf('/') + 1);

    var targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : baseUrl;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(function (windowClients) {
                for (var i = 0; i < windowClients.length; i++) {
                    var client = windowClients[i];
                    if (client.url.startsWith(baseUrl) && 'focus' in client) {
                        return client.focus();
                    }
                }
                if (clients.openWindow) {
                    return clients.openWindow(targetUrl);
                }
            })
    );
});
