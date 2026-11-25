document.querySelectorAll('[data-ingredients-editor]').forEach((editor) => {
    const rows = editor.querySelector('[data-ingredient-rows]');
    const template = editor.querySelector('[data-ingredient-template]');
    const add = editor.querySelector('[data-add-ingredient]');
    const status = editor.querySelector('[data-ingredient-status]');
    let nextIndex = Math.max(-1, ...Array.from(rows.querySelectorAll('input[name]'), (input) => Number(input.name.match(/ingredients\[(\d+)\]/)?.[1] ?? -1))) + 1;
    const update = () => { add.disabled = rows.children.length >= 20; };
    add.addEventListener('click', () => {
        if (rows.children.length >= 20) return;
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach((input) => { input.name = input.name.replace('__INDEX__', String(nextIndex)); });
        nextIndex += 1;
        const input = fragment.querySelector('input');
        rows.append(fragment);
        input.focus();
        status.textContent = 'Baris kandungan ditambahkan.';
        update();
    });
    rows.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-remove-ingredient]');
        if (!button) return;
        const row = button.closest('[data-ingredient-row]');
        const hasValue = Array.from(row.querySelectorAll('input,select')).some((input) => input.value.trim());
        if (hasValue && !(await window.AppConfirm.open({title: 'Hapus baris kandungan', message: 'Hapus kandungan ini dari formulir? Perubahan berlaku setelah produk disimpan.', confirmLabel: 'Hapus baris', variant: 'danger'}))) return;
        row.remove();
        add.focus();
        status.textContent = 'Baris kandungan dihapus. Simpan produk untuk menerapkan perubahan.';
        update();
    });
    update();
});
