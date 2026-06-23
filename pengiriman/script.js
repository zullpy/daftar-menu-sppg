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