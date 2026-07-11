<?php
session_start();
// ====== CEK SESSION ROLE ======
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    header('Location: index.php');
    exit;
}
$role = $_SESSION['role'];
$isAdmin = ($role === 'admin');
$isOperator = ($role === 'operator');

// ✅ AMBIL LOKASI DAPUR DARI SESSION
$lokasiSession = $_SESSION['lokasi'] ?? 'semua';
$namaLokasi = $_SESSION['nama_op'] ?? '';

// Mapping lokasi untuk tampilan
$LOKASI_MAP = [
    'sodong' => 'Sodong',
    'sariwangi' => 'Sariwangi',
    'manonjaya' => 'Manonjaya',
    'semua' => 'Semua Dapur'
];
$namaLokasiDisplay = $LOKASI_MAP[$lokasiSession] ?? $namaLokasi;

// ====== LOGOUT ======
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

require_once 'database/koneksi.php';
require_once 'assets/icons.php';

// ====== 🔒 PROSES HAPUS DETAIL (HANYA ADMIN) ======
if (isset($_GET['delete_detail'])) {
    if (!$isAdmin) {
        header("Location: menu.php?error=unauthorized");
        exit;
    }
    $id = (int)$_GET['delete_detail'];
    $pdo->prepare("DELETE FROM belanja_detail WHERE id_detail = ?")->execute([$id]);
    header("Location: menu.php?deleted=1");
    exit;
}

// ====== 🔒 PROSES UPDATE DETAIL (HANYA ADMIN) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_detail'])) {
    if (!$isAdmin) {
        header("Location: menu.php?error=unauthorized");
        exit;
    }
    try {
        $id = $_POST['id_detail'];
        $item = $_POST['item_barang'];
        $qty = $_POST['qty'];
        $satuan = $_POST['satuan'];
        $harga = $_POST['harga_satuan'];
        $jumlah = $qty * $harga;
        $kategori = $_POST['kategori'];
        $stmt = $pdo->prepare("UPDATE belanja_detail SET item_barang=?, qty=?, satuan=?, harga_satuan=?, jumlah=?, kategori=? WHERE id_detail=?");
        $stmt->execute([$item, $qty, $satuan, $harga, $jumlah, $kategori, $id]);
        header("Location: menu.php?updated=1");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ====== ✅ PROSES UPDATE STATUS PER ITEM (OPERATOR & ADMIN) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status_item'])) {
    if (!in_array($role, ['admin', 'operator'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    try {
        $id = (int)$_POST['id_detail'];
        $status = $_POST['status_item'] ?? null;
        $keterangan = trim($_POST['keterangan_kurang'] ?? '');

        if ($status === 'kurang' && empty($keterangan)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Keterangan wajib diisi untuk status Kurang!']);
            exit;
        }

        $keteranganFinal = ($status === 'kurang') ? $keterangan : null;

        $stmt = $pdo->prepare("UPDATE belanja_detail SET status_item=?, keterangan_kurang=? WHERE id_detail=?");
        $stmt->execute([$status, $keteranganFinal, $id]);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'status' => $status,
            'keterangan' => $keteranganFinal
        ]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ====== 🔒 PROSES TAMBAH PEMBELIAN (HANYA ADMIN) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_belanja'])) {
    if (!$isAdmin) {
        header("Location: menu.php?error=unauthorized");
        exit;
    }
    $tanggal = $_POST['tanggal'];
    $judul = $_POST['judul'];
    $porsi = $_POST['porsi'] ?? 0;
    $nama_sppg = $_POST['nama_sppg'] ?? '';
    $no_kontak = $_POST['no_kontak'] ?? '';
    $alamat = $_POST['alamat'] ?? '';
    $no_faktur = $_POST['no_faktur'] ?? '';
    $lokasi = $_POST['lokasi'] ?? 'semua'; // ✅ LOKASI DAPUR

    try {
        $pdo->beginTransaction();
        // ✅ TAMBAHKAN KOLOM lokasi
        $stmt = $pdo->prepare("INSERT INTO belanja (tanggal, judul, porsi, nama_sppg, no_kontak, alamat, no_faktur, lokasi) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tanggal, $judul, $porsi, $nama_sppg, $no_kontak, $alamat, $no_faktur, $lokasi]);
        $idBelanja = $pdo->lastInsertId();

        if (isset($_FILES['foto_menu']) && is_array($_FILES['foto_menu']['name'])) {
            $uploadDir = 'uploads/menu/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            foreach ($_FILES['foto_menu']['name'] as $key => $filename) {
                if (!empty($filename) && $_FILES['foto_menu']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $newName = 'menu_' . date('YmdHis') . '_' . $idBelanja . '_' . $key . '.' . $fileExt;
                        if (move_uploaded_file($_FILES['foto_menu']['tmp_name'][$key], $uploadDir . $newName)) {
                            $pdo->prepare("INSERT INTO foto_menu_multiple (id_belanja, foto) VALUES (?, ?)")->execute([$idBelanja, $newName]);
                        }
                    }
                }
            }
        }

        $rowIndexes = $_POST['row_index'] ?? [];
        $items = $_POST['item_barang'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $satuans = $_POST['satuan'] ?? [];
        $hargas = $_POST['harga_satuan'] ?? [];
        $jumlahs = $_POST['jumlah'] ?? [];
        $kategoris = $_POST['kategori'] ?? [];
        foreach ($rowIndexes as $idx) {
            if (empty($items[$idx])) continue;
            $stmtDetail = $pdo->prepare("INSERT INTO belanja_detail (id_belanja, item_barang, qty, satuan, harga_satuan, jumlah, kategori) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtDetail->execute([$idBelanja, $items[$idx], $qtys[$idx], $satuans[$idx], $hargas[$idx], $jumlahs[$idx], $kategoris[$idx] ?? 'Bahan Pokok']);
            $idDetail = $pdo->lastInsertId();

            if (isset($_FILES['nota_files']['name'][$idx]) && is_array($_FILES['nota_files']['name'][$idx])) {
                $uploadDir = 'uploads/nota/';
                if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
                foreach ($_FILES['nota_files']['name'][$idx] as $key => $filename) {
                    if (!empty($filename) && $_FILES['nota_files']['error'][$idx][$key] === UPLOAD_ERR_OK) {
                        $tmpName = $_FILES['nota_files']['tmp_name'][$idx][$key];
                        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $newName = 'nota_' . date('YmdHis') . '_' . $idDetail . '_' . $key . '.' . $fileExt;
                        if (move_uploaded_file($tmpName, $uploadDir . $newName)) {
                            $pdo->prepare("INSERT INTO lampiran_nota (id_detail, file_nota) VALUES (?, ?)")->execute([$idDetail, $newName]);
                        }
                    }
                }
            }

            if (isset($_FILES['foto_files']['name'][$idx]) && is_array($_FILES['foto_files']['name'][$idx])) {
                $uploadDir = 'uploads/foto/';
                if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
                foreach ($_FILES['foto_files']['name'][$idx] as $key => $filename) {
                    if (!empty($filename) && $_FILES['foto_files']['error'][$idx][$key] === UPLOAD_ERR_OK) {
                        $tmpName = $_FILES['foto_files']['tmp_name'][$idx][$key];
                        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $newName = 'receiving_' . date('YmdHis') . '_' . $idDetail . '_' . $key . '.' . $fileExt;
                        if (move_uploaded_file($tmpName, $uploadDir . $newName)) {
                            $pdo->prepare("INSERT INTO foto_receiving (id_detail, foto) VALUES (?, ?)")->execute([$idDetail, $newName]);
                        }
                    }
                }
            }
        }
        $pdo->commit();
        header("Location: menu.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ====== 🔒 PROSES TAMBAH BARANG SUSULAN (HANYA ADMIN) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_single_item'])) {
    if (!$isAdmin) {
        header("Location: menu.php?error=unauthorized");
        exit;
    }
    try {
        $idBelanja = (int)$_POST['id_belanja'];
        $item = $_POST['item_barang'];
        $qty = $_POST['qty'];
        $satuan = $_POST['satuan'];
        $harga = $_POST['harga_satuan'];
        $jumlah = (float)$qty * (float)$harga;
        $kategori = $_POST['kategori'] ?? 'Bahan Pokok';
        $stmt = $pdo->prepare("INSERT INTO belanja_detail (id_belanja, item_barang, qty, satuan, harga_satuan, jumlah, kategori) VALUES (:id_belanja, :item_barang, :qty, :satuan, :harga_satuan, :jumlah, :kategori)");
        $stmt->execute([
            ':id_belanja'    => $idBelanja,
            ':item_barang'   => $item,
            ':qty'           => $qty,
            ':satuan'        => $satuan,
            ':harga_satuan'  => $harga,
            ':jumlah'        => $jumlah,
            ':kategori'      => $kategori,
        ]);
        $idDetail = $pdo->lastInsertId();

        if (isset($_FILES['nota_susulan']) && $_FILES['nota_susulan']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/nota/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileExt = strtolower(pathinfo($_FILES['nota_susulan']['name'], PATHINFO_EXTENSION));
            $newName = 'nota_' . date('YmdHis') . '_' . $idDetail . '.' . $fileExt;
            if (move_uploaded_file($_FILES['nota_susulan']['tmp_name'], $uploadDir . $newName)) {
                $pdo->prepare("INSERT INTO lampiran_nota (id_detail, file_nota) VALUES (:id_detail, :file_nota)")
                    ->execute([':id_detail' => $idDetail, ':file_nota' => $newName]);
            }
        }
        header("Location: menu.php?item_added=1");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// =====================================================
// ✅ FUNGSI: GENERATE RINGKASAN OTOMATIS
// =====================================================
function generateRingkasanStatus($details)
{
    if (empty($details)) return null;

    $stats = ['lengkap' => [], 'kurang' => [], 'tidak ada' => [], 'belum' => []];
    foreach ($details as $d) {
        $st = $d['status_item'] ?? 'belum';
        if (!in_array($st, ['lengkap', 'kurang', 'tidak ada'])) $st = 'belum';
        $stats[$st][] = [
            'item' => $d['item_barang'],
            'keterangan' => $d['keterangan_kurang'] ?? null
        ];
    }

    $total = count($details);
    $bagian = [];

    if (count($stats['belum']) === $total) return null;
    if (count($stats['lengkap']) === $total) {
        return "Semua barang ({$total} item) diterima dengan lengkap dan dalam kondisi baik.";
    }

    if (!empty($stats['lengkap'])) {
        $bagian[] = count($stats['lengkap']) . " item diterima lengkap";
    }
    if (!empty($stats['kurang'])) {
        $detailKurang = [];
        foreach ($stats['kurang'] as $k) {
            $ket = $k['keterangan'] ? " ({$k['keterangan']})" : "";
            $detailKurang[] = $k['item'] . $ket;
        }
        $bagian[] = count($stats['kurang']) . " item kurang: " . implode(', ', $detailKurang);
    }
    if (!empty($stats['tidak ada'])) {
        $namaTA = array_column($stats['tidak ada'], 'item');
        $bagian[] = count($stats['tidak ada']) . " item tidak ada: " . implode(', ', $namaTA);
    }
    if (!empty($stats['belum'])) {
        $bagian[] = count($stats['belum']) . " item belum dicek";
    }

    return ucfirst(implode('. ', $bagian)) . '.';
}

// ====== AMBIL DATA - ✅ DENGAN FILTER LOKASI ======
if ($isAdmin) {
    // Admin: lihat semua
    $belanjaList = $pdo->query("SELECT * FROM belanja ORDER BY tanggal DESC, created_at DESC")->fetchAll();
} else {
    // Operator: hanya lihat sesuai lokasi dapurnya
    $stmt = $pdo->prepare("SELECT * FROM belanja WHERE lokasi = ? ORDER BY tanggal DESC, created_at DESC");
    $stmt->execute([$lokasiSession]);
    $belanjaList = $stmt->fetchAll();
}

foreach ($belanjaList as &$belanja) {
    $stmt = $pdo->prepare("SELECT foto FROM foto_menu_multiple WHERE id_belanja = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$belanja['id_belanja']]);
    $belanja['fotos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($belanja['fotos']) && !empty($belanja['foto_menu'])) $belanja['fotos'] = [$belanja['foto_menu']];

    $stmt = $pdo->prepare("SELECT * FROM belanja_detail WHERE id_belanja = ? ORDER BY FIELD(kategori,'Bahan Pokok','Bumbu','Sayuran','Buah-buahan','Tambahan'), item_barang");
    $stmt->execute([$belanja['id_belanja']]);
    $belanja['details'] = $stmt->fetchAll();

    $belanja['status_stats'] = ['lengkap' => 0, 'kurang' => 0, 'tidak ada' => 0, 'belum' => 0];
    foreach ($belanja['details'] as &$detail) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lampiran_nota WHERE id_detail = ?");
        $stmt->execute([$detail['id_detail']]);
        $detail['jumlah_nota'] = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM foto_receiving WHERE id_detail = ?");
        $stmt->execute([$detail['id_detail']]);
        $detail['jumlah_foto'] = $stmt->fetchColumn();

        $st = $detail['status_item'] ?? 'belum';
        if ($st === 'belum' || !in_array($st, ['lengkap', 'kurang', 'tidak ada'])) {
            $belanja['status_stats']['belum']++;
        } else {
            $belanja['status_stats'][$st]++;
        }
    }
    unset($detail);

    $belanja['ringkasan_otomatis'] = generateRingkasanStatus($belanja['details']);
}
unset($belanja);

// ====== 📄 AMBIL DATA FAKTUR - ✅ FILTER LOKASI JUGA ======
$fakturMap = [];
if ($isAdmin) {
    $stmtFaktur = $pdo->query("SELECT tanggal, file_faktur FROM faktur_ttd");
} else {
    $stmtFaktur = $pdo->prepare("SELECT ft.tanggal, ft.file_faktur FROM faktur_ttd ft JOIN belanja b ON ft.tanggal = b.tanggal WHERE b.lokasi = ? GROUP BY ft.tanggal");
    $stmtFaktur->execute([$lokasiSession]);
}
foreach ($stmtFaktur->fetchAll() as $f) {
    $fakturMap[$f['tanggal']] = $f['file_faktur'];
}

function formatTanggalIndonesia($tanggal)
{
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $p = explode('-', $tanggal);
    return $p[2] . ' ' . $bulan[(int)$p[1]] . ' ' . $p[0];
}
function getNamaHari($tanggal)
{
    $hari = ['Sun' => 'Minggu', 'Mon' => 'Senin', 'Tue' => 'Selasa', 'Wed' => 'Rabu', 'Thu' => 'Kamis', 'Fri' => 'Jumat', 'Sat' => 'Sabtu'];
    return $hari[date('D', strtotime($tanggal))];
}

$KATEGORI_LIST = ['Bahan Pokok', 'Bumbu', 'Sayuran', 'Buah-buahan', 'Tambahan'];
$LOKASI_LIST = ['sodong' => 'Dapur Sodong', 'sariwangi' => 'Dapur Sariwangi', 'manonjaya' => 'Dapur Manonjaya'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Menu MBG - Koperasi Bina Usaha Sauyunan</title>
    <link rel="shortcut icon" href="assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ✅ Tambahan style untuk info dapur operator */
        .lokasi-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: #fff;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-left: 8px;
        }

        .lokasi-badge svg {
            flex-shrink: 0;
        }

        .info-dapur-operator {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 1px solid #86efac;
            border-radius: 12px;
            padding: 12px 16px;
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #166534;
            font-size: 14px;
        }

        .info-dapur-operator strong {
            color: #15803d;
        }
    </style>
</head>

<body>
    <main>
        <!-- Navbar Role -->
        <div class="role-navbar">
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <div class="role-badge role-<?= $role ?>">
                    <?php if ($isAdmin): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
                        </svg>
                        Admin
                    <?php else: ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v2h20v-2c0-3.3-6.7-5-10-5z" />
                        </svg>
                        Operator
                    <?php endif; ?>
                </div>
                <?php if ($isOperator): ?>
                    <!-- ✅ Badge lokasi untuk operator -->
                    <span class="lokasi-badge">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                            <circle cx="12" cy="10" r="3" />
                        </svg>
                        Dapur <?= htmlspecialchars($namaLokasiDisplay) ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="?logout=1" class="btn-logout" onclick="return confirm('Yakin ingin keluar?')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <polyline points="16 17 21 12 16 7" />
                    <line x1="21" y1="12" x2="9" y2="12" />
                </svg>
                Keluar
            </a>
        </div>

        <div class="header-top">
            <div class="info-menu">
                <h1 style="font-size: 24px; font-weight: 500; color:gray;">Permen Ceker</h1>
                <span style="font-style: italic; color:gray;">Perencana Menu - Cek dan Receiving Barang</span>
                <h2>Menu SPPG Yayasan Bina Warga Sauyunan</h2>

                <?php if ($isAdmin): ?>
                    <button class="btn btn-primary" style="margin-top: 16px;" onclick="openModal('modalAdd')">
                        <?= icon('plus', 16) ?> <span>Input Daftar Menu</span>
                    </button>
                <?php else: ?>
                    <!-- ✅ Info banner khusus operator dengan nama dapur -->
                    <div class="info-dapur-operator">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 21h18M3 7v14M21 7v14M6 21V10M10 21V10M14 21V10M18 21V10M12 3l9 4-9 4-9-4 9-4z" />
                        </svg>
                        <span>
                            Halo Operator <strong><?= htmlspecialchars($namaLokasiDisplay) ?></strong>!
                            Kamu hanya melihat data untuk <strong>Dapur <?= htmlspecialchars($namaLokasiDisplay) ?></strong>.
                            Tugasmu: upload foto receiving & beri status penerimaan per-item.
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="menu-carousel">
                <?php
                // ✅ Filter carousel juga berdasarkan lokasi
                if ($isAdmin) {
                    $carouselPhotos = $pdo->query("SELECT fm.foto, b.judul, b.tanggal FROM foto_menu_multiple fm JOIN belanja b ON fm.id_belanja = b.id_belanja ORDER BY fm.uploaded_at DESC LIMIT 10")->fetchAll();
                } else {
                    $stmtCarousel = $pdo->prepare("SELECT fm.foto, b.judul, b.tanggal FROM foto_menu_multiple fm JOIN belanja b ON fm.id_belanja = b.id_belanja WHERE b.lokasi = ? ORDER BY fm.uploaded_at DESC LIMIT 10");
                    $stmtCarousel->execute([$lokasiSession]);
                    $carouselPhotos = $stmtCarousel->fetchAll();
                }

                if (empty($carouselPhotos)): ?>
                    <div class="slide active"><img src="https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=800&q=80" alt="Menu"></div>
                <?php else: ?>
                    <?php foreach ($carouselPhotos as $i => $photo): ?>
                        <div class="slide <?= $i === 0 ? 'active' : '' ?>">
                            <img src="uploads/menu/<?= htmlspecialchars($photo['foto']) ?>" alt="<?= htmlspecialchars($photo['judul']) ?>">
                            <div class="slide-caption"><?= htmlspecialchars($photo['judul']) ?> — <?= formatTanggalIndonesia($photo['tanggal']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <button class="prev" onclick="changeSlide(-1)"><?= icon('chevron-left', 18) ?></button>
                <button class="next" onclick="changeSlide(1)"><?= icon('chevron-right', 18) ?></button>
            </div>
        </div>

        <!-- SEARCH BAR -->
        <div class="searchbar-wrap">
            <div class="searchbar-inner">
                <span class="searchbar-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                </span>
                <input type="text" id="searchMenu" class="searchbar-input" placeholder="Cari nama menu... (contoh: Nasi Ayam)" oninput="filterMenu(this.value)" autocomplete="off">
                <button class="searchbar-clear" id="searchClear" onclick="clearSearch()" title="Hapus pencarian">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="searchbar-result" id="searchResult"></div>
        </div>

        <?php if (isset($_GET['success'])): ?><div class="alert alert-success"><?= icon('check', 18) ?> <span>Daftar Menu berhasil ditambahkan!</span></div><?php endif; ?>
        <?php if (isset($_GET['updated'])): ?><div class="alert alert-success"><?= icon('check', 18) ?> <span>Item berhasil diupdate!</span></div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success"><?= icon('check', 18) ?> <span>Item berhasil dihapus!</span></div><?php endif; ?>
        <?php if (isset($_GET['item_added'])): ?><div class="alert alert-success"><?= icon('check', 18) ?> <span>Barang susulan berhasil ditambahkan!</span></div><?php endif; ?>
        <?php if (isset($_GET['faktur_uploaded'])): ?><div class="alert alert-success"><?= icon('check', 18) ?> <span>Foto faktur tertandatangan berhasil diupload!</span></div><?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?><div class="alert alert-error"><?= icon('alert', 18) ?> <span>Akses ditolak! Hanya admin yang bisa melakukan aksi ini.</span></div><?php endif; ?>
        <?php if (isset($error)): ?><div class="alert alert-error"><?= icon('alert', 18) ?> <span>Error: <?= htmlspecialchars($error) ?></span></div><?php endif; ?>

        <?php if (empty($belanjaList)): ?>
            <div class="empty-state">
                <div style="text-align:center;padding:60px 20px;color:var(--muted);">
                    <div style="margin-bottom:16px;"><?= icon('empty', 64) ?></div>
                    <h3 style="margin-bottom:8px;color:var(--text);">Belum Ada Data Pembelian</h3>
                    <p>
                        <?php if ($isAdmin): ?>
                            Silakan klik tombol "Input Daftar Menu" untuk menambahkan data
                        <?php else: ?>
                            Belum ada data pembelian untuk <strong>Dapur <?= htmlspecialchars($namaLokasiDisplay) ?></strong>.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <?php
            $groupedByDate = [];
            foreach ($belanjaList as $b) $groupedByDate[$b['tanggal']][] = $b;
            $firstDate = true;
            foreach ($groupedByDate as $tanggal => $menus):
            ?>
                <div class="date-group" data-tanggal="<?= $tanggal ?>">
                    <div class="date-header accordion-toggle <?= $firstDate ? 'open' : '' ?>" onclick="toggleAccordion(this)">
                        <div>
                            <h3><?= icon('calendar', 20) ?> <?= getNamaHari($tanggal) ?>, <?= formatTanggalIndonesia($tanggal) ?></h3>
                            <p>Total Menu: <?= count($menus) ?></p>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <?php if ($isAdmin): ?>
                                <?php if (isset($fakturMap[$tanggal])): ?>
                                    <button class="btn btn-secondary btn-sm" onclick="event.stopPropagation(); viewFullImage('uploads/faktur/<?= htmlspecialchars($fakturMap[$tanggal]) ?>')">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                            <circle cx="12" cy="12" r="3" />
                                        </svg>
                                        <span>Lihat Foto Faktur</span>
                                    </button>
                                <?php else: ?>
                                    <label class="btn btn-warning btn-sm" style="cursor:pointer;" onclick="event.stopPropagation();">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                            <polyline points="17 8 12 3 7 8" />
                                            <line x1="12" y1="3" x2="12" y2="15" />
                                        </svg>
                                        <span>Upload Faktur TTD</span>
                                        <input type="file" accept="image/*,.pdf" hidden onchange="event.stopPropagation(); uploadFakturTTD(this, '<?= $tanggal ?>')">
                                    </label>
                                <?php endif; ?>
                                <button class="btn btn-success btn-sm" onclick="event.stopPropagation(); exportPDF('<?= $tanggal ?>')">
                                    <?= icon('download', 14) ?> <span>Ekspor PDF</span>
                                </button>
                            <?php endif; ?>
                            <span class="accordion-icon">▼</span>
                        </div>
                    </div>
                    <div class="date-content <?= $firstDate ? 'active' : '' ?>">
                        <?php foreach ($menus as $belanja): ?>
                            <div class="menu-card">
                                <?php if (!empty($belanja['nama_sppg']) || !empty($belanja['no_faktur'])): ?>
                                    <div class="dapur-info-badge">
                                        <span>
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z" />
                                                <path d="M17 18h1" />
                                                <path d="M12 18h1" />
                                                <path d="M7 18h1" />
                                            </svg>
                                            <?= htmlspecialchars($belanja['nama_sppg'] ?? '-') ?>
                                        </span>
                                        <span>
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                                <polyline points="14 2 14 8 20 8" />
                                                <line x1="16" y1="13" x2="8" y2="13" />
                                                <line x1="16" y1="17" x2="8" y2="17" />
                                            </svg>
                                            <?= htmlspecialchars($belanja['no_faktur'] ?? '-') ?>
                                        </span>
                                        <span>
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
                                            </svg>
                                            <?= htmlspecialchars($belanja['no_kontak'] ?? '-') ?>
                                        </span>
                                        <?php if ($isAdmin && !empty($belanja['lokasi'])): ?>
                                            <!-- ✅ Tampilkan badge lokasi untuk admin -->
                                            <span style="background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;">
                                                📍 <?= htmlspecialchars($LOKASI_MAP[$belanja['lokasi']] ?? $belanja['lokasi']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="menu-card-header">
                                    <div class="menu-card-left">
                                        <div>
                                            <h4 class="menu-title"><?= htmlspecialchars($belanja['judul']) ?></h4>
                                            <p class="menu-info">Porsi: <strong><?= number_format($belanja['porsi'] ?? 0) ?></strong></p>
                                            <?php if (!empty($belanja['alamat'])): ?>
                                                <p class="menu-info menu-info-alamat">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                                        <circle cx="12" cy="10" r="3" />
                                                    </svg>
                                                    <?= htmlspecialchars($belanja['alamat']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="menu-card-actions">
                                            <button type="button" class="btn btn-primary btn-sm btn-upload-menu" onclick="showUploadMenuOptions(<?= $belanja['id_belanja'] ?>)">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                    <polyline points="17 8 12 3 7 8" />
                                                    <line x1="12" y1="3" x2="12" y2="15" />
                                                </svg>
                                                <span><?= !empty($belanja['fotos']) ? 'Tambah Foto' : 'Upload Foto' ?></span>
                                            </button>
                                            <input type="file" id="menuPhotoGaleri_<?= $belanja['id_belanja'] ?>" accept="image/*" multiple hidden onchange="uploadInlinePhoto(this, 'add_menu_photo', <?= $belanja['id_belanja'] ?>)">
                                            <input type="file" id="menuPhotoKamera_<?= $belanja['id_belanja'] ?>" accept="image/*" capture="environment" hidden onchange="uploadInlinePhoto(this, 'add_menu_photo', <?= $belanja['id_belanja'] ?>)">
                                            <?php if ($isAdmin): ?>
                                                <button type="button" class="btn btn-secondary btn-sm" onclick="openAddItemModal(<?= $belanja['id_belanja'] ?>, '<?= htmlspecialchars(addslashes($belanja['judul'])) ?>')">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <line x1="12" y1="5" x2="12" y2="19" />
                                                        <line x1="5" y1="12" x2="19" y2="12" />
                                                    </svg>
                                                    <span>Tambah Barang</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($belanja['fotos']) && count($belanja['fotos']) > 0): ?>
                                        <div class="menu-thumbnail" onclick="viewFullImage('uploads/menu/<?= htmlspecialchars($belanja['fotos'][0]) ?>')">
                                            <img src="uploads/menu/<?= htmlspecialchars($belanja['fotos'][0]) ?>" alt="<?= htmlspecialchars($belanja['judul']) ?>">
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($isAdmin): ?>
                                    <?php
                                    $kategoriGroups = [
                                        'Bahan Pokok' => ['color' => '#2563eb', 'bg' => '#eff6ff', 'border' => '#bfdbfe'],
                                        'Bumbu'       => ['color' => '#d97706', 'bg' => '#fffbeb', 'border' => '#fde68a'],
                                        'Sayuran'     => ['color' => '#16a34a', 'bg' => '#f0fdf4', 'border' => '#bbf7d0'],
                                        'Buah-buahan' => ['color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fecaca'],
                                        'Tambahan'    => ['color' => '#7c3aed', 'bg' => '#f5f3ff', 'border' => '#ddd6fe'],
                                    ];
                                    $grouped = [];
                                    foreach ($belanja['details'] as $d) {
                                        $kat = !empty($d['kategori']) ? $d['kategori'] : 'Bahan Pokok';
                                        $grouped[$kat][] = $d['item_barang'];
                                    }
                                    ?>
                                    <div class="kategori-cards">
                                        <?php foreach ($kategoriGroups as $katName => $katStyle): ?>
                                            <div class="kategori-card" style="--cat-color: <?= $katStyle['color'] ?>; --cat-bg: <?= $katStyle['bg'] ?>; --cat-border: <?= $katStyle['border'] ?>;">
                                                <div class="kategori-card-header">
                                                    <span class="kategori-card-title"><?= $katName ?></span>
                                                    <span class="kategori-card-count"><?= count($grouped[$katName] ?? []) ?></span>
                                                </div>
                                                <div class="kategori-card-body">
                                                    <?php if (!empty($grouped[$katName])): ?>
                                                        <ul class="kategori-item-list">
                                                            <?php foreach ($grouped[$katName] as $itemName): ?>
                                                                <li class="kategori-item"><span class="kategori-item-dot"></span> <?= htmlspecialchars($itemName) ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <div class="kategori-empty">Kosong</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="item-list">
                                    <?php
                                    $no = 1;
                                    $totalBelanja = 0;
                                    if (!isset($kategoriGroups)) {
                                        $kategoriGroups = [
                                            'Bahan Pokok' => ['color' => '#2563eb', 'bg' => '#eff6ff'],
                                            'Bumbu'       => ['color' => '#d97706', 'bg' => '#fffbeb'],
                                            'Sayuran'     => ['color' => '#16a34a', 'bg' => '#f0fdf4'],
                                            'Buah-buahan' => ['color' => '#dc2626', 'bg' => '#fef2f2'],
                                            'Tambahan'    => ['color' => '#7c3aed', 'bg' => '#f5f3ff'],
                                        ];
                                    }
                                    foreach ($belanja['details'] as $detail):
                                        $totalBelanja += $detail['jumlah'];
                                        $katColor = $kategoriGroups[$detail['kategori'] ?? 'Bahan Pokok']['color'] ?? '#64748b';
                                        $katBg = $kategoriGroups[$detail['kategori'] ?? 'Bahan Pokok']['bg'] ?? '#f1f5f9';
                                        $qtyDisplay = rtrim(rtrim(number_format((float)$detail['qty'], 2, ',', '.'), '0'), ',');
                                        $currentStatus = $detail['status_item'] ?? null;
                                    ?>
                                        <div class="item-row" data-id-detail="<?= $detail['id_detail'] ?>">
                                            <div class="item-row-main">
                                                <div class="item-row-info">
                                                    <span class="item-row-no"><?= $no++ ?></span>
                                                    <div class="item-row-text">
                                                        <div class="item-row-name-line">
                                                            <span class="item-row-name"><?= htmlspecialchars($detail['item_barang']) ?></span>
                                                            <span class="item-qty-chip"><?= $qtyDisplay ?> <?= htmlspecialchars($detail['satuan']) ?></span>
                                                        </div>
                                                        <div class="item-row-meta">
                                                            <span class="badge-kategori" style="background: <?= $katBg ?>; color: <?= $katColor ?>;"><?= htmlspecialchars($detail['kategori'] ?? 'Bahan Pokok') ?></span>
                                                            <?php if ($isAdmin): ?>
                                                                <span class="item-row-harga">Rp <?= number_format($detail['harga_satuan'], 0, ',', '.') ?> / <?= htmlspecialchars($detail['satuan']) ?></span>
                                                            <?php endif; ?>

                                                            <?php if ($isOperator): ?>
                                                                <div class="status-toggle-group" data-id="<?= $detail['id_detail'] ?>">
                                                                    <button type="button" class="status-btn status-lengkap <?= $currentStatus === 'lengkap' ? 'active' : '' ?>" onclick="setStatus(<?= $detail['id_detail'] ?>, 'lengkap', this)" title="Barang diterima lengkap">
                                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                                            <polyline points="20 6 9 17 4 12" />
                                                                        </svg>
                                                                        <span>Lengkap</span>
                                                                    </button>
                                                                    <button type="button" class="status-btn status-kurang <?= $currentStatus === 'kurang' ? 'active' : '' ?>" onclick="handleKurangClick(<?= $detail['id_detail'] ?>, this)" title="Barang kurang - wajib isi keterangan">
                                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                                                            <line x1="12" y1="9" x2="12" y2="13" />
                                                                            <line x1="12" y1="17" x2="12.01" y2="17" />
                                                                        </svg>
                                                                        <span>Kurang</span>
                                                                    </button>
                                                                    <button type="button" class="status-btn status-tidakada <?= $currentStatus === 'tidak ada' ? 'active' : '' ?>" onclick="setStatus(<?= $detail['id_detail'] ?>, 'tidak ada', this)" title="Barang tidak ada / tidak dikirim">
                                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                                            <line x1="18" y1="6" x2="6" y2="18" />
                                                                            <line x1="6" y1="6" x2="18" y2="18" />
                                                                        </svg>
                                                                        <span>Tidak Ada</span>
                                                                    </button>
                                                                </div>
                                                            <?php elseif ($isAdmin): ?>
                                                                <?php if ($currentStatus === 'lengkap'): ?>
                                                                    <span class="status-badge status-badge-lengkap">
                                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                                                                            <polyline points="20 6 9 17 4 12" />
                                                                        </svg>
                                                                        Lengkap
                                                                    </span>
                                                                <?php elseif ($currentStatus === 'kurang'): ?>
                                                                    <span class="status-badge status-badge-kurang">
                                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                                                                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                                                        </svg>
                                                                        Kurang
                                                                    </span>
                                                                <?php elseif ($currentStatus === 'tidak ada'): ?>
                                                                    <span class="status-badge status-badge-tidakada">
                                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                                                                            <line x1="18" y1="6" x2="6" y2="18" />
                                                                            <line x1="6" y1="6" x2="18" y2="18" />
                                                                        </svg>
                                                                        Tidak Ada
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="status-badge status-badge-belum">Belum Dicek</span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </div>

                                                        <?php if ($currentStatus === 'kurang' && !empty($detail['keterangan_kurang'])): ?>
                                                            <div class="keterangan-kurang-box">
                                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                                                                </svg>
                                                                <span><strong>Keterangan:</strong> <?= htmlspecialchars($detail['keterangan_kurang']) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if ($isAdmin): ?>
                                                    <div class="item-row-subtotal">Rp <?= number_format($detail['jumlah'], 0, ',', '.') ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="item-row-actions">
                                                <?php if ($isAdmin): ?>
                                                    <div class="action-group">
                                                        <?php if ($detail['jumlah_nota'] > 0): ?>
                                                            <button type="button" class="action-btn action-btn-nota" onclick="viewPhotos(<?= $detail['id_detail'] ?>, 'nota', <?= $detail['jumlah_nota'] ?>)" title="Lihat Nota (<?= $detail['jumlah_nota'] ?>)">
                                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                                                    <polyline points="14 2 14 8 20 8" />
                                                                    <line x1="16" y1="13" x2="8" y2="13" />
                                                                    <line x1="16" y1="17" x2="8" y2="17" />
                                                                </svg>
                                                                <span><?= $detail['jumlah_nota'] ?></span>
                                                            </button>
                                                        <?php endif; ?>
                                                        <label class="action-btn action-btn-nota-add" title="Tambah Nota">
                                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                                <line x1="12" y1="5" x2="12" y2="19" />
                                                                <line x1="5" y1="12" x2="19" y2="12" />
                                                            </svg>
                                                            <input type="file" accept="image/*,.pdf" multiple hidden onchange="uploadInlinePhoto(this, 'add_nota', <?= $detail['id_detail'] ?>)">
                                                        </label>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="action-group">
                                                    <?php if ($detail['jumlah_foto'] > 0): ?>
                                                        <button type="button" class="action-btn action-btn-foto" onclick="viewPhotos(<?= $detail['id_detail'] ?>, 'foto', <?= $detail['jumlah_foto'] ?>)" title="Lihat Foto Receiving (<?= $detail['jumlah_foto'] ?>)">
                                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                                <circle cx="12" cy="12" r="3" />
                                                            </svg>
                                                            <span><?= $detail['jumlah_foto'] ?></span>
                                                        </button>
                                                    <?php endif; ?>
                                                    <label class="action-btn action-btn-galeri" title="Pilih dari Galeri">
                                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <rect x="3" y="3" width="18" height="18" rx="2" />
                                                            <circle cx="8.5" cy="8.5" r="1.5" />
                                                            <polyline points="21 15 16 10 5 21" />
                                                        </svg>
                                                        <input type="file" accept="image/*" multiple hidden onchange="uploadInlinePhoto(this, 'add_foto_receiving', <?= $detail['id_detail'] ?>)">
                                                    </label>
                                                    <label class="action-btn action-btn-kamera" title="Ambil Foto dengan Kamera">
                                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                                                            <circle cx="12" cy="13" r="4" />
                                                        </svg>
                                                        <input type="file" accept="image/*" capture="environment" hidden onchange="uploadInlinePhoto(this, 'add_foto_receiving', <?= $detail['id_detail'] ?>)">
                                                    </label>
                                                </div>

                                                <?php if ($isAdmin): ?>
                                                    <div class="action-group action-group-end">
                                                        <button type="button" class="action-btn action-btn-edit" onclick="openEditItem(this)"
                                                            data-id="<?= $detail['id_detail'] ?>"
                                                            data-item="<?= htmlspecialchars($detail['item_barang']) ?>"
                                                            data-qty="<?= $detail['qty'] ?>"
                                                            data-satuan="<?= htmlspecialchars($detail['satuan']) ?>"
                                                            data-harga="<?= $detail['harga_satuan'] ?>"
                                                            data-kategori="<?= htmlspecialchars($detail['kategori']) ?>" title="Edit Item">
                                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                                                            </svg>
                                                        </button>
                                                        <a href="?delete_detail=<?= $detail['id_detail'] ?>" class="action-btn action-btn-delete" onclick="return confirm('Yakin ingin menghapus item ini?')" title="Hapus Item">
                                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <polyline points="3 6 5 6 21 6" />
                                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                                <line x1="10" y1="11" x2="10" y2="17" />
                                                                <line x1="14" y1="11" x2="14" y2="17" />
                                                            </svg>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (empty($belanja['details'])): ?>
                                        <div class="item-list-empty">Belum ada item barang</div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($belanja['details'])): ?>
                                    <div class="item-list-footer">
                                        <?php if ($isAdmin): ?>
                                            <span>Total Belanja</span>
                                            <strong>Rp <?= number_format($totalBelanja, 0, ',', '.') ?></strong>
                                        <?php else: ?>
                                            <span>Total Item</span>
                                            <strong><?= count($belanja['details']) ?> item</strong>
                                        <?php endif; ?>
                                    </div>

                                    <div class="ringkasan-status-box">
                                        <div class="ringkasan-header">
                                            <h4>
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                                    <polyline points="14 2 14 8 20 8" />
                                                    <line x1="16" y1="13" x2="8" y2="13" />
                                                    <line x1="16" y1="17" x2="8" y2="17" />
                                                </svg>
                                                Ringkasan Status Penerimaan
                                            </h4>
                                            <div class="ringkasan-stats">
                                                <span class="stat-chip stat-lengkap">✓ <?= $belanja['status_stats']['lengkap'] ?></span>
                                                <span class="stat-chip stat-kurang">⚠ <?= $belanja['status_stats']['kurang'] ?></span>
                                                <span class="stat-chip stat-tidakada">✗ <?= $belanja['status_stats']['tidak ada'] ?></span>
                                                <span class="stat-chip stat-belum">? <?= $belanja['status_stats']['belum'] ?></span>
                                            </div>
                                        </div>

                                        <?php if (!empty($belanja['ringkasan_otomatis'])): ?>
                                            <div class="ringkasan-readonly">
                                                <p><?= nl2br(htmlspecialchars($belanja['ringkasan_otomatis'])) ?></p>
                                                <small class="ringkasan-updated">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                                        <circle cx="12" cy="12" r="10" />
                                                        <polyline points="12 6 12 12 16 14" />
                                                    </svg>
                                                    Di-generate otomatis dari status per-item
                                                </small>
                                            </div>
                                        <?php else: ?>
                                            <div class="ringkasan-empty">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10" />
                                                    <line x1="12" y1="8" x2="12" y2="12" />
                                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                                </svg>
                                                <span>
                                                    <?php if ($isOperator): ?>
                                                        Ringkasan akan muncul otomatis setelah kamu mengisi status penerimaan per-item.
                                                    <?php else: ?>
                                                        Operator belum mengisi status penerimaan.
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php $firstDate = false;
            endforeach; ?>
        <?php endif; ?>
    </main>

    <!-- Modal Tambah Pembelian (HANYA ADMIN) -->
    <?php if ($isAdmin): ?>
        <div class="modal-overlay" id="modalAdd">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h2><?= icon('plus', 20) ?> Input Daftar Menu</h2>
                    <button class="close-modal" onclick="closeModal('modalAdd')"><?= icon('x', 20) ?></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formBelanja">
                    <div class="form-section">
                        <h3 class="section-title">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;">
                                <path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z" />
                                <path d="M17 18h1" />
                                <path d="M12 18h1" />
                                <path d="M7 18h1" />
                            </svg>
                            Informasi Dapur
                        </h3>

                        <!-- ✅ DROPDOWN LOKASI DAPUR (BARU!) -->
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label>Pilih Dapur <span class="required">*</span></label>
                            <select name="lokasi" class="form-control" required style="font-weight:600;">
                                <option value="">-- Pilih Dapur --</option>
                                <?php foreach ($LOKASI_LIST as $key => $label): ?>
                                    <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">
                                Data ini hanya akan muncul untuk operator dapur yang dipilih
                            </small>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Nama SPPG <span class="required">*</span></label>
                                <input type="text" name="nama_sppg" class="form-control" placeholder="Contoh: Dapur SPPG 1" required>
                            </div>
                            <div class="form-group">
                                <label>No Kontak</label>
                                <input type="text" name="no_kontak" class="form-control" placeholder="08xxxxxxxxxx">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Alamat Dapur</label>
                            <textarea name="alamat" class="form-control" rows="2" placeholder="Alamat lengkap dapur..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>No Faktur <small style="color:var(--muted);">(Otomatis)</small></label>
                            <input type="text" name="no_faktur" class="form-control" readonly style="background:#f1f5f9; font-weight:600; color:var(--primary);">
                        </div>
                    </div>
                    <div class="form-section">
                        <h3 class="section-title">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;">
                                <path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2" />
                                <path d="M7 2v20" />
                                <path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7" />
                            </svg>
                            Informasi Menu
                        </h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Tanggal <span class="required">*</span></label>
                                <input type="date" name="tanggal" class="form-control" required value="<?= date('Y-m-d') ?>" onchange="updateNoFaktur()">
                            </div>
                            <div class="form-group">
                                <label>Nama Menu <span class="required">*</span></label>
                                <input type="text" name="judul" class="form-control" placeholder="Contoh: Nasi Kotak Ayam Bakar" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Total Porsi</label>
                                <input type="number" name="porsi" class="form-control" value="0" min="0">
                            </div>
                            <div class="form-group">
                                <label><?= icon('camera', 14) ?> Upload Foto Menu (Bisa Banyak)</label>
                                <div class="upload-menu-options-inline">
                                    <button type="button" class="btn-upload-option btn-opt-kamera" onclick="document.getElementById('fotoMenuKamera').click()">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                                            <circle cx="12" cy="13" r="4" />
                                        </svg>
                                        <span>Ambil Foto</span>
                                        <small>Kamera HP</small>
                                    </button>
                                    <button type="button" class="btn-upload-option btn-opt-galeri" onclick="document.getElementById('fotoMenuGaleri').click()">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                            <rect x="3" y="3" width="18" height="18" rx="2" />
                                            <circle cx="8.5" cy="8.5" r="1.5" />
                                            <polyline points="21 15 16 10 5 21" />
                                        </svg>
                                        <span>Pilih dari Galeri</span>
                                        <small>Multiple foto</small>
                                    </button>
                                </div>
                                <input type="file" id="fotoMenuGaleri" name="foto_menu[]" class="file-input" accept="image/*" multiple onchange="previewFotoMenuMulti(this)">
                                <input type="file" id="fotoMenuKamera" name="foto_menu[]" class="file-input" accept="image/*" capture="environment" onchange="previewFotoMenuMulti(this)">
                                <div id="fotoMenuPreview" class="image-preview"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                            <h3 class="section-title" style="margin-bottom:0;border:none;padding:0;">Detail Item Barang</h3>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addRow()"><?= icon('plus', 14) ?> <span>Tambah Baris</span></button>
                        </div>
                        <div class="table-responsive">
                            <table class="form-table" id="tableItem">
                                <thead>
                                    <tr>
                                        <th style="width:20%">Item Barang</th>
                                        <th style="width:12%">Kategori</th>
                                        <th style="width:8%">QTY</th>
                                        <th style="width:8%">Satuan</th>
                                        <th style="width:12%">Harga</th>
                                        <th style="width:12%">Jumlah</th>
                                        <th style="width:10%">Nota</th>
                                        <th style="width:10%">Foto</th>
                                        <th style="width:8%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('modalAdd')"><?= icon('x', 14) ?> <span>Batal</span></button>
                        <button type="submit" name="save_belanja" class="btn btn-primary btn-lg"><?= icon('save', 16) ?> <span>Simpan Pembelian</span></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal Edit & Add Item (tetap sama, tidak diubah) -->
        <div class="modal-overlay" id="modalEdit">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h2><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px;">
                            <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                        </svg>Edit Item Barang</h2>
                    <button class="close-modal" onclick="closeModal('modalEdit')"><?= icon('x', 20) ?></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="id_detail" id="edit_id_detail">
                    <div class="form-section">
                        <div class="form-group">
                            <label>Nama Item Barang</label>
                            <input type="text" name="item_barang" id="edit_item_barang" class="form-control" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Kategori</label>
                                <select name="kategori" id="edit_kategori" class="form-control" required>
                                    <?php foreach ($KATEGORI_LIST as $kat): ?>
                                        <option value="<?= $kat ?>"><?= $kat ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Satuan</label>
                                <input type="text" name="satuan" id="edit_satuan" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>QTY</label>
                                <input type="number" name="qty" id="edit_qty" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Harga Satuan</label>
                                <input type="number" name="harga_satuan" id="edit_harga" class="form-control" step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('modalEdit')">Batal</button>
                        <button type="submit" name="update_detail" class="btn btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-overlay" id="modalAddItem">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h2><?= icon('plus', 18) ?> Tambah Barang Susulan</h2>
                    <button class="close-modal" onclick="closeModal('modalAddItem')"><?= icon('x', 20) ?></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formAddItem">
                    <input type="hidden" name="id_belanja" id="additem_id_belanja">
                    <div class="form-section">
                        <p id="additem_judul_menu" style="margin-bottom:14px;color:var(--muted);font-size:13px;"></p>
                        <div class="form-group">
                            <label>Nama Item Barang <span class="required">*</span></label>
                            <input type="text" name="item_barang" class="form-control" placeholder="Contoh: Minyak Goreng" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Kategori</label>
                                <select name="kategori" class="form-control" required>
                                    <?php foreach ($KATEGORI_LIST as $kat): ?>
                                        <option value="<?= $kat ?>"><?= $kat ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Satuan <span class="required">*</span></label>
                                <input type="text" name="satuan" class="form-control" placeholder="pcs/kg" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>QTY <span class="required">*</span></label>
                                <input type="number" name="qty" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Harga Satuan <span class="required">*</span></label>
                                <input type="number" name="harga_satuan" class="form-control" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><?= icon('camera', 14) ?> Lampiran Nota (Opsional)</label>
                            <input type="file" name="nota_susulan" class="form-control" accept="image/*,.pdf">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('modalAddItem')">Batal</button>
                        <button type="submit" name="add_single_item" class="btn btn-primary">Simpan Barang</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal Keterangan Kurang -->
    <div class="modal-overlay" id="modalKeterangan">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header">
                <h2 style="color:#b45309;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <line x1="12" y1="9" x2="12" y2="13" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg>
                    Keterangan Barang Kurang
                </h2>
                <button class="close-modal" onclick="closeModal('modalKeterangan')"><?= icon('x', 20) ?></button>
            </div>
            <div class="form-section">
                <p style="color:var(--muted);font-size:13px;margin-bottom:14px;line-height:1.5;">
                    <strong style="color:#b45309;">Wajib diisi!</strong> Jelaskan secara singkat apa yang kurang.
                </p>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Keterangan <span class="required">*</span></label>
                    <textarea id="keteranganInput" class="form-control" rows="4" placeholder="Contoh: Minyak goreng kurang 2 liter..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalKeterangan')">Batal</button>
                <button type="button" class="btn btn-warning" onclick="submitKeteranganKurang()" style="color:#000;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                    Simpan Status Kurang
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Photo Viewer -->
    <div class="modal-overlay" id="photoViewerModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="photoViewerTitle">Lihat Foto</h2>
                <button class="close-modal" onclick="closeModal('photoViewerModal')"><?= icon('x', 20) ?></button>
            </div>
            <div class="photo-viewer">
                <div class="photo-grid" id="photoGrid"></div>
            </div>
        </div>
    </div>

    <div class="full-image-modal" id="fullImageModal" onclick="closeFullImage()">
        <span class="full-image-close"><?= icon('x', 30) ?></span>
        <img id="fullImage" src="" alt="Full">
    </div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p id="loadingText">Memproses...</p>
    </div>

    <div id="toastNotif" class="toast-notif"></div>

    <script src="script.js?v=<?= time() ?>"></script>
    <script>
        const KATEGORI_LIST = <?= json_encode($KATEGORI_LIST) ?>;
        const USER_ROLE = '<?= $role ?>';
        const USER_LOKASI = '<?= $lokasiSession ?>';
        document.addEventListener('DOMContentLoaded', () => {
            const tbody = document.querySelector('#tableItem tbody');
            if (tbody && tbody.children.length === 0) addRow();
            updateNoFaktur();
        });
    </script>
</body>

</html>