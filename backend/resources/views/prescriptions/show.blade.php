@extends('layouts.app')
@section('title','Resep pasien')
@include('examinations.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/prescriptions.css') }}?v={{ filemtime(public_path('css/prescriptions.css')) }}">@endpush
@section('content')
<div class="products-page soap-page rx-page"><div class="heading"><div><span class="eyebrow">LAYANAN PASIEN / RESEP</span><h1>{{ $prescription?->number ?? 'Resep pasien' }}</h1><p>{{ $visit->patient_name }} · {{ $visit->medical_record_number }} · {{ $visit->number }}</p></div>@if($access->allows('visits.view'))<a class="btn" href="{{ route('visits.show',[$currentPharmacy,$visit->id]) }}">Detail kunjungan</a>@endif</div>
@include('partials.clinical-path')
@include('prescriptions.collection', ['rxListRoute'=>'prescriptions.show'])
<p class="clinical-note">Status SOAP: <strong>{{ ['DRAFT'=>'Draft','FINAL'=>'Final'][$soapStatus] ?? 'Belum dilengkapi' }}</strong>. Resep dapat dibuat bersamaan dengan pemeriksaan dan disiapkan untuk penebusan setelah disimpan.</p>
@if($access->allows('patients.view_clinical'))<div class="clinical-alert"><strong>Alergi master pasien saat ini</strong><p>{{ $allergies ?: 'Belum dicatat. Konfirmasikan kepada pasien; ini bukan pernyataan tidak ada alergi.' }}</p></div>@endif
<div class="soap-editor"><section class="card"><header class="clinical-section"><h2>{{ $prescription?->status === 'FINAL' ? 'Resep final' : 'Draft resep' }}</h2><p>Dokter penanggung jawab: {{ $payload['doctor']['name'] ?? $visit->doctor_name }}</p></header>
@if($prescription)<p>Berlaku sampai: <strong>{{ $prescription->valid_until ? \Illuminate\Support\Carbon::parse($prescription->valid_until)->format('d/m/Y') : 'Belum ditetapkan' }}</strong></p>@include('prescriptions.items')@if(!empty($payload['notes']))<div class="soap-reading"><h3>Catatan resep</h3><p>{{ $payload['notes'] }}</p></div>@endif @else<div class="product-empty"><h2>Resep belum dibuat</h2><p>{{ $canWrite ? "Buat draft resep dari kunjungan ini tanpa menunggu SOAP final." : "Resep belum dicatat oleh dokter penanggung jawab atau Owner." }}</p></div>@endif
</section><aside class="soap-aside"><section class="card soap-guide"><h2>Tindakan resep</h2>@if(!$canWrite)<p>Anda memiliki akses baca. Penulisan memerlukan akses Owner atau dokter penanggung jawab serta dokter, pasien dan apotek aktif.</p>@endif
@if($canWrite && (!$prescription || $prescription->status === 'DRAFT') && $access->allows($prescription ? 'prescriptions.update' : 'prescriptions.create'))<a class="btn primary" href="{{ route('prescriptions.edit',[$currentPharmacy,$visit->id,'prescription'=>$prescription?->id ?? 'new']) }}">{{ $prescription ? 'Edit draft resep' : 'Buat draft resep' }}</a>@endif

@if($prescription && ($prescription->status === 'FINAL' || $prescription->ready_at))@if($access->allows('dispensing.view'))<a class="btn primary" href="{{ route('dispensing.show',[$currentPharmacy,$prescription->id]) }}">Buka penebusan</a>@endif<p>Resep dapat ditebus bertahap melalui farmasi.</p>@if($access->allows('prescriptions.print'))<a class="btn" target="_blank" rel="noopener" href="{{ route('prescriptions.print',[$currentPharmacy,$visit->id,'prescription'=>$prescription?->id ?? 'new']) }}">Cetak resep</a>@endif<p>{{ $prescription->signature_id ? 'Tanda tangan dokter tersimpan.' : 'Resep ini belum memiliki gambar tanda tangan dokter.' }}</p>@endif
@if($canReadExamination)<p><a href="{{ route('examinations.show',[$currentPharmacy,$visit->id]) }}">Lengkapi / lihat SOAP</a></p>@endif
</section></aside></div></div>
@endsection