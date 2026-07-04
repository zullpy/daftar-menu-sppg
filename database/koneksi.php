<?php
// ═══════════════════════════════════════════════════════════
// KONEKSI DATABASE
// db_mbg          -> data pengiriman, penerimaan, dll
// db_draft_barang -> data stok barang gudang pusat
// ═══════════════════════════════════════════════════════════

$host    = 'localhost';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Koneksi 1: db_mbg
    $db  = 'db_mbg';
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Koneksi 2: db_draft_barang
    $db2  = 'db_draft_barang';
    $dsn2 = "mysql:host=$host;dbname=$db2;charset=$charset";
    $pdo_draft = new PDO($dsn2, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// Base URL
define('BASE_URL', 'http://localhost/aplikasi-MBG/');
