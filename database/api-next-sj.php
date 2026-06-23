<?php
session_start();
require_once 'koneksi.php';

header('Content-Type: application/json');

// RBAC
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$tanggal = $_GET['tanggal'] ?? date('Y-m-d');
$tgl_format = date('Ymd', strtotime($tanggal));

// Cari nomor tertinggi yang sudah ada
$stmt = $pdo->prepare("SELECT no_surat_jalan FROM pengiriman 
    WHERE no_surat_jalan LIKE 'SP____-_____%' 
    ORDER BY no_surat_jalan DESC LIMIT 1");
$stmt->execute();
$last = $stmt->fetchColumn();

if ($last) {
    preg_match('/SP(\d{4})-/', $last, $matches);
    $last_num = (int)$matches[1];
    $new_num = $last_num + 1;
} else {
    $new_num = 1;
}

$no_sj = 'SP' . str_pad($new_num, 4, '0', STR_PAD_LEFT) . '-' . $tgl_format;

echo json_encode(['no_sj' => $no_sj]);
