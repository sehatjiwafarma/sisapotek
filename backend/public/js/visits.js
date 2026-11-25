'use strict';
(() => {
    const button = document.getElementById('refresh-visit-patients');
    if (!button) return;
    button.addEventListener('click', async () => {
        const feedback = document.getElementById('visit-patient-feedback');
        const select = document.querySelector('select[name="patient_id"]');
        const selected = select.value;
        button.disabled = true;
        feedback.textContent = 'Memuat pilihan pasien…';
        try {
            const response = await fetch(button.dataset.url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error('request');
            const patients = await response.json();
            select.replaceChildren(new Option('Pilih pasien berdasarkan nama atau nomor RM', ''));
            for (const patient of patients) {
                const birth = patient.birth_date.slice(0,10).split('-').reverse().join('/');
                select.add(new Option(`${patient.name} · ${patient.medical_record_number} · ${birth}`, patient.id));
            }
            select.value = selected;
            select.dispatchEvent(new Event('change', {bubbles: true}));
            const submit = select.form.querySelector('button[type="submit"], button.btn.primary');
            if (submit) submit.disabled = !patients.length || !select.form.querySelector('select[name="doctor_id"] option[value]:not([value=""])');
            feedback.textContent = 'Pilihan pasien diperbarui. Pilih pasien baru; isian kunjungan Anda tetap tersimpan di formulir ini.';
        } catch {
            feedback.textContent = 'Pilihan pasien belum dapat diperbarui. Periksa koneksi atau sesi masuk, lalu coba lagi.';
        } finally {
            button.disabled = false;
        }
    });
})();
