<?php
// ═══════════════════════════════════════════════════════════
// KONEKSI DATABASE
// db_mbg          -> data pengiriman, penerimaan, dll
// db_draft_barang -> data stok barang gudang pusat
// Auto-detect: LOCAL vs HOSTING
// ═══════════════════════════════════════════════════════════

$charset = 'utf8mb4';
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// ==== Deteksi Environment ====
// Cek hostname: kalau bukan server hosting = local
$_hostname = gethostname();
$_server   = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '';
$isLocal   = !str_contains($_server, 'kbus.site') && !str_contains($_server, 'permenceker');

if ($isLocal) {
    // ==== KONFIGURASI LOCAL ====
    $cfg = [
        'host' => 'localhost',
        'mbg'  => ['user' => 'root', 'pass' => '', 'db' => 'db_mbg'],
        'draft'=> ['user' => 'root', 'pass' => '', 'db' => 'db_draft_barang'],
    ];
    define('BASE_URL', 'http://localhost:8000');
} else {
    // ==== KONFIGURASI HOSTING (Hostinger) ====
    $cfg = [
        'host' => 'localhost',
        'mbg'  => ['user' => 'u673037475_bgn2026',  'pass' => 'Bgnmbg2026',  'db' => 'u673037475_db_bgn'],
        'draft'=> ['user' => 'u673037475_dbkbus',    'pass' => 'Kbus2026',    'db' => 'u673037475_db_barang'],
    ];
    define('BASE_URL', 'https://permenceker.kbus.site/');
}

try {
    // Koneksi 1: db_mbg
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['mbg']['db']};charset=$charset";
    $pdo = new PDO($dsn, $cfg['mbg']['user'], $cfg['mbg']['pass'], $options);

    // Koneksi 2: db_draft_barang
    $dsn2      = "mysql:host={$cfg['host']};dbname={$cfg['draft']['db']};charset=$charset";
    $pdo_draft = new PDO($dsn2, $cfg['draft']['user'], $cfg['draft']['pass'], $options);

} catch (\PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}