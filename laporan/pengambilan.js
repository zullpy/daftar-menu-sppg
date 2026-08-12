function setAutoJam() {
    const now = new Date();
    const jam = now.toTimeString().split(' ')[0].substring(0, 5);
    document.getElementById('jam_pengambilan').value = jam;
}

function toggleAccordion(target) {
    let header = null;
    let body = null;

    if (typeof target === 'string') {
        body = document.getElementById(target);
        if (body) header = body.previousElementSibling;
    } else if (target && target.nodeType) {
        header = target.closest('.pengambil-header, .accordion-header, .date-header') || target;
        body = header.nextElementSibling;
    }

    if (header) header.classList.toggle('open');
    if (body) body.classList.toggle('active');
}

function toggleFilter() {
    const bar = document.getElementById('filterBar');
    const btn = document.getElementById('filterToggleBtn');
    bar.classList.toggle('open');
    btn.classList.toggle('open');
}

function openModal() {
    document.getElementById('modalTambah').classList.add('active');
    document.body.style.overflow = 'hidden';
    setAutoJam();
    document.getElementById('tanggal_pengambilan').value = new Date().toISOString().split('T')[0];
    const container = document.getElementById('barangContainer');
    if (container.children.length === 0) {
        addBarangRow();
    }
}

function closeModal() {
    document.getElementById('modalTambah').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('formTambah').reset();
    document.getElementById('barangContainer').innerHTML = '';
}

// ============================================
// ============================================
// ✅ FUNGSI TOAST NOTIFICATION (BARU)
// ============================================
function showToast(message, type = 'success') {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    let icon = '';
    if (type === 'success') icon = '<span class="toast-icon" style="color:#2e7d32;"><i class="ph-bold ph-check-circle"></i></span>';
    else if (type === 'warning') icon = '<span class="toast-icon" style="color:#ed6c02;"><i class="ph-bold ph-warning"></i></span>';
    else if (type === 'error') icon = '<span class="toast-icon" style="color:#d32f2f;"><i class="ph-bold ph-x-circle"></i></span>';

    toast.innerHTML = `${icon} <span>${message}</span>`;
    container.appendChild(toast);

    // Hilang otomatis setelah 3.5 detik
    setTimeout(() => {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

// ============================================
// ✅ AUTOCOMPLETE NAMA BARANG DARI STOK (BARU)
// ============================================
let debounceTimerStok = null;

function cariBarangStok(input) {
    const keyword = input.value.trim();
    const wrap = input.closest('.autocomplete-wrap');
    const dropdown = wrap.querySelector('.autocomplete-dropdown');
    const infoEl = wrap.querySelector('.stok-info');
    const row = input.closest('.barang-row');

    // Reset stok tersimpan & tampilan info tiap kali user ngetik ulang (belum pilih dari list lagi)
    delete input.dataset.stok;
    delete input.dataset.satuan;
    delete input.dataset.satuanGrosir;
    delete input.dataset.sisaGrosir;
    delete input.dataset.satuanEceran;
    delete input.dataset.sisaEceran;
    delete input.dataset.isiPerSatuan;
    infoEl.innerHTML = '';
    infoEl.className = 'stok-info';
    if (row) {
        const wrapSelector = row.querySelector('.unit-selector-wrap');
        if (wrapSelector) wrapSelector.innerHTML = '';
    }

    if (keyword.length < 2) {
        dropdown.innerHTML = '';
        dropdown.classList.remove('show');
        return;
    }

    clearTimeout(debounceTimerStok);
    debounceTimerStok = setTimeout(() => {
        const lokasiInput = document.querySelector('input[name="lokasi"], select[name="lokasi"]');
        const lokasi = lokasiInput ? lokasiInput.value : 'semua';

        fetch(`../database/api-search-stok.php?q=${encodeURIComponent(keyword)}&lokasi=${encodeURIComponent(lokasi)}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    renderDropdownStok(input, dropdown, data.data);
                }
            })
            .catch(() => {
                dropdown.innerHTML = '';
                dropdown.classList.remove('show');
            });
    }, 300);
}

function renderDropdownStok(input, dropdown, items) {
    if (!items || items.length === 0) {
        dropdown.innerHTML = `<div class="autocomplete-empty">Tidak ada barang di stok yang cocok</div>`;
        dropdown.classList.add('show');
        return;
    }

    dropdown.innerHTML = items.map(item => {
        const satuanEceran = item.satuan_eceran || '';
        const sisaEceran = (item.sisa_eceran !== undefined && item.sisa_eceran !== null) ? item.sisa_eceran : '';
        const labelStok = satuanEceran
            ? `${item.sisa_stok} ${item.satuan} (≈${sisaEceran} ${satuanEceran})`
            : `${item.sisa_stok} ${item.satuan}`;
        return `
        <div class="autocomplete-item" 
             onmousedown="event.preventDefault(); pilihBarangStok(this)"
             data-nama="${item.nama_barang.replace(/"/g, '&quot;')}"
             data-satuan="${item.satuan.replace(/"/g, '&quot;')}"
             data-stok="${item.sisa_stok}"
             data-satuan-grosir="${(item.satuan_grosir || item.satuan).replace(/"/g, '&quot;')}"
             data-sisa-grosir="${item.sisa_grosir}"
             data-satuan-eceran="${satuanEceran.replace(/"/g, '&quot;')}"
             data-sisa-eceran="${sisaEceran}"
             data-isi-per-satuan="${item.isi_per_satuan || ''}">
            <span class="ai-nama">${item.nama_barang}</span>
            <span class="ai-stok">${labelStok}</span>
        </div>
    `;
    }).join('');
    dropdown.classList.add('show');
}

// Update tampilan sisa stok yang PERSISTEN di bawah input.
function tampilkanInfoStok(wrap, nama, stok, satuan, sisaEceran, satuanEceran) {
    const infoEl = wrap.querySelector('.stok-info');
    const acuan = (sisaEceran !== undefined && sisaEceran !== null && satuanEceran) ? sisaEceran : stok;
    let level = 'aman';
    if (acuan <= 0) level = 'habis';
    else if (acuan <= 10) level = 'menipis';

    let label;
    if (acuan <= 0) {
        label = 'Stok habis';
    } else if (satuanEceran && sisaEceran !== undefined && sisaEceran !== null) {
        label = `Sisa stok: <strong>${stok} ${satuan}</strong> (setara ${sisaEceran} ${satuanEceran})`;
    } else {
        label = `Sisa stok: <strong>${stok} ${satuan}</strong>`;
    }

    infoEl.className = 'stok-info stok-' + level;
    infoEl.innerHTML = `<i class="ph-fill ph-package"></i> ${label}`;
}

function renderUnitSelector(row, data) {
    const wrap = row.querySelector('.unit-selector-wrap');
    if (!wrap) return;

    const satuanGrosir = data.satuanGrosir || data.satuan || '';
    const satuanEceran = data.satuanEceran || '';

    if (satuanEceran && satuanEceran.toLowerCase() !== satuanGrosir.toLowerCase()) {
        wrap.innerHTML = `
            <button type="button" class="btn-unit-pill active" data-unit-type="grosir" onclick="switchUnitMode(this, 'grosir')">
                <i class="ph ph-package"></i> Grosir (${satuanGrosir})
            </button>
            <button type="button" class="btn-unit-pill" data-unit-type="eceran" onclick="switchUnitMode(this, 'eceran')">
                <i class="ph ph-tag"></i> Eceran (${satuanEceran})
            </button>
        `;
    } else {
        wrap.innerHTML = '';
    }
}

function switchUnitMode(btn, mode) {
    const row = btn.closest('.barang-row');
    const inputNama = row.querySelector('.input-nama-barang');
    const satuanInput = row.querySelector('input[name="satuan[]"]');
    const qtyInput = row.querySelector('input[name="qty[]"]');
    const wrap = row.querySelector('.autocomplete-wrap');
    const pills = row.querySelectorAll('.btn-unit-pill');

    pills.forEach(p => p.classList.remove('active'));
    btn.classList.add('active');

    const satG = inputNama.dataset.satuanGrosir || inputNama.dataset.satuan || '';
    const satE = inputNama.dataset.satuanEceran || '';
    const sisaG = parseFloat(inputNama.dataset.sisaGrosir ?? inputNama.dataset.stok);
    const sisaE = (inputNama.dataset.sisaEceran !== '' && inputNama.dataset.sisaEceran !== undefined)
        ? parseFloat(inputNama.dataset.sisaEceran) : null;

    if (mode === 'eceran' && satE) {
        if (satuanInput) satuanInput.value = satE;
        if (qtyInput) qtyInput.placeholder = `Qty (${satE})`;

        const infoEl = wrap.querySelector('.stok-info');
        if (infoEl && sisaE !== null) {
            let level = 'aman';
            if (sisaE <= 0) level = 'habis';
            else if (sisaE <= 10) level = 'menipis';
            infoEl.className = 'stok-info stok-' + level;
            let infoKonversi = (sisaG > 0) ? ` (Grosir: ${sisaG} ${satG})` : '';
            infoEl.innerHTML = `<i class="ph-fill ph-tag"></i> Mode Eceran: <strong>${sisaE} ${satE}</strong>${infoKonversi}`;
        }
        showToast(`Mode <strong>Eceran (${satE})</strong> aktif. Sisa stok: ${sisaE} ${satE}`, 'success');
    } else if (mode === 'grosir') {
        if (satuanInput) satuanInput.value = satG;
        if (qtyInput) qtyInput.placeholder = `Qty (${satG})`;

        const infoEl = wrap.querySelector('.stok-info');
        if (infoEl) {
            let level = 'aman';
            if (sisaG <= 0) level = 'habis';
            else if (sisaG <= 10) level = 'menipis';
            infoEl.className = 'stok-info stok-' + level;
            let infoKonversi = (satE && sisaE !== null) ? ` (setara ${sisaE} ${satE})` : '';
            infoEl.innerHTML = `<i class="ph-fill ph-package"></i> Mode Grosir: <strong>${sisaG} ${satG}</strong>${infoKonversi}`;
        }
        showToast(`Mode <strong>Grosir (${satG})</strong> aktif. Sisa stok: ${sisaG} ${satG}`, 'success');
    }
}

function syncUnitPills(satuanInput) {
    const row = satuanInput.closest('.barang-row');
    const inputNama = row.querySelector('.input-nama-barang');
    if (!inputNama) return;

    const val = satuanInput.value.trim().toLowerCase();
    const satG = (inputNama.dataset.satuanGrosir || inputNama.dataset.satuan || '').toLowerCase();
    const satE = (inputNama.dataset.satuanEceran || '').toLowerCase();

    const pillGrosir = row.querySelector('.btn-unit-pill[data-unit-type="grosir"]');
    const pillEceran = row.querySelector('.btn-unit-pill[data-unit-type="eceran"]');

    if (!pillGrosir || !pillEceran) return;

    if (val === satE && val !== satG) {
        pillGrosir.classList.remove('active');
        pillEceran.classList.add('active');
    } else if (val === satG) {
        pillEceran.classList.remove('active');
        pillGrosir.classList.add('active');
    }
}

function pilihBarangStok(el) {
    const wrap = el.closest('.autocomplete-wrap');
    const input = wrap.querySelector('.input-nama-barang');
    const row = wrap.closest('.barang-row');
    const dropdown = wrap.querySelector('.autocomplete-dropdown');

    const nama = el.dataset.nama;
    const satuan = el.dataset.satuan;
    const stok = parseFloat(el.dataset.stok);
    const satuanEceran = el.dataset.satuanEceran || '';
    const sisaEceran = el.dataset.sisaEceran !== undefined && el.dataset.sisaEceran !== '' ? parseFloat(el.dataset.sisaEceran) : null;
    const satuanGrosir = el.dataset.satuanGrosir || satuan;
    const sisaGrosir = el.dataset.sisaGrosir !== undefined && el.dataset.sisaGrosir !== '' ? parseFloat(el.dataset.sisaGrosir) : stok;

    input.value = nama;
    input.dataset.stok = stok;
    input.dataset.satuan = satuan;
    input.dataset.satuanGrosir = satuanGrosir;
    input.dataset.sisaGrosir = sisaGrosir;
    input.dataset.satuanEceran = satuanEceran;
    input.dataset.sisaEceran = sisaEceran !== null ? sisaEceran : '';
    if (el.dataset.isiPerSatuan) {
        input.dataset.isiPerSatuan = el.dataset.isiPerSatuan;
    } else {
        delete input.dataset.isiPerSatuan;
    }

    const satuanInput = row.querySelector('input[name="satuan[]"]');
    if (satuanInput) satuanInput.value = satuanGrosir;

    dropdown.innerHTML = '';
    dropdown.classList.remove('show');

    tampilkanInfoStok(wrap, nama, stok, satuan, sisaEceran, satuanEceran);
    renderUnitSelector(row, { satuanGrosir, satuanEceran, sisaGrosir, sisaEceran });

    const acuan = (sisaEceran !== null && satuanEceran !== '') ? sisaEceran : stok;
    if (acuan <= 0) {
        showToast(`Stok <strong>${nama}</strong> HABIS! (Sisa: ${stok} ${satuan})`, 'error');
    } else if (acuan <= 10) {
        showToast(`Stok <strong>${nama}</strong> menipis. Tersisa: ${stok} ${satuan}`, 'warning');
    } else {
        showToast(`Stok <strong>${nama}</strong> tersedia: ${stok} ${satuan}`, 'success');
    }
}

function sembunyikanDropdown(input) {
    const dropdown = input.closest('.autocomplete-wrap').querySelector('.autocomplete-dropdown');
    dropdown.innerHTML = '';
    dropdown.classList.remove('show');
}

// ============================================
// ✅ CEK STOK VIA TOAST (BARU)
// ============================================
function cekStokBarang(input) {
    if (input.dataset.stok !== undefined) return;

    const namaBarang = input.value.trim();
    if (!namaBarang) return;

    const wrap = input.closest('.autocomplete-wrap');
    const lokasiInput = document.querySelector('input[name="lokasi"], select[name="lokasi"]');
    const lokasi = lokasiInput ? lokasiInput.value : 'semua';

    fetch(`../database/api-cek-stok.php?nama=${encodeURIComponent(namaBarang)}&lokasi=${encodeURIComponent(lokasi)}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const sisa = data.sisa_stok;
                const satuan = data.satuan || '';
                const satuanEceran = data.satuan_eceran || '';
                const sisaEceran = (data.sisa_eceran !== undefined && data.sisa_eceran !== null && satuanEceran !== '') ? data.sisa_eceran : null;

                input.dataset.stok = sisa;
                input.dataset.satuan = satuan;
                input.dataset.satuanGrosir = data.satuan_grosir || satuan;
                input.dataset.sisaGrosir = data.sisa_grosir !== undefined ? data.sisa_grosir : sisa;
                input.dataset.satuanEceran = satuanEceran;
                input.dataset.sisaEceran = sisaEceran !== null ? sisaEceran : '';
                if (data.isi_per_satuan) {
                    input.dataset.isiPerSatuan = data.isi_per_satuan;
                } else {
                    delete input.dataset.isiPerSatuan;
                }

                const row = wrap.closest('.barang-row');
                const satuanInput = row ? row.querySelector('input[name="satuan[]"]') : null;
                if (satuanInput && !satuanInput.value.trim()) {
                    satuanInput.value = data.satuan_grosir || satuan;
                }

                tampilkanInfoStok(wrap, namaBarang, sisa, satuan, sisaEceran, satuanEceran);
                if (row) {
                    renderUnitSelector(row, {
                        satuanGrosir: data.satuan_grosir || satuan,
                        satuanEceran: satuanEceran,
                        sisaGrosir: data.sisa_grosir !== undefined ? data.sisa_grosir : sisa,
                        sisaEceran: sisaEceran
                    });
                }

                const acuan = (sisaEceran !== null && satuanEceran !== '') ? sisaEceran : sisa;
                if (acuan <= 0) {
                    showToast(`Stok <strong>${namaBarang}</strong> HABIS! (Sisa: ${sisa} ${satuan})`, 'error');
                } else if (acuan <= 10) {
                    showToast(`Stok <strong>${namaBarang}</strong> menipis. Tersisa: ${sisa} ${satuan}`, 'warning');
                } else {
                    showToast(`Stok <strong>${namaBarang}</strong> tersedia: ${sisa} ${satuan}`, 'success');
                }
            } else {
                showToast('Gagal mengecek stok barang.', 'error');
            }
        })
        .catch(() => {
            showToast('Error koneksi saat cek stok.', 'error');
        });
}

// ============================================
// DYNAMIC BARANG ROWS
// ============================================
let barangIndex = 0;
function addBarangRow() {
    const container = document.getElementById('barangContainer');
    const html = `
        <div class="barang-row" id="barang-${barangIndex}">
            <div class="form-group autocomplete-wrap">
                <input type="text" name="nama_barang[]" class="input-nama-barang" placeholder="Nama Barang" required
                    autocomplete="off"
                    oninput="cariBarangStok(this)"
                    onfocus="cariBarangStok(this)"
                    onblur="setTimeout(() => { sembunyikanDropdown(this); cekStokBarang(this); }, 200)">
                <div class="autocomplete-dropdown"></div>
                <div class="stok-info"></div>
                <div class="unit-selector-wrap"></div>
            </div>
            <div class="barang-row-inputs">
                <div class="form-group">
                    <input type="number" name="qty[]" placeholder="Qty" step="0.01" required inputmode="decimal">
                </div>
                <div class="form-group">
                    <input type="text" name="satuan[]" placeholder="Satuan" required oninput="syncUnitPills(this)">
                </div>
            </div>
            <div class="form-group">
                <select name="jenis[]" required>
                    <option value="foodcost">Food Cost</option>
                    <option value="addcost">Add Cost</option>
                </select>
            </div>
            <button type="button" class="btn btn-danger" onclick="removeBarangRow(${barangIndex})">
                <i class="ph ph-trash"></i>
            </button>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    barangIndex++;
}

function removeBarangRow(id) {
    const el = document.getElementById(`barang-${id}`);
    if (el) el.remove();
}

// ============================================
// SUBMIT FORM
// ============================================
document.getElementById('formTambah').addEventListener('submit', function (e) {
    e.preventDefault();

    // Validasi stok barang di frontend
    const rows = document.querySelectorAll('.barang-row');
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const inputNama = row.querySelector('input[name="nama_barang[]"]');
        const inputQty = row.querySelector('input[name="qty[]"]');
        const inputSatuan = row.querySelector('input[name="satuan[]"]');
        if (inputNama && inputQty) {
            const nama = inputNama.value.trim();
            const qty = parseFloat(inputQty.value) || 0;
            const satuanDipakai = (inputSatuan ? inputSatuan.value.trim() : '') || inputNama.dataset.satuan || '';

            const satuanGrosir = inputNama.dataset.satuanGrosir || inputNama.dataset.satuan || '';
            const satuanEceran = inputNama.dataset.satuanEceran || '';
            const sisaGrosir = parseFloat(inputNama.dataset.sisaGrosir ?? inputNama.dataset.stok);
            const sisaEceran = (inputNama.dataset.sisaEceran !== '' && inputNama.dataset.sisaEceran !== undefined && satuanEceran !== '')
                ? parseFloat(inputNama.dataset.sisaEceran) : null;

            const modeEceran = satuanEceran
                && satuanDipakai.toLowerCase() === satuanEceran.toLowerCase()
                && satuanDipakai.toLowerCase() !== satuanGrosir.toLowerCase();

            const stokBanding = modeEceran ? sisaEceran : sisaGrosir;
            const satuanBanding = modeEceran ? satuanEceran : (satuanGrosir || satuanDipakai);
            const labelMode = modeEceran ? 'Eceran' : 'Grosir';

            if (stokBanding === null || isNaN(stokBanding) || stokBanding <= 0) {
                Swal.fire('Stok Tidak Ada', `Stok untuk barang "${nama}" tidak ada atau habis!`, 'error');
                return;
            }

            if (qty > stokBanding) {
                Swal.fire('Stok Tidak Cukup', `Jumlah pengambilan ${labelMode} untuk barang "${nama}" (${qty} ${satuanBanding}) melebihi stok yang ada! (Sisa stok ${labelMode}: ${stokBanding} ${satuanBanding})`, 'error');
                return;
            }
        }
    }

    const formData = new FormData(this);
    const data = {
        nama_pengambil: formData.get('nama_pengambil'),
        nama_sppg: formData.get('nama_sppg'),
        tanggal_pengambilan: formData.get('tanggal_pengambilan'),
        jam_pengambilan: formData.get('jam_pengambilan'),
        no_kontak: formData.get('no_kontak'),
        lokasi: formData.get('lokasi'),
        barang: []
    };

    const namaBarangs = formData.getAll('nama_barang[]');
    const qtys = formData.getAll('qty[]');
    const satuans = formData.getAll('satuan[]');
    const jenisList = formData.getAll('jenis[]');

    for (let i = 0; i < namaBarangs.length; i++) {
        if (namaBarangs[i]) {
            data.barang.push({
                nama_barang: namaBarangs[i],
                qty: qtys[i],
                satuan: satuans[i],
                jenis: jenisList[i] || 'foodcost'
            });
        }
    }

    if (data.barang.length === 0) {
        Swal.fire('Error', 'Minimal tambahkan 1 barang!', 'error');
        return;
    }

    Swal.fire({
        title: 'Menyimpan...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('../database/api-tambah-pengambilan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
        .then(res => res.json())
        .then(result => {
            if (result.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: result.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire('Gagal', result.message, 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Terjadi kesalahan sistem', 'error'));
});

// Lihat Detail
function lihatDetail(id, noPengambilan, sppg) {
    document.getElementById('modalDetail').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('detailTitle').innerText = noPengambilan;
    document.getElementById('detailMeta').innerText = 'SPPG: ' + sppg;
    document.getElementById('detailBody').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#999;">Memuat...</td></tr>';

    fetch('../database/get-pengambilan-detail.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' && data.detail.length > 0) {
                let html = '';
                data.detail.forEach(d => {
                    const jenisLabel = d.jenis === 'addcost' ? 'Add Cost' : 'Food Cost';
                    let unitBadge = '';
                    if (d.satuan_eceran && d.satuan.toLowerCase() === d.satuan_eceran.toLowerCase() && d.satuan.toLowerCase() !== (d.satuan_grosir || '').toLowerCase()) {
                        unitBadge = '<span class="badge-unit-eceran"><i class="ph ph-tag"></i> Eceran</span>';
                    } else if (d.satuan_grosir && d.satuan.toLowerCase() === d.satuan_grosir.toLowerCase()) {
                        unitBadge = '<span class="badge-unit-grosir"><i class="ph ph-package"></i> Grosir</span>';
                    }
                    html += `<tr><td>${d.nama_barang}</td><td>${parseFloat(d.qty)}</td><td>${d.satuan} ${unitBadge}</td><td>${jenisLabel}</td></tr>`;
                });
                document.getElementById('detailBody').innerHTML = html;
            } else {
                document.getElementById('detailBody').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#999;">Tidak ada item.</td></tr>';
            }
        })
        .catch(() => {
            document.getElementById('detailBody').innerHTML = '<tr><td colspan="4" style="text-align:center; color:#d32f2f;">Gagal memuat data.</td></tr>';
        });
}

function closeDetail() {
    document.getElementById('modalDetail').classList.remove('active');
    document.body.style.overflow = '';
}

function verifikasiLaporan(id) {
    Swal.fire({
        title: 'Konfirmasi',
        text: 'Tandai laporan ini sudah dibuatkan faktur?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, Verifikasi',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('../database/api-verifikasi.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id
            })
                .then(res => res.json())
                .then(result => {
                    if (result.status === 'success') {
                        Swal.fire('Berhasil', 'Laporan diverifikasi!', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Gagal', result.message, 'error');
                    }
                });
        }
    });
}

window.onclick = function (event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('active');
        document.body.style.overflow = '';
    }
}