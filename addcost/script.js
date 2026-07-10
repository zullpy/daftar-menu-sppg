// ===== Auto No Faktur AddCost =====
async function updateNoFakturAddcost() {
    const tglInput = document.querySelector('input[name="tanggal"]');
    const fakturInput = document.getElementById('noFakturAddcost');
    if (!tglInput || !tglInput.value) return;

    try {
        const res = await fetch(`addcost.php?generate_no_faktur=1&tanggal=${tglInput.value}`);
        fakturInput.value = await res.text();
    } catch (err) {
        console.error('Gagal generate no faktur:', err);
        fakturInput.value = 'Error generating';
    }
}

// ===== Dynamic Form Rows Addcost =====
function addRowAddcost() {
    const rowIndex = Date.now();
    const tbody = document.querySelector('#tableAddcostItem tbody');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.dataset.rowIndex = rowIndex;
    tr.innerHTML = `
        <td><input type="text" name="nama_barang[${rowIndex}]" placeholder="Nama barang" required></td>
        <td><input type="number" name="harga[${rowIndex}]" class="input-harga" step="0.01" min="0" placeholder="0" required onchange="calculateRowAddcost(this)"></td>
        <td><input type="number" name="qty[${rowIndex}]" class="input-qty" step="0.01" min="0" placeholder="0" required onchange="calculateRowAddcost(this)"></td>
        <td><input type="text" name="satuan[${rowIndex}]" placeholder="pcs/kg" required></td>
        <td><input type="number" name="subtotal[${rowIndex}]" class="input-subtotal" readonly placeholder="0" style="background:#f1f5f9;font-weight:600;"></td>
        <td><input type="hidden" name="row_index[]" value="${rowIndex}"><button type="button" class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="removeRowAddcost(this)" title="Hapus"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></button></td>
    `;
    tbody.appendChild(tr);
}

function removeRowAddcost(btn) {
    const tbody = document.querySelector('#tableAddcostItem tbody');
    if (!tbody) return;

    if (tbody.rows.length > 1) {
        btn.closest('tr').remove();
    } else {
        alert('Minimal 1 item!');
    }
}

function calculateRowAddcost(input) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('.input-qty').value) || 0;
    const harga = parseFloat(row.querySelector('.input-harga').value) || 0;
    row.querySelector('.input-subtotal').value = (qty * harga).toFixed(2);
}

function openEditItemAddcost(btn) {
    document.getElementById('edit_id_detail').value = btn.dataset.id;
    document.getElementById('edit_pembelian_add_id').value = btn.dataset.pembelianAddId;
    document.getElementById('edit_nama_barang').value = btn.dataset.nama;
    document.getElementById('edit_harga').value = btn.dataset.harga;
    document.getElementById('edit_qty').value = btn.dataset.qty;
    document.getElementById('edit_satuan').value = btn.dataset.satuan;
    openModal('modalEditAddcost');
}

// ===== UPLOAD FOTO PER ITEM (MULTIPLE) =====
function uploadFotoReceivingItem(input, idDetail) {
    uploadFotoItemGeneric(input, idDetail, 'upload_foto_receiving_item', 'receiving');
}

function uploadFotoNotaItem(input, idDetail) {
    uploadFotoItemGeneric(input, idDetail, 'upload_foto_nota_item', 'nota');
}

function uploadFotoItemGeneric(input, idDetail, action, type) {
    const file = input.files[0];
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
        alert('⚠️ Ukuran file maksimal 10MB.');
        input.value = '';
        return;
    }

    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `<div class="spinner"></div><p id="loadingText">Mengompres gambar...</p>`;
        loading.classList.add('active');
    }

    const doUpload = (uploadFile) => {
        if (loading) {
            loading.innerHTML = `<div class="spinner"></div><p id="loadingText">Mengupload foto ${type}...</p>`;
        }
        const fd = new FormData();
        fd.append('action', action);
        fd.append('id_detail', idDetail);
        fd.append('foto', uploadFile);

        fetch('addcost.php', {
            method: 'POST',
            body: fd
        })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Server error: ' + text.substring(0, 100));
                }
            })
            .then(result => {
                if (loading) loading.classList.remove('active');
                if (!result.success) {
                    alert('❌ Gagal upload: ' + (result.message || 'Unknown error'));
                } else {
                    // Reload page to show new photo
                    window.location.href = 'addcost.php?foto_uploaded=1';
                }
            })
            .catch(err => {
                if (loading) loading.classList.remove('active');
                alert('❌ Error: ' + err.message);
            });
    };

    if (file.type.startsWith('image/') && file.type !== 'image/gif') {
        compressImage(file, { maxWidth: 1800, maxHeight: 1800, quality: 0.8, maxSizeKB: 1000 })
            .then(doUpload)
            .catch(err => {
                console.error('Gagal mengompres gambar:', err);
                doUpload(file);
            });
    } else {
        doUpload(file);
    }

    input.value = '';
}

// ===== HAPUS FOTO =====
function deleteFoto(idDetail, type, filename) {
    if (!confirm('Yakin ingin menghapus foto ini?')) return;

    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `<div class="spinner"></div><p>Menghapus foto...</p>`;
        loading.classList.add('active');
    }

    const fd = new FormData();
    fd.append('action', 'delete_foto_item');
    fd.append('id_detail', idDetail);
    fd.append('type', type);
    fd.append('filename', filename);

    fetch('addcost.php', {
        method: 'POST',
        body: fd
    })
        .then(r => r.text())
        .then(() => {
            if (loading) loading.classList.remove('active');
            window.location.href = 'addcost.php?foto_deleted=1';
        })
        .catch(err => {
            if (loading) loading.classList.remove('active');
            alert('❌ Error: ' + err.message);
        });
}

// ===== UPDATE STATUS ITEM =====
function updateStatusItem(idDetail, status, btn) {
    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `<div class="spinner"></div><p>Updating status...</p>`;
        loading.classList.add('active');
    }

    const fd = new FormData();
    fd.append('update_status_item', '1');
    fd.append('id_detail', idDetail);
    fd.append('status', status);
    fd.append('keterangan', '');

    fetch('addcost.php', {
        method: 'POST',
        body: fd
    })
        .then(r => r.text())
        .then(() => {
            if (loading) loading.classList.remove('active');
            window.location.href = 'addcost.php?status_updated=1';
        })
        .catch(err => {
            if (loading) loading.classList.remove('active');
            alert('❌ Error: ' + err.message);
        });
}

// ===== TOGGLE KETERANGAN (untuk status Kurang) =====
function toggleKeterangan(idDetail, btn) {
    const area = document.getElementById('keterangan-area-' + idDetail);
    if (!area) return;

    if (area.classList.contains('show')) {
        submitKeterangan(idDetail);
    } else {
        area.classList.add('show');
        const input = document.getElementById('keterangan-input-' + idDetail);
        if (input) input.focus();
    }
}

function hideKeterangan(idDetail) {
    const area = document.getElementById('keterangan-area-' + idDetail);
    if (area) area.classList.remove('show');
}

function submitKeterangan(idDetail) {
    const input = document.getElementById('keterangan-input-' + idDetail);
    if (!input) return;

    const keterangan = input.value.trim();

    if (!keterangan) {
        alert('⚠️ Keterangan wajib diisi saat status "Kurang"!');
        input.focus();
        return;
    }

    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `<div class="spinner"></div><p>Updating status...</p>`;
        loading.classList.add('active');
    }

    const fd = new FormData();
    fd.append('update_status_item', '1');
    fd.append('id_detail', idDetail);
    fd.append('status', 'kurang');
    fd.append('keterangan', keterangan);

    fetch('addcost.php', {
        method: 'POST',
        body: fd
    })
        .then(r => r.text())
        .then(() => {
            if (loading) loading.classList.remove('active');
            window.location.href = 'addcost.php?status_updated=1';
        })
        .catch(err => {
            if (loading) loading.classList.remove('active');
            alert('❌ Error: ' + err.message);
        });
}

// ===== SEARCH / FILTER =====
function filterAddcost(query) {
    const clearBtn = document.getElementById('searchClear');
    const resultInfo = document.getElementById('searchResult');
    const q = query.trim().toLowerCase();

    if (clearBtn) {
        clearBtn.classList.toggle('visible', q.length > 0);
    }

    const dateGroups = document.querySelectorAll('.date-group');
    let totalVisible = 0;

    dateGroups.forEach(group => {
        const cards = group.querySelectorAll('.menu-card');
        let visibleInGroup = 0;

        cards.forEach(card => {
            const supplier = card.querySelector('.menu-title');
            const faktur = card.querySelector('.menu-info strong');
            const text = (supplier ? supplier.textContent : '') + ' ' + (faktur ? faktur.textContent : '');
            const match = q === '' || text.toLowerCase().includes(q);
            card.style.display = match ? '' : 'none';
            if (match) visibleInGroup++;
        });

        const isGroupVisible = q === '' || visibleInGroup > 0;
        group.style.display = isGroupVisible ? '' : 'none';

        if (q !== '' && isGroupVisible) {
            const header = group.querySelector('.accordion-toggle');
            const content = group.querySelector('.date-content');
            if (header && !header.classList.contains('open')) {
                header.classList.add('open');
                if (content) content.classList.add('active');
            }
        }

        totalVisible += visibleInGroup;
    });

    if (resultInfo) {
        if (q === '') {
            resultInfo.innerHTML = '';
        } else if (totalVisible === 0) {
            resultInfo.innerHTML = `Tidak ada data yang cocok dengan "<span class="highlight">${escapeHtml(query)}</span>"`;
        } else {
            resultInfo.innerHTML = `Ditemukan <span class="highlight">${totalVisible}</span> data untuk "<span class="highlight">${escapeHtml(query)}</span>"`;
        }
    }
}

function clearSearch() {
    const input = document.getElementById('searchAddcost');
    if (input) {
        input.value = '';
        input.focus();
        filterAddcost('');
    }
}

function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.querySelector('#tableAddcostItem tbody');
    if (tbody && tbody.children.length === 0) {
        addRowAddcost();
    }
    updateNoFakturAddcost();
});

function openEditItemAddcost(btn) {
    document.getElementById('edit_id_detail').value = btn.dataset.id;
    document.getElementById('edit_pembelian_add_id').value = btn.dataset.pembelianAddId;
    document.getElementById('edit_nama_barang').value = btn.dataset.nama;
    document.getElementById('edit_harga').value = btn.dataset.harga;
    document.getElementById('edit_qty').value = btn.dataset.qty;
    document.getElementById('edit_satuan').value = btn.dataset.satuan;
    openModal('modalEditAddcost');
}