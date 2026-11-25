'use strict';
(() => {
  const owners = document.querySelector('[data-owners]');
  const ownerTemplate = document.querySelector('[data-owner-template]');
  const refreshOwners = () => {
    owners?.querySelectorAll('[data-owner-row]').forEach((row, index) => {
      row.querySelector('legend').textContent = `Pemilik ${index + 1}`;
      row.querySelectorAll('input').forEach(input => {
        input.name = input.name.replace(/owners\[[^\]]+\]/, `owners[${index}]`);
      });
      row.querySelector('[data-owner-remove]').disabled = owners.children.length === 1;
    });
  };
  document.querySelector('[data-owner-add]')?.addEventListener('click', () => {
    owners.append(ownerTemplate.content.cloneNode(true));
    refreshOwners();
    owners.lastElementChild.querySelector('input').focus();
  });
  owners?.addEventListener('click', async event => {
    const button = event.target.closest('[data-owner-remove]');
    if (!button || owners.children.length <= 1) return;
    if (!await window.AppConfirm.open({ title: 'Hapus pemilik', message: 'Hapus pemilik ini dari formulir? Perubahan berlaku setelah disimpan.', confirmLabel: 'Hapus pemilik', variant: 'danger' })) return;
    button.closest('[data-owner-row]').remove();
    refreshOwners();
  });
  refreshOwners();
  const personnelList = document.querySelector('[data-personnel-list]');
  const personnelEditors = [...document.querySelectorAll('[data-personnel-editor]')];
  let personnelEditorOpener;
  document.querySelectorAll('[data-personnel-editor-open]').forEach(button => {
    button.addEventListener('click', () => {
      const editor = personnelEditors.find(panel => panel.dataset.personnelEditor === button.dataset.personnelEditorOpen);
      if (!editor || !personnelList) return;
      personnelEditorOpener = button;
      personnelList.hidden = true;
      personnelEditors.forEach(panel => { panel.hidden = panel !== editor; });
      editor.querySelector('input:not([type="hidden"])')?.focus();
    });
  });
  document.querySelectorAll('[data-personnel-editor-cancel]').forEach(button => {
    button.addEventListener('click', () => {
      const editor = button.closest('[data-personnel-editor]');
      editor?.querySelector('form')?.reset();
      personnelEditors.forEach(panel => { panel.hidden = true; });
      if (personnelList) personnelList.hidden = false;
      const opener = personnelEditorOpener || document.querySelector(`[data-personnel-editor-open="${editor?.dataset.personnelEditor}"]`);
      opener?.focus();
    });
  });
  document.querySelectorAll('[data-deactivate-open]').forEach(button => {
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    if (!panel) return;
    button.addEventListener('click', () => {
      panel.hidden = !panel.hidden;
      button.setAttribute('aria-expanded', String(!panel.hidden));
      if (!panel.hidden) panel.querySelector('textarea')?.focus();
    });
    panel.querySelector('[data-deactivate-cancel]')?.addEventListener('click', () => {
      panel.hidden = true;
      button.setAttribute('aria-expanded', 'false');
      button.focus();
    });
  });
  const assignmentDialog = document.querySelector('#personnel-assignment-dialog');
  let assignmentOpener;
  function openAssignment(button) {
    if (!assignmentDialog) return;
    assignmentOpener = button;
    assignmentDialog.querySelector('form').reset();
    assignmentDialog.querySelector('[data-assignment-person]').value = button.dataset.assignmentOpen;
    assignmentDialog.querySelector('[data-assignment-name]').textContent = button.dataset.personName;
    assignmentDialog.querySelector('form').dataset.confirm = `Tetapkan ${button.dataset.personName} sebagai penanggung jawab untuk periode yang dipilih?`;
    assignmentDialog.showModal();
    assignmentDialog.querySelector('input[type="date"]')?.focus();
  }
  document.querySelectorAll('[data-assignment-open]').forEach(button => button.addEventListener('click', () => openAssignment(button)));
  assignmentDialog?.querySelector('[data-assignment-cancel]')?.addEventListener('click', () => assignmentDialog.close());
  assignmentDialog?.addEventListener('close', () => assignmentOpener?.focus());
  function revealHistory() {
    const historyPanel = document.querySelector('#personnel-history');
    if (!historyPanel) return;
    historyPanel.open = true;
    historyPanel.querySelector('summary')?.focus();
    historyPanel.scrollIntoView({ block: 'nearest' });
  }
  document.querySelector('[data-personnel-manage]')?.addEventListener('click', revealHistory);
  const tabs = [...document.querySelectorAll('[data-master-tab]')];
  const panels = [...document.querySelectorAll('[data-master-panel]')];
  function activateTab(key, focus = false) {
    const selected = tabs.find(tab => tab.dataset.masterTab === key) || tabs[0];
    if (!selected) return;
    tabs.forEach(tab => {
      const active = tab === selected;
      tab.id = 'master-tab-' + tab.dataset.masterTab;
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-selected', String(active));
      tab.setAttribute('aria-controls', 'master-panel-' + tab.dataset.masterTab);
      tab.tabIndex = active ? 0 : -1;
    });
    panels.forEach(panel => {
      panel.id = 'master-panel-' + panel.dataset.masterPanel;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', 'master-tab-' + panel.dataset.masterPanel);
      panel.hidden = panel.dataset.masterPanel !== selected.dataset.masterTab;
    });
    if (focus) selected.focus();
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => {
      activateTab(tab.dataset.masterTab);
      history.replaceState(null, '', '#' + tab.dataset.masterTab);
    });
    tab.addEventListener('keydown', event => {
      let next;
      if(event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      if(event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
      if(event.key === 'Home') next = 0;
      if(event.key === 'End') next = tabs.length - 1;
      if(next !== undefined) { event.preventDefault(); activateTab(tabs[next].dataset.masterTab, true); }
    });
  });
  activateTab(location.hash.slice(1));
  if (assignmentDialog?.dataset.reopen === 'true') {
    activateTab('personnel');
    const personId = assignmentDialog.querySelector('[data-assignment-person]').value;
    assignmentOpener = [...document.querySelectorAll('[data-assignment-open]')].find(button => button.dataset.assignmentOpen === personId);
    assignmentDialog.querySelector('[data-assignment-name]').textContent = assignmentOpener?.dataset.personName || 'Apoteker yang dipilih';
    assignmentDialog.showModal();
  }
  document.addEventListener('invalid', event => {
    let ancestor = event.target.parentElement;
    while (ancestor) {
      if (ancestor.tagName === 'DETAILS') ancestor.open = true;
      ancestor = ancestor.parentElement;
    }
    const panel = event.target.closest('[data-master-panel]');
    if(panel?.hidden) activateTab(panel.dataset.masterPanel);
  }, true);
  const logoInput = document.querySelector('[data-logo-input]');
  let previewUrl;
  logoInput?.addEventListener('change', () => {
    const preview = document.querySelector('[data-logo-preview]');
    const file = logoInput.files[0];
    if(previewUrl) URL.revokeObjectURL(previewUrl);
    if(!file) { preview.hidden = true; return; }
    if(!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      logoInput.setCustomValidity('Logo harus JPG/PNG/WEBP maksimal 2 MB.'); logoInput.reportValidity(); preview.hidden = true; return;
    }
    logoInput.setCustomValidity(''); previewUrl = URL.createObjectURL(file); preview.src = previewUrl; preview.hidden = false;
  });
  const levels = ['province','city','district','village'];
  const regionWidgets = new Map();
  levels.forEach(level => {
    const select = document.querySelector('[data-region="'+level+'"]');
    if (!select) return;
    const field = select.closest('.region-field');
    const trigger = document.createElement('button');
    trigger.type = 'button'; trigger.className = 'region-trigger'; trigger.id = 'region-trigger-'+level;
    trigger.setAttribute('aria-labelledby', 'region-label-'+level+' '+trigger.id);
    trigger.setAttribute('aria-haspopup', 'listbox'); trigger.setAttribute('aria-expanded', 'false');
    const dropdown = document.createElement('div'); dropdown.className = 'region-dropdown'; dropdown.hidden = true;
    const search = document.createElement('input'); search.type = 'search'; search.autocomplete = 'off';
    search.placeholder = 'Cari '+select.dataset.regionLabel.toLocaleLowerCase('id');
    search.setAttribute('aria-label', search.placeholder); search.setAttribute('role', 'combobox');
    search.setAttribute('aria-autocomplete', 'list'); search.setAttribute('aria-expanded', 'false');
    const list = document.createElement('div'); list.className = 'region-options'; list.id = 'region-options-'+level;
    list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', select.dataset.regionLabel);
    trigger.setAttribute('aria-controls', list.id); search.setAttribute('aria-controls', list.id);
    dropdown.append(search,list); field.append(trigger,dropdown);
    select.classList.add('region-native'); select.tabIndex = -1;
    field.querySelector('label').htmlFor = trigger.id;
    let options = [], highlighted = -1;
    function close(restore = false) {
      dropdown.hidden = true; trigger.setAttribute('aria-expanded','false'); search.setAttribute('aria-expanded','false');
      search.removeAttribute('aria-activedescendant'); if (restore) trigger.focus();
    }
    function choose(option) { select.value = option.value; select.dispatchEvent(new Event('change', {bubbles:true})); close(true); }
    function highlight(index) {
      highlighted = index;
      [...list.querySelectorAll('[role="option"]')].forEach((item,i) => {
        item.classList.toggle('is-highlighted',i === index);
        if(i === index) { search.setAttribute('aria-activedescendant',item.id); item.scrollIntoView({block:'nearest'}); }
      });
    }
    function render() {
      list.replaceChildren(); highlighted = -1; search.removeAttribute('aria-activedescendant');
      options = [...select.options].filter(option => option.value && option.text.toLocaleLowerCase('id').includes(search.value.trim().toLocaleLowerCase('id')));
      options.forEach((option,i) => {
        const item = document.createElement('div'); item.className = 'region-option'; item.id = list.id+'-'+i;
        item.setAttribute('role','option'); item.setAttribute('aria-selected',String(option.selected)); item.textContent = option.text;
        // Keep focus in the search until click selects the option. Otherwise
        // focusout closes the popup before a non-focusable option receives click.
        item.addEventListener('pointerdown',event => event.preventDefault());
        item.addEventListener('click',() => choose(option)); list.append(item);
      });
      if(!options.length) { const empty = document.createElement('div'); empty.className = 'region-no-results'; empty.setAttribute('role','status'); empty.textContent = 'Wilayah tidak ditemukan'; list.append(empty); }
    }
    function refresh() { trigger.textContent = select.selectedOptions[0]?.text || 'Pilih wilayah'; trigger.disabled = select.disabled; if (!dropdown.hidden) render(); }
    trigger.addEventListener('click', () => {
      if(!dropdown.hidden) return close();
      regionWidgets.forEach(widget => widget.close()); search.value = ''; render();
      dropdown.hidden = false; trigger.setAttribute('aria-expanded','true'); search.setAttribute('aria-expanded','true'); search.focus();
    });
    search.addEventListener('input',render);
    search.addEventListener('keydown',event => {
      if(event.key === 'Escape') { event.preventDefault(); close(true); }
      if(event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault(); if(options.length) highlight((highlighted + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
      }
      if(event.key === 'Enter') { event.preventDefault(); if(highlighted >= 0) choose(options[highlighted]); }
    });
    field.addEventListener('focusout',event => { if(!field.contains(event.relatedTarget)) close(); });
    document.addEventListener('pointerdown',event => { if(!field.contains(event.target)) close(); });
    select.addEventListener('change',refresh);
    select.addEventListener('invalid',event => { event.preventDefault(); trigger.focus(); trigger.setAttribute('aria-invalid','true'); });
    select.addEventListener('change',() => trigger.removeAttribute('aria-invalid'));
    select.form?.addEventListener('reset',() => setTimeout(refresh,0));
    regionWidgets.set(level,{refresh,close}); refresh();
  });
  let regionRequest = 0;
  levels.forEach((level,index) => {
    const select = document.querySelector('[data-region="'+level+'"]');
    select?.addEventListener('change', async () => {
      const requestId = ++regionRequest;
      const feedback = document.querySelector('#region-feedback');
      levels.slice(index+1).forEach(child => {
        const target = document.querySelector('[data-region="'+child+'"]');
        target.replaceChildren(new Option('Pilih '+target.dataset.regionLabel.toLocaleLowerCase('id'),''));
        target.disabled = false; regionWidgets.get(child)?.refresh();
      });
      feedback.textContent = '';
      if(index===levels.length-1 || !select.value) return;
      const target = document.querySelector('[data-region="'+levels[index+1]+'"]');
      target.disabled = true; regionWidgets.get(levels[index+1])?.refresh(); feedback.textContent = 'Memuat pilihan wilayah?';
      try {
        const response = await fetch(select.dataset.regionUrl+'?parent='+encodeURIComponent(select.value), {headers:{Accept:'application/json'}});
        if(!response.ok) throw new Error('request');
        const payload = await response.json();
        if(requestId!==regionRequest) return;
        payload.data.forEach(region => target.add(new Option(region.name,region.code))); feedback.textContent = '';
      } catch { if(requestId===regionRequest) feedback.textContent = 'Pilihan wilayah gagal dimuat. Pilih kembali wilayah induk untuk mencoba ulang.'; }
      finally { if(requestId===regionRequest) { target.disabled = false; regionWidgets.get(levels[index+1])?.refresh(); } }
    });
  });
  const hours = document.querySelector('#hours-rows');
  function reindex() { hours?.querySelectorAll('[data-hours-row]').forEach((row,i) => row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/^hours\[\d+\]/,'hours['+i+']'); })); }
  document.querySelector('#hours-add')?.addEventListener('click', () => {
    const first = hours.querySelector('[data-hours-row]'); if(!first) return;
    const row = first.cloneNode(true); row.querySelectorAll('input').forEach(input => { input.value = ''; });
    row.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; }); hours.append(row); reindex();
  });
  hours?.addEventListener('click', event => {
    const button = event.target.closest('[data-hours-remove]'); if(!button) return;
    if(hours.children.length<=1) return; button.closest('[data-hours-row]').remove(); reindex();
  });
})();
