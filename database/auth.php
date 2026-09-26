<?php
// =========================================================================
// LOADER KONFIGURASI AUTENTIKASI MBG
// Memuat password dari .env atau database/auth_config.php tanpa hardcode
// =========================================================================

if (!function_exists('mbg_load_env')) {
    /**
     * Membaca file .env jika tersedia
     */
    function mbg_load_env($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                // Hapus tanda kutip bila ada
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }
                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $val;
                    putenv("$key=$val");
                }
            }
        }
    }
}

// 1. Coba baca dari file .env (prioritas path: root aplikasi-MBG atau root project)
mbg_load_env(__DIR__ . '/../.env');
mbg_load_env(__DIR__ . '/../../.env');

// 2. Baca dari file auth_config.php jika ada
$auth_config = [];
if (file_exists(__DIR__ . '/auth_config.php')) {
    $auth_config = require __DIR__ . '/auth_config.php';
}

// 3. Mapping password ke variabel (mendukung .env atau auth_config.php)
$password_admin = getenv('MBG_PASSWORD_ADMIN') ?: ($auth_config['admin']['password'] ?? '');
$password_admin_alt = getenv('MBG_PASSWORD_ADMIN_ALT') ?: ($auth_config['admin']['password_alt'] ?? '');
$password_opsodong = getenv('MBG_PASSWORD_OPSODONG') ?: ($auth_config['opsodong']['password'] ?? '');
$password_opsariwangi = getenv('MBG_PASSWORD_OPSARIWANGI') ?: ($auth_config['opsariwangi']['password'] ?? '');
$password_opmanonjaya = getenv('MBG_PASSWORD_OPMANONJAYA') ?: ($auth_config['opmanonjaya']['password'] ?? '');

// Helper verifikasi password (mendukung perbandingan langsung atau bcrypt hash)
if (!function_exists('mbg_verify_password')) {
    function mbg_verify_password($input, $target) {
        if ($target === '' || $target === null) {
            return false;
        }
        if ($input === $target) {
            return true;
        }
        // Jika target berupa hash bcrypt/argon2
        if (str_starts_with($target, '$2y$') || str_starts_with($target, '$2a$') || str_starts_with($target, '$argon2')) {
            return password_verify($input, $target);
        }
        return false;
    }
}
