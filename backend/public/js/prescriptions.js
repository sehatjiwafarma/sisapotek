'use strict';
(() => {
    const list = document.getElementById('prescription-items');
    const add = document.getElementById('add-prescription-item');
    const template = document.getElementById('prescription-item-template');
    if (!list || !add || !template) return;
    let sequence = Math.max(-1,...[...list.querySelectorAll('select[name]')].map(select=>Number(select.name.match(/\[(\d+)\]/)?.[1] ?? -1)))+1;
    let panelSequence = 0;
    function setExpanded(row, expanded) {
        const toggle = row.querySelector('[data-toggle-rx]');
        row.querySelector('.clinical-fields').hidden = !expanded;
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.textContent = expanded ? 'Ringkas' : 'Ubah detail';
    }
    function summarize(row) {
        const field = key => row.querySelector(`[name$="[${key}]"]`);
        const product = field('product_id');
        const quantity = field('quantity').value;
        const dose = field('dose').value.trim();
        const frequency = field('frequency').value.trim();
        const instructions = field('instructions').value.trim();
        const option = product.selectedOptions[0];
        const stock = Number(option?.dataset.stock || 0);
        const stockHelp = row.querySelector('[data-rx-stock]');
        const insufficient = Boolean(product.value) && Number(quantity) > stock;
        stockHelp.textContent = product.value
            ? `Stok layak jual: ${stock.toLocaleString('id-ID', {maximumFractionDigits: 4})} ${option.dataset.unit}. ${insufficient ? 'Jumlah resep melebihi stok tersedia.' : 'Stok belum ditahan oleh resep.'}`
            : 'Pilih obat untuk melihat stok layak jual.';
        stockHelp.classList.toggle('rx-stock-warning', insufficient);
        row.querySelector('[data-rx-summary]').textContent = product.value
            ? `${product.selectedOptions[0].text} · Jumlah: ${quantity || '—'}`
            : 'Belum memilih obat';
        const missing = [!product.value && 'obat', !quantity && 'jumlah', !dose && 'dosis', !frequency && 'frekuensi', !instructions && 'aturan pakai'].filter(Boolean);
        row.querySelector('[data-rx-status]').textContent = [dose, frequency, insufficient && 'Stok tidak mencukupi', missing.length ? `Belum diisi: ${missing.join(', ')}` : 'Data wajib finalisasi terisi'].filter(Boolean).join(' · ');
    }
    function prepare(row, expanded) {
        const body = row.querySelector('.clinical-fields');
        body.id = `rx-fields-${++panelSequence}`;
        const toggle = row.querySelector('[data-toggle-rx]');
        toggle.hidden = false;
        toggle.setAttribute('aria-controls', body.id);
        summarize(row);
        setExpanded(row, expanded);
    }
    function refresh() {
        [...list.children].forEach((row,index)=>{
            row.querySelector('[data-rx-number]').textContent=index+1;
            row.querySelector('[data-toggle-rx]').setAttribute('aria-label', `Detail obat ${index+1}`);
        });
        add.disabled = list.children.length >= 30;
    }
    add.addEventListener('click',()=> {
        if (list.children.length >= 30) return;
        const row = template.content.cloneNode(true);
        row.querySelectorAll('[name]').forEach(field=>{field.name=field.name.replace('__INDEX__',String(sequence));});
        sequence++;
        [...list.children].forEach(item=>setExpanded(item,false));
        list.append(row); window.AppForms?.mark(list.closest('form'));
        prepare(list.lastElementChild,true);
        refresh();
        document.dispatchEvent(new Event('search-select:init'));
        list.lastElementChild.querySelector('.search-choice-trigger,select')?.focus();
    });
    list.addEventListener('click',async event=> {
        const toggle = event.target.closest('[data-toggle-rx]');
        if (toggle) {
            const row = toggle.closest('.rx-item');
            const expand = toggle.getAttribute('aria-expanded') !== 'true';
            if (expand) [...list.children].forEach(item=>setExpanded(item,false));
            setExpanded(row,expand);
            return;
        }
        const remove = event.target.closest('[data-remove-rx]');
        if (!remove) return;
        const row = remove.closest('.rx-item');
        const confirmed = await window.AppConfirm.open({title:'Hapus baris obat',message:'Hapus baris obat ini dari formulir? Perubahan baru tersimpan setelah Anda menyimpan draft.',confirmLabel:'Hapus baris',variant:'danger'});
        if (confirmed && row.isConnected) { row.remove(); refresh(); window.AppForms?.mark(list.closest('form')); add.focus(); }
    });
    list.addEventListener('input',event=>{const row=event.target.closest('.rx-item');if(row)summarize(row);});
    list.addEventListener('change',event=>{const row=event.target.closest('.rx-item');if(row)summarize(row);});
    list.addEventListener('app:reveal-field',event=>setExpanded(event.target.closest('.rx-item'),true));
    // Reveal invalid controls before the browser or searchable selector focuses them.
    list.addEventListener('invalid',event=>setExpanded(event.target.closest('.rx-item'),true),true);
    [...list.children].forEach((row,index)=>prepare(row,row.dataset.rxError === 'true' || index === 0));
    refresh();
})();
