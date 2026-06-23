<?php
session_start();
require_once '../database/koneksi.php';


if (!isset($_SESSION['role'])) {
    header("Location: ../index.php");
    exit;
}

// RBAC: Admin & Operator BOLEH akses
if (!in_array($_SESSION['role'], ['admin', 'operator'])) {
    header("Location: ../index.php");
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$is_operator = ($_SESSION['role'] === 'operator');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Ambil data pengiriman
$stmt = $pdo->prepare("SELECT * FROM pengiriman WHERE id = ?");
$stmt->execute([$id]);
$pengiriman = $stmt->fetch();

if (!$pengiriman) {
    header("Location: ../pengiriman/index.php"); // ← Kembali ke daftar pengiriman
    exit;
}

// Ambil detail barang + status penerimaan (jika sudah pernah dicek)
$stmt_d = $pdo->prepare("
    SELECT dp.*, 
           dpr.status_barang AS terima_status, 
           dpr.keterangan AS terima_keterangan
    FROM detail_pengiriman dp
    LEFT JOIN penerimaan pr ON pr.pengiriman_id = dp.pengiriman_id
    LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id 
                                   AND dpr.penerimaan_id = pr.id
    WHERE dp.pengiriman_id = ?
");
$stmt_d->execute([$id]);
$details = $stmt_d->fetchAll();

// Ambil data penerimaan existing (jika ada)
$stmt_pr = $pdo->prepare("SELECT * FROM penerimaan WHERE pengiriman_id = ? LIMIT 1");
$stmt_pr->execute([$id]);
$penerimaan_exist = $stmt_pr->fetch();

// =======================================================
// HANDLE SUBMIT
// =======================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Hanya operator yang bisa submit
    if (!$is_operator) {
        die("Hanya operator yang dapat melakukan konfirmasi penerimaan.");
    }

    try {
        $pdo->beginTransaction();

        $nama_penerima_barang = trim($_POST['nama_penerima_barang']);
        $tanggal_terima = $_POST['tanggal_terima'];

        // 1. Insert/Update tabel penerimaan (header)
        if ($penerimaan_exist) {
            $pdo->prepare("UPDATE penerimaan SET nama_penerima_barang = ?, tanggal_terima = ? WHERE id = ?")
                ->execute([$nama_penerima_barang, $tanggal_terima, $penerimaan_exist['id']]);
            $penerimaan_id = $penerimaan_exist['id'];

            // Hapus detail penerimaan lama (akan di-insert ulang)
            $pdo->prepare("DELETE FROM detail_penerimaan WHERE penerimaan_id = ?")->execute([$penerimaan_id]);
        } else {
            $pdo->prepare("INSERT INTO penerimaan (pengiriman_id, nama_penerima_barang, tanggal_terima) 
                           VALUES (?, ?, ?)")
                ->execute([$id, $nama_penerima_barang, $tanggal_terima]);
            $penerimaan_id = $pdo->lastInsertId();
        }

        // 2. Insert detail_penerimaan per item
        $detail_ids = $_POST['detail_id'];
        $statuses = $_POST['status_barang'];
        $keterangans = $_POST['keterangan_status'];

        $stmt_insert = $pdo->prepare("
            INSERT INTO detail_penerimaan (penerimaan_id, detail_pengiriman_id, status_barang, keterangan)
            VALUES (?, ?, ?, ?)
        ");

        for ($i = 0; $i < count($detail_ids); $i++) {
            $status = $statuses[$i] ?? null;
            $ket = trim($keterangans[$i] ?? '');

            if ($status) {
                $stmt_insert->execute([
                    $penerimaan_id,
                    (int)$detail_ids[$i],
                    $status,
                    $ket ?: null
                ]);
            }
        }

        $pdo->commit();
        header("Location: ../pengiriman/index.php?msg=saved");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Gagal menyimpan: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Penerimaan - MBG</title>
    <link rel="stylesheet" href="../pengiriman/style.css">
    <style>
        /* Status select wrapper */
        .select-wrapper {
            position: relative;
        }

        .select-wrapper .status-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 14px;
            height: 14px;
            pointer-events: none;
            display: none;
        }

        .select-wrapper.has-value select {
            padding-left: 32px;
        }

        .select-wrapper.has-value .status-icon {
            display: block;
        }

        .select-wrapper.status-ada .status-icon {
            color: var(--green);
        }

        .select-wrapper.status-kurang .status-icon {
            color: var(--orange);
        }

        .select-wrapper.status-tidak_ada .status-icon {
            color: var(--red);
        }

        .select-wrapper.status-ada select {
            border-color: var(--green);
            background: var(--green-tint);
        }

        .select-wrapper.status-kurang select {
            border-color: var(--orange);
            background: var(--orange-tint);
        }

        .select-wrapper.status-tidak_ada select {
            border-color: var(--red);
            background: var(--red-tint);
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <div class="logo">
                <div class="logo-mark">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M5 12l4 4 10-10" />
                    </svg>
                </div>
                <div class="logo-text">
                    <h1>Konfirmasi Penerimaan</h1>
                    <span>MBG &middot; <?= strtoupper($_SESSION['role']) ?></span>
                </div>
            </div>
            <nav>
                <a href="../pengiriman/index.php" class="btn btn-secondary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M19 12H5M11 18l-6-6 6-6" />
                    </svg>
                    Kembali
                </a>
            </nav>
        </header>
        <div class="header-stripe"></div>
        <main>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="info-card">
                <h3>Faktur: <?= htmlspecialchars($pengiriman['no_faktur']) ?></h3>
                <p><strong>Penerima Tujuan:</strong> <?= htmlspecialchars($pengiriman['nama_penerima']) ?></p>
                <p><strong>Alamat:</strong> <?= htmlspecialchars($pengiriman['alamat']) ?></p>
                <p><strong>Tanggal Ekspedisi:</strong> <?= date('d F Y', strtotime($pengiriman['tanggal_ekspedisi'])) ?></p>
            </div>

            <form method="POST" <?= $is_operator ? '' : 'onsubmit="return false;"' ?>>
                <div class="form-section">
                    <div class="section-header">
                        <h3>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M9 11l3 3 8-8" />
                                <path d="M20 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2h9" />
                            </svg>
                            Pengecekan Barang (Per Item)
                        </h3>
                    </div>

                    <table class="table-detail">
                        <thead>
                            <tr>
                                <th style="width: 30%">Nama Barang</th>
                                <th style="width: 10%">Qty</th>
                                <th style="width: 25%">Status Penerimaan</th>
                                <th style="width: 35%">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($details as $d): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($d['nama_barang']) ?></strong>
                                        <input type="hidden" name="detail_id[]" value="<?= $d['id'] ?>">
                                        <?php if ($d['keterangan']): ?>
                                            <div style="font-size:11px; color:var(--ink-faint); margin-top:4px;">
                                                <em>Catatan kirim: <?= htmlspecialchars($d['keterangan']) ?></em>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= $d['qty'] ?></strong> <?= htmlspecialchars($d['satuan']) ?></td>
                                    <td>
                                        <div class="select-wrapper <?= $d['terima_status'] ? 'has-value status-' . $d['terima_status'] : '' ?>">
                                            <!-- Icon: Ada (checkmark) -->
                                            <svg class="status-icon icon-ada" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20 6L9 17l-5-5" />
                                            </svg>
                                            <!-- Icon: Kurang (warning) -->
                                            <svg class="status-icon icon-kurang" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                                <line x1="12" y1="9" x2="12" y2="13" />
                                                <line x1="12" y1="17" x2="12.01" y2="17" />
                                            </svg>
                                            <!-- Icon: Tidak Ada (X) -->
                                            <svg class="status-icon icon-tidak_ada" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="18" y1="6" x2="6" y2="18" />
                                                <line x1="6" y1="6" x2="18" y2="18" />
                                            </svg>
                                            <select name="status_barang[]" class="form-control status-select" required onchange="handleStatusChange(this)">
                                                <option value="">-- Pilih Status --</option>
                                                <option value="ada" <?= ($d['terima_status'] ?? '') == 'ada' ? 'selected' : '' ?>>Ada (Lengkap)</option>
                                                <option value="kurang" <?= ($d['terima_status'] ?? '') == 'kurang' ? 'selected' : '' ?>>Kurang / Rusak</option>
                                                <option value="tidak_ada" <?= ($d['terima_status'] ?? '') == 'tidak_ada' ? 'selected' : '' ?>>Tidak Ada</option>
                                            </select>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" name="keterangan_status[]" class="form-control input-ket"
                                            placeholder="Wajib diisi jika kurang/tidak ada"
                                            value="<?= htmlspecialchars($d['terima_keterangan'] ?? '') ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-section">
                    <div class="section-header">
                        <h3>Data Penerima Fisik</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nama Penerima Barang *</label>
                            <input type="text" name="nama_penerima_barang" class="form-control" required
                                value="<?= htmlspecialchars($penerimaan_exist['nama_penerima_barang'] ?? '') ?>"
                                placeholder="Contoh: Budi Santoso">
                        </div>
                        <div class="form-group">
                            <label>Tanggal Diterima *</label>
                            <input type="datetime-local" name="tanggal_terima" class="form-control" required
                                value="<?= $penerimaan_exist['tanggal_terima'] ?? date('Y-m-d\TH:i') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <?php if ($is_operator): ?>
                        <button type="submit" class="btn btn-success btn-lg">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M5 12l4 4 10-10" />
                            </svg>
                            Simpan Konfirmasi
                        </button>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 8v4M12 16h.01" />
                            </svg>
                            Anda login sebagai <strong>Admin</strong>. Hanya Operator yang dapat melakukan konfirmasi penerimaan.
                        </div>
                    <?php endif; ?>

                    <a href="../pengiriman/index.php" class="btn btn-secondary btn-lg" style="color:var(--ink-soft); border-color:var(--line-strong);">Batal</a>
                </div>
            </form>
        </main>
    </div>
    <script>
        const SVG_ICONS = {
            ada: '.icon-ada',
            kurang: '.icon-kurang',
            tidak_ada: '.icon-tidak_ada'
        };

        function handleStatusChange(select) {
            const wrapper = select.closest('.select-wrapper');
            const val = select.value;
            const tr = select.closest('tr');
            const ketInput = tr.querySelector('.input-ket');

            // Reset wrapper classes
            wrapper.classList.remove('has-value', 'status-ada', 'status-kurang', 'status-tidak_ada');

            // Hide all icons
            wrapper.querySelectorAll('.status-icon').forEach(el => el.style.display = 'none');

            if (val) {
                wrapper.classList.add('has-value', 'status-' + val);
                const icon = wrapper.querySelector('.icon-' + val);
                if (icon) icon.style.display = 'block';
            }

            // Keterangan validation
            if (val === 'kurang' || val === 'tidak_ada') {
                ketInput.required = true;
                ketInput.style.borderColor = 'var(--orange)';
                ketInput.style.background = 'var(--orange-tint)';
                ketInput.placeholder = 'WAJIB: Jelaskan kekurangan/kerusakan...';
            } else {
                ketInput.required = false;
                ketInput.style.borderColor = '';
                ketInput.style.background = '';
                ketInput.placeholder = 'Kosongkan jika lengkap';
            }
        }

        // Init on page load
        document.querySelectorAll('.status-select').forEach(handleStatusChange);
    </script>
</body>

</html>