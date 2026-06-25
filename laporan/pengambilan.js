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
// ✅ CEK STOK VIA TOAST (BARU)
// ============================================
function cekStokBarang(input) {
    const namaBarang = input.value.trim();
    if (!namaBarang) return;

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
            <div class="form-group">
                <input type="text" name="nama_barang[]" placeholder="Nama Barang" required 
                    onblur="cekStokBarang(this)">
            </div>
            <div class="barang-row-inputs">
                <div class="form-group">
                    <input type="number" name="qty[]" placeholder="Qty" step="0.01" required inputmode="decimal">
                </div>
                <div class="form-group">
                    <input type="text" name="satuan[]" placeholder="Satuan" required>
                </div>
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

    for (let i = 0; i < namaBarangs.length; i++) {
        if (namaBarangs[i]) {
            data.barang.push({
                nama_barang: namaBarangs[i],
                qty: qtys[i],
                satuan: satuans[i]
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
                    html += `<tr><td>${d.nama_barang}</td><td>${parseFloat(d.qty)}</td><td>${d.satuan}</td></tr>`;
                });
                document.getElementById('detailBody').innerHTML = html;
            } else {
                document.getElementById('detailBody').innerHTML = '<tr><td colspan="3" style="text-align:center; color:#999;">Tidak ada item.</td></tr>';
            }
        })
        .catch(() => {
            document.getElementById('detailBody').innerHTML = '<tr><td colspan="3" style="text-align:center; color:#d32f2f;">Gagal memuat data.</td></tr>';
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