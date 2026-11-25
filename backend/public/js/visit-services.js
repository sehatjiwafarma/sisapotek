'use strict';
(() => {
  const choices = document.querySelector('[data-visit-services]');
  if (!choices) return;
  const total = choices.querySelector('[data-service-total]');
  if (!total) return;
  const update = () => {
    let cents = 0n;
    choices.querySelectorAll('input:checked').forEach(input => {
      const parts = input.dataset.price.split('.');
      cents += BigInt(parts[0]) * 100n + BigInt((parts[1] || '').padEnd(2,'0').slice(0,2));
    });
    total.textContent = 'Rp ' + (Number(cents) / 100).toLocaleString('id-ID', {minimumFractionDigits:2,maximumFractionDigits:2});
  };
  choices.addEventListener('change', update);
  update();
})();
