@extends('layouts.app')
@section('title','Konsultasi kembali')
@include('clinical.assets')
@section('content')
<div class="products-page"><div class="heading"><div><span class="eyebrow">PELAYANAN PASIEN</span><h1>Konsultasi kembali</h1><p>Pasien dengan resep final yang kedaluwarsa dan masih memiliki sisa hak tebus. Ini adalah pengingat untuk ditinjau, bukan diagnosis atau bukti bahwa pasien sudah dihubungi.</p></div></div>
@forelse($patients as $patient)
<section class="card"><header class="clinical-section"><h2>{{ $patient->patient_name }}</h2><p>{{ $patient->medical_record_number }} · {{ $records[$patient->patient_id]->count() }} resep perlu ditinjau</p>@if($access->allows('patients.view'))<a class="btn small" href="{{ route('patients.show',[$currentPharmacy,$patient->patient_id]) }}">Buka rekam medis</a>@endif</header>
@foreach($records[$patient->patient_id] as $row)
<div class="clinical-restricted"><p><strong>{{ $row->number }}</strong> · Berlaku sampai {{ \Illuminate\Support\Carbon::parse($row->valid_until)->format('d/m/Y') }} · {{ $row->doctor_name }}</p><p>Status: <strong>{{ ['PENDING'=>'Belum ditinjau','CONTACTED'=>'Sudah dihubungi','DONE'=>'Selesai'][$row->follow_up_status ?? 'PENDING'] }}</strong>@if($row->follow_up_at) · {{ \Illuminate\Support\Carbon::parse($row->follow_up_at)->timezone($currentPharmacy->timezone ?: 'Asia/Jakarta')->format('d/m/Y H:i') }}@endif</p>@if($row->follow_up_note)<p>Catatan petugas: {{ $row->follow_up_note }}</p>@endif
<a class="btn small" href="{{ route('dispensing.show',[$currentPharmacy,$row->id]) }}">Tinjau resep</a>
@if($access->allows('dispensing.prepare') && $currentPharmacy->status === 'ACTIVE')<details><summary>Ubah tindak lanjut</summary><form method="post" action="{{ route('patients.follow-up.update',[$currentPharmacy,$row->id]) }}">@csrf<label>Status<select name="status"><option value="PENDING" @selected(($row->follow_up_status ?? 'PENDING') === 'PENDING')>Belum ditinjau</option><option value="CONTACTED" @selected($row->follow_up_status === 'CONTACTED')>Sudah dihubungi</option><option value="DONE" @selected($row->follow_up_status === 'DONE')>Selesai</option></select></label><label>Catatan tindak lanjut <textarea name="note" required minlength="5" maxlength="1000" rows="2">{{ $row->follow_up_note }}</textarea></label><p>Tandai selesai setelah petugas memastikan tindak lanjut telah selesai. Status dapat dibuka kembali.</p><button class="btn">Simpan tindak lanjut</button></form></details>@endif</div>
@endforeach</section>
@empty<section class="card"><p>Tidak ada pasien dengan resep kedaluwarsa yang memiliki sisa penebusan.</p></section>@endforelse
{{ $patients->links('partials.pagination') }}</div>
@endsection
