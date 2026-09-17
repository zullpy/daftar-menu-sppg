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
                            if (!str_contains($f, 'res.cloudinary.com') || str_contains($f, 'aplikasi-permenceker/aplikasi-permenceker') || str_ends_with($f, '/image.webp')) {
                                $hasLocal = true;
                                break;
                            }
                        }
                        if ($hasLocal) $countLocal++; else $countCloud++;
                    }
                }
            } else {
                $stmtLocal = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != '' AND (`{$col}` NOT LIKE '%res.cloudinary.com%' OR `{$col}` LIKE '%aplikasi-permenceker/aplikasi-permenceker%' OR `{$col}` LIKE '%image.webp%')");
                $countLocal = (int)$stmtLocal->fetchColumn();

                $stmtCloud = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` LIKE '%res.cloudinary.com%' AND `{$col}` NOT LIKE '%aplikasi-permenceker/aplikasi-permenceker%' AND `{$col}` NOT LIKE '%image.webp%'");
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
    $isBrokenCloud = str_contains($filename, 'aplikasi-permenceker/aplikasi-permenceker') || str_ends_with($filename, '/image.webp');

    if (str_contains($filename, 'res.cloudinary.com') && !$isBrokenCloud) {
        return ['success' => true, 'status' => 'already_cloud', 'id' => $id, 'filename' => $filename, 'message' => 'Sudah berupa URL Cloudinary yang valid'];
    }

    // Jika bernilai URL hosting lama atau broken Cloudinary, ambil nama filenya
    $cleanFilename = basename(parse_url($filename, PHP_URL_PATH) ?? $filename);

    // Resolusi path file fisik lokal
    $localPath = rtrim($localDir, '/') . '/' . ltrim($cleanFilename, '/');
    if (!file_exists($localPath)) {
        $altPath = __DIR__ . '/uploads/' . basename($localDir) . '/' . $cleanFilename;
        if (file_exists($altPath)) {
            $localPath = $altPath;
        } else {
            // Coba cari fallback file berdasarkan ID di folder lokal (misal menu_*_{$id}_* atau receiving_*_{$id}_*)
            $foundFallback = false;
            $patterns = [
                rtrim($localDir, '/') . "/*_{$id}_*.*",
                rtrim($localDir, '/') . "/*_{$id}.*",
                rtrim($localDir, '/') . "/*_{$id}_*",
            ];
            foreach ($patterns as $pat) {
                $matched = glob($pat);
                if (!empty($matched) && file_exists($matched[0])) {
                    $localPath = $matched[0];
                    $cleanFilename = basename($localPath);
                    $foundFallback = true;
                    break;
                }
            }

            if (!$foundFallback) {
                return [
                    'success'  => false,
                    'status'   => 'missing',
                    'id'       => $id,
                    'filename' => $cleanFilename,
                    'message'  => "File tidak ditemukan di disk: {$cleanFilename}"
                ];
            }
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

    if ($action === 'inspect') {
        $samples = [];
        foreach ($MIGRATION_TARGETS as $k => $cfg) {
            $table = $cfg['table'];
            $col   = $cfg['col'];
            $pk    = $cfg['pk'];
            try {
                $stmt = $pdo->query("SELECT `{$pk}`, `{$col}` FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != '' ORDER BY `{$pk}` DESC LIMIT 4");
                $samples[$k] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $samples[$k] = ['error' => $e->getMessage()];
            }
        }
        echo json_encode(['success' => true, 'samples' => $samples]);
        exit;
    }

    if ($action === 'sync_disk_batch') {
        $cat          = $_POST['cat'] ?? 'menu';
        $offset       = (int)($_POST['offset'] ?? 0);
        $batchLimit   = max(1, min(15, (int)($_POST['batch_size'] ?? 5)));
        $skipExisting = !isset($_POST['skip_existing']) || $_POST['skip_existing'] === '1' || $_POST['skip_existing'] === 'true';
        $deleteLocal  = !empty($_POST['delete_local']) && ($_POST['delete_local'] === '1' || $_POST['delete_local'] === 'true');

        $diskConfigs = [
            'menu' => [
                'dir'   => __DIR__ . '/uploads/menu',
                'cloud' => 'menu',
                'label' => 'Foto Menu',
            ],
            'receiving' => [
                'dir'   => __DIR__ . '/uploads/foto',
                'cloud' => 'receiving',
                'label' => 'Foto Receiving',
            ],
            'kemasan' => [
                'dir'   => __DIR__ . '/uploads/foto-perkemasan',
                'cloud' => 'kemasan',
                'label' => 'Foto Kemasan',
            ],
            'nota' => [
                'dir'   => __DIR__ . '/uploads/nota',
                'cloud' => 'nota',
                'label' => 'Foto Nota',
            ],
            'faktur' => [
                'dir'   => __DIR__ . '/uploads/faktur',
                'cloud' => 'faktur',
                'label' => 'Faktur TTD',
            ],
            'addcost_nota' => [
                'dir'   => __DIR__ . '/addcost/uploads/addcost_nota',
                'cloud' => 'addcost-nota',
                'label' => 'Nota Addcost',
            ],
            'addcost_receiving' => [
                'dir'   => __DIR__ . '/addcost/uploads/addcost_receiving',
                'cloud' => 'addcost-receiving',
                'label' => 'Receiving Addcost',
            ],
        ];

        if (!isset($diskConfigs[$cat])) {
            echo json_encode(['success' => false, 'message' => 'Kategori disk tidak valid']);
            exit;
        }

        $cfg = $diskConfigs[$cat];
        $dir = $cfg['dir'];

        if (!is_dir($dir)) {
            echo json_encode([
                'success'     => true,
                'cat'         => $cat,
                'label'       => $cfg['label'],
                'offset'      => 0,
                'next_offset' => 0,
                'total_files' => 0,
                'finished'    => true,
                'items'       => [],
                'message'     => 'Folder tidak ditemukan di server'
            ]);
            exit;
        }

        $allFiles = array_values(array_filter(scandir($dir), function($f) use ($dir) {
            return is_file($dir . '/' . $f) && $f !== '.gitkeep' && !str_starts_with($f, '.');
        }));

        $totalFiles = count($allFiles);
        $slice = array_slice($allFiles, $offset, $batchLimit);
        $items = [];
        $nextOffset = $offset + count($slice);

        // Helper pemeriksa validitas URL Cloudinary di DB
        $isValidCloud = function($u) {
            return is_string($u)
                && str_contains($u, 'res.cloudinary.com')
                && !str_contains($u, 'aplikasi-permenceker/aplikasi-permenceker')
                && !str_ends_with($u, '/image.webp');
        };

        foreach ($slice as $filename) {
            $localPath = $dir . '/' . $filename;

            // 1. Cek apakah file sudah pernah diupload ke Cloudinary dan valid di database
            if ($skipExisting) {
                $alreadyCloudUrl = null;
                try {
                    if ($cat === 'menu') {
                        if (preg_match('/menu_\d+_(\d+)_[a-zA-Z0-9]+/', $filename, $m)) {
                            $idBelanja = (int)$m[1];
                            $stmt = $pdo->prepare("SELECT foto FROM foto_menu_multiple WHERE id_belanja = ?");
                            $stmt->execute([$idBelanja]);
                            while ($u = $stmt->fetchColumn()) {
                                if ($isValidCloud($u)) { $alreadyCloudUrl = $u; break; }
                            }
                        }
                        if (!$alreadyCloudUrl) {
                            $stmt2 = $pdo->prepare("SELECT foto FROM foto_menu_multiple WHERE foto LIKE ?");
                            $stmt2->execute(['%' . $filename]);
                            while ($u = $stmt2->fetchColumn()) {
                                if ($isValidCloud($u)) { $alreadyCloudUrl = $u; break; }
                            }
                        }
                    } elseif ($cat === 'receiving') {
                        if (preg_match('/receiving_\d+_(\d+)_[a-zA-Z0-9]+/', $filename, $m)) {
                            $idDetail = (int)$m[1];
                            $stmt = $pdo->prepare("SELECT foto FROM foto_receiving WHERE id_detail = ?");
                            $stmt->execute([$idDetail]);
                            while ($u = $stmt->fetchColumn()) {
                                if ($isValidCloud($u)) { $alreadyCloudUrl = $u; break; }
                            }
                        }
                        if (!$alreadyCloudUrl) {
                            $stmt2 = $pdo->prepare("SELECT foto FROM foto_receiving WHERE foto LIKE ?");
                            $stmt2->execute(['%' . $filename]);
                            while ($u = $stmt2->fetchColumn()) {
                                if ($isValidCloud($u)) { $alreadyCloudUrl = $u; break; }
                            }
                        }
                    } elseif ($cat === 'kemasan') {
                        $stmt = $pdo->prepare("SELECT foto_kemasan FROM detail_penerimaan WHERE foto_kemasan LIKE ?");
                        $stmt->execute(['%' . $filename]);
                        while ($u = $stmt->fetchColumn()) {
                            if ($isValidCloud($u)) { $alreadyCloudUrl = $u; break; }
                        }
                        if (!$alreadyCloudUrl && preg_match('/kemasan_(\d+)_/', $filename, $m)) {
                            $stmt2 = $pdo->prepare("SELECT foto_kemasan FROM detail_penerimaan WHERE id = ?");
                            $stmt2->execute([(int)$m[1]]);
                            $u = $stmt2->fetchColumn();
                            if ($isValidCloud($u)) { $alreadyCloudUrl = $u; }
                        }
                    } elseif ($cat === 'nota') {
                        $stmt = $pdo->prepare("SELECT file_nota FROM lampiran_nota WHERE file_nota LIKE ?");
                        $stmt->execute(['%' . $filename]);
                        while ($u = $stmt->fetchColumn()) {
                            if ($isValidCloud($u)) { $alreadyCloudUrl = $u; break; }
                        }
                        if (!$alreadyCloudUrl && preg_match('/nota_\d+_(\d+)_[a-zA-Z0-9]+/', $filename, $m)) {
                            $stmt2 = $pdo->prepare("SELECT file_nota FROM lampiran_nota WHERE id_detail = ?");
                            $stmt2->execute([(int)$m[1]]);
                            while ($u = $stmt2->fetchColumn()) {
                                if ($isValidCloud($u)) { $alreadyCloudUrl = $u; break; }
                            }
                        }
                    } elseif ($cat === 'faktur') {
                        $stmt = $pdo->prepare("SELECT file_faktur FROM faktur_ttd WHERE file_faktur LIKE ?");
                        $stmt->execute(['%' . $filename]);
                        while ($u = $stmt->fetchColumn()) {
                            if ($isValidCloud($u)) { $alreadyCloudUrl = $u; break; }
                        }
                    } elseif ($cat === 'addcost_nota') {
                        $stmt = $pdo->prepare("SELECT foto_nota FROM pembelian_addcost_detail WHERE foto_nota LIKE ?");
                        $stmt->execute(['%' . $filename . '%']);
                        while ($json = $stmt->fetchColumn()) {
                            $arr = json_decode($json, true);
                            if (is_array($arr)) {
                                foreach ($arr as $item) {
                                    if ($isValidCloud($item)) { $alreadyCloudUrl = $item; break 2; }
                                }
                            }
                        }
                    } elseif ($cat === 'addcost_receiving') {
                        $stmt = $pdo->prepare("SELECT foto_receiving FROM pembelian_addcost_detail WHERE foto_receiving LIKE ?");
                        $stmt->execute(['%' . $filename . '%']);
                        while ($json = $stmt->fetchColumn()) {
                            $arr = json_decode($json, true);
                            if (is_array($arr)) {
                                foreach ($arr as $item) {
                                    if ($isValidCloud($item)) { $alreadyCloudUrl = $item; break 2; }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                }

                if ($alreadyCloudUrl !== null) {
                    $items[] = [
                        'file'    => $filename,
                        'status'  => 'skipped',
                        'url'     => $alreadyCloudUrl,
                        'message' => 'Sudah ada di Cloudinary'
                    ];
                    continue;
                }
            }

            // 2. Upload file fisik ke Cloudinary
            try {
                $up = cloudinary_upload($localPath, $cfg['cloud']);
                if ($up['success'] && !empty($up['url'])) {
                    $cloudUrl = $up['url'];

                    // 3. Update DB sesuai kategori
                    if ($cat === 'menu') {
                        if (preg_match('/menu_\d+_(\d+)_[a-zA-Z0-9]+/', $filename, $m)) {
                            $idBelanja = (int)$m[1];
                            $stmtCheck = $pdo->prepare("SELECT id FROM foto_menu_multiple WHERE id_belanja = ? AND (foto LIKE '%image.webp%' OR foto LIKE '%aplikasi-permenceker/aplikasi-permenceker%' OR foto NOT LIKE '%res.cloudinary.com%') LIMIT 1");
                            $stmtCheck->execute([$idBelanja]);
                            $existId = $stmtCheck->fetchColumn();
                            if ($existId) {
                                $pdo->prepare("UPDATE foto_menu_multiple SET foto = ? WHERE id = ?")->execute([$cloudUrl, $existId]);
                            } else {
                                $stmtExact = $pdo->prepare("SELECT id FROM foto_menu_multiple WHERE id_belanja = ? AND foto = ?");
                                $stmtExact->execute([$idBelanja, $cloudUrl]);
                                if (!$stmtExact->fetchColumn()) {
                                    $pdo->prepare("INSERT INTO foto_menu_multiple (id_belanja, foto) VALUES (?, ?)")->execute([$idBelanja, $cloudUrl]);
                                }
                            }
                            // Sinkronkan juga ke tabel belanja jika ada
                            $pdo->prepare("UPDATE belanja SET foto_menu = ? WHERE id_belanja = ? AND (foto_menu IS NULL OR foto_menu = '' OR foto_menu NOT LIKE '%res.cloudinary.com%' OR foto_menu LIKE '%image.webp%' OR foto_menu LIKE '%aplikasi-permenceker/aplikasi-permenceker%')")->execute([$cloudUrl, $idBelanja]);
                        } else {
                            $pdo->prepare("UPDATE foto_menu_multiple SET foto = :url WHERE foto = :fn OR foto LIKE :search")->execute([':url' => $cloudUrl, ':fn' => $filename, ':search' => '%' . $filename]);
                        }
                    } elseif ($cat === 'receiving') {
                        $upd = $pdo->prepare("UPDATE foto_receiving SET foto = :cloudUrl WHERE foto = :filename OR foto LIKE :searchName");
                        $upd->execute([':cloudUrl' => $cloudUrl, ':filename' => $filename, ':searchName' => '%' . $filename]);
                        if ($upd->rowCount() === 0 && preg_match('/receiving_\d+_(\d+)_[a-zA-Z0-9]+/', $filename, $m)) {
                            $idDetail = (int)$m[1];
                            $pdo->prepare("UPDATE foto_receiving SET foto = ? WHERE id_detail = ? AND (foto NOT LIKE '%res.cloudinary.com%' OR foto LIKE '%image.webp%' OR foto LIKE '%aplikasi-permenceker/aplikasi-permenceker%') LIMIT 1")->execute([$cloudUrl, $idDetail]);
                        }
                    } elseif ($cat === 'kemasan') {
                        $upd = $pdo->prepare("UPDATE detail_penerimaan SET foto_kemasan = :cloudUrl WHERE foto_kemasan = :filename OR foto_kemasan LIKE :searchName");
                        $upd->execute([':cloudUrl' => $cloudUrl, ':filename' => $filename, ':searchName' => '%' . $filename]);
                        if ($upd->rowCount() === 0 && preg_match('/kemasan_(\d+)_/', $filename, $m)) {
                            $id = (int)$m[1];
                            $pdo->prepare("UPDATE detail_penerimaan SET foto_kemasan = ? WHERE id = ? AND (foto_kemasan NOT LIKE '%res.cloudinary.com%' OR foto_kemasan LIKE '%image.webp%' OR foto_kemasan LIKE '%aplikasi-permenceker/aplikasi-permenceker%')")->execute([$cloudUrl, $id]);
                        }
                    } elseif ($cat === 'nota') {
                        $upd = $pdo->prepare("UPDATE lampiran_nota SET file_nota = :cloudUrl WHERE file_nota = :filename OR file_nota LIKE :searchName");
                        $upd->execute([':cloudUrl' => $cloudUrl, ':filename' => $filename, ':searchName' => '%' . $filename]);
                        if ($upd->rowCount() === 0 && preg_match('/nota_\d+_(\d+)_[a-zA-Z0-9]+/', $filename, $m)) {
                            $idDetail = (int)$m[1];
                            $pdo->prepare("UPDATE lampiran_nota SET file_nota = ? WHERE id_detail = ? AND (file_nota NOT LIKE '%res.cloudinary.com%' OR file_nota LIKE '%image.webp%' OR file_nota LIKE '%aplikasi-permenceker/aplikasi-permenceker%') LIMIT 1")->execute([$cloudUrl, $idDetail]);
                        }
                    } elseif ($cat === 'faktur') {
                        $upd = $pdo->prepare("UPDATE faktur_ttd SET file_faktur = :cloudUrl WHERE file_faktur = :filename OR file_faktur LIKE :searchName");
                        $upd->execute([':cloudUrl' => $cloudUrl, ':filename' => $filename, ':searchName' => '%' . $filename]);
                    } elseif ($cat === 'addcost_nota') {
                        $rows = $pdo->query("SELECT id, foto_nota FROM pembelian_addcost_detail WHERE foto_nota LIKE '%{$filename}%'")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $r) {
                            $arr = json_decode($r['foto_nota'], true) ?: [];
                            $newArr = array_map(fn($f) => ($f === $filename || str_ends_with($f, $filename)) ? $cloudUrl : $f, $arr);
                            $pdo->prepare("UPDATE pembelian_addcost_detail SET foto_nota = ? WHERE id = ?")->execute([json_encode($newArr), $r['id']]);
                        }
                    } elseif ($cat === 'addcost_receiving') {
                        $rows = $pdo->query("SELECT id, foto_receiving FROM pembelian_addcost_detail WHERE foto_receiving LIKE '%{$filename}%'")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $r) {
                            $arr = json_decode($r['foto_receiving'], true) ?: [];
                            $newArr = array_map(fn($f) => ($f === $filename || str_ends_with($f, $filename)) ? $cloudUrl : $f, $arr);
                            $pdo->prepare("UPDATE pembelian_addcost_detail SET foto_receiving = ? WHERE id = ?")->execute([json_encode($newArr), $r['id']]);
                        }
                    }

                    if ($deleteLocal && file_exists($localPath)) {
                        @unlink($localPath);
                    }

                    $items[] = ['file' => $filename, 'status' => 'ok', 'url' => $cloudUrl, 'message' => 'Sukses terunggah ke Cloudinary & DB diperbaiki'];
                } else {
                    $items[] = ['file' => $filename, 'status' => 'failed', 'message' => $up['message'] ?? 'Upload Cloudinary gagal'];
                }
            } catch (Exception $e) {
                $items[] = ['file' => $filename, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        echo json_encode([
            'success'     => true,
            'cat'         => $cat,
            'label'       => $cfg['label'],
            'offset'      => $offset,
            'next_offset' => $nextOffset,
            'total_files' => $totalFiles,
            'finished'    => ($nextOffset >= $totalFiles),
            'items'       => $items,
            'stats'       => getMigrationStats($pdo, $MIGRATION_TARGETS)
        ]);
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

        // Ambil baris berikutnya berdasarkan cursor lastId (termasuk baris dengan broken Cloudinary URL)
        $stmt = $pdo->prepare("SELECT `{$pkCol}`, `{$col}` FROM `{$table}` WHERE `{$pkCol}` > ? AND `{$col}` IS NOT NULL AND `{$col}` != '' AND (`{$col}` NOT LIKE '%res.cloudinary.com%' OR `{$col}` LIKE '%aplikasi-permenceker/aplikasi-permenceker%' OR `{$col}` LIKE '%image.webp%') ORDER BY `{$pkCol}` ASC LIMIT {$batchSize}");
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
                        <option value="all">Semua Kategori (Menu, Receiving, Kemasan, Nota, Faktur, Addcost)</option>
                        <option value="menu">Foto Menu (uploads/menu)</option>
                        <option value="receiving">Foto Receiving (uploads/foto)</option>
                        <option value="kemasan">Foto Kemasan (uploads/foto-perkemasan)</option>
                        <option value="nota">Foto Lampiran Nota (uploads/nota)</option>
                        <option value="faktur">Faktur TTD (uploads/faktur)</option>
                        <option value="addcost_nota">Nota Addcost (addcost/uploads/addcost_nota)</option>
                        <option value="addcost_receiving">Receiving Addcost (addcost/uploads/addcost_receiving)</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 5px;">Batch per Request:</label>
                    <select id="selectBatchSize" style="padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 500;">
                        <option value="3">3 File per batch (Aman untuk koneksi santai)</option>
                        <option value="5" selected>5 File per batch (Standar)</option>
                        <option value="10">10 File per batch (Lebih cepat)</option>
                    </select>
                </div>
                <div>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #334155; margin-top: 22px; cursor: pointer;">
                        <input type="checkbox" id="chkSkipExisting" value="1" checked>
                        <span><strong>Lewati yang sudah ada di Cloudinary</strong> (Hanya upload yang belum ada atau link rusak)</span>
                    </label>
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
        <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 20px;">
            <button type="button" id="btnStartDiskSync" class="btn" style="background: #059669; color: white; border: none; font-weight: 600;" onclick="startDiskSync()" title="Pindai seluruh folder server dan unggah semua file yang belum ada di Cloudinary serta perbaiki database">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"></polyline>
                    <polyline points="1 20 1 14 7 14"></polyline>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
                <span>Perbaiki & Upload dari Folder Server (Scan Disk)</span>
            </button>
            <button type="button" id="btnStartMigration" class="btn btn-primary" onclick="startMigration()" title="Proses record dari database satu per satu">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="16 16 12 12 8 16"></polyline>
                    <line x1="12" y1="12" x2="12" y2="21"></line>
                    <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>
                </svg>
                <span>Migrasi dari Baris Database (DB Scan)</span>
            </button>
            <button type="button" id="btnStopProcess" class="btn btn-danger" style="display: none;" onclick="stopProcess()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <rect x="9" y="9" width="6" height="6"></rect>
                </svg>
                <span>Hentikan Proses</span>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="inspectSamples()" title="Tampilkan contoh data foto yang tersimpan di database">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <span>Cek Sampel URL di Database</span>
            </button>
            <span id="migrationStatusLabel" style="font-size: 13px; font-weight: 600; color: #64748b;"></span>
        </div>

        <!-- Log Eksekusi -->
        <h4 style="margin: 0 0 8px 0; color: #334155; font-size: 14px;">Log Eksekusi Real-Time</h4>
        <div class="log-box" id="logBox">[Siap] Pilih aksi: Tekan tombol hijau "Perbaiki & Upload dari Folder Server" untuk memindai file fisik dan mengunggah yang belum ada di Cloudinary.</div>
    </div>
</div>

<script>
let isRunning = false;
let shouldStop = false;

const DISK_CATEGORIES = [
    { key: 'menu', label: 'Foto Menu' },
    { key: 'receiving', label: 'Foto Receiving' },
    { key: 'kemasan', label: 'Foto Kemasan' },
    { key: 'nota', label: 'Foto Lampiran Nota' },
    { key: 'faktur', label: 'Faktur TTD' },
    { key: 'addcost_nota', label: 'Nota Addcost' },
    { key: 'addcost_receiving', label: 'Receiving Addcost' },
];

const DB_CATEGORIES = ['menu', 'menu_single', 'foto', 'kemasan', 'nota', 'faktur', 'addcost_nota', 'addcost_receiving'];

function setButtonsRunning(running) {
    isRunning = running;
    document.getElementById('btnStartDiskSync').style.display = running ? 'none' : 'inline-flex';
    document.getElementById('btnStartMigration').style.display = running ? 'none' : 'inline-flex';
    document.getElementById('btnStopProcess').style.display = running ? 'inline-flex' : 'none';
}

function stopProcess() {
    shouldStop = true;
    document.getElementById('migrationStatusLabel').textContent = 'Menghentikan proses...';
    addLog('Mengirim sinyal henti, menunggu batch aktif selesai...', 'skip');
}

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

/**
 * Fitur Utama: Scan Folder Server dan Upload Semua yang Belum Ada di Cloudinary
 */
async function startDiskSync() {
    if (isRunning) return;

    const selectedTarget = document.getElementById('selectTarget').value;
    const batchSize      = document.getElementById('selectBatchSize').value;
    const skipExisting   = document.getElementById('chkSkipExisting').checked ? '1' : '0';
    const deleteLocal    = document.getElementById('chkDeleteLocal').checked ? '1' : '0';

    let queue = [];
    if (selectedTarget === 'all') {
        queue = [...DISK_CATEGORIES];
    } else {
        const catMap = {
            'menu': 'menu',
            'menu_single': 'menu',
            'foto': 'receiving',
            'receiving': 'receiving',
            'kemasan': 'kemasan',
            'nota': 'nota',
            'faktur': 'faktur',
            'addcost_nota': 'addcost_nota',
            'addcost_receiving': 'addcost_receiving'
        };
        const mappedKey = catMap[selectedTarget] || selectedTarget;
        const found = DISK_CATEGORIES.find(c => c.key === mappedKey);
        queue = [found || { key: mappedKey, label: mappedKey }];
    }

    const confirmMsg = skipExisting === '1'
        ? `Fitur ini akan memindai folder server (${queue.length} modul) dan mengunggah SEMUA file yang belum ada di Cloudinary (atau memperbaiki link rusak di database).\n\nLanjutkan?`
        : `PERINGATAN: Opsi 'Lewati yang sudah ada' tidak dicentang. Semua file di server akan diunggah ulang secara paksa.\n\nLanjutkan?`;

    if (!confirm(confirmMsg)) return;

    shouldStop = false;
    setButtonsRunning(true);
    document.getElementById('migrationStatusLabel').textContent = 'Memindai folder server...';
    addLog(`=== MEMULAI PEMINDAIAN FOLDER SERVER (${queue.length} KATEGORI) ===`, 'info');
    if (skipExisting === '1') {
        addLog(`Mode: Hanya mengunggah file yang belum ada atau linknya rusak di Cloudinary (Cepat & Hemat Kuota)`, 'info');
    } else {
        addLog(`Mode: Force re-upload semua file ke Cloudinary`, 'skip');
    }

    let grandUploaded = 0;
    let grandSkipped  = 0;
    let grandFailed   = 0;

    for (const catObj of queue) {
        if (shouldStop) break;

        const catKey   = catObj.key;
        const catLabel = catObj.label;
        let offset = 0;

        addLog(`>>> Memeriksa folder: [${catLabel}]...`, 'info');

        while (isRunning && !shouldStop) {
            try {
                const formData = new FormData();
                formData.append('cat', catKey);
                formData.append('offset', offset);
                formData.append('batch_size', batchSize);
                formData.append('skip_existing', skipExisting);
                formData.append('delete_local', deleteLocal);

                const res = await fetch('migrate_to_cloudinary.php?action=sync_disk_batch', {
                    method: 'POST',
                    body: formData
                });

                if (!res.ok) throw new Error('HTTP Status ' + res.status);
                const json = await res.json();

                if (!json.success) {
                    addLog(`Error [${catLabel}]: ` + (json.message || 'Gagal memproses batch'), 'err');
                    grandFailed++;
                    break;
                }

                if (json.total_files === 0) {
                    addLog(`Folder [${catLabel}] kosong (0 file fisik ditemukan).`, 'skip');
                    break;
                }

                if (json.items && json.items.length > 0) {
                    for (const it of json.items) {
                        if (it.status === 'ok') {
                            grandUploaded++;
                            addLog(`✓ [${catLabel}] ${it.file} -> Sukses diunggah ke Cloudinary & DB diperbaiki`, 'ok');
                        } else if (it.status === 'skipped') {
                            grandSkipped++;
                            addLog(`⏭ [${catLabel}] ${it.file} -> Sudah ada di Cloudinary (Dilewati)`, 'skip');
                        } else {
                            grandFailed++;
                            addLog(`✗ [${catLabel}] ${it.file} -> Gagal: ${it.message}`, 'err');
                        }
                    }
                }

                if (json.stats) {
                    updateUIStats(json.stats);
                }

                document.getElementById('migrationStatusLabel').textContent = `[${catLabel}] ${Math.min(json.next_offset, json.total_files)} / ${json.total_files} file`;

                if (json.finished) {
                    addLog(`Folder [${catLabel}] selesai dipindai (${json.total_files} file).`, 'info');
                    break;
                }

                offset = json.next_offset;
                await new Promise(r => setTimeout(r, 250));

            } catch (err) {
                addLog(`Error koneksi pada [${catLabel}]: ` + err.message, 'err');
                grandFailed++;
                break;
            }
        }
    }

    setButtonsRunning(false);

    if (shouldStop) {
        document.getElementById('migrationStatusLabel').textContent = 'Dihentikan oleh pengguna';
        addLog('Proses dihentikan oleh pengguna.', 'skip');
    } else {
        document.getElementById('migrationStatusLabel').textContent = 'Pemindaian server selesai!';
        addLog(`=== SELESAI SINKRONISASI SERVER === Berhasil diunggah/diperbaiki: ${grandUploaded} | Sudah ada (dilewati): ${grandSkipped} | Gagal: ${grandFailed}`, 'ok');
        alert(`Sinkronisasi selesai!\n- Berhasil diunggah & diperbaiki: ${grandUploaded}\n- Sudah ada di Cloudinary (dilewati): ${grandSkipped}\n- Gagal: ${grandFailed}`);
    }
}

/**
 * Migrasi Berdasarkan Baris Database
 */
async function startMigration() {
    if (isRunning) return;

    shouldStop = false;
    setButtonsRunning(true);
    document.getElementById('migrationStatusLabel').textContent = 'Sedang berjalan dari Database...';

    const selectedTarget = document.getElementById('selectTarget').value;
    const batchSize      = document.getElementById('selectBatchSize').value;
    const deleteLocal    = document.getElementById('chkDeleteLocal').checked ? '1' : '0';

    let queue = [];
    if (selectedTarget === 'all') {
        queue = [...DB_CATEGORIES];
    } else {
        const catMap = {
            'menu': 'menu',
            'receiving': 'foto',
            'foto': 'foto',
            'kemasan': 'kemasan',
            'nota': 'nota',
            'faktur': 'faktur',
            'addcost_nota': 'addcost_nota',
            'addcost_receiving': 'addcost_receiving'
        };
        queue = [catMap[selectedTarget] || selectedTarget];
    }

    addLog(`Memulai proses migrasi dari baris database (${queue.length} modul antrean)...`, 'info');

    let totalUploaded = 0;
    let totalSkipped  = 0;
    let totalFailed   = 0;

    for (const targetKey of queue) {
        if (shouldStop) break;

        let lastId = 0;
        addLog(`>>> Memproses antrean database: ${targetKey}`, 'info');

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

                if (!res.ok) throw new Error('HTTP Status ' + res.status);
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
                        } else if (it.status === 'already_cloud') {
                            totalSkipped++;
                            addLog(`⏭ [${it.category}] ID ${it.id} -> Sudah di Cloudinary`, 'skip');
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
                    addLog(`Kategori database [${targetKey}] selesai dipindai.`, 'info');
                    break;
                }

                await new Promise(r => setTimeout(r, 250));

            } catch (err) {
                addLog('Kesalahan koneksi / batch error: ' + err.message, 'err');
                totalFailed++;
                break;
            }
        }
    }

    setButtonsRunning(false);

    if (shouldStop) {
        document.getElementById('migrationStatusLabel').textContent = 'Dihentikan oleh pengguna';
        addLog('Proses dihentikan.', 'skip');
    } else {
        document.getElementById('migrationStatusLabel').textContent = 'Migrasi DB selesai!';
        addLog(`=== SELESAI === Berhasil diunggah: ${totalUploaded} | Dilewati: ${totalSkipped} | Gagal: ${totalFailed}`, 'ok');
    }
}

async function inspectSamples() {
    addLog('Mengambil sampel 4 record terbaru dari database...', 'info');
    try {
        const res = await fetch('migrate_to_cloudinary.php?action=inspect');
        const json = await res.json();
        if (json.success && json.samples) {
            for (const cat in json.samples) {
                const rows = json.samples[cat];
                if (Array.isArray(rows) && rows.length > 0) {
                    addLog(`--- Kategori [${cat}] ---`, 'info');
                    rows.forEach(r => {
                        const pkKey = Object.keys(r)[0];
                        const colKey = Object.keys(r)[1];
                        const val = r[colKey];
                        const isCloud = String(val).includes('res.cloudinary.com');
                        const isBroken = String(val).includes('aplikasi-permenceker/aplikasi-permenceker') || String(val).endsWith('/image.webp');
                        let statusTag = '[BUKAN CLOUDINARY]';
                        let logType = 'skip';
                        if (isCloud && !isBroken) {
                            statusTag = '[CLOUDINARY VALID]';
                            logType = 'ok';
                        } else if (isCloud && isBroken) {
                            statusTag = '[CLOUDINARY RUSAK/DUPLIKAT]';
                            logType = 'err';
                        }
                        addLog(`ID ${r[pkKey]}: ${val} ${statusTag}`, logType);
                    });
                }
            }
        } else {
            addLog('Gagal mengambil sampel data', 'err');
        }
    } catch (e) {
        addLog('Error inspect: ' + e.message, 'err');
    }
}
</script>

</body>
</html>
