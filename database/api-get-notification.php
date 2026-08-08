<?php
header('Content-Type: application/json');
require_once 'koneksi.php';

try {
    $stmt = $pdo->query("SELECT title, body FROM push_notifications ORDER BY id DESC LIMIT 1");
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($notification) {
        echo json_encode([
            'status' => 'success',
            'title' => $notification['title'],
            'body' => $notification['body']
        ]);
    } else {
        echo json_encode([
            'status' => 'empty',
            'title' => 'Permenceker Update',
            'body' => 'Ada pembaruan terbaru di aplikasi.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'title' => 'Permenceker Update',
        'body' => 'Ada pembaruan terbaru di aplikasi.'
    ]);
}
