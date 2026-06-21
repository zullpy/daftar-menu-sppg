<?php
    // pengambilan-stok.php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
require '../database/koneksi.php';

$filterDari  = $_GET['dari'] ?? '';
$filterSampai = $_GET['sampai'] ?? '';
$filterSppg  = $_GET['sppg'] ?? '';

$where = [];
$params = [];
if (!empty($filterDari)) {
    $where[] = "pb.tanggal_pengambilan >= :dari";
    $params[':dari'] = $filterDari;
}
if (!empty($filterSampai)) {
    $where[] = "pb.tanggal_pengambilan <= :sampai";
    $params[':sampai'] = $filterSampai;
}
if (!empty($filterSppg)) {
    $where[] = "pb.nama_sppg LIKE :sppg";
    $params[':sppg'] = '%' . $filterSppg . '%';
}

$whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : '';

$sql = "SELECT pb.*,
        (SELECT COUNT(*) FROM pengambilan_barang_detail WHERE id_pengambilan = pb.id_pengambilan) AS jumlah_item
        FROM pengambilan_barang pb
        $whereSql
        ORDER BY pb.tanggal_pengambilan DESC, pb.id_pengambilan DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$dataGrouped = [];
while ($row = $stmt->fetch()) {
    $dataGrouped[$row['tanggal_pengambilan']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengambilan Stok Barang - Bina Usaha Sauyunan</title>
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web">
    </link>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #1565c0;
            --primary-dark: #0d47a1;
            --primary-light: #e3f2fd;
            --bg: #f4f6f8;
            --card-bg: #ffffff;
            --text-dark: #212529;
            --text-muted: #6c757d;
            --border: #e0e0e0;
            --shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: var(--bg);
            padding: 24px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .topbar h1 {
            font-size: 22px;
            color: var(--text-dark);
        }

        .topbar a.back {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 4px;
        }

        .filter-bar {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: end;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .filter-group input {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 9px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-outline {
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-dark);
        }

        .date-group {
            margin-bottom: 20px;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            background: var(--card-bg);
        }

        .date-header {
            padding: 14px 18px;
            background: var(--primary-light);
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            justify-content: space-between;
        }

        .date-header .left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .date-body {
            padding: 0;
        }

        .row-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 18px;
            border-top: 1px solid var(--border);
            font-size: 13px;
        }

        .row-item .main {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .row-item .no-pengambilan {
            font-weight: 700;
            color: var(--text-dark);
        }

        .row-item .sub {
            color: var(--text-muted);
            font-size: 12px;
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .row-item .count-badge {
            background: var(--primary-light);
            color: var(--primary-dark);
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 12px;
            display: block;
        }

        /* Modal detail */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
            z-index: 999;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 600px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 24px;
        }

        .modal-box h2 {
            font-size: 17px;
            margin-bottom: 6px;
        }

        .modal-box .meta {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .detail-table {
            width: 100%;
            border-collapse: collapse;
        }

        .detail-table th {
            text-align: left;
            font-size: 12px;
            color: var(--text-muted);
            padding: 8px 6px;
            border-bottom: 1px solid var(--border);
        }

        .detail-table td {
            padding: 8px 6px;
            font-size: 13px;
            border-bottom: 1px solid #f0f0f0;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }
    </style>
</head>

<body>

    <div>
        <a href="/select-bibi.php" class="back"><i class="ph ph-arrow-left"></i> Kembali ke Menu</a>
        <div class="topbar">
            <h1>Laporan Pengambilan Stok Barang</h1>
        </div>
    </div>

    <form class="filter-bar" method="GET">
        <div class="filter-group">
            <label>Dari Tanggal</label>
            <input type="date" name="dari" value="<?= htmlspecialchars($filterDari) ?>">
        </div>
        <div class="filter-group">
            <label>Sampai Tanggal</label>
            <input type="date" name="sampai" value="<?= htmlspecialchars($filterSampai) ?>">
        </div>
        <div class="filter-group">
            <label>Nama SPPG</label>
            <input type="text" name="sppg" placeholder="Cari SPPG..." value="<?= htmlspecialchars($filterSppg) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="ph ph-funnel"></i> Filter</button>
        <a href="pengambilan-stok.php" class="btn btn-outline"><i class="ph ph-x"></i> Reset</a>
    </form>

    <?php if (empty($dataGrouped)): ?>
        <div class="empty-state">
            <i class="ph ph-package"></i>
            <p>Belum ada data pengambilan stok barang.</p>
        </div>
    <?php else: ?>
        <?php foreach ($dataGrouped as $tanggal => $items): ?>
            <div class="date-group">
                <div class="date-header">
                    <div class="left">
                        <i class="ph ph-calendar"></i>
                        <?= date('d F Y', strtotime($tanggal)) ?>
                    </div>
                    <span class="count-badge"><?= count($items) ?> pengambilan</span>
                </div>
                <div class="date-body">
                    <?php foreach ($items as $item): ?>
                        <div class="row-item">
                            <div class="main">
                                <span class="no-pengambilan"><?= htmlspecialchars($item['no_pengambilan']) ?></span>
                                <span class="sub">
                                    <span><i class="ph ph-storefront"></i> <?= htmlspecialchars($item['nama_sppg']) ?></span>
                                    <?php if (!empty($item['no_kontak'])): ?>
                                        <span><i class="ph ph-phone"></i> <?= htmlspecialchars($item['no_kontak']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span class="count-badge"><?= $item['jumlah_item'] ?> item</span>
                                <button class="btn btn-outline" onclick="lihatDetail(<?= $item['id_pengambilan'] ?>, '<?= htmlspecialchars($item['no_pengambilan'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['nama_sppg'], ENT_QUOTES) ?>')">
                                    <i class="ph ph-eye"></i> Detail
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Modal Detail -->
    <div class="modal-overlay" id="modalDetail">
        <div class="modal-box">
            <h2 id="detailTitle"></h2>
            <div class="meta" id="detailMeta"></div>
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

    <script>
        function lihatDetail(id, noPengambilan, sppg) {
            document.getElementById('modalDetail').classList.add('active');
            document.getElementById('detailTitle').innerText = noPengambilan;
            document.getElementById('detailMeta').innerText = 'SPPG: ' + sppg;
            document.getElementById('detailBody').innerHTML = '<tr><td colspan="3" style="text-align:center; color:#999;">Memuat...</td></tr>';

            fetch('database/get-pengambilan-detail.php?id=' + id)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.detail.length > 0) {
                        let html = '';
                        data.detail.forEach(d => {
                            html += `<tr>
                        <td>${d.nama_barang}</td>
                        <td>${parseFloat(d.qty)}</td>
                        <td>${d.satuan}</td>
                    </tr>`;
                        });
                        document.getElementById('detailBody').innerHTML = html;
                    } else {
                        document.getElementById('detailBody').innerHTML = '<tr><td colspan="3" style="text-align:center; color:#999;">Tidak ada item.</td></tr>';
                    }
                })
                .catch(() => {
                    document.getElementById('detailBody').innerHTML = '<tr><td colspan="3" style="text-align:center; color:#d32f2f;">Gagal memuat data.</td></tr>';
                });
        }

        function closeDetail() {
            document.getElementById('modalDetail').classList.remove('active');
        }
    </script>

</body>

</html>