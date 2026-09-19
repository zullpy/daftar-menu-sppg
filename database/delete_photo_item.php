<?php
// Anti bocor
while (ob_get_level()) { ob_end_clean(); }
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/cloudinary_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - silakan login terlebih dahulu']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$idDetail = intval($data['id_detail'] ?? $_POST['id_detail'] ?? 0);
$type = trim($data['type'] ?? $_POST['type'] ?? '');
$file = trim($data['file'] ?? $_POST['file'] ?? '');

$role = $_SESSION['role'];
if ($role === 'operator' && $type === 'nota') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Hanya admin yang bisa menghapus nota.']);
    exit;
}

if (!$idDetail || empty($file)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parameter id_detail dan file wajib diisi']);
    exit;
}

try {
    $uploadDir = ($type === 'nota') ? __DIR__ . '/../uploads/nota/' : __DIR__ . '/../uploads/foto/';
    $table = ($type === 'nota') ? 'lampiran_nota' : 'foto_receiving';
    $column = ($type === 'nota') ? 'file_nota' : 'foto';
    $cleanPath = parse_url($file, PHP_URL_PATH) ?? $file;
    $baseName = basename($cleanPath);

    // 1. Ambil nama file dari database sebelum dihapus untuk memastikan file fisik yang tepat terhapus
    $stmtFind = $pdo->prepare("SELECT $column FROM $table WHERE id_detail = ? AND ($column = ? OR $column LIKE ?)");
    $stmtFind->execute([$idDetail, $file, '%' . $baseName . '%']);
    $matchedFiles = $stmtFind->fetchAll(PDO::FETCH_COLUMN);

    // 2. Hapus file fisik secara permanen dari server lokal / Cloudinary
    foreach ($matchedFiles as $mf) {
        delete_photo_asset($mf, $uploadDir);
    }
    delete_photo_asset($file, $uploadDir);

    // 3. Hapus baris dari tabel database
    $stmt = $pdo->prepare("DELETE FROM $table WHERE id_detail = ? AND ($column = ? OR $column LIKE ?)");
    $stmt->execute([$idDetail, $file, '%' . $baseName . '%']);

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE id_detail = ?");
    $stmtCount->execute([$idDetail]);
    $remaining = (int)$stmtCount->fetchColumn();

    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode([
        'success' => true,
        'message' => 'Foto berhasil dihapus secara permanen',
        'remaining' => $remaining
    ]);
    exit;
} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menghapus foto: ' . $e->getMessage()
    ]);
    exit;
}
