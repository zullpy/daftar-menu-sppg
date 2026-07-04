<?php
header('Content-Type: application/json');
require 'koneksi.php';

$nama_barang = $_GET['nama'] ?? '';
$lokasi = $_GET['lokasi'] ?? 'semua';

if (empty($nama_barang)) {
    echo json_encode(['status' => 'error', 'message' => 'Nama barang kosong']);
    exit;
}

try {
    // Ambil langsung dari tabel stok_barang (sudah otomatis ke-update
    // saat penerimaan dikonfirmasi dan saat pengambilan disimpan).
    if ($lokasi === 'semua') {
        // Gabungkan stok dari semua lokasi
        $sql = "SELECT COALESCE(SUM(qty), 0) as sisa_stok, MAX(satuan) as satuan
                FROM stok_barang
                WHERE TRIM(UPPER(nama_barang)) = TRIM(UPPER(:nama))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':nama' => $nama_barang]);
    } else {
        $sql = "SELECT COALESCE(SUM(qty), 0) as sisa_stok, MAX(satuan) as satuan
                FROM stok_barang
                WHERE TRIM(UPPER(nama_barang)) = TRIM(UPPER(:nama))
                AND lokasi = :lokasi";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':nama' => $nama_barang, ':lokasi' => $lokasi]);
    }

    $result = $stmt->fetch();
    $sisaStok = (float)($result['sisa_stok'] ?? 0);
    $satuan = $result['satuan'] ?? '';

    echo json_encode([
        'status' => 'success',
        'nama_barang' => $nama_barang,
        'sisa_stok' => $sisaStok,
        'satuan' => $satuan
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
