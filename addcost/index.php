<?php
session_start();
// ====== CEK SESSION ROLE ======
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    header('Location: index.php');
    exit;
}
$role = $_SESSION['role'];
$isAdmin = ($role === 'admin');
$isOperator = ($role === 'operator');

// ✅ AMBIL LOKASI DAPUR DARI SESSION
$lokasiSession = $_SESSION['lokasi'] ?? 'semua';
$lokasiMap = ['sodong' => 'Sodong', 'sariwangi' => 'Sariwangi', 'manonjaya' => 'Manonjaya', 'semua' => 'Semua'];
$namaLokasiDisplay = $lokasiMap[$lokasiSession] ?? $lokasiSession;

// ====== LOGOUT ======
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

require_once '../database/koneksi.php';
require_once '../assets/icons.php';

// ====== 🔒 PROSES HAPUS DETAIL ADDCOST (HANYA ADMIN) ======
if (isset($_GET['delete_detail'])) {
    if (!$isAdmin) {
        header("Location: addcost.php?error=unauthorized");
        exit;
    }
    $id = (int)$_GET['delete_detail'];
    $stmt = $pdo->prepare("SELECT pembelian_add_id FROM pembelian_addcost_detail WHERE id = ?");
    $stmt->execute([$id]);
    $pembelianAddId = $stmt->fetchColumn();
    $pdo->prepare("DELETE FROM pembelian_addcost_detail WHERE id = ?")->execute([$id]);
    if ($pembelianAddId) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) as total FROM pembelian_addcost_detail WHERE pembelian_add_id = ?");
        $stmt->execute([$pembelianAddId]);
        $newTotal = $stmt->fetchColumn();
        $pdo->prepare("UPDATE pembelian_addcost SET total = ? WHERE id = ?")->execute([$newTotal, $pembelianAddId]);
    }
    header("Location: addcost.php?deleted=1");
    exit;
}

// ====== 🔒 PROSES HAPUS ADDCOST (HANYA ADMIN) ======
if (isset($_GET['delete_addcost'])) {
    if (!$isAdmin) {
        header("Location: addcost.php?error=unauthorized");
        exit;
    }
    $id = (int)$_GET['delete_addcost'];
    $pdo->prepare("DELETE FROM pembelian_addcost_detail WHERE pembelian_add_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM pembelian_addcost WHERE id = ?")->execute([$id]);
    header("Location: addcost.php?deleted=1");
    exit;
}

// ====== 🔒 PROSES UPDATE DETAIL ADDCOST (HANYA ADMIN) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_detail'])) {
    if (!$isAdmin) {
        header("Location: addcost.php?error=unauthorized");
        exit;
    }
    try {
        $id = $_POST['id_detail'];
        $nama_barang = $_POST['nama_barang'];
        $harga = (float)$_POST['harga'];
        $qty = (float)$_POST['qty'];
        $satuan = $_POST['satuan'];
        $subtotal = $harga * $qty;
        $stmt = $pdo->prepare("UPDATE pembelian_addcost_detail SET nama_barang=?, harga=?, qty=?, satuan=?, subtotal=? WHERE id=?");
        $stmt->execute([$nama_barang, $harga, $qty, $satuan, $subtotal, $id]);
        $pembelian_add_id = $_POST['pembelian_add_id'];
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) as total FROM pembelian_addcost_detail WHERE pembelian_add_id = ?");
        $stmt->execute([$pembelian_add_id]);
        $newTotal = $stmt->fetchColumn();
        $pdo->prepare("UPDATE pembelian_addcost SET total = ? WHERE id = ?")->execute([$newTotal, $pembelian_add_id]);
        header("Location: addcost.php?updated=1");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ====== 🔄 PROSES UPDATE STATUS ITEM (HANYA OPERATOR) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status_item'])) {
    if ($isAdmin) {
        header("Location: addcost.php?error=unauthorized");
        exit;
    }
    try {
        $id = (int)$_POST['id_detail'];
        $status = $_POST['status'];
        $keterangan = trim($_POST['keterangan'] ?? '');
        if ($status === 'kurang' && empty($keterangan)) {
            header("Location: addcost.php?error=keterangan_wajib");
            exit;
        }
        if ($status !== 'kurang') {
            $keterangan = null;
        }
        $stmt = $pdo->prepare("UPDATE pembelian_addcost_detail SET status = ?, keterangan = ? WHERE id = ?");
        $stmt->execute([$status, $keterangan, $id]);
        header("Location: addcost.php?status_updated=1");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ====== 📸 UPLOAD FOTO RECEIVING PER ITEM (MULTIPLE) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_foto_receiving_item') {
    $id = (int)$_POST['id_detail'];
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/addcost_receiving/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        $fileExt = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        if (!in_array($fileExt, $allowedExt)) {
            echo json_encode(['success' => false, 'message' => 'Format file tidak didukung']);
            exit;
        }
        $newName = 'receiving_' . date('YmdHis') . '_' . $id . '_' . rand(1000, 9999) . '.' . $fileExt;
        if (move_uploaded_file($_FILES['foto']['tmp_name'], $uploadDir . $newName)) {
            $stmt = $pdo->prepare("SELECT foto_receiving FROM pembelian_addcost_detail WHERE id = ?");
            $stmt->execute([$id]);
            $existingPhotos = $stmt->fetchColumn();
            $photosArray = !empty($existingPhotos) ? json_decode($existingPhotos, true) : [];
            if (!is_array($photosArray)) $photosArray = [];
            $photosArray[] = $newName;
            $pdo->prepare("UPDATE pembelian_addcost_detail SET foto_receiving = ? WHERE id = ?")
                ->execute([json_encode($photosArray), $id]);
            echo json_encode(['success' => true, 'filename' => $newName]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Gagal upload foto receiving']);
    exit;
}

// ====== 📸 UPLOAD FOTO NOTA PER ITEM (MULTIPLE) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_foto_nota_item') {
    $id = (int)$_POST['id_detail'];
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/addcost_nota/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        $fileExt = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        if (!in_array($fileExt, $allowedExt)) {
            echo json_encode(['success' => false, 'message' => 'Format file tidak didukung']);
            exit;
        }
        $newName = 'nota_' . date('YmdHis') . '_' . $id . '_' . rand(1000, 9999) . '.' . $fileExt;
        if (move_uploaded_file($_FILES['foto']['tmp_name'], $uploadDir . $newName)) {
            $stmt = $pdo->prepare("SELECT foto_nota FROM pembelian_addcost_detail WHERE id = ?");
            $stmt->execute([$id]);
            $existingPhotos = $stmt->fetchColumn();
            $photosArray = !empty($existingPhotos) ? json_decode($existingPhotos, true) : [];
            if (!is_array($photosArray)) $photosArray = [];
            $photosArray[] = $newName;
            $pdo->prepare("UPDATE pembelian_addcost_detail SET foto_nota = ? WHERE id = ?")
                ->execute([json_encode($photosArray), $id]);
            echo json_encode(['success' => true, 'filename' => $newName]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Gagal upload foto nota']);
    exit;
}

// ====== 🗑️ HAPUS FOTO PER ITEM ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_foto_item') {
    $id = (int)$_POST['id_detail'];
    $type = $_POST['type'];
    $filename = $_POST['filename'];
    $column = $type === 'receiving' ? 'foto_receiving' : 'foto_nota';
    $uploadDir = $type === 'receiving' ? 'uploads/addcost_receiving/' : 'uploads/addcost_nota/';
    $stmt = $pdo->prepare("SELECT $column FROM pembelian_addcost_detail WHERE id = ?");
    $stmt->execute([$id]);
    $existingPhotos = $stmt->fetchColumn();
    $photosArray = !empty($existingPhotos) ? json_decode($existingPhotos, true) : [];
    if (!is_array($photosArray)) $photosArray = [];
    $photosArray = array_filter($photosArray, function ($photo) use ($filename) {
        return $photo !== $filename;
    });
    $photosArray = array_values($photosArray);
    if (file_exists($uploadDir . $filename)) {
        unlink($uploadDir . $filename);
    }
    $pdo->prepare("UPDATE pembelian_addcost_detail SET $column = ? WHERE id = ?")
        ->execute([json_encode($photosArray), $id]);
    echo json_encode(['success' => true]);
    exit;
}

// ====== 🔒 PROSES TAMBAH ADDCOST BARU (HANYA ADMIN) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_addcost'])) {
    if (!$isAdmin) {
        header("Location: addcost.php?error=unauthorized");
        exit;
    }
    $no_faktur = $_POST['no_faktur'];
    $nama_supplier = $_POST['nama_supplier'];
    $no_kontak = $_POST['no_kontak'];
    $alamat_dapur = $_POST['alamat_dapur'];
    $tanggal = $_POST['tanggal'];
    $lokasi = $_POST['lokasi'] ?? 'semua'; // ✅ LOKASI DAPUR
    $total = 0;

    if (empty($no_faktur)) {
        header("Location: addcost.php?error=no_faktur");
        exit;
    }
    try {
        $pdo->beginTransaction();
        // ✅ TAMBAHKAN KOLOM lokasi
        $stmt = $pdo->prepare("INSERT INTO pembelian_addcost (no_faktur, nama_supplier, no_kontak, alamat_dapur, lokasi, tanggal, total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$no_faktur, $nama_supplier, $no_kontak, $alamat_dapur, $lokasi, $tanggal, $total]);
        $pembelianAddId = $pdo->lastInsertId();

        $rowIndexes = $_POST['row_index'] ?? [];
        $namaBarangs = $_POST['nama_barang'] ?? [];
        $hargas = $_POST['harga'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $satuans = $_POST['satuan'] ?? [];
        foreach ($rowIndexes as $idx) {
            if (empty($namaBarangs[$idx])) continue;
            $harga = (float)$hargas[$idx];
            $qty = (float)$qtys[$idx];
            $subtotal = $harga * $qty;
            $stmtDetail = $pdo->prepare("INSERT INTO pembelian_addcost_detail (pembelian_add_id, nama_barang, harga, qty, satuan, subtotal, status) VALUES (?, ?, ?, ?, ?, ?, 'belum_cek')");
            $stmtDetail->execute([$pembelianAddId, $namaBarangs[$idx], $harga, $qty, $satuans[$idx], $subtotal]);
        }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) as total FROM pembelian_addcost_detail WHERE pembelian_add_id = ?");
        $stmt->execute([$pembelianAddId]);
        $total = $stmt->fetchColumn();
        $pdo->prepare("UPDATE pembelian_addcost SET total = ? WHERE id = ?")->execute([$total, $pembelianAddId]);
        $pdo->commit();
        header("Location: addcost.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ====== GENERATE NO FAKTUR ADDCOST ======
if (isset($_GET['generate_no_faktur'])) {
    $tanggal = $_GET['tanggal'] ?? date('Ymd');
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(no_faktur, 'AC-', 1) AS UNSIGNED)) as max_no FROM pembelian_addcost WHERE no_faktur LIKE '%AC-%'");
    $stmt->execute();
    $maxNo = $stmt->fetchColumn();
    $nextNo = ($maxNo !== null && $maxNo > 0) ? $maxNo + 1 : 1;
    $noFaktur = str_pad($nextNo, 4, '0', STR_PAD_LEFT) . 'AC-' . $tanggal;
    echo $noFaktur;
    exit;
}

// ====== AMBIL DATA ADDCOST - ✅ DENGAN FILTER LOKASI ======
if ($isAdmin) {
    $addcostList = $pdo->query("SELECT * FROM pembelian_addcost ORDER BY tanggal DESC, created_at DESC")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM pembelian_addcost WHERE lokasi = ? ORDER BY tanggal DESC, created_at DESC");
    $stmt->execute([$lokasiSession]);
    $addcostList = $stmt->fetchAll();
}

foreach ($addcostList as &$addcost) {
    $stmt = $pdo->prepare("SELECT * FROM pembelian_addcost_detail WHERE pembelian_add_id = ? ORDER BY nama_barang");
    $stmt->execute([$addcost['id']]);
    $addcost['details'] = $stmt->fetchAll();
}
unset($addcost);

// ====== HITUNG RINGKASAN STATUS GLOBAL ======
$totalLengkap = 0;
$totalKurang = 0;
$totalTidakAda = 0;
$totalBelum = 0;
$totalItem = 0;
foreach ($addcostList as $addcost) {
    foreach ($addcost['details'] as $detail) {
        $totalItem++;
        $status = $detail['status'] ?? 'belum_cek';
        if ($status === 'lengkap') $totalLengkap++;
        elseif ($status === 'kurang') $totalKurang++;
        elseif ($status === 'tidak_ada') $totalTidakAda++;
        else $totalBelum++;
    }
}

function formatTanggalIndonesia($tanggal)
{
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $p = explode('-', $tanggal);
    return $p[2] . ' ' . $bulan[(int)$p[1]] . ' ' . $p[0];
}
function getNamaHari($tanggal)
{
    $hari = ['Sun' => 'Minggu', 'Mon' => 'Senin', 'Tue' => 'Selasa', 'Wed' => 'Rabu', 'Thu' => 'Kamis', 'Fri' => 'Jumat', 'Sat' => 'Sabtu'];
    return $hari[date('D', strtotime($tanggal))];
}
function getStatusBadge($status)
{
    $badges = [
        'belum_cek' => '<span class="status-badge status-belum">Belum Cek</span>',
        'lengkap' => '<span class="status-badge status-lengkap">✓ Lengkap</span>',
        'kurang' => '<span class="status-badge status-kurang">⚠ Kurang</span>',
        'tidak_ada' => '<span class="status-badge status-tidak-ada">✕ Tidak Ada</span>',
    ];
    return $badges[$status] ?? $badges['belum_cek'];
}
function generateRingkasanText($addcostList)
{
    $lengkap = 0;
    $kurang = 0;
    $tidakAda = 0;
    $belum = 0;
    $kurangItems = [];
    foreach ($addcostList as $addcost) {
        foreach ($addcost['details'] as $detail) {
            $status = $detail['status'] ?? 'belum_cek';
            if ($status === 'lengkap') {
                $lengkap++;
            } elseif ($status === 'kurang') {
                $kurang++;
                $keterangan = !empty($detail['keterangan']) ? ' (' . $detail['keterangan'] . ')' : '';
                $kurangItems[] = $detail['nama_barang'] . $keterangan;
            } elseif ($status === 'tidak_ada') {
                $tidakAda++;
            } else {
                $belum++;
            }
        }
    }
    $parts = [];
    if ($lengkap > 0) $parts[] = "$lengkap item diterima lengkap";
    if ($kurang > 0) $parts[] = "$kurang item kurang: " . implode(', ', $kurangItems);
    if ($tidakAda > 0) $parts[] = "$tidakAda item tidak ada";
    if ($belum > 0) $parts[] = "$belum item belum dicek";
    return implode('. ', $parts) . '.';
}

$LOKASI_LIST = ['sodong' => 'Dapur Sodong', 'sariwangi' => 'Dapur Sariwangi', 'manonjaya' => 'Dapur Manonjaya'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Cost - Koperasi Bina Usaha Sauyunan</title>
    <link rel="shortcut icon" href="assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ✅ Tambahan style untuk info dapur operator */
        .lokasi-badge-nav {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: #fff;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-left: 8px;
        }

        .info-dapur-operator {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 1px solid #86efac;
            border-radius: 12px;
            padding: 12px 16px;
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #166534;
            font-size: 14px;
        }

        .info-dapur-operator strong {
            color: #15803d;
        }

        .badge-lokasi-card {
            background: #dbeafe;
            color: #1e40af;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>
</head>

<body>
    <main>
        <!-- Navbar Role -->
        <div class="role-navbar">
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <div class="role-badge role-<?= $role ?>">
                    <?php if ($isAdmin): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
                        </svg>
                        Admin
                    <?php else: ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v2h20v-2c0-3.3-6.7-5-10-5z" />
                        </svg>
                        Operator
                    <?php endif; ?>
                </div>
                <?php if ($isOperator): ?>
                    <!-- ✅ Badge lokasi untuk operator -->
                    <span class="lokasi-badge-nav">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                            <circle cx="12" cy="10" r="3" />
                        </svg>
                        Dapur <?= htmlspecialchars($namaLokasiDisplay) ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="?logout=1" class="btn-logout" onclick="return confirm('Yakin ingin keluar?')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <polyline points="16 17 21 12 16 7" />
                    <line x1="21" y1="12" x2="9" y2="12" />
                </svg>
                Keluar
            </a>
        </div>

        <div class="header-top">
            <div class="info-menu">
                <h1 style="font-size: 24px; font-weight: 500; color:gray;">Add Cost</h1>
                <span style="font-style: italic; color:gray;">Manajemen Tambahan Biaya</span>
                <h2>Tambahan Biaya Pembelian</h2>
                <?php if ($isAdmin): ?>
                    <button class="btn btn-primary" style="margin-top: 16px;" onclick="openModal('modalAdd')">
                        <?= icon('plus', 16) ?> <span>Input Add Cost</span>
                    </button>
                <?php else: ?>
                    <!-- ✅ Info banner khusus operator dengan nama dapur -->
                    <div class="info-dapur-operator">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="16" x2="12" y2="12" />
                            <line x1="12" y1="8" x2="12.01" y2="8" />
                        </svg>
                        <span>
                            Halo Operator <strong>Dapur <?= htmlspecialchars($namaLokasiDisplay) ?></strong>!
                            Anda hanya melihat data Add Cost untuk dapur Anda.
                            Anda bisa <strong>upload foto receiving & nota</strong> dan <strong>update status</strong> barang per item.
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SEARCH BAR -->
        <div class="searchbar-wrap">
            <div class="searchbar-inner">
                <span class="searchbar-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                </span>
                <input type="text" id="searchAddcost" class="searchbar-input" placeholder="Cari nama dapur / supplier.." oninput="filterAddcost(this.value)" autocomplete="off">
                <button class="searchbar-clear" id="searchClear" onclick="clearSearch()" title="Hapus pencarian">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="searchbar-result" id="searchResult"></div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?= icon('check', 18) ?> <span>Add Cost berhasil ditambahkan!</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['updated'])): ?>
            <div class="alert alert-success"><?= icon('check', 18) ?> <span>Item berhasil diupdate!</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success"><?= icon('check', 18) ?> <span>Item berhasil dihapus!</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['status_updated'])): ?>
            <div class="alert alert-success"><?= icon('check', 18) ?> <span>Status barang berhasil diupdate!</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['foto_uploaded'])): ?>
            <div class="alert alert-success"><?= icon('check', 18) ?> <span>Foto berhasil diupload!</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['foto_deleted'])): ?>
            <div class="alert alert-success"><?= icon('check', 18) ?> <span>Foto berhasil dihapus!</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
            <div class="alert alert-error"><?= icon('alert', 18) ?> <span>Akses ditolak! Hanya admin yang bisa melakukan aksi ini.</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'no_faktur'): ?>
            <div class="alert alert-error"><?= icon('alert', 18) ?> <span>No faktur tidak valid!</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'keterangan_wajib'): ?>
            <div class="alert alert-error"><?= icon('alert', 18) ?> <span>Keterangan wajib diisi saat status "Kurang"!</span></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?= icon('alert', 18) ?> <span>Error: <?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <?php if (empty($addcostList)): ?>
            <div class="empty-state">
                <div style="text-align:center;padding:60px 20px;color:var(--muted);">
                    <div style="margin-bottom:16px;"><?= icon('empty', 64) ?></div>
                    <h3 style="margin-bottom:8px;color:var(--text);">Belum Ada Data Add Cost</h3>
                    <p>
                        <?php if ($isAdmin): ?>
                            Silakan klik tombol "Input Add Cost" untuk menambahkan data
                        <?php else: ?>
                            Belum ada data Add Cost untuk <strong>Dapur <?= htmlspecialchars($namaLokasiDisplay) ?></strong>.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <?php
            $groupedByDate = [];
            foreach ($addcostList as $b) $groupedByDate[$b['tanggal']][] = $b;
            $firstDate = true;
            foreach ($groupedByDate as $tanggal => $addcosts):
            ?>
                <div class="date-group" data-tanggal="<?= $tanggal ?>">
                    <div class="date-header accordion-toggle <?= $firstDate ? 'open' : '' ?>" onclick="toggleAccordion(this)">
                        <div>
                            <h3><?= icon('calendar', 20) ?> <?= getNamaHari($tanggal) ?>, <?= formatTanggalIndonesia($tanggal) ?></h3>
                            <p>Total Add Cost: <?= count($addcosts) ?></p>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <span class="accordion-icon">▼</span>
                        </div>
                    </div>
                    <div class="date-content <?= $firstDate ? 'active' : '' ?>">
                        <?php foreach ($addcosts as $addcost): ?>
                            <div class="menu-card">
                                <div class="dapur-info-badge">
                                    <span>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                            <circle cx="8.5" cy="7" r="4" />
                                            <line x1="20" y1="8" x2="20" y2="14" />
                                            <line x1="23" y1="11" x2="17" y2="11" />
                                        </svg>
                                        <?= htmlspecialchars($addcost['nama_supplier'] ?? '-') ?>
                                    </span>
                                    <span>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
                                        </svg>
                                        <?= htmlspecialchars($addcost['no_kontak'] ?? '-') ?>
                                    </span>
                                    <span>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                            <polyline points="14 2 14 8 20 8" />
                                            <line x1="16" y1="13" x2="8" y2="13" />
                                            <line x1="16" y1="17" x2="8" y2="17" />
                                        </svg>
                                        <?= htmlspecialchars($addcost['no_faktur'] ?? '-') ?>
                                    </span>
                                    <?php if ($isAdmin && !empty($addcost['lokasi'])): ?>
                                        <!-- ✅ Badge lokasi untuk admin -->
                                        <span class="badge-lokasi-card">
                                            📍 <?= htmlspecialchars($lokasiMap[$addcost['lokasi']] ?? $addcost['lokasi']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="menu-card-header">
                                    <div>
                                        <h4 class="menu-title">Supplier: <?= htmlspecialchars($addcost['nama_supplier']) ?></h4>
                                        <p class="menu-info">No Faktur: <strong><?= htmlspecialchars($addcost['no_faktur']) ?></strong></p>
                                        <?php if (!empty($addcost['alamat_dapur'])): ?>
                                            <p class="menu-info menu-info-alamat">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                                    <circle cx="12" cy="10" r="3" />
                                                </svg>
                                                <?= htmlspecialchars($addcost['alamat_dapur']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($isAdmin): ?>
                                        <a href="?delete_addcost=<?= $addcost['id'] ?>" class="btn btn-secondary btn-sm" onclick="return confirm('Yakin ingin menghapus add cost ini? Semua item akan terhapus!')" style="margin-left:auto;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="3 6 5 6 21 6" />
                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                            </svg>
                                            <span>Hapus</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="item-list">
                                    <?php
                                    $no = 1;
                                    $totalAddcost = 0;
                                    foreach ($addcost['details'] as $detail):
                                        $totalAddcost += $detail['subtotal'];
                                        $currentStatus = $detail['status'] ?? 'belum_cek';
                                        $receivingPhotos = !empty($detail['foto_receiving']) ? json_decode($detail['foto_receiving'], true) : [];
                                        if (!is_array($receivingPhotos)) $receivingPhotos = [];
                                        $notaPhotos = !empty($detail['foto_nota']) ? json_decode($detail['foto_nota'], true) : [];
                                        if (!is_array($notaPhotos)) $notaPhotos = [];
                                    ?>
                                        <div class="item-row">
                                            <div class="item-row-header">
                                                <div class="item-row-number"><?= $no++ ?></div>
                                                <div class="item-row-name"><?= htmlspecialchars($detail['nama_barang']) ?></div>
                                                <span class="item-qty-chip"><?= $detail['qty'] ?> <?= htmlspecialchars($detail['satuan']) ?></span>
                                                <?php if ($isAdmin): ?>
                                                    <div class="item-row-subtotal">Rp <?= number_format($detail['subtotal'], 0, ',', '.') ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="item-row-meta-row">
                                                <?php if ($isAdmin): ?>
                                                    <span class="item-row-harga">Rp <?= number_format($detail['harga'], 0, ',', '.') ?> / <?= htmlspecialchars($detail['satuan']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!$isAdmin): ?>
                                                    <div class="status-btn-group">
                                                        <button type="button" class="status-btn btn-lengkap <?= $currentStatus === 'lengkap' ? 'active' : '' ?>" onclick="updateStatusItem(<?= $detail['id'] ?>, 'lengkap', this)">✓ Lengkap</button>
                                                        <button type="button" class="status-btn btn-kurang <?= $currentStatus === 'kurang' ? 'active' : '' ?>" onclick="toggleKeterangan(<?= $detail['id'] ?>, this)">⚠ Kurang</button>
                                                        <button type="button" class="status-btn btn-tidak-ada <?= $currentStatus === 'tidak_ada' ? 'active' : '' ?>" onclick="updateStatusItem(<?= $detail['id'] ?>, 'tidak_ada', this)">✕ Tidak Ada</button>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="margin-left: auto;">
                                                        <?= getStatusBadge($currentStatus) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($currentStatus === 'kurang' && !empty($detail['keterangan'])): ?>
                                                <div class="keterangan-display">📝 <strong>Keterangan:</strong> <?= htmlspecialchars($detail['keterangan']) ?></div>
                                            <?php endif; ?>
                                            <div class="keterangan-area" id="keterangan-area-<?= $detail['id'] ?>">
                                                <label class="keterangan-label">📝 Keterangan (Wajib diisi saat status "Kurang"):</label>
                                                <textarea id="keterangan-input-<?= $detail['id'] ?>" placeholder="Jelaskan apa yang kurang..."><?= htmlspecialchars($detail['keterangan'] ?? '') ?></textarea>
                                                <div style="display:flex; gap:6px; margin-top:8px; justify-content:flex-end;">
                                                    <button type="button" class="btn btn-sm btn-secondary" onclick="hideKeterangan(<?= $detail['id'] ?>)" style="padding:4px 10px; font-size:11px;">Batal</button>
                                                    <button type="button" class="btn btn-sm btn-primary" onclick="submitKeterangan(<?= $detail['id'] ?>)" style="padding:4px 10px; font-size:11px;">Simpan</button>
                                                </div>
                                            </div>
                                            <div class="item-foto-icons">
                                                <div class="item-foto-icon-btn <?= !empty($receivingPhotos) ? 'has-photo' : '' ?>" title="Upload Foto Receiving">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <rect x="3" y="3" width="18" height="18" rx="2" />
                                                        <circle cx="8.5" cy="8.5" r="1.5" />
                                                        <polyline points="21 15 16 10 5 21" />
                                                    </svg>
                                                    <input type="file" accept="image/*,.pdf" onchange="uploadFotoReceivingItem(this, <?= $detail['id'] ?>)">
                                                </div>
                                                <div class="item-foto-icon-btn <?= !empty($notaPhotos) ? 'has-photo' : '' ?>" title="Upload Foto Nota">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                                                        <circle cx="12" cy="13" r="4" />
                                                    </svg>
                                                    <input type="file" accept="image/*,.pdf" onchange="uploadFotoNotaItem(this, <?= $detail['id'] ?>)">
                                                </div>
                                            </div>
                                            <?php if (!empty($receivingPhotos) || !empty($notaPhotos)): ?>
                                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e2e8f0;">
                                                    <?php if (!empty($receivingPhotos)): ?>
                                                        <div style="font-size: 10px; font-weight: 600; color: #64748b; margin-bottom: 6px;">📸 Foto Receiving (<?= count($receivingPhotos) ?>)</div>
                                                        <div class="item-foto-thumbnails">
                                                            <?php foreach ($receivingPhotos as $photo): ?>
                                                                <div class="item-foto-thumb-item">
                                                                    <img src="uploads/addcost_receiving/<?= htmlspecialchars($photo) ?>" alt="Receiving" onclick="viewFullImage('uploads/addcost_receiving/<?= htmlspecialchars($photo) ?>')">
                                                                    <button type="button" class="item-foto-thumb-delete" onclick="deleteFoto(<?= $detail['id'] ?>, 'receiving', '<?= htmlspecialchars($photo) ?>')" title="Hapus">
                                                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                                                        </svg>
                                                                    </button>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($notaPhotos)): ?>
                                                        <div style="font-size: 10px; font-weight: 600; color: #64748b; margin-bottom: 6px; margin-top: 8px;">📄 Foto Nota (<?= count($notaPhotos) ?>)</div>
                                                        <div class="item-foto-thumbnails">
                                                            <?php foreach ($notaPhotos as $photo): ?>
                                                                <div class="item-foto-thumb-item">
                                                                    <img src="uploads/addcost_nota/<?= htmlspecialchars($photo) ?>" alt="Nota" onclick="viewFullImage('uploads/addcost_nota/<?= htmlspecialchars($photo) ?>')">
                                                                    <button type="button" class="item-foto-thumb-delete" onclick="deleteFoto(<?= $detail['id'] ?>, 'nota', '<?= htmlspecialchars($photo) ?>')" title="Hapus">
                                                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                                                        </svg>
                                                                    </button>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($isAdmin): ?>
                                                <div class="item-row-actions">
                                                    <div class="action-group action-group-end">
                                                        <button type="button" class="action-btn action-btn-edit" onclick="openEditItemAddcost(this)"
                                                            data-id="<?= $detail['id'] ?>"
                                                            data-nama="<?= htmlspecialchars($detail['nama_barang']) ?>"
                                                            data-harga="<?= $detail['harga'] ?>"
                                                            data-qty="<?= $detail['qty'] ?>"
                                                            data-satuan="<?= htmlspecialchars($detail['satuan']) ?>"
                                                            data-pembelian-add-id="<?= $detail['pembelian_add_id'] ?>"
                                                            title="Edit Item">
                                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                                                            </svg>
                                                        </button>
                                                        <a href="?delete_detail=<?= $detail['id'] ?>" class="action-btn action-btn-delete" onclick="return confirm('Yakin ingin menghapus item ini?')" title="Hapus Item">
                                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <polyline points="3 6 5 6 21 6" />
                                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                                <line x1="10" y1="11" x2="10" y2="17" />
                                                                <line x1="14" y1="11" x2="14" y2="17" />
                                                            </svg>
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($addcost['details'])): ?>
                                        <div class="item-list-empty">Belum ada item barang</div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($addcost['details'])): ?>
                                    <div class="item-list-footer">
                                        <?php if ($isAdmin): ?>
                                            <span>Total Add Cost</span>
                                            <strong>Rp <?= number_format($totalAddcost, 0, ',', '.') ?></strong>
                                        <?php else: ?>
                                            <span>Total Item</span>
                                            <strong><?= count($addcost['details']) ?> item</strong>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ringkasan-status-box">
                                        <div class="ringkasan-status-header">
                                            <div class="ringkasan-status-title">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                                    <polyline points="14 2 14 8 20 8" />
                                                    <line x1="16" y1="13" x2="8" y2="13" />
                                                    <line x1="16" y1="17" x2="8" y2="17" />
                                                </svg>
                                                Ringkasan Status Penerimaan
                                            </div>
                                            <div class="ringkasan-status-badges">
                                                <?php
                                                $itemLengkap = 0;
                                                $itemKurang = 0;
                                                $itemTidakAda = 0;
                                                $itemBelum = 0;
                                                foreach ($addcost['details'] as $d) {
                                                    $s = $d['status'] ?? 'belum_cek';
                                                    if ($s === 'lengkap') $itemLengkap++;
                                                    elseif ($s === 'kurang') $itemKurang++;
                                                    elseif ($s === 'tidak_ada') $itemTidakAda++;
                                                    else $itemBelum++;
                                                }
                                                ?>
                                                <?php if ($itemLengkap > 0): ?>
                                                    <span class="ringkasan-badge lengkap">✓ <?= $itemLengkap ?></span>
                                                <?php endif; ?>
                                                <?php if ($itemKurang > 0): ?>
                                                    <span class="ringkasan-badge kurang">⚠ <?= $itemKurang ?></span>
                                                <?php endif; ?>
                                                <?php if ($itemTidakAda > 0): ?>
                                                    <span class="ringkasan-badge tidak-ada">✕ <?= $itemTidakAda ?></span>
                                                <?php endif; ?>
                                                <?php if ($itemBelum > 0): ?>
                                                    <span class="ringkasan-badge belum">? <?= $itemBelum ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="ringkasan-status-desc">
                                            <?= generateRingkasanText([$addcost]) ?>
                                        </div>
                                        <div class="ringkasan-status-note">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="10" />
                                                <line x1="12" y1="16" x2="12" y2="12" />
                                                <line x1="12" y1="8" x2="12.01" y2="8" />
                                            </svg>
                                            Di-generate otomatis dari status per-item
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php $firstDate = false;
            endforeach; ?>
        <?php endif; ?>
    </main>

    <?php if ($isAdmin): ?>
        <div class="modal-overlay" id="modalAdd">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h2><?= icon('plus', 20) ?> Input Add Cost</h2>
                    <button class="close-modal" onclick="closeModal('modalAdd')"><?= icon('x', 20) ?></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formAddcost">
                    <div class="form-section">
                        <h3 class="section-title">Informasi Dapur</h3>

                        <!-- ✅ DROPDOWN LOKASI DAPUR (BARU!) -->
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label>Pilih Dapur (Gudang Tujuan) <span class="required">*</span></label>
                            <select name="lokasi" class="form-control" required style="font-weight:600;">
                                <option value="">-- Pilih Dapur --</option>
                                <?php foreach ($LOKASI_LIST as $key => $label): ?>
                                    <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">
                                Data ini hanya akan muncul untuk operator dapur yang dipilih
                            </small>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Tanggal <span class="required">*</span></label>
                                <input type="date" name="tanggal" class="form-control" required value="<?= date('Y-m-d') ?>" onchange="updateNoFakturAddcost()">
                            </div>
                            <div class="form-group">
                                <label>No Faktur <small style="color:var(--muted);">(Otomatis)</small></label>
                                <input type="text" name="no_faktur" id="noFakturAddcost" class="form-control" readonly style="background:#f1f5f9; font-weight:600; color:var(--primary);">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nama Supplier/Dapur <span class="required">*</span></label>
                                <input type="text" name="nama_supplier" class="form-control" placeholder="Contoh: SPPG Sodonghilir 2" required>
                            </div>
                            <div class="form-group">
                                <label>No Kontak</label>
                                <input type="text" name="no_kontak" class="form-control" placeholder="08xxxxxxxxxx">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Alamat</label>
                            <textarea name="alamat_dapur" class="form-control" rows="2" placeholder="Alamat lengkap..."></textarea>
                        </div>
                    </div>
                    <div class="form-section">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                            <h3 class="section-title" style="margin-bottom:0;border:none;padding:0;">Detail Item Biaya</h3>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addRowAddcost()"><?= icon('plus', 14) ?> <span>Tambah Baris</span></button>
                        </div>
                        <div class="table-responsive">
                            <table class="form-table" id="tableAddcostItem">
                                <thead>
                                    <tr>
                                        <th style="width:35%">Nama Barang</th>
                                        <th style="width:15%">Harga</th>
                                        <th style="width:12%">QTY</th>
                                        <th style="width:12%">Satuan</th>
                                        <th style="width:16%">Subtotal</th>
                                        <th style="width:10%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('modalAdd')"><?= icon('x', 14) ?> <span>Batal</span></button>
                        <button type="submit" name="save_addcost" class="btn btn-primary btn-lg"><?= icon('save', 16) ?> <span>Simpan Add Cost</span></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-overlay" id="modalEditAddcost">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h2>Edit Item Add Cost</h2>
                    <button class="close-modal" onclick="closeModal('modalEditAddcost')"><?= icon('x', 20) ?></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="id_detail" id="edit_id_detail">
                    <input type="hidden" name="pembelian_add_id" id="edit_pembelian_add_id">
                    <div class="form-section">
                        <div class="form-group">
                            <label>Nama Barang</label>
                            <input type="text" name="nama_barang" id="edit_nama_barang" class="form-control" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Harga</label>
                                <input type="number" name="harga" id="edit_harga" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Satuan</label>
                                <input type="text" name="satuan" id="edit_satuan" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>QTY</label>
                            <input type="number" name="qty" id="edit_qty" class="form-control" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditAddcost')">Batal</button>
                        <button type="submit" name="update_detail" class="btn btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="full-image-modal" id="fullImageModal" onclick="closeFullImage()">
        <span class="full-image-close"><?= icon('x', 30) ?></span>
        <img id="fullImage" src="" alt="Full">
    </div>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p id="loadingText">Memproses...</p>
    </div>

    <script src="../script.js"></script>
    <script src="script.js"></script>
</body>

</html>