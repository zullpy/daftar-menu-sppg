<?php
// database/get-pengambilan-detail.php
header('Content-Type: application/json');
require 'koneksi.php';
require_once 'stok_helper.php';

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
    $rows = $stmt->fetchAll();

    $detail = array_map(function($d) {
        $mapping = stok_getMapping($d['nama_barang']);
        $d['satuan_grosir'] = $mapping ? $mapping['satuan_grosir'] : null;
        $d['satuan_eceran'] = $mapping ? $mapping['satuan_eceran'] : null;
        return $d;
    }, $rows);

    echo json_encode(['status' => 'success', 'detail' => $detail]);
} catch (\PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil data: ' . $e->getMessage(), 'detail' => []]);
}