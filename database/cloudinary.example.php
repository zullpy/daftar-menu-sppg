<?php
// =========================================================================
// TEMPLATE KONFIGURASI CLOUDINARY
// Salin file ini menjadi 'database/cloudinary.php' dan isi kredensial Anda.
// File 'database/cloudinary.php' SUDAH masuk ke .gitignore sehingga AMAN
// dan tidak akan pernah ter-push ke GitHub.
// =========================================================================

// Dapatkan dari Dashboard Cloudinary (https://console.cloudinary.com)
define('CLOUDINARY_CLOUD_NAME', 'YOUR_CLOUD_NAME');
define('CLOUDINARY_API_KEY',    'YOUR_API_KEY');
define('CLOUDINARY_API_SECRET', 'YOUR_API_SECRET');

// Folder utama di Cloudinary Media Library (sesuai folder yang sudah dibuat)
define('CLOUDINARY_BASE_FOLDER', 'aplikasi-permenceker');

// Jika true: Bila koneksi Cloudinary gagal atau belum diset, file akan disimpan ke folder uploads lokal
// Jika false: Akan melempar error jika upload Cloudinary gagal
define('CLOUDINARY_FALLBACK_LOCAL', true);
