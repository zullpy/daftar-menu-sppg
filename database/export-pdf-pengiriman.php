<?php
session_start();
require_once 'koneksi.php';

// RBAC
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    die("Unauthorized");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT p.*, pr.nama_penerima_barang, pr.tanggal_terima 
                       FROM pengiriman p 
                       LEFT JOIN penerimaan pr ON pr.pengiriman_id = p.id 
                       WHERE p.id = ?");
$stmt->execute([$id]);
$data = $stmt->fetch();
if (!$data) die("Data tidak ditemukan.");

$stmt_d = $pdo->prepare("
    SELECT dp.*, dpr.status_barang, dpr.keterangan AS keterangan_terima
    FROM detail_pengiriman dp
    LEFT JOIN penerimaan pr ON pr.pengiriman_id = dp.pengiriman_id
    LEFT JOIN detail_penerimaan dpr ON dpr.detail_pengiriman_id = dp.id 
                                   AND dpr.penerimaan_id = pr.id
    WHERE dp.pengiriman_id = ?
");
$stmt_d->execute([$id]);
$details = $stmt_d->fetchAll();

$total_item   = count($details);
$total_ada    = 0;
$total_kurang = 0;
$total_tidak  = 0;
foreach ($details as $d) {
    if ($d['status_barang'] == 'ada')       $total_ada++;
    elseif ($d['status_barang'] == 'kurang')    $total_kurang++;
    elseif ($d['status_barang'] == 'tidak_ada') $total_tidak++;
}

$status_map = [
    'ada'       => 'ADA (LENGKAP)',
    'kurang'    => 'KURANG / RUSAK',
    'tidak_ada' => 'TIDAK ADA',
];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Jalan - <?= htmlspecialchars($data['no_faktur']) ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #222;
            background: #e8e8e8;
            padding: 20px;
        }

        @page {
            size: A4 landscape;
            margin: 5mm;
        }

        .page {
            background: #fff;
            width: 720px;
            margin: 0 auto;
            padding: 22px 26px 26px;
            border: 1px solid #bbb;
        }

        /* ── HEADER ── */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px double #1a2a5e;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-circle {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
        }

        .org-name {
            line-height: 1.45;
            font-size: 11px;
            color: #444;
        }

        .org-name strong {
            display: block;
            font-size: 13.5px;
            color: #1a2a5e;
            letter-spacing: 0.3px;
        }

        .doc-title {
            font-size: 22px;
            font-weight: bold;
            color: #1a2a5e;
            letter-spacing: 3px;
            border: 2.5px solid #1a2a5e;
            padding: 7px 20px;
            text-align: center;
            line-height: 1;
        }

        /* ── RECIPIENT INFO ── */
        .info-section {
            display: flex;
            gap: 16px;
            margin-bottom: 12px;
            font-size: 11.5px;
        }

        .info-left {
            flex: 1.3;
        }

        .info-right {
            flex: 1;
        }

        .field-row {
            display: flex;
            align-items: flex-end;
            margin-bottom: 5px;
        }

        .field-label {
            min-width: 72px;
            color: #333;
            white-space: nowrap;
            padding-bottom: 1px;
        }

        .field-sep {
            margin: 0 5px 1px;
        }

        .field-val {
            flex: 1;
            border-bottom: 1px solid #aaa;
            min-width: 100px;
            padding-bottom: 1px;
            color: #111;
        }

        .faktur-row {
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            margin-bottom: 6px;
            gap: 5px;
        }

        .faktur-row .field-val {
            min-width: 150px;
            font-weight: bold;
        }

        /* ── TABLE ── */
        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 2px;
        }

        table.items thead tr th {
            background: #1a2a5e;
            color: #fff;
            padding: 7px 8px;
            border: 1px solid #1a2a5e;
            font-weight: bold;
            font-size: 11px;
        }

        table.items thead tr th.center {
            text-align: center;
        }

        table.items thead tr th.left {
            text-align: left;
        }

        table.items tbody tr td {
            border: 1px solid #c0c0c0;
            padding: 5px 8px;
            vertical-align: top;
            height: 24px;
        }

        table.items tbody tr td.center {
            text-align: center;
        }

        table.items tbody tr:nth-child(even) {
            background: #f9f9f9;
        }

        .status-ada {
            color: #1e7e45;
            font-weight: bold;
        }

        .status-kurang {
            color: #b85c10;
            font-weight: bold;
        }

        .status-tidak_ada {
            color: #b83030;
            font-weight: bold;
        }

        /* ── BOTTOM SECTION ── */
        .bottom-section {
            display: flex;
            gap: 16px;
            margin-top: 10px;
            font-size: 11px;
            align-items: flex-start;
        }

        .bottom-left {
            flex: 1.1;
        }

        .bottom-right {
            flex: 1.3;
        }

        .total-label {
            font-weight: bold;
            font-size: 11.5px;
            margin-bottom: 6px;
        }

        .catatan-label {
            margin-bottom: 3px;
            color: #333;
        }

        .catatan-line {
            border-bottom: 1px solid #aaa;
            height: 16px;
            margin-bottom: 5px;
        }

        .note-box {
            border: 1px solid #bbb;
            padding: 8px 10px;
            background: #fafafa;
            font-size: 10.5px;
            line-height: 1.75;
        }

        .note-box p {
            margin-bottom: 1px;
        }

        /* ── RINGKASAN (jika ada status) ── */
        .summary-bar {
            margin-top: 10px;
            padding: 7px 10px;
            background: #f0f4ff;
            border-left: 3px solid #1a2a5e;
            font-size: 11px;
            color: #333;
        }

        /* ── RECEIVED ROW ── */
        .received-row {
            margin-top: 10px;
            padding: 7px 10px;
            border: 1px solid #c0c0c0;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 11.5px;
        }

        .received-row span:first-child {
            flex: 1.2;
        }

        .jam-line {
            display: inline-block;
            border-bottom: 1px solid #888;
            min-width: 70px;
            margin: 0 4px;
            vertical-align: bottom;
        }

        /* ── SIGNATURES ── */
        .sig-area {
            display: flex;
            border: 1px solid #c0c0c0;
            margin-top: 14px;
        }

        .sig-box {
            flex: 1;
            padding: 12px 16px 10px;
            text-align: center;
            font-size: 11px;
        }

        .sig-box:first-child {
            border-right: 1px solid #c0c0c0;
        }

        .sig-title {
            font-weight: bold;
            margin-bottom: 60px;
            font-size: 11.5px;
        }

        .sig-name-line {
            border-top: 1px solid #333;
            padding-top: 4px;
            display: inline-block;
            min-width: 200px;
            font-size: 11px;
        }

        .sig-date {
            font-size: 10px;
            color: #666;
            margin-top: 3px;
        }

        /* ── PRINT ── */
        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .page {
                border: none;
                width: 100%;
                box-shadow: none;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <!-- Print & Close Buttons (hilang saat print) -->
    <div class="no-print" style="width:720px; margin:0 auto 12px; display:flex; gap:8px; justify-content:flex-end;">
        <button onclick="window.print()"
            style="padding:7px 18px; background:#1a2a5e; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px;">
            🖨️ Cetak / Simpan PDF
        </button>
        <button onclick="window.close()"
            style="padding:7px 14px; background:#fff; color:#555; border:1px solid #bbb; border-radius:4px; cursor:pointer; font-size:12px;">
            ✕ Tutup
        </button>
    </div>

    <div class="page">

        <!-- ══ HEADER ══ -->
        <div class="header">
            <div class="logo-area">
                <!-- Logo SVG sederhana mirip K2US -->
                <div class="logo-circle">
                    <img src="../assets/logo.png" alt="Logo Koperasi Bina Usaha Sauyunan" style="width:54px; height:54px; border-radius:50%; object-fit:cover; display:block;">
                </div>
                <div class="org-name">
                    <strong>KOPERASI<br>BINA USAHA SAUYUNAN</strong>
                    Panyingkiran - Singaparna<br>
                    Kab. Tasikmalaya<br>
                    email : kop.binausahasauyunan@gmail.com
                </div>
            </div>
            <div class="doc-title">SURAT JALAN</div>
        </div>

        <!-- ══ INFO SECTION ══ -->
        <div class="info-section">
            <div class="info-left">
                <div style="font-size:11.5px; margin-bottom:5px;">Kepada Yth.</div>
                <div class="field-row">
                    <span class="field-label">Nama</span>
                    <span class="field-sep">:</span>
                    <span class="field-val"><?= htmlspecialchars($data['nama_penerima']) ?></span>
                </div>
                <div class="field-row">
                    <span class="field-label">Alamat</span>
                    <span class="field-sep">:</span>
                    <span class="field-val"><?= htmlspecialchars($data['alamat']) ?></span>
                </div>
            </div>

            <div class="info-right">
                <div class="faktur-row">
                    <span>No. Faktur</span>
                    <span class="field-sep">:</span>
                    <span class="field-val"><?= htmlspecialchars($data['no_faktur']) ?></span>
                </div>
                <div class="field-row">
                    <span class="field-label">Tanggal</span>
                    <span class="field-sep">:</span>
                    <span class="field-val"><?= date('d/m/Y', strtotime($data['tanggal_ekspedisi'])) ?></span>
                </div>
                <div class="field-row">
                    <span class="field-label">Ekspedisi</span>
                    <span class="field-sep">:</span>
                    <span class="field-val"><?= htmlspecialchars($data['ekspedisi'] ?? '') ?></span>
                </div>
            </div>
        </div>

        <!-- ══ TABEL BARANG ══ -->
        <table class="items">
            <thead>
                <tr>
                    <th class="center" style="width:5%;">No</th>
                    <th class="left" style="width:33%;">Nama Barang</th>
                    <th class="center" style="width:8%;">Qty</th>
                    <th class="left" style="width:11%;">Satuan</th>
                    <th class="center" style="width:15%;">Status</th>
                    <th class="left" style="width:28%;">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $min_rows = max(count($details), 5);
                for ($i = 0; $i < $min_rows; $i++):
                    $d = $details[$i] ?? null;
                    $status_text  = '';
                    $status_class = '';
                    $ket          = '';

                    if ($d && $d['status_barang']) {
                        $status_text  = $status_map[$d['status_barang']] ?? $d['status_barang'];
                        $status_class = 'status-' . $d['status_barang'];
                        $ket          = $d['keterangan_terima'] ?? '';
                    }
                ?>
                    <tr>
                        <td class="center"><?= $d ? ($i + 1) : '' ?></td>
                        <td><?= $d ? htmlspecialchars($d['nama_barang']) : '' ?></td>
                        <td class="center"><?= $d ? htmlspecialchars($d['qty']) : '' ?></td>
                        <td><?= $d ? htmlspecialchars($d['satuan']) : '' ?></td>
                        <td class="center <?= $status_class ?>"><?= $status_text ?></td>
                        <td><?= htmlspecialchars($ket) ?></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <!-- ══ BAGIAN BAWAH TABEL ══ -->
        <div class="bottom-section">
            <div class="bottom-left">
                <div class="total-label">Total Item <?= $total_item ?></div>
                <div class="catatan-label">Catatan :</div>
                <div class="catatan-line"></div>
                <div class="catatan-line"></div>
                <div class="catatan-line"></div>
            </div>
            <div class="bottom-right">
                <div class="note-box">
                    <strong>Perhatian :</strong>
                    <p>1. Surat jalan ini merupakan bukti resmi penerimaan barang</p>
                    <p>2. Surat jalan ini bukan bukti penjualan</p>
                    <p>3. Surat jalan ini akan dilengkapi faktur sebagai bukti penjualan</p>
                </div>
            </div>
        </div>

        <!-- ══ RINGKASAN STATUS (tampil jika sudah ada penerimaan) ══ -->
        <?php if ($data['tanggal_terima']): ?>
            <div class="summary-bar">
                <strong>Ringkasan Penerimaan:</strong> &nbsp;
                ✅ Lengkap: <strong><?= $total_ada ?> item</strong> &nbsp;|&nbsp;
                ⚠️ Kurang/Rusak: <strong style="color:#b85c10;"><?= $total_kurang ?> item</strong> &nbsp;|&nbsp;
                ❌ Tidak Ada: <strong style="color:#b83030;"><?= $total_tidak ?> item</strong>
            </div>
        <?php endif; ?>

        <!-- ══ BARIS PENERIMAAN ══ -->
        <div class="received-row">
            <span>Barang sudah diterima dalam keadaan baik dan cukup oleh :</span>
            <span>
                Jam Penerimaan :
                <span class="jam-line">
                    <?= $data['tanggal_terima'] ? date('H:i', strtotime($data['tanggal_terima'])) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' ?>
                </span>
                WIB
            </span>
        </div>

        <!-- ══ TANDA TANGAN ══ -->
        <div class="sig-area">
            <div class="sig-box">
                <div class="sig-title">Penerima / Petugas Gudang</div>
                <div class="sig-name-line">
                    ( <?= htmlspecialchars($data['nama_penerima_barang'] ?? '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;') ?> )
                </div>
                <?php if ($data['tanggal_terima']): ?>
                    <div class="sig-date"><?= date('d/m/Y H:i', strtotime($data['tanggal_terima'])) ?> WIB</div>
                <?php endif; ?>
            </div>
            <div class="sig-box">
                <div class="sig-title">Bagian Pengiriman</div>
                <div class="sig-name-line">( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</div>
            </div>
        </div>

    </div><!-- /.page -->

</body>

</html>