'use strict';
(() => {
  const input = document.querySelector('[data-signature-input]');
  const preview = document.querySelector('[data-signature-preview]');
  if (!input || !preview) return;
  let objectUrl;
  input.addEventListener('change',() => {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    preview.hidden = true; preview.removeAttribute('src'); input.setCustomValidity('');
    const file = input.files?.[0];
    if (!file) return;
    if (!['image/png','image/jpeg'].includes(file.type) || file.size > 2*1024*1024) {
      input.setCustomValidity('Gunakan gambar PNG/JPG maksimal 2 MB.'); input.reportValidity(); return;
    }
    objectUrl = URL.createObjectURL(file); preview.src = objectUrl; preview.hidden = false;
  });
  preview.addEventListener('error',() => { preview.hidden = true; input.setCustomValidity('Berkas gambar tidak dapat dibaca. Pilih PNG/JPG yang valid.'); });
})();
