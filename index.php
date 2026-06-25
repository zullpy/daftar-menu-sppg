<?php
session_start();
// =======================================================
// KONFIGURASI PASSWORD
// =======================================================
$password_admin    = "admin123";
$password_opsodong = "sodong123";
$password_opsariwangi = "sariwangi123";
$password_opmanonjaya = "manonjaya123";

// =======================================================
// PROSES VERIFIKASI PASSWORD
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'verifikasi') {
    header('Content-Type: application/json');
    $role     = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($role === 'admin' && $password === $password_admin) {
        $_SESSION['role'] = 'admin';
        $_SESSION['lokasi'] = 'semua';
        $_SESSION['nama_op'] = 'Admin';

        echo json_encode([
            'status' => 'sukses',
            'redirect' => 'dashboard.php'
        ]);
        exit;
    }

    if ($role === 'opsodong' && $password === $password_opsodong) {
        $_SESSION['role'] = 'operator';
        $_SESSION['lokasi'] = 'sodong';
        $_SESSION['nama_op'] = 'Sodong';

        echo json_encode([
            'status' => 'sukses',
            'redirect' => 'dashboard.php'
        ]);
        exit;
    }

    if ($role === 'opsariwangi' && $password === $password_opsariwangi) {
        $_SESSION['role'] = 'operator';
        $_SESSION['lokasi'] = 'sariwangi';
        $_SESSION['nama_op'] = 'Sariwangi';

        echo json_encode([
            'status' => 'sukses',
            'redirect' => 'dashboard.php'
        ]);
        exit;
    }

    if ($role === 'opmanonjaya' && $password === $password_opmanonjaya) {
        $_SESSION['role'] = 'operator';
        $_SESSION['lokasi'] = 'manonjaya';
        $_SESSION['nama_op'] = 'Manonjaya';

        echo json_encode([
            'status' => 'sukses',
            'redirect' => 'dashboard.php'
        ]);
        exit;
    }

    echo json_encode(['status' => 'gagal', 'pesan' => 'Password salah, silakan coba lagi.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Akses - Bina Usaha Sauyunan</title>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="shortcut icon" href="assets/favicon.ico" type="image/x-icon">
    <style>
        :root {
            --color-primary: #2563eb;
            --color-primary-dark: #1d4ed8;
            --color-secondary: #16a34a;
            --color-secondary-dark: #15803d;
            --color-bg: #f1f5f9;
            --color-card: #ffffff;
            --color-text: #1e293b;
            --color-text-muted: #64748b;
            --color-border: #e2e8f0;
            --shadow-card: 0 4px 20px rgba(0, 0, 0, 0.06);
            --shadow-card-hover: 0 10px 30px rgba(0, 0, 0, 0.12);
            --radius: 16px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--color-bg) 0%, #e2e8f0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .container {
            width: 100%;
            max-width: 760px;
        }

        .banner-wrapper {
            margin-bottom: 32px;
            border-radius: var(--radius);
            overflow: hidden;
            background: transparent;
            box-shadow: none;
            border: none;
        }

        .banner-image {
            width: 100%;
            height: auto;
            display: block;
            background: transparent;
        }

        .pilihan-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        @media (max-width: 640px) {
            .pilihan-wrapper {
                grid-template-columns: 1fr;
            }

            .banner-wrapper {
                margin-bottom: 24px;
            }
        }

        .kartu-akses {
            background: var(--color-card);
            border-radius: var(--radius);
            padding: 40px 28px;
            text-align: center;
            cursor: pointer;
            box-shadow: var(--shadow-card);
            border: 2px solid transparent;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .kartu-akses:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-card-hover);
        }

        .kartu-akses.admin:hover {
            border-color: var(--color-primary);
        }

        .kartu-akses.operator:hover {
            border-color: var(--color-secondary);
        }

        .icon-wrapper {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
        }

        .kartu-akses.admin .icon-wrapper {
            background: rgba(37, 99, 235, 0.1);
            color: var(--color-primary);
        }

        .kartu-akses.operator .icon-wrapper {
            background: rgba(22, 163, 74, 0.1);
            color: var(--color-secondary);
        }

        .kartu-akses h2 {
            font-size: 20px;
            color: var(--color-text);
            margin-bottom: 6px;
        }

        .kartu-akses p {
            font-size: 13px;
            color: var(--color-text-muted);
        }

        .badge {
            display: inline-block;
            margin-top: 16px;
            padding: 6px 18px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            color: #fff;
        }

        .kartu-akses.admin .badge {
            background: var(--color-primary);
        }

        .kartu-akses.operator .badge {
            background: var(--color-secondary);
        }

        .footer-text {
            text-align: center;
            margin-top: 32px;
            font-size: 12px;
            color: var(--color-text-muted);
        }

        /* Custom select style di SweetAlert */
        .custom-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            color: #1e293b;
            outline: none;
            transition: border-color 0.2s;
        }

        .custom-select:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1);
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="banner-wrapper">
            <img src="/assets/banner.png" alt="PerMen CekeR Banner" class="banner-image">
        </div>

        <div class="pilihan-wrapper">
            <div class="kartu-akses admin" onclick="pilihAkses('admin')">
                <div class="icon-wrapper">
                    <i class="ph-fill ph-shield-check"></i>
                </div>
                <h2>Admin</h2>
                <p>Akses penuh ke seluruh data &amp; pengaturan sistem</p>
                <span class="badge">Masuk sebagai Admin</span>
            </div>

            <div class="kartu-akses operator" onclick="pilihAkses('operator')">
                <div class="icon-wrapper">
                    <i class="ph-fill ph-user-gear"></i>
                </div>
                <h2>Operator</h2>
                <p>Pilih dapur &amp; masukkan password untuk masuk</p>
                <span class="badge">Masuk sebagai Operator</span>
            </div>
        </div>

        <div class="footer-text">
            &copy; <?php echo date('Y'); ?> Created By Muhammad Zulfahmi
        </div>
    </div>

    <script>
        function pilihAkses(role) {
            if (role === 'admin') {
                // Admin: langsung input password
                tampilkanPopupPassword('admin', 'Admin', '#2563eb');
            } else {
                // Operator: pilih dapur dulu, baru password
                tampilkanPopupPilihDapur();
            }
        }

        function tampilkanPopupPilihDapur() {
            Swal.fire({
                title: 'Pilih Dapur',
                html: `
                    <div style="text-align:left; margin-top: 8px;">
                        <label style="font-size:13px; color:#64748b; display:block; margin-bottom:6px;">Nama Dapur</label>
                        <select id="pilih-dapur" class="custom-select">
                            <option value="">-- Pilih Dapur --</option>
                            <option value="opsodong">Sodong</option>
                            <option value="opsariwangi">Sariwangi</option>
                            <option value="opmanonjaya">Manonjaya</option>
                        </select>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Lanjut',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#94a3b8',
                focusConfirm: false,
                preConfirm: () => {
                    const dapur = document.getElementById('pilih-dapur').value;
                    if (!dapur) {
                        Swal.showValidationMessage('Silakan pilih dapur terlebih dahulu');
                        return false;
                    }
                    return dapur;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const dapur = result.value;
                    const labelMap = {
                        'opsodong': 'Sodong',
                        'opsariwangi': 'Sariwangi',
                        'opmanonjaya': 'Manonjaya'
                    };
                    tampilkanPopupPassword(dapur, 'Operator ' + labelMap[dapur], '#16a34a');
                }
            });
        }

        function tampilkanPopupPassword(role, label, warna) {
            Swal.fire({
                title: `Masuk sebagai ${label}`,
                html: `
                    <div style="text-align:left; margin-top: 8px;">
                        <label style="font-size:13px; color:#64748b; display:block; margin-bottom:6px;">Password</label>
                        <input type="password" id="input-password" class="swal2-input" 
                            style="margin:0; width:100%;" placeholder="Masukkan password ${label}" autofocus>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Masuk',
                cancelButtonText: 'Batal',
                confirmButtonColor: warna,
                cancelButtonColor: '#94a3b8',
                focusConfirm: false,
                didOpen: () => {
                    const inputEl = document.getElementById('input-password');
                    inputEl.addEventListener('keyup', (e) => {
                        if (e.key === 'Enter') {
                            Swal.clickConfirm();
                        }
                    });
                },
                preConfirm: () => {
                    const password = document.getElementById('input-password').value;
                    if (!password) {
                        Swal.showValidationMessage('Password tidak boleh kosong');
                        return false;
                    }
                    return password;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    verifikasiPassword(role, result.value, label);
                }
            });
        }

        function verifikasiPassword(role, password, label) {
            Swal.fire({
                title: 'Memverifikasi...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `aksi=verifikasi&role=${encodeURIComponent(role)}&password=${encodeURIComponent(password)}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'sukses') {
                        Swal.fire({
                            icon: 'success',
                            title: `Selamat datang, ${label}!`,
                            text: 'Mengalihkan ke dashboard...',
                            timer: 1200,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = data.redirect;
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal Masuk',
                            text: data.pesan || 'Password salah, silakan coba lagi.',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                })
                .catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Terjadi Kesalahan',
                        text: 'Tidak dapat terhubung ke server, coba lagi.'
                    });
                });
        }
    </script>
</body>

</html>