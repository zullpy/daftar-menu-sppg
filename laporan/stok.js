// Kategori "utama" yang punya warna khusus (case-insensitive).
// Kategori LAIN yang belum ada di sini (misal baru diketik manual di
// Koperasi) otomatis dapet warna dari kategoriStyle() di bawah -
// jadi TIDAK perlu tambah baris di sini setiap ada kategori baru.
const KAT_CLASS = {
    'bahan baku': 'kat-bahan',
    'bahan pokok': 'kat-bahan',
    'bahan pangan olahan': 'kat-bahan',
    'bumbu': 'kat-bumbu',
    'sayuran': 'kat-sayuran',
    'buah-buahan': 'kat-buah',
    'lauk pauk': 'kat-lauk',
    'snack': 'kat-snack',
    'stok': 'kat-stok'
};

// Cache warna hash biar konsisten dalam satu sesi & nggak dihitung ulang tiap render
const _katColorCache = {};

// Kembalikan {cls, style} untuk sebuah kategori.
// Kalau nama kategori dikenal -> pakai class CSS yang sudah didesain.
// Kalau kategori baru/tidak dikenal -> generate warna otomatis dari nama
// kategorinya (deterministik, jadi kategori yang sama selalu dapet warna yang sama).
function kategoriStyle(kategori) {
    const key = (kategori || '').trim().toLowerCase();
    const cls = KAT_CLASS[key];
    if (cls) return { cls, style: '' };

    if (!_katColorCache[key]) {
        let hash = 0;
        for (let i = 0; i < key.length; i++) {
            hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
        }
        const hue = hash % 360;
        _katColorCache[key] = `background:hsl(${hue},72%,94%);color:hsl(${hue},58%,30%)`;
    }
    return { cls: '', style: _katColorCache[key] };
}

function kategoriBadge(kategori) {
    const { cls, style } = kategoriStyle(kategori);
    return `<span class="kat-badge ${cls}" style="${style}">${kategori}</span>`;
}

const LOKASI_TH_CLASS = {
    sodong: 'th-sodong',
    sariwangi: 'th-sariwangi',
    manonjaya: 'th-manonjaya'
};
const LOKASI_NUM_CLASS = {
    sodong: 'c-sodong',
    sariwangi: 'c-sariwangi',
    manonjaya: 'c-manonjaya'
};

let mode = 'grosir'; // 'grosir' | 'eceran'
let filtered = STOCK_DATA.slice();

function fmt(n) {
    n = parseFloat(n) || 0;
    if (Number.isInteger(n)) return n.toLocaleString('id-ID');
    return n.toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    });
}

// Helper ambil stok satu lokasi sesuai mode aktif
function stokLokasi(d, lokasi) {
    const L = d.lokasi[lokasi];
    if (!L) return 0;
    return mode === 'eceran' ? L.stok_eceran : L.stok_grosir;
}

function totalStok(d) {
    return mode === 'eceran' ? d.total_stok_eceran : d.total_stok_grosir;
}

function satuanAktif(d) {
    return mode === 'eceran' ? d.satuan_eceran : d.satuan;
}

function setMode(m) {
    mode = m;
    document.querySelectorAll('.mode-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.mode === m);
    });
    doFilter();
}

function getStatus(total) {
    if (total < 0) return { cls: 's-habis', txt: 'Minus' };
    if (total === 0) return { cls: 's-habis', txt: 'Habis' };
    if (total <= 10) return { cls: 's-menipis', txt: 'Menipis' };
    return { cls: 's-aman', txt: 'Aman' };
}

function buildKategoriFilter() {
    const sel = document.getElementById('kategoriFilter');
    // Ambil kategori unik dari data, urut alfabet -> otomatis ikut
    // kategori baru yang diketik manual di Koperasi, tanpa perlu edit kode.
    const kategoriList = Array.from(new Set(STOCK_DATA.map(d => d.kategori))).sort((a, b) =>
        a.localeCompare(b, 'id')
    );
    sel.innerHTML = '<option value="">Semua Kategori</option>' +
        kategoriList.map(k => `<option value="${k}">${k}</option>`).join('');
}

function buildHeader() {
    const tr = document.getElementById('tableHeadRow');
    let html = '<th>#</th><th>Barang</th>';
    const singleLokasi = VISIBLE_LOKASI.length === 1;
    VISIBLE_LOKASI.forEach(lok => {
        const label = singleLokasi ? 'Stok' : (LOKASI_LABEL[lok] || lok);
        html += `<th class="center ${LOKASI_TH_CLASS[lok] || ''}">${label}</th>`;
    });
    if (VISIBLE_LOKASI.length > 1) html += '<th class="center">Total</th>';
    html += '<th class="center">Status</th>';
    tr.innerHTML = html;

    // Lebar tabel dihitung sesuai jumlah kolom AKTUAL (bukan fixed 560px),
    // jadi operator (kolom sedikit) nggak dipaksa scroll horizontal.
    const totalCols = 2 + VISIBLE_LOKASI.length + (VISIBLE_LOKASI.length > 1 ? 1 : 0) + 1;
    const table = document.querySelector('.stok-table');
    table.style.minWidth = Math.max(300, totalCols * 76) + 'px';

    setupScrollFade();
}

// Sembunyikan fade indicator kalau tabel tidak perlu scroll,
// atau kalau scroll sudah mentok kanan.
function setupScrollFade() {
    const scrollEl = document.querySelector('.table-scroll');
    const wrapEl = document.querySelector('.table-wrap');
    if (!scrollEl || !wrapEl) return;

    function updateFade() {
        const needsScroll = scrollEl.scrollWidth > scrollEl.clientWidth + 1;
        const atEnd = scrollEl.scrollLeft + scrollEl.clientWidth >= scrollEl.scrollWidth - 1;
        wrapEl.classList.toggle('at-scroll-end', !needsScroll || atEnd);
    }

    scrollEl.addEventListener('scroll', updateFade, { passive: true });
    window.addEventListener('resize', updateFade);
    updateFade();
    // Recheck sesaat setelah render (lebar konten baru terukur akurat)
    setTimeout(updateFade, 50);
}

function cellStok(val) {
    if (!val || val <= 0) return `<td class="center"><span class="num-zero">0</span></td>`;
    return `<td class="center"><span class="num-stok">${fmt(val)}</span></td>`;
}

function doFilter() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const kat = document.getElementById('kategoriFilter').value;
    const st = document.getElementById('statusFilter').value;
    filtered = STOCK_DATA.filter(d => {
        const matchName = d.nama_barang.toLowerCase().includes(q);
        const matchKat = !kat || d.kategori === kat;
        const s = getStatus(totalStok(d));
        const matchSt = !st || s.txt === st;
        return matchName && matchKat && matchSt;
    });
    updateMetrics();
    renderTable();
}

function updateMetrics() {
    let masuk = 0, keluar = 0, sisa = 0;
    filtered.forEach(d => {
        VISIBLE_LOKASI.forEach(lok => {
            const L = d.lokasi[lok];
            if (!L) return;
            masuk += mode === 'eceran' ? L.masuk_eceran : L.masuk_grosir;
            keluar += mode === 'eceran' ? L.keluar_eceran : L.keluar_grosir;
        });
        sisa += totalStok(d);
    });
    document.getElementById('metricMasuk').textContent = fmt(masuk);
    document.getElementById('metricKeluar').textContent = fmt(keluar);
    document.getElementById('metricSisa').textContent = fmt(sisa);

    const s = getStatus(sisa);
    const sisaEl = document.getElementById('metricSisa');
    const statusEl = document.getElementById('metricStatus');
    const iconEl = document.getElementById('metricIcon');
    sisaEl.className = 'metric-val ' + (s.cls === 's-habis' ? 'val-danger' : (s.cls === 's-menipis' ? 'val-menipis' : 'val-aman'));
    statusEl.className = 'metric-status ' + (s.cls === 's-habis' ? 'status-danger' : (s.cls === 's-menipis' ? 'status-menipis' : 'status-aman'));
    statusEl.textContent = s.txt;
    iconEl.className = 'metric-icon ' + (s.cls === 's-habis' ? 'icon-danger' : (s.cls === 's-menipis' ? 'icon-menipis' : 'icon-aman'));
}

function renderTable() {
    document.getElementById('countPill').textContent = filtered.length + ' item';
    document.getElementById('tableFooter').textContent = `Menampilkan ${filtered.length} barang`;

    const tbody = document.getElementById('tbody');
    const colCount = 3 + VISIBLE_LOKASI.length + (VISIBLE_LOKASI.length > 1 ? 1 : 0);

    if (!filtered.length) {
        tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center;padding:32px;color:#64748b;">Tidak ada barang yang cocok</td></tr>`;
        return;
    }

    tbody.innerHTML = filtered.map((d, i) => {
        const total = totalStok(d);
        const st = getStatus(total);

        let lokasiCells = '';
        VISIBLE_LOKASI.forEach(lok => {
            lokasiCells += cellStok(stokLokasi(d, lok));
        });

        const totalCell = VISIBLE_LOKASI.length > 1
            ? `<td class="center"><span class="num-total">${fmt(total)}</span></td>`
            : '';

        return `<tr onclick="openBs(${i})">
                    <td><span class="row-no">${i + 1}</span></td>
                    <td>
                        <div class="row-nama">${d.nama_barang}</div>
                        <div class="row-satuan">${satuanAktif(d)} &bull; ${kategoriBadge(d.kategori)}</div>
                    </td>
                    ${lokasiCells}
                    ${totalCell}
                    <td class="center"><span class="item-status ${st.cls}">${st.txt}</span></td>
                </tr>`;
    }).join('');
}

function openBs(idx) {
    const d = filtered[idx];
    const total = totalStok(d);
    const st = getStatus(total);

    document.getElementById('bsName').textContent = d.nama_barang;
    document.getElementById('bsSatuan').textContent = 'Satuan: ' + satuanAktif(d) +
        (d.isi_per_satuan ? ` (1 ${d.satuan} = ${fmt(d.isi_per_satuan)} ${d.satuan_eceran})` : '');

    let html = '';
    VISIBLE_LOKASI.forEach(lok => {
        const L = d.lokasi[lok];
        if (!L) return;
        const masuk = mode === 'eceran' ? L.masuk_eceran : L.masuk_grosir;
        const keluar = mode === 'eceran' ? L.keluar_eceran : L.keluar_grosir;
        const stok = mode === 'eceran' ? L.stok_eceran : L.stok_grosir;
        const numCls = LOKASI_NUM_CLASS[lok] || '';
        html += `
                <div class="bs-lokasi-block">
                    <div class="bs-lokasi-title ${numCls}">
                        <i class="ph ph-cooking-pot" style="font-size:13px"></i> ${LOKASI_LABEL[lok] || lok}
                    </div>
                    <div class="bs-row">
                        <span class="bs-lbl"><i class="ph ph-arrow-circle-down" style="font-size:14px;color:#166534"></i>Masuk</span>
                        <span class="bs-val" style="color:#166534">+${fmt(masuk)} ${satuanAktif(d)}</span>
                    </div>
                    <div class="bs-row">
                        <span class="bs-lbl"><i class="ph ph-arrow-circle-up" style="font-size:14px;color:#991b1b"></i>Keluar</span>
                        <span class="bs-val" style="color:#991b1b">-${fmt(keluar)} ${satuanAktif(d)}</span>
                    </div>
                    <div class="bs-row">
                        <span class="bs-lbl"><i class="ph ph-package" style="font-size:14px"></i>Stok</span>
                        <span class="bs-val">${fmt(stok)} ${satuanAktif(d)}</span>
                    </div>
                </div>`;
    });

    html += `
            <div class="bs-divider"></div>
            <div class="bs-row">
                <span class="bs-lbl"><i class="ph ph-tag" style="font-size:15px"></i>Kategori</span>
                <span class="bs-val">${kategoriBadge(d.kategori)}</span>
            </div>
            <div class="bs-row">
                <span class="bs-lbl"><i class="ph ph-stack" style="font-size:15px"></i>Total Stok</span>
                <span class="bs-val">${fmt(total)} ${satuanAktif(d)}</span>
            </div>
            <div class="bs-row">
                <span class="bs-lbl"><i class="ph ph-info" style="font-size:15px"></i>Status</span>
                <span class="item-status ${st.cls}">${st.txt}</span>
            </div>`;

    document.getElementById('bsContent').innerHTML = html;
    document.getElementById('bsOverlay').classList.add('open');
}

function closeBs(e) {
    if (e.target === document.getElementById('bsOverlay')) {
        document.getElementById('bsOverlay').classList.remove('open');
    }
}

// Keyboard support
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.getElementById('bsOverlay').classList.remove('open');
});

// Init
buildKategoriFilter();
buildHeader();
doFilter();