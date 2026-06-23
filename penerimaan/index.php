<?php
session_start();
require_once '../database/koneksi.php';

if (!isset($_SESSION['role'])) {
    header("Location: ../index.php");
    exit;
}
if (!in_array($_SESSION['role'], ['admin', 'operator'])) {
    header("Location: ../index.php");
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$is_operator = ($_SESSION['role'] === 'operator');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM pengiriman WHERE id = ?");
$stmt->execute([$id]);
$pengiriman = $stmt->fetch();
if (!$pengiriman) {
    header("Location: ../pengiriman/index.php");
    exit;
}

$stmt_d = $pdo->prepare("
    SELECT dp.*, dpr.status_barang AS terima_status, dpr.keterangan AS terima_keterangan
    FROM detail_pengiriman dp
    LEFT JOIN penerimaan pr ON pr.pengiriman_id = dp.pengiriman_id
    LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id AND dpr.penerimaan_id = pr.id
    WHERE dp.pengiriman_id = ?
");
$stmt_d->execute([$id]);
$details = $stmt_d->fetchAll();

$stmt_pr = $pdo->prepare("SELECT * FROM penerimaan WHERE pengiriman_id = ? LIMIT 1");
$stmt_pr->execute([$id]);
$penerimaan_exist = $stmt_pr->fetch();

$tanggal_terima_value = '';
if ($penerimaan_exist && !empty($penerimaan_exist['tanggal_terima'])) {
    $tanggal_terima_value = date('Y-m-d\TH:i', strtotime($penerimaan_exist['tanggal_terima']));
} else {
    $tanggal_terima_value = date('Y-m-d\TH:i');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$is_operator) die("Hanya operator yang dapat melakukan konfirmasi.");

    try {
        $pdo->beginTransaction();

        $nama_penerima_barang = trim($_POST['nama_penerima_barang']);
        $tanggal_terima_mysql = date('Y-m-d H:i:s', strtotime($_POST['tanggal_terima']));
        $ttd_penerima = $_POST['tanda_tangan_penerima'] ?? null;

        if (empty($ttd_penerima) || $ttd_penerima === 'data:image/png;base64,') {
            throw new Exception("Tanda tangan penerima wajib diisi!");
        }

        if ($penerimaan_exist) {
            $pdo->prepare("UPDATE penerimaan SET nama_penerima_barang = ?, tanggal_terima = ?, tanda_tangan_penerima = ? WHERE id = ?")
                ->execute([$nama_penerima_barang, $tanggal_terima_mysql, $ttd_penerima, $penerimaan_exist['id']]);
            $penerimaan_id = $penerimaan_exist['id'];
            $pdo->prepare("DELETE FROM detail_penerimaan WHERE penerimaan_id = ?")->execute([$penerimaan_id]);
        } else {
            $pdo->prepare("INSERT INTO penerimaan (pengiriman_id, nama_penerima_barang, tanggal_terima, tanda_tangan_penerima) 
                           VALUES (?, ?, ?, ?)")
                ->execute([$id, $nama_penerima_barang, $tanggal_terima_mysql, $ttd_penerima]);
            $penerimaan_id = $pdo->lastInsertId();
        }

        $detail_ids = $_POST['detail_id'];
        $statuses = $_POST['status_barang'];
        $keterangans = $_POST['keterangan_status'];

        $stmt_insert = $pdo->prepare("INSERT INTO detail_penerimaan (penerimaan_id, detail_pengiriman_id, status_barang, keterangan) VALUES (?, ?, ?, ?)");
        for ($i = 0; $i < count($detail_ids); $i++) {
            $status = $statuses[$i] ?? null;
            $ket = trim($keterangans[$i] ?? '');
            if ($status) {
                $stmt_insert->execute([$penerimaan_id, (int)$detail_ids[$i], $status, $ket ?: null]);
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
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <style>
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

        .pengirim-box {
            background: var(--navy-tint);
            border-left: 4px solid var(--navy);
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-top: 12px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 13.5px;
        }

        .pengirim-box .item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ink-soft);
        }

        .pengirim-box .item strong {
            color: var(--navy);
        }

        /* Signature Pad */
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
                <h3>
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z" />
                        <path d="M14 3v5h5" />
                    </svg>
                    Surat Jalan: <?= htmlspecialchars($pengiriman['no_surat_jalan']) ?>
                </h3>
                <p><strong>Tujuan SPPG:</strong> <?= htmlspecialchars($pengiriman['nama_sppg']) ?></p>
                <p><strong>Alamat:</strong> <?= htmlspecialchars($pengiriman['alamat']) ?></p>
                <p><strong>Tanggal Ekspedisi:</strong> <?= date('d F Y', strtotime($pengiriman['tanggal_ekspedisi'])) ?></p>
                <div class="pengirim-box">
                    <div class="item">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="8" r="3.5" />
                            <path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6" />
                        </svg>
                        <span>Pengirim: <strong><?= htmlspecialchars($pengiriman['nama_pengirim'] ?? '-') ?></strong></span>
                    </div>
                    <div class="item">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="1" y="3" width="15" height="13" rx="2" />
                            <path d="M16 8h4l3 3v5h-7V8z" />
                        </svg>
                        <span>Armada: <strong><?= htmlspecialchars($pengiriman['ekspedisi'] ?? '-') ?></strong></span>
                    </div>
                </div>
            </div>

            <form method="POST" onsubmit="return submitWithSignature()">
                <input type="hidden" name="tanda_tangan_penerima" id="ttd_penerima_input"
                    value="<?= $penerimaan_exist ? htmlspecialchars($penerimaan_exist['tanda_tangan_penerima'] ?? '') : '' ?>">

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
                                            <svg class="status-icon icon-ada" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <path d="M20 6L9 17l-5-5" />
                                            </svg>
                                            <svg class="status-icon icon-kurang" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                                <line x1="12" y1="9" x2="12" y2="13" />
                                                <line x1="12" y1="17" x2="12.01" y2="17" />
                                            </svg>
                                            <svg class="status-icon icon-tidak_ada" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
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
                                value="<?= $tanggal_terima_value ?>">
                        </div>
                    </div>
                </div>

                <!-- ═══ CANVAS TANDA TANGAN PENERIMA ═══ -->
                <div class="form-section">
                    <div class="section-header">
                        <h3>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                            </svg>
                            Tanda Tangan Penerima (SPPG)
                        </h3>
                        <span class="sig-status empty" id="sigStatusPenerima">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v5l3 2" />
                            </svg>
                            Belum ditandatangani
                        </span>
                    </div>
                    <div class="signature-wrapper" id="sigWrapperPenerima">
                        <canvas id="canvasPenerima" class="signature-canvas"></canvas>
                        <div class="signature-actions">
                            <small>✍️ Gambar tanda tangan penerima di area putih</small>
                            <button type="button" class="btn-clear-sig" onclick="clearSignature('penerima')">
                                🗑️ Hapus TTD
                            </button>
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
                            Anda login sebagai <strong>Admin</strong>. Hanya Operator yang dapat konfirmasi.
                        </div>
                    <?php endif; ?>
                    <a href="../pengiriman/index.php" class="btn btn-secondary btn-lg" style="color:var(--ink-soft); border-color:var(--line-strong);">Batal</a>
                </div>
            </form>
        </main>
    </div>
    <script>
        // ============================================
        // SIGNATURE PAD - PENERIMA
        // ============================================
        const canvasPenerima = document.getElementById('canvasPenerima');
        const sigPadPenerima = new SignaturePad(canvasPenerima, {
            backgroundColor: 'rgb(255, 255, 255)',
            penColor: 'rgb(31, 43, 77)',
            minWidth: 1,
            maxWidth: 2.5
        });

        const existingTTDPenerima = document.getElementById('ttd_penerima_input').value;
        const ttdInput = document.getElementById('ttd_penerima_input');

        // ═══════════════════════════════════════════════════════════
        // RESIZE CANVAS (WAJIB! Biar signature gak ngaco)
        // ═══════════════════════════════════════════════════════════
        function resizeCanvas() {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvasPenerima.width = canvasPenerima.offsetWidth * ratio;
            canvasPenerima.height = canvasPenerima.offsetHeight * ratio;
            canvasPenerima.getContext('2d').scale(ratio, ratio);

            // Clear & load ulang TTD existing kalau ada
            sigPadPenerima.clear();
            if (existingTTDPenerima && existingTTDPenerima.length > 100 && existingTTDPenerima !== 'data:image/png;base64,') {
                sigPadPenerima.fromDataURL(existingTTDPenerima);
                updateSigStatus('penerima', true);
                // Pastikan hidden input juga terisi
                ttdInput.value = existingTTDPenerima;
            }
        }

        // Jalankan resize saat load
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);
        window.addEventListener('orientationchange', () => setTimeout(resizeCanvas, 200));

        // ═══════════════════════════════════════════════════════════
        // AUTO-SAVE: Setiap selesai gambar, langsung simpan ke hidden input
        // ═══════════════════════════════════════════════════════════
        sigPadPenerima.addEventListener('endStroke', () => {
            const isSigned = !sigPadPenerima.isEmpty();
            updateSigStatus('penerima', isSigned);

            // Auto-save ke hidden input
            if (isSigned) {
                ttdInput.value = sigPadPenerima.toDataURL('image/png');
            } else {
                ttdInput.value = '';
            }
        });

        function clearSignature(type) {
            if (type === 'penerima') {
                sigPadPenerima.clear();
                ttdInput.value = '';
                updateSigStatus('penerima', false);
            }
        }

        function updateSigStatus(type, isSigned) {
            const status = document.getElementById('sigStatusPenerima');
            const wrapper = document.getElementById('sigWrapperPenerima');
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

        // ═══════════════════════════════════════════════════════════
        // HELPER: Trim whitespace dari signature pad
        // ═══════════════════════════════════════════════════════════
        function getTrimmedSignature(pad) {
            const canvas = pad.toCanvas();
            const ctx = canvas.getContext('2d');
            const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = imgData.data;
            const w = canvas.width;
            const h = canvas.height;

            // Cari bounding box dari coretan (bukan putih)
            let top = h,
                left = w,
                right = 0,
                bottom = 0;
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    const i = (y * w + x) * 4;
                    if (data[i] < 250 || data[i + 1] < 250 || data[i + 2] < 250) {
                        if (x < left) left = x;
                        if (x > right) right = x;
                        if (y < top) top = y;
                        if (y > bottom) bottom = y;
                    }
                }
            }

            // Padding biar gak mepet
            const padding = 20;
            left = Math.max(0, left - padding);
            top = Math.max(0, top - padding);
            right = Math.min(w, right + padding);
            bottom = Math.min(h, bottom + padding);

            const cropW = right - left;
            const cropH = bottom - top;

            // Kalau kosong, return original
            if (cropW <= 0 || cropH <= 0) {
                return canvas.toDataURL('image/png');
            }

            const trimmedCanvas = document.createElement('canvas');
            trimmedCanvas.width = cropW;
            trimmedCanvas.height = cropH;
            const trimmedCtx = trimmedCanvas.getContext('2d');
            trimmedCtx.fillStyle = '#ffffff';
            trimmedCtx.fillRect(0, 0, cropW, cropH);
            trimmedCtx.drawImage(canvas, left, top, cropW, cropH, 0, 0, cropW, cropH);

            return trimmedCanvas.toDataURL('image/png');
        }

        // ═══════════════════════════════════════════════════════════
        // SUBMIT HANDLER - FIX: pakai sigPadPenerima (bukan Pengirim!)
        // ═══════════════════════════════════════════════════════════
        function submitWithSignature() {
            // ✅ FIX: cek sigPadPenerima, BUKAN sigPadPengirim
            const hasCanvasSignature = !sigPadPenerima.isEmpty();
            const hasInputSignature = ttdInput.value.length > 100;

            if (!hasCanvasSignature && !hasInputSignature) {
                alert('⚠️ Tanda tangan penerima wajib diisi!\n\nSilakan gambar tanda tangan di area putih.');
                return false;
            }

            // ✅ FIX: gunakan trimmed version
            if (hasCanvasSignature) {
                ttdInput.value = getTrimmedSignature(sigPadPenerima);
            }

            console.log('✅ Signature saved, length:', ttdInput.value.length);
            return true;
        }

        // ============================================
        // STATUS SELECT HANDLER
        // ============================================
        function handleStatusChange(select) {
            const wrapper = select.closest('.select-wrapper');
            const val = select.value;
            const tr = select.closest('tr');
            const ketInput = tr.querySelector('.input-ket');

            wrapper.classList.remove('has-value', 'status-ada', 'status-kurang', 'status-tidak_ada');
            wrapper.querySelectorAll('.status-icon').forEach(el => el.style.display = 'none');

            if (val) {
                wrapper.classList.add('has-value', 'status-' + val);
                const icon = wrapper.querySelector('.icon-' + val);
                if (icon) icon.style.display = 'block';
            }

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
        document.querySelectorAll('.status-select').forEach(handleStatusChange);
    </script>
</body>

</html>