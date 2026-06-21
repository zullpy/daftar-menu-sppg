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
async function updateNoFaktur() {
    const tglInput = document.querySelector('input[name="tanggal"]');
    const fakturInput = document.querySelector('input[name="no_faktur"]');

    if (!tglInput.value) return;

    const res = await fetch(
        `../database/get-no-faktur.php?tanggal=${tglInput.value}`
    );

    fakturInput.value = await res.text();
}

document.querySelector('input[name="tanggal"]')
    .addEventListener('change', updateNoFaktur);

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
// 🗜️ FUNGSI COMPRESS GAMBAR (BARU!)
// =====================================================
/**
 * Compress gambar menggunakan Canvas API
 * @param {File} file - File gambar yang akan di-compress
 * @param {Object} options - Opsi compress
 * @returns {Promise<File>} - File yang sudah di-compress
 */
function compressImage(file, options = {}) {
    const {
        maxWidth = 1600,        // Max lebar (px)
        maxHeight = 1600,       // Max tinggi (px)
        quality = 0.7,          // Quality JPEG (0-1)
        maxSizeKB = 800,        // Target max size dalam KB
        minQuality = 0.4        // Quality minimum kalau masih kebesaran
    } = options;

    return new Promise((resolve, reject) => {
        // Skip kalau bukan gambar atau sudah kecil
        if (!file.type.startsWith('image/') || file.type === 'image/gif') {
            resolve(file);
            return;
        }

        // Skip kalau sudah kecil (< 500KB)
        if (file.size < 500 * 1024) {
            resolve(file);
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                // Hitung dimensi baru (maintain aspect ratio)
                let width = img.width;
                let height = img.height;

                if (width > maxWidth || height > maxHeight) {
                    const ratio = Math.min(maxWidth / width, maxHeight / height);
                    width = Math.round(width * ratio);
                    height = Math.round(height * ratio);
                }

                // Buat canvas
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');

                // Background putih (untuk JPEG transparan)
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(0, 0, width, height);

                // Gambar dengan smoothing
                ctx.imageSmoothingEnabled = true;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, 0, 0, width, height);

                // Compress dengan quality bertahap
                let currentQuality = quality;
                const tryCompress = (q) => {
                    canvas.toBlob((blob) => {
                        if (!blob) {
                            reject(new Error('Gagal compress gambar'));
                            return;
                        }

                        // Kalau masih kebesaran dan quality masih di atas minimum, coba lagi
                        if (blob.size > maxSizeKB * 1024 && q > minQuality) {
                            tryCompress(q - 0.1);
                            return;
                        }

                        // Convert blob ke File
                        const compressedFile = new File(
                            [blob],
                            file.name.replace(/\.[^.]+$/, '.jpg'),
                            { type: 'image/jpeg', lastModified: Date.now() }
                        );

                        console.log(`🗜️ Compress: ${formatSize(file.size)} → ${formatSize(blob.size)} (${Math.round((1 - blob.size / file.size) * 100)}% reduction)`);
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

// Helper: format ukuran file
function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

// =====================================================
// 📤 UPLOAD INLINE PHOTO (UPDATED - DENGAN COMPRESS)
// =====================================================
function uploadInlinePhoto(input, action, id) {
    const files = input.files;
    if (!files || files.length === 0) return;

    const loading = document.getElementById('loadingOverlay');
    if (loading) {
        loading.innerHTML = `
            <div class="spinner"></div>
            <p id="loadingText">Mempersiapkan gambar...</p>
        `;
        loading.classList.add('active');
    }

    // Tentukan apakah perlu compress berdasarkan action
    const needCompress = (action === 'add_foto_receiving' || action === 'add_menu_photo');

    // Proses semua file (compress jika perlu)
    const processFiles = async () => {
        const processedFiles = [];
        const totalFiles = files.length;

        for (let i = 0; i < totalFiles; i++) {
            const file = files[i];

            // Update loading text
            const loadingText = document.getElementById('loadingText');
            if (loadingText) {
                if (needCompress && file.type.startsWith('image/') && file.type !== 'image/gif') {
                    loadingText.textContent = `Mengcompress gambar ${i + 1}/${totalFiles}...`;
                } else {
                    loadingText.textContent = `Memproses file ${i + 1}/${totalFiles}...`;
                }
            }

            try {
                if (needCompress && file.type.startsWith('image/') && file.type !== 'image/gif') {
                    // Compress gambar
                    const compressed = await compressImage(file, {
                        maxWidth: 1600,
                        maxHeight: 1600,
                        quality: 0.75,
                        maxSizeKB: 800
                    });
                    processedFiles.push(compressed);
                } else {
                    // File PDF atau GIF, skip compress
                    processedFiles.push(file);
                }
            } catch (err) {
                console.error('Error compress:', err);
                processedFiles.push(file); // Fallback: pakai file asli
            }
        }

        return processedFiles;
    };

    processFiles().then(processedFiles => {
        // Update loading text
        const loadingText = document.getElementById('loadingText');
        if (loadingText) loadingText.textContent = 'Mengupload...';

        // Upload semua file yang sudah di-compress
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
        // Reset loading HTML
        if (loading) {
            loading.innerHTML = `<div class="spinner"></div><p>Memproses...</p>`;
        }

        const failed = results.find(r => !r.success);
        if (failed) {
            alert('❌ Gagal upload: ' + failed.message);
        } else {
            location.reload();
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

// ===== Tambah Barang Susulan (item yang lupa diinput) =====
function openAddItemModal(idBelanja, judulMenu) {
    const form = document.getElementById('formAddItem');
    form.reset();
    document.getElementById('additem_id_belanja').value = idBelanja;
    document.getElementById('additem_judul_menu').textContent = `Menambahkan item untuk: ${judulMenu}`;
    openModal('modalAddItem');
}

// ===== Upload Faktur Yang Sudah Ditandatangani =====
function uploadFakturTTD(input, tanggal) {
    const file = input.files[0];
    if (!file) return;

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

    // Compress dulu kalau file-nya gambar, biar tidak berat (PDF dilewati)
    if (file.type.startsWith('image/') && file.type !== 'image/gif') {
        compressImage(file, { maxWidth: 1800, maxHeight: 1800, quality: 0.8, maxSizeKB: 1000 })
            .then(doUpload)
            .catch(() => doUpload(file));
    } else {
        doUpload(file);
    }

    input.value = '';
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

// ===== POPUP PILIHAN UPLOAD FOTO MENU =====
function showUploadMenuOptions(idBelanja) {
    // Hapus popup lama kalau ada
    const oldPopup = document.getElementById('uploadMenuPopup');
    if (oldPopup) oldPopup.remove();

    // Buat popup baru
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
            <button class="upload-menu-popup-btn btn-cancel-opt" onclick="closeUploadMenuPopup()">
                Batal
            </button>
        </div>
    `;
    document.body.appendChild(popup);

    // Animasi masuk
    setTimeout(() => popup.classList.add('active'), 10);
}

function triggerMenuPhoto(type, idBelanja) {
    const inputId = type === 'kamera'
        ? `menuPhotoKamera_${idBelanja}`
        : `menuPhotoGaleri_${idBelanja}`;
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

// Tutup popup kalau klik di luar
document.addEventListener('click', function (e) {
    const popup = document.getElementById('uploadMenuPopup');
    if (popup && !popup.contains(e.target) && !e.target.closest('.btn-upload-menu')) {
        closeUploadMenuPopup();
    }
});