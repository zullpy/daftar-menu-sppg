<?php
// ═══════════════════════════════════════════════════════════
// HELPER STOK GUDANG PUSAT (tabel `barang` di db_draft_barang)
// Setiap perubahan stok otomatis dicatat ke tabel `mutasi_stok`
// ═══════════════════════════════════════════════════════════

// Dipakai saat ada pengiriman keluar -> stok berkurang, jenis mutasi 'keluar'
function kurangiStokGudangPusat($pdo_draft, $nama_barang, $qty, $keterangan = '')
{
    $stmt = $pdo_draft->prepare("SELECT id_barang, stok_akhir, satuan FROM barang WHERE nama_barang = ?");
    $stmt->execute([$nama_barang]);
    $row = $stmt->fetch();

    if ($row) {
        $stok_sebelum = (int)$row['stok_akhir'];
        $stok_sesudah = $stok_sebelum - (int)$qty;

        $pdo_draft->prepare("UPDATE barang SET stok_akhir = ? WHERE id_barang = ?")
            ->execute([$stok_sesudah, $row['id_barang']]);

        $pdo_draft->prepare("INSERT INTO mutasi_stok
            (id_barang, jenis, qty, satuan, stok_sebelum, stok_sesudah, keterangan)
            VALUES (?, 'keluar', ?, ?, ?, ?, ?)")
            ->execute([$row['id_barang'], $qty, $row['satuan'] ?? null, $stok_sebelum, $stok_sesudah, $keterangan]);
    }
    // Jika barang tidak ditemukan di tabel barang, tidak dikurangi (biar user input manual dulu)
}

// Dipakai saat pengiriman dihapus/diedit ulang -> stok dikembalikan, jenis mutasi 'masuk'
function kembalikanStokGudangPusat($pdo_draft, $nama_barang, $qty, $keterangan = '')
{
    $stmt = $pdo_draft->prepare("SELECT id_barang, stok_akhir, satuan FROM barang WHERE nama_barang = ?");
    $stmt->execute([$nama_barang]);
    $row = $stmt->fetch();

    if ($row) {
        $stok_sebelum = (int)$row['stok_akhir'];
        $stok_sesudah = $stok_sebelum + (int)$qty;

        $pdo_draft->prepare("UPDATE barang SET stok_akhir = ? WHERE id_barang = ?")
            ->execute([$stok_sesudah, $row['id_barang']]);

        $pdo_draft->prepare("INSERT INTO mutasi_stok
            (id_barang, jenis, qty, satuan, stok_sebelum, stok_sesudah, keterangan)
            VALUES (?, 'masuk', ?, ?, ?, ?, ?)")
            ->execute([$row['id_barang'], $qty, $row['satuan'] ?? null, $stok_sebelum, $stok_sesudah, $keterangan]);
    }
}

// ═══════════════════════════════════════════════════════════
// PENJUALAN ECERAN (mis. bus mart) -> kurangi stok dalam satuan eceran
// Konversi otomatis: total stok dihitung dalam satuan eceran
// (stok_akhir grosir x isi_per_satuan + stok_eceran), dikurangi qty
// jual, lalu dipecah lagi jadi stok_akhir (grosir utuh) + stok_eceran
// (sisa pecahan). Perlu kolom satuan_eceran & isi_per_satuan terisi
// di tabel barang untuk item terkait (lihat alter_konversi_satuan.sql).
// ═══════════════════════════════════════════════════════════
function kurangiStokEceran($pdo_draft, $nama_barang, $qty_eceran, $keterangan = '')
{
    $stmt = $pdo_draft->prepare("SELECT id_barang, stok_akhir, stok_eceran, isi_per_satuan, satuan_eceran
        FROM barang WHERE nama_barang = ?");
    $stmt->execute([$nama_barang]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['success' => false, 'message' => 'Barang tidak ditemukan'];
    }

    $isi_per_satuan = (float)$row['isi_per_satuan'];
    if ($isi_per_satuan <= 0) {
        return ['success' => false, 'message' => 'Konversi satuan eceran belum diatur untuk barang ini'];
    }

    // Totalkan semua stok jadi satuan eceran
    $total_eceran_sebelum = ((float)$row['stok_akhir'] * $isi_per_satuan) + (float)$row['stok_eceran'];
    $total_eceran_sesudah = $total_eceran_sebelum - (float)$qty_eceran;

    if ($total_eceran_sesudah < 0) {
        return ['success' => false, 'message' => 'Stok tidak cukup (tersedia ' . $total_eceran_sebelum . ' ' . $row['satuan_eceran'] . ')'];
    }

    // Pecah lagi jadi grosir utuh + sisa eceran
    $stok_akhir_baru  = floor($total_eceran_sesudah / $isi_per_satuan);
    $stok_eceran_baru = round($total_eceran_sesudah - ($stok_akhir_baru * $isi_per_satuan), 2);

    $pdo_draft->prepare("UPDATE barang SET stok_akhir = ?, stok_eceran = ? WHERE id_barang = ?")
        ->execute([$stok_akhir_baru, $stok_eceran_baru, $row['id_barang']]);

    $pdo_draft->prepare("INSERT INTO mutasi_stok
        (id_barang, jenis, qty, satuan, stok_sebelum, stok_sesudah, keterangan)
        VALUES (?, 'keluar', ?, ?, ?, ?, ?)")
        ->execute([$row['id_barang'], $qty_eceran, $row['satuan_eceran'], $total_eceran_sebelum, $total_eceran_sesudah, $keterangan]);

    return ['success' => true, 'stok_akhir' => $stok_akhir_baru, 'stok_eceran' => $stok_eceran_baru];
}

// Kebalikan dari kurangiStokEceran, dipakai kalau ada retur/pembatalan penjualan eceran
function kembalikanStokEceran($pdo_draft, $nama_barang, $qty_eceran, $keterangan = '')
{
    $stmt = $pdo_draft->prepare("SELECT id_barang, stok_akhir, stok_eceran, isi_per_satuan, satuan_eceran
        FROM barang WHERE nama_barang = ?");
    $stmt->execute([$nama_barang]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['success' => false, 'message' => 'Barang tidak ditemukan'];
    }

    $isi_per_satuan = (float)$row['isi_per_satuan'];
    if ($isi_per_satuan <= 0) {
        return ['success' => false, 'message' => 'Konversi satuan eceran belum diatur untuk barang ini'];
    }

    $total_eceran_sebelum = ((float)$row['stok_akhir'] * $isi_per_satuan) + (float)$row['stok_eceran'];
    $total_eceran_sesudah = $total_eceran_sebelum + (float)$qty_eceran;

    $stok_akhir_baru  = floor($total_eceran_sesudah / $isi_per_satuan);
    $stok_eceran_baru = round($total_eceran_sesudah - ($stok_akhir_baru * $isi_per_satuan), 2);

    $pdo_draft->prepare("UPDATE barang SET stok_akhir = ?, stok_eceran = ? WHERE id_barang = ?")
        ->execute([$stok_akhir_baru, $stok_eceran_baru, $row['id_barang']]);

    $pdo_draft->prepare("INSERT INTO mutasi_stok
        (id_barang, jenis, qty, satuan, stok_sebelum, stok_sesudah, keterangan)
        VALUES (?, 'masuk', ?, ?, ?, ?, ?)")
        ->execute([$row['id_barang'], $qty_eceran, $row['satuan_eceran'], $total_eceran_sebelum, $total_eceran_sesudah, $keterangan]);

    return ['success' => true, 'stok_akhir' => $stok_akhir_baru, 'stok_eceran' => $stok_eceran_baru];
}
