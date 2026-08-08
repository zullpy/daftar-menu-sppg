<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/push_helper.php';

header('Content-Type: text/plain');
echo "Mengirim notifikasi tes...\n";

// Cek jumlah subscriber
$stmt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions");
$count = $stmt->fetchColumn();

if ($count == 0) {
    echo "Peringatan: Belum ada perangkat/browser yang terdaftar (push_subscriptions kosong).\n";
    echo "Silakan buka aplikasi di browser (PC/HP), lalu klik 'Aktifkan' pada banner notifikasi terlebih dahulu.\n";
    exit;
}

echo "Ditemukan $count perangkat terdaftar.\n";
broadcast_push_notification($pdo, "Uji Coba Notifikasi", "Uji coba koneksi notifikasi berhasil! Sistem siap mengirim update pengiriman & pengambilan.");
echo "Proses pengiriman selesai.\n";
