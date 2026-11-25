'use strict';
document.querySelectorAll('[data-consultation-form]').forEach(form=>{
    const method=form.querySelector('[data-consultation-method]');
    const online=form.querySelector('[data-consultation-online]');
    const phone=form.querySelector('[data-consultation-phone]');
    const input=form.querySelector('[data-consultation-images]');
    const previews=form.querySelector('[data-consultation-previews]');
    const feedback=form.querySelector('[data-consultation-feedback]');
    let urls=[];
    function updateMethod(){online.hidden=method.value==='in_person';phone.hidden=method.value!=='telephone';}
    function refresh(){
        const captions=[...previews.querySelectorAll('input')].map(field=>field.value);
        urls.forEach(url=>URL.revokeObjectURL(url));urls=[];previews.replaceChildren();
        const files=[...input.files];
        const retained=form.querySelectorAll('input[name="retained_images[]"]:checked').length;
        let error='';
        if(files.length>6||files.length+retained>10)error='Maksimal 6 gambar baru dan 10 gambar total per versi.';
        else if(files.some(file=>file.size>5*1024*1024||!['image/png','image/jpeg'].includes(file.type)))error='Gunakan PNG/JPG maksimal 5 MB per gambar.';
        input.setCustomValidity(error);feedback.textContent=error;
        if(error)return;
        files.forEach((file,index)=>{
            const card=document.createElement('div');card.className='consultation-image';
            const img=document.createElement('img');const url=URL.createObjectURL(file);urls.push(url);img.src=url;img.alt='Pratinjau screenshot konsultasi';
            const label=document.createElement('label');label.textContent='Keterangan screenshot '+(index+1);
            const caption=document.createElement('input');caption.name='new_captions['+index+']';caption.maxLength=300;caption.placeholder='Contoh: Konfirmasi keluhan';caption.value=captions[index]||'';
            label.append(caption);card.append(img,label);previews.append(card);
        });
    }
    method.addEventListener('change',updateMethod);input.addEventListener('change',refresh);
    form.querySelectorAll('input[name="retained_images[]"]').forEach(field=>field.addEventListener('change',refresh));
    window.addEventListener('pagehide',()=>urls.forEach(url=>URL.revokeObjectURL(url)));
    updateMethod();
});
