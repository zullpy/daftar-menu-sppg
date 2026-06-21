function setAutoJam() {
    const now = new Date();
    // toTimeString() selalu return 24 jam format "15:06:30 GMT+0700..."
    // substring(0,5) ambil "15:06"
    const jam = now.toTimeString().split(' ')[0].substring(0, 5);
    document.getElementById('jam_pengambilan').value = jam;
}

// Accordion Toggle (skrg juga toggle class 'open' di header utk animasi chevron)
function toggleAccordion(id) {
    const el = document.getElementById(id);
    el.classList.toggle('active');
    const header = el.previousElementSibling;
    if (header) header.classList.toggle('open');
}

// Filter bar toggle (mobile)
function toggleFilter() {
    const bar = document.getElementById('filterBar');
    const btn = document.getElementById('filterToggleBtn');
    bar.classList.toggle('open');
    btn.classList.toggle('open');
}

// Modal Control
function openModal() {
    document.getElementById('modalTambah').classList.add('active');
    document.body.style.overflow = 'hidden';
    setAutoJam();
    document.getElementById('tanggal_pengambilan').value = new Date().toISOString().split('T')[0];

    // Otomatis tambahkan 1 baris barang saat modal dibuka
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

// Dynamic Barang Rows
let barangIndex = 0;
function addBarangRow() {
    const container = document.getElementById('barangContainer');
    const html = `
        <div class="barang-row" id="barang-${barangIndex}">
            <div class="form-group">
                <input type="text" name="nama_barang[]" placeholder="Nama Barang" required>
            </div>
            <div class="form-group">
                <input type="number" name="qty[]" placeholder="Qty" step="0.01" required inputmode="decimal">
            </div>
            <div class="form-group">
                <input type="text" name="satuan[]" placeholder="Satuan" required>
            </div>
            <button type="button" class="btn btn-danger" onclick="removeBarangRow(${barangIndex})">
                <i class="ph ph-trash"></i> <span class="only-mobile-label"></span>
            </button>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    barangIndex++;
}

function removeBarangRow(id) {
    document.getElementById(`barang-${id}`).remove();
}

// Submit Form
document.getElementById('formTambah').addEventListener('submit', function (e) {
    e.preventDefault();
    const formData = new FormData(this);

    const data = {
        nama_pengambil: formData.get('nama_pengambil'),
        nama_sppg: formData.get('nama_sppg'),
        tanggal_pengambilan: formData.get('tanggal_pengambilan'),
        jam_pengambilan: formData.get('jam_pengambilan'),
        no_kontak: formData.get('no_kontak'),
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

    fetch('../database/api-tambah-pengambilan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
        .then(res => res.json())
        .then(result => {
            if (result.status === 'success') {
                Swal.fire('Berhasil', result.message, 'success').then(() => location.reload());
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

// Verifikasi Faktur
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

// Close modal when clicking outside
window.onclick = function (event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('active');
        document.body.style.overflow = '';
    }
}