'use strict';
(() => {
  const ready = new WeakSet();
  let sequence = 0;
  function initialize() {
    document.querySelectorAll('select[data-searchable]').forEach(select => {
      if (ready.has(select) || typeof HTMLElement.prototype.showPopover !== 'function') return;
      ready.add(select);
      const id = 'search-choice-' + (++sequence);
      const trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'search-choice-trigger';
      trigger.setAttribute('aria-haspopup','listbox'); trigger.setAttribute('aria-expanded','false');
      const label = select.closest('label')?.childNodes[0]?.textContent?.trim() || select.getAttribute('aria-label') || 'Pilihan produk';
      const panel = document.createElement('div'); panel.className = 'search-choice-panel'; panel.setAttribute('popover','manual');
      const search = document.createElement('input'); search.type = 'search'; search.placeholder = 'Ketik untuk mencari…'; search.autocomplete = 'off';
      search.setAttribute('aria-label',label); search.setAttribute('role','combobox'); search.setAttribute('aria-autocomplete','list');
      const list = document.createElement('div'); list.className = 'search-choice-options'; list.id = id; list.setAttribute('role','listbox'); list.setAttribute('aria-label',label);
      if (select.getAttribute('aria-describedby')) trigger.setAttribute('aria-describedby',select.getAttribute('aria-describedby')); if (select.getAttribute('aria-invalid')) trigger.setAttribute('aria-invalid',select.getAttribute('aria-invalid')); trigger.setAttribute('aria-controls',id); search.setAttribute('aria-controls',id);
      panel.append(search,list); document.body.append(panel); select.after(trigger); select.classList.add('search-choice-native'); select.tabIndex = -1;
      let options = [], active = -1, open = false;
      function refresh() { trigger.textContent = select.selectedOptions[0]?.text || 'Pilih'; trigger.disabled = select.disabled; trigger.setAttribute('aria-label',label+': '+trigger.textContent); }
      function close(focus = false) { if(!open) return; open = false; panel.hidePopover(); trigger.setAttribute('aria-expanded','false'); search.setAttribute('aria-expanded','false'); if(focus) trigger.focus(); }
      function choose(option) { select.value = option.value; select.dispatchEvent(new Event('change',{bubbles:true})); close(true); }
      function highlight(index) { active = index; [...list.children].forEach((item,i) => { item.classList.toggle('is-highlighted',i === index); if(i === index){ search.setAttribute('aria-activedescendant',item.id); item.scrollIntoView({block:'nearest'}); } }); }
      function render() {
        list.replaceChildren(); active = -1; search.removeAttribute('aria-activedescendant');
        options = [...select.options].filter(option => (option.value || !select.required) && !option.disabled && !option.hidden && !option.parentElement?.disabled && option.text.toLocaleLowerCase('id').includes(search.value.trim().toLocaleLowerCase('id')));
        options.forEach((option,index) => { const item = document.createElement('div'); item.className = 'search-choice-option'; item.id = id+'-'+index; item.textContent = option.text; item.setAttribute('role','option'); item.setAttribute('aria-selected',String(option.selected)); item.addEventListener('pointerdown',e=>e.preventDefault()); item.addEventListener('click',()=>choose(option)); list.append(item); });
        if(!options.length) { const empty = document.createElement('p'); empty.className = 'search-choice-empty'; empty.textContent = 'Tidak ada pilihan yang sesuai.'; empty.setAttribute('role','status'); list.append(empty); }
      }
      function position() {
        const rect = trigger.getBoundingClientRect();
        panel.style.width = Math.min(Math.max(rect.width,280),innerWidth-24)+'px';
        panel.style.left = Math.max(12,Math.min(rect.left,innerWidth-panel.offsetWidth-12))+'px';
        panel.style.top = Math.max(12, rect.bottom + 6 + panel.offsetHeight <= innerHeight ? rect.bottom + 6 : rect.top - panel.offsetHeight - 6)+'px';
      }
      trigger.addEventListener('click',()=> { if(open) return close(); document.dispatchEvent(new Event('search-select:close')); open = true; search.value=''; render(); panel.showPopover(); position(); trigger.setAttribute('aria-expanded','true'); search.setAttribute('aria-expanded','true'); search.focus(); });
      search.addEventListener('input',()=>{render();position();});
      search.addEventListener('keydown',e=> { if(e.key==='Escape'){e.preventDefault();close(true);} if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();if(options.length)highlight(active < 0 ? (e.key==='ArrowDown' ? 0 : options.length-1) : (active+(e.key==='ArrowDown'?1:-1)+options.length)%options.length);} if(e.key==='Enter'){e.preventDefault();if(active>=0)choose(options[active]);} });
      document.addEventListener('pointerdown',e=>{if(!panel.contains(e.target)&&!trigger.contains(e.target))close();});
      document.addEventListener('search-select:close',()=>close());
      panel.addEventListener('focusout',e=>{if(!panel.contains(e.relatedTarget))close();});
      window.addEventListener('resize',()=>close()); window.addEventListener('scroll',event=>{if(!panel.contains(event.target))close();},true);
      select.addEventListener('change',refresh);
      select.addEventListener('invalid',e=>{e.preventDefault();trigger.focus();trigger.setAttribute('aria-invalid','true');});
      select.addEventListener('change',()=>trigger.removeAttribute('aria-invalid'));
      refresh();
    });
  }
  initialize(); document.addEventListener('search-select:init',initialize);
})();
