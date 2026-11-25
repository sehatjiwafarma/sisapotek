'use strict';
const boxes = [...document.querySelectorAll('input[name="permissions[]"]')];
const counter = document.querySelector('#permission-count');
const modules = [...document.querySelectorAll('.permission-card[data-module]')];
function updateCount() {
  if (counter) counter.textContent = `${boxes.filter(box => box.checked).length} izin dipilih`;
  modules.forEach(group => {
    const options = [...group.querySelectorAll('input[name="permissions[]"]')];
    const available = options.filter(box => !box.disabled);
    const selected = options.filter(box => box.checked).length;
    const toggle = group.querySelector('[data-module-toggle]');
    group.querySelector('.permission-module-count').textContent = `${selected} dari ${options.length} izin`;
    toggle.disabled = !available.length;
    toggle.checked = available.length > 0 && available.every(box => box.checked);
    toggle.indeterminate = available.some(box => box.checked) && !toggle.checked;
  });
}
boxes.forEach(box => box.addEventListener('change', updateCount));
modules.forEach(group => group.querySelector('[data-module-toggle]').addEventListener('change', event => {
  group.querySelectorAll('input[name="permissions[]"]:not(:disabled)').forEach(box => { box.checked = event.target.checked; });
  updateCount();
}));
document.querySelectorAll('[data-permissions]').forEach(button => button.addEventListener('click', () => {
  modules.filter(group => !group.hidden).forEach(group => {
    group.querySelectorAll('input[name="permissions[]"]:not(:disabled)').forEach(box => { box.checked = button.dataset.permissions === 'all'; });
  });
  updateCount();
}));
document.querySelector('#permission-search')?.addEventListener('input', event => {
  const term = event.target.value.toLocaleLowerCase('id').trim();
  modules.forEach(group => { group.hidden = !group.dataset.search.includes(term); });
  document.querySelector('#permission-empty').hidden = modules.some(group => !group.hidden);
  document.querySelector('#permission-help').textContent = term
    ? 'Pilihan massal hanya berlaku pada modul hasil pencarian. Pilihan pada modul lain tetap tersimpan.'
    : 'Pilihan berlaku untuk semua modul. Izin yang tidak dapat Anda berikan ditandai terkunci.';
  document.querySelector('[data-permissions="all"]').textContent = term ? 'Pilih hasil pencarian' : 'Pilih semua';
  document.querySelector('[data-permissions="none"]').textContent = term ? 'Kosongkan hasil pencarian' : 'Kosongkan pilihan';
  document.querySelectorAll('[data-permissions]').forEach(button => { button.disabled = !modules.some(group => !group.hidden); });
});
document.querySelector('#permission-codes')?.addEventListener('change', event => {
  document.querySelector('#permission-grid').classList.toggle('show-permission-codes', event.target.checked);
});
updateCount();
