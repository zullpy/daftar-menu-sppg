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

// ===== Toast Notification =====
function showToast(message, type = 'success') {
    const toast = document.getElementById('toastNotif');
    if (!toast) return;
    toast.className = 'toast-notif toast-' + type + ' show';
    toast.innerHTML = message;
    setTimeout(() => toast.classList.remove('show'), 3000);
}

// ===== Modal =====
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) {
        document.body.style.overflow = '';
    }
}
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m) {
            m.classList.remove('active');
            if (!document.querySelector('.modal-overlay.active')) {
                document.body.style.overflow = '';
            }
        }
    });
});

// ===== Accordion Toggle & Persistence =====
function toggleAccordion(header) {
    if (!header) return;
    if (typeof header === 'string') {
        header = document.getElementById(header);
        if (!header) return;
    }
    if (header && header.classList) header.classList.toggle('open');
    const content = header ? header.nextElementSibling : null;
    if (content && content.classList) {
        content.classList.toggle('active');
        content.classList.toggle('open');
    }
    try {
        const openDates = [];
        document.querySelectorAll('.date-group').forEach(group => {
            const h = group.querySelector('.accordion-toggle');
            if (h && h.classList.contains('open')) {
                const tgl = group.dataset.tanggal;
                if (tgl) openDates.push(tgl);
            }
        });
        sessionStorage.setItem('mbg_open_dates', JSON.stringify(openDates));
        sessionStorage.setItem('mbg_scroll_y', window.scrollY);
    } catch (e) {}
}

// Restore Accordion & Scroll Position on Page Load
document.addEventListener('DOMContentLoaded', () => {
    try {
        const savedDates = JSON.parse(sessionStorage.getItem('mbg_open_dates') || '[]');
        if (Array.isArray(savedDates) && savedDates.length > 0) {
            document.querySelectorAll('.date-group').forEach(group => {
                const tgl = group.dataset.tanggal;
                const header = group.querySelector('.accordion-toggle');
                const content = header ? header.nextElementSibling : null;
                if (tgl && savedDates.includes(tgl)) {
                    if (header) header.classList.add('open');
                    if (content) { content.classList.add('active', 'open'); }
                } else if (tgl && savedDates.length > 0) {
                    if (header) header.classList.remove('open');
                    if (content) { content.classList.remove('active', 'open'); }
                }
            });
        }
        const savedY = parseInt(sessionStorage.getItem('mbg_scroll_y') || '0', 10);
        if (savedY > 0) {
            window.scrollTo({ top: savedY, behavior: 'instant' });
            setTimeout(() => window.scrollTo({ top: savedY, behavior: 'instant' }), 100);
        }
    } catch (e) {}
});

// Helper Update Ringkasan Stats In-Place
function updateCardRingkasanStats(card) {
    if (!card) return;
    const items = card.querySelectorAll('.item-row');
    let lengkap = 0, kurang = 0, tidakAda = 0, belum = 0;
    items.forEach(row => {
        const activeBtn = row.querySelector('.status-btn.active');
        if (!activeBtn) {
            belum++;
        } else if (activeBtn.classList.contains('btn-lengkap')) {
            lengkap++;
        } else if (activeBtn.classList.contains('btn-kurang')) {
            kurang++;
        } else if (activeBtn.classList.contains('btn-tidak-ada')) {
            tidakAda++;
        } else {
            belum++;
        }
    });

    const chipLengkap = card.querySelector('.stat-chip.stat-lengkap');
    if (chipLengkap) chipLengkap.textContent = `✓ ${lengkap}`;
    const chipKurang = card.querySelector('.stat-chip.stat-kurang');
    if (chipKurang) chipKurang.textContent = `⚠ ${kurang}`;
    const chipTidakAda = card.querySelector('.stat-chip.stat-tidakada');
    if (chipTidakAda) chipTidakAda.textContent = `✗ ${tidakAda}`;
    const chipBelum = card.querySelector('.stat-chip.stat-belum');
    if (chipBelum) chipBelum.textContent = `? ${belum}`;
}

// ===== Auto No Faktur =====
async function updateNoFaktur() {
    const tglInput = document.querySelector('input[name="tanggal"]');
    const fakturInput = document.querySelector('input[name="no_faktur"]');
    if (!tglInput.value) return;
    const res = await fetch(`../database/get-no-faktur.php?tanggal=${tglInput.value}`);
    fakturInput.value = await res.text();
}
document.querySelector('input[name="tanggal"]')?.addEventListener('change', updateNoFaktur);

// ===== Dynamic Form Rows =====
let rowIndex = 0;
function addRow(item = null) {
    rowIndex++;
    const tbody = document.querySelector('#tableItem tbody');
    const tr = document.createElement('tr');
    tr.dataset.rowIndex = rowIndex;
    
    const targetKategori = item ? (item.kategori || 'Karbohidrat') : 'Karbohidrat';
    let kategoriOptions = '';
    KATEGORI_LIST.forEach(k => {
        const isSel = (k === targetKategori) ? 'selected' : '';
        kategoriOptions += `<option value="${k}" ${isSel}>${k}</option>`;
    });

    const nama = item ? (item.item_barang || '') : '';
    const qty = (item && item.qty !== undefined && item.qty !== null) ? item.qty : '';
    const satuan = item ? (item.satuan || '') : '';
    const harga = (item && item.harga_satuan !== undefined) ? item.harga_satuan : 0;
    const jumlah = (qty && harga) ? (qty * harga).toFixed(2) : 0;

    tr.innerHTML = `
        <td><input type="text" name="item_barang[${rowIndex}]" value="${escapeHtml(String(nama))}" placeholder="Nama barang" required></td>
        <td><select name="kategori[${rowIndex}]" class="kategori-select" required>${kategoriOptions}</select></td>
        <td><input type="number" name="qty[${rowIndex}]" class="input-qty" step="0.01" min="0" value="${qty}" placeholder="0" required></td>
        <td><input type="text" name="satuan[${rowIndex}]" value="${escapeHtml(String(satuan))}" placeholder="pcs/kg" required>
            <input type="hidden" name="harga_satuan[${rowIndex}]" class="input-harga" value="${harga}">
            <input type="hidden" name="jumlah[${rowIndex}]" class="input-jumlah" value="${jumlah}">
        </td>
        <td><input type="hidden" name="row_index[]" value="${rowIndex}"><button type="button" class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="removeRow(this)" title="Hapus"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></button></td>
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

    // Validasi ukuran max 10MB
    const validFiles = [];
    const invalidFiles = [];
    Array.from(input.files).forEach(file => {
        if (file.size > 10 * 1024 * 1024) {
            invalidFiles.push(file.name);
        } else {
            validFiles.push(file);
        }
    });

    if (invalidFiles.length > 0) {
        alert(`⚠️ File berikut melebihi batas maksimal 10MB dan tidak dimasukkan:\n- ${invalidFiles.join('\n- ')}`);
        const dataTransfer = new DataTransfer();
        validFiles.forEach(f => dataTransfer.items.add(f));
        input.files = dataTransfer.files;
    }

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

// =====================================================
// 🗜️ FUNGSI COMPRESS GAMBAR
// =====================================================
function compressImage(file, options = {}) {
    const {
        maxWidth = 1800,
        maxHeight = 1800,
        quality = 0.8,
        maxSizeKB = 1000,
        minQuality = 0.5
    } = options;
    return new Promise((resolve, reject) => {
        if (!file.type.startsWith('image/') || file.type === 'image/gif') {
            resolve(file);
            return;
        }
        if (file.size < 1000 * 1024) {
            resolve(file);
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                let width = img.width;
                let height = img.height;
                if (width > maxWidth || height > maxHeight) {
                    const ratio = Math.min(maxWidth / width, maxHeight / height);
                    width = Math.round(width * ratio);
                    height = Math.round(height * ratio);
                }
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(0, 0, width, height);
                ctx.imageSmoothingEnabled = true;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, 0, 0, width, height);
                let currentQuality = quality;
                const tryCompress = (q) => {
                    canvas.toBlob((blob) => {
                        if (!blob) {
                            reject(new Error('Gagal compress gambar'));
                            return;
                        }
                        if (blob.size > maxSizeKB * 1024 && q > minQuality) {
                            tryCompress(q - 0.1);
                            return;
                        }
                        const compressedFile = new File(
                            [blob],
                            file.name.replace(/\.[^.]+$/, '.jpg'),
                            { type: 'image/jpeg', lastModified: Date.now() }
                        );
                        resolve(compressedFile);
                    }, 'image/jpeg', q);
                };
                tryCompress(currentQuality);
            };
            img.onerror = () => reject(new Error('Gagal memuat gambar'));
            img.src = e.target.result;
        };
        reader.onerror = () => reject(new Error('Gagal membaca file'));
        reader.readAsDataURL(file);
    });
}

// =====================================================
// 📤 UPLOAD INLINE PHOTO
// =====================================================
function uploadInlinePhoto(input, action, id) {
    const files = input.files;
    if (!files || files.length === 0) return;

    // Validasi ukuran max 10MB
    const invalidFiles = [];
    for (let i = 0; i < files.length; i++) {
        if (files[i].size > 10 * 1024 * 1024) {
            invalidFiles.push(files[i].name);
        }
    }
    if (invalidFiles.length > 0) {
        alert(`⚠️ File berikut melebihi batas maksimal 10MB:\n- ${invalidFiles.join('\n- ')}`);
        input.value = '';
        return;
    }

    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `<div class="spinner"></div><p id="loadingText">Mempersiapkan gambar...</p>`;
        loading.classList.add('active');
    }
    const needCompress = true;
    const processFiles = async () => {
        const processedFiles = [];
        const totalFiles = files.length;
        for (let i = 0; i < totalFiles; i++) {
            const file = files[i];
            const loadingText = document.getElementById('loadingText');
            if (loadingText) {
                if (needCompress && file.type.startsWith('image/') && file.type !== 'image/gif' && file.size > 1000 * 1024) {
                    loadingText.textContent = `Mengcompress gambar ${i + 1}/${totalFiles}...`;
                } else {
                    loadingText.textContent = `Memproses file ${i + 1}/${totalFiles}...`;
                }
            }
            try {
                if (needCompress && file.type.startsWith('image/') && file.type !== 'image/gif' && file.size > 1000 * 1024) {
                    const compressed = await compressImage(file, {
                        maxWidth: 1600, maxHeight: 1600, quality: 0.75, maxSizeKB: 1000
                    });
                    processedFiles.push(compressed);
                } else {
                    processedFiles.push(file);
                }
            } catch (err) {
                console.error('Error compress:', err);
                processedFiles.push(file);
            }
        }
        return processedFiles;
    };

    processFiles().then(processedFiles => {
        const loadingText = document.getElementById('loadingText');
        if (loadingText) loadingText.textContent = 'Mengupload...';
        const promises = processedFiles.map(file => {
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
        return Promise.all(promises);
    }).then(results => {
        if (loading) loading.classList.remove('active');
        if (loading) {
            loading.innerHTML = `<div class="spinner"></div><p>Memproses...</p>`;
        }
        const failed = results.find(r => !r.success);
        if (failed) {
            alert('❌ Gagal upload: ' + failed.message);
        } else {
            const addedCount = results.filter(r => r.success).length;
            const actionGroup = input.closest('.action-group');
            if (actionGroup) {
                if (action === 'add_nota') {
                    let btn = actionGroup.querySelector('.action-btn-nota');
                    if (btn) {
                        const span = btn.querySelector('span');
                        const currentCount = parseInt(span ? span.textContent : '0', 10) || 0;
                        const newCount = currentCount + addedCount;
                        if (span) span.textContent = newCount;
                        btn.setAttribute('onclick', `viewPhotos(${id}, 'nota', ${newCount})`);
                        btn.title = `Lihat Nota (${newCount})`;
                    } else {
                        const newBtn = document.createElement('button');
                        newBtn.type = 'button';
                        newBtn.className = 'action-btn action-btn-nota';
                        newBtn.setAttribute('onclick', `viewPhotos(${id}, 'nota', ${addedCount})`);
                        newBtn.title = `Lihat Nota (${addedCount})`;
                        newBtn.innerHTML = `
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                                <line x1="16" y1="13" x2="8" y2="13" />
                                <line x1="16" y1="17" x2="8" y2="17" />
                            </svg>
                            <span>${addedCount}</span>
                        `;
                        actionGroup.insertBefore(newBtn, actionGroup.firstChild);
                    }
                } else if (action === 'add_foto_receiving') {
                    let btn = actionGroup.querySelector('.action-btn-foto');
                    if (btn) {
                        const span = btn.querySelector('span');
                        const currentCount = parseInt(span ? span.textContent : '0', 10) || 0;
                        const newCount = currentCount + addedCount;
                        if (span) span.textContent = newCount;
                        btn.setAttribute('onclick', `viewPhotos(${id}, 'foto', ${newCount})`);
                        btn.title = `Lihat Foto Receiving (${newCount})`;
                    } else {
                        const newBtn = document.createElement('button');
                        newBtn.type = 'button';
                        newBtn.className = 'action-btn action-btn-foto';
                        newBtn.setAttribute('onclick', `viewPhotos(${id}, 'foto', ${addedCount})`);
                        newBtn.title = `Lihat Foto Receiving (${addedCount})`;
                        newBtn.innerHTML = `
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <span>${addedCount}</span>
                        `;
                        actionGroup.insertBefore(newBtn, actionGroup.firstChild);
                    }
                }
            }
            showToast(`✓ Berhasil mengunggah ${addedCount} file`, 'success');
        }
    }).catch(err => {
        if (loading) loading.classList.remove('active');
        if (loading) {
            loading.innerHTML = `<div class="spinner"></div><p>Memproses...</p>`;
        }
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
                        item.innerHTML = `<div style="height:200px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div><div class="photo-label">${photo}</div>`;
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
    const ext = src.split('.').pop().toLowerCase();
    if (ext === 'pdf') {
        window.open(src, '_blank');
        return;
    }
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

// ===== Edit Item Modal & Asynchronous Handlers =====
let currentEditDetailBtn = null;

function openEditItem(btn) {
    currentEditDetailBtn = btn;
    document.getElementById('edit_id_detail').value = btn.dataset.id;
    document.getElementById('edit_item_barang').value = btn.dataset.item;
    document.getElementById('edit_qty').value = btn.dataset.qty;
    document.getElementById('edit_satuan').value = btn.dataset.satuan;
    document.getElementById('edit_harga').value = btn.dataset.harga;
    document.getElementById('edit_kategori').value = btn.dataset.kategori;
    openModal('modalEdit');
}

async function submitEditDetail(e) {
    e.preventDefault();
    const form = document.getElementById('formEditDetail') || e.target;
    const saveBtn = document.getElementById('btnSaveEditDetail');
    const origBtnHtml = saveBtn ? saveBtn.innerHTML : 'Simpan Perubahan';

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = 'Menyimpan...';
    }

    const formData = new FormData(form);
    formData.append('ajax', '1');

    try {
        const res = await fetch('menu.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();

        if (!json.success) {
            alert('Gagal menyimpan perubahan: ' + (json.message || 'Error'));
            return;
        }

        const data = json.data;
        const id = data.id_detail;

        let row = currentEditDetailBtn ? currentEditDetailBtn.closest('.item-row') : document.querySelector(`.item-row[data-id-detail="${id}"]`);
        if (row) {
            const oldKategori = currentEditDetailBtn ? (currentEditDetailBtn.dataset.kategori || '') : '';
            const oldName = currentEditDetailBtn ? (currentEditDetailBtn.dataset.item || '') : '';

            // Update nama barang
            const nameSpan = row.querySelector('.item-row-name');
            if (nameSpan) nameSpan.textContent = data.item_barang;

            // Format QTY (strip decimal jika bulat)
            const qtyNum = parseFloat(data.qty) || 0;
            const qtyFormatted = (qtyNum % 1 === 0) ? qtyNum.toString() : qtyNum.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
            const qtyChip = row.querySelector('.item-qty-chip');
            if (qtyChip) qtyChip.textContent = `${qtyFormatted} ${data.satuan}`;

            // Update badge kategori
            const badgeKat = row.querySelector('.badge-kategori');
            if (badgeKat) {
                badgeKat.textContent = data.kategori;
                const katStyles = {
                    'Karbohidrat': { color: '#2563eb', bg: '#eff6ff' },
                    'Protein': { color: '#dc2626', bg: '#fef2f2' },
                    'Sayuran': { color: '#16a34a', bg: '#f0fdf4' },
                    'Buah-buahan': { color: '#db2777', bg: '#fdf2f8' },
                    'Bumbu': { color: '#d97706', bg: '#fffbeb' },
                    'Pelengkap/Tambahan': { color: '#7c3aed', bg: '#f5f3ff' },
                };
                const st = katStyles[data.kategori] || { color: '#64748b', bg: '#f1f5f9' };
                badgeKat.style.color = st.color;
                badgeKat.style.background = st.bg;
            }

            // Update data attributes pada button edit
            const editBtn = row.querySelector('.action-btn-edit');
            if (editBtn) {
                editBtn.dataset.item = data.item_barang;
                editBtn.dataset.qty = data.qty;
                editBtn.dataset.satuan = data.satuan;
                editBtn.dataset.harga = data.harga_satuan;
                editBtn.dataset.kategori = data.kategori;
            }

            // Update kategori card summary
            const menuCard = row.closest('.menu-card');
            if (menuCard) {
                updateKategoriCardsOnEdit(menuCard, oldKategori, data.kategori, oldName, data.item_barang);
            }

            // Efek highlight
            row.style.transition = 'background-color 0.4s ease';
            const origBg = row.style.backgroundColor;
            row.style.backgroundColor = '#ecfdf5';
            setTimeout(() => { row.style.backgroundColor = origBg; }, 1200);
        }

        closeModal('modalEdit');
        showToast('✓ Perubahan item berhasil disimpan!', 'success');

    } catch (err) {
        alert('Terjadi kesalahan koneksi: ' + err.message);
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = origBtnHtml;
        }
    }
}

// ===== Hapus Item Tanpa Reload =====
async function deleteDetailItem(idDetail, btn) {
    if (!confirm('Yakin ingin menghapus item ini?')) return;

    const row = btn.closest('.item-row');
    const itemList = row ? row.closest('.item-list') : null;
    const menuCard = row ? row.closest('.menu-card') : null;

    btn.disabled = true;
    btn.style.opacity = '0.5';

    try {
        const formData = new FormData();
        formData.append('action', 'delete_detail');
        formData.append('id_detail', idDetail);
        formData.append('ajax', '1');

        const res = await fetch('menu.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();

        if (!json.success) {
            alert('Gagal menghapus item: ' + (json.message || 'Error'));
            btn.disabled = false;
            btn.style.opacity = '1';
            return;
        }

        const itemName = row.querySelector('.item-row-name') ? row.querySelector('.item-row-name').textContent.trim() : '';
        const editBtn = row.querySelector('.action-btn-edit');
        const itemKategori = editBtn ? (editBtn.dataset.kategori || '') : '';

        // Animasi hapus baris
        row.style.transition = 'all 0.3s ease';
        row.style.opacity = '0';
        row.style.transform = 'translateX(20px)';
        setTimeout(() => {
            row.remove();

            if (itemList) {
                const remainingRows = itemList.querySelectorAll('.item-row');
                remainingRows.forEach((r, idx) => {
                    const noSpan = r.querySelector('.item-row-no');
                    if (noSpan) noSpan.textContent = idx + 1;
                });

                if (menuCard) {
                    const footerStrong = menuCard.querySelector('.item-list-footer strong');
                    if (footerStrong) {
                        footerStrong.textContent = `${remainingRows.length} item`;
                    }
                    if (remainingRows.length === 0) {
                        const emptyDiv = document.createElement('div');
                        emptyDiv.className = 'item-list-empty';
                        emptyDiv.textContent = 'Belum ada item barang';
                        itemList.appendChild(emptyDiv);

                        const footer = menuCard.querySelector('.item-list-footer');
                        if (footer) footer.style.display = 'none';
                        const ringkasan = menuCard.querySelector('.ringkasan-status-box');
                        if (ringkasan) ringkasan.style.display = 'none';
                    }
                }
            }

            if (menuCard && itemKategori) {
                updateKategoriCardsOnDelete(menuCard, itemKategori, itemName);
            }

            showToast('✓ Item berhasil dihapus!', 'success');
        }, 300);

    } catch (err) {
        alert('Terjadi kesalahan koneksi: ' + err.message);
        btn.disabled = false;
        btn.style.opacity = '1';
    }
}

// ===== Helper Kategori Cards =====
function findKategoriCard(menuCard, katName) {
    if (!menuCard || !katName) return null;
    const cards = menuCard.querySelectorAll('.kategori-card');
    for (let c of cards) {
        const title = c.querySelector('.kategori-card-title');
        if (title && title.textContent.trim().toLowerCase() === katName.trim().toLowerCase()) {
            return c;
        }
    }
    return null;
}

function updateKategoriCardsOnDelete(menuCard, katName, itemName) {
    const card = findKategoriCard(menuCard, katName);
    if (!card) return;

    const countEl = card.querySelector('.kategori-card-count');
    let count = parseInt(countEl ? countEl.textContent : '0', 10) || 0;
    count = Math.max(0, count - 1);
    if (countEl) countEl.textContent = count;

    const list = card.querySelector('.kategori-item-list');
    if (list) {
        const items = list.querySelectorAll('.kategori-item');
        for (let it of items) {
            if (it.textContent.trim().toLowerCase() === itemName.trim().toLowerCase()) {
                it.remove();
                break;
            }
        }
    }

    if (count === 0) {
        const body = card.querySelector('.kategori-card-body');
        if (body) {
            body.innerHTML = '<div class="kategori-empty">Kosong</div>';
        }
    }
}

function updateKategoriCardsOnEdit(menuCard, oldKat, newKat, oldName, newName) {
    if (oldKat === newKat && oldName === newName) return;

    if (oldKat === newKat) {
        const card = findKategoriCard(menuCard, newKat);
        if (card) {
            const list = card.querySelector('.kategori-item-list');
            if (list) {
                const items = list.querySelectorAll('.kategori-item');
                for (let it of items) {
                    if (it.textContent.trim().toLowerCase() === oldName.trim().toLowerCase()) {
                        it.innerHTML = `<span class="kategori-item-dot"></span> ${escapeHtml(newName)}`;
                        break;
                    }
                }
            }
        }
    } else {
        if (oldKat) {
            updateKategoriCardsOnDelete(menuCard, oldKat, oldName);
        }

        const newCard = findKategoriCard(menuCard, newKat);
        if (newCard) {
            const countEl = newCard.querySelector('.kategori-card-count');
            let count = parseInt(countEl ? countEl.textContent : '0', 10) || 0;
            count++;
            if (countEl) countEl.textContent = count;

            const body = newCard.querySelector('.kategori-card-body');
            let list = newCard.querySelector('.kategori-item-list');
            if (!list && body) {
                body.innerHTML = '<ul class="kategori-item-list"></ul>';
                list = body.querySelector('.kategori-item-list');
            }
            if (list) {
                const empty = body.querySelector('.kategori-empty');
                if (empty) empty.remove();
                const li = document.createElement('li');
                li.className = 'kategori-item';
                li.innerHTML = `<span class="kategori-item-dot"></span> ${escapeHtml(newName)}`;
                list.appendChild(li);
            }
        }
    }
}

// ===== Tambah Barang Susulan =====
function openAddItemModal(idBelanja, judulMenu) {
    const form = document.getElementById('formAddItem');
    form.reset();
    document.getElementById('additem_id_belanja').value = idBelanja;
    document.getElementById('additem_judul_menu').textContent = `Menambahkan item untuk: ${judulMenu}`;
    openModal('modalAddItem');
}

async function submitAddItem(e) {
    e.preventDefault();
    const form = document.getElementById('formAddItem') || e.target;
    const saveBtn = document.getElementById('btnSaveAddItem');
    const origBtnHtml = saveBtn ? saveBtn.innerHTML : 'Simpan Barang';

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = 'Menyimpan...';
    }

    const formData = new FormData(form);
    formData.append('ajax', '1');

    try {
        const res = await fetch('menu.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();

        if (!json.success) {
            alert('Gagal menambah barang: ' + (json.message || 'Error'));
            return;
        }

        const data = json.data;
        const idBelanja = data.id_belanja;

        const menuCard = document.querySelector(`.menu-card[data-id-belanja="${idBelanja}"]`);
        if (menuCard) {
            const itemList = menuCard.querySelector('.item-list');
            if (itemList) {
                const emptyEl = itemList.querySelector('.item-list-empty');
                if (emptyEl) emptyEl.remove();

                const rows = itemList.querySelectorAll('.item-row');
                const nextNo = rows.length + 1;

                const qtyNum = parseFloat(data.qty) || 0;
                const qtyFormatted = (qtyNum % 1 === 0) ? qtyNum.toString() : qtyNum.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

                const katStyles = {
                    'Karbohidrat': { color: '#2563eb', bg: '#eff6ff' },
                    'Protein': { color: '#dc2626', bg: '#fef2f2' },
                    'Sayuran': { color: '#16a34a', bg: '#f0fdf4' },
                    'Buah-buahan': { color: '#db2777', bg: '#fdf2f8' },
                    'Bumbu': { color: '#d97706', bg: '#fffbeb' },
                    'Pelengkap/Tambahan': { color: '#7c3aed', bg: '#f5f3ff' },
                };
                const st = katStyles[data.kategori] || { color: '#64748b', bg: '#f1f5f9' };

                const newRow = document.createElement('div');
                newRow.className = 'item-row';
                newRow.dataset.idDetail = data.id_detail;

                let notaBtnHtml = '';
                if (data.jumlah_nota > 0) {
                    notaBtnHtml = `
                        <button type="button" class="action-btn action-btn-nota" onclick="viewPhotos(${data.id_detail}, 'nota', 1)" title="Lihat Nota (1)">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                                <line x1="16" y1="13" x2="8" y2="13" />
                                <line x1="16" y1="17" x2="8" y2="17" />
                            </svg>
                            <span>1</span>
                        </button>
                    `;
                }

                newRow.innerHTML = `
                    <div class="item-row-main">
                        <div class="item-row-info">
                            <span class="item-row-no">${nextNo}</span>
                            <div class="item-row-text">
                                <div class="item-row-name-line">
                                    <span class="item-row-name">${escapeHtml(data.item_barang)}</span>
                                    <span class="item-qty-chip">${qtyFormatted} ${escapeHtml(data.satuan)}</span>
                                </div>
                                <div class="item-row-meta">
                                    <span class="badge-kategori" style="background: ${st.bg}; color: ${st.color};">${escapeHtml(data.kategori)}</span>
                                    <span class="status-badge status-badge-belum">Belum Dicek</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="item-row-actions">
                        <div class="action-group">
                            ${notaBtnHtml}
                            <label class="action-btn action-btn-nota-add" title="Tambah Nota">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19" />
                                    <line x1="5" y1="12" x2="19" y2="12" />
                                </svg>
                                <input type="file" accept="image/*,.pdf" multiple hidden onchange="uploadInlinePhoto(this, 'add_nota', ${data.id_detail})">
                            </label>
                        </div>
                        <div class="action-group">
                            <label class="action-btn action-btn-galeri" title="Pilih dari Galeri">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2" />
                                    <circle cx="8.5" cy="8.5" r="1.5" />
                                    <polyline points="21 15 16 10 5 21" />
                                </svg>
                                <input type="file" accept="image/*" multiple hidden onchange="uploadInlinePhoto(this, 'add_foto_receiving', ${data.id_detail})">
                            </label>
                            <label class="action-btn action-btn-kamera" title="Ambil Foto dengan Kamera">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                                    <circle cx="12" cy="13" r="4" />
                                </svg>
                                <input type="file" accept="image/*" capture="environment" hidden onchange="uploadInlinePhoto(this, 'add_foto_receiving', ${data.id_detail})">
                            </label>
                        </div>
                        <div class="action-group action-group-end">
                            <button type="button" class="action-btn action-btn-edit" onclick="openEditItem(this)"
                                data-id="${data.id_detail}"
                                data-item="${escapeHtml(data.item_barang)}"
                                data-qty="${data.qty}"
                                data-satuan="${escapeHtml(data.satuan)}"
                                data-harga="${data.harga_satuan}"
                                data-kategori="${escapeHtml(data.kategori)}" title="Edit Item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                                </svg>
                            </button>
                            <button type="button" class="action-btn action-btn-delete" onclick="deleteDetailItem(${data.id_detail}, this)" title="Hapus Item">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6" />
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                    <line x1="10" y1="11" x2="10" y2="17" />
                                    <line x1="14" y1="11" x2="14" y2="17" />
                                </svg>
                            </button>
                        </div>
                    </div>
                `;

                itemList.appendChild(newRow);

                const footer = menuCard.querySelector('.item-list-footer');
                if (footer) {
                    footer.style.display = 'flex';
                    const footerStrong = footer.querySelector('strong');
                    if (footerStrong) footerStrong.textContent = `${nextNo} item`;
                }

                updateKategoriCardsOnEdit(menuCard, '', data.kategori, '', data.item_barang);

                newRow.style.transition = 'background-color 0.4s ease';
                newRow.style.backgroundColor = '#ecfdf5';
                setTimeout(() => { newRow.style.backgroundColor = ''; }, 1200);
            }
        }

        closeModal('modalAddItem');
        form.reset();
        showToast('✓ Barang susulan berhasil ditambahkan!', 'success');

    } catch (err) {
        alert('Terjadi kesalahan koneksi: ' + err.message);
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = origBtnHtml;
        }
    }
}

// ===== Upload Faktur TTD =====
function uploadFakturTTD(input, tanggal) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
        alert('⚠️ Ukuran file faktur maksimal 10MB.');
        input.value = '';
        return;
    }
    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `<div class="spinner"></div><p id="loadingText">Mengupload faktur...</p>`;
        loading.classList.add('active');
    }
    const doUpload = (uploadFile) => {
        const fd = new FormData();
        fd.append('action', 'add_faktur_ttd');
        fd.append('tanggal', tanggal);
        fd.append('foto', uploadFile);
        fetch('database/upload-faktur.php', { method: 'POST', body: fd })
            .then(async r => {
                const text = await r.text();
                try { return JSON.parse(text); }
                catch (e) { throw new Error('Server error: ' + text.substring(0, 100)); }
            })
            .then(result => {
                if (loading) loading.classList.remove('active');
                if (!result.success) {
                    alert('❌ Gagal upload faktur: ' + result.message);
                } else {
                    window.location.href = 'menu.php?faktur_uploaded=1';
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
            .catch(() => doUpload(file));
    } else {
        doUpload(file);
    }
    input.value = '';
}

// =====================================================
// ✅ FITUR: STATUS PER ITEM (OPERATOR)
// =====================================================

/**
 * Set status item (Lengkap / Tidak Ada) - langsung simpan
 */
async function setStatus(idDetail, status, btnEl) {
    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `<div class="spinner"></div><p>Menyimpan status...</p>`;
        loading.classList.add('active');
    }

    try {
        const fd = new FormData();
        fd.append('update_status_item', '1');
        fd.append('id_detail', idDetail);
        fd.append('status_item', status);

        const res = await fetch('menu.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (loading) loading.classList.remove('active');

        if (data.success) {
            // Update UI tombol
            const group = btnEl.closest('.status-toggle-group');
            group.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
            btnEl.classList.add('active');

            // Hapus keterangan lama kalau ada
            const itemRow = btnEl.closest('.item-row');
            const ketBox = itemRow.querySelector('.keterangan-kurang-box');
            if (ketBox) ketBox.remove();

            const statusLabel = status === 'lengkap' ? 'Lengkap ✓' : 'Tidak Ada ✗';
            showToast(`Status diubah ke: <strong>${statusLabel}</strong>`, 'success');

            // Update stats box kartu secara langsung tanpa reload
            const menuCard = btnEl.closest('.menu-card');
            if (menuCard) {
                updateCardRingkasanStats(menuCard);
            }
        } else {
            alert('❌ ' + (data.message || 'Gagal menyimpan status'));
        }
    } catch (err) {
        if (loading) loading.classList.remove('active');
        alert('❌ Error: ' + err.message);
    }
}

/**
 * Handle klik tombol "Kurang" - buka modal untuk isi keterangan
 */
function handleKurangClick(idDetail, btnEl) {
    const modal = document.getElementById('modalKeterangan');
    modal.dataset.idDetail = idDetail;
    modal.dataset.btnElement = Array.from(btnEl.parentNode.children).indexOf(btnEl);
    document.getElementById('keteranganInput').value = '';
    openModal('modalKeterangan');
    setTimeout(() => document.getElementById('keteranganInput').focus(), 200);
}

/**
 * Submit keterangan kurang
 */
async function submitKeteranganKurang() {
    const modal = document.getElementById('modalKeterangan');
    const idDetail = modal.dataset.idDetail;
    const keterangan = document.getElementById('keteranganInput').value.trim();

    if (keterangan.length < 5) {
        alert('⚠️ Keterangan minimal 5 karakter!');
        document.getElementById('keteranganInput').focus();
        return;
    }

    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `<div class="spinner"></div><p>Menyimpan status & keterangan...</p>`;
        loading.classList.add('active');
    }

    try {
        const fd = new FormData();
        fd.append('update_status_item', '1');
        fd.append('id_detail', idDetail);
        fd.append('status_item', 'kurang');
        fd.append('keterangan_kurang', keterangan);

        const res = await fetch('menu.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (loading) loading.classList.remove('active');

        if (data.success) {
            closeModal('modalKeterangan');
            showToast(`Status <strong>Kurang</strong> tersimpan`, 'success');

            // Update tombol baris terkait langsung di DOM
            const itemRow = document.querySelector(`.item-row [data-id="${idDetail}"]`)?.closest('.item-row')
                         || document.querySelector(`button[onclick*="viewPhotos(${idDetail},"]`)?.closest('.item-row')
                         || document.querySelector(`input[onchange*="${idDetail}"]`)?.closest('.item-row');
            if (itemRow) {
                const group = itemRow.querySelector('.status-toggle-group');
                if (group) {
                    group.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
                    const btnKurang = group.querySelector('.btn-kurang');
                    if (btnKurang) btnKurang.classList.add('active');
                }
                let ketBox = itemRow.querySelector('.keterangan-kurang-box');
                if (!ketBox) {
                    ketBox = document.createElement('div');
                    ketBox.className = 'keterangan-kurang-box';
                    const detailBox = itemRow.querySelector('.item-detail') || itemRow.querySelector('.item-main');
                    if (detailBox) detailBox.appendChild(ketBox);
                    else itemRow.appendChild(ketBox);
                }
                ketBox.innerHTML = `<em>Keterangan: ${keterangan}</em>`;

                const menuCard = itemRow.closest('.menu-card');
                if (menuCard) {
                    updateCardRingkasanStats(menuCard);
                }
            }
        } else {
            alert('❌ ' + (data.message || 'Gagal menyimpan'));
        }
    } catch (err) {
        if (loading) loading.classList.remove('active');
        alert('❌ Error: ' + err.message);
    }
}

// ===== Form Submit Validation =====
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('formBelanja');
    if (form) {
        form.addEventListener('submit', async e => {
            e.preventDefault(); // Stop normal form submission

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
                alert('Lengkapi semua field!');
                return;
            }

            const loading = document.getElementById('loadingOverlay');
            if (loading) {
                loading.innerHTML = `<div class="spinner"></div><p id="loadingText">Mengompres gambar...</p>`;
                loading.classList.add('active');
            }

            try {
                const fileInputs = form.querySelectorAll('input[type="file"]');
                for (const input of fileInputs) {
                    if (input.files && input.files.length > 0) {
                        const dataTransfer = new DataTransfer();
                        for (let i = 0; i < input.files.length; i++) {
                            const file = input.files[i];
                            if (file.size > 10 * 1024 * 1024) {
                                alert(`⚠️ File ${file.name} melebihi batas maksimal 10MB.`);
                                if (loading) loading.classList.remove('active');
                                return;
                            }
                            if (file.type.startsWith('image/') && file.type !== 'image/gif' && file.size > 1000 * 1024) {
                                const loadingText = document.getElementById('loadingText');
                                if (loadingText) {
                                    loadingText.textContent = `Mengompres ${file.name}...`;
                                }
                                const compressed = await compressImage(file, {
                                    maxWidth: 1800,
                                    maxHeight: 1800,
                                    quality: 0.8,
                                    maxSizeKB: 1000
                                });
                                dataTransfer.items.add(compressed);
                            } else {
                                dataTransfer.items.add(file);
                            }
                        }
                        input.files = dataTransfer.files;
                    }
                }
            } catch (err) {
                console.error('Error compress sebelum submit:', err);
            }

            if (loading) {
                const loadingText = document.getElementById('loadingText');
                if (loadingText) loadingText.textContent = 'Menyimpan data...';
            }

            form.submit();
        });
    }

    const formAddItem = document.getElementById('formAddItem');
    if (formAddItem) {
        formAddItem.addEventListener('submit', async e => {
            e.preventDefault(); // Stop normal form submission

            const loading = document.getElementById('loadingOverlay');
            if (loading) {
                loading.innerHTML = `<div class="spinner"></div><p id="loadingText">Mengompres gambar...</p>`;
                loading.classList.add('active');
            }

            try {
                const fileInputs = formAddItem.querySelectorAll('input[type="file"]');
                for (const input of fileInputs) {
                    if (input.files && input.files.length > 0) {
                        const dataTransfer = new DataTransfer();
                        for (let i = 0; i < input.files.length; i++) {
                            const file = input.files[i];
                            if (file.size > 10 * 1024 * 1024) {
                                alert(`⚠️ File ${file.name} melebihi batas maksimal 10MB.`);
                                if (loading) loading.classList.remove('active');
                                return;
                            }
                            if (file.type.startsWith('image/') && file.type !== 'image/gif' && file.size > 1000 * 1024) {
                                const loadingText = document.getElementById('loadingText');
                                if (loadingText) {
                                    loadingText.textContent = `Mengompres ${file.name}...`;
                                }
                                const compressed = await compressImage(file, {
                                    maxWidth: 1800,
                                    maxHeight: 1800,
                                    quality: 0.8,
                                    maxSizeKB: 1000
                                });
                                dataTransfer.items.add(compressed);
                            } else {
                                dataTransfer.items.add(file);
                            }
                        }
                        input.files = dataTransfer.files;
                    }
                }
            } catch (err) {
                console.error('Error compress sebelum submit:', err);
            }

            if (loading) {
                const loadingText = document.getElementById('loadingText');
                if (loadingText) loadingText.textContent = 'Menyimpan data...';
            }

            formAddItem.submit();
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
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ===== POPUP PILIHAN UPLOAD FOTO MENU =====
function showUploadMenuOptions(idBelanja) {
    const oldPopup = document.getElementById('uploadMenuPopup');
    if (oldPopup) oldPopup.remove();
    const popup = document.createElement('div');
    popup.id = 'uploadMenuPopup';
    popup.className = 'upload-menu-popup';
    popup.innerHTML = `
        <div class="upload-menu-popup-content">
            <div class="upload-menu-popup-title">Pilih cara upload foto</div>
            <button class="upload-menu-popup-btn btn-kamera-opt" onclick="triggerMenuPhoto('kamera', ${idBelanja})">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <span>Ambil Foto</span>
                <small>Buka kamera belakang</small>
            </button>
            <button class="upload-menu-popup-btn btn-galeri-opt" onclick="triggerMenuPhoto('galeri', ${idBelanja})">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <span>Pilih dari Galeri</span>
                <small>Bisa pilih banyak foto</small>
            </button>
            <button class="upload-menu-popup-btn btn-cancel-opt" onclick="closeUploadMenuPopup()">Batal</button>
        </div>
    `;
    document.body.appendChild(popup);
    setTimeout(() => popup.classList.add('active'), 10);
}
function triggerMenuPhoto(type, idBelanja) {
    const inputId = type === 'kamera' ? `menuPhotoKamera_${idBelanja}` : `menuPhotoGaleri_${idBelanja}`;
    const input = document.getElementById(inputId);
    if (input) input.click();
    closeUploadMenuPopup();
}
function closeUploadMenuPopup() {
    const popup = document.getElementById('uploadMenuPopup');
    if (popup) {
        popup.classList.remove('active');
        setTimeout(() => popup.remove(), 200);
    }
}
document.addEventListener('click', function (e) {
    const popup = document.getElementById('uploadMenuPopup');
    if (popup && !popup.contains(e.target) && !e.target.closest('.btn-upload-menu')) {
        closeUploadMenuPopup();
    }
});

// =====================================================
// ✅ FITUR TARIK DARI DOMPET HARIAN
// =====================================================
let daftarMenuDompetCache = [];
let debounceMenuDompetTimer = null;

function openModalTarikDompet() {
    openModal('modalTarikDompet');
    const inputSearch = document.getElementById('searchMenuDompet');
    if (inputSearch) inputSearch.value = '';
    loadDaftarMenuDompet();
}

function debounceFilterMenuDompet(query) {
    clearTimeout(debounceMenuDompetTimer);
    debounceMenuDompetTimer = setTimeout(() => {
        filterMenuDompetLocal(query);
    }, 200);
}

function filterMenuDompetLocal(query) {
    const q = query.trim().toLowerCase();
    if (!q) {
        renderListMenuDompet(daftarMenuDompetCache);
        return;
    }
    const filtered = daftarMenuDompetCache.filter(m => {
        const nama = (m.nama_menu || '').toLowerCase();
        const tgl = (m.tanggal || '').toLowerCase();
        return nama.includes(q) || tgl.includes(q);
    });
    renderListMenuDompet(filtered);
}

async function loadDaftarMenuDompet() {
    const container = document.getElementById('listMenuDompetContainer');
    if (!container) return;

    container.innerHTML = `
        <div style="text-align:center; padding:30px; color:#64748b;">
            <div class="spinner" style="margin:0 auto 10px; width:28px; height:28px; border-width:3px;"></div>
            Memuat daftar menu dari Dompet Harian...
        </div>
    `;

    try {
        const res = await fetch('database/api-dompet-harian.php?action=list');
        const json = await res.json();

        if (!json.success) {
            container.innerHTML = `<div style="text-align:center; padding:24px; color:#ef4444;">Gagal memuat menu: ${escapeHtml(json.message || 'Error')}</div>`;
            return;
        }

        daftarMenuDompetCache = json.data || [];
        renderListMenuDompet(daftarMenuDompetCache);
    } catch (err) {
        container.innerHTML = `<div style="text-align:center; padding:24px; color:#ef4444;">Koneksi error: ${escapeHtml(err.message)}</div>`;
    }
}

function renderListMenuDompet(menus) {
    const container = document.getElementById('listMenuDompetContainer');
    if (!container) return;

    if (!menus || menus.length === 0) {
        container.innerHTML = `
            <div style="text-align:center; padding:36px 20px; color:#94a3b8;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 10px; display:block; opacity:0.6;">
                    <circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
                <div style="font-weight:600; color:#475569;">Tidak ada data menu ditemukan</div>
                <small>Pastikan data belanja sudah dicatat di Dompet Harian.</small>
            </div>
        `;
        return;
    }

    let html = '';
    menus.forEach(m => {
        const tglIndo = formatTanggalIndo(m.tanggal);
        const statusClass = (m.status === 'approved') ? 'approved' : 'pending';
        const statusLabel = (m.status === 'approved') ? 'Disetujui' : (m.status || 'Pending');
        const porsi = Number(m.jumlah_porsi || 0).toLocaleString('id-ID');
        const totalItems = m.total_items || 0;

        html += `
            <div class="menu-dompet-item" onclick="pilihMenuDompet(${m.id})" title="Klik untuk tarik menu ini">
                <div style="flex:1;">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                        <span style="font-size:12px; font-weight:600; color:#0284c7; background:#e0f2fe; padding:2px 8px; border-radius:6px;">
                            ${escapeHtml(tglIndo)}
                        </span>
                        <span class="badge-status-dh ${statusClass}">${escapeHtml(statusLabel)}</span>
                    </div>
                    <div style="font-weight:700; font-size:15px; color:#0f172a;">${escapeHtml(m.nama_menu)}</div>
                    <div style="font-size:12.5px; color:#64748b; margin-top:3px; display:flex; gap:14px; flex-wrap:wrap;">
                        <span>Porsi: <strong style="color:#334155;">${porsi}</strong></span>
                        <span>Item Belanja: <strong style="color:#334155;">${totalItems} item</strong></span>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-primary" style="flex-shrink:0; pointer-events:none; padding:8px 14px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                    <span>Pilih</span>
                </button>
            </div>
        `;
    });

    container.innerHTML = html;
}

function formatTanggalIndo(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const b = parseInt(parts[1], 10) - 1;
    return `${parts[2]} ${bulan[b] || parts[1]} ${parts[0]}`;
}

async function pilihMenuDompet(id) {
    const loading = document.getElementById('loadingOverlay');
    const loadingText = document.getElementById('loadingText');
    if (loading) {
        if (loadingText) loadingText.textContent = 'Menarik data menu & menyaring bahan makanan...';
        loading.classList.add('active');
    }

    try {
        const res = await fetch(`database/api-dompet-harian.php?action=detail&id=${id}`);
        const json = await res.json();

        if (!json.success) {
            alert('Gagal mengambil detail menu: ' + (json.message || 'Error'));
            return;
        }

        const menu = json.menu;
        const items = json.items || [];
        const excluded = json.excluded || [];

        // 1. Isi data Header Menu
        const inputTanggal = document.querySelector('input[name="tanggal"]');
        if (inputTanggal && menu.tanggal) {
            inputTanggal.value = menu.tanggal;
            if (typeof updateNoFaktur === 'function') updateNoFaktur();
        }

        const inputJudul = document.querySelector('input[name="judul"]');
        if (inputJudul && menu.nama_menu) {
            inputJudul.value = menu.nama_menu;
        }

        const inputPorsi = document.querySelector('input[name="porsi"]');
        if (inputPorsi && menu.porsi !== undefined) {
            inputPorsi.value = menu.porsi;
        }

        // 2. Kosongkan tabel bahan dan isi dengan item bahan makanan hasil filter
        const tbody = document.querySelector('#tableItem tbody');
        if (tbody) {
            tbody.innerHTML = '';
            rowIndex = 0; // Reset index
            if (items.length > 0) {
                items.forEach(it => addRow(it));
            } else {
                addRow(); // Default 1 baris kosong jika tidak ada item
            }
        }

        // 3. Tampilkan Notice Pengecualian jika ada item bensin, sewa, insentif
        const noticeWrap = document.getElementById('noticeExcludedWrap');
        if (noticeWrap) {
            if (excluded.length > 0) {
                const listExcludedHtml = excluded.map(e => `
                    <span style="display:inline-block; background:rgba(217,119,6,0.12); padding:2px 8px; border-radius:6px; margin:2px 4px 2px 0; font-size:12px; font-weight:600;">
                        ${escapeHtml(e.nama_barang)} <small style="font-weight:normal; opacity:0.85;">(${escapeHtml(e.alasan)})</small>
                    </span>
                `).join('');

                noticeWrap.innerHTML = `
                    <div class="notice-excluded-box">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:1px;">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        <div style="flex:1;">
                            <div style="font-weight:700; color:#b45309; margin-bottom:4px;">
                                ${excluded.length} Item Biaya Operasional Otomatis Dikecualikan:
                            </div>
                            <div style="margin-bottom:6px;">${listExcludedHtml}</div>
                            <div style="font-size:12px; color:#78350f;">
                                Item-item operasional non-pangan di atas tidak dimasukkan ke dalam rincian menu dapur MBG.
                            </div>
                        </div>
                    </div>
                `;
                noticeWrap.style.display = 'block';
            } else {
                noticeWrap.innerHTML = '';
                noticeWrap.style.display = 'none';
            }
        }

        // 4. Tutup modal pemilih dompet
        closeModal('modalTarikDompet');

        // 5. Berikan feedback toast notifikasi
        showToast(`Menu "<strong>${escapeHtml(menu.nama_menu)}</strong>" berhasil ditarik! Kategori gizi telah terisi otomatis.`, 'success');

    } catch (err) {
        alert('Terjadi kesalahan saat memproses data: ' + err.message);
    } finally {
        if (loading) loading.classList.remove('active');
    }
}