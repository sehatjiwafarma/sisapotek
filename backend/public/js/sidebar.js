'use strict';
(() => {
  const toggle = document.querySelector('#sidebar-toggle');
  const sidebar = document.querySelector('#app-sidebar');
  if (!toggle || !sidebar) return;
  const viewport = matchMedia('(max-width: 680px)');
  const workspace = document.querySelector('.workspace');
  const backdrop = document.createElement('button'); backdrop.type = 'button'; backdrop.className = 'sidebar-backdrop'; backdrop.tabIndex = -1; backdrop.setAttribute('aria-label','Tutup menu'); sidebar.after(backdrop);
  let desktopCollapsed = false, mobileOpen = false;
  try { desktopCollapsed = JSON.parse(localStorage.getItem('apotek.sidebar.v1'))?.collapsed === true; } catch { /* Preferences are optional. */ }
  const navigation = sidebar.querySelector('#sidebar-navigation');
  const groups = [...sidebar.querySelectorAll('[data-sidebar-group]')];
  const preferenceKey = `apotek.sidebar.groups.v1:${navigation?.dataset.preferenceScope || 'account'}`;
  let groupPreferences = {};
  try {
    const stored = JSON.parse(localStorage.getItem(preferenceKey));
    if (stored && typeof stored === 'object' && !Array.isArray(stored)) groupPreferences = stored;
  } catch { /* Navigation works without storage. */ }
  const fewItems = (navigation?.querySelectorAll('a[href]').length || 0) <= 8;
  const groupStates = new Map(groups.map(group => {
    const saved = groupPreferences[group.dataset.sidebarGroup];
    return [group, !!group.querySelector('[aria-current="page"]') || (typeof saved === 'boolean' ? saved : fewItems)];
  }));
  function renderGroups() {
    const rail = !viewport.matches && desktopCollapsed;
    groups.forEach(group => {
      const open = rail || groupStates.get(group);
      group.querySelector('.sidebar-group-toggle').setAttribute('aria-expanded', String(open));
      group.querySelector('.sidebar-group-items').hidden = !open;
    });
  }
  groups.forEach(group => group.querySelector('.sidebar-group-toggle').addEventListener('click', () => {
    const open = !groupStates.get(group);
    groupStates.set(group, open);
    groupPreferences[group.dataset.sidebarGroup] = open;
    try { localStorage.setItem(preferenceKey, JSON.stringify(groupPreferences)); } catch { /* Optional. */ }
    renderGroups();
  }));
  function render() {
    const open = viewport.matches && mobileOpen;
    document.body.classList.toggle('sidebar-collapsed',!viewport.matches && desktopCollapsed);
    document.body.classList.toggle('menu-open',open); sidebar.classList.toggle('mobile-menu-open',open);
    if (workspace) workspace.inert = open;
    toggle.setAttribute('aria-expanded',String(viewport.matches ? open : !desktopCollapsed));
    toggle.setAttribute('aria-controls',viewport.matches ? 'app-sidebar' : 'sidebar-navigation');
    toggle.title = viewport.matches ? (open ? 'Tutup menu' : 'Buka menu') : (desktopCollapsed ? 'Buka sidebar' : 'Kecilkan sidebar');
    toggle.setAttribute('aria-label',toggle.title);
    renderGroups();
  }
  function close() { mobileOpen = false; render(); toggle.focus(); }
  toggle.addEventListener('click', () => {
    if (viewport.matches) { mobileOpen = !mobileOpen; render(); if (mobileOpen) sidebar.querySelector('[data-close-sidebar]')?.focus(); }
    else { desktopCollapsed = !desktopCollapsed; try { localStorage.setItem('apotek.sidebar.v1',JSON.stringify({collapsed:desktopCollapsed})); } catch { /* Optional. */ } render(); }
  });
  backdrop.addEventListener('click',close); sidebar.querySelector('[data-close-sidebar]')?.addEventListener('click',close);
  sidebar.addEventListener('click',event => { if (viewport.matches && event.target.closest('a[href]')) { mobileOpen = false; render(); } });
  document.addEventListener('keydown',event => {
    if (!viewport.matches || !mobileOpen || document.querySelector('dialog[open]')) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); }
    if (event.key !== 'Tab') return;
    const controls = [...sidebar.querySelectorAll('a[href],button:not([disabled])')].filter(element => element.getClientRects().length);
    const first = controls[0], last = controls.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  viewport.addEventListener('change', () => { mobileOpen = false; render(); }); render();
})();
