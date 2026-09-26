<?php
// =========================================================================
// TEMPLATE KONFIGURASI PASSWORD & AUTENTIKASI MBG
// Salin file ini menjadi 'database/auth_config.php' dan sesuaikan password.
// File 'database/auth_config.php' SUDAH masuk ke .gitignore sehingga AMAN
// dan tidak akan pernah ter-push ke repository publik / GitHub.
// Password dapat berupa teks biasa (plaintext) atau hash bcrypt (password_hash).
// =========================================================================

return [
    'admin' => [
        'password'     => 'evinkbus2026',
        'password_alt' => 'amiw',
    ],
    'opsodong' => [
        'password' => 'sodong123',
    ],
    'opsariwangi' => [
        'password' => 'sariwangi123',
    ],
    'opmanonjaya' => [
        'password' => 'manonjaya123',
    ],
];
