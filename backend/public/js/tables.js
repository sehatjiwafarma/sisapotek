'use strict';
(() => {
  const collator = new Intl.Collator('id', { numeric: true, sensitivity: 'base' });
  function value(cell) {
    if (cell.dataset.sortValue !== undefined) return cell.dataset.sortValue;
    const input = cell.querySelector('input,select,textarea');
    if (input) return input.type === 'checkbox' ? Number(input.checked) : input.value;
    const text = cell.textContent.trim();
    const date = text.match(/^(\d{2})\/(\d{2})\/(\d{4})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (date) return `${date[3]}-${date[2]}-${date[1]}T${date[4] || '00'}:${date[5] || '00'}:${date[6] || '00'}`;
    return text;
  }
  document.querySelectorAll('.table-wrap table').forEach(table => {
    table.classList.add('app-table');
    const server = table.dataset.tableSort === 'server';
    const columns = (table.dataset.sortColumns || '').split(',');
    const prefix = table.dataset.sortPrefix || '';
    const params = new URL(location.href).searchParams;
    const requested = params.get(prefix + 'sort');
    let current = server ? (columns.includes(requested) ? requested : table.dataset.sortDefault) : null;
    let direction = params.get(prefix + 'direction');
    if (!['asc', 'desc'].includes(direction)) direction = table.dataset.sortDefaultDirection || 'asc';
    const headers = [...(table.tHead?.rows[0]?.cells || [])];
    headers.forEach((header, index) => {
      const label = header.textContent.trim();
      const key = server ? columns[index] : String(index);
      if (!label || header.hasAttribute('data-no-sort') || (server ? !key : ['AKSI', 'TINDAKAN'].includes(label.toLocaleUpperCase('id')))) return;
      const control = document.createElement(server ? 'a' : 'button');
      if (!server) control.type = 'button';
      control.className = 'table-sort';
      const text = document.createElement('span'); text.textContent = label;
      const icon = document.createElement('span'); icon.className = 'table-sort-icon'; icon.setAttribute('aria-hidden', 'true');
      control.append(text, icon); header.replaceChildren(control);
      function update() {
        const active = current === key;
        header.setAttribute('aria-sort', active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
        icon.textContent = active ? (direction === 'asc' ? '↑' : '↓') : '↕';
        control.setAttribute('aria-label', `${label}: urutkan ${active && direction === 'asc' ? 'menurun' : 'menaik'}`);
        if (server) {
          const url = new URL(location.href);
          url.searchParams.set(prefix + 'sort', key);
          url.searchParams.set(prefix + 'direction', active && direction === 'asc' ? 'desc' : 'asc');
          url.searchParams.delete(table.dataset.sortPage || 'page');
          control.href = url.href;
        }
      }
      update();
      if (!server) control.addEventListener('click', () => {
        direction = current === key && direction === 'asc' ? 'desc' : 'asc'; current = key;
        const body = table.tBodies[0];
        const rows = [...body.rows].filter(row => row.cells.length === headers.length);
        rows.sort((a, b) => collator.compare(value(a.cells[index]), value(b.cells[index])) * (direction === 'asc' ? 1 : -1));
        rows.forEach(row => body.append(row));
        headers.forEach(other => { if (other !== header) { other.setAttribute('aria-sort', 'none'); const marker = other.querySelector('.table-sort-icon'); if(marker) marker.textContent = '↕'; } });
        update();
      });
    });
  });
})();
