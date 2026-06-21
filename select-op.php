<?php
// selection.php
// Halaman menu pilihan utama
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Menu - Bina Usaha Sauyunan</title>
    <link
        rel="stylesheet"
        type="text/css"
        href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/regular/style.css" />
    <link
        rel="stylesheet"
        type="text/css"
        href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/fill/style.css" />
    </link>
    <style>
        :root {
            --primary: #2e7d32;
            --primary-dark: #1b5e20;
            --primary-light: #e8f5e9;
            --secondary: #1565c0;
            --secondary-light: #e3f2fd;
            --accent: #ef6c00;
            --accent-light: #fff3e0;
            --bg: #f4f6f8;
            --card-bg: #ffffff;
            --text-dark: #212529;
            --text-muted: #6c757d;
            --border: #e0e0e0;
            --shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            --shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .header h1 {
            font-size: 26px;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .header p {
            color: var(--text-muted);
            font-size: 14px;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            max-width: 900px;
            width: 100%;
        }

        .menu-card {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 32px 24px;
            text-align: center;
            text-decoration: none;
            color: var(--text-dark);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
        }

        .menu-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-hover);
        }

        .menu-card .icon-wrap {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
        }

        .menu-card.food-cost .icon-wrap {
            background: var(--primary-light);
            color: var(--primary);
        }

        .menu-card.add-cost .icon-wrap {
            background: var(--accent-light);
            color: var(--accent);
        }

        .menu-card.pengambilan .icon-wrap {
            background: var(--secondary-light);
            color: var(--secondary);
        }

        .menu-card h3 {
            font-size: 18px;
            font-weight: 600;
        }

        .menu-card p {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .menu-card .badge {
            margin-top: 4px;
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .menu-card.food-cost .badge {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .menu-card.add-cost .badge {
            background: var(--accent-light);
            color: var(--accent);
        }

        .menu-card.pengambilan .badge {
            background: var(--secondary-light);
            color: var(--secondary);
        }

        @media (max-width:480px) {
            .menu-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="header">
        <h1>Bina Usaha Sauyunan</h1>
        <p>Silakan pilih menu yang ingin diakses</p>
    </div>

    <div class="menu-grid">

        <a href="menu.php" class="menu-card food-cost">
            <div class="icon-wrap"><i class="ph ph-cooking-pot"></i></div>
            <h3>Food Cost</h3>
            <p>Kelola belanja bahan makanan, item, dan rincian biaya menu harian.</p>
            <span class="badge">Belanja Bahan</span>
        </a>

        <a href="pengambilan-barang.php" class="menu-card pengambilan">
            <div class="icon-wrap"><i class="ph ph-package"></i></div>
            <h3>Pengambilan Stok Barang</h3>
            <p>Lihat laporan dan riwayat pengambilan stok barang dari gudang.</p>
            <span class="badge">Laporan Stok</span>
        </a>

    </div>

</body>

</html>