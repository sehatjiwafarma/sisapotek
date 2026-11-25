'use strict';
(() => {
  const form = document.querySelector('[data-combined-transaction]');
  if (!form || !window.fetch || !window.DOMParser) return;
  let timer, controller, composing = false;
  const money = n => 'Rp ' + (n / 100n).toLocaleString('id-ID') + ',' + String(n % 100n).padStart(2, '0');
  const scaled = (value, places) => {
    const m = String(value).match(/^(\d+)(?:\.(\d*))?$/);
    if (!m || (m[2] || '').length > places) return null;
    return BigInt(m[1]) * 10n ** BigInt(places) + BigInt((m[2] || '').padEnd(places, '0') || '0');
  };
  const fraction = (amount, rate, denominator) => (amount * rate + denominator / 2n) / denominator;
  let previousTotal = 0n, cashValue = '', lastMethod = form.querySelector('[data-combined-method]')?.value || 'cash';
  function syncSubstitution(row, reset = false) {
    const select = row.querySelector('[data-combined-product]');
    const quantity = row.querySelector('[data-combined-quantity]');
    const details = row.querySelector('[data-combined-substitution]');
    if (!select || !quantity || !details) return;
    const option = select.selectedOptions[0];
    const substitute = select.value !== select.dataset.prescribedProduct;
    const used = Number(quantity.value) > 0;
    const entitlement = details.querySelector('input[name$="[prescribed_quantity]"]');
    const dose = details.querySelector('input[name$="[adjusted_dose]"]');
    const confirmed = details.querySelector('input[name$="[substitution_confirmed]"]');
    const status = details.querySelector('[data-substitution-status]');
    const price = scaled(option?.dataset.unitPrice || '', 2);
    row.querySelector('[data-combined-product-price]').textContent = price === null ? 'Harga tidak tersedia' : 'Harga: ' + money(price) + ' / satuan dasar';
    quantity.max = substitute ? '100000' : quantity.dataset.prescribedRemaining;
    if (reset) { entitlement.value = ''; entitlement.dataset.manual = '0'; dose.value = ''; confirmed.checked = false; }
    dose.required = entitlement.required = confirmed.required = substitute && used;
    if (!substitute) { status.textContent = ''; return; }
    details.open = true;
    const compatible = option?.dataset.checkOk === '1';
    status.textContent = option?.dataset.checkMessage || 'Data produk tidak tersedia untuk diperiksa.';
    if (compatible && used) {
      const estimate = Number((Number(quantity.value) * Number(option.dataset.checkRatio)).toFixed(4));
      if (entitlement.dataset.manual !== '1') entitlement.value = estimate > 0 ? String(estimate) : '';
      status.textContent += ' Perkiraan hak resep: ' + estimate + '. Periksa sebelum menyimpan.';
    } else if (!used && entitlement.dataset.manual !== '1') entitlement.value = '';
  }
  function syncRows() {
    form.querySelectorAll('[data-combined-item]').forEach(row => {
      const entitlement = row.querySelector('input[name$="[prescribed_quantity]"]');
      if (entitlement && !entitlement.dataset.manual) entitlement.dataset.manual = entitlement.value ? '1' : '0';
      syncSubstitution(row);
    });
  }
  function updatePayment() {
    const quantityInputs = [...form.querySelectorAll('[data-combined-quantity]')];
    const paymentArea = form.querySelector('[data-combined-payment-area]');
    if (paymentArea) { paymentArea.hidden = quantityInputs.length === 0; paymentArea.disabled = quantityInputs.length === 0; }
    const method = form.querySelector('[data-combined-method]');
    const received = form.querySelector('[data-combined-amount]');
    const reference = form.querySelector('[name="payment_reference"]');
    const discountOption = form.querySelector('[name="discount_rule_id"]')?.selectedOptions[0];
    const taxOption = form.querySelector('[name="tax_rule_id"]')?.selectedOptions[0];
    const status = form.querySelector('[data-combined-payment-status]');
    const button = form.querySelector('[data-combined-save]');
    if (!method || !received || !status) return;
    let subtotal = 0n, selectedCount = 0, unavailable = false;
    quantityInputs.forEach(input => {
      const quantity = scaled(input.value || '0', 4);
      const selected = input.closest('[data-combined-item]')?.querySelector('[data-combined-product]');
      const option = selected?.selectedOptions[0];
      const price = scaled(option?.dataset.unitPrice || '', 2);
      if (quantity === null) { unavailable = true; return; }
      if (quantity > 0n) {
        selectedCount++;
        if (price === null || (selected.value !== selected.dataset.prescribedProduct && option?.dataset.checkOk !== '1')) { unavailable = true; return; }
        subtotal += fraction(quantity, price, 10000n);
      }
    });
    const discountValue = scaled(discountOption?.dataset.value || '0', 2) ?? 0n;
    const discount = discountOption?.dataset.type === 'percentage' ? fraction(subtotal, discountValue, 10000n) : discountValue;
    const invalidDiscount = discount > subtotal;
    const net = invalidDiscount ? 0n : subtotal - discount;
    const taxRate = scaled(taxOption?.dataset.value || '0', 2) ?? 0n;
    const tax = taxOption?.dataset.mode ? fraction(net, taxRate, taxOption.dataset.mode === 'inclusive' ? 10000n + taxRate : 10000n) : 0n;
    const total = net + (taxOption?.dataset.mode === 'exclusive' ? tax : 0n);
    const totalValue = (total / 100n).toString() + '.' + String(total % 100n).padStart(2, '0');
    form.querySelector('[data-combined-expected-total]').value = totalValue;
    const cash = method.value === 'cash';
    if (lastMethod === 'cash' && !cash) cashValue = received.value;
    if (lastMethod !== 'cash' && cash) received.value = cashValue;
    if (!cash || scaled(received.value, 2) === previousTotal) received.value = totalValue;
    received.readOnly = !cash;
    received.min = totalValue;
    if (reference) reference.required = !cash;
    const paid = scaled(received.value, 2);
    received.setCustomValidity(cash && paid !== null && paid < total ? 'Dana diterima kurang dari total pembayaran.' : '');
    form.querySelector('[data-combined-subtotal]').textContent = money(subtotal);
    form.querySelector('[data-combined-discount]').textContent = money(discount);
    form.querySelector('[data-combined-tax]').textContent = money(tax);
    form.querySelector('[data-combined-total]').textContent = money(total);
    form.querySelector('[data-combined-change]').textContent = money(cash && paid !== null && paid > total ? paid - total : 0n);
    form.querySelector('[data-combined-reference-note]').textContent = cash ? 'Opsional untuk tunai.' : 'Wajib untuk non-tunai.';
    status.hidden = !unavailable && !invalidDiscount;
    status.textContent = unavailable ? 'Harga, jumlah, atau kesesuaian produk tidak tersedia. Periksa kembali pilihan obat.' : (invalidDiscount ? 'Diskon tidak boleh melebihi subtotal.' : '');
    if (button) button.disabled = !selectedCount || unavailable || invalidDiscount;
    previousTotal = total;
    lastMethod = method.value;
  }
  form.addEventListener('input', event => {
    const row = event.target.closest('[data-combined-item]');
    if (row && event.target.matches('input[name$="[prescribed_quantity]"]')) event.target.dataset.manual = '1';
    if (row && event.target.matches('[data-combined-quantity]')) syncSubstitution(row);
    if (event.target.matches('[data-combined-quantity], [data-combined-amount]')) updatePayment();
  });
  form.addEventListener('change', event => {
    if (event.target.matches('[data-combined-product]')) { syncSubstitution(event.target.closest('[data-combined-item]'), true); updatePayment(); }
    if (event.target.matches('[name="discount_rule_id"], [name="tax_rule_id"], [data-combined-method]')) updatePayment();
  });
  form.addEventListener('invalid', event => {
    for (let details = event.target.closest('details'); details; details = details.parentElement?.closest('details')) details.open = true;
  }, true);
  syncRows();
  updatePayment();
  const browser = () => document.querySelector('[data-combined-browser]');
  function productFingerprint(row) {
    const option = row.querySelector('[data-combined-product]')?.selectedOptions[0];
    const source = row.querySelector('[data-prescribed-composition]')?.textContent || '';
    return JSON.stringify([source, option?.value, option?.textContent, option?.dataset.unitPrice, option?.dataset.checkOk, option?.dataset.checkRatio, option?.dataset.checkMessage]);
  }
  function savedRows() {
    const saved = new Map();
    form.querySelectorAll('[data-combined-item]').forEach(row => {
      const rx = row.querySelector('input[name$="[prescription_id]"]');
      const product = row.querySelector('input[name$="[product_id]"]');
      const quantity = row.querySelector('[data-combined-quantity]');
      const select = row.querySelector('[data-combined-product]');
      const entitlement = row.querySelector('input[name$="[prescribed_quantity]"]');
      const dose = row.querySelector('input[name$="[adjusted_dose]"]');
      const confirmed = row.querySelector('input[name$="[substitution_confirmed]"]');
      if (rx && product && quantity && select) saved.set(rx.value + '|' + product.value, {
        quantity: quantity.value, selected: select.value, entitlement: entitlement.value,
        manual: entitlement.dataset.manual, dose: dose.value, confirmed: confirmed.checked,
        open: row.querySelector('[data-combined-substitution]').open, cardOpen: row.closest('[data-combined-prescription]')?.open, fingerprint: productFingerprint(row),
      });
    });
    return saved;
  }
  async function load(url, options = {}) {
    controller?.abort();
    controller = new AbortController();
    const current = controller;
    const oldBrowser = browser();
    oldBrowser?.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {signal: current.signal, headers: {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest'}});
      if (!response.ok) throw new Error('request');
      const html = await response.text();
      if (current !== controller) return;
      const page = new DOMParser().parseFromString(html, 'text/html');
      const newBrowser = page.querySelector('[data-combined-browser]');
      const newRecords = page.querySelector('[data-combined-records]');
      if (!newBrowser || !newRecords) throw new Error('response');
      const saved = savedRows();
      newRecords.querySelectorAll('[data-combined-item]').forEach(row => {
        const rx = row.querySelector('input[name$="[prescription_id]"]');
        const product = row.querySelector('input[name$="[product_id]"]');
        const state = rx && product ? saved.get(rx.value + '|' + product.value) : null;
        if (!state) return;
        row.querySelector('[data-combined-quantity]').value = state.quantity;
        const select = row.querySelector('[data-combined-product]');
        if (![...select.options].some(option => option.value === state.selected)) {
          const unavailable = new Option('Produk yang dipilih tidak lagi tersedia — pilih ulang', state.selected, true, true);
          unavailable.disabled = true;
          select.add(unavailable);
        }
        select.value = state.selected;
        const entitlement = row.querySelector('input[name$="[prescribed_quantity]"]');
        entitlement.value = state.entitlement; entitlement.dataset.manual = state.manual;
        row.querySelector('input[name$="[adjusted_dose]"]').value = state.dose;
        row.querySelector('input[name$="[substitution_confirmed]"]').checked = state.confirmed && productFingerprint(row) === state.fingerprint;
        row.querySelector('[data-combined-substitution]').open = state.open;
        const card = row.closest('[data-combined-prescription]');
        if (card && state.cardOpen !== undefined) card.open = state.cardOpen;
      });
      oldBrowser.replaceWith(newBrowser);
      form.querySelector('[data-combined-records]').replaceWith(newRecords);
      form.querySelector('[data-combined-selected-count]').textContent = newRecords.querySelectorAll('[data-combined-prescription]').length + ' resep';
      syncRows();
      updatePayment();
      history.replaceState(null, '', url);
      if (options.focusSearch) {
        const search = newBrowser.querySelector('input[name="q"]');
        search.focus({preventScroll: true});
        const end = search.value.length;
        search.setSelectionRange(end, end);
      }
    } catch (error) {
      if (error.name === 'AbortError') return;
      const message = browser()?.querySelector('[data-combined-browser-error]');
      if (message) { message.textContent = 'Daftar resep gagal dimuat. Periksa koneksi dan coba lagi.'; message.hidden = false; }
    } finally {
      if (current === controller) browser()?.removeAttribute('aria-busy');
    }
  }
  document.addEventListener('click', event => {
    const link = event.target.closest('[data-combined-browser] a, [data-combined-remove]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    clearTimeout(timer);
    load(link.href);
  }, true);
  document.addEventListener('submit', event => {
    if (!event.target.matches('[data-combined-search]')) return;
    event.preventDefault();
    clearTimeout(timer);
    const url = new URL(event.target.action || location.href);
    url.search = new URLSearchParams(new FormData(event.target)).toString();
    load(url.href, {focusSearch: true});
  }, true);
  document.addEventListener('compositionstart', event => { if (event.target.matches('[data-combined-search] input[name="q"]')) composing = true; });
  document.addEventListener('compositionend', event => {
    if (!event.target.matches('[data-combined-search] input[name="q"]')) return;
    composing = false; clearTimeout(timer); timer = setTimeout(() => event.target.form?.requestSubmit(), 400);
  });
  document.addEventListener('input', event => {
    if (!event.target.matches('[data-combined-search] input[name="q"]')) return;
    clearTimeout(timer);
    if (!composing && !event.isComposing) timer = setTimeout(() => event.target.form?.requestSubmit(), 400);
  });
})();
