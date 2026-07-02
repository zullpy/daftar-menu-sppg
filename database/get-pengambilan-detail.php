<?php
// database/get-pengambilan-detail.php
header('Content-Type: application/json');
require 'koneksi.php';

$id_pengambilan = (int) ($_GET['id'] ?? 0);

if ($id_pengambilan <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID tidak valid', 'detail' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT nama_barang, qty, satuan, jenis
                            FROM pengambilan_barang_detail
                            WHERE id_pengambilan = :id
                            ORDER BY id_detail ASC");
    $stmt->execute([':id' => $id_pengambilan]);
    $detail = $stmt->fetchAll();

    echo json_encode(['status' => 'success', 'detail' => $detail]);
} catch (\PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil data: ' . $e->getMessage(), 'detail' => []]);
}