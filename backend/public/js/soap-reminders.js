'use strict';
document.querySelectorAll('.soap-notifications').forEach(panel => {
  document.addEventListener('click', event => {
    if (panel.open && !panel.contains(event.target)) panel.open = false;
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && panel.open) {
      panel.open = false;
      panel.querySelector('summary').focus();
    }
  });
});
