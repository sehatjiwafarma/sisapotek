'use strict';

// Kunjungan merupakan sumber data bersama untuk registrasi, SOAP, dan resep.
let visits = patients.map((p,i)=>({
  id:`KNJ-20261028-${String(i+1).padStart(3,'0')}`,
  patientId:p.id,doctorId:doctors.find(d=>d.name===p.doctor)?.id,
  date:'2026-10-28',time:['09:00','09:15','09:30'][i],
  queue:`A-${String(i+1).padStart(3,'0')}`,type:i===2?'Kontrol':'Baru',
  complaint:p.complaint,status:i===0?'Terdaftar':'Selesai',
  exam:i===0?null:{subjective:p.complaint,blood:'120/80',pulse:'80',temperature:'36.5',objective:'Catatan pemeriksaan contoh',diagnosis:i===1?'Batuk dan pilek':'Kontrol kesehatan',plan:'Terapi sesuai resep dan edukasi pasien',followup:'Kontrol bila keluhan menetap',status:'Final'},
  amendments:[],rxId:i===0?null:prescriptions[i]?.id
}));
// Resep contoh milik pasien pertama berasal dari kunjungan sebelumnya.
visits.push({id:'KNJ-20261027-001',patientId:patients[0].id,doctorId:doctors[0].id,date:'2026-10-27',time:'10:42',queue:'A-001',type:'Baru',complaint:'Demam',status:'Selesai',exam:{subjective:'Demam',diagnosis:'Demam',plan:'Terapi simtomatik',status:'Final'},amendments:[],rxId:prescriptions[0].id});
prescriptions.forEach((r,i)=>{const v=visits.find(v=>v.rxId===r.id);r.visitId=v.id;r.patientId=v.patientId;r.doctorId=v.doctorId});
let registrationDraft=null;
let clinicalSequence=visits.length;
const visitPatient = v => patients.find(p=>p.id===v.patientId);
const visitDoctor = v => doctors.find(d=>d.id===v.doctorId);
const visitRx = v => prescriptions.find(r=>r.visitId===v.id);
const findVisit = id => visits.find(v=>v.id===id)||visits.find(v=>v.patientId===id&&v.date==='2026-10-28')||visits.find(v=>v.patientId===id);
const readonlyField=(label,value)=>`<label class="field"><span>${label}</span><input value="${esc(value)}" readonly></label>`;
function clinicalSteps(v,stage){return `<div class="care-steps" aria-label="Tahap layanan">${['Pendaftaran','Pemeriksaan','Resep dokter'].map((label,i)=>`<div class="care-step ${i===stage?'current':i<stage?'done':''}"><span>${i<stage?'✓':i+1}</span><div><strong>${label}</strong><small>${i===0?(v?esc(v.id):'Data kunjungan'):i===1?(v?.exam?.status==='Final'?'Catatan final':'SOAP & diagnosis'):(v&&visitRx(v)?esc(visitRx(v).id):'Terhubung ke farmasi')}</small></div></div>`).join('')}</div>`}
function visitContext(v){const p=visitPatient(v),d=visitDoctor(v);return `<div class="care-context"><div><small>Pasien / rekam medis</small><strong>${esc(p.name)} · ${p.id}</strong></div><div><small>Dokter pemeriksa</small><strong>${esc(d?.name||'—')}</strong></div><div><small>Kunjungan</small><strong>${formatDate(v.date)} · ${v.queue}</strong></div></div>`}

services = function(){
  const q=search.toLowerCase();
  const list=visits.filter(v=>`${v.id} ${visitPatient(v)?.name} ${v.patientId} ${visitDoctor(v)?.name}`.toLowerCase().includes(q));
  let body;
  if(sub==='resep'){
    body=table(['NOMOR RESEP','KUNJUNGAN','PASIEN','DOKTER','STATUS',''],prescriptions.filter(r=>JSON.stringify(r).toLowerCase().includes(q)).map(r=>[r.id,r.visitId||'—',esc(r.patient),esc(r.doctor),badge(r.status),btn('Lihat resep',`rx:${r.id}`,'small')]));
  }else if(sub==='medis'){
    body=table(['NO. REKAM MEDIS','PASIEN','KUNJUNGAN','CATATAN FINAL',''],patients.filter(p=>`${p.id} ${p.name}`.toLowerCase().includes(q)).map(p=>[p.id,`<strong>${esc(p.name)}</strong>`,visits.filter(v=>v.patientId===p.id).length,visits.filter(v=>v.patientId===p.id&&v.exam?.status==='Final').length,btn('Lihat riwayat',`patient:${p.id}`,'small')]));
  }else{
    body=table(['ANTREAN / TANGGAL','PASIEN','KUNJUNGAN','KELUHAN','DOKTER','PROGRES LAYANAN',''],list.map(v=>{
      const p=visitPatient(v),r=visitRx(v);
      return [`${badge(v.queue,'gray')}<small>${formatDate(v.date)} · ${v.time} WIB</small>`,`<strong>${esc(p.name)}</strong><small>${p.id}</small>${p.allergy&&p.allergy!=='Tidak diketahui'?`<small class="text-red">⚠ Alergi ${esc(p.allergy)}</small>`:''}`,v.id,esc(v.complaint),esc(visitDoctor(v)?.name),`${badge(v.status)}<small>${r?'Resep '+r.id:v.exam?.status==='Final'?'Pemeriksaan final · belum ada resep':v.exam?'Draft pemeriksaan tersimpan':'Menunggu pemeriksaan'}</small>`,btn(r?'Lihat resep':v.exam?.status==='Final'?'Buat resep':v.status==='Terdaftar'?'Mulai periksa':'Lanjutkan periksa',`${r?'rx':v.exam?.status==='Final'?'new-rx':'examine'}:${r?r.id:v.id}`,'small')];
    }));
  }
  return heading('Layanan pasien','Satu kunjungan, dari pendaftaran hingga resep dan farmasi.',btn('Daftarkan kunjungan','new-visit','primary','plus'))+subnav([['kunjungan','Kunjungan & pemeriksaan'],['medis','Rekam medis'],['resep','Resep dokter']])+`<div class="notice">Pendaftaran → pemeriksaan SOAP → finalisasi → resep dokter → antrean farmasi. Pilih aksi pada kunjungan untuk melanjutkan tahapnya.</div><section class="card"><div class="toolbar">${searchBox('Cari pasien, rekam medis, atau kunjungan…')}${sub==='resep'?btn('Pilih pemeriksaan untuk resep','choose-rx','','plus'):''}</div>${body}</section>`;
};

visitForm = function(patientId){
  const draft=registrationDraft||{},selected=patients.find(p=>p.id===(patientId||draft.patientId))||patients[0];
  formModal('Pendaftaran kunjungan','Pasien, dokter, dan keluhan diteruskan otomatis ke pemeriksaan.','visit-form',`${clinicalSteps(null,0)}<div class="form-grid">${selectField('Pasien','patient',patients.map(p=>`${p.id} · ${p.name}`),selected?`${selected.id} · ${selected.name}`:'')}${selectField('Dokter pemeriksa','doctor',doctors.filter(d=>d.active).map(d=>d.name),draft.doctor)}${selectField('Jenis kunjungan','type',['Baru','Kontrol'],draft.type)}${field('Tanggal kunjungan','date',draft.date||'2026-10-28','date',true)}${textField('Keluhan utama','complaint',draft.complaint||'',true)}</div><div style="margin-top:15px">${btn('+ Daftarkan pasien baru','register-patient','text')}</div>`,'Daftarkan & buka pemeriksaan');
};

function startExamination(id){const v=findVisit(id);if(!v)return toast('Kunjungan tidak ditemukan. Daftarkan kunjungan terlebih dahulu.');if(v.status==='Terdaftar'){v.status='Diperiksa';audit('Mulai pemeriksaan',v.id)}examine(v.id);render()}
examine = function(id){
  const v=findVisit(id);if(!v)return toast('Daftarkan kunjungan sebelum pemeriksaan.');
  const p=visitPatient(v),x=v.exam||{},r=visitRx(v);
  if(x.status==='Final'){
    modal('Pemeriksaan final',v.id,`${clinicalSteps(v,1)}${visitContext(v)}<div class="notice">Catatan final terkunci. Lanjutkan pembuatan resep dari kunjungan ini.</div><dl class="detail-grid"><div><dt>Subjektif</dt><dd>${esc(x.subjective)}</dd></div><div><dt>Diagnosis</dt><dd>${esc(x.diagnosis)}</dd></div><div><dt>Rencana</dt><dd>${esc(x.plan)}</dd></div><div><dt>Tekanan darah</dt><dd>${esc(x.blood||'—')}</dd></div><div><dt>Suhu</dt><dd>${esc(x.temperature||'—')} °C</dd></div><div><dt>Tindak lanjut</dt><dd>${esc(x.followup||'—')}</dd></div></dl>${v.amendments.length?`<h3 class="section-title">Amendemen</h3>${v.amendments.map(a=>`<div class="notice">${esc(a.text)}<br>Alasan: ${esc(a.reason)}<br><small>${esc(a.user)} · ${esc(a.time)}</small></div>`).join('')}`:''}`,`${btn('Tutup','close-modal')}${btn('Tambah amendemen',`amend:${v.id}`)}${btn(r?'Lihat resep':'Lanjut buat resep',`${r?'rx':'new-rx'}:${r?r.id:v.id}`,'primary')}`);return;
  }
  formModal('Pemeriksaan pasien',`${v.id} · Catatan terhubung ke kunjungan`,'exam-form',`${clinicalSteps(v,1)}${visitContext(v)}<input type="hidden" name="visitId" value="${v.id}">${p.allergy&&p.allergy!=='Tidak diketahui'?`<div class="notice red">⚠ Alergi obat: ${esc(p.allergy)}</div>`:''}<h3 class="section-title">S — Subjektif</h3><div class="form-grid">${textField('Anamnesis / keluhan','subjective',x.subjective||v.complaint,true)}</div><h3 class="section-title">O — Objektif</h3><div class="form-grid">${field('Tekanan darah (mmHg)','blood',x.blood||'')}${field('Nadi (x/menit)','pulse',x.pulse||'','number')}${field('Suhu tubuh (°C)','temperature',x.temperature||'','number')}${field('Berat badan (kg)','weight',x.weight||'','number')}${field('Tinggi badan (cm)','height',x.height||'','number')}${textField('Temuan objektif','objective',x.objective||'')}</div><h3 class="section-title">A / P — Assessment & rencana</h3><div class="form-grid">${textField('Diagnosis / assessment','diagnosis',x.diagnosis||'',true)}${textField('Rencana / tindakan','plan',x.plan||'',true)}${textField('Catatan tindak lanjut','followup',x.followup||'')}</div><div class="notice amber" style="margin-top:18px">Finalisasi mengunci catatan dan membuka editor resep untuk pasien serta dokter ini.</div>`,'Finalkan & lanjut ke resep');
  $('.modal-foot').insertAdjacentHTML('afterbegin',btn('Simpan draft','save-exam-draft'));
};

rxForm = function(id){
  const v=findVisit(id);if(!v){choosePrescriptionVisit();return}
  if(v.exam?.status!=='Final'){toast('Finalkan pemeriksaan kunjungan sebelum membuat resep.');examine(v.id);return}
  const r=visitRx(v);if(r){rxDetail(r.id);return}
  const p=visitPatient(v),doc=visitDoctor(v);
  formModal('Resep dari pemeriksaan',`${v.id} · Identitas mengikuti kunjungan`,'rx-form',`${clinicalSteps(v,2)}<input type="hidden" name="visitId" value="${v.id}"><div class="form-grid">${readonlyField('Pasien / rekam medis',`${p.name} · ${p.id}`)}${readonlyField('Dokter pemeriksa',doc.name)}${readonlyField('Nomor kunjungan',v.id)}${readonlyField('Diagnosis pemeriksaan',v.exam.diagnosis)}</div><div class="notice ${p.allergy==='Tidak diketahui'?'amber':'red'}" style="margin-top:18px">Alergi pasien: ${esc(p.allergy||'Tidak diketahui')}. Verifikasi sebelum meresepkan.</div><div class="form-grid">${selectField('Obat','drug',drugs.filter(d=>d.active&&!expired(d)).map(d=>`${d.id} · ${d.name}`))}${field('Jumlah diresepkan','qty',10,'number',true)}${field('Dosis per konsumsi','dose','1 tablet','text',true)}${field('Frekuensi','frequency','3× sehari','text',true)}${selectField('Rute pemberian','route',['Oral','Topikal','Lainnya'])}${field('Durasi','duration','3 hari')}${textField('Aturan pakai','instruction','Sesudah makan',true)}${textField('Catatan dokter','note')}</div><h3 class="section-title">Tanda tangan ${esc(doc.name)}</h3>${doc.signature?`<img src="${doc.signature}" alt="Tanda tangan dokter" style="max-width:150px;max-height:70px">`:'<div class="notice amber">Tanda tangan belum terdaftar. Unggah di sini untuk melanjutkan tanpa meninggalkan resep.</div>'}<label class="field"><span>${doc.signature?'Ganti gambar tanda tangan (opsional)':'Unggah tanda tangan PNG / JPG *'}</span><input name="signature" type="file" accept="image/png,image/jpeg" ${doc.signature?'':'required'}><small>Maksimal 2 MB. Disimpan ke profil dokter dan snapshot resep.</small></label><div class="notice" style="margin-top:18px">Setelah dikirim, resep langsung tampil pada antrean Farmasi dengan referensi kunjungan yang sama.</div>`,'Finalkan & kirim ke farmasi');
};

function choosePrescriptionVisit(){const ready=visits.filter(v=>v.exam?.status==='Final'&&!visitRx(v));modal('Pilih pemeriksaan final','Resep harus dibuat dari pemeriksaan kunjungan yang telah final.',table(['KUNJUNGAN','PASIEN','DOKTER','DIAGNOSIS',''],ready.map(v=>[v.id,esc(visitPatient(v).name),esc(visitDoctor(v).name),esc(v.exam.diagnosis),btn('Buat resep',`new-rx:${v.id}`,'small')]),'Belum ada pemeriksaan final tanpa resep. Selesaikan pemeriksaan terlebih dahulu.'),`${btn('Tutup','close-modal')}${btn('Daftarkan kunjungan','new-visit','primary')}`,true)}

patientDetail = function(id){const p=patients.find(p=>p.id===id);const history=visits.filter(v=>v.patientId===id);modal('Rekam medis pasien',`${p.id} · ${esc(p.name)}`,`${p.allergy&&p.allergy!=='Tidak diketahui'?`<div class="notice red">⚠ Alergi obat: ${esc(p.allergy)}</div>`:''}<dl class="detail-grid"><div><dt>Tanggal lahir</dt><dd>${formatDate(p.birth)}</dd></div><div><dt>Jenis kelamin</dt><dd>${esc(p.sex)}</dd></div><div><dt>Telepon</dt><dd>${esc(p.phone)}</dd></div></dl><h3 class="section-title">Riwayat per kunjungan</h3><div class="timeline">${history.map(v=>{const r=visitRx(v);return `<div><strong>${formatDate(v.date)} · ${v.id}</strong><p>${esc(visitDoctor(v).name)} · ${esc(v.type)}<br>Keluhan: ${esc(v.complaint)}<br>${v.exam?.status==='Final'?`Diagnosis: ${esc(v.exam.diagnosis)}<br>Rencana: ${esc(v.exam.plan)}`:'Pemeriksaan belum final.'}<br>${r?`Resep: ${r.id} · ${r.status}`:'Belum ada resep.'}</p><div class="actions">${btn(v.exam?.status==='Final'?'Lihat pemeriksaan':'Lanjutkan periksa',`examine:${v.id}`,'small')}${r?btn('Lihat resep',`rx:${r.id}`,'small'):v.exam?.status==='Final'?btn('Buat resep',`new-rx:${v.id}`,'small'):''}</div></div>`}).join('')||'<p>Belum ada kunjungan.</p>'}</div>`,`${btn('Ubah identitas',`edit-patient:${id}`)}${btn('Kunjungan baru',`visit-patient:${id}`,'primary')}`,true)};

function registerVisit(v){
  const p=patients.find(p=>p.id===v.patient.split(' · ')[0]),doc=doctors.find(d=>d.name===v.doctor&&d.active);
  if(!p||!doc)throw Error('Pasien atau dokter tidak ditemukan / tidak aktif.');
  if(visits.some(x=>x.patientId===p.id&&x.date===v.date&&['Terdaftar','Diperiksa'].includes(x.status)))throw Error('Pasien masih memiliki kunjungan aktif pada tanggal ini. Lanjutkan pemeriksaan kunjungan tersebut.');
  const n=++clinicalSequence;
  const visit={id:`KNJ-${v.date.replaceAll('-','')}-${String(n).padStart(3,'0')}`,patientId:p.id,doctorId:doc.id,date:v.date,time:'11:00',queue:`A-${String(n).padStart(3,'0')}`,type:v.type,complaint:v.complaint.trim(),status:'Terdaftar',exam:null,amendments:[],rxId:null};
  if(!visit.complaint)throw Error('Keluhan utama wajib diisi.');
  visits.unshift(visit);registrationDraft=null;audit('Daftarkan kunjungan',visit.id);return visit;
}
function saveExamination(v,finalize){
  const visit=visits.find(x=>x.id===v.visitId);if(!visit||visit.exam?.status==='Final')throw Error('Pemeriksaan tidak tersedia atau sudah final.');
  if(finalize&&(!v.subjective?.trim()||!v.diagnosis?.trim()||!v.plan?.trim()))throw Error('Subjektif, diagnosis, dan rencana wajib diisi sebelum finalisasi.');
  if(v.blood&&!/^\d{2,3}\/\d{2,3}$/.test(v.blood))throw Error('Tekanan darah menggunakan format 120/80.');
  if((v.pulse&&(+v.pulse<30||+v.pulse>220))||(v.temperature&&(+v.temperature<30||+v.temperature>45)))throw Error('Periksa kembali nadi atau suhu tubuh di luar rentang wajar.');
  visit.exam={...v,status:finalize?'Final':'Draft',doctorId:visit.doctorId,finalizedAt:finalize?'28/10/2026 · sesi demo':null};
  visit.status=finalize?'Selesai':'Diperiksa';audit(finalize?'Finalisasi pemeriksaan':'Simpan draft pemeriksaan',visit.id);return visit;
}
async function finalizeClinicalPrescription(v,f){
  const visit=visits.find(x=>x.id===v.visitId);if(!visit||visit.exam?.status!=='Final')throw Error('Pemeriksaan kunjungan harus final.');
  if(visitRx(visit))throw Error('Kunjungan sudah memiliki resep. Buka resep yang telah dibuat.');
  const p=visitPatient(visit),doc=visitDoctor(visit),d=drugs.find(d=>d.id===v.drug.split(' · ')[0]);
  if(!d||!d.active||expired(d))throw Error('Obat tidak tersedia untuk resep.');
  if(!Number.isFinite(+v.qty)||+v.qty<=0)throw Error('Jumlah resep harus lebih dari 0.');
  if(!v.dose?.trim()||!v.frequency?.trim()||!v.instruction?.trim())throw Error('Dosis, frekuensi, dan aturan pakai wajib diisi.');
  if(/penisilin/i.test(p.allergy)&&/amoxicillin/i.test(d.name))throw Error('Obat berpotensi konflik dengan alergi penisilin. Tinjau resep.');
  let signature=doc.signature;const file=f.querySelector('[name=signature]')?.files[0];
  if(file?.size){if(file.size>2*1024*1024||!['image/png','image/jpeg'].includes(file.type))throw Error('Tanda tangan harus PNG/JPG maksimal 2 MB.');signature=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=reject;reader.readAsDataURL(file)})}
  if(!signature)throw Error('Unggah tanda tangan dokter sebelum memfinalkan resep.');
  // Periksa lagi setelah pembacaan berkas agar submit ganda tidak menduplikasi resep.
  if(visitRx(visit))throw Error('Resep kunjungan ini sudah dikirim.');
  if(signature!==doc.signature){doc.signature=signature;audit('Unggah tanda tangan dokter',doc.id)}
  const r={id:`RSP-20261028-${String(13+prescriptions.length-3).padStart(3,'0')}`,visitId:visit.id,patientId:p.id,doctorId:doc.id,patient:p.name,doctor:doc.name,time:'11:00',date:visit.date,status:'Menunggu',drug:d.id,qty:+v.qty,dose:v.dose,frequency:v.frequency,instruction:v.instruction,allergy:p.allergy,signature,route:v.route,duration:v.duration,note:v.note,diagnosis:visit.exam.diagnosis};
  prescriptions.unshift(r);visit.rxId=r.id;audit('Finalisasi resep',`${r.id} · ${visit.id}`);return r;
}
function clinicalHandoff(r){const v=visits.find(v=>v.id===r.visitId);modal('Resep terkirim ke farmasi',`${r.id} · ${v.id}`,`${clinicalSteps(v,2)}${visitContext(v)}<div class="notice">✓ Pendaftaran, catatan pemeriksaan final, dan resep telah terhubung. Petugas farmasi dapat melanjutkan verifikasi dan penyerahan.</div>${table(['RESEP','DIAGNOSIS','STATUS'],[[r.id,esc(r.diagnosis),badge(r.status)]])}`,`${btn('Lihat resep',`rx:${r.id}`)}${btn('Buka antrean farmasi','open-pharmacy','primary')}`)}

document.addEventListener('click',e=>{const target=e.target.closest('[data-action]');if(!target)return;const [a,id]=target.dataset.action.split(':');switch(a){
  case'choose-rx':choosePrescriptionVisit();break;
  case'visit-patient':registrationDraft=null;visitForm(id);break;
  case'register-patient':{const f=$('#visit-form');const values=Object.fromEntries(new FormData(f));registrationDraft={...values,patientId:values.patient.split(' · ')[0]};personForm('patient');$('#person-form').insertAdjacentHTML('afterbegin','<input type="hidden" name="returnToVisit" value="1">');$('.modal-foot').insertAdjacentHTML('afterbegin',btn('Kembali ke pendaftaran','resume-registration'));break}
  case'resume-registration':visitForm();break;
  case'save-exam-draft':{try{const f=$('#exam-form');const v=saveExamination(Object.fromEntries(new FormData(f)),false);closeModal(true);render();toast(`Draft pemeriksaan ${v.id} tersimpan.`)}catch(err){toast(err.message)}break}
  case'open-pharmacy':closeModal(true);location.hash='farmasi';if(page==='farmasi')render();break;
}});
render();
