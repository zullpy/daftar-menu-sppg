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