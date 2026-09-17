<?php
/**
 * Script Migrasi Foto Lokal ke Cloudinary
 * Aplikasi MBG - Koperasi
 * 
 * Fitur:
 * - Dukungan CLI Terminal & Web Browser UI
 * - Menggunakan ID Cursor (tidak stuck pada file missing/lama)
 * - Menangani Menu, Receiving, Kemasan, Nota, Faktur
 * - Otomatis convert format WebP & kompresi optimal
 * - Opsi hapus file lokal setelah sukses di-upload
 */

@ini_set('memory_limit', '512M');
@set_time_limit(0);

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/database/koneksi.php';
require_once __DIR__ . '/database/cloudinary_helper.php';

if (!cloudinary_is_configured()) {
    $msg = "ERROR: Cloudinary belum terkonfigurasi di database/cloudinary.php. Pastikan CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, dan CLOUDINARY_API_SECRET telah terisi.";
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    } else {
        die("<div style='font-family:sans-serif;padding:24px;color:#b91c1c;background:#fee2e2;border-radius:8px;max-width:600px;margin:40px auto;'><h3>Konfigurasi Belum Lengkap</h3><p>{$msg}</p></div>");
    }
}

// Konfigurasi target migrasi
$MIGRATION_TARGETS = [
    'menu' => [
        'label'        => 'Foto Menu',
        'table'        => 'foto_menu_multiple',
        'pk'           => 'id',
        'col'          => 'foto',
        'local_dir'    => __DIR__ . '/uploads/menu',
        'cloud_folder' => 'menu',
    ],
    'menu_single' => [
        'label'        => 'Foto Menu (Tabel Belanja)',
        'table'        => 'belanja',
        'pk'           => 'id_belanja',
        'col'          => 'foto_menu',
        'local_dir'    => __DIR__ . '/uploads/menu',
        'cloud_folder' => 'menu',
    ],
    'foto' => [
        'label'        => 'Foto Receiving / Penerimaan Item',
        'table'        => 'foto_receiving',
        'pk'           => 'id_receiving',
        'col'          => 'foto',
        'local_dir'    => __DIR__ . '/uploads/foto',
        'cloud_folder' => 'receiving',
    ],
    'kemasan' => [
        'label'        => 'Foto Kemasan Penerimaan',
        'table'        => 'detail_penerimaan',
        'pk'           => 'id',
        'col'          => 'foto_kemasan',
        'local_dir'    => __DIR__ . '/uploads/foto-perkemasan',
        'cloud_folder' => 'kemasan',
    ],
    'nota' => [
        'label'        => 'Foto Lampiran Nota',
        'table'        => 'lampiran_nota',
        'pk'           => 'id_nota',
        'col'          => 'file_nota',
        'local_dir'    => __DIR__ . '/uploads/nota',
        'cloud_folder' => 'nota',
    ],
    'faktur' => [
        'label'        => 'Faktur TTD',
        'table'        => 'faktur_ttd',
        'pk'           => 'id_faktur',
        'col'          => 'file_faktur',
        'local_dir'    => __DIR__ . '/uploads/faktur',
        'cloud_folder' => 'faktur',
    ],
    'addcost_nota' => [
        'label'        => 'Foto Nota Addcost',
        'table'        => 'pembelian_addcost_detail',
        'pk'           => 'id',
        'col'          => 'foto_nota',
        'local_dir'    => __DIR__ . '/addcost/uploads/addcost_nota',
        'cloud_folder' => 'addcost-nota',
        'is_json'      => true,
    ],
    'addcost_receiving' => [
        'label'        => 'Foto Receiving Addcost',
        'table'        => 'pembelian_addcost_detail',
        'pk'           => 'id',
        'col'          => 'foto_receiving',
        'local_dir'    => __DIR__ . '/addcost/uploads/addcost_receiving',
        'cloud_folder' => 'addcost-receiving',
        'is_json'      => true,
    ],
];

/**
 * Hitung statistik migrasi
 */
function getMigrationStats(PDO $pdo, array $targets): array
{
    $stats = [];
    $totalLocal = 0;
    $totalCloud = 0;

    foreach ($targets as $key => $t) {
        $table = $t['table'];
        $col   = $t['col'];

        $isJson = $t['is_json'] ?? false;

        try {
            if ($isJson) {
                $stmt = $pdo->query("SELECT `{$col}` FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != ''");
                $countLocal = 0;
                $countCloud = 0;
                while ($val = $stmt->fetchColumn()) {
                    $arr = json_decode($val, true);
                    if (is_array($arr) && !empty($arr)) {
                        $hasLocal = false;
                        foreach ($arr as $f) {
                            if (!str_contains($f, 'res.cloudinary.com')) {
                                $hasLocal = true;
                                break;
                            }
                        }
                        if ($hasLocal) $countLocal++; else $countCloud++;
                    }
                }
            } else {
                $stmtLocal = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != '' AND `{$col}` NOT LIKE '%res.cloudinary.com%'");
                $countLocal = (int)$stmtLocal->fetchColumn();

                $stmtCloud = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` LIKE '%res.cloudinary.com%'");
                $countCloud = (int)$stmtCloud->fetchColumn();
            }

            $stats[$key] = [
                'key'         => $key,
                'label'       => $t['label'],
                'local_count' => $countLocal,
                'cloud_count' => $countCloud,
                'total_count' => $countLocal + $countCloud,
            ];
            $totalLocal += $countLocal;
            $totalCloud += $countCloud;
        } catch (Exception $e) {
            $stats[$key] = [
                'key'         => $key,
                'label'       => $t['label'],
                'error'       => $e->getMessage(),
                'local_count' => 0,
                'cloud_count' => 0,
                'total_count' => 0,
            ];
        }
    }

    return [
        'targets'     => $stats,
        'total_local' => $totalLocal,
        'total_cloud' => $totalCloud,
        'grand_total' => $totalLocal + $totalCloud,
    ];
}

/**
 * Proses migrasi satu record item
 */
function migrateSingleItem(PDO $pdo, array $targetConfig, array $row, bool $deleteLocal = false, bool $dryRun = false): array
{
    $table       = $targetConfig['table'];
    $pkCol       = $targetConfig['pk'];
    $col         = $targetConfig['col'];
    $localDir    = $targetConfig['local_dir'];
    $cloudFolder = $targetConfig['cloud_folder'];
    $isJson      = $targetConfig['is_json'] ?? false;

    $id       = (int)$row[$pkCol];
    $rawVal   = trim($row[$col] ?? '');

    if (empty($rawVal)) {
        return ['success' => false, 'status' => 'empty', 'id' => $id, 'filename' => '', 'message' => 'Nilai kolom kosong di database'];
    }

    if ($isJson) {
        $arr = json_decode($rawVal, true);
        if (!is_array($arr) || empty($arr)) {
            return ['success' => false, 'status' => 'empty', 'id' => $id, 'filename' => '', 'message' => 'Array JSON kosong'];
        }

        $newArr = [];
        $uploadedCount = 0;
        $missingCount = 0;

        foreach ($arr as $itemPhoto) {
            $itemPhoto = trim($itemPhoto);
            if (empty($itemPhoto)) continue;

            if (str_contains($itemPhoto, 'res.cloudinary.com')) {
                $newArr[] = $itemPhoto;
                continue;
            }

            $cleanPhoto = basename(parse_url($itemPhoto, PHP_URL_PATH) ?? $itemPhoto);
            $localPath = rtrim($localDir, '/') . '/' . ltrim($cleanPhoto, '/');
            if (!file_exists($localPath)) {
                $altPath = __DIR__ . '/addcost/uploads/' . basename($localDir) . '/' . $cleanPhoto;
                if (file_exists($altPath)) $localPath = $altPath;
            }

            if (!file_exists($localPath)) {
                $newArr[] = $itemPhoto; // Tetap simpan nilai lama
                $missingCount++;
                continue;
            }

            if ($dryRun) {
                $newArr[] = '[SIMULASI_CLOUDINARY]';
                $uploadedCount++;
                continue;
            }

            try {
                $uploadResult = cloudinary_upload($localPath, $cloudFolder);
                if ($uploadResult['success'] && !empty($uploadResult['url'])) {
                    $newArr[] = $uploadResult['url'];
                    $uploadedCount++;
                    if ($deleteLocal) @unlink($localPath);
                } else {
                    $newArr[] = $itemPhoto;
                }
            } catch (Exception $e) {
                $newArr[] = $itemPhoto;
            }
        }

        if ($uploadedCount > 0 && !$dryRun) {
            $updateStmt = $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$pkCol}` = ?");
            $updateStmt->execute([json_encode($newArr), $id]);
        }

        if ($uploadedCount > 0) {
            return ['success' => true, 'status' => $dryRun ? 'dry_run' : 'uploaded', 'id' => $id, 'filename' => "JSON ({$uploadedCount} file)", 'message' => "Sukses diunggah ({$uploadedCount} file)"];
        } elseif ($missingCount > 0) {
            return ['success' => false, 'status' => 'missing', 'id' => $id, 'filename' => $rawVal, 'message' => 'File tidak ada di disk'];
        } else {
            return ['success' => true, 'status' => 'already_cloud', 'id' => $id, 'filename' => $rawVal, 'message' => 'Semua foto sudah di Cloudinary'];
        }
    }

    $filename = $rawVal;

    if (str_contains($filename, 'res.cloudinary.com')) {
        return ['success' => true, 'status' => 'already_cloud', 'id' => $id, 'filename' => $filename, 'message' => 'Sudah berupa URL Cloudinary'];
    }

    // Jika bernilai URL hosting lama (http://... atau https://...), ambil nama filenya
    $cleanFilename = basename(parse_url($filename, PHP_URL_PATH) ?? $filename);

    // Resolusi path file fisik lokal
    $localPath = rtrim($localDir, '/') . '/' . ltrim($cleanFilename, '/');
    if (!file_exists($localPath)) {
        $altPath = __DIR__ . '/uploads/' . basename($localDir) . '/' . $cleanFilename;
        if (file_exists($altPath)) {
            $localPath = $altPath;
        } else {
            return [
                'success'  => false,
                'status'   => 'missing',
                'id'       => $id,
                'filename' => $cleanFilename,
                'message'  => "File tidak ditemukan di disk: {$cleanFilename}"
            ];
        }
    }

    if ($dryRun) {
        return [
            'success'  => true,
            'status'   => 'dry_run',
            'id'       => $id,
            'filename' => $filename,
            'url'      => '[SIMULASI]',
            'message'  => 'Dry-run: File siap diunggah'
        ];
    }

    try {
        $uploadResult = cloudinary_upload($localPath, $cloudFolder);

        if (!$uploadResult['success'] || empty($uploadResult['url'])) {
            throw new Exception("Gagal mengunggah ke Cloudinary");
        }

        $cloudUrl = $uploadResult['url'];

        // Update record di database
        $updateStmt = $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$pkCol}` = ?");
        $updateStmt->execute([$cloudUrl, $id]);

        // Hapus file lokal jika diminta
        if ($deleteLocal && file_exists($localPath)) {
            @unlink($localPath);
        }

        return [
            'success'  => true,
            'status'   => 'uploaded',
            'id'       => $id,
            'filename' => $filename,
            'url'      => $cloudUrl,
            'message'  => 'Sukses terunggah ke Cloudinary & DB terupdate'
        ];
    } catch (Exception $e) {
        return [
            'success'  => false,
            'status'   => 'error',
            'id'       => $id,
            'filename' => $filename,
            'message'  => $e->getMessage()
        ];
    }
}

// =========================================================================
// MODE 1: WEB AJAX BATCH ACTION
// =========================================================================
if (!$isCli && isset($_GET['action'])) {
    session_start();
    $role = $_SESSION['role'] ?? '';
    if ($role !== 'admin') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_stats') {
        echo json_encode(getMigrationStats($pdo, $MIGRATION_TARGETS));
        exit;
    }

    if ($action === 'process_batch') {
        $targetKey   = $_POST['target_key'] ?? 'menu';
        $lastId      = (int)($_POST['last_id'] ?? 0);
        $batchSize   = max(1, min(20, (int)($_POST['batch_size'] ?? 5)));
        $deleteLocal = !empty($_POST['delete_local']) && $_POST['delete_local'] === '1';

        if (!isset($MIGRATION_TARGETS[$targetKey])) {
            echo json_encode(['success' => false, 'message' => 'Target migrasi tidak valid']);
            exit;
        }

        $targetConfig = $MIGRATION_TARGETS[$targetKey];
        $table        = $targetConfig['table'];
        $pkCol        = $targetConfig['pk'];
        $col          = $targetConfig['col'];

        // Ambil baris berikutnya berdasarkan cursor lastId
        $stmt = $pdo->prepare("SELECT `{$pkCol}`, `{$col}` FROM `{$table}` WHERE `{$pkCol}` > ? AND `{$col}` IS NOT NULL AND `{$col}` != '' AND `{$col}` NOT LIKE '%res.cloudinary.com%' ORDER BY `{$pkCol}` ASC LIMIT {$batchSize}");
        $stmt->execute([$lastId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processedItems = [];
        $nextLastId = $lastId;

        foreach ($rows as $row) {
            $nextLastId = (int)$row[$pkCol];
            $res = migrateSingleItem($pdo, $targetConfig, $row, $deleteLocal, false);
            $res['category'] = $targetConfig['label'];
            $processedItems[] = $res;
        }

        $isCategoryFinished = (count($rows) === 0);
        $latestStats = getMigrationStats($pdo, $MIGRATION_TARGETS);

        echo json_encode([
            'success'             => true,
            'target_key'          => $targetKey,
            'next_last_id'        => $nextLastId,
            'count'               => count($processedItems),
            'items'               => $processedItems,
            'category_finished'   => $isCategoryFinished,
            'stats'               => $latestStats,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action tidak dikenali']);
    exit;
}

// =========================================================================
// MODE 2: CLI RUNNER
// =========================================================================
if ($isCli) {
    echo "\n=========================================================\n";
    echo "  MIGRASI FOTO LOKAL KE CLOUDINARY - APLIKASI MBG\n";
    echo "=========================================================\n";

    $options = getopt('', ['dry-run', 'delete-local', 'limit::', 'type::']);
    $dryRun      = isset($options['dry-run']);
    $deleteLocal = isset($options['delete-local']);
    $limit       = isset($options['limit']) ? (int)$options['limit'] : 0;
    $filterType  = $options['type'] ?? 'all';

    if ($dryRun) {
        echo ">>> MODE: DRY RUN (Simulasi tanpa upload Cloudinary)\n";
    }
    if ($deleteLocal) {
        echo ">>> PERINGATAN: File lokal akan dihapus setelah sukses di-upload.\n";
    }

    $initialStats = getMigrationStats($pdo, $MIGRATION_TARGETS);
    echo "\nStatus Data Sebelum Migrasi:\n";
    foreach ($initialStats['targets'] as $st) {
        echo sprintf("  - %-32s : %4d lokal | %4d Cloudinary\n", $st['label'], $st['local_count'], $st['cloud_count']);
    }
    echo sprintf("  TOTAL FOTO LOKAL PERLU DI-MIGRASI : %d\n\n", $initialStats['total_local']);

    if ($initialStats['total_local'] === 0) {
        echo "Semua foto sudah termigrasi ke Cloudinary! Selesai.\n\n";
        exit(0);
    }

    $targetsToProcess = ($filterType === 'all' || !isset($MIGRATION_TARGETS[$filterType]))
        ? $MIGRATION_TARGETS
        : [$filterType => $MIGRATION_TARGETS[$filterType]];

    $totalProcessed = 0;
    $totalSuccess   = 0;
    $totalMissing   = 0;
    $totalFailed    = 0;

    foreach ($targetsToProcess as $tKey => $targetConfig) {
        $table = $targetConfig['table'];
        $pkCol = $targetConfig['pk'];
        $col   = $targetConfig['col'];

        $lastId = 0;
        $categoryProcessed = 0;

        echo ">>> Memproses: {$targetConfig['label']}...\n";

        while (true) {
            $batchLimit = ($limit > 0) ? min(50, $limit - $totalProcessed) : 50;
            if ($batchLimit <= 0) break;

            $stmt = $pdo->prepare("SELECT `{$pkCol}`, `{$col}` FROM `{$table}` WHERE `{$pkCol}` > ? AND `{$col}` IS NOT NULL AND `{$col}` != '' AND `{$col}` NOT LIKE '%res.cloudinary.com%' ORDER BY `{$pkCol}` ASC LIMIT {$batchLimit}");
            $stmt->execute([$lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row[$pkCol];
                $totalProcessed++;
                $categoryProcessed++;

                echo sprintf("  [%3d] ID %-5d %-40s ", $categoryProcessed, $lastId, substr($row[$col], 0, 40));

                $res = migrateSingleItem($pdo, $targetConfig, $row, $deleteLocal, $dryRun);

                if ($res['status'] === 'uploaded' || $res['status'] === 'dry_run') {
                    $totalSuccess++;
                    echo "[OK]\n";
                } elseif ($res['status'] === 'missing') {
                    $totalMissing++;
                    echo "[SKIP: File tidak ada di disk]\n";
                } else {
                    $totalFailed++;
                    echo "[FAILED: {$res['message']}]\n";
                }

                if ($limit > 0 && $totalProcessed >= $limit) {
                    echo "\nBatas limit ({$limit} item) tercapai.\n";
                    break 2;
                }
            }
        }
        echo "\n";
    }

    echo "=========================================================\n";
    echo "  RINGKASAN HASIL MIGRASI\n";
    echo sprintf("  Total Diproses   : %d\n", $totalProcessed);
    echo sprintf("  Sukses Diunggah  : %d\n", $totalSuccess);
    echo sprintf("  Dilewati Missing : %d\n", $totalMissing);
    echo sprintf("  Gagal / Error    : %d\n", $totalFailed);
    echo "=========================================================\n\n";

    exit(0);
}

// =========================================================================
// MODE 3: WEB UI BROWSER (KHUSUS ADMIN)
// =========================================================================
session_start();
$role = $_SESSION['role'] ?? '';

if ($role !== 'admin') {
    die("
    <!DOCTYPE html>
    <html lang='id'>
    <head><meta charset='UTF-8'><title>Akses Ditolak</title><link rel='stylesheet' href='style.css'></head>
    <body style='display:flex;align-items:center;justify-content:center;height:100vh;background:#f8fafc;font-family:sans-serif;'>
        <div style='background:white;padding:30px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.08);max-width:400px;text-align:center;'>
            <h2 style='color:#ef4444;margin-top:0;'>Akses Ditolak</h2>
            <p style='color:#64748b;'>Halaman migrasi ini hanya dapat diakses oleh akun <strong>Admin</strong>.</p>
            <a href='index.php' class='btn btn-primary' style='display:inline-block;margin-top:15px;text-decoration:none;'>Kembali ke Login</a>
        </div>
    </body>
    </html>
    ");
}

$stats = getMigrationStats($pdo, $MIGRATION_TARGETS);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrasi Foto Lokal ke Cloudinary - MBG</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .migrasi-container {
            max-width: 920px;
            margin: 30px auto;
            padding: 0 16px;
            font-family: inherit;
        }
        .migrasi-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.05);
            padding: 26px;
            margin-bottom: 24px;
        }
        .migrasi-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 18px;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
        }
        .stat-box h4 {
            margin: 0 0 8px 0;
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-box .count {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
        }
        .badge-local {
            display: inline-block;
            background: #fee2e2;
            color: #991b1b;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-cloud {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .progress-bar-container {
            background: #e2e8f0;
            border-radius: 999px;
            height: 14px;
            overflow: hidden;
            margin: 16px 0;
        }
        .progress-bar-fill {
            background: linear-gradient(90deg, #3b82f6, #10b981);
            height: 100%;
            width: <?= ($stats['grand_total'] > 0) ? round(($stats['total_cloud'] / $stats['grand_total']) * 100) : 100 ?>%;
            transition: width 0.3s ease;
        }
        .log-box {
            background: #0f172a;
            color: #f8fafc;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
            padding: 16px;
            border-radius: 8px;
            height: 280px;
            overflow-y: auto;
            white-space: pre-wrap;
            line-height: 1.6;
        }
        .log-entry-ok { color: #4ade80; }
        .log-entry-err { color: #f87171; }
        .log-entry-info { color: #60a5fa; }
        .log-entry-skip { color: #facc15; }
    </style>
</head>
<body style="background: #f1f5f9;">

<div class="migrasi-container">
    <div class="migrasi-card">
        <div class="migrasi-header">
            <div>
                <h2 style="margin: 0 0 6px 0; color: #0f172a; font-size: 22px;">Migrasi Foto Lokal ke Cloudinary</h2>
                <p style="margin: 0; color: #64748b; font-size: 14px;">Otomatis unggah semua foto lokal yang ada ke Cloudinary dan perbarui database.</p>
            </div>
            <div>
                <a href="menu.php" class="btn btn-secondary btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    <span>Kembali ke Menu</span>
                </a>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-box">
                <h4>Total File Lokal di DB</h4>
                <div class="count" id="totalLocalCount"><?= number_format($stats['total_local']) ?></div>
                <div style="margin-top: 6px;"><span class="badge-local">Perlu Migrasi</span></div>
            </div>
            <div class="stat-box">
                <h4>Sudah di Cloudinary</h4>
                <div class="count" id="totalCloudCount"><?= number_format($stats['total_cloud']) ?></div>
                <div style="margin-top: 6px;"><span class="badge-cloud">Tersimpan Aman</span></div>
            </div>
            <div class="stat-box">
                <h4>Target Cloudinary</h4>
                <div class="count" style="font-size: 20px; color: #3b82f6;"><?= htmlspecialchars(CLOUDINARY_CLOUD_NAME) ?></div>
                <div style="margin-top: 6px; font-size: 12px; color: #64748b;">Base: <code>aplikasi-permenceker</code></div>
            </div>
        </div>

        <h4 style="margin: 0 0 10px 0; color: #334155; font-size: 15px;">Detail Status Per Modul</h4>
        <div style="overflow-x: auto; margin-bottom: 22px;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 10px;">Kategori</th>
                        <th style="padding: 10px; text-align: center;">Tersimpan Lokal</th>
                        <th style="padding: 10px; text-align: center;">Di Cloudinary</th>
                        <th style="padding: 10px; text-align: center;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['targets'] as $t): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px; font-weight: 600;"><?= htmlspecialchars($t['label']) ?></td>
                        <td style="padding: 10px; text-align: center;">
                            <span class="badge-local" id="badge_local_<?= $t['key'] ?>"><?= number_format($t['local_count']) ?></span>
                        </td>
                        <td style="padding: 10px; text-align: center;">
                            <span class="badge-cloud" id="badge_cloud_<?= $t['key'] ?>"><?= number_format($t['cloud_count']) ?></span>
                        </td>
                        <td style="padding: 10px; text-align: center; color: #64748b;"><?= number_format($t['total_count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-bottom: 22px;">
            <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; color: #475569;">
                <span>Persentase di Cloudinary</span>
                <span id="progressPercent">
                    <?= ($stats['grand_total'] > 0) ? round(($stats['total_cloud'] / $stats['grand_total']) * 100) : 100 ?>%
                </span>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar-fill" id="progressBar"></div>
            </div>
        </div>

        <!-- Pilihan Kontrol -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-bottom: 22px;">
            <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center;">
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 5px;">Pilih Kategori:</label>
                    <select id="selectTarget" style="padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 500;">
                        <option value="all">Semua Kategori (Otomatis Berurutan)</option>
                        <option value="menu">Foto Menu Saja</option>
                        <option value="foto">Foto Receiving Saja</option>
                        <option value="kemasan">Foto Kemasan Saja</option>
                        <option value="nota">Foto Nota Saja</option>
                        <option value="faktur">Faktur TTD Saja</option>
                        <option value="addcost_nota">Nota Addcost Saja</option>
                        <option value="addcost_receiving">Receiving Addcost Saja</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 5px;">Batch per Request:</label>
                    <select id="selectBatchSize" style="padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 500;">
                        <option value="3">3 Foto per batch (Koneksi santai)</option>
                        <option value="5" selected>5 Foto per batch (Standar)</option>
                        <option value="10">10 Foto per batch (Lebih cepat)</option>
                    </select>
                </div>
                <div>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #334155; margin-top: 22px; cursor: pointer;">
                        <input type="checkbox" id="chkDeleteLocal" value="1">
                        <span>Hapus file fisik lokal setelah sukses di-upload</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Tombol Aksi -->
        <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 20px;">
            <button type="button" id="btnStartMigration" class="btn btn-primary" onclick="startMigration()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="16 16 12 12 8 16"></polyline>
                    <line x1="12" y1="12" x2="12" y2="21"></line>
                    <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>
                </svg>
                <span>Mulai Migrasi ke Cloudinary</span>
            </button>
            <button type="button" id="btnStopMigration" class="btn btn-danger" style="display: none;" onclick="stopMigration()">
                <span>Hentikan Proses</span>
            </button>
            <span id="migrationStatusLabel" style="font-size: 13px; font-weight: 600; color: #64748b;"></span>
        </div>

        <!-- Log Eksekusi -->
        <h4 style="margin: 0 0 8px 0; color: #334155; font-size: 14px;">Log Eksekusi Real-Time</h4>
        <div class="log-box" id="logBox">[Siap] Tekan tombol "Mulai Migrasi ke Cloudinary" untuk menjalankan proses.</div>
    </div>
</div>

<script>
let isRunning = false;
let shouldStop = false;

const ALL_KEYS = ['menu', 'menu_single', 'foto', 'kemasan', 'nota', 'faktur', 'addcost_nota', 'addcost_receiving'];

function addLog(msg, type = 'info') {
    const logBox = document.getElementById('logBox');
    const time = new Date().toLocaleTimeString();
    let spanClass = 'log-entry-info';
    if (type === 'ok') spanClass = 'log-entry-ok';
    if (type === 'err') spanClass = 'log-entry-err';
    if (type === 'skip') spanClass = 'log-entry-skip';

    const line = `<span class="${spanClass}">[${time}] ${msg}</span>\n`;
    logBox.innerHTML += line;
    logBox.scrollTop = logBox.scrollHeight;
}

function updateUIStats(stats) {
    document.getElementById('totalLocalCount').textContent = Number(stats.total_local).toLocaleString();
    document.getElementById('totalCloudCount').textContent = Number(stats.total_cloud).toLocaleString();

    for (const key in stats.targets) {
        const item = stats.targets[key];
        const badgeLocal = document.getElementById('badge_local_' + key);
        const badgeCloud = document.getElementById('badge_cloud_' + key);
        if (badgeLocal) badgeLocal.textContent = Number(item.local_count).toLocaleString();
        if (badgeCloud) badgeCloud.textContent = Number(item.cloud_count).toLocaleString();
    }

    const percent = stats.grand_total > 0 ? Math.round((stats.total_cloud / stats.grand_total) * 100) : 100;
    document.getElementById('progressBar').style.width = percent + '%';
    document.getElementById('progressPercent').textContent = percent + '%';
}

async function startMigration() {
    if (isRunning) return;

    isRunning = true;
    shouldStop = false;

    document.getElementById('btnStartMigration').style.display = 'none';
    document.getElementById('btnStopMigration').style.display = 'inline-flex';
    document.getElementById('migrationStatusLabel').textContent = 'Sedang berjalan...';

    const selectedTarget = document.getElementById('selectTarget').value;
    const batchSize      = document.getElementById('selectBatchSize').value;
    const deleteLocal    = document.getElementById('chkDeleteLocal').checked ? '1' : '0';

    const queue = (selectedTarget === 'all') ? [...ALL_KEYS] : [selectedTarget];

    addLog(`Memulai proses migrasi (${queue.length} modul antrean)...`, 'info');

    let totalUploaded = 0;
    let totalSkipped  = 0;
    let totalFailed   = 0;

    for (const targetKey of queue) {
        if (shouldStop) break;

        let lastId = 0;
        addLog(`>>> Memproses antrean kategori: ${targetKey}`, 'info');

        while (isRunning && !shouldStop) {
            try {
                const formData = new FormData();
                formData.append('target_key', targetKey);
                formData.append('last_id', lastId);
                formData.append('batch_size', batchSize);
                formData.append('delete_local', deleteLocal);

                const res = await fetch('migrate_to_cloudinary.php?action=process_batch', {
                    method: 'POST',
                    body: formData
                });

                if (!res.ok) {
                    throw new Error('HTTP Status ' + res.status);
                }

                const json = await res.json();

                if (!json.success) {
                    addLog('Error: ' + (json.message || 'Gagal memproses batch'), 'err');
                    totalFailed++;
                    break;
                }

                if (json.items && json.items.length > 0) {
                    for (const it of json.items) {
                        if (it.status === 'uploaded') {
                            totalUploaded++;
                            addLog(`✓ [${it.category}] ID ${it.id} (${it.filename}) -> Sukses Cloudinary`, 'ok');
                        } else if (it.status === 'missing') {
                            totalSkipped++;
                            addLog(`⚠ [${it.category}] ID ${it.id} (${it.filename}) -> Dilewati: file tidak ada di disk`, 'skip');
                        } else {
                            totalFailed++;
                            addLog(`✗ [${it.category}] ID ${it.id} (${it.filename}) -> ${it.message}`, 'err');
                        }
                    }
                }

                lastId = json.next_last_id;

                if (json.stats) {
                    updateUIStats(json.stats);
                }

                if (json.category_finished) {
                    addLog(`Kategori [${targetKey}] selesai dipindai.`, 'info');
                    break;
                }

                // Jeda 350ms antar batch
                await new Promise(r => setTimeout(r, 350));

            } catch (err) {
                addLog('Kesalahan koneksi / batch error: ' + err.message, 'err');
                totalFailed++;
                break;
            }
        }
    }

    isRunning = false;
    document.getElementById('btnStartMigration').style.display = 'inline-flex';
    document.getElementById('btnStopMigration').style.display = 'none';

    if (shouldStop) {
        document.getElementById('migrationStatusLabel').textContent = 'Dihentikan oleh pengguna';
        addLog('Proses dihentikan.', 'info');
    } else {
        document.getElementById('migrationStatusLabel').textContent = 'Migrasi selesai!';
        addLog(`=== SELESAI === Berhasil diunggah: ${totalUploaded} | Dilewati (missing): ${totalSkipped} | Gagal: ${totalFailed}`, 'ok');
    }
}

function stopMigration() {
    shouldStop = true;
    document.getElementById('migrationStatusLabel').textContent = 'Menghentikan proses...';
    addLog('Mengirim sinyal berhenti, menunggu batch aktif selesai...', 'info');
}
</script>

</body>
</html>
