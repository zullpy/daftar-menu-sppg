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
    // Hitung stok masuk (dari pengiriman)
    $sqlMasuk = "SELECT 
        COALESCE(SUM(CASE WHEN dpr.status_barang = 'tidak_ada' THEN 0 ELSE dp.qty END), 0) as total_masuk,
        dp.satuan
    FROM detail_pengiriman dp
    JOIN pengiriman p ON dp.pengiriman_id = p.id
    LEFT JOIN penerimaan pr ON pr.pengiriman_id = p.id
    LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id AND dpr.penerimaan_id = pr.id
    WHERE (:lokasi1 = 'semua' OR p.lokasi = :lokasi2)
    AND TRIM(UPPER(dp.nama_barang)) = TRIM(UPPER(:nama))
    GROUP BY dp.satuan";

    $stmtMasuk = $pdo->prepare($sqlMasuk);
    $stmtMasuk->execute([
        ':lokasi1' => $lokasi,
        ':lokasi2' => $lokasi,
        ':nama' => $nama_barang
    ]);
    $resultMasuk = $stmtMasuk->fetch();

    $totalMasuk = (float)($resultMasuk['total_masuk'] ?? 0);
    $satuan = $resultMasuk['satuan'] ?? '';

    // Hitung stok keluar (dari pengambilan)
    $sqlKeluar = "SELECT COALESCE(SUM(pbd.qty), 0) as total_keluar
    FROM pengambilan_barang_detail pbd
    JOIN pengambilan_barang pb ON pbd.id_pengambilan = pb.id_pengambilan
    WHERE (:lokasi = 'semua' OR pb.lokasi = :lokasi_exact)
    AND TRIM(UPPER(pbd.nama_barang)) = TRIM(UPPER(:nama))";

    $stmtKeluar = $pdo->prepare($sqlKeluar);
    $stmtKeluar->execute([
        ':lokasi' => $lokasi,
        ':lokasi_exact' => $lokasi,
        ':nama' => $nama_barang
    ]);
    $totalKeluar = (float)$stmtKeluar->fetchColumn();

    $sisaStok = $totalMasuk - $totalKeluar;

    echo json_encode([
        'status' => 'success',
        'nama_barang' => $nama_barang,
        'stok_masuk' => $totalMasuk,
        'stok_keluar' => $totalKeluar,
        'sisa_stok' => $sisaStok,
        'satuan' => $satuan // ✅ TAMBAHKAN SATUAN
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
