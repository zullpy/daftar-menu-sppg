<?php
require_once __DIR__ . '/push_config.php';

if (!function_exists('base64url_encode')) {
    function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('base64url_decode')) {
    function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

if (!function_exists('der_to_signature')) {
    function der_to_signature($der) {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) return false;
        $len = ord($der[$offset++]);
        if ($len & 0x80) {
            $offset += ($len & 0x7f);
        }
        if (ord($der[$offset++]) !== 0x02) return false;
        $lenR = ord($der[$offset++]);
        $R = substr($der, $offset, $lenR);
        $offset += $lenR;
        if (ord($der[$offset++]) !== 0x02) return false;
        $lenS = ord($der[$offset++]);
        $S = substr($der, $offset, $lenS);
        
        $R = ltrim($R, "\x00");
        $S = ltrim($S, "\x00");
        
        $R = str_pad($R, 32, "\x00", STR_PAD_LEFT);
        $S = str_pad($S, 32, "\x00", STR_PAD_LEFT);
        
        return $R . $S;
    }
}

function send_push_trigger($endpoint) {
    // Parse the endpoint URL to get audience
    $parsed_url = parse_url($endpoint);
    if (!$parsed_url || !isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
        return 0;
    }
    $audience = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    
    // Generate JWT
    $header = base64url_encode(json_encode(["alg" => "ES256", "typ" => "JWT"]));
    $claims = base64url_encode(json_encode([
        "aud" => $audience,
        "exp" => time() + 43200, // 12 hours
        "sub" => VAPID_SUBJECT
    ]));
    
    $data_to_sign = $header . "." . $claims;
    
    $pkey = openssl_pkey_get_private(VAPID_PRIVATE_PEM);
    if (!$pkey) {
        return 0;
    }
    
    $signature_der = '';
    openssl_sign($data_to_sign, $signature_der, $pkey, OPENSSL_ALGO_SHA256);
    $raw_signature = der_to_signature($signature_der);
    if (!$raw_signature) {
        return 0;
    }
    
    $jwt = $data_to_sign . "." . base64url_encode($raw_signature);
    
    // Prepare HTTP request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ''); // Empty body for payload-free push
    
    $headers = [
        'TTL: 86400',
        'Urgency: high',
        'Content-Length: 0',
        'Authorization: vapid t=' . $jwt . ',k=' . VAPID_PUBLIC_KEY
    ];
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code;
}

function broadcast_push_notification($pdo, $title, $body) {
    // 1. Save notification to db log
    try {
        $stmt = $pdo->prepare("INSERT INTO push_notifications (title, body) VALUES (?, ?)");
        $stmt->execute([$title, $body]);
    } catch (Exception $e) {
        // Ignore or log
    }
    
    // 2. Fetch all subscriptions
    try {
        $stmt = $pdo->query("SELECT id, endpoint FROM push_subscriptions");
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $failed_ids = [];
        foreach ($subscriptions as $sub) {
            $status = send_push_trigger($sub['endpoint']);
            if ($status === 410 || $status === 404) {
                // Subscription has expired or is invalid
                $failed_ids[] = $sub['id'];
            }
        }
        
        if (!empty($failed_ids)) {
            $in = implode(',', array_fill(0, count($failed_ids), '?'));
            $stmt_del = $pdo->prepare("DELETE FROM push_subscriptions WHERE id IN ($in)");
            $stmt_del->execute($failed_ids);
        }
    } catch (Exception $e) {
        // Ignore
    }
}
