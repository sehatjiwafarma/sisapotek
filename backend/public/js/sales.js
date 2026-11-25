'use strict';
(() => {
  const form = document.querySelector('[data-pos-form]');
  if (!form) return;
  const rows = form.querySelector('[data-pos-rows]');
  const error = form.querySelector('[data-pos-error]');
  const discount = form.querySelector('[data-pos-discount]');
  const discountRule = form.querySelector('[data-pos-discount-rule]');
  const taxRule = form.querySelector('[data-pos-tax-rule]');
  const search = document.querySelector('[data-pos-search]');
  const catalog = document.querySelector('[data-pos-catalog]');
  const previous = document.querySelector('[data-catalog-prev]');
  const next = document.querySelector('[data-catalog-next]');
  let cart = JSON.parse(document.getElementById('pos-cart-data').textContent);
  let products = new Map();
  let page = 1, timer, controller;
  const scaled = (value, places) => {
    const match = String(value ?? 0).match(/^(\d+)(?:\.(\d*))?$/);
    if (!match) return 0n;
    return BigInt(match[1] + (match[2] || '').padEnd(places, '0').slice(0, places));
  };
  const rounded = (value, divisor) => (value + divisor / 2n) / divisor;
  const money = cents => 'Rp ' + (Number(cents) / 100).toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const quantity = value => Number(value).toLocaleString('id-ID', {maximumFractionDigits: 4});
  const sellingUnits = product => typeof product?.selling_units === 'string' ? JSON.parse(product.selling_units) : (product?.selling_units || []);
  const selectedUnit = item => /^s\d+$/.test(item.unit_type) ? sellingUnits(item.product)[Number(item.unit_type.slice(1))] : item.unit_type === 'purchase' ? sellingUnits(item.product).find(unit => unit.unit === item.product.purchase_unit && scaled(unit.conversion, 4) === scaled(item.product.purchase_conversion, 4)) : null;
  const price = item => selectedUnit(item) ? scaled(selectedUnit(item).price, 2) : item.product ? rounded(scaled(item.product.selling_price, 2) * (item.unit_type === 'purchase' ? scaled(item.product.purchase_conversion, 4) : 10000n), 10000n) : 0n;
  const lineTotal = item => rounded(scaled(item.quantity, 3) * price(item), 1000n);
  function pricing(subtotal) {
    const rule = discountRule.selectedOptions?.[0] || discountRule;
    const value = scaled(rule.dataset.value, 2);
    const off = discountRule.value ? (rule.dataset.type === 'percentage' ? rounded(subtotal * value, 10000n) : value) : scaled(discount.value, 2);
    discount.disabled = Boolean(discountRule.value);
    const net = subtotal > off ? subtotal - off : 0n;
    const tax = taxRule.selectedOptions[0];
    const inclusive = tax.dataset.mode === 'inclusive';
    const rate = scaled(tax.dataset.rate, 2);
    const amount = rounded(net * rate, inclusive ? 10000n + rate : 10000n);
    return {off, amount, base: inclusive ? net - amount : net, total: inclusive ? net : net + amount, inclusive};
  }
  function totals() {
    const subtotal = cart.reduce((sum, item) => sum + lineTotal(item), 0n);
    const result = pricing(subtotal);
    form.querySelector('[data-pos-subtotal]').textContent = money(subtotal);
    form.querySelector('[data-pos-discount-total]').textContent = money(result.off);
    form.querySelectorAll('[data-pos-total]').forEach(output => { output.textContent = money(result.total); }); document.querySelectorAll('[data-pos-mobile-total]').forEach(output => { output.textContent = money(result.total); }); document.querySelectorAll('[data-cart-count]').forEach(output => { output.textContent = cart.length; });
    form.querySelector('[data-pos-tax-base]').textContent = money(result.base);
    form.querySelector('[data-pos-tax-total]').textContent = money(result.amount);
    form.querySelector('[data-pos-tax-label]').textContent = result.inclusive ? 'PPN (termasuk harga)' : 'PPN (ditambahkan)';
    form.querySelector('[data-cart-empty]').hidden = cart.length > 0;
    form.querySelector('[data-pos-save]').disabled = !cart.length;
    [...rows.children].forEach((row, index) => {
      row.querySelector('[data-row-total]').textContent = money(lineTotal(cart[index]));
      row.querySelector('[data-row-price]').textContent = money(price(cart[index])) + ' / ' + (selectedUnit(cart[index])?.unit || (cart[index].unit_type === 'purchase' ? cart[index].product?.purchase_unit : cart[index].product?.base_unit));
    });
  }
  function renderCart() {
    rows.replaceChildren();
    cart.forEach((item, index) => {
      const row = document.getElementById('pos-row-template').content.firstElementChild.cloneNode(true);
      row.querySelector('[data-row-name]').textContent = item.product?.name || 'Produk tidak tersedia — hapus dari keranjang';
      row.querySelector('[data-row-sku]').textContent = item.product ? item.product.brand + ' · ' + item.product.sku : '';
      row.querySelector('[data-row-composition]').textContent = item.product ? (item.product.composition || 'Belum dicatat') : '';
      row.querySelector('[data-row-stock]').textContent = item.product ? (item.product.stocktake_number ? 'Sedang opname: ' + item.product.stocktake_number : 'Stok layak jual: ' + quantity(item.product.available) + ' ' + item.product.base_unit) : '';
      row.querySelector('[data-field="product_id"]').value = item.product_id;
      const units = row.querySelector('[data-field="unit_type"]');
      if (item.unit_type === 'purchase') {
        const matching = sellingUnits(item.product).findIndex(unit => unit.unit === item.product.purchase_unit && scaled(unit.conversion, 4) === scaled(item.product.purchase_conversion, 4));
        if (matching >= 0) item.unit_type = 's' + matching;
      }
      units.add(new Option(item.product?.base_unit || 'Satuan dasar', 'base'));
      if (item.product && item.product.purchase_unit !== item.product.base_unit && !sellingUnits(item.product).some(unit => unit.unit === item.product.purchase_unit)) units.add(new Option(item.product.purchase_unit + ' (isi ' + quantity(item.product.purchase_conversion) + ')', 'purchase'));
      sellingUnits(item.product).forEach((unit, unitIndex) => units.add(new Option(unit.unit + ' (isi ' + quantity(unit.conversion) + ')', 's' + unitIndex)));
      if (![...units.options].some(option => option.value === item.unit_type)) item.unit_type = 'base';
      units.value = item.unit_type;
      row.querySelector('[data-field="quantity"]').value = item.quantity;
      row.querySelectorAll('[data-field]').forEach(input => {
        input.name = `items[${index}][${input.dataset.field}]`;
        input.addEventListener('input', () => { item[input.dataset.field] = input.value; totals(); });
        input.addEventListener('change', () => { item[input.dataset.field] = input.value; totals(); });
      });
      row.querySelector('[data-remove-row]').setAttribute('aria-label', 'Hapus ' + (item.product?.name || 'produk'));
      row.querySelector('[data-remove-row]').addEventListener('click', () => { cart.splice(index, 1); renderCart(); window.AppForms?.mark(form); });
      rows.append(row);
    });
    totals();
  }
  function add(product) {
    const existing = cart.find(item => item.product_id === product.id);
    if (existing) {
      existing.quantity = String(Math.min(10000, (Number(existing.quantity) || 0) + 1));
    } else if (cart.length < 100) {
      cart.push({product, product_id: product.id, quantity: '1', unit_type: 'base'});
    } else {
      error.hidden = false; error.textContent = 'Maksimal 100 produk berbeda per transaksi.'; return;
    }
    error.hidden = true; renderCart(); window.AppForms?.mark(form);
  }
  async function loadCatalog(target = 1, addBarcode = false) {
    controller?.abort(); controller = new AbortController();
    const current = controller;
    const url = new URL(form.dataset.catalogUrl, location.href);
    url.searchParams.set('q', search.value.trim()); url.searchParams.set('page', target);
    previous.disabled = true; next.disabled = true;
    catalog.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {signal: current.signal, headers: {'Accept':'application/json'}});
      if (!response.ok) throw new Error('catalog');
      const result = await response.json();
      if (current !== controller) return;
      products = new Map(result.data.map(product => [product.id, product]));
      if (addBarcode) {
        const exact = result.data.filter(product => [product.sku, product.barcode].some(code => code && code.toLowerCase() === search.value.trim().toLowerCase()));
        if (exact.length === 1 && Number(exact[0].available) > 0 && !exact[0].stocktake_number) { add(exact[0]); search.select(); }
      }
      catalog.replaceChildren();
      result.data.forEach(product => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'pos-product';
        const title = document.createElement('strong'); title.textContent = product.name;
        const info = document.createElement('small'); info.textContent = product.brand + ' · ' + product.sku;
        const composition = document.createElement('small'); composition.textContent = (product.composition || 'Belum dicatat');
        const amount = document.createElement('b'); amount.textContent = money(scaled(product.selling_price, 2)) + ' / ' + product.base_unit;
        const stock = document.createElement('small'); stock.textContent = 'Stok: ' + quantity(product.available) + ' ' + product.base_unit;
        const action = document.createElement('small'); action.textContent = product.stocktake_number ? 'Sedang opname: ' + product.stocktake_number : (Number(product.available) > 0 ? '+ Tambah ke keranjang' : 'Stok kosong');
        button.disabled = Number(product.available) <= 0 || Boolean(product.stocktake_number);
        button.append(title, info, composition, amount, stock, action); button.addEventListener('click', () => add(product)); catalog.append(button);
      });
      if (!result.data.length) { const message = document.createElement('p'); message.className = 'pos-help'; message.textContent = 'Produk tidak ditemukan. Coba kata pencarian lain; obat yang memerlukan resep tidak ditampilkan.'; catalog.append(message); }
      page = result.current_page; previous.disabled = page <= 1; next.disabled = page >= result.last_page;
      document.querySelector('[data-catalog-page]').textContent = `${result.total} produk · ${page}/${result.last_page}`;
    } catch (failure) {
      if (failure.name === 'AbortError') return;
      catalog.replaceChildren(); const message = document.createElement('p'); message.className = 'pos-help'; message.textContent = 'Katalog gagal dimuat. Periksa koneksi atau sesi masuk Anda.';
      const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'btn small'; retry.textContent = 'Coba lagi'; retry.addEventListener('click', () => loadCatalog(target)); catalog.append(message, retry);
    } finally { if (current === controller) catalog.setAttribute('aria-busy', 'false'); }
  }
  search.addEventListener('input', event => { clearTimeout(timer); controller?.abort(); if (!event.isComposing) timer = setTimeout(() => loadCatalog(), 400); });
  search.addEventListener('compositionend', () => { clearTimeout(timer); timer = setTimeout(() => loadCatalog(), 400); });
  search.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const exact = [...products.values()].filter(product => [product.sku, product.barcode].some(code => code && code.toLowerCase() === search.value.trim().toLowerCase()));
    if (exact.length === 1 && Number(exact[0].available) > 0 && !exact[0].stocktake_number) { add(exact[0]); search.select(); } else { clearTimeout(timer); loadCatalog(1, true); }
  });
  previous.addEventListener('click', () => loadCatalog(page - 1)); next.addEventListener('click', () => loadCatalog(page + 1));
  discount.addEventListener('input', totals);
  discountRule.addEventListener('change', totals); taxRule.addEventListener('change', totals);
  form.addEventListener('submit', event => {
    const subtotal = cart.reduce((sum, item) => sum + lineTotal(item), 0n);
    if (!cart.length || cart.some(item => !item.product) || pricing(subtotal).off > subtotal) {
      event.preventDefault(); error.hidden = false; error.textContent = 'Lengkapi produk yang tersedia dan pastikan diskon tidak melebihi subtotal.'; error.scrollIntoView({block:'center'});
    }
  });
  document.addEventListener('keydown', event => {
    if (!event.altKey || event.ctrlKey || event.metaKey) return;
    if (event.key.toLowerCase() === 'k') { event.preventDefault(); search.focus(); }
    if (event.key.toLowerCase() === 's') { event.preventDefault(); form.requestSubmit(); }
  });
  const layout = document.querySelector('.pos-layout');
  layout.classList.add('pos-enhanced');
  document.querySelectorAll('[data-pos-view]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    layout.querySelectorAll('[data-pos-panel]').forEach(panel => panel.classList.toggle('pos-panel-active',panel.dataset.posPanel === link.dataset.posView));
    document.querySelectorAll('[data-pos-view]').forEach(other => other.setAttribute('aria-current',other === link ? 'true' : 'false'));
    layout.querySelector('.pos-panel-active input:not([type="hidden"])')?.focus();
  }));
  form.addEventListener('invalid', event => {
    const options = event.target.closest('.pos-options');
    if (options) options.open = true;
    layout.querySelectorAll('[data-pos-panel]').forEach(panel => panel.classList.toggle('pos-panel-active',panel === form));
  },true);
  renderCart(); loadCatalog();
})();
