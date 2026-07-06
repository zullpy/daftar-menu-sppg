<?php
// laporan/stok.php
session_start();
require '../database/koneksi.php'; // menyediakan $pdo (koneksi ke db_mbg)
if (!isset($_SESSION['role'])) {
    header('Location: ../index.php');
    exit;
}
$role = $_SESSION['role'];
$userRole = strtolower($role);
$lokasiSession = $_SESSION['lokasi'] ?? 'sodong';
$namaLokasi = $_SESSION['nama_op'] ?? '';

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// ===== KONEKSI TAMBAHAN KE db_draft_barang =====
try {
    $pdoBarang = new PDO('mysql:host=localhost;dbname=db_draft_barang;charset=utf8mb4', 'root', '');
    $pdoBarang->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    $pdoBarang = null;
}

$LOKASI_LIST  = ['sodong', 'sariwangi', 'manonjaya'];
$LOKASI_LABEL = [
    'sodong'    => 'Sodong',
    'sariwangi' => 'Sariwangi',
    'manonjaya' => 'Manonjaya',
];

// Lokasi yang boleh dilihat user ini.
if ($userRole === 'operator') {
    $visibleLokasi = in_array($lokasiSession, $LOKASI_LIST) ? [$lokasiSession] : ['sodong'];
} else {
    $visibleLokasi = $LOKASI_LIST;
}

// ===== 1. MAPPING SATUAN GROSIR/ECERAN DARI db_draft_barang =====
$barangMap = [];
if ($pdoBarang) {
    $stmtB = $pdoBarang->query("SELECT nama_barang, satuan, satuan_eceran, isi_per_satuan, harga_beli, harga_eceran FROM barang");
    foreach ($stmtB->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $key = strtolower(trim($b['nama_barang']));
        $satuanGrosir    = trim($b['satuan'] ?? '') ?: '-';
        $satuanEceranRaw = trim($b['satuan_eceran'] ?? '');
        $isiRaw          = ((float)($b['isi_per_satuan'] ?? 0) > 0) ? (float)$b['isi_per_satuan'] : null;

        $hasEceran = ($satuanEceranRaw !== '') && $isiRaw;
        $hargaBeli      = (float)($b['harga_beli'] ?? 0);
        $hargaEceranRaw = (float)($b['harga_eceran'] ?? 0);

        if ($hasEceran && $hargaEceranRaw > 0) {
            $hargaEceran = $hargaEceranRaw;
        } elseif ($hasEceran) {
            $hargaEceran = $hargaBeli / $isiRaw;
        } else {
            $hargaEceran = $hargaBeli;
        }

        $barangMap[$key] = [
            'satuan_grosir'  => $satuanGrosir,
            'satuan_eceran'  => $hasEceran ? $satuanEceranRaw : $satuanGrosir,
            'isi_per_satuan' => $hasEceran ? $isiRaw : null,
            'harga_beli'     => $hargaBeli,
            'harga_eceran'   => $hargaEceran,
        ];
    }
}

// ===== 2. STOK LANGSUNG DARI stok_barang =====
$stokPerLokasi = [];
$allItemKeys = [];

foreach ($visibleLokasi as $lokasi) {
    $stmt = $pdo->prepare("SELECT nama_barang, qty_grosir, qty_eceran FROM stok_barang WHERE lokasi = :lokasi");
    $stmt->execute([':lokasi' => $lokasi]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = strtolower(trim($r['nama_barang']));
        if (!isset($stokPerLokasi[$lokasi][$key])) {
            $stokPerLokasi[$lokasi][$key] = ['grosir' => 0.0, 'eceran' => 0.0];
        }
        $stokPerLokasi[$lokasi][$key]['grosir'] += (float)$r['qty_grosir'];
        $stokPerLokasi[$lokasi][$key]['eceran'] += (float)$r['qty_eceran'];
        $allItemKeys[$key] = trim($r['nama_barang']);
    }
}

// ===== 3. SUSUN LAPORAN STOK FINAL =====
$stockReport = [];
$totalStokEceran = 0;
$totalItemAktif = 0;

foreach ($allItemKeys as $key => $namaTampil) {
    $bMap = $barangMap[$key] ?? null;
    $satuanGrosir = $bMap['satuan_grosir'] ?? '-';
    $satuanEceran = $bMap['satuan_eceran'] ?? $satuanGrosir;
    $isi = $bMap['isi_per_satuan'] ?? null;

    $row = [
        'nama_barang'    => $namaTampil,
        'satuan'         => $satuanGrosir,
        'satuan_eceran'  => $satuanEceran,
        'isi_per_satuan' => $isi,
        'lokasi'         => [],
    ];

    $itemTotalEceran = 0;
    $hasStock = false;

    foreach ($visibleLokasi as $lokasi) {
        $grosir = $stokPerLokasi[$lokasi][$key]['grosir'] ?? 0.0;
        $eceran = $stokPerLokasi[$lokasi][$key]['eceran'] ?? 0.0;

        $row['lokasi'][$lokasi] = [
            'stok_grosir' => $grosir,
            'stok_eceran' => $eceran,
        ];

        $itemTotalEceran += $eceran;
        if ($grosir > 0 || $eceran > 0) $hasStock = true;
    }

    $row['total_stok_eceran'] = $itemTotalEceran;

    if ($hasStock) {
        $stockReport[] = $row;
        $totalStokEceran += $itemTotalEceran;
        $totalItemAktif++;
    }
}

usort($stockReport, function ($a, $b) {
    return strcasecmp($a['nama_barang'], $b['nama_barang']);
});

function formatAngka($angka) {
    if ($angka == floor($angka)) return number_format($angka, 0, ',', '.');
    return number_format($angka, 2, ',', '.');
}

$stockJson = json_encode($stockReport, JSON_UNESCAPED_UNICODE);
$visibleLokasiJson = json_encode($visibleLokasi, JSON_UNESCAPED_UNICODE);
$lokasiLabelJson = json_encode($LOKASI_LABEL, JSON_UNESCAPED_UNICODE);

// KOLOM TOTAL HANYA UNTUK ADMIN
$showTotalColumn = ($userRole === 'admin') ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Data Stok Barang - Bina Usaha Sauyunan</title>
<link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="stok.css">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
<!-- TOPBAR -->
<div class="topbar">
    <a href="../dashboard.php" class="btn-back" aria-label="Kembali"><i class="ph ph-arrow-left"></i></a>
    <div class="topbar-info">
        <h1>Data Stok Dapur</h1>
        <p>Monitoring Persediaan · Gudang Cabang</p>
    </div>
    <div class="topbar-badges">
        <span class="badge <?= $userRole === 'admin' ? 'badge-admin' : 'badge-operator' ?>">
            <?= $userRole === 'admin' ? 'Admin' : 'Operator' ?>
        </span>
    </div>
</div>

<!-- METRICS -->
<div class="metrics-wrap">
    <div class="metric-card">
        <div class="metric-icon-row">
            <div class="metric-icon icon-keluar"><i class="ph-fill ph-package"></i></div>
            <span class="metric-label">Total Stok Eceran</span>
        </div>
        <div class="metric-val val-keluar" id="metricStok"><?= formatAngka($totalStokEceran) ?></div>
        <div class="metric-sub">Total satuan terkecil</div>
    </div>
    <div class="metric-card ">
        <div class="metric-icon-row">
            <div class="metric-icon icon-aman"><i class="ph-fill ph-list-checks"></i></div>
            <span class="metric-label">Jumlah Item Aktif</span>
        </div>
        <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
            <div class="metric-val val-aman" id="metricItem"><?= $totalItemAktif ?></div>
            <span class="metric-status status-aman">Barang tersedia</span>
        </div>
        <div class="metric-sub">Dari <?= count($visibleLokasi) ?> gudang cabang</div>
    </div>
</div>

<!-- CONTROLS -->
<div class="controls">
    <div class="search-wrap">
        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
        <input type="text" id="searchInput" class="search-input" placeholder="Cari nama barang..." oninput="doFilter()" aria-label="Cari barang">
    </div>
    <div class="filter-row">
        <select id="statusFilter" class="filter-select" onchange="doFilter()" aria-label="Filter status">
            <option value="">Semua Status</option>
            <option value="Aman">Aman</option>
            <option value="Menipis">Menipis</option>
            <option value="Rendah">Rendah</option>
            <option value="Habis">Habis</option>
        </select>
    </div>
</div>

<!-- LIST HEADER -->
<div class="section-hdr">
    <span>Tabel Stok Barang</span>
    <span class="count-pill" id="countPill">0 item</span>
</div>

<!-- TABEL STOK -->
<div class="table-wrap">
    <div class="table-scroll">
        <table class="stok-table">
            <thead>
                <tr id="tableHeadRow"></tr>
            </thead>
            <tbody id="tbody"></tbody>
        </table>
    </div>
    <div class="table-footer" id="tableFooter">Menampilkan 0 barang</div>
</div>

<!-- BOTTOM SHEET -->
<div class="bs-overlay" id="bsOverlay" onclick="closeBs(event)">
    <div class="bs-sheet" id="bsSheet">
        <div class="bs-handle"></div>
        <div class="bs-item-head">
            <div>
                <div class="bs-item-name" id="bsName"></div>
                <div class="bs-item-satuan" id="bsSatuan"></div>
            </div>
            <button class="bs-close" onclick="document.getElementById('bsOverlay').classList.remove('open')" aria-label="Tutup"><i class="ph ph-x"></i></button>
        </div>
        <div class="bs-divider"></div>
        <div id="bsContent"></div>
    </div>
</div>

<script>
const STOCK_DATA = <?= $stockJson ?>;
const VISIBLE_LOKASI = <?= $visibleLokasiJson ?>;
const LOKASI_LABEL = <?= $lokasiLabelJson ?>;
const SHOW_TOTAL_COLUMN = <?= $showTotalColumn ?>;
</script>
<script src="stok.js"></script>
</body>
</html>