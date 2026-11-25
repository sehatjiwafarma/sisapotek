@extends('layouts.app')
@section('title','Kunjungan & antrean')
@include('clinical.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/visits.css') }}">@endpush
@section('content')
<div class="products-page"><div class="heading"><div><span class="eyebrow">LAYANAN PASIEN / PENDAFTARAN</span><h1>Kunjungan & antrean <x-help-tooltip label="Informasi Kunjungan & antrean">Kelola kedatangan pasien dan pantau antrean layanan per hari.</x-help-tooltip></h1></div>@if($access->allows('visits.create') && $currentPharmacy->status === 'ACTIVE')<a class="btn primary" href="{{ route('visits.create',$currentPharmacy) }}">+ Daftarkan kunjungan</a>@endif</div>
<div class="visit-summary" aria-label="Ringkasan seluruh kunjungan pada tanggal terpilih">@foreach(\App\Services\VisitRegistration::STATUSES as $key=>$label)<div class="card"><span>{{ $label }}</span><strong>{{ $counts[$key] ?? 0 }}</strong></div>@endforeach</div>
<section class="card"><form class="toolbar product-filters" method="get" data-live-filter>@include('inventory.sort-fields')
<label>Tanggal<input type="date" name="date" value="{{ $date }}" required></label><label class="search-label">Cari kunjungan<input type="search" name="q" maxlength="150" value="{{ request('q') }}" placeholder="Nama, nomor RM, atau nomor kunjungan"></label>
<label>Status<select name="status"><option value="">Semua status</option>@foreach(\App\Services\VisitRegistration::STATUSES as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Dokter<select name="doctor"><option value="">Semua dokter</option>@foreach($doctors as $doctor)<option value="{{ $doctor->id }}" @selected(request('doctor') === $doctor->id)>{{ $doctor->name }}{{ $doctor->active ? '' : ' (nonaktif)' }}</option>@endforeach</select></label>
@if(request('patient'))<input type="hidden" name="patient" value="{{ request('patient') }}">@endif
<button class="btn">Tampilkan</button><a class="btn" href="{{ route('visits.index',$currentPharmacy) }}">Hari ini / reset</a></form>
@if($visits->isNotEmpty())<div class="table-wrap"><table class="product-table" data-table-sort="server" data-sort-columns="queue,number,patient,doctor,time,status,,," data-sort-default="queue"><thead><tr><th>ANTREAN</th><th>KUNJUNGAN</th><th>PASIEN</th><th>DOKTER</th><th>JAM DAFTAR</th><th>LAYANAN</th><th data-no-sort>SOAP</th><th data-no-sort>RESEP</th><th>AKSI</th></tr></thead><tbody>
@foreach($visits as $visit)<tr><td><strong>#{{ str_pad($visit->queue_number,3,'0',STR_PAD_LEFT) }}</strong></td><td>{{ $visit->number }}</td><td><strong>{{ $visit->patient_name }}</strong><br><small>{{ $visit->medical_record_number }}</small></td><td>{{ $visit->doctor_name }}</td><td>{{ \Illuminate\Support\Carbon::parse($visit->registered_at)->timezone($currentPharmacy->settings?->timezone ?: 'Asia/Jakarta')->format('H:i') }}</td><td><span class="badge {{ $visit->status === 'CANCELLED' ? 'neutral' : '' }}">{{ \App\Services\VisitRegistration::STATUSES[$visit->status] }}</span></td><td>{{ ["DRAFT"=>"Draft","FINAL"=>"Final"][$visit->soap_status] ?? "Belum dilengkapi" }}</td><td>{{ ["DRAFT"=>"Draft","FINAL"=>"Final"][$visit->prescription_status] ?? "Belum dibuat" }}</td><td><a class="btn small" href="{{ route('visits.show',[$currentPharmacy,$visit->id]) }}">Detail</a></td></tr>@endforeach
</tbody></table></div><div class="product-list-footer"><span>{{ $visits->total() }} kunjungan sesuai filter</span>{{ $visits->links('partials.pagination') }}</div>@else<div class="product-empty"><h2>Belum ada kunjungan yang sesuai</h2><p>Ubah tanggal atau filter untuk melihat kunjungan lain. Pendaftaran baru masuk ke antrean hari ini.</p></div>@endif</section></div>
@endsection


