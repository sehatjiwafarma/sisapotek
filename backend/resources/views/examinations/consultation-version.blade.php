@extends('layouts.app')
@section('title', 'Riwayat bukti konsultasi')
@include('examinations.assets')
@section('content')
<div class="products-page soap-page"><div class="heading"><div><span class="eyebrow">PEMERIKSAAN / RIWAYAT KONSULTASI</span><h1>Bukti konsultasi versi {{ $evidenceRecord->version }}</h1><p>{{ $visit->patient_name }} · {{ $visit->number }}</p></div><a class="btn" href="{{ route('examinations.show', [$currentPharmacy, $visit->id]) }}#bukti-konsultasi">Kembali</a></div>
@include('examinations.consultation')
</div>
@endsection
