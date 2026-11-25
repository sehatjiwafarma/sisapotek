@include('examinations.images')
@if(!empty($payload['consultation_version_id']))<div class="clinical-note"><a href="{{ route('examinations.consultation.version', [$currentPharmacy, $visit->id, $payload['consultation_version_id']]) }}">Lihat snapshot bukti konsultasi pada versi SOAP ini</a></div>@endif
<section class="card soap-record"><header class="clinical-section"><h2>SOAP pemeriksaan</h2><p>{{ isset($version) && $version ? 'Dokumen versi '.$version->version.' · '.$version->doctor_name : 'Draft tersimpan; belum merupakan hasil final.' }}</p></header>
<dl class="soap-vitals">@foreach(\App\Services\ExaminationRecords::VITALS as $key=>[$label,$unit])<div><dt>{{ $label }}</dt><dd>{{ isset($payload[$key]) ? $payload[$key].' '.$unit : 'Belum dicatat' }}</dd></div>@endforeach</dl>
<div class="soap-reading"><h3>Hasil tes urine</h3><p>{{ $payload['urine_test'] ?? 'Belum dicatat' }}</p></div>
@if(($payload['documentation_mode'] ?? 'FORM') === 'FORM')
@foreach(\App\Services\ExaminationRecords::TEXTS as $key=>$label)<div class="soap-reading"><h3>{{ ['subjective'=>'S — ','objective'=>'O — ','assessment'=>'A — ','plan'=>'P — '][$key] ?? '' }}{{ $label }}</h3><p>{{ $payload[$key] ?? 'Belum dicatat' }}</p></div>@endforeach
@endif
@if(isset($version) && $version && $access->allows('patients.view_clinical'))<div class="soap-reading"><h3>Alergi tercatat saat versi ini dibuat</h3><p>{{ $version->allergies ?: 'Belum dicatat pada master pasien saat versi ini dibuat.' }}</p></div>@endif
</section>
