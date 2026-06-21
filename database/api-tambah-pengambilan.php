<?php
header('Content-Type: application/json');
require 'koneksi.php';

// Baca input JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    exit;
}

$nama_pengambil = $input['nama_pengambil'] ?? '';
$nama_sppg = $input['nama_sppg'] ?? '';
$tanggal = $input['tanggal_pengambilan'] ?? '';
$jam = $input['jam_pengambilan'] ?? '';
$no_kontak = $input['no_kontak'] ?? '';
$barang = $input['barang'] ?? [];

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

    // Insert Header
    $sql = "INSERT INTO pengambilan_barang (no_pengambilan, nama_pengambil, nama_sppg, tanggal_pengambilan, jam_pengambilan, no_kontak, status) 
            VALUES (:no, :pengambil, :sppg, :tgl, :jam, :kontak, 'pending')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':no' => $no_pengambilan,
        ':pengambil' => $nama_pengambil,
        ':sppg' => $nama_sppg,
        ':tgl' => $tanggal,
        ':jam' => $jam,
        ':kontak' => $no_kontak
    ]);
    $id_pengambilan = $pdo->lastInsertId();

    // Insert Detail Barang
    $sqlDetail = "INSERT INTO pengambilan_barang_detail (id_pengambilan, nama_barang, qty, satuan) 
                  VALUES (:id, :nama, :qty, :satuan)";
    $stmtDetail = $pdo->prepare($sqlDetail);

    foreach ($barang as $b) {
        $stmtDetail->execute([
            ':id' => $id_pengambilan,
            ':nama' => $b['nama_barang'],
            ':qty' => $b['qty'],
            ':satuan' => $b['satuan']
        ]);
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => "Laporan $no_pengambilan berhasil dibuat!"]);
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
}
