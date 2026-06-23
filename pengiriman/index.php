<?php
session_start();
require_once '../database/koneksi.php';

// RBAC: Hanya Admin dan Operator
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}
$is_admin = ($_SESSION['role'] === 'admin');
$is_operator = ($_SESSION['role'] === 'operator');

// Handle delete (Hanya Admin)
if (isset($_GET['hapus']) && $is_admin) {
    $id = (int)$_GET['hapus'];
    // Hapus berurutan karena foreign key
    $pdo->prepare("DELETE dp FROM detail_penerimaan dp 
                   INNER JOIN penerimaan pr ON pr.id = dp.penerimaan_id 
                   WHERE pr.pengiriman_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM penerimaan WHERE pengiriman_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM detail_pengiriman WHERE pengiriman_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM pengiriman WHERE id = ?")->execute([$id]);
    header("Location: index.php?msg=deleted");
    exit;
}

// Query dengan agregasi status per faktur
// Nama kolom: total_item, total_qty, item_sudah_diterima, item_bermasalah
$query = "SELECT p.*,
    (SELECT COUNT(*) FROM detail_pengiriman WHERE pengiriman_id = p.id) as total_item,
    (SELECT SUM(qty) FROM detail_pengiriman WHERE pengiriman_id = p.id) as total_qty,
    (SELECT COUNT(*) FROM detail_penerimaan dpr 
        INNER JOIN penerimaan pr ON pr.id = dpr.penerimaan_id 
        WHERE pr.pengiriman_id = p.id) as item_sudah_diterima,
    (SELECT COUNT(*) FROM detail_penerimaan dpr 
        INNER JOIN penerimaan pr ON pr.id = dpr.penerimaan_id 
        WHERE pr.pengiriman_id = p.id AND dpr.status_barang IN ('kurang','tidak_ada')) as item_bermasalah,
    pr.nama_penerima_barang, pr.tanggal_terima
    FROM pengiriman p
    LEFT JOIN penerimaan pr ON pr.pengiriman_id = p.id
    ORDER BY p.tanggal_ekspedisi DESC, p.no_faktur DESC";
$stmt = $pdo->query($query);
$all_data = $stmt->fetchAll();

// Group by tanggal
$grouped = [];
foreach ($all_data as $row) {
    $tgl = $row['tanggal_ekspedisi'];
    if (!isset($grouped[$tgl])) $grouped[$tgl] = [];
    $grouped[$tgl][] = $row;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Pengiriman - MBG</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="../assets/favicon.ico">
</head>

<body>
    <div class="container">
        <header>
            <div class="logo">
                <div class="logo-mark">
                    <svg class="icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 7l9-4 9 4-9 4-9-4z" />
                        <path d="M3 7v10l9 4 9-4V7" />
                        <path d="M12 11v10" />
                    </svg>
                </div>
                <div class="logo-text">
                    <h1>Data Pengiriman Barang</h1>
                    <span>MBG &middot; LOGISTIK (<?= strtoupper($_SESSION['role']) ?>)</span>
                </div>
            </div>
            <nav>
                <a href="../index.php" class="btn btn-secondary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 11l9-7 9 7" />
                        <path d="M5 10v9a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1v-9" />
                    </svg>
                    Beranda
                </a>
                <?php if ($is_admin): ?>
                    <a href="tambah.php" class="btn btn-success">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        Tambah Pengiriman
                    </a>
                <?php endif; ?>
            </nav>
        </header>
        <div class="header-stripe"></div>
        <main>
            <?php if (isset($_GET['msg'])): ?>
                <div class="alert alert-success" id="alertMsg">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M8 12l3 3 5-6" />
                    </svg>
                    <?php
                    $msg = $_GET['msg'];
                    if ($msg == 'deleted') echo 'Data berhasil dihapus!';
                    elseif ($msg == 'saved') echo 'Konfirmasi penerimaan berhasil disimpan!';
                    else echo 'Operasi berhasil!';
                    ?>
                </div>
            <?php endif; ?>

            <?php if (empty($grouped)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="56" height="56">
                        <path d="M3 9l2-5h14l2 5" />
                        <path d="M3 9v9a1 1 0 001 1h16a1 1 0 001-1V9" />
                        <path d="M3 9h5l1 3h6l1-3h5" />
                    </svg>
                    <p>Belum ada data pengiriman</p>
                    <?php if ($is_admin): ?>
                        <a href="tambah.php" class="btn btn-success">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                            Tambah Pengiriman Pertama
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $tanggal => $items): ?>
                    <div class="date-group">
                        <div class="date-header" onclick="toggleDate(this)">
                            <div class="date-header-left">
                                <h3>
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="5" width="18" height="16" rx="2" />
                                        <path d="M16 3v4M8 3v4M3 10h18" />
                                    </svg>
                                    <?= date('d F Y', strtotime($tanggal)) ?>
                                </h3>
                                <span class="badge"><?= count($items) ?> Faktur</span>
                            </div>
                            <svg class="icon arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 9l6 6 6-6" />
                            </svg>
                        </div>
                        <div class="date-content">
                            <?php foreach ($items as $item): ?>
                                <?php
                                // Ambil detail barang + status penerimaan per item
                                $stmt_d = $pdo->prepare("
                    SELECT dp.*, 
                           dpr.status_barang AS terima_status, 
                           dpr.keterangan AS terima_ket
                    FROM detail_pengiriman dp
                    LEFT JOIN penerimaan pr ON pr.pengiriman_id = dp.pengiriman_id
                    LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id 
                                                   AND dpr.penerimaan_id = pr.id
                    WHERE dp.pengiriman_id = ?
                ");
                                $stmt_d->execute([$item['id']]);
                                $details = $stmt_d->fetchAll();

                                // Tentukan status faktur berdasarkan item
                                $is_fully_checked = ($item['item_sudah_diterima'] == $item['total_item'] && $item['total_item'] > 0);
                                $has_issue = ($item['item_bermasalah'] > 0);
                                $is_partial = ($item['item_sudah_diterima'] > 0 && !$is_fully_checked);
                                ?>
                                <div class="accordion-item">
                                    <div class="accordion-header" onclick="toggleAccordion(this)">
                                        <div class="faktur-info">
                                            <strong>
                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z" />
                                                    <path d="M14 3v5h5" />
                                                </svg>
                                                <?= htmlspecialchars($item['no_faktur']) ?>
                                            </strong>
                                            <span>
                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="8" r="3.5" />
                                                    <path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6" />
                                                </svg>
                                                <?= htmlspecialchars($item['nama_penerima']) ?>
                                            </span>
                                            <span class="badge-info"><?= $item['total_qty'] ?> Item</span>
                                        </div>
                                        <div class="faktur-actions">
                                            <?php if ($is_fully_checked && !$has_issue): ?>
                                                <span class="badge badge-ada">
                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M20 6L9 17l-5-5" />
                                                    </svg>
                                                    LENGKAP
                                                </span>
                                            <?php elseif ($is_fully_checked && $has_issue): ?>
                                                <span class="badge badge-kurang">
                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                                        <line x1="12" y1="9" x2="12" y2="13" />
                                                        <line x1="12" y1="17" x2="12.01" y2="17" />
                                                    </svg>
                                                    ADA MASALAH
                                                </span>
                                            <?php elseif ($is_partial): ?>
                                                <span class="badge badge-pending">SEBAGIAN (<?= $item['item_sudah_diterima'] ?>/<?= $item['total_item'] ?>)</span>
                                            <?php else: ?>
                                                <span class="badge badge-pending">
                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="9" />
                                                        <path d="M12 7v5l3 2" />
                                                    </svg>
                                                    BELUM DITERIMA
                                                </span>
                                            <?php endif; ?>
                                            <svg class="icon arrow-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M9 6l6 6-6 6" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="accordion-content">
                                        <div class="detail-info">
                                            <p><strong>Alamat:</strong> <?= htmlspecialchars($item['alamat']) ?></p>
                                            <p><strong>Tanggal Ekspedisi:</strong> <?= date('d F Y', strtotime($item['tanggal_ekspedisi'])) ?></p>
                                        </div>
                                        <table class="table-detail">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nama Barang</th>
                                                    <th>Qty</th>
                                                    <th>Satuan</th>
                                                    <th>Status Terima</th>
                                                    <th>Keterangan</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $no = 1;
                                                foreach ($details as $detail): ?>
                                                    <tr>
                                                        <td><?= $no++ ?></td>
                                                        <td><?= htmlspecialchars($detail['nama_barang']) ?></td>
                                                        <td><?= $detail['qty'] ?></td>
                                                        <td><?= htmlspecialchars($detail['satuan']) ?></td>
                                                        <td>
                                                            <?php if ($detail['terima_status']): ?>
                                                                <span class="badge badge-<?= $detail['terima_status'] ?>">
                                                                    <?= strtoupper(str_replace('_', ' ', $detail['terima_status'])) ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge badge-pending" style="font-size:10px;">PENDING</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($detail['terima_ket']): ?>
                                                                <span class="keterangan-warning">
                                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                        <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                                                        <line x1="12" y1="9" x2="12" y2="13" />
                                                                        <line x1="12" y1="17" x2="12.01" y2="17" />
                                                                    </svg>
                                                                    <?= htmlspecialchars($detail['terima_ket']) ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span style="color:var(--ink-faint); font-size:12px;">
                                                                    <?= htmlspecialchars($detail['keterangan'] ?: '-') ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <th colspan="2">Total</th>
                                                    <th><?= $item['total_qty'] ?></th>
                                                    <th colspan="3">Item</th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                        <div class="action-buttons">
                                            <?php if (!$is_fully_checked && $is_operator): ?>
                                                <a href="../penerimaan/index.php?id=<?= $item['id'] ?>" class="btn btn-warning">
                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M5 12l4 4 10-10" />
                                                    </svg>
                                                    <?= $is_partial ? 'Lanjutkan Konfirmasi' : 'Konfirmasi Penerimaan' ?>
                                                </a>
                                            <?php elseif ($is_fully_checked): ?>
                                                <div class="status-terima">
                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="9" />
                                                        <path d="M8 12l3 3 5-6" />
                                                    </svg>
                                                    <strong>Diterima oleh:</strong>
                                                    <?= htmlspecialchars($item['nama_penerima_barang']) ?> |
                                                    <?= date('d/m/Y H:i', strtotime($item['tanggal_terima'])) ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($is_fully_checked): ?>
                                                <a href="../database/export-pdf-pengiriman.php?id=<?= $item['id'] ?>" target="_blank" class="btn btn-info">
                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z" />
                                                        <path d="M14 3v5h5" />
                                                        <path d="M9 13h6M9 17h6" />
                                                    </svg>
                                                    Cetak Surat Jalan
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($is_admin): ?>
                                                <a href="tambah.php?edit=<?= $item['id'] ?>" class="btn btn-primary">
                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M4 20l4-1 11-11-3-3L5 16l-1 4z" />
                                                        <path d="M14 4l3 3" />
                                                    </svg>
                                                    Edit
                                                </a>
                                                <a href="?hapus=<?= $item['id'] ?>" class="btn btn-danger"
                                                    onclick="return confirm('Yakin ingin menghapus data ini?')">
                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M5 7h14M9 7V4h6v3M7 7l1 13h8l1-13" />
                                                    </svg>
                                                    Hapus
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
        <footer>
            <p>&copy; <?= date('Y') ?> Created By Muhammad Zulfahmi</p>
        </footer>
    </div>
    <script src="script.js"></script>
</body>

</html>