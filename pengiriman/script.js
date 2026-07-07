// Toggle Date Group
function toggleDate(el) {
    const content = el.nextElementSibling;
    content.classList.toggle('collapsed');
    el.classList.toggle('collapsed');
}

// Toggle Accordion
function toggleAccordion(el) {
    const content = el.nextElementSibling;
    content.classList.toggle('show');
    el.classList.toggle('active');
    // Inject labels when opened
    if (content.classList.contains('show')) {
        content.querySelectorAll('.table-detail').forEach(injectTableLabels);
    }
}

// Auto-hide alerts
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => alert.remove(), 500);
        }, 4000);
    });
});

// Inject data-label on table-detail for mobile card view
function injectTableLabels(table) {
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
    table.querySelectorAll('tbody tr').forEach(row => {
        row.querySelectorAll('td').forEach((td, i) => {
            if (headers[i]) td.setAttribute('data-label', headers[i]);
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    // Inject labels for any tables already visible
    document.querySelectorAll('.table-detail').forEach(injectTableLabels);
});

// ── Modal Foto Kemasan ──
function bukaFotoModal(src, namaBarang, keterangan) {
    const overlay = document.getElementById('fotoModalOverlay');
    const img = document.getElementById('fotoModalImg');
    const title = document.getElementById('fotoModalTitle');
    const caption = document.getElementById('fotoModalCaption');
    if (!overlay || !img) return;

    img.src = src;
    title.textContent = namaBarang ? 'Foto Kemasan — ' + namaBarang : 'Foto Kemasan';
    if (keterangan) {
        caption.textContent = keterangan;
        caption.style.display = '';
    } else {
        caption.textContent = '';
        caption.style.display = 'none';
    }
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function tutupFotoModal() {
    const overlay = document.getElementById('fotoModalOverlay');
    if (!overlay) return;
    overlay.classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') tutupFotoModal();
});