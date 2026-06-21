<?php
session_start();
// ====== CEK SESSION ROLE ======
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    header('Location: index.php');
    exit;
}
$role = $_SESSION['role'];
$isAdmin = ($role === 'admin');

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

    // Update total
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
        header("Location: addcost/index.php?error=unauthorized");
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

        // Update total di tabel utama
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
    $total = 0;

    if (empty($no_faktur)) {
        header("Location: addcost.php?error=no_faktur");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Insert main addcost
        $stmt = $pdo->prepare("INSERT INTO pembelian_addcost (no_faktur, nama_supplier, no_kontak, alamat_dapur, tanggal, nota, total, created_at) VALUES (?, ?, ?, ?, ?, '', ?, NOW())");
        $stmt->execute([$no_faktur, $nama_supplier, $no_kontak, $alamat_dapur, $tanggal, $total]);
        $pembelianAddId = $pdo->lastInsertId();

        // Upload nota
        if (isset($_FILES['nota_file']) && $_FILES['nota_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/nota_addcost/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileExt = strtolower(pathinfo($_FILES['nota_file']['name'], PATHINFO_EXTENSION));
            $newName = 'nota_addcost_' . date('YmdHis') . '_' . $pembelianAddId . '.' . $fileExt;

            if (move_uploaded_file($_FILES['nota_file']['tmp_name'], $uploadDir . $newName)) {
                $pdo->prepare("UPDATE pembelian_addcost SET nota = ? WHERE id = ?")->execute([$newName, $pembelianAddId]);
            }
        }

        // Handle detail items
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

            $stmtDetail = $pdo->prepare("INSERT INTO pembelian_addcost_detail (pembelian_add_id, nama_barang, harga, qty, satuan, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtDetail->execute([$pembelianAddId, $namaBarangs[$idx], $harga, $qty, $satuans[$idx], $subtotal]);
        }

        // Update total
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

// ====== UPLOAD FOTO NOTA ADDCOST (INLINE) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_nota_addcost') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $id = (int)$_POST['id_addcost'];

    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/nota_addcost/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        $fileExt = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $newName = 'nota_addcost_' . date('YmdHis') . '_' . $id . '_' . rand(1000, 9999) . '.' . $fileExt;

        if (move_uploaded_file($_FILES['foto']['tmp_name'], $uploadDir . $newName)) {
            $pdo->prepare("UPDATE pembelian_addcost SET nota = ? WHERE id = ?")->execute([$newName, $id]);
            echo json_encode(['success' => true]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Gagal upload']);
    exit;
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

// ====== AMBIL DATA ADDCOST ======
$addcostList = $pdo->query("SELECT * FROM pembelian_addcost ORDER BY tanggal DESC, created_at DESC")->fetchAll();

foreach ($addcostList as &$addcost) {
    $stmt = $pdo->prepare("SELECT * FROM pembelian_addcost_detail WHERE pembelian_add_id = ? ORDER BY nama_barang");
    $stmt->execute([$addcost['id']]);
    $addcost['details'] = $stmt->fetchAll();
}
unset($addcost);

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
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Cost - Koperasi Bina Usaha Sauyunan</title>
    <link rel="shortcut icon" href="assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>

<body>
    <main>
        <!-- Navbar Role -->
        <div class="role-navbar">
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
                    <div class="info-banner">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="16" x2="12" y2="12" />
                            <line x1="12" y1="8" x2="12.01" y2="8" />
                        </svg>
                        <span>Halo Operator! Anda bisa <strong>upload foto nota</strong> tambahan biaya.</span>
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
                <input type="text" id="searchAddcost" class="searchbar-input" placeholder="Cari nama dapur.." oninput="filterAddcost(this.value)" autocomplete="off">
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
        <?php if (isset($_GET['nota_uploaded'])): ?>
            <div class="alert alert-success"><?= icon('check', 18) ?> <span>Foto nota berhasil diupload!</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
            <div class="alert alert-error"><?= icon('alert', 18) ?> <span>Akses ditolak! Hanya admin yang bisa melakukan aksi ini.</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'no_faktur'): ?>
            <div class="alert alert-error"><?= icon('alert', 18) ?> <span>No faktur tidak valid!</span></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?= icon('alert', 18) ?> <span>Error: <?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <?php if (empty($addcostList)): ?>
            <div class="empty-state">
                <div style="text-align:center;padding:60px 20px;color:var(--muted);">
                    <div style="margin-bottom:16px;"><?= icon('empty', 64) ?></div>
                    <h3 style="margin-bottom:8px;color:var(--text);">Belum Ada Data Add Cost</h3>
                    <p><?php echo $isAdmin ? 'Silakan klik tombol "Input Add Cost" untuk menambahkan data' : 'Belum ada data tambahan biaya yang tersedia.'; ?></p>
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
                                </div>

                                <div class="menu-card-header">
                                    <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                                        <?php if (!empty($addcost['nota'])): ?>
                                            <div class="menu-thumbnail" onclick="viewFullImage('uploads/nota_addcost/<?= htmlspecialchars($addcost['nota']) ?>')">
                                                <img src="uploads/nota_addcost/<?= htmlspecialchars($addcost['nota']) ?>" alt="Nota">
                                            </div>
                                        <?php endif; ?>

                                        <label class="btn btn-primary btn-sm" style="cursor:pointer;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                <polyline points="17 8 12 3 7 8" />
                                                <line x1="12" y1="3" x2="12" y2="15" />
                                            </svg>
                                            <span><?= !empty($addcost['nota']) ? 'Upload Nota Lagi' : 'Upload Foto Nota' ?></span>
                                            <input type="file" accept="image/*,.pdf" hidden onchange="uploadNotaAddcost(this, <?= $addcost['id'] ?>)">
                                        </label>

                                        <?php if ($isAdmin): ?>
                                            <a href="?delete_addcost=<?= $addcost['id'] ?>" class="btn btn-secondary btn-sm" onclick="return confirm('Yakin ingin menghapus add cost ini? Semua item akan terhapus!')">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="3 6 5 6 21 6" />
                                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                </svg>
                                                <span>Hapus</span>
                                            </a>
                                        <?php endif; ?>

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
                                    </div>
                                </div>

                                <div class="item-list">
                                    <?php
                                    $no = 1;
                                    $totalAddcost = 0;
                                    foreach ($addcost['details'] as $detail):
                                        $totalAddcost += $detail['subtotal'];
                                    ?>
                                        <div class="item-row">
                                            <div class="item-row-main">
                                                <div class="item-row-info">
                                                    <span class="item-row-no"><?= $no++ ?></span>
                                                    <div class="item-row-text">
                                                        <div class="item-row-name-line">
                                                            <span class="item-row-name"><?= htmlspecialchars($detail['nama_barang']) ?></span>
                                                            <span class="item-qty-chip"><?= $detail['qty'] ?> <?= htmlspecialchars($detail['satuan']) ?></span>
                                                        </div>
                                                        <div class="item-row-meta">
                                                            <?php if ($isAdmin): ?>
                                                                <span class="item-row-harga">Rp <?= number_format($detail['harga'], 0, ',', '.') ?> / <?= htmlspecialchars($detail['satuan']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php if ($isAdmin): ?>
                                                    <div class="item-row-subtotal">Rp <?= number_format($detail['subtotal'], 0, ',', '.') ?></div>
                                                <?php endif; ?>
                                            </div>
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
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php $firstDate = false;
            endforeach; ?>
        <?php endif; ?>
    </main>

    <!-- Modal Tambah Add Cost (HANYA ADMIN) -->
    <?php if ($isAdmin): ?>
        <div class="modal-overlay" id="modalAdd">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h2><?= icon('plus', 20) ?> Input Add Cost</h2>
                    <button class="close-modal" onclick="closeModal('modalAdd')"><?= icon('x', 20) ?></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formAddcost">
                    <div class="form-section">
                        <h3 class="section-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                <circle cx="8.5" cy="7" r="4" />
                                <line x1="20" y1="8" x2="20" y2="14" />
                                <line x1="23" y1="11" x2="17" y2="11" />
                            </svg>Informasi Dapur</h3>
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
                                <label>Nama Dapur <span class="required">*</span></label>
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
                        <div class="form-group">
                            <label><?= icon('camera', 14) ?> Upload Foto Nota</label>
                            <input type="file" name="nota_file" class="form-control" accept="image/*,.pdf">
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

        <!-- Modal Edit Item Addcost -->
        <div class="modal-overlay" id="modalEditAddcost">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h2><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;">
                            <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                        </svg>Edit Item Add Cost</h2>
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

    <!-- Full Image Modal -->
    <div class="full-image-modal" id="fullImageModal" onclick="closeFullImage()">
        <span class="full-image-close"><?= icon('x', 30) ?></span>
        <img id="fullImage" src="" alt="Full">
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p>Memproses...</p>
    </div>

    <script src="../script.js"></script>
    <script>
        // ===== Auto No Faktur AddCost =====
        async function updateNoFakturAddcost() {
            const tglInput = document.querySelector('input[name="tanggal"]');
            const fakturInput = document.getElementById('noFakturAddcost');
            if (!tglInput.value) return;

            try {
                const res = await fetch(`addcost.php?generate_no_faktur=1&tanggal=${tglInput.value}`);
                fakturInput.value = await res.text();
            } catch (err) {
                console.error('Gagal generate no faktur:', err);
                fakturInput.value = 'Error generating';
            }
        }

        // ===== Dynamic Form Rows Addcost =====
        function addRowAddcost() {
            const rowIndex = Date.now();
            const tbody = document.querySelector('#tableAddcostItem tbody');
            const tr = document.createElement('tr');
            tr.dataset.rowIndex = rowIndex;
            tr.innerHTML = `
        <td><input type="text" name="nama_barang[${rowIndex}]" placeholder="Nama barang" required></td>
        <td><input type="number" name="harga[${rowIndex}]" class="input-harga" step="0.01" min="0" placeholder="0" required onchange="calculateRowAddcost(this)"></td>
        <td><input type="number" name="qty[${rowIndex}]" class="input-qty" step="0.01" min="0" placeholder="0" required onchange="calculateRowAddcost(this)"></td>
        <td><input type="text" name="satuan[${rowIndex}]" placeholder="pcs/kg" required></td>
        <td><input type="number" name="subtotal[${rowIndex}]" class="input-subtotal" readonly placeholder="0" style="background:#f1f5f9;font-weight:600;"></td>
        <td><input type="hidden" name="row_index[]" value="${rowIndex}"><button type="button" class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="removeRowAddcost(this)" title="Hapus"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></button></td>
    `;
            tbody.appendChild(tr);
        }

        function removeRowAddcost(btn) {
            const tbody = document.querySelector('#tableAddcostItem tbody');
            if (tbody.rows.length > 1) btn.closest('tr').remove();
            else alert('Minimal 1 item!');
        }

        function calculateRowAddcost(input) {
            const row = input.closest('tr');
            const qty = parseFloat(row.querySelector('.input-qty').value) || 0;
            const harga = parseFloat(row.querySelector('.input-harga').value) || 0;
            row.querySelector('.input-subtotal').value = (qty * harga).toFixed(2);
        }

        function openEditItemAddcost(btn) {
            document.getElementById('edit_id_detail').value = btn.dataset.id;
            document.getElementById('edit_pembelian_add_id').value = btn.dataset.pembelianAddId;
            document.getElementById('edit_nama_barang').value = btn.dataset.nama;
            document.getElementById('edit_harga').value = btn.dataset.harga;
            document.getElementById('edit_qty').value = btn.dataset.qty;
            document.getElementById('edit_satuan').value = btn.dataset.satuan;
            openModal('modalEditAddcost');
        }

        function uploadNotaAddcost(input, idAddcost) {
            const file = input.files[0];
            if (!file) return;

            const loading = document.getElementById('loadingOverlay');
            if (loading) {
                loading.innerHTML = `<div class="spinner"></div><p id="loadingText">Mengupload nota...</p>`;
                loading.classList.add('active');
            }

            const fd = new FormData();
            fd.append('action', 'add_nota_addcost');
            fd.append('id_addcost', idAddcost);
            fd.append('foto', file);

            fetch('addcost.php', {
                    method: 'POST',
                    body: fd
                })
                .then(async r => {
                    const text = await r.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Server error: ' + text.substring(0, 100));
                    }
                })
                .then(result => {
                    if (loading) loading.classList.remove('active');
                    if (!result.success) {
                        alert('❌ Gagal upload nota: ' + (result.message || 'Unknown error'));
                    } else {
                        window.location.href = 'addcost.php?nota_uploaded=1';
                    }
                })
                .catch(err => {
                    if (loading) loading.classList.remove('active');
                    alert('❌ Error: ' + err.message);
                });

            input.value = '';
        }

        function filterAddcost(query) {
            const clearBtn = document.getElementById('searchClear');
            const resultInfo = document.getElementById('searchResult');
            const q = query.trim().toLowerCase();
            clearBtn.classList.toggle('visible', q.length > 0);

            const dateGroups = document.querySelectorAll('.date-group');
            let totalVisible = 0;

            dateGroups.forEach(group => {
                const cards = group.querySelectorAll('.menu-card');
                let visibleInGroup = 0;

                cards.forEach(card => {
                    const supplier = card.querySelector('.menu-title');
                    const faktur = card.querySelector('.menu-info strong');
                    const text = (supplier ? supplier.textContent : '') + ' ' + (faktur ? faktur.textContent : '');
                    const match = q === '' || text.toLowerCase().includes(q);
                    card.style.display = match ? '' : 'none';
                    if (match) visibleInGroup++;
                });

                const isGroupVisible = q === '' || visibleInGroup > 0;
                group.style.display = isGroupVisible ? '' : 'none';

                if (q !== '' && isGroupVisible) {
                    const header = group.querySelector('.accordion-toggle');
                    const content = group.querySelector('.date-content');
                    if (header && !header.classList.contains('open')) {
                        header.classList.add('open');
                        content.classList.add('active');
                    }
                }

                totalVisible += visibleInGroup;
            });

            if (q === '') {
                resultInfo.innerHTML = '';
            } else if (totalVisible === 0) {
                resultInfo.innerHTML = `Tidak ada data yang cocok dengan "<span class="highlight">${escapeHtml(query)}</span>"`;
            } else {
                resultInfo.innerHTML = `Ditemukan <span class="highlight">${totalVisible}</span> data untuk "<span class="highlight">${escapeHtml(query)}</span>"`;
            }
        }

        function clearSearch() {
            const input = document.getElementById('searchAddcost');
            input.value = '';
            input.focus();
            filterAddcost('');
        }

        function escapeHtml(text) {
            return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // Init
        document.addEventListener('DOMContentLoaded', () => {
            const tbody = document.querySelector('#tableAddcostItem tbody');
            if (tbody && tbody.children.length === 0) addRowAddcost();
            updateNoFakturAddcost();
        });
    </script>
</body>

</html>