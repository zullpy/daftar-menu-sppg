<?php
$host = 'localhost';
$db   = 'db_mbg';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
// Additional connection for db_draft_barang
$host2 = $host; // reuse same host
$db2 = 'db_draft_barang';
$dsn2 = "mysql:host=$host2;dbname=$db2;charset=$charset";
$pdo_draft = new PDO($dsn2, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// Base URL
define('BASE_URL', 'http://localhost/aplikasi-MBG/');
?>