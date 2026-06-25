<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
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
$lokasiSession = $_SESSION['lokasi'] ?? 'semua';
$lokasiMap = ['sodong' => 'Sodong', 'sariwangi' => 'Sariwangi', 'manonjaya' => 'Manonjaya', 'semua' => 'Semua'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM pengiriman WHERE id = ?");
$stmt->execute([$id]);
$pengiriman = $stmt->fetch();

if (!$pengiriman) {
    header("Location: ../pengiriman/index.php");
    exit;
}

if ($is_operator && $lokasiSession !== 'semua') {
    if (($pengiriman['lokasi'] ?? 'semua') !== $lokasiSession) {
        die("⛔ <h2 style='text-align:center; margin-top:50px; color:#c73e3e;'>Akses Ditolak!</h2><p style='text-align:center;'>Anda hanya dapat mengkonfirmasi penerimaan untuk dapur <strong>" . ($lokasiMap[$lokasiSession] ?? $lokasiSession) . "</strong>.</p>");
    }
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

$ttd_pengirim_existing = $pengiriman['tanda_tangan_pengirim'] ?? '';
$ttd_penerima_existing = $penerimaan_exist['tanda_tangan_penerima'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$is_operator) die("Hanya operator yang dapat melakukan konfirmasi.");
    try {
        $pdo->beginTransaction();
        $ttd_pengirim = $_POST['tanda_tangan_pengirim'] ?? null;
        if (empty($ttd_pengirim) || $ttd_pengirim === 'data:image/png;base64,') {
            throw new Exception("Tanda tangan pengirim wajib diisi!");
        }
        $pdo->prepare("UPDATE pengiriman SET tanda_tangan_pengirim = ? WHERE id = ?")
            ->execute([$ttd_pengirim, $id]);

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

        $detail_ids  = $_POST['detail_id'];
        $statuses    = $_POST['status_barang'];
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
        /* ── Select status wrapper ── */
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

        /* ── Pengirim info box ── */
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

        /* ── Signature ── */
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
            height: 180px;
            margin: 0 auto;
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
            flex-wrap: wrap;
            gap: 8px;
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

        /* ── Lokasi badge ── */
        .lokasi-badge-info {
            background: var(--amber-tint);
            color: var(--amber);
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--amber);
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ══════════════════════════════════════
           MOBILE: Tabel Pengecekan → Card List
           ══════════════════════════════════════ */
        @media (max-width: 768px) {

            /* Nomor kartu di pojok kanan atas */
            .barang-card-no {
                position: absolute;
                top: 10px;
                right: 12px;
                background: var(--navy);
                color: #fff;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                font-size: 10px;
                font-weight: 700;
                font-family: var(--font-mono);
                display: flex;
                align-items: center;
                justify-content: center;
            }

            /* Sembunyikan tabel, tampilkan card */
            .table-pengecekan {
                display: none !important;
            }

            .cards-pengecekan {
                display: flex !important;
            }

            .signature-canvas {
                height: 160px;
            }

            .pengirim-box {
                flex-direction: column;
                gap: 10px;
            }

            .form-section {
                padding: 14px;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }

        /* Cards container — hidden on desktop, shown on mobile */
        .cards-pengecekan {
            display: none;
            flex-direction: column;
            gap: 12px;
            margin: 12px 0;
        }

        .barang-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-left: 3px solid var(--navy-soft);
            border-radius: var(--radius-sm);
            padding: 14px 14px 14px 14px;
            position: relative;
        }

        /* Border kiri berubah sesuai status */
        .barang-card.status-ada {
            border-left-color: var(--green);
        }

        .barang-card.status-kurang {
            border-left-color: var(--orange);
        }

        .barang-card.status-tidak_ada {
            border-left-color: var(--red);
        }

        .barang-card-nama {
            font-weight: 700;
            font-size: 14.5px;
            color: var(--ink);
            padding-right: 28px;
            /* ruang untuk nomor */
            margin-bottom: 4px;
        }

        .barang-card-catatan {
            font-size: 11px;
            color: var(--ink-faint);
            font-style: italic;
            margin-bottom: 10px;
        }

        .barang-card-qty {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            color: var(--navy);
            background: var(--navy-tint);
            padding: 3px 10px;
            border-radius: 999px;
            margin-bottom: 12px;
            font-family: var(--font-mono);
        }

        .barang-card-field {
            margin-bottom: 10px;
        }

        .barang-card-field label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--ink-soft);
            margin-bottom: 5px;
            display: block;
        }

        /* ── Tombol status penerimaan (mobile cards) ── */
        .status-btn-group {
            display: flex;
            gap: 8px;
        }

        .btn-status-pilih {
            flex: 1;
            padding: 10px 6px;
            border-radius: var(--radius-sm);
            border: 2px solid var(--line-strong);
            background: var(--surface);
            color: var(--ink-soft);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            line-height: 1.2;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-status-pilih .btn-icon {
            width: 22px;
            height: 22px;
            flex-shrink: 0;
        }

        .btn-status-pilih:active {
            transform: scale(0.96);
        }

        /* Aktif per status */
        .btn-status-pilih.active-ada {
            background: var(--green-tint);
            border-color: var(--green);
            color: var(--green);
        }

        .btn-status-pilih.active-kurang {
            background: var(--orange-tint);
            border-color: var(--orange);
            color: var(--orange);
        }

        .btn-status-pilih.active-tidak_ada {
            background: var(--red-tint);
            border-color: var(--red);
            color: var(--red);
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

            <div class="lokasi-badge-info">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                    <circle cx="12" cy="10" r="3" />
                </svg>
                Pengiriman untuk: <strong><?= htmlspecialchars($lokasiMap[$pengiriman['lokasi']] ?? $pengiriman['lokasi']) ?></strong>
            </div>

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
                <input type="hidden" name="tanda_tangan_pengirim" id="ttd_pengirim_input" value="<?= htmlspecialchars($ttd_pengirim_existing) ?>">
                <input type="hidden" name="tanda_tangan_penerima" id="ttd_penerima_input" value="<?= htmlspecialchars($ttd_penerima_existing) ?>">

                <div class="form-section">
                    <div class="section-header">
                        <h3>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M9 11l3 3 8-8" />
                                <path d="M20 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2h9" />
                            </svg>
                            Pengecekan Barang (<?= count($details) ?> Item)
                        </h3>
                    </div>

                    <!-- DESKTOP: tabel biasa -->
                    <table class="table-detail table-pengecekan">
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

                    <!-- MOBILE: card list -->
                    <div class="cards-pengecekan">
                        <?php $no = 1;
                        foreach ($details as $d): ?>
                            <div class="barang-card <?= $d['terima_status'] ? 'status-' . $d['terima_status'] : '' ?>" id="card-<?= $d['id'] ?>">
                                <div class="barang-card-no"><?= $no++ ?></div>
                                <div class="barang-card-nama"><?= htmlspecialchars($d['nama_barang']) ?></div>
                                <?php if ($d['keterangan']): ?>
                                    <div class="barang-card-catatan">📝 Catatan kirim: <?= htmlspecialchars($d['keterangan']) ?></div>
                                <?php endif; ?>
                                <div class="barang-card-qty">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z" />
                                        <path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16" />
                                    </svg>
                                    <?= $d['qty'] ?> <?= htmlspecialchars($d['satuan']) ?>
                                </div>

                                <div class="barang-card-field">
                                    <label>Status Penerimaan *</label>
                                    <!-- Hidden input sebagai nilai form -->
                                    <input type="hidden" name="status_barang[]" class="status-hidden-input"
                                        value="<?= htmlspecialchars($d['terima_status'] ?? '') ?>" required>
                                    <div class="status-btn-group">
                                        <button type="button"
                                            class="btn-status-pilih <?= ($d['terima_status'] ?? '') === 'ada' ? 'active-ada' : '' ?>"
                                            onclick="pilihStatus(this, 'ada')">
                                            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20 6L9 17l-5-5" />
                                            </svg>
                                            <span>Ada<br>Lengkap</span>
                                        </button>
                                        <button type="button"
                                            class="btn-status-pilih <?= ($d['terima_status'] ?? '') === 'kurang' ? 'active-kurang' : '' ?>"
                                            onclick="pilihStatus(this, 'kurang')">
                                            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                                <line x1="12" y1="9" x2="12" y2="13" />
                                                <line x1="12" y1="17" x2="12.01" y2="17" />
                                            </svg>
                                            <span>Kurang/<br>Rusak</span>
                                        </button>
                                        <button type="button"
                                            class="btn-status-pilih <?= ($d['terima_status'] ?? '') === 'tidak_ada' ? 'active-tidak_ada' : '' ?>"
                                            onclick="pilihStatus(this, 'tidak_ada')">
                                            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="18" y1="6" x2="6" y2="18" />
                                                <line x1="6" y1="6" x2="18" y2="18" />
                                            </svg>
                                            <span>Tidak<br>Ada</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="barang-card-field field-keterangan" style="<?= in_array($d['terima_status'] ?? '', ['kurang', 'tidak_ada']) ? '' : 'display:none;' ?>">
                                    <label>Keterangan</label>
                                    <input type="text" name="keterangan_status[]" class="form-control input-ket"
                                        placeholder="Wajib diisi jika kurang/tidak ada"
                                        value="<?= htmlspecialchars($d['terima_keterangan'] ?? '') ?>">
                                </div>

                                <!-- hidden id untuk form submit -->
                                <input type="hidden" name="detail_id[]" value="<?= $d['id'] ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
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

                <!-- TTD Pengirim -->
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
                            <small>✍️ Gambar tanda tangan pengirim di area putih</small>
                            <button type="button" class="btn-clear-sig" onclick="clearSignature('pengirim')">🗑️ Hapus TTD</button>
                        </div>
                    </div>
                </div>

                <!-- TTD Penerima -->
                <div class="form-section">
                    <div class="section-header">
                        <h3>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M12 19l7-7 3 3-7 7-3-3z" />
                                <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" />
                            </svg>
                            Tanda Tangan Penerima
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
                            <button type="button" class="btn-clear-sig" onclick="clearSignature('penerima')">🗑️ Hapus TTD</button>
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
        // ── Resize canvas ──
        function resizeSignatureCanvas(canvas, pad, existingData) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            pad.clear();
            if (existingData && existingData.length > 100 && existingData !== 'data:image/png;base64,') {
                pad.fromDataURL(existingData);
            }
        }

        // ── Trim whitespace dari TTD ──
        function getTrimmedSignature(pad) {
            const canvas = pad.toCanvas();
            const ctx = canvas.getContext('2d');
            const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = imgData.data;
            const w = canvas.width,
                h = canvas.height;
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
            const p = 20;
            left = Math.max(0, left - p);
            top = Math.max(0, top - p);
            right = Math.min(w, right + p);
            bottom = Math.min(h, bottom + p);
            const cw = right - left,
                ch = bottom - top;
            if (cw <= 0 || ch <= 0) return canvas.toDataURL('image/png');
            const tc = document.createElement('canvas');
            tc.width = cw;
            tc.height = ch;
            const tctx = tc.getContext('2d');
            tctx.fillStyle = '#ffffff';
            tctx.fillRect(0, 0, cw, ch);
            tctx.drawImage(canvas, left, top, cw, ch, 0, 0, cw, ch);
            return tc.toDataURL('image/png');
        }

        // ── Signature Pads ──
        const canvasPengirim = document.getElementById('canvasPengirim');
        const sigPadPengirim = new SignaturePad(canvasPengirim, {
            backgroundColor: 'rgb(255,255,255)',
            penColor: 'rgb(31,43,77)',
            minWidth: 1,
            maxWidth: 2.5
        });
        const ttdPengirimInput = document.getElementById('ttd_pengirim_input');
        const existingTTDPengirim = ttdPengirimInput.value;
        sigPadPengirim.addEventListener('endStroke', () => {
            const ok = !sigPadPengirim.isEmpty();
            updateSigStatus('pengirim', ok);
            ttdPengirimInput.value = ok ? sigPadPengirim.toDataURL('image/png') : '';
        });

        const canvasPenerima = document.getElementById('canvasPenerima');
        const sigPadPenerima = new SignaturePad(canvasPenerima, {
            backgroundColor: 'rgb(255,255,255)',
            penColor: 'rgb(31,43,77)',
            minWidth: 1,
            maxWidth: 2.5
        });
        const ttdPenerimaInput = document.getElementById('ttd_penerima_input');
        const existingTTDPenerima = ttdPenerimaInput.value;
        sigPadPenerima.addEventListener('endStroke', () => {
            const ok = !sigPadPenerima.isEmpty();
            updateSigStatus('penerima', ok);
            ttdPenerimaInput.value = ok ? sigPadPenerima.toDataURL('image/png') : '';
        });

        function resizeAll() {
            resizeSignatureCanvas(canvasPengirim, sigPadPengirim, existingTTDPengirim);
            resizeSignatureCanvas(canvasPenerima, sigPadPenerima, existingTTDPenerima);
            if (existingTTDPengirim && existingTTDPengirim.length > 100) {
                ttdPengirimInput.value = existingTTDPengirim;
                updateSigStatus('pengirim', true);
            }
            if (existingTTDPenerima && existingTTDPenerima.length > 100) {
                ttdPenerimaInput.value = existingTTDPenerima;
                updateSigStatus('penerima', true);
            }
        }
        resizeAll();
        window.addEventListener('resize', resizeAll);
        window.addEventListener('orientationchange', () => setTimeout(resizeAll, 200));

        function clearSignature(type) {
            if (type === 'pengirim') {
                sigPadPengirim.clear();
                ttdPengirimInput.value = '';
                updateSigStatus('pengirim', false);
            } else {
                sigPadPenerima.clear();
                ttdPenerimaInput.value = '';
                updateSigStatus('penerima', false);
            }
        }

        function updateSigStatus(type, isSigned) {
            const cap = type === 'pengirim' ? 'Pengirim' : 'Penerima';
            const status = document.getElementById('sigStatus' + cap);
            const wrapper = document.getElementById('sigWrapper' + cap);
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
            const hasPengirimCanvas = !sigPadPengirim.isEmpty();
            const hasPengirimInput = ttdPengirimInput.value.length > 100;
            const hasPenerimaCanvas = !sigPadPenerima.isEmpty();
            const hasPenerimaInput = ttdPenerimaInput.value.length > 100;
            if (!hasPengirimCanvas && !hasPengirimInput) {
                alert('⚠️ Tanda tangan PENGIRIM wajib diisi!');
                return false;
            }
            if (!hasPenerimaCanvas && !hasPenerimaInput) {
                alert('⚠️ Tanda tangan PENERIMA wajib diisi!');
                return false;
            }
            if (hasPengirimCanvas) ttdPengirimInput.value = getTrimmedSignature(sigPadPengirim);
            if (hasPenerimaCanvas) ttdPenerimaInput.value = getTrimmedSignature(sigPadPenerima);
            return true;
        }

        // ── Status select handler untuk TABEL DESKTOP ──
        function handleStatusChange(select) {
            const wrapper = select.closest('.select-wrapper');
            const val = select.value;
            wrapper.classList.remove('has-value', 'status-ada', 'status-kurang', 'status-tidak_ada');
            wrapper.querySelectorAll('.status-icon').forEach(el => el.style.display = 'none');
            if (val) {
                wrapper.classList.add('has-value', 'status-' + val);
                const icon = wrapper.querySelector('.icon-' + val);
                if (icon) icon.style.display = 'block';
            }
            const ketInput = select.closest('tr')?.querySelector('.input-ket');
            if (ketInput) {
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
        }
        document.querySelectorAll('.status-select').forEach(handleStatusChange);

        // ── Tombol status untuk MOBILE CARDS ──
        function pilihStatus(btn, val) {
            const card = btn.closest('.barang-card');

            // Reset semua tombol di card ini
            card.querySelectorAll('.btn-status-pilih').forEach(b => {
                b.classList.remove('active-ada', 'active-kurang', 'active-tidak_ada');
            });

            // Aktifkan tombol yang dipilih
            btn.classList.add('active-' + val);

            // Set nilai hidden input
            card.querySelector('.status-hidden-input').value = val;

            // Update border kiri card
            card.classList.remove('status-ada', 'status-kurang', 'status-tidak_ada');
            card.classList.add('status-' + val);

            // Tampil/sembunyikan field keterangan
            const fieldKet = card.querySelector('.field-keterangan');
            const ketInput = card.querySelector('.input-ket');
            if (fieldKet && ketInput) {
                if (val === 'kurang' || val === 'tidak_ada') {
                    fieldKet.style.display = '';
                    ketInput.required = true;
                    ketInput.style.borderColor = 'var(--orange)';
                    ketInput.style.background = 'var(--orange-tint)';
                    ketInput.placeholder = 'WAJIB: Jelaskan kekurangan/kerusakan...';
                } else {
                    fieldKet.style.display = 'none';
                    ketInput.required = false;
                    ketInput.value = '';
                    ketInput.style.borderColor = '';
                    ketInput.style.background = '';
                }
            }
        }

        // Inisialisasi keterangan field untuk card yang sudah punya status (mode edit)
        document.querySelectorAll('.barang-card').forEach(card => {
            const hiddenInput = card.querySelector('.status-hidden-input');
            if (hiddenInput && hiddenInput.value) {
                const val = hiddenInput.value;
                const fieldKet = card.querySelector('.field-keterangan');
                const ketInput = card.querySelector('.input-ket');
                if (fieldKet && ketInput) {
                    if (val === 'kurang' || val === 'tidak_ada') {
                        fieldKet.style.display = '';
                        ketInput.required = true;
                        ketInput.style.borderColor = 'var(--orange)';
                        ketInput.style.background = 'var(--orange-tint)';
                        ketInput.placeholder = 'WAJIB: Jelaskan kekurangan/kerusakan...';
                    } else {
                        fieldKet.style.display = 'none';
                        ketInput.required = false;
                    }
                }
            }
        });

        // ── Validasi tambahan saat submit: pastikan semua card sudah dipilih statusnya ──
        const origSubmit = window.submitWithSignature;
        window.submitWithSignature = function() {
            // Cek hanya jika cards sedang visible (mobile)
            const cardsVisible = window.getComputedStyle(document.querySelector('.cards-pengecekan')).display !== 'none';
            if (cardsVisible) {
                const empties = document.querySelectorAll('.status-hidden-input');
                for (const inp of empties) {
                    if (!inp.value) {
                        alert('⚠️ Semua status barang wajib dipilih!');
                        inp.closest('.barang-card').scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        return false;
                    }
                }
            }
            return origSubmit();
        };
    </script>
</body>

</html>