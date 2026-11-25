'use strict';
(() => {
  const scheduled = new WeakSet();
  function scheduleNotices() {
    document.querySelectorAll('.notice.success, [data-notice-auto-dismiss]').forEach(notice => {
      if (scheduled.has(notice)) return;
      scheduled.add(notice);
      window.setTimeout(() => notice.remove(), 5000);
    });
  }
  scheduleNotices();
  new MutationObserver(scheduleNotices).observe(document.body, { childList: true, subtree: true });
})();
