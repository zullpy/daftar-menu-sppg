<?php
session_start();
header('Content-Type: application/json');

// ====== CEK SESSION & ROLE (HANYA ADMIN) ======
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya admin yang bisa upload faktur.']);
    exit;
}

require_once 'koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'add_faktur_ttd') {
    echo json_encode(['success' => false, 'message' => 'Aksi tidak valid.']);
    exit;
}

$tanggal = $_POST['tanggal'] ?? '';
if (!$tanggal || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    echo json_encode(['success' => false, 'message' => 'Tanggal tidak valid.']);
    exit;
}

if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File tidak ditemukan atau gagal diupload.']);
    exit;
}

$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
$fileExt = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));

if (!in_array($fileExt, $allowedExt, true)) {
    echo json_encode(['success' => false, 'message' => 'Tipe file tidak diizinkan. Gunakan gambar atau PDF.']);
    exit;
}

// Batas ukuran file 10MB
if ($_FILES['foto']['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 10MB.']);
    exit;
}

try {
    $uploadDir = '../uploads/faktur/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newName = 'faktur_' . date('YmdHis') . '_' . preg_replace('/-/', '', $tanggal) . '.' . $fileExt;

    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $uploadDir . $newName)) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file ke server.']);
        exit;
    }

    // Cek apakah tanggal ini sudah pernah ada fakturnya, kalau ada hapus file lama lalu update
    $stmtCheck = $pdo->prepare("SELECT id_faktur, file_faktur FROM faktur_ttd WHERE tanggal = :tanggal");
    $stmtCheck->execute([':tanggal' => $tanggal]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $oldFile = $uploadDir . $existing['file_faktur'];
        if (file_exists($oldFile)) {
            unlink($oldFile);
        }
        $stmtUpdate = $pdo->prepare("UPDATE faktur_ttd SET file_faktur = :file_faktur, uploaded_at = NOW() WHERE id_faktur = :id_faktur");
        $stmtUpdate->execute([
            ':file_faktur' => $newName,
            ':id_faktur'   => $existing['id_faktur'],
        ]);
    } else {
        $stmtInsert = $pdo->prepare("INSERT INTO faktur_ttd (tanggal, file_faktur) VALUES (:tanggal, :file_faktur)");
        $stmtInsert->execute([
            ':tanggal'     => $tanggal,
            ':file_faktur' => $newName,
        ]);
    }

    echo json_encode(['success' => true, 'filename' => $newName]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
