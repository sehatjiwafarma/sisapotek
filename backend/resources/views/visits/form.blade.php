@extends('layouts.app')
@section('title',$visit ? 'Edit kunjungan' : 'Daftarkan kunjungan')
@include('clinical.assets')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/procurement.css') }}?v={{ filemtime(public_path('css/procurement.css')) }}">
<link rel="stylesheet" href="{{ asset('css/visits.css') }}?v={{ filemtime(public_path('css/visits.css')) }}">
@endpush
@push('master-scripts')
<script src="{{ asset('js/search-select.js') }}" defer></script>
<script src="{{ asset('js/visits.js') }}?v={{ filemtime(public_path('js/visits.js')) }}" defer></script>
@endpush
@section('content')
@php
    $returnUrl = $visit ? route('visits.show',[$currentPharmacy,$visit->id]) : route('visits.index',$currentPharmacy);
    $timezone = $currentPharmacy->settings?->timezone ?: 'Asia/Jakarta';
@endphp
<div class="products-page visit-registration">
    <div class="heading">
        <div><span class="eyebrow">LAYANAN PASIEN / KUNJUNGAN & ANTREAN</span><h1>{{ $visit ? 'Edit pendaftaran' : 'Daftarkan kunjungan' }}</h1><p>{{ $visit ? 'Perbarui kebutuhan layanan sebelum pemeriksaan dimulai.' : 'Pilih pasien, tentukan dokter, lalu catat kebutuhan kunjungannya.' }}</p></div>
        <a class="btn" href="{{ $returnUrl }}">Kembali {{ $visit ? 'ke detail' : 'ke antrean' }}</a>
    </div>
    <form method="post" action="{{ $visit ? route('visits.update',[$currentPharmacy,$visit->id]) : route('visits.store',$currentPharmacy) }}" autocomplete="off" class="visit-registration-grid">
        @csrf
        @if($visit) @method('PUT')<input type="hidden" name="revision" value="{{ $visit->revision }}">@else<input type="hidden" name="request_key" value="{{ old('request_key',$requestKey) }}">@endif
        <div class="visit-registration-main">
            <section class="card visit-form-card" aria-labelledby="visit-identity-title">
                <header class="visit-section-heading"><span class="visit-step" aria-hidden="true">1</span><div><h2 id="visit-identity-title">Pasien & dokter</h2><p>Pastikan identitas pasien sesuai sebelum melanjutkan.</p></div><span class="visit-required-note">* Wajib diisi</span></header>
                <div class="visit-form-body">
                    @if($visit)
                        <div class="visit-fixed-patient"><span>Pasien terdaftar</span><strong>{{ $visit->patient_name }}</strong><small>{{ $visit->medical_record_number }} · Antrean #{{ $visit->queue_number }}</small><p>Identitas pasien dan nomor antrean tidak dapat diubah.</p></div>
                    @else
                        <div class="visit-field">
                            <label>Pasien *<select name="patient_id" required data-searchable aria-describedby="visit-patient-hint"><option value="">Cari nama atau nomor rekam medis</option>@foreach($patients as $patient)<option value="{{ $patient->id }}" @selected(old('patient_id',request('patient')) === $patient->id)>{{ $patient->name }} · {{ $patient->medical_record_number }} · {{ \Illuminate\Support\Carbon::parse($patient->birth_date)->format('d/m/Y') }}</option>@endforeach</select></label>
                            <small id="visit-patient-hint">Cocokkan nama, nomor rekam medis, dan tanggal lahir pasien.</small>
                            @error('patient_id')<p class="visit-field-error">{{ $message }}</p>@enderror
                        </div>
                        @if($access->allows('patients.create') && $access->allows('patients.view'))
                            <div class="visit-patient-tools"><div><strong>Pasien baru?</strong><p>Daftarkan identitasnya, lalu perbarui pilihan di sini.</p></div><div class="visit-tool-actions"><a class="btn small" href="{{ route('patients.create',$currentPharmacy) }}" target="_blank" rel="noopener">+ Tambah pasien <span class="visit-new-tab">(tab baru)</span></a><button class="btn small" type="button" id="refresh-visit-patients" data-url="{{ route('visits.patients',$currentPharmacy) }}">Perbarui pilihan</button></div></div>
                            <p id="visit-patient-feedback" class="visit-feedback" role="status" aria-live="polite"></p>
                        @endif
                        @if($patients->isEmpty())<p class="visit-empty-warning">Belum ada pasien aktif. Tambahkan pasien, lalu perbarui pilihan untuk melanjutkan.</p>@endif
                    @endif
                    <div class="visit-field">
                        <label>Dokter penanggung jawab *<select name="doctor_id" required data-searchable aria-describedby="visit-doctor-hint"><option value="">Pilih dokter yang melayani</option>@foreach($doctors as $doctor)<option value="{{ $doctor->id }}" @selected(old('doctor_id',$visit?->doctor_id) === $doctor->id)>{{ $doctor->name }} · {{ $doctor->code }}{{ $doctor->specialty ? ' · '.$doctor->specialty : '' }}</option>@endforeach</select></label>
                        <small id="visit-doctor-hint">Hanya dokter aktif di apotek ini yang dapat dipilih.</small>
                        @error('doctor_id')<p class="visit-field-error">{{ $message }}</p>@enderror
                    </div>
                    @if($doctors->isEmpty())<div class="visit-empty-warning">Belum ada dokter aktif. @if($access->allows('doctors.view'))<a href="{{ route('doctors.index',$currentPharmacy) }}">Lengkapi master dokter</a>@elseHubungi pengelola apotek untuk melengkapi master dokter.@endif</div>@endif
                </div>
            </section>
            <section class="card visit-form-card" aria-labelledby="visit-needs-title">
                <header class="visit-section-heading"><span class="visit-step" aria-hidden="true">2</span><div><h2 id="visit-needs-title">Kebutuhan kunjungan <x-help-tooltip label="Informasi Kebutuhan kunjungan">Catat informasi awal untuk membantu petugas layanan.</x-help-tooltip></h2></div></header>
                <div class="visit-form-body">
                    <div class="visit-field visit-type-field"><label>Jenis kunjungan *<select name="type" required>@foreach(\App\Services\VisitRegistration::TYPES as $key=>$label)<option value="{{ $key }}" @selected(old('type',$visit?->type ?? 'new') === $key)>{{ $label }}</option>@endforeach</select></label>@error('type')<p class="visit-field-error">{{ $message }}</p>@enderror</div>
                    <div class="visit-field"><label for="visit-complaint">Keluhan utama *</label><textarea id="visit-complaint" name="complaint" required maxlength="2000" rows="4" placeholder="Tuliskan keluhan utama yang disampaikan pasien" aria-describedby="visit-complaint-hint">{{ old('complaint',$visit?->complaint) }}</textarea><small id="visit-complaint-hint">Cukup uraian singkat untuk pendaftaran, maksimal 2.000 karakter.</small>@error('complaint')<p class="visit-field-error">{{ $message }}</p>@enderror</div>
                </div>
            </section>
            @include('visits.intake-form')
            @include('visits.services')
            <div class="visit-form-footer"><p>Periksa kembali pasien dan dokter sebelum menyimpan.</p><div><a class="btn" href="{{ $returnUrl }}">Batal</a><button type="submit" class="btn primary" @disabled($doctors->isEmpty() || (!$visit && $patients->isEmpty()))>{{ $visit ? 'Simpan perubahan' : 'Daftarkan & ambil antrean' }}</button></div></div>
        </div>
        <aside class="visit-registration-aside" aria-label="Informasi pendaftaran">
            <section class="card visit-registration-info"><span class="eyebrow">{{ $visit ? 'KUNJUNGAN TERDAFTAR' : 'PENDAFTARAN HARI INI' }}</span><h2>{{ $visit ? $visit->number : now($timezone)->translatedFormat('d F Y') }}</h2><p>{{ $visit ? 'Antrean #'.$visit->queue_number.' · Menunggu' : $timezone }}</p><dl><div><dt>Nomor kunjungan</dt><dd>{{ $visit ? 'Tetap' : 'Otomatis setelah disimpan' }}</dd></div><div><dt>Nomor antrean</dt><dd>{{ $visit ? 'Tidak berubah' : 'Mengikuti urutan pendaftaran' }}</dd></div></dl></section>
            <section class="visit-registration-guide"><h2>Setelah pendaftaran</h2><ol><li>Kunjungan masuk ke antrean dengan status <strong>Menunggu</strong>.</li><li>Setelah pembayaran layanan lunas, kunjungan masuk ke daftar pemeriksaan.</li></ol><p>{{ $visit ? 'Pendaftaran hanya dapat diubah saat masih menunggu.' : 'Satu pasien hanya dapat memiliki satu kunjungan aktif per hari. Waktu kedatangan dicatat saat pendaftaran disimpan.' }}</p></section>
        </aside>
    </form>
</div>
@endsection
