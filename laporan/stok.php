<?php
// laporan/stok.php
session_start();
require '../database/koneksi.php';
if (!isset($_SESSION['role'])) {
    header('Location: ../index.php');
    exit;
}
$role = $_SESSION['role'];
$userRole = strtolower($role);
$lokasiSession = $_SESSION['lokasi'] ?? 'semua';
$namaLokasi = $_SESSION['nama_op'] ?? '';

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Filter lokasi
if ($userRole === 'operator') {
    $lokasiFilter = $lokasiSession;
} else {
    $lokasiFilter = $_GET['lokasi'] ?? 'sodong'; // default ke sodong jika admin, bukan 'semua'
}

$KATEGORI_LIST = ['Bahan Pokok', 'Bumbu', 'Sayuran', 'Buah-buahan', 'Tambahan', 'Stok'];

// Load category mapping from belanja_detail
$categoryLookup = [];
$catStmt = $pdo->query("SELECT DISTINCT TRIM(UPPER(item_barang)) AS item_key, kategori FROM belanja_detail WHERE kategori IS NOT NULL AND kategori != ''");
while ($row = $catStmt->fetch()) {
    $categoryLookup[$row['item_key']] = $row['kategori'];
}

// STOK MASUK - dari pengiriman
$incomingSql = "SELECT
    TRIM(dp.nama_barang) AS item_nama_raw,
    TRIM(UPPER(dp.nama_barang)) AS item_key,
    dp.satuan,
    SUM(CASE WHEN dpr.status_barang = 'tidak_ada' THEN 0 ELSE dp.qty END) AS total_masuk
FROM detail_pengiriman dp
JOIN pengiriman p ON dp.pengiriman_id = p.id
LEFT JOIN penerimaan pr ON pr.pengiriman_id = p.id
LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id AND dpr.penerimaan_id = pr.id
WHERE (:lokasi1 = 'semua' OR p.lokasi = :lokasi2)
GROUP BY TRIM(UPPER(dp.nama_barang)), dp.satuan";
$stmt = $pdo->prepare($incomingSql);
$stmt->execute([':lokasi1' => $lokasiFilter, ':lokasi2' => $lokasiFilter]);
$incomingData = $stmt->fetchAll();

// STOK KELUAR
$outgoingSql = "SELECT
    TRIM(UPPER(pbd.nama_barang)) AS item_key,
    pbd.satuan,
    SUM(pbd.qty) AS total_keluar
FROM pengambilan_barang_detail pbd
JOIN pengambilan_barang pb ON pbd.id_pengambilan = pb.id_pengambilan
WHERE (:lokasi = 'semua' OR pb.lokasi = :lokasi_exact)
GROUP BY TRIM(UPPER(pbd.nama_barang)), pbd.satuan";
$stmtOut = $pdo->prepare($outgoingSql);
if ($lokasiFilter === 'semua') {
    $stmtOut->execute([':lokasi' => 'semua', ':lokasi_exact' => 'semua']);
} else {
    $stmtOut->execute([':lokasi' => $lokasiFilter, ':lokasi_exact' => $lokasiFilter]);
}
$outgoingData = $stmtOut->fetchAll();

// Index outgoing
$outgoingIndexed = [];
foreach ($outgoingData as $out) {
    $key = $out['item_key'] . '|' . trim(strtoupper($out['satuan']));
    $outgoingIndexed[$key] = (float)$out['total_keluar'];
}

// Combine data
$stockReport = [];
foreach ($incomingData as $inc) {
    $itemKey = $inc['item_key'];
    $satuan = $inc['satuan'];
    $kategori = $categoryLookup[$itemKey] ?? 'Tambahan';
    $outKey = $itemKey . '|' . trim(strtoupper($satuan));
    $totalKeluar = $outgoingIndexed[$outKey] ?? 0.0;
    $totalMasuk = (float)$inc['total_masuk'];
    $sisaStok = $totalMasuk - $totalKeluar;
    $stockReport[] = [
        'nama_barang' => $inc['item_nama_raw'],
        'satuan' => $satuan,
        'kategori' => $kategori,
        'masuk' => $totalMasuk,
        'keluar' => $totalKeluar,
        'sisa' => $sisaStok
    ];
}

// Tambah item keluar yang tidak pernah masuk
$incomingIndexedKeys = [];
foreach ($incomingData as $inc) {
    $incomingIndexedKeys[$inc['item_key'] . '|' . trim(strtoupper($inc['satuan']))] = true;
}
foreach ($outgoingData as $out) {
    $key = $out['item_key'] . '|' . trim(strtoupper($out['satuan']));
    if (!isset($incomingIndexedKeys[$key])) {
        $rawSql = "SELECT nama_barang FROM pengambilan_barang_detail WHERE TRIM(UPPER(nama_barang)) = :key_name LIMIT 1";
        $stmtRaw = $pdo->prepare($rawSql);
        $stmtRaw->execute([':key_name' => $out['item_key']]);
        $rawName = $stmtRaw->fetchColumn() ?: $out['item_key'];
        $stockReport[] = [
            'nama_barang' => $rawName,
            'satuan' => $out['satuan'],
            'kategori' => 'Tambahan',
            'masuk' => 0.0,
            'keluar' => (float)$out['total_keluar'],
            'sisa' => -(float)$out['total_keluar']
        ];
    }
}

// Sort by name
usort($stockReport, function ($a, $b) {
    return strcasecmp($a['nama_barang'], $b['nama_barang']);
});

// Hitung total metrics
$totalStokMasuk = 0;
$totalStokKeluar = 0;
$totalStokAkhir = 0;
foreach ($stockReport as $stItem) {
    $totalStokMasuk += $stItem['masuk'];
    $totalStokKeluar += $stItem['keluar'];
    $totalStokAkhir += $stItem['sisa'];
}

function formatAngka($angka)
{
    if ($angka == floor($angka)) {
        return number_format($angka, 0, ',', '.');
    } else {
        return number_format($angka, 2, ',', '.');
    }
}

$LOKASI_MAP = [
    'sodong'    => 'Dapur Sodong',
    'sariwangi' => 'Dapur Sariwangi',
    'manonjaya' => 'Dapur Manonjaya',
    'semua'     => 'Semua Dapur'
];
$namaLokasiDisplay = $LOKASI_MAP[$lokasiFilter] ?? $lokasiFilter;

// Warna dinamis stok akhir
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

// Encode stockReport ke JSON untuk JS
$stockJson = json_encode($stockReport, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Data Stok Barang - Bina Usaha Sauyunan</title>
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        :root {
            --primary: #0891b2;
            --primary-dark: #0e7490;
            --primary-light: #ecfeff;
            --success: #16a34a;
            --success-light: #f0fdf4;
            --warning: #d97706;
            --warning-light: #fffbeb;
            --danger: #dc2626;
            --danger-light: #fef2f2;
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --radius-sm: 10px;
            --radius: 14px;
            --radius-lg: 18px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 32px;
        }

        /* ===== TOPBAR ===== */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-back {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--muted);
            flex-shrink: 0;
            font-size: 18px;
            transition: background 0.15s;
        }

        .btn-back:hover {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .topbar-info {
            flex: 1;
            min-width: 0;
        }

        .topbar-info h1 {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .topbar-info p {
            font-size: 11px;
            color: var(--muted);
            margin-top: 1px;
        }

        .topbar-badges {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-shrink: 0;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-admin {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }

        .badge-operator {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .badge-lokasi {
            background: var(--primary-light);
            color: var(--primary-dark);
            border: 1px solid #a5f3fc;
        }

        /* ===== METRICS ===== */
        .metrics-wrap {
            padding: 16px 16px 0;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .metric-full {
            grid-column: 1 / -1;
        }

        .metric-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px;
        }

        .metric-icon-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .metric-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .icon-masuk {
            background: #dcfce7;
            color: #166534;
        }

        .icon-keluar {
            background: #fee2e2;
            color: #991b1b;
        }

        .icon-aman {
            background: #e0f2fe;
            color: #0369a1;
        }

        .icon-menipis {
            background: #fef3c7;
            color: #92400e;
        }

        .icon-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .metric-label {
            font-size: 10px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .metric-val {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
        }

        .val-masuk {
            color: #166534;
        }

        .val-keluar {
            color: #991b1b;
        }

        .val-aman {
            color: #0369a1;
        }

        .val-menipis {
            color: #92400e;
        }

        .val-danger {
            color: #991b1b;
        }

        .metric-sub {
            font-size: 11px;
            color: var(--muted);
            margin-top: 3px;
        }

        .metric-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-aman {
            background: #dcfce7;
            color: #166534;
        }

        .status-menipis {
            background: #fef3c7;
            color: #92400e;
        }

        .status-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        /* ===== CONTROLS ===== */
        .controls {
            padding: 14px 16px 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .search-wrap {
            position: relative;
        }

        .search-wrap i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 17px;
            color: var(--muted);
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            padding: 10px 12px 10px 36px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
            background: var(--card);
            color: var(--text);
            outline: none;
            transition: border-color 0.15s;
        }

        .search-input:focus {
            border-color: var(--primary);
        }

        .filter-row {
            display: flex;
            gap: 8px;
        }

        .filter-select {
            flex: 1;
            padding: 9px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: inherit;
            background: var(--card);
            color: var(--text);
            outline: none;
            cursor: pointer;
        }

        .filter-select:focus {
            border-color: var(--primary);
        }

        /* ===== SECTION HEADER ===== */
        .section-hdr {
            padding: 14px 16px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-hdr span {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .count-pill {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
        }

        /* ===== ITEM CARDS ===== */
        .items-list {
            padding: 0 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .item-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px;
            cursor: pointer;
            transition: box-shadow 0.15s;
            -webkit-tap-highlight-color: transparent;
        }

        .item-card:active {
            opacity: 0.85;
        }

        .item-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
        }

        .item-name-wrap {
            flex: 1;
            min-width: 0;
        }

        .item-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.3;
        }

        .item-satuan {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        .kat-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .kat-bahan {
            background: #eff6ff;
            color: #1e40af;
        }

        .kat-bumbu {
            background: #fffbeb;
            color: #92400e;
        }

        .kat-sayuran {
            background: #f0fdf4;
            color: #166534;
        }

        .kat-buah {
            background: #fef2f2;
            color: #991b1b;
        }

        .kat-tambahan {
            background: #f5f3ff;
            color: #5b21b6;
        }

        .kat-stok {
            background: #ecfeff;
            color: #155e75;
        }

        .item-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .s-aman {
            background: #dcfce7;
            color: #166534;
        }

        .s-menipis {
            background: #fef3c7;
            color: #92400e;
        }

        .s-habis {
            background: #fee2e2;
            color: #991b1b;
        }

        /* 3-column num boxes */
        .item-nums {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
        }

        .num-box {
            background: #f8fafc;
            border-radius: var(--radius-sm);
            padding: 8px 6px;
            text-align: center;
        }

        .num-lbl {
            display: block;
            font-size: 9px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .num-val {
            display: block;
            font-size: 14px;
            font-weight: 700;
            line-height: 1;
        }

        .num-sat {
            display: block;
            font-size: 10px;
            color: var(--muted);
            margin-top: 2px;
        }

        .c-masuk {
            color: #166534;
        }

        .c-keluar {
            color: #991b1b;
        }

        .c-aman {
            color: #0369a1;
        }

        .c-menipis {
            color: #92400e;
        }

        .c-habis {
            color: #991b1b;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            padding: 56px 24px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 44px;
            display: block;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        .empty-state h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 6px;
        }

        .empty-state p {
            font-size: 13px;
        }

        /* ===== BOTTOM SHEET ===== */
        .bs-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 200;
            display: none;
            align-items: flex-end;
        }

        .bs-overlay.open {
            display: flex;
        }

        .bs-sheet {
            background: var(--card);
            border-radius: 20px 20px 0 0;
            padding: 20px 20px 32px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .bs-handle {
            width: 36px;
            height: 4px;
            border-radius: 2px;
            background: var(--border);
            margin: 0 auto 18px;
        }

        .bs-item-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .bs-item-name {
            font-size: 17px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.3;
        }

        .bs-item-satuan {
            font-size: 12px;
            color: var(--muted);
            margin-top: 3px;
        }

        .bs-divider {
            height: 1px;
            background: var(--border);
            margin: 14px 0;
        }

        .bs-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .bs-row:last-child {
            border-bottom: none;
        }

        .bs-lbl {
            font-size: 13px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .bs-val {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
        }

        .bs-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f1f5f9;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            color: var(--muted);
            flex-shrink: 0;
        }

        /* ===== ADMIN LOKASI TABS ===== */
        .lokasi-tabs {
            display: flex;
            gap: 0;
            padding: 0 16px;
            margin-top: 14px;
            background: var(--card);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .lokasi-tabs::-webkit-scrollbar {
            display: none;
        }

        .tab-btn {
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            border: none;
            border-bottom: 2px solid transparent;
            background: none;
            cursor: pointer;
            white-space: nowrap;
            font-family: inherit;
            transition: all 0.15s;
        }

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary-dark);
            border-bottom-color: var(--primary);
        }

        @media (min-width: 640px) {
            .metrics-wrap {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .metric-full {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

    <!-- TOPBAR -->
    <div class="topbar">
        <a href="../dashboard.php" class="btn-back" aria-label="Kembali">
            <i class="ph ph-arrow-left"></i>
        </a>
        <div class="topbar-info">
            <h1>Data Stok Dapur</h1>
            <p>Monitoring Sisa Persediaan</p>
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

    <!-- ADMIN: TAB LOKASI (tanpa "Semua Dapur") -->
    <?php if ($userRole === 'admin'): ?>
        <div class="lokasi-tabs" role="tablist" aria-label="Pilih Dapur">
            <button class="tab-btn <?= $lokasiFilter === 'sodong' ? 'active' : '' ?>"
                onclick="changeLokasi('sodong')" role="tab">
                <i class="ph ph-cooking-pot" style="margin-right:5px;font-size:13px;vertical-align:-2px"></i>Sodong
            </button>
            <button class="tab-btn <?= $lokasiFilter === 'sariwangi' ? 'active' : '' ?>"
                onclick="changeLokasi('sariwangi')" role="tab">
                <i class="ph ph-cooking-pot" style="margin-right:5px;font-size:13px;vertical-align:-2px"></i>Sariwangi
            </button>
            <button class="tab-btn <?= $lokasiFilter === 'manonjaya' ? 'active' : '' ?>"
                onclick="changeLokasi('manonjaya')" role="tab">
                <i class="ph ph-cooking-pot" style="margin-right:5px;font-size:13px;vertical-align:-2px"></i>Manonjaya
            </button>
        </div>
    <?php endif; ?>

    <!-- METRICS -->
    <div class="metrics-wrap">
        <!-- Stok Masuk -->
        <div class="metric-card">
            <div class="metric-icon-row">
                <div class="metric-icon icon-masuk"><i class="ph-fill ph-arrow-circle-down"></i></div>
                <span class="metric-label">Stok Masuk</span>
            </div>
            <div class="metric-val val-masuk"><?= formatAngka($totalStokMasuk) ?></div>
            <div class="metric-sub">Dari semua pengiriman</div>
        </div>

        <!-- Stok Keluar -->
        <div class="metric-card">
            <div class="metric-icon-row">
                <div class="metric-icon icon-keluar"><i class="ph-fill ph-arrow-circle-up"></i></div>
                <span class="metric-label">Stok Keluar</span>
            </div>
            <div class="metric-val val-keluar"><?= formatAngka($totalStokKeluar) ?></div>
            <div class="metric-sub">Dari semua pengambilan</div>
        </div>

        <!-- Stok Akhir -->
        <div class="metric-card metric-full">
            <div class="metric-icon-row">
                <div class="metric-icon icon-<?= $stokAkhirType ?>"><i class="ph-fill ph-package"></i></div>
                <span class="metric-label">Sisa Stok Akhir</span>
            </div>
            <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
                <div class="metric-val val-<?= $stokAkhirType ?>"><?= formatAngka($totalStokAkhir) ?></div>
                <span class="metric-status status-<?= $stokAkhirType ?>">
                    <?php if ($stokAkhirType === 'aman'): ?><i class="ph-fill ph-check-circle" style="font-size:10px"></i><?php endif; ?>
                    <?= $stokAkhirStatus ?>
                </span>
            </div>
            <div class="metric-sub">Total sisa semua kategori</div>
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
            <select id="katFilter" class="filter-select" onchange="doFilter()" aria-label="Filter kategori">
                <option value="">Semua Kategori</option>
                <?php foreach ($KATEGORI_LIST as $kat): ?>
                    <option value="<?= htmlspecialchars($kat) ?>"><?= htmlspecialchars($kat) ?></option>
                <?php endforeach; ?>
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
        <span>Daftar Barang</span>
        <span class="count-pill" id="countPill">0 item</span>
    </div>

    <!-- ITEMS -->
    <div class="items-list" id="itemsList">
        <?php if (empty($stockReport)): ?>
            <div class="empty-state">
                <i class="ph ph-archive"></i>
                <h3>Belum Ada Data Stok</h3>
                <p>Tidak ada transaksi di dapur ini.</p>
            </div>
        <?php endif; ?>
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

        const KAT_CLASS = {
            'Bahan Pokok': 'kat-bahan',
            'Bumbu': 'kat-bumbu',
            'Sayuran': 'kat-sayuran',
            'Buah-buahan': 'kat-buah',
            'Tambahan': 'kat-tambahan',
            'Stok': 'kat-stok'
        };

        function fmt(n) {
            n = parseFloat(n);
            if (Number.isInteger(n)) return n.toLocaleString('id-ID');
            return n.toLocaleString('id-ID', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 2
            });
        }

        function getStatus(sisa) {
            if (sisa < 0) return {
                cls: 's-habis',
                txt: 'Minus',
                numCls: 'c-habis'
            };
            if (sisa === 0) return {
                cls: 's-habis',
                txt: 'Habis',
                numCls: 'c-habis'
            };
            if (sisa <= 10) return {
                cls: 's-menipis',
                txt: 'Menipis',
                numCls: 'c-menipis'
            };
            return {
                cls: 's-aman',
                txt: 'Aman',
                numCls: 'c-aman'
            };
        }

        function getSisaColorClass(sisa) {
            if (sisa <= 0) return 'c-habis';
            if (sisa <= 10) return 'c-menipis';
            return 'c-aman';
        }

        let filtered = STOCK_DATA.slice();

        function doFilter() {
            const q = document.getElementById('searchInput').value.toLowerCase().trim();
            const kat = document.getElementById('katFilter').value;
            const st = document.getElementById('statusFilter').value;
            filtered = STOCK_DATA.filter(d => {
                const matchName = d.nama_barang.toLowerCase().includes(q);
                const matchKat = !kat || d.kategori === kat;
                const s = getStatus(d.sisa);
                const matchSt = !st || s.txt === st;
                return matchName && matchKat && matchSt;
            });
            renderItems();
        }

        function renderItems() {
            const list = document.getElementById('itemsList');
            document.getElementById('countPill').textContent = filtered.length + ' item';
            if (!filtered.length) {
                list.innerHTML = `<div class="empty-state">
            <i class="ph ph-funnel-x"></i>
            <h3>Tidak ada hasil</h3>
            <p>Coba ubah filter atau kata kunci pencarian</p>
        </div>`;
                return;
            }
            list.innerHTML = filtered.map((d, i) => {
                const st = getStatus(d.sisa);
                const kc = KAT_CLASS[d.kategori] || 'kat-tambahan';
                const sisaCls = getSisaColorClass(d.sisa);
                return `<div class="item-card" onclick="openBs(${i})" role="button" tabindex="0"
                    aria-label="Detail ${d.nama_barang}">
            <div class="item-head">
                <div class="item-name-wrap">
                    <div class="item-name">${d.nama_barang}</div>
                    <div class="item-satuan">${d.satuan} &bull; <span class="kat-badge ${kc}">${d.kategori}</span></div>
                </div>
                <span class="item-status ${st.cls}">${st.txt}</span>
            </div>
            <div class="item-nums">
                <div class="num-box">
                    <span class="num-lbl">Masuk</span>
                    <span class="num-val c-masuk">${fmt(d.masuk)}</span>
                    <span class="num-sat">${d.satuan}</span>
                </div>
                <div class="num-box">
                    <span class="num-lbl">Keluar</span>
                    <span class="num-val c-keluar">${fmt(d.keluar)}</span>
                    <span class="num-sat">${d.satuan}</span>
                </div>
                <div class="num-box">
                    <span class="num-lbl">Sisa</span>
                    <span class="num-val ${sisaCls}">${fmt(d.sisa)}</span>
                    <span class="num-sat">${d.satuan}</span>
                </div>
            </div>
        </div>`;
            }).join('');
        }

        function openBs(idx) {
            const d = filtered[idx];
            const st = getStatus(d.sisa);
            document.getElementById('bsName').textContent = d.nama_barang;
            document.getElementById('bsSatuan').textContent = 'Satuan: ' + d.satuan;
            document.getElementById('bsContent').innerHTML = `
        <div class="bs-row">
            <span class="bs-lbl"><i class="ph ph-tag" style="font-size:15px"></i>Kategori</span>
            <span class="bs-val"><span class="kat-badge ${KAT_CLASS[d.kategori]||'kat-tambahan'}">${d.kategori}</span></span>
        </div>
        <div class="bs-row">
            <span class="bs-lbl"><i class="ph ph-arrow-circle-down" style="font-size:15px;color:#166534"></i>Total Masuk</span>
            <span class="bs-val" style="color:#166534">+${fmt(d.masuk)} ${d.satuan}</span>
        </div>
        <div class="bs-row">
            <span class="bs-lbl"><i class="ph ph-arrow-circle-up" style="font-size:15px;color:#991b1b"></i>Total Keluar</span>
            <span class="bs-val" style="color:#991b1b">-${fmt(d.keluar)} ${d.satuan}</span>
        </div>
        <div class="bs-row">
            <span class="bs-lbl"><i class="ph ph-package" style="font-size:15px"></i>Sisa Stok</span>
            <span class="bs-val">${fmt(d.sisa)} ${d.satuan}</span>
        </div>
        <div class="bs-row">
            <span class="bs-lbl"><i class="ph ph-info" style="font-size:15px"></i>Status</span>
            <span class="item-status ${st.cls}">${st.txt}</span>
        </div>
    `;
            document.getElementById('bsOverlay').classList.add('open');
        }

        function closeBs(e) {
            if (e.target === document.getElementById('bsOverlay')) {
                document.getElementById('bsOverlay').classList.remove('open');
            }
        }

        function changeLokasi(val) {
            const url = new URL(window.location.href);
            url.searchParams.set('lokasi', val);
            window.location.href = url.toString();
        }

        // Keyboard support untuk item cards
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') document.getElementById('bsOverlay').classList.remove('open');
        });

        // Init render
        renderItems();
    </script>
</body>

</html>