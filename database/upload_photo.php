<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
header('Content-Type: application/json');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'];
$action = $_POST['action'] ?? '';

// 🔒 BATASI AKSI BERDASARKAN ROLE
$operatorAllowed = ['add_foto_receiving']; // Operator HANYA boleh ini
$adminOnly = ['add_menu_photo', 'add_nota']; // Admin saja

if ($role === 'operator' && !in_array($action, $operatorAllowed)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Operator hanya bisa upload foto receiving.']);
    exit;
}

if ($role !== 'admin' && in_array($action, $adminOnly)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak!']);
    exit;
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Fatal Error: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

try {
    // koneksi.php ada di folder yang sama (database/)
    require_once __DIR__ . '/koneksi.php';
    
    if (!isset($_POST['action'])) {
        throw new Exception('Action tidak ditemukan');
    }
    
    $action = $_POST['action'];
    $response = ['success' => false, 'message' => 'Action tidak valid'];
    
    if (!isset($_FILES['foto'])) {
        throw new Exception('Field "foto" tidak ditemukan');
    }
    
    $fileError = $_FILES['foto']['error'];
    if ($fileError !== UPLOAD_ERR_OK) {
        $errorMsg = [
            UPLOAD_ERR_INI_SIZE   => 'File terlalu besar (php.ini)',
            UPLOAD_ERR_FORM_SIZE  => 'File terlalu besar (form)',
            UPLOAD_ERR_PARTIAL    => 'File hanya ter-upload sebagian',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang di-upload',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ada',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file',
            UPLOAD_ERR_EXTENSION  => 'Upload diblokir extension',
        ];
        throw new Exception($errorMsg[$fileError] ?? 'Upload error: ' . $fileError);
    }
    
    $file = $_FILES['foto'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedNota = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    
    // Path uploads: naik 1 level dari database/ ke root, lalu ke uploads/
    $basePath = __DIR__ . '/../uploads/';
    
    if ($action === 'add_menu_photo') {
        $idBelanja = (int)($_POST['id_belanja'] ?? 0);
        if ($idBelanja <= 0) throw new Exception('ID Belanja tidak valid');
        if (!in_array($fileExt, $allowedImg)) throw new Exception('Format: ' . implode(', ', $allowedImg));
        
        $uploadDir = $basePath . 'menu/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        if (!is_writable($uploadDir)) throw new Exception('Folder uploads/menu/ tidak writable');
        
        $newName = 'menu_' . date('YmdHis') . '_' . $idBelanja . '_' . uniqid() . '.' . $fileExt;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
            throw new Exception('Gagal memindahkan file');
        }
        
        try {
            $pdo->prepare("INSERT INTO foto_menu_multiple (id_belanja, foto) VALUES (?, ?)")
                ->execute([$idBelanja, $newName]);
        } catch (PDOException $e) {
            $pdo->prepare("UPDATE belanja SET foto_menu = ? WHERE id_belanja = ?")
                ->execute([$newName, $idBelanja]);
        }
        
        $response = ['success' => true, 'foto' => $newName, 'message' => 'Foto menu ditambahkan'];
    }
    elseif ($action === 'add_nota') {
        $idDetail = (int)($_POST['id_detail'] ?? 0);
        if ($idDetail <= 0) throw new Exception('ID Detail tidak valid');
        if (!in_array($fileExt, $allowedNota)) throw new Exception('Format: ' . implode(', ', $allowedNota));
        
        $uploadDir = $basePath . 'nota/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        if (!is_writable($uploadDir)) throw new Exception('Folder uploads/nota/ tidak writable');
        
        $newName = 'nota_' . date('YmdHis') . '_' . $idDetail . '_' . uniqid() . '.' . $fileExt;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
            throw new Exception('Gagal memindahkan file');
        }
        
        $pdo->prepare("INSERT INTO lampiran_nota (id_detail, file_nota) VALUES (?, ?)")
            ->execute([$idDetail, $newName]);
        
        $response = ['success' => true, 'foto' => $newName, 'message' => 'Nota ditambahkan'];
    }
    elseif ($action === 'add_foto_receiving') {
        $idDetail = (int)($_POST['id_detail'] ?? 0);
        if ($idDetail <= 0) throw new Exception('ID Detail tidak valid');
        if (!in_array($fileExt, $allowedImg)) throw new Exception('Format: ' . implode(', ', $allowedImg));
        
        $uploadDir = $basePath . 'foto/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        if (!is_writable($uploadDir)) throw new Exception('Folder uploads/foto/ tidak writable');
        
        $newName = 'receiving_' . date('YmdHis') . '_' . $idDetail . '_' . uniqid() . '.' . $fileExt;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
            throw new Exception('Gagal memindahkan file');
        }
        
        $pdo->prepare("INSERT INTO foto_receiving (id_detail, foto) VALUES (?, ?)")
            ->execute([$idDetail, $newName]);
        
        $response = ['success' => true, 'foto' => $newName, 'message' => 'Foto receiving ditambahkan'];
    }
    else {
        throw new Exception('Action tidak dikenal: ' . $action);
    }
    
    echo json_encode($response);
    exit;
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}