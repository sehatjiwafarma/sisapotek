'use strict';
(() => {
  const list = document.querySelector('[data-selling-units]');
  if (!list) return;
  const renumber = () => list.querySelectorAll('[data-selling-unit-row]').forEach((row, index) => {
    row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/selling_units\[[^\]]+\]/, `selling_units[${index}]`); });
  });
  document.querySelector('[data-selling-unit-add]').addEventListener('click', () => {
    if (list.children.length >= 20) return;
    list.append(document.querySelector('[data-selling-unit-template]').content.cloneNode(true));
    renumber();
    list.lastElementChild.querySelector('select').focus();
  });
  list.addEventListener('click', async event => {
    const button = event.target.closest('[data-selling-unit-remove]');
    if (!button) return;
    if (!await window.AppConfirm.open({title: 'Hapus satuan jual', message: 'Hapus satuan ini dari formulir? Perubahan berlaku setelah disimpan.', confirmLabel: 'Hapus satuan', variant: 'danger'})) return;
    button.closest('[data-selling-unit-row]').remove();
    renumber();
  });
})();
