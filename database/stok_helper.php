<?php

/**
 * stok_helper.php
 * Helper bersama untuk mengelola stok_barang (grosir) dan stok_barang_eceran (cerminan otomatis).
 * Taruh file ini satu folder dengan koneksi.php, lalu require di file yang butuh (penerimaan, pengambilan).
 *
 * ATURAN:
 * - stok_barang        = SUMBER KEBENARAN (satuan grosir, misal DUS/KG).
 * - stok_barang_eceran = CERMINAN OTOMATIS, selalu di-SET ulang (bukan ditambah)
 *                        dari total stok_barang * isi_per_satuan.
 * - Kalau barang tidak terdaftar di db_draft_barang / tidak ada isi_per_satuan,
 *   eceran mirror 1:1 dari grosir (satuan & qty sama).
 */

/**
 * Koneksi ke db_draft_barang (untuk mapping satuan_grosir/satuan_eceran/isi_per_satuan).
 * Return null kalau gagal konek — sistem tetap jalan, mode fallback mirror 1:1.
 */
function stok_getPdoBarang()
{
    static $pdoBarang = null;
    static $tried = false;
    if ($tried) return $pdoBarang;
    $tried = true;
    try {
        $pdoBarang = new PDO('mysql:host=localhost;dbname=db_draft_barang;charset=utf8mb4', 'root', '');
        $pdoBarang->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        $pdoBarang = null;
    }
    return $pdoBarang;
}

/**
 * Ambil mapping grosir/eceran untuk 1 nama barang.
 * Return null kalau tidak ketemu / tidak ada sistem eceran untuk barang ini.
 */
function stok_getMapping($nama_barang)
{
    $pdoBarang = stok_getPdoBarang();
    if (!$pdoBarang) return null;

    static $cache = [];
    $key = strtolower(trim($nama_barang));
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $pdoBarang->prepare("SELECT satuan, satuan_eceran, isi_per_satuan FROM barang WHERE LOWER(TRIM(nama_barang)) = :nama LIMIT 1");
    $stmt->execute([':nama' => $key]);
    $row = $stmt->fetch();

    if (!$row) {
        $cache[$key] = null;
        return null;
    }

    $satuanEceranRaw = trim($row['satuan_eceran'] ?? '');
    $isi = ((float)($row['isi_per_satuan'] ?? 0) > 0) ? (float)$row['isi_per_satuan'] : null;
    $hasEceran = $satuanEceranRaw !== '' && $isi;

    $mapping = $hasEceran ? [
        'satuan_grosir'  => trim($row['satuan']),
        'satuan_eceran'  => $satuanEceranRaw,
        'isi_per_satuan' => $isi,
    ] : null;

    $cache[$key] = $mapping;
    return $mapping;
}

/**
 * Tambah/kurangi stok GROSIR (delta bisa negatif), lalu sinkronkan mirror eceran-nya.
 */
function stok_upsertGrosir(PDO $pdo, $nama_barang, $satuan, $lokasi, $delta)
{
    if ($delta != 0) {
        $pdo->prepare("INSERT INTO stok_barang (nama_barang, satuan, lokasi, qty) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)")
            ->execute([$nama_barang, $satuan, $lokasi, $delta]);
    }
    stok_syncEceran($pdo, $nama_barang, $lokasi);
}

/**
 * Hitung ulang & SET (bukan tambah) mirror stok_barang_eceran untuk 1 nama_barang + lokasi,
 * berdasarkan total stok_barang saat ini dikali isi_per_satuan.
 */
function stok_syncEceran(PDO $pdo, $nama_barang, $lokasi)
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(qty),0) as total_qty, MAX(satuan) as satuan
        FROM stok_barang WHERE nama_barang = ? AND lokasi = ?");
    $stmt->execute([$nama_barang, $lokasi]);
    $row = $stmt->fetch();
    $qtyGrosir = (float)($row['total_qty'] ?? 0);
    $satuanGrosir = $row['satuan'] ?? '';

    $mapping = stok_getMapping($nama_barang);
    if ($mapping) {
        $qtyEceran = round($qtyGrosir * $mapping['isi_per_satuan'], 2);
        $satuanEceran = $mapping['satuan_eceran'];
    } else {
        // Tidak ada mapping -> mirror 1:1
        $qtyEceran = $qtyGrosir;
        $satuanEceran = $satuanGrosir ?: '-';
    }

    $pdo->prepare("INSERT INTO stok_barang_eceran (nama_barang, satuan, lokasi, qty) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE satuan = VALUES(satuan), qty = VALUES(qty)")
        ->execute([$nama_barang, $satuanEceran, $lokasi, $qtyEceran]);
}

/**
 * Kurangi stok untuk PENGAMBILAN barang. $satuanInput adalah satuan yang diketik operator
 * (bisa satuan grosir ATAU satuan eceran barang tsb). Fungsi ini otomatis:
 * - Deteksi apakah $satuanInput itu satuan grosir atau eceran (via mapping).
 * - Kalau eceran -> konversi qty ke setara grosir (qty / isi_per_satuan) sebelum motong stok_barang.
 * - Kalau grosir / tidak ada mapping -> potong langsung.
 * - Support lokasi 'semua' (potong bertahap dari lokasi dengan stok terbanyak).
 * - Setelah motong grosir, sinkronkan ulang mirror eceran-nya.
 *
 * Melempar Exception kalau stok grosir (setelah konversi) tidak cukup.
 */
function stok_kurangiUntukPengambilan(PDO $pdo, $nama_barang, $satuanInput, $lokasi, $qtyAmbil)
{
    $mapping = stok_getMapping($nama_barang);
    $qtyGrosirSetara = $qtyAmbil;
    $modeEceran = false;

    if ($mapping) {
        $satuanEceranNorm = strtolower(trim($mapping['satuan_eceran']));
        $satuanGrosirNorm  = strtolower(trim($mapping['satuan_grosir']));
        $satuanInputNorm   = strtolower(trim($satuanInput));

        if ($satuanInputNorm === $satuanEceranNorm && $satuanInputNorm !== $satuanGrosirNorm) {
            // Diambil dalam satuan ECERAN -> konversi ke grosir
            $qtyGrosirSetara = $qtyAmbil / $mapping['isi_per_satuan'];
            $modeEceran = true;
        }
    }

    if ($lokasi !== 'semua') {
        $stmt = $pdo->prepare("SELECT id, qty FROM stok_barang
            WHERE nama_barang = :nama AND lokasi = :lokasi FOR UPDATE");
        $stmt->execute([':nama' => $nama_barang, ':lokasi' => $lokasi]);
        $row = $stmt->fetch();
        $tersedia = $row ? (float)$row['qty'] : 0;

        if ($qtyGrosirSetara > $tersedia + 0.0001) {
            $tersediaTampil = $modeEceran && $mapping ? round($tersedia * $mapping['isi_per_satuan'], 2) : $tersedia;
            $satuanTampil = $modeEceran && $mapping ? $mapping['satuan_eceran'] : ($row['satuan'] ?? $satuanInput);
            throw new Exception("Jumlah pengambilan untuk barang '$nama_barang' ($qtyAmbil $satuanInput) melebihi stok yang tersedia ($tersediaTampil $satuanTampil)!");
        }

        if ($row) {
            $pdo->prepare("UPDATE stok_barang SET qty = qty - :q WHERE id = :id")
                ->execute([':q' => $qtyGrosirSetara, ':id' => $row['id']]);
        }
        stok_syncEceran($pdo, $nama_barang, $lokasi);
        return;
    }

    // lokasi = 'semua' -> potong bertahap dari semua baris lokasi, stok terbanyak dulu
    $stmt = $pdo->prepare("SELECT id, lokasi, qty FROM stok_barang
        WHERE nama_barang = :nama ORDER BY qty DESC FOR UPDATE");
    $stmt->execute([':nama' => $nama_barang]);
    $rows = $stmt->fetchAll();

    $totalTersedia = array_sum(array_column($rows, 'qty'));
    if ($qtyGrosirSetara > $totalTersedia + 0.0001) {
        $totalTampil = $modeEceran && $mapping ? round($totalTersedia * $mapping['isi_per_satuan'], 2) : $totalTersedia;
        $satuanTampil = $modeEceran && $mapping ? $mapping['satuan_eceran'] : $satuanInput;
        throw new Exception("Jumlah pengambilan untuk barang '$nama_barang' ($qtyAmbil $satuanInput) melebihi stok yang tersedia ($totalTampil $satuanTampil)!");
    }

    $sisaAmbil = $qtyGrosirSetara;
    $lokasiTerdampak = [];
    foreach ($rows as $row) {
        if ($sisaAmbil <= 0) break;
        $potong = min($sisaAmbil, (float)$row['qty']);
        if ($potong <= 0) continue;
        $pdo->prepare("UPDATE stok_barang SET qty = qty - :q WHERE id = :id")
            ->execute([':q' => $potong, ':id' => $row['id']]);
        $lokasiTerdampak[$row['lokasi']] = true;
        $sisaAmbil -= $potong;
    }
    foreach (array_keys($lokasiTerdampak) as $lok) {
        stok_syncEceran($pdo, $nama_barang, $lok);
    }
}
