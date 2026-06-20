<?php
require_once 'koneksi.php';

header('Content-Type: application/json');

$idDetail = $_GET['id_detail'] ?? 0;
$type = $_GET['type'] ?? '';

$photos = [];

if ($type === 'nota') {
    $stmt = $pdo->prepare("SELECT file_nota FROM lampiran_nota WHERE id_detail = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$idDetail]);
    $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);
} elseif ($type === 'foto') {
    $stmt = $pdo->prepare("SELECT foto FROM foto_receiving WHERE id_detail = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$idDetail]);
    $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

echo json_encode(['photos' => $photos]);
?>