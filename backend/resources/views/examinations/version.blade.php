@extends('layouts.app')
@section('title','Versi pemeriksaan')
@include('examinations.assets')
@section('content')
<div class="products-page soap-page"><div class="heading"><div><span class="eyebrow">REKAM MEDIS / RIWAYAT VERSI</span><h1>Pemeriksaan versi {{ $version->version }}</h1><p>{{ $visit->patient_name }} · {{ $visit->medical_record_number }} · {{ $visit->number }}</p></div><a class="btn" href="{{ route('examinations.show',[$currentPharmacy,$visit->id]) }}">Kembali ke hasil terkini</a></div>
<div class="clinical-note"><strong>{{ $version->kind === 'FINAL' ? 'Final awal' : 'Amendemen' }} · {{ $version->doctor_name }}</strong><p>{{ \Illuminate\Support\Carbon::parse($version->created_at)->timezone($currentPharmacy->settings?->timezone ?: 'Asia/Jakarta')->format('d/m/Y H:i') }} · {{ $version->doctor_code }} · SIP {{ $version->sip_number ?: 'Belum dicatat' }} · STR {{ $version->str_number ?: 'Belum dicatat' }}</p><p>Versi {{ $version->version }} dari {{ $exam->current_version }}. Catatan versi ini tidak dapat diubah.</p>@if($version->reason)<p class="soap-version-reason"><strong>Alasan koreksi:</strong> {{ $version->reason }}</p>@endif</div>
@include('examinations.payload')
</div>
@endsection