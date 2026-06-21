<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'koneksi.php'; // $pdo

$tanggal = $_GET['tanggal'] ?? '';
$tanggal = str_replace('-', '', $tanggal);

$stmt = $pdo->prepare("
    SELECT no_faktur
    FROM belanja
    WHERE no_faktur LIKE ?
    ORDER BY id_belanja DESC
    LIMIT 1
");

$stmt->execute(["%FC-$tanggal"]);
$last = $stmt->fetch(PDO::FETCH_ASSOC);

if ($last) {
    preg_match('/^(\d+)/', $last['no_faktur'], $match);
    $urut = (int)$match[1] + 1;
} else {
    $urut = 1;
}

echo sprintf('%04d', $urut) . "FC-$tanggal";
