// Helper to get base path of the application
function getAppBasePath() {
    let origin = window.location.origin;
    if (!origin) {
        origin = window.location.protocol + '//' + window.location.host;
    }

    let path = window.location.pathname;
    let parts = path.split('/').filter(Boolean);

    // Hapus filename jika ada ekstensi (misal: index.php)
    if (parts.length > 0 && parts[parts.length - 1].includes('.')) {
        parts.pop();
    }

    // Hapus subdirektori yang dikenal
    const subdirs = ['pengiriman', 'laporan', 'addcost', 'penerimaan', 'database'];
    while (parts.length > 0 && subdirs.includes(parts[parts.length - 1])) {
        parts.pop();
    }

    let cleanPath = parts.length > 0 ? '/' + parts.join('/') + '/' : '/';

    return origin + cleanPath;
}

function urlBase64ToUint8Array(base64String) {

    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/\-/g, '+')
        .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

async function sendSubscriptionToServer(subscription, basePath) {
    const key = subscription.getKey ? subscription.getKey('p256dh') : null;
    const auth = subscription.getKey ? subscription.getKey('auth') : null;

    const payload = {
        endpoint: subscription.endpoint,
        keys: {
            p256dh: key ? btoa(String.fromCharCode(...new Uint8Array(key))) : null,
            auth: auth ? btoa(String.fromCharCode(...new Uint8Array(auth))) : null
        }
    };

    try {
        const response = await fetch(basePath + 'database/api-subscribe.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        return await response.json();
    } catch (e) {
        console.error('Gagal mengirim data subscription ke server:', e);
        return { status: 'error' };
    }
}

async function subscribeUser() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        alert('Browser Anda tidak mendukung Web Push Notification.');
        return false;
    }

    const basePath = getAppBasePath();
    const swPath = basePath + 'sw.js';

    try {
        const registration = await navigator.serviceWorker.register(swPath, { scope: basePath });

        const response = await fetch(basePath + 'database/push_config.php?action=get_public_key');
        const data = await response.json();
        const publicKey = data.publicKey;

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(publicKey)
        });

        console.log('User berhasil disubscribe:', subscription);
        await sendSubscriptionToServer(subscription, basePath);

        updateNotifWidgetState('granted');
        return true;
    } catch (error) {
        console.error('Gagal men-subscribe user:', error);
        return false;
    }
}

// Global helper agar bisa dipanggil dari tombol mana saja jika diperlukan
window.togglePushNotificationPermission = async function (e) {
    if (e) {
        if (typeof e.preventDefault === 'function') e.preventDefault();
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
    }

    if (!('Notification' in window)) {
        alert('Browser Anda tidak mendukung Notifikasi Web.');
        return;
    }

    if (!window.isSecureContext) {
        alert('Notifikasi Web membutuhkan koneksi aman (HTTPS). Pastikan website diakses menggunakan https://');
        return;
    }

    if (Notification.permission === 'granted') {
        const userChoice = confirm('Notifikasi Web saat ini AKTIF.\n\nApakah Anda ingin memperbarui langganan notifikasi perangkat ini?');
        if (!userChoice) return;
        const success = await subscribeUser();
        if (success) alert('Notifikasi Web berhasil diperbarui!');
    } else if (Notification.permission === 'denied') {
        alert('Izin Notifikasi saat ini DIBLOKIR di browser Anda.\n\nUntuk mengaktifkan kembali:\n1. Klik ikon gembok / setelan di sebelah kiri URL browser.\n2. Ubah izin "Notifikasi" menjadi "Izinkan" / "Allow".\n3. Muat ulang (refresh) halaman ini.');
    } else {
        const permission = await Notification.requestPermission();
        if (permission === 'granted') {
            const success = await subscribeUser();
            if (success) alert('Notifikasi Web berhasil diaktifkan!');
        } else {
            updateNotifWidgetState('denied');
        }
    }
};

function updateNotifWidgetState(permissionState) {
    const banner = document.getElementById('push-notif-banner');
    const fab = document.getElementById('push-notif-fab');

    if (permissionState === 'granted') {
        if (banner) banner.style.display = 'none';
        if (fab) {
            fab.style.backgroundColor = '#16a34a';
            fab.title = 'Notifikasi Web Aktif';
            fab.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    <polyline points="9 11 11 13 15 9"></polyline>
                </svg>
            `;
        }
    } else if (permissionState === 'denied') {
        if (banner) banner.style.display = 'none';
        if (fab) {
            fab.style.backgroundColor = '#dc2626';
            fab.title = 'Notifikasi Diblokir - Klik untuk petunjuk';
            fab.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                    <path d="M18.6 13c.2 2 .9 3 2.4 3H3s3-2 3-9a6 6 0 0 1 .7-2.7"></path>
                </svg>
            `;
        }
    } else {
        if (fab) {
            fab.style.backgroundColor = '#2563eb';
            fab.title = 'Aktifkan Notifikasi Web';
            fab.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
            `;
        }
    }
}

function renderPushNotifUI() {
    // 1. Floating Action Button (FAB) Lonceng Notifikasi
    // Posisikan di bottom: 90px agar TIDAK menimpa tombol add (.fab-add yang ada di bottom: 22px)
    if (!document.getElementById('push-notif-fab')) {
        const fab = document.createElement('button');
        fab.id = 'push-notif-fab';
        fab.type = 'button';
        fab.style.position = 'fixed';
        fab.style.bottom = 'calc(90px + var(--safe-bottom, 0px))';
        fab.style.right = '23px';
        fab.style.width = '48px';
        fab.style.height = '48px';
        fab.style.borderRadius = '50%';
        fab.style.backgroundColor = '#16a34a';
        fab.style.color = '#ffffff';
        fab.style.border = 'none';
        fab.style.boxShadow = '0 4px 14px rgba(0, 0, 0, 0.25)';
        fab.style.cursor = 'pointer';
        fab.style.zIndex = '9998';
        fab.style.display = 'flex';
        fab.style.alignItems = 'center';
        fab.style.justifyContent = 'center';
        fab.style.transition = 'all 0.2s ease-in-out';

        fab.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            window.togglePushNotificationPermission(e);
        });

        document.body.appendChild(fab);
    }

    // 2. Banner Modal Pop-up jika belum ditentukan (default)
    if (Notification.permission === 'default' && !sessionStorage.getItem('push-notif-dismissed')) {
        if (!document.getElementById('push-notif-banner')) {
            const banner = document.createElement('div');
            banner.id = 'push-notif-banner';
            banner.style.position = 'fixed';
            banner.style.bottom = '150px';
            banner.style.right = '24px';
            banner.style.backgroundColor = '#1e293b';
            banner.style.color = '#ffffff';
            banner.style.padding = '16px 20px';
            banner.style.borderRadius = '14px';
            banner.style.boxShadow = '0 10px 25px -5px rgba(0, 0, 0, 0.35)';
            banner.style.zIndex = '9999';
            banner.style.display = 'flex';
            banner.style.flexDirection = 'column';
            banner.style.gap = '10px';
            banner.style.width = 'calc(100% - 48px)';
            banner.style.maxWidth = '360px';
            banner.style.fontFamily = 'system-ui, -apple-system, sans-serif';
            banner.style.fontSize = '14px';

            banner.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-weight: 700; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                        <span>🔔</span> Notifikasi Realtime
                    </div>
                    <button type="button" id="push-notif-close-x" style="background: transparent; color: #94a3b8; border: none; font-size: 18px; cursor: pointer; padding: 0 4px;">&times;</button>
                </div>
                <div style="color: #cbd5e1; font-size: 13px; line-height: 1.4;">
                    Aktifkan notifikasi untuk menerima pemberitahuan otomatis saat ada pengiriman atau pengambilan barang baru.
                </div>
                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px;">
                    <button type="button" id="push-notif-ignore" style="background: #334155; color: #f8fafc; border: none; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500;">Nanti</button>
                    <button type="button" id="push-notif-grant" style="background: #2563eb; color: white; border: none; padding: 8px 18px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px;">Izinkan Notifikasi</button>
                </div>
            `;

            document.body.appendChild(banner);

            const closeBanner = (e) => {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                banner.style.display = 'none';
                sessionStorage.setItem('push-notif-dismissed', 'true');
            };

            document.getElementById('push-notif-close-x').addEventListener('click', closeBanner);
            document.getElementById('push-notif-ignore').addEventListener('click', closeBanner);

            document.getElementById('push-notif-grant').addEventListener('click', async (e) => {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                banner.style.display = 'none';
                await window.togglePushNotificationPermission(e);
            });
        }
    }

    updateNotifWidgetState(Notification.permission);
}

// Auto init / sync
async function initPushNotification() {
    if (!window.isSecureContext) {
        console.warn('Push Notifications: Browser tidak dalam konteks aman (bukan HTTPS atau localhost).');
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }

    const basePath = getAppBasePath();
    const swPath = basePath + 'sw.js';

    try {
        const registration = await navigator.serviceWorker.register(swPath, { scope: basePath });

        if (Notification.permission === 'granted') {
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await sendSubscriptionToServer(subscription, basePath);
            } else {
                await subscribeUser();
            }
        }
    } catch (error) {
        console.error('Error me-register push:', error);
    }

    renderPushNotifUI();
}

// Helper untuk Pop-up In-App (Bisa Swal atau Custom Floating Toast)
function showInAppToastPopup(title, body) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'info',
            title: '🔔 ' + title,
            text: body,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 7000,
            timerProgressBar: true,
            background: '#1e293b',
            color: '#ffffff',
            iconColor: '#38bdf8'
        });
    } else {
        // Fallback custom DOM toast jika SweetAlert tidak terpasang di halaman
        let existingToast = document.getElementById('mbg-push-toast-popup');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.id = 'mbg-push-toast-popup';
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:999999;background:#1e293b;color:#ffffff;padding:16px 20px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.3);border-left:5px solid #38bdf8;font-family:sans-serif;max-width:320px;animation:fadeIn 0.3s ease;';
        toast.innerHTML = '<div style="font-weight:bold;font-size:15px;margin-bottom:4px;display:flex;align-items:center;gap:6px;"><span>🔔</span> <span>' + title + '</span></div><div style="font-size:13px;color:#cbd5e1;line-height:1.4;">' + body + '</div>';
        document.body.appendChild(toast);

        setTimeout(function () {
            if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
        }, 7000);
    }
}

// Listener pesan dari Service Worker untuk Pop-Up In-App
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (event) {
        if (event.data && event.data.type === 'PUSH_NOTIFICATION_RECEIVED') {
            const title = event.data.title || 'Pemberitahuan Baru';
            const body = event.data.body || 'Ada transaksi pengiriman atau pengambilan baru.';
            showInAppToastPopup(title, body);
        }
    });
}

// Jalankan saat halaman siap
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPushNotification);
} else {
    initPushNotification();
}
