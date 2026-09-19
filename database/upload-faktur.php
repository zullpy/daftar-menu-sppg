<?php
session_start();
header('Content-Type: application/json');

// ====== CEK SESSION & ROLE (HANYA ADMIN) ======
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya admin yang bisa upload faktur.']);
    exit;
}

require_once 'koneksi.php';
require_once __DIR__ . '/cloudinary_helper.php';

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
    $savedPhoto = smart_upload_foto(
        $_FILES['foto'],
        'faktur',
        $uploadDir,
        'faktur_' . preg_replace('/-/', '', $tanggal)
    );

    // Cek apakah tanggal ini sudah pernah ada fakturnya, kalau ada hapus file lama lalu update
    $stmtCheck = $pdo->prepare("SELECT id_faktur, file_faktur FROM faktur_ttd WHERE tanggal = :tanggal");
    $stmtCheck->execute([':tanggal' => $tanggal]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $oldFile = $existing['file_faktur'];
        if (!empty($oldFile)) {
            delete_photo_asset($oldFile, __DIR__ . '/../uploads/faktur/');
        }
        $stmtUpdate = $pdo->prepare("UPDATE faktur_ttd SET file_faktur = :file_faktur, uploaded_at = NOW() WHERE id_faktur = :id_faktur");
        $stmtUpdate->execute([
            ':file_faktur' => $savedPhoto,
            ':id_faktur'   => $existing['id_faktur'],
        ]);
    } else {
        $stmtInsert = $pdo->prepare("INSERT INTO faktur_ttd (tanggal, file_faktur) VALUES (:tanggal, :file_faktur)");
        $stmtInsert->execute([
            ':tanggal'     => $tanggal,
            ':file_faktur' => $savedPhoto,
        ]);
    }

    echo json_encode(['success' => true, 'filename' => $savedPhoto]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
