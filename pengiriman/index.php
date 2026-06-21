<?php
require_once '../database/koneksi.php';

// Ambil data pengiriman grouped by tanggal
$query = "SELECT 
            p.*,
            DATE(p.tanggal_ekspedisi) as tgl_exp,
            GROUP_CONCAT(DISTINCT p.no_faktur) as faktur_list
          FROM pengiriman p
          GROUP BY DATE(p.tanggal_ekspedisi), p.no_faktur
          ORDER BY p.tanggal_ekspedisi DESC, p.no_faktur DESC";

$stmt = $pdo->query($query);
$data_pengiriman = $stmt->fetchAll();

// Group by tanggal
$grouped_data = [];
foreach ($data_pengiriman as $row) {
    $tgl = $row['tgl_exp'];
    if (!isset($grouped_data[$tgl])) {
        $grouped_data[$tgl] = [];
    }
    $grouped_data[$tgl][] = $row;
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi Pengiriman Barang - MBG</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="../assets/favicon.ico">
</head>

<body>
    <div class="container">
        <header>
            <div class="logo">
                <img src="../assets/logo.png" alt="Logo MBG">
                <h1>Sistem Pengiriman Barang</h1>
            </div>
            <nav>
                <a href="index.php" class="btn btn-primary">Data Pengiriman</a>
                <a href="tambah.php" class="btn btn-success">+ Tambah Pengiriman</a>
            </nav>
        </header>

        <main>
            <h2>Data Pengiriman Barang</h2>

            <?php if (empty($grouped_data)): ?>
                <div class="alert alert-info">Belum ada data pengiriman</div>
            <?php else: ?>
                <?php foreach ($grouped_data as $tanggal => $items): ?>
                    <div class="date-group">
                        <div class="date-header" onclick="toggleDate(this)">
                            <h3>📅 <?= date('d F Y', strtotime($tanggal)) ?></h3>
                            <span class="badge"><?= count($items) ?> Faktur</span>
                            <span class="arrow">▼</span>
                        </div>

                        <div class="date-content">
                            <?php foreach ($items as $item): ?>
                                <?php
                                // Ambil detail barang
                                $stmt_detail = $pdo->prepare("SELECT * FROM detail_pengiriman WHERE pengiriman_id = ?");
                                $stmt_detail->execute([$item['id']]);
                                $details = $stmt_detail->fetchAll();

                                // Cek status penerimaan
                                $stmt_terima = $pdo->prepare("SELECT * FROM penerimaan WHERE pengiriman_id = ?");
                                $stmt_terima->execute([$item['id']]);
                                $penerimaan = $stmt_terima->fetch();

                                $total_item = array_sum(array_column($details, 'qty'));
                                ?>

                                <div class="accordion-item">
                                    <div class="accordion-header" onclick="toggleAccordion(this)">
                                        <div class="faktur-info">
                                            <strong>📄 No. Faktur: <?= htmlspecialchars($item['no_faktur']) ?></strong>
                                            <span>👤 <?= htmlspecialchars($item['nama_penerima']) ?></span>
                                        </div>
                                        <div class="faktur-actions">
                                            <span class="badge-info"><?= $total_item ?> Item</span>
                                            <?php if ($penerimaan): ?>
                                                <span class="badge badge-<?= $penerimaan['status_barang'] ?>">
                                                    <?= strtoupper($penerimaan['status_barang']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-pending">BELUM DITERIMA</span>
                                            <?php endif; ?>
                                            <span class="arrow-right">▶</span>
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
                                                        <td><?= htmlspecialchars($detail['keterangan']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>

                                        <div class="action-buttons">
                                            <?php if (!$penerimaan): ?>
                                                <a href="penerimaan.php?id=<?= $item['id'] ?>" class="btn btn-warning">
                                                    ✓ Konfirmasi Penerimaan
                                                </a>
                                            <?php else: ?>
                                                <div class="status-terima">
                                                    <strong>Status:</strong>
                                                    <?= strtoupper($penerimaan['status_barang']) ?> |
                                                    Penerima: <?= htmlspecialchars($penerimaan['nama_penerima_barang']) ?> |
                                                    <?= date('d/m/Y H:i', strtotime($penerimaan['tanggal_terima'])) ?>
                                                </div>
                                            <?php endif; ?>

                                            <a href="export_pdf.php?id=<?= $item['id'] ?>" target="_blank" class="btn btn-info">
                                                📄 Surat Jalan
                                            </a>
                                            <a href="edit.php?id=<?= $item['id'] ?>" class="btn btn-primary">
                                                ✏️ Edit
                                            </a>
                                            <a href="hapus.php?id=<?= $item['id'] ?>" class="btn btn-danger"
                                                onclick="return confirm('Yakin ingin menghapus data ini?')">
                                                🗑️ Hapus
                                            </a>
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
            <p>&copy; <?= date('Y') ?> Aplikasi MBG - Sistem Pengiriman Barang</p>
        </footer>
    </div>

    <script src="script.js"></script>
</body>

</html>