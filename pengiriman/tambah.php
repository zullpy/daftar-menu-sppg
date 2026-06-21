<?php
require_once '../database/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        $no_faktur = $_POST['no_faktur'];
        $nama_penerima = $_POST['nama_penerima'];
        $alamat = $_POST['alamat'];
        $tanggal_ekspedisi = $_POST['tanggal_ekspedisi'];

        // Insert pengiriman
        $stmt = $pdo->prepare("INSERT INTO pengiriman (no_faktur, nama_penerima, alamat, tanggal_ekspedisi) 
                               VALUES (?, ?, ?, ?)");
        $stmt->execute([$no_faktur, $nama_penerima, $alamat, $tanggal_ekspedisi]);
        $pengiriman_id = $pdo->lastInsertId();

        // Insert detail barang
        $nama_barangs = $_POST['nama_barang'];
        $qtys = $_POST['qty'];
        $satuans = $_POST['satuan'];
        $keterangans = $_POST['keterangan'];

        $stmt_detail = $pdo->prepare("INSERT INTO detail_pengiriman 
                                     (pengiriman_id, nama_barang, qty, satuan, keterangan) 
                                     VALUES (?, ?, ?, ?, ?)");

        for ($i = 0; $i < count($nama_barangs); $i++) {
            if (!empty($nama_barangs[$i])) {
                $stmt_detail->execute([
                    $pengiriman_id,
                    $nama_barangs[$i],
                    $qtys[$i],
                    $satuans[$i],
                    $keterangans[$i]
                ]);
            }
        }

        $pdo->commit();
        header("Location: index.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Gagal menyimpan data: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Pengiriman - MBG</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>
    <div class="container">
        <header>
            <div class="logo">
                <img src="assets/logo.png" alt="Logo MBG">
                <h1>Tambah Pengiriman Barang</h1>
            </div>
            <nav>
                <a href="index.php" class="btn btn-secondary">← Kembali</a>
            </nav>
        </header>

        <main>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="formPengiriman">
                <div class="form-group">
                    <label>No. Faktur *</label>
                    <input type="text" name="no_faktur" class="form-control" required
                        value="<?= 'FTR-' . date('Ymd') . '-' . rand(1000, 9999) ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nama Penerima *</label>
                        <input type="text" name="nama_penerima" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Tanggal Ekspedisi *</label>
                        <input type="date" name="tanggal_ekspedisi" class="form-control"
                            value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Alamat Pengiriman *</label>
                    <textarea name="alamat" class="form-control" rows="3" required></textarea>
                </div>

                <div class="form-section">
                    <h3>Detail Barang</h3>
                    <div id="barangContainer">
                        <div class="barang-item">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Nama Barang *</label>
                                    <input type="text" name="nama_barang[]" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label>Qty *</label>
                                    <input type="number" name="qty[]" class="form-control" min="1" required>
                                </div>

                                <div class="form-group">
                                    <label>Satuan *</label>
                                    <select name="satuan[]" class="form-control" required>
                                        <option value="Pcs">Pcs</option>
                                        <option value="Box">Box</option>
                                        <option value="Kg">Kg</option>
                                        <option value="Liter">Liter</option>
                                        <option value="Pack">Pack</option>
                                        <option value="Unit">Unit</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Keterangan</label>
                                <textarea name="keterangan[]" class="form-control" rows="2"></textarea>
                            </div>

                            <button type="button" class="btn btn-remove" onclick="removeBarang(this)">
                                Hapus Barang
                            </button>
                        </div>
                    </div>

                    <button type="button" class="btn btn-add" onclick="addBarang()">
                        + Tambah Barang
                    </button>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success btn-lg">💾 Simpan Pengiriman</button>
                    <a href="index.php" class="btn btn-secondary btn-lg">Batal</a>
                </div>
            </form>
        </main>
    </div>

    <script src="assets/script.js"></script>
    <script>
        let barangCount = 1;

        function addBarang() {
            barangCount++;
            const container = document.getElementById('barangContainer');
            const newItem = document.createElement('div');
            newItem.className = 'barang-item';
            newItem.innerHTML = `
                <div class="form-row">
                    <div class="form-group">
                        <label>Nama Barang *</label>
                        <input type="text" name="nama_barang[]" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Qty *</label>
                        <input type="number" name="qty[]" class="form-control" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Satuan *</label>
                        <select name="satuan[]" class="form-control" required>
                            <option value="Pcs">Pcs</option>
                            <option value="Box">Box</option>
                            <option value="Kg">Kg</option>
                            <option value="Liter">Liter</option>
                            <option value="Pack">Pack</option>
                            <option value="Unit">Unit</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Keterangan</label>
                    <textarea name="keterangan[]" class="form-control" rows="2"></textarea>
                </div>
                
                <button type="button" class="btn btn-remove" onclick="removeBarang(this)">
                    Hapus Barang
                </button>
            `;
            container.appendChild(newItem);
        }

        function removeBarang(btn) {
            const items = document.querySelectorAll('.barang-item');
            if (items.length > 1) {
                btn.parentElement.remove();
            } else {
                alert('Minimal harus ada 1 barang');
            }
        }
    </script>
</body>

</html>