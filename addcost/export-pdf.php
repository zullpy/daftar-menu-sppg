<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../database/koneksi.php';

$tanggal = $_GET['tanggal'] ?? date('Y-m-d');
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

function formatTanggalIndonesia($tanggal)
{
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $p = explode('-', $tanggal);
    return $p[2] . ' ' . $bulan[(int)$p[1]] . ' ' . $p[0];
}

function getNamaHari($tanggal)
{
    $hari = ['Sun' => 'Minggu', 'Mon' => 'Senin', 'Tue' => 'Selasa', 'Wed' => 'Rabu', 'Thu' => 'Kamis', 'Fri' => 'Jumat', 'Sat' => 'Sabtu'];
    return $hari[date('D', strtotime($tanggal))];
}

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM pembelian_addcost WHERE id = ?");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM pembelian_addcost WHERE tanggal = ? ORDER BY created_at ASC");
    $stmt->execute([$tanggal]);
}
$addcostList = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Faktur Add Cost - <?= htmlspecialchars($tanggal) ?></title>
    <style>
        @page { size: 148.5mm 210mm; margin: 6mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 9pt; margin: 0; padding: 0; background: #fff; color: #000; }
        .header-table { width: 100%; border-collapse: separate; border: none; border-bottom: 1.5px double #000; margin-bottom: 5px; }
        .header-table td { padding: 4px 5px; vertical-align: middle; }
        .col-logo { width: 75px; text-align: center; vertical-align: middle; }
        .col-logo img { width: 65px; height: auto; }
        .col-kop { text-align: center; vertical-align: middle; padding: 4px 0; }
        .col-kop .label-koperasi { font-size: 9pt; font-weight: bold; color: #16a34a; margin: 0; line-height: 1.3; letter-spacing: 1px; }
        .col-kop .nama-koperasi { font-size: 15pt; font-weight: bold; color: #16a34a; margin: 0; line-height: 1.2; }
        .col-kop .tagline { color: #b8860b; font-style: italic; font-weight: bold; font-size: 8pt; margin: 2px 0 1px; }
        .col-kop .alamat { font-size: 8pt; line-height: 1.5; }
        .judul { text-align: center; font-size: 14pt; font-weight: bold; margin: 6px 0 8px; letter-spacing: 1px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .info-table td { border: none; padding: 2px 3px; font-size: 8.5pt; line-height: 1.4; }
        .info-table .label { width: 1%; white-space: nowrap; }
        .info-table .colon { width: 1%; white-space: nowrap; padding-left: 2px; padding-right: 2px; }
        .info-table .value { padding-left: 2px; }
        .info-table .label-right { width: 1%; white-space: nowrap; padding-left: 14px; }
        .addcost-section { page-break-inside: avoid; margin-bottom: 12px; }
        .addcost-title-box { font-size: 9.5pt; font-weight: bold; color: #16a34a; padding: 4px 5px; border: 1px solid #000; border-bottom: none; background: #f0fdf4; }
        .barang { width: 100%; border-collapse: collapse; }
        .barang th { border: 1.5px solid #000; padding: 4px 5px; text-align: center; font-size: 8.5pt; background: #fff; font-weight: bold; }
        .barang td { border: 1px solid #000; padding: 3px 5px; font-size: 8.5pt; height: 18px; }
        .col-qty { width: 40px; } .col-satuan { width: 55px; } .col-harga { width: 90px; } .col-sub { width: 95px; }
        .barang td.center { text-align: center; } .barang td.right { text-align: right; } .barang td.left { text-align: left; }
        .row-total td { border: 1px solid #000; padding: 4px 5px; font-size: 8.5pt; font-weight: bold; }
        .grand-total-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .grand-total-table td { border: 1.5px solid #000; padding: 6px 8px; font-size: 10.5pt; font-weight: bold; }
        .grand-total-table .gt-label { text-align: right; color: #16a34a; }
        .grand-total-table .gt-value { text-align: right; width: 140px; }
        .catatan-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .catatan-table td { font-size: 8pt; padding: 1px 2px; vertical-align: top; }
        .catatan-label { width: 55px; font-style: italic; text-decoration: underline; white-space: nowrap; }
        .catatan-isi { font-style: italic; line-height: 1.6; }
        .ttd-table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .ttd-table td { font-size: 8.5pt; vertical-align: top; padding: 0 4px; }
        .ttd-kiri { width: 50%; display: flex; flex-direction: column; justify-content: center; text-align: center; }
        .ttd-kanan { text-align: center; width: 50%; }
        .ttd-gap { height: 55px; display: block; }
        .ttd-line { display: block; margin-top: 2px; text-align: center; }
        .ttd-gap-kecil { display: block; height: 30px; }
        .cap-img { width: 65px; height: auto; opacity: 0.88; display: inline-block; margin: 2px 0; }
        .empty-msg { text-align: center; padding: 20px; color: #666; border: 1px solid #000; font-size: 9pt; }
        .toolbar { background: #16a34a; color: #fff; padding: 10px 16px; border-radius: 8px; margin: 0 auto 12px auto; max-width: 148.5mm; display: flex; justify-content: space-between; align-items: center; }
        .toolbar h3 { margin: 0; font-size: 12px; }
        .toolbar-btn { background: #fff; color: #16a34a; border: none; padding: 7px 13px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 11px; margin-left: 6px; }
        .toolbar-btn:hover { background: #f0f0f0; }
        .page-wrap { max-width: 148.5mm; margin: 0 auto 30px auto; background: #fff; padding: 8mm 10mm; box-shadow: 0 2px 10px rgba(0,0,0,0.12); }
        @media print {
            .page-wrap { max-width: none; width: 148.5mm; margin: 0; padding: 6mm 8mm; box-shadow: none; }
            .toolbar { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <h3>📄 Preview Faktur Add Cost - <?= getNamaHari($tanggal) ?>, <?= formatTanggalIndonesia($tanggal) ?></h3>
        <div>
            <button class="toolbar-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
            <button class="toolbar-btn" onclick="tutupHalaman()">✕ Tutup</button>
        </div>
    </div>
    <div class="page-wrap">
        <table class="header-table">
            <tr>
                <td class="col-logo"><img src="../assets/logo.png" alt="Logo KBUS"></td>
                <td class="col-kop">
                    <div class="label-koperasi">KOPERASI</div>
                    <div class="nama-koperasi">BINA USAHA SAUYUNAN</div>
                    <div class="tagline">"Bersama Membangun Usaha, Bersatu Meraih Sejahtera"</div>
                    <div class="alamat">Kp. Panyingkiran - Singaparna - Kab. Tasikmalaya</div>
                    <div class="alamat">email : kop.binausahasauyunan@gmail.com</div>
                </td>
            </tr>
        </table>
        <div class="judul">FAKTUR ADD COST</div>
        <?php
        $firstAddcost = $addcostList[0] ?? null;
        $infoSupplier = $firstAddcost['nama_supplier'] ?? '-';
        $infoKontak   = $firstAddcost['no_kontak']     ?? '-';
        $infoAlamat   = $firstAddcost['alamat_dapur']  ?? '-';
        $infoNoFaktur = $firstAddcost['no_faktur']     ?? '-';
        ?>
        <table class="info-table">
            <tr>
                <td class="label">Nama Supplier :</td>
                <td class="value"><?= htmlspecialchars($infoSupplier) ?></td>
                <td class="label-right">Tanggal :</td>
                <td class="value"><?= getNamaHari($tanggal) ?>, <?= formatTanggalIndonesia($tanggal) ?></td>
            </tr>
            <tr>
                <td class="label">No Kontak :</td>
                <td class="value"><?= htmlspecialchars($infoKontak) ?></td>
                <td class="label-right">No Faktur :</td>
                <td class="value"><?= htmlspecialchars($infoNoFaktur) ?></td>
            </tr>
            <tr>
                <td class="label">Alamat Dapur :</td>
                <td colspan="3" class="value"><?= htmlspecialchars($infoAlamat) ?></td>
            </tr>
        </table>
        <?php
        $grandTotal = 0;
        if (empty($addcostList)): ?>
            <div class="empty-msg">Tidak ada data Add Cost pada tanggal ini.</div>
        <?php else: ?>
            <?php foreach ($addcostList as $addcost):
                $stmtD = $pdo->prepare("SELECT * FROM pembelian_addcost_detail WHERE pembelian_add_id = ? ORDER BY nama_barang");
                $stmtD->execute([$addcost['id']]);
                $details = $stmtD->fetchAll();
                $addcostTotal = array_sum(array_column($details, 'subtotal'));
                $grandTotal += $addcostTotal;
            ?>
                <div class="addcost-section">
                    <?php if (count($addcostList) > 1): ?>
                    <div class="addcost-title-box">
                        No. Faktur: <?= htmlspecialchars($addcost['no_faktur']) ?> &nbsp;|&nbsp; Supplier: <?= htmlspecialchars($addcost['nama_supplier']) ?>
                    </div>
                    <?php endif; ?>
                    <table class="barang">
                        <thead>
                            <tr>
                                <th class="col-no">No</th>
                                <th class="col-nama">NAMA BARANG</th>
                                <th class="col-qty">QTY</th>
                                <th class="col-satuan">SATUAN</th>
                                <th class="col-harga">HARGA</th>
                                <th class="col-sub">SUB TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($details as $detail): ?>
                                <tr>
                                    <td class="center"><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($detail['nama_barang']) ?></td>
                                    <td class="center"><?= rtrim(rtrim(number_format((float)$detail['qty'], 2, ',', '.'), '0'), ',') ?></td>
                                    <td class="left"><?= htmlspecialchars($detail['satuan']) ?></td>
                                    <td class="right">Rp <?= number_format($detail['harga'], 0, ',', '.') ?></td>
                                    <td class="right">Rp <?= number_format($detail['subtotal'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="row-total">
                                <td colspan="5" class="right">TOTAL :</td>
                                <td class="right">Rp <?= number_format($addcostTotal, 0, ',', '.') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
            <?php if (count($addcostList) > 1): ?>
            <table class="grand-total-table">
                <tr>
                    <td class="gt-label">GRAND TOTAL :</td>
                    <td class="gt-value">Rp <?= number_format($grandTotal, 0, ',', '.') ?></td>
                </tr>
            </table>
            <?php endif; ?>
        <?php endif; ?>
        <table class="catatan-table">
            <tr>
                <td class="catatan-label">Catatan :</td>
                <td class="catatan-isi">Terimakasih telah belanja di tempat kami<br>Mohon dicek dengan teliti barang yang sudah dibeli</td>
            </tr>
        </table>
        <table class="ttd-table">
            <tr>
                <td class="ttd-kiri">
                    Penerima
                    <span class="ttd-gap"></span>
                    <span class="ttd-line">...................................</span>
                </td>
                <td class="ttd-kanan">
                    Hormat Kami,<br>
                    <img src="../assets/logo-kbus.png" class="cap-img" alt="Cap KBUS">
                    <span class="ttd-gap-kecil"></span>
                    <span class="ttd-line">Yudi Hendrian</span>
                </td>
            </tr>
        </table>
    </div>
    <script>
        function tutupHalaman() {
            window.close();
            setTimeout(() => { if (!window.closed) history.back(); }, 300);
        }
    </script>
</body>
</html>
