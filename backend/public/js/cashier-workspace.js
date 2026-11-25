'use strict';
(() => {
  const search = document.querySelector('[data-cashier-search]');
  const table = document.querySelector('[data-cashier-table]');
  if (!search || !table) return;
  const empty = table.querySelector('[data-cashier-no-results]');
  const rows = [...table.querySelectorAll('tbody tr')].filter(row => row !== empty && row.cells.length > 1);
  search.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase('id');
    let matches = 0;
    rows.forEach(row => { const visible = row.textContent.toLocaleLowerCase('id').includes(query); row.hidden = !visible; if (visible) matches++; });
    empty.hidden = !query || matches > 0;
  });
})();
