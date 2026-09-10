<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

// ====== CEK SESSION ROLE ======
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'operator'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/koneksi.php';

if (!isset($pdo_draft) || !$pdo_draft) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksi ke db_draft_barang gagal']);
    exit;
}

$action = $_GET['action'] ?? '';

// Regex blacklist untuk item operasional non-pangan (Bensin, Sewa, Insentif, Parkir, dll.)
const PATTERN_EXCLUDED = '/\b(bensin|sewa|insentif|intensif|parkir|sopir|driver|roko|rokok)\b/i';

/**
 * Mendeteksi alasan pengecualian item
 */
function getAlasanPengecualian($nama)
{
    if (preg_match('/bensin/i', $nama)) return 'Biaya BBM / Bensin';
    if (preg_match('/sewa/i', $nama)) return 'Biaya Sewa Kendaraan / Armada';
    if (preg_match('/(insentif|intensif)/i', $nama)) return 'Insentif / Honor Tim Masak';
    if (preg_match('/parkir/i', $nama)) return 'Biaya Parkir';
    if (preg_match('/(sopir|driver)/i', $nama)) return 'Ongkos Driver / Sopir';
    return 'Biaya Operasional Non-Pangan';
}

/**
 * Mendeteksi kategori gizi barang
 */
function detectKategori($nama, $historyMap)
{
    $clean = trim(strtoupper($nama));

    // 1. Exact match riwayat MBG
    if (isset($historyMap[$clean])) {
        return $historyMap[$clean];
    }

    // 2. Partial match riwayat MBG
    foreach ($historyMap as $hNama => $hKat) {
        if ($hNama === '' || strlen($hNama) < 3) continue;
        if (str_contains($clean, $hNama) || str_contains($hNama, $clean)) {
            return $hKat;
        }
    }

    // 3. Fallback Kamus Kata Kunci (Urutan prioritas: Bumbu lebih dulu agar kaldu/dashi/royco sapi tidak salah jadi protein)
    if (preg_match('/\b(bawang|cabe|cabai|lada|merica|ketumbar|kemiri|jahe|kunyit|lengkuas|laos|serai|sereh|salam|daun salam|daun jeruk|daun bawang|bawang daun|seledri|garam|gula|kecap|saus|saos|cuka|minyak|mentega|margarin|masako|royco|dashi|dashiplus|penyedap|mecin|micin|kaldu|santan|terasi|asam|asem|kemangi)\b/i', $clean)) {
        return 'Bumbu';
    }

    if (preg_match('/\b(buah|melon|semangka|jeruk|pisang|apel|pepaya|nanas|mangga|anggur|pir|salak|kelengkeng|alpukat|guava|jambu)\b/i', $clean)) {
        return 'Buah-buahan';
    }

    if (preg_match('/\b(sayur|wortel|bayam|kangkung|sawi|buncis|brokoli|kembang kol|kubis|kol|tomat|terong|oyong|labu|labusiam|timun|mentimun|bonteng|daun singkong|daun pepaya|tauge|toge|kacang panjang|nangka muda|jamur)\b/i', $clean)) {
        return 'Sayuran';
    }

    if (preg_match('/\b(beras|nasi|kentang|singkong|ubi|jagung|mie|bihun|makaroni|pasta|roti|tepung|oat|sereal)\b/i', $clean)) {
        return 'Karbohidrat';
    }

    if (preg_match('/\b(ayam|daging|sapi|kambing|ikan|telur|telor|tahu|tempe|udang|cumi|bakso|baso|sosis|kornet|nugget|fillet|karkas|ati|ampela)\b/i', $clean)) {
        return 'Protein';
    }

    return 'Pelengkap/Tambahan';
}

try {
    switch ($action) {
        // ─── DAFTAR MENU DARI DOMPET HARIAN ────────────────────────────
        case 'list':
            $q = trim($_GET['q'] ?? '');
            $limit = 40;

            $sql = "
                SELECT pb.id, pb.tanggal, pb.nama_menu, pb.jumlah_porsi, pb.total_belanja, pb.status, pb.created_at,
                       COUNT(d.id) AS total_items
                FROM pengajuan_belanja pb
                LEFT JOIN detail_item_belanja d ON d.pengajuan_id = pb.id
                WHERE 1=1
            ";
            $params = [];

            if (!empty($q)) {
                $sql .= " AND (pb.nama_menu LIKE :q OR pb.tanggal LIKE :q)";
                $params[':q'] = '%' . $q . '%';
            }

            $sql .= " GROUP BY pb.id ORDER BY pb.tanggal DESC, pb.id DESC LIMIT " . (int)$limit;

            $stmt = $pdo_draft->prepare($sql);
            $stmt->execute($params);
            $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data'    => $menus
            ]);
            exit;

        // ─── DETAIL MENU & BARANG HASIL FILTER ──────────────────────────
        case 'detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID menu tidak valid']);
                exit;
            }

            // Ambil data pengajuan belanja (header)
            $stmtHeader = $pdo_draft->prepare("SELECT * FROM pengajuan_belanja WHERE id = ?");
            $stmtHeader->execute([$id]);
            $header = $stmtHeader->fetch(PDO::FETCH_ASSOC);

            if (!$header) {
                echo json_encode(['success' => false, 'message' => 'Data pengajuan belanja tidak ditemukan']);
                exit;
            }

            // Ambil riwayat kategori dari belanja_detail db_mbg untuk referensi Lapis 1
            $histRes = $pdo->query("SELECT TRIM(UPPER(item_barang)) as nama, kategori FROM belanja_detail GROUP BY TRIM(UPPER(item_barang))");
            $historyMap = [];
            while ($row = $histRes->fetch(PDO::FETCH_ASSOC)) {
                $historyMap[$row['nama']] = $row['kategori'];
            }

            // Ambil rincian barang dari dompet harian
            $stmtDetail = $pdo_draft->prepare("SELECT * FROM detail_item_belanja WHERE pengajuan_id = ? ORDER BY COALESCE(NULLIF(urutan, 0), id) ASC, id ASC");
            $stmtDetail->execute([$id]);
            $rawItems = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

            $foodItems = [];
            $excludedItems = [];

            foreach ($rawItems as $item) {
                $nama = trim($item['nama_barang']);
                if (empty($nama)) continue;

                // Cek pengecualian (bensin, sewa, insentif, intensif, dll.)
                if (preg_match(PATTERN_EXCLUDED, $nama)) {
                    $excludedItems[] = [
                        'nama_barang' => $nama,
                        'qty'         => (float)$item['qty'],
                        'satuan'      => trim($item['satuan'] ?? ''),
                        'harga'       => (float)($item['harga'] ?? 0),
                        'alasan'      => getAlasanPengecualian($nama),
                    ];
                } else {
                    // Item bahan pangan: tentukan kategori gizi otomatis
                    $kategori = detectKategori($nama, $historyMap);

                    $foodItems[] = [
                        'item_barang'  => $nama,
                        'qty'          => (float)$item['qty'],
                        'satuan'       => trim($item['satuan'] ?? 'pcs'),
                        'harga_satuan' => (float)($item['harga'] ?? 0),
                        'kategori'     => $kategori,
                    ];
                }
            }

            echo json_encode([
                'success'  => true,
                'menu'     => [
                    'id'          => (int)$header['id'],
                    'tanggal'     => $header['tanggal'],
                    'nama_menu'   => $header['nama_menu'],
                    'porsi'       => (int)$header['jumlah_porsi'],
                    'status'      => $header['status'],
                ],
                'items'    => $foodItems,
                'excluded' => $excludedItems,
            ]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak dikenali']);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}
