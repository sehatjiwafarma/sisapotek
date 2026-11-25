'use strict';
(() => {
  let active;
  function close() {
    if (!active) return;
    active.content.hidePopover();
    active = undefined;
  }
  function position() {
    if (!active) return;
    const anchor = active.button.getBoundingClientRect();
    const box = active.content.getBoundingClientRect();
    const margin = 12;
    const left = Math.max(margin, Math.min(anchor.left, window.innerWidth - box.width - margin));
    const below = anchor.bottom + 8;
    const top = below + box.height <= window.innerHeight - margin
      ? below : Math.max(margin, anchor.top - box.height - 8);
    active.content.style.left = `${left}px`;
    active.content.style.top = `${top}px`;
  }
  document.querySelectorAll('[data-help-tooltip]').forEach(button => {
    const wrapper = button.closest('.help-tooltip');
    const content = document.getElementById(button.getAttribute('aria-describedby'));
    if (!content || !content.showPopover) return;
    function open() {
      if (active?.button === button) return;
      close();
      active = { button, content };
      content.showPopover();
      position();
    }
    button.addEventListener('focus', open);
    button.addEventListener('click', open);
    wrapper.addEventListener('pointerenter', open);
    wrapper.addEventListener('pointerleave', () => {
      if (active?.button === button && document.activeElement !== button) close();
    });
    wrapper.addEventListener('focusout', event => {
      if (active?.button === button && !wrapper.contains(event.relatedTarget)) close();
    });
  });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
  document.addEventListener('submit', close);
  document.addEventListener('pointerdown', event => {
    if (active && !active.button.closest('.help-tooltip').contains(event.target)) close();
  });
  // Moving to another section must not leave a tooltip above the hidden panel.
  document.addEventListener('click', event => {
    if (event.target.closest('[data-master-tab], [data-personnel-step], [data-personnel-go], [data-personnel-manage]')) close();
  });
  window.addEventListener('resize', position);
  document.addEventListener('scroll', position, true);
})();
