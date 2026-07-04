<?php
session_start();
require_once '../database/koneksi.php';
require_once '../database/helper-stok.php';
require_once '../database/stok_helper.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

// ═══════════════════════════════════════════════════════════
// AJAX ENDPOINT: Ambil daftar barang dari tabel `barang`
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'search_barang') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) {
        echo json_encode([]);
        exit;
    }
    try {
        $stmt = $pdo_draft->prepare(
            "SELECT id_barang, nama_barang, stok_akhir, satuan 
             FROM barang 
             WHERE nama_barang LIKE ? 
             ORDER BY nama_barang LIMIT 15"
        );
        $stmt->execute(["%$q%"]);
        $results = $stmt->fetchAll();

        // Format output
        $output = [];
        foreach ($results as $row) {
            $output[] = [
                'id'         => $row['id_barang'],
                'nama_barang' => trim($row['nama_barang']),
                'stok'       => (int)$row['stok_akhir'],
                'satuan'     => trim($row['satuan'] ?: 'Pcs')
            ];
        }
        echo json_encode($output);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// AJAX ENDPOINT: Ambil stok per nama barang spesifik
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_stok') {
    header('Content-Type: application/json');
    $nama = trim($_GET['nama'] ?? '');
    if ($nama === '') {
        echo json_encode(['stok' => 0, 'satuan' => '-', 'found' => false]);
        exit;
    }
    try {
        $stmt = $pdo_draft->prepare(
            "SELECT stok_akhir, satuan FROM barang WHERE nama_barang = ?"
        );
        $stmt->execute([$nama]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode([
                'stok'   => (int)$row['stok_akhir'],
                'satuan' => trim($row['satuan'] ?: 'Pcs'),
                'found'  => true
            ]);
        } else {
            echo json_encode(['stok' => 0, 'satuan' => '-', 'found' => false]);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// GENERATE NO. SURAT JALAN
// ═══════════════════════════════════════════════════════════
function generateNoSuratJalan($pdo, $tanggal = null)
{
    if (!$tanggal) $tanggal = date('Y-m-d');
    $tgl_format = date('Ymd', strtotime($tanggal));
    $stmt = $pdo->prepare("SELECT no_surat_jalan FROM pengiriman
        WHERE no_surat_jalan LIKE 'SP____-_____%'
        ORDER BY no_surat_jalan DESC LIMIT 1");
    $stmt->execute();
    $last = $stmt->fetchColumn();
    if ($last) {
        preg_match('/SP(\d{4})-/', $last, $matches);
        $new_num = (int)$matches[1] + 1;
    } else {
        $new_num = 1;
    }
    return 'SP' . str_pad($new_num, 4, '0', STR_PAD_LEFT) . '-' . $tgl_format;
}

// ═══════════════════════════════════════════════════════════
// Catatan: kurangiStokGudangPusat() & kembalikanStokGudangPusat()
// sekarang didefinisikan di ../database/helper_stok.php (dipakai
// bareng dengan index.php), dan otomatis mencatat ke `mutasi_stok`.
// ═══════════════════════════════════════════════════════════
// HANDLE EDIT MODE
// ═══════════════════════════════════════════════════════════
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$pengiriman = null;
$details = [];
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

$no_sj_default = generateNoSuratJalan($pdo);

// ═══════════════════════════════════════════════════════════
// HANDLE FORM SUBMIT
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        $pdo_draft->beginTransaction();

        $no_surat_jalan    = trim($_POST['no_surat_jalan']);
        $nama_sppg         = trim($_POST['nama_sppg']);
        $nama_pengirim     = trim($_POST['nama_pengirim']);
        $alamat            = trim($_POST['alamat']);
        $tanggal_ekspedisi = $_POST['tanggal_ekspedisi'];
        $ekspedisi         = trim($_POST['ekspedisi']);
        $lokasi            = $_POST['lokasi'] ?? 'semua';

        if (!preg_match('/^SP\d{4}-\d{8}$/', $no_surat_jalan)) {
            throw new Exception("Format No. Surat Jalan harus: SPxxxx-YYYYMMDD");
        }

        if ($edit_id) {
            // ── MODE EDIT: kembalikan stok Gudang Pusat lama dulu ──
            $old_details = $pdo->prepare("SELECT nama_barang, qty FROM detail_pengiriman WHERE pengiriman_id = ?");
            $old_details->execute([$edit_id]);
            foreach ($old_details->fetchAll() as $old) {
                kembalikanStokGudangPusat($pdo_draft, $old['nama_barang'], $old['qty'], "Edit ulang pengiriman No. $no_surat_jalan - stok lama dikembalikan");
            }

            // ═══════════════════════════════════════════════════════
            // ✅ FIX BUG: kalau pengiriman ini SUDAH pernah dikonfirmasi
            // diterima, batalkan dulu efek stoknya di `stok_barang`
            // (gudang tujuan) & hapus data penerimaan lama tsb.
            // Wajib dilakukan SEBELUM detail_pengiriman lama dihapus,
            // karena detail_pengiriman akan dibuat ulang dengan id baru
            // (DELETE + INSERT di bawah), sehingga detail_penerimaan lama
            // yang masih menunjuk ke id lama jadi basi/orphan dan stok
            // yang sudah masuk tidak akan pernah dibalik.
            // ═══════════════════════════════════════════════════════
            $lokasi_lama = $pengiriman['lokasi'] ?? 'semua';
            $stmt_pr_check = $pdo->prepare("SELECT id FROM penerimaan WHERE pengiriman_id = ? LIMIT 1");
            $stmt_pr_check->execute([$edit_id]);
            $penerimaan_lama = $stmt_pr_check->fetch();

            if ($penerimaan_lama) {
                $penerimaan_id_lama = $penerimaan_lama['id'];
                $stmt_dp_lama = $pdo->prepare("SELECT dpr.qty_diterima, dp.nama_barang, dp.satuan
                    FROM detail_penerimaan dpr
                    JOIN detail_pengiriman dp ON dp.id = dpr.detail_pengiriman_id
                    WHERE dpr.penerimaan_id = ?");
                $stmt_dp_lama->execute([$penerimaan_id_lama]);
                foreach ($stmt_dp_lama->fetchAll() as $dp_lama) {
                    if ($dp_lama['qty_diterima'] === null || (float)$dp_lama['qty_diterima'] == 0) continue;
                    // Balikin (kurangi) stok tujuan yang sudah pernah ditambah waktu konfirmasi
                    stok_upsertGrosir($pdo, $dp_lama['nama_barang'], $dp_lama['satuan'], $lokasi_lama, -1 * $dp_lama['qty_diterima']);
                }
                // Hapus data penerimaan lama -> operator WAJIB konfirmasi ulang setelah pengiriman diedit
                $pdo->prepare("DELETE FROM detail_penerimaan WHERE penerimaan_id = ?")->execute([$penerimaan_id_lama]);
                $pdo->prepare("DELETE FROM penerimaan WHERE id = ?")->execute([$penerimaan_id_lama]);
            }

            $stmt = $pdo->prepare("UPDATE pengiriman SET
                no_surat_jalan=?, nama_sppg=?, nama_pengirim=?, alamat=?,
                tanggal_ekspedisi=?, ekspedisi=?, lokasi=?
                WHERE id=?");
            $stmt->execute([
                $no_surat_jalan,
                $nama_sppg,
                $nama_pengirim,
                $alamat,
                $tanggal_ekspedisi,
                $ekspedisi,
                $lokasi,
                $edit_id
            ]);
            $pdo->prepare("DELETE FROM detail_pengiriman WHERE pengiriman_id = ?")->execute([$edit_id]);
            $pengiriman_id = $edit_id;
        } else {
            $cek = $pdo->prepare("SELECT id FROM pengiriman WHERE no_surat_jalan = ?");
            $cek->execute([$no_surat_jalan]);
            if ($cek->fetch()) {
                throw new Exception("No. Surat Jalan <strong>$no_surat_jalan</strong> sudah digunakan.");
            }
            $stmt = $pdo->prepare("INSERT INTO pengiriman
                (no_surat_jalan, nama_sppg, nama_pengirim, alamat, tanggal_ekspedisi, ekspedisi, lokasi)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $no_surat_jalan,
                $nama_sppg,
                $nama_pengirim,
                $alamat,
                $tanggal_ekspedisi,
                $ekspedisi,
                $lokasi
            ]);
            $pengiriman_id = $pdo->lastInsertId();
        }

        // ── Simpan detail & KURANGI STOK ──
        $nama_barangs = $_POST['nama_barang'];
        $qtys         = $_POST['qty'];
        $satuans      = $_POST['satuan'];
        $keterangans  = $_POST['keterangan'];

        $stmt_detail = $pdo->prepare("INSERT INTO detail_pengiriman
            (pengiriman_id, nama_barang, qty, satuan, keterangan) VALUES (?, ?, ?, ?, ?)");

        for ($i = 0; $i < count($nama_barangs); $i++) {
            $nama_b = trim($nama_barangs[$i]);
            if (!empty($nama_b)) {
                $qty_b   = (int)$qtys[$i];
                $satuan_b = trim($satuans[$i]);
                $ket_b   = trim($keterangans[$i] ?? '');

                $stmt_detail->execute([$pengiriman_id, $nama_b, $qty_b, $satuan_b, $ket_b]);

                // ✅ KURANGI STOK GUDANG PUSAT + catat mutasi keluar
                kurangiStokGudangPusat($pdo_draft, $nama_b, $qty_b, "Pengiriman No. $no_surat_jalan");
            }
        }

        $pdo->commit();
        $pdo_draft->commit();
        header("Location: index.php?msg=saved");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $pdo_draft->rollBack();
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
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="container">
        <header>
            <div class="logo">
                <div class="logo-mark">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
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
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <div class="info-card" style="border-left-color: var(--amber);">
                <h3>
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    Info Stok Gudang Pusat
                </h3>
                <p>Ketika Anda mengirim barang ke gudang transit (Sodong/Sariwangi/Manonjaya),
                    <strong>stok di Gudang Pusat akan otomatis berkurang</strong> sesuai qty yang dikirim.
                    Ketik nama barang untuk melihat stok tersedia.
                </p>
            </div>

            <form method="POST" action="" id="formPengiriman">
                <div class="form-row">
                    <div class="form-group">
                        <label>No. Surat Jalan *</label>
                        <input type="text" name="no_surat_jalan" id="no_sj" class="form-control" required readonly
                            pattern="SP\d{4}-\d{8}" title="Format: SP0001-20260623"
                            value="<?= $pengiriman ? htmlspecialchars($pengiriman['no_surat_jalan']) : $no_sj_default ?>">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Ekspedisi *</label>
                        <input type="date" name="tanggal_ekspedisi" id="tgl_ekspedisi" class="form-control" required
                            value="<?= $pengiriman ? $pengiriman['tanggal_ekspedisi'] : date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Lokasi Dapur / SPPG *</label>
                        <select name="lokasi" class="form-control" required>
                            <option value="sodong" <?= ($pengiriman && $pengiriman['lokasi'] == 'sodong') ? 'selected' : '' ?>>Dapur Sodong</option>
                            <option value="sariwangi" <?= ($pengiriman && $pengiriman['lokasi'] == 'sariwangi') ? 'selected' : '' ?>>Dapur Sariwangi</option>
                            <option value="manonjaya" <?= ($pengiriman && $pengiriman['lokasi'] == 'manonjaya') ? 'selected' : '' ?>>Dapur Manonjaya</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama SPPG (Tujuan) *</label>
                        <input type="text" name="nama_sppg" class="form-control" required
                            placeholder="Contoh: SPPG Sodonghilir"
                            value="<?= $pengiriman ? htmlspecialchars($pengiriman['nama_sppg']) : '' ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nama Pengirim *</label>
                        <input type="text" name="nama_pengirim" class="form-control" required
                            placeholder="Contoh: Budi Santoso"
                            value="<?= $pengiriman ? htmlspecialchars($pengiriman['nama_pengirim']) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Ekspedisi / Armada *</label>
                        <input type="text" name="ekspedisi" class="form-control" required
                            placeholder="Contoh: Kolbak Hitam, Grandmax Putih B 1234 XYZ"
                            value="<?= $pengiriman ? htmlspecialchars($pengiriman['ekspedisi'] ?? '') : '' ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Alamat Pengiriman *</label>
                    <textarea name="alamat" class="form-control" rows="3" required><?= $pengiriman ? htmlspecialchars($pengiriman['alamat']) : '' ?></textarea>
                </div>

                <div class="form-section">
                    <div class="section-header">
                        <h3>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M3 7l9-4 9 4-9 4-9-4z" />
                                <path d="M3 7v10l9 4 9-4V7" />
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
                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M6 6l12 12M18 6L6 18" />
                                        </svg>
                                    </button>
                                    <div class="form-row">
                                        <div class="form-group autocomplete-wrapper">
                                            <label>Nama Barang *</label>
                                            <input type="text" name="nama_barang[]" class="form-control input-nama-barang" required
                                                autocomplete="off"
                                                value="<?= htmlspecialchars($detail['nama_barang']) ?>">
                                            <div class="autocomplete-dropdown"></div>
                                            <div class="stok-info" style="display:none;">
                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                    <path d="M20 7L12 3 4 7l8 4 8-4z" />
                                                    <path d="M4 7v10l8 4 8-4V7" />
                                                </svg>
                                                <span class="stok-text"></span>
                                            </div>
                                        </div>
                                        <div class="form-group small">
                                            <label>Qty *</label>
                                            <input type="number" name="qty[]" class="form-control qty-input" min="1" required
                                                value="<?= $detail['qty'] ?>" oninput="hitungTotal()">
                                        </div>
                                        <div class="form-group small">
                                            <label>Satuan *</label>
                                            <input type="text" name="satuan[]" class="form-control input-satuan" required
                                                placeholder="Pcs / Box / Kg"
                                                value="<?= htmlspecialchars($detail['satuan']) ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Keterangan</label>
                                        <input type="text" name="keterangan[]" class="form-control"
                                            placeholder="1 dus isi 24"
                                            value="<?= htmlspecialchars($detail['keterangan']) ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="barang-item">
                                <button type="button" class="btn-remove-row" onclick="removeBarang(this)">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M6 6l12 12M18 6L6 18" />
                                    </svg>
                                </button>
                                <div class="form-row">
                                    <div class="form-group autocomplete-wrapper">
                                        <label>Nama Barang *</label>
                                        <input type="text" name="nama_barang[]" class="form-control input-nama-barang" required autocomplete="off">
                                        <div class="autocomplete-dropdown"></div>
                                        <div class="stok-info" style="display:none;">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <path d="M20 7L12 3 4 7l8 4 8-4z" />
                                                <path d="M4 7v10l8 4 8-4V7" />
                                            </svg>
                                            <span class="stok-text"></span>
                                        </div>
                                    </div>
                                    <div class="form-group small">
                                        <label>Qty *</label>
                                        <input type="number" name="qty[]" class="form-control qty-input" min="1" required oninput="hitungTotal()">
                                    </div>
                                    <div class="form-group small">
                                        <label>Satuan *</label>
                                        <input type="text" name="satuan[]" class="form-control input-satuan" required placeholder="Pcs / Box / Kg">
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
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        Tambah Barang
                    </button>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success btn-lg">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
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

    <!-- CONFIRM MODAL (pengganti confirm() bawaan browser) -->
    <div id="confirmModalOverlay" class="modal-overlay" data-type="warning">
        <div class="modal-box">
            <div class="modal-icon-wrap"></div>
            <h3 id="confirmModalTitle" class="modal-title">Perhatian</h3>
            <div id="confirmModalBody" class="modal-body"></div>
            <div class="modal-footer">
                <button type="button" id="confirmModalCancel" class="btn btn-outline">Batal</button>
                <button type="button" id="confirmModalOk" class="btn btn-warning">Lanjutkan</button>
            </div>
        </div>
    </div>

    <!-- TOAST CONTAINER -->
    <div id="toastContainer" class="toast-container"></div>

    <script src="script.js"></script>
    <script>
        // ============================================
        // TOAST NOTIFICATION
        // ============================================
        function showToast(message, type = 'info', duration = 4000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const icons = {
                info: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
                success: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>',
                warning: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                danger: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
            };

            toast.innerHTML = `${icons[type] || icons.info}<span>${message}</span>`;
            container.appendChild(toast);

            setTimeout(() => toast.classList.add('show'), 10);

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 400);
            }, duration);
        }

        // ============================================
        // CONFIRM MODAL (pengganti confirm() bawaan)
        // ============================================
        function showConfirmModal(bodyHtml, opts = {}) {
            const {
                title = 'Perhatian', type = 'warning', okText = 'Lanjutkan', cancelText = 'Batal'
            } = opts;
            const overlay = document.getElementById('confirmModalOverlay');
            const iconWrap = overlay.querySelector('.modal-icon-wrap');
            const okBtn = document.getElementById('confirmModalOk');
            const cancelBtn = document.getElementById('confirmModalCancel');

            const icons = {
                warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                danger: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
            };

            overlay.setAttribute('data-type', type);
            iconWrap.innerHTML = icons[type] || icons.warning;
            document.getElementById('confirmModalTitle').textContent = title;
            document.getElementById('confirmModalBody').innerHTML = bodyHtml;
            okBtn.textContent = okText;
            cancelBtn.textContent = cancelText;

            return new Promise(resolve => {
                function cleanup(result) {
                    overlay.classList.remove('show');
                    okBtn.removeEventListener('click', onOk);
                    cancelBtn.removeEventListener('click', onCancel);
                    overlay.removeEventListener('click', onOverlay);
                    document.removeEventListener('keydown', onKey);
                    resolve(result);
                }

                function onOk() {
                    cleanup(true);
                }

                function onCancel() {
                    cleanup(false);
                }

                function onOverlay(e) {
                    if (e.target === overlay) cleanup(false);
                }

                function onKey(e) {
                    if (e.key === 'Escape') cleanup(false);
                }

                okBtn.addEventListener('click', onOk);
                cancelBtn.addEventListener('click', onCancel);
                overlay.addEventListener('click', onOverlay);
                document.addEventListener('keydown', onKey);

                overlay.classList.add('show');
            });
        }

        // ============================================
        // AUTOCOMPLETE NAMA BARANG + STOK INFO
        // ============================================
        let currentStokMap = {};

        function setupAutocomplete(input) {
            const wrapper = input.closest('.autocomplete-wrapper');
            const dropdown = wrapper.querySelector('.autocomplete-dropdown');
            const stokInfo = wrapper.querySelector('.stok-info');
            const stokText = stokInfo.querySelector('.stok-text');
            const barangItem = input.closest('.barang-item');
            const satuanInput = barangItem.querySelector('.input-satuan');

            let debounceTimer;

            input.addEventListener('input', function() {
                const val = this.value.trim();
                clearTimeout(debounceTimer);

                if (val.length < 1) {
                    dropdown.innerHTML = '';
                    dropdown.classList.remove('show');
                    stokInfo.style.display = 'none';
                    return;
                }

                debounceTimer = setTimeout(() => {
                    fetch(`tambah.php?action=search_barang&q=${encodeURIComponent(val)}`)
                        .then(r => r.json())
                        .then(data => {
                            dropdown.innerHTML = '';
                            if (!data || data.length === 0) {
                                dropdown.classList.remove('show');
                                return;
                            }
                            data.forEach(item => {
                                const div = document.createElement('div');
                                div.className = 'autocomplete-item';
                                const stokClass = item.stok <= 0 ? 'stok-habis' : (item.stok < 20 ? 'stok-menipis' : 'stok-aman');
                                div.innerHTML = `
                            <div class="ac-main">
                                <strong>${escapeHtml(item.nama_barang)}</strong>
                                <small>${escapeHtml(item.satuan)}</small>
                            </div>
                            <div class="ac-stok ${stokClass}">
                                <span class="stok-num">${item.stok}</span>
                                <span class="stok-label">tersedia</span>
                            </div>
                        `;
                                div.addEventListener('click', () => {
                                    input.value = item.nama_barang;
                                    if (satuanInput) satuanInput.value = item.satuan;
                                    dropdown.innerHTML = '';
                                    dropdown.classList.remove('show');

                                    currentStokMap[input.name] = item.stok;

                                    stokText.textContent = `Stok Gudang Pusat: ${item.stok} ${item.satuan}`;
                                    stokInfo.className = 'stok-info ' + stokClass;
                                    stokInfo.style.display = 'flex';

                                    if (item.stok <= 0) {
                                        showToast(`⚠️ <strong>${escapeHtml(item.nama_barang)}</strong> stok HABIS di Gudang Pusat!`, 'danger', 5000);
                                    } else if (item.stok < 20) {
                                        showToast(`Stok <strong>${escapeHtml(item.nama_barang)}</strong>: <strong>${item.stok} ${item.satuan}</strong> (menipis!)`, 'warning', 4500);
                                    } else {
                                        showToast(`✓ Stok <strong>${escapeHtml(item.nama_barang)}</strong>: <strong>${item.stok} ${item.satuan}</strong> tersedia`, 'success', 3500);
                                    }
                                });
                                dropdown.appendChild(div);
                            });
                            dropdown.classList.add('show');
                        })
                        .catch(err => console.error(err));
                }, 250);
            });

            input.addEventListener('blur', function() {
                setTimeout(() => {
                    dropdown.classList.remove('show');
                    const val = this.value.trim();
                    if (val) fetchStokInfo(val, stokInfo, stokText, input);
                }, 200);
            });

            if (input.value.trim()) {
                fetchStokInfo(input.value.trim(), stokInfo, stokText, input);
            }
        }

        function fetchStokInfo(nama, stokInfo, stokText, input) {
            fetch(`tambah.php?action=get_stok&nama=${encodeURIComponent(nama)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.found) {
                        currentStokMap[input.name] = data.stok;
                        const stokClass = data.stok <= 0 ? 'stok-habis' : (data.stok < 20 ? 'stok-menipis' : 'stok-aman');
                        stokText.textContent = `Stok Gudang Pusat: ${data.stok} ${data.satuan}`;
                        stokInfo.className = 'stok-info ' + stokClass;
                        stokInfo.style.display = 'flex';
                    } else {
                        stokInfo.style.display = 'none';
                        delete currentStokMap[input.name];
                    }
                });
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function initAllAutocomplete() {
            document.querySelectorAll('.input-nama-barang').forEach(setupAutocomplete);
        }
        initAllAutocomplete();

        // ============================================
        // DETAIL BARANG
        // ============================================
        function addBarang() {
            const container = document.getElementById('barangContainer');
            const div = document.createElement('div');
            div.className = 'barang-item';
            div.innerHTML = `
        <button type="button" class="btn-remove-row" onclick="removeBarang(this)">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
        <div class="form-row">
            <div class="form-group autocomplete-wrapper">
                <label>Nama Barang *</label>
                <input type="text" name="nama_barang[]" class="form-control input-nama-barang" required autocomplete="off">
                <div class="autocomplete-dropdown"></div>
                <div class="stok-info" style="display:none;">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M20 7L12 3 4 7l8 4 8-4z"/>
                        <path d="M4 7v10l8 4 8-4V7"/>
                    </svg>
                    <span class="stok-text"></span>
                </div>
            </div>
            <div class="form-group small">
                <label>Qty *</label>
                <input type="number" name="qty[]" class="form-control qty-input" min="1" required oninput="hitungTotal()">
            </div>
            <div class="form-group small">
                <label>Satuan *</label>
                <input type="text" name="satuan[]" class="form-control input-satuan" required placeholder="Pcs / Box / Kg">
            </div>
        </div>
        <div class="form-group">
            <label>Keterangan</label>
            <input type="text" name="keterangan[]" class="form-control">
        </div>
    `;
            container.appendChild(div);

            const newInput = div.querySelector('.input-nama-barang');
            setupAutocomplete(newInput);
            newInput.focus();
        }

        function removeBarang(btn) {
            const items = document.querySelectorAll('.barang-item');
            if (items.length > 1) {
                btn.parentElement.remove();
                hitungTotal();
            } else alert('Minimal harus ada 1 barang');
        }

        function hitungTotal() {
            let total = 0;
            document.querySelectorAll('.qty-input').forEach(input => total += parseInt(input.value) || 0);
            document.getElementById('totalBadge').textContent = 'Total: ' + total + ' Item';
        }
        hitungTotal();

        // ============================================
        // VALIDASI SEBELUM SUBMIT
        // ============================================
        document.getElementById('formPengiriman').addEventListener('submit', function(e) {
            const form = this;

            // Sudah dikonfirmasi lewat modal sebelumnya -> lanjutkan submit normal
            if (form.dataset.confirmedStok === 'true') {
                return;
            }

            let warnings = [];
            document.querySelectorAll('.barang-item').forEach((item, idx) => {
                const namaInput = item.querySelector('.input-nama-barang');
                const qtyInput = item.querySelector('.qty-input');
                const nama = namaInput.value.trim();
                const qty = parseInt(qtyInput.value) || 0;

                if (nama && currentStokMap[namaInput.name] !== undefined) {
                    const stok = currentStokMap[namaInput.name];
                    if (qty > stok) {
                        warnings.push(`• <strong>${escapeHtml(nama)}</strong>: kirim ${qty}, stok hanya ${stok}`);
                    }
                }
            });

            if (warnings.length > 0) {
                e.preventDefault();
                const msg = `Qty melebihi stok gudang pusat:<br>${warnings.join('<br>')}<br><br>Lanjutkan pengiriman?`;
                showConfirmModal(msg, {
                        title: 'Perhatian',
                        type: 'warning',
                        okText: 'Lanjutkan',
                        cancelText: 'Batal'
                    })
                    .then(ok => {
                        if (ok) {
                            form.dataset.confirmedStok = 'true';
                            if (form.requestSubmit) {
                                form.requestSubmit();
                            } else {
                                form.submit();
                            }
                        }
                    });
            }
        });
    </script>
</body>

</html>