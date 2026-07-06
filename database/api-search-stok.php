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
    // qty_grosir = jumlah unit grosir UTUH yang masih ada (diturunkan dari qty_eceran)
    // qty_eceran = SUMBER KEBENARAN, total stok penuh dalam satuan eceran/unit terkecil
    if ($lokasi === 'semua') {
        // Gabungkan qty dari semua lokasi untuk nama barang yang sama
        $sql = "SELECT nama_barang,
                       MAX(satuan) as satuan,
                       MAX(satuan_eceran) as satuan_eceran,
                       SUM(qty_grosir) as sisa_grosir,
                       SUM(qty_eceran) as sisa_eceran
                FROM stok_barang
                WHERE nama_barang LIKE :q
                GROUP BY nama_barang
                HAVING sisa_eceran > 0 OR sisa_grosir > 0
                ORDER BY nama_barang ASC
                LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => '%' . $q . '%']);
    } else {
        $sql = "SELECT nama_barang,
                       MAX(satuan) as satuan,
                       MAX(satuan_eceran) as satuan_eceran,
                       SUM(qty_grosir) as sisa_grosir,
                       SUM(qty_eceran) as sisa_eceran
                FROM stok_barang
                WHERE nama_barang LIKE :q AND lokasi = :lokasi
                GROUP BY nama_barang
                HAVING sisa_eceran > 0 OR sisa_grosir > 0
                ORDER BY nama_barang ASC
                LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => '%' . $q . '%', ':lokasi' => $lokasi]);
    }

    $rows = $stmt->fetchAll();
    $data = array_map(function ($r) {
        return [
            'nama_barang'   => $r['nama_barang'],
            // "satuan" & "sisa_stok" dipertahankan (kompatibel dengan pemanggil
            // lama) = versi GROSIR, karena itu yang di-autofill ke form.
            'satuan'        => $r['satuan'],
            'sisa_stok'     => (float)$r['sisa_grosir'],
            // Data tambahan biar frontend bisa validasi benar walau operator
            // ganti satuan ke versi eceran (mis. PCS bukan DUS)
            'satuan_grosir' => $r['satuan'],
            'satuan_eceran' => $r['satuan_eceran'],
            'sisa_grosir'   => (float)$r['sisa_grosir'],
            'sisa_eceran'   => (float)$r['sisa_eceran'],
        ];
    }, $rows);

    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}