<?php
header('Content-Type: application/json');
require 'koneksi.php';

// Baca input JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    exit;
}

// ✅ AMBIL SEMUA DATA TERMASUK LOKASI
$nama_pengambil = $input['nama_pengambil'] ?? '';
$nama_sppg      = $input['nama_sppg'] ?? '';
$tanggal        = $input['tanggal_pengambilan'] ?? '';
$jam            = $input['jam_pengambilan'] ?? '';
$no_kontak      = $input['no_kontak'] ?? '';
$lokasi         = $input['lokasi'] ?? 'semua'; // ✅ LOKASI DAPUR
$barang         = $input['barang'] ?? [];

// Validasi data wajib
if (empty($nama_pengambil) || empty($nama_sppg) || empty($tanggal)) {
    echo json_encode(['status' => 'error', 'message' => 'Data wajib tidak boleh kosong']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Generate No Pengambilan
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(no_pengambilan, 4) AS UNSIGNED)) as max_no FROM pengambilan_barang WHERE no_pengambilan LIKE 'PGJ%'");
    $row = $stmt->fetch();
    $next_no = str_pad(($row['max_no'] + 1), 5, '0', STR_PAD_LEFT);
    $no_pengambilan = 'PGJ' . $next_no;

    // ✅ INSERT HEADER - DENGAN KOLOM LOKASI
    $sql = "INSERT INTO pengambilan_barang 
            (no_pengambilan, nama_pengambil, nama_sppg, tanggal_pengambilan, jam_pengambilan, no_kontak, lokasi, status)
            VALUES (:no, :pengambil, :sppg, :tgl, :jam, :kontak, :lokasi, 'pending')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':no'       => $no_pengambilan,
        ':pengambil' => $nama_pengambil,
        ':sppg'     => $nama_sppg,
        ':tgl'      => $tanggal,
        ':jam'      => $jam,
        ':kontak'   => $no_kontak,
        ':lokasi'   => $lokasi // ✅ SIMPAN LOKASI
    ]);

    $id_pengambilan = $pdo->lastInsertId();

    // Insert Detail Barang
    $sqlDetail = "INSERT INTO pengambilan_barang_detail (id_pengambilan, nama_barang, qty, satuan)
                  VALUES (:id, :nama, :qty, :satuan)";
    $stmtDetail = $pdo->prepare($sqlDetail);
    foreach ($barang as $b) {
        $nama_barang = $b['nama_barang'] ?? '';
        $qty_ambil = (float)($b['qty'] ?? 0);

        // Cek stok masuk
        $sqlMasuk = "SELECT 
            COALESCE(SUM(CASE WHEN dpr.status_barang = 'tidak_ada' THEN 0 ELSE dp.qty END), 0) as total_masuk
        FROM detail_pengiriman dp
        JOIN pengiriman p ON dp.pengiriman_id = p.id
        LEFT JOIN penerimaan pr ON pr.pengiriman_id = p.id
        LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id AND dpr.penerimaan_id = pr.id
        WHERE (:lokasi1 = 'semua' OR p.lokasi = :lokasi2)
        AND TRIM(UPPER(dp.nama_barang)) = TRIM(UPPER(:nama))";
        
        $stmtMasuk = $pdo->prepare($sqlMasuk);
        $stmtMasuk->execute([
            ':lokasi1' => $lokasi,
            ':lokasi2' => $lokasi,
            ':nama' => $nama_barang
        ]);
        $totalMasuk = (float)$stmtMasuk->fetchColumn();

        // Cek stok keluar
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

        if ($sisaStok <= 0) {
            throw new Exception("Stok untuk barang '$nama_barang' tidak ada!");
        }

        if ($qty_ambil > $sisaStok) {
            throw new Exception("Jumlah pengambilan untuk barang '$nama_barang' ($qty_ambil) melebihi stok yang tersedia ($sisaStok)!");
        }

        $stmtDetail->execute([
            ':id'    => $id_pengambilan,
            ':nama'  => $nama_barang,
            ':qty'   => $qty_ambil,
            ':satuan' => $b['satuan']
        ]);
    }

    $pdo->commit();
    echo json_encode([
        'status'  => 'success',
        'message' => "Laporan $no_pengambilan berhasil dibuat untuk Dapur " . strtoupper($lokasi)
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
}
