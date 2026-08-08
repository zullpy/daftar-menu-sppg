<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'koneksi.php'; // $pdo

$tanggal = $_GET['tanggal'] ?? '';
$tanggal = str_replace('-', '', $tanggal); // contoh: 20260715

if (strlen($tanggal) < 6) {
    echo '0001FC-' . $tanggal;
    exit;
}

// ✅ Ambil bagian tahun-bulan saja (6 digit pertama), abaikan tanggalnya
// Contoh: 20260715 -> 202607
$tahunBulan = substr($tanggal, 0, 6);

// ✅ Cari faktur terakhir dalam bulan yang sama (bukan hari yang sama)
$stmt = $pdo->prepare("
    SELECT no_faktur
    FROM belanja
    WHERE no_faktur LIKE ?
    ORDER BY id_belanja DESC
    LIMIT 1
");
$stmt->execute(["%FC-{$tahunBulan}%"]);
$last = $stmt->fetch(PDO::FETCH_ASSOC);

if ($last) {
    preg_match('/^(\d+)/', $last['no_faktur'], $match);
    $urut = (int)$match[1] + 1;
} else {
    // Belum ada faktur di bulan ini -> mulai dari 0001
    $urut = 1;
}

echo sprintf('%04d', $urut) . "FC-$tanggal";