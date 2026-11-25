@extends('layouts.app')
@section('title',$patient ? 'Edit pasien' : 'Daftarkan pasien')
@include('clinical.assets')
@section('content')
<div class="products-page"><div class="heading"><div><span class="eyebrow">LAYANAN PASIEN / IDENTITAS</span><h1>{{ $patient ? 'Edit data pasien' : 'Daftarkan pasien' }}</h1><p>{{ $patient?->medical_record_number ?? 'Nomor rekam medis dibuat otomatis setelah data disimpan.' }}</p></div><a class="btn" href="{{ $patient ? route('patients.show',[$currentPharmacy,$patient->id]) : route('patients.index',$currentPharmacy) }}">Kembali</a></div>
<form class="card clinical-form" method="post" enctype="multipart/form-data" action="{{ $patient ? route('patients.update',[$currentPharmacy,$patient->id]) : route('patients.store',$currentPharmacy) }}" autocomplete="off">@csrf @if($patient) @method('PUT')<input type="hidden" name="revision" value="{{ $patient->revision }}">@else<input type="hidden" name="request_key" value="{{ old('request_key',$requestKey) }}">@endif
<header class="clinical-section"><div class="help-heading"><h2>Identitas pasien</h2><x-help-tooltip label="Informasi Identitas pasien">Nama, tanggal lahir, dan pilihan jenis kelamin wajib diisi. Pilih “Belum diisi / tidak diketahui” jika informasinya belum tersedia.</x-help-tooltip></div></header><div class="clinical-fields">
@include('clinical.input',['label'=>'Nama lengkap','name'=>'name','value'=>$patient?->name,'max'=>150,'required'=>true,'wide'=>true])
@include('clinical.input',['label'=>'Tanggal lahir','name'=>'birth_date','value'=>$patient?->birth_date,'type'=>'date','required'=>true,'dateMax'=>now($currentPharmacy->timezone ?: 'Asia/Jakarta')->toDateString()])
<label>Jenis kelamin *<select name="sex" required>@foreach(\App\Http\Controllers\PatientController::SEXES as $value=>$label)<option value="{{ $value }}" @selected(old('sex',$patient?->sex ?? 'unspecified') === $value)>{{ $label }}</option>@endforeach</select></label>
@include('clinical.input',['label'=>'Telepon','name'=>'phone','value'=>$patient?->phone,'type'=>'tel','max'=>30])
@if($access->allows('patients.view_sensitive'))@include('clinical.input',['label'=>'NIK (opsional)','name'=>'nik','value'=>$patient?->nik,'max'=>16,'hint'=>'Isi tepat 16 digit; kosongkan jika belum tersedia.'])@endif
@include('clinical.input',['label'=>'Alamat','name'=>'address','value'=>$patient?->address,'type'=>'textarea','wide'=>true])
</div><header class="clinical-section"><div class="help-heading"><h2>Kontak darurat</h2><x-help-tooltip label="Informasi Kontak darurat">Opsional; isi pihak yang dapat dihubungi bila diperlukan.</x-help-tooltip></div></header><div class="clinical-fields">
@include('clinical.input',['label'=>'Nama kontak','name'=>'emergency_name','value'=>$patient?->emergency_name,'max'=>150])
@include('clinical.input',['label'=>'Hubungan dengan pasien','name'=>'emergency_relationship','value'=>$patient?->emergency_relationship,'max'=>80])
@include('clinical.input',['label'=>'Telepon kontak','name'=>'emergency_phone','value'=>$patient?->emergency_phone,'type'=>'tel','max'=>30])
</div>
@if(!$patient && $access->allows('patients.view_sensitive'))
<header class="clinical-section"><div class="help-heading"><h2>Surat perjanjian</h2><x-help-tooltip label="Informasi Surat perjanjian">Opsional. Unggah surat perjanjian pasien dalam format PDF, JPG, atau PNG, maksimal 5 MB.</x-help-tooltip></div></header>
<div class="clinical-fields"><label class="wide">Berkas surat perjanjian<input type="file" name="agreement" accept="application/pdf,image/jpeg,image/png"><small>Berkas dapat dilihat melalui detail pasien setelah pendaftaran disimpan. Pilih ulang berkas jika formulir gagal divalidasi.</small></label></div>
@endif
@if($access->allows('patients.view_clinical') || $access->allows('patients.view_sensitive'))<header class="clinical-section"><div class="help-heading"><h2>Informasi terbatas</h2><x-help-tooltip label="Informasi Informasi terbatas">Isi hanya informasi yang diperlukan untuk layanan. Kolom yang tidak dapat Anda akses tetap dipertahankan saat menyimpan.</x-help-tooltip></div></header><div class="clinical-fields">
@if($access->allows('patients.view_clinical'))@include('clinical.input',['label'=>'Alergi obat','name'=>'allergies','value'=>$patient?->allergies,'type'=>'textarea','wide'=>true,'hint'=>'Kosong berarti belum dicatat; bukan konfirmasi bahwa pasien tidak memiliki alergi.'])@endif
@if($access->allows('patients.view_sensitive'))@include('clinical.input',['label'=>'Catatan pribadi pasien','name'=>'notes','value'=>$patient?->notes,'type'=>'textarea','wide'=>true])@endif
</div>@endif
<div class="clinical-save"><p>Data tersimpan khusus apotek ini. Tidak ada penghapusan permanen.</p><button class="btn primary">{{ $patient ? 'Simpan perubahan' : 'Daftarkan pasien' }}</button></div></form></div>
@endsection
