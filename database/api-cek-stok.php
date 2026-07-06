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
    // qty_grosir = jumlah unit grosir UTUH yang masih ada (diturunkan dari qty_eceran)
    // qty_eceran = SUMBER KEBENARAN, total stok penuh dalam satuan eceran/unit terkecil
    if ($lokasi === 'semua') {
        $sql = "SELECT COALESCE(SUM(qty_grosir), 0) as sisa_grosir,
                       COALESCE(SUM(qty_eceran), 0) as sisa_eceran,
                       MAX(satuan) as satuan,
                       MAX(satuan_eceran) as satuan_eceran
                FROM stok_barang
                WHERE TRIM(UPPER(nama_barang)) = TRIM(UPPER(:nama))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':nama' => $nama_barang]);
    } else {
        $sql = "SELECT COALESCE(SUM(qty_grosir), 0) as sisa_grosir,
                       COALESCE(SUM(qty_eceran), 0) as sisa_eceran,
                       MAX(satuan) as satuan,
                       MAX(satuan_eceran) as satuan_eceran
                FROM stok_barang
                WHERE TRIM(UPPER(nama_barang)) = TRIM(UPPER(:nama))
                AND lokasi = :lokasi";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':nama' => $nama_barang, ':lokasi' => $lokasi]);
    }

    $result = $stmt->fetch();
    $sisaGrosir   = (float)($result['sisa_grosir'] ?? 0);
    $sisaEceran   = (float)($result['sisa_eceran'] ?? 0);
    $satuan       = $result['satuan'] ?? '';
    $satuanEceran = $result['satuan_eceran'] ?? '';

    // "sisa_stok"/"satuan" dipertahankan (kompatibel dengan pemanggil lama) =
    // versi GROSIR, karena itu satuan default yang di-autofill ke form.
    echo json_encode([
        'status'        => 'success',
        'nama_barang'   => $nama_barang,
        'sisa_stok'     => $sisaGrosir,
        'satuan'        => $satuan,
        // Data tambahan biar frontend bisa validasi benar walau operator
        // ganti satuan ke versi eceran (mis. PCS bukan DUS)
        'sisa_grosir'   => $sisaGrosir,
        'sisa_eceran'   => $sisaEceran,
        'satuan_grosir' => $satuan,
        'satuan_eceran' => $satuanEceran,
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}