'use strict';
(() => {
    const type = document.querySelector('[data-recipient-type]');
    const fields = document.querySelector('[data-recipient-fields]');
    if (!type || !fields) return;
    function refresh() {
        const representative = type.value === 'representative';
        fields.hidden = !representative;
        fields.querySelectorAll('input').forEach(input => {
            input.required = representative;
            input.disabled = !representative;
        });
    }
    type.addEventListener('change', refresh);
    refresh();
})();

(() => {
    const data = document.querySelector('#substitution-check-data');
    if (!data) return;
    const checks = JSON.parse(data.textContent);
    document.querySelectorAll('[data-prescribed-product]').forEach(select => {
        const row = select.closest('tr');
        const details = row.querySelector('.dispensing-substitution');
        const status = details.querySelector('[data-substitution-status]');
        const entitlement = details.querySelector('input[name$="[prescribed_quantity]"]');
        const quantity = row.querySelector('input[name$="[quantity]"]');
        const confirmed = details.querySelector('input[type="checkbox"]');
        let manuallyEdited = entitlement.value !== '';
        const refresh = (changedProduct = false) => {
            const substitute = select.value !== select.dataset.prescribedProduct;
            if (!substitute) { status.textContent = ''; confirmed.required = false; return; }
            const check = checks[select.dataset.prescribedProduct]?.[select.value];
            details.open = true;
            confirmed.required = Number(quantity.value) > 0 && !confirmed.disabled;
            if (changedProduct) { confirmed.checked = false; manuallyEdited = false; entitlement.value = ''; }
            status.textContent = check?.message || 'Data produk tidak tersedia untuk diperiksa.';
            if (check?.ok && !manuallyEdited && Number(quantity.value) > 0) {
                entitlement.value = String(Number((Number(quantity.value) * check.ratio).toFixed(4)));
            }
            if (check?.ok && Number(quantity.value) > 0) {
                status.textContent += ' Perkiraan hak resep: ' + Number((Number(quantity.value) * check.ratio).toFixed(4)) + '. Periksa sebelum menyimpan.';
            }
        };
        entitlement.addEventListener('input', () => { manuallyEdited = true; });
        quantity.addEventListener('input', () => refresh());
        select.addEventListener('change', () => refresh(true));
        refresh();
    });
})();