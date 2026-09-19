// ===== Auto No Faktur AddCost =====
async function updateNoFakturAddcost() {
    const tglInput = document.querySelector('input[name="tanggal"]');
    const fakturInput = document.getElementById('noFakturAddcost');
    if (!tglInput || !tglInput.value) return;

    try {
        const res = await fetch(`?generate_no_faktur=1&tanggal=${tglInput.value}`);
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
        <td><input type="number" name="qty[${rowIndex}]" class="input-qty" step="0.01" min="0" placeholder="0" required></td>
        <td><input type="text" name="satuan[${rowIndex}]" placeholder="pcs/kg" required>
            <input type="hidden" name="harga[${rowIndex}]" class="input-harga" value="0">
            <input type="hidden" name="subtotal[${rowIndex}]" class="input-subtotal" value="0">
        </td>
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
        const loadingMsg = (file.type.startsWith('image/') && file.type !== 'image/gif' && file.size > 1000 * 1024)
            ? 'Mengompres gambar...'
            : 'Mempersiapkan gambar...';
        loading.innerHTML = `<div class="spinner"></div><p id="loadingText">${loadingMsg}</p>`;
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

        fetch('', {
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
                    try { sessionStorage.setItem('mbg_scroll_y_' + window.location.pathname, window.scrollY); } catch(e) {}
                    window.location.href = '?foto_uploaded=1';
                }
            })
            .catch(err => {
                if (loading) loading.classList.remove('active');
                alert('❌ Error: ' + err.message);
            });
    };

    if (file.type.startsWith('image/') && file.type !== 'image/gif' && file.size > 1000 * 1024) {
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

// ===== MODAL PREVIEW FOTO (PERSIS SEPERTI DOMPET HARIAN) =====
function closeAddcostPreview() {
    const modal = document.getElementById('addcostPreviewModal');
    if (modal) modal.style.display = 'none';
    const body = document.getElementById('addcostPreviewBody');
    if (body) body.innerHTML = '';
}

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('addcostPreviewModal');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeAddcostPreview();
        });
    }
});

function openAddcostPreview(url, rawFile, idDetail, type, namaBarang) {
    if (!url) return;
    const modal = document.getElementById('addcostPreviewModal');
    const title = document.getElementById('addcostPreviewTitle');
    const body = document.getElementById('addcostPreviewBody');
    if (!modal || !body) return;

    const isNota = (type === 'nota');
    const labelTitle = isNota 
        ? `Nota — ${namaBarang ? namaBarang.toUpperCase() : 'Addcost'}` 
        : `Foto Receiving — ${namaBarang ? namaBarang.toUpperCase() : 'Addcost'}`;
    if (title) title.textContent = labelTitle;

    const isPdf = url.toLowerCase().split('?')[0].endsWith('.pdf');
    const labelBtn = isNota ? 'Hapus Nota' : 'Hapus Foto';
    const itemLabel = isNota ? 'Nota' : 'Foto Receiving';

    body.innerHTML = `
        <div class="nota-preview-item">
            <div class="nota-preview-label">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <rect x="1" y="2" width="12" height="10" rx="1.2" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="4.5" cy="6" r="1.2" fill="currentColor"/>
                    <path d="M1 12l4-4 2.5 2.5 2-2L13 12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                ${itemLabel}
            </div>
            ${isPdf 
                ? `<div class="nota-preview-pdf-wrap"><embed src="${url}" type="application/pdf" class="nota-preview-pdf"></div>`
                : `<img src="${url}" alt="Preview" class="nota-preview-img" onclick="window.open('${url}', '_blank')">`
            }
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px;">
                <button type="button" class="btn-delete-nota" onclick="deleteFoto(${idDetail}, '${type}', '${rawFile || url}', true)">
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                        <path d="M2 3.5h9M5 3.5V2.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 .5.5v1M5.5 6v3.5M7.5 6v3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        <path d="M3 3.5l.7 7a.5.5 0 0 0 .5.5h4.6a.5.5 0 0 0 .5-.5l.7-7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    ${labelBtn}
                </button>
            </div>
        </div>
    `;

    modal.style.display = 'flex';
}

// ===== HAPUS FOTO DENGAN PENGHAPUSAN FISIK & SWEETALERT =====
async function deleteFoto(idDetail, type, filename, fromModal = false) {
    const isNota = (type === 'nota');
    const label = isNota ? 'Nota' : 'Foto';

    let confirmed = false;
    if (typeof Swal !== 'undefined') {
        const result = await Swal.fire({
            title: `Hapus ${label}?`,
            text: `File fisik ${label.toLowerCase()} di Cloudinary/server akan ikut terhapus permanen. Tindakan ini tidak dapat dibatalkan!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus Permanen',
            cancelButtonText: 'Batal',
            customClass: { popup: 'swal-kopdes' },
            didOpen: () => {
                const container = document.querySelector('.swal2-container');
                if (container) container.style.zIndex = '99999999';
            }
        });
        confirmed = result.isConfirmed;
    } else {
        confirmed = confirm(`Yakin ingin menghapus ${label.toLowerCase()} ini? File fisik di Cloudinary/server akan ikut terhapus permanen.`);
    }

    if (!confirmed) return;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Menghapus...',
            text: 'Sedang menghapus file fisik...',
            allowOutsideClick: false,
            customClass: { popup: 'swal-kopdes' },
            didOpen: () => {
                const container = document.querySelector('.swal2-container');
                if (container) container.style.zIndex = '99999999';
                Swal.showLoading();
            }
        });
    }

    const fd = new FormData();
    fd.append('action', 'delete_foto_item');
    fd.append('id_detail', idDetail);
    fd.append('type', type);
    fd.append('filename', filename);

    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const text = await r.text();
        
        if (fromModal) {
            closeAddcostPreview();
        }

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil Dihapus',
                text: `File fisik ${label.toLowerCase()} telah dihapus.`,
                timer: 1500,
                showConfirmButton: false
            });
        }

        // Hapus thumbnail foto langsung di DOM
        const deleteBtn = document.querySelector(`button[onclick*="'${filename}'"]`);
        const thumbItem = deleteBtn ? deleteBtn.closest('.item-foto-thumb-item') : null;
        if (thumbItem) {
            const thumbContainer = thumbItem.closest('.item-foto-thumbnails');
            thumbItem.remove();
            if (thumbContainer && thumbContainer.querySelectorAll('.item-foto-thumb-item').length === 0) {
                thumbContainer.previousElementSibling?.remove(); // hapus label judul
                thumbContainer.remove();
            }
        }
    } catch (err) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'Error', text: err.message });
        } else {
            alert('❌ Error: ' + err.message);
        }
    }
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

    fetch('', {
        method: 'POST',
        body: fd
    })
        .then(r => r.text())
        .then(() => {
            if (loading) loading.classList.remove('active');
            // Update UI status button in-place
            const itemRow = btn ? btn.closest('.item-row') : null;
            if (itemRow) {
                const group = itemRow.querySelector('.status-btn-group');
                if (group) {
                    group.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                }
                const ketDisplay = itemRow.querySelector('.keterangan-display');
                if (ketDisplay) ketDisplay.remove();
                hideKeterangan(idDetail);
            }
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

    fetch('', {
        method: 'POST',
        body: fd
    })
        .then(r => r.text())
        .then(() => {
            if (loading) loading.classList.remove('active');
            hideKeterangan(idDetail);

            // Update UI in-place
            const area = document.getElementById('keterangan-area-' + idDetail);
            const itemRow = area ? area.closest('.item-row') : null;
            if (itemRow) {
                const group = itemRow.querySelector('.status-btn-group');
                if (group) {
                    group.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
                    const btnKurang = group.querySelector('.btn-kurang');
                    if (btnKurang) btnKurang.classList.add('active');
                }
                let ketDisplay = itemRow.querySelector('.keterangan-display');
                if (!ketDisplay) {
                    ketDisplay = document.createElement('div');
                    ketDisplay.className = 'keterangan-display';
                    itemRow.insertBefore(ketDisplay, area);
                }
                ketDisplay.innerHTML = `📝 <strong>Keterangan:</strong> ${keterangan}`;
            }
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

// ===== CETAK FAKTUR ADDCOST =====
function exportPDFAddcost(tanggal, id) {
    const url = id
        ? `export-pdf.php?tanggal=${tanggal}&id=${id}`
        : `export-pdf.php?tanggal=${tanggal}`;
    window.open(url, '_blank');
}