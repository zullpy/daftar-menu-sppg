<?php
// =========================================================================
// CLOUDINARY HELPER (Zero Dependency - PHP Native cURL)
// Mendukung upload langsung ke Cloudinary REST API,
// fallback otomatis ke penyimpanan lokal jika kredensial belum diisi,
// dan backward compatibility untuk foto lama.
// =========================================================================

// Muat konfigurasi Cloudinary (jika ada)
$configFile = __DIR__ . '/cloudinary.php';
if (file_exists($configFile)) {
    require_once $configFile;
} elseif (file_exists(__DIR__ . '/cloudinary.example.php')) {
    require_once __DIR__ . '/cloudinary.example.php';
}

/**
 * Cek apakah kredensial Cloudinary sudah diisi valid
 */
function cloudinary_is_configured(): bool
{
    return defined('CLOUDINARY_CLOUD_NAME')
        && defined('CLOUDINARY_API_KEY')
        && defined('CLOUDINARY_API_SECRET')
        && !empty(trim(CLOUDINARY_CLOUD_NAME))
        && !empty(trim(CLOUDINARY_API_KEY))
        && !empty(trim(CLOUDINARY_API_SECRET))
        && trim(CLOUDINARY_CLOUD_NAME) !== 'YOUR_CLOUD_NAME';
}

/**
 * Upload file langsung ke Cloudinary API menggunakan cURL
 *
 * @param string $filePath Path file fisik di server (bisa $_FILES['...']['tmp_name'])
 * @param string $subfolder Subfolder di dalam aplikasi-permenceker (misal 'menu', 'receiving', 'nota')
 * @param string|null $publicId Nama custom public_id (opsional)
 * @param string $resourceType 'auto' (mendukung gambar & PDF), 'image', atau 'raw'
 * @return array ['success' => bool, 'url' => string, 'public_id' => string, 'format' => string]
 * @throws Exception Jika upload gagal
 */
function cloudinary_upload(string $filePath, string $subfolder = '', ?string $publicId = null, string $resourceType = 'auto'): array
{
    if (!file_exists($filePath)) {
        throw new Exception("File sumber tidak ditemukan: " . $filePath);
    }

    if (!cloudinary_is_configured()) {
        throw new Exception("Cloudinary belum dikonfigurasi. Silakan lengkapi kredensial di database/cloudinary.php");
    }

    $cloudName = trim(CLOUDINARY_CLOUD_NAME);
    $apiKey    = trim(CLOUDINARY_API_KEY);
    $apiSecret = trim(CLOUDINARY_API_SECRET);

    $baseFolder = defined('CLOUDINARY_BASE_FOLDER') ? trim(CLOUDINARY_BASE_FOLDER, '/') : 'aplikasi-permenceker';
    $targetFolder = !empty($subfolder) ? ($baseFolder . '/' . trim($subfolder, '/')) : $baseFolder;

    $timestamp = time();
    $paramsToSign = [
        'folder'    => $targetFolder,
        'timestamp' => (string)$timestamp,
    ];

    // Deteksi apakah file gambar (bukan PDF / non-image) agar otomatis dikonversi ke WebP di Cloudinary
    $isImage = false;
    if (function_exists('getimagesize')) {
        $imgCheck = @getimagesize($filePath);
        if ($imgCheck !== false) {
            $isImage = true;
        }
    }

    if ($isImage) {
        $paramsToSign['format'] = 'webp';
    }

    if (!empty($publicId)) {
        $paramsToSign['public_id'] = $publicId;
    }

    // Urutkan parameter berdasarkan nama kunci alfabetis
    ksort($paramsToSign);

    // Bentuk string parameter yang akan di-hash
    $paramPairs = [];
    foreach ($paramsToSign as $k => $v) {
        $paramPairs[] = "{$k}={$v}";
    }
    $stringToSign = implode('&', $paramPairs) . $apiSecret;
    $signature = sha1($stringToSign);

    // Payload POST multipart
    $postFields = [
        'file'      => new CURLFile($filePath),
        'api_key'   => $apiKey,
        'timestamp' => $timestamp,
        'signature' => $signature,
        'folder'    => $targetFolder,
    ];

    if ($isImage) {
        $postFields['format'] = 'webp';
    }

    if (!empty($publicId)) {
        $postFields['public_id'] = $publicId;
    }

    $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/upload";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Koneksi ke Cloudinary gagal: " . $curlError);
    }

    $json = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($json['secure_url'])) {
        $secureUrl = $json['secure_url'];
        // Tambahkan transformasi f_auto,q_auto untuk auto-kompresi & auto WebP
        if (str_contains($secureUrl, '/image/upload/') && !str_contains($secureUrl, 'f_auto')) {
            $secureUrl = str_replace('/image/upload/', '/image/upload/f_auto,q_auto/', $secureUrl);
        }
        return [
            'success'   => true,
            'url'       => $secureUrl,
            'public_id' => $json['public_id'] ?? '',
            'format'    => $json['format'] ?? '',
            'bytes'     => $json['bytes'] ?? 0,
        ];
    }

    $errorMsg = $json['error']['message'] ?? ('HTTP ' . $httpCode . ': ' . $response);
    throw new Exception("Cloudinary Error: " . $errorMsg);
}

/**
 * Upload cerdas: Otomatis upload ke Cloudinary jika dikonfigurasi,
 * atau simpan lokal jika Cloudinary belum diset / fallback diaktifkan.
 *
 * @param array $fileItem Elemen dari $_FILES (misal $_FILES['foto'])
 * @param string $subfolder Subfolder di Cloudinary (misal 'menu', 'receiving', 'nota', 'faktur')
 * @param string $localDir Folder lokal cadangan (misal '../uploads/menu/')
 * @param string $prefix Prefix nama file jika disimpan lokal
 * @return string Mengembalikan URL Cloudinary (https://...) ATAU nama file lokal
 * @throws Exception
 */
function smart_upload_foto(array $fileItem, string $subfolder, string $localDir, string $prefix = 'img'): string
{
    $tmpName = $fileItem['tmp_name'] ?? '';
    $origName = $fileItem['name'] ?? '';

    if (empty($tmpName) || !file_exists($tmpName)) {
        throw new Exception("File upload tidak ditemukan");
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    // Coba upload ke Cloudinary terlebih dahulu jika sudah dikonfigurasi
    if (cloudinary_is_configured()) {
        try {
            $res = cloudinary_upload($tmpName, $subfolder);
            if (!empty($res['url'])) {
                return $res['url'];
            }
        } catch (Exception $e) {
            $fallback = defined('CLOUDINARY_FALLBACK_LOCAL') ? CLOUDINARY_FALLBACK_LOCAL : true;
            if (!$fallback) {
                throw $e;
            }
            // Jika fallback diizinkan, lanjut simpan lokal dan catat log
            error_log("Cloudinary upload failed, falling back to local: " . $e->getMessage());
        }
    }

    // Penyimpanan Lokal
    if (!file_exists($localDir)) {
        @mkdir($localDir, 0777, true);
    }
    if (!is_writable($localDir)) {
        @chmod($localDir, 0777);
    }

    $newName = $prefix . '_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
    $targetPath = rtrim($localDir, '/') . '/' . $newName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new Exception("Gagal menyimpan file ke penyimpanan server lokal ({$targetPath})");
    }

    return $newName;
}

/**
 * Resolver URL Foto:
 * Jika nilai adalah URL Cloudinary (https://...), gunakan langsung.
 * Jika masih berupa nama file lokal lama, tambahkan prefix folder lokal.
 *
 * @param string|null $photo Filename atau Full URL
 * @param string $localPrefix Path relatif folder lokal (misal 'uploads/menu/' atau '../uploads/foto/')
 * @return string
 */
function resolve_photo_url(?string $photo, string $localPrefix = ''): string
{
    if (empty($photo)) {
        return '';
    }

    // Jika sudah berupa URL Cloudinary atau web link
    if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
        // Otomatis optimalkan format (WebP) dan kompresi (q_auto) jika berasal dari Cloudinary
        if (str_contains($photo, 'res.cloudinary.com') && str_contains($photo, '/image/upload/') && !str_contains($photo, 'f_auto')) {
            return str_replace('/image/upload/', '/image/upload/f_auto,q_auto/', $photo);
        }
        return $photo;
    }

    // Jika nama file lokal lama
    return rtrim($localPrefix, '/') . '/' . ltrim($photo, '/');
}
