<?php
session_start();
require_once '../database/koneksi.php';
require_once '../database/helper-stok.php';
require_once '../database/stok_helper.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$is_operator = ($_SESSION['role'] === 'operator');
$lokasiSession = $_SESSION['lokasi'] ?? 'semua';
$lokasiMap = ['sodong' => 'Sodong', 'sariwangi' => 'Sariwangi', 'manonjaya' => 'Manonjaya', 'semua' => 'Semua'];

// Handle delete (Hanya Admin)
if (isset($_GET['hapus']) && $is_admin) {
    $id = (int)$_GET['hapus'];

    try {
        $pdo->beginTransaction();
        $pdo_draft->beginTransaction();

        $stmt_pengiriman_row = $pdo->prepare("SELECT lokasi FROM pengiriman WHERE id = ?");
        $stmt_pengiriman_row->execute([$id]);
        $pengiriman_row = $stmt_pengiriman_row->fetch();

        // ✅ KEMBALIKAN STOK GUDANG PUSAT sebelum hapus
        $stmt_details = $pdo->prepare("SELECT nama_barang, qty FROM detail_pengiriman WHERE pengiriman_id = ?");
        $stmt_details->execute([$id]);
        $details_to_restore = $stmt_details->fetchAll();

        foreach ($details_to_restore as $d) {
            kembalikanStokGudangPusat($pdo_draft, $d['nama_barang'], $d['qty'], "Hapus pengiriman ID #$id - stok dikembalikan");
        }

        // ═══════════════════════════════════════════════════════
        // ✅ FIX BUG: kalau pengiriman ini SUDAH dikonfirmasi diterima,
        // balikin dulu (kurangi) stok tujuan di `stok_barang` sesuai
        // qty_diterima, SEBELUM detail_penerimaan/penerimaan dihapus.
        // Tanpa ini, qty yang sudah pernah masuk ke stok_barang akan
        // nyangkut selamanya walau pengirimannya sudah dihapus.
        // ═══════════════════════════════════════════════════════
        $lokasi_hapus = $pengiriman_row['lokasi'] ?? 'semua';
        $stmt_dp_confirmed = $pdo->prepare("SELECT dpr.qty_diterima, dp.nama_barang, dp.satuan
            FROM detail_penerimaan dpr
            INNER JOIN penerimaan pr ON pr.id = dpr.penerimaan_id
            INNER JOIN detail_pengiriman dp ON dp.id = dpr.detail_pengiriman_id
            WHERE pr.pengiriman_id = ?");
        $stmt_dp_confirmed->execute([$id]);
        foreach ($stmt_dp_confirmed->fetchAll() as $dc) {
            if ($dc['qty_diterima'] === null || (float)$dc['qty_diterima'] == 0) continue;
            stok_upsertGrosir($pdo, $dc['nama_barang'], $dc['satuan'], $lokasi_hapus, -1 * $dc['qty_diterima']);
        }

        $pdo->prepare("DELETE dp FROM detail_penerimaan dp
            INNER JOIN penerimaan pr ON pr.id = dp.penerimaan_id
            WHERE pr.pengiriman_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM penerimaan WHERE pengiriman_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM detail_pengiriman WHERE pengiriman_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM pengiriman WHERE id = ?")->execute([$id]);

        $pdo->commit();
        $pdo_draft->commit();
        header("Location: index.php?msg=deleted");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $pdo_draft->rollBack();
        die("Gagal hapus: " . $e->getMessage());
    }
}

// ✅ QUERY DENGAN FILTER LOKASI
$where = "";
$params = [];
if ($is_operator && $lokasiSession !== 'semua') {
    $where = "WHERE p.lokasi = ?";
    $params[] = $lokasiSession;
}

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
$where
ORDER BY p.tanggal_ekspedisi DESC, p.no_surat_jalan DESC";

if (!empty($params)) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
} else {
    $stmt = $pdo->query($query);
}
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
                <a href="../dashboard.php" class="btn btn-secondary">
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
            <?php if ($is_operator && $lokasiSession !== 'semua'): ?>
                <div class="alert alert-info" style="margin-bottom: 20px; border-left: 4px solid var(--amber);">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                        <circle cx="12" cy="10" r="3" />
                    </svg>
                    Anda login sebagai Operator <strong><?= htmlspecialchars($lokasiMap[$lokasiSession] ?? $lokasiSession) ?></strong>.
                    Hanya menampilkan data pengiriman untuk dapur Anda.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['msg'])): ?>
                <div class="alert alert-success" id="alertMsg">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M8 12l3 3 5-6" />
                    </svg>
                    <?php
                    $msg = $_GET['msg'];
                    if ($msg == 'deleted') echo 'Data berhasil dihapus!';
                    elseif ($msg == 'saved') echo 'Data pengiriman berhasil disimpan!';
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
                                <span class="badge"><?= count($items) ?> Surat Jalan</span>
                            </div>
                            <svg class="icon arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 9l6 6 6-6" />
                            </svg>
                        </div>
                        <div class="date-content">
                            <?php foreach ($items as $item): ?>
                                <?php
                                $stmt_d = $pdo->prepare("
                                SELECT dp.*,
                                dpr.status_barang AS terima_status,
                                dpr.keterangan AS terima_ket,
                                dpr.keterangan_kemasan AS terima_ket_kemasan,
                                dpr.foto_kemasan AS terima_foto_kemasan
                                FROM detail_pengiriman dp
                                LEFT JOIN penerimaan pr ON pr.pengiriman_id = dp.pengiriman_id
                                LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id
                                AND dpr.penerimaan_id = pr.id
                                WHERE dp.pengiriman_id = ?
                                ");
                                $stmt_d->execute([$item['id']]);
                                $details = $stmt_d->fetchAll();
                                $is_fully_checked = ($item['item_sudah_diterima'] == $item['total_item'] && $item['total_item'] > 0);
                                $has_issue = ($item['item_bermasalah'] > 0);
                                $is_partial = ($item['item_sudah_diterima'] > 0 && !$is_fully_checked);
                                $lokasiDisplay = $lokasiMap[$item['lokasi']] ?? $item['lokasi'];
                                ?>
                                <div class="accordion-item">
                                    <div class="accordion-header" onclick="toggleAccordion(this)">
                                        <div class="faktur-info">
                                            <strong>
                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z" />
                                                    <path d="M14 3v5h5" />
                                                </svg>
                                                <?= htmlspecialchars($item['no_surat_jalan']) ?>
                                            </strong>
                                            <!-- ✅ BADGE LOKASI DAPUR -->
                                            <span class="badge-info" style="background: var(--amber);">📍 <?= htmlspecialchars($lokasiDisplay) ?></span>
                                            <span>
                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 7l9-4 9 4-9 4-9-4z" />
                                                    <path d="M3 7v10l9 4 9-4V7" />
                                                </svg>
                                                Tujuan: <?= htmlspecialchars($item['nama_sppg']) ?>
                                            </span>
                                            <span>
                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="1" y="3" width="15" height="13" rx="2" />
                                                    <path d="M16 8h4l3 3v5h-7V8z" />
                                                    <circle cx="5.5" cy="18.5" r="2.5" />
                                                    <circle cx="18.5" cy="18.5" r="2.5" />
                                                </svg>
                                                Unit: <?= htmlspecialchars($item['ekspedisi'] ?? '-') ?>
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
                                        <table class="table-detail">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nama Barang</th>
                                                    <th>Qty</th>
                                                    <th>Satuan</th>
                                                    <th>Status </th>
                                                    <th>Foto</th>
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
                                                            <?php if ($detail['terima_foto_kemasan']): ?>
                                                                <button type="button" class="btn-foto"
                                                                    onclick="bukaFotoModal('../uploads/foto-perkemasan/<?= htmlspecialchars($detail['terima_foto_kemasan']) ?>', <?= htmlspecialchars(json_encode($detail['nama_barang']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($detail['terima_ket_kemasan'] ?: ''), ENT_QUOTES) ?>)">
                                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                                        <rect x="3" y="5" width="18" height="14" rx="2" />
                                                                        <circle cx="8.5" cy="10.5" r="1.5" />
                                                                        <path d="M21 15l-5-5L5 19" />
                                                                    </svg>
                                                                    Lihat
                                                                </button>
                                                            <?php else: ?>
                                                                <span style="color:var(--ink-faint); font-size:12px;">-</span>
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
                                                            <?php elseif ($detail['terima_ket_kemasan']): ?>
                                                                <span style="color:var(--ink-soft); font-size:12px;">
                                                                    <?= htmlspecialchars($detail['terima_ket_kemasan']) ?>
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
                                                    <th colspan="4">Item</th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                        <div class="action-buttons">
                                            <a href="../database/export-pdf-pengiriman.php?id=<?= $item['id'] ?>" target="_blank" class="btn btn-info">
                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z" />
                                                    <path d="M14 3v5h5" />
                                                    <path d="M9 13h6M9 17h6" />
                                                </svg>
                                                Cetak Surat Jalan
                                            </a>
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
                                                <?php if ($is_operator): ?>
                                                    <a href="../penerimaan/index.php?id=<?= $item['id'] ?>" class="btn btn-primary">
                                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M4 20l4-1 11-11-3-3L5 16l-1 4z" />
                                                            <path d="M14 4l3 3" />
                                                        </svg>
                                                        Edit Penerimaan
                                                    </a>
                                                <?php endif; ?>
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

    <!-- Modal Foto Kemasan -->
    <div class="foto-modal-overlay" id="fotoModalOverlay" onclick="if(event.target===this) tutupFotoModal()">
        <div class="foto-modal-box">
            <button type="button" class="foto-modal-close" onclick="tutupFotoModal()" aria-label="Tutup">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
                    <path d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
            <div class="foto-modal-title" id="fotoModalTitle">Foto Kemasan</div>
            <img src="" alt="Foto Kemasan" id="fotoModalImg">
            <div class="foto-modal-caption" id="fotoModalCaption"></div>
        </div>
    </div>

    <script src="script.js"></script>
</body>

</html>