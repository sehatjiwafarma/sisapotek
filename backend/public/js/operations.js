document.addEventListener('click', event => {
    if (event.target.closest('[data-print-operation]')) window.print();
    const add = event.target.closest('[data-add-service]');
    if (add) {
        const form = add.closest('form');
        const body = form.querySelector('[data-service-lines]');
        const template = form.querySelector('[data-service-template]');
        if (!body || !template || body.children.length >= 100) return;
        const next = Math.max(-1, ...Array.from(body.querySelectorAll('[name]'), input => Number(input.name.match(/items\[(\d+)\]/)?.[1] ?? -1))) + 1;
        const row = template.content.cloneNode(true);
        row.querySelectorAll('[data-service-field]').forEach(input => input.name = `items[${next}][${input.dataset.serviceField}]`);
        body.append(row); window.AppForms?.mark(form);
        body.lastElementChild.querySelector('select')?.focus();
    }
    const remove = event.target.closest('[data-remove-service]');
    if (remove) {
        const row = remove.closest('tr'); window.AppForms?.mark(row.closest('form'));
        if (row.parentElement.children.length > 1) row.remove();
        else {
            row.querySelector('select').value = '';
            row.querySelector('input').value = '1';
            row.querySelector('select').focus();
        }
    }
});
