'use strict';
(() => {
  document.querySelectorAll('.table-wrap').forEach((wrapper,index) => {
    const table = wrapper.querySelector('table'); if (!table) return;
    if (table.querySelector('tbody input:not([type="hidden"]),tbody select,tbody textarea')) wrapper.dataset.inputTable = '';
    const hint = document.createElement('p'); hint.className = 'table-scroll-hint'; hint.id = 'table-scroll-' + index; hint.textContent = 'Geser tabel ke samping untuk melihat kolom lainnya. Fokus pada tabel dan gunakan tombol panah.'; hint.hidden = true; wrapper.after(hint);
    function update() {
      const overflow = wrapper.scrollWidth > wrapper.clientWidth + 1;
      hint.hidden = !overflow; wrapper.tabIndex = overflow ? 0 : -1;
      if (overflow) { wrapper.setAttribute('role','region'); wrapper.setAttribute('aria-label','Tabel '+(wrapper.closest('.card')?.querySelector('h2')?.textContent || 'data')); wrapper.setAttribute('aria-describedby',hint.id); }
      else { wrapper.removeAttribute('role'); wrapper.removeAttribute('aria-label'); wrapper.removeAttribute('aria-describedby'); }
    }
    if (typeof ResizeObserver === 'function') { const observer = new ResizeObserver(update); observer.observe(wrapper); observer.observe(table); }
    else window.addEventListener('resize',update);
    update();
  });
  const summary = document.querySelector('[data-selected-lines]');
  if (summary) {
    const form = summary.closest('form');
    const update = () => { const count = [...form.querySelectorAll('input[name$="[quantity]"]')].filter(input => Number(input.value) > 0).length; summary.textContent = count ? count+' jenis obat dipilih untuk penebusan ini.' : 'Belum ada obat dipilih.'; };
    form.addEventListener('input',update); update();
  }
  document.querySelectorAll('[data-cash-close]').forEach(form => {
    const input = form.querySelector('[name="counted_cash"]'); const reason = form.querySelector('[name="reason"]'); const output = form.querySelector('[data-cash-difference]');
    function update() {
      const actual = Number(input.value), expected = Number(form.dataset.expectedCash);
      const difference = Math.round(actual*100)-Math.round(expected*100);
      reason.required = input.value !== '' && difference !== 0;
      output.textContent = input.value === '' ? 'Isi kas fisik untuk melihat estimasi selisih.' : 'Estimasi selisih: Rp '+(difference/100).toLocaleString('id-ID',{minimumFractionDigits:2,maximumFractionDigits:2})+'. Kas sistem diperiksa ulang saat penutupan.';
    }
    input.addEventListener('input',update); update();
  });
})();