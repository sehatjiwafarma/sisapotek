@extends('layouts.app')
@section('title','Kasir / pembayaran')
@include('operations.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/cashier-workspace.css') }}?v={{ filemtime(public_path('css/cashier-workspace.css')) }}">@endpush
@push('master-scripts')<script src="{{ asset('js/cashier-workspace.js') }}?v={{ filemtime(public_path('js/cashier-workspace.js')) }}" defer></script>@endpush
@section('content')
<div class="proc-page op-page"><div class="heading"><div><span class="eyebrow">PENJUALAN & KASIR</span><h1>Kasir / pembayaran <x-help-tooltip label="Informasi Kasir / pembayaran">Layanan, resep, dan penjualan umum dibayar dalam transaksi dan struk terpisah.</x-help-tooltip></h1></div></div>
<div class="actions cashier-actions">
@if($access->allows('sales.create'))<a class="btn primary" href="{{ route('sales.create',$currentPharmacy) }}">Penjualan umum baru</a>@endif
@if($access->allows('dispensing.view'))<a class="btn" href="{{ route('dispensing.index',$currentPharmacy) }}">Siapkan resep</a>@endif
@if($access->allows('billing.view'))<a class="btn" href="{{ route('billing.index',$currentPharmacy) }}">Tagihan layanan</a>@endif

@if($access->allows('sales.view'))<a class="btn" href="{{ route('sales.index',$currentPharmacy) }}">Riwayat penjualan</a>@endif
</div>
<section class="card op-card"><h2>Menunggu pembayaran <x-help-tooltip label="Informasi Menunggu pembayaran">Maksimal 50 tagihan layanan dan 50 draft penjualan terbaru. Buka riwayat untuk melihat transaksi lainnya.</x-help-tooltip></h2><label class="cashier-search">Cari pasien, pelanggan, atau nomor transaksi<input type="search" data-cashier-search placeholder="Ketik nama atau nomor transaksi" autocomplete="off"></label><p class="cashier-search-hint">Pencarian mencakup daftar yang ditampilkan di halaman ini.</p>@if($access->allows('sales.view') && !$access->allows('dispensing.view'))<p class="cashier-search-hint">Transaksi resep memerlukan izin Farmasi: Lihat. Hubungi pengelola apotek untuk mengatur akses.</p>@endif<div class="table-wrap"><table data-cashier-table><thead><tr><th>Jenis</th><th>Nomor</th><th>Pasien / pelanggan</th><th>Total</th><th data-no-sort>Aksi</th></tr></thead><tbody>
@foreach($transactions as $entry)@if($entry['type'] === 'bill')@php($bill=$entry['record'])<tr><td>Layanan</td><td>{{ $bill->number }}</td><td>{{ $bill->patient_name }}</td><td data-sort-value="{{ $bill->total }}">Rp {{ number_format($bill->total,2,',','.') }}</td><td><a class="btn small" href="{{ route('billing.show',[$currentPharmacy,$bill->id]) }}">Buka</a></td></tr>@else @php($sale=$entry['record'])<tr><td>{{ $sale->kind === 'PRESCRIPTION' ? 'Resep' : 'Umum' }}</td><td>{{ $sale->number }}</td><td>{{ $sale->kind === 'PRESCRIPTION' ? ($sale->prescription_count > 1 ? 'Beberapa pasien' : ($sale->patient_name ?: 'Pasien tidak tersedia')) : ($sale->customer_name ?: 'Umum') }}</td><td data-sort-value="{{ $sale->total }}">Rp {{ number_format($sale->total,2,',','.') }}</td><td><a class="btn small" href="{{ route('sales.show',[$currentPharmacy,$sale->id]) }}">Buka</a></td></tr>@endif @endforeach
<tr data-cashier-no-results hidden><td colspan="5">Tidak ada transaksi yang cocok dengan pencarian.</td></tr>
@if($bills->isEmpty() && $sales->isEmpty())<tr><td colspan="5">Belum ada transaksi yang menunggu pembayaran.</td></tr>@endif
</tbody></table></div></section>
</div>
@endsection
