'use strict';
(() => {
  const form = document.querySelector('[data-order-form]');
  if (!form) return;
  const body = form.querySelector('[data-order-rows]');
  const template = form.querySelector('[data-order-template]');
  const money = new Intl.NumberFormat('id-ID', {style:'currency',currency:'IDR',minimumFractionDigits:0,maximumFractionDigits:2});
  function number(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  let nextIndex = Math.max(-1, ...[...body.querySelectorAll('[data-order-product]')].map(select => Number(select.name.match(/\[(\d+)\]/)?.[1] || 0))) + 1;
  function rowState(row, resetPrice = false) {
    const product = row.querySelector('[data-order-product]').selectedOptions[0];
    const unit = row.querySelector('[data-order-unit]');
    const base = unit.value === 'base';
    const conversion = number(product?.dataset.conversion) || 1;
    row.querySelector('[data-unit-hint]').textContent = product?.value ? (base ? product.dataset.base : `1 ${product.dataset.unit} = ${conversion} ${product.dataset.base}`) : 'Pilih produk terlebih dahulu';
    if(resetPrice) row.querySelector('input[data-price]').value = product?.value ? (number(product.dataset.purchasePrice) / (base ? conversion : 1)).toFixed(2) : '0';
  }
  function calculate() {
    let total = 0;
    body.querySelectorAll('[data-order-row]').forEach(row => {
      const input = key => number(row.querySelector(`input[data-${key}]`)?.value);
      const selected = row.querySelector('[data-order-product]').value;
      const subtotal = Math.round(input('quantity') * input('price') * 100) / 100;
      const sum = selected ? number(subtotal - input('discount') + input('tax')) : 0;
      row.querySelector('[data-line-total]').textContent = money.format(sum);
      total += sum;
    });
    form.querySelector('[data-order-total]').textContent = money.format(total);
  }
  body.addEventListener('change', event => {
    const row = event.target.closest('[data-order-row]');
    if(row) rowState(row, event.target.matches('[data-order-product],[data-order-unit]'));
    calculate();
  });
  body.addEventListener('input',calculate);
  body.addEventListener('click',event => {
    if (!event.target.closest('[data-remove-row]')) return;
    const row = event.target.closest('[data-order-row]');
    if(body.rows.length > 1) { row.remove(); window.AppForms?.mark(form); }
    calculate();
  });
  form.querySelector('[data-add-row]').addEventListener('click', () => {
    if(body.rows.length >= 100) return;
    body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__',String(nextIndex++)));
    document.dispatchEvent(new Event('search-select:init'));
    rowState(body.lastElementChild); calculate(); window.AppForms?.mark(form);
  });
  const supplier = form.querySelector('[data-supplier-select]');
  const payment = form.querySelector('[data-payment-type]');
  const days = form.querySelector('[name="payment_days"]');
  supplier.addEventListener('change',() => {
    days.value = supplier.selectedOptions[0]?.dataset.days || 0;
    payment.value = Number(days.value) > 0 ? 'credit' : 'cash';
  });
  payment.addEventListener('change',() => { if(payment.value === 'cash') days.value = 0; });
  body.querySelectorAll('[data-order-row]').forEach(row => rowState(row)); calculate();
})();
