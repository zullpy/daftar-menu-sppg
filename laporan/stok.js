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

let filtered = STOCK_DATA.slice();

function fmt(n) {
    n = parseFloat(n) || 0;
    if (Number.isInteger(n)) return n.toLocaleString('id-ID');
    return n.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function ucSatuan(s) {
    if (!s) return '';
    return s.toString().trim().toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
}

// Format stok per lokasi: qty_grosir (dus) + sisa qty_eceran (pcs)
function fmtStok(d, lokasi) {
    const L = d.lokasi[lokasi];
    if (!L) return '0';
    const grosir = L.stok_grosir || 0;
    const eceran = L.stok_eceran || 0;
    const isi = d.isi_per_satuan || 0;
    const satG = d.satuan || '-';
    const satE = d.satuan_eceran || satG;

    if (grosir <= 0 && eceran <= 0) return '0';

    if (!isi || isi <= 0) {
        return `${fmt(eceran > 0 ? eceran : grosir)} ${ucSatuan(satE)}`;
    }

    const sisa = eceran - (grosir * isi);
    const sisaRounded = Math.round(sisa * 100) / 100;

    let parts = [];
    if (grosir > 0) parts.push(`${fmt(grosir)} ${ucSatuan(satG)}`);
    if (sisaRounded > 0) parts.push(`${fmt(sisaRounded)} ${ucSatuan(satE)}`);

    return parts.length > 0 ? parts.join(' ') : `0 ${ucSatuan(satE)}`;
}

function totalStokEceran(d) {
    let total = 0;
    VISIBLE_LOKASI.forEach(lok => {
        if (d.lokasi[lok]) total += (d.lokasi[lok].stok_eceran || 0);
    });
    return total;
}

// Format total stok gabungan semua lokasi (hanya untuk admin)
function fmtTotalStok(d) {
    const totalEceran = totalStokEceran(d);
    const isi = d.isi_per_satuan || 0;
    const satG = d.satuan || '-';
    const satE = d.satuan_eceran || satG;

    if (totalEceran <= 0) return `0 ${ucSatuan(satE)}`;
    if (!isi || isi <= 0) return `${fmt(totalEceran)} ${ucSatuan(satE)}`;

    const grosir = Math.floor(totalEceran / isi);
    const sisa = totalEceran - (grosir * isi);
    const sisaRounded = Math.round(sisa * 100) / 100;

    let parts = [];
    if (grosir > 0) parts.push(`${fmt(grosir)} ${ucSatuan(satG)}`);
    if (sisaRounded > 0) parts.push(`${fmt(sisaRounded)} ${ucSatuan(satE)}`);

    return parts.length > 0 ? parts.join(' ') : `0 ${ucSatuan(satE)}`;
}

function getStatus(total) {
    if (total <= 0) return { cls: 's-habis', txt: 'Habis' };
    if (total <= 10) return { cls: 's-menipis', txt: 'Menipis' };
    if (total <= 30) return { cls: 's-rendah', txt: 'Rendah' };
    return { cls: 's-aman', txt: 'Aman' };
}

function buildHeader() {
    const tr = document.getElementById('tableHeadRow');
    let html = '<th>#</th><th>Barang</th>';
    const singleLokasi = VISIBLE_LOKASI.length === 1;
    VISIBLE_LOKASI.forEach(lok => {
        const label = singleLokasi ? 'Stok' : (LOKASI_LABEL[lok] || lok);
        html += `<th class="center ${LOKASI_TH_CLASS[lok] || ''}">${label}</th>`;
    });
    // KOLOM TOTAL HANYA UNTUK ADMIN
    if (VISIBLE_LOKASI.length > 1 && SHOW_TOTAL_COLUMN) {
        html += '<th class="center">Total Stok</th>';
    }
    html += '<th class="center">Status</th>';
    tr.innerHTML = html;

    // Hitung lebar tabel dinamis
    const totalCols = 2 + VISIBLE_LOKASI.length + (VISIBLE_LOKASI.length > 1 && SHOW_TOTAL_COLUMN ? 1 : 0) + 1;
    const table = document.querySelector('.stok-table');
    table.style.minWidth = Math.max(300, totalCols * 76) + 'px';
    setupScrollFade();
}

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
    setTimeout(updateFade, 50);
}

function cellStok(val) {
    if (!val || val === '0') return `<td class="center"><span class="num-zero">0</span></td>`;
    return `<td class="center"><span class="num-stok">${val}</span></td>`;
}

function doFilter() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const st = document.getElementById('statusFilter').value;
    filtered = STOCK_DATA.filter(d => {
        const matchName = d.nama_barang.toLowerCase().includes(q);
        const total = totalStokEceran(d);
        const s = getStatus(total);
        const matchSt = !st || s.txt === st;
        return matchName && matchSt;
    });
    updateMetrics();
    renderTable();
}

function updateMetrics() {
    let totalStok = 0;
    filtered.forEach(d => {
        totalStok += totalStokEceran(d);
    });
    document.getElementById('metricStok').textContent = fmt(totalStok);
    document.getElementById('metricItem').textContent = filtered.length;
}

function renderTable() {
    document.getElementById('countPill').textContent = filtered.length + ' item';
    document.getElementById('tableFooter').textContent = `Menampilkan ${filtered.length} barang`;
    const tbody = document.getElementById('tbody');
    // Hitung colspan sesuai jumlah kolom aktual
    const colCount = 3 + VISIBLE_LOKASI.length + (VISIBLE_LOKASI.length > 1 && SHOW_TOTAL_COLUMN ? 1 : 0) + 1;
    if (!filtered.length) {
        tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center;padding:32px;color:#64748b;">Tidak ada barang yang cocok</td></tr>`;
        return;
    }
    tbody.innerHTML = filtered.map((d, i) => {
        const total = totalStokEceran(d);
        const st = getStatus(total);
        let lokasiCells = '';
        VISIBLE_LOKASI.forEach(lok => {
            lokasiCells += cellStok(fmtStok(d, lok));
        });
        // KOLOM TOTAL HANYA UNTUK ADMIN
        const totalCell = (VISIBLE_LOKASI.length > 1 && SHOW_TOTAL_COLUMN)
            ? `<td class="center"><span class="num-total">${fmtTotalStok(d)}</span></td>`
            : '';
        return `<tr onclick="openBs(${i})">
            <td><span class="row-no">${i + 1}</span></td>
            <td>
                <div class="row-nama">${d.nama_barang}</div>
                <div class="row-satuan">${ucSatuan(d.satuan_eceran)} &bull; ${d.satuan}</div>
            </td>
            ${lokasiCells}
            ${totalCell}
            <td class="center"><span class="item-status ${st.cls}">${st.txt}</span></td>
        </tr>`;
    }).join('');
}

function openBs(idx) {
    const d = filtered[idx];
    const total = totalStokEceran(d);
    const st = getStatus(total);
    document.getElementById('bsName').textContent = d.nama_barang;
    document.getElementById('bsSatuan').textContent = `1 ${d.satuan} = ${fmt(d.isi_per_satuan)} ${d.satuan_eceran}`;

    let html = '';
    VISIBLE_LOKASI.forEach(lok => {
        const L = d.lokasi[lok];
        if (!L) return;
        const numCls = LOKASI_NUM_CLASS[lok] || '';
        html += `
            <div class="bs-lokasi-block">
                <div class="bs-lokasi-title ${numCls}">
                    <i class="ph ph-cooking-pot" style="font-size:13px"></i> ${LOKASI_LABEL[lok] || lok}
                </div>
                <div class="bs-row">
                    <span class="bs-lbl"><i class="ph ph-package" style="font-size:14px"></i>Stok</span>
                    <span class="bs-val">${fmtStok(d, lok)}</span>
                </div>
            </div>`;
    });
    html += `
        <div class="bs-divider"></div>
        <div class="bs-row">
            <span class="bs-lbl"><i class="ph ph-stack" style="font-size:15px"></i>Total Stok</span>
            <span class="bs-val">${fmtTotalStok(d)}</span>
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
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.getElementById('bsOverlay').classList.remove('open');
});

buildHeader();
doFilter();