<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/push_helper.php';

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';

    $stmt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions");
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        $result = ['status' => 'error', 'message' => 'Belum ada perangkat terdaftar. Buka aplikasi & aktifkan notifikasi terlebih dahulu.'];
    } else {
        $lokasi_names = [
            'sodong'    => 'Sodong',
            'sariwangi' => 'Sariwangi',
            'manonjaya' => 'Manonjaya',
            'semua'     => 'Semua Dapur'
        ];                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  
        $lokasi    = $_POST['lokasi'] ?? 'sodong';
        $dapurName = $lokasi_names[$lokasi] ?? ucfirst($lokasi);

        if ($type === 'pengambilan') {
            $no        = 'PKB-' . strtoupper(substr(uniqid(), -6));
            $pengambil = trim($_POST['nama_pengambil'] ?? 'Budi Santoso');
            $sppg      = trim($_POST['nama_sppg']      ?? 'MBG-001');
            $title     = 'Pengambilan Barang Baru';
            $body      = "Ada pengambilan barang baru dengan No. Laporan $no oleh $pengambil ($sppg) dari Dapur $dapurName.";
        } elseif ($type === 'pengiriman') {
            $no    = 'SJ-' . strtoupper(substr(uniqid(), -6));
            $title = 'Pengiriman Baru';
            $body  = "Ada pengiriman barang baru dengan No. Surat Jalan $no tujuan Dapur $dapurName.";
        } elseif ($type === 'pengiriman_update') {
            $no    = 'SJ-' . strtoupper(substr(uniqid(), -6));
            $title = 'Pengiriman Diperbarui';
            $body  = "Pengiriman No. $no tujuan Dapur $dapurName telah diperbarui.";
        } else {
            $title = 'Uji Coba Notifikasi';
            $body  = 'Uji coba koneksi notifikasi berhasil! Sistem siap mengirim update pengiriman & pengambilan.';
        }

        try {
            broadcast_push_notification($pdo, $title, $body);
            $result = [
                'status'  => 'success',
                'message' => "Notifikasi berhasil dikirim ke <strong>$count perangkat</strong>.",
                'title'   => $title,
                'body'    => $body,
            ];
        } catch (Exception $e) {
            $result = ['status' => 'error', 'message' => 'Gagal mengirim: ' . $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Push Notifikasi — MBG Logistik</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0f1117;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            color: #e2e8f0;
        }
        .container { width: 100%; max-width: 520px; }
        .card {
            background: #1a1d27;
            border: 1px solid #2d3148;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.5);
        }
        .header { text-align: center; margin-bottom: 32px; }
        .icon-wrap {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #6c63ff, #48cae4);
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
            box-shadow: 0 8px 24px rgba(108,99,255,0.35);
        }
        h1 { font-size: 1.4rem; font-weight: 700; color: #fff; margin-bottom: 4px; }
        .subtitle { font-size: 0.85rem; color: #64748b; }
        .section-label {
            font-size: 0.75rem; font-weight: 600;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: #64748b; margin-bottom: 10px;
        }
        .type-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 24px; }
        .type-btn {
            background: #242740;
            border: 2px solid #2d3148;
            border-radius: 12px;
            padding: 14px 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            display: flex; flex-direction: column; align-items: center; gap: 6px;
        }
        .type-btn:hover { border-color: #6c63ff; background: #2a2d4a; }
        .type-btn.active {
            border-color: #6c63ff;
            background: rgba(108,99,255,0.15);
            box-shadow: 0 0 0 3px rgba(108,99,255,0.2);
        }
        .type-btn .emoji { font-size: 22px; }
        .type-btn .label { font-size: 0.78rem; font-weight: 600; color: #cbd5e1; }
        .type-btn .desc  { font-size: 0.7rem; color: #64748b; }
        .divider { border: none; border-top: 1px solid #2d3148; margin: 20px 0; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 0.8rem; font-weight: 500; color: #94a3b8; margin-bottom: 6px; }
        select, input[type="text"] {
            width: 100%;
            background: #242740; border: 1px solid #2d3148; border-radius: 10px;
            padding: 10px 14px; color: #e2e8f0; font-size: 0.9rem;
            font-family: 'Inter', sans-serif; outline: none; transition: border-color 0.2s;
        }
        select:focus, input[type="text"]:focus { border-color: #6c63ff; }
        select option { background: #1a1d27; }
        .extra-fields { display: none; }
        .extra-fields.visible { display: block; }
        .btn-send {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #6c63ff, #48cae4);
            border: none; border-radius: 12px;
            color: #fff; font-family: 'Inter', sans-serif;
            font-size: 1rem; font-weight: 700;
            cursor: pointer; transition: opacity 0.2s, transform 0.1s;
            margin-top: 8px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-send:hover  { opacity: 0.9; }
        .btn-send:active { transform: scale(0.98); }
        .btn-send:disabled { opacity: 0.5; cursor: not-allowed; }
        .result-box { margin-top: 24px; border-radius: 12px; padding: 16px; animation: fadeIn 0.3s ease; }
        .result-box.success { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); }
        .result-box.error   { background: rgba(239,68,68,0.12);  border: 1px solid rgba(239,68,68,0.3); }
        .result-status { font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; }
        .result-box.success .result-status { color: #4ade80; }
        .result-box.error   .result-status { color: #f87171; }
        .result-message { font-size: 0.82rem; color: #94a3b8; line-height: 1.5; }
        .preview-card {
            margin-top: 12px; background: #242740; border-radius: 10px;
            padding: 12px 14px; border-left: 3px solid #6c63ff;
        }
        .preview-title { font-size: 0.82rem; font-weight: 700; color: #e2e8f0; margin-bottom: 4px; }
        .preview-body  { font-size: 0.78rem; color: #94a3b8; line-height: 1.4; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .back-link {
            display: block; text-align: center; margin-top: 20px;
            font-size: 0.8rem; color: #64748b; text-decoration: none; transition: color 0.2s;
        }
        .back-link:hover { color: #94a3b8; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">
            <div class="icon-wrap">🔔</div>
            <h1>Test Push Notifikasi</h1>
            <p class="subtitle">MBG Logistik — Developer Tool</p>
        </div>

        <form method="POST" id="testForm">
            <p class="section-label">Pilih Jenis Notifikasi</p>
            <div class="type-grid">
                <div class="type-btn active" id="btn-pengambilan" onclick="selectType('pengambilan')">
                    <span class="emoji">📦</span>
                    <span class="label">Pengambilan</span>
                    <span class="desc">Barang baru</span>
                </div>
                <div class="type-btn" id="btn-pengiriman" onclick="selectType('pengiriman')">
                    <span class="emoji">🚚</span>
                    <span class="label">Pengiriman</span>
                    <span class="desc">Surat jalan baru</span>
                </div>
                <div class="type-btn" id="btn-pengiriman_update" onclick="selectType('pengiriman_update')">
                    <span class="emoji">✏️</span>
                    <span class="label">Pengiriman Update</span>
                    <span class="desc">Data diperbarui</span>
                </div>
                <div class="type-btn" id="btn-general" onclick="selectType('general')">
                    <span class="emoji">📣</span>
                    <span class="label">General</span>
                    <span class="desc">Uji koneksi</span>
                </div>
            </div>
            <input type="hidden" name="type" id="typeInput" value="pengambilan">

            <hr class="divider">

            <div class="form-group">
                <label for="lokasi">Dapur / Lokasi</label>
                <select name="lokasi" id="lokasi">
                    <option value="sodong">Sodong</option>
                    <option value="sariwangi">Sariwangi</option>
                    <option value="manonjaya">Manonjaya</option>
                    <option value="semua">Semua Dapur</option>
                </select>
            </div>

            <div class="extra-fields visible" id="fields-pengambilan">
                <div class="form-group">
                    <label for="nama_pengambil">Nama Pengambil</label>
                    <input type="text" name="nama_pengambil" id="nama_pengambil" placeholder="cth: Budi Santoso" value="Budi Santoso">
                </div>
                <div class="form-group">
                    <label for="nama_sppg">No. SPPG</label>
                    <input type="text" name="nama_sppg" id="nama_sppg" placeholder="cth: MBG-001" value="MBG-001">
                </div>
            </div>

            <button type="submit" class="btn-send" id="sendBtn">
                <span>🚀</span> Kirim Notifikasi Test
            </button>
        </form>

        <?php if ($result): ?>
        <div class="result-box <?= $result['status'] ?>">
            <div class="result-status">
                <?= $result['status'] === 'success' ? '✅ Berhasil' : '❌ Gagal' ?>
            </div>
            <div class="result-message"><?= $result['message'] ?></div>
            <?php if ($result['status'] === 'success'): ?>
            <div class="preview-card">
                <div class="preview-title"><?= htmlspecialchars($result['title']) ?></div>
                <div class="preview-body"><?= htmlspecialchars($result['body']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <a href="../dashboard.php" class="back-link">← Kembali ke Dashboard</a>
    </div>
</div>

<script>
    function selectType(type) {
        document.getElementById('typeInput').value = type;
        ['pengambilan','pengiriman','pengiriman_update','general'].forEach(function(t) {
            var btn = document.getElementById('btn-' + t);
            if (btn) btn.classList.toggle('active', t === type);
        });
        var extraFields = document.getElementById('fields-pengambilan');
        extraFields.classList.toggle('visible', type === 'pengambilan');
    }

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type'])): ?>
    selectType('<?= htmlspecialchars($_POST['type']) ?>');
    <?php endif; ?>

    document.getElementById('testForm').addEventListener('submit', function() {
        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> Mengirim...';
    });
</script>
</body>
</html>
