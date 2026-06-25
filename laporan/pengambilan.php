<?php
// pengambilan.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require '../database/koneksi.php';

if (!isset($_SESSION['role'])) {
    header('Location: index.php');
    exit;
}

$userRole = strtolower($_SESSION['role']);
$lokasiSession = $_SESSION['lokasi'] ?? 'semua';
$lokasiMap = ['sodong' => 'Sodong', 'sariwangi' => 'Sariwangi', 'manonjaya' => 'Manonjaya', 'semua' => 'Semua'];
$namaLokasiDisplay = $lokasiMap[$lokasiSession] ?? $lokasiSession;

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

$filterDari  = $_GET['dari'] ?? '';
$filterSampai = $_GET['sampai'] ?? '';
$filterSppg  = $_GET['sppg'] ?? '';

$where = [];
$params = [];

// ✅ FILTER LOKASI UNTUK OPERATOR
if ($userRole === 'operator' && $lokasiSession !== 'semua') {
    $where[] = "pb.lokasi = :lokasi";
    $params[':lokasi'] = $lokasiSession;
}

if (!empty($filterDari)) {
    $where[] = "pb.tanggal_pengambilan >= :dari";
    $params[':dari'] = $filterDari;
}
if (!empty($filterSampai)) {
    $where[] = "pb.tanggal_pengambilan <= :sampai";
    $params[':sampai'] = $filterSampai;
}
if (!empty($filterSppg)) {
    $where[] = "(pb.nama_sppg LIKE :sppg OR pb.nama_pengambil LIKE :sppg2)";
    $params[':sppg'] = '%' . $filterSppg . '%';
    $params[':sppg2'] = '%' . $filterSppg . '%';
}

$whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : '';

$sql = "SELECT pb.*,
    (SELECT COUNT(*) FROM pengambilan_barang_detail WHERE id_pengambilan = pb.id_pengambilan) AS jumlah_item
    FROM pengambilan_barang pb
    $whereSql
    ORDER BY pb.tanggal_pengambilan DESC, pb.nama_pengambil ASC, pb.id_pengambilan DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// Grouping Data
$dataGrouped = [];
$totalLaporan = 0;
while ($row = $stmt->fetch()) {
    $tgl = $row['tanggal_pengambilan'];
    $pengambil = $row['nama_pengambil'] ?: 'Tidak Diketahui';
    $dataGrouped[$tgl][$pengambil][] = $row;
    $totalLaporan++;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Pengambilan Stok Barang - Bina Usaha Sauyunan</title>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="pengambilan.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Info banner lokasi operator */
        .lokasi-info-banner {
            background: linear-gradient(135deg, #e0f2fe, #bae6fd);
            border: 1px solid #7dd3fc;
            border-left: 4px solid #0284c7;
            color: #075985;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
        }

        .lokasi-info-banner i {
            font-size: 20px;
            color: #0284c7;
        }

        .lokasi-info-banner strong {
            color: #0369a1;
        }

        /* Badge lokasi di item */
        .badge-lokasi {
            background: #dbeafe;
            color: #1e40af;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>
</head>

<body>
    <div class="page-header">
        <div class="header-top">
            <div class="header-left">
                <a href="?logout=1" class="btn-back" onclick="return confirm('Yakin ingin keluar?')">
                    <i class="ph ph-arrow-left"></i>
                    <span class="full">Kembali</span>
                </a>
                <div class="header-title-wrap">
                    <h1>Laporan Pengambilan Barang</h1>
                    <div class="header-subtitle">
                        <?= date('d M Y') ?> • <?= count($dataGrouped) ?> pengambil • <?= $totalLaporan ?> laporan
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <span class="role-badge <?= $userRole === 'admin' ? 'role-admin' : 'role-operator' ?>">
                    <?= $userRole === 'admin' ? 'Admin' : 'Operator' ?>
                </span>
                <!-- ✅ Tombol Tambah Laporan sekarang bisa untuk Admin & Operator -->
                <button class="btn-add" onclick="openModal()">
                    <i class="ph ph-plus"></i>
                    <span>Tambah Laporan</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ✅ INFO BANNER KHUSUS OPERATOR -->
    <?php if ($userRole === 'operator' && $lokasiSession !== 'semua'): ?>
        <div class="lokasi-info-banner">
            <i class="ph-fill ph-warehouse"></i>
            <span>
                Anda login sebagai Operator <strong>Dapur <?= htmlspecialchars($namaLokasiDisplay) ?></strong>.
                Data di bawah ini hanya menampilkan stok keluar dari gudang dapur Anda.
            </span>
        </div>
    <?php endif; ?>

    <button type="button" class="filter-toggle-btn" id="filterToggleBtn" onclick="toggleFilter()">
        <span style="display:flex; align-items:center; gap:8px;">
            <i class="ph ph-funnel"></i> Filter Data
            <?php if ($filterDari || $filterSampai || $filterSppg): ?>
                <span class="count-badge">aktif</span>
            <?php endif; ?>
        </span>
        <i class="ph ph-caret-down chev"></i>
    </button>

    <form class="filter-bar" id="filterBar" method="GET">
        <div class="filter-group">
            <label>Dari Tanggal</label>
            <input type="date" name="dari" value="<?= htmlspecialchars($filterDari) ?>">
        </div>
        <div class="filter-group">
            <label>Sampai Tanggal</label>
            <input type="date" name="sampai" value="<?= htmlspecialchars($filterSampai) ?>">
        </div>
        <div class="filter-group">
            <label>Cari SPPG / Pengambil</label>
            <input type="text" name="sppg" placeholder="Cari..." value="<?= htmlspecialchars($filterSppg) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="ph ph-funnel"></i> Filter</button>
        <a href="pengambilan.php" class="btn btn-outline"><i class="ph ph-x"></i> Reset</a>
    </form>

    <?php if (empty($dataGrouped)): ?>
        <div class="empty-state">
            <i class="ph ph-package"></i>
            <p>Belum ada data pengambilan stok barang.</p>
            <?php if ($userRole === 'operator' && $lokasiSession !== 'semua'): ?>
                <p style="font-size: 13px; color: var(--text-muted);">
                    Belum ada laporan pengambilan untuk <strong>Dapur <?= htmlspecialchars($namaLokasiDisplay) ?></strong>.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($dataGrouped as $tanggal => $pengambilData): ?>
            <div class="date-section">
                <div class="date-section-title">
                    <i class="ph ph-calendar"></i>
                    <span><?= date('d F Y', strtotime($tanggal)) ?></span>
                    <span class="count-badge"><?= array_sum(array_map('count', $pengambilData)) ?> laporan</span>
                </div>
                <?php foreach ($pengambilData as $namaPengambil => $items): ?>
                    <?php $pengambilId = 'peng-' . md5($namaPengambil . $tanggal); ?>
                    <div class="pengambil-group">
                        <div class="pengambil-header" onclick="toggleAccordion('<?= $pengambilId ?>')">
                            <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                                <i class="ph ph-user"></i>
                                <span class="label"><?= htmlspecialchars($namaPengambil) ?></span>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                                <span class="count-badge"><?= count($items) ?> laporan</span>
                                <i class="ph ph-caret-down chev"></i>
                            </div>
                        </div>
                        <div class="pengambil-body" id="<?= $pengambilId ?>">
                            <?php foreach ($items as $item): ?>
                                <div class="row-item">
                                    <div class="main">
                                        <span class="no-pengambilan">
                                            <?= htmlspecialchars($item['no_pengambilan']) ?>
                                            <!-- ✅ BADGE LOKASI DAPUR -->
                                            <span class="badge-lokasi">
                                                <i class="ph-fill ph-map-pin" style="font-size:12px;"></i>
                                                <?= htmlspecialchars($lokasiMap[$item['lokasi']] ?? $item['lokasi']) ?>
                                            </span>
                                        </span>
                                        <span class="sub">
                                            <span><i class="ph ph-storefront"></i> <?= htmlspecialchars($item['nama_sppg']) ?></span>
                                            <span><i class="ph ph-clock"></i> <?= htmlspecialchars($item['jam_pengambilan']) ?></span>
                                            <?php if (!empty($item['no_kontak'])): ?>
                                                <span><i class="ph ph-phone"></i> <?= htmlspecialchars($item['no_kontak']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="actions">
                                        <span class="status-badge status-<?= $item['status'] ?>">
                                            <?= $item['status'] === 'verified' ? '✓ Faktur Dibuat' : '⏳ Pending' ?>
                                        </span>
                                        <span class="count-badge"><?= $item['jumlah_item'] ?> item</span>
                                        <button class="btn btn-outline" onclick="lihatDetail(<?= $item['id_pengambilan'] ?>, '<?= htmlspecialchars($item['no_pengambilan'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['nama_sppg'], ENT_QUOTES) ?>')">
                                            detail
                                        </button>
                                        <?php if ($userRole === 'admin' && $item['status'] !== 'verified'): ?>
                                            <button class="btn btn-success" onclick="verifikasiLaporan(<?= $item['id_pengambilan'] ?>)">
                                                <i class="ph ph-check"></i> Sudah Dibuatkan Faktur
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- FAB Tambah (mobile only) -->
    <button class="fab-add" onclick="openModal()" aria-label="Tambah Laporan">
        <i class="ph ph-plus"></i>
    </button>

    <!-- Modal Tambah Laporan (Sekarang bisa untuk Admin & Operator) -->
    <div class="modal-overlay" id="modalTambah">
        <div class="modal-box">
            <div class="modal-drag-handle"></div>
            <h2><i class="ph ph-plus-circle"></i> Tambah Laporan Pengambilan</h2>
            <form id="formTambah">
                <div class="form-grid">
                    <!-- ✅ FIELD LOKASI DAPUR -->
                    <?php if ($userRole === 'admin'): ?>
                        <div class="form-group">
                            <label>Lokasi Dapur (Gudang Asal) *</label>
                            <select name="lokasi" required>
                                <option value="sodong">Dapur Sodong</option>
                                <option value="sariwangi">Dapur Sariwangi</option>
                                <option value="manonjaya">Dapur Manonjaya</option>
                            </select>
                        </div>
                    <?php else: ?>
                        <!-- Operator otomatis terkunci di dapurnya -->
                        <input type="hidden" name="lokasi" value="<?= htmlspecialchars($lokasiSession) ?>">
                        <div class="form-group">
                            <label>Lokasi Dapur (Otomatis)</label>
                            <input type="text" value="Dapur <?= htmlspecialchars($namaLokasiDisplay) ?>" readonly style="background:#f1f5f9; font-weight:600; color:#0284c7;">
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Nama Pengambil *</label>
                        <input type="text" name="nama_pengambil" required>
                    </div>
                    <div class="form-group">
                        <label>Nama SPPG *</label>
                        <input type="text" name="nama_sppg" required>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Pengambilan *</label>
                        <input type="date" name="tanggal_pengambilan" id="tanggal_pengambilan" required>
                    </div>
                    <div class="form-group">
                        <label>Jam Pengambilan (Otomatis)</label>
                        <input type="text" name="jam_pengambilan" id="jam_pengambilan"
                            readonly placeholder="15:00"
                            pattern="([01]\d|2[0-3]):[0-5]\d"
                            style="font-weight:600; letter-spacing:1px;">
                    </div>
                    <div class="form-group full">
                        <label>Nomor Kontak</label>
                        <input type="text" name="no_kontak" placeholder="08xxx" inputmode="tel">
                    </div>
                </div>
                <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <label style="font-weight:700; font-size:14px;">Detail Barang</label>
                    <button type="button" class="btn btn-primary" onclick="addBarangRow()">
                        <i class="ph ph-plus"></i> Tambah Barang
                    </button>
                </div>
                <div class="barang-header">
                    <span>Nama Barang</span>
                    <span>Qty</span>
                    <span>Satuan</span>
                    <span></span>
                </div>
                <div id="barangContainer"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="ph ph-floppy-disk"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Detail -->
    <div class="modal-overlay" id="modalDetail">
        <div class="modal-box">
            <div class="modal-drag-handle"></div>
            <h2 id="detailTitle"></h2>
            <div class="meta" id="detailMeta" style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;"></div>
            <table class="detail-table">
                <thead>
                    <tr>
                        <th>Nama Barang</th>
                        <th>Qty</th>
                        <th>Satuan</th>
                    </tr>
                </thead>
                <tbody id="detailBody">
                    <tr>
                        <td colspan="3" style="text-align:center; color:#999;">Memuat...</td>
                    </tr>
                </tbody>
            </table>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeDetail()">Tutup</button>
            </div>
        </div>
    </div>

    <script src="pengambilan.js"></script>
</body>

</html>