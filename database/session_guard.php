<?php
// =========================================================================
// SESSION GUARD & SERVER-SIDE INACTIVITY TIMEOUT (30 MENIT)
// =========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Tentukan path relatif ke index.php
$current_file = $_SERVER['PHP_SELF'] ?? '';
$is_subfolder = (
    str_contains($current_file, '/pengiriman/') ||
    str_contains($current_file, '/penerimaan/') ||
    str_contains($current_file, '/addcost/') ||
    str_contains($current_file, '/laporan/')
);
$index_url = $is_subfolder ? '../index.php' : 'index.php';

// 1. Handle Logout manual (?logout=1)
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $index_url);
    exit;
}

// 2. Cek apakah ada sesi login valid
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    header("Location: " . $index_url . "?error=unauthorized");
    exit;
}

// 3. Server-side Inactivity Timeout (30 menit = 1800 detik)
$inactivity_limit = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactivity_limit)) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $index_url . "?error=expired");
    exit;
}
$_SESSION['last_activity'] = time();
