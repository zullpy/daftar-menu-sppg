<?php
header('Content-Type: application/json');
require 'koneksi.php';

$q = trim($_GET['q'] ?? '');
$lokasi = $_GET['lokasi'] ?? 'semua';

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['status' => 'success', 'data' => []]);
    exit;
}

try {
    if ($lokasi === 'semua') {
        // Gabungkan qty dari semua lokasi untuk nama barang yang sama
        $sql = "SELECT nama_barang, satuan, SUM(qty) as sisa_stok
                FROM stok_barang
                WHERE nama_barang LIKE :q
                GROUP BY nama_barang, satuan
                HAVING sisa_stok > 0
                ORDER BY nama_barang ASC
                LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => '%' . $q . '%']);
    } else {
        $sql = "SELECT nama_barang, satuan, SUM(qty) as sisa_stok
                FROM stok_barang
                WHERE nama_barang LIKE :q AND lokasi = :lokasi
                GROUP BY nama_barang, satuan
                HAVING sisa_stok > 0
                ORDER BY nama_barang ASC
                LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => '%' . $q . '%', ':lokasi' => $lokasi]);
    }

    $rows = $stmt->fetchAll();
    $data = array_map(function ($r) {
        return [
            'nama_barang' => $r['nama_barang'],
            'satuan'      => $r['satuan'],
            'sisa_stok'   => (float)$r['sisa_stok'],
        ];
    }, $rows);

    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
