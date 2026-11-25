'use strict';
(() => {
  const dialog = document.getElementById('app-confirm');
  if (!dialog) return;
  const title = dialog.querySelector('#app-confirm-title');
  const message = dialog.querySelector('#app-confirm-message');
  const cancel = dialog.querySelector('[data-dialog-cancel]');
  const accept = dialog.querySelector('[data-dialog-accept]');
  const queue = [];
  let current = null;

  function showNext() {
    if (current || !queue.length) return;
    current = queue.shift();
    current.focus = document.activeElement;
    const options = current.options;
    title.textContent = options.title || 'Konfirmasi tindakan';
    message.textContent = options.message || 'Lanjutkan proses ini?';
    cancel.textContent = options.cancelLabel || 'Batal';
    accept.textContent = options.confirmLabel || 'Ya, lanjutkan';
    const danger = options.variant === 'danger';
    dialog.dataset.variant = danger ? 'danger' : 'primary';
    accept.className = `btn ${danger ? 'danger' : 'primary'}`;
    dialog.showModal();
    cancel.focus();
  }

  function finish(confirmed) {
    if (!current || !dialog.open) return;
    // Wait for the close event before showing another queued dialog.
    current.confirmed = confirmed;
    dialog.close();
  }

  dialog.addEventListener('close', () => {
    if (!current) return;
    const completed = current;
    current = null;
    if (completed.focus?.isConnected) completed.focus.focus();
    completed.resolve(completed.confirmed === true);
    showNext();
  });
  dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
  cancel.addEventListener('click', () => finish(false));
  accept.addEventListener('click', () => finish(true));

  window.AppConfirm = Object.freeze({
    open(options = {}) {
      return new Promise(resolve => { queue.push({ options, resolve }); showNext(); });
    },
  });

  const pending = new WeakSet();
  const approved = new WeakSet();
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
    if (approved.has(form)) { approved.delete(form); return; }
    if (event.defaultPrevented) return;
    event.preventDefault();
    if (pending.has(form)) return;
    pending.add(form);
    const submitter = event.submitter;
    const danger = form.dataset.confirmVariant === 'danger'
      || (!form.dataset.confirmVariant && (submitter?.classList.contains('danger')
        || form.querySelector('input[name="_method"]')?.value.toUpperCase() === 'DELETE'));
    try {
      const confirmed = await window.AppConfirm.open({
        title: form.dataset.confirmTitle,
        message: form.dataset.confirm,
        confirmLabel: form.dataset.confirmLabel || submitter?.textContent.trim() || 'Ya, lanjutkan',
        cancelLabel: form.dataset.confirmCancel,
        variant: danger ? 'danger' : 'primary',
      });
      if (!confirmed || !form.isConnected) return;
      if (submitter && (!submitter.isConnected || submitter.form !== form || submitter.disabled)) return;
      approved.add(form);
      try { form.requestSubmit(submitter || undefined); }
      finally { approved.delete(form); }
    } finally { pending.delete(form); }
  });
})();
