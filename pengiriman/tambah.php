<?php
session_start();
require_once '../database/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $no_surat_jalan    = trim($_POST['no_surat_jalan']);
        $nama_sppg         = trim($_POST['nama_sppg']);
        $nama_pengirim     = trim($_POST['nama_pengirim']);
        $alamat            = trim($_POST['alamat']);
        $tanggal_ekspedisi = $_POST['tanggal_ekspedisi'];
        $ekspedisi         = trim($_POST['ekspedisi']);
        $ttd_pengirim      = $_POST['tanda_tangan_pengirim'] ?? null;

        // Validasi TTD pengirim wajib
        if (empty($ttd_pengirim) || $ttd_pengirim === 'data:image/png;base64,') {
            throw new Exception("Tanda tangan pengirim wajib diisi!");
        }

        if (!preg_match('/^SP\d{4}-\d{8}$/', $no_surat_jalan)) {
            throw new Exception("Format No. Surat Jalan harus: SPxxxx-YYYYMMDD");
        }

        if ($edit_id) {
            $stmt = $pdo->prepare("UPDATE pengiriman SET
                no_surat_jalan=?, nama_sppg=?, nama_pengirim=?, alamat=?, 
                tanggal_ekspedisi=?, ekspedisi=?, tanda_tangan_pengirim=?
                WHERE id=?");
            $stmt->execute([
                $no_surat_jalan,
                $nama_sppg,
                $nama_pengirim,
                $alamat,
                $tanggal_ekspedisi,
                $ekspedisi,
                $ttd_pengirim,
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
                (no_surat_jalan, nama_sppg, nama_pengirim, alamat, tanggal_ekspedisi, ekspedisi, tanda_tangan_pengirim)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $no_surat_jalan,
                $nama_sppg,
                $nama_pengirim,
                $alamat,
                $tanggal_ekspedisi,
                $ekspedisi,
                $ttd_pengirim
            ]);
            $pengiriman_id = $pdo->lastInsertId();
        }

        $nama_barangs = $_POST['nama_barang'];
        $qtys         = $_POST['qty'];
        $satuans      = $_POST['satuan'];
        $keterangans  = $_POST['keterangan'];

        $stmt_detail = $pdo->prepare("INSERT INTO detail_pengiriman 
            (pengiriman_id, nama_barang, qty, satuan, keterangan) VALUES (?, ?, ?, ?, ?)");
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
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <style>
        .signature-wrapper {
            background: #fff;
            border: 2px dashed var(--line-strong);
            border-radius: var(--radius-md);
            padding: 12px;
            position: relative;
        }

        .signature-wrapper.signed {
            border-style: solid;
            border-color: var(--green);
            background: var(--green-tint);
        }

        .signature-canvas {
            width: 100%;
            max-width: 500px;
            /* ← Batasi lebar */
            height: 180px;
            margin: 0 auto;
            /* ← Center canvas */
            display: block;
            background: #fff;
            border-radius: var(--radius-sm);
            cursor: crosshair;
            touch-action: none;
            border: 1px solid var(--line);
        }

        .signature-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            font-size: 12px;
            color: var(--ink-faint);
        }

        .btn-clear-sig {
            background: transparent;
            color: var(--red);
            border: 1px solid var(--red);
            padding: 5px 12px;
            border-radius: var(--radius-sm);
            font-size: 11.5px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-clear-sig:hover {
            background: var(--red);
            color: #fff;
        }

        .sig-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }

        .sig-status.empty {
            color: var(--orange);
        }

        .sig-status.done {
            color: var(--green);
        }
    </style>
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

            <form method="POST" action="" id="formPengiriman" onsubmit="return submitWithSignature()">
                <input type="hidden" name="tanda_tangan_pengirim" id="ttd_pengirim_input"
                    value="<?= $pengiriman ? htmlspecialchars($pengiriman['tanda_tangan_pengirim'] ?? '') : '' ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>No. Surat Jalan *</label>
                        <input type="text" name="no_surat_jalan" id="no_sj" class="form-control" required
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
                        <label>Nama SPPG (Tujuan) *</label>
                        <input type="text" name="nama_sppg" class="form-control" required
                            placeholder="Contoh: SPPG Sodonghilir"
                            value="<?= $pengiriman ? htmlspecialchars($pengiriman['nama_sppg']) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Nama Pengirim *</label>
                        <input type="text" name="nama_pengirim" class="form-control" required
                            placeholder="Contoh: Budi Santoso"
                            value="<?= $pengiriman ? htmlspecialchars($pengiriman['nama_pengirim']) : '' ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Ekspedisi / Armada *</label>
                    <input type="text" name="ekspedisi" class="form-control" required
                        placeholder="Contoh: Kolbak Hitam, Grandmax Putih B 1234 XYZ"
                        value="<?= $pengiriman ? htmlspecialchars($pengiriman['ekspedisi'] ?? '') : '' ?>">
                </div>

                <div class="form-group">
                    <label>Alamat Pengiriman *</label>
                    <textarea name="alamat" class="form-control" rows="3" required><?= $pengiriman ? htmlspecialchars($pengiriman['alamat']) : '' ?></textarea>
                </div>

                <!-- ═══ CANVAS TANDA TANGAN PENGIRIM ═══ -->
                <div class="form-section">
                    <div class="section-header">
                        <h3>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                                <path d="M2 2l7.586 7.586" />
                                <circle cx="11" cy="11" r="2" />
                            </svg>
                            Tanda Tangan Pengirim
                        </h3>
                        <span class="sig-status empty" id="sigStatusPengirim">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v5l3 2" />
                            </svg>
                            Belum ditandatangani
                        </span>
                    </div>
                    <div class="signature-wrapper" id="sigWrapperPengirim">
                        <canvas id="canvasPengirim" class="signature-canvas"></canvas>
                        <div class="signature-actions">
                            <small>✍️ Gambar tanda tangan di area putih (pakai mouse/jari)</small>
                            <button type="button" class="btn-clear-sig" onclick="clearSignature('pengirim')">
                                🗑️ Hapus TTD
                            </button>
                        </div>
                    </div>
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
                                            <input type="text" name="satuan[]" class="form-control" required
                                                placeholder="Pcs / Box / Kg"
                                                value="<?= htmlspecialchars($detail['satuan']) ?>">
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
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                                        <input type="text" name="satuan[]" class="form-control" required placeholder="Pcs / Box / Kg">
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

    <script src="script.js"></script>
    <script>
        // ============================================
        // SIGNATURE PAD - PENGIRIM
        // ============================================
        // ============================================
        // SIGNATURE PAD - PENGIRIM
        // ============================================
        const canvasPengirim = document.getElementById('canvasPengirim');
        const sigPadPengirim = new SignaturePad(canvasPengirim, {
            backgroundColor: 'rgb(255, 255, 255)',
            penColor: 'rgb(31, 43, 77)',
            minWidth: 1,
            maxWidth: 2.5
        });

        // Load TTD existing (mode edit)
        const existingTTD = document.getElementById('ttd_pengirim_input').value;
        if (existingTTD && existingTTD !== 'data:image/png;base64,' && existingTTD.length > 100) {
            sigPadPengirim.fromDataURL(existingTTD);
            updateSigStatus('pengirim', true);
        }

        sigPadPengirim.addEventListener('endStroke', () => {
            // Auto-save signature ke hidden input setiap kali selesai gambar
            const dataURL = sigPadPengirim.toDataURL('image/png');
            document.getElementById('ttd_pengirim_input').value = dataURL;
            updateSigStatus('pengirim', !sigPadPengirim.isEmpty());
        });

        function clearSignature(type) {
            if (type === 'pengirim') {
                sigPadPengirim.clear();
                document.getElementById('ttd_pengirim_input').value = '';
                updateSigStatus('pengirim', false);
            }
        }

        function updateSigStatus(type, isSigned) {
            const status = document.getElementById('sigStatusPengirim');
            const wrapper = document.getElementById('sigWrapperPengirim');
            if (isSigned) {
                status.className = 'sig-status done';
                status.innerHTML = `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Sudah ditandatangani`;
                wrapper.classList.add('signed');
            } else {
                status.className = 'sig-status empty';
                status.innerHTML = `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> Belum ditandatangani`;
                wrapper.classList.remove('signed');
            }
        }

        function submitWithSignature() {
            // Cek apakah ada signature di canvas ATAU di hidden input (untuk mode edit)
            const hasCanvasSignature = !sigPadPengirim.isEmpty();
            const hasInputSignature = document.getElementById('ttd_pengirim_input').value.length > 100;

            if (!hasCanvasSignature && !hasInputSignature) {
                alert('⚠️ Tanda tangan pengirim wajib diisi!\n\nSilakan gambar tanda tangan di area putih.');
                return false;
            }

            // Pastikan signature tersimpan di hidden input
            if (hasCanvasSignature) {
                const dataURL = sigPadPengirim.toDataURL('image/png');
                document.getElementById('ttd_pengirim_input').value = dataURL;
            }

            console.log('Signature saved:', document.getElementById('ttd_pengirim_input').value.substring(0, 50) + '...');
            return true;
        }

        // ============================================
        // RESIZE CANVAS
        // ============================================
        function resizeCanvas() {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvasPengirim.width = canvasPengirim.offsetWidth * ratio;
            canvasPengirim.height = canvasPengirim.offsetHeight * ratio;
            canvasPengirim.getContext("2d").scale(ratio, ratio);

            // Jangan clear signature yang sudah ada
            if (existingTTD && existingTTD !== 'data:image/png;base64,' && existingTTD.length > 100) {
                sigPadPengirim.fromDataURL(existingTTD);
            }
        }

        // Init
        window.addEventListener('load', () => {
            resizeCanvas();
            // Jika ada existing signature, pastikan tersimpan
            if (existingTTD && existingTTD.length > 100) {
                document.getElementById('ttd_pengirim_input').value = existingTTD;
            }
        });
        window.addEventListener('resize', resizeCanvas);
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
            <div class="form-group"><label>Nama Barang *</label><input type="text" name="nama_barang[]" class="form-control" required></div>
            <div class="form-group small"><label>Qty *</label><input type="number" name="qty[]" class="form-control qty-input" min="1" required oninput="hitungTotal()"></div>
            <div class="form-group small"><label>Satuan *</label><input type="text" name="satuan[]" class="form-control" required placeholder="Pcs / Box / Kg"></div>
        </div>
        <div class="form-group"><label>Keterangan</label><input type="text" name="keterangan[]" class="form-control"></div>
    `;
            container.appendChild(div);
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
    </script>
</body>

</html>