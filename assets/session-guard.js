/**
 * MBG Client Session Guard
 * - Tutup Tab / Browser -> Langsung Logout (via sessionStorage)
 * - Auto-logout jika tidak ada aktivitas selama 30 menit
 */
(function () {
    // Tentukan URL index.php berdasarkan kedalaman path
    const isSubfolder = window.location.pathname.includes('/pengiriman/') ||
                        window.location.pathname.includes('/penerimaan/') ||
                        window.location.pathname.includes('/addcost/') ||
                        window.location.pathname.includes('/laporan/');
    const loginUrl = isSubfolder ? '../index.php' : 'index.php';

    const IDLE_TIMEOUT_MS = 30 * 60 * 1000; // 30 menit

    // 1. Cek sessionStorage
    // Jika tab baru dibuka tanpa login di tab tersebut, atau browser ditutup lalu dibuka lagi
    if (!sessionStorage.getItem('mbg_session_active')) {
        window.location.replace(loginUrl + '?logout=1&reason=tab_closed');
        return;
    }

    // 2. Cek apakah ada jeda idle lebih dari 30 menit saat tab baru aktif kembali
    const lastActivity = parseInt(sessionStorage.getItem('mbg_last_activity') || '0', 10);
    if (lastActivity > 0 && (Date.now() - lastActivity > IDLE_TIMEOUT_MS)) {
        sessionStorage.removeItem('mbg_session_active');
        sessionStorage.removeItem('mbg_last_activity');
        window.location.replace(loginUrl + '?logout=1&reason=idle_timeout');
        return;
    }

    // Perbarui timestamp aktivitas saat halaman dimuat
    sessionStorage.setItem('mbg_last_activity', Date.now().toString());

    // 3. Timer Idle 30 menit
    let idleTimer;
    function resetIdleTimer() {
        sessionStorage.setItem('mbg_last_activity', Date.now().toString());
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
            sessionStorage.removeItem('mbg_session_active');
            sessionStorage.removeItem('mbg_last_activity');
            alert('Sesi Anda telah berakhir karena tidak ada aktivitas selama 30 menit.');
            window.location.replace(loginUrl + '?logout=1&reason=idle_timeout');
        }, IDLE_TIMEOUT_MS);
    }

    const events = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click'];
    events.forEach(evt => {
        window.addEventListener(evt, resetIdleTimer, { passive: true });
    });
    resetIdleTimer();

    // 4. Global function logout
    window.mbgLogout = function (confirmMsg = 'Yakin ingin logout?') {
        if (!confirmMsg || confirm(confirmMsg)) {
            sessionStorage.removeItem('mbg_session_active');
            sessionStorage.removeItem('mbg_last_activity');
            window.location.href = loginUrl + '?logout=1';
        }
    };
})();
