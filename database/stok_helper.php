<?php

/**
 * stok_helper.php
 * Helper untuk mengelola stok_barang dengan 2 kolom yang terhubung:
 *   - qty_eceran  = TOTAL stok, SUMBER KEBENARAN, selalu dalam satuan
 *                   eceran/unit terkecil (mis. PCS, KG).
 *   - qty_grosir  = DITURUNKAN dari qty_eceran, cuma nunjukin berapa
 *                   banyak unit GROSIR UTUH yang masih bisa dibentuk
 *                   dari total tsb: qty_grosir = floor(qty_eceran / isi_per_satuan)
 *
 * Contoh (isi_per_satuan = 24, 1 DUS = 24 PCS):
 *   - Terima 1 DUS            -> qty_eceran = 24,  qty_grosir = 1
 *   - Terjual 2 PCS eceran    -> qty_eceran = 22,  qty_grosir = 0   (DUS-nya udah "kebuka")
 *   - Terjual lagi 22 PCS     -> qty_eceran = 0,   qty_grosir = 0
 *   - (Kalau langsung beli 1 DUS utuh dari stok 1 DUS -> qty_eceran = 0, qty_grosir = 0 juga)
 *
 * Konversi pakai isi_per_satuan dari tabel `barang` (db_draft_barang).
 * Kalau barang tidak punya mapping eceran, semua operasi jatuh ke
 * qty_grosir apa adanya (fallback), qty_eceran dibiarkan 0.
 *
 * PERUBAHAN:
 * - stok_barang_eceran (tabel mirror terpisah) TIDAK dipakai lagi.
 * - Kolom stok_barang.qty berganti nama jadi qty_grosir.
 * - qty_eceran sekarang jadi SUMBER KEBENARAN (total penuh, bukan sisa/
 *   remainder, dan bukan mirror kali dari qty_grosir).
 * - Semua fungsi publik (stok_upsertGrosir, stok_tambahEceran,
 *   stok_kurangiEceran, stok_kurangiUntukPengambilan) dipanggil dengan
 *   cara SAMA seperti sebelumnya, jadi tambah.php / index.php / penerimaan
 *   tidak perlu diubah.
 */

/**
 * Koneksi ke db_draft_barang (untuk mapping satuan_grosir/satuan_eceran/isi_per_satuan).
 * Return null kalau gagal konek — sistem tetap jalan, mode fallback (tanpa eceran).
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
 * Turunkan ulang qty_grosir dari qty_eceran (sumber kebenaran):
 * qty_grosir = floor(qty_eceran / isi_per_satuan)
 * Dipanggil di akhir setiap fungsi yang mengubah qty_eceran.
 * Tidak melakukan apa-apa kalau barang tidak punya mapping eceran.
 */
function stok_syncGrosir(PDO $pdo, $nama_barang, $lokasi)
{
    $mapping = stok_getMapping($nama_barang);
    if (!$mapping) return;

    $isi = $mapping['isi_per_satuan'];
    $stmt = $pdo->prepare("SELECT id, qty_eceran FROM stok_barang WHERE nama_barang = ? AND lokasi = ? FOR UPDATE");
    $stmt->execute([$nama_barang, $lokasi]);
    $row = $stmt->fetch();
    if (!$row) return;

    $totalEceran = (float)$row['qty_eceran'];
    if ($totalEceran < 0) $totalEceran = 0; // jaga-jaga, stok gak boleh minus

    $qtyGrosirBaru = floor($totalEceran / $isi);

    $pdo->prepare("UPDATE stok_barang SET qty_grosir = ?, qty_eceran = ?, satuan_eceran = ? WHERE id = ?")
        ->execute([$qtyGrosirBaru, round($totalEceran, 2), $mapping['satuan_eceran'], $row['id']]);
}

/**
 * (Alias lama, tetap ada biar kompatibel kalau ada kode lain yang manggil
 * nama ini) -- sekarang isinya cuma turunkan ulang qty_grosir dari qty_eceran.
 */
function stok_normalisasi(PDO $pdo, $nama_barang, $lokasi)
{
    stok_syncGrosir($pdo, $nama_barang, $lokasi);
}

/**
 * Tambah/kurangi stok, delta BISA dalam satuan grosir ATAU satuan eceran
 * (dideteksi otomatis dari $satuan yang dikirim, dibandingkan dengan
 * satuan_grosir/satuan_eceran barang tsb). Delta selalu dikonversi dulu
 * ke satuan ECERAN (unit terkecil) dan ditambahkan ke qty_eceran (sumber
 * kebenaran), lalu qty_grosir diturunkan ulang (floor pembagian).
 *
 * Dipanggil dengan cara sama seperti sebelumnya dari tambah.php / index.php,
 * cuma sekarang aman walau $satuan yang dikirim ternyata satuan eceran
 * (mis. operator ngetik "PCS" padahal satuan_grosir barang itu "DUS").
 */
function stok_upsertGrosir(PDO $pdo, $nama_barang, $satuan, $lokasi, $delta)
{
    $mapping = stok_getMapping($nama_barang);

    if (!$mapping) {
        // Fallback: barang tanpa sistem eceran -> perilaku lama, langsung ke qty_grosir
        if ($delta != 0) {
            $pdo->prepare("INSERT INTO stok_barang (nama_barang, satuan, satuan_eceran, lokasi, qty_grosir, qty_eceran)
                VALUES (?, ?, NULL, ?, ?, 0)
                ON DUPLICATE KEY UPDATE qty_grosir = qty_grosir + VALUES(qty_grosir)")
                ->execute([$nama_barang, $satuan, $lokasi, $delta]);
        }
        return;
    }

    $isi = $mapping['isi_per_satuan'];
    $satuanEceran = $mapping['satuan_eceran'];
    $satuanGrosir = $mapping['satuan_grosir'];

    $satuanInputNorm = strtolower(trim($satuan));
    $modeEceran = ($satuanInputNorm === strtolower(trim($satuanEceran))
        && $satuanInputNorm !== strtolower(trim($satuanGrosir)));

    // Konversi delta ke satuan ECERAN (unit terkecil) dulu
    $deltaEceran = $modeEceran ? (float)$delta : (float)$delta * $isi;

    if ($deltaEceran != 0) {
        $pdo->prepare("INSERT INTO stok_barang (nama_barang, satuan, satuan_eceran, lokasi, qty_grosir, qty_eceran)
            VALUES (?, ?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE
                qty_eceran = qty_eceran + VALUES(qty_eceran),
                satuan_eceran = VALUES(satuan_eceran)")
            ->execute([$nama_barang, $satuanGrosir, $satuanEceran, $lokasi, $deltaEceran]);
    } else {
        $pdo->prepare("UPDATE stok_barang SET satuan_eceran = ? WHERE nama_barang = ? AND lokasi = ?")
            ->execute([$satuanEceran, $nama_barang, $lokasi]);
    }

    stok_syncGrosir($pdo, $nama_barang, $lokasi);
}

/**
 * Tambah stok dalam satuan ECERAN langsung (mis. retur penjualan eceran).
 * Ditambahkan langsung ke qty_eceran (sumber kebenaran), lalu qty_grosir
 * diturunkan ulang.
 */
function stok_tambahEceran(PDO $pdo, $nama_barang, $lokasi, $qty_eceran, $keterangan = '')
{
    $mapping = stok_getMapping($nama_barang);
    if (!$mapping) {
        return ['success' => false, 'message' => 'Konversi satuan eceran belum diatur untuk barang ini'];
    }
    $isi = $mapping['isi_per_satuan'];

    $stmt = $pdo->prepare("SELECT id, qty_eceran FROM stok_barang WHERE nama_barang = ? AND lokasi = ? FOR UPDATE");
    $stmt->execute([$nama_barang, $lokasi]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'message' => 'Stok barang tidak ditemukan di lokasi ini'];
    }

    $totalBaru = round((float)$row['qty_eceran'] + (float)$qty_eceran, 2);
    $qtyGrosirBaru = floor($totalBaru / $isi);

    $pdo->prepare("UPDATE stok_barang SET qty_grosir = ?, qty_eceran = ? WHERE id = ?")
        ->execute([$qtyGrosirBaru, $totalBaru, $row['id']]);

    return ['success' => true, 'qty_grosir' => $qtyGrosirBaru, 'qty_eceran' => $totalBaru];
}

/**
 * Kurangi stok dalam satuan ECERAN langsung (mis. penjualan eceran).
 * Dikurangkan langsung dari qty_eceran (sumber kebenaran), lalu qty_grosir
 * diturunkan ulang. Return ['success' => false, ...] kalau barang tanpa
 * mapping atau stok kurang.
 */
function stok_kurangiEceran(PDO $pdo, $nama_barang, $lokasi, $qty_eceran, $keterangan = '')
{
    $mapping = stok_getMapping($nama_barang);
    if (!$mapping) {
        return ['success' => false, 'message' => 'Konversi satuan eceran belum diatur untuk barang ini'];
    }
    $isi = $mapping['isi_per_satuan'];

    $stmt = $pdo->prepare("SELECT id, qty_eceran FROM stok_barang WHERE nama_barang = ? AND lokasi = ? FOR UPDATE");
    $stmt->execute([$nama_barang, $lokasi]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'message' => 'Stok barang tidak ditemukan di lokasi ini'];
    }

    $totalTersedia = (float)$row['qty_eceran'];
    if ((float)$qty_eceran > $totalTersedia + 0.0001) {
        return ['success' => false, 'message' => "Stok tidak cukup (tersedia {$totalTersedia} {$mapping['satuan_eceran']})"];
    }

    $totalBaru = round($totalTersedia - (float)$qty_eceran, 2);
    $qtyGrosirBaru = floor($totalBaru / $isi);

    $pdo->prepare("UPDATE stok_barang SET qty_grosir = ?, qty_eceran = ? WHERE id = ?")
        ->execute([$qtyGrosirBaru, $totalBaru, $row['id']]);

    return ['success' => true, 'qty_grosir' => $qtyGrosirBaru, 'qty_eceran' => $totalBaru];
}

/**
 * Kurangi stok untuk PENGAMBILAN barang. $satuanInput adalah satuan yang diketik operator
 * (bisa satuan grosir ATAU satuan eceran barang tsb). Fungsi ini otomatis:
 * - Deteksi apakah $satuanInput itu satuan grosir atau eceran (via mapping).
 * - Hitung total stok tersedia dalam satuan eceran (qty_eceran = sumber kebenaran).
 * - Support lokasi 'semua' (potong bertahap dari lokasi dengan total stok terbanyak).
 * - Barang tanpa mapping eceran diperlakukan seperti dulu (qty_eceran selalu 0).
 *
 * Melempar Exception kalau stok (setelah konversi) tidak cukup.
 */
function stok_kurangiUntukPengambilan(PDO $pdo, $nama_barang, $satuanInput, $lokasi, $qtyAmbil)
{
    $mapping = stok_getMapping($nama_barang);
    $isi = $mapping['isi_per_satuan'] ?? 1;
    $modeEceran = false;

    if ($mapping) {
        $satuanEceranNorm = strtolower(trim($mapping['satuan_eceran']));
        $satuanGrosirNorm  = strtolower(trim($mapping['satuan_grosir']));
        $satuanInputNorm   = strtolower(trim($satuanInput));

        if ($satuanInputNorm === $satuanEceranNorm && $satuanInputNorm !== $satuanGrosirNorm) {
            $modeEceran = true;
        }
    }

    // Nyatakan qty yang mau diambil dalam satuan ECERAN (unit terkecil) supaya
    // perbandingan/pengurangan presisi. Kalau tidak ada mapping, 1 unit = 1 unit.
    $qtyAmbilEceran = $modeEceran ? $qtyAmbil : $qtyAmbil * $isi;

    if ($lokasi !== 'semua') {
        $stmt = $pdo->prepare("SELECT id, qty_eceran FROM stok_barang
            WHERE nama_barang = :nama AND lokasi = :lokasi FOR UPDATE");
        $stmt->execute([':nama' => $nama_barang, ':lokasi' => $lokasi]);
        $row = $stmt->fetch();
        $totalTersedia = $row ? (float)$row['qty_eceran'] : 0;

        if ($qtyAmbilEceran > $totalTersedia + 0.0001) {
            $tampil = $modeEceran ? $totalTersedia : round($totalTersedia / $isi, 2);
            $satuanTampil = $modeEceran ? ($mapping['satuan_eceran'] ?? $satuanInput) : $satuanInput;
            throw new Exception("Jumlah pengambilan untuk barang '$nama_barang' ($qtyAmbil $satuanInput) melebihi stok yang tersedia ($tampil $satuanTampil)!");
        }

        $totalSesudah = round($totalTersedia - $qtyAmbilEceran, 2);
        $qtyGrosirBaru = $mapping ? floor($totalSesudah / $isi) : $totalSesudah;

        if ($row) {
            $pdo->prepare("UPDATE stok_barang SET qty_grosir = ?, qty_eceran = ? WHERE id = ?")
                ->execute([$qtyGrosirBaru, $totalSesudah, $row['id']]);
        }
        return;
    }

    // lokasi = 'semua' -> potong bertahap dari semua baris lokasi, total terbanyak dulu
    $stmt = $pdo->prepare("SELECT id, lokasi, qty_eceran FROM stok_barang
        WHERE nama_barang = :nama FOR UPDATE");
    $stmt->execute([':nama' => $nama_barang]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['total_tersedia'] = (float)$r['qty_eceran'];
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['total_tersedia'] <=> $a['total_tersedia']);

    $totalTersedia = array_sum(array_column($rows, 'total_tersedia'));
    if ($qtyAmbilEceran > $totalTersedia + 0.0001) {
        $tampil = $modeEceran ? $totalTersedia : round($totalTersedia / $isi, 2);
        $satuanTampil = $modeEceran ? ($mapping['satuan_eceran'] ?? $satuanInput) : $satuanInput;
        throw new Exception("Jumlah pengambilan untuk barang '$nama_barang' ($qtyAmbil $satuanInput) melebihi stok yang tersedia ($tampil $satuanTampil)!");
    }

    $sisaAmbil = $qtyAmbilEceran;
    foreach ($rows as $r) {
        if ($sisaAmbil <= 0.0001) break;
        $potong = min($sisaAmbil, $r['total_tersedia']);
        if ($potong <= 0) continue;

        $totalBaru = round($r['total_tersedia'] - $potong, 2);
        $qtyGrosirBaru = $mapping ? floor($totalBaru / $isi) : $totalBaru;

        $pdo->prepare("UPDATE stok_barang SET qty_grosir = ?, qty_eceran = ? WHERE id = ?")
            ->execute([$qtyGrosirBaru, $totalBaru, $r['id']]);

        $sisaAmbil -= $potong;
    }
}