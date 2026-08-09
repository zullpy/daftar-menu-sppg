<?php
// =========================================================================
// AUTO DATABASE MIGRATION RUNNER (PDO) - Aplikasi MBG
// =========================================================================

function runAutoMigrationsPDO($pdo)
{
    if (!($pdo instanceof PDO)) return;

    // 1. Buat tabel schema_migrations jika belum ada
    $createTable = "CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(255) NOT NULL PRIMARY KEY,
        executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($createTable);
    } catch (\Throwable $e) {
        // Suppress
    }

    // 2. Ambil daftar migrasi yang sudah pernah dieksekusi
    $executed = [];
    try {
        $stmt = $pdo->query("SELECT version FROM schema_migrations");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $executed[$row['version']] = true;
            }
        }
    } catch (\Throwable $e) {
        // Suppress
    }

    // 3. Scan folder database/migrations/
    $migrationDir = __DIR__ . '/migrations';
    if (!is_dir($migrationDir)) {
        return;
    }

    $migrationFiles = [];
    $files = array_merge(
        glob($migrationDir . '/*.sql') ?: [],
        glob($migrationDir . '/*.php') ?: []
    );
    foreach ($files as $f) {
        $version = basename($f);
        $migrationFiles[$version] = $f;
    }

    ksort($migrationFiles);

    // 4. Eksekusi file migrasi yang belum pernah dijalankan
    foreach ($migrationFiles as $version => $filePath) {
        if (isset($executed[$version])) {
            continue;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $success = false;

        if ($ext === 'sql') {
            $sql = file_get_contents($filePath);
            if (trim($sql) !== '') {
                $queries = array_filter(array_map('trim', explode(';', $sql)));
                $success = true;
                foreach ($queries as $q) {
                    if ($q !== '') {
                        try {
                            $pdo->exec($q);
                        } catch (\PDOException $pe) {
                            $errCode = (int) ($pe->errorInfo[1] ?? 0);
                            if (!in_array($errCode, [1060, 1061, 1050, 1146], true)) {
                                $success = false;
                            }
                        } catch (\Throwable $t) {
                            // Suppress
                        }
                    }
                }
            } else {
                $success = true;
            }
        } elseif ($ext === 'php') {
            try {
                include $filePath;
                $success = true;
            } catch (\Throwable $e) {
                $success = false;
            }
        }

        if ($success) {
            try {
                $ins = $pdo->prepare("INSERT IGNORE INTO schema_migrations (version) VALUES (?)");
                $ins->execute([$version]);
            } catch (\Throwable $t) {
                // Suppress
            }
        }
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    runAutoMigrationsPDO($pdo);
}

if (isset($pdo_draft) && $pdo_draft instanceof PDO) {
    runAutoMigrationsPDO($pdo_draft);
}
