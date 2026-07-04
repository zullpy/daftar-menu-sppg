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
// Dipakai buat ambil info satuan grosir/eceran, isi_per_satuan, harga.
// GANTI host/user/pass/dbname di bawah ini kalau kredensialnya beda dari koneksi.php kamu.
try {
    $pdoBarang = new PDO('mysql:host=localhost;dbname=db_draft_barang;charset=utf8mb4', 'root', '');
    $pdoBarang->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    $pdoBarang = null; // kalau gagal konek, sistem tetap jalan (fallback: semua barang dianggap satuan tunggal)
}

$LOKASI_LIST  = ['sodong', 'sariwangi', 'manonjaya'];
$LOKASI_LABEL = [
    'sodong'    => 'Sodong',
    'sariwangi' => 'Sariwangi',
    'manonjaya' => 'Manonjaya',
];
$LOKASI_CLASS = [
    'sodong'    => 'col-sodong',
    'sariwangi' => 'col-sariwangi',
    'manonjaya' => 'col-manonjaya',
];

// Lokasi yang boleh dilihat user ini.
// Operator cuma boleh lihat lokasinya sendiri (data lokasi lain tidak ikut di-query sama sekali).
if ($userRole === 'operator') {
    $visibleLokasi = in_array($lokasiSession, $LOKASI_LIST) ? [$lokasiSession] : ['sodong'];
} else {
    $visibleLokasi = $LOKASI_LIST; // admin lihat 3 lokasi sekaligus dalam satu tabel
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

        // Barang dianggap punya sistem grosir/eceran HANYA kalau satuan_eceran
        // terisi DAN isi_per_satuan > 0. Kalau salah satu kosong, satuan tunggal
        // (stok eceran = stok grosir, tidak dikonversi).
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

// Kategori lookup dari belanja_detail (db_mbg)
$categoryLookup = [];
$catStmt = $pdo->query("SELECT DISTINCT TRIM(UPPER(item_barang)) AS item_key, kategori FROM belanja_detail WHERE kategori IS NOT NULL AND kategori != ''");
while ($row = $catStmt->fetch()) {
    $categoryLookup[$row['item_key']] = $row['kategori'];
}

// ===== 2. MASUK & KELUAR PER LOKASI (hanya lokasi yang boleh dilihat) =====
$stockPerLokasi = []; // [lokasi][item_key] = ['nama'=>, 'satuan_asli'=>, 'masuk'=>, 'keluar'=>]

foreach ($visibleLokasi as $lokasi) {
    // STOK MASUK = detail_pengiriman yang sudah diterima
    $sqlMasuk = "SELECT
            TRIM(dp.nama_barang) AS item_nama_raw,
            TRIM(UPPER(dp.nama_barang)) AS item_key,
            dp.satuan,
            SUM(CASE WHEN dpr.status_barang = 'tidak_ada' THEN 0 ELSE dp.qty END) AS total_masuk
        FROM detail_pengiriman dp
        JOIN pengiriman p ON dp.pengiriman_id = p.id
        LEFT JOIN penerimaan pr ON pr.pengiriman_id = p.id
        LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id AND dpr.penerimaan_id = pr.id
        WHERE p.lokasi = :lokasi
        GROUP BY TRIM(UPPER(dp.nama_barang)), dp.satuan";
    $stmt = $pdo->prepare($sqlMasuk);
    $stmt->execute([':lokasi' => $lokasi]);
    foreach ($stmt->fetchAll() as $inc) {
        $key = $inc['item_key'];
        if (!isset($stockPerLokasi[$lokasi][$key])) {
            $stockPerLokasi[$lokasi][$key] = [
                'nama'        => $inc['item_nama_raw'],
                'satuan_asli' => $inc['satuan'],
                'masuk'       => 0.0,
                'keluar'      => 0.0,
            ];
        }
        $stockPerLokasi[$lokasi][$key]['masuk'] += (float)$inc['total_masuk'];
    }

    // STOK KELUAR = pengambilan_barang_detail
    $sqlKeluar = "SELECT
            TRIM(pbd.nama_barang) AS item_nama_raw,
            TRIM(UPPER(pbd.nama_barang)) AS item_key,
            pbd.satuan,
            SUM(pbd.qty) AS total_keluar
        FROM pengambilan_barang_detail pbd
        JOIN pengambilan_barang pb ON pbd.id_pengambilan = pb.id_pengambilan
        WHERE pb.lokasi = :lokasi
        GROUP BY TRIM(UPPER(pbd.nama_barang)), pbd.satuan";
    $stmt2 = $pdo->prepare($sqlKeluar);
    $stmt2->execute([':lokasi' => $lokasi]);
    foreach ($stmt2->fetchAll() as $out) {
        $key = $out['item_key'];
        if (!isset($stockPerLokasi[$lokasi][$key])) {
            $stockPerLokasi[$lokasi][$key] = [
                'nama'        => $out['item_nama_raw'],
                'satuan_asli' => $out['satuan'],
                'masuk'       => 0.0,
                'keluar'      => 0.0,
            ];
        }
        $stockPerLokasi[$lokasi][$key]['keluar'] += (float)$out['total_keluar'];
    }
}

// ===== 3. KUMPULKAN SEMUA ITEM YANG MUNCUL DI LOKASI MANAPUN (yang visible) =====
$allKeys = []; // item_key => nama tampilan
foreach ($visibleLokasi as $lokasi) {
    foreach (($stockPerLokasi[$lokasi] ?? []) as $key => $v) {
        if (!isset($allKeys[$key])) $allKeys[$key] = $v['nama'];
    }
}

// ===== 4. SUSUN LAPORAN STOK FINAL (per item, per lokasi, grosir & eceran) =====
$stockReport = [];
foreach ($allKeys as $key => $namaTampil) {
    $bMap = $barangMap[strtolower(trim($namaTampil))] ?? null;

    $satuanGrosir  = $bMap['satuan_grosir'] ?? null;
    $satuanEceran  = $bMap['satuan_eceran'] ?? null;
    $isi           = $bMap['isi_per_satuan'] ?? null;

    $row = [
        'nama_barang'    => $namaTampil,
        'kategori'       => $categoryLookup[$key] ?? 'Tambahan',
        'satuan'         => $satuanGrosir,
        'satuan_eceran'  => $satuanEceran,
        'isi_per_satuan' => $isi,
        'lokasi'         => [],
    ];

    $totalMasukG = 0.0;
    $totalKeluarG = 0.0;
    $totalStokG = 0.0;
    $totalStokE = 0.0;

    foreach ($visibleLokasi as $lokasi) {
        $d = $stockPerLokasi[$lokasi][$key] ?? null;
        $masuk  = $d['masuk'] ?? 0.0;
        $keluar = $d['keluar'] ?? 0.0;
        $stokGrosir = $masuk - $keluar;

        $masukEceran  = $isi ? $masuk * $isi : $masuk;
        $keluarEceran = $isi ? $keluar * $isi : $keluar;
        $stokEceran   = $isi ? round($stokGrosir * $isi, 2) : $stokGrosir;

        // Fallback satuan kalau barang ini tidak ketemu di db_draft_barang
        if (!$row['satuan'] && $d) {
            $row['satuan'] = $d['satuan_asli'];
        }

        $row['lokasi'][$lokasi] = [
            'masuk_grosir'  => $masuk,
            'keluar_grosir' => $keluar,
            'stok_grosir'   => $stokGrosir,
            'masuk_eceran'  => $masukEceran,
            'keluar_eceran' => $keluarEceran,
            'stok_eceran'   => $stokEceran,
        ];

        $totalMasukG  += $masuk;
        $totalKeluarG += $keluar;
        $totalStokG   += $stokGrosir;
        $totalStokE   += $stokEceran;
    }

    if (!$row['satuan']) $row['satuan'] = '-';
    if (!$row['satuan_eceran']) $row['satuan_eceran'] = $row['satuan'];

    $row['total_masuk_grosir']  = $totalMasukG;
    $row['total_keluar_grosir'] = $totalKeluarG;
    $row['total_stok_grosir']   = $totalStokG;
    $row['total_stok_eceran']   = $totalStokE;

    $stockReport[] = $row;
}

usort($stockReport, function ($a, $b) {
    return strcasecmp($a['nama_barang'], $b['nama_barang']);
});

// ===== 5. TOTAL METRICS (default tampilan awal = grosir, live diupdate JS sesuai mode) =====
$totalStokMasuk  = array_sum(array_column($stockReport, 'total_masuk_grosir'));
$totalStokKeluar = array_sum(array_column($stockReport, 'total_keluar_grosir'));
$totalStokAkhir  = array_sum(array_column($stockReport, 'total_stok_grosir'));

function formatAngka($angka)
{
    if ($angka == floor($angka)) {
        return number_format($angka, 0, ',', '.');
    } else {
        return number_format($angka, 2, ',', '.');
    }
}

if ($totalStokAkhir <= 0) {
    $stokAkhirStatus = 'Habis / Minus';
    $stokAkhirType   = 'danger';
} elseif ($totalStokAkhir <= 100) {
    $stokAkhirStatus = 'Menipis';
    $stokAkhirType   = 'warning';
} else {
    $stokAkhirStatus = 'Aman';
    $stokAkhirType   = 'aman';
}

$namaLokasiDisplay = $userRole === 'operator'
    ? ('Dapur ' . ($LOKASI_LABEL[$lokasiSession] ?? $lokasiSession))
    : 'Semua Dapur';

$stockJson         = json_encode($stockReport, JSON_UNESCAPED_UNICODE);
$visibleLokasiJson = json_encode($visibleLokasi, JSON_UNESCAPED_UNICODE);
$lokasiLabelJson   = json_encode($LOKASI_LABEL, JSON_UNESCAPED_UNICODE);
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
        <a href="../dashboard.php" class="btn-back" aria-label="Kembali">
            <i class="ph ph-arrow-left"></i>
        </a>
        <div class="topbar-info">
            <h1>Data Stok Dapur</h1>
            <p>Monitoring Sisa Persediaan · Grosir & Eceran</p>
        </div>
        <div class="topbar-badges">
            <span class="badge <?= $userRole === 'admin' ? 'badge-admin' : 'badge-operator' ?>">
                <?= $userRole === 'admin' ? 'Admin' : 'Operator' ?>
            </span>
            <span class="badge badge-lokasi">
                <i class="ph ph-map-pin" style="font-size:10px"></i>
                <?= htmlspecialchars($namaLokasiDisplay) ?>
            </span>
        </div>
    </div>

    <!-- METRICS -->
    <div class="metrics-wrap">
        <!-- Stok Masuk -->
        <div class="metric-card">
            <div class="metric-icon-row">
                <div class="metric-icon icon-masuk"><i class="ph-fill ph-arrow-circle-down"></i></div>
                <span class="metric-label">Stok Masuk</span>
            </div>
            <div class="metric-val val-masuk" id="metricMasuk"><?= formatAngka($totalStokMasuk) ?></div>
            <div class="metric-sub">Dari semua pengiriman</div>
        </div>

        <!-- Stok Keluar -->
        <div class="metric-card">
            <div class="metric-icon-row">
                <div class="metric-icon icon-keluar"><i class="ph-fill ph-arrow-circle-up"></i></div>
                <span class="metric-label">Stok Keluar</span>
            </div>
            <div class="metric-val val-keluar" id="metricKeluar"><?= formatAngka($totalStokKeluar) ?></div>
            <div class="metric-sub">Dari semua pengambilan</div>
        </div>

        <!-- Stok Akhir -->
        <div class="metric-card metric-full">
            <div class="metric-icon-row">
                <div class="metric-icon icon-<?= $stokAkhirType ?>" id="metricIcon"><i class="ph-fill ph-package"></i></div>
                <span class="metric-label">Sisa Stok Akhir</span>
            </div>
            <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
                <div class="metric-val val-<?= $stokAkhirType ?>" id="metricSisa"><?= formatAngka($totalStokAkhir) ?></div>
                <span class="metric-status status-<?= $stokAkhirType ?>" id="metricStatus">
                    <?= $stokAkhirStatus ?>
                </span>
            </div>
            <div class="metric-sub" id="metricSub">Total sisa <?= count($visibleLokasi) ?> dapur</div>
        </div>
    </div>

    <!-- MODE TOGGLE GROSIR / ECERAN -->
    <div class="mode-toggle-wrap">
        <div class="mode-toggle">
            <button type="button" class="mode-btn active" data-mode="grosir" onclick="setMode('grosir')">
                <i class="ph ph-package"></i> Grosir
            </button>
            <button type="button" class="mode-btn" data-mode="eceran" onclick="setMode('eceran')">
                <i class="ph ph-shopping-bag"></i> Eceran
            </button>
        </div>
    </div>

    <!-- CONTROLS -->
    <div class="controls">
        <div class="search-wrap">
            <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
            <input type="text" id="searchInput" class="search-input"
                placeholder="Cari nama barang..."
                oninput="doFilter()" aria-label="Cari barang">
        </div>
        <div class="filter-row">
            <select id="kategoriFilter" class="filter-select" onchange="doFilter()" aria-label="Filter kategori">
                <option value="">Semua Kategori</option>
            </select>
            <select id="statusFilter" class="filter-select" onchange="doFilter()" aria-label="Filter status">
                <option value="">Semua Status</option>
                <option value="Aman">Aman</option>
                <option value="Menipis">Menipis</option>
                <option value="Habis">Habis</option>
                <option value="Minus">Minus</option>
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
                    <tr id="tableHeadRow">
                        <!-- diisi JS sesuai visibleLokasi -->
                    </tr>
                </thead>
                <tbody id="tbody">
                </tbody>
            </table>
        </div>
        <div class="table-footer" id="tableFooter">Menampilkan 0 barang</div>
    </div>
    <div id="emptyStateWrap"></div>

    <!-- BOTTOM SHEET -->
    <div class="bs-overlay" id="bsOverlay" onclick="closeBs(event)">
        <div class="bs-sheet" id="bsSheet">
            <div class="bs-handle"></div>
            <div class="bs-item-head">
                <div>
                    <div class="bs-item-name" id="bsName"></div>
                    <div class="bs-item-satuan" id="bsSatuan"></div>
                </div>
                <button class="bs-close" onclick="document.getElementById('bsOverlay').classList.remove('open')" aria-label="Tutup">
                    <i class="ph ph-x"></i>
                </button>
            </div>
            <div class="bs-divider"></div>
            <div id="bsContent"></div>
        </div>
    </div>

    <script>
        const STOCK_DATA = <?= $stockJson ?>;
        const VISIBLE_LOKASI = <?= $visibleLokasiJson ?>; // contoh: ["sodong","sariwangi","manonjaya"]
        const LOKASI_LABEL = <?= $lokasiLabelJson ?>;
    </script>
    <script src="stok.js"></script>
</body>

</html>