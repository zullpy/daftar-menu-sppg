<?php
header('Content-Type: application/json');
require 'koneksi.php';
require 'stok_helper.php';

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
        $satuan = $b['satuan'] ?? '';
        $qty_ambil = (float)($b['qty'] ?? 0);

        if ($qty_ambil <= 0) {
            throw new Exception("Qty pengambilan untuk barang '$nama_barang' tidak valid!");
        }

        // Cek & potong stok (otomatis deteksi satuan grosir/eceran, konversi kalau perlu,
        // lalu sinkronkan mirror stok_barang_eceran). Row locking di dalam fungsi ini
        // supaya aman kalau ada 2 pengambilan bersamaan.
        stok_kurangiUntukPengambilan($pdo, $nama_barang, $satuan, $lokasi, $qty_ambil);

        $stmtDetail->execute([
            ':id'    => $id_pengambilan,
            ':nama'  => $nama_barang,
            ':qty'   => $qty_ambil,
            ':satuan' => $satuan
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
