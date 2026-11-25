'use strict';
(() => {
    const mode = document.querySelector('select[name="documentation_mode"]');
    if (!mode) return;
    const form = mode.form;
    function updateMode() {
        const structured = mode.value === 'FORM';
        form.querySelectorAll('[data-soap-structured]').forEach(section=>{section.hidden=!structured;});
        const help = document.getElementById('soap-mode-help');
        if (help) help.textContent = structured
            ? 'Isi S–O–A–P pada formulir di bawah. Gambar dapat ditambahkan sebagai lampiran.'
            : 'Unggah gambar SOAP yang jelas dan lengkap. Tidak perlu mengisi ulang S–O–A–P.';
    }
    mode.addEventListener('change',updateMode);
    updateMode();
    const input = form.querySelector('input[name="images[]"]');
    const previews = document.getElementById('soap-image-previews');
    const feedback = document.getElementById('soap-image-feedback');
    let urls = [];
    function refresh() {
        urls.forEach(url=>URL.revokeObjectURL(url)); urls=[]; previews.replaceChildren();
        const files = [...input.files];
        const retained = form.querySelectorAll('input[name="image_ids[]"]:checked').length;
        let message = '';
        if(files.length>6 || retained+files.length>10) message='Maksimal 6 gambar baru dan 10 gambar total per versi.';
        else if(files.some(file=>file.size>5*1024*1024 || !['image/png','image/jpeg'].includes(file.type))) message='Gunakan PNG/JPG maksimal 5 MB per gambar.';
        input.setCustomValidity(message); feedback.textContent=message;
        if(message) return;
        for(const file of files) {
            const item = document.createElement('div');
            const img = document.createElement('img');
            const caption = document.createElement('small');
            const url = URL.createObjectURL(file); urls.push(url); img.src=url; img.alt='Pratinjau gambar SOAP'; caption.textContent=file.name;
            item.append(img,caption); previews.append(item);
        }
    }
    input.addEventListener('change',refresh);
    form.querySelectorAll('input[name="image_ids[]"]').forEach(field=>field.addEventListener('change',refresh));
    window.addEventListener('pagehide',()=>urls.forEach(url=>URL.revokeObjectURL(url)));
})();
