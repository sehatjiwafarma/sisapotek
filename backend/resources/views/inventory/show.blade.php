@extends('layouts.app')
@section('title', 'Detail batch persediaan')
@include('inventory.assets')
@section('content')
@php($qty = fn($value) => rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ','))
<div class="products-page">
@include('inventory.header')
<div class="inventory-context">Saldo berasal dari transaksi yang sudah diposting, dalam satuan dasar. Batch ditampilkan menurut tanggal kedaluwarsa terdekat; batch kedaluwarsa harus dipisahkan dari barang yang dapat diserahkan. Tanda â€œâ€”â€ berarti data tidak dicatat.</div>
<section class="card"><header class="inventory-section"><div class="help-heading"><h2>Stok per batch & lokasi</h2><x-help-tooltip label="Informasi Stok per batch & lokasi">Batch tanpa tanggal kedaluwarsa ditampilkan terakhir. Baris bersaldo nol tidak ditampilkan.</x-help-tooltip></div></header>
@if($batches->isNotEmpty())
<div class="table-wrap"><table class="product-table"><thead><tr><th>BATCH</th><th>KEDALUWARSA</th><th>RAK / LOKASI</th><th>SALDO FISIK</th><th>KONDISI</th></tr></thead><tbody>
@foreach($batches as $batch)<tr><td>{{ $batch->batch_number ?: 'â€”' }}</td><td data-sort-value="{{ $batch->expires_on ?: '9999-12-31' }}">{{ $batch->expires_on ? \Illuminate\Support\Carbon::parse($batch->expires_on)->format('d/m/Y') : 'â€”' }}</td><td>{{ $batch->location ?: 'â€”' }}</td><td class="inventory-number" data-sort-value="{{ $batch->quantity }}"><strong>{{ $qty($batch->quantity) }}</strong> {{ $batch->base_unit }}</td><td><div class="inventory-status">@if($batch->expires_on && $batch->expires_on <= $today)<span class="badge expired">Kedaluwarsa</span>@elseif($batch->expires_on && $batch->expires_on <= $until)<span class="badge warning">Hampir kedaluwarsa</span>@else<span class="badge">{{ $batch->expires_on ? 'Layak jual' : 'Tanpa tanggal kedaluwarsa' }}</span>@endif</div></td></tr>@endforeach
</tbody></table></div>
@else<div class="product-empty"><h2>Belum ada saldo batch</h2><p>Stok akan muncul setelah penerimaan barang diposting. Periksa kartu stok untuk melihat riwayat mutasi.</p></div>@endif
</section></div>
@endsection
