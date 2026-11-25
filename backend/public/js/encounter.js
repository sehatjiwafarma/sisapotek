'use strict';
(() => {
  const form = document.querySelector('[data-encounter-form]');
  if (!form) return;
  let dirty = false;
  form.addEventListener('input', () => { dirty = true; });
  form.addEventListener('change', () => { dirty = true; });
  form.addEventListener('click', event => {
    if (event.target.closest('#add-prescription-item,[data-remove-rx]')) dirty = true;
  });
  document.addEventListener('submit', event => {
    if (!dirty || event.target === form) return;
    event.preventDefault();
    const warning = document.querySelector('[data-encounter-warning]');
    if (warning) {
      warning.hidden = false;
      warning.scrollIntoView({block: 'center'});
    }
  }, true);
})();
