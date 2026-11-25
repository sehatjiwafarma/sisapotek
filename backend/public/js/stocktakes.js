'use strict';
(() => {
  const page = document.querySelector('.stocktake-page');
  const form = page?.querySelector('[data-count-form]');
  if (!form || !form.querySelector('[data-physical]')) return;
  let dirty = false;
  const scaled = value => {
    const match = String(value).match(/^(-?)(\d+)(?:\.(\d*))?$/);
    return match ? BigInt(match[2] + (match[3] || '').padEnd(4,'0').slice(0,4)) * (match[1] ? -1n : 1n) : 0n;
  };
  function update(row) {
    const physical = row.querySelector('[data-physical]');
    const difference = scaled(physical.value) - scaled(row.dataset.system);
    const output = row.querySelector('[data-difference]');
    output.textContent = physical.value === '' ? '—' : (Number(difference)/10000).toLocaleString('id-ID',{maximumFractionDigits:4});
    output.toggleAttribute('data-nonzero',physical.value !== '' && difference !== 0n);
    const reason = row.querySelector('[data-reason]'); reason.required = physical.value !== '' && difference !== 0n; if (reason.required && !reason.value) reason.closest('details')?.setAttribute('open','');
  }
  form.querySelectorAll('[data-count-row]').forEach(row => {
    update(row);
    row.addEventListener('input',() => {
      dirty = true; update(row);
      form.querySelector('[data-count-state]').textContent = 'Ada perubahan yang belum disimpan.';
    });
  });
  document.addEventListener('submit',event => {
    if (!dirty || event.target === form) return;
    event.preventDefault();
    const warning = page.querySelector('[data-count-warning]');
    warning.hidden = false; warning.scrollIntoView({block:'center'});
  },true);

})();
