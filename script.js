// ===== Carousel Header =====
let currentSlide = 0;
const slides = document.querySelectorAll('.menu-carousel > .slide');
function showSlide(n) {
    if (slides.length === 0) return;
    slides.forEach(s => s.classList.remove('active'));
    currentSlide = (n + slides.length) % slides.length;
    slides[currentSlide].classList.add('active');
}
function changeSlide(n) { showSlide(currentSlide + n); }
if (slides.length > 0) setInterval(() => changeSlide(1), 5000);

// ===== Modal =====
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = 'auto';
}
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m) {
            m.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    });
});

// ===== Accordion Toggle =====
function toggleAccordion(header) {
    header.classList.toggle('open');
    const content = header.nextElementSibling;
    content.classList.toggle('active');
}

// ===== Auto No Faktur =====
function updateNoFaktur() {
    const tglInput = document.querySelector('input[name="tanggal"]');
    const fakturInput = document.querySelector('input[name="no_faktur"]');
    if (tglInput && fakturInput && tglInput.value) {
        const tglFormat = tglInput.value.replace(/-/g, '');
        fakturInput.value = `0001-${tglFormat}-FC`;
    }
}

// ===== Dynamic Form Rows =====
let rowIndex = 0;
function addRow() {
    rowIndex++;
    const tbody = document.querySelector('#tableItem tbody');
    const tr = document.createElement('tr');
    tr.dataset.rowIndex = rowIndex;
    let kategoriOptions = '';
    KATEGORI_LIST.forEach(k => {
        kategoriOptions += `<option value="${k}">${k}</option>`;
    });

    tr.innerHTML = `
        <td><input type="text" name="item_barang[${rowIndex}]" placeholder="Nama barang" required></td>
        <td><select name="kategori[${rowIndex}]" class="kategori-select" required>${kategoriOptions}</select></td>
        <td><input type="number" name="qty[${rowIndex}]" class="input-qty" step="0.01" min="0" placeholder="0" required onchange="calculateRow(this)"></td>
        <td><input type="text" name="satuan[${rowIndex}]" placeholder="pcs/kg" required></td>
        <td><input type="number" name="harga_satuan[${rowIndex}]" class="input-harga" step="0.01" min="0" placeholder="0" required onchange="calculateRow(this)"></td>
        <td><input type="number" name="jumlah[${rowIndex}]" class="input-jumlah" readonly placeholder="0" style="background:#f1f5f9;font-weight:600;"></td>
        <td><input type="file" name="nota_files[${rowIndex}][]" class="file-input-multi" multiple accept="image/*,.pdf" style="font-size:10px;"></td>
        <td><input type="file" name="foto_files[${rowIndex}][]" class="file-input-multi" multiple accept="image/*" style="font-size:10px;"></td>
        <td><input type="hidden" name="row_index[]" value="${rowIndex}"><button type="button" class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="removeRow(this)" title="Hapus">🗑</button></td>
    `;
    tbody.appendChild(tr);
}
function removeRow(btn) {
    const tbody = document.querySelector('#tableItem tbody');
    if (tbody.rows.length > 1) btn.closest('tr').remove();
    else alert('Minimal 1 item!');
}
function calculateRow(input) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('.input-qty').value) || 0;
    const harga = parseFloat(row.querySelector('.input-harga').value) || 0;
    row.querySelector('.input-jumlah').value = (qty * harga).toFixed(2);
}

// ===== Preview Multiple Foto Menu =====
function previewFotoMenuMulti(input) {
    const preview = document.getElementById('fotoMenuPreview');
    preview.innerHTML = '';
    Array.from(input.files).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.innerHTML = `<img src="${e.target.result}" alt="Preview"><button type="button" class="remove-preview" onclick="this.parentElement.remove()">×</button>`;
            preview.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}

// ===== Upload Inline =====
function uploadInlinePhoto(input, action, id) {
    const files = input.files;
    if (!files || files.length === 0) return;
    const loading = document.getElementById('loadingOverlay');
    if (loading) loading.classList.add('active');
    const promises = Array.from(files).map(file => {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('foto', file);
        if (action === 'add_menu_photo') fd.append('id_belanja', id);
        else fd.append('id_detail', id);

        return fetch('database/upload_photo.php', { method: 'POST', body: fd })
            .then(async r => {
                const text = await r.text();
                try { return JSON.parse(text); }
                catch (e) { throw new Error('Server error: ' + text.substring(0, 100)); }
            });
    });

    Promise.all(promises).then(results => {
        if (loading) loading.classList.remove('active');
        const failed = results.find(r => !r.success);
        if (failed) alert('❌ Gagal upload: ' + failed.message);
        else location.reload();
    }).catch(err => {
        if (loading) loading.classList.remove('active');
        alert('❌ Error: ' + err.message);
    });
    input.value = '';
}

// ===== View Photos =====
function viewPhotos(idDetail, type, count) {
    const modal = document.getElementById('photoViewerModal');
    const title = document.getElementById('photoViewerTitle');
    const grid = document.getElementById('photoGrid');
    title.textContent = type === 'nota' ? `Lampiran Nota (${count})` : `Foto Receiving (${count})`;
    grid.innerHTML = '<div style="text-align:center;padding:40px;"><div class="spinner"></div><p style="margin-top:12px;">Memuat...</p></div>';
    openModal('photoViewerModal');
    fetch(`database/get_photos.php?id_detail=${idDetail}&type=${type}`)
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } catch (e) { throw new Error('Gagal memuat data foto'); }
        })
        .then(data => {
            grid.innerHTML = '';
            if (data.photos && data.photos.length > 0) {
                const photoPath = type === 'nota' ? 'uploads/nota/' : 'uploads/foto/';
                data.photos.forEach((photo, index) => {
                    const item = document.createElement('div');
                    item.className = 'photo-item';
                    const fileExt = photo.split('.').pop().toLowerCase();
                    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt);
                    if (isImage) {
                        item.innerHTML = `<img src="${photoPath}${photo}" onclick="viewFullImage('${photoPath}${photo}')" alt="${type} ${index + 1}"><div class="photo-label">${type === 'nota' ? 'Nota' : 'Foto'} ${index + 1}</div>`;
                    } else {
                        item.innerHTML = `<div style="height:200px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:48px;">📄</div><div class="photo-label">${photo}</div>`;
                    }
                    grid.appendChild(item);
                });
            } else {
                grid.innerHTML = '<p style="text-align:center;color:var(--muted);padding:40px;grid-column:1/-1;">Tidak ada foto</p>';
            }
        })
        .catch(error => {
            grid.innerHTML = `<p style="text-align:center;color:var(--danger);padding:40px;grid-column:1/-1;">Gagal memuat foto: ${error.message}</p>`;
        });
}
function viewFullImage(src) {
    document.getElementById('fullImage').src = src;
    document.getElementById('fullImageModal').classList.add('active');
}
function closeFullImage() {
    document.getElementById('fullImageModal').classList.remove('active');
}
function exportPDF(tanggal) {
    const loading = document.getElementById('loadingOverlay');
    loading.classList.add('active');
    setTimeout(() => {
        window.location.href = `database/export_pdf.php?tanggal=${tanggal}`;
        loading.classList.remove('active');
    }, 500);
}

// ===== Edit Item Modal =====
function openEditItem(btn) {
    document.getElementById('edit_id_detail').value = btn.dataset.id;
    document.getElementById('edit_item_barang').value = btn.dataset.item;
    document.getElementById('edit_qty').value = btn.dataset.qty;
    document.getElementById('edit_satuan').value = btn.dataset.satuan;
    document.getElementById('edit_harga').value = btn.dataset.harga;
    document.getElementById('edit_kategori').value = btn.dataset.kategori;
    openModal('modalEdit');
}

// ===== Form Submit Validation =====
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('formBelanja');
    if (form) {
        form.addEventListener('submit', e => {
            const loading = document.getElementById('loadingOverlay');
            let valid = true;
            form.querySelectorAll('[required]').forEach(f => {
                if (!f.value.trim()) {
                    valid = false;
                    f.style.borderColor = 'var(--danger)';
                } else {
                    f.style.borderColor = 'var(--border)';
                }
            });
            if (!valid) {
                e.preventDefault();
                alert('Lengkapi semua field!');
                return;
            }
            loading.classList.add('active');
        });
    }
    if (document.querySelector('#tableItem tbody') && document.querySelector('#tableItem tbody').children.length === 0) {
        addRow();
    }
});

// ===== Escape Key =====
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
        document.getElementById('fullImageModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
});

// ===== Search Menu =====
function filterMenu(query) {
    const clearBtn = document.getElementById('searchClear');
    const resultInfo = document.getElementById('searchResult');
    const q = query.trim().toLowerCase();
    clearBtn.classList.toggle('visible', q.length > 0);

    const dateGroups = document.querySelectorAll('.date-group');
    let totalVisible = 0;

    dateGroups.forEach(group => {
        const cards = group.querySelectorAll('.menu-card');
        let visibleInGroup = 0;

        cards.forEach(card => {
            const title = card.querySelector('.menu-title');
            const namaMenu = title ? title.textContent.toLowerCase() : '';
            const match = q === '' || namaMenu.includes(q);
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
                content.classList.add('active');
            }
        }

        totalVisible += visibleInGroup;
    });

    if (q === '') {
        resultInfo.innerHTML = '';
    } else if (totalVisible === 0) {
        resultInfo.innerHTML = `Tidak ada menu yang cocok dengan "<span class="highlight">${escapeHtml(query)}</span>"`;
    } else {
        resultInfo.innerHTML = `Ditemukan <span class="highlight">${totalVisible}</span> menu untuk "<span class="highlight">${escapeHtml(query)}</span>"`;
    }
}
function clearSearch() {
    const input = document.getElementById('searchMenu');
    input.value = '';
    input.focus();
    filterMenu('');
}
function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}