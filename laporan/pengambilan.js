function setAutoJam() {
    const now = new Date();
    const jam = now.toTimeString().split(' ')[0].substring(0, 5);
    document.getElementById('jam_pengambilan').value = jam;
}

function toggleAccordion(id) {
    const el = document.getElementById(id);
    el.classList.toggle('active');
    const header = el.previousElementSibling;
    if (header) header.classList.toggle('open');
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
    if (type === 'success') icon = '<span class="toast-icon" style="color:#2e7d32;">✅</span>';
    else if (type === 'warning') icon = '<span class="toast-icon" style="color:#ed6c02;">⚠️</span>';
    else if (type === 'error') icon = '<span class="toast-icon" style="color:#d32f2f;">❌</span>';

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

    // Reset stok tersimpan & tampilan info tiap kali user ngetik ulang (belum pilih dari list lagi)
    delete input.dataset.stok;
    delete input.dataset.satuan;
    infoEl.innerHTML = '';
    infoEl.className = 'stok-info';

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

    dropdown.innerHTML = items.map(item => `
        <div class="autocomplete-item" 
             onmousedown="event.preventDefault(); pilihBarangStok(this)"
             data-nama="${item.nama_barang.replace(/"/g, '&quot;')}"
             data-satuan="${item.satuan.replace(/"/g, '&quot;')}"
             data-stok="${item.sisa_stok}">
            <span class="ai-nama">${item.nama_barang}</span>
            <span class="ai-stok">${item.sisa_stok} ${item.satuan}</span>
        </div>
    `).join('');
    dropdown.classList.add('show');
}

// Update tampilan sisa stok yang PERSISTEN di bawah input (misal "📦 Susu — 10 dus")
function tampilkanInfoStok(wrap, nama, stok, satuan) {
    const infoEl = wrap.querySelector('.stok-info');
    let level = 'aman';
    if (stok <= 0) level = 'habis';
    else if (stok <= 10) level = 'menipis';

    const label = stok <= 0 ? 'Stok habis' : `Sisa stok: <strong>${stok} ${satuan}</strong>`;
    infoEl.className = 'stok-info stok-' + level;
    infoEl.innerHTML = `<i class="ph-fill ph-package"></i> ${label}`;
}

function pilihBarangStok(el) {
    const wrap = el.closest('.autocomplete-wrap');
    const input = wrap.querySelector('.input-nama-barang');
    const row = wrap.closest('.barang-row');
    const dropdown = wrap.querySelector('.autocomplete-dropdown');

    const nama = el.dataset.nama;
    const satuan = el.dataset.satuan;
    const stok = parseFloat(el.dataset.stok);

    input.value = nama;
    input.dataset.stok = stok;
    input.dataset.satuan = satuan;

    // Auto-isi satuan di baris yang sama
    const satuanInput = row.querySelector('input[name="satuan[]"]');
    if (satuanInput) satuanInput.value = satuan;

    dropdown.innerHTML = '';
    dropdown.classList.remove('show');

    tampilkanInfoStok(wrap, nama, stok, satuan);

    if (stok <= 0) {
        showToast(`Stok <strong>${nama}</strong> HABIS! (Sisa: ${stok} ${satuan})`, 'error');
    } else if (stok <= 10) {
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
    // Kalau sudah dipilih dari dropdown, dataset.stok sudah ada — tidak perlu fetch ulang
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

                // Simpan data untuk validasi qty nanti
                input.dataset.stok = sisa;
                input.dataset.satuan = satuan;

                tampilkanInfoStok(wrap, namaBarang, sisa, satuan);

                if (sisa <= 0) {
                    showToast(`Stok <strong>${namaBarang}</strong> HABIS! (Sisa: ${sisa} ${satuan})`, 'error');
                } else if (sisa <= 10) {
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
// DYNAMIC BARANG ROWS (DIPERBAIKI BUG NYA)
// ============================================
let barangIndex = 0;
function addBarangRow() {
    const container = document.getElementById('barangContainer');
    // Template literal yang bersih dan benar
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
            </div>
            <div class="barang-row-inputs">
                <div class="form-group">
                    <input type="number" name="qty[]" placeholder="Qty" step="0.01" required inputmode="decimal">
                </div>
                <div class="form-group">
                    <input type="text" name="satuan[]" placeholder="Satuan" required>
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
        if (inputNama && inputQty) {
            const nama = inputNama.value.trim();
            const qty = parseFloat(inputQty.value) || 0;
            const stok = parseFloat(inputNama.dataset.stok);
            const satuan = inputNama.dataset.satuan || '';

            if (isNaN(stok) || stok <= 0) {
                Swal.fire('Stok Tidak Ada', `Stok untuk barang "${nama}" tidak ada atau habis!`, 'error');
                return;
            }

            if (qty > stok) {
                Swal.fire('Stok Tidak Cukup', `Jumlah pengambilan untuk barang "${nama}" (${qty} ${satuan}) melebihi stok yang ada! (Sisa stok: ${stok} ${satuan})`, 'error');
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
    document.getElementById('detailBody').innerHTML = '<tr><td colspan="3" style="text-align:center; color:#999;">Memuat...</td></tr>';

    fetch('../database/get-pengambilan-detail.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' && data.detail.length > 0) {
                let html = '';
                data.detail.forEach(d => {
                    const jenisLabel = d.jenis === 'addcost' ? 'Add Cost' : 'Food Cost';
                    html += `<tr><td>${d.nama_barang}</td><td>${parseFloat(d.qty)}</td><td>${d.satuan}</td><td>${jenisLabel}</td></tr>`;
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