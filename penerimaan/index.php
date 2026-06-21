<?php
require_once '../database/koneksi.php';

if (!isset($_GET['id'])) {
    header("Location: ../pengiriman/index.php");
    exit;
}

$id = $_GET['id'];

// Ambil data pengiriman
$stmt = $pdo->prepare("SELECT * FROM pengiriman WHERE id = ?");
$stmt->execute([$id]);
$pengiriman = $stmt->fetch();

if (!$pengiriman) {
    header("Location: index.php");
    exit;
}

// Ambil detail barang
$stmt_detail = $pdo->prepare("SELECT * FROM detail_pengiriman WHERE pengiriman_id = ?");
$stmt_detail->execute([$id]);
$details = $stmt_detail->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $nama_penerima_barang = $_POST['nama_penerima_barang'];
        $status_barang = $_POST['status_barang'];
        $keterangan_kurang = $_POST['keterangan_kurang'] ?? '';
        $tanggal_terima = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("INSERT INTO penerimaan 
                              (pengiriman_id, nama_penerima_barang, tanggal_terima, 
                               status_barang, keterangan_kurang) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $id,
            $nama_penerima_barang,
            $tanggal_terima,
            $status_barang,
            $keterangan_kurang
        ]);

        header("Location: index.php?success=2");
        exit;
    } catch (Exception $e) {
        $error = "Gagal menyimpan: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Penerimaan - MBG</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>
    <div class="container">
        <header>
            <div class="logo">
                <img src="assets/logo.png" alt="Logo MBG">
                <h1>Konfirmasi Penerimaan Barang</h1>
            </div>
            <nav>
                <a href="index.php" class="btn btn-secondary">← Kembali</a>
            </nav>
        </header>

        <main>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <div class="info-card">
                <h3>Informasi Pengiriman</h3>
                <p><strong>No. Faktur:</strong> <?= htmlspecialchars($pengiriman['no_faktur']) ?></p>
                <p><strong>Penerima:</strong> <?= htmlspecialchars($pengiriman['nama_penerima']) ?></p>
                <p><strong>Alamat:</strong> <?= htmlspecialchars($pengiriman['alamat']) ?></p>
                <p><strong>Tanggal Ekspedisi:</strong>
                    <?= date('d F Y', strtotime($pengiriman['tanggal_ekspedisi'])) ?></p>
            </div>

            <h3>Detail Barang</h3>
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

            <form method="POST" action="" class="form-penerimaan">
                <div class="form-group">
                    <label>Nama Penerima Barang *</label>
                    <input type="text" name="nama_penerima_barang" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Status Penerimaan *</label>
                    <div class="status-buttons">
                        <button type="button" class="btn-status" data-value="ada" onclick="selectStatus('ada')">
                            ✓ ADA SEMUA
                        </button>
                        <button type="button" class="btn-status" data-value="kurang" onclick="selectStatus('kurang')">
                            ⚠️ KURANG
                        </button>
                        <button type="button" class="btn-status" data-value="tidak_ada" onclick="selectStatus('tidak_ada')">
                            ✗ TIDAK ADA
                        </button>
                    </div>
                    <input type="hidden" name="status_barang" id="status_barang" required>
                </div>

                <div class="form-group" id="keteranganGroup" style="display:none;">
                    <label>Keterangan Barang Kurang/Hilang</label>
                    <textarea name="keterangan_kurang" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label>Tanggal & Waktu Terima</label>
                    <input type="text" class="form-control" value="<?= date('d/m/Y H:i:s') ?>" readonly>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success btn-lg">✓ Konfirmasi Penerimaan</button>
                    <a href="index.php" class="btn btn-secondary btn-lg">Batal</a>
                </div>
            </form>
        </main>
    </div>

    <script src="assets/script.js"></script>
    <script>
        function selectStatus(status) {
            document.getElementById('status_barang').value = status;

            // Update button styles
            document.querySelectorAll('.btn-status').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-value="${status}"]`).classList.add('active');

            // Show/hide keterangan field
            const keteranganGroup = document.getElementById('keteranganGroup');
            if (status === 'kurang' || status === 'tidak_ada') {
                keteranganGroup.style.display = 'block';
            } else {
                keteranganGroup.style.display = 'none';
            }
        }
    </script>
</body>

</html>