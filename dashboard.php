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

        .header {
            max-width: 1000px;
            margin: auto;
            margin-bottom: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 6px;
        }

        .header p {
            color: var(--text-muted);
        }

        .topbar {
            max-width: 1000px;
            margin: auto;
            margin-bottom: 25px;

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

        .menu-grid {
            max-width: 1000px;
            margin: auto;

            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
        }

        .menu-card {
            background: white;
            border-radius: 18px;
            text-decoration: none;
            color: inherit;
            padding: 30px 20px;
            text-align: center;

            border: 1px solid var(--border);
            box-shadow: var(--shadow);

            transition: .2s;
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
            margin: auto;
            margin-bottom: 15px;
            font-size: 34px;
        }

        .food .icon-wrap {
            background: var(--primary-light);
            color: var(--primary);
        }

        .addcost .icon-wrap {
            background: var(--accent-light);
            color: var(--accent);
        }

        .ambil .icon-wrap {
            background: var(--secondary-light);
            color: var(--secondary);
        }

        .stok .icon-wrap {
            background: #ecfeff;
            color: #0891b2;
        }

        .pengiriman .icon-wrap,
        .penerimaan .icon-wrap {
            background: var(--purple-light);
            color: var(--purple);
        }

        h3 {
            margin-bottom: 10px;
        }

        p {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.5;
        }
    </style>
</head>

<body>

    <div class="topbar">
        <div class="role-badge">
            Login sebagai :
            <strong><?= ucfirst($role) ?></strong>
        </div>
        <a href="dashboard.php?logout=1" class="logout-btn" onclick="return confirm('Yakin ingin keluar?')">Logout</a>
    </div>

    <div class="header">
        <h1>Bina Usaha Sauyunan</h1>
        <p>Silakan pilih menu yang ingin diakses</p>
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

</body>

</html>