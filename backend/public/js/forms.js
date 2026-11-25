'use strict';
(() => {
  const dirty = new Set();
  const pending = new Map();
  const editable = form => form instanceof HTMLFormElement && (form.getAttribute('method') || 'get').toLowerCase() !== 'get' && !document.body.classList.contains('guest');
  function state(form, text) {
    let output = form.querySelector('[data-form-state]');
    if (!output) {
      output = document.createElement('p'); output.dataset.formState = ''; output.className = 'form-state'; output.setAttribute('role', 'status'); form.append(output);
    }
    output.textContent = text;
  }
  function mark(form) {
    if (!editable(form)) return;
    dirty.add(form); state(form, 'Ada perubahan yang belum disimpan.');
  }
  window.AppForms = Object.freeze({ mark, hasChanges: () => [...dirty].some(form => form.isConnected) });
  ['input', 'change'].forEach(type => document.addEventListener(type, event => {
    if (event.target.matches('input:not([type="hidden"]),select,textarea')) mark(event.target.form);
  }));
  document.addEventListener('app:dirty', event => mark(event.target.closest('form')));
  window.addEventListener('beforeunload', event => {
    if (!window.AppForms.hasChanges()) return;
    event.preventDefault(); event.returnValue = '';
  });
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement) || (form.getAttribute('method') || 'get').toLowerCase() === 'get' || !window.AppConfirm) return;
    const others = [...dirty].filter(other => other !== form && other.isConnected);
    if (!others.length) return;
    event.preventDefault();
    if (await window.AppConfirm.open({title: 'Formulir lain belum disimpan', message: 'Lanjutkan tindakan ini dan buang perubahan pada formulir lain di halaman ini?', confirmLabel: 'Buang perubahan & lanjutkan', variant: 'danger'})) {
      others.forEach(other => dirty.delete(other));
      if (form.isConnected) form.requestSubmit(event.submitter || undefined);
    }
  }, true);
  document.addEventListener('click', async event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
    const url = new URL(link.href, location.href);
    if (!['http:', 'https:'].includes(url.protocol)) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
    if (!window.AppForms.hasChanges() || !window.AppConfirm) return;
    event.preventDefault(); event.stopImmediatePropagation();
    if (await window.AppConfirm.open({title: 'Perubahan belum disimpan', message: 'Tinggalkan halaman dan buang perubahan yang belum disimpan?', confirmLabel: 'Tinggalkan halaman', variant: 'danger'})) {
      dirty.clear(); location.assign(url.href);
    }
  }, true);
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented || (form.getAttribute('method') || 'get').toLowerCase() === 'get') return;
    if (pending.has(form)) { event.preventDefault(); return; }
    const buttons = [...form.querySelectorAll('button:not([type]),button[type="submit"],input[type="submit"]')];
    pending.set(form, buttons.map(button => ({button, disabled: button.disabled, text: button.textContent})));
    form.setAttribute('aria-busy', 'true'); dirty.delete(form);
    const submitter = event.submitter;
    if (submitter?.tagName === 'BUTTON') submitter.textContent = form.dataset.busyLabel || 'Memproses…';
    // Keep submitter name/value in the native request; disable after serialization.
    setTimeout(() => buttons.forEach(button => { button.disabled = true; }), 0);
    state(form, 'Permintaan sedang diproses. Tunggu hasil penyimpanan.');
  });
  window.addEventListener('pageshow', () => {
    pending.forEach((buttons, form) => {
      buttons.forEach(({button, disabled, text}) => { button.disabled = disabled; button.textContent = text; });
      form.removeAttribute('aria-busy'); mark(form);
    });
    pending.clear();
  });
  function reveal(field) {
    for (let parent = field.parentElement; parent; parent = parent.parentElement) {
      if (parent.tagName === 'DETAILS') parent.open = true;
      if (parent.matches('[data-master-panel]')) document.querySelector(`[data-master-tab="${parent.dataset.masterPanel}"]`)?.click();
    }
    field.dispatchEvent(new Event('app:reveal-field', {bubbles: true}));
    const target = field.classList.contains('search-choice-native') ? field.nextElementSibling : field;
    target?.focus({preventScroll: true}); target?.scrollIntoView({block: 'center', behavior: 'auto'});
  }
  document.addEventListener('invalid', event => reveal(event.target), true);
  document.querySelectorAll('[data-error-summary] [data-error-field]').forEach(link => {
    const key = link.dataset.errorField;
    const name = key.split('.').reduce((name, part, index) => index ? `${name}[${part}]` : part, '');
    const field = [...document.querySelectorAll('input[name],select[name],textarea[name]')].find(input => input.name === name || input.name === name + '[]');
    if (!field || field.type === 'hidden') { link.replaceWith(document.createTextNode(link.textContent)); return; }
    if (!field.id) field.id = 'field-error-' + key.replace(/[^a-zA-Z0-9_-]/g, '-');
    link.href = '#' + field.id;
    const alreadyInvalid = field.getAttribute('aria-invalid') === 'true';
    field.setAttribute('aria-invalid', 'true');
    if (!alreadyInvalid) {
      const message = document.createElement('small'); message.className = 'field-error'; message.id = field.id + '-message'; message.textContent = link.textContent;
      const visual = field.classList.contains('search-choice-native') ? field.nextElementSibling : field;
      visual.after(message);
      field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), message.id].filter(Boolean).join(' '));
    }
    if (field.classList.contains('search-choice-native')) {
      field.nextElementSibling?.setAttribute('aria-invalid', 'true');
      field.nextElementSibling?.setAttribute('aria-describedby', field.getAttribute('aria-describedby') || '');
    }
    link.addEventListener('click', event => { event.preventDefault(); reveal(field); });
  });
  document.querySelector('[data-error-summary]')?.focus({preventScroll: true});
})();
