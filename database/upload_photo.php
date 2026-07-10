<?php
session_start();
header('Content-Type: application/json');

// 🔒 CEK LOGIN
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized - silakan login terlebih dahulu']);
    exit;
}

$role = $_SESSION['role'];
$action = $_POST['action'] ?? '';

// ✨ UPDATED: Operator boleh upload foto menu & foto receiving
$operatorAllowed = ['add_foto_receiving', 'add_menu_photo'];
$adminOnly = ['add_nota'];

// Validasi action berdasarkan role
if ($role === 'operator' && !in_array($action, $operatorAllowed)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Operator hanya bisa upload foto menu dan foto receiving.']);
    exit;
}

if ($role !== 'admin' && in_array($action, $adminOnly)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Hanya admin yang bisa upload nota.']);
    exit;
}

// Validasi file
if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'File tidak valid';
    if (isset($_FILES['foto'])) {
        $errorMsg .= ' (Error code: ' . $_FILES['foto']['error'] . ')';
    }
    echo json_encode(['success' => false, 'message' => $errorMsg]);
    exit;
}

// Validasi ukuran (max 10MB sebelum compress)
if ($_FILES['foto']['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 10MB']);
    exit;
}

$fileExt = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
if (!in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
    echo json_encode(['success' => false, 'message' => 'Format file tidak didukung. Gunakan JPG, PNG, GIF, atau WEBP']);
    exit;
}

require_once 'koneksi.php';

try {
    if ($action === 'add_menu_photo') {
        $idBelanja = (int)($_POST['id_belanja'] ?? 0);
        if (!$idBelanja) {
            throw new Exception('ID belanja tidak valid');
        }

        $uploadDir = '../uploads/menu/';
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                throw new Exception('Gagal membuat folder uploads/menu/');
            }
        }

        // Pastikan folder writable
        if (!is_writable($uploadDir)) {
            throw new Exception('Folder uploads/menu/ tidak bisa ditulis. Cek permission.');
        }

        $newName = 'menu_' . date('YmdHis') . '_' . $idBelanja . '_' . uniqid() . '.' . $fileExt;
        $targetPath = $uploadDir . $newName;

        if (!move_uploaded_file($_FILES['foto']['tmp_name'], $targetPath)) {
            throw new Exception('Gagal menyimpan file ke server');
        }

        $stmt = $pdo->prepare("INSERT INTO foto_menu_multiple (id_belanja, foto) VALUES (?, ?)");
        $stmt->execute([$idBelanja, $newName]);

        echo json_encode([
            'success' => true,
            'message' => 'Foto menu berhasil diupload',
            'filename' => $newName
        ]);
    } elseif ($action === 'add_foto_receiving') {
        $idDetail = (int)($_POST['id_detail'] ?? 0);
        if (!$idDetail) {
            throw new Exception('ID detail tidak valid');
        }

        $uploadDir = '../uploads/foto/';
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                throw new Exception('Gagal membuat folder uploads/foto/');
            }
        }

        if (!is_writable($uploadDir)) {
            throw new Exception('Folder uploads/foto/ tidak bisa ditulis. Cek permission.');
        }

        $newName = 'receiving_' . date('YmdHis') . '_' . $idDetail . '_' . uniqid() . '.' . $fileExt;
        $targetPath = $uploadDir . $newName;

        if (!move_uploaded_file($_FILES['foto']['tmp_name'], $targetPath)) {
            throw new Exception('Gagal menyimpan file ke server');
        }

        $stmt = $pdo->prepare("INSERT INTO foto_receiving (id_detail, foto) VALUES (?, ?)");
        $stmt->execute([$idDetail, $newName]);

        echo json_encode([
            'success' => true,
            'message' => 'Foto receiving berhasil diupload',
            'filename' => $newName
        ]);
    } elseif ($action === 'add_nota') {
        $idDetail = (int)($_POST['id_detail'] ?? 0);
        if (!$idDetail) {
            throw new Exception('ID detail tidak valid');
        }

        $uploadDir = '../uploads/nota/';
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                throw new Exception('Gagal membuat folder uploads/nota/');
            }
        }

        if (!is_writable($uploadDir)) {
            throw new Exception('Folder uploads/nota/ tidak bisa ditulis. Cek permission.');
        }

        $newName = 'nota_' . date('YmdHis') . '_' . $idDetail . '_' . uniqid() . '.' . $fileExt;
        $targetPath = $uploadDir . $newName;

        if (!move_uploaded_file($_FILES['foto']['tmp_name'], $targetPath)) {
            throw new Exception('Gagal menyimpan file ke server');
        }

        $stmt = $pdo->prepare("INSERT INTO lampiran_nota (id_detail, file_nota) VALUES (?, ?)");
        $stmt->execute([$idDetail, $newName]);

        echo json_encode([
            'success' => true,
            'message' => 'Nota berhasil diupload',
            'filename' => $newName
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Action tidak valid: ' . $action]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
