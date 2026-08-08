<?php
// VAPID configuration for Web Push Notifications

$config_file = __DIR__ . '/push_config_keys.php';
if (!file_exists($config_file)) {
    // Generate VAPID keypair
    $res = openssl_pkey_new([
        "private_key_type" => OPENSSL_KEYTYPE_EC,
        "curve_name" => "prime256v1"
    ]);
    if ($res) {
        openssl_pkey_export($res, $private_pem);
        $details = openssl_pkey_get_details($res);
        if (isset($details['ec'])) {
            $x = $details['ec']['x'];
            $y = $details['ec']['y'];
            $d = $details['ec']['d'];
            
            $public_key_bin = "\x04" . $x . $y;
            $public_key_b64 = rtrim(strtr(base64_encode($public_key_bin), '+/', '-_'), '=');
            $private_key_b64 = rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
            
            $content = "<?php\n"
                     . "define('VAPID_PUBLIC_KEY', " . var_export($public_key_b64, true) . ");\n"
                     . "define('VAPID_PRIVATE_KEY', " . var_export($private_key_b64, true) . ");\n"
                     . "define('VAPID_PRIVATE_PEM', " . var_export($private_pem, true) . ");\n"
                     . "define('VAPID_SUBJECT', 'mailto:admin@kbus.site');\n";
            file_put_contents($config_file, $content);
        }
    }
}

if (file_exists($config_file)) {
    require_once $config_file;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_public_key') {
    header('Content-Type: application/json');
    echo json_encode(['publicKey' => VAPID_PUBLIC_KEY]);
    exit;
}
