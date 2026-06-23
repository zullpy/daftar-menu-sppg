<?php
session_start();
require_once '../database/koneksi.php';

// RBAC: HANYA ADMIN
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$pengiriman = null;
$details = [];

// Mode Edit: ambil data
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM pengiriman WHERE id = ?");
    $stmt->execute([$edit_id]);
    $pengiriman = $stmt->fetch();

    if (!$pengiriman) {
        header("Location: index.php");
        exit;
    }

    $stmt_d = $pdo->prepare("SELECT * FROM detail_pengiriman WHERE pengiriman_id = ?");
    $stmt_d->execute([$edit_id]);
    $details = $stmt_d->fetchAll();
}

// Generate no faktur default
$no_faktur_default = 'FTR-' . date('Ymd') . '-' . rand(1000, 9999);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $no_faktur = trim($_POST['no_faktur']);
        $nama_penerima = trim($_POST['nama_penerima']);
        $alamat = trim($_POST['alamat']);
        $tanggal_ekspedisi = $_POST['tanggal_ekspedisi'];

        if ($edit_id) {
            // UPDATE
            $stmt = $pdo->prepare("UPDATE pengiriman SET 
                                   no_faktur=?, nama_penerima=?, alamat=?, tanggal_ekspedisi=?
                                   WHERE id=?");
            $stmt->execute([$no_faktur, $nama_penerima, $alamat, $tanggal_ekspedisi, $edit_id]);

            // Hapus detail lama
            $pdo->prepare("DELETE FROM detail_pengiriman WHERE pengiriman_id = ?")->execute([$edit_id]);
            $pengiriman_id = $edit_id;
        } else {
            // INSERT
            $stmt = $pdo->prepare("INSERT INTO pengiriman (no_faktur, nama_penerima, alamat, tanggal_ekspedisi) 
                                   VALUES (?, ?, ?, ?)");
            $stmt->execute([$no_faktur, $nama_penerima, $alamat, $tanggal_ekspedisi]);
            $pengiriman_id = $pdo->lastInsertId();
        }

        // Insert detail barang
        $nama_barangs = $_POST['nama_barang'];
        $qtys = $_POST['qty'];
        $satuans = $_POST['satuan'];
        $keterangans = $_POST['keterangan'];

        $stmt_detail = $pdo->prepare("INSERT INTO detail_pengiriman 
                                     (pengiriman_id, nama_barang, qty, satuan, keterangan) 
                                     VALUES (?, ?, ?, ?, ?)");

        for ($i = 0; $i < count($nama_barangs); $i++) {
            if (!empty(trim($nama_barangs[$i]))) {
                $stmt_detail->execute([
                    $pengiriman_id,
                    trim($nama_barangs[$i]),
                    (int)$qtys[$i],
                    trim($satuans[$i]),
                    trim($keterangans[$i] ?? '')
                ]);
            }
        }

        $pdo->commit();
        header("Location: index.php?msg=saved");
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
    <title><?= $edit_id ? 'Edit' : 'Tambah' ?> Pengiriman - MBG</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="container">
        <header>
            <div class="logo">
                <div class="logo-mark">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <?php if ($edit_id): ?>
                            <path d="M4 20l4-1 11-11-3-3L5 16l-1 4z" />
                            <path d="M14 4l3 3" />
                        <?php else: ?>
                            <path d="M12 5v14M5 12h14" />
                        <?php endif; ?>
                    </svg>
                </div>
                <div class="logo-text">
                    <h1><?= $edit_id ? 'Edit' : 'Tambah' ?> Pengiriman Barang</h1>
                    <span>MBG &middot; LOGISTIK</span>
                </div>
            </div>
            <nav>
                <a href="index.php" class="btn btn-secondary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5M11 18l-6-6 6-6" />
                    </svg>
                    Kembali
                </a>
            </nav>
        </header>
        <div class="header-stripe"></div>

        <main>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="formPengiriman">
                <div class="form-row">
                    <div class="form-group">
                        <label>No. Faktur *</label>
                        <input type="text" name="no_faktur" class="form-control" required
                            value="<?= $pengiriman ? htmlspecialchars($pengiriman['no_faktur']) : $no_faktur_default ?>">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Ekspedisi *</label>
                        <input type="date" name="tanggal_ekspedisi" class="form-control" required
                            value="<?= $pengiriman ? $pengiriman['tanggal_ekspedisi'] : date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Nama Penerima *</label>
                    <input type="text" name="nama_penerima" class="form-control" required
                        value="<?= $pengiriman ? htmlspecialchars($pengiriman['nama_penerima']) : '' ?>">
                </div>

                <div class="form-group">
                    <label>Alamat Pengiriman *</label>
                    <textarea name="alamat" class="form-control" rows="3" required><?= $pengiriman ? htmlspecialchars($pengiriman['alamat']) : '' ?></textarea>
                </div>

                <div class="form-section">
                    <div class="section-header">
                        <h3>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 7l9-4 9 4-9 4-9-4z" />
                                <path d="M3 7v10l9 4 9-4V7" />
                                <path d="M12 11v10" />
                            </svg>
                            Detail Barang
                        </h3>
                        <span class="total-badge" id="totalBadge">Total: 0 Item</span>
                    </div>

                    <div id="barangContainer">
                        <?php if (!empty($details)): ?>
                            <?php foreach ($details as $detail): ?>
                                <div class="barang-item">
                                    <button type="button" class="btn-remove-row" onclick="removeBarang(this)">
                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M6 6l12 12M18 6L6 18" />
                                        </svg>
                                    </button>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Nama Barang *</label>
                                            <input type="text" name="nama_barang[]" class="form-control" required
                                                value="<?= htmlspecialchars($detail['nama_barang']) ?>">
                                        </div>
                                        <div class="form-group small">
                                            <label>Qty *</label>
                                            <input type="number" name="qty[]" class="form-control qty-input" min="1" required
                                                value="<?= $detail['qty'] ?>" oninput="hitungTotal()">
                                        </div>
                                        <div class="form-group small">
                                            <label>Satuan *</label>
                                            <select name="satuan[]" class="form-control" required>
                                                <?php foreach (['Pcs', 'Box', 'Kg', 'Liter', 'Pack', 'Unit', 'Set', 'Rim'] as $s): ?>
                                                    <option value="<?= $s ?>" <?= $detail['satuan'] == $s ? 'selected' : '' ?>><?= $s ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Keterangan</label>
                                        <input type="text" name="keterangan[]" class="form-control"
                                            value="<?= htmlspecialchars($detail['keterangan']) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="barang-item">
                                <button type="button" class="btn-remove-row" onclick="removeBarang(this)">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 6l12 12M18 6L6 18" />
                                    </svg>
                                </button>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Nama Barang *</label>
                                        <input type="text" name="nama_barang[]" class="form-control" required>
                                    </div>
                                    <div class="form-group small">
                                        <label>Qty *</label>
                                        <input type="number" name="qty[]" class="form-control qty-input" min="1" required oninput="hitungTotal()">
                                    </div>
                                    <div class="form-group small">
                                        <label>Satuan *</label>
                                        <select name="satuan[]" class="form-control" required>
                                            <?php foreach (['Pcs', 'Box', 'Kg', 'Liter', 'Pack', 'Unit', 'Set', 'Rim'] as $s): ?>
                                                <option value="<?= $s ?>"><?= $s ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Keterangan</label>
                                    <input type="text" name="keterangan[]" class="form-control">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="btn btn-add" onclick="addBarang()">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        Tambah Barang
                    </button>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success btn-lg">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 4h11l3 3v13H5z" />
                            <path d="M8 4v6h8V4M8 14h8v6H8z" />
                        </svg>
                        <?= $edit_id ? 'Update' : 'Simpan' ?> Pengiriman
                    </button>
                    <a href="index.php" class="btn btn-secondary btn-lg" style="color:var(--ink-soft); border-color:var(--line-strong);">Batal</a>
                </div>
            </form>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        function addBarang() {
            const container = document.getElementById('barangContainer');
            const div = document.createElement('div');
            div.className = 'barang-item';
            div.innerHTML = `
                <button type="button" class="btn-remove-row" onclick="removeBarang(this)">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 6l12 12M18 6L6 18"/>
                    </svg>
                </button>
                <div class="form-row">
                    <div class="form-group">
                        <label>Nama Barang *</label>
                        <input type="text" name="nama_barang[]" class="form-control" required>
                    </div>
                    <div class="form-group small">
                        <label>Qty *</label>
                        <input type="number" name="qty[]" class="form-control qty-input" min="1" required oninput="hitungTotal()">
                    </div>
                    <div class="form-group small">
                        <label>Satuan *</label>
                        <select name="satuan[]" class="form-control" required>
                            <?php foreach (['Pcs', 'Box', 'Kg', 'Liter', 'Pack', 'Unit', 'Set', 'Rim'] as $s): ?>
                                <option value="<?= $s ?>"><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Keterangan</label>
                    <input type="text" name="keterangan[]" class="form-control">
                </div>
            `;
            container.appendChild(div);
        }

        function removeBarang(btn) {
            const items = document.querySelectorAll('.barang-item');
            if (items.length > 1) {
                btn.parentElement.remove();
                hitungTotal();
            } else {
                alert('Minimal harus ada 1 barang');
            }
        }

        function hitungTotal() {
            let total = 0;
            document.querySelectorAll('.qty-input').forEach(input => {
                total += parseInt(input.value) || 0;
            });
            document.getElementById('totalBadge').textContent = 'Total: ' + total + ' Item';
        }

        // Init
        hitungTotal();
    </script>
</body>

</html>