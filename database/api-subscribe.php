<?php
header('Content-Type: application/json');
require_once 'koneksi.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$endpoint = trim($input['endpoint'] ?? '');
$p256dh = trim($input['keys']['p256dh'] ?? '');
$auth = trim($input['keys']['auth'] ?? '');

if (empty($endpoint) || empty($p256dh) || empty($auth)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing subscription details']);
    exit;
}

try {
    // Insert or update subscription
    $stmt = $pdo->prepare("INSERT INTO push_subscriptions (endpoint, p256dh, auth) 
                           VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth)");
    $stmt->execute([$endpoint, $p256dh, $auth]);
    echo json_encode(['status' => 'success', 'message' => 'Subscribed successfully']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
