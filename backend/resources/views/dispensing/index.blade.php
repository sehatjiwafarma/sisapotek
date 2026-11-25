@extends('layouts.app')
@section('title','Penebusan resep')
@include('examinations.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/dispensing.css') }}?v={{ filemtime(public_path('css/dispensing.css')) }}">@endpush
@section('content')
<div class="products-page dispensing-page">
    <div class="heading"><div><span class="eyebrow">KASIR / POS / PENEBUSAN RESEP</span><h1>Antrean resep <x-help-tooltip label="Informasi Antrean resep">Untuk obat berdasarkan resep tersimpan. Resep dapat ditebus bertahap; pembayaran dan penyerahan dicatat terpisah.</x-help-tooltip></h1></div></div>
    <section class="card">
        <form method="get" class="toolbar" data-live-filter>
            <input type="hidden" name="sort" value="{{ request('sort','date') }}"><input type="hidden" name="direction" value="{{ request('direction','asc') }}">
            <label class="search-label">Cari resep / pasien<input type="search" name="q" maxlength="150" value="{{ request('q') }}" placeholder="Nomor resep, nama, atau nomor rekam medis"></label>
            <label>Status penebusan<select name="status"><option value="">Semua status</option>@foreach($labels as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></label>
            <button class="btn">Tampilkan</button><a class="btn" href="{{ route('dispensing.index',$currentPharmacy) }}">Reset</a>
        </form>
        <div class="table-wrap"><table class="product-table" data-table-sort="server" data-sort-columns="number,date,patient,doctor,status," data-sort-default="date" data-sort-default-direction="desc">
            <thead><tr><th>RESEP</th><th>TANGGAL FINAL</th><th>PASIEN</th><th>DOKTER</th><th>STATUS PEMBAYARAN RESEP</th><th data-no-sort>AKSI</th></tr></thead>
            <tbody>@forelse($prescriptions as $rx)<tr><td><strong>{{ $rx->number }}</strong>@if($rx->prescription_count > 1)<small>Beberapa resep dalam pemeriksaan ? perlu izin khusus penebusan</small>@endif</td><td>{{ \Illuminate\Support\Carbon::parse($rx->finalized_at ?? $rx->ready_at)->timezone($currentPharmacy->settings?->timezone ?: 'Asia/Jakarta')->format('d/m/Y H:i') }}</td><td><strong>{{ $rx->patient_name }}</strong><small>{{ $rx->medical_record_number }}</small></td><td>{{ $rx->doctor_name }}</td><td>@if(!$rx->valid_until)<span class="badge warning">Masa berlaku belum ditetapkan</span> @elseif($rx->valid_until < now($currentPharmacy->timezone ?: 'Asia/Jakarta')->toDateString())<span class="badge warning">Kedaluwarsa ? konsultasi kembali</span> @endif<span class="badge {{ $rx->dispensing_status === 'UNREDEEMED' ? 'neutral' : '' }}">{{ $labels[$rx->dispensing_status] }}</span></td><td><a class="btn small" href="{{ route('dispensing.show',[$currentPharmacy,$rx->id]) }}">Buka penebusan</a></td></tr>@empty<tr><td colspan="6" class="empty">Belum ada resep yang sesuai filter.</td></tr>@endforelse</tbody>
        </table></div>{{ $prescriptions->links('partials.pagination') }}
    </section>
</div>
@endsection