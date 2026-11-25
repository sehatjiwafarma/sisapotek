@extends('layouts.app')
@section('title','Pemeriksaan')
@include('examinations.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/procurement.css') }}?v={{ filemtime(public_path('css/procurement.css')) }}">@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('css/prescriptions.css') }}?v={{ filemtime(public_path('css/prescriptions.css')) }}">@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('css/encounter.css') }}?v={{ filemtime(public_path('css/encounter.css')) }}">@endpush
@push('master-scripts')
<script src="{{ asset('js/examination-images.js') }}?v={{ filemtime(public_path('js/examination-images.js')) }}" defer></script>
<script src="{{ asset('js/search-select.js') }}" defer></script>
<script src="{{ asset('js/prescriptions.js') }}?v={{ filemtime(public_path('js/prescriptions.js')) }}" defer></script>
<script src="{{ asset('js/encounter.js') }}?v={{ filemtime(public_path('js/encounter.js')) }}" defer></script>
@endpush
@section('content')
<div class="products-page soap-page soap-compact encounter-page">
<div class="heading"><div><span class="eyebrow">LAYANAN PASIEN / PEMERIKSAAN</span><h1>Pemeriksaan</h1><p>{{ $visit->patient_name }} · {{ $visit->number }} · {{ $visit->doctor_name }}</p></div><a class="btn" href="{{ route('examinations.index',$currentPharmacy) }}">Daftar pemeriksaan</a></div>
@if($access->allows('visits.view'))<a class="encounter-back" href="{{ route('visits.show',[$currentPharmacy,$visit->id]) }}">← Detail kunjungan</a>@endif
@include('examinations.context')
<nav class="encounter-navigation" aria-label="Bagian pemeriksaan" data-encounter-tabs><a href="#soap">Pemeriksaan <small>{{ ['DRAFT'=>'Tersimpan','FINAL'=>'Tersimpan'][$exam?->status] ?? 'Belum dicatat' }}</small></a>@if($canReadPrescription)<a href="#resep">Resep <small>{{ ['DRAFT'=>'Tersimpan','FINAL'=>'Tersimpan'][$prescription?->status] ?? 'Belum dibuat' }}</small></a>@endif</nav>
<p class="notice warning" role="alert" data-encounter-warning hidden>Simpan perubahan pemeriksaan terlebih dahulu.</p>
<div data-rx-collection>@if($canReadPrescription)@include('prescriptions.collection', ['rxListRoute'=>'examinations.show'])@endif</div>
<form method="post" action="{{ route('examinations.encounter.save',[$currentPharmacy,$visit->id]) }}" enctype="multipart/form-data" autocomplete="off" data-encounter-form>
@csrf @method('PUT')
<section id="soap" class="encounter-section" aria-labelledby="encounter-soap-title"><header class="clinical-section"><h2 id="encounter-soap-title">1. Pemeriksaan</h2><p>Keluhan pendaftaran: {{ $visit->complaint }}</p></header>
@if($editSoap)
@if(!$exam)<p class="clinical-restricted">Pemeriksaan belum dicatat. Lengkapi formulir di bawah.</p>@endif
<div class="soap-compact-form">@include('examinations.encounter-soap',['amend'=>false])</div>
@elseif($exam)@include('examinations.payload')
@else<p class="clinical-restricted">Pemeriksaan belum dicatat. Anda tidak memiliki akses menulis pemeriksaan ini.</p>@endif
</section>
@if($canReadPrescription)
<section id="resep" class="encounter-section rx-page" aria-labelledby="encounter-rx-title"><header class="clinical-section"><div class="help-heading"><h2 id="encounter-rx-title">2. Resep pasien</h2><x-help-tooltip label="Informasi 2. Resep pasien">Tambahkan obat jika diperlukan. Jumlah menggunakan satuan dasar.</x-help-tooltip></div></header>
@if($editRx)
<div class="encounter-rx-body">
@include('examinations.encounter-rx',['payload'=>$rxPayload])
</div>
@elseif($prescription)
<p class="encounter-rx-validity"><span>Berlaku sampai</span> <strong>{{ $prescription->valid_until ? \Illuminate\Support\Carbon::parse($prescription->valid_until)->format('d/m/Y') : 'Belum ditetapkan' }}</strong></p>
@include('prescriptions.items',['payload'=>$rxPayload])
@if(!empty($rxPayload['notes']))<div class="soap-reading"><h3>Catatan resep</h3><p>{{ $rxPayload['notes'] }}</p></div>@endif
@else<p class="clinical-restricted">Resep belum dibuat.</p>@endif
</section>
@endif
@if($editSoap || $editRx)<div class="soap-save encounter-save"><div><strong>Simpan hasil pemeriksaan</strong><p>Pemeriksaan dan resep disimpan bersama. Stok dipotong setelah pembayaran.</p></div><button class="btn primary">Simpan pemeriksaan</button></div>@endif
</form>

@if($prescription && $canReadPrescription)<div class="clinical-actions">@if($access->allows('dispensing.view') && ($prescription->status === 'FINAL' || $prescription->ready_at))<a class="btn primary" href="{{ route('dispensing.show',[$currentPharmacy,$prescription->id]) }}">Buka penebusan</a>@endif @if($access->allows('prescriptions.print') && ($prescription->status === 'FINAL' || $prescription->ready_at))<a class="btn" target="_blank" rel="noopener" href="{{ route('prescriptions.print',[$currentPharmacy,$visit->id,'prescription'=>$prescription->id]) }}">Cetak resep</a>@endif</div>@endif
<details class="encounter-history"><summary>Riwayat pemeriksaan dan versi</summary>@include('examinations.encounter-history')</details>
</div>
@endsection
