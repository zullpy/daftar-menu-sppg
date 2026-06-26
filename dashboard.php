<?php
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['role'])) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['role'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <title>Pilih Menu - Bina Usaha Sauyunan</title>

    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/regular/style.css">

    <style>
        :root {
            --primary: #2e7d32;
            --primary-dark: #1b5e20;
            --primary-light: #e8f5e9;

            --secondary: #1565c0;
            --secondary-light: #e3f2fd;

            --accent: #ef6c00;
            --accent-light: #fff3e0;

            --purple: #7b1fa2;
            --purple-light: #f3e5f5;

            --bg: #f4f6f8;
            --card-bg: #fff;
            --text-dark: #212529;
            --text-muted: #6c757d;
            --border: #e0e0e0;

            --shadow: 0 2px 10px rgba(0, 0, 0, .06);
            --shadow-hover: 0 8px 24px rgba(0, 0, 0, .12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            padding: 40px 20px;
        }

        .topbar {
            max-width: 1000px;
            margin: 0 auto 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .role-badge {
            background: #dbeafe;
            color: #1d4ed8;
            padding: 10px 15px;
            border-radius: 10px;
            font-weight: 600;
        }

        .logout-btn {
            text-decoration: none;
            background: #dc2626;
            color: white;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 600;
        }

        .header {
            max-width: 1000px;
            margin: 0 auto 36px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 6px;
            color: var(--text-dark);
        }

        .header p {
            color: var(--text-muted);
        }

        /* Section wrapper */
        .section {
            max-width: 1000px;
            margin: 0 auto 36px;
        }

        .section-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--text-muted);
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            font-size: 16px;
        }

        /* Pelayanan SPPG — green accent */
        .section-sppg .section-title {
            color: var(--primary);
            border-color: var(--primary-light);
        }

        /* Monitoring Stok — blue accent */
        .section-stok .section-title {
            color: var(--secondary);
            border-color: var(--secondary-light);
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .menu-card {
            background: white;
            border-radius: 18px;
            text-decoration: none;
            color: inherit;
            padding: 28px 18px;
            text-align: center;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: .2s;
            display: block;
        }

        .menu-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-hover);
        }

        .icon-wrap {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 15px;
            font-size: 34px;
        }

        .menu-card.food .icon-wrap {
            background: var(--primary-light);
            color: var(--primary);
        }

        .menu-card.addcost .icon-wrap {
            background: var(--accent-light);
            color: var(--accent);
        }

        .menu-card.ambil .icon-wrap {
            background: var(--secondary-light);
            color: var(--secondary);
        }

        .menu-card.stok .icon-wrap {
            background: #ecfeff;
            color: #0891b2;
        }

        .menu-card.pengiriman .icon-wrap,
        .menu-card.penerimaan .icon-wrap {
            background: var(--purple-light);
            color: var(--purple);
        }

        .menu-card h3 {
            margin-bottom: 8px;
            font-size: 16px;
            color: var(--text-dark);
        }

        .menu-card p {
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.5;
        }
    </style>
</head>

<body>

    <div class="topbar">
        <div class="role-badge">
            Login sebagai: <strong><?= ucfirst($role) ?></strong>
        </div>
        <a href="dashboard.php?logout=1" class="logout-btn"
            onclick="return confirm('Yakin ingin keluar?')">Logout</a>
    </div>

    <div class="header">
        <h1>Bina Usaha Sauyunan</h1>
        <p>Silakan pilih menu yang ingin diakses</p>
    </div>

    <!-- ===== SEKSI 1: Pelayanan SPPG ===== -->
    <div class="section section-sppg">
        <div class="section-title">
            <i class="ph ph-fork-knife"></i>
            Pelayanan SPPG
        </div>
        <div class="menu-grid">
            <a href="menu.php" class="menu-card food">
                <div class="icon-wrap">
                    <i class="ph ph-cooking-pot"></i>
                </div>
                <h3>Food Cost</h3>
                <p>Kelola belanja bahan makanan.</p>
            </a>

            <a href="addcost/index.php" class="menu-card addcost">
                <div class="icon-wrap">
                    <i class="ph ph-receipt"></i>
                </div>
                <h3>Add Cost</h3>
                <p>Kelola biaya tambahan operasional.</p>
            </a>
        </div>
    </div>

    <!-- ===== SEKSI 2: Monitoring Stok ===== -->
    <div class="section section-stok">
        <div class="section-title">
            <i class="ph ph-chart-bar"></i>
            Monitoring Stok Barang Koperasi di Gudang Transit
        </div>
        <div class="menu-grid">
            <a href="laporan/stok.php" class="menu-card stok">
                <div class="icon-wrap">
                    <i class="ph ph-database"></i>
                </div>
                <h3>Data Stok</h3>
                <p>Monitoring sisa stok barang.</p>
            </a>

            <a href="laporan/pengambilan.php" class="menu-card ambil">
                <div class="icon-wrap">
                    <i class="ph ph-package"></i>
                </div>
                <h3>Pengambilan Barang</h3>
                <p>Laporan pengambilan stok barang.</p>
            </a>

            <?php if ($role == 'admin'): ?>
                <a href="pengiriman/index.php" class="menu-card pengiriman">
                    <div class="icon-wrap">
                        <i class="ph ph-truck"></i>
                    </div>
                    <h3>Pengiriman Barang</h3>
                    <p>Laporan pengiriman barang.</p>
                </a>
            <?php endif; ?>

            <?php if ($role == 'operator'): ?>
                <a href="penerimaan/index.php" class="menu-card penerimaan">
                    <div class="icon-wrap">
                        <i class="ph ph-archive-box"></i>
                    </div>
                    <h3>Penerimaan Barang</h3>
                    <p>Konfirmasi penerimaan barang.</p>
                </a>
            <?php endif; ?>
        </div>
    </div>

</body>

</html>