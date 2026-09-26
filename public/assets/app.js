'use strict';

const menuButton = document.querySelector('.menu-toggle');
const sidebar = document.querySelector('#sidebar');
menuButton?.addEventListener('click', () => {
    const open = sidebar.classList.toggle('is-open');
    menuButton.setAttribute('aria-expanded', String(open));
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && sidebar) {
        sidebar.classList.remove('is-open');
        menuButton?.setAttribute('aria-expanded', 'false');
    }
});
document.addEventListener('click', event => {
    if (sidebar?.classList.contains('is-open') && !sidebar.contains(event.target) && !menuButton?.contains(event.target)) {
        sidebar.classList.remove('is-open');
        menuButton?.setAttribute('aria-expanded', 'false');
    }
});
document.querySelectorAll('[data-go-back]').forEach(button => button.addEventListener('click', () => history.back()));
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    const field = document.getElementById(button.dataset.copy);
    try {
        await navigator.clipboard.writeText(field.value);
        button.textContent = 'Copiado';
    } catch {
        field.focus();
        field.select();
        button.textContent = 'Seleccionado';
    }
}));

const licenseForm = document.querySelector('#license-form');
function updateLicenseForm() {
    if (!licenseForm) return;
    const product = licenseForm.querySelector('[name="product_id"]').value;
    const type = licenseForm.querySelector('[name="license_type"]:checked').value;
    licenseForm.querySelectorAll('[data-product]').forEach(group => {
        const selected = group.dataset.product === product;
        group.hidden = !selected;
        group.querySelectorAll('input').forEach(input => { input.disabled = !selected; });
    });
    licenseForm.querySelectorAll('[data-license-type]').forEach(group => {
        const selected = group.dataset.licenseType === type;
        group.hidden = !selected;
        group.querySelectorAll('input').forEach(input => { input.disabled = !selected; input.required = selected; });
    });
}
licenseForm?.querySelector('[name="product_id"]').addEventListener('change', updateLicenseForm);
licenseForm?.querySelectorAll('[name="license_type"]').forEach(input => input.addEventListener('change', updateLicenseForm));
updateLicenseForm();

document.querySelectorAll('.feature-grid').forEach(group => {
    group.addEventListener('change', event => {
        if (!event.target.matches('input[type="checkbox"]')) return;
        const inputs = [...group.querySelectorAll('input[type="checkbox"]')];
        const visited = new Set();
        function enableRequired(input) {
            if (visited.has(input.value)) return;
            visited.add(input.value);
            for (const key of (input.dataset.requires || '').split(',').filter(Boolean)) {
                const required = inputs.find(candidate => candidate.value === key);
                if (required) { required.checked = true; enableRequired(required); }
            }
        }
        if (event.target.checked) {
            enableRequired(event.target);
        } else {
            let changed = true;
            while (changed) {
                changed = false;
                for (const input of inputs.filter(candidate => candidate.checked)) {
                    const missing = (input.dataset.requires || '').split(',').filter(Boolean).some(key => !inputs.some(candidate => candidate.value === key && candidate.checked));
                    if (missing) { input.checked = false; changed = true; }
                }
            }
        }
    });
});
document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});
