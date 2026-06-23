<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'koneksi.php';

$tanggal = $_GET['tanggal'] ?? date('Y-m-d');

function formatTanggalIndonesia($tanggal)
{
    $bulan = [
        1 => 'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];
    $pecahkan = explode('-', $tanggal);
    return $pecahkan[2] . ' ' . $bulan[(int)$pecahkan[1]] . ' ' . $pecahkan[0];
}

function getNamaHari($tanggal)
{
    $hari = [
        'Sun' => 'Minggu',
        'Mon' => 'Senin',
        'Tue' => 'Selasa',
        'Wed' => 'Rabu',
        'Thu' => 'Kamis',
        'Fri' => 'Jumat',
        'Sat' => 'Sabtu'
    ];
    return $hari[date('D', strtotime($tanggal))];
}

$stmt = $pdo->prepare("SELECT * FROM belanja WHERE tanggal = ? ORDER BY created_at ASC");
$stmt->execute([$tanggal]);
$belanjaList = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Laporan Pembelian - <?= formatTanggalIndonesia($tanggal) ?></title>
    <style>
        /* ===== PAGE SETUP A4 (laporan bisa banyak menu, A4 lebih lega dari A5) ===== */
        @page {
            size: A4 landscape;
            margin: 5mm;
        }

        body {
            margin: 0;
            padding: 0;
        }

        .page-wrap {
            width: 148.5mm;
            /* setengah A4 */
            min-height: 200mm;
            /* tinggi A4 landscape */
            padding: 8mm;
            box-sizing: border-box;
            border-right: 1px dashed #999;
            box-shadow: none;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 9pt;
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
        }

        /* ===== HEADER KOP SURAT ===== */
        .header-table {
            width: 100%;
            border-collapse: separate;
            border: none;
            border-bottom: 1.5px double #000;
            margin-bottom: 5px;
        }

        .header-table td {
            padding: 4px 5px;
            vertical-align: middle;
        }

        .col-logo {
            width: 75px;
            text-align: center;
            vertical-align: middle;
        }

        .col-logo img {
            width: 65px;
            height: auto;
        }

        .col-kop {
            text-align: center;
            vertical-align: middle;
            padding: 4px 0;
        }

        .col-kop .label-koperasi {
            font-size: 9pt;
            font-weight: bold;
            color: #6b3fa0;
            margin: 0;
            line-height: 1.3;
            letter-spacing: 1px;
        }

        .col-kop .nama-koperasi {
            font-size: 15pt;
            font-weight: bold;
            color: #6b3fa0;
            margin: 0;
            line-height: 1.2;
        }

        .col-kop .tagline {
            color: #b8860b;
            font-style: italic;
            font-weight: bold;
            font-size: 8pt;
            margin: 2px 0 1px;
        }

        .col-kop .alamat {
            font-size: 8pt;
            line-height: 1.5;
        }

        .col-logo-kanan {
            width: 75px;
            text-align: center;
            vertical-align: middle;
        }

        .col-logo-kanan img {
            width: 55px;
            height: auto;
        }

        /* ===== JUDUL ===== */
        .judul {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin: 6px 0 8px;
            letter-spacing: 1px;
        }

        /* ===== INFO LAPORAN ===== */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .info-table td {
            border: none;
            padding: 4px 6px;
            font-size: 8.5pt;
            line-height: 1.4;
        }

        .info-table .label {
            width: 110px;
            white-space: nowrap;
        }

        .info-table .value {
            min-width: 100px;
        }

        .info-table .label-right {
            width: 90px;
            white-space: nowrap;
        }

        .info-table .value-right {
            width: 130px;
        }

        /* ===== SECTION PER MENU ===== */
        .menu-section {
            page-break-inside: avoid;
            margin-bottom: 12px;
        }

        .menu-title-box {
            font-size: 9.5pt;
            font-weight: bold;
            color: #6b3fa0;
            padding: 4px 5px;
            border: 1px solid #000;
            border-bottom: none;
            background: #f6f2fa;
        }

        /* ===== TABEL BARANG ===== */
        .barang {
            width: 100%;
            border-collapse: collapse;
        }

        .barang th {
            border: 1.5px solid #000;
            padding: 4px 5px;
            text-align: center;
            font-size: 8.5pt;
            background: #fff;
            font-weight: bold;
        }

        .barang td {
            border: 1px solid #000;
            padding: 3px 5px;
            font-size: 8.5pt;
            height: 18px;
        }

        .col-qty {
            width: 40px;
        }

        .col-satuan {
            width: 55px;
        }

        .col-nama {
            /* auto */
        }

        .col-harga {
            width: 90px;
        }

        .col-sub {
            width: 95px;
        }

        .barang td.center {
            text-align: center;
        }

        .barang td.right {
            text-align: right;
        }

        /* Row total per menu */
        .row-total td {
            border: 1px solid #000;
            padding: 4px 5px;
            font-size: 8.5pt;
            font-weight: bold;
        }

        /* ===== GRAND TOTAL ===== */
        .grand-total-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        .grand-total-table td {
            border: 1.5px solid #000;
            padding: 6px 8px;
            font-size: 10.5pt;
            font-weight: bold;
        }

        .grand-total-table .gt-label {
            text-align: right;
            color: #6b3fa0;
        }

        .grand-total-table .gt-value {
            text-align: right;
            width: 140px;
        }

        /* ===== CATATAN ===== */
        .catatan-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .catatan-table td {
            font-size: 8pt;
            padding: 1px 2px;
            vertical-align: top;
        }

        .catatan-label {
            width: 55px;
            font-style: italic;
            text-decoration: underline;
            white-space: nowrap;
        }

        .catatan-isi {
            font-style: italic;
            line-height: 1.6;
        }

        /* ===== TTD ===== */
        .ttd-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }

        .ttd-table td {
            font-size: 8.5pt;
            vertical-align: top;
            padding: 0 4px;
        }

        .ttd-kiri {
            width: 50%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
        }

        .ttd-kanan {
            text-align: center;
            width: 50%;
        }

        .ttd-gap {
            height: 55px;
            display: block;
        }

        .ttd-line {
            display: block;
            margin-top: 2px;
        }

        .ttd-gap-kecil {
            display: block;
            height: 30px;
        }

        .ttd-line {
            display: block;
            text-align: center;
        }

        .cap-img {
            width: 65px;
            height: auto;
            opacity: 0.88;
            display: inline-block;
            margin: 2px 0;
        }

        .empty-msg {
            text-align: center;
            padding: 20px;
            color: #666;
            border: 1px solid #000;
            font-size: 9pt;
        }

        .printed-at {
            text-align: right;
            font-size: 7.5pt;
            font-style: italic;
            color: #444;
            margin-top: 10px;
        }

        /* ===== TOOLBAR (tidak ikut print) ===== */
        .toolbar {
            background: #6b3fa0;
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            margin: 0 auto 14px auto;
            max-width: 19cm;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .toolbar h3 {
            margin: 0;
            font-size: 13px;
        }

        .toolbar-btn {
            background: #fff;
            color: #6b3fa0;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            font-size: 12px;
            margin-left: 8px;
        }

        .toolbar-btn:hover {
            background: #f0f0f0;
        }

        .page-wrap {
            max-width: 19cm;
            margin: 0 auto 30px auto;
            background: #fff;
            padding: 10mm 12mm;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12);
        }

        /* ===== PRINT ===== */
        @media print {
            body {
                display: flex;
                justify-content: flex-start;
            }

            .page-wrap {
                max-width: none;
                width: 148.5mm;
                margin: 0;
                padding: 8mm;
                box-shadow: none;
            }

            .toolbar {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <div class="toolbar no-print">
        <h3>📄 Preview Laporan - <?= getNamaHari($tanggal) ?>, <?= formatTanggalIndonesia($tanggal) ?></h3>
        <div>
            <button class="toolbar-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
            <button class="toolbar-btn" onclick="tutupHalaman()">✕ Tutup</button>
        </div>
    </div>

    <div class="page-wrap">

        <!-- ===== KOP SURAT ===== -->
        <table class="header-table">
            <tr>
                <td class="col-logo">
                    <img src="../assets/logo.png" alt="Logo KBUS">
                </td>

                <td class="col-kop">
                    <div class="label-koperasi">KOPERASI</div>
                    <div class="nama-koperasi">BINA USAHA SAUYUNAN</div>
                    <div class="tagline">"Bersama Membangun Usaha, Bersatu Meraih Sejahtera"</div>
                    <div class="alamat">Kp. Panyingkiran - Singaparna - Kab. Tasikmalaya</div>
                    <div class="alamat">email : kop.binausahasauyunan@gmail.com</div>
                </td>
            </tr>
        </table>

        <!-- ===== JUDUL ===== -->
        <div class="judul">FAKTUR PENJUALAN</div>

        <!-- ===== INFO LAPORAN ===== -->
        <?php
        // Ambil info SPPG dari record pertama (asumsi 1 laporan = 1 SPPG per tanggal)
        // Sesuaikan nama kolom (nama_sppg, no_kontak, alamat, no_faktur) dengan struktur tabel belanja kamu
        $infoSppg     = $belanjaList[0]['nama_sppg']  ?? '-';
        $infoKontak   = $belanjaList[0]['no_kontak']  ?? '-';
        $infoAlamat   = $belanjaList[0]['alamat']     ?? '-';
        $infoNoFaktur = $belanjaList[0]['no_faktur']  ?? '-';
        ?>
        <table class="info-table">
            <tr>
                <td class="label">Nama SPPG</td>
                <td class="value">: <?= htmlspecialchars($infoSppg) ?></td>
                <td class="label-right">Tanggal</td>
                <td class="value-right">: <?= getNamaHari($tanggal) ?>, <?= formatTanggalIndonesia($tanggal) ?></td>
            </tr>
            <tr>
                <td class="label">No Kontak</td>
                <td class="value">: <?= htmlspecialchars($infoKontak) ?></td>
                <td class="label-right">No Faktur</td>
                <td class="value-right">: <?= htmlspecialchars($infoNoFaktur) ?></td>
            </tr>
            <tr>
                <td class="label">Alamat</td>
                <td colspan="3">: <?= htmlspecialchars($infoAlamat) ?></td>
            </tr>
        </table>

        <?php
        $grandTotal = 0;
        if (empty($belanjaList)):
        ?>
            <div class="empty-msg">Tidak ada data pembelian pada tanggal ini.</div>
        <?php else: ?>
            <?php foreach ($belanjaList as $belanja):
                $stmt = $pdo->prepare("SELECT * FROM belanja_detail WHERE id_belanja = ?");
                $stmt->execute([$belanja['id_belanja']]);
                $details = $stmt->fetchAll();
                $menuTotal = array_sum(array_column($details, 'jumlah'));
                $grandTotal += $menuTotal;


            ?>
                <div class="menu-section">
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
                            <?php $no = 1;
                            foreach ($details as $detail):  ?>
                                <tr>
                                    <td class="center"><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($detail['item_barang']) ?></td>
                                    <td class="center"><?= rtrim(rtrim(number_format((float)$detail['qty'], 2, ',', '.'), '0'), ',') ?></td>
                                    <td class="left"><?= htmlspecialchars($detail['satuan']) ?></td>
                                    <td class="right">Rp <?= number_format($detail['harga_satuan'], 0, ',', '.') ?></td>
                                    <td class="right">Rp <?= number_format($detail['jumlah'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="row-total">
                                <td colspan="5" class="right">TOTAL :</td>
                                <td class="right">Rp <?= number_format($menuTotal, 0, ',', '.') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>


        <?php endif; ?>

        <!-- ===== CATATAN ===== -->
        <table class="catatan-table">
            <tr>
                <td class="catatan-label">Catatan :</td>
                <td class="catatan-isi">
                    Terimakasih telah belanja di tempat kami<br>
                    Mohon dicek dengan teliti barang yang sudah dibeli
                </td>
            </tr>
        </table>

        <!-- ===== TANDA TANGAN ===== -->
        <table class="ttd-table">
            <tr>
                <td class="ttd-kiri">
                    Penerima
                    <span class="ttd-gap"></span>
                    <span class="ttd-line">...................................</span>
                </td>

                <td class="ttd-kanan">
                    Hormat Kami,
                    <br>
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
            // Fallback: jika window.close() gagal (tab dibuka langsung, bukan via window.open)
            setTimeout(() => {
                if (!window.closed) {
                    history.back();
                }
            }, 300);
        }
    </script>

</body>

</html>