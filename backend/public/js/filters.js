'use strict';
(() => {
  const focusKey = 'apotek.filter-focus';
  document.querySelectorAll('form[data-live-filter]').forEach(form => {
    if (typeof form.requestSubmit !== 'function') return;
    let timer, composing = false, submitting = false;
    const search = form.querySelector('input[type="search"],input[name="q"]');
    const from = form.querySelector('input[name="from"]');
    const to = form.querySelector('input[name="to"]');
    const range = Boolean(from && to);
    const validateRange = () => { if (range) to.setCustomValidity(from.value && to.value && from.value > to.value ? 'Tanggal sampai harus sama atau setelah tanggal dari.' : ''); };
    const submit = input => {
      clearTimeout(timer); validateRange();
      if (submitting || !form.reportValidity()) return;
      if (input === search) {
        try { sessionStorage.setItem(focusKey, JSON.stringify({path: location.pathname, name: search.name, value: search.value, start: search.selectionStart, end: search.selectionEnd})); } catch { /* Storage is optional. */ }
      }
      form.requestSubmit();
    };
    form.querySelectorAll('select').forEach(select => select.addEventListener('change', () => submit(select)));
    form.querySelectorAll('input[type="date"]').forEach(input => input.addEventListener('change', () => { validateRange(); if (!range) submit(input); }));
    search?.addEventListener('input', () => { clearTimeout(timer); if (!composing) timer = setTimeout(() => submit(search), 400); });
    search?.addEventListener('compositionstart', () => { composing = true; clearTimeout(timer); });
    search?.addEventListener('compositionend', () => { composing = false; clearTimeout(timer); timer = setTimeout(() => submit(search), 400); });
    form.addEventListener('submit', event => {
      clearTimeout(timer); validateRange();
      if (submitting || !form.checkValidity()) { event.preventDefault(); return; }
      queueMicrotask(() => { if (!event.defaultPrevented) submitting = true; });
    });
    form.querySelectorAll('button:not([type]),button[type="submit"]').forEach(button => { button.hidden = !range; });
    window.addEventListener('pageshow', () => { submitting = false; });
    const presets = form.querySelector('[data-date-presets]');
    if (presets && range) presets.hidden = false;
    form.querySelectorAll('[data-date-preset]').forEach(button => button.addEventListener('click', () => {
      if (!range || !form.dataset.today) return;
      const date = new Date(form.dataset.today + 'T12:00:00Z'); to.value = form.dataset.today;
      if (button.dataset.datePreset === 'month') date.setUTCDate(1);
      if (button.dataset.datePreset === 'week') date.setUTCDate(date.getUTCDate() - 6);
      from.value = date.toISOString().slice(0, 10); submit(button);
    }));
    try {
      const saved = JSON.parse(sessionStorage.getItem(focusKey));
      if (search && saved?.path === location.pathname && saved.name === search.name && saved.value === search.value) {
        sessionStorage.removeItem(focusKey); search.focus({preventScroll: true}); search.setSelectionRange(saved.start, saved.end);
      }
    } catch { /* Focus restoration is optional. */ }
  });
})();
