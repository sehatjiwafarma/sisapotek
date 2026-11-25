const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

function setup(){
  const listeners={},nodes={};
  function node(key){return nodes[key]??=( {innerHTML:'',textContent:'',value:'',style:{},classList:{add(){},remove(){},toggle(){}},focus(){},insertAdjacentHTML(position,html){this.innerHTML+=html},setAttribute(){},removeAttribute(){}} )}
  const context=vm.createContext({console,Intl,Blob,URL,setTimeout:()=>0,clearTimeout(){},confirm:()=>true,location:{hash:'#layanan/kunjungan'},FormData:class{constructor(form){this.data=form.data}*[Symbol.iterator](){yield* Object.entries(this.data)}},document:{title:'',activeElement:node('focus'),body:{style:{}},querySelector:node,querySelectorAll:()=>[],addEventListener(name,fn){(listeners[name]??=[]).push(fn)}},window:{addEventListener(){},print(){}}});
  for(const file of ['app.js','clinical.js'])vm.runInContext(fs.readFileSync(path.join(__dirname,'..',file),'utf8'),context);
  return {run:code=>vm.runInContext(code,context),context,listeners,nodes};
}
test('pendaftaran membuat kunjungan tersendiri, menolak duplikasi aktif, dan meneruskan identitas',()=>{
  const {run}=setup();
  run("globalThis.created=registerVisit({patient:'RM-0002 · Ahmad Fauzi',doctor:'dr. Bima Saputra',date:'2026-10-28',type:'Kontrol',complaint:'Kontrol batuk'})");
  assert.equal(run('created.patientId'),'RM-0002');assert.equal(run('created.doctorId'),'DOK-002');assert.equal(run('created.status'),'Terdaftar');
  assert.throws(()=>run("registerVisit({patient:'RM-0002 · Ahmad Fauzi',doctor:'dr. Bima Saputra',date:'2026-10-28',type:'Kontrol',complaint:'Lagi'})"),/kunjungan aktif/);
  assert.equal(run("visits.find(v=>v.id==='KNJ-20261028-002').exam.diagnosis"),'Batuk dan pilek');
  run('startExamination(created.id)');assert.equal(run('created.status'),'Diperiksa');
});
test('draft pemeriksaan bisa dilanjutkan; final terkunci dan membuka resep dengan identitas tetap',()=>{
  const {run,nodes}=setup();
  run("saveExamination({visitId:'KNJ-20261028-001',subjective:'Demam'},false)");
  assert.equal(run("findVisit('KNJ-20261028-001').exam.status"),'Draft');
  assert.throws(()=>run("saveExamination({visitId:'KNJ-20261028-001',subjective:'Demam'},true)"),/wajib/);
  run("saveExamination({visitId:'KNJ-20261028-001',subjective:'Demam',diagnosis:'Febris',plan:'Terapi simtomatik'},true)");
  run("rxForm('KNJ-20261028-001')");
  const html=nodes['#modal-root'].innerHTML;
  assert.match(html,/Siti Nurhaliza/);assert.match(html,/dr. Nadia Putri/);assert.match(html,/Febris/);assert.match(html,/name="visitId" value="KNJ-20261028-001"/);assert.doesNotMatch(html,/select name="patient"|select name="doctor"/);
  assert.throws(()=>run("saveExamination({visitId:'KNJ-20261028-001'},false)"),/sudah final/);
});
test('resep memerlukan pemeriksaan final, tanda tangan, serta pemeriksaan alergi; terhubung dan tidak duplikat',async()=>{
  const {run}=setup();
  run("globalThis.payload={visitId:'KNJ-20261028-001',drug:'OBT-001 · Paracetamol 500 mg',qty:10,dose:'1 tablet',frequency:'3× sehari',instruction:'Sesudah makan'};globalThis.form={querySelector:()=>null}");
  await assert.rejects(run('finalizeClinicalPrescription(payload,form)'),/harus final/);
  run("saveExamination({visitId:payload.visitId,subjective:'Demam',diagnosis:'Febris',plan:'Terapi'},true)");
  await assert.rejects(run('finalizeClinicalPrescription(payload,form)'),/tanda tangan/);
  run("doctors[0].signature='data:image/png;base64,DEMO';payload.drug='OBT-002 · Amoxicillin 500 mg'");
  await assert.rejects(run('finalizeClinicalPrescription(payload,form)'),/alergi/);
  run("payload.drug='OBT-001 · Paracetamol 500 mg'");
  const rx=await run('finalizeClinicalPrescription(payload,form)');
  assert.equal(rx.visitId,'KNJ-20261028-001');assert.equal(rx.patientId,'RM-0001');assert.equal(rx.doctorId,'DOK-001');assert.equal(rx.status,'Menunggu');assert.equal(rx.diagnosis,'Febris');
  assert.equal(run("findVisit(payload.visitId).rxId"),rx.id);
  run("doctors[0].signature='changed'");assert.equal(rx.signature,'data:image/png;base64,DEMO');
  await assert.rejects(run('finalizeClinicalPrescription(payload,form)'),/sudah memiliki resep/);
});
test('submit pendaftaran → pemeriksaan → resep langsung meneruskan tahap berikutnya',async()=>{
  const {run,listeners,nodes}=setup();const submit=listeners.submit[0];
  const send=async(id,data)=>submit({preventDefault(){},target:{id,data,querySelector:()=>null}});
  await send('visit-form',{patient:'RM-0002 · Ahmad Fauzi',doctor:'dr. Bima Saputra',date:'2026-10-28',type:'Kontrol',complaint:'Sakit kepala'});
  assert.match(nodes['#modal-root'].innerHTML,/id="exam-form"/);
  const id=run('visits[0].id');
  await send('exam-form',{visitId:id,subjective:'Sakit kepala',diagnosis:'Cephalgia',plan:'Terapi dan istirahat'});
  assert.match(nodes['#modal-root'].innerHTML,/id="rx-form"/);
  run("doctors.find(d=>d.id==='DOK-002').signature='data:image/png;base64,DEMO'");
  await send('rx-form',{visitId:id,drug:'OBT-001 · Paracetamol 500 mg',qty:5,dose:'1 tablet',frequency:'2× sehari',instruction:'Sesudah makan'});
  assert.match(nodes['#modal-root'].innerHTML,/Resep terkirim ke farmasi/);
  assert.equal(run('prescriptions[0].visitId'),id);
  assert.equal(run('prescriptions[0].patient'),'Ahmad Fauzi');
  assert.equal(run('prescriptions[0].doctor'),'dr. Bima Saputra');
});
test('pasien baru kembali ke pendaftaran tanpa menghilangkan data kunjungan',async()=>{
  const {run,listeners,nodes}=setup();
  run("registrationDraft={doctor:'dr. Bima Saputra',date:'2026-10-28',type:'Kontrol',complaint:'Kontrol demam'}");
  await listeners.submit[0]({preventDefault(){},target:{id:'person-form',data:{type:'patient',name:'Pasien Uji',birth:'1995-01-01',sex:'Perempuan',phone:'0800000000',returnToVisit:'1'}}});
  assert.match(nodes['#modal-root'].innerHTML,/id="visit-form"/);
  assert.match(nodes['#modal-root'].innerHTML,/RM-0004 · Pasien Uji/);
  assert.match(nodes['#modal-root'].innerHTML,/Kontrol demam/);
  assert.match(nodes['#modal-root'].innerHTML,/<option selected>dr. Bima Saputra/);
});
test('kunjungan berikutnya tidak mewarisi SOAP atau resep final kunjungan lama',async()=>{
  const {run}=setup();
  const before=run("JSON.stringify(visits.find(v=>v.id==='KNJ-20261028-002'))");
  run("globalThis.followup=registerVisit({patient:'RM-0002 · Ahmad Fauzi',doctor:'dr. Bima Saputra',date:'2026-10-29',type:'Kontrol',complaint:'Keluhan baru'})");
  assert.equal(run('followup.exam'),null);assert.equal(run('followup.rxId'),null);
  run("saveExamination({visitId:followup.id,subjective:'Keluhan baru',diagnosis:'Diagnosis baru',plan:'Rencana baru'},true)");
  assert.equal(run("JSON.stringify(visits.find(v=>v.id==='KNJ-20261028-002'))"),before);
  assert.equal(run('visitRx(followup)'),undefined);
});
