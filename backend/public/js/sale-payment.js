'use strict';
(() => {
  const cents = value => {
    const match = String(value).match(/^(\d+)(?:\.(\d{0,2}))?$/);
    return match ? BigInt(match[1]) * 100n + BigInt((match[2] || '').padEnd(2,'0')) : null;
  };
  const money = value => 'Rp ' + (value / 100n).toLocaleString('id-ID') + ',' + String(value % 100n).padStart(2,'0');
  document.querySelectorAll('[data-payment-form]').forEach(form => {
    const method = form.querySelector('[name="payment_method"]');
    const amount = form.querySelector('[name="amount_received"]');
    const reference = form.querySelector('[name="payment_reference"]');
    let total = cents(form.dataset.total);
    if (!method || !amount || total === null) return;
    let cashValue = amount.value;
    let lastMethod = method.value;
    const discount = form.elements.namedItem('discount_rule_id');
    const tax = form.elements.namedItem('tax_rule_id');
    const subtotal = cents(form.dataset.subtotal);
    function refreshTotal() {
      if (subtotal === null) return;
      const discountOption = discount?.selectedOptions[0];
      const taxOption = tax?.selectedOptions[0];
      const discountValue = cents(discountOption?.dataset.value || form.dataset.fixedDiscount || '0') ?? 0n;
      const rate = cents(taxOption?.dataset.value || '0') ?? 0n;
      const round = (number, divisor) => (number + divisor / 2n) / divisor;
      const discountAmount = discountOption?.dataset.type === 'percentage' ? round(subtotal * discountValue, 10000n) : discountValue;
      const net = subtotal > discountAmount ? subtotal - discountAmount : 0n;
      const taxAmount = taxOption?.dataset.mode === 'exclusive' ? round(net * rate, 10000n) : 0n;
      const previous = total;
      total = net + taxAmount;
      form.dataset.total = (total / 100n).toString() + '.' + String(total % 100n).padStart(2, '0');
      amount.min = form.dataset.total;
      if (cents(amount.value) === previous) amount.value = form.dataset.total;
      form.querySelector('[data-payment-total]')?.replaceChildren(document.createTextNode(money(total)));
      document.querySelector('[data-summary-total]')?.replaceChildren(document.createTextNode(money(total)));
      document.querySelector('[data-summary-discount]')?.replaceChildren(document.createTextNode(money(discountAmount)));
      update();
    }
    function update() {
      const cash = method.value === 'cash';
      if (lastMethod === 'cash' && !cash) cashValue = amount.value;
      if (lastMethod !== 'cash' && cash) amount.value = cashValue;
      if (!cash) amount.value = form.dataset.total;
      amount.readOnly = !cash;
      if (reference) reference.required = !cash;
      const received = cents(amount.value);
      const missing = received !== null && received < total ? total - received : 0n;
      const change = received !== null && received > total ? received - total : 0n;
      amount.setCustomValidity(cash && missing > 0n ? 'Dana diterima kurang dari total pembayaran.' : '');
      form.querySelector('[data-payment-change]').textContent = money(cash ? change : 0n);
      const shortage = form.querySelector('[data-payment-shortfall]');
      if (shortage) { shortage.hidden = missing === 0n; shortage.textContent = 'Kurang bayar: ' + money(missing); }
      form.querySelector('[data-payment-reference-note]')?.replaceChildren(document.createTextNode(cash ? 'Opsional untuk tunai.' : 'Wajib: nomor bukti transaksi non-tunai.'));
      lastMethod = method.value;
    }
    method.addEventListener('change', update); amount.addEventListener('input', update);
    discount?.addEventListener('change', refreshTotal); tax?.addEventListener('change', refreshTotal);
    if (subtotal !== null) refreshTotal(); else update();
  });
  document.querySelector('[data-print-receipt]')?.addEventListener('click', () => window.print());
  if (document.body?.hasAttribute('data-auto-print-receipt')) window.addEventListener('load', () => window.print(), { once: true });
})();